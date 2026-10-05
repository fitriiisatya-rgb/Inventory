<?php
declare(strict_types=1);

/**
 * "Laporan Nilai Stok & HPP" — dual valuation (FIFO + Average): InventoryValuationService + GET /reports/inventory-valuation*, on REAL postings made through the
 * application's own services (tests/lib/valuation_fixture.php). Every expectation is HAND-COMPUTED in the fixture, independent of the report code.
 * Includes the MANDATORY addendum scenario (100 @ 1.000, 100 @ 1.100, OUT 80 → FIFO HPP 80.000 / end 120 qty 130.000; Average 1.050 / HPP 84.000 / end 126.000).
 *
 * Usage: php tests/inventory_valuation_test.php
 */

require_once __DIR__ . '/lib/valuation_fixture.php';

use App\Services\Database;
use App\Services\InventoryValuationService as V;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $eps;
}
function snap(PDO $pdo): array
{
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'stock_adjustments', 'item_price_history', 'warehouse_transfers', 'warehouse_transfer_lines', 'audit_logs', 'purchase_line_costs'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
$fx = valuation_build_fixture($pdo);
$E = $fx['expect'];
$I = $fx['items'];
$W1 = $fx['wh']['1'];
$W2 = $fx['wh']['2'];
$base = ['start_date' => $fx['range']['from'], 'end_date' => $fx['range']['to'], 'q' => 'VLZ'];
$before = snap($pdo);
$rowOf = static function (array $items, string $key) use ($I): ?array {
    foreach ($items as $r) {
        if ($r['item_id'] === $I[$key]['id']) {
            return $r;
        }
    }
    return null;
};

// ================================================================ A. MANDATORY addendum fixture (item P, warehouse W1)
echo "== A. mandatory fixture: 100 @ 1.000, 100 @ 1.100, OUT 80 ==\n";
$pF = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W1, 'item_id' => $I['P']['id'], 'method' => 'fifo']);
$pA = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W1, 'item_id' => $I['P']['id'], 'method' => 'average']);
$s = $pF['summary'];
check('FIFO: HPP = 80 × 1.000 = Rp 80.000; ending qty 120; ending value Rp 130.000', near($s['fifo']['hpp'], 80000) && near($s['qty_close'], 120) && near($s['fifo']['closing'], 130000), json_encode([$s['fifo']['hpp'], $s['qty_close'], $s['fifo']['closing']]));
check('FIFO: remaining layers 20 @ 1.000 and 100 @ 1.100 (value 20.000 + 110.000)', count($pF['layers']['active']) === 2 && near($pF['layers']['active'][0]['remaining'], 20) && near($pF['layers']['active'][0]['cost'], 1000) && near($pF['layers']['active'][1]['remaining'], 100) && near($pF['layers']['active'][1]['cost'], 1100) && near($pF['layers']['total_value'], 130000));
check('FIFO: the used layers panel lists the first layer as "Habis" (100 @ 1.000 consumed in the period? no — only 80 of it) → it is "Sebagian", not "Habis"', $pF['layers']['active'][0]['status'] === 'Sebagian' && $pF['layers']['active'][1]['status'] === 'Tersisa' && $pF['layers']['used'] === []);
$ra = $s['avg'];
check('Average: average = 1.050; HPP = 80 × 1.050 = Rp 84.000; ending qty 120; ending value Rp 126.000', near($ra['hpp'], 84000) && near($ra['closing'], 126000) && near($ra['unit_cost'], 1050) && $ra['known'], json_encode($ra));
check('Difference: HPP Rp 4.000 and ending inventory value Rp 4.000 (Average − FIFO)', near($pA['comparison']['diff']['hpp'], 4000) && near($pA['comparison']['diff']['closing'], -4000) && near($pF['comparison']['fifo']['hpp'], 80000) && near($pF['comparison']['average']['hpp'], 84000));
$fr = $pF['rows'];
check('FIFO history: 3 movements — IN 100 @ 1.000 (saldo 100 / 100.000), IN 100 @ 1.100 (200 / 210.000), OUT 80 with layer "80 @ 1.000" HPP 80.000 (saldo 120 / 130.000)', count($fr) === 3
    && near($fr[0]['qty_in'], 100) && near($fr[0]['unit_cost_in'], 1000) && near($fr[0]['bal_qty'], 100) && near($fr[0]['bal_value'], 100000)
    && near($fr[1]['unit_cost_in'], 1100) && near($fr[1]['bal_qty'], 200) && near($fr[1]['bal_value'], 210000)
    && near($fr[2]['qty_out'], 80) && count($fr[2]['layers']) === 1 && near($fr[2]['layers'][0]['qty'], 80) && near($fr[2]['layers'][0]['cost'], 1000) && near($fr[2]['hpp'], 80000) && near($fr[2]['bal_qty'], 120) && near($fr[2]['bal_value'], 130000));
