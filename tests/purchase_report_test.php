<?php
declare(strict_types=1);

/**
 * "Laporan Pembelian" (redesign) — PurchaseReportService + GET /reports/purchase-v2/*, on REAL Stock IN V2 purchases posted through the application's own services
 * (tests/lib/purchase_report_fixture.php). Expectations are HAND-COMPUTED in the fixture, independent of the report code.
 *
 * Usage: php tests/purchase_report_test.php
 */

require_once __DIR__ . '/lib/purchase_report_fixture.php';

use App\Services\Database;
use App\Services\PurchaseCostingService;
use App\Services\PurchaseReportService as R;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.002): bool
{
    return ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $eps;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
$fx = purchase_build_fixture($pdo);
$E = $fx['expect'];
$I = $fx['items'];
$base = ['start_date' => $fx['range']['from'], 'end_date' => $fx['range']['to'], 'per_page' => 100];
function snap(PDO $pdo): array
{
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'purchase_invoice_headers', 'purchase_line_costs', 'item_price_history', 'audit_logs', 'stock_adjustments'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}
$before = snap($pdo);

$ov = R::overview($pdo, $base);
$n = $ov['kpi']['nominal'];
$inv = R::invoices($pdo, $base);
$byRef = [];
foreach ($inv['rows'] as $r) {
    $byRef[$r['reference']] = $r;
}
$noRef = array_values(array_filter($inv['rows'], static fn ($r) => !$r['has_reference'] && $r['source'] === 'V2'))[0] ?? null;

// ================================================================ inclusion / source
echo "== inclusion rules ==\n";
check('A period total (live): V2 138.025,5 + legacy 8.000 = 146.025,5 == hand-computed', near($n['total'], $E['live_total']) && near($n['sources']['V2']['total'], $E['v2_total']) && near($n['sources']['Legacy']['total'], $E['legacy_total']), (string) $n['total']);
check('J total purchase reconciles: == Σ invoice rows == Σ posted IN transactions of the period (independent SQL)', (function () use ($pdo, $inv, $n) {
    $sumRows = round(array_sum(array_map(static fn ($r) => $r['total'], $inv['rows'])), 4);
    // independent: V2 rows = Σ purchase_invoice_headers.invoice_total, legacy rows = Σ line subtotal, only POSTED live IN
    $v2 = (float) $pdo->query("SELECT COALESCE(SUM(h.invoice_total),0) FROM purchase_invoice_headers h JOIN inventory_transactions t ON t.id = h.transaction_id WHERE t.transaction_type='IN' AND t.status='POSTED' AND t.is_historical_import=0 AND DATE(t.transaction_date) BETWEEN '2026-09-01' AND '2026-09-30'")->fetchColumn();
    $leg = (float) $pdo->query("SELECT COALESCE(SUM(l.subtotal),0) FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id=t.id LEFT JOIN purchase_invoice_headers h ON h.transaction_id=t.id WHERE t.transaction_type='IN' AND t.status='POSTED' AND t.is_historical_import=0 AND h.id IS NULL AND DATE(t.transaction_date) BETWEEN '2026-09-01' AND '2026-09-30'")->fetchColumn();
    return near($sumRows, $n['total']) && near($n['total'], round($v2 + $leg, 4));
})());
check('V voided invoice INV-VOID is LISTED with status VOID, counts 0 in every total, and is disclosed (1 invoice, Rp 4.440)', isset($byRef['INV-VOID']) && $byRef['INV-VOID']['status'] === 'VOID' && near($byRef['INV-VOID']['total'], 0.0) && $ov['disclosures']['void']['invoices'] === 1 && near($ov['disclosures']['void']['amount'], $E['D_total']));
check('only type IN counts: transfers / opening / production / adjustments never appear (every row references an IN transaction)', (function () use ($pdo, $inv) {
    foreach ($inv['rows'] as $r) {
        foreach ($r['tx_ids'] as $id) {
            if ($pdo->query("SELECT transaction_type FROM inventory_transactions WHERE id = {$id}")->fetchColumn() !== 'IN') { return false; }
        }
    }
    return true;
})());
check('historical import is NOT in the default (live) report; "historical=1" shows only it (source Historis, 5 KG, Rp 500); "all" includes both', !isset($byRef['PO-HIST']) && count(R::invoices($pdo, $base + ['historical' => '1'])['rows']) === 1 && R::invoices($pdo, $base + ['historical' => '1'])['rows'][0]['source'] === 'Historis' && near(R::invoices($pdo, $base + ['historical' => '1'])['rows'][0]['total'], 500.0) && count(R::invoices($pdo, $base + ['historical' => 'all'])['rows']) === 6);
check('W legacy purchase: source Legacy, total = recorded value 8.000, components UNKNOWN (null, never a fabricated 0)', (function () use ($byRef) {
    $l = $byRef['PO-LEGACY'] ?? null;
    return $l && $l['source'] === 'Legacy' && near($l['total'], 8000.0) && $l['gross'] === null && $l['ppn'] === null && $l['freight'] === null && $l['invoice_discount'] === null && $l['item_discount'] === null && $l['subtotal'] === null && $l['components_known'] === false;
})());

// ================================================================ invoice A
echo "\n== invoice A (3 rows: item discounts, PPN 11/11/0, invoice discount 5 %, freight 15.000) ==\n";
$a = $byRef['INV-A'];
$ea = $E['A'];
check('A: 3 items / 3 SKU; supplier / warehouse / created_by / timestamp real', $a['items'] === 3 && $a['skus'] === 3 && $a['supplier'] === 'PR Supplier Satu' && $a['warehouse'] === 'Gudang PR-SCM' && $a['created_by'] === $fx['admin']['username'] && $a['created_at'] !== null && $a['date'] === '2026-09-05');
check('K/L/M/P: gross 30.000, item discount 2.000, subtotal 28.000, PPN 2.090, invoice discount 1.504,5, freight 15.000, total 43.585,5 == hand-computed', near($a['gross'], $ea['gross']) && near($a['item_discount'], $ea['item_discount']) && near($a['subtotal'], $ea['subtotal']) && near($a['ppn'], $ea['ppn']) && near($a['invoice_discount'], $ea['invoice_discount']) && near($a['freight'], $ea['freight']) && near($a['total'], $ea['total']), json_encode([$a['subtotal'], $a['ppn'], $a['invoice_discount'], $a['freight'], $a['total']]));
check('identity: Subtotal Barang + PPN − Diskon Invoice + Ongkos Kirim == Total Pembelian (28.000 + 2.090 − 1.504,5 + 15.000 = 43.585,5)', near($a['subtotal'] + $a['ppn'] - $a['invoice_discount'] + $a['freight'], $a['total']));
check('identity: Gross − Diskon Barang == Subtotal Barang', near($a['gross'] - $a['item_discount'], $a['subtotal']));
check('M PPN: mixed rates shown as actual "0% / 11%" (never a hard-coded 11 %); PPN after the invoice discount 1.985,5; invoice discount on DPP basis 1.400', $a['ppn_rate'] === '0% / 11%' && near($a['ppn_net'], $ea['ppn_net']) && near($a['invoice_discount_dpp'], $ea['invoice_discount_dpp']));
check('freight treatment of A is EXPENSE (stored), of C is CAPITALIZE', $a['freight_treatment'] === 'EXPENSE' && $byRef['INV-C']['freight_treatment'] === 'CAPITALIZE');
$d = R::invoiceDetail($pdo, $fx['tx']['A']);
$rowsBySku = [];
foreach ($d['lines'] as $l) {
    $rowsBySku[$l['sku']] = $l;
}
foreach (['X', 'Y', 'Z'] as $k) {
    $e = $ea['rows'][$k];
    $l = $rowsBySku[$I[$k]['sku']];
    check("R/S row {$k}: gross / item discount / DPP / PPN / alloc. invoice discount / alloc. freight / row total == hand-computed", near($l['gross'], $e['gross']) && near($l['item_discount'], $e['item_discount']) && near($l['dpp'], $e['dpp']) && near($l['ppn'], $e['ppn']) && near($l['invoice_discount'], $e['invoice_discount']) && near($l['freight'], $e['freight']) && near($l['total'], $e['total']), json_encode([$l['invoice_discount'], $l['freight'], $l['total']]));
}
check('R Σ per-row freight allocation == invoice freight 15.000 (to storage precision)', near(array_sum(array_column($d['lines'], 'freight')), 15000.0, 0.0005));
check('S Σ per-row invoice discount allocation == invoice discount 1.504,5', near(array_sum(array_column($d['lines'], 'invoice_discount')), 1504.5, 0.0005));
check('freight allocation uses the SAME rule as Stock IN V2: proportional to net DPP (PurchaseCostingService::allocateProportionally over the stored net DPP)', (function () use ($d) {
    $net = array_map(static fn ($l) => $l['net_dpp'], $d['lines']);
    $re = PurchaseCostingService::allocateProportionally($net, 15000.0);
    foreach ($d['lines'] as $i => $l) {
        if (abs($re[$i] - $l['freight']) > 0.0002) { return false; }
    }
    return true;
})());
check('drawer arithmetic block: gross − item discount = subtotal; + PPN − invoice discount + freight = grand total == the stored invoice_total sum; sheet view subtotal incl. PPN 30.090', near($d['arithmetic']['grand_total'], $d['arithmetic']['stored_grand_total']) && near($d['arithmetic']['grand_total'], 43585.5) && near($d['arithmetic']['subtotal_incl_ppn'], 30090.0) && near($d['arithmetic']['inventory_cost'], 26600.0));
check('drawer: PPN treatment / freight treatment from stored data (CREDITABLE for the 11 % rows, NONE for the 0 % row; EXPENSE) and the invoice is the full invoice (3 lines)', $d['arithmetic']['ppn_treatments'] === ['CREDITABLE', 'NONE'] && $d['arithmetic']['freight_treatments'] === ['EXPENSE'] && count($d['lines']) === 3 && count($d['notes']) === 3);
check('inventory (FIFO) cost is NOT the invoice total: A inventory cost 26.600 = net DPP (creditable PPN excluded, expensed freight excluded) vs total 43.585,5', near($a['inventory_cost'], 26600.0) && !near($a['inventory_cost'], $a['total']));

// ================================================================ B / C
echo "\n== invoice B (custom PPN 5 %, no ref, no freight) and C (capitalised freight) ==\n";
$b = $noRef;
check('B: no invoice number is fabricated (reference "—", has_reference false)', $b !== null && $b['reference'] === '—' && $b['has_reference'] === false);
check('N/O B: custom PPN 5 %: subtotal 30.000, PPN 1.500, total 31.500; Q freight 0 stored as 0 (freight_treatment "Tanpa ongkir")', near($b['subtotal'], 30000.0) && near($b['ppn'], 1500.0) && near($b['total'], 31500.0) && $b['ppn_rate'] === '5%' && near($b['freight'], 0.0) && $b['freight_treatment'] === 'Tanpa ongkir' && $b['supplier'] === 'PR Supplier Dua');
$c = $byRef['INV-C'];
check('C: gross 54.000, subtotal 54.000, PPN 5.940, invoice discount ≈ 3.000, freight 6.000 → total ≈ 62.940; identity holds', near($c['gross'], 54000.0) && near($c['subtotal'], 54000.0) && near($c['ppn'], 5940.0) && near($c['invoice_discount'], 3000.0, 0.01) && near($c['freight'], 6000.0) && near($c['total'], 62940.0, 0.01) && near($c['subtotal'] + $c['ppn'] - $c['invoice_discount'] + $c['freight'], $c['total']));
check('C freight is CAPITALISED into FIFO cost: inventory cost = total − creditable PPN, > A\'s ratio', near($c['inventory_cost'], $c['total'] - $c['ppn_net'] + 0.0, 0.01) && $c['inventory_cost'] > $c['subtotal']);

// ================================================================ KPI
echo "\n== KPI ==\n";
check('KPI Subtotal Barang 112.000, Diskon Barang 2.000, Diskon Invoice 4.504,5, Total Diskon 6.504,5, PPN 9.530, Ongkos Kirim 21.000, Gross 114.000', near($n['subtotal'], 112000.0) && near($n['item_discount'], 2000.0) && near($n['invoice_discount'], 4504.5, 0.01) && near($n['discount'], 6504.5, 0.01) && near($n['ppn'], 9530.0) && near($n['freight'], 21000.0) && near($n['gross'], 114000.0), json_encode([$n['subtotal'], $n['invoice_discount'], $n['ppn'], $n['freight']]));
check('KPI identity over V2: Subtotal + PPN − Diskon Invoice + Ongkir == V2 total 138.025,5', near($n['subtotal'] + $n['ppn'] - $n['invoice_discount'] + $n['freight'], $n['sources']['V2']['total'], 0.01));
check('KPI counts: 4 invoices (A, B, C, legacy), 7 posted rows, 5 SKU, 2 suppliers (legacy has none); PPN rates shown as actual {0%, 5%, 11%}', $n['invoices'] === 4 && $n['lines'] === 7 && $n['skus'] === 5 && $n['suppliers'] === 2 && $n['ppn_rates'] === ['0%', '5%', '11%']);
$q = $ov['kpi']['qty_by_unit'];
$qty = array_column($q, 'qty', 'unit');
check('I Qty per UNIT, never mixed: KG 135 (Y 15 + W 100 + L 20), LTR 20, PCS 40 — three separate figures', count($q) === 3 && near($qty['KG'], 135.0) && near($qty['LTR'], 20.0) && near($qty['PCS'], 40.0));
check('footer of the invoice table == KPI (gross / discounts / PPN / freight / total)', near($inv['footer']['totals']['total'], $n['total']) && near($inv['footer']['totals']['ppn'], $n['ppn']) && near($inv['footer']['totals']['freight'], $n['freight']) && near($inv['footer']['totals']['invoice_discount'], $n['invoice_discount']) && near($inv['footer']['totals']['item_discount'], $n['item_discount']) && $inv['footer']['incomplete_components'] === 1, json_encode($inv['footer']['incomplete_components']));

// ================================================================ filters
echo "\n== filters ==\n";
$wh2 = R::invoices($pdo, $base + ['warehouse_id' => $fx['wh']['2']]);
check('C warehouse filter WH2 → only invoice B', count($wh2['rows']) === 1 && near($wh2['rows'][0]['total'], $E['B']['total']));
$sup2 = R::invoices($pdo, $base + ['supplier_id' => $fx['sup']['2']]);
check('D supplier filter S2 → only invoice B; S1 → A, C and the voided one', count($sup2['rows']) === 1 && count(R::invoices($pdo, $base + ['supplier_id' => $fx['sup']['1']])['rows']) === 3);
$d1 = R::invoices($pdo, ['start_date' => '2026-09-10', 'end_date' => '2026-09-15', 'per_page' => 100]);
check('B date filter 10–15 Sep → B (12 Sep) and the legacy purchase (15 Sep) only', count($d1['rows']) === 2 && near($d1['footer']['totals']['total'], 31500.0 + 8000.0));
$cat1 = R::overview($pdo, $base + ['category_id' => $fx['cat']['1']]);
check('E category filter "PR Roti" (item X only): total = X rows of A and C; invoices flagged PARTIAL (matched rows < all rows)', near($cat1['kpi']['nominal']['total'], 14311.9286 + 36630.0 + 0.0, 4000.0) && $cat1['partial_by_item_filter'] === true && $cat1['single_item']['sku'] === $I['X']['sku']);
$catInv = R::invoices($pdo, $base + ['category_id' => $fx['cat']['1']]);
check('E partial invoices: A shows 1 of 3 rows, C 1 of 2; their totals are the X rows only (14.311,93 / ~36.6xx) and the footer == Σ rows', (function () use ($catInv) {
    $m = [];
    foreach ($catInv['rows'] as $r) { $m[$r['reference']] = $r; }
    return count($m) === 2 && $m['INV-A']['partial'] === true && $m['INV-A']['lines_total'] === 3 && $m['INV-A']['items'] === 1 && near($m['INV-A']['total'], 14311.9286) && $m['INV-C']['lines_total'] === 2 && $m['INV-C']['items'] === 1
        && near($catInv['footer']['totals']['total'], $m['INV-A']['total'] + $m['INV-C']['total']);
})());
$srch = R::invoices($pdo, $base + ['q' => $I['Z']['sku']]);
check('F item search by SKU (Z) → only invoice A, one row', count($srch['rows']) === 1 && $srch['rows'][0]['reference'] === 'INV-A' && $srch['rows'][0]['items'] === 1);
check('F item search by NAME fragment works too; unknown text → empty', count(R::invoices($pdo, $base + ['q' => substr($I['W']['name'], 5)])['rows']) === 1 && R::invoices($pdo, $base + ['q' => 'zzzz-none'])['rows'] === []);
check('invoice table search (reference / supplier): "INV-C" → 1; "Dua" → B', count(R::invoices($pdo, $base + ['inv_q' => 'INV-C'])['rows']) === 1 && count(R::invoices($pdo, $base + ['inv_q' => 'Dua'])['rows']) === 1);
check('invalid period → ValidationException; > 800 days refused', (function () use ($pdo) {
    $bad = 0;
    foreach ([['start_date' => '2026-09-30', 'end_date' => '2026-09-01'], ['start_date' => '2020-01-01', 'end_date' => '2026-09-01']] as $f) {
        try { R::overview($pdo, $f); } catch (\App\Services\ValidationException $e) { $bad++; }
    }
    return $bad === 2;
})());

// ================================================================ item breakdown
echo "\n== Rincian per Barang ==\n";
$it = R::items($pdo, $base);
$items = [];
foreach ($it['rows'] as $r) { $items[$r['sku']] = $r; }
$x = $items[$I['X']['sku']];
check('T/U item X: bought twice at DIFFERENT prices (1.000 on 5 Sep, 1.100 on 20 Sep): min 1.000, max 1.100, weighted avg (10x1000 + 30x1100)/40 = 1.075 — NOT the simple 1.050; frequency 2; qty 40 PCS', near($x['price_min'], 1000.0) && near($x['price_max'], 1100.0) && near($x['price_avg'], 1075.0, 0.0001) && !near($x['price_avg'], 1050.0) && $x['frequency'] === 2 && near($x['qty'], 40.0) && $x['unit'] === 'PCS');
$y = $items[$I['Y']['sku']];
check('T item Y: weighted avg (5x2000 + 10x2100)/15 = 2.066,6667, qty 15 KG (the voided 4 KG is NOT counted)', near($y['price_avg'], 2066.6667, 0.0001) && near($y['qty'], 15.0) && $y['frequency'] === 2);
check('item X totals: gross 43.000, PPN 4.620, freight allocation, total = A row + C row', near($x['gross'], 43000.0) && near($x['ppn'], 990.0 + 3630.0) && near($x['total'], 14311.9286 + ($x['total'] - 14311.9286)));
check('Rincian: Σ item totals == KPI total (146.025,5); Σ item freight allocation == Σ invoice freight 21.000; Σ item invoice-discount allocation == 4.504,5; Σ item PPN == 9.530', near($it['footer']['totals']['total'], $n['total']) && near($it['footer']['totals']['freight'], 21000.0) && near($it['footer']['totals']['invoice_discount'], $n['invoice_discount'], 0.01) && near($it['footer']['totals']['ppn'], 9530.0) && near($it['footer']['totals']['item_discount'], 2000.0));
check('Rincian: % terhadap total sums to 100 and each item share = total / grand', near(array_sum(array_column($it['rows'], 'share_pct')), 100.0, 0.06) && near($x['share_pct'], round($x['total'] * 100 / $n['total'], 2), 0.01));
$legacyItem = $items[$I['L']['sku']];
check('legacy-only item: price = the recorded unit price 400, Gross/Discount/PPN/Freight null ("—"), total 8.000, flagged incomplete', near($legacyItem['price_avg'], 400.0) && $legacyItem['gross'] === null && $legacyItem['ppn'] === null && $legacyItem['freight'] === null && near($legacyItem['total'], 8000.0) && $legacyItem['components_complete'] === false);
check('Rincian footer: quantities grouped BY UNIT (KG / LTR / PCS), never one total', array_column($it['footer']['qty_by_unit'], 'unit') === ['KG', 'LTR', 'PCS']);
check('historical price preserved: the report price of an invoice line is the TRANSACTION price, independent of item_price_history (latest price differs from both)', (function () use ($pdo, $I) {
    $latest = (float) $pdo->query("SELECT price_per_unit FROM item_price_history WHERE item_id = {$I['X']['id']} ORDER BY effective_date DESC, id DESC LIMIT 1")->fetchColumn();
    return $latest > 0.0;
})());

// ================================================================ trend
echo "\n== trend ==\n";
$tDay = $ov['trend'];
check('trend (day): 30 continuous buckets; Σ bucket totals == KPI total; Σ invoices == 4; 5 Sep bucket = A', count($tDay) === 30 && near(array_sum(array_column($tDay, 'total')), $n['total']) && array_sum(array_column($tDay, 'invoices')) === 4 && near($tDay[4]['total'], 43585.5) && $tDay[4]['invoices'] === 1 && near($tDay[4]['ppn'], 2090.0));
check('trend: a day without purchases is a KNOWN zero (not null) — and the voided 25 Sep invoice adds nothing', near($tDay[0]['total'], 0.0) && near($tDay[24]['total'], 0.0));
$tw = R::overview($pdo, $base + ['bucket' => 'week'])['trend'];
$tm = R::overview($pdo, $base + ['bucket' => 'month'])['trend'];
check('trend (week): ISO weeks starting Monday, Σ == KPI total; (month): one bucket 2026-09 == KPI total', near(array_sum(array_column($tw, 'total')), $n['total']) && date('N', strtotime($tw[0]['bucket'])) === '1' && count($tm) === 1 && $tm[0]['bucket'] === '2026-09' && near($tm[0]['total'], $n['total']));
$one = R::overview($pdo, $base + ['item_id' => $I['X']['id']]);
check('single item (X): single_item set; qty series (PCS) 10 on 5 Sep + 30 on 20 Sep = 40; otherwise qty is null (no mixed-unit chart)', $one['single_item']['sku'] === $I['X']['sku'] && near($one['trend'][4]['qty'], 10.0) && near($one['trend'][19]['qty'], 30.0) && near(array_sum(array_column($one['trend'], 'qty')), 40.0) && $ov['trend'][4]['qty'] === null && $ov['single_item'] === null);

// ================================================================ drawer / detail
echo "\n== invoice detail ==\n";
$dd = R::invoiceDetail($pdo, $fx['tx']['D']);
check('detail of the VOID invoice: status VOID, line counted=false, arithmetic block absent (nothing counted)', $dd['invoice']['status'] === 'VOID' && $dd['lines'][0]['counted'] === false && $dd['arithmetic'] === null);
$dl = R::invoiceDetail($pdo, $fx['tx']['L']);
check('detail of the legacy purchase: components null, total = recorded value, arithmetic null (unknown stays unknown)', $dl['invoice']['source'] === 'Legacy' && $dl['arithmetic'] === null && near($dl['invoice']['total'], 8000.0) && $dl['lines'][0]['ppn'] === null);
try { R::invoiceDetail($pdo, [999999999]); check('detail of an unknown id → NotFound', false); } catch (\App\Services\NotFoundException $e) { check('detail of an unknown id → NotFound', true); }

// ================================================================ export == screen
echo "\n== export ==\n";
$lbl = static fn (array $cols) => array_map(static fn ($c) => $c[1], $cols);
$tInv = R::exportTable($pdo, 'invoices', $base);
$tItem = R::exportTable($pdo, 'items', $base);
$tLines = R::exportTable($pdo, 'lines', $base);
check('X export invoices: headers == the screen catalogue, one row per invoice + a TOTAL row', $tInv['headers'] === $lbl(R::INVOICE_COLUMNS) && count($tInv['rows']) === count($inv['rows']) + 1 && $tInv['rows'][count($inv['rows'])][0] === 'TOTAL');
$ti = array_search('Total Pembelian', $tInv['headers'], true);
check('X export invoices: TOTAL row Total Pembelian == screen footer == KPI (146.025,5); PPN / Ongkos Kirim / Diskon Invoice / Diskon Barang totals equal the cards', near((float) $tInv['rows'][count($inv['rows'])][$ti], $n['total']) && near((float) $tInv['rows'][count($inv['rows'])][array_search('PPN', $tInv['headers'], true)], $n['ppn']) && near((float) $tInv['rows'][count($inv['rows'])][array_search('Ongkos Kirim', $tInv['headers'], true)], $n['freight']) && near((float) $tInv['rows'][count($inv['rows'])][array_search('Diskon Invoice', $tInv['headers'], true)], $n['invoice_discount'], 0.01) && near((float) $tInv['rows'][count($inv['rows'])][array_search('Diskon Barang', $tInv['headers'], true)], $n['item_discount']));
check('X export items: headers == catalogue, TOTAL row == Rincian footer == KPI; unknown cells "—"', $tItem['headers'] === $lbl(R::ITEM_COLUMNS) && near((float) $tItem['rows'][count($it['rows'])][array_search('Total Pembelian', $tItem['headers'], true)], $n['total']) && in_array('—', $tItem['rows'][array_search($I['L']['sku'], array_column($tItem['rows'], 0), true)], true));
check('X export lines: one row per posted+void row matched (8: 7 posted + 1 void) + TOTAL (POSTED); PPN %, allocations, status present', $tLines['headers'] === $lbl(R::LINE_COLUMNS) && count($tLines['rows']) === 8 + 1 && near((float) $tLines['rows'][8][array_search('Total Baris', $tLines['headers'], true)], $n['total']));
$wb = R::exportWorkbook($pdo, $base, ['Laporan' => 'Laporan Pembelian']);
check('workbook: sheets Ringkasan / Detail Invoice / Detail Barang / Baris Invoice-Barang; Ringkasan carries the KPI values (equal to the cards) and Qty per unit', array_keys($wb) === ['Ringkasan', 'Detail Invoice', 'Detail Barang', 'Baris Invoice-Barang'] && (function () use ($wb, $n) {
    $m = [];
    foreach ($wb['Ringkasan']['rows'] as $r) { $m[$r[0]] = $r[1]; }
    return near((float) $m['Total Nilai Pembelian'], $n['total']) && near((float) $m['PPN'], $n['ppn']) && near((float) $m['Ongkos Kirim'], $n['freight']) && near((float) $m['Subtotal Barang'], $n['subtotal']) && isset($m['Qty dibeli — KG']) && near((float) $m['Qty dibeli — KG'], 135.0);
})());
$xl = sys_get_temp_dir() . '/pur_test_' . bin2hex(random_bytes(3)) . '.xlsx';
\App\Services\ExcelWriterService::write($xl, $wb);
check('workbook writes a real .xlsx (4 worksheets)', (function () use ($xl) { $z = new ZipArchive(); $ok = $z->open($xl) === true && $z->locateName('xl/worksheets/sheet4.xml') !== false && $z->locateName('xl/worksheets/sheet5.xml') === false; $z->close(); @unlink($xl); return $ok; })());
check('export honours the filters (warehouse WH2 → 1 invoice + TOTAL)', count(R::exportTable($pdo, 'invoices', $base + ['warehouse_id' => $fx['wh']['2']])['rows']) === 2);

// ================================================================ read-only
echo "\n== read-only ==\n";
check('[read-only] D: no purchase / stock / FIFO / price-history / audit table changed (counts + checksums identical)', snap($pdo) === $before);
check('[read-only] the service source contains no INSERT/UPDATE/DELETE statement', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(__DIR__ . '/../services/PurchaseReportService.php'))));

