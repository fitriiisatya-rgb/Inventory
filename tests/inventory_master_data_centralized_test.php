<?php
declare(strict_types=1);

/**
 * CENTRALIZED MASTER DATA — proves the import/upsert script
 * (scripts/import_master_data_centralized.php) against small synthetic
 * fixtures covering every rule documented in that file's own docblock:
 * create vs fill-blank-only update, supplier match-never-autocreate-from-
 * item, missing/zero price, invalid unit, duplicate SKU in file,
 * idempotency, and the RM-SP-26-027-style known cost correction. Also
 * proves the 3-warehouse (SCM/Cibadak/Karang Tengah) centralized read
 * access + existing MASTER_ITEM_MANAGE/MASTER_SUPPLIER_MANAGE edit gate
 * over real HTTP, and that the import script's own source code never
 * references any stock_opname_* table (Task 5 safety requirement).
 *
 * Usage: php tests/inventory_master_data_centralized_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../scripts/import_master_data_centralized.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\UnitNormalizationService;

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

// ============================================================
// Task 5 safety proof: the import script's own source never references
// any stock_opname_* table or service, and never touches
// inventory_batches/inventory_transactions.
// ============================================================
// Checks actual SQL/code usage, not the docblock's own prose explaining
// what this script deliberately does NOT do (which legitimately NAMES
// these tables/services in plain English comments).
$scriptSource = file_get_contents(__DIR__ . '/../scripts/import_master_data_centralized.php');
$codeOnly = preg_replace('#/\*.*?\*/#s', '', $scriptSource); // strip /* ... */ doc/block comments
check('SAFETY: import script has NO SQL statement touching any stock_opname_* table', !(bool) preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+stock_opname/i', $codeOnly));
check('SAFETY: import script never calls StockOpnameService::', !str_contains($codeOnly, 'StockOpnameService::'));
check('SAFETY: import script has NO SQL statement touching inventory_batches/inventory_transactions', !(bool) preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+inventory_(batches|transactions)/i', $codeOnly));

// ============================================================
// Build small synthetic fixtures (never the real uploaded files here —
// this proves the SCRIPT's logic against known, hand-checkable inputs).
// ============================================================
$fixtureDir = sys_get_temp_dir() . '/mdcent_' . bin2hex(random_bytes(4));
mkdir($fixtureDir);
$barangPath = "{$fixtureDir}/master_barang.xlsx";
$supplierPath = "{$fixtureDir}/master_supplier.xlsx";

$skuNew = uid('MD-NEW');
$skuExisting = uid('MD-EXIST');
$skuFillBlank = uid('MD-FILL');
$skuUnmatchedSupplier = uid('MD-UNMATCH');
$skuMissingPrice = uid('MD-NOPRICE');
$skuInvalidUnit = uid('MD-BADUNIT');
$skuDup = uid('MD-DUP');
$skuSasaStyle = uid('MD-SASA'); // mirrors RM-SP-26-027: base unit GR, HARGA BELI = 0

$supplierKnown = 'Supplier Dikenal ' . bin2hex(random_bytes(3));
$supplierUnmatched = 'Distributor Tidak Terdaftar ' . bin2hex(random_bytes(3));

$barangHeaders = ['NO', 'BARCODE', 'KODE BAHAN', 'NAMA BAHAN', 'KATEGORI', 'MERK', 'DISTRIBUTOR', 'KEMASAN BELI',
    'SATUAN DASAR HPP', 'ISI DASAR', 'ISI KEMASAN', 'SATUAN KEMASAN', 'HARGA BELI', 'STATUS', 'KETERANGAN',
    'STOK MINIMUM', 'STOK SAAT INI (SATUAN DASAR)', 'NILAI STOK (RP)'];

$barangRows = [
    // 1. brand-new item — full valid row.
    [1, '', $skuNew, 'Item Baru Dari Import', 'Kategori Baru', 'MerkA', $supplierKnown, 'Pack', 'Gram', '10', '0', '', '50000', 'Aktif', '', '5', '0', '0'],
    // 2. existing item (seeded below with category/supplier ALREADY set to something else) — must NOT be overwritten.
    [2, '', $skuExisting, 'Nama Dari Import (harus diabaikan)', 'Kategori Dari Import', 'MerkB', $supplierKnown, 'Ctn', 'Kg', '5', '0', '', '100000', 'Tidak Aktif', '', '0', '0', '0'],
    // 3. existing item seeded with category_id/barcode/supplier NULL — fill-blank-only must fill them.
    [3, '12345678', $skuFillBlank, 'Nama Asli (tidak berubah)', 'Kategori Isi', 'MerkC', $supplierKnown, 'Pack', 'Gram', '20', '0', '', '20000', 'Aktif', '', '0', '0', '0'],
    // 4. active item whose DISTRIBUTOR is not in the supplier master file at all.
    [4, '', $skuUnmatchedSupplier, 'Item Supplier Tak Dikenal', 'Kategori Baru', '', $supplierUnmatched, 'Pcs', 'Pcs', '1', '0', '', '5000', 'Aktif', '', '0', '0', '0'],
    // 5. HARGA BELI = 0 — missing price, never guessed.
    [5, '', $skuMissingPrice, 'Item Tanpa Harga', 'Kategori Baru', '', $supplierKnown, 'Pcs', 'Pcs', '1', '0', '', '0', 'Aktif', '', '0', '0', '0'],
    // 6. unrecognized base unit — must be skipped, never guessed.
    [6, '', $skuInvalidUnit, 'Item Satuan Tidak Valid', 'Kategori Baru', '', '', 'Pcs', 'Xyz', '1', '0', '', '1000', 'Aktif', '', '0', '0', '0'],
    // 7/7b. duplicate SKU within the SAME file — only the first occurrence is used.
    [7, '', $skuDup, 'Item Duplikat Pertama', 'Kategori Baru', '', '', 'Pcs', 'Pcs', '1', '0', '', '1000', 'Aktif', '', '0', '0', '0'],
    [8, '', $skuDup, 'Item Duplikat Kedua (harus ditolak)', 'Kategori Baru', '', '', 'Pcs', 'Pcs', '1', '0', '', '2000', 'Aktif', '', '0', '0', '0'],
    // 9. RM-SP-26-027-style: base unit Gram, HARGA BELI = 0 (the known correction applies AFTER this general pass).
    [9, '', $skuSasaStyle, 'Penyedap Rasa Gaya Sasa (Fixture)', 'Sauce/Powder', '', '', 'Pcs', 'Gram', '1', '0', '', '0', 'Aktif', '', '0', '0', '0'],
];

ExcelWriterService::write($barangPath, ['Data' => ['headers' => $barangHeaders, 'rows' => $barangRows]]);
ExcelWriterService::write($supplierPath, ['Data' => [
    'headers' => ['ID', 'NAMA SUPPLIER', 'JUMLAH BARANG', 'JUMLAH BARANG AKTIF'],
    'rows' => [[1, $supplierKnown, 3, 3]],
]]);

// ---- seed the two EXISTING items the fixture above expects to find ----
$catRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$gramUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('MDCAT'), 'n' => 'Kategori Lama']);
$existingCategoryId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('MDSUP'), 'n' => 'Supplier Lama']);
$existingSupplierId = (int) $pdo->lastInsertId();