$ar = $pA['rows'];
check('Average history: avg 1.000 → 1.050 (highlighted change), OUT: average before 1.050, HPP 84.000, saldo 120 / 126.000', count($ar) === 3
    && $ar[0]['avg_before'] === null && near($ar[0]['avg_after'], 1000) && $ar[0]['avg_changed']
    && near($ar[1]['qty_before'], 100) && near($ar[1]['value_before'], 100000) && near($ar[1]['avg_before'], 1000) && near($ar[1]['avg_after'], 1050) && near($ar[1]['value_in'], 110000) && $ar[1]['avg_changed']
    && near($ar[2]['avg_before'], 1050) && near($ar[2]['avg_after'], 1050) && !$ar[2]['avg_changed'] && near($ar[2]['hpp'], 84000) && near($ar[2]['bal_qty'], 120) && near($ar[2]['bal_value'], 126000));
check('Average formula panel: a REAL calculated example from the item — (100.000 + 110.000) / (100 + 100) = 1.050; outbound 80 × 1.050 = 84.000 → 120 / 126.000', $pA['formula']['inbound'] !== null
    && near($pA['formula']['inbound']['value_before'], 100000) && near($pA['formula']['inbound']['value_in'], 110000) && near($pA['formula']['inbound']['qty_after'], 200) && near($pA['formula']['inbound']['value_after'], 210000) && near($pA['formula']['inbound']['avg_after'], 1050)
    && near($pA['formula']['outbound']['hpp'], 84000) && near($pA['formula']['outbound']['qty_after'], 120) && near($pA['formula']['outbound']['value_after'], 126000));
check('FIFO vs Average, same item: per-OUT comparison rows (HPP FIFO 80.000 / HPP Average 84.000 / selisih 4.000)', count($pF['compare_rows']) === 1 && near($pF['compare_rows'][0]['hpp_fifo'], 80000) && near($pF['compare_rows'][0]['hpp_avg'], 84000) && near($pF['compare_rows'][0]['diff'], 4000));
check('historical import row (inventory_effect 0, 5 @ 9.999) is not in either history and does not move qty', count(array_filter($fr, static fn ($r) => $r['reference'] === 'PO-HIST')) === 0 && near($s['qty_close'], 120));

