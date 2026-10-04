<?php
declare(strict_types=1);

/**
 * STOCK OUT V2 (StockOutService + StockOutDocumentService → /stock-out/*).
 * Real MariaDB, the application's own FIFO engine, the existing Distribusi
 * tables. Expectations are hand-computed. The first numbers are the approved
 * mockup's own example (Packaging 20 %, Aksesoris 25 %, Bahan 30 %, Biaya Kirim
 * 25.000 → Subtotal 835.750 → Grand Total 860.750).
 *
 *   A percent markup · B nominal markup · C same category inherits · D several categories
 *   E markup change recalculates · F non-base unit + G stock follows the unit · H insufficient stock
 *   I shipping · J subtotal + shipping = grand · K FIFO reduction · L selling price never touches FIFO cost
 *   M DO has no price data · N Invoice has no cost leakage · O preview == printed == reprint
 *   P numbering + idempotency · Q scope/permissions over HTTP · R read-only quote/preview
 *
 * Usage: DB_DATABASE=... php tests/stock_out_v2_test.php
 */

require_once __DIR__ . '/lib/dashboard_fixture.php';

use App\Services\Database;
use App\Services\InsufficientStockException;
use App\Services\PricingPolicyService;
use App\Services\StockOutDocumentService as Doc;
use App\Services\StockOutService as S;
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
$pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$karton = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$mkUser = function (string $tag, string $roleCode, ?int $wh = null) use ($pdo): array {
    $role = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $u = df_uid($tag);
    $pass = 'So' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $role, 'w' => $wh]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
};
$WA = df_wh($pdo, 'SOV2 Gudang Cibadak');
$WB = df_wh($pdo, 'SOV2 Gudang Lain');
$admin = $mkUser('soadmin', 'SUPERADMIN');
$stockA = $mkUser('sostockA', 'STOCK', $WA);
$viewer = $mkUser('soviewer', 'VIEWER');
$uid = $admin['id'];
$pdo->prepare("INSERT INTO bakery_destinations (code, name, address, pic_name, phone, is_active) VALUES (:c,'Amor Bakery - Pusat','Jl. Sudirman No. 123, Bandung 40111','Budi Santoso','0812-3456-7890',1)")->execute(['c' => df_uid('BKR')]);
$bakery = (int) $pdo->lastInsertId();
$catId = [];
foreach (['Packaging', 'Aksesoris', 'Bahan'] as $n) {
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('CAT'), 'n' => $n]);
    $catId[$n] = (int) $pdo->lastInsertId();
}
$date = date('Y-m-d');

/** item with a real purchase (reference price + FIFO layer) */
function mk(PDO $pdo, string $cat, int $catId, int $unit, float $price, float $qty, int $wh, int $uid, string $tag, ?int $extraUnit = null, float $factor = 0): array
{
    $it = df_item($pdo, $unit, $tag, $catId);
    if ($extraUnit !== null) {
        UnitConversionService::openNewVersion($pdo, $it['id'], $extraUnit, $factor, '2020-01-01 00:00:00', null, 'x');
    }
    df_in($it['id'], $wh, $qty, $price, '2026-01-05 08:00:00', $uid, $unit, 'IN', 'PO-' . $tag);
    return $it;
}
function ln(array $item, int $unit, float $qty): array
{
    return ['item_id' => $item['id'], 'input_unit_id' => $unit, 'input_qty' => $qty];
}
function ent(int $wh, int $bakery, array $lines, array $mk, array $extra = []): array
{
    return array_merge(['warehouse_id' => $wh, 'bakery_destination_id' => $bakery, 'transaction_date' => date('Y-m-d'), 'lines' => $lines, 'markups' => $mk], $extra);
}
function postOut(PDO $pdo, array $in, int $uid, ?string $uuid = null): array
{
    $in['transaction_uuid'] = $uuid ?? df_uid('soreq');
    return Database::transaction(fn (PDO $tx) => S::post($tx, $in, $uid, 'sov2'));
}
$P = fn (float $v) => ['mode' => 'PERCENT', 'value' => $v];
$N = fn (float $v) => ['mode' => 'AMOUNT', 'value' => $v];

