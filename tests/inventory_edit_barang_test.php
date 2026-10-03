<?php
declare(strict_types=1);

/**
 * EDIT BARANG — proves the extended PUT /items/{id} route (name/category/
 * supplier/barcode/status/base_unit/unit_conversion/price, all still
 * MASTER_ITEM_MANAGE-gated, unchanged permission architecture) against
 * every rule in this feature's own spec: zero/blank price allowed,
 * negative price rejected, price stored consistently against the base
 * unit, price history append-only with an audit trail, supplier/unit
 * must be selected from the existing master (never auto-created/
 * guessed), and centralized read access for SCM/Cibadak/Karang Tengah is
 * unaffected by any of it.
 *
 * Usage: php tests/inventory_edit_barang_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
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
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

// Safety: this feature touches ONLY items/item_unit_conversions/
// item_price_history/categories/suppliers/audit_logs via the UI path —
// grep-confirm the route handler never references stock_opname_*/
// inventory_batches/inventory_transactions.
$indexSource = file_get_contents(__DIR__ . '/../public/index.php');
preg_match("/'PUT \/items\/\{id\}' => function.*?\n    \},\n/s", $indexSource, $routeMatch);
$routeSource = $routeMatch[0] ?? '';
check('setup: PUT /items/{id} route body was located in public/index.php', $routeSource !== '');
check('SAFETY: Edit Barang route has NO reference to any stock_opname_* table', !(bool) preg_match('/stock_opname/i', $routeSource));
check('SAFETY: Edit Barang route has NO reference to inventory_batches/inventory_transactions', !(bool) preg_match('/inventory_(batches|transactions)/i', $routeSource));

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$gramUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('EB-SCM','Gudang Besar / SCM','MAIN',1)")->execute();
$scmWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('EB-CIBADAK','Gudang Transit Cibadak','TRANSIT',1)")->execute();
$cibadakWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('EB-KARANGTENGAH','Gudang Transit Karang Tengah','TRANSIT',1)")->execute();
$karangTengahWhId = (int) $pdo->lastInsertId();

function ebMakeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = uid($tag);
    $pass = 'EditBarang' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$superUser = ebMakeUser($pdo, 'ebsuper', $superRoleId);
$scmStockUser = ebMakeUser($pdo, 'ebscm', $stockRoleId, $scmWhId);
$cibadakUser = ebMakeUser($pdo, 'ebcibadak', $stockRoleId, $cibadakWhId);
$karangTengahUser = ebMakeUser($pdo, 'ebkarangtengah', $stockRoleId, $karangTengahWhId);
$viewerUser = ebMakeUser($pdo, 'ebviewer', $viewerRoleId);

$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('EBSUP'), 'n' => 'Supplier Lama']);
$existingSupplierId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('EBCAT'), 'n' => 'Kategori Lama']);
$existingCategoryId = (int) $pdo->lastInsertId();

// Fixture item with zero/blank price — exactly the population this
// feature exists to make editable (e.g. the 130 items the centralized
// import left with missing price, including an RM-SP-26-027-style case).
$sku = uid('EB-ITEM');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, status) VALUES (:sku, :name, :unit, :status)')
    ->execute(['sku' => $sku, 'name' => 'Item Belum Ada Harga', 'unit' => $gramUnitId, 'status' => 'ACTIVE']);
$itemId = (int) $pdo->lastInsertId();
Database::transaction(fn (PDO $tx) => UnitConversionService::openNewVersion($tx, $itemId, $gramUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity'));

$port = 8900 + random_int(2400, 2799);
$docRoot = __DIR__ . '/../public';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, __DIR__ . '/..');
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start php -S\n");
    exit(1);
}
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
    if ($res !== false && $err === 0) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, "Server did not become ready\n");
    proc_terminate($process);
    exit(1);
}

