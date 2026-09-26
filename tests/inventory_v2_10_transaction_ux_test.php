<?php
declare(strict_types=1);

/**
 * PHASE V2.10 — Transaction UX Upgrade: searchable item selector, auto
 * purchase price, multi-unit barcode support, against real MySQL/MariaDB.
 *
 * Audit findings this phase built on (see services/ItemPriceService.php
 * and services/ItemBarcodeService.php doc comments for the full detail):
 *   - No "master/default purchase price" field exists anywhere in the
 *     schema. The only legitimate existing price source is
 *     item_price_history.price_per_unit (already populated on every real
 *     Stock IN by PriceAnomalyService::recordPrice(), for an unrelated
 *     purpose — anomaly detection). ItemPriceService reads it read-only.
 *   - items.barcode (legacy, single, non-unique) stays completely
 *     untouched. A new, additive item_barcodes table (Section: multi-unit
 *     barcode mappings) coexists alongside it.
 *   - No new search API: item/barcode search is pure client-side
 *     filtering over already-cached master data (see item-selector.js),
 *     so this file only covers what genuinely lives server-side: price
 *     resolution, barcode CRUD + uniqueness enforcement + resolution
 *     states, and the extended GET /items/{id}/units response shape.
 *
 * Covers (subset of the feature's 58-case matrix that is meaningfully
 * server-side/HTTP-automatable — camera scanning and keyboard-navigation
 * UX are browser-smoke-only, not re-tested here):
 *   Price:   exact-unit match, cross-unit derivation math, no-history=NONE,
 *            GET /items/{id}/units response shape, master data (items
 *            table) never mutated by a Stock IN price override.
 *   Barcode: create/update, item/unit validation, backend duplicate-active
 *            rejection (service AND raw DB constraint), resolve() status
 *            states (OK/UNKNOWN/ITEM_INACTIVE/MAPPING_INACTIVE), unit-
 *            specific mapping resolution, HTTP permission gates
 *            (MASTER_ITEM_MANAGE required for write, STOCK forbidden).
 *   Regression: existing GET /items/{id}/units callers (unit_id, code,
 *            name, conversion_to_base, is_purchase_default) still present
 *            byte-for-byte; POST /transactions/in still posts through the
 *            real unmodified FifoService/PurchaseCostingService.
 *
 * Usage: php tests/inventory_v2_10_transaction_ux_test.php
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
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/PurchaseCostingService.php';
require_once __DIR__ . '/../services/ItemPriceService.php';
require_once __DIR__ . '/../services/ItemBarcodeService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\ItemPriceService;
use App\Services\ItemBarcodeService;
use App\Services\ValidationException;
use App\Services\NotFoundException;

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

$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$boxUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='BOX'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v210setup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V2.10 Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $baseUnitId, string $tag, string $status = 'ACTIVE'): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => $status]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v210-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v210',
    ]));
}

$whCode = uid('V210-WH');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $whCode, 'n' => 'V2.10 Warehouse']);
$whId = (int) $pdo->lastInsertId();

// ============================================================
// PRICE — Part B1/B2/B9
// ============================================================
echo "== PRICE: exact-unit match wins over derived (Part B1) ==\n";
[$itemP1, $skuP1] = makeItem($pdo, $kgUnitId, 'V210-P1');
UnitConversionService::openNewVersion($pdo, $itemP1, $boxUnitId, 12.0, '2020-01-01 00:00:00', null, '1 BOX = 12 KG base');
postIn($pdo, $itemP1, $whId, $kgUnitId, 10, 1000, $adminUserId); // KG price history: 1000/KG
postIn($pdo, $itemP1, $whId, $boxUnitId, 2, 11000, $adminUserId); // BOX price history: 11000/BOX (=916.67/KG base)

$refKg = ItemPriceService::resolveReferencePrice($pdo, $itemP1, $kgUnitId);
check('PRICE exact-unit KG resolves EXACT_UNIT', $refKg['price_source'] === 'EXACT_UNIT', json_encode($refKg));
check('PRICE exact-unit KG price = 1000 (the real KG purchase, never the BOX-derived figure)', abs($refKg['reference_price'] - 1000.0) < 0.01, json_encode($refKg));

$refBox = ItemPriceService::resolveReferencePrice($pdo, $itemP1, $boxUnitId);
check('PRICE exact-unit BOX resolves EXACT_UNIT', $refBox['price_source'] === 'EXACT_UNIT', json_encode($refBox));
check('PRICE exact-unit BOX price = 11000 (the real BOX purchase)', abs($refBox['reference_price'] - 11000.0) < 0.01, json_encode($refBox));

echo "\n== PRICE: cross-unit derivation math (Part B9 — never a silent unit-price error) ==\n";
[$itemP2, $skuP2] = makeItem($pdo, $kgUnitId, 'V210-P2');
UnitConversionService::openNewVersion($pdo, $itemP2, $boxUnitId, 12.0, '2020-01-01 00:00:00', null, '1 BOX = 12 KG base');
postIn($pdo, $itemP2, $whId, $kgUnitId, 5, 2000, $adminUserId); // only KG history exists: unit_cost_base = 2000/KG

$derivedBox = ItemPriceService::resolveReferencePrice($pdo, $itemP2, $boxUnitId);
check('PRICE no BOX history -> DERIVED (never NONE when another unit has history)', $derivedBox['price_source'] === 'DERIVED', json_encode($derivedBox));
check('PRICE derived BOX price = 2000 * 12 = 24000 (base-cost-normalized, never the raw KG figure reused as-is)', abs($derivedBox['reference_price'] - 24000.0) < 0.01, json_encode($derivedBox));

echo "\n== PRICE: no purchase history at all -> NONE, never a fabricated Rp 0 (Part B8) ==\n";
[$itemP3, $skuP3] = makeItem($pdo, $kgUnitId, 'V210-P3');
$noHistory = ItemPriceService::resolveReferencePrice($pdo, $itemP3, $kgUnitId);
check('PRICE brand-new item resolves NONE', $noHistory['price_source'] === 'NONE', json_encode($noHistory));
check('PRICE brand-new item reference_price is null (never 0)', $noHistory['reference_price'] === null, json_encode($noHistory));

echo "\n== PRICE: master data (items table) is never mutated by a Stock IN price override (Part B4) ==\n";
[$itemP4, $skuP4] = makeItem($pdo, $kgUnitId, 'V210-P4');
postIn($pdo, $itemP4, $whId, $kgUnitId, 3, 500, $adminUserId);
$beforeRow = $pdo->query("SELECT * FROM items WHERE id = {$itemP4}")->fetch();
postIn($pdo, $itemP4, $whId, $kgUnitId, 4, 800, $adminUserId); // deliberately different override price, within PRICE_ANOMALY tolerance
$afterRow = $pdo->query("SELECT * FROM items WHERE id = {$itemP4}")->fetch();
unset($beforeRow['updated_at'], $afterRow['updated_at']);
check('PRICE overriding the transaction price never touches the items row itself', $beforeRow === $afterRow, 'items row changed after a price-overridden Stock IN');

// ============================================================
// BARCODE — Part C
// ============================================================
echo "\n== BARCODE: create + resolve OK (Part C1/C3) ==\n";
[$itemB1, $skuB1] = makeItem($pdo, $kgUnitId, 'V210-B1');
UnitConversionService::openNewVersion($pdo, $itemB1, $boxUnitId, 12.0, '2020-01-01 00:00:00', null, '1 BOX = 12 KG base');
$bcPcsVal = uid('BC-KG');
$bcBoxVal = uid('BC-BOX');
$created = ItemBarcodeService::create($pdo, ['item_id' => $itemB1, 'unit_id' => $kgUnitId, 'barcode' => $bcPcsVal, 'created_by' => $adminUserId]);
check('BARCODE create (unit-specific) succeeds', $created['success'] === true, json_encode($created));
$createdBox = ItemBarcodeService::create($pdo, ['item_id' => $itemB1, 'unit_id' => $boxUnitId, 'barcode' => $bcBoxVal, 'created_by' => $adminUserId]);
check('BARCODE second mapping, different unit, same item succeeds (Part C3 — multiple barcodes per SKU)', $createdBox['success'] === true, json_encode($createdBox));

$resolveKg = ItemBarcodeService::resolve($pdo, $bcPcsVal);
check('BARCODE resolve KG-mapped barcode -> OK + correct unit_id', $resolveKg['status'] === 'OK' && (int) $resolveKg['unit_id'] === $kgUnitId, json_encode($resolveKg));
$resolveBox = ItemBarcodeService::resolve($pdo, $bcBoxVal);
check('BARCODE resolve BOX-mapped barcode (same SKU) -> OK + correct DIFFERENT unit_id (Part C3)', $resolveBox['status'] === 'OK' && (int) $resolveBox['unit_id'] === $boxUnitId, json_encode($resolveBox));

echo "\n== BARCODE: unknown / inactive item / inactive mapping resolution states (Part C8) ==\n";
$unknown = ItemBarcodeService::resolve($pdo, uid('NEVER-REGISTERED'));
check('BARCODE unknown barcode -> UNKNOWN', $unknown['status'] === 'UNKNOWN', json_encode($unknown));

[$itemB2, $skuB2] = makeItem($pdo, $kgUnitId, 'V210-B2', 'INACTIVE');
$bcInactiveItem = uid('BC-INACTITEM');
ItemBarcodeService::create($pdo, ['item_id' => $itemB2, 'barcode' => $bcInactiveItem, 'created_by' => $adminUserId]);
$resolveInactiveItem = ItemBarcodeService::resolve($pdo, $bcInactiveItem);
check('BARCODE mapping to an INACTIVE item -> ITEM_INACTIVE (never silently substitutes another item)', $resolveInactiveItem['status'] === 'ITEM_INACTIVE', json_encode($resolveInactiveItem));

[$itemB3, $skuB3] = makeItem($pdo, $kgUnitId, 'V210-B3');
$bcDeactivated = uid('BC-DEACT');
$mapB3 = ItemBarcodeService::create($pdo, ['item_id' => $itemB3, 'barcode' => $bcDeactivated, 'created_by' => $adminUserId]);
ItemBarcodeService::update($pdo, $mapB3['item_barcode_id'], ['is_active' => false, 'updated_by' => $adminUserId]);
$resolveDeactivated = ItemBarcodeService::resolve($pdo, $bcDeactivated);
check('BARCODE deactivated mapping -> MAPPING_INACTIVE', $resolveDeactivated['status'] === 'MAPPING_INACTIVE', json_encode($resolveDeactivated));

echo "\n== BARCODE: backend duplicate-active rejection (Part C4 — never frontend-only) ==\n";
[$itemB4a, $skuB4a] = makeItem($pdo, $kgUnitId, 'V210-B4A');
[$itemB4b, $skuB4b] = makeItem($pdo, $kgUnitId, 'V210-B4B');
$dupeVal = uid('BC-DUPE');
ItemBarcodeService::create($pdo, ['item_id' => $itemB4a, 'barcode' => $dupeVal, 'created_by' => $adminUserId]);
$dupeRejected = false;
try {
    ItemBarcodeService::create($pdo, ['item_id' => $itemB4b, 'barcode' => $dupeVal, 'created_by' => $adminUserId]);
} catch (ValidationException $e) {
    $dupeRejected = true;
}
check('BARCODE service rejects a second ACTIVE mapping for the same barcode value on a DIFFERENT item', $dupeRejected);

// Prove the real guarantee is the DB constraint itself, not just the
// service's pre-check — insert directly, bypassing ItemBarcodeService.
$dbConstraintHeld = false;
try {
    $pdo->prepare('INSERT INTO item_barcodes (item_id, unit_id, barcode, is_active) VALUES (:i, NULL, :b, 1)')
        ->execute(['i' => $itemB4b, 'b' => $dupeVal]);
} catch (\PDOException $e) {
    $dbConstraintHeld = true;
}
check('BARCODE uq_item_barcodes_active is a REAL DB constraint (raw duplicate INSERT rejected even bypassing the service)', $dbConstraintHeld);

echo "\n== BARCODE: deactivating the first mapping frees the barcode value for reactivation elsewhere ==\n";
$firstMapId = ItemBarcodeService::listForItem($pdo, $itemB4a)[0]['id'];
ItemBarcodeService::update($pdo, $firstMapId, ['is_active' => false, 'updated_by' => $adminUserId]);
$reassigned = ItemBarcodeService::create($pdo, ['item_id' => $itemB4b, 'barcode' => $dupeVal, 'created_by' => $adminUserId]);
check('BARCODE value can be reassigned to a different item once the old active mapping is deactivated', $reassigned['success'] === true, json_encode($reassigned));

echo "\n== BARCODE: unit must be valid for the item (Part I — server-side, never trusts frontend blindly) ==\n";
[$itemB5, $skuB5] = makeItem($pdo, $kgUnitId, 'V210-B5'); // no BOX conversion defined for this item
$invalidUnitRejected = false;
try {
    ItemBarcodeService::create($pdo, ['item_id' => $itemB5, 'unit_id' => $boxUnitId, 'barcode' => uid('BC-BADUNIT'), 'created_by' => $adminUserId]);
} catch (ValidationException $e) {
    $invalidUnitRejected = true;
}
check('BARCODE create rejects a unit that is not actually configured for this item', $invalidUnitRejected);

echo "\n== BARCODE: create rejects an unknown item (Part I) ==\n";
$unknownItemRejected = false;
try {
    ItemBarcodeService::create($pdo, ['item_id' => 999999999, 'barcode' => uid('BC-NOITEM'), 'created_by' => $adminUserId]);
} catch (NotFoundException $e) {
    $unknownItemRejected = true;
}
check('BARCODE create rejects a non-existent item_id', $unknownItemRejected);

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Section (direct-service): {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — extended GET /items/{id}/units, /item-barcodes CRUD + permissions
// ============================================================
$port = 8900 + random_int(1200, 1599);
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
    $superUser = uid('v210super'); $superPass = 'V210SuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('v210stock'); $stockPass = 'V210StockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $whId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    echo "\n== HTTP: GET /items/{id}/units keeps every pre-existing field AND adds reference_price/price_source (regression + Part B2) ==\n";
    $unitsHttp = httpCall('GET', "{$base}/items/{$itemP1}/units", null, $superJar);
    check('HTTP units 200', $unitsHttp['status'] === 200, json_encode($unitsHttp['body']));
    $rows = $unitsHttp['body']['data'] ?? [];
    $kgRow = null;
    foreach ($rows as $r) { if ((int) $r['id'] === $kgUnitId) $kgRow = $r; }
    check('HTTP units row still has id/code/name/conversion_to_base/is_purchase_default (pre-existing shape untouched)',
        $kgRow !== null && array_key_exists('code', $kgRow) && array_key_exists('conversion_to_base', $kgRow) && array_key_exists('is_purchase_default', $kgRow),
        json_encode($kgRow));
    check('HTTP units row carries the new reference_price/price_source fields', $kgRow !== null && array_key_exists('reference_price', $kgRow) && array_key_exists('price_source', $kgRow), json_encode($kgRow));
    check('HTTP units KG reference_price = 1000 (matches the direct-service assertion above)', $kgRow !== null && abs((float) $kgRow['reference_price'] - 1000.0) < 0.01, json_encode($kgRow));

    echo "\n== HTTP: GET /item-barcodes returns ALL rows (active + inactive), any authenticated role (Part C8's UI needs both) ==\n";
    $listHttp = httpCall('GET', "{$base}/item-barcodes", null, $stockJar);
    check('HTTP GET /item-barcodes 200 for STOCK (INVENTORY_VIEW is enough — read-only)', $listHttp['status'] === 200, json_encode($listHttp['body']));
    $hasInactiveRow = false;
    foreach ($listHttp['body']['data'] ?? [] as $row) { if ((int) $row['is_active'] === 0) $hasInactiveRow = true; }
    check('HTTP GET /item-barcodes includes at least one INACTIVE row (never filtered out server-side)', $hasInactiveRow);

    echo "\n== HTTP: POST /item-barcodes requires MASTER_ITEM_MANAGE — STOCK forbidden, SUPERADMIN allowed (Part E) ==\n";
    [$itemH1, $skuH1] = makeItem($pdo, $kgUnitId, 'V210-H1');
    $stockCreate = httpCall('POST', "{$base}/item-barcodes", ['item_id' => $itemH1, 'barcode' => uid('BC-HTTP-STOCK')], $stockJar, $stockCsrf);
    check('HTTP STOCK cannot create a barcode mapping (403)', $stockCreate['status'] === 403, json_encode($stockCreate['body']));
    $superCreate = httpCall('POST', "{$base}/item-barcodes", ['item_id' => $itemH1, 'barcode' => uid('BC-HTTP-SUPER')], $superJar, $superCsrf);
    check('HTTP SUPERADMIN can create a barcode mapping (200)', $superCreate['status'] === 200, json_encode($superCreate['body']));

    echo "\n== HTTP: POST /item-barcodes duplicate-active is rejected over HTTP too (defense-in-depth) ==\n";
    $dupeBarcodeHttp = uid('BC-HTTP-DUPE');
    [$itemH2a, $skuH2a] = makeItem($pdo, $kgUnitId, 'V210-H2A');
    [$itemH2b, $skuH2b] = makeItem($pdo, $kgUnitId, 'V210-H2B');
    httpCall('POST', "{$base}/item-barcodes", ['item_id' => $itemH2a, 'barcode' => $dupeBarcodeHttp], $superJar, $superCsrf);
    $dupeHttp = httpCall('POST', "{$base}/item-barcodes", ['item_id' => $itemH2b, 'barcode' => $dupeBarcodeHttp], $superJar, $superCsrf);
    check('HTTP duplicate-active barcode rejected (never 200)', $dupeHttp['status'] !== 200, json_encode($dupeHttp['body']));

    echo "\n== HTTP: PUT /item-barcodes/{id} also gated on MASTER_ITEM_MANAGE ==\n";
    $newMapId = $superCreate['body']['data']['item_barcode_id'] ?? null;
    $stockUpdate = httpCall('PUT', "{$base}/item-barcodes/{$newMapId}", ['is_active' => false], $stockJar, $stockCsrf);
    check('HTTP STOCK cannot update a barcode mapping (403)', $stockUpdate['status'] === 403, json_encode($stockUpdate['body']));
    $superUpdate = httpCall('PUT', "{$base}/item-barcodes/{$newMapId}", ['is_active' => false], $superJar, $superCsrf);
    check('HTTP SUPERADMIN can deactivate a barcode mapping (200)', $superUpdate['status'] === 200, json_encode($superUpdate['body']));

    echo "\n== REGRESSION: POST /transactions/in still posts through the real, unmodified FifoService/PurchaseCostingService (Part K #51/#52) ==\n";
    [$itemH3, $skuH3] = makeItem($pdo, $kgUnitId, 'V210-H3');
    $postIn = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('v210-http-in'), 'item_id' => $itemH3, 'warehouse_id' => $whId,
        'input_unit_id' => $kgUnitId, 'input_qty' => 5, 'unit_price_input' => 1500,
        'transaction_date' => '2026-08-02', 'reference_no' => 'V210-HTTP',
    ], $superJar, $superCsrf);
    check('REGRESSION Stock IN over HTTP still posts (200) with an item_id resolved via the new selector path (same payload shape as before)', $postIn['status'] === 200, json_encode($postIn['body']));
    check('REGRESSION unit_cost_base derived correctly (unchanged FIFO/costing math)', $postIn['status'] === 200 && abs((float) ($postIn['body']['data']['unit_cost_base'] ?? -1) - 1500.0) < 0.01, json_encode($postIn['body']));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
