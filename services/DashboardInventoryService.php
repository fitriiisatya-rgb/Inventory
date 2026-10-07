<?php
declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/InventoryEffectiveDateService.php';

use PDO;

/**
 * Main Dashboard — READ-ONLY inventory overview + drill-down detail.
 *
 *   GET /dashboard/inventory          -> overview()  (every card on the page, ONE call)
 *   GET /dashboard/inventory/detail   -> detail()    (the paginated rows behind one card)
 *
 * NEVER writes. Only SELECTs; no stock, transaction, transfer, opname,
 * adjustment, master-data or schema change.
 *
 * ------------------------------------------------------------------
 * AUTHORITATIVE SOURCES — nothing is re-invented, the ledger rules below
 * are the exact ones the existing reports already use
 * (InventoryHppReportService / InventoryMovementReportService /
 * InventorySummaryReportService "Ringkasan Inventory"); this class
 * re-uses their public primitives and mirrors their SQL conditions, and
 * tests/inventory_dashboard_test.php proves equality against
 * InventorySummaryReportService::summary() for every scope/period.
 *
 *  Valuation unit: the ledger's per-line SIGNED VALUE
 *    InventoryHppReportService::SIGNED_VALUE_SQL
 *  (IN/OPENING/TRANSFER_IN/PRODUCTION_OUT = +subtotal, OUT/TRANSFER_OUT/
 *  PRODUCTION_IN = -|subtotal|, ADJUSTMENT/REVERSAL as stored), over
 *  inventory_transaction_lines of status POSTED/VOID with
 *  inventory_effect = 1. Quantities use the same sign rule on base_qty.
 *
 *  STOK AWAL   ledger balance BEFORE the period's effective start (a
 *              boundary-exact OPENING row counts as beginning inventory),
 *              grouped per item x warehouse, so the card total and the
 *              drill-down rows are the same set by construction.
 *  SINGLE SOURCE (production fix): the movement summary is NOT computed here. movement() takes every figure from MovementReportV3Service::overview() — the service behind
 *  "Laporan Pergerakan Stok" — so the dashboard and the report can never hold two accounting interpretations:
 *      STOK AWAL + STOCK IN - STOCK OUT + TRANSFER IN - TRANSFER OUT + ADJUSTMENT = STOK AKHIR
 *  Company-wide, Transfer IN - Transfer OUT is the value still in transit (0 once every transfer is received); per warehouse each side stands alone.
 *  ADJUSTMENT is the report's own figure; `adjustment_breakdown` NAMES it by ledger transaction type (Adjustment +/-, Saldo Awal Baru, Reversal, Produksi, IN yang di-void,
 *  any other type by its code) and its `explained_diff` against the report is returned, never plugged. There is no generic "Pergerakan lain".
 *  STOCK IN = type IN posted; STOCK OUT = type OUT; the drill-downs list the very ledger lines behind each figure.
 *  The only dashboard-owned queries are the drill-down rows, the transaction counts and two cross-checks (ledger opening / closing balance == the report's).
 *
 *  Period: the requested start is clamped to the go-live (live opening)
 *  date exactly like every other report (cutoverContext()); a period that
 *  ends before go-live returns honest zeros plus a note — never a
 *  fabricated value.
 *
 *  NILAI STOK (top KPI) keeps the existing definition: on-hand batches
 *  (SUM qty_base x unit_cost_base) + value in transit (PENDING transfers,
 *  attributed to the source warehouse) — the same formulas as
 *  InventoryService::companyTotalValue()/warehouseDashboardSummary().
 *
 *  Scope: warehouse_id null = whole company (SUPERADMIN/unscoped roles);
 *  a warehouse-scoped STOCK user is forced to their own warehouse by the
 *  route (inv_hpp_resolve_warehouse_scope) before this class is called.
 * ------------------------------------------------------------------
 */
final class DashboardInventoryService
{
    public const MAX_RANGE_DAYS = 400;
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    private const QTY_SIGNED_SQL = "CASE
        WHEN t.transaction_type IN ('IN','OPENING','TRANSFER_IN','PRODUCTION_OUT') THEN l.base_qty
        WHEN t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') THEN -ABS(l.base_qty)
        ELSE l.base_qty
    END";

    private const TYPE_LABELS = [
        'IN' => 'Stock IN', 'OUT' => 'Stock OUT', 'TRANSFER_OUT' => 'Transfer Keluar', 'TRANSFER_IN' => 'Transfer Diterima',
        'ADJUSTMENT' => 'Adjustment', 'PRODUCTION_IN' => 'Produksi (Bahan)', 'PRODUCTION_OUT' => 'Produksi (Hasil)',
        'OPENING' => 'Saldo Awal', 'REVERSAL' => 'Reversal', 'OPNAME' => 'Opname',
    ];

