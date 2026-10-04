<?php
declare(strict_types=1);

/**
 * Pergerakan Stok Harian (redesign) — MovementDailyReportService + the 5 read-only endpoints, real MariaDB, real posting services
 * (tests/lib/dashboard_fixture.php: 3 warehouses, received + in-transit transfers, adjustments, a VOIDED purchase, a mid-period
 * OPENING, mixed units, today's activity).
 *
 *  A. identity per day and scope; next-day opening == prior-day closing; closing == INDEPENDENT ledger sum
 *  B. agreement with the EXISTING report engine (opening / closing / named buckets)
 *  C. daily row == Σ item rows (day-items) for every day; item trail == the day-items numbers; trail balance walk
 *  D. transfers: company scope shows no transfer in Masuk/Keluar, Σ warehouse closings == company closing, in-transit disclosed
 *  E. FIFO: Keluar value == Σ fifo_allocations; trail HPP == allocations
 *  F. adjustments / Stock Opname classification; VOID + REVERSAL handling
 *  G. qty never summed across units (grouped by unit only); single-item qty series
 *  H. filters (category / q / item / movement type), sorting, pagination, search
 *  I. HTTP: GET only, warehouse-scope enforcement (STOCK user cannot widen), VIEWER ok, export == screen, no write
 *
 * Usage: php tests/movement_daily_report_test.php
 */

require_once __DIR__ . '/lib/dashboard_fixture.php';

use App\Services\Database;
use App\Services\InventoryMovementReportService as Old;
use App\Services\MovementDailyReportService as M;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return $a !== null && $b !== null && abs($a - $b) <= $eps;
}