// the mockup's six items (cost = latest purchase price)
$box = mk($pdo, 'Packaging', $catId['Packaging'], $pcs, 4000, 500, $WA, $uid, 'SOBOX');
$bag = mk($pdo, 'Packaging', $catId['Packaging'], $pcs, 1500, 1000, $WA, $uid, 'SOBAG');
$stk = mk($pdo, 'Packaging', $catId['Packaging'], $pcs, 300, 2000, $WA, $uid, 'SOSTK');
$lilin = mk($pdo, 'Aksesoris', $catId['Aksesoris'], $pcs, 2000, 200, $WA, $uid, 'SOLIL');
$topper = mk($pdo, 'Aksesoris', $catId['Aksesoris'], $pcs, 3500, 300, $WA, $uid, 'SOTOP');
$tepung = mk($pdo, 'Bahan', $catId['Bahan'], $kg, 12500, 100, $WA, $uid, 'SOTEP');
$mkp = [(string) $catId['Packaging'] => $P(20), (string) $catId['Aksesoris'] => $P(25), (string) $catId['Bahan'] => $P(30)];

// ============================================================ A. one item, percent
$q = S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 50)], $mkp));
$l0 = $q['lines'][0];
check('A percent markup: Harga Modal 4.000 + 20% = Harga Jual 4.800, Total 240.000', $q['valid'] && near($l0['reference_price'], 4000) && near($l0['selling_price'], 4800) && near($l0['total'], 240000) && near($q['totals']['grand_total'], 240000), json_encode($q['errors']));
check('A row carries category, stock (500 pcs) and its markup', $l0['category_name'] === 'Packaging' && near($l0['stock_base'], 500) && $l0['markup_mode'] === 'PERCENT' && near($l0['markup_value'], 20));

// ============================================================ B. nominal
$q = S::quote($pdo, ent($WA, $bakery, [ln($lilin, $pcs, 20)], [(string) $catId['Aksesoris'] => $N(500)]));
check('B nominal markup: 2.000 + Rp 500 = 2.500, qty 20 → 50.000', near($q['lines'][0]['selling_price'], 2500) && near($q['lines'][0]['total'], 50000) && $q['lines'][0]['markup_mode'] === 'AMOUNT');

// ============================================================ C/D. mockup example
$mock = [ln($box, $pcs, 50), ln($bag, $pcs, 100), ln($stk, $pcs, 200), ln($lilin, $pcs, 20), ln($topper, $pcs, 30), ln($tepung, $kg, 10)];
$qm = S::quote($pdo, ent($WA, $bakery, $mock, $mkp, ['shipping_amount' => 25000]));
$tot = array_column($qm['lines'], 'total');
$sell = array_column($qm['lines'], 'selling_price');
check('C all Packaging rows inherit 20%: 4.800 / 1.800 / 360 → 240.000 / 180.000 / 72.000', near($sell[0], 4800) && near($sell[1], 1800) && near($sell[2], 360) && near($tot[0], 240000) && near($tot[1], 180000) && near($tot[2], 72000));
check('D Aksesoris 25%: 2.500 / 4.375 → 50.000 / 131.250 ; Bahan 30%: 16.250 → 162.500', near($sell[3], 2500) && near($sell[4], 4375) && near($tot[3], 50000) && near($tot[4], 131250) && near($sell[5], 16250) && near($tot[5], 162500));
check('I+J Subtotal 835.750 + Biaya Kirim 25.000 = Grand Total 860.750; 6 jenis; Total Qty 410', near($qm['totals']['subtotal'], 835750) && near($qm['totals']['shipping_amount'], 25000) && near($qm['totals']['grand_total'], 860750) && $qm['totals']['total_items'] === 6 && near($qm['totals']['total_qty'], 410) && $qm['totals']['qty_unit'] === null, json_encode($qm['totals']));

