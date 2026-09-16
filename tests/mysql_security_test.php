<?php
declare(strict_types=1);

/**
 * PHASE D0.2 — automated security test suite (real HTTP, not manual curl).
 * Spawns its own `php -S` instance against /public on a dedicated port,
 * drives it with PHP's curl_* functions, then tears the server down.
 *
 * Covers: valid login, invalid login, CSRF missing/invalid/valid,
 * VIEWER POST -> 403, STOCK user wrong warehouse -> 403, DIVISION user
 * wrong resource -> 403, login rate limiting, unauthenticated API -> 401.
 *
 * Usage: php tests/mysql_security_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/UnitConversionService.php';

use App\Services\Database;
use App\Services\UnitConversionService;

$port = 8199 + random_int(0, 400); // avoid clashing with a concurrently-running suite
$docRoot = __DIR__ . '/../public';

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

// ---- fixtures ----
$pdo = Database::connection();
$roles = [];
foreach (['SUPERADMIN', 'VIEWER', 'STOCK', 'DIVISION'] as $code) {
    $roles[$code] = (int) $pdo->query("SELECT id FROM roles WHERE code='{$code}'")->fetchColumn();
}

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeDivision(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO divisions (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeUser(PDO $pdo, string $username, string $password, int $roleId, ?int $warehouseId, ?int $divisionId): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, division_id, is_active) VALUES (:u,:h,:n,:r,:w,:d,1)')
        ->execute(['u' => $username, 'h' => password_hash($password, PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId, 'w' => $warehouseId, 'd' => $divisionId]);
    return (int) $pdo->lastInsertId();
}

$whA = makeWarehouse($pdo, uid('SEC-WH-A'));
$whB = makeWarehouse($pdo, uid('SEC-WH-B'));
$divX = makeDivision($pdo, uid('SEC-DIV-X'));

$viewerUser = uid('viewer'); $viewerPass = 'ViewerPass123!';
makeUser($pdo, $viewerUser, $viewerPass, $roles['VIEWER'], null, null);

$stockUser = uid('stock'); $stockPass = 'StockPass123!';
makeUser($pdo, $stockUser, $stockPass, $roles['STOCK'], $whA, null);

$divisionUser = uid('division'); $divisionPass = 'DivisionPass123!';
makeUser($pdo, $divisionUser, $divisionPass, $roles['DIVISION'], null, $divX);

$rateLimitUser = uid('ratelimit'); $rateLimitPass = 'RateLimitPass123!';
makeUser($pdo, $rateLimitUser, $rateLimitPass, $roles['VIEWER'], null, null);

// a minimal item so "VIEWER POST" has a plausible target payload
$sku = uid('SKU-SEC');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

// ---- spawn the server ----
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)),
    $descriptors,
    $pipes,
    __DIR__ . '/..'
);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start php -S\n");
    exit(1);
}
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
    if ($res !== false && $err === 0) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, "Server did not become ready on port {$port}\n");
    proc_terminate($process);
    exit(1);
}

/** @return array{status:int, body:array} */
function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 5,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) {
        $headers[] = "X-CSRF-Token: {$csrfToken}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

try {
    // ---- 1. valid login ----
    $viewerJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $viewerUser, 'password' => $viewerPass], $viewerJar);
    check('1. Valid login succeeds with csrf_token issued', $login['status'] === 200 && $login['body']['success'] === true && !empty($login['body']['data']['csrf_token']));
    $viewerCsrf = $login['body']['data']['csrf_token'] ?? '';

    // ---- 2. invalid login ----
    $badLoginJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $badLogin = httpCall('POST', "{$base}/auth/login", ['username' => $viewerUser, 'password' => 'wrong-password'], $badLoginJar);
    check('2. Invalid login rejected with UNAUTHENTICATED', $badLogin['status'] === 401 && $badLogin['body']['error']['code'] === 'UNAUTHENTICATED');

    // ---- 3. CSRF missing ----
    $noCsrf = httpCall('POST', "{$base}/transactions/in", ['item_id' => $itemId], $viewerJar, null);
    check('3. CSRF missing on mutating request rejected CSRF_INVALID', $noCsrf['status'] === 403 && $noCsrf['body']['error']['code'] === 'CSRF_INVALID');

    // ---- 4. CSRF invalid ----
    $badCsrf = httpCall('POST', "{$base}/transactions/in", ['item_id' => $itemId], $viewerJar, 'not-the-real-token');
    check('4. CSRF invalid token rejected CSRF_INVALID', $badCsrf['status'] === 403 && $badCsrf['body']['error']['code'] === 'CSRF_INVALID');

    // ---- 5. CSRF valid (request proceeds past the CSRF gate — VIEWER still gets FORBIDDEN for a different reason) ----
    $validCsrf = httpCall('POST', "{$base}/transactions/in", ['item_id' => $itemId], $viewerJar, $viewerCsrf);
    check('5. CSRF valid token is accepted (not rejected as CSRF_INVALID)', ($validCsrf['body']['error']['code'] ?? null) !== 'CSRF_INVALID');

    // ---- 6. VIEWER attempts a mutating action -> 403 FORBIDDEN ----
    check('6. VIEWER POST rejected FORBIDDEN', $validCsrf['status'] === 403 && $validCsrf['body']['error']['code'] === 'FORBIDDEN');

    // ---- 7. STOCK user attempts action on a warehouse outside their scope -> 403 FORBIDDEN ----
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';
    $wrongWarehouse = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('sec-in'), 'item_id' => $itemId, 'warehouse_id' => $whB,
        'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00',
    ], $stockJar, $stockCsrf);
    check('7. STOCK user posting to a DIFFERENT warehouse than their scope is rejected FORBIDDEN', $wrongWarehouse['status'] === 403 && $wrongWarehouse['body']['error']['code'] === 'FORBIDDEN', "got status={$wrongWarehouse['status']} code=" . ($wrongWarehouse['body']['error']['code'] ?? 'none'));

    $rightWarehouse = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('sec-in-ok'), 'item_id' => $itemId, 'warehouse_id' => $whA,
        'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00',
    ], $stockJar, $stockCsrf);
    check('7b. Same STOCK user posting to THEIR OWN warehouse is allowed (not FORBIDDEN)', ($rightWarehouse['body']['error']['code'] ?? null) !== 'FORBIDDEN', "got " . json_encode($rightWarehouse['body']));

    // ---- 8. DIVISION user attempts a resource they are not allowed (TRANSACTION_IN_CREATE is STOCK/ADMIN only) ----
    $divisionJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $divisionLogin = httpCall('POST', "{$base}/auth/login", ['username' => $divisionUser, 'password' => $divisionPass], $divisionJar);
    $divisionCsrf = $divisionLogin['body']['data']['csrf_token'] ?? '';
    $divisionAttempt = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('sec-div'), 'item_id' => $itemId, 'warehouse_id' => $whA,
        'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00',
    ], $divisionJar, $divisionCsrf);
    check('8. DIVISION user attempting TRANSACTION_IN_CREATE (not granted to DIVISION) is rejected FORBIDDEN', $divisionAttempt['status'] === 403 && $divisionAttempt['body']['error']['code'] === 'FORBIDDEN');

    // ---- 9. login rate limiting ----
    $rateLimitJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $lastStatus = 0;
    $sawRateLimit = false;
    for ($i = 0; $i < 7; $i++) {
        $attempt = httpCall('POST', "{$base}/auth/login", ['username' => $rateLimitUser, 'password' => 'wrong'], $rateLimitJar);
        $lastStatus = $attempt['status'];
        if ($attempt['status'] === 429) {
            $sawRateLimit = true;
            break;
        }
    }
    check('9. Repeated failed logins eventually rejected RATE_LIMITED (429)', $sawRateLimit, "last status seen: {$lastStatus}");

    // ---- 10. unauthenticated API call ----
    $freshJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $unauth = httpCall('GET', "{$base}/warehouses", null, $freshJar);
    check('10. Unauthenticated API call rejected UNAUTHENTICATED (401)', $unauth['status'] === 401 && $unauth['body']['error']['code'] === 'UNAUTHENTICATED');

    foreach ([$viewerJar, $badLoginJar, $stockJar, $divisionJar, $rateLimitJar, $freshJar] as $jar) {
        @unlink($jar);
    }
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
