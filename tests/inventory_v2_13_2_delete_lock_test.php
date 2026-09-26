<?php
declare(strict_types=1);

/**
 * PHASE V2.13.2 — Karang Tengah locked-warehouse delete-protection hotfix.
 *
 * UAT found that a locked+inactive warehouse (zero dependent rows) could
 * still be permanently deleted via DELETE /warehouses/{id}, because the
 * existing MasterDataSafetyService::checkWarehouseReferences() check only
 * blocks a delete when real data already references the warehouse — a
 * warehouse whose cutover is merely incomplete (activation_locked=1) has
 * exactly zero references and would pass that check. This proves the new,
 * GENERIC (not Karang-Tengah-specific) rule: activation_locked=1 refuses
 * DELETE /warehouses/{id} outright, checked BEFORE the reference check,
 * regardless of the caller's permissions. Covers:
 *   1. a locked warehouse cannot be deleted
 *   2. HTTP DELETE returns 422
 *   3. exact code = WAREHOUSE_CUTOVER_LOCKED
 *   4. the locked row remains in the database afterward
 *   5. the frontend's action-menu logic never wires a clickable "Hapus
 *      Permanen" for a locked row (source-level check)
 *   6. rename/edit of the locked warehouse still works
 *   7. activation-lock behavior (V2.13.1) is unaffected by this patch
 *   8. an UNLOCKED warehouse's existing delete behavior (both the
 *      no-dependencies-allowed and has-dependencies-blocked cases) is
 *      completely unchanged, including for CIBADAK/GUDANG_BESAR
 *   9. full regression is proven green separately by
 *      tests/run_mysql_tests.sh, which this file is registered into
 *
 * Usage: php tests/inventory_v2_13_2_delete_lock_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/MasterDataSafetyService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\WarehouseCutoverLockedException;

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
        return $e instanceof $class ? get_class($e) : ('WRONG_CLASS:' . get_class($e));
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('GUDANG_BESAR', 'Gudang Besar', 'MAIN', 1, 0)")->execute();
$gudangBesarId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('CIBADAK', 'Cibadak', 'TRANSIT', 1, 0)")->execute();
$cibadakId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 0, 1)")->execute();
$karangTengahId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v2132-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v2132', 'transaction_type' => 'OPENING',
    ]));
}

$adminUserId = makeUser($pdo, 'v2132admin', $superRoleId);

// ============================================================
// 1/2/3/4 — direct-service proof + HTTP-level proof.
// ============================================================
echo "== 1: WarehouseGuardService::assertDeletionAllowed rejects a locked warehouse ==\n";
$errDirect = expectException(
    fn () => \App\Services\WarehouseGuardService::assertDeletionAllowed($karangTengahId, 1),
    WarehouseCutoverLockedException::class
);
check('1. assertDeletionAllowed REJECTS activation_locked=1', $errDirect === WarehouseCutoverLockedException::class, (string) $errDirect);

echo "\n== 2/3/4/6: HTTP-level proof (real API path) ==\n";
$port = 8900 + random_int(1600, 1999);
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

try {
    $httpUser = uid('v2132http'); $httpPass = 'V2132HttpPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $httpUser, 'h' => password_hash($httpPass, PASSWORD_BCRYPT), 'n' => $httpUser, 'r' => $superRoleId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $httpUser, 'password' => $httpPass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';
    check('HTTP login succeeds', $login['status'] === 200, json_encode($login['body']));

    // 2/3 — a real DELETE against the locked, zero-dependency Karang
    // Tengah row is refused, even though this user holds
    // MASTER_WAREHOUSE_MANAGE and the warehouse has no dependent data.
    $deleteAttempt = httpCall('DELETE', "{$base}/warehouses/{$karangTengahId}", null, $jar, $csrf);
    check('2. DELETE against a locked warehouse returns HTTP 422', $deleteAttempt['status'] === 422, json_encode($deleteAttempt['body']));
    check('3. response code is exactly WAREHOUSE_CUTOVER_LOCKED', ($deleteAttempt['body']['error']['code'] ?? null) === 'WAREHOUSE_CUTOVER_LOCKED', json_encode($deleteAttempt['body']));
    check('3. response message matches the required Indonesian text', ($deleteAttempt['body']['error']['message'] ?? null) === 'Gudang tidak dapat dihapus selama proses cutover masih terkunci.', json_encode($deleteAttempt['body']));

    // 4 — the row is still there.
    $stillThere = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE id = {$karangTengahId}")->fetchColumn();
    check('4. KARANG_TENGAH row remains in the database after the rejected delete', (int) $stillThere === 1, (string) $stillThere);

    // 6 — rename/edit of the locked warehouse still works (V2.13.1
    // behavior, re-confirmed unaffected by this delete-lock patch).
    $renameAttempt = httpCall('PUT', "{$base}/warehouses/{$karangTengahId}", ['name' => 'Gudang Karang Tengah (V2132 Renamed)'], $jar, $csrf);
    check('6. rename of the locked warehouse still succeeds', $renameAttempt['status'] === 200, json_encode($renameAttempt['body']));
    $nameAfter = $pdo->query("SELECT name FROM warehouses WHERE id = {$karangTengahId}")->fetchColumn();
    check('6. name actually changed', $nameAfter === 'Gudang Karang Tengah (V2132 Renamed)', (string) $nameAfter);

    // 7 — activation-lock behavior (V2.13.1) is unaffected: activation is
    // still refused for this same warehouse.
    $activateAttempt = httpCall('PUT', "{$base}/warehouses/{$karangTengahId}", ['is_active' => true], $jar, $csrf);
    check('7. activation of the locked warehouse is STILL refused (V2.13.1 unaffected)', $activateAttempt['status'] === 422, json_encode($activateAttempt['body']));
    check('7. response code is still WAREHOUSE_ACTIVATION_LOCKED', ($activateAttempt['body']['error']['code'] ?? null) === 'WAREHOUSE_ACTIVATION_LOCKED', json_encode($activateAttempt['body']));

    // 8a — an UNLOCKED warehouse with zero dependencies can still be
    // deleted normally (existing behavior unchanged).
    $pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('V2132_UNLOCKED_EMPTY', 'Unlocked Empty Test Warehouse', 'TRANSIT', 0, 0)")->execute();
    $unlockedEmptyId = (int) $pdo->lastInsertId();
    $deleteUnlockedEmpty = httpCall('DELETE', "{$base}/warehouses/{$unlockedEmptyId}", null, $jar, $csrf);
    check('8a. an unlocked, zero-dependency warehouse CAN still be deleted normally', $deleteUnlockedEmpty['status'] === 200, json_encode($deleteUnlockedEmpty['body']));
    $unlockedEmptyGone = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE id = {$unlockedEmptyId}")->fetchColumn();
    check('8a. the unlocked warehouse row is actually gone', (int) $unlockedEmptyGone === 0, (string) $unlockedEmptyGone);

    // 8b — an UNLOCKED warehouse WITH dependencies is still blocked by the
    // pre-existing FK/reference safety check (existing behavior unchanged).
    $itemDep = makeItem($pdo, $kgUnitId, 'V2132-DEP');
    postIn($pdo, $itemDep, $cibadakId, $kgUnitId, 10, 1000, $adminUserId);
    $deleteCibadak = httpCall('DELETE', "{$base}/warehouses/{$cibadakId}", null, $jar, $csrf);
    check('8b. CIBADAK (unlocked, but now has dependent stock) is still blocked by the existing reference check', $deleteCibadak['status'] === 422, json_encode($deleteCibadak['body']));
    check('8b. CIBADAK block reason is still the pre-existing DELETE_BLOCKED_HAS_REFERENCES, not the new lock code', ($deleteCibadak['body']['error']['code'] ?? null) === 'DELETE_BLOCKED_HAS_REFERENCES', json_encode($deleteCibadak['body']));
    $cibadakStillThere = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE id = {$cibadakId}")->fetchColumn();
    check('8b. CIBADAK row remains in the database', (int) $cibadakStillThere === 1, (string) $cibadakStillThere);

    // 5 (source-level) — the action-menu builder must never wire an
    // onClick "Hapus Permanen" handler for a locked row; it must instead
    // render a disabled, explanatory item.
    $jsSrc = file_get_contents(__DIR__ . '/../public/assets/js/master-warehouses.js');
    check('5a. master-warehouses.js branches on activation_locked before offering Hapus Permanen', str_contains($jsSrc, "'Hapus Permanen (Cutover Terkunci)'"), '');
    check('5b. the locked delete branch is marked disabled (no click handler wired)', (bool) preg_match('/activation_locked\s*\?\s*\{\s*label:\s*\'Hapus Permanen \(Cutover Terkunci\)\',\s*danger:\s*true,\s*disabled:\s*true\s*\}/s', $jsSrc), '');
} finally {
    proc_terminate($process);
    usleep(200_000);
    proc_close($process);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
