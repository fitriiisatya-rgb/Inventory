<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — "Rekonsiliasi Arus Stok" (Report 14), the control report:
 *
 *   SALDO AWAL
 *   + EXTERNAL PURCHASE + OTHER IN + TRANSFER IN + ADJUSTMENT POSITIVE
 *   - OUT - TRANSFER OUT - ADJUSTMENT NEGATIVE
 *   = THEORETICAL ENDING
 *
 * compared against ACTUAL ENDING — never forced to match. Two
 * deliberately-independent figures:
 *   - THEORETICAL ENDING is derived purely from the transaction LEDGER
 *     (InventoryHppReportService::signedValueBefore(), the same anchor
 *     Report 2/the HPP report use — same opening, same period movement
 *     buckets as InventoryMovementReportService).
 *   - ACTUAL ENDING is read from the CURRENT inventory_batches state
 *     (InventoryService::currentStock()/companyOnHandValue()'s own SQL,
 *     reused verbatim) — the literal "what's really sitting in the
 *     batches right now" figure, structurally unable to drift from the
 *     ledger unless something bypassed FifoService/StockAdjustmentService
 *     directly. This is a genuine, meaningful control check, not a
 *     tautology — but ONLY when `end_date` is today: inventory_batches
 *     has no historical snapshot capability, so comparing a past
 *     end_date's ledger-derived Ending against TODAY's physical batches
 *     is not apples-to-apples (anything posted between end_date and
 *     today would show up as a false "difference").
 *
 * MANDATORY CORRECTION B: rather than compute a misleading Difference
 * for that non-comparable case, `runForScope()` returns
 * `actual_available=false`, `actual_value=null`, `difference=null`,
 * `status='NOT_COMPARABLE'` (a fixed `reason` string) for ANY end_date
 * that is not today — this covers both the pre-go-live historical
 * window and an ordinary past-period report equally, since neither has
 * a real comparable snapshot. Only `end_date === today` ever returns
 * `actual_available=true` with a real BALANCE/REVIEW verdict — and that
 * verdict is never forced: a genuine nonzero Difference always shows as
 * REVIEW, never silently rounded away.
 *
 * Company consolidation reuses InventoryMovementReportService's own
 * transfer-elimination algebra (warehouse_id = null) — this class never
 * duplicates that math, it only asks for the period totals.
 */
final class InventoryReconciliationReportService
{
    public static function run(PDO $pdo, string $startDate, string $endDate, ?int $requestedWarehouseId): array
    {
        $scopes = self::resolveScopes($pdo, $requestedWarehouseId);
        $today = date('Y-m-d');

        $results = [];
        foreach ($scopes as $scope) {
            $results[] = self::runForScope($pdo, $startDate, $endDate, $scope['id'], $scope['code'], $scope['name'], $today);
        }

        return ['period' => ['start_date' => $startDate, 'end_date' => $endDate], 'scopes' => $results];
    }