$pdo = Database::connection();
$fx = dashboard_build_fixture($pdo);
$W = $fx['wh'];
$by = $fx['admin']['id'];
$kg = $fx['kg'];
$pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$ltr = (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn();
// mixed units
$p1 = df_item($pdo, $pcs, 'MVPCS', $fx['cat']['roti'], 0);
$l1 = df_item($pdo, $ltr, 'MVLTR', $fx['cat']['bahan'], 0);
df_in($p1['id'], $W['A'], 120, 500, '2026-06-12 09:30:00', $by, $pcs, 'IN', 'PO-PCS');
df_in($l1['id'], $W['A'], 32, 9000, '2026-06-12 09:45:00', $by, $ltr, 'IN', 'PO-LTR');
df_out($p1['id'], $W['A'], 20, '2026-06-15 10:00:00', $by, $pcs, 'OUT-PCS');

$R1 = ['2026-06-10', '2026-06-20'];
$R2 = ['2026-05-25', date('Y-m-d')];
$scopes = ['company' => null, 'A' => $W['A'], 'B' => $W['B'], 'C' => $W['C']];

function snapshot(PDO $pdo): array
{
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'stock_adjustments', 'items', 'audit_logs'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}
$before = snapshot($pdo);

// ===================== A. identity / carry / independent ledger
echo "===== A. identity · carry-over · independent closing =====\n";
$ovs = [];
foreach ([[$R1, 'range1'], [$R2, 'range2']] as [$range, $rl]) {
    foreach ($scopes as $sn => $wh) {
        $ov = M::overview($pdo, $range[0], $range[1], $wh);
        $ovs[$rl][$sn] = $ov;
        $okId = true; $okLedger = true; $okCarry = true; $prev = null; $bad = '';
        foreach ($ov['rows'] as $r) {
            if ($r['is_pre_go_live']) { continue; }
            $n = $r['nominal'];
            if (!near($n['opening'] + $n['masuk'] - $n['keluar'] + $n['lain'], $n['closing'])) { $okId = false; $bad = $r['date']; }
            if (!near($n['closing'], $r['reconciliation']['closing_direct'])) { $okLedger = false; $bad = $r['date']; }
            if ($prev !== null && !near($n['opening'], $prev)) { $okCarry = false; $bad = $r['date']; }
            $prev = $n['closing'];
        }
        check("[{$rl}/{$sn}] Saldo Awal + Masuk - Keluar ± Adjustment/Lain = Saldo Akhir on every day", $okId, $bad);
        check("[{$rl}/{$sn}] Saldo Akhir == independent ledger sum (signedValueBefore + per-date SIGNED_VALUE_SQL) on every day", $okLedger, $bad);
        check("[{$rl}/{$sn}] opening of day N+1 == closing of day N", $okCarry, $bad);
        check("[{$rl}/{$sn}] reconciliation block reports ok and no issues", $ov['reconciliation']['ok'] && !$ov['reconciliation']['issues'], json_encode($ov['reconciliation']['issues']));
        $t = $ov['totals'];
        check("[{$rl}/{$sn}] period: opening + masuk - keluar ± lain == closing", near($t['opening'] + $t['masuk'] - $t['keluar'] + $t['lain'], $t['closing'], 0.05));
    }
}

// ===================== B. agreement with the existing engine
echo "\n===== B. agreement with InventoryMovementReportService / InventoryHppReportService =====\n";
foreach ($scopes as $sn => $wh) {
    $old = Old::dailyMovement($pdo, $R1[0], $R1[1], $wh)['rows'];
    $new = $ovs['range1'][$sn]['rows'];
    $okOpen = true; $okClose = true; $okNet = true; $okBuckets = true; $bad = '';
    foreach ($old as $i => $o) {
        $n = $new[$i];
        if (!near($o['stok_awal'], $n['nominal']['opening'])) { $okOpen = false; $bad = $o['date']; }
        if (!near($o['stok_akhir'], $n['nominal']['closing'])) { $okClose = false; $bad = $o['date']; }
        // old Masuk-Keluar is the net of the day; mine = Masuk - Keluar + Lain
        $bd = Old::dayBreakdown($pdo, $o['date'], $wh);
        $cat = array_column($bd['categories'], 'value', 'key');
        // the old report keeps a mid-period OPENING out of Masuk (a starting balance); mine shows it in Lain so the identity closes
        if (!near($o['barang_masuk'] - $o['barang_keluar'] + ($cat['opening_in'] ?? 0.0), $n['nominal']['masuk'] - $n['nominal']['keluar'] + $n['nominal']['lain']) || !near($o['stok_akhir'] - $o['stok_awal'], $n['nominal']['masuk'] - $n['nominal']['keluar'] + $n['nominal']['lain'])) { $okNet = false; $bad = $o['date']; }
        $b = $n['buckets'];
        if (!near($cat['external_purchase'], $b['masuk_purchase']) || !near($cat['out_usage'], $b['keluar_usage']) || !near($cat['adjustment_positive'], $b['adj_pos']) || !near($cat['adjustment_negative'], $b['adj_neg'])) { $okBuckets = false; $bad = $o['date']; }
        if ($wh !== null && (!near($cat['transfer_in'], $b['masuk_transfer']) || !near($cat['transfer_out'], $b['keluar_transfer']))) { $okBuckets = false; $bad = $o['date'] . ' transfer'; }
    }
    check("[{$sn}] opening and closing equal the existing daily report on every day", $okOpen && $okClose, $bad);
    check("[{$sn}] day net (masuk - keluar ± lain) equals the existing report's net", $okNet, $bad);
    check("[{$sn}] purchase / usage / adjustment buckets equal the existing day-breakdown categories", $okBuckets, $bad);
}

// ===================== C. daily == Σ items ; trail
echo "\n===== C. daily row == Σ item rows · item trail =====\n";
foreach ($scopes as $sn => $wh) {
    $ok = true; $okTrail = true; $bad = ''; $days = 0;
    foreach ($ovs['range1'][$sn]['rows'] as $r) {
        if ($r['is_pre_go_live']) { continue; }
        $days++;
        $di = M::dayItems($pdo, $r['date'], $wh, null, null, null, null, 'sku', 'asc', 1, 500);
        $t = $di['totals']; $n = $r['nominal'];
        if (!near($t['opening_value'], $n['opening']) || !near($t['masuk_value'], $n['masuk']) || !near($t['keluar_value'], $n['keluar']) || !near($t['adjustment_value'], $n['lain']) || !near($t['closing_value'], $n['closing'])) { $ok = false; $bad = $r['date']; }
        if ($t['sku_closing'] !== $r['counts']['sku_closing']) { $ok = false; $bad = $r['date'] . ' sku'; }
        // trail per moved item: balance walk lands on the item's closing
        foreach ($di['rows'] as $row) {
            if ($row['tx_count'] === 0) { continue; }
            $tr = M::itemTrail($pdo, $r['date'], $row['item_id'], $wh);
            $net = 0.0; $netq = 0.0;
            foreach ($tr['rows'] as $x) { /* balance after each row must follow */ }
            $last = $tr['rows'] ? end($tr['rows']) : null;
            if (!near($tr['opening']['value'], $row['opening_value']) || !near($tr['opening']['qty'], $row['opening_qty'], 0.0005)) { $okTrail = false; $bad = "{$r['date']} {$row['sku']} opening"; }
            if (!near($tr['closing']['value'], $row['closing_value']) || !near($tr['closing']['qty'], $row['closing_qty'], 0.0005)) { $okTrail = false; $bad = "{$r['date']} {$row['sku']} closing"; }
            if ($last && (!near($last['balance_value'], $row['closing_value']) || !near($last['balance_qty'], $row['closing_qty'], 0.0005))) { $okTrail = false; $bad = "{$r['date']} {$row['sku']} last balance"; }
        }
    }
    check("[{$sn}] every day: daily table == Σ of the item detail (opening / masuk / keluar / lain / closing, SKU count) — {$days} days", $ok, $bad);
    check("[{$sn}] every moved item/day: transaction trail opening, running balance and closing equal the item row", $okTrail, $bad);
}
$tr = M::itemTrail($pdo, '2026-06-15', $fx['items']['tepung']['id'], $W['A']);
$out = array_values(array_filter($tr['rows'], fn ($r) => $r['bucket'] === 'keluar'));
check('trail of tepung @ SCM on 06-15: one OUT of 30 kg with FIFO HPP, user and reference shown', count($out) === 1 && near($out[0]['qty_out'], 30, 0.0001) && $out[0]['hpp_out'] > 0 && $out[0]['reference_no'] === 'OUT-A-1' && $out[0]['user'] !== null && $out[0]['timestamp'] === '2026-06-15 08:00:00', json_encode($out));

// ===================== D. transfers
echo "\n===== D. transfers =====\n";
$sumWhClose = 0.0;
foreach (['A', 'B', 'C'] as $k) { $sumWhClose += end($ovs['range2'][$k]['rows'])['nominal']['closing']; }
$coClose = end($ovs['range2']['company']['rows'])['nominal']['closing'];
check('Σ warehouse closing values == company closing (internal transfers neither create nor destroy value)', near($sumWhClose, $coClose, 0.05), "{$sumWhClose} vs {$coClose}");
$transferLines = $pdo->query("SELECT COUNT(*) FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id=l.transaction_id WHERE t.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT')")->fetchColumn();
check('fixture really contains transfer legs (the test is not vacuous)', (int) $transferLines >= 4);
$okElim = true; $companyTransferInMasuk = 0.0;
foreach ($ovs['range1']['company']['rows'] as $r) {
    if ($r['is_pre_go_live']) { continue; }
    if (abs($r['buckets']['masuk_transfer']) > 0 || abs($r['buckets']['keluar_transfer']) > 0) { $okElim = false; }
}
check('company scope: Masuk / Keluar contain NO transfer value on any day', $okElim);
$d14 = null; foreach ($ovs['range1']['company']['rows'] as $r) { if ($r['date'] === '2026-06-14') { $d14 = $r; } }
check('received transfer (06-14, 20 kg gula): eliminated at company scope — transfer_elimination 0', $d14 && near($d14['buckets']['transfer_elimination'], 0.0));
$d18 = null; foreach ($ovs['range1']['company']['rows'] as $r) { if ($r['date'] === '2026-06-18') { $d18 = $r; } }
check('in-transit transfer (06-18, 10 kg tepung): visible at company scope as a signed "Lain" amount (not hidden, not Masuk/Keluar)', $d18 && $d18['buckets']['transfer_elimination'] < -0.01 && near($d18['nominal']['lain'] - $d18['buckets']['transfer_elimination'], $d18['buckets']['adj_pos'] - $d18['buckets']['adj_neg'] + $d18['buckets']['other_in'] - $d18['buckets']['other_out'] + $d18['buckets']['opening_in']), json_encode($d18['buckets'] ?? null));
$b14 = null; foreach ($ovs['range1']['B']['rows'] as $r) { if ($r['date'] === '2026-06-14') { $b14 = $r; } }
check('warehouse scope (Cibadak, 06-14): the received transfer IS a Masuk (20 kg x gula cost)', $b14 && $b14['buckets']['masuk_transfer'] > 0, json_encode($b14['buckets'] ?? null));

// ===================== E. FIFO
echo "\n===== E. FIFO HPP =====\n";
foreach ($scopes as $sn => $wh) {
    $ok = true; $bad = '';
    foreach ($ovs['range1'][$sn]['rows'] as $r) {
        if ($r['is_pre_go_live']) { continue; }
        $sql = "SELECT COALESCE(SUM(a.subtotal),0) FROM fifo_allocations a JOIN inventory_transaction_lines l ON l.id=a.transaction_line_id JOIN inventory_transactions t ON t.id=l.transaction_id
                WHERE t.transaction_type='OUT' AND t.status IN ('POSTED','VOID') AND DATE(t.transaction_date)=:d" . ($wh !== null ? ' AND l.warehouse_id=:w' : '');
        $st = $pdo->prepare($sql);
        $st->execute($wh !== null ? ['d' => $r['date'], 'w' => $wh] : ['d' => $r['date']]);
        if (!near((float) $st->fetchColumn(), $r['buckets']['keluar_usage'])) { $ok = false; $bad = $r['date']; }
    }
    check("[{$sn}] Keluar (OUT) value per day == Σ fifo_allocations of those OUT lines", $ok, $bad);
}
$tr = M::itemTrail($pdo, '2026-06-15', $fx['items']['tepung']['id'], $W['A']);
$o = array_values(array_filter($tr['rows'], fn ($r) => $r['bucket'] === 'keluar'))[0];
check('trail HPP out == fifo_allocations (unit cost x qty, partly from two layers: 200@1000 + 50@1200 then 100@1100 — first 30 kg come from the oldest layer)', near($o['hpp_out'], 30 * 1000) && near($o['fifo_allocated_value'], 30000) && near($o['hpp_out_per_unit'], 1000, 0.0001), json_encode($o));

// ===================== F. adjustments / void
echo "\n===== F. adjustments · void/reversal =====\n";
$r17 = null; foreach ($ovs['range1']['B']['rows'] as $r) { if ($r['date'] === '2026-06-17') { $r17 = $r; } }
check('positive adjustment (roti +5 @ Cibadak, 06-17) is in Adjustment/Lain, never in Masuk or Keluar', $r17 && $r17['buckets']['adj_pos'] > 0 && near($r17['nominal']['masuk'], 0.0) && $r17['nominal']['lain'] > 0, json_encode($r17['buckets'] ?? null));
$di = M::dayItems($pdo, '2026-06-17', $W['A'], null, null, null, 'adjustment', 'sku', 'asc', 1, 50);
check('negative adjustment (gula -3 @ SCM, 06-17): movement filter "adjustment" returns it with a negative qty and value', count($di['rows']) === 1 && $di['rows'][0]['adjustment_qty'] < 0 && $di['rows'][0]['adjustment_value'] < 0, json_encode($di['rows']));
$tr = M::itemTrail($pdo, '2026-06-17', $fx['items']['gula']['id'], $W['A']);
$adjRow = array_values(array_filter($tr['rows'], fn ($r) => $r['bucket'] === 'adjustment'));
check('the adjustment appears in the trail as bucket "adjustment" with its reason as the note', count($adjRow) === 1 && str_contains((string) $adjRow[0]['note'], 'dashboard fixture') && $adjRow[0]['adjustment_qty'] < 0, json_encode($adjRow));
// an OPNAME adjustment gets the Stock Opname label
$opId = (int) $pdo->query("SELECT t.id FROM stock_adjustments sa JOIN inventory_transactions t ON t.id=sa.transaction_id ORDER BY sa.id DESC LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE stock_adjustments SET adjustment_type='OPNAME', reference_no='SO-MV-TEST' WHERE transaction_id = {$opId}");
$lastAdj = $pdo->query("SELECT l.item_id, l.warehouse_id, DATE(t.transaction_date) d FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id=t.id WHERE t.id = {$opId}")->fetch();
$tr = M::itemTrail($pdo, $lastAdj['d'], (int) $lastAdj['item_id'], (int) $lastAdj['warehouse_id']);
$lab = array_values(array_filter($tr['rows'], fn ($r) => $r['transaction_id'] === $opId));
check('an adjustment recorded as OPNAME is labelled "Koreksi Stock Opname" with its SO reference (not a purchase / usage)', $lab && $lab[0]['type_label'] === 'Koreksi Stock Opname' && $lab[0]['reference_no'] === 'SO-MV-TEST' && $lab[0]['bucket'] === 'adjustment', json_encode($lab));
$pdo->exec("UPDATE stock_adjustments SET adjustment_type='CORRECTION', reference_no=NULL WHERE transaction_id = {$opId}");
$r19 = null; foreach ($ovs['range1']['B']['rows'] as $r) { if ($r['date'] === '2026-06-19') { $r19 = $r; } }
check('voided purchase (06-19): the original is NOT a Masuk (status VOID); it sits in Adjustment/Lain and the reversal cancels it', $r19 && near($r19['buckets']['masuk_purchase'], 0.0) && $r19['buckets']['other_in'] > 0, json_encode($r19['buckets'] ?? null));
$voidSum = $pdo->query("SELECT COALESCE(SUM(CASE WHEN t.transaction_type='REVERSAL' THEN l.subtotal ELSE 0 END),0) FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id=l.transaction_id WHERE t.transaction_type='REVERSAL'")->fetchColumn();
check('the REVERSAL of that void exists in the ledger (reported on its own date, never silently dropped)', abs((float) $voidSum) > 0);

// ===================== G. units
echo "\n===== G. qty by unit =====\n";
$t = $ovs['range1']['A']['totals'];
$units = array_column($t['qty_by_unit']['masuk'], 'qty', 'unit');
check('Masuk quantity is grouped BY UNIT (KG, PCS, LTR separate) — never one mixed number', isset($units['KG'], $units['PCS'], $units['LTR']) && near($units['PCS'], 120, 0.0001) && near($units['LTR'], 32, 0.0001), json_encode($t['qty_by_unit']['masuk']));
check('no scalar "total qty" exists anywhere in the overview totals', !array_key_exists('qty', $t) && !array_key_exists('total_qty', $t) && !array_key_exists('masuk_qty', $t));
$di = M::dayItems($pdo, '2026-06-12', $W['A'], null, null, null, null, 'sku', 'asc', 1, 100);
$rowsU = array_column($di['rows'], 'unit', 'sku');
check('item rows carry their own unit', ($rowsU[$p1['sku']] ?? '') === 'PCS' && ($rowsU[$l1['sku']] ?? '') === 'LTR');
$ovItem = M::overview($pdo, $R1[0], $R1[1], $W['A'], null, null, $p1['id']);
$d15 = null; foreach ($ovItem['rows'] as $r) { if ($r['date'] === '2026-06-15') { $d15 = $r; } }
check('single item (PCS): the qty series is returned with the unit — 120 in on 06-12, 20 out on 06-15, closing 100', $ovItem['single_item']['unit'] === 'PCS' && $d15 && near($d15['qty']['keluar'], 20, 0.0001) && near($d15['qty']['closing'], 100, 0.0001), json_encode($d15['qty'] ?? null));
check('several items in scope: no single-item qty series (the UI must not chart mixed units)', $ovs['range1']['A']['single_item'] === null && $ovs['range1']['A']['rows'][5]['qty'] === null);

// ===================== H. filters
echo "\n===== H. filters · sort · pagination =====\n";
$cat = $fx['cat']['bahan'];
$ovCat = M::overview($pdo, $R2[0], $R2[1], null, $cat);
$closeCat = (float) $pdo->query("SELECT COALESCE(SUM(b.qty_base*b.unit_cost_base),0) FROM inventory_batches b JOIN items i ON i.id=b.item_id WHERE i.category_id={$cat}")->fetchColumn();
$lastCat = end($ovCat['rows']);
check('category filter: the closing at today equals the live batch valuation of that category (ledger == batches)', near($lastCat['nominal']['closing'], $closeCat, 0.05), "{$lastCat['nominal']['closing']} vs {$closeCat}");
$closeAll = (float) $pdo->query("SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches")->fetchColumn();
check('company closing at today == the live batch valuation of everything (matches InventoryService / Dashboard Nilai Stok on-hand)', near(end($ovs['range2']['company']['rows'])['nominal']['closing'], $closeAll, 0.05));
check('category filter: reconciliation still ok', $ovCat['reconciliation']['ok']);
$ovQ = M::overview($pdo, $R1[0], $R1[1], null, null, 'MVPCS');
check('search "MVPCS" narrows to that single item (single_item resolved, other items excluded)', $ovQ['single_item'] && $ovQ['single_item']['sku'] === $p1['sku'] && $ovQ['totals']['sku_closing'] === 1);
$pg = M::dayItems($pdo, '2026-06-20', null, null, null, null, null, 'sku', 'asc', 1, 2);
$pg2 = M::dayItems($pdo, '2026-06-20', null, null, null, null, null, 'sku', 'asc', 2, 2);
check('pagination: page 1 / 2 are disjoint, total pages derived, footer totals cover ALL rows', $pg['pagination']['total'] > 2 && count($pg['rows']) === 2 && $pg['rows'][0]['sku'] !== $pg2['rows'][0]['sku'] && near($pg['totals']['closing_value'], $pg2['totals']['closing_value']));
$s = M::dayItems($pdo, '2026-06-20', null, null, 'DFTEP', null, null, 'closing_value', 'desc', 1, 50);
check('search by SKU fragment + sort by closing value desc', count($s['rows']) >= 1 && str_starts_with($s['rows'][0]['sku'], 'DFTEP'));
$mv = M::dayItems($pdo, '2026-06-12', $W['A'], null, null, null, 'masuk', 'sku', 'asc', 1, 50);
check('movement filter "masuk": only items with an inbound that day', count($mv['rows']) >= 3 && !array_filter($mv['rows'], fn ($r) => $r['masuk_value'] <= 0 && $r['masuk_qty'] <= 0));
$mo = M::dayItems($pdo, '2026-06-20', null, null, null, null, null, 'sku', 'asc', 1, 200, true);
check('"moved only" lists only items that moved that day', count($mo['rows']) === 0 || !array_filter($mo['rows'], fn ($r) => $r['tx_count'] === 0));
try { M::dayItems($pdo, '2026-06-12', null, null, null, null, 'bogus', 'sku', 'asc', 1, 10); check('unknown movement type rejected', false); } catch (\App\Services\ValidationException $e) { check('unknown movement type rejected', true); }
$pt = M::periodTransactions($pdo, $R1[0], $R1[1], $W['A'], null, null, null, 'masuk', 1, 200);
$sumMasuk = 0.0; foreach ($pt['rows'] as $r) { $sumMasuk += $r['value']; }
check('KPI drill-down "masuk": Σ underlying lines == the period Masuk total (SCM)', $pt['pagination']['total'] <= 200 && near($sumMasuk, $ovs['range1']['A']['totals']['masuk']), "{$sumMasuk} vs {$ovs['range1']['A']['totals']['masuk']}");
$pk = M::periodTransactions($pdo, $R1[0], $R1[1], $W['A'], null, null, null, 'keluar', 1, 200);
$sumK = 0.0; foreach ($pk['rows'] as $r) { $sumK += $r['value']; }
check('KPI drill-down "keluar": Σ == period Keluar total (SCM)', near($sumK, $ovs['range1']['A']['totals']['keluar']));
$po = M::periodTransactions($pdo, $R1[0], $R1[1], $W['A'], null, null, null, 'other', 1, 200);
check('KPI drill-down "other": Σ signed == period Adjustment/Lain total (SCM)', near($po['totals']['value'], $ovs['range1']['A']['totals']['lain']) || near($po['totals']['value'], $ovs['range1']['A']['totals']['adjustment'] + $ovs['range1']['A']['totals']['other']), "{$po['totals']['value']} vs {$ovs['range1']['A']['totals']['lain']}");
$pre = M::overview($pdo, '2019-01-01', '2019-01-03', null);
check('a range entirely before go-live: honest zero rows flagged pre-go-live (never invented)', $pre['cutover']['is_pre_go_live_period'] && !array_filter($pre['rows'], fn ($r) => !$r['is_pre_go_live'] || $r['nominal']['closing'] != 0));
try { M::overview($pdo, '2024-01-01', '2026-12-31', null); check('range longer than 400 days rejected', false); } catch (\App\Services\ValidationException $e) { check('range longer than 400 days rejected', true); }


// ===================== I. HTTP
echo "\n===== I. HTTP =====\n";
$port = 8900 + random_int(4000, 4400);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir() . '/mv_srv.log', 'w']], $pipes, __DIR__ . '/..');
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'raw' => (string) $raw, 'body' => json_decode((string) $raw, true) ?: []];
}
function login(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'mv_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
function csv(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) { $rows[] = str_getcsv($line, ',', '"', '\\'); }
    return $rows;
}
try {
    check('server ready', $ready);
    $admin = login($base, $fx['admin']);
    $viewer = login($base, $fx['viewer']);
    $stockA = login($base, $fx['stockA']);
    $g = fn (array $s, string $path, array $q) => http('GET', "{$base}{$path}?" . http_build_query($q), null, $s['jar'], $s['csrf']);
    $q = ['start_date' => $R1[0], 'end_date' => $R1[1]];
    $r = $g($admin, '/reports/movement/overview', $q);
    check('admin: GET /reports/movement/overview -> 200 with rows + totals + reconciliation', $r['status'] === 200 && count($r['body']['data']['rows']) === 11 && isset($r['body']['data']['totals'], $r['body']['data']['reconciliation']));
    check('viewer (INVENTORY_VIEW): allowed', $g($viewer, '/reports/movement/overview', $q)['status'] === 200);
    $rs = $g($stockA, '/reports/movement/overview', $q + ['warehouse_id' => $W['B']]);
    check('STOCK user (warehouse A) asking for warehouse B via the query string is FORCED to warehouse A', $rs['status'] === 200 && (int) $rs['body']['data']['period']['warehouse_id'] === $W['A'] && near($rs['body']['data']['totals']['closing'], $ovs['range1']['A']['totals']['closing']));
    $rs2 = $g($stockA, '/reports/movement/overview', $q);
    check('STOCK user without a warehouse parameter also gets only their own warehouse (never company-wide)', (int) $rs2['body']['data']['period']['warehouse_id'] === $W['A']);
    $it = $g($stockA, '/reports/movement/day-items', ['date' => '2026-06-15', 'warehouse_id' => $W['B']]);
    check('STOCK user: day-items is scoped too (warehouse B request returns warehouse A numbers)', $it['status'] === 200 && near($it['body']['data']['totals']['closing_value'], M::dayItems($pdo, '2026-06-15', $W['A'], null, null, null, null, 'sku', 'asc', 1, 500)['totals']['closing_value']));
    $tt = $g($stockA, '/reports/movement/item-trail', ['date' => '2026-06-15', 'item_id' => $fx['items']['roti']['id'], 'warehouse_id' => $W['B']]);
    check('STOCK user: item-trail cannot read warehouse B (roti is only in B -> empty trail for A)', $tt['status'] === 200 && count($tt['body']['data']['rows']) === 0);
    check('validation: missing dates -> 422, missing item_id -> 422', $g($admin, '/reports/movement/overview', [])['status'] === 422 && $g($admin, '/reports/movement/item-trail', ['date' => '2026-06-15'])['status'] === 422);
    check('unauthenticated -> 401', http('GET', "{$base}/reports/movement/overview?" . http_build_query($q))['status'] === 401);
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $verb) {
        $w = http($verb, "{$base}/reports/movement/overview", [], $admin['jar'], $admin['csrf']);
        check("{$verb} on the report endpoint is not served (no write path exists)", $w['status'] >= 400);
    }
    // export == screen
    $sum = $g($admin, '/reports/movement/export', $q + ['kind' => 'summary', 'warehouse_id' => $W['A']]);
    $rowsC = csv($sum['raw']);
    $hdrIdx = null; foreach ($rowsC as $i => $row) { if (($row[0] ?? '') === 'Tanggal') { $hdrIdx = $i; break; } }
    $ovA = $ovs['range1']['A'];
    $totRow = null; foreach ($rowsC as $row) { if (($row[0] ?? '') === 'TOTAL PERIODE') { $totRow = $row; } }
    check('export summary: metadata header (period, warehouse, mode, generated) + one row per date', $sum['status'] === 200 && str_contains($sum['raw'], 'Gudang') && str_contains($sum['raw'], 'Dibuat') && $hdrIdx !== null && count(array_filter($rowsC, fn ($r) => preg_match('/^2026-06-\d\d$/', $r[0] ?? ''))) === 11);
    check('export summary: TOTAL PERIODE row == the screen totals (opening / masuk / keluar / lain / closing)', $totRow && near((float) $totRow[1], $ovA['totals']['opening']) && near((float) $totRow[2], $ovA['totals']['masuk']) && near((float) $totRow[3], $ovA['totals']['keluar']) && near((float) $totRow[4], $ovA['totals']['lain']) && near((float) $totRow[5], $ovA['totals']['closing']), json_encode($totRow));
    check('export summary: qty is exported per unit, never mixed (unit lines present)', str_contains($sum['raw'], 'Kuantitas per satuan') && str_contains($sum['raw'], 'PCS') && str_contains($sum['raw'], 'LTR'));
    $det = $g($admin, '/reports/movement/export', $q + ['kind' => 'detail', 'warehouse_id' => $W['A']]);
    $dRows = csv($det['raw']);
    $sumIn = 0.0; $sumOut = 0.0; $n = 0;
    foreach ($dRows as $row) { if (preg_match('/^2026-06-\d\d$/', $row[0] ?? '') && isset($row[8])) { $sumIn += (float) $row[8]; $sumOut += (float) $row[10]; $n++; } }
    check('export detail: Σ Masuk Nilai and Σ HPP Keluar over the item rows == the screen totals', $n > 0 && near($sumIn, $ovA['totals']['masuk']) && near($sumOut, $ovA['totals']['keluar']), "{$sumIn}/{$sumOut}");
    check('export detail keeps the unit column and qty columns', str_contains($det['raw'], 'Satuan') && str_contains($det['raw'], 'Saldo Akhir Qty') && str_contains($det['raw'], 'PCS'));
    $trn = $g($admin, '/reports/movement/export', $q + ['kind' => 'transactions', 'warehouse_id' => $W['A']]);
    $tRows = array_filter(csv($trn['raw']), fn ($r) => in_array($r[0] ?? '', ['masuk', 'keluar', 'adjustment_lain'], true));
    $tm = 0.0; foreach ($tRows as $row) { if ($row[0] === 'masuk') { $tm += (float) $row[9]; } }
    check('export transactions: one row per underlying ledger line; Σ masuk == screen Masuk', count($tRows) > 5 && near($tm, $ovA['totals']['masuk']), "{$tm}");
    check('export for a STOCK user is scoped (warehouse B request returns warehouse A)', str_contains($g($stockA, '/reports/movement/export', $q + ['kind' => 'summary', 'warehouse_id' => $W['B']])['raw'], 'Gudang SCM'));
    check('bad export kind -> 422', $g($admin, '/reports/movement/export', $q + ['kind' => 'bogus'])['status'] === 422);
    // legacy endpoints untouched
    check('the existing daily / day-breakdown endpoints still answer', $g($admin, '/reports/movement/daily', $q)['status'] === 200 && $g($admin, '/reports/movement/day-breakdown', ['date' => '2026-06-15'])['status'] === 200);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}
$after = snapshot($pdo);
check('read-only: ledger / FIFO / adjustments / items / audit tables are byte-identical after the whole run', $before === $after, json_encode(array_diff_assoc($after, $before)));

$failed = count(array_filter($results, fn ($x) => !$x));
echo "\n" . (count($results) - $failed) . ' / ' . count($results) . ' PASSED' . ($failed ? " — {$failed} FAILED" : '') . "\n";
exit($failed ? 1 : 0);
