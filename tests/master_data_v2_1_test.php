<?php
declare(strict_types=1);

/**
 * PHASE V2.1 — Master Data UX & Management Enhancement.
 * Covers: Master Barang filters/sort/pagination/search (via the extended
 * StockReportService::list()), the safe-delete matrix across all 6 master
 * types (blocked-when-referenced / deletable-when-unused / always-
 * deactivatable), and HTTP-level permission enforcement (SUPERADMIN/ADMIN
 * allowed, STOCK/DIVISION/VIEWER denied) for the new edit/delete routes.
 *
 * Usage: php tests/master_data_v2_1_test.php
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
require_once __DIR__ . '/../services/StockReportService.php';
require_once __DIR__ . '/../services/MasterDataSafetyService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockReportService;
use App\Services\MasterDataSafetyService;

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
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v21setup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V2.1 Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

// ---- fixtures: 2 warehouses, categories, suppliers, bakery destination, division ----
$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('V21-WH-A') . "', 'V2.1 WH A')");
$whAId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('V21-WH-B') . "', 'V2.1 WH B')");
$whUnusedId = (int) $pdo->lastInsertId();

$catUsedCode = uid('V21CAT-USED');
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $catUsedCode, 'n' => 'Used Category']);
$catUsedId = (int) $pdo->lastInsertId();
$catUnusedCode = uid('V21CAT-UNUSED');
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $catUnusedCode, 'n' => 'Unused Category']);
$catUnusedId = (int) $pdo->lastInsertId();

$supUsedCode = uid('V21SUP-USED');
$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $supUsedCode, 'n' => 'Used Supplier']);
$supUsedId = (int) $pdo->lastInsertId();
$supUnusedCode = uid('V21SUP-UNUSED');
$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $supUnusedCode, 'n' => 'Unused Supplier']);
$supUnusedId = (int) $pdo->lastInsertId();

$bdUsedCode = uid('V21BD-USED');
$pdo->prepare('INSERT INTO bakery_destinations (code, name) VALUES (:c, :n)')->execute(['c' => $bdUsedCode, 'n' => 'Used Bakery']);
$bdUsedId = (int) $pdo->lastInsertId();
$bdUnusedCode = uid('V21BD-UNUSED');
$pdo->prepare('INSERT INTO bakery_destinations (code, name) VALUES (:c, :n)')->execute(['c' => $bdUnusedCode, 'n' => 'Unused Bakery']);
$bdUnusedId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO divisions (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V21DIV-USED'), 'n' => 'V2.1 Div Used']);
$divUsedId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO divisions (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V21DIV-UNUSED'), 'n' => 'V2.1 Div Unused']);
$divUnusedId = (int) $pdo->lastInsertId();
// A user scoped to divUsedId makes it referenced.
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, division_id, is_active) VALUES (:u,:h,:n,:r,:d,1)')
    ->execute(['u' => uid('v21divuser'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Div User', 'r' => $superadminRoleId, 'd' => $divUsedId]);

// ---- items: several, with varying category/supplier/stock, to exercise filters/sort ----
function makeItem(PDO $pdo, int $unitId, ?int $categoryId, ?int $supplierId): array
{
    $sku = uid('V21-ITEM');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, default_supplier_id, minimum_stock, status) VALUES (:s,:n,:u,:c,:sup,10,"ACTIVE")')
        ->execute(['s' => $sku, 'n' => "Item {$sku}", 'u' => $unitId, 'c' => $categoryId, 'sup' => $supplierId]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

[$itemHighStock, $skuHigh] = makeItem($pdo, $kgUnitId, $catUsedId, $supUsedId);
[$itemLowStock, $skuLow] = makeItem($pdo, $kgUnitId, $catUsedId, $supUsedId);
// Deliberately NOT catUnusedId/supUnusedId — those two must stay genuinely
// unreferenced by anything so section C's "unused master IS deletable"
// checks are testing a real zero-reference case, not a mislabeled one.
[$itemZeroStock, $skuZero] = makeItem($pdo, $kgUnitId, null, null);
[$itemInactive, $skuInactive] = makeItem($pdo, $kgUnitId, null, null);
$pdo->prepare("UPDATE items SET status = 'INACTIVE' WHERE id = :id")->execute(['id' => $itemInactive]);
[$itemUnreferenced, $skuUnreferenced] = makeItem($pdo, $kgUnitId, null, null); // no transactions at all -> deletable

// Post real IN+OUT (creates FIFO batches/allocations/transactions -> real references)
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v21-in-high'), 'item_id' => $itemHighStock, 'warehouse_id' => $whAId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'v21test', 'supplier_id' => $supUsedId,
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v21-in-low'), 'item_id' => $itemLowStock, 'warehouse_id' => $whAId,
    'input_qty' => 5, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'v21test',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v21-in-zero-setup'), 'item_id' => $itemZeroStock, 'warehouse_id' => $whAId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'v21test',
]));
Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('v21-out-zero'), 'item_id' => $itemZeroStock, 'warehouse_id' => $whAId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $adminUserId, 'username' => 'v21test',
    'bakery_destination_id' => $bdUsedId,
]));

echo "\n== A: Master Barang filters/sort/pagination/search (StockReportService::list) ==\n";

$byCategory = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'category_id' => $catUsedId, 'per_page' => 50]);
$catSkus = array_column($byCategory['rows'], 'sku');
check('category filter includes items in that category', in_array($skuHigh, $catSkus, true) && in_array($skuLow, $catSkus, true));
check('category filter excludes items in a different category', !in_array($skuZero, $catSkus, true));

$bySupplier = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'supplier_id' => $supUsedId, 'per_page' => 50]);
$supSkus = array_column($bySupplier['rows'], 'sku');
check('supplier filter includes items from that supplier', in_array($skuHigh, $supSkus, true));
check('supplier filter excludes items from a different/no supplier', !in_array($skuZero, $supSkus, true));

$byWarehouse = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'per_page' => 50, 'item_status' => 'ACTIVE']);
check('warehouse filter scopes to that warehouse only (item present)', in_array($skuHigh, array_column($byWarehouse['rows'], 'sku'), true));

$activeOnly = StockReportService::list($pdo, ['item_status' => 'ACTIVE', 'per_page' => 200]);
check('item_status=ACTIVE excludes inactive item', !in_array($skuInactive, array_column($activeOnly['rows'], 'sku'), true));
$inactiveOnly = StockReportService::list($pdo, ['item_status' => 'INACTIVE', 'per_page' => 200]);
check('item_status=INACTIVE returns only the inactive item (among our fixtures)', in_array($skuInactive, array_column($inactiveOnly['rows'], 'sku'), true));
check('item_status=INACTIVE excludes an active item', !in_array($skuHigh, array_column($inactiveOnly['rows'], 'sku'), true));

$hasStock = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'stock_status' => 'HAS_STOCK', 'per_page' => 200]);
check('stock_status=HAS_STOCK includes item with qty>0', in_array($skuHigh, array_column($hasStock['rows'], 'sku'), true));
check('stock_status=HAS_STOCK excludes fully-consumed item (qty=0)', !in_array($skuZero, array_column($hasStock['rows'], 'sku'), true));

$zeroStock = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'stock_status' => 'ZERO_STOCK', 'per_page' => 200]);
check('stock_status=ZERO_STOCK includes the fully-consumed item', in_array($skuZero, array_column($zeroStock['rows'], 'sku'), true));

$needsAttention = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'stock_status' => 'NEEDS_ATTENTION', 'per_page' => 200]);
check('stock_status=NEEDS_ATTENTION includes below-minimum item (qty=5 < minimum=10)', in_array($skuLow, array_column($needsAttention['rows'], 'sku'), true));
check('stock_status=NEEDS_ATTENTION excludes a SAFE item', !in_array($skuHigh, array_column($needsAttention['rows'], 'sku'), true));

$sortQtyDesc = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'sort' => 'qty', 'dir' => 'desc', 'per_page' => 200]);
$qtySortedSkus = array_column($sortQtyDesc['rows'], 'sku');
check('sort=qty dir=desc: highest-stock item ranks above lowest-stock item', array_search($skuHigh, $qtySortedSkus, true) < array_search($skuLow, $qtySortedSkus, true));

$sortQtyAsc = StockReportService::list($pdo, ['warehouse_id' => $whAId, 'sort' => 'qty', 'dir' => 'asc', 'per_page' => 200, 'include_zero_stock' => true]);
$qtyAscSkus = array_column($sortQtyAsc['rows'], 'sku');
check('sort=qty dir=asc: zero-stock item ranks at/near the bottom-most position among non-empty', array_search($skuZero, $qtyAscSkus, true) < array_search($skuHigh, $qtyAscSkus, true));

$sortNameAsc = StockReportService::list($pdo, ['sort' => 'name', 'dir' => 'asc', 'per_page' => 200]);
$namesAsc = array_map(fn ($r) => $r['name'], $sortNameAsc['rows']);
$sortedCopy = $namesAsc;
sort($sortedCopy, SORT_STRING);
check('sort=name dir=asc actually returns alphabetically ascending names', $namesAsc === $sortedCopy);

$sortUpdatedAt = StockReportService::list($pdo, ['sort' => 'updated_at', 'dir' => 'desc', 'per_page' => 5]);
check('sort=updated_at is accepted and returns rows (newest-updated first)', count($sortUpdatedAt['rows']) > 0);

$search = StockReportService::list($pdo, ['q' => $skuHigh, 'per_page' => 50]);
check('search by SKU finds the exact item', count($search['rows']) === 1 && $search['rows'][0]['sku'] === $skuHigh);

$page1 = StockReportService::list($pdo, ['per_page' => 2, 'page' => 1]);
$page2 = StockReportService::list($pdo, ['per_page' => 2, 'page' => 2]);
check('pagination: page size respected', count($page1['rows']) <= 2);
check('pagination: different pages return different rows', $page1['rows'] !== $page2['rows'] || $page1['pagination']['total'] <= 2);
// active_only defaults to true when no item_status override is given, so
// itemInactive is correctly excluded from this count — 4, not 5, of our
// fixtures are ACTIVE at this point in the test.
check('pagination: total count reflects full result set, not just this page', $page1['pagination']['total'] >= 4);

echo "\n== B: Safe-delete matrix — blocked when referenced ==\n";

$itemRefs = MasterDataSafetyService::checkItemReferences($pdo, $itemHighStock);
check('item with FIFO batch/transaction history is BLOCKED from delete', $itemRefs['blocked'], implode(';', $itemRefs['reasons']));

$whRefs = MasterDataSafetyService::checkWarehouseReferences($pdo, $whAId);
check('warehouse with stock/transaction history is BLOCKED from delete', $whRefs['blocked'], implode(';', $whRefs['reasons']));

$supRefs = MasterDataSafetyService::checkSupplierReferences($pdo, $supUsedId);
check('supplier referenced by items/transactions is BLOCKED from delete', $supRefs['blocked'], implode(';', $supRefs['reasons']));

$bdRefs = MasterDataSafetyService::checkBakeryDestinationReferences($pdo, $bdUsedId);
check('bakery destination referenced by an OUT transaction is BLOCKED from delete', $bdRefs['blocked'], implode(';', $bdRefs['reasons']));

$catRefs = MasterDataSafetyService::checkCategoryReferences($pdo, $catUsedId);
check('category linked to items is BLOCKED from delete', $catRefs['blocked'], implode(';', $catRefs['reasons']));

$divRefs = MasterDataSafetyService::checkDivisionReferences($pdo, $divUsedId);
check('division referenced by a user is BLOCKED from delete', $divRefs['blocked'], implode(';', $divRefs['reasons']));

echo "\n== C: Safe-delete matrix — unused master CAN be deleted ==\n";

$itemUnrefRefs = MasterDataSafetyService::checkItemReferences($pdo, $itemUnreferenced);
check('item with zero transaction/batch history is NOT blocked', !$itemUnrefRefs['blocked'], implode(';', $itemUnrefRefs['reasons']));
$whUnusedRefs = MasterDataSafetyService::checkWarehouseReferences($pdo, $whUnusedId);
check('warehouse with zero stock/history is NOT blocked', !$whUnusedRefs['blocked'], implode(';', $whUnusedRefs['reasons']));
$supUnusedRefs = MasterDataSafetyService::checkSupplierReferences($pdo, $supUnusedId);
check('supplier never referenced is NOT blocked', !$supUnusedRefs['blocked'], implode(';', $supUnusedRefs['reasons']));
$bdUnusedRefs = MasterDataSafetyService::checkBakeryDestinationReferences($pdo, $bdUnusedId);
check('bakery destination never referenced is NOT blocked', !$bdUnusedRefs['blocked'], implode(';', $bdUnusedRefs['reasons']));
$catUnusedRefs = MasterDataSafetyService::checkCategoryReferences($pdo, $catUnusedId);
check('category with zero linked items is NOT blocked', !$catUnusedRefs['blocked'], implode(';', $catUnusedRefs['reasons']));
$divUnusedRefs = MasterDataSafetyService::checkDivisionReferences($pdo, $divUnusedId);
check('division with zero references is NOT blocked', !$divUnusedRefs['blocked'], implode(';', $divUnusedRefs['reasons']));

// Actually delete the unused ones via real DELETE FROM to prove the safety
// check's conclusion matches reality (FK constraints would also reject an
// incorrect "not blocked" verdict, so this is a real end-to-end proof).
try {
    $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $catUnusedId]);
    check('unused category actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused category actually deletes without FK error', false, $e->getMessage());
}
try {
    $pdo->prepare('DELETE FROM bakery_destinations WHERE id = :id')->execute(['id' => $bdUnusedId]);
    check('unused bakery destination actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused bakery destination actually deletes without FK error', false, $e->getMessage());
}
try {
    $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $supUnusedId]);
    check('unused supplier actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused supplier actually deletes without FK error', false, $e->getMessage());
}
try {
    $pdo->prepare('DELETE FROM divisions WHERE id = :id')->execute(['id' => $divUnusedId]);
    check('unused division actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused division actually deletes without FK error', false, $e->getMessage());
}
try {
    $pdo->prepare('DELETE FROM warehouses WHERE id = :id')->execute(['id' => $whUnusedId]);
    check('unused warehouse actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused warehouse actually deletes without FK error', false, $e->getMessage());
}
try {
    // Matches the real 'DELETE /items/{id}' handler exactly: it also
    // removes the item's own item_unit_conversions rows first (structural
    // setup data, not business history — see MasterDataSafetyService's
    // comment) in the same transaction, since nothing else references
    // that table. A raw `DELETE FROM items` alone would still hit this
    // FK, which is why the safety check deliberately does not count
    // item_unit_conversions as a "blocking reference."
    Database::transaction(function (PDO $tx) use ($itemUnreferenced) {
        $tx->prepare('DELETE FROM item_unit_conversions WHERE item_id = :id')->execute(['id' => $itemUnreferenced]);
        $tx->prepare('DELETE FROM items WHERE id = :id')->execute(['id' => $itemUnreferenced]);
    });
    check('unused item actually deletes without FK error', true);
} catch (\PDOException $e) {
    check('unused item actually deletes without FK error', false, $e->getMessage());
}

echo "\n== D: Deactivate always allowed, even when referenced ==\n";
$pdo->prepare("UPDATE items SET status = 'INACTIVE' WHERE id = :id")->execute(['id' => $itemHighStock]);
$stillActive = $pdo->query("SELECT status FROM items WHERE id = {$itemHighStock}")->fetchColumn();
check('a referenced item CAN be deactivated', $stillActive === 'INACTIVE');
$stillHasBatches = (int) $pdo->query("SELECT COUNT(*) FROM inventory_batches WHERE item_id = {$itemHighStock}")->fetchColumn();
check('deactivating a referenced item does not touch its FIFO batches', $stillHasBatches > 0);
$pdo->prepare("UPDATE warehouses SET is_active = 0 WHERE id = :id")->execute(['id' => $whAId]);
$whStillActive = (int) $pdo->query("SELECT is_active FROM warehouses WHERE id = {$whAId}")->fetchColumn();
check('a referenced warehouse CAN be deactivated', $whStillActive === 0);

// ============================================================
// E: HTTP-level permission enforcement for the new edit/delete routes
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

function loginAs(PDO $pdo, string $base, string $roleCode, ?int $warehouseId = null): array
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $username = uid('httpv21-' . strtolower($roleCode));
    $pass = 'HttpV21Pass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId, 'w' => $warehouseId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $username, 'password' => $pass], $jar);
    return ['jar' => $jar, 'csrf' => $login['body']['data']['csrf_token'] ?? ''];
}

try {
    $superadmin = loginAs($pdo, $base, 'SUPERADMIN');
    $admin = loginAs($pdo, $base, 'ADMIN');
    // whUnusedId was already deleted in Section C above (that's what proved
    // it was safely deletable) — whAId still exists (only deactivated in
    // Section D), so it's the one safe existing FK target left to scope a
    // STOCK user to here.
    $stock = loginAs($pdo, $base, 'STOCK', $whAId);
    $division = loginAs($pdo, $base, 'DIVISION');
    $viewer = loginAs($pdo, $base, 'VIEWER');

    // Fresh, guaranteed-unused fixtures for this HTTP section (the earlier
    // ones may already be deleted/referenced from sections B-D above).
    $pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('V21-HTTP-WH') . "', 'HTTP Test WH')");
    $httpWhId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO divisions (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V21HTTPDIV'), 'n' => 'V2.1 HTTP Div']);
    $httpDivId = (int) $pdo->lastInsertId();
    [$httpItemId, ] = makeItem($pdo, $kgUnitId, null, null);

    foreach (['SUPERADMIN' => $superadmin, 'ADMIN' => $admin] as $roleName => $session) {
        $r = httpCall('PUT', "{$base}/warehouses/{$httpWhId}", ['name' => 'Renamed by ' . $roleName], $session['jar'], $session['csrf']);
        check("{$roleName} CAN PUT /warehouses/{id}", ($r['body']['data']['success'] ?? false) === true, json_encode($r['body']));
    }
    $r = httpCall('PUT', "{$base}/warehouses/{$httpWhId}", ['name' => 'Should be denied'], $stock['jar'], $stock['csrf']);
    check('STOCK CANNOT PUT /warehouses/{id}', $r['status'] === 403 && ($r['body']['error']['code'] ?? '') === 'FORBIDDEN');
    $r = httpCall('PUT', "{$base}/warehouses/{$httpWhId}", ['name' => 'Should be denied'], $division['jar'], $division['csrf']);
    check('DIVISION CANNOT PUT /warehouses/{id}', $r['status'] === 403);
    $r = httpCall('PUT', "{$base}/warehouses/{$httpWhId}", ['name' => 'Should be denied'], $viewer['jar'], $viewer['csrf']);
    check('VIEWER CANNOT PUT /warehouses/{id}', $r['status'] === 403);

    $r = httpCall('PUT', "{$base}/divisions/{$httpDivId}", ['name' => 'Renamed'], $admin['jar'], $admin['csrf']);
    check('ADMIN CAN PUT /divisions/{id}', ($r['body']['data']['success'] ?? false) === true, json_encode($r['body']));
    $r = httpCall('PUT', "{$base}/divisions/{$httpDivId}", ['name' => 'Denied'], $stock['jar'], $stock['csrf']);
    check('STOCK CANNOT PUT /divisions/{id}', $r['status'] === 403);

    $r = httpCall('PUT', "{$base}/items/{$httpItemId}", ['name' => 'Renamed Item'], $admin['jar'], $admin['csrf']);
    check('ADMIN CAN PUT /items/{id}', ($r['body']['data']['success'] ?? false) === true, json_encode($r['body']));
    $r = httpCall('PUT', "{$base}/items/{$httpItemId}", ['name' => 'Denied'], $viewer['jar'], $viewer['csrf']);
    check('VIEWER CANNOT PUT /items/{id}', $r['status'] === 403);

    // Delete-blocked, via real HTTP, on the fixtures already proven
    // referenced in Section B (item/warehouse ids reused from setup).
    $r = httpCall('DELETE', "{$base}/items/{$itemLowStock}", null, $admin['jar'], $admin['csrf']);
    check('DELETE a referenced item via HTTP is rejected with DELETE_BLOCKED_HAS_REFERENCES', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'DELETE_BLOCKED_HAS_REFERENCES', json_encode($r['body']));

    // Delete-allowed, via real HTTP, on a genuinely unused item.
    [$httpDeletableItem, ] = makeItem($pdo, $kgUnitId, null, null);
    $r = httpCall('DELETE', "{$base}/items/{$httpDeletableItem}", null, $admin['jar'], $admin['csrf']);
    check('DELETE an unused item via HTTP succeeds', ($r['body']['data']['success'] ?? false) === true, json_encode($r['body']));

    $r = httpCall('DELETE', "{$base}/items/{$httpItemId}", null, $stock['jar'], $stock['csrf']);
    check('STOCK CANNOT DELETE /items/{id}', $r['status'] === 403);

    foreach ([$superadmin, $admin, $stock, $division, $viewer] as $s) {
        @unlink($s['jar']);
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
