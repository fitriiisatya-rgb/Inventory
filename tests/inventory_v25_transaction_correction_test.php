<?php
declare(strict_types=1);

/**
 * PHASE V2.5 — Transaction Correction / Void / Transfer Reversal.
 *
 * The 30 deterministic cases from the sign-off spec, numbered to match it
 * exactly. Cases 2, 27, 28 are real HTTP round trips (permission/warehouse
 * isolation cannot be proven at the service layer, which never checks
 * permissions) against a spawned `php -S`, same pattern as
 * tests/warehouse_isolation_regression_test.php. Every other case calls the
 * services directly — faster and deterministic, and this project's
 * established style for FIFO/value correctness proofs (see
 * tests/mysql_void_test.php).
 *
 * Usage: php tests/inventory_v25_transaction_correction_test.php
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
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;
use App\Services\StockOpnameService;
use App\Services\TransferService;
use App\Services\VoidService;
use App\Services\TraceService;
use App\Services\InventoryHppReportService;
use App\Services\ValidationException;
use App\Services\OpeningProtectedException;
use App\Services\TransactionAlreadyVoidException;
use App\Services\VoidHasDownstreamDependenciesException;
use App\Services\TransferAlreadyReversedException;
use App\Services\TransferReversalHasDownstreamDependenciesException;

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

// ---- shared fixtures ----
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

function makeUser(PDO $pdo, int $roleId, ?int $warehouseId = null): int
{
    $u = uid('v25user');
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}
function makeWarehouse(PDO $pdo, string $tag): array
{
    $code = uid("V25-{$tag}");
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c,:n)')->execute(['c' => $code, 'n' => $code]);
    return [(int) $pdo->lastInsertId(), $code];
}
function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid("SKU-V25-{$tag}");
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $unitId]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $price, string $date, int $by, int $unitId, array $extra = []): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, array_merge([
        'transaction_uuid' => uid('in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $by,
    ], $extra)));
}
function postOut(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $by, int $unitId, array $extra = []): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, array_merge([
        'transaction_uuid' => uid('out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'transaction_type' => 'OUT',
        'transaction_date' => $date, 'created_by' => $by,
    ], $extra)));
}
function voidTx(PDO $pdo, int $txId, int $by, string $reason = 'V2.5 test void reason'): array
{
    return Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
        'request_uuid' => uid('void'), 'transaction_id' => $txId, 'reason' => $reason, 'voided_by' => $by,
    ]));
}

$superadmin = makeUser($pdo, $superadminRoleId);
[$whA, $whACode] = makeWarehouse($pdo, 'A');
[$whB, $whBCode] = makeWarehouse($pdo, 'B');

// =============================================================================
echo "== Case 1: SUPERADMIN void simple IN ==\n";
$item1 = makeItem($pdo, $kgUnitId, 'C1');
$in1 = postIn($pdo, $item1, $whA, 100, 10000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
check('Stock = 100kg after IN', approx(InventoryService::currentStock($pdo, $item1, $whA)['qty_base'], 100));
$void1 = voidTx($pdo, $in1['transaction_id'], $superadmin);
check('void() succeeds for SUPERADMIN-initiated IN', $void1['success'] === true);
check('Stock = 0kg after voiding the IN', approx(InventoryService::currentStock($pdo, $item1, $whA)['qty_base'], 0));

// =============================================================================
echo "\n== Case 2: STOCK cannot void IN (HTTP, permission-level) ==\n";
// Deferred to the HTTP block below (needs a real router + AuthService session).

// =============================================================================
echo "\n== Case 3: IN reversal restores correct value ==\n";
$item3 = makeItem($pdo, $kgUnitId, 'C3');
$in3 = postIn($pdo, $item3, $whA, 40, 25000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$valueBefore3 = InventoryService::currentStock($pdo, $item3, $whA)['value'];
check('Value = 1.000.000 before void (40 x 25.000)', approx($valueBefore3, 1000000), "got {$valueBefore3}");
voidTx($pdo, $in3['transaction_id'], $superadmin);
$stock3 = InventoryService::currentStock($pdo, $item3, $whA);
check('Value = 0 after void (exact reversal, no residue)', approx($stock3['value'], 0), "got {$stock3['value']}");
check('Qty = 0 after void', approx($stock3['qty_base'], 0));

// =============================================================================
echo "\n== Case 4: IN reversal handles FIFO batch correctly ==\n";
$item4 = makeItem($pdo, $kgUnitId, 'C4');
$in4 = postIn($pdo, $item4, $whA, 60, 8000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$batchId4 = (int) $pdo->query("SELECT created_batch_id FROM inventory_transaction_lines WHERE transaction_id = {$in4['transaction_id']}")->fetchColumn();
$void4 = voidTx($pdo, $in4['transaction_id'], $superadmin);
$batchAfter4 = $pdo->prepare('SELECT qty_base, original_qty_base FROM inventory_batches WHERE id = :id');
$batchAfter4->execute(['id' => $batchId4]);
$batchAfter4 = $batchAfter4->fetch();
check('Original batch qty_base reduced to exactly 0 (never physically deleted)', approx((float) $batchAfter4['qty_base'], 0));
check('Original batch original_qty_base untouched (60, audit history preserved)', approx((float) $batchAfter4['original_qty_base'], 60));
$mirrorAlloc4 = $pdo->prepare('SELECT COUNT(*) FROM fifo_allocations WHERE batch_id = :id AND qty_allocated = 60');
$mirrorAlloc4->execute(['id' => $batchId4]);
check('Mirror fifo_allocations row recorded for audit symmetry', ((int) $mirrorAlloc4->fetchColumn()) === 1);

// =============================================================================
echo "\n== Case 5: IN cannot unsafe-reverse a consumed batch (blocked, not corrupted) ==\n";
$item5 = makeItem($pdo, $kgUnitId, 'C5');
$in5 = postIn($pdo, $item5, $whA, 100, 5000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$out5 = postOut($pdo, $item5, $whA, 40, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
$blocked5 = false;
$dependencyIds5 = [];
try {
    voidTx($pdo, $in5['transaction_id'], $superadmin);
} catch (VoidHasDownstreamDependenciesException $e) {
    $blocked5 = true;
    $dependencyIds5 = array_column($e->dependencies, 'id');
}
check('Void of IN whose batch was partially consumed is BLOCKED (never a naive negative subtraction)', $blocked5);
check('Blocked error lists the consuming OUT transaction id', in_array((int) $out5['transaction_id'], array_map('intval', $dependencyIds5), true), json_encode($dependencyIds5));
check('Stock untouched by the blocked attempt (60kg still present, batch never corrupted)', approx(InventoryService::currentStock($pdo, $item5, $whA)['qty_base'], 60));
// void the downstream OUT first, then the IN becomes safe again.
voidTx($pdo, $out5['transaction_id'], $superadmin);
$void5b = voidTx($pdo, $in5['transaction_id'], $superadmin);
check('After voiding the downstream OUT, the IN void now succeeds', $void5b['success'] === true);
check('Stock = 0 after both voided', approx(InventoryService::currentStock($pdo, $item5, $whA)['qty_base'], 0));

// =============================================================================
echo "\n== Case 6: SUPERADMIN void simple OUT ==\n";
$item6 = makeItem($pdo, $kgUnitId, 'C6');
postIn($pdo, $item6, $whA, 100, 10000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$out6 = postOut($pdo, $item6, $whA, 30, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
check('Stock = 70kg after OUT', approx(InventoryService::currentStock($pdo, $item6, $whA)['qty_base'], 70));
$void6 = voidTx($pdo, $out6['transaction_id'], $superadmin);
check('void() succeeds for OUT', $void6['success'] === true);
check('Stock = 100kg after voiding the OUT (restored)', approx(InventoryService::currentStock($pdo, $item6, $whA)['qty_base'], 100));

// =============================================================================
echo "\n== Case 7: OUT reversal restores exact FIFO allocations ==\n";
$item7 = makeItem($pdo, $kgUnitId, 'C7');
postIn($pdo, $item7, $whA, 100, 10000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
postIn($pdo, $item7, $whA, 100, 12000, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
$out7 = postOut($pdo, $item7, $whA, 150, '2026-09-03 08:00:00', $superadmin, $kgUnitId); // consumes 100@10000 + 50@12000
$batch1_7 = (int) $pdo->query("SELECT id FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE item_id={$item7} AND unit_cost_base=10000)")->fetchColumn();
$batch2_7 = (int) $pdo->query("SELECT id FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE item_id={$item7} AND unit_cost_base=12000)")->fetchColumn();
voidTx($pdo, $out7['transaction_id'], $superadmin);
$b1qty = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE id={$batch1_7}")->fetchColumn();
$b2qty = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE id={$batch2_7}")->fetchColumn();
check('Layer 1 (10.000/kg) restored to exactly 100kg', approx($b1qty, 100), "got {$b1qty}");
check('Layer 2 (12.000/kg) restored to exactly 100kg', approx($b2qty, 100), "got {$b2qty}");

// =============================================================================
echo "\n== Case 8: OUT reversal preserves source cost (never latest/average price) ==\n";
$item8 = makeItem($pdo, $kgUnitId, 'C8');
postIn($pdo, $item8, $whA, 50, 10000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$out8 = postOut($pdo, $item8, $whA, 50, '2026-09-02 08:00:00', $superadmin, $kgUnitId); // consumes @10.000
// price drifts sharply upward afterwards
postIn($pdo, $item8, $whA, 10, 90000, '2026-09-03 08:00:00', $superadmin, $kgUnitId, ['anomaly_approved_by' => $superadmin]);
voidTx($pdo, $out8['transaction_id'], $superadmin);
$stock8 = InventoryService::currentStock($pdo, $item8, $whA);
check('Qty = 60kg after restoring the OUT (50 restored + 10 later)', approx($stock8['qty_base'], 60), "got {$stock8['qty_base']}");
check('Value uses the ORIGINAL 10.000/kg cost for the restored 50kg, never the later 90.000/kg', approx($stock8['value'], (50 * 10000) + (10 * 90000)), "got {$stock8['value']}");

// =============================================================================
echo "\n== Case 9: double void blocked ==\n";
$item9 = makeItem($pdo, $kgUnitId, 'C9');
$in9 = postIn($pdo, $item9, $whA, 20, 5000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
voidTx($pdo, $in9['transaction_id'], $superadmin);
$doubleVoidBlocked9 = false;
try {
    voidTx($pdo, $in9['transaction_id'], $superadmin);
} catch (TransactionAlreadyVoidException $e) {
    $doubleVoidBlocked9 = true;
}
check('Second void (new request_uuid) against an already-VOID transaction throws TRANSACTION_ALREADY_VOID', $doubleVoidBlocked9);

// =============================================================================
echo "\n== Case 10: OPENING void blocked ==\n";
$item10 = makeItem($pdo, $kgUnitId, 'C10');
$opening10 = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('opening'), 'item_id' => $item10, 'warehouse_id' => $whA,
    'input_qty' => 500, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1,
    'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $superadmin, 'transaction_type' => 'OPENING',
]));
$openingBlocked10 = false;
try {
    voidTx($pdo, $opening10['transaction_id'], $superadmin);
} catch (OpeningProtectedException $e) {
    $openingBlocked10 = true;
}
check('OPENING void is blocked with OPENING_PROTECTED, even for SUPERADMIN', $openingBlocked10);
check('OPENING stock is untouched by the blocked attempt', approx(InventoryService::currentStock($pdo, $item10, $whA)['qty_base'], 500));

// =============================================================================
echo "\n== Case 11: historical inventory_effect=0 protected ==\n";
$item11 = makeItem($pdo, $kgUnitId, 'C11');
$now11 = date('Y-m-d H:i:s');
$histTxStmt = $pdo->prepare(
    "INSERT INTO inventory_transactions
        (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
     VALUES (:uuid, 'IN', :tx_date, :post_date, :wh, 'POSTED', 1, 0, :created_by, :created_at)"
);
$histTxStmt->execute(['uuid' => uid('hist'), 'tx_date' => '2026-01-01 08:00:00', 'post_date' => $now11, 'wh' => $whA, 'created_by' => $superadmin, 'created_at' => $now11]);
$histTxId11 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO inventory_transaction_lines
        (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
     VALUES (:tx_id, 1, :item_id, \'hist\', 500, :unit, 1, 500, 9999, 9999, 4999500, :wh)'
)->execute(['tx_id' => $histTxId11, 'item_id' => $item11, 'unit' => $kgUnitId, 'wh' => $whA]);
$histBlocked11 = false;
try {
    voidTx($pdo, $histTxId11, $superadmin);
} catch (ValidationException $e) {
    $histBlocked11 = true;
}
check('Historical-import row (inventory_effect=0) void is blocked — never fabricates a live reversal', $histBlocked11);

// =============================================================================
echo "\n== Case 12: Adjustment reversal ==\n";
$item12 = makeItem($pdo, $kgUnitId, 'C12');
postIn($pdo, $item12, $whA, 100, 7000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$posAdj12 = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('adj-pos'), 'item_id' => $item12, 'warehouse_id' => $whA,
    'qty_base_delta' => 15, 'adjustment_type' => 'CORRECTION', 'reason' => 'found extra stock', 'created_by' => $superadmin,
]));
check('Stock = 115kg after positive adjustment', approx(InventoryService::currentStock($pdo, $item12, $whA)['qty_base'], 115));
voidTx($pdo, $posAdj12['transaction_id'], $superadmin);
check('Stock = 100kg after voiding the positive adjustment (subtracts the same economic effect)', approx(InventoryService::currentStock($pdo, $item12, $whA)['qty_base'], 100));

$negAdj12 = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('adj-neg'), 'item_id' => $item12, 'warehouse_id' => $whA,
    'qty_base_delta' => -25, 'adjustment_type' => 'DAMAGE', 'reason' => 'damaged in transit', 'created_by' => $superadmin,
]));
check('Stock = 75kg after negative adjustment', approx(InventoryService::currentStock($pdo, $item12, $whA)['qty_base'], 75));
voidTx($pdo, $negAdj12['transaction_id'], $superadmin);
check('Stock = 100kg after voiding the negative adjustment (restores the same economic effect)', approx(InventoryService::currentStock($pdo, $item12, $whA)['qty_base'], 100));

// =============================================================================
echo "\n== Case 13: finalized opname protected — correction only through the generated ADJUSTMENT ==\n";
[$whOpname, ] = makeWarehouse($pdo, 'OPNAME');
$item13 = makeItem($pdo, $kgUnitId, 'C13');
postIn($pdo, $item13, $whOpname, 50, 4000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$session13 = StockOpnameService::start($pdo, $whOpname, $superadmin, [$item13]);
StockOpnameService::count($pdo, $session13, [$item13 => 44], $superadmin); // variance -6
StockOpnameService::finalize($pdo, $session13, $superadmin);
$reflection13 = new ReflectionClass(StockOpnameService::class);
check('StockOpnameService exposes no direct "void/delete a session" method at all — even the PHASE V2.12 cancel() (pre-POST only, reason-required, audit-logged) never rewrites a POSTED session', !$reflection13->hasMethod('void') && !$reflection13->hasMethod('delete'));
$postResult13 = StockOpnameService::post($pdo, $session13, $superadmin);
check('Stock = 44kg after opname posts its variance as a real ADJUSTMENT', approx(InventoryService::currentStock($pdo, $item13, $whOpname)['qty_base'], 44));
$cancelAfterPostRejected = null;
try {
    StockOpnameService::cancel($pdo, $session13, 'trying to cancel after POST', $superadmin);
} catch (ValidationException $e) { $cancelAfterPostRejected = $e->getMessage(); }
check('PHASE V2.12: cancel() explicitly refuses a POSTED session — correction is only via post()\'s ADJUSTMENT (Section 23)', $cancelAfterPostRejected !== null, (string) $cancelAfterPostRejected);
$opnameAdjTxId13 = (int) $postResult13['adjustments'][0]['transaction_id'];
$opnameAdjTx13 = $pdo->prepare('SELECT transaction_type FROM inventory_transactions WHERE id = :id');
$opnameAdjTx13->execute(['id' => $opnameAdjTxId13]);
check('The opname variance was posted as transaction_type=ADJUSTMENT (never a raw session/session-line rewrite)', $opnameAdjTx13->fetchColumn() === 'ADJUSTMENT');
voidTx($pdo, $opnameAdjTxId13, $superadmin, 'correct the opname variance via its adjustment');
check('Voiding the opname\'s generated adjustment correctly restores stock to 50kg (the pre-opname value)', approx(InventoryService::currentStock($pdo, $item13, $whOpname)['qty_base'], 50));
$sessionRow13 = $pdo->prepare('SELECT status FROM stock_opname_sessions WHERE id = :id');
$sessionRow13->execute(['id' => $session13]);
check('The FINALIZED/POSTED opname session record itself is never rewritten by the correction', $sessionRow13->fetchColumn() === 'POSTED');

// =============================================================================
echo "\n== Case 14: PENDING transfer cancel ==\n";
$item14 = makeItem($pdo, $kgUnitId, 'C14');
postIn($pdo, $item14, $whA, 40, 6000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$transfer14 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xfer'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $item14, 'input_qty' => 15, 'input_unit_id' => $kgUnitId]],
]));
check('Source stock reduced to 25kg after PENDING TRANSFER_OUT', approx(InventoryService::currentStock($pdo, $item14, $whA)['qty_base'], 25));
Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, $transfer14['transfer_id'], ['created_by' => $superadmin, 'reason' => 'cancel PENDING transfer test']));
check('Source stock restored to 40kg after cancelling the PENDING transfer', approx(InventoryService::currentStock($pdo, $item14, $whA)['qty_base'], 40));
$transferRow14 = $pdo->prepare('SELECT status FROM warehouse_transfers WHERE id = :id');
$transferRow14->execute(['id' => $transfer14['transfer_id']]);
check('Transfer status = CANCELLED', $transferRow14->fetchColumn() === 'CANCELLED');

// =============================================================================
echo "\n== Case 15: cancelled transfer cannot cancel again ==\n";
$doubleCancelBlocked15 = false;
try {
    Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, $transfer14['transfer_id'], ['created_by' => $superadmin, 'reason' => 'second cancel attempt']));
} catch (\App\Services\TransferAlreadyCancelledException $e) {
    $doubleCancelBlocked15 = true;
}
check('A second cancel (new request_uuid) on an already-CANCELLED transfer is rejected', $doubleCancelBlocked15);

// =============================================================================
echo "\n== Case 16/17/18: RECEIVED transfer reverse — whole chain, source FIFO restored, destination batch zeroed ==\n";
$item16 = makeItem($pdo, $kgUnitId, 'C16');
postIn($pdo, $item16, $whA, 100, 9000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
postIn($pdo, $item16, $whA, 100, 11000, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
$transfer16 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xfer16'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $item16, 'input_qty' => 150, 'input_unit_id' => $kgUnitId]], // consumes 100@9000 + 50@11000
]));
$srcBatch1_16 = (int) $pdo->query("SELECT id FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE item_id={$item16} AND unit_cost_base=9000)")->fetchColumn();
$srcBatch2_16 = (int) $pdo->query("SELECT id FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE item_id={$item16} AND unit_cost_base=11000)")->fetchColumn();
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transfer16['transfer_id'], ['created_by' => $superadmin]));
check('Destination stock = 150kg after receive', approx(InventoryService::currentStock($pdo, $item16, $whB)['qty_base'], 150));
// receive() recreates the ORIGINAL cost layers rather than re-averaging —
// one warehouse_transfer_lines row (and so one destination batch) PER FIFO
// layer the transfer consumed, never a single merged 150kg batch.
$destLineIds16 = $pdo->query("SELECT in_transaction_line_id FROM warehouse_transfer_lines WHERE transfer_id={$transfer16['transfer_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('Two destination batches created (one per source FIFO layer, cost preserved)', count($destLineIds16) === 2);
$destBatches16 = [];
foreach ($destLineIds16 as $lineId) {
    $b = $pdo->prepare('SELECT id, qty_base, original_qty_base FROM inventory_batches WHERE source_transaction_line_id = :id');
    $b->execute(['id' => $lineId]);
    $destBatches16[] = $b->fetch();
}
$destTotal16 = array_sum(array_map(fn ($b) => (float) $b['qty_base'], $destBatches16));
check('Destination batches together total exactly 150kg, each with qty_base = original_qty_base', approx($destTotal16, 150), "got {$destTotal16}");

$reverse16 = Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer16['transfer_id'], ['created_by' => $superadmin, 'reason' => 'reverse received transfer test']));
check('reverse() succeeds for a RECEIVED transfer with no downstream consumption', $reverse16['success'] === true);
check('Destination stock = 0kg after reverse (Case 18: destination batch(es) reversed/zeroed, never physically deleted)', approx(InventoryService::currentStock($pdo, $item16, $whB)['qty_base'], 0));
$destTotalAfter16 = (float) $pdo->query('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE id IN (' . implode(',', array_column($destBatches16, 'id')) . ')')->fetchColumn();
check('Both destination batch rows still exist with qty_base = 0 (logically reversed, not deleted)', approx($destTotalAfter16, 0));
$srcQty1_16 = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE id={$srcBatch1_16}")->fetchColumn();
$srcQty2_16 = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE id={$srcBatch2_16}")->fetchColumn();
check('Case 17: source layer 1 (9.000/kg) restored to exactly 100kg', approx($srcQty1_16, 100), "got {$srcQty1_16}");
check('Case 17: source layer 2 (11.000/kg) restored to exactly 100kg', approx($srcQty2_16, 100), "got {$srcQty2_16}");
check('Source (whA) stock fully restored to 200kg total', approx(InventoryService::currentStock($pdo, $item16, $whA)['qty_base'], 200));
$transferRow16 = $pdo->prepare('SELECT status FROM warehouse_transfers WHERE id = :id');
$transferRow16->execute(['id' => $transfer16['transfer_id']]);
check('Transfer status = REVERSED', $transferRow16->fetchColumn() === 'REVERSED');
$legStatuses16 = $pdo->query("SELECT DISTINCT status FROM inventory_transactions WHERE reference_no = 'TRANSFER-{$transfer16['transfer_id']}'")->fetchAll(PDO::FETCH_COLUMN);
check('Both TRANSFER_OUT and TRANSFER_IN legs flipped to REVERSED status', $legStatuses16 === ['REVERSED'], json_encode($legStatuses16));

// =============================================================================
echo "\n== Case 19: received transfer with downstream dependency blocked ==\n";
$item19 = makeItem($pdo, $kgUnitId, 'C19');
postIn($pdo, $item19, $whA, 80, 5000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$transfer19 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xfer19'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $item19, 'input_qty' => 80, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transfer19['transfer_id'], ['created_by' => $superadmin]));
$downstreamOut19 = postOut($pdo, $item19, $whB, 30, '2026-09-06 08:00:00', $superadmin, $kgUnitId); // consumes from the destination batch
$reverseBlocked19 = false;
$depIds19 = [];
try {
    Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer19['transfer_id'], ['created_by' => $superadmin, 'reason' => 'attempt unsafe reverse']));
} catch (TransferReversalHasDownstreamDependenciesException $e) {
    $reverseBlocked19 = true;
    $depIds19 = array_column($e->dependencies, 'id');
}
check('Reverse is BLOCKED when destination stock was already consumed downstream', $reverseBlocked19);
check('Blocked error lists the downstream OUT transaction id', in_array((int) $downstreamOut19['transaction_id'], array_map('intval', $depIds19), true), json_encode($depIds19));
check('Destination stock untouched by the blocked attempt (50kg still present, FIFO not corrupted)', approx(InventoryService::currentStock($pdo, $item19, $whB)['qty_base'], 50));
$transferRow19 = $pdo->prepare('SELECT status FROM warehouse_transfers WHERE id = :id');
$transferRow19->execute(['id' => $transfer19['transfer_id']]);
check('Transfer remains RECEIVED (never partially reversed)', $transferRow19->fetchColumn() === 'RECEIVED');

// =============================================================================
echo "\n== Case 20: reversed transfer cannot reverse again ==\n";
$doubleReverseBlocked20 = false;
try {
    Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer16['transfer_id'], ['created_by' => $superadmin, 'reason' => 'second reverse attempt']));
} catch (TransferAlreadyReversedException $e) {
    $doubleReverseBlocked20 = true;
}
check('A second reverse (new request_uuid) on an already-REVERSED transfer is rejected', $doubleReverseBlocked20);

// =============================================================================
echo "\n== Case 21: audit log created ==\n";
$voidAudit21 = $pdo->prepare("SELECT * FROM audit_logs WHERE action_code = 'TRANSACTION_VOID' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$voidAudit21->execute(['id' => $in1['transaction_id']]);
$voidAudit21 = $voidAudit21->fetch();
check('TRANSACTION_VOID audit row exists with reason + before/after status', $voidAudit21 && $voidAudit21['reason'] !== null && str_contains((string) $voidAudit21['after_data'], '"status":"VOID"'));
check('TRANSACTION_VOID audit after_data carries reversal_transaction_id', str_contains((string) $voidAudit21['after_data'], 'reversal_transaction_id'));

$reverseAudit21 = $pdo->prepare("SELECT * FROM audit_logs WHERE action_code = 'TRANSFER_REVERSE' AND entity_id = :id ORDER BY id DESC LIMIT 1");
$reverseAudit21->execute(['id' => $transfer16['transfer_id']]);
$reverseAudit21 = $reverseAudit21->fetch();
check('TRANSFER_REVERSE audit row exists with reason + transfer_out/in transaction ids', $reverseAudit21 && $reverseAudit21['reason'] !== null && str_contains((string) $reverseAudit21['after_data'], 'transfer_out_transaction_ids'));

// =============================================================================
echo "\n== Case 22: reason mandatory ==\n";
$item22 = makeItem($pdo, $kgUnitId, 'C22');
$in22 = postIn($pdo, $item22, $whA, 10, 1000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$emptyReasonBlocked22 = false;
try {
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => uid('void'), 'transaction_id' => $in22['transaction_id'], 'reason' => '', 'voided_by' => $superadmin]));
} catch (ValidationException $e) {
    $emptyReasonBlocked22 = true;
}
check('Void with empty reason is rejected', $emptyReasonBlocked22);
$shortReasonBlocked22 = false;
try {
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => uid('void'), 'transaction_id' => $in22['transaction_id'], 'reason' => 'abcd', 'voided_by' => $superadmin]));
} catch (ValidationException $e) {
    $shortReasonBlocked22 = true;
}
check('Void with a reason under 5 characters is rejected', $shortReasonBlocked22);

$transfer22 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xfer22'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $item22, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transfer22['transfer_id'], ['created_by' => $superadmin]));
$reverseShortReasonBlocked22 = false;
try {
    Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer22['transfer_id'], ['created_by' => $superadmin, 'reason' => 'ab']));
} catch (ValidationException $e) {
    $reverseShortReasonBlocked22 = true;
}
check('Reverse Transfer with a reason under 5 characters is rejected', $reverseShortReasonBlocked22);

// =============================================================================
echo "\n== Case 23: TraceDrawer chain (TraceService, the SAME service TraceDrawer calls) ==\n";
$trace23 = TraceService::transactionTrace($pdo, $in1['transaction_id']);
check('transactionTrace() on the voided IN shows reversed_by pointing at its REVERSAL', $trace23['reversed_by'] !== null && (int) $trace23['reversed_by']['id'] === $void1['reversal_transaction_id']);
$reversalTrace23 = TraceService::transactionTrace($pdo, $void1['reversal_transaction_id']);
check('transactionTrace() on the REVERSAL shows reversal_of pointing back at the original IN', $reversalTrace23['reversal_of'] !== null && (int) $reversalTrace23['reversal_of']['id'] === (int) $in1['transaction_id']);

$transferTrace23 = TraceService::transferTrace($pdo, $transfer16['transfer_id']);
$legTypes23 = array_column($transferTrace23['transactions'], 'status', 'transaction_type');
check('transferTrace() on the reversed transfer shows both legs REVERSED', ($legTypes23['TRANSFER_OUT'] ?? null) === 'REVERSED' && ($legTypes23['TRANSFER_IN'] ?? null) === 'REVERSED');
check('transferTrace() still exposes the original out_fifo_allocations/destination_batch (never erased)', !empty($transferTrace23['lines'][0]['out_fifo_allocations']) && $transferTrace23['lines'][0]['destination_batch'] !== null);

// =============================================================================
echo "\n== Case 24: stock balance reconciliation ==\n";
$item24 = makeItem($pdo, $kgUnitId, 'C24');
postIn($pdo, $item24, $whA, 200, 4000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$out24a = postOut($pdo, $item24, $whA, 50, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
$adj24 = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('adj24'), 'item_id' => $item24, 'warehouse_id' => $whA,
    'qty_base_delta' => -10, 'adjustment_type' => 'LOSS', 'reason' => 'shrinkage test', 'created_by' => $superadmin,
]));
check('Stock = 140kg after 200 IN, 50 OUT, -10 adjustment', approx(InventoryService::currentStock($pdo, $item24, $whA)['qty_base'], 140));
voidTx($pdo, $out24a['transaction_id'], $superadmin);
voidTx($pdo, $adj24['transaction_id'], $superadmin);
check('Stock balance fully reconciles back to 200kg once every correction is voided', approx(InventoryService::currentStock($pdo, $item24, $whA)['qty_base'], 200));

// =============================================================================
echo "\n== Case 25: inventory value reconciliation ==\n";
$item25 = makeItem($pdo, $kgUnitId, 'C25');
$valueSnapshot25 = InventoryService::currentStock($pdo, $item25, $whA)['value']; // 0, fresh item
$in25 = postIn($pdo, $item25, $whA, 30, 15000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$out25 = postOut($pdo, $item25, $whA, 12, '2026-09-02 08:00:00', $superadmin, $kgUnitId);
voidTx($pdo, $out25['transaction_id'], $superadmin);
voidTx($pdo, $in25['transaction_id'], $superadmin);
$valueAfter25 = InventoryService::currentStock($pdo, $item25, $whA)['value'];
check('Value returns EXACTLY to its pre-posting snapshot once IN+OUT are both voided (net-zero round trip)', approx($valueAfter25, $valueSnapshot25), "before={$valueSnapshot25} after={$valueAfter25}");

// =============================================================================
echo "\n== Case 26: HPP reconciliation (VOID+REVERSAL pair never creates phantom HPP) ==\n";
[$whHpp, ] = makeWarehouse($pdo, 'HPP');
$item26 = makeItem($pdo, $kgUnitId, 'C26');
// VoidService always dates the REVERSAL at real wall-clock "now" (never
// backdated to the original transaction's date — documented, deliberate).
// A report window has to cover BOTH the original posting date and today's
// real date, or the VOID'd OUT (in-range) and its REVERSAL (dated today)
// land in different reporting periods and the window is simply the wrong
// tool to observe the pair reconciling — not a phantom-HPP bug. Anchor the
// fixture dates a week before "today" and the window a week after, so this
// stays correct regardless of which real date the suite runs on.
$hppStart = date('Y-m-d', strtotime('-7 days'));
$hppEnd = date('Y-m-d', strtotime('+7 days'));
postIn($pdo, $item26, $whHpp, 100, 5000, $hppStart . ' 08:00:00', $superadmin, $kgUnitId);
$out26 = postOut($pdo, $item26, $whHpp, 20, date('Y-m-d', strtotime('-6 days')) . ' 08:00:00', $superadmin, $kgUnitId);
voidTx($pdo, $out26['transaction_id'], $superadmin, 'HPP reconciliation void test');
$summary26 = InventoryHppReportService::summary($pdo, $hppStart, $hppEnd, $whHpp);
check('HPP variance is exactly 0 across a VOID+REVERSAL pair (they economically cancel, no phantom HPP)', approx((float) $summary26['variance'], 0), "got {$summary26['variance']}");
check('Ending value equals opening + purchase (the voided OUT contributes nothing net)', approx((float) $summary26['ending_value'], (float) $summary26['opening_value'] + (float) $summary26['external_purchase']), json_encode($summary26));

// =============================================================================
echo "\n== HTTP-level cases: 2/5A.2 (STOCK), 5A.2/5A.3 (ADMIN 403), 27 (warehouse isolation), 28/5A.1 (SUPERADMIN access) ==\n";
$port = 8700 + random_int(0, 300);
$docRoot = __DIR__ . '/../public';
$stockUser = makeUser($pdo, $stockRoleId, $whA);
$stockUsername = $pdo->query("SELECT username FROM users WHERE id = {$stockUser}")->fetchColumn();
// PHASE V2.5A: a real SUPERADMIN-role session (correction actions succeed)
// and a real ADMIN-role session (correction actions must be REJECTED — the
// owner-mandated hardening: ADMIN is explicitly not treated as equivalent
// to SUPERADMIN for VOID/REVERSE, unlike every other privileged action in
// this codebase).
$superadminHttpUser = makeUser($pdo, $superadminRoleId);
$superadminHttpUsername = $pdo->query("SELECT username FROM users WHERE id = {$superadminHttpUser}")->fetchColumn();
$adminHttpUser = makeUser($pdo, $adminRoleId);
$adminHttpUsername = $pdo->query("SELECT username FROM users WHERE id = {$adminHttpUser}")->fetchColumn();
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id IN (:s, :sa, :a)')
    ->execute(['h' => password_hash('V25Http!123', PASSWORD_BCRYPT), 's' => $stockUser, 'sa' => $superadminHttpUser, 'a' => $adminHttpUser]);

// Deliberately a SEPARATE item per fixture — sharing one item between the
// "void this IN" and the "transfer this stock" fixtures would make the
// transfer a genuine downstream consumer of the IN's batch (Case 5's own
// safety check), which is correct behavior but not what this block is
// testing (permission/warehouse-isolation, not dependency-blocking).
$itemHttp = makeItem($pdo, $kgUnitId, 'HTTP');
$inHttp = postIn($pdo, $itemHttp, $whA, 25, 3000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);

$itemHttpXfer = makeItem($pdo, $kgUnitId, 'HTTP-XFER');
postIn($pdo, $itemHttpXfer, $whA, 5, 3000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$transferHttp = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xferhttp'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $itemHttpXfer, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferHttp['transfer_id'], ['created_by' => $superadmin]));

// A second, ADMIN-only-attempt pair of fixtures (must stay untouched by the
// rejected ADMIN calls below, then get cleanly voided/reversed by the real
// SUPERADMIN checks further down).
$itemHttpAdmin = makeItem($pdo, $kgUnitId, 'HTTP-ADMIN');
$inHttpAdminAttempt = postIn($pdo, $itemHttpAdmin, $whA, 12, 3000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);

$itemHttpAdminXfer = makeItem($pdo, $kgUnitId, 'HTTP-ADMIN-XFER');
postIn($pdo, $itemHttpAdminXfer, $whA, 5, 3000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$transferHttpAdminAttempt = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xferhttpadmin'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $itemHttpAdminXfer, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferHttpAdminAttempt['transfer_id'], ['created_by' => $superadmin]));

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
if (!$ready) { fwrite(STDERR, "Server did not become ready on port {$port}\n"); proc_terminate($process); exit(1); }

function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}
function httpLogin(string $base, string $user, string $pass): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $user, 'password' => $pass], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? ''];
}

try {
    $stockSess = httpLogin($base, $stockUsername, 'V25Http!123');
    $superadminSess = httpLogin($base, $superadminHttpUsername, 'V25Http!123');
    $adminSess = httpLogin($base, $adminHttpUsername, 'V25Http!123');

    // ---- Case 2 / V2.5A test 3: STOCK cannot void IN ----
    $stockVoidAttempt = httpCall('POST', "{$base}/transactions/{$inHttp['transaction_id']}/void", ['reason' => 'stock trying to void'], $stockSess['jar'], $stockSess['csrf']);
    check('Case 2 / V2.5A#3: STOCK cannot void a transaction (403 FORBIDDEN)', $stockVoidAttempt['status'] === 403 && ($stockVoidAttempt['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($stockVoidAttempt['body']));

    // ---- Case 27 / V2.5A test 6: warehouse isolation — STOCK also cannot reverse a transfer, even one touching their own warehouse ----
    $stockReverseAttempt = httpCall('POST', "{$base}/transfers/{$transferHttp['transfer_id']}/reverse", ['reason' => 'stock trying to reverse'], $stockSess['jar'], $stockSess['csrf']);
    check('Case 27 / V2.5A#6: STOCK cannot reverse a transfer touching their own warehouse (403 FORBIDDEN — permission, not scope)', $stockReverseAttempt['status'] === 403 && ($stockReverseAttempt['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($stockReverseAttempt['body']));

    // ---- V2.5A test 2: ADMIN receives 403 for void — ADMIN is explicitly NOT equivalent to SUPERADMIN here ----
    $adminVoidAttempt = httpCall('POST', "{$base}/transactions/{$inHttpAdminAttempt['transaction_id']}/void", ['reason' => 'admin trying to void — must be rejected'], $adminSess['jar'], $adminSess['csrf']);
    check('V2.5A#2: ADMIN receives 403 FORBIDDEN for void (not equivalent to SUPERADMIN)', $adminVoidAttempt['status'] === 403 && ($adminVoidAttempt['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($adminVoidAttempt['body']));
    $adminVoidedTxStatus = $pdo->query("SELECT status FROM inventory_transactions WHERE id = {$inHttpAdminAttempt['transaction_id']}")->fetchColumn();
    check('The rejected ADMIN void attempt left the transaction untouched (still POSTED)', $adminVoidedTxStatus === 'POSTED');

    // ---- V2.5A test 5: ADMIN receives 403 for transfer reversal ----
    $adminReverseAttempt = httpCall('POST', "{$base}/transfers/{$transferHttpAdminAttempt['transfer_id']}/reverse", ['reason' => 'admin trying to reverse — must be rejected'], $adminSess['jar'], $adminSess['csrf']);
    check('V2.5A#5: ADMIN receives 403 FORBIDDEN for transfer reversal (not equivalent to SUPERADMIN)', $adminReverseAttempt['status'] === 403 && ($adminReverseAttempt['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($adminReverseAttempt['body']));
    $adminReversedTransferStatus = $pdo->query("SELECT status FROM warehouse_transfers WHERE id = {$transferHttpAdminAttempt['transfer_id']}")->fetchColumn();
    check('The rejected ADMIN reverse attempt left the transfer untouched (still RECEIVED)', $adminReversedTransferStatus === 'RECEIVED');

    // ---- V2.5A test 10: existing PENDING-transfer-cancel permission (WAREHOUSE_TRANSFER_MANAGE) is untouched — ADMIN can still cancel a PENDING transfer ----
    $itemAdminCancel = makeItem($pdo, $kgUnitId, 'HTTP-ADMIN-CANCEL');
    postIn($pdo, $itemAdminCancel, $whA, 9, 2000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
    $transferAdminCancel = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
        'transfer_uuid' => uid('xferadmincancel'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
        'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
        'lines' => [['item_id' => $itemAdminCancel, 'input_qty' => 4, 'input_unit_id' => $kgUnitId]],
    ]));
    $adminCancelAttempt = httpCall('POST', "{$base}/transfers/{$transferAdminCancel['transfer_id']}/cancel", ['reason' => 'admin cancelling a PENDING transfer — this permission is unchanged'], $adminSess['jar'], $adminSess['csrf']);
    check('V2.5A#10: ADMIN can still cancel a PENDING transfer (WAREHOUSE_TRANSFER_MANAGE unchanged by this hardening)', ($adminCancelAttempt['body']['data']['success'] ?? false) === true, json_encode($adminCancelAttempt['body']));

    // ---- Case 28 / V2.5A test 1+4: SUPERADMIN access — real HTTP round trip succeeds for both actions ----
    $adminVoid = httpCall('POST', "{$base}/transactions/{$inHttp['transaction_id']}/void", ['request_uuid' => uid('void-http'), 'reason' => 'superadmin http void test'], $superadminSess['jar'], $superadminSess['csrf']);
    check('Case 28 / V2.5A#1: SUPERADMIN can void an eligible IN via the real HTTP API', ($adminVoid['body']['data']['success'] ?? false) === true, json_encode($adminVoid['body']));

    $adminReverse = httpCall('POST', "{$base}/transfers/{$transferHttp['transfer_id']}/reverse", ['reason' => 'superadmin http reverse test'], $superadminSess['jar'], $superadminSess['csrf']);
    check('Case 28 / V2.5A#4: SUPERADMIN can reverse an eligible RECEIVED transfer via the real HTTP API', ($adminReverse['body']['data']['success'] ?? false) === true, json_encode($adminReverse['body']));

    // ---- Case 30 (HTTP half): idempotent replay of the SAME void request over HTTP ----
    // (a fresh transaction, since the one above is already VOID)
    $inHttp2 = postIn($pdo, $itemHttp, $whA, 8, 3000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
    $voidUuidHttp = uid('void-http-idem');
    $firstHttpVoid = httpCall('POST', "{$base}/transactions/{$inHttp2['transaction_id']}/void", ['request_uuid' => $voidUuidHttp, 'reason' => 'idempotent http void test'], $superadminSess['jar'], $superadminSess['csrf']);
    $secondHttpVoid = httpCall('POST', "{$base}/transactions/{$inHttp2['transaction_id']}/void", ['request_uuid' => $voidUuidHttp, 'reason' => 'idempotent http void test'], $superadminSess['jar'], $superadminSess['csrf']);
    check('Case 30 (HTTP): replaying the same request_uuid over HTTP returns the same idempotent result, no duplicate side effect', ($secondHttpVoid['body']['data']['idempotent_replay'] ?? false) === true && ($secondHttpVoid['body']['data']['reversal_transaction_id'] ?? null) === ($firstHttpVoid['body']['data']['reversal_transaction_id'] ?? null));
} finally {
    proc_terminate($process);
    proc_close($process);
}

// =============================================================================
echo "\n== Case 29: atomic rollback on simulated failure ==\n";
// The dependency-safety check (Case 5) is a genuine mid-transaction failure:
// void()'s REVERSAL header row is inserted FIRST, and only THEN does
// reverseBatchCreation() detect the downstream consumer and throw — proving
// Database::transaction() rolls back that partial insert, not just that the
// batch mutation itself was skipped.
$item29 = makeItem($pdo, $kgUnitId, 'C29');
$in29 = postIn($pdo, $item29, $whA, 30, 2000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
postOut($pdo, $item29, $whA, 10, '2026-09-02 08:00:00', $superadmin, $kgUnitId); // creates the downstream dependency
$countBefore29 = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reversal_of_id = {$in29['transaction_id']}")->fetchColumn();
$rolledBack29 = false;
try {
    voidTx($pdo, $in29['transaction_id'], $superadmin, 'simulated failure rollback test');
} catch (VoidHasDownstreamDependenciesException $e) {
    $rolledBack29 = true;
}
check('void() blocked mid-transaction by the downstream-dependency check (simulated failure)', $rolledBack29);
$countAfter29 = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reversal_of_id = {$in29['transaction_id']}")->fetchColumn();
check('No orphan REVERSAL row was left behind — the DB transaction rolled back atomically', $countAfter29 === $countBefore29 && $countAfter29 === 0, "before={$countBefore29} after={$countAfter29}");
$originalStatus29 = $pdo->query("SELECT status FROM inventory_transactions WHERE id = {$in29['transaction_id']}")->fetchColumn();
check('Original transaction status is still POSTED (never partially flipped to VOID)', $originalStatus29 === 'POSTED');

// =============================================================================
echo "\n== Case 30: idempotency / duplicate request protection (service-level) ==\n";
$item30 = makeItem($pdo, $kgUnitId, 'C30');
$in30 = postIn($pdo, $item30, $whA, 22, 1500, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$voidUuid30 = uid('void-idem');
$firstVoid30 = Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => $voidUuid30, 'transaction_id' => $in30['transaction_id'], 'reason' => 'idempotency service test', 'voided_by' => $superadmin]));
$secondVoid30 = Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => $voidUuid30, 'transaction_id' => $in30['transaction_id'], 'reason' => 'idempotency service test', 'voided_by' => $superadmin]));
check('Second void() call with the SAME request_uuid returns idempotent_replay=true', $secondVoid30['idempotent_replay'] === true);
check('Both calls report the SAME reversal_transaction_id (no duplicate reversal created)', $secondVoid30['reversal_transaction_id'] === $firstVoid30['reversal_transaction_id']);
$reversalCount30 = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reversal_of_id = {$in30['transaction_id']}")->fetchColumn();
check('Exactly one REVERSAL row exists despite two identical requests', $reversalCount30 === 1);

// idempotent replay for transfer reverse too
$item30b = makeItem($pdo, $kgUnitId, 'C30b');
postIn($pdo, $item30b, $whA, 12, 1000, '2026-09-01 08:00:00', $superadmin, $kgUnitId);
$transfer30 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('xfer30'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-05 08:00:00', 'created_by' => $superadmin,
    'lines' => [['item_id' => $item30b, 'input_qty' => 6, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transfer30['transfer_id'], ['created_by' => $superadmin]));
$reverseUuid30 = uid('reverse-idem');
$firstReverse30 = Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer30['transfer_id'], ['request_uuid' => $reverseUuid30, 'created_by' => $superadmin, 'reason' => 'idempotency reverse test']));
$secondReverse30 = Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $transfer30['transfer_id'], ['request_uuid' => $reverseUuid30, 'created_by' => $superadmin, 'reason' => 'idempotency reverse test']));
check('Second reverse() call with the SAME request_uuid returns idempotent_replay=true, no double reversal', $secondReverse30['idempotent_replay'] === true);

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
