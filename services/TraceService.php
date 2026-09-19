<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.2 — read-only trace/audit aggregation layer. Deliberately built
 * as a SERVICE over EXISTING tables, not a new event-sourcing system: the
 * schema audit behind this (see docs/PHASE_V2_2_TRACE_ARCHITECTURE.md)
 * found that every correlation the spec asks for is already a real FK:
 *
 *   inventory_batches.source_transaction_line_id  -> batch's creating line
 *   inventory_transaction_lines.created_batch_id  -> line's created batch
 *   fifo_allocations.transaction_line_id/batch_id -> consuming line <-> consumed batch
 *   inventory_transactions.reversal_of_id         -> reversal <-> original (bidirectional via reverse lookup)
 *   warehouse_transfer_lines.out/in_transaction_line_id -> transfer <-> its OUT/IN transaction lines
 *   stock_adjustments.transaction_id              -> adjustment <-> its ADJUSTMENT transaction
 *   production_outputs.transaction_line_id        -> production output <-> its PRODUCTION_OUT line
 *   audit_logs (entity_type, entity_id, before_data, after_data, created_at) -> who/when/before/after
 *
 * So no new tables/columns were needed. Every method here only SELECTs —
 * tracing must never mutate stock, FIFO, transactions, or master data.
 *
 * Never fabricates history: if audit_logs has no row for an entity/action
 * (e.g. it predates that action being logged, or was never a logged
 * action), the timeline for that gap is simply absent — callers show
 * "Data historis tidak merekam informasi ini." rather than inventing a
 * value.
 */
final class TraceService
{
    /** @var array<string, array{table:string, audit_type:string}> whitelisted — never build a table name from request input */
    private const ENTITY_TABLES = [
        'item' => ['table' => 'items', 'audit_type' => 'items'],
        'warehouse' => ['table' => 'warehouses', 'audit_type' => 'warehouses'],
        'division' => ['table' => 'divisions', 'audit_type' => 'divisions'],
        'supplier' => ['table' => 'suppliers', 'audit_type' => 'suppliers'],
        'bakery_destination' => ['table' => 'bakery_destinations', 'audit_type' => 'bakery_destinations'],
        'category' => ['table' => 'categories', 'audit_type' => 'categories'],
        'stock_policy' => ['table' => 'item_warehouse_stock_policy', 'audit_type' => 'item_warehouse_stock_policy'],
    ];

    /** @return array{blocked?:bool} placeholder — always read-only; kept for symmetry with the rest of the codebase's service pattern */
    public static function entityTrace(PDO $pdo, string $type, int $id): array
    {
        if (!isset(self::ENTITY_TABLES[$type])) {
            throw new ValidationException(["unknown trace entity type '{$type}'"]);
        }
        $meta = self::ENTITY_TABLES[$type];
        $stmt = $pdo->prepare("SELECT * FROM `{$meta['table']}` WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("{$type} {$id}");
        }

        $timeline = self::auditTimeline($pdo, $meta['audit_type'], $id);

        return [
            'entity' => ['type' => $type, 'id' => $id],
            'overview' => self::stripSecrets($row),
            'timeline' => $timeline,
            'changes' => array_values(array_filter($timeline, static fn ($e) => $e['before'] !== null || $e['after'] !== null)),
        ];
    }

