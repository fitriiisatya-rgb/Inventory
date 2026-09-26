<?php
declare(strict_types=1);

/**
 * FINAL FUNCTIONAL GAP CLOSURE — two hardening fixes found by the final
 * cross-phase release gate, closed here without a new phase/migration:
 *
 *   GAP 1 — InventoryService::currentStockAllWarehouses() (the "Semua
 *   Gudang" breakdown) used to only return warehouses that already held
 *   an inventory_batches row for the selected item, silently omitting an
 *   active warehouse that has genuinely never stocked it. Fixed to start
 *   from `warehouses` (is_active=1) and LEFT JOIN the item's batch
 *   aggregate, so every active/live warehouse appears (qty=0, HABIS,
 *   minimum still resolved) — required for real per-warehouse minimum
 *   monitoring. Karang Tengah (is_active=0) still never appears. No
 *   inventory_batches row is ever created as a side effect.
 *
 *   GAP 2 — ImportLiveTransactionService (Live Transaction Import) did
 *   not support bakery_destination_code on OUT rows, unlike manual
 *   Transaksi Keluar (FifoService::postOut()'s existing, optional
 *   bakery_destination_id). Extended to resolve
 *   bakery_destination_code -> the SAME bakery_destinations.id manual
 *   posting resolves to, and pass it into the SAME FifoService::postOut()
 *   call — never a parallel write path, so History/Trace/Distribusi per
 *   Bakery see an imported OUT exactly like a manually-posted one. Unlike
 *   supplier_code/division_code (soft WARNING), an unresolved
 *   bakery_destination_code on an OUT row that named one is a hard ERROR.
 *   IN rows never read this column at all (mirrors the DB-level
 *   chk_tx_bakery_destination_out_only constraint).
 *
 * No migration — both fixes reuse existing schema completely unmodified.
 *
 * Usage: php tests/inventory_final_hardening_test.php
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
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/PurchaseCostingService.php';
require_once __DIR__ . '/../services/PurchaseCostingGateway.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';
require_once __DIR__ . '/../services/ImportLiveTransactionService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\StockPolicyService;
use App\Services\UnitConversionService;
use App\Services\TransactionHistoryService;
use App\Services\TraceService;
use App\Services\ImportLiveTransactionService;
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
    $path = tempnam(sys_get_temp_dir(), 'hard_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('hardsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Hardening Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$scmCode = uid('H-SCM');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $scmCode, 'n' => 'Hardening SCM']);
$scmId = (int) $pdo->lastInsertId();
$cibadakCode = uid('H-CIBADAK');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $cibadakCode, 'n' => 'Hardening CIBADAK (never stocked)']);
$cibadakId = (int) $pdo->lastInsertId();
$ktCode = uid('H-KT');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 0)')->execute(['c' => $ktCode, 'n' => 'Hardening Pending Cutover']);
$ktId = (int) $pdo->lastInsertId();

$bakeryCode = uid('H-BAKERY');
$pdo->prepare('INSERT INTO bakery_destinations (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => $bakeryCode, 'n' => 'Hardening Bakery']);
$bakeryId = (int) $pdo->lastInsertId();
$inactiveBakeryCode = uid('H-BAKERY-INACTIVE');
$pdo->prepare('INSERT INTO bakery_destinations (code, name, is_active) VALUES (:c,:n,0)')->execute(['c' => $inactiveBakeryCode, 'n' => 'Hardening Bakery Inactive']);

function makeItem(PDO $pdo, int $unitId, string $tag, float $globalMinimum = 10.0): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, :min, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'min' => $globalMinimum, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $price, int $by, int $kgUnitId): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('hard-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $kgUnitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'hardening', 'transaction_type' => 'OPENING',
    ]));
}

// ============================================================
// GAP 1 — SECTION A: All-Warehouse breakdown includes zero-stock active warehouses.
// ============================================================
echo "== A1: active warehouse with NO batch appears with stock 0 (never omitted) ==\n";
[$itemA1, $skuA1] = makeItem($pdo, $kgUnitId, 'H-A1', 10.0);
postIn($pdo, $itemA1, $scmId, 80, 1000, $adminUserId, $kgUnitId);
// CIBADAK deliberately never touched by this item.
$batchCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$breakdownA1 = InventoryService::currentStockAllWarehouses($pdo, $itemA1);
$byWhA1 = [];
foreach ($breakdownA1['by_warehouse'] as $r) { $byWhA1[(int) $r['warehouse_id']] = $r; }
check('A1 SCM (has stock) appears', isset($byWhA1[$scmId]), json_encode(array_keys($byWhA1)));
check('A1 CIBADAK (NEVER stocked, no batch row at all) still appears', isset($byWhA1[$cibadakId]), json_encode(array_keys($byWhA1)));
check('A1 CIBADAK shows qty_base = 0, value = 0', isset($byWhA1[$cibadakId]) && approx($byWhA1[$cibadakId]['qty_base'], 0.0) && approx($byWhA1[$cibadakId]['value'], 0.0), json_encode($byWhA1[$cibadakId] ?? null));

echo "\n== A2: warehouse minimum override applies to the zero-stock warehouse ==\n";
StockPolicyService::upsert($pdo, ['item_id' => $itemA1, 'warehouse_id' => $cibadakId, 'minimum_stock' => 20.0, 'updated_by' => $adminUserId]);
$breakdownA2 = InventoryService::currentStockAllWarehouses($pdo, $itemA1);
$byWhA2 = [];
foreach ($breakdownA2['by_warehouse'] as $r) { $byWhA2[(int) $r['warehouse_id']] = $r; }
check('A2 CIBADAK minimum_stock = 20 (its own override, not the item\'s global 10)', approx($byWhA2[$cibadakId]['minimum_stock'], 20.0), (string) $byWhA2[$cibadakId]['minimum_stock']);

echo "\n== A3: fallback global minimum applies when no override exists ==\n";
[$itemA3, $skuA3] = makeItem($pdo, $kgUnitId, 'H-A3', 15.0);
// No policy row anywhere for this item — CIBADAK must fall back to items.minimum_stock (15).
$breakdownA3 = InventoryService::currentStockAllWarehouses($pdo, $itemA3);
$byWhA3 = [];
foreach ($breakdownA3['by_warehouse'] as $r) { $byWhA3[(int) $r['warehouse_id']] = $r; }
check('A3 CIBADAK (never stocked, no policy override) falls back to items.minimum_stock (15)', approx($byWhA3[$cibadakId]['minimum_stock'], 15.0), (string) $byWhA3[$cibadakId]['minimum_stock']);

echo "\n== A4: status = HABIS for the zero-stock warehouse ==\n";
check('A4 CIBADAK report_status = HABIS (qty 0, regardless of minimum)', $byWhA3[$cibadakId]['report_status'] === 'HABIS', (string) $byWhA3[$cibadakId]['report_status']);

echo "\n== A5: inactive (Karang-Tengah-style) warehouse still excluded ==\n";
$whIdsA1 = array_keys($byWhA1);
check('A5 inactive warehouse never appears even though it is a real ACTIVE-warehouse-list scenario now', !in_array($ktId, $whIdsA1, true), json_encode($whIdsA1));
check('A5 breakdown row count == exactly the 2 active warehouses (SCM, CIBADAK), never the inactive 3rd', count($whIdsA1) === 2, json_encode($whIdsA1));

echo "\n== A6: no inventory_batches row created as a side effect of the breakdown read ==\n";
$batchCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
check('A6 inventory_batches row count unchanged (breakdown is read-only, never fabricates a batch to show zero stock)', $batchCountBefore === $batchCountAfter, "before={$batchCountBefore} after={$batchCountAfter}");

// ============================================================
// GAP 2 — SECTION B: Live Import OUT bakery destination.
// ============================================================
echo "\n== B1: valid OUT with bakery destination commits and persists correctly ==\n";
[$itemB1, $skuB1] = makeItem($pdo, $kgUnitId, 'H-B1');
postIn($pdo, $itemB1, $scmId, 100, 1000, $adminUserId, $kgUnitId);
$csvB1 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-05,OUT,{$scmCode},{$skuB1},20,KG,,,,{$bakeryCode},H-B1-OUT-REF,bakery distribution\n"
);
$batchB1 = ImportLiveTransactionService::stage($pdo, $csvB1, 'b1.csv', $adminUserId);
check('B1 row staged VALID', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchB1}")->fetchColumn());
$resultB1 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB1, $adminUserId));
check('B1 commit imported 1 row', $resultB1['imported'] === 1, json_encode($resultB1));
$txB1 = $pdo->query("SELECT * FROM inventory_transactions WHERE reference_no='H-B1-OUT-REF'")->fetch(PDO::FETCH_ASSOC);
check('B1 transaction has the correct bakery_destination_id (same id manual posting would resolve to)', $txB1 && (int) $txB1['bakery_destination_id'] === $bakeryId, json_encode($txB1));

echo "\n== B2: imported OUT appears in Distribusi per Bakery (TransactionHistoryService) ==\n";
$bakeryList = TransactionHistoryService::list($pdo, ['bakery_destination_id' => $bakeryId, 'page' => 1, 'per_page' => 50]);
$foundInBakeryReport = false;
foreach ($bakeryList['rows'] as $row) {
    if (($row['reference_no'] ?? null) === 'H-B1-OUT-REF') { $foundInBakeryReport = true; break; }
}
check('B2 the live-imported OUT appears when filtering by this bakery_destination_id', $foundInBakeryReport, json_encode(array_column($bakeryList['rows'], 'reference_no')));

echo "\n== B3: History/Trace shows the correct destination ==\n";
$historyRows = TransactionHistoryService::list($pdo, ['q' => 'H-B1-OUT-REF', 'page' => 1, 'per_page' => 10]);
$historyRow = $historyRows['rows'][0] ?? null;
check('B3 History row carries the bakery_destination name', $historyRow && ($historyRow['bakery_destination']['id'] ?? null) === $bakeryId, json_encode($historyRow['bakery_destination'] ?? null));
$trace = TraceService::transactionTrace($pdo, (int) $txB1['id']);
check('B3 TraceDrawer (TraceService::transactionTrace) shows the same bakery destination', (int) ($trace['transaction']['bakery_destination_id'] ?? 0) === $bakeryId, json_encode($trace['transaction'] ?? null));

echo "\n== B4: invalid/unknown bakery_destination_code rejected at staging ==\n";
$csvB4 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-06,OUT,{$scmCode},{$skuB1},5,KG,,,,NOSUCHBAKERY-XYZ,H-B4-OUT-REF,\n"
);
$batchB4 = ImportLiveTransactionService::stage($pdo, $csvB4, 'b4.csv', $adminUserId);
check('B4 row staged as ERROR (unknown bakery_destination_code)', 1 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchB4}")->fetchColumn());
$rejectedB4 = false;
try { Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB4, $adminUserId)); } catch (ImportValidationException $e) { $rejectedB4 = true; }
check('B4 commit rejected outright', $rejectedB4);

// Also confirm an INACTIVE (but existing) bakery code is treated the same as unknown.
$csvB4b = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-06,OUT,{$scmCode},{$skuB1},5,KG,,,,{$inactiveBakeryCode},H-B4B-OUT-REF,\n"
);
$batchB4b = ImportLiveTransactionService::stage($pdo, $csvB4b, 'b4b.csv', $adminUserId);
check('B4b row staged as ERROR (inactive bakery_destination_code, not just unknown)', 1 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchB4b}")->fetchColumn());

echo "\n== B5: IN row ignores an (inappropriate) bakery_destination_code field ==\n";
[$itemB5, $skuB5] = makeItem($pdo, $kgUnitId, 'H-B5');
$csvB5 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-07,IN,{$scmCode},{$skuB5},10,KG,1000,,,{$bakeryCode},H-B5-IN-REF,bakery code present but IN never uses it\n"
);
$batchB5 = ImportLiveTransactionService::stage($pdo, $csvB5, 'b5.csv', $adminUserId);
check('B5 IN row with a bakery_destination_code present still stages VALID (field simply not applicable, never an error)', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchB5}")->fetchColumn());
$resultB5 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB5, $adminUserId));
check('B5 IN row commits successfully', $resultB5['imported'] === 1, json_encode($resultB5));
$txB5 = $pdo->query("SELECT * FROM inventory_transactions WHERE reference_no='H-B5-IN-REF'")->fetch(PDO::FETCH_ASSOC);
check('B5 the posted IN transaction has bakery_destination_id = NULL (ignored, never forced/violates the OUT-only DB constraint)', $txB5 && $txB5['bakery_destination_id'] === null, json_encode($txB5));

echo "\n== B7: duplicate/idempotency remains intact with a bakery-carrying row ==\n";
// (numbered B7 per the requested test list; B6 below is the permission check)
[$itemB7, $skuB7] = makeItem($pdo, $kgUnitId, 'H-B7');
postIn($pdo, $itemB7, $scmId, 50, 1000, $adminUserId, $kgUnitId);
$csvB7Content = "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-08,OUT,{$scmCode},{$skuB7},5,KG,,,,{$bakeryCode},H-B7-OUT-REF,\n";
$csvB7a = tmpCsv($csvB7Content);
$batchB7a = ImportLiveTransactionService::stage($pdo, $csvB7a, 'b7.csv', $adminUserId);
$firstB7 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB7a, $adminUserId));
$secondB7 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB7a, $adminUserId));
check('B7 double commit() is idempotent (flagged idempotent_replay)', !empty($secondB7['idempotent_replay']), json_encode($secondB7));
$csvB7b = tmpCsv($csvB7Content);
$rejectedB7Dup = false;
try { ImportLiveTransactionService::stage($pdo, $csvB7b, 'b7-reupload.csv', $adminUserId); } catch (ValidationException $e) { $rejectedB7Dup = true; }
check('B7 re-uploading the identical already-committed file (with bakery field) is still rejected at stage()', $rejectedB7Dup);
$txCountB7 = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no='H-B7-OUT-REF'")->fetchColumn();
check('B7 only ONE real transaction exists despite the double-commit attempt', $txCountB7 === 1, "count={$txCountB7}");

echo "\n== B8: atomic rollback still works when a bakery-carrying row is mixed with a failing row ==\n";
[$itemB8, $skuB8] = makeItem($pdo, $kgUnitId, 'H-B8');
postIn($pdo, $itemB8, $scmId, 10, 1000, $adminUserId, $kgUnitId);
$csvB8 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,bakery_destination_code,reference_no,notes\n"
    . "2026-08-09,OUT,{$scmCode},{$skuB8},5,KG,,,,{$bakeryCode},H-B8-OUT-VALID,\n"
    . "2026-08-10,OUT,{$scmCode},{$skuB8},99999,KG,,,,{$bakeryCode},H-B8-OUT-FAIL,\n"
);
$batchB8 = ImportLiveTransactionService::stage($pdo, $csvB8, 'b8.csv', $adminUserId);
$rejectedB8 = false;
try { Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchB8, $adminUserId)); } catch (\Throwable $e) { $rejectedB8 = true; }
check('B8 commit throws (insufficient stock on the second row)', $rejectedB8);
$txCountB8 = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no IN ('H-B8-OUT-VALID','H-B8-OUT-FAIL')")->fetchColumn();
check('B8 NEITHER row committed — the valid bakery-carrying OUT was rolled back with the failing one (no partial import)', $txCountB8 === 0, "count={$txCountB8}");

// ============================================================
// Section summary before starting the HTTP server.
// ============================================================
$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Direct-service sections: {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — A7 (Gap 1: STOCK cannot see another warehouse) + B6 (Gap 2: warehouse/import permission enforced).
// ============================================================
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
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

try {
    $superUser = uid('hardsuper'); $superPass = 'HardSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('hardstock'); $stockPass = 'HardStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $scmId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    echo "\n== A7: STOCK role still cannot see another warehouse via the (now zero-stock-inclusive) breakdown ==\n";
    $ownScope = httpCall('GET', "{$base}/inventory/current/{$skuA1}?warehouse_id={$scmId}", null, $stockJar);
    check('A7 STOCK can read their OWN warehouse scope', $ownScope['status'] === 200, json_encode($ownScope['body']));
    $otherScope = httpCall('GET', "{$base}/inventory/current/{$skuA1}?warehouse_id={$cibadakId}", null, $stockJar);
    check('A7 STOCK is FORBIDDEN from reading a DIFFERENT warehouse\'s scope, even one with zero stock', $otherScope['status'] === 403, json_encode($otherScope['body']));
    $noParamScope = httpCall('GET', "{$base}/inventory/current/{$skuA1}", null, $stockJar);
    check('A7 STOCK with no warehouse_id is forced to their own single-warehouse view, never the all-warehouse breakdown', $noParamScope['status'] === 200 && !isset($noParamScope['body']['data']['by_warehouse']), json_encode($noParamScope['body']));

    echo "\n== B6: warehouse/import permission remains enforced for bakery-carrying live imports ==\n";
    $stockStage = httpCall('POST', "{$base}/import/live-transaction/stage", ['file_path' => '/nonexistent', 'file_name' => 'x.csv'], $stockJar, $stockCsrf);
    check('B6 STOCK role is forbidden from live-transaction import (403, IMPORT_MANAGE required) even with a valid bakery field in the file', $stockStage['status'] === 403, json_encode($stockStage['body']));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
