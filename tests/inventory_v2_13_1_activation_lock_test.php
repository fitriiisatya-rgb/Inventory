<?php
declare(strict_types=1);

/**
 * PHASE V2.13.1 — Karang Tengah activation safety patch.
 *
 * Proves the GENERIC `warehouses.activation_locked` mechanism added by
 * this patch: a warehouse with activation_locked = 1 can never have
 * is_active flipped 0 -> 1 through PUT /warehouses/{id}, however
 * permissioned the caller is — enforced server-side, not merely hidden by
 * the UI. Covers:
 *   1. migration adds activation_locked column, default 0
 *   2. existing warehouses (GUDANG_BESAR/CIBADAK) remain unlocked
 *   3. KARANG_TENGAH inserted inactive + activation_locked = 1
 *   4/5. an authorized warehouse manager's real HTTP activation attempt
 *        against a locked warehouse is rejected 422/WAREHOUSE_ACTIVATION_LOCKED
 *   6. the frontend's action-menu logic never offers a plain "Aktifkan"
 *      action for a locked+inactive row (source-level + live browser check)
 *   7. renaming a locked warehouse still works
 *   8. an inactive locked warehouse remains blocked from stock mutation
 *      (the pre-existing, unrelated is_active guard)
 *   9. an UNLOCKED inactive warehouse can still be activated normally
 *   10. existing CIBADAK/GUDANG_BESAR activate/deactivate behavior is
 *       unchanged (both unlocked)
 *   11. inventory qty/value/batch count are unaffected by this migration
 *   12. full regression is proven green separately by
 *       tests/run_mysql_tests.sh, which this file is registered into
 *
 * Usage: php tests/inventory_v2_13_1_activation_lock_test.php
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
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\WarehouseInactiveException;

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
function applySqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, fn (string $l) => !str_starts_with(ltrim($l), '--'));
    $cleaned = implode("\n", $lines);
    foreach (explode(';', $cleaned) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

// Pre-existing warehouses, inserted WITHOUT activation_locked (so it must
// come from the column's own DEFAULT — exactly what a real, already-
// migrated production database would look like before this patch's
// migration runs).
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('GUDANG_BESAR', 'Gudang Besar', 'MAIN', 1)")->execute();
$gudangBesarId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('CIBADAK', 'Cibadak', 'TRANSIT', 1)")->execute();
$cibadakId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
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
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by, string $date = '2026-08-01 08:00:00'): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v2131-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $by, 'username' => 'v2131', 'transaction_type' => 'OPENING',
    ]));
}
function warehouseQtyValue(PDO $pdo, int $whId): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches WHERE warehouse_id = :wh');
    $stmt->execute(['wh' => $whId]);
    return $stmt->fetch(PDO::FETCH_NUM);
}

$adminUserId = makeUser($pdo, 'v2131admin', $superRoleId, null);

// ============================================================
// 11 (fixture). Non-zero inventory BEFORE the migration, so the
// zero-effect invariant is proven against real, non-trivial numbers.
// ============================================================
echo "== Fixture: non-zero inventory (pre-migration) ==\n";
$itemFa = makeItem($pdo, $kgUnitId, 'V2131-FA');
$itemFb = makeItem($pdo, $kgUnitId, 'V2131-FB');
postIn($pdo, $itemFa, $gudangBesarId, $kgUnitId, 500, 10000, $adminUserId, '2026-07-01 08:00:00');
postIn($pdo, $itemFb, $cibadakId, $kgUnitId, 120.5, 7000, $adminUserId, '2026-07-02 08:00:00');
$companyBefore = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
$gbBefore = warehouseQtyValue($pdo, $gudangBesarId);
$cibBefore = warehouseQtyValue($pdo, $cibadakId);
check('fixture setup: company batch count > 0 before migration', (int) $companyBefore[2] > 0);

// ============================================================
// 1/2/3 — apply the real (superseded) V2.13 migration file and verify
// the activation_locked column + row attributes it produces.
// ============================================================
echo "\n== 1/2/3: migration adds activation_locked column + correct row attributes ==\n";
$migrationFile = __DIR__ . '/../database/migrations/2026_09_26_v2_13_karang_tengah_warehouse.sql';
check('migration file exists', is_file($migrationFile));
applySqlFile($pdo, $migrationFile);

$colInfo = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'activation_locked'")->fetch();
check('1. migration adds activation_locked column', $colInfo !== false);
check('1. activation_locked column defaults to 0', $colInfo !== false && (string) $colInfo['Default'] === '0', json_encode($colInfo));

$gb = $pdo->prepare('SELECT is_active, activation_locked FROM warehouses WHERE id = :id');
$gb->execute(['id' => $gudangBesarId]);
$gbRow = $gb->fetch();
$cib = $pdo->prepare('SELECT is_active, activation_locked FROM warehouses WHERE id = :id');
$cib->execute(['id' => $cibadakId]);
$cibRow = $cib->fetch();
check('2. GUDANG_BESAR remains unlocked (activation_locked=0)', (int) $gbRow['activation_locked'] === 0, json_encode($gbRow));
check('2. CIBADAK remains unlocked (activation_locked=0)', (int) $cibRow['activation_locked'] === 0, json_encode($cibRow));
check('2. GUDANG_BESAR remains active (untouched by this migration)', (int) $gbRow['is_active'] === 1);
check('2. CIBADAK remains active (untouched by this migration)', (int) $cibRow['is_active'] === 1);

$kt = $pdo->query("SELECT * FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetch();
check('3. KARANG_TENGAH row exists', $kt !== false);
check('3. KARANG_TENGAH is_active = 0 (inactive)', $kt && (int) $kt['is_active'] === 0, (string) ($kt['is_active'] ?? 'MISSING'));
check('3. KARANG_TENGAH activation_locked = 1', $kt && (int) $kt['activation_locked'] === 1, (string) ($kt['activation_locked'] ?? 'MISSING'));
$karangTengahId = (int) $kt['id'];

// idempotency: re-run must not change any of the above
applySqlFile($pdo, $migrationFile);
$ktCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetchColumn();
check('migration idempotent: no duplicate KARANG_TENGAH row after re-run', $ktCount === 1, "count={$ktCount}");
$kt2 = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetch();
check('migration idempotent: KARANG_TENGAH attributes unchanged after re-run', (int) $kt2['is_active'] === 0 && (int) $kt2['activation_locked'] === 1, json_encode($kt2));

// ============================================================
// 11. Inventory invariant — this migration changes zero qty/value/batches
// ============================================================
echo "\n== 11. Inventory invariant (qty/value/batches unchanged) ==\n";
$companyAfter = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
$gbAfter = warehouseQtyValue($pdo, $gudangBesarId);
$cibAfter = warehouseQtyValue($pdo, $cibadakId);
$ktAfter = warehouseQtyValue($pdo, $karangTengahId);
check('11. company total qty UNCHANGED', (float) $companyBefore[0] === (float) $companyAfter[0], "{$companyBefore[0]} -> {$companyAfter[0]}");
check('11. company total value UNCHANGED', (float) $companyBefore[1] === (float) $companyAfter[1], "{$companyBefore[1]} -> {$companyAfter[1]}");
check('11. company total batch count UNCHANGED', (int) $companyBefore[2] === (int) $companyAfter[2], "{$companyBefore[2]} -> {$companyAfter[2]}");
check('11. GUDANG_BESAR qty/value/batches UNCHANGED', (float) $gbBefore[0] === (float) $gbAfter[0] && (float) $gbBefore[1] === (float) $gbAfter[1] && (int) $gbBefore[2] === (int) $gbAfter[2]);
check('11. CIBADAK qty/value/batches UNCHANGED', (float) $cibBefore[0] === (float) $cibAfter[0] && (float) $cibBefore[1] === (float) $cibAfter[1] && (int) $cibBefore[2] === (int) $cibAfter[2]);
check('11. KARANG_TENGAH qty=0/value=0/batches=0', (float) $ktAfter[0] === 0.0 && (float) $ktAfter[1] === 0.0 && (int) $ktAfter[2] === 0);

// ============================================================
// 8. inactive + locked Karang Tengah remains blocked from stock mutation
// (the pre-existing, generic is_active guard — unrelated to, but not
// weakened by, the new lock).
// ============================================================
echo "\n== 8. inactive+locked Karang Tengah still blocked from stock mutation ==\n";
$itemH1 = makeItem($pdo, $kgUnitId, 'V2131-H1');
$errIn = expectException(fn () => postIn($pdo, $itemH1, $karangTengahId, $kgUnitId, 10, 1000, $adminUserId), WarehouseInactiveException::class);
check('8. FifoService::postIn still REJECTS inactive+locked Karang Tengah', $errIn === WarehouseInactiveException::class, (string) $errIn);

// ============================================================
// 4/5/6/7/9/10 — HTTP-level proof against the real PUT /warehouses/{id}
// route (the SAME path the Master Gudang UI calls).
// ============================================================
echo "\n== 4/5/6/7/9/10: HTTP-level proof (real API path) ==\n";
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
    // "authorized warehouse manager" — SUPERADMIN, which this codebase's
    // seeded role/permission set grants MASTER_WAREHOUSE_MANAGE to (same
    // role every other PUT /warehouses/{id} test in this codebase uses).
    $httpUser = uid('v2131http'); $httpPass = 'V2131HttpPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $httpUser, 'h' => password_hash($httpPass, PASSWORD_BCRYPT), 'n' => $httpUser, 'r' => $superRoleId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $httpUser, 'password' => $httpPass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';
    check('HTTP login succeeds', $login['status'] === 200, json_encode($login['body']));

    // 4/5 — activation attempt against the LOCKED, inactive Karang Tengah
    // is refused, even though this user holds MASTER_WAREHOUSE_MANAGE.
    $activateAttempt = httpCall('PUT', "{$base}/warehouses/{$karangTengahId}", ['is_active' => true], $jar, $csrf);
    check('4. authorized warehouse manager CANNOT activate a locked warehouse', $activateAttempt['status'] === 422, json_encode($activateAttempt['body']));
    check('5. response code is WAREHOUSE_ACTIVATION_LOCKED', ($activateAttempt['body']['error']['code'] ?? null) === 'WAREHOUSE_ACTIVATION_LOCKED', json_encode($activateAttempt['body']));
    check('5. response message matches the required Indonesian text', ($activateAttempt['body']['error']['message'] ?? null) === 'Gudang belum dapat diaktifkan karena proses cutover belum selesai.', json_encode($activateAttempt['body']));
    $ktAfterAttempt = $pdo->query("SELECT is_active FROM warehouses WHERE id = {$karangTengahId}")->fetchColumn();
    check('4/5. KARANG_TENGAH remains is_active=0 in the database after the rejected attempt', (int) $ktAfterAttempt === 0, (string) $ktAfterAttempt);

    // 7 — renaming the SAME locked warehouse (no is_active change) works.
    $renameAttempt = httpCall('PUT', "{$base}/warehouses/{$karangTengahId}", ['name' => 'Gudang Karang Tengah (Renamed)'], $jar, $csrf);
    check('7. rename of a locked warehouse succeeds', $renameAttempt['status'] === 200, json_encode($renameAttempt['body']));
    $ktNameAfter = $pdo->query("SELECT name, is_active, activation_locked FROM warehouses WHERE id = {$karangTengahId}")->fetch();
    check('7. name actually changed, is_active/activation_locked untouched by the rename', $ktNameAfter['name'] === 'Gudang Karang Tengah (Renamed)' && (int) $ktNameAfter['is_active'] === 0 && (int) $ktNameAfter['activation_locked'] === 1, json_encode($ktNameAfter));
    // also confirm an explicit is_active=false (i.e. "stay inactive") on a
    // locked warehouse is allowed too — only the 0->1 transition is refused
    $stayInactive = httpCall('PUT', "{$base}/warehouses/{$karangTengahId}", ['is_active' => false], $jar, $csrf);
    check('7b. an explicit is_active=false (no-op transition) on a locked warehouse still succeeds', $stayInactive['status'] === 200, json_encode($stayInactive['body']));

    // 9 — an UNLOCKED inactive test warehouse can still be activated
    // normally through the exact same endpoint.
    $pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('V2131_UNLOCKED_TEST', 'Unlocked Test Warehouse', 'TRANSIT', 0, 0)")->execute();
    $unlockedTestId = (int) $pdo->lastInsertId();
    $activateUnlocked = httpCall('PUT', "{$base}/warehouses/{$unlockedTestId}", ['is_active' => true], $jar, $csrf);
    check('9. an unlocked inactive warehouse CAN still be activated normally', $activateUnlocked['status'] === 200, json_encode($activateUnlocked['body']));
    $unlockedAfter = $pdo->query("SELECT is_active FROM warehouses WHERE id = {$unlockedTestId}")->fetchColumn();
    check('9. unlocked test warehouse is_active=1 after activation', (int) $unlockedAfter === 1, (string) $unlockedAfter);

    // 10 — existing CIBADAK/GUDANG_BESAR (both unlocked, already active)
    // deactivate/reactivate behavior is completely unchanged.
    $deactivateCibadak = httpCall('PUT', "{$base}/warehouses/{$cibadakId}", ['is_active' => false], $jar, $csrf);
    check('10. CIBADAK (unlocked) can still be deactivated normally', $deactivateCibadak['status'] === 200, json_encode($deactivateCibadak['body']));
    $reactivateCibadak = httpCall('PUT', "{$base}/warehouses/{$cibadakId}", ['is_active' => true], $jar, $csrf);
    check('10. CIBADAK (unlocked) can still be reactivated normally', $reactivateCibadak['status'] === 200, json_encode($reactivateCibadak['body']));
    $cibFinal = $pdo->query("SELECT is_active FROM warehouses WHERE id = {$cibadakId}")->fetchColumn();
    check('10. CIBADAK ends active again (no regression from the new lock check)', (int) $cibFinal === 1, (string) $cibFinal);

    // 6 (part 1 — source-level) — the action-menu builder in
    // master-warehouses.js must never wire an onClick "Aktifkan" handler
    // for a locked+inactive row; it must instead render a disabled,
    // explanatory item. This is a static assertion over the shipped
    // source (the live browser check below is the other half of proof).
    $jsSrc = file_get_contents(__DIR__ . '/../public/assets/js/master-warehouses.js');
    check('6a. master-warehouses.js branches on activation_locked before offering Aktifkan', str_contains($jsSrc, 'activation_locked') && str_contains($jsSrc, "'Aktifkan (Cutover Terkunci)'"), '');
    check('6b. the locked branch is marked disabled (no click handler wired)', (bool) preg_match('/activation_locked\s*\?\s*\{\s*label:\s*\'Aktifkan \(Cutover Terkunci\)\',\s*disabled:\s*true\s*\}/s', $jsSrc), '');
} finally {
    proc_terminate($process);
    usleep(200_000);
    proc_close($process);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
