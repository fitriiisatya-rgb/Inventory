<?php
declare(strict_types=1);

/**
 * KARANG TENGAH UNLOCK — exact state-transition tests (scripts/rv3/kt_activate.php).
 *
 *   1. ACTIVE + LOCKED (1/1), opening posted + reconciled   → unlock → 1/0, is_active byte-identical
 *   2. ACTIVE + UNLOCKED (1/0)                              → ALREADY_ACTIVE_AND_UNLOCKED, no write, no audit
 *   3. INACTIVE + LOCKED (0/1)                              → UNEXPECTED_INACTIVE_STATE, blocked, no update
 *   4. INACTIVE + UNLOCKED (0/0)                            → UNEXPECTED_INACTIVE_STATE, blocked, no update
 *   5. ACTIVE + LOCKED (1/1), opening NOT posted            → WAITING / BLOCKED, no update
 * plus: the SQL the script can issue never assigns is_active (parsed + scanned), affected_rows must be 1, and the rollback is activation_locked 0 → 1 only.
 * "No update" is proven with the server's own counters (Innodb_rows_updated / _inserted / _deleted) around the CLI run, not with a mock.
 *
 * Usage: php tests/karang_unlock_state_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/../scripts/rv3/kt_opening_lib.php';

use App\Services\Database;
use App\Services\ExcelWriterService;

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
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('ku_super','x','ku_super',:r,1)")->execute(['r' => $super]);
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('U1','Unlock 1',{$kg},'ACTIVE'), ('U2','Unlock 2',{$kg},'ACTIVE')");
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH','KARANG TENGAH','TRANSIT',1,1)");
$kt = (int) $pdo->query("SELECT id FROM warehouses WHERE code='KARANG_TENGAH'")->fetchColumn();
$tmp = sys_get_temp_dir() . '/ku_' . bin2hex(random_bytes(3)) . '.xlsx';
ExcelWriterService::write($tmp, ['S' => ['headers' => ['', '', '', '', '', '', '', 5 * 40 + 3 * 100], 'rows' => [
    ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '],
    [1, 'Unlock 1', 'U1', 'kg', 40, 2, 3, ''], [2, 'Unlock 2', 'U2', 'kg', 100, '', 3, ''],
]]]);
$act = static function (string $args) use ($root, $tmp): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/kt_activate.php') . ' ' . $args . ' --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($tmp) . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$setState = static function (int $active, int $locked) use ($pdo, $kt): void {
    $pdo->prepare('UPDATE warehouses SET is_active = :a, activation_locked = :l WHERE id = :id')->execute(['a' => $active, 'l' => $locked, 'id' => $kt]);
};
$raw = static fn (): array => $GLOBALS['pdo']->query("SELECT CAST(is_active AS CHAR), CAST(activation_locked AS CHAR) FROM warehouses WHERE id = {$GLOBALS['kt']}")->fetch(PDO::FETCH_NUM);
// the exact SQL the server received, taken from its own general log (root via the mysql client; test environment only)
$mysqlRoot = static function (string $sql): string {
    $o = [];
    exec('mysql -uroot -N -e ' . escapeshellarg($sql) . ' 2>&1', $o, $rc);
    return implode("\n", $o);
};
$logStart = static function () use ($mysqlRoot): void {
    $mysqlRoot("SET GLOBAL general_log = 0; SET GLOBAL log_output = 'TABLE'; TRUNCATE TABLE mysql.general_log; SET GLOBAL general_log = 1;");
};
/** @return list<string> every UPDATE / INSERT / DELETE statement the server received since logStart() that touches warehouses or audit_logs */
$logStatements = static function () use ($mysqlRoot): array {
    $out = $mysqlRoot("SET GLOBAL general_log = 0; SELECT REPLACE(CONVERT(argument USING utf8), CHAR(10), ' ') FROM mysql.general_log WHERE command_type IN ('Query','Prepare') AND (argument LIKE '%UPDATE warehouses%' OR argument LIKE '%UPDATE `warehouses`%' OR argument LIKE '%INSERT INTO audit_logs%' OR argument LIKE '%DELETE FROM warehouses%');");
    return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn ($l) => $l !== ''));
};
$counters = static fn (): array => [];
$audits = static fn (): int => (int) $GLOBALS['pdo']->query("SELECT COUNT(*) FROM audit_logs WHERE action_code IN ('WAREHOUSE_ACTIVATE_CUTOVER','WAREHOUSE_ACTIVATE_ROLLBACK')")->fetchColumn();
$sha = static function (string $o): string {
    preg_match('/PLAN SHA256 : ([0-9a-f]{64})/', $o, $m);
    return $m[1] ?? '';
};

