<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE E3 — mandatory pre-go-live report. Every check below returns a
 * status (PASS/WARNING/ERROR); the report's go_live_ready flag is FALSE if
 * ANY check is ERROR, full stop — no partial "mostly ready" reading.
 */
final class ReconciliationService
{
    public static function run(PDO $pdo): array
    {
        $checks = [];

        $totalSku = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        $skuWithStock = (int) $pdo->query(
            'SELECT COUNT(DISTINCT item_id) FROM inventory_batches WHERE qty_base <> 0'
        )->fetchColumn();

        $qtyPerWarehouse = $pdo->query(
            'SELECT w.id AS warehouse_id, w.code, w.name, COALESCE(SUM(b.qty_base), 0) AS qty_base
             FROM warehouses w LEFT JOIN inventory_batches b ON b.warehouse_id = w.id
             GROUP BY w.id, w.code, w.name ORDER BY w.name'
        )->fetchAll();

        $valuePerWarehouse = $pdo->query(
            'SELECT w.id AS warehouse_id, w.code, w.name, COALESCE(SUM(b.qty_base * b.unit_cost_base), 0) AS value
             FROM warehouses w LEFT JOIN inventory_batches b ON b.warehouse_id = w.id
             GROUP BY w.id, w.code, w.name ORDER BY w.name'
        )->fetchAll();

        $companyValue = InventoryService::companyTotalValue($pdo);

        // ---- Negative stock: unexpected negative balances are ERROR.
        // Owner-approved migration-negative balances are tracked separately
        // as WARNING until resolved by audited Stock Opname/Stock Adjustment.
        $negativeStock = $pdo->query(
            "SELECT n.item_id, n.warehouse_id, n.qty_base
             FROM (
                 SELECT item_id, warehouse_id, SUM(qty_base) AS qty_base
                 FROM inventory_batches
                 GROUP BY item_id, warehouse_id
                 HAVING SUM(qty_base) < 0
             ) n
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM movement_reconciliation_reviews mrr
                 JOIN items i ON i.sku = mrr.sku
                 JOIN warehouses w ON w.code = mrr.warehouse_code
                 WHERE mrr.is_migration_negative_approved = 1
                   AND i.id = n.item_id
                   AND w.id = n.warehouse_id
             )"
        )->fetchAll();
        $checks['negative_stock'] = self::check($negativeStock, 'ERROR');

        $migrationNegativeReview = array_values(array_filter(
            MigrationNegativeStockService::reviewList($pdo),
            static fn (array $row): bool => ($row['status'] ?? '') === 'MIGRATION_NEGATIVE_REVIEW'
        ));
        $checks['migration_negative_review'] = self::check($migrationNegativeReview, 'WARNING');

        // ---- Zero-cost batches still holding positive quantity ----
        $zeroCost = $pdo->query(
            'SELECT id AS batch_id, item_id, warehouse_id, qty_base FROM inventory_batches WHERE qty_base > 0 AND unit_cost_base = 0'
        )->fetchAll();
        $checks['zero_cost_batch'] = self::check($zeroCost, 'WARNING');

        // ---- Abnormal-cost batches: outside 0.2x-5x this item's own average cost ----
        $abnormalCost = $pdo->query(
            'SELECT b.id AS batch_id, b.item_id, b.warehouse_id, b.unit_cost_base, avg_cost.avg_cost
             FROM inventory_batches b
             JOIN (SELECT item_id, AVG(unit_cost_base) AS avg_cost FROM inventory_batches WHERE qty_base > 0 GROUP BY item_id) avg_cost
               ON avg_cost.item_id = b.item_id
             WHERE b.qty_base > 0 AND avg_cost.avg_cost > 0
               AND (b.unit_cost_base > avg_cost.avg_cost * 5 OR b.unit_cost_base < avg_cost.avg_cost * 0.2)'
        )->fetchAll();
        $checks['abnormal_cost_batch'] = self::check($abnormalCost, 'WARNING');