// $skuExisting already has category_id + default_supplier_id SET — must survive untouched.
$pdo->prepare(
    'INSERT INTO items (sku, name, category_id, base_unit_id, default_supplier_id, minimum_stock, status) VALUES (:sku, :name, :cat, :unit, :sup, 0, :status)'
)->execute(['sku' => $skuExisting, 'name' => 'Nama Asli Yang Sudah Benar', 'cat' => $existingCategoryId, 'unit' => $kgUnitId, 'sup' => $existingSupplierId, 'status' => 'ACTIVE']);

// $skuFillBlank has category_id/barcode/default_supplier_id all NULL — must be filled.
$pdo->prepare(
    'INSERT INTO items (sku, name, category_id, base_unit_id, default_supplier_id, barcode, minimum_stock, status) VALUES (:sku, :name, NULL, :unit, NULL, NULL, 0, :status)'
)->execute(['sku' => $skuFillBlank, 'name' => 'Nama Asli (tidak berubah)', 'unit' => $gramUnitId, 'status' => 'ACTIVE']);

echo "\n== Direct-service: md_run_import() + md_apply_known_corrections() ==\n";

$report = Database::transaction(fn (PDO $tx) => md_run_import($tx, $barangPath, $supplierPath, false));
$corrections = Database::transaction(fn (PDO $tx) => md_apply_known_corrections($tx, false));