// ================================================================ B. company totals + per item
echo "\n== B. company-wide totals and every item ==\n";
$ovF = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'item', 'per_page' => 500]);
$ovA = V::overview($pdo, $base + ['method' => 'average', 'view' => 'item', 'per_page' => 500]);
$kf = $ovF['kpi']['fifo'];
$ka = $ovA['kpi']['average'];
check('FIFO KPI == hand-computed: opening 124.000, cost in 492.000, HPP 278.500, adjustment −11.000, transfer 0, closing 326.500', near($kf['opening'], $E['fifo']['opening']) && near($kf['cost_in'], $E['fifo']['cost_in']) && near($kf['hpp'], $E['fifo']['hpp']) && near($kf['transfer'], 0) && near($kf['adjustment'], $E['fifo']['adjustment']) && near($kf['closing'], $E['fifo']['closing']), json_encode($kf));
check('FIFO KPI: equation Opening + Cost In − HPP ± Transfer ± Adjustment = Closing holds exactly', near($kf['opening'] + $kf['cost_in'] - $kf['hpp'] + $kf['transfer'] + $kf['adjustment'], $kf['closing']));
check('FIFO KPI: 11 active layers, 6 SKUs with stock (U is negative), variance (ledger − layers) = 0', $kf['layers'] === $E['fifo']['layers'] && $kf['skus_with_stock'] === $E['fifo']['skus_with_stock'] && near($kf['variance'], 0), json_encode([$kf['layers'], $kf['skus_with_stock'], $kf['variance']]));
check('Average KPI == hand-computed over the 6 reconstructable items: opening 124.000, cost in 491.000, HPP 292.000, adjustment −13.500, closing 309.500', near($ka['opening'], $E['average']['opening']) && near($ka['cost_in'], $E['average']['cost_in']) && near($ka['hpp'], $E['average']['hpp']) && near($ka['adjustment'], $E['average']['adjustment']) && near($ka['closing'], $E['average']['closing']), json_encode($ka));
check('Average KPI: Opening + Pembelian − Pemakaian + (transfer & koreksi bersih) = Closing; Selisih / Rekonsiliasi = 0; Pemakaian ≥ HPP', near($ka['opening'] + $ka['cost_in'] - $ka['usage'] + $ka['other_net'], $ka['closing']) && near($ka['variance'], 0) && $ka['usage'] >= $ka['hpp'] - 0.01);
check('Average: 1 item "tidak dapat direkonstruksi" (U: out 15 > stock 10) — listed with its reason, excluded from every Average total', $ka['unknown_items'] === 1 && count($ovA['unreconstructable']) === 1 && $ovA['unreconstructable'][0]['item_id'] === $I['U']['id'] && str_contains($ovA['unreconstructable'][0]['reason'], 'melebihi saldo'), json_encode($ovA['unreconstructable']));
$cmp = $ovF['comparison'];
check('comparison card: FIFO HPP 277.000 vs Average HPP 292.000 (Δ 15.000); ending 327.000 vs 309.500 (Δ −17.500) over the SAME 6 items; the note does not call either one wrong', near($cmp['fifo']['hpp'], $E['fifo_known']['hpp']) && near($cmp['average']['hpp'], 292000) && near($cmp['diff']['hpp'], $E['diff']['hpp']) && near($cmp['fifo']['closing'], $E['fifo_known']['closing']) && near($cmp['diff']['closing'], $E['diff']['closing']) && $cmp['items'] === 6 && $cmp['excluded_items'] === 1 && str_contains($cmp['note'], 'bukan kesalahan'));
check('the operational method is declared FIFO in the payload notes', $ovF['system_method'] === 'FIFO' && in_array('Metode operasional sistem: FIFO', $ovF['notes'], true));
$bad = [];
foreach ($E['per_item'] as $k => $e) {
    $r = $rowOf($ovF['items'], $k);
    if ($r === null) { $bad[] = "{$k} missing"; continue; }
    if (!near($r['qty_close'], $e['qty_close']) || !near($r['fifo']['closing'], $e['fifo_close']) || !near($r['fifo']['hpp'], $e['fifo_hpp']) || $r['fifo']['layers'] !== $e['layers']) { $bad[] = "{$k} fifo " . json_encode([$r['qty_close'], $r['fifo']['closing'], $r['fifo']['hpp'], $r['fifo']['layers']]); }
    if (isset($e['fifo_open']) && !near($r['fifo']['opening'], $e['fifo_open'])) { $bad[] = "{$k} fifo opening"; }
    if (isset($e['avg_close'])) {
        if (!$r['avg']['known'] || !near($r['avg']['closing'], $e['avg_close']) || !near($r['avg']['hpp'], $e['avg_hpp'])) { $bad[] = "{$k} avg " . json_encode($r['avg']); }
        if (isset($e['avg_cost']) && !near($r['avg']['unit_cost'], $e['avg_cost'])) { $bad[] = "{$k} avg cost"; }
    } elseif ($r['avg']['known']) { $bad[] = "{$k} avg should be unknown"; }
}
check('every item: FIFO closing / HPP / layers and Average closing / HPP / average cost == hand-computed (P Q R S T U O)', $bad === [], implode(' | ', $bad));
$rU = $rowOf($ovF['items'], 'U');
check('item U (negative stock): FIFO still exact (qty −5, value −500, HPP 1.500, negative layer −500); Average = "—" with a reason, no invented number', near($rU['qty_close'], -5) && near($rU['fifo']['closing'], -500) && $rU['avg']['known'] === false && !isset($rU['avg']['closing']) && is_string($rU['avg']['reason']) && $rU['avg']['reason'] !== '' && $rU['fifo']['layers'] === 0);
check('footer totals: FIFO closing = 326.500; Average footer excludes U', near($ovF['items_footer']['f']['closing'], 326500) && near($ovF['items_footer']['a']['closing'], 309500) && $ovF['items_footer']['unknown_items'] === 1);
check('item rows are per item and never summed across units/items in quantity (no total qty returned)', !isset($ovF['items_footer']['qty_close']) && count($ovF['items']) === 7);
$rQ = $rowOf($ovF['items'], 'Q');
check('boundary rule: Q\'s OPENING dated exactly at 10-01 00:00:00 is BEGINNING inventory (qty 50, Rp 100.000) — not cost-in (cost in = only the 50 @ 2.400)', near($rQ['qty_open'], 50) && near($rQ['fifo']['opening'], 100000) && near($rQ['fifo']['cost_in'], 120000) && near($rQ['avg']['opening'], 100000));
$rO = $rowOf($ovF['items'], 'O');
check('opening before the period (O: 30 @ 800 on 09-20) is the opening balance; Average opening 24.000, average cost opening 800', near($rO['qty_open'], 30) && near($rO['fifo']['opening'], 24000) && near($rO['avg']['opening'], 24000) && near($rO['avg']['avg_open'], 800));
check('Q multi-layer OUT: FIFO HPP 148.000 = 50 @ 2.000 + 20 @ 2.400 vs Average 154.000 (70 × 2.200)', (function () use ($pdo, $I, $W1) {
    $d = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W1, 'item_id' => $I['Q']['id'], 'method' => 'fifo']);
    $o = array_values(array_filter($d['rows'], static fn ($r) => $r['qty_out'] !== null))[0];
    $l = $o['layers'];
    return count($l) === 2 && near($l[0]['qty'], 50) && near($l[0]['cost'], 2000) && near($l[1]['qty'], 20) && near($l[1]['cost'], 2400) && near($o['hpp'], 148000) && near($d['comparison']['average']['hpp'], 154000);
})());
$dq = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W1, 'item_id' => $I['Q']['id'], 'method' => 'fifo']);
check('FIFO layers of Q: the 50 @ 2.000 opening layer is "Habis" (used panel), 30 @ 2.400 remains "Sebagian"', count($dq['layers']['used']) === 1 && near($dq['layers']['used'][0]['cost'], 2000) && $dq['layers']['used'][0]['status'] === 'Habis' && count($dq['layers']['active']) === 1 && near($dq['layers']['active'][0]['remaining'], 30) && $dq['layers']['active'][0]['status'] === 'Sebagian');

