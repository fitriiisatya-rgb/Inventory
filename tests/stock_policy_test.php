<?php
declare(strict_types=1);

/**
 * PHASE V2 3d — StockPolicyService::resolve()/upsert()/stockStatus() and
 * the GET/PUT /stock-policy endpoints. Requires a configured .env pointing
 * at a throwaway/dev database with the V2 schema applied.
 *
 * Usage: php tests/stock_policy_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/StockPolicyService.php';

use App\Services\Database;
use App\Services\NotFoundException;
use App\Services\StockPolicyService;
use App\Services\UnitConversionService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $kgUnitId, float $minimumStock = 10.0): int
{
    $sku = uid('SKU-POL');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock) VALUES (:s,:n,:u,:m)')
        ->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId, 'm' => $minimumStock]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $itemId;
}

$whA = makeWarehouse($pdo, uid('POL-WH-A'));
$whB = makeWarehouse($pdo, uid('POL-WH-B'));
$adminUserId = (int) $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
if ($adminUserId <= 0) {
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => uid('poltestuser'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Pol Test User', 'r' => $superadminRoleId]);
    $adminUserId = (int) $pdo->lastInsertId();
}

echo "\n== A: resolve() falls back to items.minimum_stock with buffer unconfigured when no policy row exists ==\n";
$itemA = makeItem($pdo, $kgUnitId, 25.0);
$resolved = StockPolicyService::resolve($pdo, $itemA, $whA);
check('source = fallback', $resolved['source'] === 'fallback', $resolved['source']);
check('minimum_stock = items.minimum_stock (25.0)', $resolved['minimum_stock'] === 25.0, (string) $resolved['minimum_stock']);
check('buffer_stock is null', $resolved['buffer_stock'] === null);
check('buffer_configured is false', $resolved['buffer_configured'] === false);
check('policy_id is null', $resolved['policy_id'] === null);

echo "\n== B: upsert() creates a policy row; resolve() then reads from it, not the fallback ==\n";
$upsertResult = StockPolicyService::upsert($pdo, ['item_id' => $itemA, 'warehouse_id' => $whA, 'minimum_stock' => 40.0, 'buffer_stock' => 15.0, 'updated_by' => $adminUserId]);
check('upsert returns success + policy_id', ($upsertResult['success'] ?? false) === true && ($upsertResult['policy_id'] ?? null) !== null);
$resolvedAfter = StockPolicyService::resolve($pdo, $itemA, $whA);
check('source = policy after upsert', $resolvedAfter['source'] === 'policy');
check('minimum_stock = 40.0 (policy value, not the item global 25.0)', $resolvedAfter['minimum_stock'] === 40.0, (string) $resolvedAfter['minimum_stock']);
check('buffer_stock = 15.0', $resolvedAfter['buffer_stock'] === 15.0, (string) $resolvedAfter['buffer_stock']);
check('buffer_configured = true', $resolvedAfter['buffer_configured'] === true);

echo "\n== C: editing warehouse A's policy never touches warehouse B's row for the same item ==\n";
$resolvedB = StockPolicyService::resolve($pdo, $itemA, $whB);
check('warehouse B still falls back to items.minimum_stock (25.0), unaffected by A\'s policy', $resolvedB['source'] === 'fallback' && $resolvedB['minimum_stock'] === 25.0);

echo "\n== D: re-upsert updates the SAME row (no duplicate policy rows) ==\n";
StockPolicyService::upsert($pdo, ['item_id' => $itemA, 'warehouse_id' => $whA, 'minimum_stock' => 50.0, 'buffer_stock' => null, 'updated_by' => $adminUserId]);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
$countStmt->execute(['i' => $itemA, 'w' => $whA]);
check('exactly one row exists for this item+warehouse after two upserts', (int) $countStmt->fetchColumn() === 1);
$resolvedAgain = StockPolicyService::resolve($pdo, $itemA, $whA);
check('buffer can be explicitly cleared back to unconfigured', $resolvedAgain['buffer_configured'] === false);
check('minimum updated to 50.0', $resolvedAgain['minimum_stock'] === 50.0);

echo "\n== E: validation rejects negative minimum/buffer ==\n";
try {
    StockPolicyService::upsert($pdo, ['item_id' => $itemA, 'warehouse_id' => $whA, 'minimum_stock' => -1, 'updated_by' => $adminUserId]);
    check('negative minimum_stock rejected', false, 'no exception thrown');
} catch (ValidationException $e) {
    check('negative minimum_stock rejected', true);
}
try {
    StockPolicyService::upsert($pdo, ['item_id' => $itemA, 'warehouse_id' => $whA, 'minimum_stock' => 5, 'buffer_stock' => -1, 'updated_by' => $adminUserId]);
    check('negative buffer_stock rejected', false, 'no exception thrown');
} catch (ValidationException $e) {
    check('negative buffer_stock rejected', true);
}

echo "\n== F: unknown item/warehouse rejected ==\n";
try {
    StockPolicyService::resolve($pdo, 999999999, $whA);
    check('resolve() on unknown item throws NotFoundException', false);
} catch (NotFoundException $e) {
    check('resolve() on unknown item throws NotFoundException', true);
}
try {
    StockPolicyService::upsert($pdo, ['item_id' => 999999999, 'warehouse_id' => $whA, 'minimum_stock' => 1, 'updated_by' => $adminUserId]);
    check('upsert() on unknown item throws NotFoundException', false);
} catch (NotFoundException $e) {
    check('upsert() on unknown item throws NotFoundException', true);
}

echo "\n== G: stockStatus() — the 5-state calculation, evaluated in order ==\n";
check('migration_negative_review always wins -> REVIEW, even with qty>minimum', StockPolicyService::stockStatus(100, 10, 5, true) === StockPolicyService::STATUS_REVIEW);
check('qty <= 0 -> OUT_OF_STOCK', StockPolicyService::stockStatus(0, 10, 5, false) === StockPolicyService::STATUS_OUT_OF_STOCK);
check('negative qty -> OUT_OF_STOCK', StockPolicyService::stockStatus(-5, 10, 5, false) === StockPolicyService::STATUS_OUT_OF_STOCK);
check('0 < qty < minimum -> CRITICAL', StockPolicyService::stockStatus(5, 10, 5, false) === StockPolicyService::STATUS_CRITICAL);
check('qty >= minimum, buffer NOT configured -> SAFE (LOW never fires without buffer)', StockPolicyService::stockStatus(10, 10, null, false) === StockPolicyService::STATUS_SAFE);
check('qty >= minimum, buffer configured, qty < minimum+buffer -> LOW', StockPolicyService::stockStatus(12, 10, 5, false) === StockPolicyService::STATUS_LOW);
check('qty >= minimum+buffer -> SAFE', StockPolicyService::stockStatus(15, 10, 5, false) === StockPolicyService::STATUS_SAFE);
check('boundary: qty exactly = minimum+buffer -> SAFE (not LOW)', StockPolicyService::stockStatus(15, 10, 5, false) === StockPolicyService::STATUS_SAFE);
check('boundary: qty exactly = minimum, buffer configured -> LOW, not CRITICAL (still within the buffer zone)', StockPolicyService::stockStatus(10, 10, 5, false) === StockPolicyService::STATUS_LOW);
check('boundary: qty exactly = minimum, buffer NOT configured -> SAFE, not CRITICAL', StockPolicyService::stockStatus(10, 10, null, false) === StockPolicyService::STATUS_SAFE);

// ============================================================
// H: HTTP-level warehouse-scope enforcement for GET/PUT /stock-policy
// ============================================================
echo "\n== H: HTTP warehouse-scope enforcement ==\n";

$port = 8700 + random_int(0, 400);
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
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

try {
    $stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
    $stockUser = uid('polstock'); $stockPass = 'PolStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $whA]);

    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';

    $readOwn = httpCall('GET', "{$base}/stock-policy?item_id={$itemA}&warehouse_id={$whA}", null, $jar);
    check('STOCK user can GET stock-policy for their own warehouse', $readOwn['status'] === 200, json_encode($readOwn['body']));

    $readOther = httpCall('GET', "{$base}/stock-policy?item_id={$itemA}&warehouse_id={$whB}", null, $jar);
    check('STOCK user cannot GET stock-policy for another warehouse', $readOther['status'] === 403 && $readOther['body']['error']['code'] === 'FORBIDDEN');

    // STOCK does not hold STOCK_POLICY_MANAGE by default (schema.sql role_permissions seed) — write must be rejected FORBIDDEN.
    $writeOwn = httpCall('PUT', "{$base}/stock-policy", ['item_id' => $itemA, 'warehouse_id' => $whA, 'minimum_stock' => 99], $jar, $csrf);
    check('STOCK user (no STOCK_POLICY_MANAGE grant) cannot PUT stock-policy even for their own warehouse', $writeOwn['status'] === 403 && $writeOwn['body']['error']['code'] === 'FORBIDDEN');

    // SUPERADMIN has STOCK_POLICY_MANAGE and no warehouse scope restriction.
    $superUser = uid('polsuper'); $superPass = 'PolSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $superWrite = httpCall('PUT', "{$base}/stock-policy", ['item_id' => $itemA, 'warehouse_id' => $whB, 'minimum_stock' => 77, 'buffer_stock' => 20], $superJar, $superCsrf);
    check('SUPERADMIN can PUT stock-policy for any warehouse', ($superWrite['body']['data']['success'] ?? false) === true, json_encode($superWrite['body']));

    $verifyRead = httpCall('GET', "{$base}/stock-policy?item_id={$itemA}&warehouse_id={$whB}", null, $superJar);
    check(
        'Written policy is immediately readable back correctly',
        (float) ($verifyRead['body']['data']['minimum_stock'] ?? -1) === 77.0 && ($verifyRead['body']['data']['buffer_configured'] ?? null) === true,
        json_encode($verifyRead['body']['data'] ?? null)
    );

    foreach ([$jar, $superJar] as $f) { @unlink($f); }
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