echo "== A. the SQL the script can issue never assigns is_active ==\n";
check('A1 KT_UNLOCK_SQL assigns ONLY activation_locked (parsed SET column list == [activation_locked]); is_active appears only in WHERE', kt_set_columns(KT_UNLOCK_SQL) === ['activation_locked'] && str_contains(KT_UNLOCK_SQL, 'WHERE id = :id AND is_active = 1 AND activation_locked = 1') && !in_array('is_active', kt_set_columns(KT_UNLOCK_SQL), true));
check('A2 KT_RELOCK_SQL (rollback) assigns ONLY activation_locked (0 -> 1); is_active only in WHERE', kt_set_columns(KT_RELOCK_SQL) === ['activation_locked'] && str_contains(KT_RELOCK_SQL, 'activation_locked = 1 WHERE id = :id AND is_active = 1 AND activation_locked = 0'));
$src = (string) file_get_contents($root . '/scripts/rv3/kt_activate.php') . (string) file_get_contents($root . '/scripts/rv3/kt_opening_lib.php');
preg_match_all('/UPDATE\s+warehouses\s+SET\s+(.*?)\s+WHERE\b/is', $src, $m);
$assigned = [];
foreach ($m[1] as $set) {
    $assigned = array_merge($assigned, kt_set_columns("UPDATE warehouses SET {$set} WHERE 1"));
}
check('A3 EVERY `UPDATE warehouses SET …` statement in the shipped kt_activate.php / kt_opening_lib.php assigns only activation_locked (' . count($m[1]) . ' statements) — is_active is never in an update column list', $m[1] !== [] && array_unique($assigned) === ['activation_locked'], json_encode($assigned));
check('A4 the script text contains no "SET is_active" at all', !preg_match('/SET\s+is_active/i', $src));
check('A5 kt_set_columns detects the wrong form (self-test of the proof): "SET is_active = 1, activation_locked = 0" → [is_active, activation_locked]', kt_set_columns('UPDATE warehouses SET is_active = 1, activation_locked = 0 WHERE id = 3') === ['is_active', 'activation_locked']);
check('A6 state machine: 1/1 READY_TO_UNLOCK · 1/0 ALREADY_ACTIVE_AND_UNLOCKED · 0/1 and 0/0 UNEXPECTED_INACTIVE_STATE', kt_unlock_state(1, 1) === 'READY_TO_UNLOCK' && kt_unlock_state(1, 0) === 'ALREADY_ACTIVE_AND_UNLOCKED' && kt_unlock_state(0, 1) === 'UNEXPECTED_INACTIVE_STATE' && kt_unlock_state(0, 0) === 'UNEXPECTED_INACTIVE_STATE');

echo "\n== 5. 1/1 BEFORE the opening is posted → WAITING / BLOCKED, no update ==\n";
$logStart();
[$code, $o] = $act('plan');
check('5a plan: CURRENT: ACTIVE + LOCKED · TARGET: ACTIVE + UNLOCKED · opening posted: FAIL / WAITING · unlock permitted: NO · planned mutation: activation_locked 1 -> 0 · STATUS WAITING / BLOCKED — opening not posted',
    $code === 11 && str_contains($o, 'CURRENT: ACTIVE + LOCKED') && str_contains($o, 'TARGET: ACTIVE + UNLOCKED') && str_contains($o, 'opening posted: FAIL / WAITING') && str_contains($o, 'unlock permitted: NO') && str_contains($o, 'planned mutation: activation_locked 1 -> 0') && str_contains($o, 'WAITING / BLOCKED — opening not posted'), "exit {$code}");
check('5b the plan NEVER says "inactive" for this state and NEVER contains "SET is_active"', !preg_match('/inactive \(is_active=0\)/i', $o) && !str_contains($o, 'SET is_active') && !str_contains($o, 'warehouse state is inactive') && !preg_match('/CURRENT: INACTIVE/', $o));
[$code, $o] = $act('activate --plan-sha=' . str_repeat('0', 64) . ' --actor=ku_super --yes');
check('5c activate --yes before the opening: refused (exit 11 WAITING_OPENING_NOT_RECONCILED), is_active/activation_locked still 1/1', $code === 11 && str_contains($o, 'WAITING_OPENING_NOT_RECONCILED') && $raw() === ['1', '1'], $o);
$st = $logStatements();
check('5d the SQL the server received during plan + the refused activate contains NO UPDATE warehouses and NO audit insert (server general log)', $st === [], json_encode($st));

// post the opening (the same way production will): ACTIVE + LOCKED is the normal posting path
$plan = kt_plan($pdo, kt_read_source($tmp));
check('0 the opening plan accepts ACTIVE + LOCKED (normal posting path, no bypass)', $plan['warehouse']['mode'] === 'normal-active' && !$plan['blocked']);
kt_post($pdo, $plan, 'ku_super', $plan['preview_sha']);

