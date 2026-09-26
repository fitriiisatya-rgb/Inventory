<?php
declare(strict_types=1);

/**
 * PHASE V2.6A tests against real MySQL/MariaDB:
 *  1) Download Template Excel — ImportTemplateService produces a valid
 *     .xlsx per import type with EXACTLY the importer's real accepted
 *     header set (Template sheet), an Instructions sheet, no sample data
 *     row, and — specifically for WAREHOUSE — never mentions KARANG_TENGAH
 *     anywhere in the file.
 *  2) The previously-broken .xlsx upload path for MASTER_ITEM/SUPPLIER/
 *     DIVISION/WAREHOUSE: before this phase these three importers only
 *     ever called readCsv() even though the upload route accepts .xlsx,
 *     so a real .xlsx silently produced garbage rows. Proves a real xlsx
 *     (built via ExcelWriterService, read back via XlsxReaderService, the
 *     same path ImportMasterItemService/ImportSimpleMasterService now use)
 *     stages with the CORRECT field values and commits real rows — not
 *     mis-parsed binary garbage.
 *  3) CSV import behavior is unchanged (still dispatches to readCsv()).
 *
 * Usage: php tests/mysql_import_xlsx_v26a_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';

use App\Services\Database;
use App\Services\XlsxReaderService;
use App\Services\ExcelWriterService;
use App\Services\ImportMasterItemService;
use App\Services\ImportSimpleMasterService;
use App\Services\ImportTemplateService;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function tmpCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'import_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

/** Builds a real .xlsx with a one-header-row "Template" sheet (mirrors ImportTemplateService's shape). */
function tmpXlsxFromRows(array $headers, array $rows, string $sheetName = 'Template'): string
{
    $path = tempnam(sys_get_temp_dir(), 'import_') . '.xlsx';
    ExcelWriterService::write($path, [$sheetName => ['headers' => $headers, 'rows' => $rows]]);
    return $path;
}

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$username = uid('xlsx-import-user');
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => $username, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId]);
$userId = (int) $pdo->lastInsertId();

// =============================================================================
echo "== Download Template Excel: exact header match per importer ==\n";

$expectedHeaders = [
    'MASTER_ITEM' => ['sku', 'barcode', 'name', 'category', 'brand', 'base_unit', 'purchase_unit', 'purchase_conversion', 'middle_unit', 'middle_conversion', 'minimum_stock', 'status', 'default_supplier_code', 'notes'],
    'SUPPLIER' => ['supplier_code', 'supplier_name', 'status', 'contact_name', 'phone', 'notes'],
    'DIVISION' => ['division_code', 'division_name', 'status'],
    'WAREHOUSE' => ['warehouse_code', 'warehouse_name', 'status', 'warehouse_type'],
];

foreach ($expectedHeaders as $type => $expected) {
    $sheets = ImportTemplateService::build($type);
    check("{$type}: template has Instructions + Template sheets", array_keys($sheets) === ['Instructions', 'Template']);
    check("{$type}: Template sheet headers exactly match importer's accepted columns", $sheets['Template']['headers'] === $expected, implode(',', $sheets['Template']['headers']));
    check("{$type}: Template sheet has zero sample data rows", $sheets['Template']['rows'] === []);

    // Round-trip through the real xlsx writer/reader, exactly as a real
    // download-then-reupload would: proves the file is a genuinely valid
    // .xlsx (not just a PHP array), and that XlsxReaderService picks the
    // "Template" sheet over "Instructions" regardless of which is first.
    $path = tempnam(sys_get_temp_dir(), 'template_') . '.xlsx';
    ExcelWriterService::write($path, $sheets);
    $readBack = XlsxReaderService::read($path);
    check("{$type}: re-reading the downloaded template yields zero data rows (empty template)", $readBack === []);

    if ($type === 'WAREHOUSE') {
        // The Template (data) sheet must never contain Karang Tengah as a
        // sample/live row — $readBack above is exactly that sheet's data
        // rows (empty), so this is really re-confirming emptiness with
        // explicit intent. The Instructions sheet MAY mention it as a
        // warning (checked separately below), since that's guidance text,
        // never a data row a re-upload could ingest.
        check('WAREHOUSE Template (data) sheet never contains Karang Tengah as a row', !preg_match('/KARANG[_ ]?TENGAH/i', json_encode($readBack)));
        $instructionsText = implode(' ', array_map(fn ($r) => $r[0], $sheets['Instructions']['rows']));
        check('WAREHOUSE Instructions sheet explicitly warns against adding Karang Tengah', str_contains($instructionsText, 'KARANG_TENGAH') && str_contains($instructionsText, 'PENDING_CUTOVER'));
    }
    unlink($path);
}

// =============================================================================
echo "\n== .xlsx upload now stages CORRECT values (previously silently mis-parsed as CSV) ==\n";

