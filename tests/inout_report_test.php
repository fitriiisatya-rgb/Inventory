<?php
declare(strict_types=1);

/**
 * "Laporan IN / OUT / Transfer" — InOutReportService + GET /reports/io/*, on REAL postings made through the application's own services (tests/lib/inout_report_fixture.php).
 * HPP / cost expectations are read from the stored cost of the layers consumed; selling prices are recomputed from the stored invoice-line reference price + markup.
 *
 *   A tab IN · B tab OUT · C tab Transfer · D date · E warehouse · F category · G item search · H supplier · I bakery · J transfer status/direction
 *   K IN arithmetic · L OUT FIFO HPP · M OUT invoice total · N transfer cost · O transfer pending · P transfer received · Q timestamps · R actors
 *   S export · T permissions · U no write request · V/W browser (separate Playwright test) · X unknown / empty / validation · Y reconciliation
 *
 * Usage: php tests/inout_report_test.php
 */

require_once __DIR__ . '/lib/inout_report_fixture.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\InOutReportService as S;
use App\Services\PurchaseReportService as P;
use App\Services\ValidationException;

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
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'item_price_history', 'warehouse_transfers', 'warehouse_transfer_lines', 'audit_logs', 'purchase_line_costs',
        'purchase_invoice_headers', 'distribution_orders', 'distribution_order_lines', 'distribution_invoices', 'distribution_invoice_lines'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}
/** @param list<array<string,mixed>> $rows */
function rowBy(array $rows, string $key, mixed $val): ?array
{
    foreach ($rows as $r) {
        if (($r[$key] ?? null) === $val) {
            return $r;
        }
    }
    return null;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
$fx = inout_build_fixture($pdo);
$E = $fx['expect'];
$IE = $fx['io_expect'];
$W1 = $fx['wh']['1'];
$W2 = $fx['wh']['2'];
$base = ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'per_page' => 100];
$before = snap($pdo);
$admin = $fx['admin']['username'];