// ================================================================ C. transfers
echo "\n== C. transfers (layer cost vs average cost) ==\n";
$r1 = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'item', 'warehouse_id' => $W1, 'per_page' => 500]);
$r2 = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'item', 'warehouse_id' => $W2, 'per_page' => 500]);
$a1 = V::overview($pdo, $base + ['method' => 'average', 'view' => 'item', 'warehouse_id' => $W1, 'per_page' => 500]);
$a2 = V::overview($pdo, $base + ['method' => 'average', 'view' => 'item', 'warehouse_id' => $W2, 'per_page' => 500]);
$R1f = $rowOf($r1['items'], 'R'); $R2f = $rowOf($r2['items'], 'R'); $R1a = $rowOf($a1['items'], 'R'); $R2a = $rowOf($a2['items'], 'R');
check('W1 (source): FIFO transfer out −32.000 (50 @ 500 + 10 @ 700), closing 28.000; Average transfer out −36.000 (60 × 600), closing 24.000', near($R1f['fifo']['transfer'], $E['r_w1']['fifo_trf']) && near($R1f['fifo']['closing'], $E['r_w1']['fifo_close']) && near($R1a['avg']['transfer'], $E['r_w1']['avg_trf']) && near($R1a['avg']['closing'], $E['r_w1']['avg_close']));
check('W2 (destination): FIFO transfer in +32.000 (the two received layers keep their cost), HPP 15.000 (30 @ 500), closing 17.000; Average transfer in +36.000 (carries the source average, NOT a re-price), HPP 18.000, closing 18.000', near($R2f['fifo']['transfer'], $E['r_w2']['fifo_trf']) && near($R2f['fifo']['hpp'], $E['r_w2']['fifo_hpp']) && near($R2f['fifo']['closing'], $E['r_w2']['fifo_close']) && near($R2a['avg']['transfer'], $E['r_w2']['avg_trf']) && near($R2a['avg']['hpp'], $E['r_w2']['avg_hpp']) && near($R2a['avg']['closing'], $E['r_w2']['avg_close']));
check('warehouse scope: W2 lists ONLY item R (the only item with a W2 movement)', count($r2['items']) === 1 && $r2['items'][0]['item_id'] === $I['R']['id']);
check('company-wide: internal transfer nets to zero value movement in BOTH methods', near($kf['transfer'], 0) && near($ka['transfer'], 0) && near($R1f['fifo']['transfer'] + $R2f['fifo']['transfer'], 0) && near($R1a['avg']['transfer'] + $R2a['avg']['transfer'], 0));
$d2 = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W2, 'item_id' => $I['R']['id'], 'method' => 'average']);
$tin = array_values(array_filter($d2['rows'], static fn ($r) => $r['type'] === 'TRANSFER_IN'));
check('Average history W2: the TRANSFER_IN rows total 60 pcs / Rp 36.000 at average cost 600 (the source average, not the FIFO layer costs 500 / 700)', count($tin) === 2 && near(array_sum(array_column($tin, 'qty_in')), 60) && near(array_sum(array_column($tin, 'value_in')), 36000) && near($d2['rows'][count($d2['rows']) - 1]['bal_value'], 18000));

// ================================================================ D. adjustment / void
echo "\n== D. adjustments, void ==\n";
$rS = $rowOf($ovF['items'], 'S');
check('S: FIFO adjustment −11.000 (−50 consumed at 300 = −15.000, +10 at last cost 400 = +4.000) vs Average adjustment −13.500 (−50 × 350 = −17.500, +10 recorded 4.000)', near($rS['fifo']['adjustment'], -11000) && near($rS['avg']['adjustment'], -13500) && near($rS['fifo']['hpp'], 0));
$rT = $rowOf($ovF['items'], 'T');
check('T (voided OUT): HPP 0 (a voided OUT is not HPP); the void original and its reversal net to 0 in Adjustment & Koreksi; qty 100, value 1.000 in both methods', near($rT['fifo']['hpp'], 0) && near($rT['fifo']['adjustment'], 0) && near($rT['qty_close'], 100) && near($rT['fifo']['closing'], 1000) && near($rT['avg']['closing'], 1000) && near($rT['avg']['adjustment'], 0));
$dT = V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'warehouse_id' => $W1, 'item_id' => $I['T']['id'], 'method' => 'fifo']);
check('T history shows the VOID original ("Barang Keluar — VOID") and the reversal ("Reversal (Void)") with the restored layer', count(array_filter($dT['rows'], static fn ($r) => $r['status'] === 'VOID' && str_contains($r['type_label'], 'VOID'))) === 1 && count(array_filter($dT['rows'], static fn ($r) => $r['type'] === 'REVERSAL')) === 1 && near($dT['rows'][count($dT['rows']) - 1]['bal_qty'], 100));

