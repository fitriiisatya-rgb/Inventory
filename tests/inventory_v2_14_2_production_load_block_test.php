<?php
declare(strict_types=1);

/**
 * PHASE V2.14.2 — pre-deploy safety hotfix regression test.
 *
 * Proves POST /warehouse-cutovers/{id}/load is hard-blocked (HTTP 422,
 * code CUTOVER_LOAD_DISABLED) whenever APP_ENV=production, BEFORE
 * WarehouseCutoverService::loadOpening() ever runs — SUPERADMIN cannot
 * bypass it — while remaining fully functional in any non-production
 * environment (this suite's own .env, APP_ENV=testing). Also proves the
 * block causes zero inventory mutation and leaves the pre-existing
 * activation/delete locks completely unchanged.
 *
 * Usage: php tests/inventory_v2_14_2_production_load_block_test.php
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
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';
require_once __DIR__ . '/../services/InventorySummaryReportService.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/WarehouseCutoverService.php';
require_once __DIR__ . '/../services/WarehouseCutoverImportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\WarehouseCutoverService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 0, 1)")->execute();
$karangTengahId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $sku, string $name): int
{
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function makeApprovedCutover(PDO $pdo, int $warehouseId, int $kgUnitId, int $adminUserId, string $asOf): int
{
    $cutoverId = WarehouseCutoverService::create($pdo, [
        'warehouse_id' => $warehouseId, 'source_name' => 'v2142-prodblock', 'opening_as_of' => $asOf, 'created_by' => $adminUserId,
    ]);
    $sku = uid('V2142-OPEN');
    $itemId = makeItem($pdo, $kgUnitId, $sku, 'Prod Block Opening Item');
    $pdo->prepare(
        'INSERT INTO warehouse_cutover_lines (cutover_id, item_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, approved_qty, approved_unit_cost, approved_by, approved_at, source_row_reference)
         VALUES (:cid, :item_id, :sku, :name, :unit, :qty, :cost, :status, :mapping, :decision, :qty2, :cost2, :by, NOW(), :rownum)'
    )->execute([
        'cid' => $cutoverId, 'item_id' => $itemId, 'sku' => $sku, 'name' => 'Prod Block Opening Item', 'unit' => 'KG',
        'qty' => 50.0, 'cost' => 3000.0, 'qty2' => 50.0, 'cost2' => 3000.0, 'status' => 'PASS', 'mapping' => 'MATCHED', 'decision' => 'ACCEPT_SOURCE',
        'by' => $adminUserId, 'rownum' => 2,
    ]);
    $pdo->prepare("UPDATE warehouse_cutovers SET status='APPROVED', approved_by=:by, approved_at=NOW(), total_rows=1, pass_rows=1 WHERE id=:id")->execute(['by' => $adminUserId, 'id' => $cutoverId]);
    return $cutoverId;
}

$adminUserId = makeUser($pdo, 'v2142admin', $superRoleId);

function startServer(int $port, ?string $appEnv): array
{
    $docRoot = __DIR__ . '/../public';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Always pass an EXPLICIT env array (never null) so one call can never
    // leak its APP_ENV into a later call via a stale putenv() in this
    // parent test process — default to 'testing' (this suite's own .env
    // value) when no override is requested.
    $resolvedEnv = $appEnv ?? 'testing';
    putenv("APP_ENV={$resolvedEnv}");
    $env = array_merge(getenv(), ['APP_ENV' => $resolvedEnv]);
    $process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, __DIR__ . '/..', $env);
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
    return [$process, $base];
}

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

function loginSuperadmin(string $base, int $roleId, PDO $pdo): array
{
    $u = uid('v2142http'); $pass = 'V2142HttpPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $u, 'password' => $pass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';
    return [$jar, $csrf, $login];
}

// ============================================================
// 1. APP_ENV=production + SUPERADMIN -> load MUST be rejected, no mutation
// ============================================================
echo "== 1. Production env + SUPERADMIN: load must be hard-blocked ==\n";
$cutoverProd = makeApprovedCutover($pdo, $karangTengahId, $kgUnitId, $adminUserId, '2026-09-27');
$batchesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$ktBefore = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);

[$procProd, $baseProd] = startServer(8930 + random_int(1, 300), 'production');
try {
    [$jar, $csrf, $login] = loginSuperadmin($baseProd, $superRoleId, $pdo);
    check('1. HTTP login succeeds (production server)', $login['status'] === 200, json_encode($login['body']));

    $loadCall = httpCall('POST', "{$baseProd}/warehouse-cutovers/{$cutoverProd}/load", [], $jar, $csrf);
    check('1. POST /warehouse-cutovers/{id}/load returns HTTP 422 under APP_ENV=production', $loadCall['status'] === 422, json_encode($loadCall['body']));
    check('1. error code is CUTOVER_LOAD_DISABLED', ($loadCall['body']['error']['code'] ?? null) === 'CUTOVER_LOAD_DISABLED', json_encode($loadCall['body']));
    check('1. error message matches the required Indonesian text', ($loadCall['body']['error']['message'] ?? null) === 'Load opening production belum diaktifkan. Gunakan release cutover yang telah disetujui.', json_encode($loadCall['body']));

    $cutoverAfter = $pdo->query("SELECT status FROM warehouse_cutovers WHERE id={$cutoverProd}")->fetchColumn();
    check('1. cutover status remains APPROVED, never advances to LOADED', $cutoverAfter === 'APPROVED', (string) $cutoverAfter);

    $batchesAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
    check('1. NO inventory_batches row was created (zero mutation from the blocked attempt)', $batchesAfter === $batchesBefore, "{$batchesBefore} -> {$batchesAfter}");

    $ktAfter = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
    check('1. KARANG_TENGAH still is_active=0 after the blocked attempt', (int) $ktAfter['is_active'] === (int) $ktBefore['is_active'] && (int) $ktAfter['is_active'] === 0);
    check('1. KARANG_TENGAH still activation_locked=1 after the blocked attempt', (int) $ktAfter['activation_locked'] === (int) $ktBefore['activation_locked'] && (int) $ktAfter['activation_locked'] === 1);
} finally {
    proc_terminate($procProd);
    usleep(200_000);
    proc_close($procProd);
}

// A second attempt directly against loadOpening() with a real production
// APP_ENV would still be blocked at the ROUTE, since the block is checked
// before loadOpening() is invoked at all — the route test above already
// proves that. This confirms the block cannot be bypassed via a retried
// HTTP call with a fresh session either.
[$procProd2, $baseProd2] = startServer(8931 + random_int(1, 300), 'production');
try {
    [$jar2, $csrf2] = loginSuperadmin($baseProd2, $superRoleId, $pdo);
    $retry = httpCall('POST', "{$baseProd2}/warehouse-cutovers/{$cutoverProd}/load", [], $jar2, $csrf2);
    check('1. Retrying the blocked load (fresh session, still production) is STILL rejected — no bypass via retry', $retry['status'] === 422 && ($retry['body']['error']['code'] ?? null) === 'CUTOVER_LOAD_DISABLED', json_encode($retry['body']));
} finally {
    proc_terminate($procProd2);
    usleep(200_000);
    proc_close($procProd2);
}

// ============================================================
// 2. Non-production env (this suite's own .env, APP_ENV=testing) -> load
//    path still works exactly as before.
// ============================================================
echo "\n== 2. Non-production (testing) env: load path still works ==\n";
$cutoverTest = makeApprovedCutover($pdo, $karangTengahId, $kgUnitId, $adminUserId, '2026-09-28');

[$procTest, $baseTest] = startServer(8940 + random_int(1, 300), null);
try {
    [$jar3, $csrf3, $login3] = loginSuperadmin($baseTest, $superRoleId, $pdo);
    check('2. HTTP login succeeds (non-production server)', $login3['status'] === 200, json_encode($login3['body']));

    $loadCall2 = httpCall('POST', "{$baseTest}/warehouse-cutovers/{$cutoverTest}/load", [], $jar3, $csrf3);
    check('2. POST /warehouse-cutovers/{id}/load succeeds (HTTP 200) under a non-production APP_ENV', $loadCall2['status'] === 200, json_encode($loadCall2['body']));
    check('2. load result reports exactly 1 line posted', ($loadCall2['body']['data']['line_count'] ?? null) === 1, json_encode($loadCall2['body']));

    $cutoverAfter2 = $pdo->query("SELECT status FROM warehouse_cutovers WHERE id={$cutoverTest}")->fetchColumn();
    check('2. cutover status correctly advances to LOADED', $cutoverAfter2 === 'LOADED', (string) $cutoverAfter2);
} finally {
    proc_terminate($procTest);
    usleep(200_000);
    proc_close($procTest);
}

// ============================================================
// 3. Existing activation/delete locks are completely unchanged by this
//    hotfix (still enforced independently of the new production block).
// ============================================================
echo "\n== 3. Pre-existing activation/delete locks unchanged ==\n";
$ktFinal = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('3. KARANG_TENGAH is still is_active=0 after both scenarios above', (int) $ktFinal['is_active'] === 0);
check('3. KARANG_TENGAH is still activation_locked=1 after both scenarios above', (int) $ktFinal['activation_locked'] === 1);

require_once __DIR__ . '/../services/WarehouseGuardService.php';
try {
    App\Services\WarehouseGuardService::assertDeletionAllowed($karangTengahId, (int) $ktFinal['activation_locked']);
    check('3. deletion guard still throws for an activation_locked warehouse', false, 'expected exception, none thrown');
} catch (App\Services\WarehouseCutoverLockedException $e) {
    check('3. deletion guard still throws for an activation_locked warehouse', true, $e->getMessage());
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