// ============================================================ E. markup change recalculates
$mk2 = $mkp; $mk2[(string) $catId['Packaging']] = $P(10);
$qe = S::quote($pdo, ent($WA, $bakery, $mock, $mk2, ['shipping_amount' => 25000]));
check('E changing Packaging 20% → 10% reprices exactly its three rows (4.400/1.650/330) and nothing else', near($qe['lines'][0]['selling_price'], 4400) && near($qe['lines'][1]['selling_price'], 1650) && near($qe['lines'][2]['selling_price'], 330) && near($qe['lines'][3]['selling_price'], 2500) && near($qe['lines'][5]['selling_price'], 16250));

// ============================================================ markup rules
$qn = S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], []));
check('markup missing for a category present → blocked (never assumed)', !$qn['valid'] && str_contains(implode('|', $qn['errors']), 'markup kategori Packaging belum diisi'));
$qz = S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], [(string) $catId['Packaging'] => $P(0)]));
check('explicit 0 markup is accepted: selling price = Harga Modal', $qz['valid'] && near($qz['lines'][0]['selling_price'], 4000));
check('negative markup / bad mode blocked', !S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], [(string) $catId['Packaging'] => $P(-1)]))['valid'] && !S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], [(string) $catId['Packaging'] => ['mode' => 'XX', 'value' => 1]]))['valid']);
PricingPolicyService::upsert($pdo, ['scope' => 'CATEGORY', 'category_id' => $catId['Packaging'], 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 22, 'created_by' => $uid, 'username' => 'sov2']);
PricingPolicyService::upsert($pdo, ['scope' => 'CATEGORY', 'category_id' => $catId['Aksesoris'], 'pricing_method' => 'COST_PLUS_AMOUNT', 'margin_value' => 2000, 'created_by' => $uid, 'username' => 'sov2']);
$def = S::markupDefaults($pdo, [$catId['Packaging'], $catId['Aksesoris'], $catId['Bahan']]);
check('defaults come from the existing Distribusi pricing policies (shown, editable, never silently applied)', $def[(string) $catId['Packaging']]['mode'] === 'PERCENT' && near($def[(string) $catId['Packaging']]['value'], 22) && $def[(string) $catId['Aksesoris']]['mode'] === 'AMOUNT' && near($def[(string) $catId['Aksesoris']]['value'], 2000) && !isset($def[(string) $catId['Bahan']]));

// ============================================================ F/G/H. non-base unit, stock in unit, insufficient
$sak = mk($pdo, 'Bahan', $catId['Bahan'], $kg, 1000, 192, $WA, $uid, 'SOSAK', $karton, 24.0);
$qf = S::quote($pdo, ent($WA, $bakery, [ln($sak, $karton, 2)], [(string) $catId['Bahan'] => $P(25)]));
$lf = $qf['lines'][0];
check('G Stok Tersedia follows the unit: 192 kg base = 8 KARTON', near($lf['stock_base'], 192) && near($lf['stock_in_unit'], 8) && $lf['unit_code'] === 'KARTON');
check('F non-base unit: Harga Modal per KARTON = 1.000 x 24 = 24.000 (derived, never base price x carton qty); +25% = 30.000; 2 karton = 60.000; base 48 kg', near($lf['reference_price'], 24000) && near($lf['selling_price'], 30000) && near($lf['total'], 60000) && near($lf['base_qty'], 48));
$qh = S::quote($pdo, ent($WA, $bakery, [ln($sak, $karton, 9)], [(string) $catId['Bahan'] => $P(25)]));
check('H insufficient stock blocked, message shows requested AND available in the selected unit', !$qh['valid'] && str_contains(implode('|', $qh['errors']), 'diminta 9 KARTON, tersedia 8 KARTON'), implode('|', $qh['errors']));
$qh2 = S::quote($pdo, ent($WA, $bakery, [ln($sak, $karton, 5), ln($sak, $kg, 100)], [(string) $catId['Bahan'] => $P(25)]));
check('H the same item on two rows is summed against stock (120 + 100 kg > 192)', !$qh2['valid']);
$threw = false;
try { postOut($pdo, ent($WA, $bakery, [ln($sak, $karton, 9)], [(string) $catId['Bahan'] => $P(25)]), $uid); } catch (ValidationException $e) { $threw = true; }
check('H posting an insufficient-stock entry is refused and posts nothing', $threw && (float) $pdo->query("SELECT SUM(qty_base) FROM inventory_batches WHERE item_id={$sak['id']} AND warehouse_id={$WA}")->fetchColumn() == 192.0);

