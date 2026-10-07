<?php
declare(strict_types=1);

/**
 * Karang Tengah WAREHOUSE ACTIVATION (scripts/rv3/kt_activate.php): sequence-gated (opening posted AND reconciled first), idempotent, one warehouse row, no stock movement,
 * Karang Tengah then appears wherever Cibadak appears (the single is_active source of every operational warehouse selector), rollback guarded.
 *
 * Usage: php tests/karang_activation_test.php
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
use App\Services\WarehouseGuardService;

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
$super = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$admin = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
foreach ([['kta_super', $super], ['kta_admin', $admin]] as [$n, $r]) {
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,'x',:f,:r,1)")->execute(['u' => $n, 'f' => $n, 'r' => $r]);
}
$superId = (int) $pdo->query("SELECT id FROM users WHERE username='kta_super'")->fetchColumn();
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('A1','Item A1',{$kg},'ACTIVE'), ('A2','Item A2',{$kg},'ACTIVE')");
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('SCM','Gudang SCM','MAIN',1), ('CIBADAK','Gudang Cibadak','TRANSIT',1)");
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH','Gudang Karang Tengah','TRANSIT',0,1)");
$khId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='KARANG_TENGAH'")->fetchColumn();
$scmId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='SCM'")->fetchColumn();
$a1 = (int) $pdo->query("SELECT id FROM items WHERE sku='A1'")->fetchColumn();
FifoService::postIn($pdo, ['transaction_uuid' => 'SCM-O', 'item_id' => $a1, 'warehouse_id' => $scmId, 'input_qty' => 5, 'input_unit_id' => $kg, 'unit_price_input' => 100, 'transaction_type' => 'OPENING', 'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $superId, 'allow_zero_price' => true, 'anomaly_approved_by' => $superId]);
$tmp = sys_get_temp_dir() . '/kta_' . bin2hex(random_bytes(3)) . '.xlsx';
ExcelWriterService::write($tmp, ['S' => ['headers' => ['', '', '', '', '', '', '', 5 * 40 + 3 * 100], 'rows' => [
    ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '],
    [1, 'Item A1', 'A1', 'kg', 40, 2, 3, ''], [2, 'Item A2', 'A2', 'Kg', 100, '', 3, ''],
]]]);
$cli = static function (string $args) use ($root, $tmp): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/kt_activate.php') . ' ' . $args . ' --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($tmp) . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$wh = static fn (): array => $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id = {$khId}")->fetch(PDO::FETCH_NUM);
$planSha = static function (string $o): string {
    preg_match('/PLAN SHA256 : ([0-9a-f]{64})/', $o, $m);
    return $m[1] ?? '';
};
$ledger = static fn (): string => json_encode([$pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn(), $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM fifo_allocations')->fetchColumn()]);

echo "== A. the sequence: NOTHING is activated before the opening is posted + reconciled ==\n";
[$code, $o] = $cli('plan');
check('A1 plan BEFORE the opening: exit 11, gates FAIL (opening not posted), warehouse still inactive + locked', $code === 11 && str_contains($o, 'FAIL - opening balance posted') && $wh() == [0, 1], "exit {$code}");
$lists = $o;
check('A2 plan lists every warehouse (SCM, CIBADAK, KARANG_TENGAH) with is_active / activation_locked and states the exact one-row change', str_contains($lists, 'SCM') && str_contains($lists, 'CIBADAK') && str_contains($lists, 'KARANG_TENGAH') && str_contains($lists, 'UPDATE warehouses SET is_active = 1, activation_locked = 0 WHERE id = ' . $khId));
$before = $ledger();
[$code, $o] = $cli('activate --plan-sha=' . str_repeat('0', 64) . ' --actor=kta_super --yes');
check('A3 activate --yes BEFORE the opening is posted: refused (exit 11 GATES_FAILED), nothing changed', $code === 11 && str_contains($o, 'GATES_FAILED') && $wh() == [0, 1] && $ledger() === $before, $o);

$plan = kt_plan($pdo, kt_read_source($tmp));
kt_post($pdo, $plan, 'kta_super', $plan['preview_sha']);
$afterOpening = $ledger();
[$code, $o] = $cli('plan');
$sha = $planSha($o);
check('A4 plan AFTER the opening: every gate PASS (posted, ledger reconciliation, report reconciliation), exit 0, plan sha printed', $code === 0 && !str_contains($o, 'FAIL -') && str_contains($o, 'STATUS      : SIAP') && $sha !== '', "exit {$code}: " . substr($o, -300));
check('A5 plan wrote nothing', $wh() == [0, 1] && $ledger() === $afterOpening);

echo "\n== B. activation ==\n";
[$code, $o] = $cli("activate --plan-sha={$sha} --actor=kta_super");
check('B1 activate without --yes: exit 10 NOT APPLIED, nothing changed', $code === 10 && str_contains($o, 'NOT APPLIED') && $wh() == [0, 1]);
[$code, $o] = $cli('activate --plan-sha=' . str_repeat('0', 64) . ' --actor=kta_super --yes');
check('B2 a plan sha that is not the reviewed one: exit 13, nothing changed', $code === 13 && $wh() == [0, 1], $o);
[$code, $o] = $cli("activate --plan-sha={$sha} --actor=kta_admin --yes");
check('B3 by a non-SUPERADMIN: exit 14, nothing changed', $code === 14 && $wh() == [0, 1], $o);
$otherWh = $pdo->query('SELECT id, is_active, activation_locked FROM warehouses WHERE id <> ' . $khId . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
[$code, $o] = $cli("activate --plan-sha={$sha} --actor=kta_super --yes");
check('B4 activate --yes: exit 0, Karang Tengah is_active=1 activation_locked=0', $code === 0 && $wh() == [1, 0] && str_contains($o, 'ACTIVATED'), "exit {$code}: {$o}");
check('B5 activation created NO stock movement: transactions / lines / FIFO layers / quantities / values / allocations identical to the moment after the opening', $ledger() === $afterOpening);
check('B6 no other warehouse was touched', $pdo->query('SELECT id, is_active, activation_locked FROM warehouses WHERE id <> ' . $khId . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM) == $otherWh);
$au = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_CUTOVER'")->fetchColumn(), true);
check('B7 audit row: before (inactive / locked) → after (active / unlocked) + the ledger fingerprint + reference', is_array($au) && $au['is_active'] === 1 && $au['activation_locked'] === 0 && $au['source_reference'] === KT_REFERENCE && isset($au['ledger_fingerprint']));
[$code, $o] = $cli("activate --plan-sha={$sha} --actor=kta_super --yes");
check('B8 idempotent: a second activation = "ALREADY ACTIVE", exit 0, still ONE audit row', $code === 0 && str_contains($o, 'ALREADY ACTIVE') && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_CUTOVER'")->fetchColumn() === 1, $o);

echo "\n== C. Karang Tengah now behaves like Cibadak ==\n";
$active = array_column($pdo->query('SELECT code FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll(), 'code');
check('C1 the single source of every operational selector (GET /warehouses → is_active = 1) lists SCM, CIBADAK and KARANG_TENGAH', $active === ['SCM', 'CIBADAK', 'KARANG_TENGAH'], json_encode($active));
$guard = true;
try {
    WarehouseGuardService::assertActive($pdo, $khId);
} catch (Throwable $e) {
    $guard = false;
}
check('C2 the active-warehouse guard (Stock IN / OUT / Transfer / Opname) now accepts Karang Tengah', $guard);
$r = FifoService::postIn($pdo, ['transaction_uuid' => 'KTA-IN-1', 'item_id' => $a1, 'warehouse_id' => $khId, 'input_qty' => 1, 'input_unit_id' => $kg, 'unit_price_input' => 41, 'transaction_type' => 'IN', 'transaction_date' => '2026-10-08 09:00:00', 'created_by' => $superId, 'anomaly_approved_by' => $superId]);
check('C3 a normal Stock IN into Karang Tengah works WITHOUT the cutover bypass (operational like Cibadak)', !empty($r['transaction_id']));

echo "\n== D. rollback guard ==\n";
[$code, $o] = $cli('rollback --yes --confirm=ACTIVATE_ROLLBACK');
check('D1 rollback is BLOCKED once an operational transaction exists (the Stock IN of C3); warehouse stays active', $code === 11 && str_contains($o, 'operational transactions exist') && $wh() == [1, 0], $o);
$batchIds = implode(',', array_map('intval', $pdo->query("SELECT created_batch_id FROM inventory_transaction_lines WHERE transaction_id = {$r['transaction_id']}")->fetchAll(PDO::FETCH_COLUMN)));
$pdo->exec("UPDATE inventory_transaction_lines SET created_batch_id = NULL WHERE transaction_id = {$r['transaction_id']}");
$pdo->exec("DELETE FROM inventory_batches WHERE id IN ({$batchIds})");
$pdo->exec("DELETE FROM item_price_history WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE transaction_id = {$r['transaction_id']})");
$pdo->exec("DELETE FROM inventory_transaction_lines WHERE transaction_id = {$r['transaction_id']}");
$pdo->exec("DELETE FROM inventory_transactions WHERE id = {$r['transaction_id']}");
[$code, $o] = $cli('rollback');
check('D2 rollback without --yes: exit 10, lists the plan, changes nothing', $code === 10 && str_contains($o, 'back to is_active=0') && $wh() == [1, 0], $o);
[$code, $o] = $cli('rollback --yes --confirm=ACTIVATE_ROLLBACK');
check('D3 rollback --yes --confirm (no operational transaction): inactive + locked again, ledger untouched, rollback audited', $code === 0 && $wh() == [0, 1] && $ledger() === $afterOpening && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_ROLLBACK'")->fetchColumn() === 1, $o);
[$code, $o] = $cli('plan');
$sha2 = $planSha($o);
[$code, $o] = $cli("activate --plan-sha={$sha2} --actor=kta_super --yes");
check('D4 it can be activated again after the rollback (idempotent, gated by the same checks)', $code === 0 && $wh() == [1, 0], $o);

@unlink($tmp);
$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
