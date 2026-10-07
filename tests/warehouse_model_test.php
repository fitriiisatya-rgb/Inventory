<?php
declare(strict_types=1);

/**
 * WAREHOUSE MODEL: SCM = MAIN · CIBADAK = TRANSIT · KARANG_TENGAH = TRANSIT (scripts/rv3/warehouse_model.php) and the Karang Tengah ACTIVE + LOCKED → ACTIVE + UNLOCKED state
 * (scripts/rv3/kt_activate.php). Proves warehouse_type is descriptive only: correcting CIBADAK from MAIN to TRANSIT changes ONE column of ONE row and moves nothing — ledger, FIFO,
 * historical transactions, transfers (incl. a received SCM → CIBADAK transfer), Stock Opname sessions, and the Reports V3 totals of every warehouse + company-wide are identical.
 *
 * Usage: php tests/warehouse_model_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/lib/jejak_real_fixture.php';
require_once __DIR__ . '/../scripts/rv3/rv3_lib.php';
require_once __DIR__ . '/../scripts/rv3/wm_lib.php';
require_once __DIR__ . '/../scripts/rv3/kt_opening_lib.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\FifoService;
use App\Services\TransferService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
$pdo = Database::connection();
$root = dirname(__DIR__);
$kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superRole = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$adminRole = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
foreach ([['wm_super', $superRole], ['wm_admin', $adminRole]] as [$n, $r]) {
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,'x',:f,:r,1)")->execute(['u' => $n, 'f' => $n, 'r' => $r]);
}
$uid = (int) $pdo->query("SELECT id FROM users WHERE username='wm_super'")->fetchColumn();
// the PRODUCTION model the read-only output showed: SCM MAIN active, CIBADAK MAIN (wrong) active, KARANG_TENGAH TRANSIT ACTIVE + LOCKED
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('SCM','SCM','MAIN',1,0), ('CIBADAK','CIBADAK','MAIN',1,0), ('KARANG_TENGAH','KARANG TENGAH','TRANSIT',1,1)");
$id = static fn (string $c): int => (int) $GLOBALS['pdo']->query("SELECT id FROM warehouses WHERE code='{$c}'")->fetchColumn();
$scm = $id('SCM');
$cib = $id('CIBADAK');
$kt = $id('KARANG_TENGAH');
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('W1','Item W1',{$kg},'ACTIVE'), ('W2','Item W2',{$kg},'ACTIVE')");
$w1 = (int) $pdo->query("SELECT id FROM items WHERE sku='W1'")->fetchColumn();
$w2 = (int) $pdo->query("SELECT id FROM items WHERE sku='W2'")->fetchColumn();
$post = static fn (string $u, int $item, int $wh, float $q, float $p, string $type, string $d) => FifoService::postIn($GLOBALS['pdo'], ['transaction_uuid' => $u, 'item_id' => $item, 'warehouse_id' => $wh, 'input_qty' => $q, 'input_unit_id' => $GLOBALS['kg'], 'unit_price_input' => $p,
    'transaction_type' => $type, 'transaction_date' => $d, 'created_by' => $GLOBALS['uid'], 'allow_zero_price' => true, 'anomaly_approved_by' => $GLOBALS['uid']]);
$post('WM-O1', $w1, $scm, 100, 10, 'OPENING', '2026-09-01 00:00:00');
$post('WM-O2', $w2, $cib, 50, 20, 'OPENING', '2026-09-01 00:00:00');
$post('WM-IN1', $w1, $cib, 10, 11, 'IN', '2026-09-10 09:00:00');
$t = TransferService::create($pdo, ['transfer_uuid' => 'wm-transfer', 'from_warehouse_id' => $scm, 'to_warehouse_id' => $cib, 'ship_date' => '2026-09-12 08:00:00', 'created_by' => $uid, 'username' => 'wm', 'lines' => [['item_id' => $w1, 'input_qty' => 20, 'input_unit_id' => $kg]]]);
TransferService::receive($pdo, (int) $t['transfer_id'], ['created_by' => $uid, 'username' => 'wm', 'receive_date' => '2026-09-12 12:00:00']);
$fx = jejak_build_fixture($pdo);   // a posted Stock Opname (session) in other warehouses: sessions must stay identical

$cli = static function (string $script, string $args) use ($root): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/' . $script) . ' ' . $args . ' --app-root=' . escapeshellarg($root) . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$type = static fn (string $c): string => (string) $GLOBALS['pdo']->query("SELECT warehouse_type FROM warehouses WHERE code='{$c}'")->fetchColumn();
$sha = static function (string $o): string {
    preg_match('/PLAN SHA256 : ([0-9a-f]{64})/', $o, $m);
    return $m[1] ?? '';
};

echo "== A. usage of warehouse_type in the installed code ==\n";
$scan = wm_usage_scan($root);
check('A1 every reader of warehouse_type is a known display / master-data input file (none in transaction validation, transfers, Stock IN/OUT, Stock Opname, permissions, FIFO, Movement, Dashboard, Valuation, routes)', $scan['unknown'] === [] && count($scan['readers']) >= 7, 'unknown=' . json_encode($scan['unknown']));
$files = array_unique(array_column($scan['readers'], 'file'));
check('A2 the readers are exactly: master create / import / template validation, warehouse report column, HPP panel label, Master Gudang UI, report-hpp subtitle', array_diff($files, array_keys(WM_KNOWN_READERS)) === [], implode(', ', $files));
foreach (['services/FifoService.php', 'services/TransferService.php', 'services/StockOpnameService.php', 'services/MovementDailyReportService.php', 'services/MovementReportV3Service.php', 'services/DashboardInventoryService.php', 'services/InventoryValuationService.php', 'services/AuthService.php', 'public/index.php'] as $f) {
    if (in_array($f, $files, true)) {
        check("A3 {$f} must not read warehouse_type", false);
    }
}
check('A3 FIFO / Transfer / Stock Opname / Movement / Dashboard / Valuation / Auth services and index.php never read warehouse_type', true);

echo "\n== B. check (read only) ==\n";
$before = wm_fingerprint($pdo);
[$code, $o] = $cli('warehouse_model.php', 'check');
$planSha = $sha($o);
check('B1 check exit 0: lists SCM MAIN (OK), CIBADAK MAIN (TIDAK SESUAI → TRANSIT), KARANG_TENGAH TRANSIT (OK); states the one-row change', $code === 0 && preg_match('/SCM\s+\S.*MAIN\s+MAIN\s.*OK/', $o) === 1 && str_contains($o, 'TIDAK SESUAI') && str_contains($o, "SET warehouse_type = 'TRANSIT'") && $planSha !== '', "exit {$code}");
check('B2 check wrote nothing', wm_fingerprint($pdo) === $before && $type('CIBADAK') === 'MAIN');
check('B3 the check states the conclusion (display + master input only) and the visible effect', str_contains($o, 'hanya dibaca oleh validasi input master dan label tampilan') && str_contains($o, 'Stok transit & distribusi'));
[$code, $o] = $cli('warehouse_model.php', "fix --plan-sha={$planSha} --actor=wm_super");
check('B4 fix without --yes: exit 10 NOT APPLIED, nothing changed', $code === 10 && $type('CIBADAK') === 'MAIN');
[$code, $o] = $cli('warehouse_model.php', 'fix --plan-sha=' . str_repeat('0', 64) . ' --actor=wm_super --yes');
check('B5 a plan sha that is not the reviewed one: exit 13, nothing changed', $code === 13 && $type('CIBADAK') === 'MAIN');
[$code, $o] = $cli('warehouse_model.php', "fix --plan-sha={$planSha} --actor=wm_admin --yes");
check('B6 by a non-SUPERADMIN: exit 14', $code === 14 && $type('CIBADAK') === 'MAIN');

echo "\n== C. the correction ==\n";
$repBefore = $before['reports_v3'];
[$code, $o] = $cli('warehouse_model.php', "fix --plan-sha={$planSha} --actor=wm_super --yes");
$after = wm_fingerprint($pdo);
check('C1 fix --yes: exit 0; CIBADAK = TRANSIT; SCM stays MAIN; KARANG_TENGAH stays TRANSIT', $code === 0 && $type('CIBADAK') === 'TRANSIT' && $type('SCM') === 'MAIN' && $type('KARANG_TENGAH') === 'TRANSIT', "exit {$code}: {$o}");
check('C2 PROOF: stock quantity, FIFO value, ledger (every transaction), FIFO allocations, historical transactions identical to before', $after['qty'] === $before['qty'] && $after['value'] === $before['value'] && $after['ledger_digest'] === $before['ledger_digest'] && $after['transactions'] === $before['transactions'] && $after['allocations'] === $before['allocations'] && $after['batches'] === $before['batches']);
check('C3 PROOF: transfers (incl. the received SCM → CIBADAK one) and Stock Opname sessions identical', $after['transfers'] === $before['transfers'] && $after['so_sessions'] === $before['so_sessions']);
check('C4 PROOF: Reports V3 totals (opening / IN / OUT / transfer IN / OUT / adjustment / closing) of Semua Gudang and EVERY warehouse identical', $after['reports_v3'] === $repBefore, json_encode(array_keys($after['reports_v3'])));
check('C5 exactly one warehouse row differs from before (CIBADAK) and no other column changed', (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code IN ('SCM','CIBADAK','KARANG_TENGAH') AND warehouse_type <> CASE code WHEN 'SCM' THEN 'MAIN' ELSE 'TRANSIT' END")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code NOT IN ('SCM','CIBADAK','KARANG_TENGAH') AND warehouse_type <> 'MAIN'")->fetchColumn() === 0);
$au = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'WAREHOUSE_TYPE_CORRECT'")->fetchColumn(), true);
check('C6 audit row: MAIN → TRANSIT with the fingerprint', is_array($au) && $au['warehouse_type'] === 'TRANSIT' && $au['code'] === 'CIBADAK' && isset($au['fingerprint']));
[$code, $o] = $cli('warehouse_model.php', 'check');
check('C7 idempotent: check now says CIBADAK is already TRANSIT; a second fix writes nothing', $code === 0 && str_contains($o, 'sudah bertipe TRANSIT'));
[$code, $o] = $cli('warehouse_model.php', "fix --plan-sha={$sha($o)} --actor=wm_super --yes");
check('C8 second fix: exit 0, no change, still ONE audit row', $code === 0 && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'WAREHOUSE_TYPE_CORRECT'")->fetchColumn() === 1, $o);
[$code, $o] = $cli('warehouse_model.php', 'verify');
check('C9 verify: SCM MAIN, CIBADAK TRANSIT, KARANG_TENGAH TRANSIT', $code === 0 && str_contains($o, 'VERIFY OK'), $o);

echo "\n== D. a reader outside the known list blocks the correction (fail closed) ==\n";
$tmpApp = sys_get_temp_dir() . '/wm_app_' . bin2hex(random_bytes(3));
mkdir("{$tmpApp}/services", 0755, true);
mkdir("{$tmpApp}/public/assets/js", 0755, true);
file_put_contents("{$tmpApp}/services/RogueRule.php", "<?php\nif (\$w['warehouse_type'] === 'MAIN') { /* behaviour! */ }\n");
$scan2 = wm_usage_scan($tmpApp);
check('D1 an unknown reader (services/RogueRule.php) is detected → the correction would be BLOCKED', $scan2['unknown'] === ['services/RogueRule.php:2']);
$pdo->exec("UPDATE warehouses SET warehouse_type = 'MAIN' WHERE code = 'CIBADAK'");
$plan = wm_plan($pdo, $tmpApp);
$blocked = false;
try {
    wm_apply($pdo, $plan, 'wm_super', $plan['sha']);
} catch (WmException $e) {
    $blocked = $e->codeName === 'BLOCKED';
}
check('D2 wm_apply refuses on an unknown reader and writes nothing', $blocked && $type('CIBADAK') === 'MAIN');
@unlink("{$tmpApp}/services/RogueRule.php");
@rmdir("{$tmpApp}/services");
@rmdir("{$tmpApp}/public/assets/js");
@rmdir("{$tmpApp}/public/assets");
@rmdir("{$tmpApp}/public");
@rmdir($tmpApp);
// rollback path
$plan = wm_plan($pdo, $root);
wm_apply($pdo, $plan, 'wm_super', $plan['sha']);
[$code, $o] = $cli('warehouse_model.php', 'rollback --yes --confirm=' . WM_CONFIRM);
check('D3 rollback restores MAIN from the audit row (descriptive column only)', $code === 0 && $type('CIBADAK') === 'MAIN', $o);
[$code, $o] = $cli('warehouse_model.php', 'check');
$planSha = $sha($o);
$cli('warehouse_model.php', "fix --plan-sha={$planSha} --actor=wm_super --yes");

