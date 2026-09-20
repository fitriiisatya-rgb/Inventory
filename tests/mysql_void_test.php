<?php
declare(strict_types=1);

/**
 * PHASE D0.1 tests against real MySQL/MariaDB: IN void, OUT void, double
 * void, void on a locked period, idempotent void.
 *
 * Usage: php tests/mysql_void_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/BookClosingService.php';
require_once __DIR__ . '/../services/VoidService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use App\Services\BookClosingService;
use App\Services\VoidService;
use App\Services\ValidationException;
use App\Services\PeriodLockedException;

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

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$username = uid('void-user');
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => $username, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId]);
$userId = (int) $pdo->lastInsertId();

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$whCode = uid('WH');
$pdo->prepare("INSERT INTO warehouses (code, name) VALUES (:c,:n)")->execute(['c' => $whCode, 'n' => $whCode]);
$warehouseId = (int) $pdo->lastInsertId();

// =============================================================================
echo "== IN -> VOID ==\n";
$sku = uid('SKU-VOID-IN');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

$inResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('in'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));
$stockBeforeVoid = InventoryService::currentStock($pdo, $itemId, $warehouseId);
check('Stock = 100kg before void', approx($stockBeforeVoid['qty_base'], 100));

$voidResult = Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('void'), 'transaction_id' => $inResult['transaction_id'],
    'reason' => 'test: wrong quantity entered', 'voided_by' => $userId, 'username' => $username,
]));
$stockAfterVoid = InventoryService::currentStock($pdo, $itemId, $warehouseId);
check('Stock = 0kg after voiding the IN', approx($stockAfterVoid['qty_base'], 0), "got {$stockAfterVoid['qty_base']}");

$originalTx = $pdo->prepare('SELECT * FROM inventory_transactions WHERE id = :id');
$originalTx->execute(['id' => $inResult['transaction_id']]);
$originalTx = $originalTx->fetch();
check('Original transaction status = VOID (not deleted)', $originalTx['status'] === 'VOID');
check('Original transaction row still exists with its original data', approx((float) $pdo->query("SELECT base_qty FROM inventory_transaction_lines WHERE transaction_id = {$inResult['transaction_id']}")->fetchColumn(), 100));

$reversalTx = $pdo->prepare('SELECT * FROM inventory_transactions WHERE id = :id');
$reversalTx->execute(['id' => $voidResult['reversal_transaction_id']]);
$reversalTx = $reversalTx->fetch();
check('Reversal transaction created with type REVERSAL, status POSTED', $reversalTx['transaction_type'] === 'REVERSAL' && $reversalTx['status'] === 'POSTED');
check('Reversal.reversal_of_id points back at the original', (int) $reversalTx['reversal_of_id'] === (int) $inResult['transaction_id']);

// idempotent void (same request_uuid), tested against a fresh IN
$sameUuid = uid('void2');
$inResult2 = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('in2'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));
$voidParams = ['request_uuid' => $sameUuid, 'transaction_id' => $inResult2['transaction_id'], 'reason' => 'idempotency test', 'voided_by' => $userId];
$firstVoid = Database::transaction(fn (PDO $tx) => VoidService::void($tx, $voidParams));
$secondVoid = Database::transaction(fn (PDO $tx) => VoidService::void($tx, $voidParams));
check('Idempotent VOID: same request_uuid twice returns the same reversal, no double effect', $secondVoid['idempotent_replay'] === true && $secondVoid['reversal_transaction_id'] === $firstVoid['reversal_transaction_id']);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE reversal_of_id = :id');
$countStmt->execute(['id' => $inResult2['transaction_id']]);
check('Exactly one reversal transaction exists for that original', ((int) $countStmt->fetchColumn()) === 1);

// double VOID (different request_uuid, already-voided transaction) must be REJECTED
$doubleVoidRejected = false;
try {
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
        'request_uuid' => uid('void3'), 'transaction_id' => $inResult2['transaction_id'],
        'reason' => 'trying to void an already-voided transaction', 'voided_by' => $userId,
    ]));
} catch (\App\Services\TransactionAlreadyVoidException $e) {
    // PHASE V2.5: this specific case now throws a dedicated exception
    // (TRANSACTION_ALREADY_VOID) instead of the generic ValidationException
    // it used to — same rejection, a more specific/structured error code.
    $doubleVoidRejected = true;
}
check('Double VOID with a NEW request_uuid on an already-voided transaction is rejected', $doubleVoidRejected);

// =============================================================================
echo "\n== OUT -> VOID (restores exact original FIFO-consumed batches, not current cost) ==\n";
$sku2 = uid('SKU-VOID-OUT');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku2, 'n' => $sku2, 'u' => $kgUnitId]);
$itemId2 = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId2, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('out-in1'), 'item_id' => $itemId2, 'warehouse_id' => $warehouseId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('out-in2'), 'item_id' => $itemId2, 'warehouse_id' => $warehouseId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 12000,
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $userId,
]));
// OUT 150 consumes 100@10.000 + 50@12.000 (FIFO)
$outResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('out'), 'item_id' => $itemId2, 'warehouse_id' => $warehouseId,
    'input_qty' => 150, 'input_unit_id' => $kgUnitId,
    'transaction_date' => '2026-09-03 08:00:00', 'created_by' => $userId,
]));
// meanwhile the "current" price for this item has since drifted upward
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('out-in3'), 'item_id' => $itemId2, 'warehouse_id' => $warehouseId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 12000,
    'transaction_date' => '2026-09-04 08:00:00', 'created_by' => $userId, 'anomaly_approved_by' => $userId,
]));

$stockBeforeOutVoid = InventoryService::currentStock($pdo, $itemId2, $warehouseId);
check('Stock = 60kg before voiding the OUT (150 consumed from 200 + 10 later)', approx($stockBeforeOutVoid['qty_base'], 60), "got {$stockBeforeOutVoid['qty_base']}");

$voidOutResult = Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('void-out'), 'transaction_id' => $outResult['transaction_id'],
    'reason' => 'test: OUT posted by mistake', 'voided_by' => $userId,
]));
$stockAfterOutVoid = InventoryService::currentStock($pdo, $itemId2, $warehouseId);
check('Stock = 210kg after voiding the OUT (60 + 150 restored)', approx($stockAfterOutVoid['qty_base'], 210), "got {$stockAfterOutVoid['qty_base']}");
check('Restored value uses ORIGINAL FIFO cost (100x10.000 + 50x12.000 = 1.600.000), not the newer 12.000/kg price',
    approx($stockAfterOutVoid['value'], (100 * 10000) + (100 * 12000) + (10 * 12000)), "got value={$stockAfterOutVoid['value']}");

// =============================================================================
echo "\n== VOID on a LOCKED period ==\n";
$sku3 = uid('SKU-VOID-LOCKED');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku3, 'n' => $sku3, 'u' => $kgUnitId]);
$itemId3 = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId3, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

$lockedInResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('locked-in'), 'item_id' => $itemId3, 'warehouse_id' => $warehouseId,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 5000,
    'transaction_date' => '2026-06-15 08:00:00', 'created_by' => $userId,
]));
// Period end deliberately wide (past every other date this file posts) so this
// close() isn't itself blocked by "later data already exists" (BookClosingService
// requires closing in chronological order — see its class docblock).
Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, '2026-06-01', '2026-12-31', $userId));

$lockedVoidRejected = false;
try {
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
        'request_uuid' => uid('void-locked'), 'transaction_id' => $lockedInResult['transaction_id'],
        'reason' => 'trying to void inside a locked period without override', 'voided_by' => $userId,
    ]));
} catch (PeriodLockedException $e) {
    $lockedVoidRejected = true;
}
check('VOID on a transaction inside a LOCKED period is rejected without override', $lockedVoidRejected);

$overrideResult = Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('void-locked-override'), 'transaction_id' => $lockedInResult['transaction_id'],
    'reason' => 'documented superadmin correction', 'voided_by' => $userId, 'superadmin_override' => true,
]));
check('VOID on a locked-period transaction succeeds WITH explicit superadmin_override', $overrideResult['success'] === true);

$auditRow = $pdo->prepare("SELECT * FROM audit_logs WHERE action_code = 'TRANSACTION_VOID' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$auditRow->execute(['id' => $lockedInResult['transaction_id']]);
$auditRow = $auditRow->fetch();
check('Audit log records the locked-period override', $auditRow && str_contains((string) $auditRow['after_data'], 'locked_period_override'));

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
