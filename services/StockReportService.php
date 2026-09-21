<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2 3f — GET /reports/stock ("Laporan Stok"): the complete,
 * paginated, filterable, ALL-items-including-zero-stock report the
 * mandatory admin requirements call for. Deliberately a NEW endpoint
 * rather than a change to GET /items — see
 * docs/PHASE_V2_TECHNICAL_DESIGN.md Section 7.1 for why (GET /items stays
 * exactly as-is, still used by every existing dropdown/master-cache
 * consumer).
 *
 * Single aggregate query per call (plus one summary-totals query and one
 * count query) — never a per-item loop. Status is computed as SQL
 * mirroring StockPolicyService::stockStatus() EXACTLY (so filtering/
 * sorting by status can happen at the DB level while still paginating in
 * SQL) — see tests/stock_report_test.php's cross-check between the two
 * implementations; if stockStatus() ever changes, this SQL CASE must
 * change with it.
 */
final class StockReportService
{
    private const SORTABLE = ['name' => 'i.name', 'sku' => 'i.sku', 'qty' => 'qty_base', 'value' => 'value', 'status' => 'status', 'updated_at' => 'i.updated_at'];

    /**
     * @param array{warehouse_id: ?int, category_id: ?int, q: ?string, status: ?string,
     *              include_zero_stock: bool, active_only: bool, page: int, per_page: int,
     *              sort: string, dir: string, supplier_id: ?int, item_status: ?string} $params
     *
     * PHASE V2.1 additions (both optional, fully backward compatible — every
     * existing caller that omits them gets byte-identical behavior to
     * before): `supplier_id` filters to items whose default_supplier_id
     * matches; `item_status` ('ACTIVE'|'INACTIVE') overrides `active_only`
     * when provided, letting a caller ask for inactive items specifically
     * (Master Barang's 3-way Semua/Aktif/Tidak Aktif filter) rather than
     * only ever "active" vs "everything."
     */
    public static function list(PDO $pdo, array $params): array
    {
        $warehouseId = $params['warehouse_id'] ?? null;
        $categoryId = $params['category_id'] ?? null;
        $q = trim((string) ($params['q'] ?? ''));
        $statusFilter = $params['status'] ?? null;
        $includeZeroStock = $params['include_zero_stock'] ?? true;
        $activeOnly = $params['active_only'] ?? true;
        $supplierId = $params['supplier_id'] ?? null;
        $itemStatus = $params['item_status'] ?? null;
        $stockStatusComposite = $params['stock_status'] ?? null;
        $reportStatus = $params['report_status'] ?? null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));
        $sortKey = self::SORTABLE[$params['sort'] ?? 'name'] ?? 'i.name';
        $dir = strtoupper($params['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        [$select, $joins, $where, $having, $bind] = self::buildQuery($pdo, $warehouseId, $categoryId, $q, $activeOnly, $includeZeroStock, $statusFilter, $supplierId, $itemStatus, $stockStatusComposite, $reportStatus);

        // Must select the full aliased column list (not just i.id) when HAVING
        // references computed aliases like qty_base/status.
        $countSql = "SELECT COUNT(*) FROM (SELECT {$select} {$joins} WHERE {$where} " . ($having !== '' ? "HAVING {$having}" : '') . ') counted';
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $rowsSql = "SELECT {$select} {$joins} WHERE {$where} " . ($having !== '' ? "HAVING {$having}" : '') . " ORDER BY {$sortKey} {$dir}, i.id ASC LIMIT {$perPage} OFFSET {$offset}";
        $rowsStmt = $pdo->prepare($rowsSql);
        $rowsStmt->execute($bind);
        $rawRows = $rowsStmt->fetchAll();

        $rows = array_map(fn ($r) => self::formatRow($r), $rawRows);

        $summarySql = "SELECT
                COUNT(*) AS total_items,
                COALESCE(SUM(qty_base), 0) AS total_qty,
                COALESCE(SUM(value), 0) AS total_value,
                SUM(CASE WHEN qty_base > 0 THEN 1 ELSE 0 END) AS items_with_stock,
                SUM(CASE WHEN status = 'OUT_OF_STOCK' THEN 1 ELSE 0 END) AS out_of_stock_count,
                SUM(CASE WHEN status = 'CRITICAL' THEN 1 ELSE 0 END) AS critical_count,
                SUM(CASE WHEN status = 'LOW' THEN 1 ELSE 0 END) AS low_count,
                SUM(CASE WHEN status = 'SAFE' THEN 1 ELSE 0 END) AS safe_count,
                SUM(CASE WHEN status = 'MIGRATION_NEGATIVE_REVIEW' THEN 1 ELSE 0 END) AS review_count
             FROM (SELECT {$select} {$joins} WHERE {$where} " . ($having !== '' ? "HAVING {$having}" : '') . ') summarized';
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($bind);
        $summaryRow = $summaryStmt->fetch();

        return [
            'warehouse_id' => $warehouseId,
            'generated_at' => date('c'),
            'rows' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
            'summary' => [
                'total_items' => (int) $summaryRow['total_items'],
                'total_qty' => round((float) $summaryRow['total_qty'], 6),
                'total_value' => round((float) $summaryRow['total_value'], 4),
                'items_with_stock' => (int) $summaryRow['items_with_stock'],
                'out_of_stock_count' => (int) $summaryRow['out_of_stock_count'],
                'critical_count' => (int) $summaryRow['critical_count'],
                'low_count' => (int) $summaryRow['low_count'],
                'safe_count' => (int) $summaryRow['safe_count'],
                'review_count' => (int) $summaryRow['review_count'],
            ],
        ];
    }

    /** Every row matching the current filters, unpaginated — for CSV export. Same query shape as list(). */
    public static function exportAll(PDO $pdo, array $params): array
    {
        $warehouseId = $params['warehouse_id'] ?? null;
        $categoryId = $params['category_id'] ?? null;
        $q = trim((string) ($params['q'] ?? ''));
        $statusFilter = $params['status'] ?? null;
        $includeZeroStock = $params['include_zero_stock'] ?? true;
        $activeOnly = $params['active_only'] ?? true;
        $supplierId = $params['supplier_id'] ?? null;
        $itemStatus = $params['item_status'] ?? null;
        $stockStatusComposite = $params['stock_status'] ?? null;
        $reportStatus = $params['report_status'] ?? null;

        [$select, $joins, $where, $having, $bind] = self::buildQuery($pdo, $warehouseId, $categoryId, $q, $activeOnly, $includeZeroStock, $statusFilter, $supplierId, $itemStatus, $stockStatusComposite, $reportStatus);
        $sql = "SELECT {$select} {$joins} WHERE {$where} " . ($having !== '' ? "HAVING {$having}" : '') . ' ORDER BY i.name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return array_map(fn ($r) => self::formatRow($r), $stmt->fetchAll());
    }

    /**
     * PHASE V2.6D — "Semua Produk / Aman / Warning / Habis" counters for
     * Laporan Stok. Deliberately its own query rather than reusing list()'s
     * summary block: those existing counters are scoped to the SAME
     * having-filters as the current view (so e.g. filtering to one 5-tier
     * `status` collapses the others to 0) — exactly right for that block,
     * but wrong here, since these 4 counters must stay stable reference
     * points while only the table itself narrows when one is clicked. So
     * this intentionally reuses buildQuery()'s $select/$joins/$where (the
     * warehouse/category/search/product-status scope) but ignores its
     * $having entirely — no report_status, no legacy status/stock_status
     * filter ever narrows the counts themselves. One aggregate query, full
     * filtered population, never just the current page.
     *
     * @return array{total:int, aman:int, warning:int, habis:int}
     */
    public static function statusCounts(PDO $pdo, array $params): array
    {
        $warehouseId = $params['warehouse_id'] ?? null;
        $categoryId = $params['category_id'] ?? null;
        $q = trim((string) ($params['q'] ?? ''));
        $activeOnly = $params['active_only'] ?? true;
        $supplierId = $params['supplier_id'] ?? null;
        $itemStatus = $params['item_status'] ?? null;

        [$select, $joins, $where, , $bind] = self::buildQuery($pdo, $warehouseId, $categoryId, $q, $activeOnly, true, null, $supplierId, $itemStatus, null, null);
        $sql = "SELECT report_status, COUNT(*) AS cnt FROM (SELECT {$select} {$joins} WHERE {$where}) counted GROUP BY report_status";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        $counts = ['total' => 0, 'aman' => 0, 'warning' => 0, 'habis' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $n = (int) $row['cnt'];
            $counts['total'] += $n;
            $key = strtolower((string) $row['report_status']);
            if (isset($counts[$key])) {
                $counts[$key] = $n;
            }
        }
        return $counts;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:array} [select, joins, where, having, bind] */
    private static function buildQuery(PDO $pdo, ?int $warehouseId, ?int $categoryId, string $q, bool $activeOnly, bool $includeZeroStock, ?string $statusFilter, ?int $supplierId = null, ?string $itemStatus = null, ?string $stockStatusComposite = null, ?string $reportStatus = null): array
    {
        $bind = [];

        // Migration-negative (item_id, warehouse_id) pairs — small, bounded set (historically ~8 rows),
        // resolved once here rather than per-row, then injected as a plain IN-list.
        $migrationNegativeItemIds = [];
        foreach (MigrationNegativeStockService::reviewList($pdo) as $row) {
            if (($row['status'] ?? '') !== 'MIGRATION_NEGATIVE_REVIEW' || $row['item_id'] === null || $row['warehouse_id'] === null) {
                continue;
            }
            // Company-wide (warehouse_id null): any unresolved review anywhere flags the item.
            // Single-warehouse: only when it's THIS warehouse's review.
            if ($warehouseId === null || (int) $row['warehouse_id'] === $warehouseId) {
                $migrationNegativeItemIds[(int) $row['item_id']] = true;
            }
        }
        $mnIds = array_keys($migrationNegativeItemIds);
        if ($mnIds === []) {
            $mnPlaceholder = '-1';
        } else {
            $mnPlaceholders = [];
            foreach ($mnIds as $idx => $id) {
                $key = "mn{$idx}";
                $mnPlaceholders[] = ":{$key}";
                $bind[$key] = $id;
            }
            $mnPlaceholder = implode(',', $mnPlaceholders);
        }

        $batchJoinSql = 'SELECT item_id, warehouse_id, SUM(qty_base) AS qty_base, SUM(qty_base * unit_cost_base) AS value FROM inventory_batches';
        $movementJoinSql = "SELECT l.item_id, l.warehouse_id,
                MAX(CASE WHEN t.transaction_type = 'IN' THEN t.transaction_date END) AS last_in,
                MAX(CASE WHEN t.transaction_type = 'OUT' THEN t.transaction_date END) AS last_out,
                MAX(t.transaction_date) AS last_movement
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             WHERE t.status = 'POSTED' AND t.inventory_effect = 1";

        if ($warehouseId !== null) {
            $batchJoinSql .= ' WHERE warehouse_id = :wh_b';
            $movementJoinSql .= ' AND l.warehouse_id = :wh_m';
            $bind['wh_b'] = $warehouseId;
            $bind['wh_m'] = $warehouseId;
            $batchGroup = 'GROUP BY item_id, warehouse_id';
            $policyJoin = 'LEFT JOIN item_warehouse_stock_policy p ON p.item_id = i.id AND p.warehouse_id = :wh_p AND p.is_active = 1';
            $bind['wh_p'] = $warehouseId;
        } else {
            // Company-wide rollup: aggregate across every warehouse per item.
            $batchGroup = 'GROUP BY item_id';
            $policyJoin = ''; // no single warehouse's policy applies; falls back to items.minimum_stock, buffer never configured company-wide
        }
        $batchJoinSql .= " {$batchGroup}";
        $movementJoinSql .= ' GROUP BY l.item_id' . ($warehouseId !== null ? ', l.warehouse_id' : '');

        $joins = "
            FROM items i
            JOIN units u ON u.id = i.base_unit_id
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN suppliers sup ON sup.id = i.default_supplier_id
            LEFT JOIN ({$batchJoinSql}) b ON b.item_id = i.id
            {$policyJoin}
            LEFT JOIN ({$movementJoinSql}) m ON m.item_id = i.id
        ";

        // Company-wide mode (warehouseId null) has no single warehouse's
        // policy row to join — minimum falls back straight to
        // items.minimum_stock and buffer is never configured, per
        // docs/PHASE_V2_TECHNICAL_DESIGN.md's company-wide design note.
        $minimumExpr = $warehouseId !== null ? 'COALESCE(p.minimum_stock_base, i.minimum_stock)' : 'i.minimum_stock';
        $bufferExpr = $warehouseId !== null ? 'p.buffer_stock_base' : 'NULL';

        $select = "
            i.id AS item_id, i.sku, i.name, i.status AS item_status, i.updated_at,
            i.category_id, c.code AS category_code, c.name AS category_name,
            sup.id AS supplier_id, sup.name AS supplier_name,
            u.id AS unit_id, u.code AS unit_code,
            COALESCE(b.qty_base, 0) AS qty_base,
            COALESCE(b.value, 0) AS value,
            {$minimumExpr} AS minimum_stock,
            {$bufferExpr} AS buffer_stock,
            m.last_in, m.last_out, m.last_movement,
            -- Phase 4 correction: buffer is an ABSOLUTE threshold (qty < buffer),
            -- not a margin added on top of minimum — matches
            -- StockPolicyService::stockStatus() exactly; see that method's
            -- docblock for the full rationale.
            CASE
                WHEN i.id IN ({$mnPlaceholder}) THEN 'MIGRATION_NEGATIVE_REVIEW'
                WHEN COALESCE(b.qty_base, 0) <= 0 THEN 'OUT_OF_STOCK'
                WHEN COALESCE(b.qty_base, 0) < {$minimumExpr} THEN 'CRITICAL'
                WHEN {$bufferExpr} IS NOT NULL AND COALESCE(b.qty_base, 0) < {$bufferExpr} THEN 'LOW'
                ELSE 'SAFE'
            END AS status,
            -- PHASE V2.6D — Laporan Stok's simplified 3-state view. A
            -- DELIBERATELY separate column from `status` above: that one
            -- is the existing 5-tier CRITICAL/LOW/SAFE/OUT_OF_STOCK/
            -- MIGRATION_NEGATIVE_REVIEW model other pages (Stok Barang,
            -- the Dashboard's Need Attention widget) already depend on and
            -- must never change. This mirrors the exact owner-specified
            -- formula: <=0 stock is HABIS regardless of minimum (a
            -- migration-negative item is qty<0<=0, so it is HABIS here
            -- too — no separate branch needed), otherwise <=minimum is
            -- WARNING, else AMAN. Buffer stock never enters this formula.
            CASE
                WHEN COALESCE(b.qty_base, 0) <= 0 THEN 'HABIS'
                WHEN COALESCE(b.qty_base, 0) <= {$minimumExpr} THEN 'WARNING'
                ELSE 'AMAN'
            END AS report_status
        ";

        $whereParts = ['1=1'];
        if ($itemStatus === 'ACTIVE' || $itemStatus === 'INACTIVE') {
            // Explicit tri-state filter (Master Barang's Semua/Aktif/Tidak
            // Aktif) takes priority over the legacy activeOnly boolean.
            $whereParts[] = 'i.status = :item_status';
            $bind['item_status'] = $itemStatus;
        } elseif ($activeOnly) {
            $whereParts[] = "i.status = 'ACTIVE'";
        }
        if ($categoryId !== null) {
            $whereParts[] = 'i.category_id = :category_id';
            $bind['category_id'] = $categoryId;
        }
        if ($supplierId !== null) {
            $whereParts[] = 'i.default_supplier_id = :supplier_id';
            $bind['supplier_id'] = $supplierId;
        }
        if ($q !== '') {
            // PHASE V2.1: Master Barang's search box is spec'd as SKU/name/
            // barcode — extending here (rather than only in the new Master
            // Barang endpoint) keeps "Stok Barang"'s search consistent too,
            // and stays backward compatible since a barcode match is a
            // strict OR-addition to what already matched.
            $whereParts[] = '(i.sku LIKE :q_sku OR i.name LIKE :q_name OR i.barcode LIKE :q_barcode)';
            $bind['q_sku'] = '%' . $q . '%';
            $bind['q_name'] = '%' . $q . '%';
            $bind['q_barcode'] = '%' . $q . '%';
        }
        $where = implode(' AND ', $whereParts);

        $havingParts = [];
        if (!$includeZeroStock) {
            $havingParts[] = 'qty_base <> 0';
        }
        if ($statusFilter !== null && $statusFilter !== '') {
            $havingParts[] = 'status = :status_filter';
            $bind['status_filter'] = $statusFilter;
        }
        // PHASE V2.1 — Master Barang's composite "Stock status" dropdown
        // (Ada Stok / Stok 0 / Need Attention) doesn't map to a single exact
        // `status` value the way statusFilter above does, so it's a
        // separate param evaluated here rather than overloading statusFilter.
        if ($stockStatusComposite === 'HAS_STOCK') {
            $havingParts[] = 'qty_base > 0';
        } elseif ($stockStatusComposite === 'ZERO_STOCK') {
            $havingParts[] = 'qty_base = 0';
        } elseif ($stockStatusComposite === 'NEEDS_ATTENTION') {
            $havingParts[] = "status <> 'SAFE'";
        }
        if ($reportStatus === 'AMAN' || $reportStatus === 'WARNING' || $reportStatus === 'HABIS') {
            $havingParts[] = 'report_status = :report_status';
            $bind['report_status'] = $reportStatus;
        }
        $having = implode(' AND ', $havingParts);

        return [$select, $joins, $where, $having, $bind];
    }

    private static function formatRow(array $r): array
    {
        $qty = round((float) $r['qty_base'], 6);
        $value = round((float) $r['value'], 4);
        return [
            'item_id' => (int) $r['item_id'],
            'sku' => $r['sku'],
            'name' => $r['name'],
            'item_status' => $r['item_status'],
            'category' => $r['category_id'] !== null ? ['id' => (int) $r['category_id'], 'code' => $r['category_code'], 'name' => $r['category_name']] : null,
            'supplier' => $r['supplier_id'] !== null ? ['id' => (int) $r['supplier_id'], 'name' => $r['supplier_name']] : null,
            'unit' => ['id' => (int) $r['unit_id'], 'code' => $r['unit_code']],
            'updated_at' => $r['updated_at'] ?? null,
            'qty_base' => $qty,
            'value' => $value,
            'average_cost' => $qty > 0 ? round($value / $qty, 4) : null,
            'minimum_stock' => round((float) $r['minimum_stock'], 6),
            'buffer_stock' => $r['buffer_stock'] === null ? null : round((float) $r['buffer_stock'], 6),
            'buffer_configured' => $r['buffer_stock'] !== null,
            'status' => $r['status'],
            'report_status' => $r['report_status'],
            'migration_negative_review' => $r['status'] === 'MIGRATION_NEGATIVE_REVIEW',
            'last_in' => $r['last_in'],
            'last_out' => $r['last_out'],
            'last_movement' => $r['last_movement'],
        ];
    }
}
