<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Laporan Pergerakan Stok" (reports v3) — READ-ONLY. A thin layer over the approved MovementDailyReportService (which owns the opening → movement → closing algebra and its
 * reconciliation) that adds exactly what the v3 report needs and the daily service deliberately hides:
 *
 *   - TRANSFER IN / TRANSFER OUT as their own columns. MovementDailyReportService folds a transfer into Masuk / Keluar when ONE warehouse is selected and nets it into
 *     "Adjustment / Lain" at company scope. v3 shows them separately at BOTH scopes, so the identity of every day is
 *         Stok Awal + Barang Masuk (IN) − Barang Keluar (OUT) + Transfer IN − Transfer OUT + Adjustment = Stok Akhir
 *     (Adjustment = the daily service's "lain" minus the company-scope transfer elimination, which is exactly Transfer IN − Transfer OUT). Company-wide, Transfer IN − Transfer OUT
 *     is the value still in transit (0 once every transfer is received); per warehouse each side appears on its own.
 *   - a per-item period table and an Excel workbook (Ringkasan, Harian, Per Barang, Detail Harian per Barang, Per Satuan).
 * It never writes and never re-derives the opening / closing: those come from MovementDailyReportService::overview() (the same ledger, FIFO values, go-live clamp and VOID rules);
 * the transfer figures use the very same filters (status POSTED/VOID, inventory_effect = 1, warehouse on the line, item filters on items). Quantities of different units are never added.
 */
final class MovementReportV3Service
{
    private const EPS = 0.01;
    private const EPS_Q = 0.0005;

    /** @return array{join:string,where:list<string>,bind:array<string,mixed>} */
    private static function scope(?int $wh, ?int $cat, ?string $q, ?int $item): array
    {
        $where = [];
        $bind = [];
        if ($wh !== null) { $where[] = 'l.warehouse_id = :s_wh'; $bind['s_wh'] = $wh; }
        if ($cat !== null) { $where[] = 'i.category_id = :s_cat'; $bind['s_cat'] = $cat; }
        if ($item !== null) { $where[] = 'i.id = :s_item'; $bind['s_item'] = $item; }
        if ($q !== null && trim($q) !== '') {
            $where[] = '(i.sku LIKE :s_q1 OR i.name LIKE :s_q2)';
            $like = '%' . trim($q) . '%';
            $bind['s_q1'] = $like;
            $bind['s_q2'] = $like;
        }
        return ['join' => 'JOIN items i ON i.id = l.item_id', 'where' => $where, 'bind' => $bind];
    }

    private static function nextDay(string $d): string
    {
        return date('Y-m-d', strtotime($d . ' +1 day'));
    }

    // ======================================================================
    // overview
    // ======================================================================

    /** @return array<string,mixed> the daily overview + the split columns, totals and per-unit breakdown */
    public static function overview(PDO $pdo, string $start, string $end, ?int $wh, ?int $cat = null, ?string $q = null, ?int $item = null): array
    {
        $ov = MovementDailyReportService::overview($pdo, $start, $end, $wh, $cat, $q, $item);
        $live = !$ov['cutover']['is_pre_go_live_period'];
        $eff = (string) $ov['cutover']['effective_start_date'];
        $gross = $live ? self::transferGross($pdo, $eff, $end, $wh, $cat, $q, $item) : [];
        $tot = ['opening' => (float) $ov['totals']['opening'], 'in' => 0.0, 'out' => 0.0, 'tin' => 0.0, 'tout' => 0.0, 'adjustment' => 0.0, 'closing' => (float) $ov['totals']['closing']];
        $issues = [];
        foreach ($ov['rows'] as &$r) {
            if ($r['is_pre_go_live']) {
                $r['split'] = null;
                continue;
            }
            $b = $r['buckets'];
            $n = $r['nominal'];
            $g = $gross[$r['date']] ?? ['tin' => 0.0, 'tout' => 0.0];
            $elim = $wh === null ? (float) $b['transfer_elimination'] : 0.0;
            $split = ['opening' => (float) $n['opening'], 'in' => round((float) $b['masuk_purchase'], 4), 'out' => round((float) $b['keluar_usage'], 4), 'tin' => round($g['tin'], 4), 'tout' => round($g['tout'], 4),
                'adjustment' => round((float) $n['lain'] - $elim, 4), 'closing' => (float) $n['closing']];
            $split['difference'] = round($split['opening'] + $split['in'] - $split['out'] + $split['tin'] - $split['tout'] + $split['adjustment'] - $split['closing'], 4);
            if (abs($split['difference']) > self::EPS) {
                $issues[] = ['date' => $r['date'], 'type' => 'SPLIT', 'difference' => $split['difference'], 'message' => 'Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment ≠ Stok Akhir'];
            }
            if ($wh !== null && abs($split['tin'] - (float) $b['masuk_transfer']) > self::EPS) {
                $issues[] = ['date' => $r['date'], 'type' => 'TRANSFER_IN', 'difference' => round($split['tin'] - (float) $b['masuk_transfer'], 4), 'message' => 'Transfer IN tidak sama dengan bucket Masuk-Transfer'];
            }
            if ($wh === null && abs(($split['tin'] - $split['tout']) - $elim) > self::EPS) {
                $issues[] = ['date' => $r['date'], 'type' => 'TRANSFER_NET', 'difference' => round(($split['tin'] - $split['tout']) - $elim, 4), 'message' => 'Transfer IN − OUT tidak sama dengan eliminasi transfer company-wide'];
            }
            $r['split'] = $split;
            foreach (['in', 'out', 'tin', 'tout', 'adjustment'] as $k) {
                $tot[$k] += $split[$k];
            }
        }
        unset($r);
        foreach ($tot as $k => $v) {
            $tot[$k] = round($v, 4);
        }
        $tot['transfer_net'] = round($tot['tin'] - $tot['tout'], 4);
        $tot['difference'] = round($tot['opening'] + $tot['in'] - $tot['out'] + $tot['tin'] - $tot['tout'] + $tot['adjustment'] - $tot['closing'], 4);
        if ($live && abs($tot['difference']) > self::EPS * max(1, count($ov['rows']))) {
            $issues[] = ['date' => null, 'type' => 'PERIOD_SPLIT', 'difference' => $tot['difference'], 'message' => 'Total periode: Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment ≠ Stok Akhir'];
        }
        $ov['split_totals'] = $tot;
        $ov['qty_units'] = $live ? self::qtyByUnit($pdo, $eff, $end, $wh, $cat, $q, $item, $ov['totals']['qty_by_unit']) : [];
        $ov['reconciliation']['split_ok'] = $issues === [];
        $ov['reconciliation']['split_issues'] = $issues;
        $ov['reconciliation']['ok'] = $ov['reconciliation']['ok'] && $issues === [];
        $ov['reconciliation']['issues'] = array_merge($ov['reconciliation']['issues'], $issues);
        return $ov;
    }

    /** @return array<string,array{tin:float,tout:float}> gross TRANSFER_IN / TRANSFER_OUT value per date (same filters as the daily service) */
    private static function transferGross(PDO $pdo, string $start, string $end, ?int $wh, ?int $cat, ?string $q, ?int $item): array
    {
        $sc = self::scope($wh, $cat, $q, $item);
        $where = array_merge(["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "t.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT')", 't.transaction_date >= :g_start', 't.transaction_date < :g_end'], $sc['where']);
        $bind = array_merge(['g_start' => $start . ' 00:00:00', 'g_end' => self::nextDay($end) . ' 00:00:00'], $sc['bind']);
        $st = $pdo->prepare(
            "SELECT DATE(t.transaction_date) AS d, SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN l.subtotal ELSE 0 END) AS v_tin, SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.subtotal) ELSE 0 END) AS v_tout
               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']} WHERE " . implode(' AND ', $where) . ' GROUP BY DATE(t.transaction_date)'
        );
        $st->execute($bind);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['d']] = ['tin' => (float) $r['v_tin'], 'tout' => (float) $r['v_tout']];
        }
        return $out;
    }

    /**
     * Period quantities BY UNIT (never one mixed number): opening / closing from the daily service, IN / OUT / Transfer IN / Transfer OUT from the ledger,
     * Adjustment = closing − opening − IN + OUT − Transfer IN + Transfer OUT (the residual: adjustments, voids / reversals, production, opening balances).
     * @return list<array<string,mixed>>
     */
    private static function qtyByUnit(PDO $pdo, string $start, string $end, ?int $wh, ?int $cat, ?string $q, ?int $item, array $svc): array
    {
        $sc = self::scope($wh, $cat, $q, $item);
        $where = array_merge(["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', 't.transaction_date >= :u_start', 't.transaction_date < :u_end',
            "NOT (t.transaction_type = 'OPENING' AND t.transaction_date = :u_boundary)"], $sc['where']);
        $bind = array_merge(['u_start' => $start . ' 00:00:00', 'u_end' => self::nextDay($end) . ' 00:00:00', 'u_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $st = $pdo->prepare(
            "SELECT u.code AS unit,
                    SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN ABS(l.base_qty) ELSE 0 END) AS q_in,
                    SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_out,
                    SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN ABS(l.base_qty) ELSE 0 END) AS q_tin,
                    SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_tout
               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']} JOIN units u ON u.id = i.base_unit_id
              WHERE " . implode(' AND ', $where) . ' GROUP BY u.code'
        );
        $st->execute($bind);
        $by = [];
        foreach ($st->fetchAll() as $r) {
            $by[$r['unit']] = ['in' => (float) $r['q_in'], 'out' => (float) $r['q_out'], 'tin' => (float) $r['q_tin'], 'tout' => (float) $r['q_tout']];
        }
        $pick = static function (array $list): array {
            $m = [];
            foreach ($list as $e) {
                $m[$e['unit']] = (float) $e['qty'];
            }
            return $m;
        };
        $open = $pick($svc['opening'] ?? []);
        $close = $pick($svc['closing'] ?? []);
        $units = array_unique(array_merge(array_keys($by), array_keys($open), array_keys($close)));
        sort($units);
        $out = [];
        foreach ($units as $u) {
            $b = $by[$u] ?? ['in' => 0.0, 'out' => 0.0, 'tin' => 0.0, 'tout' => 0.0];
            $o = $open[$u] ?? 0.0;
            $c = $close[$u] ?? 0.0;
            $out[] = ['unit' => $u, 'opening' => round($o, 6), 'in' => round($b['in'], 6), 'out' => round($b['out'], 6), 'tin' => round($b['tin'], 6), 'tout' => round($b['tout'], 6),
                'adjustment' => round($c - $o - $b['in'] + $b['out'] - $b['tin'] + $b['tout'], 6), 'closing' => round($c, 6)];
        }
        return $out;
    }

    // ======================================================================
    // per item over the whole period
    // ======================================================================

    /**
     * One row per item with stock or movement in the period: opening, IN, OUT, Transfer IN / OUT, Adjustment, closing — qty (the item's own unit) AND value.
     * @return list<array<string,mixed>>
     */
    public static function perItem(PDO $pdo, string $start, string $end, ?int $wh, ?int $cat, ?string $q, ?int $item): array
    {
        $cut = InventoryHppReportService::cutoverContext($pdo, $start, $end);
        if ($cut['is_pre_go_live_period']) {
            return [];
        }
        $eff = (string) $cut['effective_start_date'];
        $sc = self::scope($wh, $cat, $q, $item);
        $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
        $sq = MovementDailyReportService::SIGNED_QTY_SQL;
        // opening
        $w0 = array_merge(["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "(t.transaction_date < :o_before OR (t.transaction_type = 'OPENING' AND t.transaction_date = :o_boundary))"], $sc['where']);
        $b0 = array_merge(['o_before' => $eff . ' 00:00:00', 'o_boundary' => $eff . ' 00:00:00'], $sc['bind']);
        $st = $pdo->prepare("SELECT l.item_id, SUM({$sq}) AS q, SUM({$sv}) AS v FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']} WHERE " . implode(' AND ', $w0) . ' GROUP BY l.item_id');
        $st->execute($b0);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[(int) $r['item_id']] = ['oq' => (float) $r['q'], 'ov' => (float) $r['v']];
        }
        // movement
        $w1 = array_merge(["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', 't.transaction_date >= :m_start', 't.transaction_date < :m_end', "NOT (t.transaction_type = 'OPENING' AND t.transaction_date = :m_boundary)"], $sc['where']);
        $b1 = array_merge(['m_start' => $eff . ' 00:00:00', 'm_end' => self::nextDay($end) . ' 00:00:00', 'm_boundary' => $eff . ' 00:00:00'], $sc['bind']);
        $st = $pdo->prepare(
            "SELECT l.item_id,
                SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN l.subtotal ELSE 0 END) AS v_in, SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN ABS(l.base_qty) ELSE 0 END) AS q_in,
                SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.subtotal) ELSE 0 END) AS v_out, SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_out,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN l.subtotal ELSE 0 END) AS v_tin, SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN ABS(l.base_qty) ELSE 0 END) AS q_tin,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.subtotal) ELSE 0 END) AS v_tout, SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_tout,
                SUM({$sv}) AS v_net, SUM({$sq}) AS q_net, COUNT(DISTINCT t.id) AS tx
               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']} WHERE " . implode(' AND ', $w1) . ' GROUP BY l.item_id'
        );
        $st->execute($b1);
        $mv = [];
        foreach ($st->fetchAll() as $r) {
            $mv[(int) $r['item_id']] = $r;
            $rows[(int) $r['item_id']] ??= ['oq' => 0.0, 'ov' => 0.0];
        }
        if (!$rows) {
            return [];
        }
        $meta = [];
        foreach (array_chunk(array_keys($rows), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($pdo->query("SELECT i.id, i.sku, i.name, c.name AS category, u.code AS unit FROM items i JOIN units u ON u.id = i.base_unit_id LEFT JOIN categories c ON c.id = i.category_id WHERE i.id IN ({$in})")->fetchAll() as $m) {
                $meta[(int) $m['id']] = $m;
            }
        }
        $out = [];
        foreach ($rows as $id => $o) {
            $m = $mv[$id] ?? null;
            $x = static fn (string $k): float => $m !== null ? (float) $m[$k] : 0.0;
            $closeQ = $o['oq'] + $x('q_net');
            $closeV = $o['ov'] + $x('v_net');
            if (abs($o['oq']) <= self::EPS_Q && abs($o['ov']) <= self::EPS && $m === null) {
                continue;
            }
            if (abs($o['oq']) <= self::EPS_Q && abs($closeQ) <= self::EPS_Q && abs($o['ov']) <= self::EPS && abs($closeV) <= self::EPS && $m === null) {
                continue;
            }
            $mt = $meta[$id] ?? ['sku' => (string) $id, 'name' => '?', 'category' => null, 'unit' => ''];
            $out[] = [
                'item_id' => $id, 'sku' => $mt['sku'], 'name' => $mt['name'], 'category' => $mt['category'] ?? '(Tanpa Kategori)', 'unit' => $mt['unit'],
                'opening_qty' => round($o['oq'], 6), 'opening_value' => round($o['ov'], 4),
                'in_qty' => round($x('q_in'), 6), 'in_value' => round($x('v_in'), 4), 'out_qty' => round($x('q_out'), 6), 'out_value' => round($x('v_out'), 4),
                'tin_qty' => round($x('q_tin'), 6), 'tin_value' => round($x('v_tin'), 4), 'tout_qty' => round($x('q_tout'), 6), 'tout_value' => round($x('v_tout'), 4),
                'adjustment_qty' => round($closeQ - $o['oq'] - $x('q_in') + $x('q_out') - $x('q_tin') + $x('q_tout'), 6),
                'adjustment_value' => round($closeV - $o['ov'] - $x('v_in') + $x('v_out') - $x('v_tin') + $x('v_tout'), 4),
                'closing_qty' => round($closeQ, 6), 'closing_value' => round($closeV, 4), 'tx_count' => (int) ($m['tx'] ?? 0),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['sku'], $b['sku']));
        return $out;
    }

    // ======================================================================
    // workbook
    // ======================================================================

    /** @return array<string,array<string,mixed>> */
    public static function workbook(PDO $pdo, string $start, string $end, ?int $wh, ?int $cat, ?string $q, ?int $item, array $meta): array
    {
        $ov = self::overview($pdo, $start, $end, $wh, $cat, $q, $item);
        $t = $ov['split_totals'];
        $sum = [];
        foreach ($meta as $k => $v) {
            $sum[] = [$k, (string) $v];
        }
        $sum[] = ['', ''];
        foreach ([['Stok Awal (nilai)', $t['opening']], ['Barang Masuk — IN / pembelian', $t['in']], ['Barang Keluar — OUT', $t['out']], ['Transfer IN', $t['tin']], ['Transfer OUT', $t['tout']],
            ['Transfer bersih (IN − OUT; company-wide = dalam perjalanan)', $t['transfer_net']], ['Adjustment / Lain', $t['adjustment']], ['Stok Akhir (nilai)', $t['closing']],
            ['Selisih rekonsiliasi (harus 0)', $t['difference']], ['Transaksi masuk', $ov['totals']['tx_in']], ['Transaksi keluar', $ov['totals']['tx_out']], ['Transaksi lain', $ov['totals']['tx_other']],
            ['SKU memiliki stok (awal)', $ov['totals']['sku_opening']], ['SKU memiliki stok (akhir)', $ov['totals']['sku_closing']]] as $r) {
            $sum[] = [$r[0], $r[1]];
        }
        $sum[] = ['Rekonsiliasi', $ov['reconciliation']['ok'] ? 'SEIMBANG' : 'ADA SELISIH — lihat sheet Harian'];
        $sum[] = ['Rumus', 'Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment = Stok Akhir'];
        $daily = [];
        foreach ($ov['rows'] as $r) {
            if ($r['is_pre_go_live']) {
                continue;
            }
            $s = $r['split'];
            $daily[] = [$r['date'], $s['opening'], $s['in'], $s['out'], $s['tin'], $s['tout'], $s['adjustment'], $s['closing'], $s['difference'], $r['counts']['tx_total'], $r['counts']['sku_moved']];
        }
        $daily[] = ['TOTAL PERIODE', $t['opening'], $t['in'], $t['out'], $t['tin'], $t['tout'], $t['adjustment'], $t['closing'], $t['difference'], $ov['totals']['tx_total'], ''];
        $sheets = [
            'Ringkasan' => ['headers' => ['Keterangan', 'Nilai'], 'rows' => $sum],
            'Harian' => ['headers' => ['Tanggal', 'Stok Awal', 'Barang Masuk (IN)', 'Barang Keluar (OUT)', 'Transfer IN', 'Transfer OUT', 'Adjustment / Lain', 'Stok Akhir', 'Selisih Rekonsiliasi', 'Jumlah Transaksi', 'SKU Bergerak'],
                'types' => ['date', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'int', 'int'], 'rows' => $daily],
        ];
        $items = self::perItem($pdo, $start, $end, $wh, $cat, $q, $item);
        $ir = [];
        $sumv = ['opening_value' => 0.0, 'in_value' => 0.0, 'out_value' => 0.0, 'tin_value' => 0.0, 'tout_value' => 0.0, 'adjustment_value' => 0.0, 'closing_value' => 0.0];
        foreach ($items as $i) {
            $ir[] = [ExcelWriterService::sanitizeCellText($i['sku']), ExcelWriterService::sanitizeCellText($i['name']), $i['category'], $i['unit'], $i['opening_qty'], $i['opening_value'], $i['in_qty'], $i['in_value'],
                $i['out_qty'], $i['out_value'], $i['tin_qty'], $i['tin_value'], $i['tout_qty'], $i['tout_value'], $i['adjustment_qty'], $i['adjustment_value'], $i['closing_qty'], $i['closing_value'], $i['tx_count']];
            foreach ($sumv as $k => $_) {
                $sumv[$k] += $i[$k];
            }
        }
        $ir[] = ['TOTAL (nilai; qty tidak dijumlahkan lintas satuan)', '', '', '', '', round($sumv['opening_value'], 4), '', round($sumv['in_value'], 4), '', round($sumv['out_value'], 4), '', round($sumv['tin_value'], 4), '', round($sumv['tout_value'], 4), '',
            round($sumv['adjustment_value'], 4), '', round($sumv['closing_value'], 4), ''];
        $sheets['Per Barang'] = ['headers' => ['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty Awal', 'Nilai Awal', 'Qty Masuk (IN)', 'Nilai Masuk (IN)', 'Qty Keluar (OUT)', 'HPP Keluar (OUT)', 'Qty Transfer IN', 'Nilai Transfer IN',
            'Qty Transfer OUT', 'Nilai Transfer OUT', 'Qty Adjustment', 'Nilai Adjustment', 'Qty Akhir', 'Nilai Akhir', 'Jumlah Transaksi'],
            'types' => ['text', 'text', 'text', 'text', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'int'], 'rows' => $ir];
        $dd = [];
        foreach (MovementDailyReportService::detailRows($pdo, $start, $end, $wh, $cat, $q, $item) as $r) {
            $dd[] = [$r['date'], ExcelWriterService::sanitizeCellText($r['sku']), ExcelWriterService::sanitizeCellText($r['name']), $r['category'] ?? '(Tanpa Kategori)', $r['unit'], $r['opening_qty'], $r['opening_value'], $r['masuk_qty'], $r['masuk_value'],
                $r['keluar_qty'], $r['hpp_keluar_value'], $r['adjustment_qty'], $r['adjustment_value'], $r['closing_qty'], $r['closing_value'], $r['tx_count']];
        }
        $sheets['Detail Harian per Barang'] = ['headers' => ['Tanggal', 'SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty Awal', 'Nilai Awal', 'Qty Masuk', 'Nilai Masuk', 'Qty Keluar', 'HPP Keluar', 'Qty Adjustment / Lain', 'Nilai Adjustment / Lain', 'Qty Akhir', 'Nilai Akhir', 'Jumlah Transaksi'],
            'types' => ['date', 'text', 'text', 'text', 'text', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'qty', 'money', 'int'], 'rows' => $dd];
        $ur = [];
        foreach ($ov['qty_units'] as $u) {
            $ur[] = [$u['unit'], $u['opening'], $u['in'], $u['out'], $u['tin'], $u['tout'], $u['adjustment'], $u['closing']];
        }
        $sheets['Per Satuan (Qty)'] = ['headers' => ['Satuan', 'Qty Awal', 'Qty Masuk (IN)', 'Qty Keluar (OUT)', 'Qty Transfer IN', 'Qty Transfer OUT', 'Qty Adjustment / Lain', 'Qty Akhir'], 'types' => ['text', 'qty', 'qty', 'qty', 'qty', 'qty', 'qty', 'qty'], 'rows' => $ur];
        foreach ($sheets as $n => $s) {
            $sheets[$n] += ['freeze_header' => true, 'autofilter' => $n !== 'Ringkasan'];
        }
        return $sheets;
    }
}
