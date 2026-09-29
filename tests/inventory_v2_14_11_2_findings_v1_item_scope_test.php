<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11.2 — URGENT HOTFIX. Verified on production: 677 ACTIVE
 * master items, only 434 with nonzero SCM inventory_batches, meaning 243
 * ACTIVE SKUs were being silently excluded from a FINDINGS_V1
 * StockOpnameService::start(itemIds=null) session. That is wrong for a
 * physical Stock Opname — a zero-system SKU can still hold real physical
 * stock, which is exactly the kind of discrepancy a physical count
 * exists to catch. Fix: FINDINGS_V1's default (itemIds=null) selection
 * now scans `items WHERE status='ACTIVE'` instead of
 * `inventory_batches WHERE qty_base <> 0`. LEGACY_DUAL_COUNT's default
 * selection is untouched.
 *
 * Usage: php tests/inventory_v2_14_11_2_findings_v1_item_scope_test.php
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
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => uid('v214112admin'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'v214112admin', 'r' => $superRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, string $tag, int $baseUnitId, string $status = 'ACTIVE'): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => $status]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v214112-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v214112', 'transaction_type' => 'OPENING',
    ]));
}
function postOut(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('v214112-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId,
        'transaction_date' => '2026-08-02 08:00:00', 'created_by' => $by, 'username' => 'v214112', 'transaction_type' => 'ADJUSTMENT',
    ]));
}
function makeWarehouse(PDO $pdo, string $tag): int
{
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)")->execute(['c' => uid($tag), 'n' => $tag]);
    return (int) $pdo->lastInsertId();
}

// ============================================================
// ALL fixture items/warehouses/postings for every section below are
// created FIRST, before any start()/cancel() call — so the baseline
// snapshot taken right after this block, and re-checked at the very
// end (acceptance test 9), isolates exactly what start()/cancel()
// themselves do to inventory, uncontaminated by this file's own
// fixture setup.
// ============================================================
$whId = makeWarehouse($pdo, 'V214112 WH');
$itemPositive = makeItem($pdo, 'V214112-POS', $kgUnitId);     // ACTIVE, positive system stock
postIn($pdo, $itemPositive, $kgUnitId, $whId, 50, 1000, $adminUserId);

$itemZeroWithHistory = makeItem($pdo, 'V214112-ZEROHIST', $kgUnitId); // ACTIVE, nets to 0 but HAS batch history
postIn($pdo, $itemZeroWithHistory, $kgUnitId, $whId, 20, 500, $adminUserId);
postOut($pdo, $itemZeroWithHistory, $kgUnitId, $whId, 20, $adminUserId);

$itemNeverTouched = makeItem($pdo, 'V214112-NOBATCH', $kgUnitId); // ACTIVE, NO inventory_batches row at all
$itemInactive = makeItem($pdo, 'V214112-INACTIVE', $kgUnitId, 'INACTIVE'); // INACTIVE, must never appear

$whIdLegacy = makeWarehouse($pdo, 'V214112 WH Legacy');
$legacyPositive = makeItem($pdo, 'V214112L-POS', $kgUnitId);
postIn($pdo, $legacyPositive, $kgUnitId, $whIdLegacy, 30, 800, $adminUserId);
$legacyNoBatch = makeItem($pdo, 'V214112L-NOBATCH', $kgUnitId);

$whIdSel = makeWarehouse($pdo, 'V214112 WH Sel');
$selItemA = makeItem($pdo, 'V214112S-A', $kgUnitId);
$selItemB = makeItem($pdo, 'V214112S-B', $kgUnitId); // deliberately NOT selected in the FINDINGS_V1 sub-case
postIn($pdo, $selItemA, $kgUnitId, $whIdSel, 10, 1000, $adminUserId);

$whIdSel2 = makeWarehouse($pdo, 'V214112 WH Sel2');
postIn($pdo, $selItemB, $kgUnitId, $whIdSel2, 5, 1000, $adminUserId);

// Baseline inventory snapshot — taken AFTER every fixture postIn/postOut
// above and BEFORE any start()/cancel() call anywhere in this file.
$batchCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$batchValueBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetchColumn();
$batchQtyBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches')->fetchColumn();

// ============================================================
echo "\n== FINDINGS_V1, itemIds=null: all ACTIVE items included regardless of system stock ==\n";
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminUserId, null, 'FINDINGS_V1'));
$linesStmt = $pdo->prepare('SELECT item_id, system_qty_base FROM stock_opname_lines WHERE session_id = :sid');
$linesStmt->execute(['sid' => $sessionId]);
$lines = $linesStmt->fetchAll();
$byItem = [];
foreach ($lines as $l) { $byItem[(int) $l['item_id']] = $l; }