function ebHttp(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) {
        $headers[] = "X-CSRF-Token: {$csrfToken}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}
function ebLogin(string $base, string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ebcookie_');
    $login = ebHttp('POST', "{$base}/auth/login", ['username' => $username, 'password' => $password], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? null;
    return ['jar' => $jar, 'csrf' => $csrf];
}

try {
    $superSess = ebLogin($base, $superUser['username'], $superUser['password']);
    $scmSess = ebLogin($base, $scmStockUser['username'], $scmStockUser['password']);
    $cibadakSess = ebLogin($base, $cibadakUser['username'], $cibadakUser['password']);
    $karangTengahSess = ebLogin($base, $karangTengahUser['username'], $karangTengahUser['password']);
    $viewerSess = ebLogin($base, $viewerUser['username'], $viewerUser['password']);

    // ---- 8. unauthorized user (STOCK — no MASTER_ITEM_MANAGE) cannot edit ----
    $unauthorizedEdit = ebHttp('PUT', "{$base}/items/{$itemId}", ['name' => 'Harus Ditolak'], $scmSess['jar'], $scmSess['csrf']);
    check('8. STOCK user (no MASTER_ITEM_MANAGE) is FORBIDDEN from editing an item', $unauthorizedEdit['status'] === 403, (string) $unauthorizedEdit['status']);
    $viewerEdit = ebHttp('PUT', "{$base}/items/{$itemId}", ['name' => 'Harus Ditolak Juga'], $viewerSess['jar'], $viewerSess['csrf']);
    check('8b. VIEWER user is FORBIDDEN from editing an item', $viewerEdit['status'] === 403, (string) $viewerEdit['status']);
    $unchangedAfterForbidden = $pdo->prepare('SELECT name FROM items WHERE id = :id');
    $unchangedAfterForbidden->execute(['id' => $itemId]);
    check('8c. the item name was NOT changed by either forbidden attempt', $unchangedAfterForbidden->fetchColumn() === 'Item Belum Ada Harga');

    // ---- 2. editing item name (9. SCM/SUPERADMIN authorized user CAN edit) ----
    $renameResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['name' => 'Item Sudah Diberi Nama Baru'], $superSess['jar'], $superSess['csrf']);
    check('2. SUPERADMIN can edit item name — 200 OK', $renameResp['status'] === 200, (string) $renameResp['status']);
    check('9. SCM-authority (SUPERADMIN) user CAN edit (MASTER_ITEM_MANAGE granted)', $renameResp['status'] === 200);
    $nameAfter = $pdo->prepare('SELECT name FROM items WHERE id = :id');
    $nameAfter->execute(['id' => $itemId]);
    check('2b. name actually persisted', $nameAfter->fetchColumn() === 'Item Sudah Diberi Nama Baru');

    // ---- editing supplier (must be from existing master; blank allowed) ----
    $supplierEditResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['default_supplier_id' => $existingSupplierId], $superSess['jar'], $superSess['csrf']);
    check('supplier edit (valid existing supplier) succeeds — 200', $supplierEditResp['status'] === 200, (string) $supplierEditResp['status']);
    $supplierAfter = $pdo->prepare('SELECT default_supplier_id FROM items WHERE id = :id');
    $supplierAfter->execute(['id' => $itemId]);
    check('supplier edit persisted to the correct existing supplier id', (int) $supplierAfter->fetchColumn() === $existingSupplierId);

    $badSupplierResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['default_supplier_id' => 999999], $superSess['jar'], $superSess['csrf']);
    check('supplier edit with a NON-EXISTENT supplier id is rejected (422), never silently accepted', $badSupplierResp['status'] === 422, (string) $badSupplierResp['status']);

    $blankSupplierResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['default_supplier_id' => null], $superSess['jar'], $superSess['csrf']);
    check('supplier can be explicitly cleared back to blank', $blankSupplierResp['status'] === 200);
    $supplierAfterBlank = $pdo->prepare('SELECT default_supplier_id FROM items WHERE id = :id');
    $supplierAfterBlank->execute(['id' => $itemId]);
    check('blank supplier persisted as NULL', $supplierAfterBlank->fetchColumn() === null);

    // ---- 4. zero price allowed ----
    $priceCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM item_price_history WHERE item_id = {$itemId}")->fetchColumn();
    $zeroPriceResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['price' => ['unit_id' => $gramUnitId, 'price_per_unit' => 0]], $superSess['jar'], $superSess['csrf']);
    check('4. [MANDATORY] zero price is accepted — 200 OK, not rejected', $zeroPriceResp['status'] === 200, (string) $zeroPriceResp['status']);
    $latestAfterZero = $pdo->prepare('SELECT unit_cost_base FROM item_price_history WHERE item_id = :id ORDER BY id DESC LIMIT 1');
    $latestAfterZero->execute(['id' => $itemId]);
    check('4b. the stored unit_cost_base is exactly 0 (not null, not rejected)', (float) $latestAfterZero->fetchColumn() === 0.0);

    // ---- 5. negative price rejected ----
    $negativePriceResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['price' => ['unit_id' => $gramUnitId, 'price_per_unit' => -5]], $superSess['jar'], $superSess['csrf']);
    check('5. [MANDATORY] negative price is REJECTED — 422, never accepted', $negativePriceResp['status'] === 422, (string) $negativePriceResp['status']);
    $priceCountAfterNegativeAttempt = (int) $pdo->query("SELECT COUNT(*) FROM item_price_history WHERE item_id = {$itemId}")->fetchColumn();
    check('5b. the rejected negative price created NO new item_price_history row', $priceCountAfterNegativeAttempt === $priceCountBefore + 1, "before={$priceCountBefore} after_zero_and_rejected_negative={$priceCountAfterNegativeAttempt}");

    // ---- price must never be auto-converted without a valid conversion ----
    $unknownUnitId = $pcsUnitId; // this item has NO open conversion for PCS yet
    $noConversionPriceResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['price' => ['unit_id' => $unknownUnitId, 'price_per_unit' => 1000]], $superSess['jar'], $superSess['csrf']);
    check('price for a unit with NO existing conversion is rejected, never auto-converted/guessed', $noConversionPriceResp['status'] === 422, (string) $noConversionPriceResp['status']);

    // ---- editing unit conversion, then a real price-per-purchase-unit edit ----
    $addConvResp = ebHttp('PUT', "{$base}/items/{$itemId}", [
        'unit_conversion' => ['unit_id' => $pcsUnitId, 'conversion_to_base' => 25.0, 'is_purchase_default' => true],
    ], $superSess['jar'], $superSess['csrf']);
    check('unit_conversion edit (new valid purchase unit, factor=25) succeeds — 200', $addConvResp['status'] === 200, (string) $addConvResp['status']);
    $convRow = $pdo->prepare('SELECT conversion_to_base FROM item_unit_conversions WHERE item_id = :id AND unit_id = :u AND valid_to IS NULL');
    $convRow->execute(['id' => $itemId, 'u' => $pcsUnitId]);
    check('unit_conversion persisted with the correct factor', abs((float) $convRow->fetchColumn() - 25.0) < 0.0001);

    $badConvResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['unit_conversion' => ['unit_id' => $pcsUnitId, 'conversion_to_base' => -1]], $superSess['jar'], $superSess['csrf']);
    check('unit_conversion with factor <= 0 is REJECTED — 422', $badConvResp['status'] === 422, (string) $badConvResp['status']);

    // ---- 3/4/6. editing price via the now-valid PCS unit; 6. price history is append-only ----
    $priceCountBeforePcs = (int) $pdo->query("SELECT COUNT(*) FROM item_price_history WHERE item_id = {$itemId}")->fetchColumn();
    $pcsPriceResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['price' => ['unit_id' => $pcsUnitId, 'price_per_unit' => 1325]], $superSess['jar'], $superSess['csrf']);
    check('3. editing price via a valid purchase unit (PCS, factor=25) succeeds — 200', $pcsPriceResp['status'] === 200, (string) $pcsPriceResp['status']);
    $latestAfterPcs = $pdo->prepare('SELECT unit_cost_base, price_per_unit, unit_id FROM item_price_history WHERE item_id = :id ORDER BY id DESC LIMIT 1');
    $latestAfterPcs->execute(['id' => $itemId]);
    $latestRow = $latestAfterPcs->fetch();
    check('3b. unit_cost_base = 1325 / 25 = 53 (stored consistently against the BASE unit, rule 4)', abs((float) $latestRow['unit_cost_base'] - 53.0) < 0.0001, (string) $latestRow['unit_cost_base']);
    $priceCountAfterPcs = (int) $pdo->query("SELECT COUNT(*) FROM item_price_history WHERE item_id = {$itemId}")->fetchColumn();
    check('6. [MANDATORY] price history is APPEND-ONLY — a new row was added, none deleted/overwritten', $priceCountAfterPcs === $priceCountBeforePcs + 1, "before={$priceCountBeforePcs} after={$priceCountAfterPcs}");
    $firstPriceRowStillExists = (int) $pdo->query("SELECT COUNT(*) FROM item_price_history WHERE item_id = {$itemId} AND unit_cost_base = 0")->fetchColumn();
    check('6b. the earlier zero-price row is STILL THERE, untouched (never overwritten)', $firstPriceRowStillExists === 1);

    // ---- 7. audit log created for the price edit, with old/new price ----
    $priceAudit = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type='items' AND entity_id = :id AND action_code = 'ITEM_PRICE_UPDATE' ORDER BY id DESC LIMIT 1");
    $priceAudit->execute(['id' => $itemId]);
    $priceAuditRow = $priceAudit->fetch();
    check('7. [MANDATORY] an ITEM_PRICE_UPDATE audit_logs row was created for the price edit', $priceAuditRow !== false);
    if ($priceAuditRow !== false) {
        $beforeJson = json_decode($priceAuditRow['before_data'], true);
        $afterJson = json_decode($priceAuditRow['after_data'], true);
        check('7b. audit before_data records the OLD unit_cost_base (0)', abs((float) ($beforeJson['unit_cost_base'] ?? -1) - 0.0) < 0.0001, json_encode($beforeJson));
        check('7c. audit after_data records the NEW unit_cost_base (53)', abs((float) ($afterJson['unit_cost_base'] ?? -1) - 53.0) < 0.0001, json_encode($afterJson));
        check('7d. audit records the actor (SUPERADMIN user id)', (int) $priceAuditRow['user_id'] === (int) $superUser['id']);
        check('7e. audit records a timestamp', $priceAuditRow['created_at'] !== null);
    }

    // ---- base unit editing: locked guard ----
    $changeBaseUnitResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['base_unit_id' => $pcsUnitId], $superSess['jar'], $superSess['csrf']);
    check('base_unit_id can be changed while the item is NOT locked (no posted transactions)', $changeBaseUnitResp['status'] === 200, (string) $changeBaseUnitResp['status']);
    // revert for clarity of subsequent assertions
    ebHttp('PUT', "{$base}/items/{$itemId}", ['base_unit_id' => $gramUnitId], $superSess['jar'], $superSess['csrf']);

    // Lock the item (same locked_at flag a real posted transaction sets
    // via UnitConversionService::lockItemIfNeeded()) and confirm
    // changeBaseUnit is now refused with a friendly error.
    $pdo->prepare('UPDATE items SET locked_at = NOW() WHERE id = :id')->execute(['id' => $itemId]);
    $lockedBaseUnitResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['base_unit_id' => $pcsUnitId], $superSess['jar'], $superSess['csrf']);
    check('base_unit_id change is REFUSED once the item is locked (posted transaction) — 422 ITEM_LOCKED, not 500', $lockedBaseUnitResp['status'] === 422, (string) $lockedBaseUnitResp['status'] . ' ' . json_encode($lockedBaseUnitResp['body']));
    check('the ITEM_LOCKED error code is surfaced, not a generic 500', ($lockedBaseUnitResp['body']['error']['code'] ?? '') === 'ITEM_LOCKED', json_encode($lockedBaseUnitResp['body']['error'] ?? null));
    // Editing name/price while locked must still work — only the
    // structural base-unit change is refused.
    $stillEditableResp = ebHttp('PUT', "{$base}/items/{$itemId}", ['name' => 'Tetap Bisa Diedit Walau Terkunci'], $superSess['jar'], $superSess['csrf']);
    check('name/price editing still works on a LOCKED item — only base_unit_id is refused', $stillEditableResp['status'] === 200, (string) $stillEditableResp['status']);

    // ---- 10. Transit users can still VIEW the centralized master ----
    $cibadakItems = ebHttp('GET', "{$base}/items", null, $cibadakSess['jar']);
    $karangTengahItems = ebHttp('GET', "{$base}/items", null, $karangTengahSess['jar']);
    $scmItems = ebHttp('GET', "{$base}/items", null, $scmSess['jar']);
    check('10. Cibadak STOCK user can still GET /items (centralized read access unaffected) — 200', $cibadakItems['status'] === 200, (string) $cibadakItems['status']);
    check('10b. Karang Tengah STOCK user can still GET /items — 200', $karangTengahItems['status'] === 200, (string) $karangTengahItems['status']);
    check(
        '10c. SCM/Cibadak/Karang Tengah still see the SAME item count (no per-warehouse duplication introduced)',
        count($scmItems['body']['data'] ?? []) === count($cibadakItems['body']['data'] ?? []) && count($cibadakItems['body']['data'] ?? []) === count($karangTengahItems['body']['data'] ?? [])
    );
    // And Cibadak/Karang Tengah still cannot EDIT (view-only, unchanged).
    $cibadakEditAttempt = ebHttp('PUT', "{$base}/items/{$itemId}", ['name' => 'Cibadak Tidak Boleh Edit'], $cibadakSess['jar'], $cibadakSess['csrf']);
    check('10d. Cibadak STOCK user is still FORBIDDEN from editing (view-only preserved)', $cibadakEditAttempt['status'] === 403, (string) $cibadakEditAttempt['status']);

    // ---- GET /units (new reference-data endpoint) is reachable by any authenticated role ----
    $unitsListResp = ebHttp('GET', "{$base}/units", null, $cibadakSess['jar']);
    check('GET /units (new Base Unit/Unit Conversion dropdown source) works for a Transit STOCK user too', $unitsListResp['status'] === 200 && count($unitsListResp['body']['data'] ?? []) > 0, (string) $unitsListResp['status']);
} finally {
    proc_terminate($process);
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n{$passed} / {$total} PASSED\n";
exit($passed === $total ? 0 : 1);
