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
        // PHASE V2.2B — the 7 previously-dead-end entities from the coverage
        // gap: opname, production, opening, import, role (transfer already
        // existed above). Same whitelisted-lookup pattern, nothing generic.
        if ($want('opname')) {
            $s = $pdo->prepare('SELECT id, session_uuid, status FROM stock_opname_sessions WHERE session_uuid LIKE :q1 OR id = :qid ORDER BY id DESC LIMIT :lim');
            $s->bindValue('q1', $like);
            $s->bindValue('qid', ctype_digit($q) ? (int) $q : -1, PDO::PARAM_INT);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'opname', 'id' => (int) $r['id'], 'code' => 'OPNAME-' . $r['id'], 'label' => 'Opname #' . $r['id'], 'status' => $r['status']];
            }
        }
        if ($want('production')) {
            $s = $pdo->prepare('SELECT id, production_uuid, status FROM production_headers WHERE production_uuid LIKE :q1 OR id = :qid ORDER BY id DESC LIMIT :lim');
            $s->bindValue('q1', $like);
            $s->bindValue('qid', ctype_digit($q) ? (int) $q : -1, PDO::PARAM_INT);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'production', 'id' => (int) $r['id'], 'code' => 'PRODUCTION-' . $r['id'], 'label' => 'Produksi #' . $r['id'], 'status' => $r['status']];
            }
        }
        if ($want('opening')) {
            $s = $pdo->prepare('SELECT id, description, status, cutoff_date FROM stock_openings WHERE description LIKE :q1 OR id = :qid ORDER BY id DESC LIMIT :lim');
            $s->bindValue('q1', $like);
            $s->bindValue('qid', ctype_digit($q) ? (int) $q : -1, PDO::PARAM_INT);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'opening', 'id' => (int) $r['id'], 'code' => 'OPENING-' . $r['id'], 'label' => ($r['description'] ?? ('Opening ' . $r['cutoff_date'])), 'status' => $r['status']];
            }
        }
        if ($want('import')) {
            $s = $pdo->prepare('SELECT id, file_name, import_type, status FROM import_batches WHERE file_name LIKE :q1 OR id = :qid ORDER BY id DESC LIMIT :lim');
            $s->bindValue('q1', $like);
            $s->bindValue('qid', ctype_digit($q) ? (int) $q : -1, PDO::PARAM_INT);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'import', 'id' => (int) $r['id'], 'code' => $r['import_type'], 'label' => $r['file_name'], 'status' => $r['status']];
            }
        }
        if ($want('role')) {
            $s = $pdo->prepare('SELECT id, code, name FROM roles WHERE code LIKE :q1 OR name LIKE :q2 LIMIT :lim');
            $s->bindValue('q1', $like); $s->bindValue('q2', $like);
            $s->bindValue('lim', $limit, PDO::PARAM_INT);
            $s->execute();
            foreach ($s->fetchAll() as $r) {
                $results[] = ['type' => 'role', 'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['name'], 'status' => null];
            }
        }

        return $results;
    }

    /**
     * Full bidirectional transfer chain: header (who created/received/
     * cancelled and when), every line's FIFO consumption on the source
     * warehouse (fifo_allocations -> source batches) and the batch it
     * created on the destination warehouse once received, plus the
     * TRANSFER_OUT/TRANSFER_IN transaction headers cross-referenced the
     * same way transactionTrace() already does (reference_no = 'TRANSFER-{id}').
     */
    public static function transferTrace(PDO $pdo, int $transferId): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.*, fw.code AS from_warehouse_code, fw.name AS from_warehouse_name,
                    tw.code AS to_warehouse_code, tw.name AS to_warehouse_name,
                    cu.username AS created_by_username, ru.username AS received_by_username,
                    xu.username AS cancelled_by_username, vu.username AS reversed_by_username
             FROM warehouse_transfers t
             JOIN warehouses fw ON fw.id = t.from_warehouse_id
             JOIN warehouses tw ON tw.id = t.to_warehouse_id
             LEFT JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users ru ON ru.id = t.received_by
             LEFT JOIN users xu ON xu.id = t.cancelled_by
             LEFT JOIN users vu ON vu.id = t.reversed_by
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $transferId]);
        $transfer = $stmt->fetch();
        if ($transfer === false) {
            throw new NotFoundException("transfer {$transferId}");
        }

        $lineStmt = $pdo->prepare(
            'SELECT l.*, i.sku, i.name AS item_name
             FROM warehouse_transfer_lines l JOIN items i ON i.id = l.item_id
             WHERE l.transfer_id = :id ORDER BY l.id'
        );
        $lineStmt->execute(['id' => $transferId]);
        $lines = $lineStmt->fetchAll();

        $lineDetails = [];
        foreach ($lines as $line) {
            $outAllocations = [];
            $inBatch = null;
            if ($line['out_transaction_line_id'] !== null) {
                $allocStmt = $pdo->prepare(
                    'SELECT a.*, b.received_date, b.warehouse_id AS batch_warehouse_id
                     FROM fifo_allocations a JOIN inventory_batches b ON b.id = a.batch_id
                     WHERE a.transaction_line_id = :id ORDER BY a.id'
                );
                $allocStmt->execute(['id' => $line['out_transaction_line_id']]);
                $outAllocations = $allocStmt->fetchAll();
            }
            if ($line['in_transaction_line_id'] !== null) {
                $batchStmt = $pdo->prepare('SELECT * FROM inventory_batches WHERE source_transaction_line_id = :id');
                $batchStmt->execute(['id' => $line['in_transaction_line_id']]);
                $inBatch = $batchStmt->fetch() ?: null;
            }
            $lineDetails[] = ['line' => $line, 'out_fifo_allocations' => $outAllocations, 'destination_batch' => $inBatch];
        }

        $txStmt = $pdo->prepare(
            'SELECT id, transaction_type, transaction_date, status, warehouse_id
             FROM inventory_transactions WHERE reference_no = :ref ORDER BY id'
        );
        $txStmt->execute(['ref' => 'TRANSFER-' . $transferId]);

        return [
            'entity' => ['type' => 'transfer', 'id' => $transferId],
            'transfer' => $transfer,
            'lines' => $lineDetails,
            'transactions' => $txStmt->fetchAll(),
            'audit_events' => self::auditTimeline($pdo, 'warehouse_transfers', $transferId),
        ];
    }

    /**
     * Stock Opname chain: session header (counted/finalized/posted/cancelled
     * by+when), every counted line (system vs physical qty, variance), and
     * the resulting stock_adjustments row + its ADJUSTMENT transaction for
     * any line whose variance was actually posted.
     */
    public static function opnameTrace(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT s.*, w.code AS warehouse_code, w.name AS warehouse_name,
                    cu.username AS created_by_username, fu.username AS finalized_by_username,
                    pu.username AS posted_by_username, xu.username AS cancelled_by_username
             FROM stock_opname_sessions s
             JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN users cu ON cu.id = s.created_by
             LEFT JOIN users fu ON fu.id = s.finalized_by
             LEFT JOIN users pu ON pu.id = s.posted_by
             LEFT JOIN users xu ON xu.id = s.cancelled_by
             WHERE s.id = :id'
        );
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch();
        if ($session === false) {
            throw new NotFoundException("opname session {$sessionId}");
        }

        $lineStmt = $pdo->prepare(
            'SELECT l.*, i.sku, i.name AS item_name
             FROM stock_opname_lines l JOIN items i ON i.id = l.item_id
             WHERE l.session_id = :id ORDER BY l.id'
        );
        $lineStmt->execute(['id' => $sessionId]);
        $lines = $lineStmt->fetchAll();

        $lineDetails = [];
        foreach ($lines as $line) {
            $adjustment = null;
            if ($line['adjustment_id'] !== null) {
                $s = $pdo->prepare(
                    'SELECT sa.*, t.transaction_uuid, t.status AS transaction_status
                     FROM stock_adjustments sa LEFT JOIN inventory_transactions t ON t.id = sa.transaction_id
                     WHERE sa.id = :id'
                );
                $s->execute(['id' => $line['adjustment_id']]);
                $adjustment = $s->fetch() ?: null;
            }
            $lineDetails[] = ['line' => $line, 'resulting_adjustment' => $adjustment];
        }

        return [
            'entity' => ['type' => 'opname', 'id' => $sessionId],
            'session' => $session,
            'lines' => $lineDetails,
            'audit_events' => self::auditTimeline($pdo, 'stock_opname_sessions', $sessionId),
        ];
    }

    /**
     * Production chain both directions: raw-material inputs (their FIFO
     * allocations -> source batches -> actual FIFO cost) and finished-goods
     * outputs (the batch each output created).
     */
    public static function productionTrace(PDO $pdo, int $productionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.*, w.code AS warehouse_code, w.name AS warehouse_name,
                    d.code AS division_code, d.name AS division_name,
                    u.username AS created_by_username
             FROM production_headers p
             JOIN warehouses w ON w.id = p.warehouse_id
             LEFT JOIN divisions d ON d.id = p.division_id
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $productionId]);
        $header = $stmt->fetch();
        if ($header === false) {
            throw new NotFoundException("production {$productionId}");
        }

        $inputStmt = $pdo->prepare(
            'SELECT pi.*, i.sku, i.name AS item_name
             FROM production_inputs pi JOIN items i ON i.id = pi.item_id
             WHERE pi.production_id = :id ORDER BY pi.id'
        );
        $inputStmt->execute(['id' => $productionId]);
        $inputDetails = [];
        foreach ($inputStmt->fetchAll() as $input) {
            $allocStmt = $pdo->prepare(
                'SELECT a.*, b.received_date FROM fifo_allocations a JOIN inventory_batches b ON b.id = a.batch_id
                 WHERE a.transaction_line_id = :id ORDER BY a.id'
            );
            $allocStmt->execute(['id' => $input['transaction_line_id']]);
            $inputDetails[] = ['input' => $input, 'fifo_allocations' => $allocStmt->fetchAll()];
        }

        $outputStmt = $pdo->prepare(
            'SELECT po.*, i.sku, i.name AS item_name
             FROM production_outputs po JOIN items i ON i.id = po.item_id
             WHERE po.production_id = :id ORDER BY po.id'
        );
        $outputStmt->execute(['id' => $productionId]);
        $outputDetails = [];
        foreach ($outputStmt->fetchAll() as $output) {
            $batchStmt = $pdo->prepare('SELECT * FROM inventory_batches WHERE source_transaction_line_id = :id');
            $batchStmt->execute(['id' => $output['transaction_line_id']]);
            $outputDetails[] = ['output' => $output, 'created_batch' => $batchStmt->fetch() ?: null];
        }

        return [
            'entity' => ['type' => 'production', 'id' => $productionId],
            'production' => $header,
            'inputs' => $inputDetails,
            'outputs' => $outputDetails,
            'audit_events' => self::auditTimeline($pdo, 'production_headers', $productionId),
        ];
    }

    /**
     * Opening Stock chain — deliberately keeps the historical STAGING record
     * (stock_openings/stock_opening_lines: what was imported/reported, by
     * whom, when) visually separate from the LIVE operational baseline it
     * produced (inventory_batches / the OPENING-type inventory_transactions
     * row FIFO still reads from today), per the spec's explicit "never
     * change opening economics" instruction — this method only ever reads.
     */
    public static function openingTrace(PDO $pdo, int $openingId): array
    {
        $stmt = $pdo->prepare(
            'SELECT so.*, cu.username AS created_by_username, xu.username AS committed_by_username
             FROM stock_openings so
             LEFT JOIN users cu ON cu.id = so.created_by
             LEFT JOIN users xu ON xu.id = so.committed_by
             WHERE so.id = :id'
        );
        $stmt->execute(['id' => $openingId]);
        $opening = $stmt->fetch();
        if ($opening === false) {
            throw new NotFoundException("opening {$openingId}");
        }

        $lineStmt = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name AS item_name, w.code AS warehouse_code, w.name AS warehouse_name
             FROM stock_opening_lines sol
             LEFT JOIN items i ON i.id = sol.item_id
             LEFT JOIN warehouses w ON w.id = sol.warehouse_id
             WHERE sol.stock_opening_id = :id ORDER BY sol.id'
        );
        $lineStmt->execute(['id' => $openingId]);

        $lineDetails = [];
        foreach ($lineStmt->fetchAll() as $line) {
            $liveBatch = null;
            $liveTransaction = null;
            if ($line['created_batch_id'] !== null) {
                $bStmt = $pdo->prepare('SELECT * FROM inventory_batches WHERE id = :id');
                $bStmt->execute(['id' => $line['created_batch_id']]);
                $liveBatch = $bStmt->fetch() ?: null;
                if ($liveBatch !== null && $liveBatch['source_transaction_line_id'] !== null) {
                    $tStmt = $pdo->prepare(
                        'SELECT t.id, t.transaction_uuid, t.transaction_type, t.transaction_date, t.status
                         FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
                         WHERE l.id = :id'
                    );
                    $tStmt->execute(['id' => $liveBatch['source_transaction_line_id']]);
                    $liveTransaction = $tStmt->fetch() ?: null;
                }
            }
            $lineDetails[] = ['staging_line' => $line, 'live_fifo_batch' => $liveBatch, 'live_opening_transaction' => $liveTransaction];
        }

        return [
            'entity' => ['type' => 'opening', 'id' => $openingId],
            'opening' => $opening,
            'lines' => $lineDetails,
            'audit_events' => self::auditTimeline($pdo, 'stock_openings', $openingId),
        ];
    }

    /**
     * Import batch chain (MASTER_ITEM/SUPPLIER/DIVISION/WAREHOUSE/
     * HISTORICAL_TRANSACTION — Opening Stock's own import has a dedicated,
     * richer trace in openingTrace() instead of import_batches). Paginated
     * row list carries each row's own validation status/messages and, for
     * an accepted row, the id of the master/transaction record it created.
     */
    public static function importTrace(PDO $pdo, int $importBatchId, int $page = 1, int $perPage = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT ib.*, uu.username AS uploaded_by_username, cu.username AS committed_by_username
             FROM import_batches ib
             LEFT JOIN users uu ON uu.id = ib.uploaded_by
             LEFT JOIN users cu ON cu.id = ib.committed_by
             WHERE ib.id = :id'
        );
        $stmt->execute(['id' => $importBatchId]);
        $batch = $stmt->fetch();
        if ($batch === false) {
            throw new NotFoundException("import batch {$importBatchId}");
        }

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM import_rows WHERE import_batch_id = :id');
        $totalStmt->execute(['id' => $importBatchId]);
        $total = (int) $totalStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $rowStmt = $pdo->prepare('SELECT * FROM import_rows WHERE import_batch_id = :id ORDER BY row_no ASC LIMIT :lim OFFSET :off');
        $rowStmt->bindValue('id', $importBatchId, PDO::PARAM_INT);
        $rowStmt->bindValue('lim', $perPage, PDO::PARAM_INT);
        $rowStmt->bindValue('off', $offset, PDO::PARAM_INT);
        $rowStmt->execute();
        $rows = array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'row_no' => (int) $r['row_no'],
            'raw_data' => json_decode((string) $r['raw_data'], true),
            'row_status' => $r['row_status'],
            'messages' => $r['messages'] !== null ? json_decode((string) $r['messages'], true) : null,
            'created_entity_id' => $r['created_entity_id'] !== null ? (int) $r['created_entity_id'] : null,
        ], $rowStmt->fetchAll());

        return [
            'entity' => ['type' => 'import', 'id' => $importBatchId],
            'import_batch' => $batch,
            'rows' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))],
            'audit_events' => self::auditTimeline($pdo, 'import_batches', $importBatchId),
        ];
    }

    /**
     * User trace — SUPERADMIN/ADMIN only (enforced by the route, not here,
     * matching this service's existing division of responsibility). Never
     * returns password_hash or any session/credential-shaped field
     * (stripSecrets()). login_attempts is a real, separate table (by
     * username, not user_id) that predates this phase and is included as
     * genuine login activity evidence.
     *
     * Honest gap: no code path in this app ever wrote an audit_logs row for
     * user create/activate/deactivate/role-change/warehouse-reassignment
     * before this phase (accounts are provisioned by a CLI script — see
     * scripts/provision_user.php — which is now instrumented to log
     * USER_PROVISION going forward). Older account history is therefore
     * genuinely unavailable, not merely unqueried; this is surfaced to the
     * caller as `historical_note` rather than silently showing an empty
     * timeline with no explanation.
     */
    public static function userTrace(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name,
                    d.code AS division_code, d.name AS division_name,
                    w.code AS warehouse_code, w.name AS warehouse_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN divisions d ON d.id = u.division_id
             LEFT JOIN warehouses w ON w.id = u.warehouse_id
             WHERE u.id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            throw new NotFoundException("user {$userId}");
        }

        $loginStmt = $pdo->prepare(
            'SELECT id, ip_address, success, created_at FROM login_attempts WHERE username = :u ORDER BY id DESC LIMIT 50'
        );
        $loginStmt->execute(['u' => $user['username']]);

        $timeline = self::auditTimeline($pdo, 'users', $userId);

        return [
            'entity' => ['type' => 'user', 'id' => $userId],
            'overview' => self::stripSecrets($user),
            'login_history' => $loginStmt->fetchAll(),
            'timeline' => $timeline,
            'historical_data_limited' => $timeline === [],
            'historical_note' => 'Perubahan akun (pembuatan/aktivasi/nonaktif/ubah role/ubah gudang) sebelum fase ini tidak tercatat di audit_logs — akun dibuat lewat skrip CLI, bukan lewat aplikasi. Event baru (mulai fase ini) tercatat penuh. Riwayat login (tabel login_attempts) tersedia sejak awal dan ditampilkan di atas.',
        ];
    }

    /**
     * Role/permission trace. role_permissions in this app is exclusively
     * schema-seeded (database/schema.sql) — there is no code path anywhere
     * in the application that ever changes a role's permission set at
     * runtime, so "what permissions does this role have right now" is
     * always exactly what a change-history would show anyway; there is no
     * silent drift to hide.
     */
    public static function roleTrace(PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch();
        if ($role === false) {
            throw new NotFoundException("role {$roleId}");
        }

        $permStmt = $pdo->prepare(
            'SELECT p.id, p.code, p.description
             FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :id ORDER BY p.code'
        );
        $permStmt->execute(['id' => $roleId]);

        $userCountStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = :id');
        $userCountStmt->execute(['id' => $roleId]);

        return [
            'entity' => ['type' => 'role', 'id' => $roleId],
            'role' => $role,
            'permissions' => $permStmt->fetchAll(),
            'assigned_user_count' => (int) $userCountStmt->fetchColumn(),
            'timeline' => self::auditTimeline($pdo, 'roles', $roleId),
            'historical_note' => 'Penetapan permission per role di-seed lewat skema database, bukan lewat UI — tidak ada mekanisme aplikasi untuk mengubahnya saat runtime, sehingga tidak ada riwayat perubahan yang mungkin hilang. Daftar permission di atas adalah yang berlaku saat ini.',
        ];
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
