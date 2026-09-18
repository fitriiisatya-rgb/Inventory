<?php
declare(strict_types=1);

/**
 * PHASE V2 3e — Master Vendor/Supplier, Master Bakery Tujuan, Master
 * Kategori CRUD (create/update/soft-delete, permission-gated, duplicate
 * code rejection), and bakery_destination_id wiring into POST
 * /transactions/out: persisted for real OUT, round-trips on read, and is
 * proven never settable on TRANSFER_OUT (service-layer guard, on top of
 * the DB CHECK constraint proven in scripts/v2_schema_postcheck.php).
 *
 * Usage: php tests/master_data_v2_test.php
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
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/SupplierService.php';
require_once __DIR__ . '/../services/BakeryDestinationService.php';

use App\Services\Database;
use App\Services\FifoService;
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
$adminUserId = (int) $pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();
if ($adminUserId <= 0) {
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => uid('mdv2user'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'MD V2 User', 'r' => $roleId]);
    $adminUserId = (int) $pdo->lastInsertId();
}

echo "\n== A: SupplierService create/update/duplicate-code/soft-delete (service level) ==\n";
$supplierCode = uid('VND');
$createResult = \App\Services\SupplierService::create($pdo, [
    'code' => $supplierCode, 'name' => 'Vendor Test', 'address' => 'Jl. Test No. 1',
    'phone' => '08123', 'email' => 'vendor@test.example', 'created_by' => $adminUserId,
]);
check('create() returns success + supplier_id', ($createResult['success'] ?? false) === true);
$supplierId = $createResult['supplier_id'];

try {
    \App\Services\SupplierService::create($pdo, ['code' => $supplierCode, 'name' => 'Dupe', 'created_by' => $adminUserId]);
    check('duplicate supplier code rejected', false, 'no exception thrown');
} catch (ValidationException $e) {
    check('duplicate supplier code rejected', true);
}

\App\Services\SupplierService::update($pdo, $supplierId, ['address' => 'Jl. Baru No. 2', 'updated_by' => $adminUserId]);
$reread = $pdo->query("SELECT address FROM suppliers WHERE id = {$supplierId}")->fetchColumn();
check('update() persists new address', $reread === 'Jl. Baru No. 2', (string) $reread);

\App\Services\SupplierService::update($pdo, $supplierId, ['is_active' => false, 'updated_by' => $adminUserId]);
$activeCheck = (int) $pdo->query("SELECT is_active FROM suppliers WHERE id = {$supplierId}")->fetchColumn();
$rowStillExists = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE id = {$supplierId}")->fetchColumn();
check('soft-delete sets is_active=0, row still exists (never hard-deleted)', $activeCheck === 0 && (int) $rowStillExists === 1);

echo "\n== B: BakeryDestinationService create/update/duplicate-code/soft-delete (service level) ==\n";
$bdCode = uid('BAK');
$bdResult = \App\Services\BakeryDestinationService::create($pdo, [
    'code' => $bdCode, 'name' => 'Bakery Test Outlet', 'address' => 'Jl. Roti No. 5',
    'city_area' => 'Bandung', 'pic_name' => 'Budi', 'phone' => '08199', 'created_by' => $adminUserId,
]);
check('create() returns success + bakery_destination_id', ($bdResult['success'] ?? false) === true);
$bakeryDestId = $bdResult['bakery_destination_id'];

try {
    \App\Services\BakeryDestinationService::create($pdo, ['code' => $bdCode, 'name' => 'Dupe Outlet', 'created_by' => $adminUserId]);
    check('duplicate bakery destination code rejected', false, 'no exception thrown');
} catch (ValidationException $e) {
    check('duplicate bakery destination code rejected', true);
}

\App\Services\BakeryDestinationService::update($pdo, $bakeryDestId, ['city_area' => 'Cimahi', 'updated_by' => $adminUserId]);
$bdReread = $pdo->query("SELECT city_area FROM bakery_destinations WHERE id = {$bakeryDestId}")->fetchColumn();
check('update() persists new city_area', $bdReread === 'Cimahi', (string) $bdReread);

echo "\n== C: bakery_destination_id round-trips through a real OUT transaction ==\n";
$sku = uid('SKU-BD');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('BD-WH'), 'n' => 'BD Test WH']);
$warehouseId = (int) $pdo->lastInsertId();

$inResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('bd-in'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
]));
check('seed IN posted', ($inResult['success'] ?? false) === true);

$outUuid = uid('bd-out');
$outResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => $outUuid, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
    'bakery_destination_id' => $bakeryDestId,
]));
check('OUT with bakery_destination_id posted successfully', ($outResult['success'] ?? false) === true, json_encode($outResult));

$storedBd = $pdo->query("SELECT bakery_destination_id FROM inventory_transactions WHERE transaction_uuid = '{$outUuid}'")->fetchColumn();
check('bakery_destination_id persisted correctly on the OUT transaction row', (int) $storedBd === $bakeryDestId, (string) $storedBd);

echo "\n== D: bakery_destination_id is NEVER persisted for TRANSFER_OUT, even if a caller passes it ==\n";
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('BD-WH2'), 'n' => 'BD Test WH2']);
$warehouseId2 = (int) $pdo->lastInsertId();

$transferOutUuid = uid('bd-xfer-out');
$transferOutResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => $transferOutUuid, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 5, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'TRANSFER_OUT',
    'transaction_date' => '2026-09-03 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
    // maliciously/mistakenly attempting to set a bakery destination on a transfer
    'bakery_destination_id' => $bakeryDestId,
]));
check('TRANSFER_OUT with bakery_destination_id in the payload still posts successfully', ($transferOutResult['success'] ?? false) === true);

$storedBdOnTransfer = $pdo->query("SELECT bakery_destination_id FROM inventory_transactions WHERE transaction_uuid = '{$transferOutUuid}'")->fetchColumn();
check('...but bakery_destination_id is NULL on the stored row (service-layer guard, never persisted for non-OUT)', $storedBdOnTransfer === null, var_export($storedBdOnTransfer, true));

// ============================================================
// E: HTTP-level permission enforcement for the new CRUD routes
// ============================================================
echo "\n== E: HTTP permission enforcement ==\n";

$port = 8800 + random_int(0, 400);
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
    $stockUser = uid('mdstock'); $stockPass = 'MdStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    $stockCreatesSupplier = httpCall('POST', "{$base}/suppliers", ['code' => uid('HTTPVND'), 'name' => 'X'], $stockJar, $stockCsrf);
    check('STOCK (no MASTER_SUPPLIER_MANAGE) cannot POST /suppliers', $stockCreatesSupplier['status'] === 403 && $stockCreatesSupplier['body']['error']['code'] === 'FORBIDDEN');

    $stockCreatesBd = httpCall('POST', "{$base}/bakery-destinations", ['code' => uid('HTTPBAK'), 'name' => 'X'], $stockJar, $stockCsrf);
    check('STOCK (no MASTER_BAKERY_DESTINATION_MANAGE) cannot POST /bakery-destinations', $stockCreatesBd['status'] === 403 && $stockCreatesBd['body']['error']['code'] === 'FORBIDDEN');

    $stockReadsBd = httpCall('GET', "{$base}/bakery-destinations", null, $stockJar);
    check('STOCK CAN GET /bakery-destinations (needed for every OUT form)', $stockReadsBd['status'] === 200);

    $stockCreatesCategory = httpCall('POST', "{$base}/categories", ['code' => uid('HTTPCAT'), 'name' => 'X'], $stockJar, $stockCsrf);
    check('STOCK (no MASTER_CATEGORY_MANAGE) cannot POST /categories', $stockCreatesCategory['status'] === 403 && $stockCreatesCategory['body']['error']['code'] === 'FORBIDDEN');

    $superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $superUser = uid('mdsuper'); $superPass = 'MdSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $superCreatesSupplier = httpCall('POST', "{$base}/suppliers", ['code' => uid('HTTPVND2'), 'name' => 'Vendor via HTTP'], $superJar, $superCsrf);
    check('SUPERADMIN (inherits MASTER_SUPPLIER_MANAGE) CAN POST /suppliers', ($superCreatesSupplier['body']['data']['success'] ?? false) === true, json_encode($superCreatesSupplier['body']));

    $superCreatesCategory = httpCall('POST', "{$base}/categories", ['code' => uid('HTTPCAT2'), 'name' => 'Category via HTTP'], $superJar, $superCsrf);
    check('SUPERADMIN (inherits MASTER_CATEGORY_MANAGE) CAN POST /categories', ($superCreatesCategory['body']['data']['success'] ?? false) === true, json_encode($superCreatesCategory['body']));
    $categoryId = $superCreatesCategory['body']['data']['category_id'] ?? null;

    echo "\n== F: Category CRUD — positive path (create/list/duplicate-code/update/soft-delete) ==\n";

    $dupeCategoryCode = uid('HTTPCAT-DUPE');
    $firstCreate = httpCall('POST', "{$base}/categories", ['code' => $dupeCategoryCode, 'name' => 'Original Name'], $superJar, $superCsrf);
    check('first create with a fresh code succeeds', ($firstCreate['body']['data']['success'] ?? false) === true, json_encode($firstCreate['body']));
    $dupeCategoryId = $firstCreate['body']['data']['category_id'] ?? null;

    $dupeCreate = httpCall('POST', "{$base}/categories", ['code' => $dupeCategoryCode, 'name' => 'Duplicate Attempt'], $superJar, $superCsrf);
    check('duplicate category code rejected', $dupeCreate['status'] === 422 && ($dupeCreate['body']['success'] ?? true) === false, json_encode($dupeCreate['body']));

    $listAfterCreate = httpCall('GET', "{$base}/categories", null, $superJar);
    $listedCodes = array_column($listAfterCreate['body']['data'] ?? [], 'code');
    check('GET /categories lists the newly created category', in_array($dupeCategoryCode, $listedCodes, true));

    $updateResult = httpCall('PUT', "{$base}/categories/{$dupeCategoryId}", ['name' => 'Renamed Category'], $superJar, $superCsrf);
    check('PUT /categories/{id} updates the name', ($updateResult['body']['data']['success'] ?? false) === true, json_encode($updateResult['body']));
    $reread = httpCall('GET', "{$base}/categories", null, $superJar);
    $rerereadRow = null;
    foreach ($reread['body']['data'] ?? [] as $row) {
        if ((int) $row['id'] === (int) $dupeCategoryId) { $rerereadRow = $row; }
    }
    check('renamed category persists on re-read', $rerereadRow !== null && $rerereadRow['name'] === 'Renamed Category', json_encode($rerereadRow));

    $deactivateResult = httpCall('PUT', "{$base}/categories/{$dupeCategoryId}", ['is_active' => false], $superJar, $superCsrf);
    check('PUT /categories/{id} soft-deletes (is_active=false)', ($deactivateResult['body']['data']['success'] ?? false) === true);
    $afterDeactivate = httpCall('GET', "{$base}/categories", null, $superJar);
    $deactivatedRow = null;
    foreach ($afterDeactivate['body']['data'] ?? [] as $row) {
        if ((int) $row['id'] === (int) $dupeCategoryId) { $deactivatedRow = $row; }
    }
    check('deactivated category still appears in GET /categories (never hard-deleted, matches suppliers/bakery-destinations convention)', $deactivatedRow !== null && (int) $deactivatedRow['is_active'] === 0, json_encode($deactivatedRow));

    $updateMissing = httpCall('PUT', "{$base}/categories/999999", ['name' => 'Nope'], $superJar, $superCsrf);
    check('PUT /categories/{id} on a non-existent id returns 404', $updateMissing['status'] === 404, json_encode($updateMissing['body']));

    $blankCode = httpCall('POST', "{$base}/categories", ['code' => '', 'name' => 'No Code'], $superJar, $superCsrf);
    check('POST /categories rejects a blank code', $blankCode['status'] === 422, json_encode($blankCode['body']));

    foreach ([$stockJar, $superJar] as $f) { @unlink($f); }
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
