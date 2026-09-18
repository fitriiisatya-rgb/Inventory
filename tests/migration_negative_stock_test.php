<?php
declare(strict_types=1);

/**
 * POLICY CORRECTION — KEEP KNOWN MIGRATION NEGATIVE STOCK VISIBLE.
 *
 * Exercises the whole policy end-to-end against synthetic (uid()-prefixed)
 * items/warehouses — never the real catalog. Covers: OpeningValidationService
 * allowing a whitelisted negative row through as WARNING (not ERROR) while
 * still rejecting an unapproved negative row; ImportOpeningStockService
 * posting the real negative FIFO batch; FifoService blocking OUT/
 * TRANSFER_OUT while the balance stays <= 0 (even with allow_negative_stock);
 * StockAdjustmentService resolving the deficit; InventoryService's live
 * migration_negative_review/needs_stock_opname flags clearing once resolved;
 * OpeningReconciliationService's GO_LIVE_READY split (migration_negative_count
 * allow-with-warning vs. an unknown negative still blocking); and the
 * ImportOpeningStockService::commit() safety net refusing to post a negative
 * line for an item that is NOT actually on the whitelist even if staging
 * somehow let it through.
 *
 * Requires a configured .env pointing at a throwaway/dev database.
 * Usage: php tests/migration_negative_stock_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\OpeningValidationService;
use App\Services\StockAdjustmentService;
use App\Services\ImportOpeningStockService;
use App\Services\OpeningReconciliationService;
use App\Services\MigrationNegativeStockService;
use App\Services\InventoryService;
use App\Services\NegativeMigrationStockRequiresAdjustmentException;
use App\Services\ImportValidationException;

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
    $path = tempnam(sys_get_temp_dir(), 'mns_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}
function approx(float $a, float $b, float $eps = 0.000001): bool { return abs($a - $b) < $eps; }

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$userId = (int) $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
if (!$userId) {
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id) VALUES (:u, :p, 'Test User', :r)")
        ->execute(['u' => uid('user'), 'p' => password_hash('x', PASSWORD_DEFAULT), 'r' => $roleId]);
    $userId = (int) $pdo->lastInsertId();
}

function makeItem(PDO $pdo, int $kgUnitId, string $sku): int
{
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $itemId;
}
function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function approveMigrationNegative(PDO $pdo, string $sku, string $whCode, float $ending): void
{
    $pdo->prepare(
        'INSERT INTO movement_reconciliation_reviews
            (sku, warehouse_code, historical_opening, historical_in, historical_out, historical_calculated_ending,
             status, is_migration_negative_approved, migration_negative_approved_by_name, migration_negative_note)
         VALUES (:sku, :wh, 0, 0, 0, :ending, \'PENDING_FINAL_STOCK\', 1, \'Test Owner\', \'test whitelist row\')'
    )->execute(['sku' => $sku, 'wh' => $whCode, 'ending' => $ending]);
}

// ============================================================
echo "\n== A: whitelisted negative opening row -> WARNING (not ERROR) ==\n";
$skuA = uid('MNS-A');
$whCodeA = uid('WHA');
$itemA = makeItem($pdo, $kgUnitId, $skuA);
$whIdA = makeWarehouse($pdo, $whCodeA);
approveMigrationNegative($pdo, $skuA, $whCodeA, -0.5);

$rowA = ['warehouse_code' => $whCodeA, 'sku' => $skuA, 'opening_qty_base' => '-0.5', 'unit_cost_base' => '20000'];
$resultA = OpeningValidationService::validateRow($pdo, $rowA);
check('Whitelisted negative opening -> WARNING, not ERROR', $resultA['status'] === 'WARNING', json_encode($resultA['messages']));
check('WARNING message mentions MIGRATION_NEGATIVE_REVIEW', str_contains(implode(' ', $resultA['messages']), 'MIGRATION_NEGATIVE_REVIEW'));

echo "\n== B: an otherwise-identical negative row that is NOT whitelisted still ERRORs ==\n";
$skuB = uid('MNS-B');
$itemB = makeItem($pdo, $kgUnitId, $skuB);
$rowB = ['warehouse_code' => $whCodeA, 'sku' => $skuB, 'opening_qty_base' => '-0.5', 'unit_cost_base' => '20000'];
$resultB = OpeningValidationService::validateRow($pdo, $rowB);
check('Non-whitelisted negative opening still -> ERROR', $resultB['status'] === 'ERROR', json_encode($resultB['messages']));

// ============================================================
echo "\n== C: commit posts the real negative FIFO batch, LIVE Opening stays negative ==\n";
$csvA = tmpCsv("cutoff_date,warehouse_code,sku,opening_qty_base,unit_cost_base,expiry_date,batch_reference\n2026-09-16,{$whCodeA},{$skuA},-0.5,20000,,OPEN-A\n");
$openingIdA = ImportOpeningStockService::stage($pdo, $csvA, 'opening_a.csv', $userId);
$stagedRow = $pdo->query("SELECT row_status FROM stock_opening_lines WHERE stock_opening_id={$openingIdA}")->fetch();
check('Staged row_status is WARNING (not ERROR, so commit is not blocked)', $stagedRow['row_status'] === 'WARNING', (string) $stagedRow['row_status']);
Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingIdA, $userId));
unlink($csvA);

$stockA = InventoryService::currentStock($pdo, $itemA, $whIdA);
check('LIVE Opening balance = -0.5 (not zeroed, no invented adjustment)', approx($stockA['qty_base'], -0.5), "got {$stockA['qty_base']}");
check('migration_negative_review = true while balance <= 0', $stockA['migration_negative_review'] === true);
check('needs_stock_opname = true while balance <= 0', $stockA['needs_stock_opname'] === true);
check(
    'migration_issue_reference = MIGRATION-<sku>-<warehouse>',
    $stockA['migration_issue_reference'] === "MIGRATION-{$skuA}-{$whCodeA}",
    (string) $stockA['migration_issue_reference']
);

$batch = $pdo->query("SELECT * FROM inventory_batches WHERE item_id={$itemA} AND warehouse_id={$whIdA}")->fetch();
check('Batch qty_base = -0.5', approx((float) $batch['qty_base'], -0.5));
check('Batch is flagged is_negative_layer = 1', (int) $batch['is_negative_layer'] === 1);

// ============================================================
echo "\n== D: FIFO safety -- OUT is blocked while balance <= 0, even with allow_negative_stock ==\n";
$blocked = false; $blockedMsg = '';
try {
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('out'), 'item_id' => $itemA, 'warehouse_id' => $whIdA,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
        'transaction_date' => '2026-09-17 00:00:00', 'created_by' => $userId,
        'allow_negative_stock' => true, 'negative_stock_reason' => 'trying to force it through',
    ]));
} catch (NegativeMigrationStockRequiresAdjustmentException $e) {
    $blocked = true; $blockedMsg = $e->getMessage();
}
check('OUT throws NegativeMigrationStockRequiresAdjustmentException', $blocked, $blockedMsg);
check('Exception message carries NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT', str_contains($blockedMsg, 'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT'));

$blockedTransfer = false;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('trout'), 'item_id' => $itemA, 'warehouse_id' => $whIdA,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'TRANSFER_OUT',
        'transaction_date' => '2026-09-17 00:00:00', 'created_by' => $userId,
    ]));
} catch (NegativeMigrationStockRequiresAdjustmentException $e) {
    $blockedTransfer = true;
}
check('TRANSFER_OUT (transfer-out) is blocked the same way', $blockedTransfer);

$blockedProduction = false;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('prodin'), 'item_id' => $itemA, 'warehouse_id' => $whIdA,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'PRODUCTION_IN',
        'transaction_date' => '2026-09-17 00:00:00', 'created_by' => $userId,
    ]));
} catch (NegativeMigrationStockRequiresAdjustmentException $e) {
    $blockedProduction = true;
}
check('PRODUCTION_IN (raw-material consumption) is blocked the same way', $blockedProduction);

echo "\n== D2: IN is still allowed while the migration-negative balance is unresolved ==\n";
// Uses its own item/warehouse (not item A) so item A's -0.5 balance stays
// exactly as computed for test E below.
$skuD2 = uid('MNS-D2');
$whCodeD2 = uid('WHD2');
$itemD2 = makeItem($pdo, $kgUnitId, $skuD2);
$whIdD2 = makeWarehouse($pdo, $whCodeD2);
approveMigrationNegative($pdo, $skuD2, $whCodeD2, -1.0);
$csvD2 = tmpCsv("cutoff_date,warehouse_code,sku,opening_qty_base,unit_cost_base,expiry_date,batch_reference\n2026-09-16,{$whCodeD2},{$skuD2},-1,20000,,OPEN-D2\n");
$openingIdD2 = ImportOpeningStockService::stage($pdo, $csvD2, 'opening_d2.csv', $userId);
Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingIdD2, $userId));
unlink($csvD2);

$inOk = false;
try {
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('in'), 'item_id' => $itemD2, 'warehouse_id' => $whIdD2,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 20000,
        'transaction_type' => 'IN', 'transaction_date' => '2026-09-17 00:00:00', 'created_by' => $userId,
        'anomaly_approved_by' => $userId,
    ]));
    $inOk = $r['success'] === true;
} catch (\Throwable $e) {
    $inOk = false;
}
check('A normal IN transaction is NOT blocked for a migration-negative item', $inOk);

// ============================================================
echo "\n== E: Stock Adjustment resolves the deficit (audited, never a direct DB edit) ==\n";
$before = InventoryService::currentStock($pdo, $itemA, $whIdA);
$adjResult = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('adj'), 'item_id' => $itemA, 'warehouse_id' => $whIdA,
    'qty_base_delta' => 3.5, 'adjustment_type' => 'OPNAME', 'reason' => 'Physical count found 3 KG on hand',
    'created_by' => $userId, 'migration_issue_reference' => MigrationNegativeStockService::issueReference($skuA, $whCodeA),
]));
check('Adjustment before_qty_base = -0.5', approx($adjResult['before_qty_base'], -0.5), (string) $adjResult['before_qty_base']);
check('Adjustment after_qty_base = 3.0 (physical stock)', approx($adjResult['after_qty_base'], 3.0), (string) $adjResult['after_qty_base']);

$adjRow = $pdo->query("SELECT * FROM stock_adjustments WHERE id={$adjResult['adjustment_id']}")->fetch();
check('stock_adjustments row records before/physical/delta/reason/PIC/timestamp', (float) $adjRow['before_qty_base'] === -0.5 + 0.0
    && (float) $adjRow['after_qty_base'] === 3.0 && (float) $adjRow['qty_base_delta'] === 3.5
    && $adjRow['reason'] !== '' && (int) $adjRow['created_by'] === $userId && $adjRow['created_at'] !== null);
check('stock_adjustments row records migration_issue_reference', $adjRow['migration_issue_reference'] === "MIGRATION-{$skuA}-{$whCodeA}", (string) $adjRow['migration_issue_reference']);

$afterStock = InventoryService::currentStock($pdo, $itemA, $whIdA);
check('Balance after adjustment = 3.0', approx($afterStock['qty_base'], 3.0), "got {$afterStock['qty_base']}");
check('migration_negative_review clears once resolved (balance > 0)', $afterStock['migration_negative_review'] === false);
check('needs_stock_opname clears once resolved', $afterStock['needs_stock_opname'] === false);

echo "\n== F: OUT now succeeds normally since the balance is resolved ==\n";
$outOk = false;
try {
    $r = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('out2'), 'item_id' => $itemA, 'warehouse_id' => $whIdA,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
        'transaction_date' => '2026-09-18 00:00:00', 'created_by' => $userId,
    ]));
    $outOk = $r['success'] === true;
} catch (\Throwable $e) {
    $outOk = false;
}
check('OUT succeeds once the migration-negative balance is resolved', $outOk);

// ============================================================
echo "\n== G: OpeningReconciliationService -- migration_negative_count / go_live_ready split ==\n";
$skuG = uid('MNS-G');
$whCodeG = uid('WHG');
$itemG = makeItem($pdo, $kgUnitId, $skuG);
$whIdG = makeWarehouse($pdo, $whCodeG);
approveMigrationNegative($pdo, $skuG, $whCodeG, -2.0);

$csvG = tmpCsv("cutoff_date,warehouse_code,sku,opening_qty_base,unit_cost_base,expiry_date,batch_reference\n2026-09-16,{$whCodeG},{$skuG},-2,20000,,OPEN-G\n");
$openingIdG = ImportOpeningStockService::stage($pdo, $csvG, 'opening_g.csv', $userId);
Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingIdG, $userId));
unlink($csvG);

$reportG = OpeningReconciliationService::report($pdo, $openingIdG);
check('migration_negative_count = 1', $reportG['migration_negative_count'] === 1, json_encode($reportG['migration_negative_count']));
check('migration_negative_rows lists the whitelisted SKU', ($reportG['migration_negative_rows'][0]['sku'] ?? null) === $skuG, json_encode($reportG['migration_negative_rows']));
check('checks.negative_qty = 0 (the whitelisted row does not count as unknown-negative)', $reportG['checks']['negative_qty'] === 0, json_encode($reportG['checks']));
check('GO_LIVE_READY true when the only negative row is the approved migration exception', $reportG['go_live_ready'] === true, json_encode($reportG));

echo "\n== G2: companyTotalValue flags unresolved migration-negative stock honestly ==\n";
$totals = InventoryService::companyTotalValue($pdo);
check('contains_unresolved_migration_negative_stock = true while G is unresolved', $totals['contains_unresolved_migration_negative_stock'] === true);

// ============================================================
echo "\n== H: safety net -- commit() refuses a negative line for an item NOT on the whitelist, even if staging let it through ==\n";
$skuH = uid('MNS-H');
$whCodeH = uid('WHH');
$itemH = makeItem($pdo, $kgUnitId, $skuH);
$whIdH = makeWarehouse($pdo, $whCodeH);
// deliberately no approveMigrationNegative() call for H -- simulate a bypass
// of staging validation by inserting the stock_opening_lines row directly.
$headerStmt = $pdo->prepare("INSERT INTO stock_openings (cutoff_date, description, status, created_by, created_at) VALUES ('2026-09-16','bypass test','DRAFT',:u,NOW())");
$headerStmt->execute(['u' => $userId]);
$openingIdH = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO stock_opening_lines (stock_opening_id, item_id, warehouse_id, qty_base, unit_cost_base, row_status, row_messages)
     VALUES (:oid, :item, :wh, -1, 20000, \'WARNING\', \'[]\')'
)->execute(['oid' => $openingIdH, 'item' => $itemH, 'wh' => $whIdH]);
$pdo->prepare('UPDATE stock_openings SET control_total_value = -20000 WHERE id = :id')->execute(['id' => $openingIdH]);

$refused = false; $refusedMsg = '';
try {
    Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingIdH, $userId));
} catch (ImportValidationException $e) {
    $refused = true; $refusedMsg = implode('; ', $e->errors);
}
check('commit() refuses a negative line for a non-whitelisted item (defense-in-depth)', $refused, $refusedMsg);

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