        // ---- Orphan batches: item_id/warehouse_id with no matching master row (FK should make this impossible; checked anyway) ----
        $orphanBatch = $pdo->query(
            'SELECT b.id AS batch_id FROM inventory_batches b
             LEFT JOIN items i ON i.id = b.item_id LEFT JOIN warehouses w ON w.id = b.warehouse_id
             WHERE i.id IS NULL OR w.id IS NULL'
        )->fetchAll();
        $checks['orphan_batch'] = self::check($orphanBatch, 'ERROR');

        // ---- Duplicate SKU (UNIQUE constraint should make this impossible; checked anyway) ----
        $duplicateSku = $pdo->query(
            'SELECT sku, COUNT(*) AS n FROM items GROUP BY sku HAVING COUNT(*) > 1'
        )->fetchAll();
        $checks['duplicate_sku'] = self::check($duplicateSku, 'ERROR');

        // ---- Unknown SKU / warehouse still sitting unresolved in import staging ----
        $unknownRef = $pdo->query(
            "SELECT import_batch_id, row_no, messages FROM import_rows
             WHERE row_status = 'ERROR' AND (messages LIKE '%SKU%tidak%' OR messages LIKE '%unknown%' OR messages LIKE '%not found%')"
        )->fetchAll();
        $checks['unknown_sku_or_warehouse'] = self::check($unknownRef, 'WARNING');

        // ---- Unbalanced FIFO allocation: an OUT-type line's allocations must sum to its base_qty ----
        $unbalanced = $pdo->query(
            "SELECT l.id AS line_id, l.transaction_id, ABS(l.base_qty) AS expected_qty, COALESCE(SUM(fa.qty_allocated), 0) AS allocated_qty
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             LEFT JOIN fifo_allocations fa ON fa.transaction_line_id = l.id
             WHERE t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN')
               AND t.status = 'POSTED'
               AND t.inventory_effect <> 0
             GROUP BY l.id, l.transaction_id, l.base_qty
             HAVING ABS(ABS(l.base_qty) - COALESCE(SUM(fa.qty_allocated), 0)) > 0.0005"
        )->fetchAll();
        $checks['unbalanced_fifo_allocation'] = self::check($unbalanced, 'ERROR');

        // ---- PHASE G14: pre-cutover-specific checks ----

        // opening_value_consistency: every COMMITTED opening's own staged
        // control_total_value (G15, computed BEFORE commit) must still match
        // what actually landed in inventory_batches for the lines it created.
        // A mismatch means either the commit was interrupted, or something
        // touched those batches outside the opening import itself.
        $openingValueMismatch = $pdo->query(
            "SELECT so.id AS stock_opening_id, so.control_total_value AS expected,
                    COALESCE(SUM(b.original_qty_base * b.unit_cost_base), 0) AS actual
             FROM stock_openings so
             JOIN stock_opening_lines sol ON sol.stock_opening_id = so.id AND sol.created_batch_id IS NOT NULL
             JOIN inventory_batches b ON b.id = sol.created_batch_id
             WHERE so.status = 'COMMITTED' AND so.control_total_value IS NOT NULL
             GROUP BY so.id, so.control_total_value
             HAVING ABS(so.control_total_value - COALESCE(SUM(b.original_qty_base * b.unit_cost_base), 0)) > 1"
        )->fetchAll();
        $checks['opening_value_consistency'] = self::check($openingValueMismatch, 'ERROR');

        // historical_inventory_effect_zero: safety net — the importer always
        // forces is_historical_import=1/inventory_effect=0, but this check
        // catches it independently in case of direct data manipulation.
        $historicalEffectViolation = $pdo->query(
            "SELECT id, transaction_type, transaction_date FROM inventory_transactions
             WHERE is_historical_import = 1 AND inventory_effect <> 0"
        )->fetchAll();
        $checks['historical_inventory_effect_zero'] = self::check($historicalEffectViolation, 'ERROR');