// ================================================================ E. Per Hari
echo "\n== E. Per Hari ==\n";
$dayF = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'day']);
$dayA = V::overview($pdo, $base + ['method' => 'average', 'view' => 'day']);
$rows = $dayF['days'];
$eqOk = true;
foreach ($rows as $r) {
    $f = $r['fifo'];
    if (!near($f['opening'] + $f['cost_in'] - $f['hpp'] + $f['transfer'] + $f['adjustment'], $f['closing'], 0.02)) { $eqOk = false; }
}
check('31 daily rows; FIFO equation Opening + Cost In − HPP ± Transfer ± Adjustment = Closing on EVERY day', count($rows) === 31 && $eqOk);
$chain = true;
for ($i = 1; $i < count($rows); $i++) {
    if (!near($rows[$i]['fifo']['opening'], $rows[$i - 1]['fifo']['closing'], 0.02)) { $chain = false; }
}
check('each day opens with the previous day\'s closing; first opening = 124.000; last closing = KPI closing 326.500', $chain && near($rows[0]['fifo']['opening'], 124000) && near($rows[30]['fifo']['closing'], $kf['closing']));
$byDate = [];
foreach ($rows as $r) { $byDate[$r['date']] = $r; }
check('FIFO HPP per day: 10-02 = 1.500 (U), 10-03 = 80.000 (P), 10-04 = 197.000 (Q 148.000 + R 15.000 + O 34.000); Σ days = KPI HPP', near($byDate['2026-10-02']['fifo']['hpp'], 1500) && near($byDate['2026-10-03']['fifo']['hpp'], 80000) && near($byDate['2026-10-04']['fifo']['hpp'], 197000) && near(array_sum(array_column(array_column($rows, 'fifo'), 'hpp')), $kf['hpp']));
check('FIFO layers per day: 10-31 = 11 active layers; day variance (ledger − layers) = 0 on every day', $rows[30]['fifo']['layers'] === 11 && count(array_filter($rows, static fn ($r) => abs($r['fifo']['variance']) > 0.05)) === 0 && near($rows[30]['fifo']['layer_value'], 326500));
$ra = $dayA['days'];
$byA = [];
foreach ($ra as $r) { $byA[$r['date']] = $r; }
check('Average HPP per day: 10-03 = 84.000 (P), 10-04 = 208.000 (Q 154.000 + R 18.000 + O 36.000); 10-02 = 0 (U is excluded, never invented)', near($byA['2026-10-03']['avg']['hpp'], 84000) && near($byA['2026-10-04']['avg']['hpp'], 208000) && near($byA['2026-10-02']['avg']['hpp'], 0));
$aEq = true;
foreach ($ra as $r) { $a = $r['avg']; if (!near($a['opening'] + $a['cost_in'] - $a['hpp'] + $a['transfer'] + $a['adjustment'], $a['closing'], 0.02) || abs($a['variance']) > 0.05) { $aEq = false; } }
check('Average equation holds on every day and the replay-state variance is 0; last closing = KPI 309.500', $aEq && near($ra[30]['avg']['closing'], 309500));
check('company-wide Average End-of-Day cost is NOT computed across different SKUs ("—"), only for a single selected item', $ra[10]['avg']['eod_cost'] === null);
$dp = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'warehouse_id' => $W1, 'item_id' => $I['P']['id'], 'method' => 'average', 'view' => 'day']);
$dpd = [];
foreach ($dp['days'] as $r) { $dpd[$r['date']] = $r; }
check('single item P: Average cost end-of-day 10-01 = 1.000, 10-02 = 1.050, 10-03 = 1.050 (unchanged by the outbound), 10-04 = 1.050', near($dpd['2026-10-01']['avg']['eod_cost'], 1000) && near($dpd['2026-10-02']['avg']['eod_cost'], 1050) && near($dpd['2026-10-03']['avg']['eod_cost'], 1050) && near($dpd['2026-10-04']['avg']['eod_cost'], 1050));
$mo = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'day', 'bucket' => 'month']);
check('bucket "month": one row; opening = first day, closing = last day, movements summed', count($mo['days']) === 1 && near($mo['days'][0]['fifo']['opening'], 124000) && near($mo['days'][0]['fifo']['closing'], 326500) && near($mo['days'][0]['fifo']['hpp'], 278500) && near($mo['days'][0]['fifo']['cost_in'], 492000));
$wk = V::overview($pdo, $base + ['method' => 'average', 'view' => 'day', 'bucket' => 'week']);
check('bucket "week": ISO weeks (Mondays), Σ weekly HPP = KPI HPP, last closing = KPI closing', count($wk['days']) >= 4 && near(array_sum(array_map(static fn ($r) => $r['avg']['hpp'], $wk['days'])), $ka['hpp']) && near($wk['days'][count($wk['days']) - 1]['avg']['closing'], 309500));

