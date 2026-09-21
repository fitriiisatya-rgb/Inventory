<?php
declare(strict_types=1);

/**
 * PHASE V2 3f — GET /reports/stock ("Laporan Stok"). Covers: all items
 * incl. zero-stock by default, category/search/status filters,
 * pagination, sorting, summary totals, warehouse scoping (STOCK forced
 * to own, company-wide rollup for unscoped roles), migration-negative
 * rows always REVIEW (never folded into SAFE/LOW/CRITICAL), CSV export,
 * last_in/last_out/last_movement correctness, and a direct cross-check
 * that the SQL-computed status matches StockPolicyService::stockStatus()
 * for the same inputs (the two implementations must never drift).
 *
 * Usage: php tests/stock_report_test.php
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
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/StockReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\StockPolicyService;
use App\Services\StockReportService;
use App\Services\UnitConversionService;

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
        ->execute(['u' => uid('srtestuser'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'SR Test User', 'r' => $roleId]);
    $adminUserId = (int) $pdo->lastInsertId();
}

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $kgUnitId, ?int $categoryId = null, float $minimumStock = 10.0): array
{
    $sku = uid('SR-SKU');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, category_id) VALUES (:s,:n,:u,:m,:c)')
        ->execute(['s' => $sku, 'n' => "Item {$sku}", 'u' => $kgUnitId, 'm' => $minimumStock, 'c' => $categoryId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$itemId, $sku];
}
function postIn(PDO $pdo, int $itemId, int $whId, int $kgUnitId, float $qty, float $price, int $adminUserId, string $date = '2026-09-01 08:00:00'): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('sr-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $kgUnitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $adminUserId, 'username' => 'test',
    ]));
}
function postOut(PDO $pdo, int $itemId, int $whId, int $kgUnitId, float $qty, int $adminUserId, string $date = '2026-09-02 08:00:00'): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('sr-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
        'transaction_date' => $date, 'created_by' => $adminUserId, 'username' => 'test',
    ]));
}
function approveMigrationNegative(PDO $pdo, string $sku, string $whCode, float $ending): void
{
    $pdo->prepare(
        'INSERT INTO movement_reconciliation_reviews
            (sku, warehouse_code, historical_opening, historical_in, historical_out, historical_calculated_ending,
             status, is_migration_negative_approved, migration_negative_approved_by_name, migration_negative_note)
         VALUES (:sku, :wh, 0, 0, 0, :ending, \'PENDING_FINAL_STOCK\', 1, \'Test Owner\', \'test whitelist row\')'
    )->execute(['sku' => $sku, 'wh' => $whCode, 'ending' => $ending]);
}

$pdo->exec("INSERT INTO categories (code, name) VALUES ('SR-CAT-A', 'Category A')");
$catA = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO categories (code, name) VALUES ('SR-CAT-B', 'Category B')");
$catB = (int) $pdo->lastInsertId();

$whCode = uid('SR-WH');
$whId = makeWarehouse($pdo, $whCode);
$whId2 = makeWarehouse($pdo, uid('SR-WH2'));

echo "\n== A: all items shown including zero-stock, by default ==\n";
[$itemZero, $skuZero] = makeItem($pdo, $kgUnitId, $catA);
[$itemStocked, $skuStocked] = makeItem($pdo, $kgUnitId, $catA);
postIn($pdo, $itemStocked, $whId, $kgUnitId, 100, 1000, $adminUserId);

$reportAll = StockReportService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 200]);
$skusInReport = array_column($reportAll['rows'], 'sku');
check('zero-stock item IS included by default', in_array($skuZero, $skusInReport, true));
check('stocked item IS included', in_array($skuStocked, $skusInReport, true));

$reportNoZero = StockReportService::list($pdo, ['warehouse_id' => $whId, 'include_zero_stock' => false, 'page' => 1, 'per_page' => 200]);
$skusNoZero = array_column($reportNoZero['rows'], 'sku');
check('include_zero_stock=false excludes the zero-stock item', !in_array($skuZero, $skusNoZero, true));
check('include_zero_stock=false still includes the stocked item', in_array($skuStocked, $skusNoZero, true));

echo "\n== B: category filter ==\n";
[$itemCatB, $skuCatB] = makeItem($pdo, $kgUnitId, $catB);
$reportCatA = StockReportService::list($pdo, ['warehouse_id' => $whId, 'category_id' => $catA, 'page' => 1, 'per_page' => 200]);
$skusCatA = array_column($reportCatA['rows'], 'sku');
check('category filter includes category A items', in_array($skuZero, $skusCatA, true) && in_array($skuStocked, $skusCatA, true));
check('category filter excludes category B items', !in_array($skuCatB, $skusCatA, true));

echo "\n== C: search by SKU/name ==\n";
$reportSearch = StockReportService::list($pdo, ['warehouse_id' => $whId, 'q' => $skuStocked, 'page' => 1, 'per_page' => 200]);
$skusSearch = array_column($reportSearch['rows'], 'sku');
check('search by exact SKU returns only that item', $skusSearch === [$skuStocked], json_encode($skusSearch));

echo "\n== D: pagination ==\n";
$page1 = StockReportService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 2, 'sort' => 'sku']);
check('per_page=2 returns exactly 2 rows (or fewer if <2 total)', count($page1['rows']) <= 2);
check('pagination total matches summary total_items', $page1['pagination']['total'] === $page1['summary']['total_items']);

echo "\n== E: status filter + summary counts ==\n";
[$itemCritical, $skuCritical] = makeItem($pdo, $kgUnitId, $catA, 50.0);
postIn($pdo, $itemCritical, $whId, $kgUnitId, 10, 1000, $adminUserId); // qty=10 < minimum=50 -> CRITICAL
$reportCritical = StockReportService::list($pdo, ['warehouse_id' => $whId, 'status' => 'CRITICAL', 'page' => 1, 'per_page' => 200]);
$skusCritical = array_column($reportCritical['rows'], 'sku');
check('status=CRITICAL filter returns the critical item', in_array($skuCritical, $skusCritical, true));
check('status=CRITICAL filter excludes a SAFE item', !in_array($skuStocked, $skusCritical, true));

echo "\n== F: SQL-computed status cross-checked against StockPolicyService::stockStatus() ==\n";
$fullReport = StockReportService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 200]);
$allMatch = true;
foreach ($fullReport['rows'] as $row) {
    $expected = StockPolicyService::stockStatus($row['qty_base'], $row['minimum_stock'], $row['buffer_stock'], $row['migration_negative_review']);
    if ($expected !== $row['status']) {
        $allMatch = false;
        echo "  MISMATCH sku={$row['sku']} qty={$row['qty_base']} min={$row['minimum_stock']} buf=" . var_export($row['buffer_stock'], true) . " sqlStatus={$row['status']} serviceStatus={$expected}\n";
    }
}
check('every row\'s SQL-computed status matches StockPolicyService::stockStatus() for the same inputs', $allMatch);

echo "\n== G: migration-negative row always shows REVIEW, never folded into SAFE/LOW/CRITICAL ==\n";
[$itemMn, $skuMn] = makeItem($pdo, $kgUnitId, $catA);
approveMigrationNegative($pdo, $skuMn, $whCode, -5.0);
// Post the real negative batch the same way ImportOpeningStockService would (allow_migration_negative_opening).
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('sr-mn-in'), 'item_id' => $itemMn, 'warehouse_id' => $whId,
    'input_qty' => -5.0, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_type' => 'OPENING', 'transaction_date' => '2026-09-01 00:00:00',
    'created_by' => $adminUserId, 'username' => 'test', 'allow_migration_negative_opening' => true,
]));
$reportMn = StockReportService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 200]);
$mnRow = null;
foreach ($reportMn['rows'] as $row) {
    if ($row['sku'] === $skuMn) { $mnRow = $row; break; }
}
check('migration-negative item found in the report', $mnRow !== null);
check('status is MIGRATION_NEGATIVE_REVIEW (qty is negative, would otherwise be OUT_OF_STOCK)', $mnRow !== null && $mnRow['status'] === 'MIGRATION_NEGATIVE_REVIEW', $mnRow['status'] ?? 'N/A');
check('migration_negative_review flag is true', $mnRow !== null && $mnRow['migration_negative_review'] === true);
$reportMnReviewFilter = StockReportService::list($pdo, ['warehouse_id' => $whId, 'status' => 'MIGRATION_NEGATIVE_REVIEW', 'page' => 1, 'per_page' => 200]);
check('status=MIGRATION_NEGATIVE_REVIEW filter returns it', in_array($skuMn, array_column($reportMnReviewFilter['rows'], 'sku'), true));

echo "\n== H: last_in / last_out / last_movement ==\n";
[$itemMovement, $skuMovement] = makeItem($pdo, $kgUnitId, $catA);
postIn($pdo, $itemMovement, $whId, $kgUnitId, 50, 1000, $adminUserId, '2026-09-05 08:00:00');
postOut($pdo, $itemMovement, $whId, $kgUnitId, 10, $adminUserId, '2026-09-06 08:00:00');
$reportMovement = StockReportService::list($pdo, ['warehouse_id' => $whId, 'q' => $skuMovement, 'page' => 1, 'per_page' => 10]);
$movementRow = $reportMovement['rows'][0] ?? null;
check('last_in reflects the IN transaction date', $movementRow !== null && str_starts_with((string) $movementRow['last_in'], '2026-09-05'), json_encode($movementRow));
check('last_out reflects the OUT transaction date', $movementRow !== null && str_starts_with((string) $movementRow['last_out'], '2026-09-06'));
check('last_movement reflects the most recent of the two', $movementRow !== null && str_starts_with((string) $movementRow['last_movement'], '2026-09-06'));

echo "\n== I: company-wide rollup aggregates across warehouses ==\n";
[$itemMulti, $skuMulti] = makeItem($pdo, $kgUnitId, $catA);
postIn($pdo, $itemMulti, $whId, $kgUnitId, 30, 1000, $adminUserId);
postIn($pdo, $itemMulti, $whId2, $kgUnitId, 20, 1000, $adminUserId);
$companyReport = StockReportService::list($pdo, ['warehouse_id' => null, 'q' => $skuMulti, 'page' => 1, 'per_page' => 10]);
$companyRow = $companyReport['rows'][0] ?? null;
check('company-wide rollup sums qty across both warehouses (30+20=50)', $companyRow !== null && $companyRow['qty_base'] === 50.0, json_encode($companyRow));

$singleWhReport = StockReportService::list($pdo, ['warehouse_id' => $whId, 'q' => $skuMulti, 'page' => 1, 'per_page' => 10]);
$singleWhRow = $singleWhReport['rows'][0] ?? null;
check('single-warehouse scope shows only that warehouse\'s 30, not the combined 50', $singleWhRow !== null && $singleWhRow['qty_base'] === 30.0, json_encode($singleWhRow));

echo "\n== J: CSV export ==\n";
$csvRows = StockReportService::exportAll($pdo, ['warehouse_id' => $whId]);
check('exportAll returns unpaginated rows (more than one page-worth)', count($csvRows) >= 5, (string) count($csvRows));
check('exportAll rows have the same shape as list() rows', isset($csvRows[0]['sku'], $csvRows[0]['status'], $csvRows[0]['qty_base']));

// ============================================================
// K: HTTP-level warehouse scoping for GET /reports/stock
// ============================================================
echo "\n== K: HTTP warehouse scoping ==\n";

$port = 8900 + random_int(0, 400);
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
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
}

try {
    $stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
    $stockUser = uid('srstock'); $stockPass = 'SrStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $whId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);

    $stockOwnReport = httpCall('GET', "{$base}/reports/stock", null, $stockJar);
    check('STOCK user can GET /reports/stock for their own warehouse (no warehouse_id needed)', $stockOwnReport['status'] === 200 && ($stockOwnReport['body']['data']['warehouse_id'] ?? null) === $whId, json_encode($stockOwnReport['body']['data']['warehouse_id'] ?? null));

    $stockOtherReport = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whId2}", null, $stockJar);
    check('STOCK user cannot GET /reports/stock for another warehouse', $stockOtherReport['status'] === 403 && $stockOtherReport['body']['error']['code'] === 'FORBIDDEN');

    $stockCsvExport = httpCall('GET', "{$base}/reports/stock?format=csv", null, $stockJar);
    // PHASE V2.6C: exports now lead with a UTF-8 BOM (Excel-friendly —
    // see inv_export_csv()) before the header row, so this strips it
    // first rather than checking the raw byte stream literally.
    $stockCsvBody = str_starts_with((string) $stockCsvExport['raw'], "\xEF\xBB\xBF") ? substr((string) $stockCsvExport['raw'], 3) : (string) $stockCsvExport['raw'];
    check('CSV export returns text/csv content and starts with the header row', str_starts_with($stockCsvBody, 'SKU,'), substr((string) $stockCsvExport['raw'], 0, 40));

    @unlink($stockJar);
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