        // missing_unit_conversion: an item with posted stock but no currently
        // -open conversion row for its own base unit — should be impossible
        // (every item gets an identity conversion at creation) but would
        // silently break future postings if it ever happened.
        $missingConversion = $pdo->query(
            "SELECT i.id AS item_id, i.sku FROM items i
             WHERE NOT EXISTS (
                 SELECT 1 FROM item_unit_conversions c
                 WHERE c.item_id = i.id AND c.unit_id = i.base_unit_id AND c.valid_to IS NULL
             )"
        )->fetchAll();
        $checks['missing_unit_conversion'] = self::check($missingConversion, 'ERROR');

        // missing_cost: opening lines that committed with a positive quantity
        // but no positive cost — G6.1 requires stage()-time validation to
        // reject this, so a hit here means that safeguard was bypassed.
        $missingCost = $pdo->query(
            "SELECT sol.id AS stock_opening_line_id, sol.stock_opening_id, sol.item_id, sol.qty_base, sol.unit_cost_base
             FROM stock_opening_lines sol
             JOIN stock_openings so ON so.id = sol.stock_opening_id AND so.status = 'COMMITTED'
             WHERE sol.qty_base > 0 AND sol.unit_cost_base <= 0"
        )->fetchAll();
        $checks['missing_cost'] = self::check($missingCost, 'ERROR');

        // duplicate_legacy_transaction: two historical rows sharing the same
        // reference_no + type + warehouse + date almost always means the
        // same legacy record was imported twice (e.g. the same file
        // uploaded twice, or two overlapping historical files).
        $duplicateLegacy = $pdo->query(
            "SELECT reference_no, transaction_type, warehouse_id, transaction_date, COUNT(*) AS n
             FROM inventory_transactions
             WHERE is_historical_import = 1 AND reference_no IS NOT NULL AND reference_no <> ''
             GROUP BY reference_no, transaction_type, warehouse_id, transaction_date
             HAVING COUNT(*) > 1"
        )->fetchAll();
        $checks['duplicate_legacy_transaction'] = self::check($duplicateLegacy, 'WARNING');

        // opening_vs_current_consistency: every batch an opening import
        // created must still be traceable — this system never hard-deletes
        // a batch row, so a missing target here means the FIFO trail from
        // that opening was corrupted (broken referential integrity), not a
        // normal consequence of later consumption/adjustment.
        $openingBatchMissing = $pdo->query(
            "SELECT sol.id AS stock_opening_line_id, sol.stock_opening_id, sol.created_batch_id
             FROM stock_opening_lines sol
             JOIN stock_openings so ON so.id = sol.stock_opening_id AND so.status = 'COMMITTED'
             WHERE sol.created_batch_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM inventory_batches b WHERE b.id = sol.created_batch_id)"
        )->fetchAll();
        $checks['opening_vs_current_consistency'] = self::check($openingBatchMissing, 'ERROR');

        $goLiveReady = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'ERROR') {
                $goLiveReady = false;
                break;
            }
        }

        return [
            'total_sku' => $totalSku,
            'sku_with_stock' => $skuWithStock,
            'qty_per_warehouse' => $qtyPerWarehouse,
            'value_per_warehouse' => $valuePerWarehouse,
            'company_inventory_value' => $companyValue['on_hand_value'],
            'in_transit_value' => $companyValue['in_transit_value'],
            'company_total_value' => $companyValue['total_value'],
            'checks' => $checks,
            'go_live_ready' => $goLiveReady,
        ];
    }

    private static function check(array $rows, string $severityIfNotEmpty): array
    {
        return [
            'status' => empty($rows) ? 'PASS' : $severityIfNotEmpty,
            'count' => count($rows),
            'rows' => array_slice($rows, 0, 50), // cap payload; count above is always the true total
        ];
    }
}