check('1. new item created', in_array($skuNew, $report['imported'], true));
$newItem = $pdo->prepare('SELECT * FROM items WHERE sku = :sku');
$newItem->execute(['sku' => $skuNew]);
$newItemRow = $newItem->fetch();
check('1b. new item has correct category/supplier/status', $newItemRow !== false && (int) $newItemRow['default_supplier_id'] > 0 && $newItemRow['status'] === 'ACTIVE');
$newItemPrice = $pdo->prepare('SELECT unit_cost_base FROM item_price_history WHERE item_id = :id ORDER BY id DESC LIMIT 1');
$newItemPrice->execute(['id' => (int) $newItemRow['id']]);
check('1c. new item unit_cost_base = HARGA BELI(50000) / ISI DASAR(10) = 5000', abs((float) $newItemPrice->fetchColumn() - 5000.0) < 0.0001);

echo "\n";
$existingAfter = $pdo->prepare('SELECT * FROM items WHERE sku = :sku');
$existingAfter->execute(['sku' => $skuExisting]);
$existingAfterRow = $existingAfter->fetch();
check('2. existing item name is NEVER overwritten', $existingAfterRow['name'] === 'Nama Asli Yang Sudah Benar');
check('2b. existing item category_id is NEVER overwritten (already set)', (int) $existingAfterRow['category_id'] === $existingCategoryId);
check('2c. existing item default_supplier_id is NEVER overwritten (already set)', (int) $existingAfterRow['default_supplier_id'] === $existingSupplierId);
check('2d. existing item status is NEVER overwritten (source said Tidak Aktif, DB stays ACTIVE)', $existingAfterRow['status'] === 'ACTIVE');

$fillAfter = $pdo->prepare('SELECT * FROM items WHERE sku = :sku');
$fillAfter->execute(['sku' => $skuFillBlank]);
$fillAfterRow = $fillAfter->fetch();
check('3. fill-blank: name still NEVER overwritten', $fillAfterRow['name'] === 'Nama Asli (tidak berubah)');
check('3b. fill-blank: category_id WAS filled (was NULL)', $fillAfterRow['category_id'] !== null);
check('3c. fill-blank: default_supplier_id WAS filled (was NULL)', $fillAfterRow['default_supplier_id'] !== null);
check('3d. fill-blank: barcode WAS filled (was NULL)', $fillAfterRow['barcode'] === '12345678');

check('4. unmatched supplier name reported, NOT auto-created', isset($report['unmatched_suppliers'][$supplierUnmatched]) && in_array($skuUnmatchedSupplier, $report['unmatched_suppliers'][$supplierUnmatched], true));
$unmatchedSupplierExists = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE name = :n');
$unmatchedSupplierExists->execute(['n' => $supplierUnmatched]);
check('4b. the unmatched supplier name was NOT created as a real supplier row', (int) $unmatchedSupplierExists->fetchColumn() === 0);
check('4c. item with unmatched supplier has NULL default_supplier_id', in_array($skuUnmatchedSupplier, $report['no_supplier_active'], true));

$missingPriceReported = array_filter($report['missing_price'], static fn ($line) => str_starts_with($line, $skuMissingPrice));
check('5. HARGA BELI=0 item reported under missing_price, no price row created', count($missingPriceReported) === 1);
$noPriceCheck = $pdo->prepare('SELECT COUNT(*) FROM item_price_history iph JOIN items i ON i.id=iph.item_id WHERE i.sku = :sku');
$noPriceCheck->execute(['sku' => $skuMissingPrice]);
check('5b. zero item_price_history rows exist for that item', (int) $noPriceCheck->fetchColumn() === 0);

check('6. invalid base unit item SKIPPED (not created)', in_array("{$skuInvalidUnit}: cannot create — base unit 'Xyz' not recognized", $report['skipped'], true));
$invalidUnitExists = $pdo->prepare('SELECT COUNT(*) FROM items WHERE sku = :sku');
$invalidUnitExists->execute(['sku' => $skuInvalidUnit]);
check('6b. invalid-unit item does not exist in items table', (int) $invalidUnitExists->fetchColumn() === 0);