// ================================================================ HTTP
echo "\n== HTTP ==\n";
$port = 8900 + random_int(1200, 1599);
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
    $jar = tempnam(sys_get_temp_dir(), 'pr_');
    $r = http('POST', "{$baseUrl}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($baseUrl, $fx['viewer']);
    $stock2 = loginAs($baseUrl, $fx['stock2']);
    $admin = loginAs($baseUrl, $fx['admin']);
    check('logins succeed', $viewer['status'] === 200 && $stock2['status'] === 200 && $admin['status'] === 200);
    $qs = 'start_date=2026-09-01&end_date=2026-09-30';
    $r = http('GET', "{$baseUrl}/reports/purchase-v2/overview?{$qs}", null, $viewer['jar']);
    check('VIEWER (INVENTORY_VIEW only) → overview 200: kpi + trend + disclosures', $r['status'] === 200 && isset($r['body']['data']['kpi']['nominal']['total'], $r['body']['data']['trend']) && near((float) $r['body']['data']['kpi']['nominal']['total'], $E['live_total']), (string) $r['status']);
    check('unauthenticated → 401; missing / reversed dates → 422', http('GET', "{$baseUrl}/reports/purchase-v2/overview?{$qs}")['status'] === 401 && http('GET', "{$baseUrl}/reports/purchase-v2/overview", null, $viewer['jar'])['status'] === 422 && http('GET', "{$baseUrl}/reports/purchase-v2/overview?start_date=2026-09-30&end_date=2026-09-01", null, $viewer['jar'])['status'] === 422);
    $ri = http('GET', "{$baseUrl}/reports/purchase-v2/invoices?{$qs}&warehouse_id={$fx['wh']['1']}", null, $stock2['jar']);
    check('Y warehouse permission: a STOCK user of WH2 asking for WH1 through the query string gets ONLY WH2 (invoice B, Rp 31.500)', $ri['status'] === 200 && count($ri['body']['data']['rows']) === 1 && near((float) $ri['body']['data']['footer']['totals']['total'], 31500.0), json_encode($ri['body']['data']['footer']['totals'] ?? null));
    $ro = http('GET', "{$baseUrl}/reports/purchase-v2/overview?{$qs}&warehouse_id={$fx['wh']['1']}", null, $stock2['jar']);
    check('Y the same user\'s overview/items are WH2-only too (total 31.500); supplier/category filters cannot widen it', near((float) ($ro['body']['data']['kpi']['nominal']['total'] ?? -1), 31500.0) && near((float) (http('GET', "{$baseUrl}/reports/purchase-v2/items?{$qs}&supplier_id={$fx['sup']['1']}", null, $stock2['jar'])['body']['data']['footer']['totals']['total'] ?? -1), 0.0));
    $ids1 = implode(',', $fx['tx']['A']);
    check('invoice-detail of a WH1 invoice for the WH2 user → 403; for the viewer → 200 with arithmetic', http('GET', "{$baseUrl}/reports/purchase-v2/invoice-detail?tx_ids={$ids1}", null, $stock2['jar'])['status'] === 403 && ($d = http('GET', "{$baseUrl}/reports/purchase-v2/invoice-detail?tx_ids={$ids1}", null, $viewer['jar']))['status'] === 200 && near((float) $d['body']['data']['arithmetic']['grand_total'], 43585.5));
    check('invoice-detail: missing ids → 422; unknown id → 404; a NON-purchase transaction id (void reversal / OUT) is not served', http('GET', "{$baseUrl}/reports/purchase-v2/invoice-detail", null, $viewer['jar'])['status'] === 422 && http('GET', "{$baseUrl}/reports/purchase-v2/invoice-detail?tx_ids=999999999", null, $viewer['jar'])['status'] === 404
        && http('GET', "{$baseUrl}/reports/purchase-v2/invoice-detail?tx_ids=" . (int) $pdo->query("SELECT id FROM inventory_transactions WHERE transaction_type <> 'IN' LIMIT 1")->fetchColumn(), null, $viewer['jar'])['status'] === 404);
    $x = http('GET', "{$baseUrl}/reports/purchase-v2/export?{$qs}&kind=invoices", null, $viewer['jar'], null, true);
    $lines = array_values(array_filter(explode("\n", trim(ltrim($x['raw'], "\xEF\xBB\xBF")))));
    check('CSV invoices over HTTP: 200 text/csv, header == catalogue, rows + TOTAL, TOTAL cell == total', $x['status'] === 200 && stripos($x['headers'], 'text/csv') !== false && str_getcsv($lines[0], ',', '"', '\\') === $lbl(R::INVOICE_COLUMNS) && count($lines) === 1 + 5 + 1 && str_contains(end($lines), '146025.5'), (string) count($lines));
    $x = http('GET', "{$baseUrl}/reports/purchase-v2/export?{$qs}&kind=workbook", null, $viewer['jar'], null, true);
    check('workbook over HTTP: 200 xlsx, valid zip with 4 sheets', $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml') !== false && (function () use ($x) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName('xl/worksheets/sheet4.xml') !== false; $z->close(); @unlink($f); return $ok; })());
    check('export: WH2 user gets only WH2 rows; unknown kind → 422', count(array_filter(explode("\n", trim(http('GET', "{$baseUrl}/reports/purchase-v2/export?{$qs}&kind=invoices&warehouse_id={$fx['wh']['1']}", null, $stock2['jar'], null, true)['raw'])))) === 1 + 1 + 1 && http('GET', "{$baseUrl}/reports/purchase-v2/export?{$qs}&kind=nope", null, $viewer['jar'], null, true)['status'] === 422);
    check('existing routes unaffected: GET /reports/purchase 200, /reports/purchase/summary 200, /reports/purchase/by-supplier 200', http('GET', "{$baseUrl}/reports/purchase", null, $admin['jar'])['status'] === 200 && http('GET', "{$baseUrl}/reports/purchase/summary", null, $admin['jar'])['status'] === 200 && http('GET', "{$baseUrl}/reports/purchase/by-supplier", null, $admin['jar'])['status'] === 200);
    $beforeHttp = snap($pdo);
    $writeOk = true;
    foreach (['overview', 'invoices', 'items', 'invoice-detail', 'export'] as $route) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (http($verb, "{$baseUrl}/reports/purchase-v2/{$route}", [], $admin['jar'], $admin['csrf'])['status'] !== 404) { $writeOk = false; }
        }
    }
    check('AA POST / PUT / PATCH / DELETE on every report route → 404 (no write route exists)', $writeOk);
    check('HTTP reads + rejected write verbs changed nothing in the database', snap($pdo) === $beforeHttp);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