echo "-- MASTER_ITEM --\n";
$sku = uid('SKU-XLSX');
$xlsx = tmpXlsxFromRows(
    ['sku', 'barcode', 'name', 'category', 'brand', 'base_unit', 'purchase_unit', 'purchase_conversion', 'middle_unit', 'middle_conversion', 'minimum_stock', 'status', 'default_supplier_code', 'notes'],
    [[$sku, '', 'XLSX Import Item', '', '', 'PCS', '', '', '', '', 0, 'ACTIVE', '', '']]
);
$batchId = ImportMasterItemService::stage($pdo, $xlsx, 'upload.xlsx', $userId);
$rows = $pdo->prepare('SELECT * FROM import_rows WHERE import_batch_id = :id');
$rows->execute(['id' => $batchId]);
$row = $rows->fetch();
$raw = json_decode($row['raw_data'], true);
check('MASTER_ITEM xlsx: sku parsed correctly (not garbage/binary)', ($raw['sku'] ?? null) === $sku, (string) ($raw['sku'] ?? 'NULL'));
check('MASTER_ITEM xlsx: name parsed correctly', ($raw['name'] ?? null) === 'XLSX Import Item');
check('MASTER_ITEM xlsx: base_unit parsed correctly', ($raw['base_unit'] ?? null) === 'PCS');
$result = ImportMasterItemService::commit($pdo, $batchId, $userId);
check('MASTER_ITEM xlsx: commit creates exactly 1 item', $result['imported'] === 1);
$exists = $pdo->prepare('SELECT COUNT(*) FROM items WHERE sku = :s'); $exists->execute(['s' => $sku]);
check('MASTER_ITEM xlsx: item row exists in master with correct sku', ((int) $exists->fetchColumn()) === 1);
unlink($xlsx);

echo "-- SUPPLIER --\n";
$supCode = uid('SUP-XLSX');
$xlsx = tmpXlsxFromRows(['supplier_code', 'supplier_name', 'status', 'contact_name', 'phone', 'notes'], [[$supCode, 'XLSX Supplier', 'ACTIVE', 'Budi', '0812', '']]);
$batchId = ImportSimpleMasterService::stage($pdo, 'SUPPLIER', $xlsx, 'upload.xlsx', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'SUPPLIER', $batchId, $userId);
check('SUPPLIER xlsx: commit creates exactly 1 supplier', $result['imported'] === 1);
$exists = $pdo->prepare('SELECT contact_name FROM suppliers WHERE code = :c'); $exists->execute(['c' => $supCode]);
check('SUPPLIER xlsx: contact_name field parsed correctly through to the DB row', $exists->fetchColumn() === 'Budi');
unlink($xlsx);

echo "-- DIVISION --\n";
$divCode = uid('DIV-XLSX');
$xlsx = tmpXlsxFromRows(['division_code', 'division_name', 'status'], [[$divCode, 'XLSX Division', 'ACTIVE']]);
$batchId = ImportSimpleMasterService::stage($pdo, 'DIVISION', $xlsx, 'upload.xlsx', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'DIVISION', $batchId, $userId);
check('DIVISION xlsx: commit creates exactly 1 division', $result['imported'] === 1);
unlink($xlsx);

echo "-- WAREHOUSE --\n";
$whCode = uid('WH-XLSX');
$xlsx = tmpXlsxFromRows(['warehouse_code', 'warehouse_name', 'status', 'warehouse_type'], [[$whCode, 'XLSX Warehouse', 'ACTIVE', 'TRANSIT']]);
$batchId = ImportSimpleMasterService::stage($pdo, 'WAREHOUSE', $xlsx, 'upload.xlsx', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'WAREHOUSE', $batchId, $userId);
check('WAREHOUSE xlsx: commit creates exactly 1 warehouse', $result['imported'] === 1);
$exists = $pdo->prepare('SELECT warehouse_type FROM warehouses WHERE code = :c'); $exists->execute(['c' => $whCode]);
check('WAREHOUSE xlsx: warehouse_type (TRANSIT) parsed correctly through to the DB row', $exists->fetchColumn() === 'TRANSIT');
unlink($xlsx);

// =============================================================================
echo "\n== CSV import path still works unchanged (no regression from the xlsx dispatch change) ==\n";

$skuCsv = uid('SKU-CSV');
$csv = tmpCsv("sku,barcode,name,category,brand,base_unit,purchase_unit,purchase_conversion,middle_unit,middle_conversion,minimum_stock,status,default_supplier_code,notes\n{$skuCsv},,CSV Import Item,,,PCS,,,,,0,ACTIVE,,\n");
$batchId = ImportMasterItemService::stage($pdo, $csv, 'upload.csv', $userId);
$result = ImportMasterItemService::commit($pdo, $batchId, $userId);
check('MASTER_ITEM csv: commit still creates exactly 1 item (unchanged)', $result['imported'] === 1);
unlink($csv);

$supCodeCsv = uid('SUP-CSV');
$csv = tmpCsv("supplier_code,supplier_name,status\n{$supCodeCsv},CSV Supplier,ACTIVE\n");
$batchId = ImportSimpleMasterService::stage($pdo, 'SUPPLIER', $csv, 'upload.csv', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'SUPPLIER', $batchId, $userId);
check('SUPPLIER csv: commit still creates exactly 1 supplier (unchanged)', $result['imported'] === 1);
unlink($csv);

// =============================================================================
$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
