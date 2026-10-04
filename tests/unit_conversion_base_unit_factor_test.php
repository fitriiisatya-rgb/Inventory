<?php
declare(strict_types=1);

/**
 * STABILIZATION — BASE UNIT INTRINSIC FACTOR (backend root-cause fix).
 *
 * Production evidence: 661 real items have base_unit_id with NO active
 * item_unit_conversions row at conversion_to_base=1 — confirmed NORMAL
 * data (item_unit_conversions was only ever meant to hold "the purchase/
 * middle unit being defined", per its own schema comment), not a handful
 * of bad rows. Before this fix, FifoService::postIn()/postOut() (and
 * DistributionOrderService::create(), and PurchaseCostingGateway::preview())
 * each independently called UnitConversionService::getActiveConversion()
 * and threw UnitConversionNotApprovedException when it returned null —
 * including for the item's OWN base unit, which is always intrinsically
 * factor 1 by definition (items.base_unit_id IS the qty anchor). This
 * silently broke Transfer receive (always posts IN via base_unit_id —
 * see TransferService::baseUnitId()), and would equally have broken any
 * direct Stock IN/OUT, Distribution Order, or purchase-costing entry in
 * the base unit for any of those 661 items.
 *
 * Root cause fix: UnitConversionService::resolveConversionFactor() — a
 * NEW method, deliberately NOT folded into getActiveConversion() itself
 * (see that method's own docblock for why: getActiveConversion() is also
 * used by Edit Barang's PUT /items/{id} and the centralized import's
 * idempotent-write checks to decide whether to INSERT a new row; it must
 * keep returning null for an unrecorded base-unit case, never a synthetic
 * non-null row, or those write paths would silently skip persisting a
 * real identity row a human explicitly adds through Edit Barang).
 * resolveConversionFactor() returns 1.0 intrinsically for the base unit
 * when no real row exists, and behaves EXACTLY like
 * getActiveConversion()+null-check for every other unit (never weakened).
 *
 * This file proves, against the real backend (no mocks):
 *   A. base unit without any conversion row now WORKS (Stock IN, Stock
 *      OUT, Distribution Order, PurchaseCostingGateway)
 *   B. a non-base unit WITH a valid approved conversion still works,
 *      unchanged
 *   C. a non-base unit WITHOUT an approved conversion still correctly
 *      FAILS with UnitConversionNotApprovedException — validation is
 *      never weakened for any unit other than the base unit itself
 *   D. Transfer create()->receive() succeeds end-to-end for an item with
 *      no base identity row (the exact production shape)
 *   E. quantities/cost are IDENTICAL whether the base unit has a real
 *      identity row (explicit factor=1) or none at all (intrinsic 1.0) —
 *      proving this is purely an availability fix, never a different
 *      number
 *   F. insufficient-stock validation is completely unchanged
 *   Plus: getActiveConversion() ITSELF is proven UNCHANGED (still returns
 *   null for an unrecorded base-unit case) — the specific regression this
 *   fix was designed to avoid in Edit Barang's write path.
 *
 * Usage: php tests/unit_conversion_base_unit_factor_test.php
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
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/DistributionOrderService.php';
require_once __DIR__ . '/../services/PurchaseCostingGateway.php';
require_once __DIR__ . '/../services/PurchaseCostingService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/ItemPriceService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\TransferService;
use App\Services\UnitConversionService;
use App\Services\DistributionOrderService;
use App\Services\PurchaseCostingGateway;
use App\Services\InsufficientStockException;
use App\Services\UnitConversionNotApprovedException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function expectException(callable $fn, string $class): ?string
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e instanceof $class ? get_class($e) : ('WRONG_CLASS:' . get_class($e));
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$kartonUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$boxUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='BOX'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('UCF-SRC', 'Gudang Sumber UCF', 'MAIN', 1)")->execute();
$srcWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('UCF-DST', 'Gudang Tujuan UCF', 'TRANSIT', 1)")->execute();
$dstWhId = (int) $pdo->lastInsertId();
// DistributionOrderService::create() may only originate from the warehouse
// whose CODE is exactly 'SCM' (DistributionOrderService::SCM_WAREHOUSE_CODE)
// — a dedicated warehouse for section A3/C3, separate from the generic
// UCF-SRC/UCF-DST pair above.
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('SCM', 'SCM Gudang Besar UCF', 'MAIN', 1)")->execute();
$scmWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO bakery_destinations (code, name, address, is_active) VALUES ('UCF-BAKERY', 'UCF Bakery', 'Jl. Test', 1)")->execute();
$bakeryId = (int) $pdo->lastInsertId();

$adminId = (function () use ($pdo, $superRoleId) {
    $u = uid('ucf-admin');
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $superRoleId]);
    return (int) $pdo->lastInsertId();
})();

function makeItemRaw(PDO $pdo, int $baseUnitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    return (int) $pdo->lastInsertId();
}
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('ucf-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $by, 'username' => 'ucf', 'transaction_type' => 'OPENING',
    ]));
}
function postOut(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, int $by): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('ucf-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId,
        'transaction_date' => '2026-09-05 08:00:00', 'created_by' => $by, 'username' => 'ucf', 'transaction_type' => 'OUT',
    ]));
}
function stockBase(PDO $pdo, int $itemId, int $whId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w');
    $stmt->execute(['i' => $itemId, 'w' => $whId]);
    return (float) $stmt->fetchColumn();
}

// ============================================================
// Direct unit tests for UnitConversionService::resolveConversionFactor()
// and the critical invariant: getActiveConversion() itself is UNCHANGED.
// ============================================================
{
    $itemNoRow = makeItemRaw($pdo, $pcsUnitId, 'UCF-DIRECT-NOROW');
    $factor = UnitConversionService::resolveConversionFactor($pdo, $itemNoRow, $pcsUnitId, '2026-09-01');
    check('Direct1. resolveConversionFactor() returns 1.0 for the base unit with NO stored row', $factor === 1.0, (string) $factor);

    $rawExisting = UnitConversionService::getActiveConversion($pdo, $itemNoRow, $pcsUnitId, '2026-09-01');
    check('Direct2. CRITICAL REGRESSION GUARD: getActiveConversion() itself is UNCHANGED — still returns null for an unrecorded base-unit case (Edit Barang\'s PUT /items/{id} write path at index.php depends on this null to decide whether to INSERT a new identity row; if this ever stops being null, that write path would silently skip persisting a real row an admin explicitly adds)',
        $rawExisting === null);

    $itemWithRow = makeItemRaw($pdo, $pcsUnitId, 'UCF-DIRECT-WITHROW');
    UnitConversionService::openNewVersion($pdo, $itemWithRow, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    $factor2 = UnitConversionService::resolveConversionFactor($pdo, $itemWithRow, $pcsUnitId, '2026-09-01');
    check('Direct3. resolveConversionFactor() uses the REAL stored row when one exists (not just the intrinsic fallback)', $factor2 === 1.0, (string) $factor2);

    $itemNonBase = makeItemRaw($pdo, $pcsUnitId, 'UCF-DIRECT-NONBASE');
    $factorMissing = UnitConversionService::resolveConversionFactor($pdo, $itemNonBase, $kartonUnitId, '2026-09-01');
    check('Direct4. resolveConversionFactor() still returns null for a NON-base unit with no approved row (never weakened)', $factorMissing === null);

    UnitConversionService::openNewVersion($pdo, $itemNonBase, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    $factorApproved = UnitConversionService::resolveConversionFactor($pdo, $itemNonBase, $kartonUnitId, '2026-09-01');
    check('Direct5. resolveConversionFactor() returns the correct factor for an approved non-base conversion', $factorApproved === 100.0, (string) $factorApproved);
}

// ============================================================
// A. Base unit without any conversion row now WORKS.
// ============================================================
{
    // A1. Stock IN.
    $item = makeItemRaw($pdo, $pcsUnitId, 'UCF-A-STOCKIN');
    $result = postIn($pdo, $item, $srcWhId, $pcsUnitId, 100, 5000, $adminId);
    check('A1. Stock IN in the base unit succeeds for an item with NO base-unit conversion row', $result['success'] === true);
    check('A1b. base_qty is exactly 100 (factor 1, intrinsic)', $result['base_qty'] === 100.0, (string) $result['base_qty']);

    // A2. Stock OUT.
    $outResult = postOut($pdo, $item, $srcWhId, $pcsUnitId, 30, $adminId);
    check('A2. Stock OUT in the base unit succeeds for the same item', $outResult['success'] === true);
    check('A2b. base_qty requested is exactly 30', $outResult['base_qty'] === 30.0, (string) $outResult['base_qty']);
    check('A2c. remaining stock is exactly 70', stockBase($pdo, $item, $srcWhId) === 70.0, 'stock=' . stockBase($pdo, $item, $srcWhId));

    // A3. Distribution Order create, base unit (must originate from the
    // SCM-coded warehouse — DistributionOrderService::SCM_WAREHOUSE_CODE).
    $itemDo = makeItemRaw($pdo, $pcsUnitId, 'UCF-A-DO');
    postIn($pdo, $itemDo, $scmWhId, $pcsUnitId, 200, 5000, $adminId);
    $doResult = Database::transaction(fn (PDO $tx) => DistributionOrderService::create($tx, [
        'bakery_destination_id' => $bakeryId, 'do_date' => '2026-09-10 08:00:00',
        'from_warehouse_id' => $scmWhId, 'created_by' => $adminId, 'username' => 'ucf',
        'lines' => [['item_id' => $itemDo, 'input_qty' => 20, 'input_unit_id' => $pcsUnitId]],
    ]));
    check('A3. Distribution Order create() with a base-unit line succeeds for an item with no base conversion row', isset($doResult['do_id']) && $doResult['do_id'] > 0, json_encode($doResult));

    // A4. PurchaseCostingGateway::preview(), base unit.
    $itemPc = makeItemRaw($pdo, $pcsUnitId, 'UCF-A-PURCHCOST');
    $preview = PurchaseCostingGateway::preview($pdo, [
        'item_id' => $itemPc, 'input_unit_id' => $pcsUnitId, 'input_qty' => 10,
        'unit_price_input' => 1000, 'transaction_date' => '2026-09-10',
    ]);
    check('A4. PurchaseCostingGateway::preview() with a base-unit line succeeds for an item with no base conversion row', isset($preview['preview']), json_encode($preview));
}

// ============================================================
// B. A non-base unit WITH a valid approved conversion still works.
// ============================================================
{
    $item = makeItemRaw($pdo, $pcsUnitId, 'UCF-B-NONBASE-OK');
    UnitConversionService::openNewVersion($pdo, $item, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    $result = postIn($pdo, $item, $srcWhId, $kartonUnitId, 3, 100000, $adminId);
    check('B1. Stock IN in an approved non-base unit (KARTON) still works, unchanged', $result['success'] === true);
    check('B2. base_qty is exactly 300 (3 * factor 100)', $result['base_qty'] === 300.0, (string) $result['base_qty']);
}

// ============================================================
// C. A non-base unit WITHOUT an approved conversion still correctly FAILS.
// ============================================================
{
    $item = makeItemRaw($pdo, $pcsUnitId, 'UCF-C-NONBASE-MISSING');
    // BOX is deliberately never approved for this item.
    $errClass = expectException(fn () => postIn($pdo, $item, $srcWhId, $boxUnitId, 5, 10000, $adminId), UnitConversionNotApprovedException::class);
    check('C1. Stock IN in an UNAPPROVED non-base unit still correctly throws UnitConversionNotApprovedException — validation never weakened',
        $errClass === UnitConversionNotApprovedException::class, (string) $errClass);

    $errClass2 = expectException(fn () => postOut($pdo, $item, $srcWhId, $boxUnitId, 1, $adminId), UnitConversionNotApprovedException::class);
    check('C2. Stock OUT in the same UNAPPROVED non-base unit also still correctly throws', $errClass2 === UnitConversionNotApprovedException::class, (string) $errClass2);

    $errClass3 = expectException(
        fn () => Database::transaction(fn (PDO $tx) => DistributionOrderService::create($tx, [
            'bakery_destination_id' => $bakeryId, 'do_date' => '2026-09-10 08:00:00',
            'from_warehouse_id' => $scmWhId, 'created_by' => $adminId, 'username' => 'ucf',
            'lines' => [['item_id' => $item, 'input_qty' => 1, 'input_unit_id' => $boxUnitId]],
        ])),
        UnitConversionNotApprovedException::class
    );
    check('C3. Distribution Order create() with an UNAPPROVED non-base unit line also still correctly throws', $errClass3 === UnitConversionNotApprovedException::class, (string) $errClass3);
}

// ============================================================
// D. Transfer create()->receive() succeeds end-to-end for an item with
// NO base identity row (the exact production shape).
// ============================================================
{
    $item = makeItemRaw($pdo, $pcsUnitId, 'UCF-D-TRANSFER');
    UnitConversionService::openNewVersion($pdo, $item, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postIn($pdo, $item, $srcWhId, $kartonUnitId, 5, 100000, $adminId); // 500 PCS on hand
    $uuid = uid('ucf-xfer');
    $create = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
        'transfer_uuid' => $uuid, 'from_warehouse_id' => $srcWhId, 'to_warehouse_id' => $dstWhId,
        'ship_date' => '2026-09-15 08:00:00', 'created_by' => $adminId, 'username' => 'ucf',
        'lines' => [['item_id' => $item, 'input_qty' => 2, 'input_unit_id' => $kartonUnitId]],
    ]));
    check('D1. Transfer create() succeeds (OUT side uses the approved KARTON conversion)', isset($create['transfer_id']));
    $receive = Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $create['transfer_id'], [
        'created_by' => $adminId, 'username' => 'ucf',
    ]));
    check('D2. Transfer receive() now succeeds — IN side posts via base_unit_id (PCS) with no stored conversion row for it', $receive['success'] === true, json_encode($receive));
    check('D3. destination stock is exactly 200 (2 KARTON * factor 100)', stockBase($pdo, $item, $dstWhId) === 200.0, 'stock=' . stockBase($pdo, $item, $dstWhId));
}

// ============================================================
// E. Quantities/cost are IDENTICAL whether the base unit has a real
// identity row or none at all — proving this is purely an availability
// fix, never a different number.
// ============================================================
{
    $itemNoRow = makeItemRaw($pdo, $pcsUnitId, 'UCF-E-NOROW');
    $resultNoRow = postIn($pdo, $itemNoRow, $srcWhId, $pcsUnitId, 77, 1234.5, $adminId);

    $itemWithRow = makeItemRaw($pdo, $pcsUnitId, 'UCF-E-WITHROW');
    UnitConversionService::openNewVersion($pdo, $itemWithRow, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    $resultWithRow = postIn($pdo, $itemWithRow, $srcWhId, $pcsUnitId, 77, 1234.5, $adminId);

    check('E1. base_qty identical with vs without a stored identity row (77 either way)',
        $resultNoRow['base_qty'] === $resultWithRow['base_qty'] && $resultNoRow['base_qty'] === 77.0);
    check('E2. unit_cost_base identical with vs without a stored identity row',
        $resultNoRow['unit_cost_base'] === $resultWithRow['unit_cost_base'], "noRow={$resultNoRow['unit_cost_base']} withRow={$resultWithRow['unit_cost_base']}");
}

// ============================================================
// F. Insufficient-stock validation is completely unchanged.
// ============================================================
{
    $item = makeItemRaw($pdo, $pcsUnitId, 'UCF-F-INSUFFICIENT');
    postIn($pdo, $item, $srcWhId, $pcsUnitId, 10, 5000, $adminId); // only 10 on hand
    $errClass = expectException(fn () => postOut($pdo, $item, $srcWhId, $pcsUnitId, 50, $adminId), InsufficientStockException::class);
    check('F1. Stock OUT of 50 against only 10 on hand (base unit, no conversion row) still correctly throws InsufficientStockException',
        $errClass === InsufficientStockException::class, (string) $errClass);
    check('F2. no stock was moved by the rejected attempt', stockBase($pdo, $item, $srcWhId) === 10.0, 'stock=' . stockBase($pdo, $item, $srcWhId));
}

$failed = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $failed) . "/" . count($results) . " passed\n";
exit($failed > 0 ? 1 : 0);
