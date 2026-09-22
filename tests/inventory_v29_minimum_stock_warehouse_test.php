<?php
declare(strict_types=1);

/**
 * PHASE V2.9 — Minimum Stock Per Warehouse, against real MySQL/MariaDB.
 * Audit finding this phase built on: the per-item-per-warehouse data
 * model (item_warehouse_stock_policy, StockPolicyService::resolve()/
 * upsert(), PUT/GET /stock-policy) ALREADY existed from an earlier phase
 * and is reused completely unmodified — this phase only closes three real
 * gaps found during audit:
 *   1. InventoryService::currentStockAllWarehouses() (the "Semua Gudang"
 *      breakdown) never resolved a per-warehouse minimum/status at all —
 *      extended to include minimum_stock + report_status per row via
 *      StockPolicyService (never a single global minimum reused across
 *      every warehouse).
 *   2. StockPolicyService::upsert() didn't check warehouse is_active —
 *      hardened to reject a PENDING_CUTOVER warehouse (Karang Tengah),
 *      same convention as every other write path in this project.
 *   3. No bulk-import path and no management UI existed for
 *      item_warehouse_stock_policy — both added, reusing the generic
 *      import_batches/import_rows architecture and calling the real,
 *      unmodified StockPolicyService::upsert() per row.
 *
 * Covers: same-SKU-different-status-by-warehouse, exact minimum boundary,
 * zero stock, negative migration stock stays signed, All-Warehouse
 * breakdown shape, bulk import (stage/commit/validation/idempotent
 * re-import/inactive-warehouse rejection/.xlsx), permission enforcement.
 * NOT re-tested here (already covered by tests/stock_policy_test.php,
 * unaffected by this phase's changes and re-verified in the same full
 * regression run): resolve()/upsert() fallback logic, the 5-state
 * stockStatus() model, GET/PUT /stock-policy warehouse-scope enforcement.
 *
 * Usage: php tests/inventory_v29_minimum_stock_warehouse_test.php
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
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';
require_once __DIR__ . '/../services/ImportStockPolicyService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use App\Services\StockPolicyService;
use App\Services\ExcelWriterService;
use App\Services\ImportTemplateService;
use App\Services\ImportStockPolicyService;
use App\Services\ImportValidationException;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }
function tmpCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'v29_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

// ============================================================
// Fixtures
// ============================================================
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v29setup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V29 Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$scmCode = uid('V29-SCM');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $scmCode, 'n' => 'V29 SCM-equivalent']);
$scmId = (int) $pdo->lastInsertId();
$cibadakCode = uid('V29-CIBADAK');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $cibadakCode, 'n' => 'V29 CIBADAK-equivalent']);
$cibadakId = (int) $pdo->lastInsertId();
// Karang-Tengah-style PENDING_CUTOVER warehouse: exists but is_active=0.
$ktCode = uid('V29-KT');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 0)')->execute(['c' => $ktCode, 'n' => 'V29 Pending Cutover']);
$ktId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag, float $globalMinimum = 10.0): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, :min, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'min' => $globalMinimum, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v29-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $GLOBALS['kgUnitId'], 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v29', 'transaction_type' => 'OPENING',
    ]));
}

// ============================================================
// A1 — same SKU, different status by warehouse: SCM has an override
// (minimum 5), CIBADAK falls back to the item's global minimum (20).
// Both warehouses hold the SAME quantity (10) — SCM must read AMAN
// (10 > 5), CIBADAK must read WARNING (10 <= 20). One number pair,
// two different classifications, proving the status is genuinely
// per-warehouse and never a single company-wide comparison.
// ============================================================
echo "== A1: same SKU, different report_status per warehouse (SCM override vs CIBADAK fallback) ==\n";
[$itemA1, $skuA1] = makeItem($pdo, $kgUnitId, 'V29-A1', 20.0);
postIn($pdo, $itemA1, $scmId, 10, 1000, $adminUserId);
postIn($pdo, $itemA1, $cibadakId, 10, 1000, $adminUserId);
StockPolicyService::upsert($pdo, ['item_id' => $itemA1, 'warehouse_id' => $scmId, 'minimum_stock' => 5.0, 'updated_by' => $adminUserId]);

$breakdownA1 = InventoryService::currentStockAllWarehouses($pdo, $itemA1);
$rowsA1 = [];
foreach ($breakdownA1['by_warehouse'] as $r) { $rowsA1[(int) $r['warehouse_id']] = $r; }
check('A1 SCM row: minimum_stock = 5 (its own policy override)', isset($rowsA1[$scmId]) && approx($rowsA1[$scmId]['minimum_stock'], 5.0), (string) ($rowsA1[$scmId]['minimum_stock'] ?? 'missing'));
check('A1 SCM row: qty 10 > minimum 5 -> AMAN', isset($rowsA1[$scmId]) && $rowsA1[$scmId]['report_status'] === 'AMAN', (string) ($rowsA1[$scmId]['report_status'] ?? 'missing'));
check('A1 CIBADAK row: minimum_stock = 20 (falls back to items.minimum_stock, no override exists)', isset($rowsA1[$cibadakId]) && approx($rowsA1[$cibadakId]['minimum_stock'], 20.0), (string) ($rowsA1[$cibadakId]['minimum_stock'] ?? 'missing'));
check('A1 CIBADAK row: SAME qty 10 <= minimum 20 -> WARNING (same SKU, same qty, DIFFERENT status than SCM)', isset($rowsA1[$cibadakId]) && $rowsA1[$cibadakId]['report_status'] === 'WARNING', (string) ($rowsA1[$cibadakId]['report_status'] ?? 'missing'));

// ============================================================
// A2 — exact minimum boundary at the breakdown level: qty == minimum -> WARNING, never AMAN.
// ============================================================
echo "\n== A2: exact minimum boundary (qty == minimum -> WARNING, inclusive) ==\n";
[$itemA2, $skuA2] = makeItem($pdo, $kgUnitId, 'V29-A2');
postIn($pdo, $itemA2, $scmId, 15, 1000, $adminUserId);
StockPolicyService::upsert($pdo, ['item_id' => $itemA2, 'warehouse_id' => $scmId, 'minimum_stock' => 15.0, 'updated_by' => $adminUserId]);
$breakdownA2 = InventoryService::currentStockAllWarehouses($pdo, $itemA2);
$rowA2 = $breakdownA2['by_warehouse'][0];
check('A2 qty exactly = minimum (15 == 15) -> WARNING, not AMAN', $rowA2['report_status'] === 'WARNING', json_encode($rowA2));

// ============================================================
// A3 — zero stock -> HABIS.
// ============================================================
echo "\n== A3: zero stock -> HABIS ==\n";
[$itemA3, $skuA3] = makeItem($pdo, $kgUnitId, 'V29-A3');
postIn($pdo, $itemA3, $scmId, 10, 1000, $adminUserId);
Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('v29-out'), 'item_id' => $itemA3, 'warehouse_id' => $scmId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-08-02 08:00:00',
    'created_by' => $adminUserId, 'username' => 'v29',
]));
$breakdownA3 = InventoryService::currentStockAllWarehouses($pdo, $itemA3);
$rowA3 = $breakdownA3['by_warehouse'][0];
check('A3 qty = 0 -> HABIS regardless of minimum', $rowA3['report_status'] === 'HABIS' && approx($rowA3['qty_base'], 0.0), json_encode($rowA3));

// ============================================================
// A4 — negative migration stock remains SIGNED (never floored to 0) at
// the All-Warehouse breakdown level, and still classifies HABIS.
// ============================================================
echo "\n== A4: negative migration stock remains signed at the All-Warehouse breakdown level ==\n";
[$itemA4, $skuA4] = makeItem($pdo, $kgUnitId, 'V29-A4');
// A raw negative batch (same technique the migration-negative whitelist
// scenario produces) — never posted through a normal transaction.
$pdo->prepare(
    'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, is_negative_layer, created_at)
     VALUES (:item,:wh,-3.5,-3.5,1000,:recv,1,:now)'
)->execute(['item' => $itemA4, 'wh' => $scmId, 'recv' => '2026-08-01 00:00:00', 'now' => date('Y-m-d H:i:s')]);
$breakdownA4 = InventoryService::currentStockAllWarehouses($pdo, $itemA4);
$rowA4 = $breakdownA4['by_warehouse'][0];
check('A4 qty stays exactly -3.5 (signed, never clamped to 0)', approx($rowA4['qty_base'], -3.5), (string) $rowA4['qty_base']);
check('A4 report_status = HABIS (qty <= 0 branch, no separate handling needed)', $rowA4['report_status'] === 'HABIS', (string) $rowA4['report_status']);

// ============================================================
// A5 — StockPolicyService::upsert() rejects an inactive/PENDING_CUTOVER warehouse.
// ============================================================
echo "\n== A5: upsert() rejects an inactive warehouse (Karang-Tengah-style) ==\n";
[$itemA5, $skuA5] = makeItem($pdo, $kgUnitId, 'V29-A5');
$rejectedA5 = false;
try {
    StockPolicyService::upsert($pdo, ['item_id' => $itemA5, 'warehouse_id' => $ktId, 'minimum_stock' => 5, 'updated_by' => $adminUserId]);
} catch (ValidationException $e) {
    $rejectedA5 = true;
}
check('A5 upsert() throws ValidationException for an inactive warehouse', $rejectedA5);
$countStmtA5 = $pdo->prepare('SELECT COUNT(*) FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
$countStmtA5->execute(['i' => $itemA5, 'w' => $ktId]);
check('A5 no policy row was created for the inactive warehouse', (int) $countStmtA5->fetchColumn() === 0);

// ============================================================
// A6 — bulk import: stage + commit creates real item_warehouse_stock_policy rows.
// ============================================================
echo "\n== A6: bulk import creates real policy rows via the REAL StockPolicyService::upsert() ==\n";
[$itemA6, $skuA6] = makeItem($pdo, $kgUnitId, 'V29-A6');
$csvA6 = tmpCsv(
    "sku,warehouse_code,minimum_stock,buffer_stock\n"
    . "{$skuA6},{$scmCode},12,18\n"
);
$batchA6 = ImportStockPolicyService::stage($pdo, $csvA6, 'a6.csv', $adminUserId);
check('A6 stage produced 1 VALID row', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchA6}")->fetchColumn());
$resultA6 = Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, $batchA6, $adminUserId));
check('A6 commit imported 1 row', $resultA6['imported'] === 1, json_encode($resultA6));
$resolvedA6 = StockPolicyService::resolve($pdo, $itemA6, $scmId);
check('A6 resolve() now returns the imported policy (source=policy, minimum=12, buffer=18)', $resolvedA6['source'] === 'policy' && approx($resolvedA6['minimum_stock'], 12.0) && approx($resolvedA6['buffer_stock'], 18.0), json_encode($resolvedA6));

// ============================================================
// A7 — bulk import: unknown SKU / unknown warehouse_code -> ERROR, commit blocked.
// ============================================================
echo "\n== A7: bulk import rejects unknown SKU / unknown warehouse_code ==\n";
$csvA7 = tmpCsv(
    "sku,warehouse_code,minimum_stock,buffer_stock\n"
    . "NOSUCHSKU-{$scmCode},{$scmCode},5,\n"
    . "{$skuA6},NOSUCHWAREHOUSE-XYZ,5,\n"
);
$batchA7 = ImportStockPolicyService::stage($pdo, $csvA7, 'a7.csv', $adminUserId);
check('A7 both rows staged ERROR', 2 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA7}")->fetchColumn());
$rejectedA7 = false;
try { Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, $batchA7, $adminUserId)); } catch (ImportValidationException $e) { $rejectedA7 = true; }
check('A7 commit rejected outright', $rejectedA7);

// ============================================================
// A8 — bulk import: inactive warehouse_code (Karang-Tengah-style) rejected.
// ============================================================
echo "\n== A8: bulk import rejects an inactive warehouse_code (Karang-Tengah-style) ==\n";
$csvA8 = tmpCsv(
    "sku,warehouse_code,minimum_stock,buffer_stock\n"
    . "{$skuA6},{$ktCode},5,\n"
);
$batchA8 = ImportStockPolicyService::stage($pdo, $csvA8, 'a8.csv', $adminUserId);
check('A8 row staged ERROR (unknown or inactive warehouse_code)', 1 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA8}")->fetchColumn());
$rejectedA8 = false;
try { Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, $batchA8, $adminUserId)); } catch (ImportValidationException $e) { $rejectedA8 = true; }
check('A8 commit rejected outright — Karang Tengah can never receive a minimum-stock policy via bulk import', $rejectedA8);

// ============================================================
// A9 — re-importing the SAME SKU+warehouse UPDATES the existing row, never duplicates.
// ============================================================
echo "\n== A9: re-importing the same SKU+warehouse updates, never duplicates ==\n";
$csvA9 = tmpCsv(
    "sku,warehouse_code,minimum_stock,buffer_stock\n"
    . "{$skuA6},{$scmCode},99,\n"
);
$batchA9 = ImportStockPolicyService::stage($pdo, $csvA9, 'a9.csv', $adminUserId);
Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, $batchA9, $adminUserId));
$countA9 = $pdo->prepare('SELECT COUNT(*) FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
$countA9->execute(['i' => $itemA6, 'w' => $scmId]);
check('A9 exactly ONE policy row still exists for this item+warehouse (updated, not duplicated)', (int) $countA9->fetchColumn() === 1);
$resolvedA9 = StockPolicyService::resolve($pdo, $itemA6, $scmId);
check('A9 minimum_stock updated to 99, buffer cleared back to unconfigured', approx($resolvedA9['minimum_stock'], 99.0) && $resolvedA9['buffer_configured'] === false, json_encode($resolvedA9));

// ============================================================
// A10 — .xlsx input parses correctly.
// ============================================================
echo "\n== A10: .xlsx bulk import parses correctly ==\n";
[$itemA10, $skuA10] = makeItem($pdo, $kgUnitId, 'V29-A10');
$xlsxPath = tempnam(sys_get_temp_dir(), 'v29_') . '.xlsx';
ExcelWriterService::write($xlsxPath, [
    'Instructions' => ['headers' => ['Info'], 'rows' => [['isi sheet Template']]],
    'Template' => [
        'headers' => ['sku', 'warehouse_code', 'minimum_stock', 'buffer_stock'],
        'rows' => [[$skuA10, $cibadakCode, '7', '']],
    ],
]);
$batchA10 = ImportStockPolicyService::stage($pdo, $xlsxPath, 'a10.xlsx', $adminUserId);
check('A10 .xlsx staged with 1 VALID row', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchA10}")->fetchColumn());
$resultA10 = Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, $batchA10, $adminUserId));
check('A10 .xlsx commit imported 1 row', $resultA10['imported'] === 1, json_encode($resultA10));
$resolvedA10 = StockPolicyService::resolve($pdo, $itemA10, $cibadakId);
check('A10 .xlsx-imported policy resolves correctly (minimum=7)', approx($resolvedA10['minimum_stock'], 7.0), (string) $resolvedA10['minimum_stock']);
unlink($xlsxPath);

// ============================================================
// A11 — Karang Tengah never appears in the All-Warehouse breakdown even
// with a stray batch (re-confirms the V2.6/V2.6D guarantee still holds
// with the new minimum_stock/report_status fields added).
// ============================================================
echo "\n== A11: Karang Tengah excluded from the All-Warehouse breakdown (still true with V2.9 fields) ==\n";
[$itemA11, $skuA11] = makeItem($pdo, $kgUnitId, 'V29-A11');
$pdo->prepare(
    'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, created_at)
     VALUES (:item,:wh,100,100,1000,:recv,:now)'
)->execute(['item' => $itemA11, 'wh' => $ktId, 'recv' => '2026-08-01 00:00:00', 'now' => date('Y-m-d H:i:s')]);
postIn($pdo, $itemA11, $scmId, 5, 1000, $adminUserId);
$breakdownA11 = InventoryService::currentStockAllWarehouses($pdo, $itemA11);
$whIdsA11 = array_map('intval', array_column($breakdownA11['by_warehouse'], 'warehouse_id'));
check('A11 Karang Tengah (inactive) never appears despite holding a stray batch', !in_array($ktId, $whIdsA11, true), json_encode($whIdsA11));
check('A11 exactly 1 row (SCM only)', count($whIdsA11) === 1, (string) count($whIdsA11));

// ============================================================
// Section A summary before starting the HTTP server for Section B.
// ============================================================
$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Section A: {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// B — HTTP integration
// ============================================================
$port = 8900 + random_int(800, 1199);
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

function httpUpload(string $url, string $filePath, string $cookieJar, string $csrfToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ["X-CSRF-Token: {$csrfToken}"],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($filePath, 'text/csv', 'upload.csv')],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

try {
    $superUser = uid('v29super'); $superPass = 'V29SuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('v29stock'); $stockPass = 'V29StockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $scmId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    // --------------------------------------------------------
    // B1 — GET /inventory/current/{sku} (All-Warehouse breakdown) now
    // shows Warehouse | Stock | Minimum | Status per row over HTTP too.
    // --------------------------------------------------------
    echo "\n== B1: All-Warehouse breakdown HTTP response shape ==\n";
    $breakdownHttp = httpCall('GET', "{$base}/inventory/current/{$skuA1}", null, $superJar);
    check('B1 returns 200', $breakdownHttp['status'] === 200, json_encode($breakdownHttp['body']));
    $byWhHttp = $breakdownHttp['body']['data']['by_warehouse'] ?? [];
    check('B1 response has 2 warehouse rows (SCM + CIBADAK)', count($byWhHttp) === 2, json_encode($byWhHttp));
    $hasShape = true;
    foreach ($byWhHttp as $row) {
        if (!array_key_exists('minimum_stock', $row) || !array_key_exists('report_status', $row)) { $hasShape = false; }
    }
    check('B1 every row carries minimum_stock AND report_status', $hasShape, json_encode($byWhHttp));

    // --------------------------------------------------------
    // B2 — bulk import IMPORT_MANAGE gate: STOCK forbidden.
    // --------------------------------------------------------
    echo "\n== B2: IMPORT_MANAGE permission gate on bulk minimum-stock import ==\n";
    $stockStage = httpCall('POST', "{$base}/import/minimum-stock/stage", ['file_path' => '/nonexistent', 'file_name' => 'x.csv'], $stockJar, $stockCsrf);
    check('B2 STOCK role is forbidden (403)', $stockStage['status'] === 403, json_encode($stockStage['body']));

    // --------------------------------------------------------
    // B3 — full real multipart upload -> stage -> preview -> commit.
    // --------------------------------------------------------
    echo "\n== B3: full upload -> stage -> preview -> commit round trip ==\n";
    [$itemB3, $skuB3] = makeItem($pdo, $kgUnitId, 'V29-B3');
    $csvB3 = tmpCsv("sku,warehouse_code,minimum_stock,buffer_stock\n{$skuB3},{$scmCode},33,\n");
    $uploadResp = httpUpload("{$base}/import/upload", $csvB3, $superJar, $superCsrf);
    check('B3 upload returns 200', $uploadResp['status'] === 200, json_encode($uploadResp['body']));
    $filePath = $uploadResp['body']['data']['file_path'] ?? null;
    $fileName = $uploadResp['body']['data']['file_name'] ?? null;

    $stageResp = httpCall('POST', "{$base}/import/minimum-stock/stage", ['file_path' => $filePath, 'file_name' => $fileName], $superJar, $superCsrf);
    check('B3 stage returns 200 with an import_batch_id', $stageResp['status'] === 200 && !empty($stageResp['body']['data']['import_batch_id']), json_encode($stageResp['body']));
    $batchIdB3 = $stageResp['body']['data']['import_batch_id'];

    $previewResp = httpCall('GET', "{$base}/import/batches/{$batchIdB3}/rows", null, $superJar, $superCsrf);
    check('B3 preview (generic, fully reused) returns 200 with 1 VALID row', $previewResp['status'] === 200 && count($previewResp['body']['data']['rows'] ?? []) === 1 && $previewResp['body']['data']['rows'][0]['row_status'] === 'VALID');

    $commitResp = httpCall('POST', "{$base}/import/minimum-stock/{$batchIdB3}/commit", [], $superJar, $superCsrf);
    check('B3 commit returns 200, imported=1', $commitResp['status'] === 200 && ($commitResp['body']['data']['imported'] ?? 0) === 1, json_encode($commitResp['body']));

    $verifyB3 = httpCall('GET', "{$base}/stock-policy?item_id={$itemB3}&warehouse_id={$scmId}", null, $superJar);
    check('B3 end-to-end HTTP round trip really set minimum_stock=33', (float) ($verifyB3['body']['data']['minimum_stock'] ?? -1) === 33.0, json_encode($verifyB3['body']['data'] ?? null));

    // --------------------------------------------------------
    // B4 — Download Template Excel exposes exactly the real accepted columns.
    // --------------------------------------------------------
    echo "\n== B4: Download Template Excel ==\n";
    $sheets = ImportTemplateService::build('MINIMUM_STOCK');
    check('B4 template has Instructions + Template sheets', isset($sheets['Instructions']) && isset($sheets['Template']));
    check('B4 template headers = sku, warehouse_code, minimum_stock, buffer_stock', $sheets['Template']['headers'] === ['sku', 'warehouse_code', 'minimum_stock', 'buffer_stock'], json_encode($sheets['Template']['headers']));
    $templateHttp = httpCall('GET', "{$base}/import/template/MINIMUM_STOCK", null, $superJar, $superCsrf);
    check('B4 GET /import/template/MINIMUM_STOCK is reachable (200)', $templateHttp['status'] === 200);

    // --------------------------------------------------------
    // B5 — PUT /stock-policy also rejects an inactive warehouse over HTTP
    // (defense-in-depth on top of A5's direct-service check).
    // --------------------------------------------------------
    echo "\n== B5: PUT /stock-policy rejects an inactive warehouse over HTTP ==\n";
    $putKt = httpCall('PUT', "{$base}/stock-policy", ['item_id' => $itemB3, 'warehouse_id' => $ktId, 'minimum_stock' => 1], $superJar, $superCsrf);
    check('B5 SUPERADMIN PUT to an inactive warehouse is rejected (never 200)', $putKt['status'] !== 200, json_encode($putKt['body']));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
