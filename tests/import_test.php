<?php
declare(strict_types=1);

/** Exercises the Phase E Master Barang importer (services/ImportMasterItemService.php) end to end. */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';

use App\Services\ImportMasterItemService;
use App\Services\ImportValidationException;

function fresh_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(file_get_contents(__DIR__ . '/sqlite_schema.sql'));
    return $pdo;
}

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

echo "== Import Test 1: clean master_items.csv commits successfully ==\n";
$pdo = fresh_pdo();
$batchId = ImportMasterItemService::stage($pdo, __DIR__ . '/../templates/master_items.csv', 'master_items.csv', 1);
$batch = $pdo->query("SELECT * FROM import_batches WHERE id = {$batchId}")->fetch();
check('stage: 1 total row, 0 error', (int) $batch['total_rows'] === 1 && (int) $batch['error_rows'] === 0, "total={$batch['total_rows']} error={$batch['error_rows']}");

$result = ImportMasterItemService::commit($pdo, $batchId, 1);
check('commit: 1 item imported', $result['imported'] === 1, "got {$result['imported']}");

$item = $pdo->query("SELECT * FROM items WHERE sku = 'DUMMY-001'")->fetch();
check('item created with correct name', $item && $item['name'] === 'Contoh Tepung Terigu');

$conversions = $pdo->query(
    "SELECT c.*, u.code FROM item_unit_conversions c JOIN units u ON u.id = c.unit_id WHERE c.item_id = {$item['id']}"
)->fetchAll();
$byCode = [];
foreach ($conversions as $c) { $byCode[$c['code']] = (float) $c['conversion_to_base']; }
check('base unit GR has identity conversion 1.0', ($byCode['GR'] ?? null) === 1.0, json_encode($byCode));
check('purchase unit KARUNG converts to 25000', ($byCode['KARUNG'] ?? null) === 25000.0, json_encode($byCode));
check('middle unit KG converts to 1000', ($byCode['KG'] ?? null) === 1000.0, json_encode($byCode));

echo "\n== Import Test 2: file with an ERROR row is rejected at commit ==\n";
$pdo2 = fresh_pdo();
$badCsv = tempnam(sys_get_temp_dir(), 'import_bad_') . '.csv';
file_put_contents($badCsv, "sku,barcode,name,category,brand,base_unit,purchase_unit,purchase_conversion,middle_unit,middle_conversion,minimum_stock,status\n"
    . "OK-001,123,Barang OK,Kategori,Merk,PCS,,,,,10,ACTIVE\n"
    . ",456,Barang Tanpa SKU,Kategori,Merk,PCS,,,,,10,ACTIVE\n");
$batchId2 = ImportMasterItemService::stage($pdo2, $badCsv, 'bad.csv', 1);
$batch2 = $pdo2->query("SELECT * FROM import_batches WHERE id = {$batchId2}")->fetch();
check('stage: 1 valid + 1 error detected', (int) $batch2['valid_rows'] === 1 && (int) $batch2['error_rows'] === 1, "valid={$batch2['valid_rows']} error={$batch2['error_rows']}");

$rejected = false;
try {
    ImportMasterItemService::commit($pdo2, $batchId2, 1);
} catch (ImportValidationException $e) {
    $rejected = true;
}
check('commit throws ValidationException while any row is ERROR', $rejected);
$itemCount = (int) $pdo2->query('SELECT COUNT(*) FROM items')->fetchColumn();
check('no items were committed (all-or-nothing rejection)', $itemCount === 0, "got {$itemCount}");
unlink($badCsv);

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