// ================================================================ A / K. tab IN
echo "== A / K. tab IN ==\n";
$in = S::inOverview($pdo, $base);
$n = $in['kpi']['nominal'];
check('A KPI: Total Nilai Masuk = Rp 146.025,5 (A + B + C V2 + legacy 8.000); void D and historical H excluded', near($n['total'], $E['live_total']), (string) $n['total']);
check('A KPI: 4 transaksi (A, B, C, legacy), 5 SKU, 2 supplier; avg per transaksi = total / 4', $n['invoices'] === 4 && $n['skus'] === 5 && $n['suppliers'] === 2 && near($n['avg_invoice'], $E['live_total'] / 4));
check('K KPI components = hand-computed: subtotal 112.000, diskon barang 2.000, diskon invoice 4.504,5, PPN 9.530, ongkir 21.000', near($n['subtotal'], 112000) && near($n['item_discount'], 2000) && near($n['invoice_discount'], 4504.5) && near($n['ppn'], 9530) && near($n['freight'], 21000));
check('A void invoice D is disclosed (1 invoice, Rp 4.440) and not counted', $in['disclosures']['void']['invoices'] === 1 && near($in['disclosures']['void']['amount'], $E['D_total']));
$qty = array_column($in['kpi']['qty_by_unit'], 'qty', 'unit');
check('A quantities are never summed across units: KG 135, LTR 20, PCS 40', near($qty['KG'] ?? null, 135) && near($qty['LTR'] ?? null, 20) && near($qty['PCS'] ?? null, 40) && count($qty) === 3);
$il = S::inList($pdo, $base);
$rA = rowBy($il['rows'], 'reference', 'INV-A');
check('K invoice INV-A: gross 30.000, diskon barang 2.000, subtotal 28.000, PPN 2.090, diskon invoice 1.504,5, ongkir 15.000, grand total 43.585,5', $rA !== null && near($rA['gross'], 30000) && near($rA['item_discount'], 2000) && near($rA['subtotal'], 28000) && near($rA['ppn'], 2090) && near($rA['invoice_discount'], 1504.5) && near($rA['freight'], 15000) && near($rA['total'], 43585.5));
check('K Grand Total = Subtotal + PPN − Diskon Invoice + Ongkir for INV-A and INV-C', near($rA['subtotal'] + $rA['ppn'] - $rA['invoice_discount'] + $rA['freight'], $rA['total']) && near(rowBy($il['rows'], 'reference', 'INV-C')['total'], 62940));
check('A row shows items / SKU, qty per unit text (no mixed sum), supplier, warehouse, creator and posting timestamp', $rA['items'] === 3 && $rA['skus'] === 3 && $rA['qty_text'] === 'KG 5 · LTR 20 · PCS 10' && $rA['supplier'] === 'PR Supplier Satu' && $rA['warehouse'] === 'Gudang PR-SCM' && $rA['created_by'] === $admin && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $rA['posted_at']) === 1);
$rB = rowBy($il['rows'], 'supplier', 'PR Supplier Dua');
check('A invoice without reference shows "—" (never an invented number)', $rB !== null && $rB['reference'] === '—');
$rL = rowBy($il['rows'], 'reference', 'PO-LEGACY');
check('A legacy purchase: total = recorded value 8.000, components UNKNOWN (null → "—"), not a fabricated 0', $rL !== null && near($rL['total'], 8000) && $rL['subtotal'] === null && $rL['ppn'] === null && $rL['freight'] === null && $rL['source'] === 'Legacy');
check('A historical import (PO-HIST) never appears by default', rowBy($il['rows'], 'reference', 'PO-HIST') === null);
$h = S::inList($pdo, $base + ['in_source' => 'Historis']);
check('A Jenis IN = Historis shows ONLY the historical import, labelled', count($h['rows']) === 1 && $h['rows'][0]['reference'] === 'PO-HIST' && $h['rows'][0]['source'] === 'Historis');
check('A Jenis IN = V2 → 4 rows (A, B, C + the voided D, listed); Legacy → 1 invoice', count(S::inList($pdo, $base + ['in_source' => 'V2'])['rows']) === 4 && count(S::inList($pdo, $base + ['in_source' => 'Legacy'])['rows']) === 1);
$sv = S::inList($pdo, $base + ['status' => 'VOID']);
check('A status VOID → only the voided invoice, listed with total 0 (voided value kept apart)', count($sv['rows']) === 1 && $sv['rows'][0]['reference'] === 'INV-VOID' && near($sv['rows'][0]['total'], 0) && near($sv['rows'][0]['voided_total'], 4440));
$sp = S::inList($pdo, $base + ['status' => 'POSTED']);
check('A status POSTED → void invoice absent', rowBy($sp['rows'], 'reference', 'INV-VOID') === null && count($sp['rows']) === 4);
check('A footer totals = KPI (Rp 146.025,5)', near($il['footer']['totals']['total'], $n['total']) && near($il['footer']['totals']['subtotal'], 112000));
$d = S::inDetail($pdo, $fx['tx']['A'], null);
$dx = rowBy($d['lines'], 'sku', $fx['items']['X']['sku']);
check('K item detail INV-A: X row — harga beli 1.000, diskon item 1.000, DPP 9.000, PPN 990, alokasi diskon 499,5, alokasi ongkir 4.821,4286, total 14.311,9286', near($dx['price'], 1000) && near($dx['item_discount'], 1000) && near($dx['dpp'], 9000) && near($dx['ppn'], 990) && near($dx['invoice_discount'], 499.5) && near($dx['freight'], 4821.4286, 0.001) && near($dx['total'], 14311.9286, 0.001));
check('K item detail: real inventory cost (Stock IN V2 costing) present, petugas + timestamp + catatan columns, arithmetic block grand total = stored', $dx['inventory_cost'] !== null && $dx['created_by'] === $admin && $dx['created_at'] !== null && array_key_exists('notes', $dx) && near($d['arithmetic']['grand_total'], $d['arithmetic']['stored_grand_total']) && near($d['arithmetic']['grand_total'], 43585.5));
$pr = P::overview($pdo, ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'])['kpi']['nominal'];
check('K cross-check: IN tab totals equal Laporan Pembelian (total, PPN, ongkir, diskon invoice, subtotal)', near($n['total'], $pr['total']) && near($n['ppn'], $pr['ppn']) && near($n['freight'], $pr['freight']) && near($n['invoice_discount'], $pr['invoice_discount']) && near($n['subtotal'], $pr['subtotal']));
$tr = $in['trend'];
check('A trend: 30 daily buckets, Σ nominal = KPI total, Σ count = invoices', count($tr) === 30 && near(array_sum(array_column($tr, 'total')), $n['total']) && array_sum(array_column($tr, 'count')) === 4);
$trm = S::inOverview($pdo, $base + ['bucket' => 'month'])['trend'];
$trw = S::inOverview($pdo, $base + ['bucket' => 'week'])['trend'];
check('A trend Bulanan = 1 bucket, Mingguan = Σ equal; both equal the KPI total', count($trm) === 1 && near($trm[0]['total'], $n['total']) && near(array_sum(array_column($trw, 'total')), $n['total']) && count($trw) >= 4);
check('A previous period is the equally long period before; its total is 0 so the % change is NULL (never invented)', $in['period']['previous']['end'] === '2026-08-31' && near($in['previous_kpi']['nominal']['total'], 0) && $in['delta']['total']['pct'] === null);
$oct = S::inOverview($pdo, ['start_date' => '2026-09-16', 'end_date' => '2026-09-30'] + $base);
check('A previous period with data: 09-01..09-15 holds A + B + legacy; the % change is computed from it', $oct['delta']['total']['previous'] > 0 && $oct['delta']['total']['pct'] !== null);

// ================================================================ B / L / M. tab OUT
echo "\n== B / L / M. tab OUT ==\n";
$out = S::outOverview($pdo, $base);
$no = $out['kpi']['nominal'];
$O1 = $fx['out']['O1'];
$O2 = $fx['out']['O2'];
$O3 = $fx['out']['O3'];
$stored = static function (int $invoiceId) use ($pdo): array {
    $st = $pdo->prepare('SELECT il.*, i.sku FROM distribution_invoice_lines il JOIN items i ON i.id = il.item_id WHERE il.invoice_id = :i ORDER BY il.line_no');
    $st->execute(['i' => $invoiceId]);
    return $st->fetchAll();
};
$sellOf = static function (array $l): float {
    $ref = (float) $l['reference_purchase_price'];
    $price = $l['pricing_method'] === 'COST_PLUS_PERCENT' ? round($ref * (1 + (float) $l['margin_value'] / 100), 4) : round($ref + (float) $l['margin_value'], 4);
    return round((float) $l['qty'] * $price, 4);
};
$s1 = array_sum(array_map($sellOf, $stored((int) $O1['invoice_id'])));
$s2 = array_sum(array_map($sellOf, $stored((int) $O2['invoice_id'])));
$expHpp = $IE['hpp_total'];
check('B KPI HPP Keluar = Σ stored FIFO cost of the consumed layers (OUT1 + OUT2 + legacy)', near($no['hpp'], $expHpp, 0.01), "{$no['hpp']} vs {$expHpp}");
check('B KPI Nilai Jual = Σ invoice lines recomputed from the STORED reference price + markup (OUT1 + OUT2)', near($no['sell'], $s1 + $s2, 0.01), "{$no['sell']} vs " . ($s1 + $s2));
check('B KPI Ongkir = 10.000 (OUT1) + 0 (OUT2); Total Invoice = Nilai Jual + Ongkir', near($no['shipping'], 10000) && near($no['grand_total'], $s1 + $s2 + 10000, 0.01));
check('B KPI Margin = Nilai Jual − HPP of the priced lines; legacy OUT HPP 2.000 is kept apart as "tanpa nilai jual"', near($no['margin'], $s1 + $s2 - ($IE['O1']['hpp_x'] + $IE['O1']['hpp_y'] + $IE['O2']['hpp_z'] + $IE['O2']['hpp_x']), 0.01) && near($no['hpp_without_sell'], 2000));
check('B KPI: 3 transaksi (O1, O2, legacy), 4 SKU, 2 bakery; reversed O3 not counted', $no['documents'] === 3 && $no['skus'] === 4 && $no['bakeries'] === 2 && $out['disclosures']['void']['documents'] === 1);
$oqty = array_column($out['kpi']['qty_by_unit'], 'qty', 'unit');
check('B quantities per unit: KG 8 (3 + legacy 5), LTR 5, PCS 16 (12 + 4)', near($oqty['KG'] ?? null, 8) && near($oqty['LTR'] ?? null, 5) && near($oqty['PCS'] ?? null, 16));
$ol = S::outList($pdo, $base);
$d1 = rowBy($ol['rows'], 'do_number', $O1['do_number']);
check('B row O1: DO + invoice numbers are the real ones, gudang asal, bakery tujuan, 2 items / 2 SKU, status DISPATCHED', $d1 !== null && $d1['invoice_number'] === $O1['invoice_number'] && $d1['warehouse'] === 'Gudang PR-SCM' && $d1['bakery'] === 'IO Bakery Satu' && $d1['items'] === 2 && $d1['skus'] === 2 && $d1['status'] === 'DISPATCHED');
check('L O1 HPP Total = 10 × cost(A·X) + 2 × cost(C·X) + 3 × cost(A·Y) (X spans TWO FIFO layers)', near($d1['hpp'], $IE['O1']['hpp_x'] + $IE['O1']['hpp_y'], 0.01), "{$d1['hpp']}");
check('M O1 Nilai Jual = Σ stored lines; Ongkir 10.000; Grand Total = invoice grand_total stored; Margin = Nilai Jual − HPP', near($d1['sell'], $s1, 0.01) && near($d1['shipping'], 10000) && near($d1['grand_total'], (float) $pdo->query("SELECT grand_total FROM distribution_invoices WHERE id = " . (int) $O1['invoice_id'])->fetchColumn(), 0.01) && near($d1['margin'], $s1 - $d1['hpp'], 0.01));
check('M margin % = Margin / Nilai Jual', near($d1['margin_pct'], round(($s1 - $d1['hpp']) * 100 / $s1, 2), 0.01));
check('B dispatched-by actor + created-by + qty per unit text (no mixed sum)', $d1['created_by'] === $admin && $d1['dispatched_by'] === $admin && $d1['qty_text'] === 'KG 3 · PCS 12' && $d1['dispatched_at'] !== null);
$d3 = rowBy($ol['rows'], 'do_number', $O3['do_number']);
check('B reversed O3: listed, DO status CANCELLED, never counted (items 0, HPP 0), voided HPP kept apart', $d3 !== null && $d3['status'] === 'CANCELLED' && $d3['items'] === 0 && near($d3['hpp'], 0) && $d3['voided_hpp'] > 0);
$dl = rowBy($ol['rows'], 'source', 'Legacy');
check('B legacy OUT (no DO / invoice): "—" for DO + invoice, HPP 2.000 real, Nilai Jual / Margin UNKNOWN (null), not 0', $dl !== null && $dl['do_number'] === '—' && $dl['invoice_number'] === '—' && near($dl['hpp'], 2000) && $dl['sell'] === null && $dl['margin'] === null && $dl['grand_total'] === null);
check('B footer totals = KPI (HPP + Nilai Jual)', near($ol['footer']['totals']['hpp'], $no['hpp']) && near($ol['footer']['totals']['sell'], $no['sell']) && near($ol['footer']['totals']['shipping'], $no['shipping']));
$od = S::outDetail($pdo, (int) $O1['do_id'], null, null);
$lx = rowBy($od['lines'], 'sku', $fx['items']['X']['sku']);
$ly = rowBy($od['lines'], 'sku', $fx['items']['Y']['sku']);
$stRows = $stored((int) $O1['invoice_id']);
check('L item detail X: Harga Modal Referensi = stored reference price; HPP FIFO Aktual per satuan = HPP / qty; markup Persen 10; Harga Jual = ref × 1,10', near($lx['reference_price'], (float) $stRows[0]['reference_purchase_price'], 0.0001) && near($lx['hpp_unit'], $lx['hpp'] / 12, 0.0001) && $lx['markup_type'] === 'Persen (%)' && near($lx['markup_value'], 10) && near($lx['sell_price'], round((float) $stRows[0]['reference_purchase_price'] * 1.1, 4), 0.0001));
check('L item detail Y: markup Nominal (Rp) 500; Harga Jual = ref + 500', $ly['markup_type'] === 'Nominal (Rp)' && near($ly['markup_value'], 500) && near($ly['sell_price'], round((float) $stRows[1]['reference_purchase_price'] + 500, 4), 0.0001));
check('L FIFO detail X: TWO layers (10 + 2); Σ FIFO qty = qty OUT (12); Σ FIFO value = HPP stored', count($lx['layer_rows']) === 2 && near($lx['layer_rows'][0]['qty'], 10) && near($lx['layer_rows'][1]['qty'], 2) && near($lx['fifo_qty'], 12) && near($lx['fifo_value'], $lx['hpp'], 0.01), json_encode(array_column($lx['layer_rows'], 'qty')));
check('L FIFO detail: layer cost = stored layer cost; layer source names the originating IN transaction', near($lx['layer_rows'][0]['unit_cost'], $IE['O1']['hpp_x'] > 0 ? (float) $pdo->query("SELECT unit_cost_base FROM inventory_transaction_lines WHERE transaction_id = " . (int) $fx['tx']['A'][0] . ' LIMIT 1')->fetchColumn() : 0, 0.0001) && str_contains($lx['layer_rows'][0]['batch_source'], 'IN') && str_contains($lx['layer_rows'][0]['batch_source'], 'INV-A'));
check('L detail check flags: Σ FIFO qty = qty OUT and Σ FIFO value = HPP for every line', $od['check']['fifo_qty_equals_out_qty'] === true && $od['check']['fifo_value_equals_hpp'] === true);
check('M shipping allocation is DERIVED (labelled) and adds up to the invoice shipping exactly: ' . round($lx['shipping'], 4) . ' + ' . round($ly['shipping'], 4), near($lx['shipping'] + $ly['shipping'], 10000, 0.00001) && near($lx['shipping'], round(10000 * $lx['sell'] / ($lx['sell'] + $ly['sell']), 4), 0.0001));
check('M line total = Subtotal Jual + alokasi ongkir; Σ line total = invoice grand total', near($lx['line_total'] + $ly['line_total'], $d1['grand_total'], 0.0001) && near($lx['line_total'], $lx['sell'] + $lx['shipping'], 0.00001));
check('M line margin = Subtotal Jual − HPP Total (stored values, never the master price)', near($lx['margin'], $lx['sell'] - $lx['hpp'], 0.0001));
check('R item detail carries timestamp + notes columns; document block has DO + invoice ids', $lx['created_at'] !== null && array_key_exists('notes', $lx) && $od['document']['invoice_number'] === $O1['invoice_number']);
$odl = S::outDetail($pdo, null, $fx['legacy_out_tx'], null);
check('L legacy OUT detail: FIFO layers present; price columns unknown (null)', count($odl['lines'][0]['layer_rows']) >= 1 && $odl['lines'][0]['sell_price'] === null && $odl['lines'][0]['reference_price'] === null);
$odr = S::outDetail($pdo, (int) $O3['do_id'], null, null);
check('B reversed document detail still loads (history kept) with status CANCELLED and counted = false', $odr['lines'][0]['counted'] === false && $odr['document']['status'] === 'CANCELLED');
$os = S::outOverview($pdo, $base + ['bucket' => 'month'])['trend'];
check('B trend Bulanan: HPP + Nilai Jual equal the KPI; 3 documents', near($os[0]['hpp'], $no['hpp']) && near($os[0]['sell'], $no['sell']) && $os[0]['count'] === 3);

// ================================================================ C / N / O / P / Q / R. tab Transfer
echo "\n== C / N / O / P / Q / R. tab Transfer ==\n";
$tr = S::trfOverview($pdo, $base);
$nt = $tr['kpi']['nominal'];
$T = $fx['trf'];
$tl = S::trfList($pdo, $base);
$t1 = rowBy($tl['rows'], 'id', $T['T1']);
$t2 = rowBy($tl['rows'], 'id', $T['T2']);
$t3 = rowBy($tl['rows'], 'id', $T['T3']);
$t4 = rowBy($tl['rows'], 'id', $T['T4']);
$t5 = rowBy($tl['rows'], 'id', $T['T5']);
check('C KPI: 3 active transfers (T1, T2, T4); 1 pending, 2 received; cancelled T3 + reversed T5 listed but not counted', $nt['transfers'] === 3 && $nt['pending'] === 1 && $nt['received'] === 2 && $tr['disclosures']['inactive']['transfers'] === 2 && count($tl['rows']) === 5);
check('C KPI SKU dipindahkan = 4 (X, Y, Z, W)', $nt['skus'] === 4);
$layerValue = static function (int $transferId) use ($pdo): float {
    return (float) $pdo->query("SELECT COALESCE(SUM(fa.subtotal), 0) FROM warehouse_transfer_lines wtl JOIN fifo_allocations fa ON fa.transaction_line_id = wtl.out_transaction_line_id WHERE wtl.transfer_id = " . (int) $transferId . " AND wtl.id = (SELECT MIN(id) FROM warehouse_transfer_lines w2 WHERE w2.transfer_id = wtl.transfer_id AND w2.out_transaction_line_id = wtl.out_transaction_line_id)")->fetchColumn();
};
$tv = static fn (int $id): float => (float) $pdo->query("SELECT SUM(qty_base * unit_cost_base) FROM warehouse_transfer_lines WHERE transfer_id = " . (int) $id)->fetchColumn();
check('N transfer value = Σ qty × stored layer cost (T1) — never a re-pricing from today\'s price', near($t1['value'], $tv($T['T1']), 0.001) && near($t4['value'], $tv($T['T4']), 0.001) && $t1['value'] > 0);
check('N cost preserved end to end: TRANSFER_OUT value = TRANSFER_IN value for received T1 and T4', near($t1['out_value'], $t1['in_value'], 0.01) && near($t4['out_value'], $t4['in_value'], 0.01));
check('N value equals the FIFO allocations the TRANSFER_OUT consumed (T1)', near($t1['out_value'], (float) $pdo->query("SELECT SUM(fa.subtotal) FROM warehouse_transfer_lines wtl JOIN fifo_allocations fa ON fa.transaction_line_id = wtl.out_transaction_line_id WHERE wtl.transfer_id = " . (int) $T['T1'] . ' AND wtl.id IN (SELECT MIN(id) FROM warehouse_transfer_lines WHERE transfer_id = ' . (int) $T['T1'] . ' GROUP BY out_transaction_line_id)')->fetchColumn(), 0.01));
check('N KPI Nilai Cost = Σ active (T1 + T2 + T4); in-transit = T2; received = T1 + T4', near($nt['value'], $t1['value'] + $t2['value'] + $t4['value']) && near($nt['in_transit_value'], $t2['value']) && near($nt['received_value'], $t1['value'] + $t4['value']));
check('O pending T2: status PENDING, no received timestamp / actor, no lead time (unknown — null, not 0)', $t2['status'] === 'PENDING' && $t2['received_at'] === null && $t2['received_by'] === null && $t2['lead_time'] === null && $t2['lead_hours'] === null);
$td2 = S::trfDetail($pdo, $T['T2'], null);
check('O pending detail: Qty Diterima + Selisih UNKNOWN (null) while PENDING; layers listed', $td2['lines'][0]['qty_received'] === null && $td2['lines'][0]['diff'] === null && $td2['lines'][0]['qty'] === 3.0 && count($td2['lines'][0]['layer_rows']) >= 1);
$td1 = S::trfDetail($pdo, $T['T1'], null);
$x1 = rowBy($td1['lines'], 'sku', $fx['items']['X']['sku']);
check('P received detail T1: Qty Diterima = Qty Transfer (10), Selisih 0, unit cost = value / qty, layer rows Σ qty = qty', near($x1['qty'], 10) && near($x1['qty_received'], 10) && near($x1['diff'], 0) && near($x1['unit_cost'], $x1['value'] / 10, 0.0001) && near(array_sum(array_column($x1['layer_rows'], 'qty')), 10) && near(array_sum(array_column($x1['layer_rows'], 'value')), $x1['out_value'], 0.01));
check('N layer rows carry batch id, layer date and layer cost (the cost that travelled)', isset($x1['layer_rows'][0]['batch_id']) && $x1['layer_rows'][0]['batch_date'] !== '' && $x1['layer_rows'][0]['unit_cost'] > 0);
check('Q timestamps T1: dibuat/dispatched 2026-09-23 09:00:00 (real TRANSFER_OUT posting), diterima 2026-09-24 15:00:00; lead time 30 jam = 1,3 hari', $t1['created_at'] === '2026-09-23 09:00:00' && $t1['dispatched_at'] === '2026-09-23 09:00:00' && $t1['received_at'] === '2026-09-24 15:00:00' && near($t1['lead_hours'], 30) && $t1['lead_time'] === '1.3 hari', (string) $t1['lead_time']);
check('Q timestamps T4: lead time 26 jam; KPI Rata-rata Lead Time = (30 + 26) / 2 = 28 jam over 2 samples', near($t4['lead_hours'], 26) && near($nt['avg_lead_hours'], 28) && $nt['lead_samples'] === 2);
check('Q cancelled T3 / reversed T5 carry their real reason + actor; PENDING/RECEIVED do not', $t3['status'] === 'CANCELLED' && $t3['notes'] === 'io fixture cancel' && $t5['status'] === 'REVERSED' && $t5['notes'] === 'io fixture reverse' && $t1['notes'] === null);
$c3 = S::trfDetail($pdo, $T['T3'], null)['transfer'];
check('R actors: dibuat / dispatched / diterima oleh = real usernames; cancel block has by + at + reason', $t1['created_by'] === $admin && $t1['dispatched_by'] === $admin && $t1['received_by'] === $admin && $c3['cancel']['by'] === $admin && $c3['cancel']['at'] !== null);
check('R lists show Dari → Ke correctly (T4 is the opposite direction)', $t1['from'] === 'Gudang PR-SCM' && $t1['to'] === 'Gudang PR-Cibadak' && $t4['from'] === 'Gudang PR-Cibadak' && $t4['to'] === 'Gudang PR-SCM');
check('C footer value = KPI (active only)', near($tl['footer']['totals']['value'], $nt['value']));
$tq = array_column($tr['kpi']['qty_by_unit'], 'qty', 'unit');
check('C quantities per unit: KG 22 (2 + 20), LTR 3, PCS 10', near($tq['KG'] ?? null, 22) && near($tq['LTR'] ?? null, 3) && near($tq['PCS'] ?? null, 10));
$tt = S::trfOverview($pdo, $base + ['bucket' => 'month'])['trend'];
check('C trend: Σ nilai = KPI, 3 transfers', near($tt[0]['value'], $nt['value']) && $tt[0]['count'] === 3);

// ================================================================ D / E / F / G / H / I / J. filters
echo "\n== D-J. filters ==\n";
$e1 = S::inList($pdo, ['start_date' => '2026-09-01', 'end_date' => '2026-09-10'] + $base);
check('D date filter 09-01..09-10 → IN: only INV-A (09-05); OUT: none; Transfer: none', count($e1['rows']) === 1 && $e1['rows'][0]['reference'] === 'INV-A' && count(S::outList($pdo, ['start_date' => '2026-09-01', 'end_date' => '2026-09-10'] + $base)['rows']) === 0 && count(S::trfList($pdo, ['start_date' => '2026-09-01', 'end_date' => '2026-09-10'] + $base)['rows']) === 0);
check('D date filter is inclusive on both ends (09-22..09-22 → OUT1 only)', count(S::outList($pdo, ['start_date' => '2026-09-22', 'end_date' => '2026-09-22'] + $base)['rows']) === 1);
$w2in = S::inList($pdo, $base + ['warehouse_id' => $W2]);
check('E warehouse W2 → IN: only B (31.500); OUT: none (every OUT left W1)', count($w2in['rows']) === 1 && near($w2in['rows'][0]['total'], 31500) && count(S::outList($pdo, $base + ['warehouse_id' => $W2])['rows']) === 0);
check('E warehouse W2 → Transfer: every transfer involves W2 (either leg) = 5; W1 → 5 as well (T4 ends at W1)', count(S::trfList($pdo, $base + ['warehouse_id' => $W2])['rows']) === 5 && count(S::trfList($pdo, $base + ['warehouse_id' => $W1])['rows']) === 5);
$w1out = S::outList($pdo, $base + ['warehouse_id' => $W1]);
check('E warehouse W1 → OUT: O1, O2, O3 (reversed), legacy', count($w1out['rows']) === 4);
$cat2 = S::inOverview($pdo, $base + ['category_id' => $fx['cat']['2']])['kpi']['nominal'];
$cat1 = S::inOverview($pdo, $base + ['category_id' => $fx['cat']['1']])['kpi']['nominal'];
check('F category PR Bahan (C2: Y, Z, W, L) vs PR Roti (C1: X): totals add up to the whole', $cat1['skus'] === 1 && $cat2['skus'] === 4 && near($cat1['total'] + $cat2['total'], $n['total'], 0.01));
$catOut = S::outOverview($pdo, $base + ['category_id' => $fx['cat']['1']])['kpi']['nominal'];
check('F category on OUT (Roti = X only): 2 documents (O1, O2), HPP = X layers only', $catOut['documents'] === 2 && near($catOut['hpp'], $IE['O1']['hpp_x'] + $IE['O2']['hpp_x'], 0.01) && $catOut['skus'] === 1);
$catTr = S::trfOverview($pdo, $base + ['category_id' => $fx['cat']['1']]);
check('F category on Transfer (X only): T1 (+ cancelled T3 listed)', $catTr['kpi']['nominal']['transfers'] === 1 && $catTr['kpi']['nominal']['skus'] === 1);
$gx = S::inList($pdo, $base + ['q' => $fx['items']['X']['sku']]);
check('G item search by SKU on IN → INV-A and INV-C only; partial invoice keeps its whole-invoice header values', count($gx['rows']) === 2 && rowBy($gx['rows'], 'reference', 'INV-A') !== null && rowBy($gx['rows'], 'reference', 'INV-C') !== null && $gx['rows'][0]['items'] === 1);
check('G global search per tab: IN "legacy" finds PO-LEGACY; OUT by DO number; Transfer by "TRF-' . $T['T2'] . '"', count(S::inList($pdo, $base + ['gq' => 'po-legacy'])['rows']) === 1 && count(S::outList($pdo, $base + ['gq' => $O2['do_number']])['rows']) === 1 && count(S::trfList($pdo, $base + ['gq' => 'TRF-' . $T['T2']])['rows']) === 1);
check('G global search matches invoice number, bakery name and supplier name', count(S::outList($pdo, $base + ['gq' => $O1['invoice_number']])['rows']) === 1 && count(S::outList($pdo, $base + ['gq' => 'bakery dua'])['rows']) === 1 && count(S::inList($pdo, $base + ['gq' => 'supplier dua'])['rows']) === 1);
check('G search with no match → empty rows + zero footer (never an error)', S::inList($pdo, $base + ['gq' => 'zzzz-none'])['rows'] === [] && S::inList($pdo, $base + ['gq' => 'zzzz-none'])['pagination']['total'] === 0);
$hs = S::inList($pdo, $base + ['supplier_id' => $fx['sup']['2']]);
check('H supplier S2 → only invoice B (31.500)', count($hs['rows']) === 1 && near($hs['rows'][0]['total'], 31500));
$b1 = S::outList($pdo, $base + ['bakery_destination_id' => $fx['bakery']['1']]);
$b2 = S::outList($pdo, $base + ['bakery_destination_id' => $fx['bakery']['2']]);
check('I bakery filter: Bakery Satu → O1 + O3 (reversed, listed); Bakery Dua → O2', count($b1['rows']) === 2 && rowBy($b1['rows'], 'do_number', $O1['do_number']) !== null && count($b2['rows']) === 1 && $b2['rows'][0]['do_number'] === $O2['do_number']);
check('I bakery KPI: Bakery Satu counts only O1 (O3 reversed) — 1 bakery, 1 document', S::outOverview($pdo, $base + ['bakery_destination_id' => $fx['bakery']['1']])['kpi']['nominal']['documents'] === 1);
check('I OUT status filter: CANCELLED → O3; DISPATCHED → O1 + O2', count(S::outList($pdo, $base + ['status' => 'CANCELLED'])['rows']) === 1 && count(S::outList($pdo, $base + ['status' => 'DISPATCHED'])['rows']) === 2);
check('I OUT Jenis: V2 → 3 documents (incl. reversed); Legacy → 1', count(S::outList($pdo, $base + ['out_source' => 'V2'])['rows']) === 3 && count(S::outList($pdo, $base + ['out_source' => 'Legacy'])['rows']) === 1);
check('I division filter: no OUT carries a division → an unrelated division returns nothing; none selected returns all', count(S::outList($pdo, $base + ['division_id' => 999999])['rows']) === 0 && count(S::outList($pdo, $base)['rows']) === 4);
check('J transfer status PENDING → T2; RECEIVED → T1 + T4; CANCELLED → T3; REVERSED → T5', array_column(S::trfList($pdo, $base + ['status' => 'PENDING'])['rows'], 'id') === [$T['T2']] && count(S::trfList($pdo, $base + ['status' => 'RECEIVED'])['rows']) === 2
    && array_column(S::trfList($pdo, $base + ['status' => 'CANCELLED'])['rows'], 'id') === [$T['T3']] && array_column(S::trfList($pdo, $base + ['status' => 'REVERSED'])['rows'], 'id') === [$T['T5']]);
check('J direction filters: Gudang Asal = W2 → T4; Gudang Tujuan = W1 → T4', array_column(S::trfList($pdo, $base + ['from_warehouse_id' => $W2])['rows'], 'id') === [$T['T4']] && array_column(S::trfList($pdo, $base + ['to_warehouse_id' => $W1])['rows'], 'id') === [$T['T4']]);
$pg = S::trfList($pdo, ['per_page' => 2, 'page' => 2] + $base);
check('J pagination: per_page 2, page 2 → 2 rows of 5, 3 pages', count($pg['rows']) === 2 && $pg['pagination']['total'] === 5 && $pg['pagination']['total_pages'] === 3);
$srt = S::outList($pdo, $base + ['sort' => 'hpp', 'dir' => 'desc']);
check('J sort by HPP desc', $srt['rows'][0]['hpp'] >= $srt['rows'][1]['hpp'] && $srt['rows'][1]['hpp'] >= $srt['rows'][2]['hpp']);

// ================================================================ S. export
echo "\n== S. export ==\n";
$meta = ['Laporan' => 'test'];
$xin = S::inExport($pdo, $base, $meta);
$xout = S::outExport($pdo, $base, $meta);
$xtr = S::trfExport($pdo, $base, $meta);
check('S sheets: IN = Ringkasan IN / Transaksi IN / Rincian Item IN; OUT adds FIFO Allocation; Transfer = Ringkasan / Daftar / Rincian Item (+ layer cost)', array_keys($xin) === ['Ringkasan IN', 'Transaksi IN', 'Rincian Item IN'] && array_keys($xout) === ['Ringkasan OUT', 'Transaksi OUT', 'Rincian Item OUT', 'FIFO Allocation']
    && array_keys($xtr) === ['Ringkasan Transfer', 'Daftar Transfer', 'Rincian Item Transfer', 'Layer Cost Transfer']);
$last = static fn (array $sheet): array => end($sheet['rows']);
$col = static fn (array $sheet, string $label): int => (int) array_search($label, $sheet['headers'], true);
$ti = $xin['Transaksi IN'];
check('S export IN total row = UI footer (Grand Total 146.025,5, subtotal 112.000, PPN 9.530, ongkir 21.000)', near((float) $last($ti)[$col($ti, 'Grand Total')], $n['total']) && near((float) $last($ti)[$col($ti, 'Subtotal Barang')], 112000) && near((float) $last($ti)[$col($ti, 'PPN')], 9530) && near((float) $last($ti)[$col($ti, 'Ongkos Kirim')], 21000));
$ri = $xin['Rincian Item IN'];
check('S export IN item sheet total (POSTED) = Σ invoice totals; void line listed but not summed', near((float) $last($ri)[$col($ri, 'Total Nilai')], $n['total']) && count($ri['rows']) === 8 + 1);
$summ = array_column($xin['Ringkasan IN']['rows'], 1, 0);
check('S export IN summary = KPI cards (Total Nilai Masuk, Jumlah Transaksi, PPN, Ongkos Kirim) and carries the filter metadata', near((float) $summ['Total Nilai Masuk'], $n['total']) && (int) $summ['Jumlah Transaksi'] === 4 && near((float) $summ['PPN'], 9530) && near((float) $summ['Ongkos Kirim'], 21000) && ($summ['Laporan'] ?? '') === 'test');
$to = $xout['Transaksi OUT'];
check('S export OUT total row = UI footer (HPP, Nilai Jual, Ongkir, Grand Total, Margin)', near((float) $last($to)[$col($to, 'HPP Total')], $no['hpp']) && near((float) $last($to)[$col($to, 'Nilai Jual')], $no['sell']) && near((float) $last($to)[$col($to, 'Ongkir')], $no['shipping']) && near((float) $last($to)[$col($to, 'Margin')], $no['margin']));
$ro = $xout['Rincian Item OUT'];
check('S export OUT item sheet: HPP Total = KPI; unknown prices exported as "—" (legacy line), not 0', near((float) $last($ro)[$col($ro, 'HPP Total')], $no['hpp']) && in_array('—', array_column($ro['rows'], $col($ro, 'Harga Jual')), true));
$fo = $xout['FIFO Allocation'];
check('S export FIFO sheet: Σ Nilai FIFO = HPP of every exported OUT line (counted + reversed history layers), layer rows ≥ lines', (float) $last($fo)[$col($fo, 'Nilai FIFO')] >= $no['hpp'] - 0.01 && count($fo['rows']) - 1 >= 5);
$tt2 = $xtr['Daftar Transfer'];
check('S export Transfer total = UI footer (Nilai Cost, active only)', near((float) $last($tt2)[$col($tt2, 'Nilai Cost')], $nt['value']));
$ts = array_column($xtr['Ringkasan Transfer']['rows'], 1, 0);
check('S export Transfer summary: Total Transfer 3, Pending 1, Diterima 2, lead time 28 jam', (int) $ts['Total Transfer (aktif)'] === 3 && (int) $ts['Pending'] === 1 && (int) $ts['Diterima'] === 2 && near((float) $ts['Rata-rata Lead Time (jam)'], 28));
$xlsxOk = static function (array $sheets, int $count): bool {
    $f = tempnam(sys_get_temp_dir(), 'io') . '.xlsx';
    ExcelWriterService::write($f, $sheets);
    $z = new ZipArchive();
    $ok = $z->open($f) === true && $z->locateName("xl/worksheets/sheet{$count}.xml") !== false && $z->locateName('xl/worksheets/sheet' . ($count + 1) . '.xml') === false;
    $z->close();
    @unlink($f);
    return $ok;
};
check('S the workbooks are valid xlsx files with 3 / 4 / 4 sheets', $xlsxOk($xin, 3) && $xlsxOk($xout, 4) && $xlsxOk($xtr, 4));
$fxls = S::inExport($pdo, $base + ['gq' => 'INV-A'], $meta);
check('S export honours the table search + filters (INV-A only)', count($fxls['Transaksi IN']['rows']) === 1 + 1);

// ================================================================ X. unknown / empty / validation / Y. reconciliation
echo "\n== X / Y ==\n";
$empty = ['start_date' => '2025-01-01', 'end_date' => '2025-01-31'];
$ei = S::inOverview($pdo, $empty);
$eo = S::outOverview($pdo, $empty);
$et = S::trfOverview($pdo, $empty);
check('X empty period: zero counts, no rows, avg per transaksi / lead time UNKNOWN (null) instead of 0', $ei['kpi']['nominal']['invoices'] === 0 && $ei['kpi']['nominal']['avg_invoice'] === null && $eo['kpi']['nominal']['margin_pct'] === null && $et['kpi']['nominal']['avg_lead_hours'] === null
    && S::inList($pdo, $empty)['rows'] === [] && S::outList($pdo, $empty)['rows'] === [] && S::trfList($pdo, $empty)['rows'] === []);
$bad = 0;
foreach ([['start_date' => '2026-09-30', 'end_date' => '2026-09-01'], ['start_date' => '', 'end_date' => ''], ['start_date' => '2020-01-01', 'end_date' => '2026-09-30']] as $f) {
    foreach (['inOverview', 'outOverview', 'trfOverview', 'inList', 'outList', 'trfList'] as $m) {
        try { S::$m($pdo, $f); } catch (ValidationException) { $bad++; }
    }
}
check('X reversed / missing / > 800-day period → ValidationException on every method (18 of 18)', $bad === 18, (string) $bad);
$nf = 0;
foreach ([fn () => S::inDetail($pdo, [999999999], null), fn () => S::outDetail($pdo, 999999999, null, null), fn () => S::trfDetail($pdo, 999999999, null)] as $fn) {
    try { $fn(); } catch (\App\Services\NotFoundException) { $nf++; }
}
check('X unknown invoice / DO / transfer → NotFoundException', $nf === 3);
check('X detail respects the warehouse scope: an OUT document of W1 is NOT FOUND for scope W2; an IN invoice of W1 too', (function () use ($pdo, $O1, $fx, $W2) {
    try { S::outDetail($pdo, (int) $O1['do_id'], null, $W2); return false; } catch (\App\Services\NotFoundException) {}
    try { S::inDetail($pdo, $fx['tx']['A'], $W2); return false; } catch (\App\Services\NotFoundException) {}
    return true;
})());
$checks = S::reconcile($pdo, $base);
$failed = array_filter($checks, static fn ($c) => !$c[1]);
foreach ($checks as $c) {
    check('Y ' . $c[0], $c[1], $c[2]);
}
check('Y reconciliation: every check passes on the fixture (' . count($checks) . ' checks)', $failed === [] && count($checks) === 13);
check('the service never wrote: snapshot of every ledger / FIFO / costing / DO / invoice / transfer table is unchanged', snap($pdo) === $before);

// ================================================================ T / U. HTTP
echo "\n== T / U. HTTP ==\n";
$port = 8900 + random_int(2000, 2399);
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
    $jar = tempnam(sys_get_temp_dir(), 'io_');
    $r = http('POST', "{$baseUrl}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($baseUrl, $fx['viewer']);
    $stock2 = loginAs($baseUrl, $fx['stock2']);
    $adm = loginAs($baseUrl, $fx['admin']);
    check('logins succeed', $viewer['status'] === 200 && $stock2['status'] === 200 && $adm['status'] === 200);
    $qs = 'start_date=2026-09-01&end_date=2026-09-30';
    foreach (['in' => $n['total'], 'out' => $no['hpp'], 'transfer' => $nt['value']] as $tab => $expect) {
        $r = http('GET', "{$baseUrl}/reports/io/overview?{$qs}&tab={$tab}", null, $viewer['jar']);
        $k = $r['body']['data']['kpi']['nominal'] ?? [];
        $got = $tab === 'in' ? ($k['total'] ?? -1) : ($tab === 'out' ? ($k['hpp'] ?? -1) : ($k['value'] ?? -1));
        check("T VIEWER (INVENTORY_VIEW only): overview tab={$tab} 200 with KPI + trend", $r['status'] === 200 && near((float) $got, (float) $expect) && isset($r['body']['data']['trend']), (string) $r['status']);
        $r = http('GET', "{$baseUrl}/reports/io/list?{$qs}&tab={$tab}", null, $viewer['jar']);
        check("T VIEWER: list tab={$tab} 200 with rows + columns + footer + pagination", $r['status'] === 200 && isset($r['body']['data']['rows'], $r['body']['data']['columns'], $r['body']['data']['footer'], $r['body']['data']['pagination']));
    }
    check('T unauthenticated → 401 on every route', http('GET', "{$baseUrl}/reports/io/overview?{$qs}&tab=in")['status'] === 401 && http('GET', "{$baseUrl}/reports/io/list?{$qs}&tab=out")['status'] === 401
        && http('GET', "{$baseUrl}/reports/io/detail?tab=transfer&id=1")['status'] === 401 && http('GET', "{$baseUrl}/reports/io/export?{$qs}&tab=in")['status'] === 401 && http('GET', "{$baseUrl}/reports/io/options")['status'] === 401);
    check('T bad tab / reversed dates / missing dates → 422', http('GET', "{$baseUrl}/reports/io/overview?{$qs}&tab=zzz", null, $viewer['jar'])['status'] === 422 && http('GET', "{$baseUrl}/reports/io/list?start_date=2026-09-30&end_date=2026-09-01&tab=in", null, $viewer['jar'])['status'] === 422
        && http('GET', "{$baseUrl}/reports/io/overview?tab=in", null, $viewer['jar'])['status'] === 422);
    // a STOCK user of W2 asking for W1 through the query string
    $r = http('GET', "{$baseUrl}/reports/io/overview?{$qs}&tab=in&warehouse_id={$W1}", null, $stock2['jar']);
    check('T a STOCK user of W2 asking for W1 gets ONLY W2: IN total 31.500 (invoice B)', $r['status'] === 200 && near((float) $r['body']['data']['kpi']['nominal']['total'], 31500));
    $r = http('GET', "{$baseUrl}/reports/io/list?{$qs}&tab=out&warehouse_id={$W1}", null, $stock2['jar']);
    check('T the same user sees NO OUT of W1 (every OUT left W1)', $r['status'] === 200 && count($r['body']['data']['rows']) === 0);
    $r = http('GET', "{$baseUrl}/reports/io/list?{$qs}&tab=transfer&warehouse_id={$W1}", null, $stock2['jar']);
    check('T transfers: the W2 user sees the 5 transfers that involve W2', $r['status'] === 200 && count($r['body']['data']['rows']) === 5);
    $r = http('GET', "{$baseUrl}/reports/io/detail?tab=out&do_id={$O1['do_id']}", null, $stock2['jar']);
    check('T detail of a W1 DO for the W2 user → 404 (scope enforced on detail too)', $r['status'] === 404);
    $r = http('GET', "{$baseUrl}/reports/io/detail?tab=in&tx_ids=" . implode(',', $fx['tx']['A']), null, $stock2['jar']);
    check('T detail of a W1 invoice for the W2 user → 404', $r['status'] === 404);
    $r = http('GET', "{$baseUrl}/reports/io/options", null, $stock2['jar']);
    check('T options for a scoped user list only their own warehouse in the Gudang filter', $r['status'] === 200 && count($r['body']['data']['warehouses']) === 1 && (int) $r['body']['data']['warehouses'][0]['id'] === $W2 && count($r['body']['data']['bakeries']) >= 2);
    $r = http('GET', "{$baseUrl}/reports/io/detail?tab=out&do_id={$O1['do_id']}", null, $viewer['jar']);
    check('detail OUT over HTTP: 200 with lines + FIFO layers + checks', $r['status'] === 200 && count($r['body']['data']['lines']) === 2 && $r['body']['data']['check']['fifo_qty_equals_out_qty'] === true && count($r['body']['data']['lines'][0]['layer_rows']) === 2);
    $r = http('GET', "{$baseUrl}/reports/io/detail?tab=transfer&id={$T['T1']}", null, $viewer['jar']);
    check('detail Transfer over HTTP: 200 with item lines + layers; unknown id → 404; missing id → 422', $r['status'] === 200 && count($r['body']['data']['lines']) === 2 && http('GET', "{$baseUrl}/reports/io/detail?tab=transfer&id=999999999", null, $viewer['jar'])['status'] === 404
        && http('GET', "{$baseUrl}/reports/io/detail?tab=transfer", null, $viewer['jar'])['status'] === 422);
    $r = http('GET', "{$baseUrl}/reports/io/detail?tab=in&tx_ids=" . implode(',', $fx['tx']['A']), null, $viewer['jar']);
    check('detail IN over HTTP: 200 with 3 lines + arithmetic', $r['status'] === 200 && count($r['body']['data']['lines']) === 3 && near((float) $r['body']['data']['arithmetic']['grand_total'], 43585.5));
    foreach (['in' => 3, 'out' => 4, 'transfer' => 4] as $tab => $sheets) {
        $x = http('GET', "{$baseUrl}/reports/io/export?{$qs}&tab={$tab}", null, $viewer['jar'], null, true);
        check("S export tab={$tab} over HTTP: 200 xlsx, a valid zip with {$sheets} sheets", $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml') !== false && (function () use ($x, $sheets) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName("xl/worksheets/sheet{$sheets}.xml") !== false && $z->locateName('xl/worksheets/sheet' . ($sheets + 1) . '.xml') === false; $z->close(); @unlink($f); return $ok; })());
    }
    check('existing routes unaffected: GET /reports/in-out and /reports/transfer still answer', http('GET', "{$baseUrl}/reports/in-out?date_from=2026-09-01&date_to=2026-09-30", null, $adm['jar'])['status'] === 200 && http('GET', "{$baseUrl}/reports/transfer?date_from=2026-09-01&date_to=2026-09-30", null, $adm['jar'])['status'] === 200);
    $beforeHttp = snap($pdo);
    $writeOk = true;
    foreach (['/overview', '/list', '/detail', '/export', '/options'] as $route) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (http($verb, "{$baseUrl}/reports/io{$route}", [], $adm['jar'], $adm['csrf'])['status'] !== 404) { $writeOk = false; }
        }
    }
    check('U POST / PUT / PATCH / DELETE on every /reports/io route → 404 (no write route exists)', $writeOk);
    check('U HTTP reads + rejected write verbs changed nothing in the database', snap($pdo) === $beforeHttp);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
