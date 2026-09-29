<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11.3 — URGENT HOTFIX: independent login role for Stock
 * Opname counters ("Petugas Stock Opname" / OPNAME_COUNTER). Combines
 * service-level assertions (direct $pdo access — password hashing,
 * zero-permission proof, duplicate/validation rejection, historical-data
 * preservation) with real HTTP-level assertions (php -S + curl — login/
 * logout, admin-route blocking, blind-count route access once assigned)
 * against the actual application, matching this project's existing
 * dual-level test convention. Covers the 21 required security/isolation
 * scenarios verbatim, plus the P1/P2-same-session exclusivity re-check
 * (#21) under this specific new role.
 *
 * Usage: php tests/inventory_v2_14_11_3_opname_counter_role_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/StockOpnamePhotoService.php';
require_once __DIR__ . '/../services/StockOpnameCounterAccountService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnameCounterAccountService;
use App\Services\AuthService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function expectException(callable $fn, string $class): ?string
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            return null;
        }
        return $e->getMessage();
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
check('timezone: application timezone is Asia/Jakarta', date_default_timezone_get() === 'Asia/Jakarta', date_default_timezone_get());

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

// ============================================================
echo "\n== 9. counter account has ZERO role_permissions ==\n";
$counterPerms = AuthService::permissionsForRole($pdo, 'OPNAME_COUNTER');
check('9. OPNAME_COUNTER role has zero permissions (fresh install / migrated DB)', $counterPerms === [], json_encode($counterPerms));

// ============================================================
echo "\n== 5/7/8. create() — hashing, duplicate, short-password rejection ==\n";
function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'PlainPass' . bin2hex(random_bytes(3)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}
$superadminActor = makeUser($pdo, 'v214113super', $superRoleId);
$adminActor = makeUser($pdo, 'v214113admin', $adminRoleId);
$stockActor = makeUser($pdo, 'v214113stock', $stockRoleId);

$plainPassword = 'TrialP1Pass123';
$counterUsername = strtolower(uid('trialp1'));
$created = Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create(
    $tx, 'Trial P1', $counterUsername, $plainPassword, true, $superadminActor['id'], $superadminActor['username']
));
check('service: create() returns no password field', !array_key_exists('password', $created) && !array_key_exists('password_hash', $created), json_encode(array_keys($created)));

$hashRow = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$hashRow->execute(['id' => $created['id']]);
$hash = $hashRow->fetchColumn();
check('5. password stored hashed (bcrypt, never plaintext)', is_string($hash) && str_starts_with($hash, '$2y$') && password_verify($plainPassword, $hash), (string) $hash);

$dupErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create(
    $tx, 'Trial Dup', $counterUsername, 'AnotherPass123', true, $superadminActor['id'], $superadminActor['username']
)), ValidationException::class);
check('7. duplicate username rejected', $dupErr !== null, (string) $dupErr);

$shortPwErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create(
    $tx, 'Trial Short', strtolower(uid('shortpw')), 'short1', true, $superadminActor['id'], $superadminActor['username']
)), ValidationException::class);
check('8. invalid/short password rejected (<8 chars)', $shortPwErr !== null, (string) $shortPwErr);

// ============================================================
echo "\n== 19/20. resetPassword() + historical data preserved on deactivate ==\n";
$newPassword = 'ResetPass456!';
Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::resetPassword($tx, $created['id'], $newPassword, $superadminActor['id'], $superadminActor['username']));
$hashRow2 = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$hashRow2->execute(['id' => $created['id']]);
check('19. resetPassword() actually changes the stored hash to the new password', password_verify($newPassword, (string) $hashRow2->fetchColumn()));

// ---- fixture for team-assignment / historical-data checks ----
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'V214113 WH', 1)")->execute(['c' => uid('V214113')]);
$whId = (int) $pdo->lastInsertId();
function makeItem(PDO $pdo, string $tag, int $baseUnitId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
$item = makeItem($pdo, 'V214113-A', $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v214113-in'), 'item_id' => $item, 'warehouse_id' => $whId,
    'input_qty' => 40, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $superadminActor['id'], 'username' => 'v214113', 'transaction_type' => 'OPENING',
]));
$counterP2Username = strtolower(uid('trialp2'));
$counterP2 = Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create(
    $tx, 'Trial P2', $counterP2Username, 'TrialP2Pass123', true, $superadminActor['id'], $superadminActor['username']
));
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $superadminActor['id'], [$item], 'FINDINGS_V1'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p1', [$created['id']], $superadminActor['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p2', [$counterP2['id']], $superadminActor['id']));

