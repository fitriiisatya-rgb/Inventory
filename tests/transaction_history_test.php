<?php
declare(strict_types=1);

/**
 * PHASE V2 3g — GET /reports/transactions and GET /reports/transactions/{id}.
 * Covers: filters (item/category/type/date range/vendor/bakery
 * destination/search/warehouse scope), pagination, detail drawer shape
 * (lines, FIFO allocations for OUT, audit trail gated on AUDIT_LOG_VIEW),
 * and warehouse-scope enforcement re-derived from the DB, never trusted
 * from the request.
 *
 * Usage: php tests/transaction_history_test.php
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
require_once __DIR__ . '/../services/TransactionHistoryService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\TransactionHistoryService;
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
        ->execute(['u' => uid('thtestuser'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'TH Test User', 'r' => $roleId]);
    $adminUserId = (int) $pdo->lastInsertId();
}

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $kgUnitId, ?int $categoryId = null): array
{
    $sku = uid('TH-SKU');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id) VALUES (:s,:n,:u,:c)')
        ->execute(['s' => $sku, 'n' => "Item {$sku}", 'u' => $kgUnitId, 'c' => $categoryId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$itemId, $sku];
}

$whId = makeWarehouse($pdo, uid('TH-WH'));
$whId2 = makeWarehouse($pdo, uid('TH-WH2'));
$pdo->exec("INSERT INTO categories (code, name) VALUES ('TH-CAT', 'TH Category')");
$catId = (int) $pdo->lastInsertId();

$supplierId = (int) \App\Services\SupplierService::create($pdo, ['code' => uid('TH-VND'), 'name' => 'TH Vendor', 'created_by' => $adminUserId])['supplier_id'];
$bakeryDestId = (int) \App\Services\BakeryDestinationService::create($pdo, ['code' => uid('TH-BAK'), 'name' => 'TH Bakery', 'created_by' => $adminUserId])['bakery_destination_id'];

[$item1, $sku1] = makeItem($pdo, $kgUnitId, $catId);
[$item2, $sku2] = makeItem($pdo, $kgUnitId);

echo "\n== A: IN with vendor, OUT with bakery destination — round-trip through the list ==\n";
$inResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('th-in'), 'item_id' => $item1, 'warehouse_id' => $whId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
    'supplier_id' => $supplierId, 'reference_no' => 'PO-001',
]));
$inTxId = $inResult['transaction_id'];

$outResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('th-out'), 'item_id' => $item1, 'warehouse_id' => $whId,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
    'bakery_destination_id' => $bakeryDestId, 'reference_no' => 'DO-001',
]));
$outTxId = $outResult['transaction_id'];

$list = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 50]);
$inRow = null; $outRow = null;
foreach ($list['rows'] as $row) {
    if ($row['transaction_id'] === $inTxId) { $inRow = $row; }
    if ($row['transaction_id'] === $outTxId) { $outRow = $row; }
}
check('IN transaction appears in the list', $inRow !== null);
check('IN row shows the vendor', $inRow !== null && $inRow['supplier']['id'] === $supplierId, json_encode($inRow['supplier'] ?? null));
check('IN row has no bakery destination', $inRow !== null && $inRow['bakery_destination'] === null);
check('OUT transaction appears in the list', $outRow !== null);
check('OUT row shows the bakery destination', $outRow !== null && $outRow['bakery_destination']['id'] === $bakeryDestId, json_encode($outRow['bakery_destination'] ?? null));
check('OUT row has no vendor', $outRow !== null && $outRow['supplier'] === null);
check('rows show qty/unit (mandatory requirement #8)', $inRow !== null && $inRow['input_qty'] === 100.0 && $inRow['input_unit']['code'] === 'KG');
check('rows show source/destination area (warehouse)', $inRow !== null && $inRow['warehouse']['id'] === $whId);

echo "\n== B: filters — item_id, category_id, transaction_type, date range, supplier_id, bakery_destination_id, q ==\n";
[$item3, $sku3] = makeItem($pdo, $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('th-in3'), 'item_id' => $item3, 'warehouse_id' => $whId,
    'input_qty' => 5, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 500,
    'transaction_date' => '2026-09-10 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
]));

$byItem = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'item_id' => $item1, 'page' => 1, 'per_page' => 50]);
check('item_id filter returns only that item\'s transactions', count($byItem['rows']) === 2 && !in_array($sku3, array_column(array_column($byItem['rows'], 'item'), 'sku'), true), (string) count($byItem['rows']));

$byCategory = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'category_id' => $catId, 'page' => 1, 'per_page' => 50]);
$catSkus = array_column(array_column($byCategory['rows'], 'item'), 'sku');
check('category_id filter includes item1 (in category)', in_array($sku1, $catSkus, true));
check('category_id filter excludes item3 (no category)', !in_array($sku3, $catSkus, true));

$byType = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'transaction_type' => 'OUT', 'page' => 1, 'per_page' => 50]);
check('transaction_type=OUT filter returns only OUT rows', count($byType['rows']) === 1 && $byType['rows'][0]['transaction_type'] === 'OUT');

$byDateRange = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'date_from' => '2026-09-05', 'date_to' => '2026-09-15', 'page' => 1, 'per_page' => 50]);
check('date range filter returns only the item3 transaction', count($byDateRange['rows']) === 1 && $byDateRange['rows'][0]['item']['sku'] === $sku3, json_encode($byDateRange['rows']));

$bySupplier = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'supplier_id' => $supplierId, 'page' => 1, 'per_page' => 50]);
check('supplier_id filter returns only the vendor IN', count($bySupplier['rows']) === 1 && $bySupplier['rows'][0]['transaction_id'] === $inTxId);

$byBakery = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'bakery_destination_id' => $bakeryDestId, 'page' => 1, 'per_page' => 50]);
check('bakery_destination_id filter returns only the bakery OUT', count($byBakery['rows']) === 1 && $byBakery['rows'][0]['transaction_id'] === $outTxId);

$byRef = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'q' => 'PO-001', 'page' => 1, 'per_page' => 50]);
check('q filter matches reference_no', count($byRef['rows']) === 1 && $byRef['rows'][0]['transaction_id'] === $inTxId);

$byNameSearch = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'q' => $sku3, 'page' => 1, 'per_page' => 50]);
check('q filter matches SKU', count($byNameSearch['rows']) === 1 && $byNameSearch['rows'][0]['item']['sku'] === $sku3);

echo "\n== C: another warehouse's transaction never leaks into this warehouse's list ==\n";
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('th-in-wh2'), 'item_id' => $item2, 'warehouse_id' => $whId2,
    'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 100,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'test',
]));
$whScopedList = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 50]);
check('warehouse-scoped list never includes another warehouse\'s transaction', !in_array($sku2, array_column(array_column($whScopedList['rows'], 'item'), 'sku'), true));

echo "\n== D: detail endpoint — header, lines, FIFO allocations for OUT ==\n";
$outDetail = TransactionHistoryService::detail($pdo, $outTxId, false);
check('detail returns the correct transaction_id', $outDetail['transaction_id'] === $outTxId);
check('detail shows bakery destination', $outDetail['bakery_destination']['id'] === $bakeryDestId);
check('detail has exactly one line', count($outDetail['lines']) === 1);
check('OUT line has FIFO allocations (consumed the IN batch)', count($outDetail['lines'][0]['fifo_allocations']) >= 1, json_encode($outDetail['lines'][0]['fifo_allocations']));
check('FIFO allocation shows qty/cost/received_date', isset($outDetail['lines'][0]['fifo_allocations'][0]['qty_allocated'], $outDetail['lines'][0]['fifo_allocations'][0]['unit_cost_base'], $outDetail['lines'][0]['fifo_allocations'][0]['received_date']));
check('audit_log key is absent when includeAudit=false', !array_key_exists('audit_log', $outDetail));

$inDetail = TransactionHistoryService::detail($pdo, $inTxId, false);
check('IN line has NO FIFO allocations (it creates a batch, does not consume one)', $inDetail['lines'][0]['fifo_allocations'] === []);

$inDetailWithAudit = TransactionHistoryService::detail($pdo, $inTxId, true);
check('audit_log key present when includeAudit=true', array_key_exists('audit_log', $inDetailWithAudit));

echo "\n== E: pagination ==\n";
$paged = TransactionHistoryService::list($pdo, ['warehouse_id' => $whId, 'page' => 1, 'per_page' => 1]);
check('per_page=1 returns exactly 1 row', count($paged['rows']) === 1);
check('pagination total reflects all matching rows, not just this page', $paged['pagination']['total'] === 3, (string) $paged['pagination']['total']);

// ============================================================
// F: HTTP-level scope enforcement
// ============================================================
echo "\n== F: HTTP warehouse-scope enforcement ==\n";

$port = 9000 + random_int(0, 400);
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
    $stockUser = uid('thstock'); $stockPass = 'ThStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $whId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);

    $stockOwnList = httpCall('GET', "{$base}/reports/transactions", null, $stockJar);
    check('STOCK user can GET /reports/transactions for their own warehouse', $stockOwnList['status'] === 200 && count($stockOwnList['body']['data']['rows']) === 3, json_encode($stockOwnList['body']['data']['pagination'] ?? null));

    $stockOtherList = httpCall('GET', "{$base}/reports/transactions?warehouse_id={$whId2}", null, $stockJar);
    check('STOCK user cannot GET /reports/transactions for another warehouse', $stockOtherList['status'] === 403 && $stockOtherList['body']['error']['code'] === 'FORBIDDEN');

    $stockOwnDetail = httpCall('GET', "{$base}/reports/transactions/{$outTxId}", null, $stockJar);
    check('STOCK user can GET detail for a transaction in their own warehouse', $stockOwnDetail['status'] === 200);

    $whId2TxIdStmt = $pdo->query("SELECT id FROM inventory_transactions WHERE warehouse_id = {$whId2} LIMIT 1");
    $whId2TxId = (int) $whId2TxIdStmt->fetchColumn();
    $stockOtherDetail = httpCall('GET', "{$base}/reports/transactions/{$whId2TxId}", null, $stockJar);
    check('STOCK user cannot GET detail for a transaction in another warehouse (re-derived from DB, not trusted from request)', $stockOtherDetail['status'] === 403 && $stockOtherDetail['body']['error']['code'] === 'FORBIDDEN');

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