    /** @return list<array{id: ?int, code: string, name: string}> */
    private static function resolveScopes(PDO $pdo, ?int $requestedWarehouseId): array
    {
        if ($requestedWarehouseId !== null) {
            $stmt = $pdo->prepare('SELECT id, code, name FROM warehouses WHERE id = :id');
            $stmt->execute(['id' => $requestedWarehouseId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new NotFoundException('warehouse not found');
            }
            return [['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']]];
        }

        // Company-wide: every LIVE warehouse individually, PLUS a
        // consolidated company row. Karang Tengah (or any other
        // PENDING_CUTOVER warehouse) is never included automatically —
        // this reuses the exact same `is_active = 1` filter every other
        // warehouse-scoped report/selector in this codebase already uses.
        $rows = $pdo->query('SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();
        $scopes = array_map(static fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name']], $rows);
        $scopes[] = ['id' => null, 'code' => 'COMPANY', 'name' => 'Perusahaan (Konsolidasi)'];
        return $scopes;
    }

    private static function runForScope(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, string $code, string $name, string $today): array
    {
        $cutover = InventoryHppReportService::cutoverContext($pdo, $startDate, $endDate);
        $isSameDayCheck = $endDate === $today;
        // MANDATORY CORRECTION B: inventory_batches holds only the CURRENT
        // physical state — there is no historical snapshot table. Actual
        // Ending is therefore only ever a fair, comparable figure when
        // end_date IS today; for any other end_date (including, but not
        // limited to, the pre-go-live historical window) this class must
        // never imply a comparison it cannot honestly make. NOT_COMPARABLE
        // is returned instead of BALANCE/REVIEW, with actual_available=
        // false and both actual_value/difference explicitly null — never a
        // fabricated 0 or a silently-computed-but-misleading number.
        static $notComparableReason = 'Historical physical inventory snapshot is not available.';

        if ($cutover['is_pre_go_live_period']) {
            return [
                'warehouse_id' => $warehouseId, 'warehouse_code' => $code, 'warehouse_name' => $name,
                'cutover' => $cutover, 'saldo_awal' => 0.0,
                'external_purchase' => 0.0, 'other_in' => 0.0, 'transfer_in' => 0.0, 'adjustment_positive' => 0.0,
                'out_usage' => 0.0, 'transfer_out' => 0.0, 'adjustment_negative' => 0.0, 'other_out' => 0.0,
                'transfer_elimination' => 0.0,
                'theoretical_ending' => 0.0,
                'actual_available' => false, 'actual_value' => null,
                'difference' => null, 'status' => 'NOT_COMPARABLE', 'is_same_day_check' => $isSameDayCheck,
                'reason' => $notComparableReason,
                'note' => 'Periode seluruhnya sebelum Opening Go-Live — tidak ada pergerakan ekonomi untuk direkonsiliasi.',
            ];
        }

        $movement = InventoryMovementReportService::dailyMovement($pdo, $startDate, $endDate, $warehouseId);
        $saldoAwal = null;
        $barangMasuk = 0.0;
        $barangKeluar = 0.0;
        foreach ($movement['rows'] as $row) {
            if ($row['is_pre_go_live']) {
                continue;
            }
            if ($saldoAwal === null) {
                $saldoAwal = $row['stok_awal'];
            }
            $barangMasuk += $row['barang_masuk'];
            $barangKeluar += $row['barang_keluar'];
        }
        $saldoAwal ??= 0.0;
        $theoreticalEnding = round($saldoAwal + $barangMasuk - $barangKeluar, 4);

        // Period-level category buckets, derived the same way
        // dailyMovement() derives each day's — summed straight across the
        // per-day breakdown so "Other In/Out" and the transfer-elimination
        // disclosure stay consistent with Report 2's own numbers for the
        // identical period/scope.
        $buckets = self::sumBreakdownCategories($pdo, $startDate, $endDate, $warehouseId, $cutover['effective_start_date']);
        $base = [
            'warehouse_id' => $warehouseId, 'warehouse_code' => $code, 'warehouse_name' => $name,
            'cutover' => $cutover, 'saldo_awal' => round($saldoAwal, 4),
            'external_purchase' => $buckets['external_purchase'], 'other_in' => $buckets['other_in'],
            'transfer_in' => $buckets['transfer_in'], 'adjustment_positive' => $buckets['adjustment_positive'],
            'out_usage' => $buckets['out_usage'], 'transfer_out' => $buckets['transfer_out'],
            'adjustment_negative' => $buckets['adjustment_negative'], 'other_out' => $buckets['other_out'],
            'transfer_elimination' => $buckets['transfer_elimination'],
            'theoretical_ending' => $theoreticalEnding,
        ];

        if (!$isSameDayCheck) {
            return $base + [
                'actual_available' => false, 'actual_value' => null,
                'difference' => null, 'status' => 'NOT_COMPARABLE', 'is_same_day_check' => false,
                'reason' => $notComparableReason,
            ];
        }

        $actualEnding = self::actualEnding($pdo, $warehouseId);
        $difference = round($theoreticalEnding - $actualEnding, 4);
        $status = abs($difference) < 0.5 ? 'BALANCE' : 'REVIEW';

        return $base + [
            'actual_available' => true, 'actual_value' => $actualEnding,
            'difference' => $difference, 'status' => $status, 'is_same_day_check' => true,
            'reason' => null,
        ];
    }

    private static function actualEnding(PDO $pdo, ?int $warehouseId): float
    {
        if ($warehouseId === null) {
            return InventoryService::companyOnHandValue($pdo);
        }
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches WHERE warehouse_id = :wh');
        $stmt->execute(['wh' => $warehouseId]);
        return round((float) $stmt->fetchColumn(), 4);
    }

    /** Sums each named category across every day in range via dayBreakdown() — same numbers Report 2 shows, never a separate formula. */
    private static function sumBreakdownCategories(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, string $effectiveStart): array
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
}