// ---- 21. same user cannot be P1 and P2 in the same session (re-verified under OPNAME_COUNTER) ----
$sameUserErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers(
    $tx, $sessionId, 'p2', [$created['id']], $superadminActor['id']
)), ValidationException::class);
check('21. same OPNAME_COUNTER user cannot be P1 and P2 in the same session', $sameUserErr !== null, (string) $sameUserErr);

// ---- 14. P1 assignment grants counting authority for THIS session ----
$claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $created['id'], $item));
$findingResult = Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $sessionId, 'p1', $item,
    ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 40]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
    null, $created['id'], $claim['claim_token']
));
check('14. P1/P2 session assignment grants real counting authority for that session (finding recorded)', isset($findingResult['lines']));

$findingRow = $pdo->prepare('SELECT id FROM stock_opname_findings WHERE stock_opname_line_id = (SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item) ORDER BY id DESC LIMIT 1');
$findingRow->execute(['sid' => $sessionId, 'item' => $item]);
$findingId = (int) $findingRow->fetchColumn();

// ---- 20. deactivation must not delete historical findings/team membership ----
Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::deactivate($tx, $created['id'], $superadminActor['id'], $superadminActor['username']));
$findingStillExists = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_findings WHERE id = :id');
$findingStillExists->execute(['id' => $findingId]);
check('20a. historical finding NOT deleted after counter deactivation', (int) $findingStillExists->fetchColumn() === 1);
$memberStillExists = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_team_members WHERE session_id = :sid AND user_id = :uid');
$memberStillExists->execute(['sid' => $sessionId, 'uid' => $created['id']]);
check('20b. historical team-membership row NOT deleted after counter deactivation', (int) $memberStillExists->fetchColumn() === 1);
$accountStillExists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
$accountStillExists->execute(['id' => $created['id']]);
check('20c. the account itself is NOT hard-deleted (only is_active flipped)', (int) $accountStillExists->fetchColumn() === 1);

// reactivate for the HTTP section below
Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::activate($tx, $created['id'], $superadminActor['id'], $superadminActor['username']));

// ---- 15 (service slice): a plain assertNotFindingsV1-style role check never substitutes for STOCK_OPNAME_MANAGE/SUPERVISE ----
$noPermErr = expectException(fn () => (function () use ($pdo) {
    if (!AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'STOCK_OPNAME_MANAGE')) {
        throw new ValidationException(['OPNAME_COUNTER has no STOCK_OPNAME_MANAGE']);
    }
})(), ValidationException::class);
check('15a. AuthService confirms OPNAME_COUNTER role never has STOCK_OPNAME_MANAGE', $noPermErr !== null);
check('15b. AuthService confirms OPNAME_COUNTER role never has STOCK_OPNAME_SUPERVISE', AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'STOCK_OPNAME_SUPERVISE') === false);
check('16. AuthService confirms OPNAME_COUNTER role never has INVENTORY_VIEW (Master/report surface)', AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'INVENTORY_VIEW') === false);
check('16b. AuthService confirms OPNAME_COUNTER role never has TRANSACTION_IN_CREATE/OUT_CREATE (transaction surface)', AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'TRANSACTION_IN_CREATE') === false && AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'TRANSACTION_OUT_CREATE') === false);
check('16c. AuthService confirms OPNAME_COUNTER role never has MASTER_ITEM_MANAGE (master-data write surface)', AuthService::hasPermission($pdo, 'OPNAME_COUNTER', 'MASTER_ITEM_MANAGE') === false);

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Service-level section: {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — real application routes (php -S + curl)
// ============================================================
$port = 8900 + random_int(3600, 3999);
$docRoot = __DIR__ . '/../public';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, __DIR__ . '/..');
if (!is_resource($process)) { fwrite(STDERR, "Failed to start php -S\n"); exit(1); }
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($res !== false && $err === 0) { $ready = true; break; }
}
if (!$ready) { fwrite(STDERR, "Server did not become ready\n"); proc_terminate($process); exit(1); }

function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}
function login(string $base, array $creds): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $creds['username'], 'password' => $creds['password']], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? '', 'status' => $res['status'], 'raw' => $res['raw']];
}

