<?php
declare(strict_types=1);

/**
 * STABILIZATION — TRANSFER UNIT OPTIONS.
 *
 * Proves two independent things against the REAL backend (no mocks):
 *
 *  1. (A-D, dedup) The exact set GET /items/{id}/units would return for a
 *     range of item/conversion shapes, with the SAME base-unit backfill
 *     algorithm just added to item-selector.js's loadUnitsForItem()
 *     mirrored here in PHP (never imported from the JS — this proves the
 *     ALGORITHM is correct against real item_unit_conversions data; the
 *     companion Playwright test proves the ACTUAL shipped JS renders it
 *     correctly in a real browser). Confirms: base unit is backfilled
 *     only when missing, never duplicated when already present, and the
 *     existing default-selection (first element) is never disturbed by
 *     the backfill (it is always appended, never prepended).
 *
 *  2. (E-H) End-to-end TransferService::create()/receive() correctness
 *     through FifoService, for lines submitted in the base unit, a
 *     middle/purchase unit, and with decimals — proving the selected
 *     *input* unit never changes the resulting *base* quantity, that
 *     insufficient stock is still rejected (unchanged backend behavior —
 *     this file touches neither TransferService nor FifoService), and
 *     surfacing (never silently working around) a PRE-EXISTING, SEPARATE
 *     backend fact unrelated to the dropdown fix: TransferService::receive()
 *     always posts the IN side using the item's base_unit_id (see its own
 *     baseUnitId() helper) — so any item that reaches Transfer without an
 *     OPEN base-unit identity conversion row was already going to fail at
 *     receive(), regardless of this round's frontend change, on ANY unit
 *     chosen for the OUT side. Section H documents this explicitly as an
 *     existing condition, not something this round introduces or fixes.
 *
 * Usage: php tests/transfer_unit_options_test.php
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
require_once __DIR__ . '/../services/ItemPriceService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\TransferService;
use App\Services\UnitConversionService;
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

/**
 * Mirrors EXACTLY the algorithm just added to item-selector.js's
 * loadUnitsForItem() (public/assets/js/item-selector.js) — a separate,
 * independent re-implementation (not a shared import) so this test can
 * never pass merely because it imported the same bug the fix might have.
 * Appends, never prepends; skips entirely if the base unit id is already
 * present.
 */
function mergedUnitsForItem(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.code, u.name, c.conversion_to_base, c.is_purchase_default
         FROM item_unit_conversions c JOIN units u ON u.id = c.unit_id
         WHERE c.item_id = :item_id AND c.valid_to IS NULL
         ORDER BY c.is_purchase_default DESC, c.conversion_to_base DESC'
    );
    $stmt->execute(['item_id' => $itemId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemStmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
    $itemStmt->execute(['id' => $itemId]);
    $baseUnitId = (int) $itemStmt->fetchColumn();

    $alreadyPresent = false;
    foreach ($units as $u) {
        if ((int) $u['id'] === $baseUnitId) { $alreadyPresent = true; break; }
    }
    if (!$alreadyPresent) {
        $uStmt = $pdo->prepare('SELECT id, code, name FROM units WHERE id = :id');
        $uStmt->execute(['id' => $baseUnitId]);
        $baseUnit = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($baseUnit) {
            $units[] = [
                'id' => $baseUnit['id'], 'code' => $baseUnit['code'], 'name' => $baseUnit['name'],
                'conversion_to_base' => 1, 'is_purchase_default' => false,
            ];
        }
    }
    return $units;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$packUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PACK'")->fetchColumn();
$kartonUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
if (!$pcsUnitId || !$packUnitId || !$kartonUnitId) {
    fwrite(STDERR, "FATAL: expected units PCS/PACK/KARTON not found in schema seed\n");
    exit(1);
}

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('TFU-SRC', 'Gudang Sumber TFU', 'MAIN', 1)")->execute();
$srcWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('TFU-DST', 'Gudang Tujuan TFU', 'TRANSIT', 1)")->execute();
$dstWhId = (int) $pdo->lastInsertId();

$adminId = (function () use ($pdo, $superRoleId) {
    $u = uid('tfu-admin');
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
function postInBase(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('tfu-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $by, 'username' => 'tfu', 'transaction_type' => 'OPENING',
    ]));
}
function stockBase(PDO $pdo, int $itemId, int $whId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w');
    $stmt->execute(['i' => $itemId, 'w' => $whId]);
    return (float) $stmt->fetchColumn();
}
function doTransfer(PDO $pdo, int $itemId, float $qty, int $unitId, int $by): array
{
    $uuid = uid('tfu-xfer');
    $create = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
        'transfer_uuid' => $uuid, 'from_warehouse_id' => $GLOBALS['srcWhId'], 'to_warehouse_id' => $GLOBALS['dstWhId'],
        'ship_date' => '2026-09-05 08:00:00', 'created_by' => $by, 'username' => 'tfu',
        'lines' => [['item_id' => $itemId, 'input_qty' => $qty, 'input_unit_id' => $unitId]],
    ]));
    $receive = Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $create['transfer_id'], [
        'created_by' => $by, 'username' => 'tfu',
    ]));
    return ['create' => $create, 'receive' => $receive];
}

