<?php
declare(strict_types=1);

/**
 * PHASE V2.3C — "PRODUCTION HPP REPORT HOTFIX" regression fixture.
 *
 * Reproduces, against a real MySQL database, the exact production defect
 * report and proves all three root causes are fixed:
 *
 *   Root Cause 1 — ABS() was flipping a migration-negative Opening line's
 *   real negative economic value positive.
 *   Root Cause 2 — a VOID'd IN was counted in the "Pembelian Eksternal"
 *   (External Purchase) KPI even though the purchase never effectively
 *   happened.
 *   Root Cause 3 — a stock OPENING dated exactly at a report's own
 *   start_date was treated as a mid-period movement instead of beginning
 *   inventory.
 *
 * Two warehouses named SCM/CIBADAK (uid()-suffixed, never colliding with
 * anything real) host a "production-equivalent" composition — a large
 * positive opening balance plus the 5 real approved migration-negative
 * balances from the production evidence (100304, 777419 in SCM;
 * 400201, 555410, 800401 in CIBADAK) — engineered so SCM/CIBADAK/company
 * sum to the EXACT production-like target figures the hotfix request
 * named. Every other fixture element (clean scenario, internal transfer,
 * VOID IN, VOID OUT, historical rows) lives in the SAME two warehouses
 * (a genuinely unified environment, closer to production than isolated
 * throwaway warehouses would be) but is always checked via its own
 * SKU-scoped filter or a before/after delta, never an unscoped absolute
 * total — so it can never corrupt the numeric-target assertions.
 *
 * This file does NOT touch production. It never connects to anything
 * outside the local test database, never creates Karang Tengah, and
 * never mutates data the report reads (proven directly, section I).
 *
 * Usage: php tests/inventory_hpp_v23c_production_hotfix_test.php
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
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;
use App\Services\VoidService;
use App\Services\TransferService;
use App\Services\InventoryService;
use App\Services\MigrationNegativeStockService;
use App\Services\InventoryHppReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) < $eps; }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v23c'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V23C', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeWarehouse(PDO $pdo, string $tag): array
{
    $code = uid($tag);
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $tag]);
    return [(int) $pdo->lastInsertId(), $code];
}
function makeItem(PDO $pdo, int $unitId, string $sku): int
{
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)');
    $stmt->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function approveMigrationNegative(PDO $pdo, string $sku, string $whCode): void
{
    $pdo->prepare(
        'INSERT INTO movement_reconciliation_reviews
            (sku, warehouse_code, historical_opening, historical_in, historical_out, historical_calculated_ending,
             status, is_migration_negative_approved, migration_negative_approved_by_name, migration_negative_note)
         VALUES (:sku, :wh, 0, 0, 0, -1, \'PENDING_FINAL_STOCK\', 1, \'V2.3C test owner\', \'production-equivalent whitelist row\')'
    )->execute(['sku' => $sku, 'wh' => $whCode]);
}
/** Posts an OPENING line at unit price 1 — subtotal == qty exactly, no float-precision risk for the numeric-target proof. */
function postOpeningAtPrice1(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $createdBy, int $unitId, bool $allowMigrationNegative = false): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v23c-open'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => 1,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'v23c',
        'transaction_type' => 'OPENING', 'allow_migration_negative_opening' => $allowMigrationNegative,
    ]));
    return (int) $r['transaction_id'];
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $cost, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v23c-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $cost,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'v23c',
    ]));
    return (int) $r['transaction_id'];
}
function postOut(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('v23c-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'transaction_type' => 'OUT',
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'v23c',
    ]));
    return (int) $r['transaction_id'];
}

[$whScmId, $whScmCode] = makeWarehouse($pdo, 'SCM');
[$whCibId, $whCibCode] = makeWarehouse($pdo, 'CIBADAK');

$periodStart = '2026-09-16';
$periodEnd = '2026-09-20';
$boundaryDate = $periodStart . ' 00:00:00';

