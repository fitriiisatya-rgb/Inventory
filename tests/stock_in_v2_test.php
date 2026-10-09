<?php
declare(strict_types=1);

/**
 * STOCK IN V2 (PurchaseInvoiceService → POST /stock-in/quote, /stock-in).
 * Real MariaDB, the application's own FIFO / purchase-costing engines.
 * Expectations are HAND-COMPUTED here, not read back from the code under test.
 *
 *   A basic · B PPN (creditable vs non-creditable) · C item discount % · D item discount Rp
 *   E invoice discount % · F invoice discount Rp · G shipping (expensed vs capitalised)
 *   H multi-item combination · I non-base unit · J price override never touches master
 *   K invalid input blocked · L posted base qty · M FIFO cost on a later OUT
 *   N all-or-nothing + price anomaly confirm · O idempotent replay · P quote is read-only
 *
 * Usage: DB_DATABASE=... php tests/stock_in_v2_test.php
 */

require_once __DIR__ . '/lib/dashboard_fixture.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\ItemPriceService;
use App\Services\PriceAnomalyException;
use App\Services\PurchaseInvoiceService as P;
use App\Services\UnitConversionService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.001): bool
{
    return $a !== null && $b !== null && abs($a - $b) <= $eps;
}

$pdo = Database::connection();
$kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$karton = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$role = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => df_uid('siv2'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'siv2', 'r' => $role]);
$uid = (int) $pdo->lastInsertId();
$WH = df_wh($pdo, 'SIV2 Gudang');
$pdo->prepare("INSERT INTO suppliers (code, name, is_active) VALUES (:c,'SIV2 Supplier',1)")->execute(['c' => df_uid('SUP')]);
$supplier = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO categories (code, name, is_active) VALUES (:c,'SIV2 Cat',1)")->execute(['c' => df_uid('CAT')]);
$cat = (int) $pdo->lastInsertId();

function mkItem(PDO $pdo, int $kg, int $cat, int $karton, string $tag, bool $withCarton = false): array
{
    $it = df_item($pdo, $kg, $tag, $cat);
    if ($withCarton) {
        UnitConversionService::openNewVersion($pdo, $it['id'], $karton, 24.0, '2020-01-01 00:00:00', null, 'carton=24kg');
    }
    return $it;
}
$A = mkItem($pdo, $kg, $cat, $karton, 'SIVA');
$B = mkItem($pdo, $kg, $cat, $karton, 'SIVB');
$C = mkItem($pdo, $kg, $cat, $karton, 'SIVC', true);

$date = date('Y-m-d');
function line(array $item, int $unit, float $qty, float $price, float $ppn = 0, string $dt = 'NONE', float $dv = 0): array
{
    return ['item_id' => $item['id'], 'input_unit_id' => $unit, 'input_qty' => $qty, 'unit_price_input' => $price, 'ppn_rate' => $ppn, 'discount_type' => $dt, 'discount_value' => $dv];
}
function entry(int $wh, array $lines, array $extra = []): array
{
    return array_merge(['warehouse_id' => $wh, 'transaction_date' => date('Y-m-d'), 'lines' => $lines], $extra);
}
function post(PDO $pdo, array $in, int $uid): array
{
    $in['transaction_uuid'] = $in['transaction_uuid'] ?? df_uid('siv2req');
    return Database::transaction(fn (PDO $tx) => P::post($tx, $in, $uid, 'siv2'));
}
function costRow(PDO $pdo, int $txId): array
{
    $s = $pdo->prepare('SELECT plc.*, itl.base_qty, itl.unit_cost_base, itl.notes AS line_notes FROM purchase_line_costs plc JOIN inventory_transaction_lines itl ON itl.id = plc.transaction_line_id WHERE plc.transaction_id = :t');
    $s->execute(['t' => $txId]);
    return $s->fetch() ?: [];
}
function hdr(PDO $pdo, int $txId): array
{
    $s = $pdo->prepare('SELECT * FROM purchase_invoice_headers WHERE transaction_id = :t');
    $s->execute(['t' => $txId]);
    return $s->fetch() ?: [];
}