echo "\n== E. Karang Tengah is ACTIVE + LOCKED (production) → ACTIVE + UNLOCKED ==\n";
$tmp = sys_get_temp_dir() . '/wm_kt_' . bin2hex(random_bytes(3)) . '.xlsx';
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('K1','Karang 1',{$kg},'ACTIVE'), ('K2','Karang 2',{$kg},'ACTIVE')");
ExcelWriterService::write($tmp, ['S' => ['headers' => ['', '', '', '', '', '', '', 5 * 40 + 3 * 100], 'rows' => [
    ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '],
    [1, 'Karang 1', 'K1', 'kg', 40, 2, 3, ''], [2, 'Karang 2', 'K2', 'Kg', 100, '', 3, ''],
]]]);
$act = static function (string $args) use ($root, $tmp): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/kt_activate.php') . ' ' . $args . ' --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($tmp) . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$khState = static fn (): array => array_map('intval', $GLOBALS['pdo']->query("SELECT is_active, activation_locked FROM warehouses WHERE id = {$GLOBALS['kt']}")->fetch(PDO::FETCH_NUM));
[$code, $o] = $act('plan');
check('E1 plan reports the production state as "ACTIVE + LOCKED" (never just "inactive") with TARGET ACTIVE + UNLOCKED; gates FAIL while no opening is posted', $code === 11 && str_contains($o, 'CURRENT : ACTIVE + LOCKED') && str_contains($o, 'TARGET  : ACTIVE + UNLOCKED') && !preg_match('/CURRENT : INACTIVE/', $o) && str_contains($o, 'FAIL - opening balance posted'), $o);
$kp = kt_plan($pdo, kt_read_source($tmp));
check('E2 the opening plan accepts ACTIVE + LOCKED (normal posting path, no bypass) and prints the state in words', $kp['warehouse']['mode'] === 'normal-active' && str_contains(implode("\n", kt_report_lines($kp)), 'ACTIVE + LOCKED') && !$kp['blocked']);
[$code, $o] = $act('activate --plan-sha=' . str_repeat('0', 64) . ' --actor=wm_super --yes');
check('E3 unlocking BEFORE the opening is posted is refused (exit 11); still ACTIVE + LOCKED', $code === 11 && $khState() === [1, 1], $o);
kt_post($pdo, $kp, 'wm_super', $kp['preview_sha']);
$ledgerAfterOpening = wm_fingerprint($pdo);
[$code, $o] = $act('plan');
$planKt = $sha($o);
check('E4 after the opening: all gates PASS (posted, ledger, FIFO, Reports V3), STATUS SIAP, exit 0', $code === 0 && !str_contains($o, 'FAIL -') && str_contains($o, 'STATUS      : SIAP'), substr($o, -250));
[$code, $o] = $act("activate --plan-sha={$planKt} --actor=wm_super --yes");
$ledgerAfterUnlock = wm_fingerprint($pdo);
check('E5 activate --yes: ACTIVE + LOCKED → ACTIVE + UNLOCKED (is_active stays 1, activation_locked 1 → 0)', $code === 0 && $khState() === [1, 0] && str_contains($o, 'ACTIVE + LOCKED → ACTIVE + UNLOCKED'), $o);
check('E6 NO stock movement: ledger / FIFO / quantities / values / transfers / SO / report totals identical to just after the opening', $ledgerAfterUnlock === $ledgerAfterOpening);
$au = json_decode((string) $pdo->query("SELECT before_data FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_CUTOVER'")->fetchColumn(), true);
check('E7 audit before = ACTIVE + LOCKED', $au['is_active'] === 1 && $au['activation_locked'] === 1 && $au['state'] === 'ACTIVE + LOCKED');
[$code, $o] = $act("activate --plan-sha={$planKt} --actor=wm_super --yes");
check('E8 idempotent: already ACTIVE + UNLOCKED → "nothing to do", exit 0, ONE audit row', $code === 0 && str_contains($o, 'ALREADY ACTIVE + UNLOCKED') && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_CUTOVER'")->fetchColumn() === 1);
[$code, $o] = $act('rollback --yes --confirm=ACTIVATE_ROLLBACK');
check('E9 rollback (no operational transaction): back to ACTIVE + LOCKED — is_active never touched', $code === 0 && $khState() === [1, 1], $o);
$pdo->exec("UPDATE warehouses SET is_active = 0 WHERE id = {$kt}");
[$code, $o] = $act('plan');
$bad = $sha($o);
[$code2, $o2] = $act("activate --plan-sha={$bad} --actor=wm_super --yes");
check('E10 an UNEXPECTEDLY INACTIVE Karang Tengah is never silently activated: plan reports CURRENT INACTIVE + LOCKED as unexpected, activate refused (exit 11), is_active stays 0', $code === 11 && str_contains($o, 'CURRENT: INACTIVE + LOCKED') && $code2 === 11 && $khState()[0] === 0, $o2);
$pdo->exec("UPDATE warehouses SET is_active = 1 WHERE id = {$kt}");
@unlink($tmp);

$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