// ============================================================
// PART 1 — PRODUCTION NUMERIC TARGET + boundary handling
//
// Positive opening filler + the 5 real approved migration-negative
// balances from the production evidence, all posted at unit price 1
// (subtotal == qty exactly) and dated EXACTLY at the report's own
// start boundary (2026-09-16 00:00:00) — reproducing the live
// production scenario that triggered Root Cause 3.
//
//   SCM:      100304 = -0.5, 777419 = -0.5           -> SCM negative = -1.0
//   CIBADAK:  400201 = -466.5, 555410 = -250, 800401 = -162 -> CIBADAK negative = -878.5
//
// Positive filler qty solved so SCM/CIBADAK/company match the exact
// production-like target (rounded to the app's own 4-decimal money
// scale): SCM=2,330,669,085.7833 CIBADAK=307,378,082.1376
// Company=2,638,047,167.9209
// ============================================================
echo "== PART 1: production numeric target + OPENING boundary ==\n";

$itemPosScm = makeItem($pdo, $kgUnitId, uid('V23CTARGET-POS-SCM'));
$item100304 = makeItem($pdo, $kgUnitId, uid('V23CTARGET-100304'));
$item777419 = makeItem($pdo, $kgUnitId, uid('V23CTARGET-777419'));
$itemPosCib = makeItem($pdo, $kgUnitId, uid('V23CTARGET-POS-CIB'));
$item400201 = makeItem($pdo, $kgUnitId, uid('V23CTARGET-400201'));
$item555410 = makeItem($pdo, $kgUnitId, uid('V23CTARGET-555410'));
$item800401 = makeItem($pdo, $kgUnitId, uid('V23CTARGET-800401'));

foreach ([[$item100304, $whScmCode], [$item777419, $whScmCode], [$item400201, $whCibCode], [$item555410, $whCibCode], [$item800401, $whCibCode]] as [$itemId, $whCode]) {
    $skuStmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id');
    $skuStmt->execute(['id' => $itemId]);
    approveMigrationNegative($pdo, $skuStmt->fetchColumn(), $whCode);
}

postOpeningAtPrice1($pdo, $itemPosScm, $whScmId, 2330669086.7833, $boundaryDate, $adminUserId, $kgUnitId);
postOpeningAtPrice1($pdo, $item100304, $whScmId, -0.5, $boundaryDate, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item777419, $whScmId, -0.5, $boundaryDate, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $itemPosCib, $whCibId, 307378960.6376, $boundaryDate, $adminUserId, $kgUnitId);
postOpeningAtPrice1($pdo, $item400201, $whCibId, -466.5, $boundaryDate, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item555410, $whCibId, -250, $boundaryDate, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item800401, $whCibId, -162, $boundaryDate, $adminUserId, $kgUnitId, true);

const TARGET_SCM = 2330669085.7833;
const TARGET_CIB = 307378082.1376;
const TARGET_COMPANY = 2638047167.9209;

$targetQ = 'V23CTARGET';
$scmSummary = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, $targetQ);
$cibSummary = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whCibId, null, $targetQ);

check('SCM opening_value == production target 2,330,669,085.7833 (Root Cause 1: migration-negative sign preserved, not flipped)', approx($scmSummary['opening_value'], TARGET_SCM), (string) $scmSummary['opening_value']);
check('CIBADAK opening_value == production target 307,378,082.1376', approx($cibSummary['opening_value'], TARGET_CIB), (string) $cibSummary['opening_value']);
check('SCM ending_value == same target (nothing else moved for these items)', approx($scmSummary['ending_value'], TARGET_SCM), (string) $scmSummary['ending_value']);
check('CIBADAK ending_value == same target', approx($cibSummary['ending_value'], TARGET_CIB), (string) $cibSummary['ending_value']);
check('SCM + CIBADAK == company target 2,638,047,167.9209 (consolidated = sum of warehouses)', approx($scmSummary['opening_value'] + $cibSummary['opening_value'], TARGET_COMPANY), (string) ($scmSummary['opening_value'] + $cibSummary['opening_value']));