// ============================================================ A. basic
$q = P::quote($pdo, entry($WH, [line($A, $kg, 10, 1000)]));
check('A quote valid; row total 10.000, grand 10.000', $q['valid'] && near($q['lines'][0]['total'], 10000) && near($q['totals']['grand_total'], 10000) && near($q['totals']['subtotal'], 10000), json_encode($q['errors']));
$r = post($pdo, entry($WH, [line($A, $kg, 10, 1000)], ['reference_no' => 'INV-A-1', 'supplier_id' => $supplier, 'notes' => 'catatan A']), $uid);
$t = $r['transactions'][0];
$tx = $pdo->prepare('SELECT * FROM inventory_transactions WHERE id = :i'); $tx->execute(['i' => $t['transaction_id']]); $tx = $tx->fetch();
$c = costRow($pdo, (int) $t['transaction_id']);
check('A posted as IN, POSTED, supplier + reference kept', $tx['transaction_type'] === 'IN' && $tx['status'] === 'POSTED' && (int) $tx['supplier_id'] === $supplier && $tx['reference_no'] === 'INV-A-1');
check('A base_qty 10, unit_cost_base 1.000, FIFO batch qty 10', near((float) $c['base_qty'], 10) && near((float) $c['unit_cost_base'], 1000) && near((float) $pdo->query("SELECT SUM(qty_base) FROM inventory_batches WHERE item_id={$A['id']} AND warehouse_id={$WH}")->fetchColumn(), 10));
check('A catatan stored on the line; costing row reconciles', $c['line_notes'] === 'catatan A' && near((float) $c['final_inventory_cost'], 10000));

// ============================================================ B. PPN
$q = P::quote($pdo, entry($WH, [line($B, $kg, 10, 1000, 11)]));
check('B PPN 11%: dpp 10.000, ppn 1.100, row total 11.100, grand 11.100', near($q['lines'][0]['dpp'], 10000) && near($q['lines'][0]['ppn'], 1100) && near($q['lines'][0]['total'], 11100) && near($q['totals']['grand_total'], 11100));
check('B creditable PPN (default) is NOT capitalised: inventory cost 10.000', near($q['lines'][0]['inventory_cost'], 10000) && near($q['totals']['inventory_cost_total'], 10000));
$r = post($pdo, entry($WH, [line($B, $kg, 10, 1000, 11)]), $uid);
$h = hdr($pdo, (int) $r['transactions'][0]['transaction_id']);
check('B persisted: ppn_amount 1.100, ppn_rate 11, invoice_total 11.100, inventory cost 10.000, unit cost 1.000', near((float) $h['ppn_amount'], 1100) && near((float) $h['ppn_rate'], 11) && near((float) $h['invoice_total'], 11100) && near((float) $h['inventory_cost_total'], 10000) && near((float) $r['transactions'][0]['unit_cost_base'], 1000));
$qn = P::quote($pdo, entry($WH, [line($B, $kg, 10, 1000, 11)], ['ppn_treatment' => 'NON_CREDITABLE']));
check('B non-creditable PPN IS capitalised: inventory cost 11.100 (explicit choice)', near($qn['lines'][0]['inventory_cost'], 11100) && near($qn['lines'][0]['unit_cost_base'], 1110));

