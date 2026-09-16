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

        // ---- Negative stock: aggregate qty per item+warehouse must never be < 0 ----
        $negativeStock = $pdo->query(
            'SELECT item_id, warehouse_id, SUM(qty_base) AS qty_base
             FROM inventory_batches GROUP BY item_id, warehouse_id HAVING SUM(qty_base) < 0'
        )->fetchAll();
        $checks['negative_stock'] = self::check($negativeStock, 'ERROR');

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
             WHERE t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') AND t.status = 'POSTED'
             GROUP BY l.id, l.transaction_id, l.base_qty
             HAVING ABS(ABS(l.base_qty) - COALESCE(SUM(fa.qty_allocated), 0)) > 0.0005"
        )->fetchAll();
        $checks['unbalanced_fifo_allocation'] = self::check($unbalanced, 'ERROR');

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