// ============================================================ K/L/M/N/O/P. post the mockup entry
$beforeCost = [];
foreach ($mock as $m) {
    $beforeCost[$m['item_id']] = (float) $pdo->query("SELECT unit_cost_base FROM inventory_batches WHERE item_id={$m['item_id']} AND warehouse_id={$WA} ORDER BY id LIMIT 1")->fetchColumn();
}
$previewDo = Doc::renderDo(Doc::doModelFromQuote($qm, ent($WA, $bakery, $mock, $mkp, ['shipping_amount' => 25000, 'reference_no' => 'REF-001', 'notes' => 'Pengiriman rutin']), $admin['username']));
$previewInv = Doc::renderInvoice(Doc::invoiceModelFromQuote($qm, ent($WA, $bakery, $mock, $mkp, ['shipping_amount' => 25000, 'reference_no' => 'REF-001', 'notes' => 'Pengiriman rutin'])));
$uuid = df_uid('soreq');
$r = postOut($pdo, ent($WA, $bakery, $mock, $mkp, ['shipping_amount' => 25000, 'reference_no' => 'REF-001', 'notes' => 'Pengiriman rutin']), $uid, $uuid);
check('post → DO + Invoice with numbers; DO DISPATCHED, Invoice ISSUED; totals 835.750 / 25.000 / 860.750', preg_match('/^DO-\d{8}-\d{4}$/', $r['do_number']) === 1 && preg_match('/^INV-\d{8}-\d{4}$/', $r['invoice_number']) === 1 && $r['do_status'] === 'DISPATCHED' && $r['invoice_status'] === 'ISSUED' && near((float) $r['subtotal'], 835750) && near((float) $r['shipping_amount'], 25000) && near((float) $r['grand_total'], 860750) && (int) $r['item_count'] === 6, json_encode($r));
$outs = $pdo->prepare("SELECT t.id, t.transaction_type, t.status, t.reference_no, t.bakery_destination_id, t.warehouse_id, l.item_id, l.base_qty, l.unit_cost_base FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id WHERE t.reference_no = :r ORDER BY l.item_id");
$outs->execute(['r' => $r['do_number']]);
$outRows = $outs->fetchAll();
check('K six real OUT transactions (type OUT, POSTED, bakery set, reference = DO number)', count($outRows) === 6 && count(array_filter($outRows, fn ($o) => $o['transaction_type'] === 'OUT' && $o['status'] === 'POSTED' && (int) $o['bakery_destination_id'] === $bakery && (int) $o['warehouse_id'] === $WA)) === 6);
$stockNow = fn (array $it) => (float) $pdo->query("SELECT SUM(qty_base) FROM inventory_batches WHERE item_id={$it['id']} AND warehouse_id={$WA}")->fetchColumn();
check('K FIFO stock reduced exactly: box 500→450, bag 1000→900, stiker 2000→1800, lilin 200→180, topper 300→270, tepung 100→90', near($stockNow($box), 450) && near($stockNow($bag), 900) && near($stockNow($stk), 1800) && near($stockNow($lilin), 180) && near($stockNow($topper), 270) && near($stockNow($tepung), 90));
$costOk = true;
foreach ($outRows as $o) { $costOk = $costOk && near((float) $o['unit_cost_base'], $beforeCost[(int) $o['item_id']]); }
check('L OUT lines hold the real FIFO cost (HPP 4.000/1.500/300/2.000/3.500/12.500) — NOT the selling price', $costOk);
$batchCostSame = true;
foreach ($mock as $m) { $batchCostSame = $batchCostSame && near((float) $pdo->query("SELECT unit_cost_base FROM inventory_batches WHERE item_id={$m['item_id']} AND warehouse_id={$WA} ORDER BY id LIMIT 1")->fetchColumn(), $beforeCost[$m['item_id']]); }
check('L the remaining FIFO layers keep their original cost', $batchCostSame);