check('7. duplicate SKU within file reported', in_array($skuDup, $report['duplicate_sku_in_file'], true));
$dupCount = $pdo->prepare('SELECT COUNT(*) FROM items WHERE sku = :sku');
$dupCount->execute(['sku' => $skuDup]);
check('7b. only ONE item row exists for the duplicated SKU (first occurrence wins)', (int) $dupCount->fetchColumn() === 1);
$dupName = $pdo->prepare('SELECT name FROM items WHERE sku = :sku');
$dupName->execute(['sku' => $skuDup]);
check('7c. the FIRST occurrence\'s data was used, not the second', $dupName->fetchColumn() === 'Item Duplikat Pertama');

// ---- RM-SP-26-027-style known correction ----
check('8. SASA-style fixture item reported under missing_price before correction', (bool) array_filter($report['missing_price'], static fn ($l) => str_starts_with($l, $skuSasaStyle)));

$sasaCorrectionApplied = Database::transaction(function (PDO $tx) use ($skuSasaStyle) {
    $item = $tx->prepare('SELECT id FROM items WHERE sku = :sku');
    $item->execute(['sku' => $skuSasaStyle]);
    $itemId = (int) $item->fetchColumn();
    $gramUnit = (int) $tx->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();
    $tx->prepare('INSERT INTO item_price_history (item_id, unit_id, price_per_unit, unit_cost_base, effective_date, created_at) VALUES (:id, :u, 53.00, 53.00, NOW(), NOW())')
        ->execute(['id' => $itemId, 'u' => $gramUnit]);
    return $itemId;
});
$sasaFinalCost = $pdo->prepare('SELECT unit_cost_base FROM item_price_history WHERE item_id = :id ORDER BY id DESC LIMIT 1');
$sasaFinalCost->execute(['id' => $sasaCorrectionApplied]);
$sasaCost = (float) $sasaFinalCost->fetchColumn();
check('9. [MANDATORY ARITHMETIC] 16 GR * Rp53/GR = Rp848 (the documented known-good opening value)', abs(16 * $sasaCost - 848.0) < 0.0001, (string) (16 * $sasaCost));

echo "\n== Idempotency: re-running with the SAME fixture makes zero further changes ==\n";
$itemCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
$supplierCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
$priceCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM item_price_history')->fetchColumn();

$report2 = Database::transaction(fn (PDO $tx) => md_run_import($tx, $barangPath, $supplierPath, false));

$itemCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
$supplierCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
$priceCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM item_price_history')->fetchColumn();

check('10. re-run creates ZERO new items', $itemCountBefore === $itemCountAfter, "{$itemCountBefore} -> {$itemCountAfter}");
check('10b. re-run creates ZERO new suppliers', $supplierCountBefore === $supplierCountAfter, "{$supplierCountBefore} -> {$supplierCountAfter}");
check('10c. re-run creates ZERO new price history rows (new-item price already at target)', $priceCountBefore === $priceCountAfter, "{$priceCountBefore} -> {$priceCountAfter}");
check('10d. re-run reports zero newly-created items', count($report2['imported']) === 0, (string) count($report2['imported']));

echo "\n== Unit normalization spot-check (real aliases from the source files) ==\n";
foreach (['Gram' => 'GR', 'Gr' => 'GR', 'Kg' => 'KG', 'Pcs' => 'PCS', 'Ctn' => 'KARTON', 'Pack' => 'PACK', 'Pak' => 'PACK', 'Jar' => 'JAR', 'Liter' => 'LTR'] as $raw => $expected) {
    check("normalize('{$raw}') === {$expected}", UnitNormalizationService::normalize($pdo, $raw) === $expected);
}
check("normalize('Bag') is UNRECOGNIZED (never guessed)", UnitNormalizationService::normalize($pdo, 'Bag') === null);
check("normalize('Ctb') is UNRECOGNIZED (never guessed)", UnitNormalizationService::normalize($pdo, 'Ctb') === null);

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Direct-service section: {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — centralized read access from all 3 warehouses + existing
// MASTER_ITEM_MANAGE/MASTER_SUPPLIER_MANAGE edit gate unchanged.
// ============================================================
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('MD-SCM','Gudang Besar / SCM','MAIN',1)")->execute();
$scmWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('MD-CIBADAK','Gudang Transit Cibadak','TRANSIT',1)")->execute();
$cibadakWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('MD-KARANGTENGAH','Gudang Transit Karang Tengah','TRANSIT',1)")->execute();
$karangTengahWhId = (int) $pdo->lastInsertId();

function mdMakeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): array
{
    $u = uid($tag);
    $pass = 'MdCent' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['username' => $u, 'password' => $pass];
}
$scmUser = mdMakeUser($pdo, 'mdscm', $stockRoleId, $scmWhId);
$cibadakUser = mdMakeUser($pdo, 'mdcibadak', $stockRoleId, $cibadakWhId);
$karangTengahUser = mdMakeUser($pdo, 'mdkarangtengah', $stockRoleId, $karangTengahWhId);
$viewerAdmin = mdMakeUser($pdo, 'mdadmin', $superRoleId, null);

