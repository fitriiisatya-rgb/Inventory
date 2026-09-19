<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.3 — "Laporan Nilai Stok & HPP" (Inventory Value & HPP
 * Reconciliation). Read-only reporting layer built entirely on the
 * existing unified ledger (`inventory_transactions`/`inventory_
 * transaction_lines`) and the FIFO cost trail (`fifo_allocations`) —
 * per Section 13 of the spec, no new tables, no duplicated FIFO logic.
 *
 * Sign convention reused VERBATIM from InventoryService (the single
 * source of truth for it): IN/OPENING/TRANSFER_IN/PRODUCTION_OUT are
 * +ABS(subtotal); OUT/TRANSFER_OUT/PRODUCTION_IN are -ABS(subtotal);
 * ADJUSTMENT/REVERSAL are already signed at post time and used as-is.
 * Only POSTED, inventory_effect=1 lines ever count (historical-import
 * rows are explicitly excluded from every total here — they never moved
 * real stock).
 *
 * Core formulas (spec section — see docs/PHASE_V2_3_HPP_REPORT.md):
 *   Opening Value  = SUM(signed value) WHERE transaction_date <  start
 *   Ending Value   = SUM(signed value) WHERE transaction_date <= end
 *   External Purchase = SUM(ABS(subtotal)) WHERE type = 'IN' in [start,end]
 *   FIFO HPP       = SUM(fifo_allocations.subtotal) for allocations whose
 *                    consuming line's transaction is type='OUT' in [start,end]
 *                    — read from the actual FIFO cost trail, not the
 *                    line's own precomputed subtotal, so every HPP rupiah
 *                    is traceable to a real batch allocation.
 *   HPP Reconciliation = Opening + External Purchase - Ending   (literal spec formula)
 *   Variance       = HPP Reconciliation - FIFO HPP
 *                    (algebraically equals the negated sum of every OTHER
 *                    movement in the period — adjustment, reversal,
 *                    production, opening-mid-period, net transfer
 *                    imbalance — which is why those buckets are always
 *                    disclosed alongside it, never hidden inside "HPP")
 */
final class InventoryHppReportService
{
    private const MAX_DAYS = 400; // sanity bound on the daily-recap PHP loop, not a business rule

    private const SIGNED_VALUE_SQL = "CASE
        WHEN t.transaction_type IN ('IN','OPENING','TRANSFER_IN','PRODUCTION_OUT') THEN ABS(l.subtotal)
        WHEN t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') THEN -ABS(l.subtotal)
        ELSE l.subtotal
    END";

    /**
     * Period-level (company or single-warehouse) summary: the 6 KPI cards +
     * the formula reconciliation strip + the non-HPP movement disclosure +
     * a same-length previous-period comparison for the "vs periode lalu" deltas.
     */
    public static function summary(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId = null, ?string $q = null): array
    {
        $current = self::periodTotals($pdo, $startDate, $endDate, $warehouseId, $categoryId, $q);

        $days = self::daysBetween($startDate, $endDate);
        $prevEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
        $previous = self::periodTotals($pdo, $prevStart, $prevEnd, $warehouseId, $categoryId, $q);

        $pct = static function (float $cur, float $prev): ?float {
            if (abs($prev) < 0.005) {
                return null;
            }
            return round((($cur - $prev) / abs($prev)) * 100, 1);
        };

        return [
            'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'warehouse_id' => $warehouseId],
            'opening_value' => $current['opening'],
            'external_purchase' => $current['purchase'],
            'fifo_hpp' => $current['fifo_hpp'],
            'ending_value' => $current['ending'],
            'hpp_reconciliation' => $current['reconciliation'],
            'variance' => $current['variance'],
            'deltas_vs_previous_period' => [
                'opening_value_pct' => $pct($current['opening'], $previous['opening']),
                'external_purchase_pct' => $pct($current['purchase'], $previous['purchase']),
                'fifo_hpp_pct' => $pct($current['fifo_hpp'], $previous['fifo_hpp']),
                'ending_value_pct' => $pct($current['ending'], $previous['ending']),
                'hpp_reconciliation_pct' => $pct($current['reconciliation'], $previous['reconciliation']),
            ],
            'non_hpp_movements' => [
                'adjustment_net' => $current['adjustment_net'],
                'opname_net' => $current['opname_net'],
                'reversal_net' => $current['reversal_net'],
                'production_net' => $current['production_net'],
                'transfer_in' => $current['transfer_in'],
                'transfer_out' => $current['transfer_out'],
                'transfer_net' => round($current['transfer_in'] + $current['transfer_out'], 4),
                'opening_mid_period' => $current['opening_mid_period'],
                'historical_import_note' => 'Historical import rows (inventory_effect=0) never affect stock and are excluded from every total above.',
            ],
        ];
    }

