<?php
declare(strict_types=1);

/**
 * PHASE F2 integration tests — real MySQL/MariaDB (not SQLite), covering
 * every PHASE C2 flow: Transfer, Stock Opname, Production, Book Closing,
 * period lock, and idempotency across all of them. Requires a configured
 * .env (see tests/mysql_smoke_test.php for the same convention).
 *
 * Usage: php tests/mysql_integration_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/ProductionService.php';
require_once __DIR__ . '/../services/BookClosingService.php';
require_once __DIR__ . '/../services/ReconciliationService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use App\Services\TransferService;
use App\Services\StockOpnameService;
use App\Services\ProductionService;
use App\Services\BookClosingService;
use App\Services\ReconciliationService;
use App\Services\ValidationException;
use App\Services\PeriodLockedException;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function uid(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

// ---- shared fixtures ----
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare("INSERT INTO warehouses (code, name) VALUES (:c, :n)")->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeUser(PDO $pdo, string $username): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u, :h, :n, :r, 1)")
        ->execute(['u' => $username, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, string $sku, int $baseUnitId): int
{
    $pdo->prepare("INSERT INTO items (sku, name, base_unit_id) VALUES (:sku, :name, :unit)")
        ->execute(['sku' => $sku, 'name' => $sku, 'unit' => $baseUnitId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $itemId;
}

$userId = makeUser($pdo, uid('user'));

// =============================================================================
echo "== TRANSFER: A 100@10.000, transfer 40 to B, then receive ==\n";
$whA = makeWarehouse($pdo, uid('WH-A'));
$whB = makeWarehouse($pdo, uid('WH-B'));
$itemT = makeItem($pdo, uid('SKU-TRANSFER'), $kgUnitId);

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('t-in'), 'item_id' => $itemT, 'warehouse_id' => $whA,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));

$companyBefore = InventoryService::companyTotalValue($pdo);

$transferUuid = uid('transfer');
$createResult = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => $transferUuid, 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $userId,
    'lines' => [['item_id' => $itemT, 'input_qty' => 40, 'input_unit_id' => $kgUnitId]],
]));
$transferId = $createResult['transfer_id'];

$stockA = InventoryService::currentStock($pdo, $itemT, $whA);
$stockB = InventoryService::currentStock($pdo, $itemT, $whB);
$inTransit = InventoryService::inTransitValue($pdo);
check('A = 60kg after shipping 40', approx($stockA['qty_base'], 60), "got {$stockA['qty_base']}");
check('B = 0kg (not yet received)', approx($stockB['qty_base'], 0), "got {$stockB['qty_base']}");
check('In-transit value = Rp400.000 (40 x 10.000)', approx($inTransit, 400000), "got {$inTransit}");
$companyDuringTransit = InventoryService::companyTotalValue($pdo);
check('Company total value unchanged during transit', approx($companyDuringTransit['total_value'], $companyBefore['total_value']), "before={$companyBefore['total_value']} during={$companyDuringTransit['total_value']}");

// double receive must be impossible
$receiveRequestUuid = uid('receive-req');
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferId, ['created_by' => $userId, 'request_uuid' => $receiveRequestUuid]));
$secondReceive = Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferId, ['created_by' => $userId, 'request_uuid' => $receiveRequestUuid]));
check('Second receive with the SAME request_uuid is idempotent (no error, no double effect)', $secondReceive['idempotent_replay'] === true);
$thirdReceiveRejected = false;
try {
    Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferId, ['created_by' => $userId, 'request_uuid' => uid('different-req')]));
} catch (\App\Services\TransferAlreadyReceivedException $e) {
    $thirdReceiveRejected = true;
}
check('A genuinely NEW receive attempt (different request_uuid) on an already-RECEIVED transfer is rejected TRANSFER_ALREADY_RECEIVED', $thirdReceiveRejected);

$stockA2 = InventoryService::currentStock($pdo, $itemT, $whA);
$stockB2 = InventoryService::currentStock($pdo, $itemT, $whB);
$inTransit2 = InventoryService::inTransitValue($pdo);
check('A stays 60kg after receive', approx($stockA2['qty_base'], 60), "got {$stockA2['qty_base']}");
check('B = 40kg after receive', approx($stockB2['qty_base'], 40), "got {$stockB2['qty_base']}");
check('In-transit value = 0 after receive', approx($inTransit2, 0), "got {$inTransit2}");
check('B batch preserves original cost (Rp10.000/kg, not re-averaged)', approx($stockB2['value'] / max($stockB2['qty_base'], 0.0001), 10000), "got " . ($stockB2['value'] / max($stockB2['qty_base'], 0.0001)));
$companyAfter = InventoryService::companyTotalValue($pdo);
check('Company total value unchanged after full round-trip', approx($companyAfter['total_value'], $companyBefore['total_value']), "before={$companyBefore['total_value']} after={$companyAfter['total_value']}");

// idempotent create
$createAgain = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => $transferUuid, 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $userId,
    'lines' => [['item_id' => $itemT, 'input_qty' => 40, 'input_unit_id' => $kgUnitId]],
]));
check('Duplicate transfer_uuid create() is idempotent', $createAgain['idempotent_replay'] === true);

// cancel only allowed while PENDING
$whC = makeWarehouse($pdo, uid('WH-C'));
$transferUuid2 = uid('transfer2');
Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => $transferUuid2, 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whC,
    'ship_date' => '2026-09-06 08:00:00', 'created_by' => $userId,
    'lines' => [['item_id' => $itemT, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]));
$transfer2Id = (int) $pdo->query("SELECT id FROM warehouse_transfers WHERE transfer_uuid = '{$transferUuid2}'")->fetchColumn();
$stockABeforeCancel = InventoryService::currentStock($pdo, $itemT, $whA);
Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, $transfer2Id, ['created_by' => $userId, 'reason' => 'test cancel']));
$stockAAfterCancel = InventoryService::currentStock($pdo, $itemT, $whA);
check('Cancel restores source warehouse stock exactly', approx($stockAAfterCancel['qty_base'], $stockABeforeCancel['qty_base'] + 10), "before={$stockABeforeCancel['qty_base']} after={$stockAAfterCancel['qty_base']}");
$cancelReceiveRejected = false;
try {
    Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transfer2Id, ['created_by' => $userId]));
} catch (ValidationException $e) {
    $cancelReceiveRejected = true;
}
check('Cannot receive a CANCELLED transfer', $cancelReceiveRejected);

// =============================================================================
echo "\n== STOCK OPNAME: system 100, physical 95 -> ADJUSTMENT OUT 5 ==\n";
$whOp = makeWarehouse($pdo, uid('WH-OPNAME'));
$itemOp = makeItem($pdo, uid('SKU-OPNAME'), $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('op-in'), 'item_id' => $itemOp, 'warehouse_id' => $whOp,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whOp, $userId, [$itemOp]));

// while opname is active, movement on this warehouse must be blocked
$blockedDuringOpname = false;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('blocked'), 'item_id' => $itemOp, 'warehouse_id' => $whOp,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-10 08:00:00', 'created_by' => $userId,
    ]));
} catch (\App\Services\WarehouseLockedException $e) {
    $blockedDuringOpname = true;
}
check('Movement blocked on warehouse under active opname', $blockedDuringOpname);

Database::transaction(fn (PDO $tx) => StockOpnameService::count($tx, $sessionId, [$itemOp => 95], $userId));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $userId));
$postResult = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $userId));

$stockOpAfter = InventoryService::currentStock($pdo, $itemOp, $whOp);
check('Ending stock = 95kg after opname post', approx($stockOpAfter['qty_base'], 95), "got {$stockOpAfter['qty_base']}");

$adj = $pdo->prepare('SELECT * FROM stock_adjustments WHERE item_id = :item AND warehouse_id = :wh');
$adj->execute(['item' => $itemOp, 'wh' => $whOp]);
$adjRow = $adj->fetch();
check('stock_adjustments row created with delta -5', $adjRow && approx((float) $adjRow['qty_base_delta'], -5), $adjRow ? "delta={$adjRow['qty_base_delta']}" : 'no row');
check('stock_adjustments type = OPNAME', $adjRow && $adjRow['adjustment_type'] === 'OPNAME');

$auditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'STOCK_ADJUSTMENT' AND entity_id = :id");
$auditStmt->execute(['id' => $adjRow['id']]);
check('Audit log exists for the adjustment', ((int) $auditStmt->fetchColumn()) > 0);

// warehouse unblocked after post
$unblockedAfterPost = true;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('unblocked'), 'item_id' => $itemOp, 'warehouse_id' => $whOp,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-10 08:00:00', 'created_by' => $userId,
    ]));
} catch (\App\Services\WarehouseLockedException $e) {
    $unblockedAfterPost = false;
}
check('Warehouse unblocked after opname POSTED', $unblockedAfterPost);

// posting twice must not double-adjust
$adjCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $userId));
$adjCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
check('Posting an already-POSTED opname session again creates no new adjustment', $adjCountBefore === $adjCountAfter, "before={$adjCountBefore} after={$adjCountAfter}");

// =============================================================================
echo "\n== PRODUCTION: raw 100@10.000, consume 40, output 20 units ==\n";
$whProd = makeWarehouse($pdo, uid('WH-PROD'));
$rawItem = makeItem($pdo, uid('SKU-RAW'), $kgUnitId);
$outputItem = makeItem($pdo, uid('SKU-OUTPUT'), $pcsUnitId);

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('raw-in'), 'item_id' => $rawItem, 'warehouse_id' => $whProd,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));

$prodUuid = uid('production');
$prodResult = Database::transaction(fn (PDO $tx) => ProductionService::create($tx, [
    'production_uuid' => $prodUuid, 'warehouse_id' => $whProd, 'production_date' => '2026-09-02 08:00:00',
    'created_by' => $userId,
    'inputs' => [['item_id' => $rawItem, 'input_qty' => 40, 'input_unit_id' => $kgUnitId]],
    'output' => ['item_id' => $outputItem, 'output_qty' => 20, 'output_unit_id' => $pcsUnitId],
]));

check('Production input cost = Rp400.000', approx($prodResult['total_input_cost'], 400000), "got {$prodResult['total_input_cost']}");
check('Output unit cost = Rp20.000/unit (400.000 / 20)', approx($prodResult['output_unit_cost_base'], 20000), "got {$prodResult['output_unit_cost_base']}");

$rawAfter = InventoryService::currentStock($pdo, $rawItem, $whProd);
$outputAfter = InventoryService::currentStock($pdo, $outputItem, $whProd);
check('Raw material remaining = 60kg', approx($rawAfter['qty_base'], 60), "got {$rawAfter['qty_base']}");
check('Output batch = 20 units, value Rp400.000', approx($outputAfter['qty_base'], 20) && approx($outputAfter['value'], 400000), "qty={$outputAfter['qty_base']} value={$outputAfter['value']}");
$totalValueCheck = round($rawAfter['value'] + $outputAfter['value'], 2) == round((60 * 10000) + 400000, 2);
check('Total inventory value conserved through production (600.000 raw + 400.000 output)', $totalValueCheck, "raw_value={$rawAfter['value']} output_value={$outputAfter['value']}");

// idempotency
$prodAgain = Database::transaction(fn (PDO $tx) => ProductionService::create($tx, [
    'production_uuid' => $prodUuid, 'warehouse_id' => $whProd, 'production_date' => '2026-09-02 08:00:00',
    'created_by' => $userId,
    'inputs' => [['item_id' => $rawItem, 'input_qty' => 40, 'input_unit_id' => $kgUnitId]],
    'output' => ['item_id' => $outputItem, 'output_qty' => 20, 'output_unit_id' => $pcsUnitId],
]));
check('Duplicate production_uuid is idempotent (one production only)', $prodAgain['idempotent_replay'] === true);
$prodCount = (int) $pdo->query('SELECT COUNT(*) FROM production_headers')->fetchColumn();

// =============================================================================
echo "\n== BOOK CLOSING: September ending == October opening, period lock rejects late post ==\n";
$whClose = makeWarehouse($pdo, uid('WH-CLOSE'));
$itemClose = makeItem($pdo, uid('SKU-CLOSE'), $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('close-in'), 'item_id' => $itemClose, 'warehouse_id' => $whClose,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 8000,
    'transaction_date' => '2026-08-15 08:00:00', 'created_by' => $userId,
]));

$endingSeptValue = InventoryService::companyOnHandValue($pdo);
$closeResult = Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, '2026-08-01', '2026-12-31', $userId));
check('Book closing succeeds with no blockers', $closeResult['success'] === true);
check('Closing ending_inventory_value matches live company value at close time', approx($closeResult['ending_inventory_value'], $endingSeptValue), "closing={$closeResult['ending_inventory_value']} live={$endingSeptValue}");

$openingOctValue = InventoryService::companyOnHandValue($pdo);
check('September ending == October opening (same live data, nothing moved)', approx($endingSeptValue, $openingOctValue), "sept={$endingSeptValue} oct={$openingOctValue}");

$periodLockRejected = false;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('late-post'), 'item_id' => $itemClose, 'warehouse_id' => $whClose,
        'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 8000,
        'transaction_date' => '2026-08-20 08:00:00', 'created_by' => $userId,
    ]));
} catch (PeriodLockedException $e) {
    $periodLockRejected = true;
}
check('Transaction dated inside the closed period is rejected PERIOD_LOCKED', $periodLockRejected);

// idempotent close
$closeAgain = Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, '2026-08-01', '2026-12-31', $userId));
check('Re-closing an already-LOCKED period is idempotent', $closeAgain['idempotent_replay'] === true);

// D0.4: next_closeable_period — cannot skip ahead to an arbitrary future period
$previewAfterClose = BookClosingService::preview($pdo, '2027-01-01', '2027-01-31');
check('preview() reports next_closeable_period_start = day after the last LOCKED period_end', $previewAfterClose['next_closeable_period_start'] === '2027-01-01', "got {$previewAfterClose['next_closeable_period_start']}");
$skipAheadRejected = false;
try {
    Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, '2027-03-01', '2027-03-31', $userId));
} catch (ValidationException $e) {
    $skipAheadRejected = true;
}
check('close() rejects skipping ahead to a period other than next_closeable_period', $skipAheadRejected);

// =============================================================================
echo "\n== RECONCILIATION: sample output ==\n";
$reconciliation = ReconciliationService::run($pdo);
check('Reconciliation report returns go_live_ready flag', array_key_exists('go_live_ready', $reconciliation));
echo "Sample reconciliation summary: total_sku={$reconciliation['total_sku']}, sku_with_stock={$reconciliation['sku_with_stock']}, company_value={$reconciliation['company_inventory_value']}, go_live_ready=" . ($reconciliation['go_live_ready'] ? 'true' : 'false') . "\n";
foreach ($reconciliation['checks'] as $name => $c) {
    echo "  - {$name}: {$c['status']} ({$c['count']})\n";
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
