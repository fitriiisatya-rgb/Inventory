<?php
declare(strict_types=1);

/**
 * Karang Tengah OPENING BALANCE (scripts/rv3/kt_opening_lib.php + kt_opening.php) against the REAL physical-SO workbook (tests/fixtures/karang/Hasil_SO_Factory_Karang.xlsx,
 * 380 rows / 284 with stock / total Rp 327.364.108,46) and synthetic workbooks for every blocker. Proves: mapping statuses, case-normalised unit matching, inverse HPP conversion
 * (value invariant), blockers, idempotency, preview-sha binding, posting as OPENING (not IN / purchase / transfer / adjustment), transaction == on-hand == FIFO layer,
 * the Reports V3 classification (October OPENING, Stock IN 0), nothing else touched, and the last-resort rollback.
 *
 * Usage: php tests/karang_opening_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/../scripts/rv3/kt_opening_lib.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\FifoService;
use App\Services\MovementReportV3Service;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
$pdo = Database::connection();
$root = dirname(__DIR__);
$real = $root . '/tests/fixtures/karang/Hasil_SO_Factory_Karang.xlsx';
$tmp = sys_get_temp_dir() . '/kt_test_' . bin2hex(random_bytes(4));
mkdir($tmp);

// ---------------------------------------------------------------- master fixture: units are the seeded ones; items = every code of the real workbook (base unit = its source UoM)
$unitOf = ['KG' => 'KG', 'PCS' => 'PCS', 'GRAM' => 'GR', 'GR' => 'GR', 'ML' => 'ML', 'PACK' => 'PACK', 'SET' => 'SET', 'LITER' => 'LTR', 'JAR' => 'JAR', 'PAIL' => 'PAIL', 'ROLL' => 'ROLL'];
$unitId = [];
foreach ($pdo->query('SELECT id, code FROM units')->fetchAll() as $u) {
    $unitId[$u['code']] = (int) $u['id'];
}
$src = kt_read_source($real);
$insItem = $pdo->prepare("INSERT INTO items (sku, name, base_unit_id, status) VALUES (:s, :n, :u, 'ACTIVE')");
foreach ($src['rows'] as $r) {
    $insItem->execute(['s' => $r['code'], 'n' => trim($r['name']), 'u' => $unitId[$unitOf[kt_norm_unit($r['uom'])]]]);
}
$superRole = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$adminRole = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$mkUser = static function (string $name, int $role) use ($pdo): int {
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u, 'x', :f, :r, 1)")->execute(['u' => $name, 'f' => $name, 'r' => $role]);
    return (int) $pdo->lastInsertId();
};
$superId = $mkUser('kt_super', $superRole);
$mkUser('kt_admin', $adminRole);
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 0, 1)");
$khId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('SCM_X', 'Gudang SCM X', 'MAIN', 1)");
$scmId = (int) $pdo->lastInsertId();
// an existing warehouse with history + FIFO layers + a posted SO adjustment-like row: must stay byte-identical
$anyItem = (int) $pdo->query("SELECT id FROM items WHERE sku = '777101'")->fetchColumn();
$kg = $unitId['KG'];
FifoService::postIn($pdo, ['transaction_uuid' => 'SCM-OPEN-1', 'item_id' => $anyItem, 'warehouse_id' => $scmId, 'input_qty' => 10, 'input_unit_id' => (int) $pdo->query("SELECT base_unit_id FROM items WHERE id = {$anyItem}")->fetchColumn(),
    'unit_price_input' => 1000, 'transaction_type' => 'OPENING', 'transaction_date' => '2026-09-01 00:00:00', 'reference_no' => 'SCM-OPENING', 'created_by' => $superId, 'allow_zero_price' => true, 'anomaly_approved_by' => $superId]);
$snap = static function () use ($pdo, $khId): array {
    $o = [];
    foreach (['stock_opname_sessions', 'stock_opname_lines', 'stock_adjustments', 'warehouse_transfers'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = $row[1];
    }
    foreach (['inventory_transactions', 'inventory_batches', 'inventory_transaction_lines', 'item_price_history'] as $t) {
        $o[$t . ' (not Karang)'] = (string) $pdo->query($t === 'inventory_batches' ? "SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches WHERE warehouse_id <> {$khId}" : ($t === 'inventory_transactions' ? "SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id <> {$khId}" : "SELECT COUNT(*) FROM {$t}" . ($t === 'inventory_transaction_lines' ? " WHERE warehouse_id <> {$khId}" : ($t === 'item_price_history' ? " WHERE source_transaction_line_id IS NULL OR source_transaction_line_id NOT IN (SELECT id FROM inventory_transaction_lines WHERE warehouse_id = {$khId})" : ''))))->fetchColumn();
    }
    return $o;
};

// ================================================================== A. the REAL workbook — read-only plan
echo "== A. real workbook: plan (read only) ==\n";
check('A1 380 source rows, 284 with stock, 96 zero, no duplicate / missing code', count($src['rows']) === 380 && count(array_filter($src['rows'], static fn ($r) => $r['qty'] > 0)) === 284 && count(array_filter($src['rows'], static fn ($r) => $r['qty'] == 0)) === 96
    && count(array_unique(array_column($src['rows'], 'code'))) === 380 && !in_array('', array_column($src['rows'], 'code'), true));
check('A2 control total stated by the file = 327.364.108,4587 and Σ(qty × HARGA) agrees', $src['declared_total'] !== null && abs($src['declared_total'] - 327364108.4587133) < 0.001 && abs(array_sum(array_column($src['rows'], 'source_value')) - 327364108.4587133) < 0.001);
check('A3 Opening Qty = Stock Akhir Produksi + Stock Akhir Gudang (blank cell = 0)', (function () use ($src) { foreach ($src['rows'] as $r) { if (abs($r['qty'] - ($r['qty_production'] + $r['qty_warehouse'])) > 1e-9) { return false; } } return true; })());
$before = $snap();
$plan = kt_plan($pdo, $src);
check('A4 plan wrote nothing', $snap() === $before);
$c = $plan['summary']['rows'];
check('A5 statuses: 284 positive rows all mapped (MATCH_EXACT 284?), 96 ZERO_QTY, no blocker', $c['total'] === 380 && $c['MATCH_EXACT'] + $c['MATCH_WITH_UNIT_CONVERSION'] === 284 && $c['ZERO_QTY'] === 96 && $c['NOT_FOUND'] === 0 && $c['AMBIGUOUS'] === 0 && $c['UNIT_UNRESOLVED'] === 0 && $c['HPP_MISSING'] === 0 && $plan['blocked'] === false, json_encode($c));
check('A6 case-normalised units: kg/Kg/KG/kG, pcs/Pcs/PCS, gram/Gram/Gr, ml/Ml, pack/Pack, set/Set all resolve (to the master base unit, factor 1)', $c['UNIT_UNRESOLVED'] === 0 && $plan['summary']['normalized_qty_by_base_unit'] !== []);
check('A7 only 284 FIFO layers will be created (zero-qty rows never)', $c['postable'] === 284);
$zero = array_values(array_filter($plan['rows'], static fn ($r) => $r['source_code'] === '999609'))[0];
check('A8 999609 SP CAIR MILKBATH: qty 0 + HPP 0 → ZERO_QTY, informational (HPP_ZERO), not a blocker', $zero['status'] === 'ZERO_QTY' && $zero['severity'] === 'INFO' && str_contains($zero['issue'], 'HPP_ZERO') && $zero['postable'] === false);
check('A9 value: source Σ qty×HARGA == Rp 327.364.108,4587; normalised (qty 6dp × HPP 4dp) within the rounding tolerance', abs($plan['summary']['source_value'] - 327364108.4587) < 0.001 && abs($plan['summary']['difference']) <= KT_TOTAL_TOLERANCE && $plan['summary']['max_line_drift'] <= KT_LINE_TOLERANCE, json_encode([$plan['summary']['source_value'], $plan['summary']['normalized_value'], $plan['summary']['difference'], $plan['summary']['max_line_drift']]));
check('A10 warehouse gate: Karang Tengah found, inactive + locked → bypass mode; empty; not posted yet', $plan['warehouse']['mode'] === 'bypass-inactive-locked' && $plan['warehouse']['existing_batches'] === 0 && $plan['warehouse']['already_posted'] === false && $plan['warehouse']['warehouse_id'] === $khId);
check('A11 preview sha256 is deterministic', kt_plan($pdo, $src)['preview_sha'] === $plan['preview_sha'] && preg_match('/^[0-9a-f]{64}$/', $plan['preview_sha']) === 1);
$lines = implode("\n", kt_report_lines($plan));
check('A12 the printed preview carries the spec summary (rows, exact, conversion, zero, not found, ambiguous, unit, HPP, qty by unit, values, difference)', str_contains($lines, 'MATCH_EXACT') && str_contains($lines, 'MATCH_WITH_UNIT_CONVERSION') && str_contains($lines, 'total qty sumber per satuan') && str_contains($lines, 'total nilai ternormalisasi') && str_contains($lines, 'PREVIEW SHA256'));
$out = kt_write_outputs($plan, $tmp . '/out');
check('A13 mapping csv has all 380 rows + header; blockers csv; summary json', count(file($tmp . '/out/karang_mapping_all_rows.csv')) === 381 && is_file($tmp . '/out/karang_blockers.csv') && json_decode((string) file_get_contents($tmp . '/out/karang_summary.json'), true)['preview_sha'] === $plan['preview_sha']);

// ================================================================== B. synthetic workbooks — every blocker
echo "\n== B. blockers (synthetic workbooks) ==\n";
$mkXlsx = static function (string $name, array $dataRows, ?float $declared = null) use ($tmp): string {
    $hdr = ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '];
    $p = $tmp . "/{$name}.xlsx";
    ExcelWriterService::write($p, ['Sheet1' => ['headers' => ['', '', '', '', '', '', '', $declared], 'rows' => array_merge([$hdr], $dataRows)]]);
    return $p;
};
$kgId = (int) $unitId['KG'];
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('CONV1','Conv item KG',{$kgId},'ACTIVE'), ('INACT1','Inactive item',{$kgId},'INACTIVE'), ('NOCONV1','No conversion KG',{$kgId},'ACTIVE')");
$convItem = (int) $pdo->query("SELECT id FROM items WHERE sku='CONV1'")->fetchColumn();
$pdo->prepare("INSERT INTO item_unit_conversions (item_id, unit_id, conversion_to_base, valid_from) VALUES (:i, :u, 0.001, '2026-01-01 00:00:00')")->execute(['i' => $convItem, 'u' => $unitId['GR']]);
$row = static fn (int $n, string $code, string $uom, $price, $prod, $wh) => [$n, "item {$code}", $code, $uom, $price, $prod, $wh, ''];
$syn = static function (array $rows, ?float $declared = null) use ($mkXlsx, $pdo) {
    static $i = 0;
    $s = kt_read_source($mkXlsx('syn' . (++$i), $rows, $declared));
    return kt_plan($pdo, $s);
};
$by = static fn (array $p, string $code) => array_values(array_filter($p['rows'], static fn ($r) => $r['source_code'] === $code))[0];
$p = $syn([$row(1, 'CONV1', 'Gram', 20, 5000, ''), $row(2, '777101', 'pcs', 10, 3, 2)], 5000 * 20 + 50);
$x = $by($p, 'CONV1');
check('B1 unit conversion from item_unit_conversions only: 5000 Gram → 5 KG, HPP 20/g → 20.000/kg, value unchanged (inverse HPP)', $x['status'] === 'MATCH_WITH_UNIT_CONVERSION' && abs($x['norm_qty'] - 5.0) < 1e-9 && abs($x['norm_unit_hpp'] - 20000.0) < 1e-9 && abs($x['norm_value'] - 100000.0) < 1e-6 && abs($x['norm_value'] - $x['source_value']) < 1e-6, json_encode($x));
check('B2 that plan has no blocker (conversion + exact rows)', $p['blocked'] === false, json_encode($p['blockers']));
$p = $syn([$row(1, 'NOCONV1', 'Gram', 20, 5000, ''), $row(2, '777101', 'pcs', 10, 1, 0)], 100000 + 10);
check('B3 Gram → KG without a master conversion = UNIT_UNRESOLVED and BLOCKS (nothing guessed or hard-coded)', $by($p, 'NOCONV1')['status'] === 'UNIT_UNRESOLVED' && $p['blocked'], $by($p, 'NOCONV1')['issue']);
$p = $syn([$row(1, 'NOPE999', 'pcs', 10, 5, 0), $row(2, '777101', 'pcs', 10, 1, 0)], 60);
check('B4 code not in Master Barang with qty > 0 → NOT_FOUND, BLOCKS, master item NOT created', $by($p, 'NOPE999')['status'] === 'NOT_FOUND' && $by($p, 'NOPE999')['severity'] === 'BLOCKER' && $p['blocked'] && (int) $pdo->query("SELECT COUNT(*) FROM items WHERE sku='NOPE999'")->fetchColumn() === 0);
$p = $syn([$row(1, 'NOPE998', 'pcs', 10, 0, 0), $row(2, '777101', 'pcs', 10, 1, 0)], 10);
check('B5 code not found with qty 0 does NOT block (reported, no layer)', $by($p, 'NOPE998')['status'] === 'NOT_FOUND' && $by($p, 'NOPE998')['severity'] === 'INFO' && !$p['blocked']);
$p = $syn([$row(1, '777101', 'pcs', 0, 5, 0)], 0);
check('B6 positive qty with HPP 0 / blank → HPP_MISSING, BLOCKS', $by($p, '777101')['status'] === 'HPP_MISSING' && $p['blocked']);
$p = $syn([$row(1, '777101', 'pcs', 10, 5, 0), $row(2, '777101', 'pcs', 10, 2, 0)], 70);
check('B7 duplicate code in the source → AMBIGUOUS, BLOCKS', count(array_filter($p['rows'], static fn ($r) => $r['status'] === 'AMBIGUOUS')) === 2 && $p['blocked']);
$p = $syn([$row(1, '777101', 'pcs', 10, 'abc', 0)], 0);
check('B8 invalid quantity (not a number) → QTY_INVALID, BLOCKS', $by($p, '777101')['status'] === 'QTY_INVALID' && $p['blocked']);
$p = $syn([$row(1, '777101', 'pcs', 10, -3, 0)], 0);
check('B9 negative quantity → QTY_INVALID, BLOCKS', $by($p, '777101')['status'] === 'QTY_INVALID' && $p['blocked']);
$p = $syn([$row(1, 'INACT1', 'kg', 10, 2, 0)], 20);
check('B10 inactive master item with stock → ITEM_INACTIVE, BLOCKS', $by($p, 'INACT1')['status'] === 'ITEM_INACTIVE' && $p['blocked']);
$p = $syn([$row(1, '777101', 'karung besar', 10, 2, 0)], 20);
check('B11 source UoM unknown to the units master → UNIT_UNRESOLVED, BLOCKS', $by($p, '777101')['status'] === 'UNIT_UNRESOLVED' && $p['blocked']);
$p = $syn([$row(1, '777101', 'pcs', 10, 2, 0)], 999);
check('B12 value reconciliation: Σ(qty×HARGA) ≠ the file control total → BLOCKS', $p['blocked'] && str_contains(json_encode($p['blockers']), 'total kontrol'));
$p = $syn([$row(1, '777101', 'pcs', 10, 2, 0)], null);
check('B13 no control total in the file → BLOCKS', $p['blocked']);

// ================================================================== C. posting
echo "\n== C. post (real workbook) ==\n";
$cli = static function (string $args) use ($root): array {
    $cmd = 'php ' . escapeshellarg($root . '/scripts/rv3/kt_opening.php') . ' ' . $args . ' 2>&1';
    $o = [];
    exec($cmd, $o, $code);
    return [$code, implode("\n", $o)];
};
$base = '--app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($real);
[$code, $o] = $cli("preview {$base}");
check('C1 CLI preview: exit 0, prints the preview sha, wrote nothing', $code === 0 && str_contains($o, $plan['preview_sha']) && $snap() === $before, "exit {$code}");
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=kt_super");
check('C2 post without --yes: exit 10 "NOT APPLIED", nothing written', $code === 10 && str_contains($o, 'NOT APPLIED') && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0);
[$code, $o] = $cli("post {$base} --preview-sha=" . str_repeat('0', 64) . ' --actor=kt_super --yes');
check('C3 post with a preview sha that is not the reviewed one: exit 13, nothing written', $code === 13 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0, $o);
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=kt_admin --yes");
check('C4 post by a non-SUPERADMIN: exit 14, nothing written', $code === 14 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0, $o);
$beforePost = $snap();
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=kt_super --yes");
check('C5 post --yes: exit 0, 284 OPENING lines', $code === 0 && str_contains($o, 'POSTED 284 OPENING lines'), "exit {$code}: " . substr($o, -200));
check('C6 posting touched NOTHING else: sessions / lines / adjustments / transfers / SCM ledger + FIFO / price history of other warehouses identical', $snap() === $beforePost);
$tx = $pdo->query("SELECT t.transaction_type, t.status, t.transaction_date, t.posting_date, t.created_at, t.reference_no, t.warehouse_id FROM inventory_transactions t WHERE t.reference_no = '" . KT_REFERENCE . "'")->fetchAll();
check('C7 284 transactions: type OPENING (not IN / transfer / adjustment), POSTED, effective 2026-10-01 00:00:00, warehouse Karang Tengah', count($tx) === 284 && count(array_unique(array_column($tx, 'transaction_type'))) === 1 && $tx[0]['transaction_type'] === 'OPENING' && $tx[0]['status'] === 'POSTED' && array_unique(array_column($tx, 'transaction_date')) === ['2026-10-01 00:00:00'] && array_unique(array_column($tx, 'warehouse_id')) === [(string) $khId] || array_unique(array_column($tx, 'warehouse_id')) === [$khId]);
check('C8 effective date is separate from posted_at: posting_date / created_at are the real posting time (not 2026-10-01)', substr((string) $tx[0]['posting_date'], 0, 10) === date('Y-m-d') && substr((string) $tx[0]['created_at'], 0, 10) === date('Y-m-d'));
$v = kt_verify_ledger($pdo, kt_plan($pdo, $src));
$bad = array_filter($v, static fn ($c) => !$c[1]);
check('C9 ledger reconciliation: per item opening qty == on-hand == FIFO layer qty; Σ value == Σ layer value; warehouse total == plan; classification', $bad === [], json_encode(array_values($bad)));
$layers = $pdo->query("SELECT COUNT(*), COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches WHERE warehouse_id = {$khId}")->fetch(PDO::FETCH_NUM);
check('C10 Karang Tengah FIFO: 284 layers, value within rounding of Rp 327.364.108,46', (int) $layers[0] === 284 && abs((float) $layers[1] - 327364108.4587) <= KT_TOTAL_TOLERANCE, json_encode($layers));
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=kt_super --yes");
check('C11 posting again: OPENING_BALANCE_ALREADY_POSTED (exit 12), no duplicate layer', $code === 12 && str_contains($o, 'OPENING_BALANCE_ALREADY_POSTED') && (int) $pdo->query("SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = {$khId}")->fetchColumn() === 284, $o);
$a = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'KARANG_OPENING_LINE' AND reason = '" . KT_REFERENCE . "'")->fetchColumn();
$h = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'KARANG_OPENING_POST'")->fetchColumn(), true);
$l = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'KARANG_OPENING_LINE' ORDER BY id LIMIT 1")->fetchColumn(), true);
check('C12 audit: 284 line rows + 1 header; each line keeps source file / row / code / qty / UoM / HPP / value, normalised qty / HPP / value, item_id, posted_by, posted_at, effective_date',
    (int) $a === 284 && $h['lines'] === 284 && $h['source_sha256'] === $src['sha256'] && isset($l['source_row'], $l['source_uom'], $l['source_qty'], $l['source_hpp'], $l['source_value'], $l['normalized_qty'], $l['normalized_unit_hpp'], $l['normalized_value'], $l['item_id'], $l['posted_by'], $l['posted_at'], $l['effective_date']) && $l['effective_date'] === '2026-10-01');

echo "\n== D. reports: the opening is October OPENING, never Stock IN ==\n";
$rep = kt_verify_reports($pdo, kt_plan($pdo, $src));
$badr = array_filter($rep, static fn ($c) => !$c[1]);
check('D1 Karang Tengah: Oct opening == opening balance value; Stock IN / Transfer / OUT / Adjustment = 0; identity holds; September empty', $badr === [], json_encode(array_values($badr)));
$all = MovementReportV3Service::overview($pdo, '2026-10-01', '2026-10-31', null)['split_totals'];
$scm = MovementReportV3Service::overview($pdo, '2026-10-01', '2026-10-31', $scmId)['split_totals'];
check('D2 "Semua Gudang" Oct opening = SCM opening + Karang Tengah opening (no double counting); Stock IN stays 0', abs($all['opening'] - ($scm['opening'] + (float) kt_plan($pdo, $src)['summary']['normalized_value'])) < 0.01 && abs($all['in']) < 0.005 && abs($all['difference']) < 0.01, json_encode([$all['opening'], $scm['opening'], $all['in']]));
$mid = MovementReportV3Service::overview($pdo, '2026-10-05', '2026-10-31', $khId)['split_totals'];
check('D3 a period starting after 1 Oct also carries it in the opening (not as a movement)', abs($mid['opening'] - (float) kt_plan($pdo, $src)['summary']['normalized_value']) < 0.01 && abs($mid['in']) < 0.005 && abs($mid['adjustment']) < 0.005);
[$code, $o] = $cli("verify {$base}");
check('D4 CLI verify: exit 0 (ledger + reports)', $code === 0 && str_contains($o, 'VERIFY OK'), $o);

echo "\n== E. last-resort rollback ==\n";
[$code, $o] = $cli("rollback {$base}");
check('E1 rollback without --yes: exit 10, lists the plan, removes nothing', $code === 10 && str_contains($o, 'ROLLBACK PLAN: 284') && (int) $pdo->query("SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = {$khId}")->fetchColumn() === 284, $o);
$pdo->prepare("UPDATE inventory_batches SET qty_base = qty_base - 0.5 WHERE warehouse_id = :w ORDER BY id LIMIT 1")->execute(['w' => $khId]);
[$code, $o] = $cli("rollback {$base} --yes --confirm=" . KT_REFERENCE);
check('E2 rollback is BLOCKED while a layer was consumed (restore the backup instead)', $code === 11 && str_contains($o, 'consumed'), $o);
$pdo->prepare("UPDATE inventory_batches SET qty_base = qty_base + 0.5 WHERE warehouse_id = :w ORDER BY id LIMIT 1")->execute(['w' => $khId]);
[$code, $o] = $cli("rollback {$base} --yes --confirm=" . KT_REFERENCE);
check('E3 rollback with --yes --confirm removes exactly the 284 rows of this reference; other warehouses untouched', $code === 0 && str_contains($o, 'ROLLED BACK') && (int) $pdo->query("SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = {$khId}")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0 && $snap() === $beforePost, $o);
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=kt_super --yes");
check('E4 after the rollback the same preview can be posted again (idempotency guard is the reference, not a leftover)', $code === 0, $o);

foreach (glob($tmp . '/*') as $f) { if (is_file($f)) { unlink($f); } }
foreach (glob($tmp . '/out/*') as $f) { unlink($f); }
@rmdir($tmp . '/out');
@rmdir($tmp);
$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