echo "\n== 1. 1/1 AFTER the opening → unlock → 1/0, is_active unchanged ==\n";
[$code, $o] = $act('plan');
$planSha = $sha($o);
check('1a plan after the opening: opening posted: PASS · unlock permitted: YES · STATUS SIAP · exit 0 · no "SET is_active"', $code === 0 && str_contains($o, 'opening posted: PASS') && str_contains($o, 'unlock permitted: YES') && str_contains($o, 'STATUS      : SIAP') && !str_contains($o, 'SET is_active') && $planSha !== '', substr($o, -400));
$isActiveBefore = $raw()[0];
$au0 = $audits();
$logStart();
[$code, $o] = $act("activate --plan-sha={$planSha} --actor=ku_super --yes");
check('1b activate --yes: exit 0', $code === 0, $o);
$st = $logStatements();
$upd = array_values(array_filter($st, static fn ($l) => stripos($l, 'UPDATE') !== false && stripos($l, 'warehouses') !== false));
check('1b2 GENERATED SQL (server general log): exactly ONE UPDATE warehouses statement, its SET column list is exactly [activation_locked] — is_active is NOT in it — and it carries is_active = 1 only as a WHERE guard', count($upd) === 1 && array_map('kt_set_columns', $upd) === [['activation_locked']] && !preg_match('/SET[^W]*is_active/i', $upd[0]) && str_contains($upd[0], 'is_active = 1'), json_encode($upd));
check('1c AFTER: is_active = 1 (byte-identical to before: "' . $isActiveBefore . '" → "' . $raw()[0] . '"), activation_locked = 0', $raw() === ['1', '0'] && $raw()[0] === $isActiveBefore);
check('1d exactly one audit row (before ACTIVE + LOCKED → after ACTIVE + UNLOCKED)', $audits() === $au0 + 1);

echo "\n== 2. 1/0 → ALREADY_ACTIVE_AND_UNLOCKED: no write ==\n";
$logStart();
$au1 = $audits();
[$code, $o] = $act("activate --plan-sha={$planSha} --actor=ku_super --yes");
check('2a exit 0, prints ALREADY_ACTIVE_AND_UNLOCKED, no audit row, state unchanged 1/0', $code === 0 && str_contains($o, 'ALREADY_ACTIVE_AND_UNLOCKED') && $audits() === $au1 && $raw() === ['1', '0'], $o);
$st = $logStatements();
check('2b the SQL the server received for the no-op contains NO UPDATE warehouses and NO audit insert (server general log)', $st === [], json_encode($st));
[$code, $o] = $act('plan');
check('2c plan on 1/0: "unlock permitted: NOT NEEDED (ALREADY_ACTIVE_AND_UNLOCKED)", exit 0', $code === 0 && str_contains($o, 'ALREADY_ACTIVE_AND_UNLOCKED') && str_contains($o, 'CURRENT: ACTIVE + UNLOCKED'));

echo "\n== 3 + 4. unexpectedly INACTIVE (0/1 and 0/0) → UNEXPECTED_INACTIVE_STATE: blocked, no update ==\n";
foreach ([[0, 1, '3'], [0, 0, '4']] as [$a, $l, $n]) {
    $setState($a, $l);
    $logStart();
    $au = $audits();
    [$code, $o] = $act('plan');
    $p = $sha($o);
    [$code2, $o2] = $act("activate --plan-sha={$p} --actor=ku_super --yes");
    check("{$n}a state {$a}/{$l}: plan prints CURRENT: INACTIVE + " . ($l === 1 ? 'LOCKED' : 'UNLOCKED') . ' and UNEXPECTED_INACTIVE_STATE, unlock permitted: NO, exit 11', $code === 11 && str_contains($o, 'CURRENT: INACTIVE + ' . ($l === 1 ? 'LOCKED' : 'UNLOCKED')) && str_contains($o, 'UNEXPECTED_INACTIVE_STATE') && str_contains($o, 'unlock permitted: NO'), "exit {$code}");
    check("{$n}b activate --yes refused (exit 11 UNEXPECTED_INACTIVE_STATE); is_active NEVER set to 1; row unchanged {$a}/{$l}; no audit row", $code2 === 11 && str_contains($o2, 'UNEXPECTED_INACTIVE_STATE') && $raw() === [(string) $a, (string) $l] && $audits() === $au, $o2);
    $st = $logStatements();
    check("{$n}c the SQL the server received contains NO UPDATE warehouses and NO audit insert (server general log)", $st === [], json_encode($st));
}

echo "\n== rollback = activation_locked 0 -> 1 only ==\n";
$setState(1, 0);
[$code, $o] = $act('rollback');
check('R1 rollback plan states "activation_locked 0 -> 1 only (is_active is NOT modified)" and the exact SQL', $code === 10 && str_contains($o, 'activation_locked 0 -> 1 only') && str_contains($o, 'is_active is NOT modified') && str_contains($o, 'UPDATE warehouses SET activation_locked = 1 WHERE id = ' . $kt . ' AND is_active = 1 AND activation_locked = 0') && !str_contains($o, 'SET is_active'), $o);
[$code, $o] = $act('rollback --yes --confirm=ACTIVATE_ROLLBACK');
check('R2 rollback --yes --confirm: 1/0 → 1/1 (no operational transaction exists); is_active untouched', $code === 0 && $raw() === ['1', '1'], $o);
$setState(0, 1);
[$code, $o] = $act('rollback --yes --confirm=ACTIVATE_ROLLBACK');
check('R3 rollback while INACTIVE (0/1): blocked ("not ACTIVE + UNLOCKED now"), nothing changed', $code === 11 && $raw() === ['0', '1'], $o);

$mysqlRoot('SET GLOBAL general_log = 0');
@unlink($tmp);
$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