// ================================================================ F. filters
echo "\n== F. filters ==\n";
$cA = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'category_id' => $fx['cat']['A'], 'method' => 'fifo', 'view' => 'item']);
$cAa = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'category_id' => $fx['cat']['A'], 'method' => 'average', 'view' => 'item']);
check('category A (P + Q): 2 items; FIFO closing 202.000 (130.000 + 72.000); Average 192.000 (126.000 + 66.000); HPP 228.000 / 238.000', count($cA['items']) === 2 && near($cA['kpi']['fifo']['closing'], 202000) && near($cAa['kpi']['average']['closing'], 192000) && near($cA['kpi']['fifo']['hpp'], 228000) && near($cAa['kpi']['average']['hpp'], 238000));
$cB = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'category_id' => $fx['cat']['B'], 'method' => 'fifo', 'view' => 'item']);
check('category B: 5 items (R S T U O); FIFO closing 124.500', count($cB['items']) === 5 && near($cB['kpi']['fifo']['closing'], 124500));
$qq = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'q' => $I['S']['sku'], 'method' => 'fifo', 'view' => 'item']);
check('search by SKU narrows to that item', count($qq['items']) === 1 && $qq['items'][0]['sku'] === $I['S']['sku']);
$late = V::overview($pdo, ['start_date' => '2026-10-03', 'end_date' => '2026-10-31', 'item_id' => $I['P']['id'], 'warehouse_id' => $W1, 'method' => 'average', 'view' => 'item']);
check('period starting 10-03: P opens with 200 pcs / Rp 210.000 at average 1.050 (history before the period is replayed, never re-priced)', near($late['items'][0]['qty_open'], 200) && near($late['items'][0]['avg']['opening'], 210000) && near($late['items'][0]['avg']['avg_open'], 1050) && near($late['items'][0]['avg']['hpp'], 84000));
$before2 = V::overview($pdo, ['start_date' => '2025-01-01', 'end_date' => '2025-01-31', 'q' => 'VLZ', 'method' => 'fifo', 'view' => 'item']);
check('a period before any movement: no rows and zero KPIs (nothing invented)', $before2['items'] === [] && near($before2['kpi']['fifo']['closing'], 0));
check('validation: bad method / reversed dates / missing item_id for the detail', (function () use ($pdo) {
    foreach ([['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'method' => 'lifo'], ['start_date' => '2026-10-31', 'end_date' => '2026-10-01'], ['end_date' => '2026-10-01']] as $f) {
        try { V::overview($pdo, $f); return false; } catch (\App\Services\ValidationException $e) {}
    }
    try { V::itemDetail($pdo, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']); return false; } catch (\App\Services\ValidationException $e) {}
    return true;
})());

// ================================================================ G. reconciliation (+ it detects a tampered layer)
echo "\n== G. reconciliation ==\n";
$rec = $ovF['reconciliation'];
check('reconciliation: every check OK (layer qty = ledger qty, layer value = ledger value, layers = inventory_batches now, allocation qty = OUT qty, allocation cost = HPP, Average identities, transfers net 0)', $rec['ok'] === true && count($rec['checks']) >= 8, json_encode(array_map(static fn ($c) => [$c['name'], $c['ok']], array_filter($rec['checks'], static fn ($c) => !$c['ok']))));
$endNow = max('2026-10-31', date('Y-m-d'));
$bId = (int) $pdo->query("SELECT b.id FROM inventory_batches b WHERE b.item_id = {$I['P']['id']} ORDER BY b.id LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE inventory_batches SET qty_base = qty_base + 3 WHERE id = {$bId}");
$recBad = V::overview($pdo, ['start_date' => '2026-10-01', 'end_date' => $endNow, 'q' => 'VLZ', 'method' => 'fifo', 'view' => 'item'])['reconciliation'];
$pdo->exec("UPDATE inventory_batches SET qty_base = qty_base - 3 WHERE id = {$bId}");
check('a tampered batch (+3 pcs) is DETECTED by the reconciliation (not hidden)', $recBad['ok'] === false && count(array_filter($recBad['checks'], static fn ($c) => !$c['ok'])) >= 1);
$aId = (int) $pdo->query("SELECT fa.id FROM fifo_allocations fa JOIN inventory_transaction_lines l ON l.id = fa.transaction_line_id WHERE l.item_id = {$I['P']['id']} ORDER BY fa.id LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE fifo_allocations SET subtotal = subtotal + 7 WHERE id = {$aId}");
$recBad2 = V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'item'])['reconciliation'];
$pdo->exec("UPDATE fifo_allocations SET subtotal = subtotal - 7 WHERE id = {$aId}");
check('a tampered allocation cost (+Rp 7) is DETECTED (allocation cost ≠ OUT HPP)', $recBad2['ok'] === false);
check('after restoring the tampered rows the reconciliation is clean again', V::overview($pdo, $base + ['method' => 'fifo', 'view' => 'item'])['reconciliation']['ok'] === true);

// ================================================================ H. export
echo "\n== H. export ==\n";
$meta = ['Laporan' => 'Laporan Nilai Stok & HPP', 'Periode' => '2026-10-01 s/d 2026-10-31'];
$sf = V::exportWorkbook($pdo, $base + ['method' => 'fifo', 'view' => 'item'], $meta);
$sa = V::exportWorkbook($pdo, $base + ['method' => 'average', 'view' => 'item'], $meta);
check('FIFO workbook sheets: Ringkasan FIFO, Nilai Stok per Barang, Riwayat FIFO, Layer Aktif, Layer Terpakai, Rekonsiliasi, FIFO vs Average', array_keys($sf) === ['Ringkasan FIFO', 'Nilai Stok per Barang', 'Riwayat FIFO', 'Layer Aktif', 'Layer Terpakai', 'Rekonsiliasi', 'FIFO vs Average'], implode(',', array_keys($sf)));
check('Average workbook sheets: Ringkasan Average, Average per Barang, Riwayat Average, Per Hari, Rekonsiliasi, FIFO vs Average', array_keys($sa) === ['Ringkasan Average', 'Average per Barang', 'Riwayat Average', 'Per Hari', 'Rekonsiliasi', 'FIFO vs Average'], implode(',', array_keys($sa)));
$metaCells = array_column($sf['Ringkasan FIFO']['rows'], 1, 0);
check('export metadata states the method (FIFO / AVERAGE) and "Metode operasional sistem: FIFO"', ($metaCells['Metode'] ?? '') === 'FIFO' && ($metaCells['Metode operasional sistem'] ?? '') === 'FIFO' && (array_column($sa['Ringkasan Average']['rows'], 1, 0)['Metode'] ?? '') === 'AVERAGE');
$tot = end($sf['Nilai Stok per Barang']['rows']);
check('export == screen: Nilai Stok per Barang TOTAL row (closing / HPP) equals the KPI cards', $tot[0] === 'TOTAL' && near((float) $tot[13], $kf['closing']) && near((float) $tot[10], $kf['hpp']) && near((float) $tot[8], $kf['opening']));
$aRows = $sa['Average per Barang']['rows'];
$uRow = array_values(array_filter($aRows, static fn ($r) => $r[0] === $I['U']['sku']))[0];
check('Average export: item U shows "Average tidak dapat direkonstruksi" (no number); TOTAL excludes it and equals the KPI closing 309.500', $uRow[8] === 'Average tidak dapat direkonstruksi' && near((float) end($aRows)[14], 309500));
$layerRows = $sf['Layer Aktif']['rows'];
check('Layer Aktif sheet: 11 active layers whose Nilai Sisa sums to 326.500 + 500 (the negative layer is not listed) = 327.000', count($layerRows) === 11 && near(array_sum(array_column($layerRows, 9)), 327000));
$hist = $sf['Riwayat FIFO']['rows'];
check('Riwayat FIFO: one row per ledger movement in the period (no historical import row), the multi-layer OUT carries its "50 @ 2000 + 20 @ 2400" layer text', count(array_filter($hist, static fn ($r) => $r[5] === 'PO-HIST')) === 0 && count(array_filter($hist, static fn ($r) => $r[9] === '50 @ 2000 + 20 @ 2400')) === 1);
$cs = $sf['FIFO vs Average']['rows'];
check('FIFO vs Average sheet: columns SKU, Item, Qty Akhir, Nilai FIFO, Nilai Average, HPP FIFO, HPP Average, Selisih Nilai, Selisih HPP; TOTAL differences = comparison card', $sf['FIFO vs Average']['headers'] === ['SKU', 'Item', 'Qty Akhir', 'Nilai FIFO', 'Nilai Average', 'HPP FIFO', 'HPP Average', 'Selisih Nilai', 'Selisih HPP'] && near((float) end($cs)[8], $cmp['diff']['hpp']) && near((float) end($cs)[7], $cmp['diff']['closing']));

// ================================================================ I. read-only
check('I the whole report (overview, detail, day view, export) wrote NOTHING (row counts + checksums of the ledger, batches, allocations, adjustments, price history, audit log unchanged)', snap($pdo) === $before);

// ================================================================ J. HTTP
echo "\n== J. HTTP ==\n";
$port = 8900 + random_int(1600, 1999);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
$baseUrl = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$baseUrl}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null, bool $raw = false): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($csrf) { $headers[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => $raw]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return $raw ? ['status' => $status, 'headers' => substr((string) $out, 0, $hs), 'raw' => substr((string) $out, $hs), 'body' => []] : ['status' => $status, 'body' => json_decode((string) $out, true) ?: []];
}
function loginAs(string $baseUrl, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'vl_');
    $r = http('POST', "{$baseUrl}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($baseUrl, $fx['viewer']);
    $stock2 = loginAs($baseUrl, $fx['stock2']);
    $admin = loginAs($baseUrl, $fx['admin']);
    check('logins succeed', $viewer['status'] === 200 && $stock2['status'] === 200 && $admin['status'] === 200);
    $qs = 'start_date=2026-10-01&end_date=2026-10-31&q=VLZ';
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}&method=fifo&view=item", null, $viewer['jar']);
    check('VIEWER (INVENTORY_VIEW only): FIFO overview 200 with KPI + items + comparison + reconciliation; FIFO closing 326.500', $r['status'] === 200 && near((float) ($r['body']['data']['kpi']['fifo']['closing'] ?? -1), 326500) && isset($r['body']['data']['comparison'], $r['body']['data']['reconciliation']), (string) $r['status']);
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}&method=average&view=item", null, $viewer['jar']);
    check('method=average on the same filters → Average closing 309.500 (same endpoint, explicit method parameter)', $r['status'] === 200 && $r['body']['data']['method'] === 'average' && near((float) $r['body']['data']['kpi']['average']['closing'], 309500));
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}&method=average&view=day", null, $viewer['jar']);
    check('view=day → 31 daily rows', $r['status'] === 200 && count($r['body']['data']['days']) === 31);
    check('unauthenticated → 401; bad method / reversed dates / missing dates → 422', http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}")['status'] === 401 && http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}&method=lifo", null, $viewer['jar'])['status'] === 422
        && http('GET', "{$baseUrl}/reports/inventory-valuation?start_date=2026-10-31&end_date=2026-10-01", null, $viewer['jar'])['status'] === 422 && http('GET', "{$baseUrl}/reports/inventory-valuation", null, $viewer['jar'])['status'] === 422);
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation?{$qs}&method=fifo&warehouse_id={$W1}", null, $stock2['jar']);
    check('Y a STOCK user of W2 asking for W1 through the query string gets ONLY W2 (item R, FIFO closing 17.000)', $r['status'] === 200 && count($r['body']['data']['items']) === 1 && near((float) $r['body']['data']['kpi']['fifo']['closing'], 17000));
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation/item?{$qs}&item_id={$I['P']['id']}&method=average", null, $viewer['jar']);
    check('item detail: 200, Average rows incl. the formula example; unknown item → 404; missing item_id → 422', $r['status'] === 200 && near((float) $r['body']['data']['summary']['avg']['closing'], 126000) && isset($r['body']['data']['formula']['inbound'])
        && http('GET', "{$baseUrl}/reports/inventory-valuation/item?{$qs}&item_id=999999999", null, $viewer['jar'])['status'] === 404 && http('GET', "{$baseUrl}/reports/inventory-valuation/item?{$qs}", null, $viewer['jar'])['status'] === 422);
    $r = http('GET', "{$baseUrl}/reports/inventory-valuation/item?{$qs}&item_id={$I['P']['id']}&method=fifo", null, $viewer['jar']);
    check('item detail FIFO: layers + per-OUT comparison present', $r['status'] === 200 && count($r['body']['data']['layers']['active']) === 2 && count($r['body']['data']['compare_rows']) === 1);
    $x = http('GET', "{$baseUrl}/reports/inventory-valuation/export?{$qs}&method=fifo", null, $viewer['jar'], null, true);
    check('export FIFO over HTTP: 200 xlsx, a valid zip with 7 sheets', $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml') !== false && (function () use ($x) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName('xl/worksheets/sheet7.xml') !== false && $z->locateName('xl/worksheets/sheet8.xml') === false; $z->close(); @unlink($f); return $ok; })());
    $x = http('GET', "{$baseUrl}/reports/inventory-valuation/export?{$qs}&method=average", null, $viewer['jar'], null, true);
    check('export Average over HTTP: 200 xlsx, 6 sheets', $x['status'] === 200 && (function () use ($x) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName('xl/worksheets/sheet6.xml') !== false && $z->locateName('xl/worksheets/sheet7.xml') === false; $z->close(); @unlink($f); return $ok; })());
    check('existing routes unaffected: GET /reports/inventory-hpp/summary 200', http('GET', "{$baseUrl}/reports/inventory-hpp/summary?start_date=2026-10-01&end_date=2026-10-31", null, $admin['jar'])['status'] === 200);
    $beforeHttp = snap($pdo);
    $writeOk = true;
    foreach (['', '/item', '/export'] as $route) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (http($verb, "{$baseUrl}/reports/inventory-valuation{$route}", [], $admin['jar'], $admin['csrf'])['status'] !== 404) { $writeOk = false; }
        }
    }
    check('POST / PUT / PATCH / DELETE on every valuation route → 404 (no write route exists)', $writeOk);
    check('HTTP reads + rejected write verbs changed nothing in the database', snap($pdo) === $beforeHttp);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