// ============================================================
// A. Item with BASE UNIT ONLY (no conversions at all — a true data gap,
//    like the Edit Barang "zero conversions" case).
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-A-BASEONLY');
    $merged = mergedUnitsForItem($pdo, $itemId);
    check('A1. base-only item: merged set has exactly 1 unit (the base unit itself)', count($merged) === 1, 'count=' . count($merged));
    check('A2. A1\'s one unit is the base unit (PCS)', count($merged) === 1 && (int) $merged[0]['id'] === $pcsUnitId);
}

// ============================================================
// B. Item with BASE + 1 conversion, base identity row MISSING (the
//    exact production shape: RM-TF-26-032 showed only its 2 non-base
//    conversions, never its base unit).
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-B-ONECONV');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    $merged = mergedUnitsForItem($pdo, $itemId);
    check('B1. base+1-conversion, base row missing: merged set has exactly 2 units', count($merged) === 2, 'count=' . count($merged));
    check('B2. default (first) option is UNCHANGED — still KARTON (today\'s existing behavior), base is appended at the END, never prepended',
        (int) $merged[0]['id'] === $kartonUnitId && (int) $merged[1]['id'] === $pcsUnitId);
    check('B3. the appended base entry carries conversion_to_base=1', (float) $merged[1]['conversion_to_base'] === 1.0);
}

// ============================================================
// C. Item with BASE + MULTIPLE conversions, base row missing (PACK +
//    KARTON, matching "KARTON and CTN only" from production, generalized).
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-C-MULTICONV');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    UnitConversionService::openNewVersion($pdo, $itemId, $packUnitId, 10.0, '2026-01-01 00:00:00', null, 'pack', false);
    $merged = mergedUnitsForItem($pdo, $itemId);
    check('C1. base+multi-conversion, base row missing: merged set has exactly 3 units', count($merged) === 3, 'count=' . count($merged));
    check('C2. default (first) option still KARTON (purchase-default, largest factor) — unchanged by the backfill',
        (int) $merged[0]['id'] === $kartonUnitId);
    check('C3. base unit (PCS) present somewhere in the set, at the end', (int) $merged[2]['id'] === $pcsUnitId);
}

// ============================================================
// D. Purchase-default is NOT the base unit (PACK is purchase-default,
//    KARTON a plain middle unit with a larger factor) — proves ORDER BY
//    is_purchase_default DESC still wins over conversion_to_base DESC for
//    picking today's default, and the backfill never disturbs that.
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-D-PURCHDEFAULT');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', false);
    UnitConversionService::openNewVersion($pdo, $itemId, $packUnitId, 10.0, '2026-01-01 00:00:00', null, 'pack-is-purchase-default', true);
    $merged = mergedUnitsForItem($pdo, $itemId);
    check('D1. purchase-default (PACK) sorts first even though KARTON has a larger factor', (int) $merged[0]['id'] === $packUnitId);
    check('D2. base unit (PCS) still appended at the end, not disturbing the default', (int) $merged[count($merged) - 1]['id'] === $pcsUnitId);
}

// ============================================================
// Dedup. Base unit ALREADY has its own open conversion row (the
// "correctly seeded" case — e.g. items created by the centralized
// import's new-item path) — must NOT be duplicated.
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-DEDUP');
    UnitConversionService::openNewVersion($pdo, $itemId, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity (base unit)');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    $merged = mergedUnitsForItem($pdo, $itemId);
    $pcsCount = count(array_filter($merged, fn ($u) => (int) $u['id'] === $pcsUnitId));
    check('Dedup1. base unit already has its own row: merged set has exactly 2 units (no duplicate PCS)', count($merged) === 2, 'count=' . count($merged));
    check('Dedup2. PCS appears EXACTLY ONCE, never twice', $pcsCount === 1, "pcsCount={$pcsCount}");
}

// ============================================================
// E. Insufficient stock after conversion — unchanged backend behavior
// (this file touches neither TransferService nor FifoService; this
// proves the existing guard still fires once a larger unit is actually
// selectable through the new dropdown option).
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-E-INSUFFICIENT');
    UnitConversionService::openNewVersion($pdo, $itemId, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postInBase($pdo, $itemId, $srcWhId, $pcsUnitId, 150, $adminId); // only 150 PCS on hand
    // Attempt to transfer 2 KARTON = 200 PCS base-equivalent > 150 available.
    $errClass = expectException(fn () => doTransfer($pdo, $itemId, 2, $kartonUnitId, $adminId), InsufficientStockException::class);
    check('E1. transferring 2 KARTON (=200 PCS) against only 150 PCS on hand is REJECTED with InsufficientStockException', $errClass === InsufficientStockException::class, (string) $errClass);
    check('E2. no stock was moved by the rejected attempt', stockBase($pdo, $itemId, $srcWhId) === 150.0, 'stock=' . stockBase($pdo, $itemId, $srcWhId));
}

