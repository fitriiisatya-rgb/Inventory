<?php
declare(strict_types=1);

/**
 * PHASE V2.6B — InventoryMovementReportService (Report 2, "Pergerakan
 * Stok Harian") and InventoryReconciliationReportService (Report 14,
 * "Rekonsiliasi Arus Stok"). Proves the NEW logic this phase adds —
 * the category-bucket split, the Beginning+Movement=Ending identity, the
 * company-level transfer-elimination algebra, VOID/read-only behavior,
 * and that Reconciliation's Difference is a real, disclosed number, never
 * forced to zero. It deliberately does NOT re-prove cutover-clamping
 * (`InventoryHppReportService::cutoverContext()`/`liveOpeningDate()`) or
 * HPP/FIFO valuation itself — those are reused verbatim and already
 * covered by tests/inventory_hpp_v23d_cutover_test.php and
 * tests/inventory_hpp_costing_audit_test.php. To keep that reuse from
 * interfering with this file's own 2026 fixture dates, a throwaway
 * OPENING is posted first at a date far in the past so every case below
 * runs fully "live" (matches the pattern used by every other *_test.php
 * file's own early-OPENING fixtures).
 *
 * Usage: php tests/inventory_movement_report_v26b_test.php
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
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';
require_once __DIR__ . '/../services/InventoryReconciliationReportService.php';
require_once __DIR__ . '/../services/InventorySummaryReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use App\Services\TransferService;
use App\Services\VoidService;
use App\Services\InventoryMovementReportService;
use App\Services\InventoryReconciliationReportService;
use App\Services\InventorySummaryReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.5): bool { return abs($a - $b) < $eps; }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('mvsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Movement Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeWarehouse(PDO $pdo, string $tag): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid($tag), 'n' => $tag]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpening(PDO $pdo, int $itemId, int $whId, float $qty, float $cost, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('mv-open'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $cost,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'mvtest', 'transaction_type' => 'OPENING',
    ]));
    return (int) $r['transaction_id'];
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $cost, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('mv-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $cost,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'mvtest',
    ]));
    return (int) $r['transaction_id'];
}
function postOut(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('mv-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'transaction_type' => 'OUT',
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'mvtest',
    ]));
    return (int) $r['transaction_id'];
}
function postAdjustment(PDO $pdo, int $itemId, int $whId, float $delta, string $type, string $date, int $createdBy): int
{
    $r = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
        'transaction_uuid' => uid('mv-adj'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'qty_base_delta' => $delta, 'adjustment_type' => $type, 'reason' => 'V2.6B movement test',
        'transaction_date' => $date, 'created_by' => $createdBy,
    ]));
    return (int) ($r['transaction_id'] ?? 0);
}
function rowByDate(array $rows, string $date): ?array
{
    foreach ($rows as $r) {
        if ($r['date'] === $date) return $r;
    }
    return null;
}

// Pin live_opening_date far in the past so every fixture below runs fully
// "live" — this file tests the NEW category/reconciliation logic, not the
// already-covered cutover-clamping mechanic.
postOpening($pdo, makeItem($pdo, $kgUnitId, 'MV-PIN'), makeWarehouse($pdo, 'MV-PIN-WH'), 1, 1, '2020-01-01 00:00:00', $adminUserId, $kgUnitId);

// ============================================================
// CASE A — Beginning + Movement = Ending identity, zero-activity carry-
// forward, external purchase, out_usage, adjustment positive/negative
// ============================================================
echo "== CASE A: daily identity, carry-forward, adjustment split ==\n";

$whA = makeWarehouse($pdo, 'MV-A-WH');
$itemA = makeItem($pdo, $kgUnitId, 'MV-A-SKU');
postOpening($pdo, $itemA, $whA, 100, 1000, '2026-06-01 00:00:00', $adminUserId, $kgUnitId);
postIn($pdo, $itemA, $whA, 50, 1200, '2026-06-05 08:00:00', $adminUserId, $kgUnitId);
postOut($pdo, $itemA, $whA, 20, '2026-06-07 08:00:00', $adminUserId, $kgUnitId);
postAdjustment($pdo, $itemA, $whA, 10, 'OTHER', '2026-06-08 08:00:00', $adminUserId);
postAdjustment($pdo, $itemA, $whA, -5, 'DAMAGE', '2026-06-09 08:00:00', $adminUserId);

$mvA = InventoryMovementReportService::dailyMovement($pdo, '2026-06-01', '2026-06-10', $whA);
$rows = $mvA['rows'];
check('dailyMovement returns 10 rows for a 10-day range', count($rows) === 10, (string) count($rows));

$r0601 = rowByDate($rows, '2026-06-01');
check('OPENING day: stok_awal seeded from boundary-inclusive opening (100,000)', $r0601 !== null && approx($r0601['stok_awal'], 100000.0), (string) ($r0601['stok_awal'] ?? 'null'));
check('OPENING day: barang_masuk excludes the OPENING itself (already counted as stok_awal, not a movement)', approx($r0601['barang_masuk'], 0.0), (string) $r0601['barang_masuk']);

$r0605 = rowByDate($rows, '2026-06-05');
check('external purchase day: barang_masuk = 60,000', approx($r0605['barang_masuk'], 60000.0), (string) $r0605['barang_masuk']);

$r0606 = rowByDate($rows, '2026-06-06');
check('zero-activity day carries stok_awal/stok_akhir forward unchanged', approx($r0606['stok_awal'], $r0606['stok_akhir']) && approx($r0606['stok_awal'], 160000.0), (string) $r0606['stok_awal']);

$r0607 = rowByDate($rows, '2026-06-07');
check('OUT day: barang_keluar = 20,000 (FIFO cost of 20 units @ 1000)', approx($r0607['barang_keluar'], 20000.0), (string) $r0607['barang_keluar']);

$r0608 = rowByDate($rows, '2026-06-08');
check('adjustment positive day: barang_masuk includes the +10 adjustment (10,000 @ last cost 1200)', approx($r0608['barang_masuk'], 12000.0), (string) $r0608['barang_masuk']);

$r0609 = rowByDate($rows, '2026-06-09');
check('adjustment negative day: barang_keluar includes the -5 adjustment magnitude', $r0609['barang_keluar'] > 0, (string) $r0609['barang_keluar']);

foreach ($rows as $r) {
    if ($r['is_pre_go_live']) continue;
    check("identity holds on {$r['date']}: stok_akhir - stok_awal == barang_masuk - barang_keluar", approx($r['stok_akhir'] - $r['stok_awal'], $r['barang_masuk'] - $r['barang_keluar']), "{$r['stok_akhir']}-{$r['stok_awal']} vs {$r['barang_masuk']}-{$r['barang_keluar']}");
}

$lastRow = end($rows);
$realA = InventoryService::currentStock($pdo, $itemA, $whA);
check('final stok_akhir matches the real batch-based currentStock() value (independent cross-check)', approx($lastRow['stok_akhir'], (float) $realA['value']), "{$lastRow['stok_akhir']} vs {$realA['value']}");

// ============================================================
// CASE B — warehouse transfer inclusion vs company-level elimination
// ============================================================
echo "\n== CASE B: transfer inclusion (warehouse) vs elimination (company) ==\n";

$whC = makeWarehouse($pdo, 'MV-C-WH');
$whD = makeWarehouse($pdo, 'MV-D-WH');
$itemB = makeItem($pdo, $kgUnitId, 'MV-B-SKU');
postOpening($pdo, $itemB, $whC, 200, 500, '2026-06-01 00:00:00', $adminUserId, $kgUnitId);

$transferCreate = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('mv-transfer'), 'from_warehouse_id' => $whC, 'to_warehouse_id' => $whD,
    'ship_date' => '2026-06-15 08:00:00', 'created_by' => $adminUserId, 'username' => 'mvtest',
    'lines' => [['item_id' => $itemB, 'input_qty' => 50, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $transferCreate['transfer_id'], [
    'created_by' => $adminUserId, 'username' => 'mvtest',
]));

$mvC = InventoryMovementReportService::dailyMovement($pdo, '2026-06-15', '2026-06-15', $whC);
$mvD = InventoryMovementReportService::dailyMovement($pdo, '2026-06-15', '2026-06-15', $whD);
$mvCompany = InventoryMovementReportService::dailyMovement($pdo, '2026-06-15', '2026-06-15', null);

check('warehouse-scoped (source): transfer OUT visible as barang_keluar = 25,000', approx($mvC['rows'][0]['barang_keluar'], 25000.0), (string) $mvC['rows'][0]['barang_keluar']);
check('warehouse-scoped (destination): transfer IN visible as barang_masuk = 25,000', approx($mvD['rows'][0]['barang_masuk'], 25000.0), (string) $mvD['rows'][0]['barang_masuk']);
check('company-consolidated: same-day fully-received transfer contributes ~0 to barang_masuk (eliminated)', approx($mvCompany['rows'][0]['barang_masuk'], 0.0), (string) $mvCompany['rows'][0]['barang_masuk']);
check('company-consolidated: same-day fully-received transfer contributes ~0 to barang_keluar (eliminated)', approx($mvCompany['rows'][0]['barang_keluar'], 0.0), (string) $mvCompany['rows'][0]['barang_keluar']);

$breakdownCompany = InventoryMovementReportService::dayBreakdown($pdo, '2026-06-15', null);
$elimCat = null;
foreach ($breakdownCompany['categories'] as $c) { if ($c['key'] === 'transfer_elimination') $elimCat = $c; }
check('company breakdown surfaces a transfer_elimination disclosure line, ~0 when fully received same-day', $elimCat !== null && approx($elimCat['value'], 0.0), (string) ($elimCat['value'] ?? 'missing'));

// In-transit edge: ship without receiving — company-level elimination must
// show the real non-zero in-transit residual, never silently drop it.
$itemB2 = makeItem($pdo, $kgUnitId, 'MV-B2-SKU');
postOpening($pdo, $itemB2, $whC, 100, 300, '2026-06-01 00:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('mv-transfer-pending'), 'from_warehouse_id' => $whC, 'to_warehouse_id' => $whD,
    'ship_date' => '2026-06-20 08:00:00', 'created_by' => $adminUserId, 'username' => 'mvtest',
    'lines' => [['item_id' => $itemB2, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]));
$breakdownPending = InventoryMovementReportService::dayBreakdown($pdo, '2026-06-20', null);
$elimPending = null;
foreach ($breakdownPending['categories'] as $c) { if ($c['key'] === 'transfer_elimination') $elimPending = $c; }
check('an un-received (in-transit) transfer shows a real non-zero elimination residual, never hidden', $elimPending !== null && $elimPending['value'] < -0.5, (string) ($elimPending['value'] ?? 'missing'));

// ============================================================
// CASE C — VOID treatment: original entry stays on its real date, the
// reversal lands on its own real date, never retroactively rewritten
// ============================================================
echo "\n== CASE C: VOID treatment ==\n";

$whE = makeWarehouse($pdo, 'MV-E-WH');
$itemE = makeItem($pdo, $kgUnitId, 'MV-E-SKU');
postOpening($pdo, $itemE, $whE, 100, 1000, '2026-06-01 00:00:00', $adminUserId, $kgUnitId);
$outTxE = postOut($pdo, $itemE, $whE, 20, '2026-06-10 08:00:00', $adminUserId, $kgUnitId);
$today = date('Y-m-d');
Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('mv-void'), 'transaction_id' => $outTxE, 'reason' => 'V2.6B void test', 'voided_by' => $adminUserId, 'username' => 'mvtest',
]));

$mvE = InventoryMovementReportService::dailyMovement($pdo, '2026-06-01', $today, $whE);
$r0610E = rowByDate($mvE['rows'], '2026-06-10');
check('a later VOID never rewrites the original OUT day — barang_keluar still shows 20,000', $r0610E !== null && approx($r0610E['barang_keluar'], 20000.0), (string) ($r0610E['barang_keluar'] ?? 'missing'));
$rTodayE = rowByDate($mvE['rows'], $today);
check('the void date shows the reversal as a real, disclosed inflow (barang_masuk > 0)', $rTodayE !== null && $rTodayE['barang_masuk'] > 0, (string) ($rTodayE['barang_masuk'] ?? 'missing'));
$realE = InventoryService::currentStock($pdo, $itemE, $whE);
check('after the void, final stok_akhir matches real currentStock() (fully reversed back to 100,000)', approx(end($mvE['rows'])['stok_akhir'], (float) $realE['value']), (string) end($mvE['rows'])['stok_akhir'] . ' vs ' . $realE['value']);

// ============================================================
// CASE D — read-only: no report call ever mutates ledger/batch state
// ============================================================
echo "\n== CASE D: reports are strictly read-only ==\n";

$txCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$batchSumBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches')->fetchColumn();

InventoryMovementReportService::dailyMovement($pdo, '2026-06-01', '2026-06-10', $whA);
InventoryMovementReportService::dayBreakdown($pdo, '2026-06-05', $whA);
InventoryMovementReportService::categoryTransactions($pdo, '2026-06-05', $whA, 'external_purchase');
InventoryReconciliationReportService::run($pdo, '2026-06-01', '2026-06-10', $whA);

$txCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$batchSumAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches')->fetchColumn();
check('report calls never change inventory_transactions row count', $txCountBefore === $txCountAfter, "{$txCountBefore} vs {$txCountAfter}");
check('report calls never change total inventory_batches value', approx($batchSumBefore, $batchSumAfter), "{$batchSumBefore} vs {$batchSumAfter}");

$catTx = InventoryMovementReportService::categoryTransactions($pdo, '2026-06-05', $whA, 'external_purchase');
check('categoryTransactions carries a real transaction_id for TraceDrawer drill-down', !empty($catTx) && $catTx[0]['transaction_id'] > 0, (string) (count($catTx)));

// ============================================================
// CASE E — Rekonsiliasi Arus Stok: Difference is real and never hidden
// ============================================================
echo "\n== CASE E: reconciliation Difference is disclosed, never forced to 0 ==\n";

$reconA = InventoryReconciliationReportService::run($pdo, '2026-06-01', '2026-06-10', $whA);
$scopeA = $reconA['scopes'][0];
check('reconciliation returns a status field', in_array($scopeA['status'], ['BALANCE', 'REVIEW', 'REVIEW_TIMING_GAP'], true), $scopeA['status']);
check('reconciliation theoretical_ending matches dailyMovement\'s own last stok_akhir for the same range/scope', approx($scopeA['theoretical_ending'], $lastRow['stok_akhir']), "{$scopeA['theoretical_ending']} vs {$lastRow['stok_akhir']}");
check('a correct, undisturbed scope reconciles to BALANCE with ~0 difference', $scopeA['status'] === 'BALANCE' && approx((float) $scopeA['difference'], 0.0), "{$scopeA['status']} diff={$scopeA['difference']}");

// Deliberate test-only ledger/batch drift (never done outside a throwaway
// test database) — proves Difference surfaces a REAL discrepancy rather
// than being silently forced to balance.
$driftWh = makeWarehouse($pdo, 'MV-DRIFT-WH');
$driftItem = makeItem($pdo, $kgUnitId, 'MV-DRIFT-SKU');
postOpening($pdo, $driftItem, $driftWh, 100, 1000, '2026-06-01 00:00:00', $adminUserId, $kgUnitId);
$pdo->prepare('UPDATE inventory_batches SET qty_base = qty_base - 10 WHERE item_id = :item AND warehouse_id = :wh')
    ->execute(['item' => $driftItem, 'wh' => $driftWh]);
$reconDrift = InventoryReconciliationReportService::run($pdo, '2026-06-01', $today, $driftWh);
$scopeDrift = $reconDrift['scopes'][0];
check('an induced ledger/batch drift produces a nonzero, disclosed Difference (never silently balanced)', abs((float) $scopeDrift['difference']) > 5000.0, (string) $scopeDrift['difference']);
check('an induced drift is flagged REVIEW, not BALANCE', $scopeDrift['status'] !== 'BALANCE', $scopeDrift['status']);

// ============================================================
// CASE F — InventorySummaryReportService (Report 1): consistency with
// the movement engine, expiry omitted when no real expiry data exists
// ============================================================
echo "\n== CASE F: Ringkasan Inventory summary ==\n";

$summaryA = InventorySummaryReportService::summary($pdo, '2026-06-01', '2026-06-10', $whA);
check('summary beginning_inventory_value matches movement engine\'s own first stok_awal', approx($summaryA['beginning_inventory_value'], 100000.0), (string) $summaryA['beginning_inventory_value']);
check('summary ending_inventory_value matches movement engine\'s own last stok_akhir', approx($summaryA['ending_inventory_value'], $lastRow['stok_akhir']), "{$summaryA['ending_inventory_value']} vs {$lastRow['stok_akhir']}");
check('summary external_purchase matches Case A\'s known purchase (60,000)', approx($summaryA['external_purchase'], 60000.0), (string) $summaryA['external_purchase']);
check('summary active_sku is a real positive count', $summaryA['active_sku'] > 0, (string) $summaryA['active_sku']);
check('warehouse-scoped summary reports transfer_in (not null)', $summaryA['transfer_in'] !== null);

$summaryCompany = InventorySummaryReportService::summary($pdo, '2026-06-15', '2026-06-15', null);
check('company-scoped summary omits transfer_in/transfer_out (eliminated) and reports transfer_elimination instead', $summaryCompany['transfer_in'] === null && $summaryCompany['transfer_out'] === null && $summaryCompany['transfer_elimination'] !== null);

// No item anywhere in this fixture has ever had expiry_date populated —
// expiry_warning must be honestly omitted (has_data=false), never a
// fabricated 0.
check('expiry_warning.has_data is false when no batch has a real expiry_date (never a fabricated 0)', $summaryA['expiry_warning']['has_data'] === false && $summaryA['expiry_warning']['count_30d'] === null, json_encode($summaryA['expiry_warning']));

$txCountBeforeSummary = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
InventorySummaryReportService::summary($pdo, '2026-06-01', '2026-06-10', $whA);
$txCountAfterSummary = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
check('InventorySummaryReportService::summary() is read-only', $txCountBeforeSummary === $txCountAfterSummary);

// ============================================================
echo "\n== SUMMARY ==\n";
$total = count($results);
$passed = count(array_filter($results));
echo "{$passed} / {$total} PASSED\n";
if ($passed !== $total) {
    exit(1);
}