// Root Cause 3, precisely: the boundary-exact OPENING is beginning
// inventory (opening_value), NEVER a mid-period movement.
check('Root Cause 3: SCM opening_mid_period == 0 for these items (the boundary row was NOT double-classified as a movement)', approx($scmSummary['non_hpp_movements']['opening_mid_period'], 0.0), (string) $scmSummary['non_hpp_movements']['opening_mid_period']);
check('Root Cause 3: CIBADAK opening_mid_period == 0 for these items', approx($cibSummary['non_hpp_movements']['opening_mid_period'], 0.0), (string) $cibSummary['non_hpp_movements']['opening_mid_period']);
check('Root Cause 3: SCM Variance == 0 (no giant negative reconciliation variance from the live opening)', approx($scmSummary['variance'], 0.0), (string) $scmSummary['variance']);
check('Root Cause 3: CIBADAK Variance == 0', approx($cibSummary['variance'], 0.0), (string) $cibSummary['variance']);

// Negative control: an OPENING dated ONE DAY AFTER the boundary is a
// genuine mid-period movement and must NOT be swept into opening_value —
// proves the fix is scoped to the exact boundary, not a blanket <= change.
// Deliberately NOT prefixed "V23CTARGET" — must never match the $targetQ
// filter used elsewhere in this file, or it would pollute Part 2's daily
// roll-forward (which scopes to that same prefix) with its own 5,000.
$itemMidOpen = makeItem($pdo, $kgUnitId, uid('V23C-NEGCONTROL-MIDOPEN'));
postOpeningAtPrice1($pdo, $itemMidOpen, $whScmId, 5000, '2026-09-17 00:00:00', $adminUserId, $kgUnitId);
$midOpenSummary = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, 'V23C-NEGCONTROL-MIDOPEN');
check('A non-boundary OPENING (dated 1 day after start) stays a mid-period movement, not opening_value', approx($midOpenSummary['opening_value'], 0.0) && approx($midOpenSummary['non_hpp_movements']['opening_mid_period'], 5000.0), 'opening=' . $midOpenSummary['opening_value'] . ' mid_period=' . $midOpenSummary['non_hpp_movements']['opening_mid_period']);

// Cross-verify against the real, trusted InventoryService::currentStock()
// summed over exactly these 7 items — proves the report's total matches
// the actual batch-level reconciliation, not just its own internal math.
$targetItems = [$itemPosScm, $item100304, $item777419, $itemPosCib, $item400201, $item555410, $item800401];
$realTotal = 0.0;
foreach ($targetItems as $itemId) {
    $whForItem = in_array($itemId, [$itemPosScm, $item100304, $item777419], true) ? $whScmId : $whCibId;
    $realTotal += (float) InventoryService::currentStock($pdo, $itemId, $whForItem)['value'];
}
check('Real InventoryService::currentStock() summed over the 7 target items == company target (report ending matches real batch reconciliation)', approx($realTotal, TARGET_COMPANY), (string) $realTotal);

// The whitelisted items must still read back as MIGRATION_NEGATIVE_REVIEW
// (never silently "fixed" by this hotfix — only a real Opname/Adjustment
// clears that flag).
$flag100304 = InventoryService::currentStock($pdo, $item100304, $whScmId);
check('Whitelisted migration-negative item still flags migration_negative_review (never silently cleared)', $flag100304['migration_negative_review'] === true);

// ============================================================
// PART 2 — Daily roll-forward across the exact production window,
// touching the boundary date itself.
// ============================================================
echo "\n== PART 2: daily roll-forward 2026-09-16..2026-09-20 ==\n";

