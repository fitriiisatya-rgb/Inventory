<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE G-DATA 2 Section 12 — the GO_LIVE_READY gate for a staged final
 * opening batch. Every check must read PASS (0 for the count-style
 * checks) before a batch may be committed to production. This is a
 * report/gate only — it never mutates data, and it never adjusts a row
 * to force a check to pass.
 */
final class OpeningReconciliationService
{
    public static function report(PDO $pdo, int $openingId): array
    {
        $opening = $pdo->prepare('SELECT * FROM stock_openings WHERE id = :id');
        $opening->execute(['id' => $openingId]);
        $opening = $opening->fetch();
        if (!$opening) {
            throw new NotFoundException('stock opening batch not found');
        }

        $countWhere = fn (string $condition) => (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = {$openingId} AND ({$condition})"
        )->fetchColumn();

        $negativeQty = $countWhere('qty_base < 0');
        $missingCost = $countWhere('qty_base > 0 AND unit_cost_base <= 0');
        $unknownSku = $countWhere('item_id IS NULL');
        $unknownWarehouse = $countWhere('warehouse_id IS NULL');
        $baseUnitMismatch = $countWhere("row_status = 'ERROR' AND row_messages LIKE '%Global Base Unit mismatch%'");

        $dupStmt = $pdo->prepare(
            'SELECT item_id, warehouse_id, batch_reference, COUNT(*) c
             FROM stock_opening_lines WHERE stock_opening_id = :id
             GROUP BY item_id, warehouse_id, batch_reference HAVING c > 1'
        );
        $dupStmt->execute(['id' => $openingId]);
        $duplicateOpening = count($dupStmt->fetchAll());

        $errorRows = $countWhere("row_status = 'ERROR'");

        $controlTotalMatch = 'PASS';
        if ($opening['status'] === 'COMMITTED') {
            $actual = $pdo->prepare(
                'SELECT COALESCE(SUM(b.qty_base * b.unit_cost_base), 0)
                 FROM stock_opening_lines sol JOIN inventory_batches b ON b.id = sol.created_batch_id
                 WHERE sol.stock_opening_id = :id AND sol.created_batch_id IS NOT NULL'
            );
            $actual->execute(['id' => $openingId]);
            $actualTotal = round((float) $actual->fetchColumn(), 4);
            $expectedTotal = round((float) $opening['control_total_value'], 4);
            $controlTotalMatch = abs($actualTotal - $expectedTotal) <= 1.0 ? 'PASS' : 'FAIL';
        }

        $currentStockEqualsOpening = 'PASS';
        if ($opening['status'] === 'COMMITTED') {
            $mismatchStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_opening_lines sol
                 JOIN inventory_batches b ON b.id = sol.created_batch_id
                 WHERE sol.stock_opening_id = :id
                   AND (ABS(b.qty_base - sol.qty_base) > 0.000001 OR ABS(b.unit_cost_base - sol.unit_cost_base) > 0.0001)'
            );
            $mismatchStmt->execute(['id' => $openingId]);
            $currentStockEqualsOpening = ((int) $mismatchStmt->fetchColumn() === 0) ? 'PASS' : 'FAIL';
        }

        // Section 12 / G-DATA project-wide invariant: historical-import
        // transactions must never carry an inventory effect.
        $historicalEffect = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_transactions WHERE is_historical_import = 1 AND inventory_effect <> 0"
        )->fetchColumn();

        $checks = [
            'negative_qty' => $negativeQty,
            'missing_cost' => $missingCost,
            'unknown_sku' => $unknownSku,
            'unknown_warehouse' => $unknownWarehouse,
            'base_unit_mismatch' => $baseUnitMismatch,
            'duplicate_opening' => $duplicateOpening,
            'error_rows' => $errorRows,
            'opening_control_total_match' => $controlTotalMatch,
            'current_stock_equals_opening' => $currentStockEqualsOpening,
            'historical_inventory_effect_zero' => $historicalEffect === 0 ? 'PASS' : 'FAIL',
        ];

        $goLiveReady = $negativeQty === 0 && $missingCost === 0 && $unknownSku === 0 && $unknownWarehouse === 0
            && $baseUnitMismatch === 0 && $duplicateOpening === 0 && $errorRows === 0
            && $controlTotalMatch === 'PASS' && $currentStockEqualsOpening === 'PASS'
            && $historicalEffect === 0;

        return [
            'stock_opening_id' => $openingId,
            'status' => $opening['status'],
            'checks' => $checks,
            'go_live_ready' => $goLiveReady,
        ];
    }
}