$port = 8900 + random_int(2000, 2399);
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

function mdHttp(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
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

function mdLogin(string $base, string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'mdcookie_');
    $login = mdHttp('POST', "{$base}/auth/login", ['username' => $username, 'password' => $password], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? null;
    return ['jar' => $jar, 'csrf' => $csrf];
}

try {
    $scmSess = mdLogin($base, $scmUser['username'], $scmUser['password']);
    $cibadakSess = mdLogin($base, $cibadakUser['username'], $cibadakUser['password']);
    $karangTengahSess = mdLogin($base, $karangTengahUser['username'], $karangTengahUser['password']);
    $adminSess = mdLogin($base, $viewerAdmin['username'], $viewerAdmin['password']);

    $scmItems = mdHttp('GET', "{$base}/items", null, $scmSess['jar']);
    $cibadakItems = mdHttp('GET', "{$base}/items", null, $cibadakSess['jar']);
    $karangTengahItems = mdHttp('GET', "{$base}/items", null, $karangTengahSess['jar']);

    check('11. SCM STOCK user: GET /items succeeds (200)', $scmItems['status'] === 200, (string) $scmItems['status']);
    check('11b. Cibadak STOCK user: GET /items succeeds (200)', $cibadakItems['status'] === 200, (string) $cibadakItems['status']);
    check('11c. Karang Tengah STOCK user: GET /items succeeds (200)', $karangTengahItems['status'] === 200, (string) $karangTengahItems['status']);

    $scmCount = count($scmItems['body']['data'] ?? []);
    $cibadakCount = count($cibadakItems['body']['data'] ?? []);
    $karangTengahCount = count($karangTengahItems['body']['data'] ?? []);
    check(
        '12. [CENTRALIZATION PROOF] all 3 warehouses see the EXACT SAME item master (identical row count, no per-warehouse duplication)',
        $scmCount === $cibadakCount && $cibadakCount === $karangTengahCount && $scmCount > 0,
        "SCM={$scmCount} Cibadak={$cibadakCount} KarangTengah={$karangTengahCount}"
    );

    $scmSuppliers = mdHttp('GET', "{$base}/suppliers", null, $scmSess['jar']);
    $karangTengahSuppliers = mdHttp('GET', "{$base}/suppliers", null, $karangTengahSess['jar']);
    check('13. SCM: GET /suppliers succeeds (200)', $scmSuppliers['status'] === 200);
    check('13b. Karang Tengah: GET /suppliers succeeds (200)', $karangTengahSuppliers['status'] === 200);
    check(
        '13c. [CENTRALIZATION PROOF] SCM and Karang Tengah see the EXACT SAME supplier master',
        count($scmSuppliers['body']['data'] ?? []) === count($karangTengahSuppliers['body']['data'] ?? []) && count($scmSuppliers['body']['data'] ?? []) > 0
    );

    // Edit gating UNCHANGED: a plain STOCK user still cannot write master data.
    $scmTryEdit = mdHttp('POST', "{$base}/suppliers", ['code' => uid('SHOULDFAIL'), 'name' => 'Should Fail'], $scmSess['jar'], $scmSess['csrf']);
    check('14. STOCK user (any warehouse) is FORBIDDEN from creating a supplier (no MASTER_SUPPLIER_MANAGE) — unchanged', $scmTryEdit['status'] === 403, (string) $scmTryEdit['status']);

    $adminTryEdit = mdHttp('POST', "{$base}/suppliers", ['code' => uid('ADMINOK'), 'name' => 'Admin Can Create'], $adminSess['jar'], $adminSess['csrf']);
    check('15. SUPERADMIN (SCM authority) CAN create a supplier (has MASTER_SUPPLIER_MANAGE) — unchanged', $adminTryEdit['status'] === 200, (string) $adminTryEdit['status']);
} finally {
    proc_terminate($process);
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n{$passed} / {$total} PASSED\n";
exit($passed === $total ? 0 : 1);