check('1. ACTIVE item with positive stock is included', isset($byItem[$itemPositive]));
check('2. ACTIVE item with zero stock (but batch history exists) is included', isset($byItem[$itemZeroWithHistory]));
check('3. ACTIVE item with NO inventory_batches row at all is included', isset($byItem[$itemNeverTouched]));
check('4. INACTIVE item is excluded', !isset($byItem[$itemInactive]));
check('5. system_qty_base for the never-touched ACTIVE item is exactly 0', isset($byItem[$itemNeverTouched]) && abs((float) $byItem[$itemNeverTouched]['system_qty_base'] - 0.0) < 0.0000001, (string) ($byItem[$itemNeverTouched]['system_qty_base'] ?? 'MISSING'));
check('5b. system_qty_base for the zero-net-but-has-history item is exactly 0', isset($byItem[$itemZeroWithHistory]) && abs((float) $byItem[$itemZeroWithHistory]['system_qty_base'] - 0.0) < 0.0000001, (string) ($byItem[$itemZeroWithHistory]['system_qty_base'] ?? 'MISSING'));
check('5c. system_qty_base for the positive-stock item is exactly 50', isset($byItem[$itemPositive]) && abs((float) $byItem[$itemPositive]['system_qty_base'] - 50.0) < 0.0000001, (string) ($byItem[$itemPositive]['system_qty_base'] ?? 'MISSING'));

$snapshotUnits = StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemNeverTouched);
check('6. frozen unit snapshot IS created for the zero-stock ACTIVE item (no exception, non-empty)', count($snapshotUnits) > 0, (string) count($snapshotUnits));

Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $sessionId, 'V2.14.11.2 test cleanup', $adminUserId));

// ============================================================
echo "\n== 7. LEGACY_DUAL_COUNT default selection behavior remains unchanged ==\n";
$legacySessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdLegacy, $adminUserId, null, 'LEGACY_DUAL_COUNT'));
$legacyLinesStmt = $pdo->prepare('SELECT item_id FROM stock_opname_lines WHERE session_id = :sid');
$legacyLinesStmt->execute(['sid' => $legacySessionId]);
$legacyItemIds = array_map('intval', array_column($legacyLinesStmt->fetchAll(), 'item_id'));
check('7a. LEGACY_DUAL_COUNT default selection still includes the positive-stock item', in_array($legacyPositive, $legacyItemIds, true));
check('7b. LEGACY_DUAL_COUNT default selection still EXCLUDES a zero/no-batch ACTIVE item (old behavior preserved)', !in_array($legacyNoBatch, $legacyItemIds, true));
Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $legacySessionId, 'V2.14.11.2 test cleanup', $adminUserId));

// ============================================================
echo "\n== 8. selected-item explicit scope remains unchanged (both models) ==\n";
$selSessionFindings = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdSel, $adminUserId, [$selItemA], 'FINDINGS_V1'));
$selLinesStmt = $pdo->prepare('SELECT item_id FROM stock_opname_lines WHERE session_id = :sid');
$selLinesStmt->execute(['sid' => $selSessionFindings]);
$selItemIds = array_map('intval', array_column($selLinesStmt->fetchAll(), 'item_id'));
check('8a. FINDINGS_V1 explicit itemIds scope includes ONLY the selected item', $selItemIds === [$selItemA], json_encode($selItemIds));
Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $selSessionFindings, 'V2.14.11.2 test cleanup', $adminUserId));

$selSessionLegacy = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdSel2, $adminUserId, [$selItemB], 'LEGACY_DUAL_COUNT'));
$selLinesStmt2 = $pdo->prepare('SELECT item_id FROM stock_opname_lines WHERE session_id = :sid');
$selLinesStmt2->execute(['sid' => $selSessionLegacy]);
$selItemIds2 = array_map('intval', array_column($selLinesStmt2->fetchAll(), 'item_id'));
check('8b. LEGACY_DUAL_COUNT explicit itemIds scope includes ONLY the selected item', $selItemIds2 === [$selItemB], json_encode($selItemIds2));
Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $selSessionLegacy, 'V2.14.11.2 test cleanup', $adminUserId));

// ============================================================
echo "\n== 9. no inventory qty/value/batch mutation from start()/cancel() themselves ==\n";
$batchCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$batchValueAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetchColumn();
$batchQtyAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches')->fetchColumn();
check('9. inventory_batches count/qty/value unchanged across every start()/cancel() call above', $batchCountBefore === $batchCountAfter && abs($batchValueBefore - $batchValueAfter) < 0.0001 && abs($batchQtyBefore - $batchQtyAfter) < 0.0000001, "{$batchCountBefore}->{$batchCountAfter}, {$batchValueBefore}->{$batchValueAfter}, {$batchQtyBefore}->{$batchQtyAfter}");

// ============================================================
echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed !== $total) {
    exit(1);
}
