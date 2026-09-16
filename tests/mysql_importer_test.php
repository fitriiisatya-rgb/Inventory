<?php
declare(strict_types=1);

/**
 * PHASE E2 importer tests against real MySQL/MariaDB: Supplier, Division,
 * Warehouse, Opening Stock, Historical Transaction. Master Barang is
 * already covered by tests/import_test.php (SQLite) — these five follow
 * the same staged/validate/commit pattern, verified here against the real
 * schema since Opening Stock and Historical Transaction both interact with
 * FifoService/period-lock machinery that's worth proving on the real
 * driver at least once.
 *
 * Usage: php tests/mysql_importer_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';

use App\Services\Database;
use App\Services\InventoryService;
use App\Services\ImportSimpleMasterService;
use App\Services\ImportOpeningStockService;
use App\Services\ImportHistoricalTransactionService;
use App\Services\ValidationException;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }

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

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$username = uid('importer-user');
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => $username, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId]);
$userId = (int) $pdo->lastInsertId();

// =============================================================================
echo "== SUPPLIER import ==\n";
$supCode = uid('SUP');
$csv = tmpCsv("supplier_code,supplier_name,status\n{$supCode},Contoh Supplier,ACTIVE\n");
$batchId = ImportSimpleMasterService::stage($pdo, 'SUPPLIER', $csv, 'suppliers.csv', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'SUPPLIER', $batchId, $userId);
check('Supplier imported', $result['imported'] === 1);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE code = :c'); $stmt->execute(['c' => $supCode]);
check('Supplier row exists with correct code', ((int) $stmt->fetchColumn()) === 1);
unlink($csv);

// bad supplier row (missing code) blocks commit
$csvBad = tmpCsv("supplier_code,supplier_name,status\n,No Code Supplier,ACTIVE\n");
$batchIdBad = ImportSimpleMasterService::stage($pdo, 'SUPPLIER', $csvBad, 'bad.csv', $userId);
$rejected = false;
try { ImportSimpleMasterService::commit($pdo, 'SUPPLIER', $batchIdBad, $userId); } catch (ValidationException $e) { $rejected = true; }
check('Supplier import with missing code is rejected', $rejected);
unlink($csvBad);

// =============================================================================
echo "\n== DIVISION import ==\n";
$divCode = uid('DIV');
$csv = tmpCsv("division_code,division_name,status\n{$divCode},Contoh Divisi,ACTIVE\n");
$batchId = ImportSimpleMasterService::stage($pdo, 'DIVISION', $csv, 'divisions.csv', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'DIVISION', $batchId, $userId);
check('Division imported', $result['imported'] === 1);
unlink($csv);

// =============================================================================
echo "\n== WAREHOUSE import ==\n";
$whCode = uid('WH');
$csv = tmpCsv("warehouse_code,warehouse_name,status\n{$whCode},Contoh Gudang,ACTIVE\n");
$batchId = ImportSimpleMasterService::stage($pdo, 'WAREHOUSE', $csv, 'warehouses.csv', $userId);
$result = ImportSimpleMasterService::commit($pdo, 'WAREHOUSE', $batchId, $userId);
check('Warehouse imported', $result['imported'] === 1);
unlink($csv);
$importedWarehouseId = (int) $pdo->query("SELECT id FROM warehouses WHERE code = '{$whCode}'")->fetchColumn();

// =============================================================================
echo "\n== OPENING STOCK import (must create a REAL batch) ==\n";
$sku = uid('SKU-OPEN');
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
$openItemId = (int) $pdo->lastInsertId();
\App\Services\UnitConversionService::openNewVersion($pdo, $openItemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

$csv = tmpCsv("cutoff_date,warehouse_code,sku,quantity_base,unit_cost_base,expired_date,batch_reference\n2026-09-30,{$whCode},{$sku},125,12,,OPN-1\n");
$openingId = ImportOpeningStockService::stage($pdo, $csv, 'opening.csv', $userId);
$result = ImportOpeningStockService::commit($pdo, $openingId, $userId);
check('Opening stock line imported', $result['imported'] === 1);

$stock = InventoryService::currentStock($pdo, $openItemId, $importedWarehouseId);
check('Opening stock creates real stock: 125kg @ Rp12 = Rp1.500', approx($stock['qty_base'], 125) && approx($stock['value'], 1500), "qty={$stock['qty_base']} value={$stock['value']}");

$ledger = InventoryService::ledger($pdo, $openItemId, $importedWarehouseId);
check('Ledger shows an OPENING-type line', !empty($ledger) && $ledger[0]['transaction_type'] === 'OPENING', $ledger[0]['transaction_type'] ?? 'none');
unlink($csv);

// =============================================================================
echo "\n== HISTORICAL TRANSACTION import (must NOT affect stock) ==\n";
$stockBeforeHistorical = InventoryService::currentStock($pdo, $openItemId, $importedWarehouseId);
$csv = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-09-05,OUT,{$whCode},{$sku},10,KG,0,,,REF-HIST-1,catatan historis\n"
);
$histBatchId = ImportHistoricalTransactionService::stage($pdo, $csv, 'historical.csv', $userId);
$result = ImportHistoricalTransactionService::commit($pdo, $histBatchId, $userId);
check('Historical transaction row imported', $result['imported'] === 1);

$stockAfterHistorical = InventoryService::currentStock($pdo, $openItemId, $importedWarehouseId);
check('Stock UNCHANGED after historical import', approx($stockAfterHistorical['qty_base'], $stockBeforeHistorical['qty_base']), "before={$stockBeforeHistorical['qty_base']} after={$stockAfterHistorical['qty_base']}");

$histTx = $pdo->prepare("SELECT * FROM inventory_transactions WHERE reference_no = 'REF-HIST-1'");
$histTx->execute();
$histTxRow = $histTx->fetch();
check('Historical transaction flagged is_historical_import=1, inventory_effect=0', $histTxRow && (int) $histTxRow['is_historical_import'] === 1 && (int) $histTxRow['inventory_effect'] === 0);

$noBatchCreated = $pdo->prepare('SELECT COUNT(*) FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE transaction_id = :id)');
$noBatchCreated->execute(['id' => $histTxRow['id']]);
check('No inventory_batches row created for the historical line', ((int) $noBatchCreated->fetchColumn()) === 0);
unlink($csv);

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