// ============================================================ C/D. item discount
$qc = P::quote($pdo, entry($WH, [line($A, $kg, 10, 1000, 0, 'PERCENT', 5)]));
check('C item discount 5%: discount 500, dpp 9.500, total 9.500, unit cost 950', near($qc['lines'][0]['item_discount'], 500) && near($qc['lines'][0]['total'], 9500) && near($qc['lines'][0]['unit_cost_base'], 950));
$qd = P::quote($pdo, entry($WH, [line($A, $kg, 10, 1000, 0, 'AMOUNT', 1500)]));
check('D item discount Rp 1.500: dpp 8.500, total 8.500, unit cost 850', near($qd['lines'][0]['item_discount'], 1500) && near($qd['lines'][0]['total'], 8500) && near($qd['lines'][0]['unit_cost_base'], 850));
$qcp = P::quote($pdo, entry($WH, [line($B, $kg, 10, 1000, 11, 'PERCENT', 5)]));
check('C+B discount first, PPN on the discounted DPP: dpp 9.500, ppn 1.045, total 10.545', near($qcp['lines'][0]['dpp'], 9500) && near($qcp['lines'][0]['ppn'], 1045) && near($qcp['lines'][0]['total'], 10545));

// ============================================================ E/F. invoice discount (two items, no PPN)
$two = [line($A, $kg, 10, 1000), line($B, $kg, 20, 1000)];
$qe = P::quote($pdo, entry($WH, $two, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10]));
check('E invoice discount 10%: subtotal 30.000, discount 3.000, grand 27.000', near($qe['totals']['subtotal'], 30000) && near($qe['totals']['invoice_discount'], 3000) && near($qe['totals']['grand_total'], 27000));
check('E allocated proportionally 1.000 / 2.000; inventory cost 9.000 / 18.000', near($qe['lines'][0]['invoice_discount_share'], 1000) && near($qe['lines'][1]['invoice_discount_share'], 2000) && near($qe['lines'][0]['inventory_cost'], 9000) && near($qe['lines'][1]['inventory_cost'], 18000));
$qf = P::quote($pdo, entry($WH, $two, ['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 3000]));
check('F invoice discount Rp 3.000: grand 27.000, same allocation', near($qf['totals']['grand_total'], 27000) && near($qf['lines'][0]['invoice_discount_share'], 1000) && near($qf['lines'][1]['invoice_discount_share'], 2000));
// with PPN on one row: hand computed in the class docblock
$mixed = [line($A, $kg, 10, 1000, 11), line($B, $kg, 20, 1000, 0)];
$qm = P::quote($pdo, entry($WH, $mixed, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10]));
check('E+PPN: subtotal 31.100, discount 3.110, grand 27.990', near($qm['totals']['subtotal'], 31100) && near($qm['totals']['invoice_discount'], 3110, 0.01) && near($qm['totals']['grand_total'], 27990, 0.01), json_encode($qm['totals']));
check('E+PPN: row1 payable = 11.100 - 1.110 = 9.990 (PPN follows the discounted DPP); inventory cost 9.000', near($qm['lines'][0]['payable'], 9990, 0.01) && near($qm['lines'][0]['inventory_cost'], 9000, 0.01));
check('identity Subtotal - InvDisc + Shipping = Grand holds exactly', near($qm['totals']['subtotal'] - $qm['totals']['invoice_discount'] + $qm['totals']['freight_amount'], $qm['totals']['grand_total'], 0.00001));

// ============================================================ G. shipping
$qg = P::quote($pdo, entry($WH, $two, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 3000]));
check('G shipping Rp 3.000: grand 30.000; NOT capitalised -> inventory cost unchanged 9.000 / 18.000', near($qg['totals']['grand_total'], 30000) && near($qg['lines'][0]['inventory_cost'], 9000) && near($qg['lines'][1]['inventory_cost'], 18000) && $qg['totals']['freight_treatment'] === 'EXPENSE');
$qgc = P::quote($pdo, entry($WH, $two, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 3000, 'freight_capitalize' => true]));
check('G shipping capitalised: split 1.000 / 2.000 by net DPP -> inventory cost 10.000 / 20.000, grand still 30.000', near($qgc['lines'][0]['freight_share'], 1000) && near($qgc['lines'][1]['freight_share'], 2000) && near($qgc['lines'][0]['inventory_cost'], 10000) && near($qgc['lines'][1]['inventory_cost'], 20000) && near($qgc['totals']['grand_total'], 30000) && near($qgc['totals']['inventory_cost_total'], 30000));