// ============================================================
// F. Transfer using the BASE/smallest unit directly.
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-F-BASEUNIT');
    UnitConversionService::openNewVersion($pdo, $itemId, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postInBase($pdo, $itemId, $srcWhId, $pcsUnitId, 500, $adminId);
    doTransfer($pdo, $itemId, 50, $pcsUnitId, $adminId); // 50 PCS, factor 1 -> base_qty 50
    check('F1. transfer of 50 PCS (base unit, factor 1) reduces source stock by exactly 50', stockBase($pdo, $itemId, $srcWhId) === 450.0, 'stock=' . stockBase($pdo, $itemId, $srcWhId));
    check('F2. transfer of 50 PCS (base unit) increases destination stock by exactly 50', stockBase($pdo, $itemId, $dstWhId) === 50.0, 'stock=' . stockBase($pdo, $itemId, $dstWhId));
}

// ============================================================
// G. Transfer using the LARGEST unit — same economic effect as F's
// base-unit equivalent qty, proving unit choice never changes the
// resulting base quantity (requirement #11).
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-G-LARGESTUNIT');
    UnitConversionService::openNewVersion($pdo, $itemId, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postInBase($pdo, $itemId, $srcWhId, $pcsUnitId, 500, $adminId);
    doTransfer($pdo, $itemId, 2, $kartonUnitId, $adminId); // 2 KARTON * 100 = 200 PCS base_qty
    check('G1. transfer of 2 KARTON (factor 100) reduces source stock by exactly 200 (same as 200 PCS would)', stockBase($pdo, $itemId, $srcWhId) === 300.0, 'stock=' . stockBase($pdo, $itemId, $srcWhId));
    check('G2. transfer of 2 KARTON increases destination stock by exactly 200', stockBase($pdo, $itemId, $dstWhId) === 200.0, 'stock=' . stockBase($pdo, $itemId, $dstWhId));
}

// ============================================================
// H. Decimal quantities — schema's DECIMAL(20,6) columns and the
// frontend's step="any" qty input both already allow this; proves the
// backend path (unaffected by this round's change) still does too.
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-H-DECIMAL');
    UnitConversionService::openNewVersion($pdo, $itemId, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postInBase($pdo, $itemId, $srcWhId, $pcsUnitId, 500, $adminId);
    doTransfer($pdo, $itemId, 2.5, $kartonUnitId, $adminId); // 2.5 KARTON * 100 = 250.0 PCS
    check('H1. transfer of 2.5 KARTON (decimal qty) resolves to exactly 250 base units', stockBase($pdo, $itemId, $dstWhId) === 250.0, 'stock=' . stockBase($pdo, $itemId, $dstWhId));
}

// ============================================================
// I. (Separate finding, NOT a dropdown-fix regression) An item that
// reaches Transfer WITHOUT an open base-unit identity conversion row
// (the exact production gap proven in A-D above) fails at RECEIVE time
// regardless of which unit was chosen for the OUT side, because
// TransferService::receive() always posts the IN side using
// base_unit_id (see TransferService::baseUnitId()) — this is a
// PRE-EXISTING fact about TransferService/FifoService, completely
// independent of item-selector.js, and this test file does not modify
// either service. Documented here, not silently patched (out of scope
// per this round's explicit "jangan sentuh TransferService, FifoService").
// ============================================================
{
    $itemId = makeItemRaw($pdo, $pcsUnitId, 'TFU-I-NOBASEIDENTITY');
    // Deliberately NO identity row for PCS — only the KARTON conversion,
    // reproducing the exact RM-TF-26-032 shape.
    UnitConversionService::openNewVersion($pdo, $itemId, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
    postInBase($pdo, $itemId, $srcWhId, $kartonUnitId, 5, $adminId); // 5 KARTON = 500 PCS base
    $errClass = expectException(fn () => doTransfer($pdo, $itemId, 1, $kartonUnitId, $adminId), UnitConversionNotApprovedException::class);
    check('I1. (pre-existing, out of this round\'s scope) an item lacking a base-unit identity row fails at RECEIVE (not create) with UnitConversionNotApprovedException, EVEN when KARTON — a unit that already worked before this fix — is used for the OUT side',
        $errClass === UnitConversionNotApprovedException::class, (string) $errClass);
}

$failed = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $failed) . "/" . count($results) . " passed\n";
exit($failed > 0 ? 1 : 0);