    /**
     * One panel per active warehouse (or just the caller's own, if
     * warehouse-scoped) — "Gudang Besar / SCM", "Gudang Transit / CIBADAK"
     * in the mockup are simply whatever warehouses actually exist and are
     * active; nothing here is hardcoded to a warehouse name/code, and
     * Karang Tengah (PENDING_CUTOVER, not yet active) is never listed
     * because `is_active=0` / it doesn't exist yet.
     */
    public static function warehouseBreakdown(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): array
    {
        if ($warehouseId !== null) {
            $whStmt = $pdo->prepare('SELECT id, code, name, warehouse_type FROM warehouses WHERE id = :id');
            $whStmt->execute(['id' => $warehouseId]);
            $warehouses = $whStmt->fetchAll();
        } else {
            $whStmt = $pdo->query('SELECT id, code, name, warehouse_type FROM warehouses WHERE is_active = 1 ORDER BY id');
            $warehouses = $whStmt->fetchAll();
        }

        $panels = [];
        foreach ($warehouses as $wh) {
            $whId = (int) $wh['id'];
            $totals = self::periodTotals($pdo, $startDate, $endDate, $whId, null, null);
            $trend = self::dailyRunningValue($pdo, $startDate, $endDate, $whId);
            $panels[] = [
                'warehouse' => $wh,
                'opening_value' => $totals['opening'],
                'external_purchase' => $totals['purchase'],
                'fifo_hpp' => $totals['fifo_hpp'],
                'transfer_in' => $totals['transfer_in'],
                'transfer_out' => $totals['transfer_out'],
                'ending_value' => $totals['ending'],
                'daily_trend' => $trend,
            ];
        }
        return $panels;
    }