$doHtml = Doc::renderDo(Doc::doModel($pdo, (int) $r['do_id']));
$invHtml = Doc::renderInvoice(Doc::invoiceModel($pdo, (int) $r['invoice_id']));
check('M DO: company, bakery name/address/PIC/contact, origin warehouse, items with qty + unit, number', str_contains($doHtml, 'CV AMOR GROUP') && str_contains($doHtml, 'DELIVERY ORDER') && str_contains($doHtml, 'Amor Bakery - Pusat') && str_contains($doHtml, 'Jl. Sudirman No. 123') && str_contains($doHtml, 'Budi Santoso') && str_contains($doHtml, '0812-3456-7890') && str_contains($doHtml, 'SOV2 Gudang Cibadak') && str_contains($doHtml, $r['do_number']) && str_contains($doHtml, 'amor-logo.jpg') && str_contains($doHtml, 'Disiapkan oleh') && str_contains($doHtml, 'Diterima oleh'));
$bodyOnly = static fn (string $h): string => (string) preg_replace('#<style>.*?</style>#s', '', $h);
$doText = strip_tags($bodyOnly($doHtml));
check('M DO shows NO price data: no "Rp", no Harga/Markup/HPP/Modal/Total, none of the money figures', !preg_match('/Rp|Harga|Markup|HPP|Modal|Subtotal|Grand|Biaya|Total/i', $doText) && !str_contains($doText, '240.000') && !str_contains($doText, '4.800'));
$invText = strip_tags($bodyOnly($invHtml));
check('N Invoice: selling prices, totals, subtotal, Biaya Kirim, Grand Total', str_contains($invHtml, 'INVOICE') && str_contains($invHtml, 'Rp 4.800') && str_contains($invHtml, 'Rp 4.375') && str_contains($invHtml, 'Rp 16.250') && str_contains($invHtml, 'Rp 240.000') && str_contains($invHtml, 'Rp 835.750') && str_contains($invHtml, 'Rp 25.000') && str_contains($invHtml, 'Rp 860.750') && str_contains($invHtml, $r['invoice_number']) && str_contains($invHtml, 'REF-001') && str_contains($invHtml, 'Kepada Yth.'));
check('N Invoice does NOT leak cost: no Harga Modal/Harga Beli/HPP/Markup/Margin/FIFO text and none of the cost prices (Rp 4.000, 1.500, 300, 2.000, 3.500, 12.500)', !preg_match('/Modal|Harga Beli|HPP|Markup|Margin|FIFO|Biaya Pokok|cost/i', $invText) && !preg_match('/Rp (4\.000|1\.500|300|2\.000|3\.500|12\.500)(?![\d.])/', $invText) && !str_contains($invText, '%'));
$norm = static fn (string $h, ?string $num): string => $num === null ? $h : str_replace('Nomor otomatis saat disimpan', $num, $h);
check('O preview == printed: the DO preview HTML equals the saved DO HTML once the number is assigned (same renderer, same state)', $norm(str_replace('<title>Delivery Order </title>', '<title>Delivery Order ' . $r['do_number'] . '</title>', $previewDo), $r['do_number']) === $doHtml);
check('O preview == printed: the Invoice preview equals the saved Invoice HTML once the number is assigned', $norm(str_replace('<title>Invoice </title>', '<title>Invoice ' . $r['invoice_number'] . '</title>', $previewInv), $r['invoice_number']) === $invHtml);
check('O reprint later is identical (render twice from the database)', $doHtml === Doc::renderDo(Doc::doModel($pdo, (int) $r['do_id'])) && $invHtml === Doc::renderInvoice(Doc::invoiceModel($pdo, (int) $r['invoice_id'])));