try {
    $superAuth = login($base, $superadminActor);
    check('setup: SUPERADMIN login succeeds', $superAuth['status'] === 200, (string) $superAuth['status']);
    $adminAuth = login($base, $adminActor);
    $stockAuth = login($base, $stockActor);

    // ---- 1/2/3/4/6. create-account authorization + plaintext-never-returned ----
    $httpPlainPassword = 'HttpCreated123';
    $httpUsername = strtolower(uid('httptrial'));
    $createAsSuper = httpCall('POST', "{$base}/stock-opname/counter-accounts", [
        'full_name' => 'HTTP Trial', 'username' => $httpUsername, 'password' => $httpPlainPassword, 'is_active' => true,
    ], $superAuth['jar'], $superAuth['csrf']);
    check('1. SUPERADMIN can create OPNAME_COUNTER over HTTP', $createAsSuper['status'] === 200, json_encode($createAsSuper['body']));
    check('6. plaintext password never appears anywhere in the create response', !str_contains($createAsSuper['raw'], $httpPlainPassword));

    $createAsAdmin = httpCall('POST', "{$base}/stock-opname/counter-accounts", [
        'full_name' => 'Should Fail', 'username' => strtolower(uid('shouldfail')), 'password' => 'WontWork123', 'is_active' => true,
    ], $adminAuth['jar'], $adminAuth['csrf']);
    check('2. ADMIN cannot create OPNAME_COUNTER (403)', $createAsAdmin['status'] === 403, json_encode($createAsAdmin['body']));

    $createAsStock = httpCall('POST', "{$base}/stock-opname/counter-accounts", [
        'full_name' => 'Should Fail 2', 'username' => strtolower(uid('shouldfail2')), 'password' => 'WontWork123', 'is_active' => true,
    ], $stockAuth['jar'], $stockAuth['csrf']);
    check('3. STOCK cannot create OPNAME_COUNTER (403)', $createAsStock['status'] === 403, json_encode($createAsStock['body']));

    // ---- 10. counter can login when active ----
    $counterAuth = login($base, ['username' => $counterUsername, 'password' => $newPassword]);
    check('10. active counter can login', $counterAuth['status'] === 200, json_encode($counterAuth));

    // ---- 4. OPNAME_COUNTER cannot create users (itself) ----
    $createAsCounter = httpCall('POST', "{$base}/stock-opname/counter-accounts", [
        'full_name' => 'Should Fail 3', 'username' => strtolower(uid('shouldfail3')), 'password' => 'WontWork123', 'is_active' => true,
    ], $counterAuth['jar'], $counterAuth['csrf']);
    check('4. OPNAME_COUNTER cannot create accounts (403)', $createAsCounter['status'] === 403, json_encode($createAsCounter['body']));

    // ---- 12. active unassigned counter sees zero SO sessions ----
    $unassignedUsername = strtolower(uid('unassigned'));
    Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create($tx, 'Unassigned Counter', $unassignedUsername, 'UnassignedPass123', true, $superadminActor['id'], $superadminActor['username']));
    $unassignedAuth = login($base, ['username' => $unassignedUsername, 'password' => 'UnassignedPass123']);
    $mySessionsUnassigned = httpCall('GET', "{$base}/stock-opname/my-sessions", null, $unassignedAuth['jar']);
    check('12. an active but unassigned counter sees ZERO Stock Opname sessions', $mySessionsUnassigned['status'] === 200 && ($mySessionsUnassigned['body']['data'] ?? null) === [], json_encode($mySessionsUnassigned['body']));

    // ---- 13. assigned counter sees ONLY the assigned session ----
    $itemOther = makeItem($pdo, 'V214113-OTHER', $kgUnitId);
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'V214113 WH Other', 1)")->execute(['c' => uid('V214113O')]);
    $whIdOther = (int) $pdo->lastInsertId();
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v214113-other-in'), 'item_id' => $itemOther, 'warehouse_id' => $whIdOther,
        'input_qty' => 5, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 500,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $superadminActor['id'], 'username' => 'v214113', 'transaction_type' => 'OPENING',
    ]));
    $otherSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdOther, $superadminActor['id'], [$itemOther], 'FINDINGS_V1'));
    // otherSessionId is NEVER assigned to $created — the counter this
    // section logs in as (re-login below) must never see it.
    $mySessionsAssigned = httpCall('GET', "{$base}/stock-opname/my-sessions", null, $counterAuth['jar']);
    $assignedIds = array_map(static fn ($s) => (int) $s['session_id'], $mySessionsAssigned['body']['data'] ?? []);
    check('13. assigned counter sees ONLY their assigned session (not an unrelated one)', in_array($sessionId, $assignedIds, true) && !in_array($otherSessionId, $assignedIds, true), json_encode($assignedIds));

    // ---- 17. counter can access own blind-count route once assigned ----
    $ownSessionView = httpCall('GET', "{$base}/stock-opname/{$sessionId}", null, $counterAuth['jar']);
    check('17. assigned counter CAN GET their own session (blind view, 200)', $ownSessionView['status'] === 200 && ($ownSessionView['body']['data']['role'] ?? '') === 'p1', json_encode($ownSessionView['body']));

    // ---- 15. counter cannot access Stock Opname admin/supervisor endpoints ----
    $startAsCounter = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whId], $counterAuth['jar'], $counterAuth['csrf']);
    check('15c. counter cannot START a new Stock Opname session (STOCK_OPNAME_MANAGE required, 403)', $startAsCounter['status'] === 403, json_encode($startAsCounter['body']));
    $assignTeamAsCounter = httpCall('POST', "{$base}/stock-opname/{$sessionId}/assign-team", ['role' => 'p1', 'user_ids' => [$created['id']]], $counterAuth['jar'], $counterAuth['csrf']);
    check('15d. counter cannot call assign-team (STOCK_OPNAME_MANAGE required, 403)', $assignTeamAsCounter['status'] === 403, json_encode($assignTeamAsCounter['body']));
    $finalizeAsCounter = httpCall('POST', "{$base}/stock-opname/{$sessionId}/finalize", [], $counterAuth['jar'], $counterAuth['csrf']);
    check('15e. counter cannot call finalize (STOCK_OPNAME_SUPERVISE required, 403)', $finalizeAsCounter['status'] === 403, json_encode($finalizeAsCounter['body']));

    // ---- 16d. counter cannot access permission-gated inventory/report endpoints ----
    $reportAsCounter = httpCall('GET', "{$base}/reports/stock", null, $counterAuth['jar']);
    check('16d. counter is blocked from a permission-gated report endpoint (INVENTORY_VIEW required, 403)', $reportAsCounter['status'] === 403, json_encode($reportAsCounter['body']));
    $txAsCounter = httpCall('POST', "{$base}/transactions/in", ['item_id' => $item, 'warehouse_id' => $whId, 'qty' => 1, 'unit_id' => $kgUnitId, 'unit_price' => 100], $counterAuth['jar'], $counterAuth['csrf']);
    check('16e. counter is blocked from creating a transaction (TRANSACTION_IN_CREATE required, 403)', $txAsCounter['status'] === 403, json_encode($txAsCounter['body']));

    // ---- 18/11. deactivation prevents further login ----
    Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::deactivate($tx, $created['id'], $superadminActor['id'], $superadminActor['username']));
    $loginAfterDeactivate = login($base, ['username' => $counterUsername, 'password' => $newPassword]);
    check('11/18. login fails after deactivation (401)', $loginAfterDeactivate['status'] === 401, json_encode($loginAfterDeactivate));
    // re-activate so any later HTTP interaction in this file is unaffected
    Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::activate($tx, $created['id'], $superadminActor['id'], $superadminActor['username']));

    // ---- new team assignment must reject an inactive counter (deactivate P2, try to assign elsewhere) ----
    Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::deactivate($tx, $counterP2['id'], $superadminActor['id'], $superadminActor['username']));
    $assignInactiveErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers(
        $tx, $otherSessionId, 'p1', [$counterP2['id']], $superadminActor['id']
    )), ValidationException::class);
    check('6 (activate/deactivate spec): a deactivated counter is rejected from a NEW team assignment', $assignInactiveErr !== null, (string) $assignInactiveErr);
    Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::activate($tx, $counterP2['id'], $superadminActor['id'], $superadminActor['username']));

} finally {
    if (getenv('V214113_DEBUG_STDERR')) {
        fwrite(STDERR, "\n--- php -S stderr ---\n" . stream_get_contents($pipes[2]) . "\n");
    }
    proc_terminate($process);
    proc_close($process);
}

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed !== $total) {
    exit(1);
}