    /**
     * Server-paginated daily recap — every calendar day in the range,
     * including zero-movement days (matches the approved mockup, which
     * lists every date). Bounded to MAX_DAYS to keep the PHP loop sane;
     * the API rejects a longer range with a clear error rather than
     * silently truncating it.
     */
    public static function dailyRecap(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, int $page, int $perPage, ?int $categoryId = null, ?string $q = null): array
    {
        $days = self::daysBetween($startDate, $endDate);
        if ($days > self::MAX_DAYS) {
            throw new ValidationException(["date range too large ({$days} days) — narrow it to at most " . self::MAX_DAYS . ' days']);
        }

        $rows = self::buildDailyRows($pdo, $startDate, $endDate, $warehouseId, $categoryId, $q);

        $total = count($rows);
        $offset = max(0, ($page - 1) * $perPage);
        $pageRows = array_slice($rows, $offset, $perPage);

        return [
            'rows' => $pageRows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))],
        ];
    }

    /**
     * The "Trace Detail HPP" panel's data for one date: every FIFO-costed
     * OUT line that day (read straight from fifo_allocations — the same
     * authoritative source FIFO HPP itself is computed from), each row
     * carrying real transaction_id/item_id/warehouse_id so the frontend
     * opens the EXISTING TraceDrawer (openTransaction/openInventory) —
     * this method only assembles the list, it never re-implements the
     * trace itself.
     */
    public static function dayDetail(PDO $pdo, string $date, ?int $warehouseId): array
    {
        $where = ["t.status = 'POSTED'", "t.transaction_type = 'OUT'", 'DATE(t.transaction_date) = :date'];
        $bind = ['date' => $date];
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT a.id AS allocation_id, a.subtotal AS hpp_value, a.qty_allocated, a.unit_cost_base,
                    a.batch_id, b.warehouse_id AS batch_warehouse_id,
                    l.id AS transaction_line_id, l.item_id, l.warehouse_id,
                    i.sku, i.name AS item_name,
                    t.id AS transaction_id, t.transaction_uuid, t.reference_no, t.transaction_date,
                    w.code AS warehouse_code, w.name AS warehouse_name
             FROM fifo_allocations a
             JOIN inventory_transaction_lines l ON l.id = a.transaction_line_id
             JOIN inventory_transactions t ON t.id = l.transaction_id
             JOIN items i ON i.id = l.item_id
             JOIN warehouses w ON w.id = l.warehouse_id
             JOIN inventory_batches b ON b.id = a.batch_id
             WHERE {$whereSql}
             ORDER BY t.id, a.id"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        $totalHpp = 0.0;
        $byWarehouse = [];
        foreach ($rows as $r) {
            $totalHpp += (float) $r['hpp_value'];
            $wKey = $r['warehouse_code'];
            $byWarehouse[$wKey] = ($byWarehouse[$wKey] ?? 0) + (float) $r['hpp_value'];
        }
        arsort($byWarehouse);

        return [
            'date' => $date,
            'total_fifo_hpp' => round($totalHpp, 4),
            'breakdown_by_warehouse' => array_map(
                static fn ($code, $value) => ['warehouse_code' => $code, 'value' => round($value, 4), 'pct' => $totalHpp > 0 ? round($value / $totalHpp * 100, 1) : 0.0],
                array_keys($byWarehouse),
                array_values($byWarehouse)
            ),
            'lines' => $rows,
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /** @return array{opening:float,purchase:float,fifo_hpp:float,ending:float,reconciliation:float,variance:float,adjustment_net:float,opname_net:float,reversal_net:float,production_net:float,transfer_in:float,transfer_out:float,opening_mid_period:float} */
    private static function periodTotals(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId, ?string $q): array
    {
        [$itemJoin, $itemWhere, $itemBind] = self::itemFilterClauses($categoryId, $q);

        $opening = self::signedValueBefore($pdo, $startDate, $warehouseId, $itemJoin, $itemWhere, $itemBind);
        $ending = self::signedValueBefore($pdo, date('Y-m-d', strtotime($endDate . ' +1 day')), $warehouseId, $itemJoin, $itemWhere, $itemBind);

        $movWhere = ["t.status = 'POSTED'", 't.inventory_effect = 1', 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $movBind = array_merge(['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $movWhere[] = 'l.warehouse_id = :wh';
            $movBind['wh'] = $warehouseId;
        }
        $movWhere = array_merge($movWhere, $itemWhere);
        $movWhereSql = implode(' AND ', $movWhere);

        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN t.transaction_type = 'IN' THEN ABS(l.subtotal) ELSE 0 END) AS purchase,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN ABS(l.subtotal) ELSE 0 END) AS transfer_in,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN -ABS(l.subtotal) ELSE 0 END) AS transfer_out,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' THEN l.subtotal ELSE 0 END) AS adjustment_net,
                SUM(CASE WHEN t.transaction_type = 'REVERSAL' THEN l.subtotal ELSE 0 END) AS reversal_net,
                SUM(CASE WHEN t.transaction_type = 'PRODUCTION_OUT' THEN ABS(l.subtotal) WHEN t.transaction_type = 'PRODUCTION_IN' THEN -ABS(l.subtotal) ELSE 0 END) AS production_net,
                SUM(CASE WHEN t.transaction_type = 'OPENING' THEN ABS(l.subtotal) ELSE 0 END) AS opening_mid_period
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$itemJoin}
             WHERE {$movWhereSql}"
        );
        $stmt->execute($movBind);
        $mov = $stmt->fetch() ?: [];

        $adjustmentNet = (float) ($mov['adjustment_net'] ?? 0);
        $opnameNet = self::opnameNet($pdo, $startDate, $endDate, $warehouseId);

        $fifoHpp = self::fifoHppTotal($pdo, $startDate, $endDate, $warehouseId, $itemJoin, $itemWhere, $itemBind);

        $reconciliation = round($opening + (float) ($mov['purchase'] ?? 0) - $ending, 4);
        $variance = round($reconciliation - $fifoHpp, 4);

        return [
            'opening' => round($opening, 4),
            'purchase' => round((float) ($mov['purchase'] ?? 0), 4),
            'fifo_hpp' => round($fifoHpp, 4),
            'ending' => round($ending, 4),
            'reconciliation' => $reconciliation,
            'variance' => $variance,
            'adjustment_net' => round($adjustmentNet, 4),
            'opname_net' => $opnameNet,
            'reversal_net' => round((float) ($mov['reversal_net'] ?? 0), 4),
            'production_net' => round((float) ($mov['production_net'] ?? 0), 4),
            'transfer_in' => round((float) ($mov['transfer_in'] ?? 0), 4),
            'transfer_out' => round((float) ($mov['transfer_out'] ?? 0), 4),
            'opening_mid_period' => round((float) ($mov['opening_mid_period'] ?? 0), 4),
        ];
    }

    private static function signedValueBefore(PDO $pdo, string $beforeDate, ?int $warehouseId, string $itemJoin, array $itemWhere, array $itemBind): float
    {
        $where = ["t.status = 'POSTED'", 't.inventory_effect = 1', 't.transaction_date < :before'];
        $bind = array_merge(['before' => $beforeDate . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $where = array_merge($where, $itemWhere);
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(" . self::SIGNED_VALUE_SQL . "), 0) AS v
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$itemJoin}
             WHERE {$whereSql}"
        );
        $stmt->execute($bind);
        return (float) $stmt->fetchColumn();
    }

    private static function fifoHppTotal(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, string $itemJoin, array $itemWhere, array $itemBind): float
    {
        $where = ["t.status = 'POSTED'", "t.transaction_type = 'OUT'", 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $bind = array_merge(['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $where = array_merge($where, $itemWhere);
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.subtotal), 0) AS v
             FROM fifo_allocations a
             JOIN inventory_transaction_lines l ON l.id = a.transaction_line_id
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$itemJoin}
             WHERE {$whereSql}"
        );
        $stmt->execute($bind);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Opname-caused movement is posted as an ADJUSTMENT (adjustment_type=
     * OPNAME) — already counted inside adjustment_net above. This computes
     * the OPNAME-only slice for the separate disclosure bucket the spec
     * asks for ("Adjustment/Opname/Reversal ... never disappear into
     * HPP"), without double counting it against adjustment_net.
     */
    private static function opnameNet(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): float
    {
        $where = ["t.status = 'POSTED'", "sa.adjustment_type = 'OPNAME'", 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $bind = ['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'];
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(l.subtotal), 0) AS v
             FROM stock_adjustments sa
             JOIN inventory_transactions t ON t.id = sa.transaction_id
             JOIN inventory_transaction_lines l ON l.transaction_id = t.id AND l.item_id = sa.item_id AND l.warehouse_id = sa.warehouse_id
             WHERE {$whereSql}"
        );
        $stmt->execute($bind);
        return round((float) $stmt->fetchColumn(), 4);
    }

    /** @return array{0:string,1:list<string>,2:array<string,mixed>} [join SQL, extra WHERE clauses, bind params] */
    private static function itemFilterClauses(?int $categoryId, ?string $q): array
    {
        if ($categoryId === null && ($q === null || trim($q) === '')) {
            return ['', [], []];
        }
        $join = 'JOIN items fi ON fi.id = l.item_id';
        $where = [];
        $bind = [];
        if ($categoryId !== null) {
            $where[] = 'fi.category_id = :cat_id';
            $bind['cat_id'] = $categoryId;
        }
        if ($q !== null && trim($q) !== '') {
            $where[] = '(fi.sku LIKE :iq1 OR fi.name LIKE :iq2)';
            $like = '%' . trim($q) . '%';
            $bind['iq1'] = $like;
            $bind['iq2'] = $like;
        }
        return [$join, $where, $bind];
    }

    /** Per-day running ending value across a range — used for the warehouse panel sparklines. */
    private static function dailyRunningValue(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): array
    {
        $rows = self::buildDailyRows($pdo, $startDate, $endDate, $warehouseId, null, null);
        return array_map(static fn ($r) => ['date' => $r['date'], 'value' => $r['stok_akhir']], $rows);
    }

    /** @return list<array<string,mixed>> one row per calendar day in [startDate, endDate] */
    private static function buildDailyRows(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId, ?string $q): array
    {
        [$itemJoin, $itemWhere, $itemBind] = self::itemFilterClauses($categoryId, $q);

        $opening = self::signedValueBefore($pdo, $startDate, $warehouseId, $itemJoin, $itemWhere, $itemBind);

        $movWhere = ["t.status = 'POSTED'", 't.inventory_effect = 1', 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $bind = array_merge(['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $movWhere[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $movWhere = array_merge($movWhere, $itemWhere);
        $movWhereSql = implode(' AND ', $movWhere);
        $stmt = $pdo->prepare(
            "SELECT DATE(t.transaction_date) AS d,
                SUM(CASE WHEN t.transaction_type = 'IN' THEN ABS(l.subtotal) ELSE 0 END) AS purchase,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN ABS(l.subtotal) ELSE 0 END) AS transfer_in,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.subtotal) ELSE 0 END) AS transfer_out,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' THEN l.subtotal ELSE 0 END) AS adjustment_net,
                SUM(" . self::SIGNED_VALUE_SQL . ") AS net_signed_value
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$itemJoin}
             WHERE {$movWhereSql}
             GROUP BY DATE(t.transaction_date)"
        );
        $stmt->execute($bind);
        $byDate = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDate[$r['d']] = $r;
        }

        $fifoWhere = ["t.status = 'POSTED'", "t.transaction_type = 'OUT'", 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $fifoBind = array_merge(['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $fifoWhere[] = 'l.warehouse_id = :wh';
            $fifoBind['wh'] = $warehouseId;
        }
        $fifoWhere = array_merge($fifoWhere, $itemWhere);
        $fifoWhereSql = implode(' AND ', $fifoWhere);
        $fstmt = $pdo->prepare(
            "SELECT DATE(t.transaction_date) AS d, SUM(a.subtotal) AS v
             FROM fifo_allocations a
             JOIN inventory_transaction_lines l ON l.id = a.transaction_line_id
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$itemJoin}
             WHERE {$fifoWhereSql}
             GROUP BY DATE(t.transaction_date)"
        );
        $fstmt->execute($fifoBind);
        $fifoByDate = [];
        foreach ($fstmt->fetchAll() as $r) {
            $fifoByDate[$r['d']] = (float) $r['v'];
        }

        $rows = [];
        $running = $opening;
        $cursor = $startDate;
        while (strtotime($cursor) <= strtotime($endDate)) {
            $d = $byDate[$cursor] ?? null;
            $stokAwal = $running;
            $pembelian = $d !== null ? (float) $d['purchase'] : 0.0;
            $transferIn = $d !== null ? (float) $d['transfer_in'] : 0.0;
            $transferOut = $d !== null ? (float) $d['transfer_out'] : 0.0;
            $adjustment = $d !== null ? (float) $d['adjustment_net'] : 0.0;
            $netSigned = $d !== null ? (float) $d['net_signed_value'] : 0.0;
            $fifoOut = $fifoByDate[$cursor] ?? 0.0;

            $stokAkhir = round($running + $netSigned, 4);
            $hppReconciliation = round($stokAwal + $pembelian - $stokAkhir, 4);
            $variance = round($hppReconciliation - $fifoOut, 4);

            $rows[] = [
                'date' => $cursor,
                'stok_awal' => round($stokAwal, 4),
                'pembelian' => round($pembelian, 4),
                'fifo_out' => round($fifoOut, 4),
                'transfer_in' => round($transferIn, 4),
                'transfer_out' => round($transferOut, 4),
                'adjustment' => round($adjustment, 4),
                'stok_akhir' => $stokAkhir,
                'hpp_reconciliation' => $hppReconciliation,
                'variance' => $variance,
            ];

            $running = $stokAkhir;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return $rows;
    }

    private static function daysBetween(string $startDate, string $endDate): int
    {
        $diff = (strtotime($endDate) - strtotime($startDate)) / 86400;
        return (int) $diff + 1;
    }

    /**
     * Assembles the 5 sheets for Export Excel: Ringkasan (the KPI/formula
     * summary), Breakdown Gudang, Rekap Harian, Detail Transaksi FIFO
     * (every OUT allocation in the period — the same rows dayDetail()
     * shows per-day, here for the whole period), and Pergerakan Non-HPP
     * (every Adjustment/Opname/Reversal/Production/mid-period-Opening line
     * — the evidence backing the Variance figure, never hidden). Pure
     * data assembly; ExcelWriterService does the actual file I/O.
     */
    public static function buildExportSheets(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId, ?string $q): array
    {
        $summary = self::summary($pdo, $startDate, $endDate, $warehouseId, $categoryId, $q);
        $warehouses = self::warehouseBreakdown($pdo, $startDate, $endDate, $warehouseId);
        $daily = self::buildDailyRows($pdo, $startDate, $endDate, $warehouseId, $categoryId, $q);

        $summarySheet = [
            'headers' => ['Metrik', 'Nilai (Rp)'],
            'rows' => [
                ['Periode', $startDate . ' s/d ' . $endDate],
                ['Nilai Stok Awal', $summary['opening_value']],
                ['Pembelian Eksternal', $summary['external_purchase']],
                ['Barang Keluar FIFO / HPP', $summary['fifo_hpp']],
                ['Nilai Stok Akhir', $summary['ending_value']],
                ['HPP Rekonsiliasi (Stok Awal + Pembelian - Stok Akhir)', $summary['hpp_reconciliation']],
                ['Variance (HPP Rekonsiliasi - FIFO HPP)', $summary['variance']],
                ['— Pergerakan Non-HPP (komponen varians) —', ''],
                ['Adjustment (net)', $summary['non_hpp_movements']['adjustment_net']],
                ['  termasuk Opname (net)', $summary['non_hpp_movements']['opname_net']],
                ['Reversal (net)', $summary['non_hpp_movements']['reversal_net']],
                ['Produksi (net)', $summary['non_hpp_movements']['production_net']],
                ['Opening di tengah periode', $summary['non_hpp_movements']['opening_mid_period']],
                ['Transfer IN', $summary['non_hpp_movements']['transfer_in']],
                ['Transfer OUT', $summary['non_hpp_movements']['transfer_out']],
                ['Transfer net (harus ~0 di level perusahaan)', $summary['non_hpp_movements']['transfer_net']],
            ],
        ];

        $whSheet = [
            'headers' => ['Gudang', 'Kode', 'Stok Awal', 'Pembelian', 'FIFO OUT/HPP', 'Transfer IN', 'Transfer OUT', 'Stok Akhir'],
            'rows' => array_map(static fn ($p) => [
                $p['warehouse']['name'], $p['warehouse']['code'], $p['opening_value'], $p['external_purchase'],
                $p['fifo_hpp'], $p['transfer_in'], $p['transfer_out'], $p['ending_value'],
            ], $warehouses),
        ];

        $dailySheet = [
            'headers' => ['Tanggal', 'Stok Awal', 'Pembelian', 'FIFO OUT/HPP', 'Transfer IN', 'Transfer OUT', 'Adjustment', 'Stok Akhir', 'HPP Rekonsiliasi', 'Variance'],
            'rows' => array_map(static fn ($d) => [
                $d['date'], $d['stok_awal'], $d['pembelian'], $d['fifo_out'], $d['transfer_in'],
                $d['transfer_out'], $d['adjustment'], $d['stok_akhir'], $d['hpp_reconciliation'], $d['variance'],
            ], $daily),
        ];

        $fifoDetailSheet = self::exportFifoDetailSheet($pdo, $startDate, $endDate, $warehouseId, $categoryId, $q);
        $nonHppSheet = self::exportNonHppSheet($pdo, $startDate, $endDate, $warehouseId);

        return [
            'Ringkasan' => $summarySheet,
            'Breakdown Gudang' => $whSheet,
            'Rekap Harian' => $dailySheet,
            'Detail Transaksi FIFO' => $fifoDetailSheet,
            'Pergerakan Non-HPP' => $nonHppSheet,
        ];
    }

    private static function exportFifoDetailSheet(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId, ?string $q): array
    {
        [$itemJoin, $itemWhere, $itemBind] = self::itemFilterClauses($categoryId, $q);
        $where = ["t.status = 'POSTED'", "t.transaction_type = 'OUT'", 't.transaction_date >= :start', 't.transaction_date < :end_excl'];
        $bind = array_merge(['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'], $itemBind);
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $where = array_merge($where, $itemWhere);
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT t.transaction_date, t.reference_no, t.transaction_uuid, i.sku, i.name AS item_name,
                    w.code AS warehouse_code, a.qty_allocated, a.unit_cost_base, a.subtotal
             FROM fifo_allocations a
             JOIN inventory_transaction_lines l ON l.id = a.transaction_line_id
             JOIN inventory_transactions t ON t.id = l.transaction_id
             JOIN items i ON i.id = l.item_id
             JOIN warehouses w ON w.id = l.warehouse_id
             {$itemJoin}
             WHERE {$whereSql}
             ORDER BY t.transaction_date, t.id"
        );
        $stmt->execute($bind);

        return [
            'headers' => ['Tanggal', 'Referensi', 'Gudang', 'SKU', 'Nama Barang', 'Qty', 'Harga Satuan (FIFO)', 'Total HPP'],
            'rows' => array_map(static fn ($r) => [
                $r['transaction_date'], $r['reference_no'] ?? $r['transaction_uuid'], $r['warehouse_code'],
                $r['sku'], $r['item_name'], (float) $r['qty_allocated'], (float) $r['unit_cost_base'], (float) $r['subtotal'],
            ], $stmt->fetchAll()),
        ];
    }

    private static function exportNonHppSheet(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId): array
    {
        $where = [
            "t.status = 'POSTED'", 't.inventory_effect = 1',
            "t.transaction_type IN ('ADJUSTMENT','REVERSAL','PRODUCTION_IN','PRODUCTION_OUT','OPENING')",
            't.transaction_date >= :start', 't.transaction_date < :end_excl',
        ];
        $bind = ['start' => $startDate . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'];
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $warehouseId;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT t.transaction_date, t.transaction_type, t.reference_no, t.transaction_uuid,
                    i.sku, i.name AS item_name, w.code AS warehouse_code, l.subtotal,
                    sa.adjustment_type, sa.reason
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             JOIN items i ON i.id = l.item_id
             JOIN warehouses w ON w.id = l.warehouse_id
             LEFT JOIN stock_adjustments sa ON sa.transaction_id = t.id AND sa.item_id = l.item_id AND sa.warehouse_id = l.warehouse_id
             WHERE {$whereSql}
             ORDER BY t.transaction_date, t.id"
        );
        $stmt->execute($bind);

        return [
            'headers' => ['Tanggal', 'Jenis', 'Sub-jenis (Opname/dll)', 'Referensi', 'Gudang', 'SKU', 'Nama Barang', 'Nilai (signed)', 'Alasan'],
            'rows' => array_map(static fn ($r) => [
                $r['transaction_date'], $r['transaction_type'], $r['adjustment_type'] ?? '-',
                $r['reference_no'] ?? $r['transaction_uuid'], $r['warehouse_code'], $r['sku'], $r['item_name'],
                (float) $r['subtotal'], $r['reason'] ?? '-',
            ], $stmt->fetchAll()),
        ];
    }
}
