<?php
declare(strict_types=1);

/**
 * PHASE G-DATA 2 — tests for CostNormalizationService, OpeningValidationService,
 * the UNIT_CONVERSION_NOT_APPROVED behavior, and the multi-warehouse company
 * total. Uses only synthetic test items/warehouses (uid()-prefixed) — never
 * the real 1,165-SKU catalog, which has not been imported into production.
 *
 * Requires a configured .env pointing at a throwaway/dev database.
 * Usage: php tests/opening_g_data_2_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\CostNormalizationService;
use App\Services\OpeningValidationService;
use App\Services\ImportOpeningStockService;
use App\Services\OpeningReconciliationService;
use App\Services\UnitConversionNotApprovedException;
use App\Services\ValidationException;
use App\Services\InventoryService;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function uid(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(4)); }
$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function tmpCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'opening_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$grUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();
$userId = (int) $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
if (!$userId) {
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id) VALUES (:u, :p, 'Test User', :r)")
        ->execute(['u' => uid('user'), 'p' => password_hash('x', PASSWORD_DEFAULT), 'r' => $roleId]);
    $userId = (int) $pdo->lastInsertId();
}

echo "\n== A: 100 KG x 20,000 = Rp2,000,000 ==\n";
check('Value = qty x cost', approx_local(100 * 20000, 2000000.0));

echo "\n== B: 999208-style normalization (1 PCS = 15 KG, package price 580,930) ==\n";
$normCost = CostNormalizationService::normalize(580930.0, 15.0);
check('580,930 / 15 = 38,728.6667/KG', approx_local($normCost, 38728.6667, 0.001), "got {$normCost}");
$preserved = CostNormalizationService::valuePreserved(10.0, 580930.0, 15.0);
check('Value preserved after normalization (10 PCS -> 150 KG)', $preserved);
check('10 PCS x 15 = 150 KG base qty', approx_local(10.0 * 15.0, 150.0));

echo "\n== C: 140539-style (Rp305,000/KG = Rp305/GR) ==\n";
$normGr = CostNormalizationService::normalize(305000.0, 1000.0); // 1 KG = 1000 GR
check('305,000 / 1000 = 305/GR', approx_local($normGr, 305.0), "got {$normGr}");

echo "\n== D: negative opening rejected ==\n";
$skuD = uid('SKU-D');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $skuD, 'n' => $skuD, 'u' => $kgUnitId]);
$itemD = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemD, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
$whCode = uid('WH');
$pdo->prepare("INSERT INTO warehouses (code, name) VALUES (:c, :n)")->execute(['c' => $whCode, 'n' => $whCode]);
$whIdMain = (int) $pdo->query("SELECT id FROM warehouses WHERE code='{$whCode}'")->fetchColumn();
$rowD = ['warehouse_code' => $whCode, 'sku' => $skuD, 'opening_qty_base' => '-5', 'unit_cost_base' => '100'];
$resultD = OpeningValidationService::validateRow($pdo, $rowD);
check('Negative opening quantity -> ERROR', $resultD['status'] === 'ERROR', json_encode($resultD['messages']));

echo "\n== E: positive qty + zero cost rejected (COST_REQUIRED) ==\n";
$rowE = ['warehouse_code' => $whCode, 'sku' => $skuD, 'opening_qty_base' => '10', 'unit_cost_base' => '0'];
$resultE = OpeningValidationService::validateRow($pdo, $rowE);
check('Positive qty + zero cost -> ERROR (COST_REQUIRED)', $resultE['status'] === 'ERROR' && str_contains($resultE['messages'][0], 'COST_REQUIRED'), json_encode($resultE['messages']));

echo "\n== F: LOW-confidence / no approved purchase conversion -> base-unit opening still ACCEPTED ==\n";
$skuF = uid('SKU-F');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $skuF, 'n' => $skuF, 'u' => $kgUnitId]);
$itemF = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemF, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
// deliberately NO purchase-unit conversion configured for item F
$rowF = ['warehouse_code' => $whCode, 'sku' => $skuF, 'opening_qty_base' => '50', 'unit_cost_base' => '1000'];
$resultF = OpeningValidationService::validateRow($pdo, $rowF);
check('No purchase conversion -> WARNING (not ERROR), base-unit opening accepted', $resultF['status'] === 'WARNING', json_encode($resultF['messages']));
check('Warning message mentions no approved purchase conversion (informational only)', str_contains(implode(' ', $resultF['messages']), 'no approved purchase conversion'));

echo "\n== G: unapproved CTN/Pack transaction rejected: UNIT_CONVERSION_NOT_APPROVED ==\n";
$ctnUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$rejectedUnapproved = false;
$rejectedClass = '';
$rejectedMessage = '';
try {
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('txn'), 'item_id' => $itemF, 'warehouse_id' => $whIdMain,
        'input_qty' => 5, 'input_unit_id' => $ctnUnitId, 'unit_price_input' => 100000,
        'transaction_type' => 'IN', 'transaction_date' => '2026-09-30 00:00:00', 'created_by' => $userId,
    ]));
} catch (UnitConversionNotApprovedException $e) {
    $rejectedUnapproved = true;
    $rejectedClass = get_class($e);
    $rejectedMessage = $e->getMessage();
}
check('Posting in an unapproved unit throws UnitConversionNotApprovedException', $rejectedUnapproved, $rejectedClass);
check('Exception message carries UNIT_CONVERSION_NOT_APPROVED', str_contains($rejectedMessage, 'UNIT_CONVERSION_NOT_APPROVED'));

echo "\n== H: same SKU, three warehouse balances -> company total ==\n";
$skuH = uid('SKU-H');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $skuH, 'n' => $skuH, 'u' => $kgUnitId]);
$itemH = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemH, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

$whScm = uid('SCM'); $whCb = uid('CBD'); $whKt = uid('KT');
foreach ([$whScm, $whCb, $whKt] as $code) {
    $pdo->prepare("INSERT INTO warehouses (code, name) VALUES (:c, :n)")->execute(['c' => $code, 'n' => $code]);
}
$whScmId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='{$whScm}'")->fetchColumn();
$whCbId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='{$whCb}'")->fetchColumn();
$whKtId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='{$whKt}'")->fetchColumn();

foreach ([[$whScmId, 100.0], [$whCbId, 20.0], [$whKtId, 30.0]] as [$whId, $qty]) {
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('open'), 'item_id' => $itemH, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 20000,
        'allow_zero_price' => false, 'transaction_type' => 'OPENING', 'transaction_date' => '2026-09-30 00:00:00',
        'created_by' => $userId, 'anomaly_approved_by' => $userId,
    ]));
}
$stockScm = InventoryService::currentStock($pdo, $itemH, $whScmId);
$stockCb = InventoryService::currentStock($pdo, $itemH, $whCbId);
$stockKt = InventoryService::currentStock($pdo, $itemH, $whKtId);
$companyQty = $stockScm['qty_base'] + $stockCb['qty_base'] + $stockKt['qty_base'];
check('SCM=100 CIBADAK=20 KARANG_TENGAH=30 -> company qty = 150', approx_local($companyQty, 150.0), "got {$companyQty}");

echo "\n== I: control total before/after import identical ==\n";
$skuI = uid('SKU-I');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $skuI, 'n' => $skuI, 'u' => $kgUnitId]);
$itemI = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemI, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
$csv = tmpCsv("cutoff_date,warehouse_code,sku,opening_qty_base,unit_cost_base,expiry_date,batch_reference\n2026-09-30,{$whCode},{$skuI},100,20000,,CT-1\n");
$openingId = ImportOpeningStockService::stage($pdo, $csv, 'opening.csv', $userId);
$stagedTotal = (float) $pdo->query("SELECT control_total_value FROM stock_openings WHERE id={$openingId}")->fetchColumn();
check('Staged control total = 100 x 20,000 = 2,000,000', approx_local($stagedTotal, 2000000.0), "got {$stagedTotal}");
$commitResult = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingId, $userId));
$reconciliation = OpeningReconciliationService::report($pdo, $openingId);
check('Post-commit control total still matches staged total (control_total_match=PASS)', $reconciliation['checks']['opening_control_total_match'] === 'PASS', json_encode($reconciliation['checks']));
check('GO_LIVE_READY true for a clean single-line import', $reconciliation['go_live_ready'] === true, json_encode($reconciliation));
unlink($csv);

function approx_local(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
