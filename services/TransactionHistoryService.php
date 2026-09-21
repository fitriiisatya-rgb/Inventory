<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2 3g — GET /reports/transactions and GET /reports/transactions/{id}
 * ("History Transaksi"). A dedicated list endpoint: previously the only
 * read path was InventoryService::ledger() (one item + one warehouse at a
 * time) — this is the "all IN/OUT across items/warehouses, filterable"
 * view the mandatory admin requirements call for.
 *
 * Default scope mirrors InventoryService::ledger()'s own convention:
 * only POSTED transactions (VOID/REVERSED rows are reachable via the
 * per-item ledger's void trail, not duplicated into this list).
 */
final class TransactionHistoryService
{
    private const SORTABLE = ['date' => 't.transaction_date', 'type' => 't.transaction_type', 'item' => 'i.name'];

    /**
     * @param array{warehouse_id: ?int, item_id: ?int, category_id: ?int, transaction_type: ?string,
     *              date_from: ?string, date_to: ?string, supplier_id: ?int, bakery_destination_id: ?int,
     *              q: ?string, page: int, per_page: int, sort: string, dir: string} $params
     */
    public static function list(PDO $pdo, array $params): array
    {
        [$where, $bind] = self::buildFilters($pdo, $params);
        $page = max(1, (int) ($params['page'] ?? 1));
        // PHASE V2.6C: raised from 200 so CSV export (Reports 4/5/11/12) can
        // request "everything matching the current filters" in one page —
        // still a bounded 5000, never truly unbounded.
        $perPage = min(5000, max(1, (int) ($params['per_page'] ?? 50)));
        $sortKey = self::SORTABLE[$params['sort'] ?? 'date'] ?? 't.transaction_date';
        $dir = strtoupper($params['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $baseSql = self::baseSelectSql();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$baseSql} WHERE {$where}) counted");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $rowsStmt = $pdo->prepare("{$baseSql} WHERE {$where} ORDER BY {$sortKey} {$dir}, t.id {$dir}, l.id ASC LIMIT {$perPage} OFFSET {$offset}");
        $rowsStmt->execute($bind);

        return [
            'rows' => array_map(fn ($r) => self::formatRow($r), $rowsStmt->fetchAll()),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    /**
     * Full detail for one transaction — header, every line, FIFO
     * allocations for OUT-type lines, and (only if the caller holds
     * AUDIT_LOG_VIEW) its audit trail.
     */
    public static function detail(PDO $pdo, int $transactionId, bool $includeAudit): array
    {
        $headerStmt = $pdo->prepare(
            "SELECT t.*, w.code AS warehouse_code, w.name AS warehouse_name,
                    s.name AS supplier_name, bd.name AS bakery_destination_name, d.name AS division_name,
                    cu.username AS created_by_username, vu.username AS voided_by_username
             FROM inventory_transactions t
             JOIN warehouses w ON w.id = t.warehouse_id
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             LEFT JOIN bakery_destinations bd ON bd.id = t.bakery_destination_id
             LEFT JOIN divisions d ON d.id = t.division_id
             JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users vu ON vu.id = t.voided_by
             WHERE t.id = :id"
        );
        $headerStmt->execute(['id' => $transactionId]);
        $header = $headerStmt->fetch();
        if ($header === false) {
            throw new NotFoundException("transaction {$transactionId}");
        }

        $linesStmt = $pdo->prepare(
            'SELECT l.*, i.sku, i.name AS item_name_current, u.code AS input_unit_code
             FROM inventory_transaction_lines l
             JOIN items i ON i.id = l.item_id
             JOIN units u ON u.id = l.input_unit_id
             WHERE l.transaction_id = :id
             ORDER BY l.line_no ASC'
        );
        $linesStmt->execute(['id' => $transactionId]);
        $lineRows = $linesStmt->fetchAll();

        $lines = [];
        foreach ($lineRows as $line) {
            $allocations = [];
            $allocStmt = $pdo->prepare(
                'SELECT a.qty_allocated, a.unit_cost_base, a.subtotal, b.received_date, b.id AS batch_id
                 FROM fifo_allocations a JOIN inventory_batches b ON b.id = a.batch_id
                 WHERE a.transaction_line_id = :line_id
                 ORDER BY b.received_date ASC, b.id ASC'
            );
            $allocStmt->execute(['line_id' => $line['id']]);
            foreach ($allocStmt->fetchAll() as $alloc) {
                $allocations[] = [
                    'batch_id' => (int) $alloc['batch_id'],
                    'received_date' => $alloc['received_date'],
                    'qty_allocated' => round((float) $alloc['qty_allocated'], 6),
                    'unit_cost_base' => round((float) $alloc['unit_cost_base'], 4),
                    'subtotal' => round((float) $alloc['subtotal'], 4),
                ];
            }

            $lines[] = [
                'line_id' => (int) $line['id'],
                'line_no' => (int) $line['line_no'],
                'item' => ['id' => (int) $line['item_id'], 'sku' => $line['sku'], 'name' => $line['item_name_current'], 'name_snapshot' => $line['item_name_snapshot']],
                'input_qty' => round((float) $line['input_qty'], 6),
                'input_unit' => ['id' => (int) $line['input_unit_id'], 'code' => $line['input_unit_code']],
                'conversion_factor_snapshot' => round((float) $line['conversion_factor_snapshot'], 6),
                'base_qty' => round((float) $line['base_qty'], 6),
                'unit_price_input' => round((float) $line['unit_price_input'], 4),
                'unit_cost_base' => round((float) $line['unit_cost_base'], 4),
                'subtotal' => round((float) $line['subtotal'], 4),
                'is_price_anomaly' => (bool) $line['is_price_anomaly'],
                'allow_negative_stock' => (bool) $line['allow_negative_stock'],
                'notes' => $line['notes'],
                // Empty for an IN/OPENING-type line (it CREATES a batch, it doesn't consume one) — not an error.
                'fifo_allocations' => $allocations,
            ];
        }

        $result = [
            'transaction_id' => (int) $header['id'],
            'transaction_uuid' => $header['transaction_uuid'],
            'transaction_type' => $header['transaction_type'],
            'transaction_date' => $header['transaction_date'],
            'posting_date' => $header['posting_date'],
            'reference_no' => $header['reference_no'],
            'status' => $header['status'],
            'is_historical' => (int) $header['is_historical_import'] === 1,
            'warehouse' => ['id' => (int) $header['warehouse_id'], 'code' => $header['warehouse_code'], 'name' => $header['warehouse_name']],
            'supplier' => $header['supplier_id'] !== null ? ['id' => (int) $header['supplier_id'], 'name' => $header['supplier_name']] : null,
            'bakery_destination' => $header['bakery_destination_id'] !== null ? ['id' => (int) $header['bakery_destination_id'], 'name' => $header['bakery_destination_name']] : null,
            'division' => $header['division_id'] !== null ? ['id' => (int) $header['division_id'], 'name' => $header['division_name']] : null,
            'created_by' => ['id' => (int) $header['created_by'], 'username' => $header['created_by_username']],
            'void_reason' => $header['void_reason'],
            'voided_by' => $header['voided_by'] !== null ? ['id' => (int) $header['voided_by'], 'username' => $header['voided_by_username']] : null,
            'voided_at' => $header['voided_at'],
            'lines' => $lines,
        ];

        if ($includeAudit) {
            $auditStmt = $pdo->prepare(
                "SELECT username_snapshot, action_code, before_data, after_data, reason, created_at
                 FROM audit_logs WHERE entity_type = 'inventory_transactions' AND entity_id = :id ORDER BY created_at ASC"
            );
            $auditStmt->execute(['id' => $transactionId]);
            $result['audit_log'] = $auditStmt->fetchAll();
        }

        return $result;
    }

    private static function baseSelectSql(): string
    {
        return "SELECT
                t.id AS transaction_id, l.id AS line_id, t.transaction_type, t.transaction_date, t.reference_no,
                t.status, t.is_historical_import,
                w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                i.id AS item_id, i.sku, i.name AS item_name,
                l.input_qty, iu.id AS input_unit_id, iu.code AS input_unit_code,
                l.base_qty, l.unit_cost_base, l.subtotal,
                s.id AS supplier_id, s.name AS supplier_name,
                bd.id AS bakery_destination_id, bd.name AS bakery_destination_name,
                d.id AS division_id, d.name AS division_name,
                cu.id AS created_by_id, cu.username AS created_by_username
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             JOIN warehouses w ON w.id = l.warehouse_id
             JOIN items i ON i.id = l.item_id
             JOIN units iu ON iu.id = l.input_unit_id
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             LEFT JOIN bakery_destinations bd ON bd.id = t.bakery_destination_id
             LEFT JOIN divisions d ON d.id = t.division_id
             JOIN users cu ON cu.id = t.created_by
        ";
    }

    /** @return array{0:string,1:array} [where, bind] */
    private static function buildFilters(PDO $pdo, array $params): array
    {
        $where = ["t.status = 'POSTED'"];
        $bind = [];

        if (($params['warehouse_id'] ?? null) !== null) {
            $where[] = 'l.warehouse_id = :warehouse_id';
            $bind['warehouse_id'] = $params['warehouse_id'];
        }
        if (($params['item_id'] ?? null) !== null) {
            $where[] = 'l.item_id = :item_id';
            $bind['item_id'] = $params['item_id'];
        }
        if (!empty($params['category_id'])) {
            $where[] = 'i.category_id = :category_id';
            $bind['category_id'] = $params['category_id'];
        }
        if (!empty($params['transaction_type'])) {
            $where[] = 't.transaction_type = :transaction_type';
            $bind['transaction_type'] = $params['transaction_type'];
        }
        // PHASE V2.6B — Reports 4/5/11/12 filter by a SET of types (e.g.
        // IN/OUT together, or IN alone for "qualifying purchase") rather
        // than one exact type; kept alongside the single-value filter
        // above (never both passed by the same caller) so the pre-existing
        // "History Transaksi" tab's own single-type filter is untouched.
        if (!empty($params['transaction_types']) && is_array($params['transaction_types'])) {
            $placeholders = [];
            foreach (array_values($params['transaction_types']) as $i => $type) {
                $key = "ttype{$i}";
                $placeholders[] = ":{$key}";
                $bind[$key] = $type;
            }
            $where[] = 't.transaction_type IN (' . implode(',', $placeholders) . ')';
        }
        if (isset($params['is_historical_import'])) {
            $where[] = 't.is_historical_import = :is_hist';
            $bind['is_hist'] = $params['is_historical_import'] ? 1 : 0;
        }
        if (!empty($params['date_from'])) {
            $where[] = 't.transaction_date >= :date_from';
            $bind['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 't.transaction_date <= :date_to';
            $bind['date_to'] = $params['date_to'];
        }
        if (!empty($params['supplier_id'])) {
            $where[] = 't.supplier_id = :supplier_id';
            $bind['supplier_id'] = $params['supplier_id'];
        }
        if (!empty($params['bakery_destination_id'])) {
            $where[] = 't.bakery_destination_id = :bakery_destination_id';
            $bind['bakery_destination_id'] = $params['bakery_destination_id'];
        }
        $q = trim((string) ($params['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(t.reference_no LIKE :q_ref OR i.name LIKE :q_name OR i.sku LIKE :q_sku)';
            $bind['q_ref'] = '%' . $q . '%';
            $bind['q_name'] = '%' . $q . '%';
            $bind['q_sku'] = '%' . $q . '%';
        }

        return [implode(' AND ', $where), $bind];
    }

    private static function formatRow(array $r): array
    {
        return [
            'transaction_id' => (int) $r['transaction_id'],
            'line_id' => (int) $r['line_id'],
            'transaction_type' => $r['transaction_type'],
            'transaction_date' => $r['transaction_date'],
            'reference_no' => $r['reference_no'],
            'status' => $r['status'],
            'is_historical' => (int) $r['is_historical_import'] === 1,
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'item' => ['id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name']],
            'input_qty' => round((float) $r['input_qty'], 6),
            'input_unit' => ['id' => (int) $r['input_unit_id'], 'code' => $r['input_unit_code']],
            'base_qty' => round((float) $r['base_qty'], 6),
            'unit_cost_base' => round((float) $r['unit_cost_base'], 4),
            'subtotal' => round((float) $r['subtotal'], 4),
            'supplier' => $r['supplier_id'] !== null ? ['id' => (int) $r['supplier_id'], 'name' => $r['supplier_name']] : null,
            'bakery_destination' => $r['bakery_destination_id'] !== null ? ['id' => (int) $r['bakery_destination_id'], 'name' => $r['bakery_destination_name']] : null,
            'division' => $r['division_id'] !== null ? ['id' => (int) $r['division_id'], 'name' => $r['division_name']] : null,
            'created_by' => ['id' => (int) $r['created_by_id'], 'username' => $r['created_by_username']],
        ];
    }

    /**
     * PHASE V2.6B — reused by Report 4 (Laporan Pembelian) and Report 5
     * (Laporan IN/OUT). Live totals (`total_value`/`transaction_count`/
     * `counterparty_count`) NEVER include a historical-import row —
     * `is_historical_import=1` is excluded unconditionally here, distinct
     * from `list()` above which still surfaces historical rows (tagged
     * `is_historical`) for on-request audit visibility. Same
     * buildFilters()/baseSelectSql() as list() — no second query engine.
     *
     * @param string $counterpartyColumn 'supplier_id' or 'bakery_destination_id' — null-safe, counts DISTINCT non-null values only.
     */
    public static function summary(PDO $pdo, array $params, string $counterpartyColumn = 'supplier_id'): array
    {
        [$where, $bind] = self::buildFilters($pdo, $params);
        $where .= ' AND t.is_historical_import = 0';
        $baseSql = self::baseSelectSql();

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT transaction_id) AS transaction_count,
                    COUNT(DISTINCT {$counterpartyColumn}) AS counterparty_count,
                    COALESCE(SUM(subtotal), 0) AS total_value
             FROM ({$baseSql} WHERE {$where}) counted"
        );
        $stmt->execute($bind);
        $row = $stmt->fetch() ?: [];

        $transactionCount = (int) ($row['transaction_count'] ?? 0);
        $totalValue = round((float) ($row['total_value'] ?? 0), 4);

        return [
            'total_value' => $totalValue,
            'transaction_count' => $transactionCount,
            'counterparty_count' => (int) ($row['counterparty_count'] ?? 0),
            'average_transaction_value' => $transactionCount > 0 ? round($totalValue / $transactionCount, 4) : 0.0,
        ];
    }

    /**
     * PHASE V2.6B — Report 11 (Pembelian per Supplier) / Report 12
     * (Distribusi per Bakery): one row per counterparty. Same historical
     * exclusion as summary() above. `$nameSql` must be a single column
     * expression already present in baseSelectSql()'s SELECT list (e.g.
     * `'supplier_name'` or `'bakery_destination_name'`).
     *
     * @param string $groupColumn 'supplier_id' or 'bakery_destination_id'
     * @param string $nameColumn 'supplier_name' or 'bakery_destination_name'
     * @return list<array{id:int, name:string, transaction_count:int, total_value:float, average_value:float, unique_sku:int, latest_date:string}>
     */
    public static function groupedSummary(PDO $pdo, array $params, string $groupColumn, string $nameColumn): array
    {
        [$where, $bind] = self::buildFilters($pdo, $params);
        $where .= ' AND t.is_historical_import = 0';
        $baseSql = self::baseSelectSql();

        // `{$groupColumn}`/`{$nameColumn}` (e.g. supplier_id/supplier_name)
        // are SELECT-list aliases of baseSelectSql() — only usable OUTSIDE
        // that query's own WHERE, hence the NOT NULL filter is applied here
        // at the outer level (against the `counted` derived table's real
        // columns), never folded into `$where` above.
        $stmt = $pdo->prepare(
            "SELECT {$groupColumn} AS group_id, {$nameColumn} AS group_name,
                    COUNT(DISTINCT transaction_id) AS transaction_count,
                    COUNT(DISTINCT item_id) AS unique_sku,
                    COALESCE(SUM(subtotal), 0) AS total_value,
                    MAX(transaction_date) AS latest_date
             FROM ({$baseSql} WHERE {$where}) counted
             WHERE {$groupColumn} IS NOT NULL
             GROUP BY {$groupColumn}, {$nameColumn}
             ORDER BY total_value DESC"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        $grandTotal = array_sum(array_map(static fn ($r) => (float) $r['total_value'], $rows));

        return array_map(static function (array $r) use ($grandTotal) {
            $count = (int) $r['transaction_count'];
            $value = round((float) $r['total_value'], 4);
            return [
                'id' => (int) $r['group_id'],
                'name' => $r['group_name'],
                'transaction_count' => $count,
                'total_value' => $value,
                'average_value' => $count > 0 ? round($value / $count, 4) : 0.0,
                'unique_sku' => (int) $r['unique_sku'],
                'latest_date' => $r['latest_date'],
                'share_pct' => $grandTotal > 0 ? round($value / $grandTotal * 100, 2) : 0.0,
            ];
        }, $rows);
    }
}