// cost / selling basis kept side by side in the invoice rows
$il = $pdo->query("SELECT reference_purchase_price, pricing_source, pricing_method, margin_value, selling_unit_price, subtotal FROM distribution_invoice_lines WHERE invoice_id = {$r['invoice_id']} ORDER BY line_no")->fetchAll();
check('invoice rows store BOTH bases: cost (reference price) and selling (method/margin/price)', near((float) $il[0]['reference_purchase_price'], 4000) && $il[0]['pricing_source'] === 'CATEGORY' && $il[0]['pricing_method'] === 'COST_PLUS_PERCENT' && near((float) $il[0]['margin_value'], 20) && near((float) $il[0]['selling_unit_price'], 4800) && near((float) $il[5]['reference_purchase_price'], 12500) && near((float) $il[5]['selling_unit_price'], 16250));
$byTx = S::byTransaction($pdo, (int) $outRows[0]['id']);
check('history link: an OUT transaction resolves to its DO + Invoice (reprint from History Transaksi)', $byTx !== null && (int) $byTx['do_id'] === (int) $r['do_id'] && (int) $byTx['invoice_id'] === (int) $r['invoice_id']);

// ============================================================ P. numbering + idempotency
$cnt = (int) $pdo->query('SELECT COUNT(*) FROM distribution_orders')->fetchColumn();
$cntTx = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$again = postOut($pdo, ent($WA, $bakery, $mock, $mkp, ['shipping_amount' => 25000, 'reference_no' => 'REF-001', 'notes' => 'Pengiriman rutin']), $uid, $uuid);
check('P the same request uuid replays: same DO/Invoice, nothing new created, stock not reduced twice', $again['idempotent_replay'] === true && $again['do_number'] === $r['do_number'] && (int) $pdo->query('SELECT COUNT(*) FROM distribution_orders')->fetchColumn() === $cnt && (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $cntTx && near($stockNow($box), 450));
$r2 = postOut($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], [(string) $catId['Packaging'] => $P(20)]), $uid);
$seq = fn (string $n): int => (int) substr($n, -4);
check('P next DO / Invoice numbers are the next sequence (unique, server-side)', $seq($r2['do_number']) === $seq($r['do_number']) + 1 && $seq($r2['invoice_number']) === $seq($r['invoice_number']) + 1 && $r2['do_number'] !== $r['do_number']);

// ============================================================ validation of the header
check('Gudang Asal / Bakery Tujuan required; inactive bakery blocked', !S::quote($pdo, ent(0, $bakery, [ln($box, $pcs, 1)], $mkp))['valid'] && !S::quote($pdo, ent($WA, 0, [ln($box, $pcs, 1)], $mkp))['valid']);
$pdo->exec("UPDATE bakery_destinations SET is_active = 0 WHERE id = {$bakery}");
check('inactive Bakery Tujuan blocked', !S::quote($pdo, ent($WA, $bakery, [ln($box, $pcs, 1)], $mkp))['valid']);
$pdo->exec("UPDATE bakery_destinations SET is_active = 1 WHERE id = {$bakery}");
$bad = [
    'qty 0' => ent($WA, $bakery, [ln($box, $pcs, 0)], $mkp), 'negative shipping' => ent($WA, $bakery, [ln($box, $pcs, 1)], $mkp, ['shipping_amount' => -1]),
    'unit not approved' => ent($WA, $bakery, [ln($box, $karton, 1)], $mkp), 'no lines' => ent($WA, $bakery, [], $mkp),
    'unknown item' => ent($WA, $bakery, [['item_id' => 99999999, 'input_unit_id' => $pcs, 'input_qty' => 1]], $mkp),
];
foreach ($bad as $label => $in) { check("blocked: {$label}", !S::quote($pdo, $in)['valid']); }
$noPrice = df_item($pdo, $pcs, 'SONOPRICE', $catId['Packaging']);
df_in($noPrice['id'], $WA, 5, 100, '2026-01-06 08:00:00', $uid, $pcs, 'OPENING');
$pdo->exec("DELETE FROM item_price_history WHERE item_id = {$noPrice['id']}");
$qnp = S::quote($pdo, ent($WA, $bakery, [ln($noPrice, $pcs, 1)], $mkp));
check('an item with no purchase price history (no Harga Modal) is blocked with a clear message', !$qnp['valid'] && str_contains(implode('|', $qnp['errors']), 'belum ada Harga Modal'));