    /** @return array{key:string,start_date:string,end_date:string,label:string} */
    public static function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        $today = date('Y-m-d');
        if ($period === 'today') {
            return ['key' => 'today', 'start_date' => $today, 'end_date' => $today, 'label' => 'Hari Ini'];
        }
        if ($period === 'custom') {
            $re = '/^\d{4}-\d{2}-\d{2}$/';
            if ($from === null || $to === null || !preg_match($re, $from) || !preg_match($re, $to) || strtotime($from) === false || strtotime($to) === false) {
                throw new ValidationException(['date_from dan date_to (YYYY-MM-DD) wajib untuk periode custom']);
            }
            if ($from > $to) {
                throw new ValidationException(['date_from tidak boleh setelah date_to']);
            }
            if ($to > $today) {
                throw new ValidationException(['date_to tidak boleh di masa depan']);
            }
            if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
                throw new ValidationException(['rentang tanggal maksimal ' . self::MAX_RANGE_DAYS . ' hari']);
            }
            return ['key' => 'custom', 'start_date' => $from, 'end_date' => $to, 'label' => "{$from} s/d {$to}"];
        }
        if ($period !== 'month' && $period !== '') {
            throw new ValidationException(['period harus today, month atau custom']);
        }
        return ['key' => 'month', 'start_date' => date('Y-m-01'), 'end_date' => $today, 'label' => 'Bulan Ini'];
    }

    // =====================================================================
    // OVERVIEW
    // =====================================================================

    public static function overview(PDO $pdo, ?int $warehouseId, string $period = 'month', ?string $from = null, ?string $to = null): array
    {
        $p = self::resolvePeriod($period, $from, $to);
        $today = date('Y-m-d');
        $scope = self::scopeBlock($pdo, $warehouseId);

        return [
            'generated_at' => date('c'),
            'today' => $today,
            'scope' => $scope,
            'period' => $p,
            'summary' => self::summary($pdo, $warehouseId),
            'movement' => self::movement($pdo, $warehouseId, $p),
            'attention' => self::attention($pdo, $warehouseId),
            'today_activity' => self::todayActivity($pdo, $warehouseId, $today),
            'recent_activity' => self::recentActivity($pdo, $warehouseId, 8),
            'top_low_stock' => self::topLowStock($pdo, $warehouseId),
            'top_inventory_value' => self::topInventoryValue($pdo, $warehouseId),
        ];
    }

    private static function scopeBlock(PDO $pdo, ?int $warehouseId): array
    {
        if ($warehouseId === null) {
            return ['warehouse_id' => null, 'warehouse_name' => 'Semua Gudang', 'warehouse_code' => null, 'is_company' => true];
        }
        $stmt = $pdo->prepare('SELECT id, code, name FROM warehouses WHERE id = :id');
        $stmt->execute(['id' => $warehouseId]);
        $w = $stmt->fetch();
        if (!$w) {
            throw new NotFoundException("warehouse {$warehouseId} not found");
        }
        return ['warehouse_id' => (int) $w['id'], 'warehouse_name' => $w['name'], 'warehouse_code' => $w['code'], 'is_company' => false];
    }

    // ---------------------------------------------------------------- top KPIs

    private static function summary(PDO $pdo, ?int $wh): array
    {
        $whB = $wh !== null ? ' WHERE warehouse_id = :wh' : '';
        $bind = $wh !== null ? ['wh' => $wh] : [];

        $on = $pdo->prepare("SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches{$whB}");
        $on->execute($bind);
        $onHand = round((float) $on->fetchColumn(), 4);

        $tr = $pdo->prepare(
            "SELECT COALESCE(SUM(wtl.qty_base * wtl.unit_cost_base), 0)
               FROM warehouse_transfer_lines wtl JOIN warehouse_transfers wt ON wt.id = wtl.transfer_id
              WHERE wt.status = 'PENDING'" . ($wh !== null ? ' AND wt.from_warehouse_id = :wh' : '')
        );
        $tr->execute($bind);
        $inTransit = round((float) $tr->fetchColumn(), 4);

        $skuWith = $pdo->prepare('SELECT COUNT(*) FROM (SELECT item_id FROM inventory_batches' . $whB . ' GROUP BY item_id HAVING ABS(SUM(qty_base)) > 0.0000005) s');
        $skuWith->execute($bind);

        return [
            'total_sku' => (int) $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'ACTIVE'")->fetchColumn(),
            'sku_with_stock' => (int) $skuWith->fetchColumn(),
            'stock_value' => ['on_hand' => $onHand, 'in_transit' => $inTransit, 'total' => round($onHand + $inTransit, 4)],
            'pending_transfers' => self::pendingTransferCount($pdo, $wh),
            'active_opname' => self::activeOpnameCount($pdo, $wh),
        ];
    }

    private static function pendingTransferCount(PDO $pdo, ?int $wh): int
    {
        if ($wh === null) {
            return (int) $pdo->query("SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING'")->fetchColumn();
        }
        $s = $pdo->prepare("SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING' AND (from_warehouse_id = :a OR to_warehouse_id = :b)");
        $s->execute(['a' => $wh, 'b' => $wh]);
        return (int) $s->fetchColumn();
    }

    private static function activeOpnameCount(PDO $pdo, ?int $wh): int
    {
        if ($wh === null) {
            return (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED')")->fetchColumn();
        }
        $s = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED') AND warehouse_id = :wh");
        $s->execute(['wh' => $wh]);
        return (int) $s->fetchColumn();
    }

    // ---------------------------------------------------------------- movement

    private static function movement(PDO $pdo, ?int $wh, array $p): array
    {
        // ONE accounting interpretation: the very service behind "Laporan Pergerakan Stok" (Reports v3). It owns the go-live clamp, the VOID rules, the FIFO ledger values and the split
        //   Stok Awal + IN - OUT + Transfer IN - Transfer OUT + Adjustment = Stok Akhir
        // The dashboard only presents its figures; it never recomputes opening / in / out / transfer / adjustment / closing itself.
        if (!class_exists(MovementReportV3Service::class)) {
            throw new \RuntimeException('MovementReportV3Service belum terpasang: ringkasan pergerakan dashboard memakai sumber yang sama dengan Laporan Pergerakan Stok (Reports V3).');
        }
        $v = MovementReportV3Service::overview($pdo, $p['start_date'], $p['end_date'], $wh);
        $cutover = $v['cutover'];
        $eff = $cutover['effective_start_date'];
        $note = null;
        if ($cutover['live_opening_date'] !== null && $cutover['effective_start_date'] > $p['start_date']) {
            $note = "Periode dihitung mulai {$cutover['effective_start_date']} (Opening Go-Live).";
        }

        if ($cutover['is_pre_go_live_period']) {
            $zero = ['value' => 0.0, 'sku_count' => 0, 'tx_count' => 0, 'line_count' => 0];
            return [
                'cutover' => $cutover, 'effective_start_date' => $eff, 'note' => 'Periode ini sebelum Opening Go-Live — tidak ada aktivitas ekonomi.',
                'is_pre_go_live' => true, 'source' => 'MovementReportV3Service',
                'opening_stock' => $zero, 'stock_in' => $zero, 'stock_out' => $zero, 'adjustment' => $zero, 'closing_stock' => $zero,
                'transfer' => ['scope' => $wh === null ? 'company' : 'warehouse', 'in' => ['value' => 0.0, 'tx_count' => 0], 'out' => ['value' => 0.0, 'tx_count' => 0], 'net' => 0.0],
                'components' => ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'adjustment' => 0.0, 'closing' => 0.0, 'difference' => 0.0],
                'adjustment_breakdown' => ['net' => 0.0, 'items' => [], 'explained_diff' => 0.0],
                'reconciliation' => ['ok' => true, 'identity_diff' => 0.0, 'ledger_closing' => 0.0, 'transfer_net_zero' => true, 'adjustment_explained' => true, 'batch_on_hand' => null, 'batch_diff' => null, 'batch_ok' => null],
            ];
        }

        $t = $v['split_totals'];
        $c = $v['totals'];
        $opening = self::balanceTotals($pdo, $eff, true, $wh, []);
        $endExcl = date('Y-m-d', strtotime($p['end_date'] . ' +1 day'));
        $closing = self::balanceTotals($pdo, $endExcl, false, $wh, []);
        $tr = self::transferTx($pdo, $eff, $p['end_date'], $wh);
        $cnt = self::inOutCounts($pdo, $eff, $p['end_date'], $wh);
        $items = self::adjustmentBreakdown($pdo, $eff, $p['end_date'], $wh);
        $breakdownNet = round(array_sum(array_column($items, 'value')), 4);
        $explainedDiff = round((float) $t['adjustment'] - $breakdownNet, 4);

        $identity = (float) $t['difference'];
        // the drill-down balances come from the ledger directly: they must equal the report's opening / closing (shown, never hidden, when they do not)
        $drillOpen = round($opening['value'] - (float) $t['opening'], 4);
        $drillClose = round($closing['value'] - (float) $t['closing'], 4);
        $recon = [
            'ok' => abs($identity) <= 0.01 && !empty($v['reconciliation']['ok']) && abs($drillOpen) <= 0.01 && abs($drillClose) <= 0.01 && abs($explainedDiff) <= 0.01,
            'identity_diff' => $identity,
            'ledger_closing' => (float) $t['closing'],
            'report_ok' => !empty($v['reconciliation']['ok']),
            'drill_opening_diff' => $drillOpen, 'drill_closing_diff' => $drillClose,
            'adjustment_explained' => abs($explainedDiff) <= 0.01, 'adjustment_explained_diff' => $explainedDiff,
            // company-wide Transfer IN - Transfer OUT is the value still in transit (0 once every transfer is received)
            'transfer_net_zero' => $wh !== null || abs((float) $t['transfer_net']) <= 0.01,
            'batch_on_hand' => null, 'batch_diff' => null, 'batch_ok' => null,
        ];
        if ($p['end_date'] >= date('Y-m-d')) {
            $s = self::summary($pdo, $wh);
            $recon['batch_on_hand'] = $s['stock_value']['on_hand'];
            $recon['batch_diff'] = round((float) $t['closing'] - $s['stock_value']['on_hand'], 4);
            $recon['batch_ok'] = abs($recon['batch_diff']) <= 0.01;
        }

        return [
            'cutover' => $cutover, 'effective_start_date' => $eff, 'note' => $note, 'is_pre_go_live' => false, 'source' => 'MovementReportV3Service',
            'opening_stock' => ['value' => (float) $t['opening'], 'sku_count' => (int) $c['sku_opening'], 'row_count' => $opening['row_count']],
            'stock_in' => ['value' => (float) $t['in'], 'tx_count' => $cnt['in_tx'], 'sku_count' => $cnt['in_sku']],
            'stock_out' => ['value' => (float) $t['out'], 'tx_count' => $cnt['out_tx'], 'sku_count' => $cnt['out_sku']],
            'adjustment' => ['value' => (float) $t['adjustment'], 'tx_count' => array_sum(array_column($items, 'tx_count'))],
            'closing_stock' => ['value' => (float) $t['closing'], 'sku_count' => (int) $c['sku_closing'], 'row_count' => $closing['row_count']],
            'transfer' => ['scope' => $wh === null ? 'company' : 'warehouse', 'in' => ['value' => (float) $t['tin'], 'tx_count' => $tr['in_tx']], 'out' => ['value' => (float) $t['tout'], 'tx_count' => $tr['out_tx']], 'net' => (float) $t['transfer_net']],
            'components' => ['opening' => (float) $t['opening'], 'in' => (float) $t['in'], 'out' => (float) $t['out'], 'transfer_in' => (float) $t['tin'], 'transfer_out' => (float) $t['tout'],
                'adjustment' => (float) $t['adjustment'], 'closing' => (float) $t['closing'], 'difference' => $identity],
            'adjustment_breakdown' => ['net' => $breakdownNet, 'items' => $items, 'explained_diff' => $explainedDiff],
            'reconciliation' => $recon,
        ];
    }

    /**
     * Transaction / SKU counts of Stock IN (type IN, posted) and Stock OUT (type OUT) only. The report's own tx_in / tx_out also fold a transfer receipt / dispatch in when ONE
     * warehouse is selected, so they are not "Stock IN / OUT" counts; the VALUES still come from the report.
     */
    private static function inOutCounts(PDO $pdo, string $effStart, string $end, ?int $wh): array
    {
        $where = ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', 't.transaction_date >= :start', 't.transaction_date < :end_excl', "t.transaction_type IN ('IN','OUT')"];
        $bind = ['start' => $effStart . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00'];
        if ($wh !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $in = "t.transaction_type = 'IN' AND t.status = 'POSTED'";
        $st = $pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN {$in} THEN t.id END) AS it, COUNT(DISTINCT CASE WHEN {$in} THEN l.item_id END) AS isk,
                                    COUNT(DISTINCT CASE WHEN t.transaction_type = 'OUT' THEN t.id END) AS ot, COUNT(DISTINCT CASE WHEN t.transaction_type = 'OUT' THEN l.item_id END) AS osk
                               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id WHERE " . implode(' AND ', $where));
        $st->execute($bind);
        $r = $st->fetch();
        return ['in_tx' => (int) $r['it'], 'in_sku' => (int) $r['isk'], 'out_tx' => (int) $r['ot'], 'out_sku' => (int) $r['osk']];
    }

    /** Transfer transaction counts for the card detail (the VALUES come from the report). */
    private static function transferTx(PDO $pdo, string $effStart, string $end, ?int $wh): array
    {
        $where = ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', 't.transaction_date >= :start', 't.transaction_date < :end_excl', "t.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT')"];
        $bind = ['start' => $effStart . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00'];
        if ($wh !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $st = $pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN t.id END) AS i, COUNT(DISTINCT CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN t.id END) AS o
                               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id WHERE " . implode(' AND ', $where));
        $st->execute($bind);
        $r = $st->fetch();
        return ['in_tx' => (int) $r['i'], 'out_tx' => (int) $r['o']];
    }

    /**
     * What the report calls "Adjustment", NAMED by ledger transaction type (no catch-all): every ledger line of the period that is not IN (posted), OUT or a transfer, partitioned by
     * transaction_type (and status / sign). Their sum must equal the report's Adjustment — the difference is returned as `explained_diff`, never plugged.
     * @return list<array{key:string,label:string,value:float,tx_count:int,types:string}>
     */
    private static function adjustmentBreakdown(PDO $pdo, string $effStart, string $end, ?int $wh): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $signed = InventoryHppReportService::SIGNED_VALUE_SQL;
        $where = [
            "t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :start", "{$td} < :end_excl",
            "NOT (t.transaction_type = 'OPENING' AND {$td} = :start_boundary)",
            "NOT (t.transaction_type = 'IN' AND t.status = 'POSTED')", "t.transaction_type NOT IN ('OUT','TRANSFER_IN','TRANSFER_OUT')",
        ];
        $bind = ['start' => $effStart . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00', 'start_boundary' => $effStart . ' 00:00:00'];
        if ($wh !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $st = $pdo->prepare(
            "SELECT t.transaction_type AS ty, t.status AS st, (CASE WHEN l.subtotal < 0 THEN -1 ELSE 1 END) AS sg, SUM({$signed}) AS v, COUNT(DISTINCT t.id) AS n
               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
              WHERE " . implode(' AND ', $where) . ' GROUP BY t.transaction_type, t.status, (CASE WHEN l.subtotal < 0 THEN -1 ELSE 1 END)'
        );
        $st->execute($bind);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $ty = (string) $r['ty'];
            $key = match (true) {
                $ty === 'ADJUSTMENT' => (int) $r['sg'] > 0 ? 'adjustment_positive' : 'adjustment_negative',
                $ty === 'OPENING' => 'opening_in',
                $ty === 'REVERSAL' => 'reversal',
                $ty === 'PRODUCTION_IN' => 'production_in',
                $ty === 'PRODUCTION_OUT' => 'production_out',
                $ty === 'IN' => 'in_void',
                default => 'other',
            };
            $label = match ($key) {
                'adjustment_positive' => 'Adjustment (+) — koreksi / Stock Opname',
                'adjustment_negative' => 'Adjustment (−) — koreksi / Stock Opname',
                'opening_in' => 'Saldo Awal Baru (OPENING di tengah periode)',
                'reversal' => 'Reversal',
                'production_in' => 'Produksi — bahan keluar (PRODUCTION_IN)',
                'production_out' => 'Produksi — hasil masuk (PRODUCTION_OUT)',
                'in_void' => 'Stock IN yang di-void (entri asli)',
                default => 'Jenis ledger lain',
            };
            $rows[$key] ??= ['key' => $key, 'label' => $label, 'value' => 0.0, 'tx_count' => 0, 'types' => ''];
            if (!in_array($ty, explode(',', $rows[$key]['types']), true)) {
                $rows[$key]['types'] = ltrim($rows[$key]['types'] . ',' . $ty, ',');
                if ($key === 'other') {
                    $rows[$key]['label'] = 'Jenis ledger lain: ' . $rows[$key]['types'];
                }
            }
            $rows[$key]['value'] = round($rows[$key]['value'] + (float) $r['v'], 4);
            $rows[$key]['tx_count'] += (int) $r['n'];
        }
        $order = ['adjustment_positive', 'adjustment_negative', 'opening_in', 'reversal', 'production_in', 'production_out', 'in_void'];
        uksort($rows, static fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)) ?: strcmp((string) $a, (string) $b));
        return array_values($rows);
    }

    // -------------------------------------------------- ledger balance (awal/akhir)

    /** @return array{0:string,1:array<string,mixed>} grouped subquery (item x warehouse) + binds */
    private static function balanceGrouped(PDO $pdo, string $beforeDate, bool $boundaryOpening, ?int $wh): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $signed = InventoryHppReportService::SIGNED_VALUE_SQL;
        $date = $boundaryOpening
            ? "({$td} < :before OR (t.transaction_type = 'OPENING' AND {$td} = :before_b))"
            : "{$td} < :before";
        $bind = ['before' => $beforeDate . ' 00:00:00'];
        if ($boundaryOpening) {
            $bind['before_b'] = $beforeDate . ' 00:00:00';
        }
        $where = ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', $date];
        if ($wh !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $sql = 'SELECT l.item_id, l.warehouse_id, SUM(' . self::QTY_SIGNED_SQL . ") AS qty, SUM({$signed}) AS value
                  FROM inventory_transaction_lines l
                  JOIN inventory_transactions t ON t.id = l.transaction_id
                 WHERE " . implode(' AND ', $where) . '
                 GROUP BY l.item_id, l.warehouse_id
                HAVING ABS(SUM(' . self::QTY_SIGNED_SQL . ')) > 0.0000005 OR ABS(SUM(' . $signed . ')) > 0.00005';
        return [$sql, $bind];
    }

    /**
     * @param array{q?:?string,category_id?:?int,warehouse_id?:?int} $filters
     * @return array{value:float,sku_count:int,row_count:int}
     */
    private static function balanceTotals(PDO $pdo, string $beforeDate, bool $boundaryOpening, ?int $wh, array $filters): array
    {
        [$g, $bind] = self::balanceGrouped($pdo, $beforeDate, $boundaryOpening, $wh);
        [$fw, $fb] = self::itemFilterSql($filters, 'g.warehouse_id');
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(g.value),0) AS v, COUNT(DISTINCT g.item_id) AS skus, COUNT(*) AS n
                                 FROM ({$g}) g JOIN items i ON i.id = g.item_id WHERE {$fw}");
        $stmt->execute($bind + $fb);
        $r = $stmt->fetch();
        return ['value' => round((float) $r['v'], 4), 'sku_count' => (int) $r['skus'], 'row_count' => (int) $r['n']];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function itemFilterSql(array $f, string $whCol): array
    {
        $w = ['1=1'];
        $b = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(i.sku LIKE :f_q1 OR i.name LIKE :f_q2)';
            $b['f_q1'] = '%' . $q . '%';
            $b['f_q2'] = '%' . $q . '%';
        }
        if (!empty($f['category_id'])) {
            $w[] = 'i.category_id = :f_cat';
            $b['f_cat'] = (int) $f['category_id'];
        }
        if (!empty($f['warehouse_id'])) {
            $w[] = "{$whCol} = :f_wh";
            $b['f_wh'] = (int) $f['warehouse_id'];
        }
        return [implode(' AND ', $w), $b];
    }

    // ---------------------------------------------------------------- attention

    private static function attention(PDO $pdo, ?int $wh): array
    {
        $params = ['warehouse_id' => $wh, 'active_only' => true, 'include_zero_stock' => true, 'page' => 1, 'per_page' => 1];
        $all = StockReportService::list($pdo, $params);
        $critical = StockReportService::list($pdo, ['status' => 'CRITICAL'] + $params);

        $dead = self::conditionFromLatestOpname($pdo, $wh, 'final_deadstock_qty');
        $rusak = self::conditionFromLatestOpname($pdo, $wh, 'final_rusak_qty');
        $expired = self::nearExpired($pdo, $wh);

        $rows = [
            ['key' => 'out_of_stock', 'label' => 'Stok Habis', 'hint' => 'SKU tidak ada stok', 'sku_count' => $all['summary']['out_of_stock_count'], 'value' => 0.0,
                'action' => ['type' => 'stock_report', 'filters' => ['status' => 'OUT_OF_STOCK']]],
            ['key' => 'below_minimum', 'label' => 'Di Bawah Minimum', 'hint' => 'SKU di bawah minimum', 'sku_count' => $critical['summary']['total_items'], 'value' => $critical['summary']['total_value'],
                'action' => ['type' => 'stock_report', 'filters' => ['status' => 'CRITICAL']]],
            ['key' => 'dead_stock', 'label' => 'Dead Stock', 'hint' => $dead['hint'], 'sku_count' => $dead['sku_count'], 'value' => $dead['value'],
                'action' => ['type' => 'detail', 'detail' => 'deadstock']],
            ['key' => 'rusak', 'label' => 'Rusak', 'hint' => $rusak['hint'], 'sku_count' => $rusak['sku_count'], 'value' => $rusak['value'],
                'action' => ['type' => 'detail', 'detail' => 'rusak']],
        ];
        if ($expired['has_data']) {
            $rows[] = ['key' => 'expired', 'label' => 'Expired / Near Expired', 'hint' => 'Kedaluwarsa ≤ 30 hari', 'sku_count' => $expired['sku_count'], 'value' => $expired['value'],
                'action' => ['type' => 'tab', 'tab' => 'laporan-expiry']];
        }
        return $rows;
    }

    /**
     * "Rusak" / "Dead Stock": the quantity of that condition recorded by the LATEST POSTED Stock
     * Opname of each warehouse in scope (stock_opname_lines.final_rusak_qty / final_deadstock_qty x
     * the line's unit_cost_base) — the same figures the Stock Opname result shows. A recorded
     * finding, not a live balance, and labelled so. (Dead Stock used to be a "no movement for
     * >= 90 days" report; it now follows the SO result as the owner asked.)
     *
     * @return array{sku_count:int,value:float,hint:string}
     */
    private static function conditionFromLatestOpname(PDO $pdo, ?int $wh, string $col): array
    {
        [$sql, $bind] = self::conditionSql($col, $wh, null);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT x.item_id) AS skus, COALESCE(SUM(x.value),0) AS v FROM ({$sql}) x");
        $stmt->execute($bind);
        $r = $stmt->fetch();
        return ['sku_count' => (int) $r['skus'], 'value' => round((float) $r['v'], 4), 'hint' => 'Hasil Stock Opname terakhir'];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function conditionSql(string $col, ?int $wh, ?string $q): array
    {
        if (!in_array($col, ['final_rusak_qty', 'final_deadstock_qty'], true)) {
            throw new \InvalidArgumentException('unsupported condition column');
        }
        $bind = [];
        $where = ["s.status = 'POSTED'", "sol.{$col} > 0"];
        // latest POSTED session per warehouse (date desc, id desc)
        $where[] = "s.id = (SELECT s2.id FROM stock_opname_sessions s2 WHERE s2.warehouse_id = s.warehouse_id AND s2.status = 'POSTED' ORDER BY s2.session_date DESC, s2.id DESC LIMIT 1)";
        if ($wh !== null) {
            $where[] = 's.warehouse_id = :r_wh';
            $bind['r_wh'] = $wh;
        }
        if ($q !== null && trim($q) !== '') {
            $where[] = '(i.sku LIKE :r_q1 OR i.name LIKE :r_q2)';
            $bind['r_q1'] = $bind['r_q2'] = '%' . trim($q) . '%';
        }
        $sql = "SELECT sol.item_id, i.sku, i.name, u.code AS unit, w.name AS warehouse_name, w.code AS warehouse_code,
                       s.session_number, s.id AS session_id, sol.{$col} AS qty, sol.unit_cost_base AS hpp,
                       ROUND(sol.{$col} * sol.unit_cost_base, 2) AS value, COALESCE(sol.final_notes, sol.notes) AS note
                  FROM stock_opname_lines sol
                  JOIN stock_opname_sessions s ON s.id = sol.session_id
                  JOIN items i ON i.id = sol.item_id
                  JOIN units u ON u.id = i.base_unit_id
                  JOIN warehouses w ON w.id = s.warehouse_id
                 WHERE " . implode(' AND ', $where);
        return [$sql, $bind];
    }

    /** @return array{has_data:bool,sku_count:int,value:float} batches expiring within 30 days (or already expired), only when the dataset has expiry dates at all */
    private static function nearExpired(PDO $pdo, ?int $wh): array
    {
        $r = ExpiryReportService::list($pdo, ['warehouse_id' => $wh, 'page' => 1, 'per_page' => 5000]);
        if (($r['pagination']['total'] ?? 0) === 0) {
            return ['has_data' => false, 'sku_count' => 0, 'value' => 0.0];
        }
        $items = [];
        $value = 0.0;
        foreach ($r['rows'] as $row) {
            if ($row['days_remaining'] <= 30) {
                $items[$row['item']['id']] = true;
                $value += (float) $row['inventory_value'];
            }
        }
        return ['has_data' => true, 'sku_count' => count($items), 'value' => round($value, 4)];
    }

    // ---------------------------------------------------------------- activity

    private static function todayActivity(PDO $pdo, ?int $wh, string $today): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $bind = ['start' => $today . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00'];
        $whSql = '';
        if ($wh !== null) {
            $whSql = ' AND l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $stmt = $pdo->prepare(
            "SELECT t.transaction_type AS type, COUNT(DISTINCT t.id) AS n, COALESCE(SUM(ABS(l.subtotal)), 0) AS v
               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
              WHERE t.status = 'POSTED' AND t.inventory_effect = 1 AND t.transaction_type IN ('IN','OUT','TRANSFER_OUT','TRANSFER_IN')
                AND {$td} >= :start AND {$td} < :end_excl{$whSql}
              GROUP BY t.transaction_type"
        );
        $stmt->execute($bind);
        $by = [];
        foreach ($stmt->fetchAll() as $r) {
            $by[$r['type']] = ['count' => (int) $r['n'], 'value' => round((float) $r['v'], 2)];
        }
        $zero = ['count' => 0, 'value' => 0.0];
        return [
            'stock_in' => $by['IN'] ?? $zero, 'stock_out' => $by['OUT'] ?? $zero,
            'transfer_out' => $by['TRANSFER_OUT'] ?? $zero, 'transfer_in' => $by['TRANSFER_IN'] ?? $zero,
        ];
    }

    private static function recentActivity(PDO $pdo, ?int $wh, int $limit): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $bind = [];
        $whSql = '';
        if ($wh !== null) {
            $whSql = ' AND l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $stmt = $pdo->prepare(
            "SELECT t.id, t.transaction_type, {$td} AS transaction_date, t.reference_no, t.status, w.name AS warehouse_name,
                    COUNT(*) AS item_count, COALESCE(SUM(ABS(l.subtotal)), 0) AS value
               FROM inventory_transaction_lines l
               JOIN inventory_transactions t ON t.id = l.transaction_id
               JOIN warehouses w ON w.id = t.warehouse_id
              WHERE t.inventory_effect = 1 AND t.transaction_type <> 'REVERSAL'{$whSql}
              GROUP BY t.id, t.transaction_type, {$td}, t.reference_no, t.status, w.name
              ORDER BY {$td} DESC, t.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute($bind);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'type' => $r['transaction_type'],
            'type_label' => self::TYPE_LABELS[$r['transaction_type']] ?? $r['transaction_type'],
            'date' => $r['transaction_date'],
            'reference_no' => $r['reference_no'] ?? ('#' . $r['id']),
            'warehouse' => $r['warehouse_name'],
            'item_count' => (int) $r['item_count'],
            'value' => round((float) $r['value'], 2),
            'status' => $r['status'],
        ], $stmt->fetchAll());
    }

    // ---------------------------------------------------------------- top lists

    private static function topLowStock(PDO $pdo, ?int $wh): array
    {
        $r = StockReportService::list($pdo, ['warehouse_id' => $wh, 'status' => 'CRITICAL', 'active_only' => true, 'include_zero_stock' => true, 'sort' => 'qty', 'dir' => 'ASC', 'page' => 1, 'per_page' => 5]);
        return array_map(static fn (array $x): array => [
            'sku' => $x['sku'], 'name' => $x['name'], 'unit' => $x['unit']['code'],
            'qty' => $x['qty_base'], 'minimum' => $x['minimum_stock'], 'gap' => round($x['qty_base'] - $x['minimum_stock'], 6),
        ], $r['rows']);
    }

    private static function topInventoryValue(PDO $pdo, ?int $wh): array
    {
        $r = StockReportService::list($pdo, ['warehouse_id' => $wh, 'active_only' => true, 'include_zero_stock' => false, 'sort' => 'value', 'dir' => 'DESC', 'page' => 1, 'per_page' => 5]);
        return array_map(static fn (array $x): array => [
            'sku' => $x['sku'], 'name' => $x['name'], 'unit' => $x['unit']['code'],
            'qty' => $x['qty_base'], 'hpp' => $x['average_cost'], 'value' => $x['value'],
        ], $r['rows']);
    }

    // =====================================================================
    // DETAIL (drill-down) — paginated, filtered, with grand totals
    // =====================================================================

    public const DETAIL_TYPES = [
        'opening_stock', 'closing_stock', 'stock_in', 'purchase_in', 'stock_out', 'current_stock',
        'pending_transfers', 'active_opname', 'rusak', 'deadstock', 'adjustment',
        'move_transfer_in', 'move_transfer_out', 'move_transfer_net', 'move_adjustment_positive', 'move_adjustment_negative', 'move_opening_in',
        'move_reversal', 'move_production_in', 'move_production_out', 'move_in_void', 'move_other',
    ];

    /**
     * @param array{q?:?string,category_id?:?int,warehouse_id?:?int,page?:int,per_page?:int} $filters
     * @return array<string,mixed>
     */
    public static function detail(PDO $pdo, string $type, ?int $wh, string $period, ?string $from, ?string $to, array $filters): array
    {
        if (!in_array($type, self::DETAIL_TYPES, true)) {
            throw new ValidationException(["type tidak dikenal: {$type}"]);
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 50);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 50;
        // inside a single-warehouse scope the extra warehouse filter is meaningless (and must never widen the scope)
        if ($wh !== null) {
            $filters['warehouse_id'] = null;
        }
        $p = self::resolvePeriod($period, $from, $to);
        $cutover = InventoryHppReportService::cutoverContext($pdo, $p['start_date'], $p['end_date']);
        $eff = $cutover['effective_start_date'];

        $base = ['type' => $type, 'period' => $p, 'effective_start_date' => $eff, 'page' => $page, 'per_page' => $perPage];

        switch ($type) {
            case 'opening_stock':
            case 'closing_stock':
            case 'current_stock':
                if ($cutover['is_pre_go_live_period'] && $type !== 'current_stock') {
                    return $base + self::emptyDetail('balance', $page, $perPage);
                }
                if ($type === 'opening_stock') {
                    [$before, $boundary] = [$eff, true];
                } elseif ($type === 'closing_stock') {
                    [$before, $boundary] = [date('Y-m-d', strtotime($p['end_date'] . ' +1 day')), false];
                } else {
                    return $base + self::currentStockDetail($pdo, $wh, $filters, $page, $perPage);
                }
                return $base + self::balanceDetail($pdo, $before, $boundary, $wh, $filters, $page, $perPage);

            case 'stock_in':
            case 'purchase_in':
            case 'stock_out':
            case 'adjustment':
            case 'move_reversal':
            case 'move_production_in':
            case 'move_production_out':
            case 'move_in_void':
            case 'move_transfer_in':
            case 'move_transfer_out':
            case 'move_transfer_net':
            case 'move_adjustment_positive':
            case 'move_adjustment_negative':
            case 'move_opening_in':
            case 'move_other':
                if ($cutover['is_pre_go_live_period']) {
                    return $base + self::emptyDetail('transactions', $page, $perPage);
                }
                return $base + self::transactionDetail($pdo, $type, $eff, $p['end_date'], $wh, $filters, $page, $perPage);

            case 'pending_transfers':
                return $base + self::pendingTransfersDetail($pdo, $wh, $page, $perPage);
            case 'active_opname':
                return $base + self::activeOpnameDetail($pdo, $wh, $page, $perPage);
            case 'rusak':
                return $base + self::conditionDetail($pdo, 'rusak', 'final_rusak_qty', $wh, $filters, $page, $perPage);
            case 'deadstock':
                return $base + self::conditionDetail($pdo, 'deadstock', 'final_deadstock_qty', $wh, $filters, $page, $perPage);
        }
        throw new ValidationException(["type tidak dikenal: {$type}"]);
    }

    private static function emptyDetail(string $kind, int $page, int $perPage): array
    {
        return ['kind' => $kind, 'rows' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1],
            'grand_total' => ['value' => 0.0, 'row_count' => 0, 'sku_count' => 0], 'card_total' => ['value' => 0.0]];
    }

    private static function pagination(int $total, int $page, int $perPage): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Stok Awal / Stok Akhir rows (item x warehouse). */
    private static function balanceDetail(PDO $pdo, string $before, bool $boundary, ?int $wh, array $filters, int $page, int $perPage): array
    {
        [$g, $bind] = self::balanceGrouped($pdo, $before, $boundary, $wh);
        [$fw, $fb] = self::itemFilterSql($filters, 'g.warehouse_id');
        $filtered = self::balanceTotals($pdo, $before, $boundary, $wh, $filters);
        $card = self::balanceTotals($pdo, $before, $boundary, $wh, []);

        $stmt = $pdo->prepare(
            "SELECT i.sku, i.name, c.name AS category, w.name AS warehouse_name, w.code AS warehouse_code, u.code AS unit, g.qty, g.value
               FROM ({$g}) g
               JOIN items i ON i.id = g.item_id
               JOIN warehouses w ON w.id = g.warehouse_id
               JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE {$fw}
              ORDER BY ABS(g.value) DESC, i.sku ASC, w.name ASC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind + $fb);
        return self::balanceResult($stmt->fetchAll(), $filtered, $card, $page, $perPage);
    }

    /** Nilai Stok KPI: current on-hand batches per item x warehouse. */
    private static function currentStockDetail(PDO $pdo, ?int $wh, array $filters, int $page, int $perPage): array
    {
        $bind = [];
        $inner = 'SELECT item_id, warehouse_id, SUM(qty_base) AS qty, SUM(qty_base * unit_cost_base) AS value FROM inventory_batches';
        if ($wh !== null) {
            $inner .= ' WHERE warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $g = $inner . ' GROUP BY item_id, warehouse_id HAVING ABS(SUM(qty_base)) > 0.0000005 OR ABS(SUM(qty_base * unit_cost_base)) > 0.00005';
        [$fw, $fb] = self::itemFilterSql($filters, 'g.warehouse_id');
        $tot = static function (string $where, array $b) use ($pdo, $g): array {
            $s = $pdo->prepare("SELECT COALESCE(SUM(g.value),0) AS v, COUNT(DISTINCT g.item_id) AS skus, COUNT(*) AS n FROM ({$g}) g JOIN items i ON i.id = g.item_id WHERE {$where}");
            $s->execute($b);
            $r = $s->fetch();
            return ['value' => round((float) $r['v'], 4), 'sku_count' => (int) $r['skus'], 'row_count' => (int) $r['n']];
        };
        $filtered = $tot($fw, $bind + $fb);
        $card = $tot('1=1', $bind);
        $stmt = $pdo->prepare(
            "SELECT i.sku, i.name, c.name AS category, w.name AS warehouse_name, w.code AS warehouse_code, u.code AS unit, g.qty, g.value
               FROM ({$g}) g JOIN items i ON i.id = g.item_id JOIN warehouses w ON w.id = g.warehouse_id JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE {$fw} ORDER BY ABS(g.value) DESC, i.sku ASC, w.name ASC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind + $fb);
        return self::balanceResult($stmt->fetchAll(), $filtered, $card, $page, $perPage);
    }

    private static function balanceResult(array $rows, array $filtered, array $card, int $page, int $perPage): array
    {
        return [
            'kind' => 'balance',
            'rows' => array_map(static function (array $r): array {
                $qty = round((float) $r['qty'], 6);
                $value = round((float) $r['value'], 4);
                return [
                    'sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'] ?? '(Tanpa Kategori)',
                    'warehouse' => $r['warehouse_name'], 'warehouse_code' => $r['warehouse_code'], 'unit' => $r['unit'],
                    'qty' => $qty, 'hpp' => abs($qty) > 0.0000005 ? round($value / $qty, 4) : null, 'value' => $value,
                ];
            }, $rows),
            'pagination' => self::pagination($filtered['row_count'], $page, $perPage),
            'grand_total' => ['value' => $filtered['value'], 'row_count' => $filtered['row_count'], 'sku_count' => $filtered['sku_count']],
            'card_total' => ['value' => $card['value'], 'row_count' => $card['row_count'], 'sku_count' => $card['sku_count']],
        ];
    }

    /** Pembelian / Stock OUT / other-movement transaction lines. */
    private static function transactionDetail(PDO $pdo, string $type, string $eff, string $end, ?int $wh, array $filters, int $page, int $perPage): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $signed = InventoryHppReportService::SIGNED_VALUE_SQL;
        $where = [
            "t.status IN ('POSTED','VOID')", 't.inventory_effect = 1',
            "{$td} >= :start", "{$td} < :end_excl",
            "NOT (t.transaction_type = 'OPENING' AND {$td} = :start_boundary)",
        ];
        $bind = ['start' => $eff . ' 00:00:00', 'end_excl' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00', 'start_boundary' => $eff . ' 00:00:00'];
        $typeSql = match ($type) {
            'stock_in', 'purchase_in' => "t.transaction_type = 'IN' AND t.status = 'POSTED'",
            'stock_out' => "t.transaction_type = 'OUT'",
            'adjustment' => "NOT (t.transaction_type = 'IN' AND t.status = 'POSTED') AND t.transaction_type NOT IN ('OUT','TRANSFER_IN','TRANSFER_OUT')",
            'move_reversal' => "t.transaction_type = 'REVERSAL'",
            'move_production_in' => "t.transaction_type = 'PRODUCTION_IN'",
            'move_production_out' => "t.transaction_type = 'PRODUCTION_OUT'",
            'move_in_void' => "t.transaction_type = 'IN' AND t.status = 'VOID'",
            'move_transfer_in' => "t.transaction_type = 'TRANSFER_IN'",
            'move_transfer_out' => "t.transaction_type = 'TRANSFER_OUT'",
            'move_transfer_net' => "t.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT')",
            'move_adjustment_positive' => "t.transaction_type = 'ADJUSTMENT' AND l.subtotal > 0",
            'move_adjustment_negative' => "t.transaction_type = 'ADJUSTMENT' AND l.subtotal < 0",
            'move_opening_in' => "t.transaction_type = 'OPENING'",
            default => "t.transaction_type NOT IN ('IN','OUT','TRANSFER_IN','TRANSFER_OUT','ADJUSTMENT','OPENING','REVERSAL','PRODUCTION_IN','PRODUCTION_OUT')",
        };
        $where[] = "({$typeSql})";
        if ($wh !== null) {
            $where[] = 'l.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        // everything above defines the CARD's set; the user's filters below only narrow the rows
        $cardWhere = implode(' AND ', $where);
        $cardBind = $bind;
        if ($wh === null && !empty($filters['warehouse_id'])) {
            $where[] = 'l.warehouse_id = :f_wh';
            $bind['f_wh'] = (int) $filters['warehouse_id'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(i.sku LIKE :f_q1 OR i.name LIKE :f_q2 OR t.reference_no LIKE :f_q3)';
            $bind['f_q1'] = $bind['f_q2'] = $bind['f_q3'] = '%' . $q . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = :f_cat';
            $bind['f_cat'] = (int) $filters['category_id'];
        }
        $whereSql = implode(' AND ', $where);
        $from = "FROM inventory_transaction_lines l
                 JOIN inventory_transactions t ON t.id = l.transaction_id
                 JOIN items i ON i.id = l.item_id
                 JOIN warehouses w ON w.id = l.warehouse_id
                 JOIN units iu ON iu.id = l.input_unit_id
                 JOIN units bu ON bu.id = i.base_unit_id
                 LEFT JOIN suppliers sup ON sup.id = t.supplier_id
                 LEFT JOIN bakery_destinations bd ON bd.id = t.bakery_destination_id
                 LEFT JOIN divisions dv ON dv.id = t.division_id";

        // value sign: purchase/out lists show magnitudes (the card does); other-movement lists show the signed ledger value
        $valueExpr = in_array($type, ['stock_in', 'purchase_in', 'stock_out'], true) ? 'ABS(' . $signed . ')' : $signed;

        $tot = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM({$valueExpr}), 0) AS v, COUNT(DISTINCT l.item_id) AS skus {$from} WHERE {$whereSql}");
        $tot->execute($bind);
        $t = $tot->fetch();

        // card total = the same set without the user's filters
        $card = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM({$valueExpr}), 0) AS v {$from} WHERE {$cardWhere}");
        $card->execute($cardBind);
        $c = $card->fetch();

        $stmt = $pdo->prepare(
            "SELECT t.id AS transaction_id, {$td} AS transaction_date, t.reference_no, t.transaction_type, t.status,
                    i.sku, i.name, sup.name AS supplier_name, w.name AS warehouse_name,
                    COALESCE(bd.name, dv.name, l.notes) AS destination,
                    l.input_qty, iu.code AS input_unit, l.base_qty, bu.code AS base_unit, l.unit_cost_base, l.unit_price_input,
                    {$valueExpr} AS value
             {$from} WHERE {$whereSql}
             ORDER BY {$td} DESC, t.id DESC, l.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind);

        return [
            'kind' => 'transactions',
            'rows' => array_map(static fn (array $r): array => [
                'transaction_id' => (int) $r['transaction_id'], 'date' => $r['transaction_date'], 'reference_no' => $r['reference_no'] ?? ('#' . $r['transaction_id']),
                'type' => $r['transaction_type'], 'type_label' => self::TYPE_LABELS[$r['transaction_type']] ?? $r['transaction_type'], 'status' => $r['status'],
                'sku' => $r['sku'], 'name' => $r['name'], 'supplier' => $r['supplier_name'], 'warehouse' => $r['warehouse_name'], 'destination' => $r['destination'],
                'qty' => round((float) $r['input_qty'], 6), 'unit' => $r['input_unit'], 'qty_base' => round((float) $r['base_qty'], 6), 'base_unit' => $r['base_unit'],
                'hpp' => round((float) $r['unit_cost_base'], 4), 'price' => round((float) $r['unit_price_input'], 4), 'value' => round((float) $r['value'], 4),
            ], $stmt->fetchAll()),
            'pagination' => self::pagination((int) $t['n'], $page, $perPage),
            'grand_total' => ['value' => round((float) $t['v'], 4), 'row_count' => (int) $t['n'], 'sku_count' => (int) $t['skus']],
            'card_total' => ['value' => round((float) $c['v'], 4), 'row_count' => (int) $c['n']],
        ];
    }

    private static function pendingTransfersDetail(PDO $pdo, ?int $wh, int $page, int $perPage): array
    {
        $where = "wt.status = 'PENDING'";
        $bind = [];
        if ($wh !== null) {
            $where .= ' AND (wt.from_warehouse_id = :a OR wt.to_warehouse_id = :b)';
            $bind = ['a' => $wh, 'b' => $wh];
        }
        $tot = $pdo->prepare("SELECT COUNT(*) FROM warehouse_transfers wt WHERE {$where}");
        $tot->execute($bind);
        $total = (int) $tot->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT wt.id, wt.ship_date, fw.name AS from_name, tw.name AS to_name, COUNT(wtl.id) AS items,
                    COALESCE(SUM(wtl.qty_base * wtl.unit_cost_base), 0) AS value
               FROM warehouse_transfers wt
               JOIN warehouses fw ON fw.id = wt.from_warehouse_id JOIN warehouses tw ON tw.id = wt.to_warehouse_id
               LEFT JOIN warehouse_transfer_lines wtl ON wtl.transfer_id = wt.id
              WHERE {$where}
              GROUP BY wt.id, wt.ship_date, fw.name, tw.name
              ORDER BY wt.ship_date DESC, wt.id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind);
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'reference_no' => 'TRF-' . $r['id'], 'date' => $r['ship_date'], 'from' => $r['from_name'], 'to' => $r['to_name'],
            'item_count' => (int) $r['items'], 'value' => round((float) $r['value'], 4),
        ], $stmt->fetchAll());
        $sum = $pdo->prepare("SELECT COALESCE(SUM(wtl.qty_base * wtl.unit_cost_base), 0) FROM warehouse_transfers wt LEFT JOIN warehouse_transfer_lines wtl ON wtl.transfer_id = wt.id WHERE {$where}");
        $sum->execute($bind);
        $v = round((float) $sum->fetchColumn(), 4);
        return ['kind' => 'pending_transfers', 'rows' => $rows, 'pagination' => self::pagination($total, $page, $perPage),
            'grand_total' => ['value' => $v, 'row_count' => $total, 'sku_count' => 0], 'card_total' => ['value' => $v, 'row_count' => $total]];
    }

    private static function activeOpnameDetail(PDO $pdo, ?int $wh, int $page, int $perPage): array
    {
        $where = "s.status IN ('OPEN','FINALIZED')";
        $bind = [];
        if ($wh !== null) {
            $where .= ' AND s.warehouse_id = :wh';
            $bind['wh'] = $wh;
        }
        $tot = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_sessions s WHERE {$where}");
        $tot->execute($bind);
        $total = (int) $tot->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT s.id, s.session_number, s.session_date, s.status, s.counting_model, w.name AS warehouse_name,
                    (SELECT COUNT(*) FROM stock_opname_lines sol WHERE sol.session_id = s.id) AS line_count
               FROM stock_opname_sessions s JOIN warehouses w ON w.id = s.warehouse_id
              WHERE {$where} ORDER BY s.session_date DESC, s.id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind);
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'reference_no' => $r['session_number'] ?? ('OPN-' . $r['id']), 'date' => $r['session_date'], 'warehouse' => $r['warehouse_name'],
            'status' => $r['status'], 'counting_model' => $r['counting_model'], 'item_count' => (int) $r['line_count'],
        ], $stmt->fetchAll());
        return ['kind' => 'active_opname', 'rows' => $rows, 'pagination' => self::pagination($total, $page, $perPage),
            'grand_total' => ['value' => null, 'row_count' => $total, 'sku_count' => 0], 'card_total' => ['value' => null, 'row_count' => $total]];
    }

    private static function conditionDetail(PDO $pdo, string $kind, string $col, ?int $wh, array $filters, int $page, int $perPage): array
    {
        [$sql, $bind] = self::conditionSql($col, $wh, $filters['q'] ?? null);
        [$cardSql, $cardBind] = self::conditionSql($col, $wh, null);
        $tot = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(x.value),0) AS v, COUNT(DISTINCT x.item_id) AS skus FROM ({$sql}) x");
        $tot->execute($bind);
        $t = $tot->fetch();
        $card = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(x.value),0) AS v FROM ({$cardSql}) x");
        $card->execute($cardBind);
        $c = $card->fetch();
        $stmt = $pdo->prepare("SELECT x.* FROM ({$sql}) x ORDER BY x.value DESC, x.sku ASC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage));
        $stmt->execute($bind);
        return [
            'kind' => $kind,
            'rows' => array_map(static fn (array $r): array => [
                'sku' => $r['sku'], 'name' => $r['name'], 'warehouse' => $r['warehouse_name'], 'session' => $r['session_number'] ?? ('OPN-' . $r['session_id']), 'session_id' => (int) $r['session_id'],
                'unit' => $r['unit'], 'qty' => round((float) $r['qty'], 6), 'hpp' => round((float) $r['hpp'], 4), 'value' => round((float) $r['value'], 2), 'note' => $r['note'],
            ], $stmt->fetchAll()),
            'pagination' => self::pagination((int) $t['n'], $page, $perPage),
            'grand_total' => ['value' => round((float) $t['v'], 2), 'row_count' => (int) $t['n'], 'sku_count' => (int) $t['skus']],
            'card_total' => ['value' => round((float) $c['v'], 2), 'row_count' => (int) $c['n']],
        ];
    }
}
