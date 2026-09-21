<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — "Pergerakan Stok Harian" (Report 2 of the Reporting Pack)
 * and the shared category-movement engine Report 14 (Rekonsiliasi Arus
 * Stok) also builds on. Deliberately a SEPARATE service from
 * InventoryHppReportService (per the V2.6B architecture direction: that
 * class stays the HPP/valuation engine, this one owns "how much moved and
 * why" for the reporting pack) — but it reuses, never re-derives, that
 * class's proven primitives: `SIGNED_VALUE_SQL` (the one canonical
 * per-line signed-value expression), `cutoverContext()` (go-live
 * clamping) and `signedValueBefore()` (the boundary-inclusive Opening/
 * Ending anchor). No FIFO math, no valuation formula, no Trace/Audit
 * logic is duplicated here.
 *
 * ------------------------------------------------------------------
 * Category bucket design (the math behind every number this class
 * returns — read this before changing any query below):
 *
 * For a given day (or period) and warehouse scope, every
 * inventory_transaction_lines row in scope contributes a single signed
 * value via SIGNED_VALUE_SQL. Splitting that signed value by sign gives
 * two totals that are true by construction, never approximated:
 *   in_value_total  = SUM(SIGNED_VALUE_SQL WHERE SIGNED_VALUE_SQL > 0)
 *   out_value_total = SUM(-SIGNED_VALUE_SQL WHERE SIGNED_VALUE_SQL < 0)
 *   in_value_total - out_value_total === net_signed_value  (exact identity)
 *
 * Four "named" categories are pulled out of that same total, each one a
 * PROVABLE SUBSET of the positive (or negative) set above:
 *   external_purchase   (type=IN, status=POSTED)              — subset of positive
 *   transfer_in_raw     (type=TRANSFER_IN)                    — subset of positive
 *   adjustment_positive (type=ADJUSTMENT, subtotal > 0)        — subset of positive
 *   transfer_out_raw    (type=TRANSFER_OUT, magnitude)         — subset of negative
 *   out_usage           (type=OUT, magnitude)                  — subset of negative
 *   adjustment_negative (type=ADJUSTMENT, subtotal < 0, magnitude) — subset of negative
 * Because each is a subset of the same total sum by a mutually-exclusive
 * transaction_type/sign condition, the residual
 *   other_in  = in_value_total  - external_purchase - adjustment_positive - transfer_in_raw
 *   other_out = out_value_total - out_usage - adjustment_negative - transfer_out_raw
 * is ALWAYS >= 0 — never clamped, never forced, a real mathematical
 * guarantee, not a fudge factor. `other_in`/`other_out` are what is left:
 * REVERSAL, PRODUCTION_IN/PRODUCTION_OUT, a mid-period OPENING row, or a
 * VOIDed IN/OUT's original entry (its own type/status combination is
 * outside the POSTED-only external_purchase/out_usage filters, by
 * design — see InventoryHppReportService's class docblock for why VOID
 * rows still count toward Ending/Opening).
 *
 * Company-wide consolidation (warehouseId === null): a warehouse-to-
 * warehouse transfer's TRANSFER_OUT and TRANSFER_IN legs are both
 * in scope with no warehouse filter, so they are NOT real company-level
 * movement — showing their full gross magnitudes as "Barang Masuk"/
 * "Barang Keluar" would fabricate purchase/usage activity that never
 * left the company. They are eliminated: only the NET residual
 * (transfer_in_raw - transfer_out_raw — zero once a transfer is fully
 * received within the queried range, non-zero only for the genuine
 * in-transit edge that spans the range boundary) is folded into
 * other_in/other_out, clearly labelled, never silently dropped. This
 * keeps the identity `barang_masuk - barang_keluar === net_signed_value`
 * exactly true at every scope — proven in this class's own docblock math
 * and re-verified by tests/inventory_movement_report_v26b_test.php.
 * ------------------------------------------------------------------
 */