// ============================================================ R. read-only quote / preview
$snap = function () use ($pdo): string {
    $o = [];
    foreach (['inventory_transactions', 'inventory_batches', 'distribution_orders', 'distribution_order_lines', 'distribution_invoices', 'distribution_invoice_lines', 'document_number_sequences', 'item_price_history', 'audit_logs', 'items'] as $t) {
        $o[] = $t . ':' . $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
    }
    return implode('|', $o);
};
$s1 = $snap();
S::quote($pdo, ent($WA, $bakery, $mock, $mkp));
Doc::renderInvoice(Doc::invoiceModelFromQuote($qm, ent($WA, $bakery, $mock, $mkp)));
S::markupDefaults($pdo, [$catId['Packaging']]);
check('R quote / preview / defaults wrote nothing and consumed no document number', $s1 === $snap());

// ============================================================ Q. HTTP
echo "\n===== HTTP =====\n";
$port = 8900 + random_int(4000, 4400);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null, bool $raw = false): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $out = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => $raw ? (string) $out : (json_decode((string) $out, true) ?: [])];
}
function login(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'so_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $a = login($base, $admin); $sa = login($base, $stockA); $v = login($base, $viewer);
    check('logins', $a['status'] === 200 && $sa['status'] === 200 && $v['status'] === 200);
    // a transit-warehouse user (scoped to WA) issues from WA
    $sbox = ['warehouse_id' => $WA, 'bakery_destination_id' => $bakery, 'transaction_date' => $date, 'lines' => [ln($box, $pcs, 2)], 'markups' => [(string) $catId['Packaging'] => $P(20)]];
    $rq = http('POST', "{$base}/stock-out/quote", $sbox, $sa['jar'], $sa['csrf']);
    check('Q scoped user quotes from its own warehouse', $rq['status'] === 200 && $rq['body']['data']['valid'] === true && near($rq['body']['data']['totals']['grand_total'], 9600));
    $other = $sbox; $other['warehouse_id'] = $WB;
    check('Q scoped user CANNOT quote / post / preview from another warehouse (403)', http('POST', "{$base}/stock-out/quote", $other, $sa['jar'], $sa['csrf'])['status'] === 403 && http('POST', "{$base}/stock-out", $other + ['transaction_uuid' => df_uid('x')], $sa['jar'], $sa['csrf'])['status'] === 403 && http('POST', "{$base}/stock-out/preview/invoice", $other, $sa['jar'], $sa['csrf'], true)['status'] === 403);
    $rp = http('POST', "{$base}/stock-out", $sbox + ['transaction_uuid' => df_uid('httpso')], $sa['jar'], $sa['csrf']);
    check('Q scoped user posts from its own warehouse → DO + Invoice', $rp['status'] === 200 && !empty($rp['body']['data']['do_number']) && !empty($rp['body']['data']['invoice_number']), json_encode($rp['body']));
    $doId = (int) ($rp['body']['data']['do_id'] ?? 0);
    $rdo = http('GET', "{$base}/stock-out/{$doId}/print/do", null, $sa['jar'], null, true);
    $rinv = http('GET', "{$base}/stock-out/{$doId}/print/invoice", null, $sa['jar'], null, true);
    check('Q reprint over HTTP: DO and Invoice HTML for the same user', $rdo['status'] === 200 && str_contains($rdo['body'], 'DELIVERY ORDER') && $rinv['status'] === 200 && str_contains($rinv['body'], 'INVOICE') && str_contains($rinv['body'], 'Rp 4.800'));
    // a DO from the other warehouse is not visible to the scoped user
    $ob = ['warehouse_id' => $WB, 'bakery_destination_id' => $bakery, 'transaction_date' => $date, 'lines' => [ln($lilin, $pcs, 1)], 'markups' => [(string) $catId['Aksesoris'] => $P(10)]];
    df_in($lilin['id'], $WB, 10, 2000, '2026-01-07 08:00:00', $uid, $pcs, 'IN', 'PO-B');
    $rob = http('POST', "{$base}/stock-out", $ob + ['transaction_uuid' => df_uid('httpob')], $a['jar'], $a['csrf']);
    $obId = (int) ($rob['body']['data']['do_id'] ?? 0);
    check('Q SUPERADMIN may issue from another warehouse', $rob['status'] === 200 && $obId > 0, json_encode($rob['body']));
    check('Q scoped user cannot view / print another warehouse\'s DO or Invoice (403)', http('GET', "{$base}/stock-out/{$obId}", null, $sa['jar'])['status'] === 403 && http('GET', "{$base}/stock-out/{$obId}/print/do", null, $sa['jar'], null, true)['status'] === 403 && http('GET', "{$base}/stock-out/{$obId}/print/invoice", null, $sa['jar'], null, true)['status'] === 403);
    $recent = http('GET', "{$base}/stock-out/recent", null, $sa['jar']);
    check('Q "Riwayat" lists only the scoped user\'s warehouse', $recent['status'] === 200 && count($recent['body']['data']) >= 1 && count(array_filter($recent['body']['data'], fn ($x) => $x['warehouse_name'] !== 'SOV2 Gudang Cibadak')) === 0);
    check('Q VIEWER (no TRANSACTION_OUT_CREATE): cannot quote/post/preview (403)', http('POST', "{$base}/stock-out/quote", $sbox, $v['jar'], $v['csrf'])['status'] === 403 && http('POST', "{$base}/stock-out", $sbox + ['transaction_uuid' => df_uid('x')], $v['jar'], $v['csrf'])['status'] === 403);
    $vinv = http('GET', "{$base}/stock-out/{$doId}/print/invoice", null, $v['jar'], null, true);
    $vdo = http('GET', "{$base}/stock-out/{$doId}/print/do", null, $v['jar'], null, true);
    check('Q VIEWER may view the DO (no prices) but NOT the Invoice (selling prices)', $vdo['status'] === 200 && !str_contains($vdo['body'], 'Rp ') && $vinv['status'] === 403);
    check('Q unauthenticated → 401', http('POST', "{$base}/stock-out/quote", $sbox)['status'] === 401 && http('GET', "{$base}/stock-out/{$doId}/print/do", null, null, null, true)['status'] === 401);
    $pv = http('POST', "{$base}/stock-out/preview/invoice", $sbox, $sa['jar'], $sa['csrf'], true);
    $pvDo = http('POST', "{$base}/stock-out/preview/do", $sbox, $sa['jar'], $sa['csrf'], true);
    check('Q preview endpoints return the printable HTML (no number yet); invalid entries are 422 not HTML', $pv['status'] === 200 && str_contains($pv['body'], 'Nomor otomatis saat disimpan') && $pvDo['status'] === 200 && http('POST', "{$base}/stock-out/preview/invoice", ['warehouse_id' => $WA] , $sa['jar'], $sa['csrf'], true)['status'] === 422);
    $bt = http('GET', "{$base}/stock-out/by-transaction/" . (int) $outRows[0]['id'], null, $a['jar']);
    check('Q by-transaction resolves a DO for a Stock OUT V2 transaction and null for a plain one', $bt['status'] === 200 && (int) ($bt['body']['data']['do_id'] ?? 0) === (int) $r['do_id']);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$fail = count(array_filter($results, fn ($x) => !$x));
echo "\n" . (count($results) - $fail) . ' / ' . count($results) . ' PASSED' . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