// ============================================================ H/I. combination + non-base unit
$combo = [
    line($A, $kg, 10, 1000, 11, 'PERCENT', 5),        // dpp 9.500, ppn 1.045, total 10.545
    line($C, $karton, 2, 24000, 0, 'AMOUNT', 2000),   // base 48.000, dpp 46.000, total 46.000 ; 48 kg
    line($B, $kg, 5, 2000, 11),                       // dpp 10.000, ppn 1.100, total 11.100
];
$qh = P::quote($pdo, entry($WH, $combo, ['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 6764.5, 'freight_amount' => 5000, 'freight_capitalize' => true]));
$sub = 10545 + 46000 + 11100;
check('H subtotal = 67.645', near($qh['totals']['subtotal'], $sub), (string) $qh['totals']['subtotal']);
check('H grand = subtotal - invoice discount + shipping = 67.645 - 6.764,5 + 5.000 = 65.880,5', near($qh['totals']['grand_total'], 65880.5, 0.01), (string) $qh['totals']['grand_total']);
check('I carton row converts: qty 2 karton x 24 = 48 kg base', near($qh['lines'][1]['base_qty'], 48) && near($qh['lines'][1]['dpp'], 46000));
$before = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$rh = post($pdo, entry($WH, $combo, ['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 6764.5, 'freight_amount' => 5000, 'freight_capitalize' => true, 'reference_no' => 'INV-H']), $uid);
check('H posts one transaction per item (3), all POSTED with the shared reference', count($rh['transactions']) === 3 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $before + 3 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no='INV-H' AND status='POSTED'")->fetchColumn() === 3);
$sumInv = 0.0; $sumInvoiceTotal = 0.0;
foreach ($rh['transactions'] as $t2) { $sumInv += (float) costRow($pdo, (int) $t2['transaction_id'])['final_inventory_cost']; $sumInvoiceTotal += (float) hdr($pdo, (int) $t2['transaction_id'])['invoice_total']; }
check('H persisted: Σ invoice_total of the rows == grand total shown; Σ inventory cost == quote', near($sumInvoiceTotal, $qh['totals']['grand_total'], 0.01) && near($sumInv, $qh['totals']['inventory_cost_total'], 0.01), "{$sumInvoiceTotal} / {$sumInv}");
$bq = $pdo->prepare('SELECT SUM(qty_base) FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w');
$bq->execute(['i' => $C['id'], 'w' => $WH]);
check('L posted base qty of the carton row = 48 kg in the FIFO batch', near((float) $bq->fetchColumn(), 48));
$ci = costRow($pdo, (int) $rh['transactions'][1]['transaction_id']);
check('I non-base unit: unit_cost_base = inventory cost / 48 (hand check from the row)', near((float) $ci['unit_cost_base'], (float) $ci['final_inventory_cost'] / 48, 0.001));

// ============================================================ J. price override never touches master
$D = mkItem($pdo, $kg, $cat, $karton, 'SIVD');
post($pdo, entry($WH, [line($D, $kg, 10, 900)]), $uid);
$ref1 = ItemPriceService::resolveReferencePrice($pdo, $D['id'], $kg);
check('J default Harga Beli = latest purchase price (reference 900)', $ref1['price_source'] === 'EXACT_UNIT' && near($ref1['reference_price'], 900));
$itemsBefore = $pdo->query("CHECKSUM TABLE items")->fetch(PDO::FETCH_NUM)[1];
$convBefore = $pdo->query("CHECKSUM TABLE item_unit_conversions")->fetch(PDO::FETCH_NUM)[1];
$rj = post($pdo, entry($WH, [line($D, $kg, 10, 1000)]), $uid);
check('J edited transaction price (1.000 vs reference 900) is used for posting', near((float) $rj['transactions'][0]['unit_cost_base'], 1000));
check('J master data (items, unit conversions) NOT modified by the override', $itemsBefore === $pdo->query("CHECKSUM TABLE items")->fetch(PDO::FETCH_NUM)[1] && $convBefore === $pdo->query("CHECKSUM TABLE item_unit_conversions")->fetch(PDO::FETCH_NUM)[1]);
$ref2 = ItemPriceService::resolveReferencePrice($pdo, $D['id'], $kg);
check('J price history (the only price store) got the new actual price appended, existing behaviour', near($ref2['reference_price'], 1000));

// ============================================================ K. invalid input blocked
$bad = [
    'qty 0' => entry($WH, [line($A, $kg, 0, 1000)]),
    'negative price' => entry($WH, [line($A, $kg, 1, -5)]),
    'item discount > base (Rp)' => entry($WH, [line($A, $kg, 1, 1000, 0, 'AMOUNT', 1001)]),
    'item discount > 100%' => entry($WH, [line($A, $kg, 1, 1000, 0, 'PERCENT', 101)]),
    'ppn 150' => entry($WH, [line($A, $kg, 1, 1000, 150)]),
    'invoice discount > subtotal' => entry($WH, [line($A, $kg, 1, 1000)], ['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 1001]),
    'negative shipping' => entry($WH, [line($A, $kg, 1, 1000)], ['freight_amount' => -1]),
    'unit not approved for the item' => entry($WH, [line($A, $karton, 1, 1000)]),
    'no lines' => entry($WH, []),
    'no warehouse' => entry(0, [line($A, $kg, 1, 1000)]),
    'unknown item' => entry($WH, [['item_id' => 99999999, 'input_unit_id' => $kg, 'input_qty' => 1, 'unit_price_input' => 1]]),
    'no item picked' => entry($WH, [['item_id' => 0, 'input_unit_id' => $kg, 'input_qty' => 1, 'unit_price_input' => 1]]),
];
foreach ($bad as $label => $in) {
    $q = P::quote($pdo, $in);
    $posted = false;
    try { post($pdo, $in, $uid); $posted = true; } catch (ValidationException $e) { /* expected */ }
    check("K blocked: {$label}", !$q['valid'] && !$posted, implode(' | ', $q['errors']));
}
$pdo->exec("UPDATE items SET status='INACTIVE' WHERE id = {$B['id']}");
$qi = P::quote($pdo, entry($WH, [line($B, $kg, 1, 1000)]));
check('K blocked: inactive item', !$qi['valid']);
$pdo->exec("UPDATE items SET status='ACTIVE' WHERE id = {$B['id']}");
$qs = P::quote($pdo, entry($WH, [line($A, $kg, 1, 1000)], ['supplier_id' => 99999999]));
check('K blocked: unknown supplier', !$qs['valid']);

// ============================================================ M. FIFO cost of a later OUT
$E = mkItem($pdo, $kg, $cat, $karton, 'SIVE');
post($pdo, entry($WH, [line($E, $kg, 10, 1000, 0, 'PERCENT', 10)]), $uid);   // unit cost 900
post($pdo, entry($WH, [line($E, $kg, 10, 2000)]), $uid);                       // unit cost 2.000
$out = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => df_uid('siv2out'), 'item_id' => $E['id'], 'warehouse_id' => $WH, 'input_qty' => 15, 'input_unit_id' => $kg,
    'transaction_type' => 'OUT', 'transaction_date' => date('Y-m-d'), 'created_by' => $uid, 'username' => 'siv2',
]));
check('M FIFO consumes the discounted layer first: 10 x 900 + 5 x 2.000 = 19.000 for 15 kg', near((float) $out['base_qty'], 15) && near(round((float) $out['unit_cost_base'] * 15, 2), 19000, 0.5), json_encode($out));

// ============================================================ N. all-or-nothing + anomaly confirm
$F = mkItem($pdo, $kg, $cat, $karton, 'SIVF');
post($pdo, entry($WH, [line($F, $kg, 10, 1000)]), $uid);                       // reference 1.000
$G = mkItem($pdo, $kg, $cat, $karton, 'SIVG');
$cntTx = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$cntB = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$uuidN = df_uid('siv2n');
$threw = false;
try {
    post($pdo, entry($WH, [line($G, $kg, 5, 100), line($F, $kg, 1, 100000)], ['transaction_uuid' => $uuidN]), $uid);   // 2nd row 100x the reference -> anomaly
} catch (PriceAnomalyException $e) { $threw = true; }
check('N price anomaly on row 2 raises PRICE_ANOMALY', $threw);
check('N all-or-nothing: row 1 was rolled back too (no transaction, no batch added)', (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $cntTx && (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn() === $cntB);
$ok = post($pdo, entry($WH, [line($G, $kg, 5, 100), line($F, $kg, 1, 100000)], ['transaction_uuid' => $uuidN, 'anomaly_approved_by' => $uid]), $uid);
check('N retry with the same request uuid + explicit approval posts both rows', count($ok['transactions']) === 2 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $cntTx + 2);

// ============================================================ O. idempotent replay
$uuidO = df_uid('siv2o');
$o1 = post($pdo, entry($WH, [line($A, $kg, 3, 1000), line($B, $kg, 4, 1000)], ['transaction_uuid' => $uuidO]), $uid);
$cntAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$o2 = post($pdo, entry($WH, [line($A, $kg, 3, 1000), line($B, $kg, 4, 1000)], ['transaction_uuid' => $uuidO]), $uid);
check('O replaying the same request uuid creates nothing new and returns the same transactions', $o2['idempotent_replay'] === true && (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $cntAfter && array_column($o2['transactions'], 'transaction_id') === array_column($o1['transactions'], 'transaction_id'));

// ============================================================ P. quote is read-only
$snap = function () use ($pdo): string {
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'purchase_invoice_headers', 'purchase_line_costs', 'item_price_history', 'audit_logs', 'items'] as $t) {
        $o[] = $t . ':' . $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
    }
    return implode('|', $o);
};
$s1 = $snap();
P::quote($pdo, entry($WH, $combo, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 5, 'freight_amount' => 100]));
P::quote($pdo, entry($WH, [line($A, $kg, 0, 1)]));
check('P quote() wrote nothing (counts + checksums unchanged)', $s1 === $snap());
$auditRow = $pdo->query("SELECT after_data FROM audit_logs WHERE action_code='PURCHASE_INVOICE_POST' ORDER BY id DESC LIMIT 1")->fetchColumn();
$audit = json_decode((string) $auditRow, true);
check('invoice-level inputs are audited (mode/value/shipping/grand total/transaction ids)', is_array($audit) && isset($audit['invoice_discount_type'], $audit['grand_total'], $audit['transaction_ids']));

// ============================================================ Q. INVOICE-LEVEL PPN (one rate at the end, on the DPP after the invoice discount)
$qi = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11]));
check('Q rows carry NO PPN: row totals = DPP 10.000 / 20.000; Subtotal 30.000; PPN 11% on the total = 3.300; Grand Total 33.300', near($qi['lines'][0]['ppn'], 0) && near($qi['lines'][0]['total'], 10000) && near($qi['lines'][1]['total'], 20000) && near($qi['totals']['subtotal'], 30000) && near($qi['totals']['ppn_total'], 3300) && near($qi['totals']['grand_total'], 33300) && $qi['totals']['ppn_mode'] === 'INVOICE' && near((float) $qi['totals']['ppn_rate'], 11), json_encode($qi['totals']));
check('Q the PPN is split by net DPP (1.100 / 2.200); creditable PPN is not capitalised: inventory cost 10.000 / 20.000', near($qi['lines'][0]['ppn_final'], 1100) && near($qi['lines'][1]['ppn_final'], 2200) && near($qi['lines'][0]['inventory_cost'], 10000) && near($qi['lines'][1]['inventory_cost'], 20000));
$qd2 = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 3000]));
check('Q invoice discount 10% is taken from the DPP BEFORE PPN: subtotal 30.000 − 3.000 = 27.000; PPN 11% = 2.970; + shipping 3.000 = Grand 32.970', near($qd2['totals']['invoice_discount'], 3000) && near($qd2['totals']['dpp_after_invoice_discount'], 27000) && near($qd2['totals']['ppn_total'], 2970) && near($qd2['totals']['grand_total'], 32970), json_encode($qd2['totals']));
check('Q the discount shares are on the DPP (1.000 / 2.000) and each row\'s PPN follows its net DPP: 990 / 1.980', near($qd2['lines'][0]['invoice_discount_share'], 1000) && near($qd2['lines'][1]['invoice_discount_share'], 2000) && near($qd2['lines'][0]['ppn_final'], 990) && near($qd2['lines'][1]['ppn_final'], 1980));
check('Q identity: DPP after discount + PPN + Shipping = Grand Total', near($qd2['totals']['dpp_after_invoice_discount'] + $qd2['totals']['ppn_total'] + $qd2['totals']['freight_amount'], $qd2['totals']['grand_total'], 0.0001));
$qn2 = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_treatment' => 'NON_CREDITABLE', 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10]));
check('Q non-creditable PPN is capitalised per row: inventory cost 9.000 + 990 = 9.990 and 18.000 + 1.980 = 19.980', near($qn2['lines'][0]['inventory_cost'], 9990) && near($qn2['lines'][1]['inventory_cost'], 19980));
$q0 = P::quote($pdo, entry($WH, [line($A, $kg, 10, 1000, 11), line($B, $kg, 20, 1000, 11)], ['ppn_rate' => 0]));
check('Q a per-item ppn_rate in the lines is IGNORED when the invoice-level rate is given (rate 0 → PPN 0, grand 30.000)', near($q0['totals']['ppn_total'], 0) && near($q0['totals']['grand_total'], 30000));
$qb = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 150]));
check('Q ppn_rate outside 0–100 is rejected', $qb['valid'] === false && str_contains(implode(' ', $qb['errors']), 'ppn_rate'));
$ql = P::quote($pdo, entry($WH, $mixed, ['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10]));
check('Q without a top-level ppn_rate the legacy per-item formula is unchanged (grand 27.990)', $ql['totals']['ppn_mode'] === 'PER_ITEM' && near($ql['totals']['grand_total'], 27990, 0.01));
$ri = post($pdo, entry($WH, $two, ['ppn_rate' => 11, 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 3000, 'supplier_id' => $supplier]), $uid);
$h1 = hdr($pdo, (int) $ri['transactions'][0]['transaction_id']);
$h2 = hdr($pdo, (int) $ri['transactions'][1]['transaction_id']);
check('Q posted: purchase_invoice_headers carry ppn_rate 11 and PPN 990 / 1.980 per line; grand total Rp 32.970; FIFO cost 9.000 / 18.000', near((float) $h1['ppn_rate'], 11) && near((float) $h1['ppn_amount'], 990) && near((float) $h2['ppn_amount'], 1980) && near($ri['totals']['grand_total'], 32970) && near((float) costRow($pdo, (int) $ri['transactions'][0]['transaction_id'])['final_inventory_cost'], 9000) && near((float) costRow($pdo, (int) $ri['transactions'][1]['transaction_id'])['final_inventory_cost'], 18000));
$auditQ = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code='PURCHASE_INVOICE_POST' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
check('Q the invoice-level PPN is audited (mode INVOICE, rate 11, PPN total 2.970)', ($auditQ['ppn_mode'] ?? '') === 'INVOICE' && near((float) ($auditQ['ppn_rate'] ?? 0), 11) && near((float) ($auditQ['ppn_total'] ?? 0), 2970));

// ============================================================ R. ADJUSTABLE PPN (exact amount from the supplier invoice)
$ra = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 3310]));
check('R adjusted PPN 3.310 replaces the 3.300 calculation: PPN total 3.310, Grand 33.310, flagged adjusted, calculated 3.300, difference +10', $ra['valid'] && near($ra['totals']['ppn_total'], 3310) && near($ra['totals']['grand_total'], 33310) && $ra['totals']['ppn_adjusted'] === true && near($ra['totals']['ppn_calculated'], 3300) && near($ra['totals']['ppn_difference'], 10), json_encode($ra['totals']));
check('R the adjusted PPN is spread over the rows by DPP and sums exactly to 3.310', near($ra['lines'][0]['ppn_final'] + $ra['lines'][1]['ppn_final'], 3310, 0.0001) && near($ra['lines'][0]['ppn_final'], 3310 / 3, 0.0001));
check('R without ppn_amount nothing is adjusted (ppn_adjusted false, difference 0)', $qi['totals']['ppn_adjusted'] === false && near($qi['totals']['ppn_difference'], 0));
$rd = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 2975, 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 3000]));
check('R with invoice discount: DPP after discount 27.000 + adjusted PPN 2.975 + shipping 3.000 = Grand 32.975 (calculated 2.970, +5)', near($rd['totals']['dpp_after_invoice_discount'], 27000) && near($rd['totals']['ppn_total'], 2975) && near($rd['totals']['grand_total'], 32975) && near($rd['totals']['ppn_difference'], 5), json_encode($rd['totals']));
$rn = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 3310, 'ppn_treatment' => 'NON_CREDITABLE']));
check('R non-creditable: the adjusted PPN is capitalised — inventory cost total 30.000 + 3.310 = 33.310', near(array_sum(array_column($rn['lines'], 'inventory_cost')), 33310, 0.01), json_encode(array_column($rn['lines'], 'inventory_cost')));
$rz = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 0, 'ppn_amount' => 3000]));
check('R a supplier-printed PPN can be entered even when the rate selector is 0%: PPN 3.000, Grand 33.000', $rz['valid'] && near($rz['totals']['ppn_total'], 3000) && near($rz['totals']['grand_total'], 33000));
$r0 = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 0]));
check('R an explicit PPN of Rp 0 is honoured (not treated as "empty"): PPN 0, Grand 30.000', $r0['valid'] && near($r0['totals']['ppn_total'], 0) && near($r0['totals']['grand_total'], 30000) && $r0['totals']['ppn_adjusted'] === true);
$rneg = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => -5]));
check('R negative PPN amount is rejected', $rneg['valid'] === false && str_contains(implode(' ', $rneg['errors']), 'PPN'));
$rbig = P::quote($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 30001]));
check('R PPN above the DPP after discount is rejected', $rbig['valid'] === false && str_contains(implode(' ', $rbig['errors']), 'PPN'));
$rp = post($pdo, entry($WH, $two, ['ppn_rate' => 11, 'ppn_amount' => 3310, 'supplier_id' => $supplier]), $uid);
$p1 = hdr($pdo, (int) $rp['transactions'][0]['transaction_id']);
$p2 = hdr($pdo, (int) $rp['transactions'][1]['transaction_id']);
check('R posted: purchase_invoice_headers PPN amounts sum to 3.310; grand total Rp 33.310', near((float) $p1['ppn_amount'] + (float) $p2['ppn_amount'], 3310, 0.001) && near($rp['totals']['grand_total'], 33310));
$auditR = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code='PURCHASE_INVOICE_POST' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
check('R the adjustment is audited (ppn_adjusted true, calculated 3.300, total 3.310)', ($auditR['ppn_adjusted'] ?? false) === true && near((float) ($auditR['ppn_calculated'] ?? 0), 3300) && near((float) ($auditR['ppn_total'] ?? 0), 3310));

$fail = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $fail) . ' / ' . count($results) . ' PASSED' . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