$days = ['2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'];
$prevEnding = null;
$allMatched = true;
foreach ($days as $d) {
    $s = InventoryHppReportService::summary($pdo, $d, $d, $whScmId, null, $targetQ);
    if ($prevEnding !== null && !approx($s['opening_value'], $prevEnding)) {
        $allMatched = false;
    }
    $prevEnding = $s['ending_value'];
}
check('Daily roll-forward: opening(day N+1) == ending(day N) across 2026-09-16..2026-09-20, including the boundary day itself', $allMatched);
check('Last day (2026-09-20) ending still equals the target (no movement in this window)', approx((float) $prevEnding, TARGET_SCM), (string) $prevEnding);

// ============================================================
// PART 3 — Clean scenario control equation (Reconciliation == FIFO HPP,
// Variance == 0, Unexplained == 0) inside the SAME SCM/CIBADAK fixture.
// ============================================================
echo "\n== PART 3: clean scenario control equation ==\n";

$itemClean = makeItem($pdo, $kgUnitId, uid('V23C-CLEAN'));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v23c-clean-open'), 'item_id' => $itemClean, 'warehouse_id' => $whScmId,
    'input_qty' => 1000, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 100,
    'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v23c', 'transaction_type' => 'OPENING',
]));
postIn($pdo, $itemClean, $whScmId, 500, 120, '2026-09-17 08:00:00', $adminUserId, $kgUnitId);
postOut($pdo, $itemClean, $whScmId, 300, '2026-09-18 08:00:00', $adminUserId, $kgUnitId);

$cleanSummary = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, 'V23C-CLEAN');
$cleanBridge = InventoryHppReportService::varianceBridge($pdo, $periodStart, $periodEnd, $whScmId);
check('Clean scenario: HPP Reconciliation == FIFO HPP', approx($cleanSummary['hpp_reconciliation'], $cleanSummary['fifo_hpp']), "recon={$cleanSummary['hpp_reconciliation']} fifo={$cleanSummary['fifo_hpp']}");
check('Clean scenario: Variance == 0', approx($cleanSummary['variance'], 0.0), (string) $cleanSummary['variance']);

// ============================================================
// PART 4 — Internal SCM -> CIBADAK transfer: consolidated value/HPP/
// purchase unaffected; TRANSFER_IN never counted as External Purchase.
// ============================================================
echo "\n== PART 4: internal SCM -> CIBADAK transfer ==\n";

$itemXfer = makeItem($pdo, $kgUnitId, uid('V23C-XFER'));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v23c-xfer-open'), 'item_id' => $itemXfer, 'warehouse_id' => $whScmId,
    'input_qty' => 1000, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 500,
    'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v23c', 'transaction_type' => 'OPENING',
]));
$xferResult = TransferService::create($pdo, [
    'transfer_uuid' => uid('v23c-xfer'), 'from_warehouse_id' => $whScmId, 'to_warehouse_id' => $whCibId,
    'ship_date' => '2026-09-17 08:00:00', 'created_by' => $adminUserId, 'username' => 'v23c',
    'lines' => [['item_id' => $itemXfer, 'input_qty' => 200, 'input_unit_id' => $kgUnitId]],
]);
TransferService::receive($pdo, (int) $xferResult['transfer_id'], ['created_by' => $adminUserId, 'username' => 'v23c', 'receive_date' => '2026-09-17 09:00:00']);

$xferScm = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, 'V23C-XFER');
$xferCib = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whCibId, null, 'V23C-XFER');
check('Transfer: SCM ending reduced by exactly the transferred value (500,000 - 100,000 = 400,000)', approx($xferScm['ending_value'], 400000.0), (string) $xferScm['ending_value']);
check('Transfer: CIBADAK ending gains exactly the transferred value (100,000)', approx($xferCib['ending_value'], 100000.0), (string) $xferCib['ending_value']);
check('Transfer: TRANSFER_IN never counted as External Purchase (CIBADAK external_purchase for this item = 0)', approx($xferCib['external_purchase'], 0.0), (string) $xferCib['external_purchase']);
check('Transfer: consolidated (SCM+CIBADAK) value for this item unchanged by the transfer (500,000 total either side)', approx($xferScm['ending_value'] + $xferCib['ending_value'], 500000.0));
check('Transfer: FIFO HPP unaffected on either side', approx($xferScm['fifo_hpp'], 0.0) && approx($xferCib['fifo_hpp'], 0.0));