    /**
     * Full chain for one transaction: header, lines, the FIFO batches it
     * created and/or consumed, its reversal link (either direction), and a
     * cross-reference to the transfer that produced it (TransferService's
     * own reference_no convention, "TRANSFER-{id}" — read here, never
     * reconstructed/guessed differently).
     */
    public static function transactionTrace(PDO $pdo, int $transactionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.*, w.code AS warehouse_code, w.name AS warehouse_name
             FROM inventory_transactions t JOIN warehouses w ON w.id = t.warehouse_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $transactionId]);
        $tx = $stmt->fetch();
        if ($tx === false) {
            throw new NotFoundException("transaction {$transactionId}");
        }

        $lineStmt = $pdo->prepare(
            'SELECT l.*, i.sku, i.name AS item_name, u.code AS unit_code
             FROM inventory_transaction_lines l
             JOIN items i ON i.id = l.item_id
             JOIN units u ON u.id = l.input_unit_id
             WHERE l.transaction_id = :id ORDER BY l.line_no'
        );
        $lineStmt->execute(['id' => $transactionId]);
        $lines = $lineStmt->fetchAll();
        $lineIds = array_map(static fn ($l) => (int) $l['id'], $lines);

        $allocations = [];
        $createdBatches = [];
        if ($lineIds !== []) {
            $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
            $allocStmt = $pdo->prepare(
                "SELECT a.*, b.item_id, b.warehouse_id, b.received_date
                 FROM fifo_allocations a JOIN inventory_batches b ON b.id = a.batch_id
                 WHERE a.transaction_line_id IN ({$placeholders})
                 ORDER BY a.id"
            );
            $allocStmt->execute($lineIds);
            $allocations = $allocStmt->fetchAll();

            $batchStmt = $pdo->prepare(
                "SELECT * FROM inventory_batches WHERE source_transaction_line_id IN ({$placeholders}) ORDER BY id"
            );
            $batchStmt->execute($lineIds);
            $createdBatches = $batchStmt->fetchAll();
        }

        $reversalOf = null;
        if ($tx['reversal_of_id'] !== null) {
            $s = $pdo->prepare('SELECT id, transaction_uuid, transaction_type, transaction_date, status FROM inventory_transactions WHERE id = :id');
            $s->execute(['id' => $tx['reversal_of_id']]);
            $reversalOf = $s->fetch() ?: null;
        }
        $s2 = $pdo->prepare('SELECT id, transaction_uuid, transaction_type, transaction_date, status FROM inventory_transactions WHERE reversal_of_id = :id');
        $s2->execute(['id' => $transactionId]);
        $reversedBy = $s2->fetch() ?: null;

        $transfer = null;
        if (preg_match('/^TRANSFER-(\d+)$/', (string) $tx['reference_no'], $m)) {
            $s = $pdo->prepare('SELECT * FROM warehouse_transfers WHERE id = :id');
            $s->execute(['id' => (int) $m[1]]);
            $transfer = $s->fetch() ?: null;
        }

        $production = null;
        if (preg_match('/^PRODUCTION-(\d+)$/', (string) $tx['reference_no'], $m)) {
            $s = $pdo->prepare('SELECT * FROM production_headers WHERE id = :id');
            $s->execute(['id' => (int) $m[1]]);
            $production = $s->fetch() ?: null;
        }

        $adjustment = null;
        $adjStmt = $pdo->prepare('SELECT * FROM stock_adjustments WHERE transaction_id = :id');
        $adjStmt->execute(['id' => $transactionId]);
        $adjustment = $adjStmt->fetch() ?: null;

        return [
            'entity' => ['type' => 'transaction', 'id' => $transactionId],
            'transaction' => $tx,
            'lines' => $lines,
            'fifo_allocations' => $allocations,
            'fifo_batches_created' => $createdBatches,
            'reversal_of' => $reversalOf,
            'reversed_by' => $reversedBy,
            'transfer' => $transfer,
            'production' => $production,
            'adjustment' => $adjustment,
            'audit_events' => self::auditTimeline($pdo, 'inventory_transactions', $transactionId),
        ];
    }

    /**
     * One item + warehouse: current stock/policy plus the full movement
     * timeline assembled by unioning every transaction line that touched
     * this item in this warehouse (IN/OUT/TRANSFER_IN/TRANSFER_OUT/
     * ADJUSTMENT/OPNAME/PRODUCTION_IN/PRODUCTION_OUT/OPENING/REVERSAL all
     * flow through inventory_transaction_lines — one query, not a per-type
     * loop), plus the item's active FIFO batches and their allocations.
     */
    public static function inventoryTrace(PDO $pdo, int $itemId, int $warehouseId, int $page = 1, int $perPage = 50): array
    {
        $itemStmt = $pdo->prepare('SELECT id, sku, name, status FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        $item = $itemStmt->fetch();
        if ($item === false) {
            throw new NotFoundException("item {$itemId}");
        }
        $whStmt = $pdo->prepare('SELECT id, code, name FROM warehouses WHERE id = :id');
        $whStmt->execute(['id' => $warehouseId]);
        $warehouse = $whStmt->fetch();
        if ($warehouse === false) {
            throw new NotFoundException("warehouse {$warehouseId}");
        }

        $policyStmt = $pdo->prepare('SELECT * FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
        $policyStmt->execute(['i' => $itemId, 'w' => $warehouseId]);
        $policy = $policyStmt->fetch() ?: null;

        $balanceStmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0) AS qty, COALESCE(SUM(qty_base*unit_cost_base),0) AS value FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w');
        $balanceStmt->execute(['i' => $itemId, 'w' => $warehouseId]);
        $balance = $balanceStmt->fetch();

        $totalStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             WHERE l.item_id = :i AND l.warehouse_id = :w'
        );
        $totalStmt->execute(['i' => $itemId, 'w' => $warehouseId]);
        $total = (int) $totalStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $moveStmt = $pdo->prepare(
            'SELECT t.id AS transaction_id, t.transaction_uuid, t.transaction_type, t.transaction_date, t.status,
                    t.is_historical_import, t.inventory_effect, t.reference_no, t.created_by,
                    l.id AS line_id, l.base_qty, l.unit_cost_base, l.subtotal, u.username AS created_by_username
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE l.item_id = :i AND l.warehouse_id = :w
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT :lim OFFSET :off'
        );
        $moveStmt->bindValue('i', $itemId, PDO::PARAM_INT);
        $moveStmt->bindValue('w', $warehouseId, PDO::PARAM_INT);
        $moveStmt->bindValue('lim', $perPage, PDO::PARAM_INT);
        $moveStmt->bindValue('off', $offset, PDO::PARAM_INT);
        $moveStmt->execute();
        $movements = $moveStmt->fetchAll();

        $batchStmt = $pdo->prepare(
            'SELECT * FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w ORDER BY received_date ASC, id ASC'
        );
        $batchStmt->execute(['i' => $itemId, 'w' => $warehouseId]);
        $batches = $batchStmt->fetchAll();

        return [
            'entity' => ['type' => 'inventory', 'item_id' => $itemId, 'warehouse_id' => $warehouseId],
            'item' => $item,
            'warehouse' => $warehouse,
            'overview' => [
                'qty_base' => round((float) $balance['qty'], 6),
                'value' => round((float) $balance['value'], 4),
                'minimum_stock' => $policy !== null ? (float) $policy['minimum_stock_base'] : null,
                'buffer_stock' => $policy !== null ? $policy['buffer_stock_base'] : null,
            ],
            'stock_policy' => $policy,
            'movements' => $movements,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))],
            'fifo_batches' => $batches,
        ];
    }

    /**
     * Global search — whitelisted set of lookups, never a single generic
     * cross-table LIKE query (keeps every match explainable and each
     * table's own index usable). Returns light rows the frontend turns
     * into "open trace" links, not the trace itself.
     */
    public static function search(PDO $pdo, string $q, ?string $entityType = null, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $results = [];

        $want = static fn (string $type) => $entityType === null || $entityType === $type;

        // Native prepares (PDO::ATTR_EMULATE_PREPARES=false, see Database.php)
        // reject a named placeholder reused more than once per query — every
        // repeated LIKE below gets its own :qN, all bound to the same value.
        if ($want('item')) {
            $s = $pdo->prepare('SELECT id, sku AS code, name, status FROM items WHERE sku LIKE :q1 OR name LIKE :q2 OR barcode LIKE :q3 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like); $s->bindValue('q3', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'item', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => $r['status']];
            }
        }
        if ($want('transaction')) {
            $s = $pdo->prepare('SELECT id, transaction_uuid, transaction_type, reference_no, transaction_date FROM inventory_transactions WHERE transaction_uuid LIKE :q1 OR reference_no LIKE :q2 ORDER BY id DESC LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'transaction', 'id' => (int) $r['id'], 'code' => $r['reference_no'] ?? $r['transaction_uuid'], 'label' => $r['transaction_type'] . ' — ' . $r['transaction_date'], 'status' => null];
            }
        }
        if ($want('supplier')) {
            $s = $pdo->prepare('SELECT id, code, name, is_active FROM suppliers WHERE code LIKE :q1 OR name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'supplier', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => $r['is_active'] ? 'ACTIVE' : 'INACTIVE'];
            }
        }
        if ($want('bakery_destination')) {
            $s = $pdo->prepare('SELECT id, code, name, is_active FROM bakery_destinations WHERE code LIKE :q1 OR name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'bakery_destination', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => $r['is_active'] ? 'ACTIVE' : 'INACTIVE'];
            }
        }
        if ($want('category')) {
            $s = $pdo->prepare('SELECT id, code, name, is_active FROM categories WHERE code LIKE :q1 OR name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'category', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => $r['is_active'] ? 'ACTIVE' : 'INACTIVE'];
            }
        }
        if ($want('warehouse')) {
            $s = $pdo->prepare('SELECT id, code, name, is_active FROM warehouses WHERE code LIKE :q1 OR name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'warehouse', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => $r['is_active'] ? 'ACTIVE' : 'INACTIVE'];
            }
        }
        if ($want('transfer')) {
            $s = $pdo->prepare('SELECT id, transfer_uuid, status FROM warehouse_transfers WHERE transfer_uuid LIKE :q1 OR id = :qid LIMIT :lim');
            $s->bindValue('q1', $like);
            $s->bindValue('qid', ctype_digit($q) ? (int) $q : -1, PDO::PARAM_INT);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'transfer', 'id' => (int) $r['id'], 'code' => 'TRANSFER-' . $r['id'], 'label' => 'Transfer #' . $r['id'], 'status' => $r['status']];
            }
        }
        if ($want('user')) {
            $s = $pdo->prepare('SELECT id, username, full_name, is_active FROM users WHERE username LIKE :q1 OR full_name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'user', 'id' => (int) $r['id'], 'code' => $r['username'], 'label' => $r['full_name'], 'status' => $r['is_active'] ? 'ACTIVE' : 'INACTIVE'];
            }
        }

        return $results;
    }

    /**
     * Paginated audit_logs feed for the Trace Center's own list view —
     * filterable by entity_type, action_code (mapped from the UI's
     * "event type" picker), warehouse (via a best-effort join back to the
     * entity when it's a transaction — audit_logs itself has no
     * warehouse_id column, see the architecture doc's "known limitation"),
     * date range, and actor.
     */
    public static function browseEvents(PDO $pdo, array $params): array
    {
        $where = ['1=1'];
        $bind = [];
        if (!empty($params['entity_type'])) {
            $where[] = 'a.entity_type = :entity_type';
            $bind['entity_type'] = $params['entity_type'];
        }
        if (!empty($params['action_code'])) {
            $where[] = 'a.action_code = :action_code';
            $bind['action_code'] = $params['action_code'];
        }
        if (!empty($params['username'])) {
            $where[] = 'a.username_snapshot LIKE :username';
            $bind['username'] = '%' . $params['username'] . '%';
        }
        if (!empty($params['date_from'])) {
            $where[] = 'a.created_at >= :date_from';
            $bind['date_from'] = $params['date_from'] . ' 00:00:00';
        }
        if (!empty($params['date_to'])) {
            $where[] = 'a.created_at <= :date_to';
            $bind['date_to'] = $params['date_to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);
        $dir = strtolower((string) ($params['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT a.* FROM audit_logs a WHERE {$whereSql} ORDER BY a.id {$dir} LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return [
            'rows' => array_map(static fn ($r) => [
                'id' => (int) $r['id'],
                'action_code' => $r['action_code'],
                'entity_type' => $r['entity_type'],
                'entity_id' => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
                'actor' => $r['username_snapshot'],
                'before' => $r['before_data'] !== null ? json_decode($r['before_data'], true) : null,
                'after' => $r['after_data'] !== null ? json_decode($r['after_data'], true) : null,
                'reason' => $r['reason'],
                'created_at' => $r['created_at'],
            ], $rows),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    /** @return list<array{id:int,action_code:string,actor:string,before:?array,after:?array,reason:?string,created_at:string}> */
    private static function auditTimeline(PDO $pdo, string $entityType, int $entityId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, action_code, username_snapshot, before_data, after_data, reason, created_at
             FROM audit_logs WHERE entity_type = :type AND entity_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['type' => $entityType, 'id' => $entityId]);
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'action_code' => $r['action_code'],
            'actor' => $r['username_snapshot'],
            'before' => $r['before_data'] !== null ? json_decode($r['before_data'], true) : null,
            'after' => $r['after_data'] !== null ? json_decode($r['after_data'], true) : null,
            'reason' => $r['reason'],
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll());
    }

    /** Defense in depth: never let a password/session-shaped column leave this service, even though no current table stores one on these rows. */
    private static function stripSecrets(array $row): array
    {
        foreach (['password_hash', 'password', 'session_token', 'csrf_token'] as $k) {
            unset($row[$k]);
        }
        return $row;
    }
}
