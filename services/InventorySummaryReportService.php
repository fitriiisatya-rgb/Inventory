<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 1 "Ringkasan Inventory": a management overview for
 * one warehouse (or the whole company) over a period. Every value-movement
 * figure is read straight from InventoryMovementReportService's own
 * category buckets (summed across the period) and
 * InventoryHppReportService::periodTotals() (for the FIFO HPP figure) —
 * this class computes nothing about valuation itself, it only assembles
 * numbers those two already-proven engines produce, plus a handful of
 * lightweight operational counts (active SKU, pending transfers, active
 * opname, migration-negative, expiry) that no existing report already
 * exposes together in one place. No decorative/duplicate KPI beyond what
 * the approved spec lists.
 */
final class InventorySummaryReportService
{
    public static function summary(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId = null): array
    {
        $cutover = InventoryHppReportService::cutoverContext($pdo, $startDate, $endDate);
        $movement = InventoryMovementReportService::dailyMovement($pdo, $startDate, $endDate, $warehouseId);
        $buckets = self::sumCategoryBuckets($pdo, $startDate, $endDate, $warehouseId, $cutover['effective_start_date']);

        $beginning = 0.0;
        $ending = 0.0;
        $seenFirst = false;
        foreach ($movement['rows'] as $row) {
            if ($row['is_pre_go_live']) {
                continue;
            }
            if (!$seenFirst) {
                $beginning = $row['stok_awal'];
                $seenFirst = true;
            }
            $ending = $row['stok_akhir'];
        }

        $hppTotals = InventoryHppReportService::periodTotals($pdo, $startDate, $endDate, $warehouseId, $categoryId, null);

        return [
            'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'warehouse_id' => $warehouseId],
            'cutover' => $cutover,
            'beginning_inventory_value' => round($beginning, 4),
            'external_purchase' => $buckets['external_purchase'],
            'other_in' => $buckets['other_in'],
            'transfer_in' => $warehouseId !== null ? $buckets['transfer_in'] : null,
            'adjustment_positive' => $buckets['adjustment_positive'],
            'out_usage' => $buckets['out_usage'],
            'transfer_out' => $warehouseId !== null ? $buckets['transfer_out'] : null,
            'adjustment_negative' => $buckets['adjustment_negative'],
            'other_out' => $buckets['other_out'],
            'transfer_elimination' => $warehouseId === null ? $buckets['transfer_elimination'] : null,
            'ending_inventory_value' => round($ending, 4),
            'fifo_hpp' => round($hppTotals['fifo_hpp'], 4),
            'active_sku' => self::activeSkuCount($pdo),
            'sku_with_stock' => self::skuWithStockCount($pdo, $warehouseId),
            'migration_negative_count' => self::migrationNegativeCount($pdo, $warehouseId),
            'pending_transfers' => self::pendingTransfersCount($pdo, $warehouseId),
            'active_opname' => self::activeOpnameCount($pdo, $warehouseId),
            'expiry_warning' => self::expiryWarning($pdo, $warehouseId),
        ];
    }

    private static function sumCategoryBuckets(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, string $effectiveStart): array
    {
        $totals = [
            'external_purchase' => 0.0, 'other_in' => 0.0, 'transfer_in' => 0.0, 'adjustment_positive' => 0.0,
            'out_usage' => 0.0, 'transfer_out' => 0.0, 'adjustment_negative' => 0.0, 'other_out' => 0.0,
            'transfer_elimination' => 0.0,
        ];
        $cursor = $effectiveStart > $startDate ? $effectiveStart : $startDate;
        while (strtotime($cursor) <= strtotime($endDate)) {
            $breakdown = InventoryMovementReportService::dayBreakdown($pdo, $cursor, $warehouseId);
            foreach ($breakdown['categories'] as $cat) {
                if ($cat['key'] === 'transfer_elimination') {
                    $totals['transfer_elimination'] += $cat['value'];
                    continue;
                }
                if (isset($totals[$cat['key']])) {
                    $totals[$cat['key']] += $cat['value'];
                }
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 4);
        }
        return $totals;
    }

    private static function activeSkuCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'ACTIVE'")->fetchColumn();
    }

    private static function skuWithStockCount(PDO $pdo, ?int $warehouseId): int
    {
        if ($warehouseId === null) {
            return (int) $pdo->query('SELECT COUNT(DISTINCT item_id) FROM inventory_batches WHERE qty_base <> 0')->fetchColumn();
        }
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT item_id) FROM inventory_batches WHERE qty_base <> 0 AND warehouse_id = :wh');
        $stmt->execute(['wh' => $warehouseId]);
        return (int) $stmt->fetchColumn();
    }

    private static function migrationNegativeCount(PDO $pdo, ?int $warehouseId): int
    {
        $rows = array_filter(
            MigrationNegativeStockService::reviewList($pdo),
            static fn (array $row): bool => ($row['status'] ?? '') === 'MIGRATION_NEGATIVE_REVIEW'
                && ($warehouseId === null || (int) ($row['warehouse_id'] ?? 0) === $warehouseId)
        );
        return count($rows);
    }

    private static function pendingTransfersCount(PDO $pdo, ?int $warehouseId): int
    {
        if ($warehouseId === null) {
            return (int) $pdo->query("SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING'")->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING' AND (from_warehouse_id = :wh OR to_warehouse_id = :wh2)");
        $stmt->execute(['wh' => $warehouseId, 'wh2' => $warehouseId]);
        return (int) $stmt->fetchColumn();
    }

    private static function activeOpnameCount(PDO $pdo, ?int $warehouseId): int
    {
        if ($warehouseId === null) {
            return (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED')")->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED') AND warehouse_id = :wh");
        $stmt->execute(['wh' => $warehouseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Owner decision (Report 10's own docblock note applies here too):
     * expiry is informational only, and a report must never fabricate an
     * expiry count when the dataset simply has no expiry_date populated.
     * `has_data=false` tells the frontend to omit the KPI entirely rather
     * than show a misleading 0.
     */
    private static function expiryWarning(PDO $pdo, ?int $warehouseId): array
    {
        $where = ['expiry_date IS NOT NULL', 'qty_base > 0'];
        $bind = [];
        if ($warehouseId !== null) {
            $where[] = 'warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $whereSql = implode(' AND ', $where);

        $hasDataStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE {$whereSql}");
        $hasDataStmt->execute($bind);
        $hasData = (int) $hasDataStmt->fetchColumn() > 0;
        if (!$hasData) {
            return ['has_data' => false, 'count_30d' => null];
        }

        $bind['threshold'] = date('Y-m-d', strtotime('+30 days'));
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE {$whereSql} AND expiry_date <= :threshold");
        $countStmt->execute($bind);
        return ['has_data' => true, 'count_30d' => (int) $countStmt->fetchColumn()];
    }
}