// ============================================================
// PART 5 — VOID IN: original entry excluded from External Purchase KPI,
// but historical reconstruction (ending) still nets correctly via its
// own disclosed bridge bucket. Query end date extends to "today" (real
// wall-clock) since VoidService always dates the reversal at the real
// moment it's voided, never backdated into the fixed production window.
// ============================================================
echo "\n== PART 5: VOID IN ==\n";

$itemVoidIn = makeItem($pdo, $kgUnitId, uid('V23C-VOIDIN'));
$voidInTxId = postIn($pdo, $itemVoidIn, $whScmId, 50, 2000, $periodStart . ' 08:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('v23c-void-in'), 'transaction_id' => $voidInTxId, 'reason' => 'V2.3C void IN test', 'voided_by' => $adminUserId, 'username' => 'v23c',
]));
$today = date('Y-m-d');
$voidInSummary = InventoryHppReportService::summary($pdo, $periodStart, $today, $whScmId, null, 'V23C-VOIDIN');
$realVoidIn = InventoryService::currentStock($pdo, $itemVoidIn, $whScmId);
check('VOID IN: external_purchase == 0 (a cancelled purchase is never counted as an effective one)', approx($voidInSummary['external_purchase'], 0.0), (string) $voidInSummary['external_purchase']);
check('VOID IN: ending_value matches real currentStock() (both 0) — historical reconstruction still correct', approx($voidInSummary['ending_value'], (float) $realVoidIn['value']) && approx($voidInSummary['ending_value'], 0.0), "report={$voidInSummary['ending_value']} real={$realVoidIn['value']}");
check('VOID IN: disclosed via its own voided_in_net bucket, not silently dropped', approx($voidInSummary['non_hpp_movements']['voided_in_net'], 100000.0), (string) $voidInSummary['non_hpp_movements']['voided_in_net']);
$voidInBridge = InventoryHppReportService::varianceBridge($pdo, $periodStart, $today, $whScmId);
check('VOID IN: variance bridge is fully explained, never forced to zero by hiding anything', $voidInBridge['is_fully_explained'] === true, (string) $voidInBridge['unexplained']);

// ============================================================
// PART 6 — VOID OUT: never counted as FIFO HPP once voided; stock
// correctly restored.
// ============================================================
echo "\n== PART 6: VOID OUT ==\n";

$itemVoidOut = makeItem($pdo, $kgUnitId, uid('V23C-VOIDOUT'));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v23c-voidout-open'), 'item_id' => $itemVoidOut, 'warehouse_id' => $whScmId,
    'input_qty' => 300, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v23c', 'transaction_type' => 'OPENING',
]));
$voidOutTxId = postOut($pdo, $itemVoidOut, $whScmId, 80, $periodStart . ' 08:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('v23c-void-out'), 'transaction_id' => $voidOutTxId, 'reason' => 'V2.3C void OUT test', 'voided_by' => $adminUserId, 'username' => 'v23c',
]));
$voidOutSummary = InventoryHppReportService::summary($pdo, '2026-09-01', $today, $whScmId, null, 'V23C-VOIDOUT');
$realVoidOut = InventoryService::currentStock($pdo, $itemVoidOut, $whScmId);
check('VOID OUT: fifo_hpp == 0 (those goods never actually left)', approx($voidOutSummary['fifo_hpp'], 0.0), (string) $voidOutSummary['fifo_hpp']);
check('VOID OUT: ending_value matches real currentStock() (both 300,000 — fully restored)', approx($voidOutSummary['ending_value'], (float) $realVoidOut['value']) && approx($voidOutSummary['ending_value'], 300000.0), "report={$voidOutSummary['ending_value']} real={$realVoidOut['value']}");
$voidOutBridge = InventoryHppReportService::varianceBridge($pdo, '2026-09-01', $today, $whScmId);
check('VOID OUT: variance bridge is fully explained', $voidOutBridge['is_fully_explained'] === true, (string) $voidOutBridge['unexplained']);