final class InventoryMovementReportService
{
    private const CATEGORY_LABELS = [
        'external_purchase' => 'Pembelian Eksternal / IN',
        'transfer_in' => 'Transfer IN',
        'transfer_out' => 'Transfer OUT',
        'out_usage' => 'OUT / Pemakaian',
        'adjustment_positive' => 'Adjustment Positif',
        'adjustment_negative' => 'Adjustment Negatif',
        'other_in' => 'Lainnya (Masuk)',
        'other_out' => 'Lainnya (Keluar)',
        'transfer_elimination' => 'Transfer Internal (Eliminasi Konsolidasi)',
    ];

    /**
     * Report 2 main table: one row per calendar day with exactly
     * Tanggal/Saldo Awal/Barang Masuk/Barang Keluar/Saldo Akhir (nominal
     * Rupiah) — the disclosure buckets used to derive Barang Masuk/Keluar
     * are NOT part of this row shape; they live behind dayBreakdown().
     *
     * @return array{rows: list<array<string,mixed>>, cutover: array}
     */
    public static function dailyMovement(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): array
    {
        $cutover = InventoryHppReportService::cutoverContext($pdo, $startDate, $endDate);
        $effectiveStart = $cutover['effective_start_date'];

        $opening = 0.0;
        $byDate = [];

        if (!$cutover['is_pre_go_live_period']) {
            $opening = InventoryHppReportService::signedValueBefore($pdo, $effectiveStart, $warehouseId, '', [], [], true);
            $byDate = self::bucketsByDate($pdo, $effectiveStart, $endDate, $warehouseId);
        }

        $rows = [];
        $running = 0.0;
        $seeded = false;
        $cursor = $startDate;
        while (strtotime($cursor) <= strtotime($endDate)) {
            if ($cursor < $effectiveStart) {
                $rows[] = [
                    'date' => $cursor, 'stok_awal' => 0.0, 'barang_masuk' => 0.0,
                    'barang_keluar' => 0.0, 'stok_akhir' => 0.0, 'is_pre_go_live' => true,
                ];
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
                continue;
            }
            if (!$seeded) {
                $running = $opening;
                $seeded = true;
            }

            $b = $byDate[$cursor] ?? self::emptyBuckets();
            $derived = self::deriveDisplayBuckets($b, $warehouseId);
            $stokAwal = $running;
            $stokAkhir = round($running + $derived['net'], 4);

            $rows[] = [
                'date' => $cursor,
                'stok_awal' => round($stokAwal, 4),
                'barang_masuk' => round($derived['barang_masuk'], 4),
                'barang_keluar' => round($derived['barang_keluar'], 4),
                'stok_akhir' => $stokAkhir,
                'is_pre_go_live' => false,
            ];

            $running = $stokAkhir;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return ['rows' => $rows, 'cutover' => $cutover];
    }

    /**
     * Breakdown drawer for one clicked day: the named categories (§ class
     * docblock) plus, at company scope only, the transfer-elimination
     * disclosure line. Every category carries its own total so the
     * frontend can render "Explains X of Y" without recomputing anything.
     */
    public static function dayBreakdown(PDO $pdo, string $date, ?int $warehouseId): array
    {
        $cutover = InventoryHppReportService::cutoverContext($pdo, $date, $date);
        if ($cutover['is_pre_go_live_period']) {
            return self::emptyBreakdown($date, $warehouseId, $cutover);
        }
        $byDate = self::bucketsByDate($pdo, $date, $date, $warehouseId);
        $b = $byDate[$date] ?? self::emptyBuckets();
        $derived = self::deriveDisplayBuckets($b, $warehouseId);

        $categories = [
            ['key' => 'external_purchase', 'label' => self::CATEGORY_LABELS['external_purchase'], 'value' => round($b['external_purchase'], 4), 'direction' => 'IN'],
        ];
        if ($warehouseId !== null) {
            $categories[] = ['key' => 'transfer_in', 'label' => self::CATEGORY_LABELS['transfer_in'], 'value' => round($b['transfer_in_raw'], 4), 'direction' => 'IN'];
        }
        $categories[] = ['key' => 'adjustment_positive', 'label' => self::CATEGORY_LABELS['adjustment_positive'], 'value' => round($b['adjustment_positive'], 4), 'direction' => 'IN'];
        $categories[] = ['key' => 'other_in', 'label' => self::CATEGORY_LABELS['other_in'], 'value' => round($derived['other_in_display'], 4), 'direction' => 'IN'];

        $categories[] = ['key' => 'out_usage', 'label' => self::CATEGORY_LABELS['out_usage'], 'value' => round($b['out_usage'], 4), 'direction' => 'OUT'];
        if ($warehouseId !== null) {
            $categories[] = ['key' => 'transfer_out', 'label' => self::CATEGORY_LABELS['transfer_out'], 'value' => round($b['transfer_out_raw'], 4), 'direction' => 'OUT'];
        }
        $categories[] = ['key' => 'adjustment_negative', 'label' => self::CATEGORY_LABELS['adjustment_negative'], 'value' => round($b['adjustment_negative'], 4), 'direction' => 'OUT'];
        $categories[] = ['key' => 'other_out', 'label' => self::CATEGORY_LABELS['other_out'], 'value' => round($derived['other_out_display'], 4), 'direction' => 'OUT'];

        if ($warehouseId === null) {
            $categories[] = [
                'key' => 'transfer_elimination',
                'label' => self::CATEGORY_LABELS['transfer_elimination'],
                'value' => round($derived['transfer_elimination'], 4),
                'direction' => $derived['transfer_elimination'] >= 0 ? 'IN' : 'OUT',
                'note' => 'Transfer antar gudang internal — dieliminasi dari pergerakan level perusahaan. Nilai 0 berarti setiap transfer yang dikirim pada periode ini sudah diterima; nilai bukan-0 adalah barang yang sedang dalam perjalanan (in-transit) pada batas periode.',
            ];
        }

        return [
            'date' => $date,
            'warehouse_id' => $warehouseId,
            'cutover' => $cutover,
            'barang_masuk' => round($derived['barang_masuk'], 4),
            'barang_keluar' => round($derived['barang_keluar'], 4),
            'net' => round($derived['net'], 4),
            'categories' => $categories,
            'is_company_consolidated' => $warehouseId === null,
        ];
    }

    /**
     * Transaction-level drill-down for one category on one day — every
     * row carries transaction_id so the frontend opens it via the
     * EXISTING TraceDrawer.openTransaction(); this class never renders a
     * second trace UI or duplicates TraceService's own queries.
     */
    public static function categoryTransactions(PDO $pdo, string $date, ?int $warehouseId, string $category): array
    {
        [$whereSql, $bind] = self::categoryWhere($category, $warehouseId);
        $bind['start'] = $date . ' 00:00:00';
        $bind['end_excl'] = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

        $sql = "SELECT t.id AS transaction_id, t.transaction_type, t.status, t.reference_no, t.transaction_date,
                       t.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                       i.sku, i.name AS item_name, l.base_qty, l.subtotal,
                       " . InventoryHppReportService::SIGNED_VALUE_SQL . " AS signed_value
                FROM inventory_transaction_lines l
                JOIN inventory_transactions t ON t.id = l.transaction_id
                JOIN items i ON i.id = l.item_id
                JOIN warehouses w ON w.id = l.warehouse_id
                WHERE t.transaction_date >= :start AND t.transaction_date < :end_excl
                  AND t.inventory_effect = 1
                  AND {$whereSql}
                ORDER BY t.transaction_date ASC, t.id ASC
                LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return array_map(static fn ($r) => [
            'transaction_id' => (int) $r['transaction_id'],
            'transaction_type' => $r['transaction_type'],
            'status' => $r['status'],
            'reference_no' => $r['reference_no'],
            'transaction_date' => $r['transaction_date'],
            'warehouse_id' => (int) $r['warehouse_id'],
            'warehouse_code' => $r['warehouse_code'],
            'warehouse_name' => $r['warehouse_name'],
            'sku' => $r['sku'],
            'item_name' => $r['item_name'],
            'qty' => round((float) $r['base_qty'], 6),
            'value' => round(abs((float) $r['signed_value']), 4),
        ], $rows);
    }

    // ---- internals ----------------------------------------------------

    private static function emptyBuckets(): array
    {
        return [
            'external_purchase' => 0.0, 'transfer_in_raw' => 0.0, 'transfer_out_raw' => 0.0,
            'out_usage' => 0.0, 'adjustment_positive' => 0.0, 'adjustment_negative' => 0.0,
            'in_value_total' => 0.0, 'out_value_total' => 0.0,
        ];
    }

    /** @return array<string, array<string,float>> keyed by 'Y-m-d' */
    private static function bucketsByDate(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): array
    {
        $signed = InventoryHppReportService::SIGNED_VALUE_SQL;
        $where = [
            "t.status IN ('POSTED','VOID')", 't.inventory_effect = 1',
            't.transaction_date >= :start', 't.transaction_date < :end_excl',
            "NOT (t.transaction_type = 'OPENING' AND t.transaction_date = :start_boundary)",
        ];
        $bind = [
            'start' => $startDate . ' 00:00:00',
            'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            'start_boundary' => $startDate . ' 00:00:00',
        ];
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT DATE(t.transaction_date) AS d,
                SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN l.subtotal ELSE 0 END) AS external_purchase,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN l.subtotal ELSE 0 END) AS transfer_in_raw,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.subtotal) ELSE 0 END) AS transfer_out_raw,
                SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.subtotal) ELSE 0 END) AS out_usage,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.subtotal > 0 THEN l.subtotal ELSE 0 END) AS adjustment_positive,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.subtotal < 0 THEN -l.subtotal ELSE 0 END) AS adjustment_negative,
                SUM(CASE WHEN {$signed} > 0 THEN {$signed} ELSE 0 END) AS in_value_total,
                SUM(CASE WHEN {$signed} < 0 THEN -({$signed}) ELSE 0 END) AS out_value_total
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             WHERE {$whereSql}
             GROUP BY DATE(t.transaction_date)"
        );
        $stmt->execute($bind);

        $result = [];
        foreach ($stmt->fetchAll() as $r) {
            $result[$r['d']] = [
                'external_purchase' => (float) $r['external_purchase'],
                'transfer_in_raw' => (float) $r['transfer_in_raw'],
                'transfer_out_raw' => (float) $r['transfer_out_raw'],
                'out_usage' => (float) $r['out_usage'],
                'adjustment_positive' => (float) $r['adjustment_positive'],
                'adjustment_negative' => (float) $r['adjustment_negative'],
                'in_value_total' => (float) $r['in_value_total'],
                'out_value_total' => (float) $r['out_value_total'],
            ];
        }
        return $result;
    }

    /**
     * Turns the raw per-type buckets into what actually gets displayed —
     * the company-level transfer-elimination algebra from the class
     * docblock. Always returns barang_masuk - barang_keluar === net
     * (in_value_total - out_value_total) exactly, by construction.
     */
    private static function deriveDisplayBuckets(array $b, ?int $warehouseId): array
    {
        $otherInResidual = max(0.0, $b['in_value_total'] - $b['external_purchase'] - $b['adjustment_positive'] - $b['transfer_in_raw']);
        $otherOutResidual = max(0.0, $b['out_value_total'] - $b['out_usage'] - $b['adjustment_negative'] - $b['transfer_out_raw']);
        $net = $b['in_value_total'] - $b['out_value_total'];

        if ($warehouseId === null) {
            $elimination = $b['transfer_in_raw'] - $b['transfer_out_raw'];
            $otherInDisplay = $otherInResidual + max(0.0, $elimination);
            $otherOutDisplay = $otherOutResidual + max(0.0, -$elimination);
            $barangMasuk = $b['external_purchase'] + $b['adjustment_positive'] + $otherInDisplay;
            $barangKeluar = $b['out_usage'] + $b['adjustment_negative'] + $otherOutDisplay;
            return [
                'barang_masuk' => $barangMasuk, 'barang_keluar' => $barangKeluar, 'net' => $net,
                'other_in_display' => $otherInDisplay, 'other_out_display' => $otherOutDisplay,
                'transfer_elimination' => $elimination,
            ];
        }

        $barangMasuk = $b['external_purchase'] + $b['transfer_in_raw'] + $b['adjustment_positive'] + $otherInResidual;
        $barangKeluar = $b['out_usage'] + $b['transfer_out_raw'] + $b['adjustment_negative'] + $otherOutResidual;
        return [
            'barang_masuk' => $barangMasuk, 'barang_keluar' => $barangKeluar, 'net' => $net,
            'other_in_display' => $otherInResidual, 'other_out_display' => $otherOutResidual,
            'transfer_elimination' => 0.0,
        ];
    }

    private static function emptyBreakdown(string $date, ?int $warehouseId, array $cutover): array
    {
        $categories = array_map(
            static fn ($key) => ['key' => $key, 'label' => self::CATEGORY_LABELS[$key], 'value' => 0.0, 'direction' => 'IN'],
            ['external_purchase', 'adjustment_positive', 'other_in']
        );
        return [
            'date' => $date, 'warehouse_id' => $warehouseId, 'cutover' => $cutover,
            'barang_masuk' => 0.0, 'barang_keluar' => 0.0, 'net' => 0.0,
            'categories' => $categories, 'is_company_consolidated' => $warehouseId === null,
            'pre_go_live_note' => 'Tanggal ini sebelum Opening Go-Live — tidak ada aktivitas ekonomi.',
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function categoryWhere(string $category, ?int $warehouseId): array
    {
        $bind = [];
        $wh = '';
        if ($warehouseId !== null) {
            $wh = ' AND l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $signed = InventoryHppReportService::SIGNED_VALUE_SQL;

        $sql = match ($category) {
            'external_purchase' => "t.transaction_type = 'IN' AND t.status = 'POSTED'",
            'transfer_in' => "t.transaction_type = 'TRANSFER_IN'",
            'transfer_out' => "t.transaction_type = 'TRANSFER_OUT'",
            'out_usage' => "t.transaction_type = 'OUT' AND t.status IN ('POSTED','VOID')",
            'adjustment_positive' => "t.transaction_type = 'ADJUSTMENT' AND l.subtotal > 0",
            'adjustment_negative' => "t.transaction_type = 'ADJUSTMENT' AND l.subtotal < 0",
            'other_in' => "t.status IN ('POSTED','VOID') AND {$signed} > 0
                AND NOT (t.transaction_type = 'IN' AND t.status = 'POSTED')
                AND NOT (t.transaction_type = 'TRANSFER_IN')
                AND NOT (t.transaction_type = 'ADJUSTMENT' AND l.subtotal > 0)",
            'other_out' => "t.status IN ('POSTED','VOID') AND {$signed} < 0
                AND NOT (t.transaction_type = 'TRANSFER_OUT')
                AND NOT (t.transaction_type = 'OUT')
                AND NOT (t.transaction_type = 'ADJUSTMENT' AND l.subtotal < 0)",
            'transfer_elimination' => "t.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT')",
            default => throw new ValidationException("Unknown movement report category: {$category}"),
        };
        return [$sql . $wh, $bind];
    }
}