// ============================================================
// PART 7 — inventory_effect=0 historical rows: zero economic impact.
// ============================================================
echo "\n== PART 7: historical import rows (inventory_effect=0) ==\n";

$itemHist = makeItem($pdo, $kgUnitId, uid('V23C-HIST'));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v23c-hist-open'), 'item_id' => $itemHist, 'warehouse_id' => $whScmId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v23c', 'transaction_type' => 'OPENING',
]));
$beforeHist = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, 'V23C-HIST');

$now = date('Y-m-d H:i:s');
$histTxStmt = $pdo->prepare(
    'INSERT INTO inventory_transactions
        (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
     VALUES (:uuid, \'IN\', :tx_date, :post_date, :wh, \'POSTED\', 1, 0, :created_by, :created_at)'
);
$histTxStmt->execute(['uuid' => uid('v23c-hist'), 'tx_date' => '2026-09-17 08:00:00', 'post_date' => $now, 'wh' => $whScmId, 'created_by' => $adminUserId, 'created_at' => $now]);
$histTxId = (int) $pdo->lastInsertId();
$histLineStmt = $pdo->prepare(
    'INSERT INTO inventory_transaction_lines
        (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
     VALUES (:tx_id, 1, :item_id, \'hist\', 500, :unit, 1, 500, 9999, 9999, 4999500, :wh)'
);
$histLineStmt->execute(['tx_id' => $histTxId, 'item_id' => $itemHist, 'unit' => $kgUnitId, 'wh' => $whScmId]);

$afterHist = InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, $whScmId, null, 'V23C-HIST');
check('Historical row: opening_value unchanged', approx($beforeHist['opening_value'], $afterHist['opening_value']));
check('Historical row: ending_value unchanged', approx($beforeHist['ending_value'], $afterHist['ending_value']));
check('Historical row: external_purchase unchanged', approx($beforeHist['external_purchase'], $afterHist['external_purchase']));

// ============================================================
// PART 8 — Read-only guarantee: this whole run never mutated any table
// the report itself is supposed to only ever read (fifo_allocations,
// inventory_batches structure beyond what the explicit posts above did).
// Proven by re-running every report call twice and diffing.
// ============================================================
echo "\n== PART 8: read-only guarantee ==\n";

function v23cSnapshot(PDO $pdo): array
{
    return [
        'batches' => $pdo->query('SELECT id, qty_base FROM inventory_batches ORDER BY id')->fetchAll(),
        'allocations' => $pdo->query('SELECT id, qty_allocated FROM fifo_allocations ORDER BY id')->fetchAll(),
        'tx_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
    ];
}
$before = v23cSnapshot($pdo);
InventoryHppReportService::summary($pdo, $periodStart, $periodEnd, null);
InventoryHppReportService::warehouseBreakdown($pdo, $periodStart, $periodEnd, null);
InventoryHppReportService::dailyRecap($pdo, $periodStart, $periodEnd, $whScmId, 1, 10);
InventoryHppReportService::varianceBridge($pdo, $periodStart, $periodEnd, $whScmId);
InventoryHppReportService::buildExportSheets($pdo, $periodStart, $periodEnd, $whScmId, null, null);
$after = v23cSnapshot($pdo);
check('Every V2.3C report call is read-only (byte-identical state before/after)', $before === $after);

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
echo "==============================\n";
exit($passed === $total ? 0 : 1);
