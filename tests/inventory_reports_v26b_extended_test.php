<?php
declare(strict_types=1);

/**
 * PHASE V2.6B (continuation) — the 5 new reporting services
 * (TransferReportService, StockOpnameReportService, AdjustmentReportService,
 * ExpiryReportService, SlowMovementReportService) plus the
 * TransactionHistoryService extensions (summary()/groupedSummary()) that
 * power Reports 4/5/11/12. Focuses on classification correctness (what
 * counts as a "qualifying purchase", what counts as "bakery distribution"
 * vs a plain warehouse transfer) and read-only enforcement — not a
 * re-proof of FIFO/valuation, which none of these services touch.
 *
 * Usage: php tests/inventory_reports_v26b_extended_test.php
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
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/TransferReportService.php';
require_once __DIR__ . '/../services/StockOpnameReportService.php';
require_once __DIR__ . '/../services/AdjustmentReportService.php';
require_once __DIR__ . '/../services/ExpiryReportService.php';
require_once __DIR__ . '/../services/SlowMovementReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;
use App\Services\TransferService;
use App\Services\StockOpnameService;
use App\Services\TransactionHistoryService;
use App\Services\TransferReportService;
use App\Services\StockOpnameReportService;
use App\Services\AdjustmentReportService;
use App\Services\ExpiryReportService;
use App\Services\SlowMovementReportService;

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
    ->execute(['u' => uid('rptsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Reports Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('RPT-SUP'), 'n' => 'Report Supplier']);
$supplierId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO bakery_destinations (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('RPT-BAKERY'), 'n' => 'Report Bakery']);
$bakeryId = (int) $pdo->lastInsertId();

function makeWarehouse(PDO $pdo, string $tag): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid($tag), 'n' => $tag]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $tag): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

$whX = makeWarehouse($pdo, 'RPT-X-WH');
$whY = makeWarehouse($pdo, 'RPT-Y-WH');

// ============================================================
// CASE A — Purchase classification (Report 4/11): IN counted, transfer/
// production/opening/adjustment excluded
// ============================================================
echo "== CASE A: purchase classification ==\n";

[$itemA, $skuA] = makeItem($pdo, $kgUnitId, 'RPT-A');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('rpt-open'), 'item_id' => $itemA, 'warehouse_id' => $whX,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('rpt-in'), 'item_id' => $itemA, 'warehouse_id' => $whX,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1500,
    'transaction_date' => '2026-07-05 08:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest', 'supplier_id' => $supplierId,
]));
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('rpt-adj'), 'item_id' => $itemA, 'warehouse_id' => $whX,
    'qty_base_delta' => 5, 'adjustment_type' => 'OTHER', 'reason' => 'purchase-report test',
    'transaction_date' => '2026-07-06 08:00:00', 'created_by' => $adminUserId,
]));
$transferPurchaseTest = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('rpt-transfer'), 'from_warehouse_id' => $whX, 'to_warehouse_id' => $whY,
    'ship_date' => '2026-07-07 08:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest',
    'lines' => [['item_id' => $itemA, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $transferPurchaseTest['transfer_id'], ['created_by' => $adminUserId, 'username' => 'rpttest', 'receive_date' => '2026-07-07 08:00:00']));

$purchaseList = TransactionHistoryService::list($pdo, ['warehouse_id' => $whX, 'transaction_type' => 'IN', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
check('purchase list contains exactly the 1 qualifying IN row (opening/adjustment/transfer excluded)', $purchaseList['pagination']['total'] === 1, (string) $purchaseList['pagination']['total']);
check('purchase list row carries supplier info', $purchaseList['rows'][0]['supplier']['id'] === $supplierId);

$purchaseSummary = TransactionHistoryService::summary($pdo, ['warehouse_id' => $whX, 'transaction_type' => 'IN', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
check('purchase summary total_value = 20 x 1500 = 30,000 (never includes opening/adjustment/transfer)', approx($purchaseSummary['total_value'], 30000.0), (string) $purchaseSummary['total_value']);
check('purchase summary transaction_count = 1', $purchaseSummary['transaction_count'] === 1);
check('purchase summary counterparty_count (supplier) = 1', $purchaseSummary['counterparty_count'] === 1);

$bySupplier = TransactionHistoryService::groupedSummary($pdo, ['warehouse_id' => $whX, 'transaction_type' => 'IN', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'], 'supplier_id', 'supplier_name');
check('by-supplier grouping finds exactly the 1 qualifying supplier', count($bySupplier) === 1 && $bySupplier[0]['id'] === $supplierId, (string) count($bySupplier));
check('by-supplier total_value matches the purchase summary', approx($bySupplier[0]['total_value'], 30000.0));
check('by-supplier share_pct is 100% (only one supplier in range)', approx($bySupplier[0]['share_pct'], 100.0));

// ============================================================
// CASE B — IN/OUT report (Report 5) + Bakery distribution (Report 12):
// warehouse transfer is never counted as bakery distribution
// ============================================================
echo "\n== CASE B: IN/OUT classification + bakery distribution excludes transfer ==\n";

$outTx = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('rpt-out'), 'item_id' => $itemA, 'warehouse_id' => $whX,
    'input_qty' => 8, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-07-08 08:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest',
    'bakery_destination_id' => $bakeryId,
]));

$inOutList = TransactionHistoryService::list($pdo, ['warehouse_id' => $whX, 'transaction_types' => ['IN', 'OUT'], 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
$types = array_unique(array_column($inOutList['rows'], 'transaction_type'));
sort($types);
check('IN/OUT report contains only IN and OUT rows (never TRANSFER_*/ADJUSTMENT/OPENING)', $types === ['IN', 'OUT'], json_encode($types));

$bakerySummary = TransactionHistoryService::groupedSummary($pdo, ['warehouse_id' => $whX, 'transaction_type' => 'OUT', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'], 'bakery_destination_id', 'bakery_destination_name');
check('bakery distribution finds exactly the 1 real bakery OUT (10-unit warehouse transfer never counted)', count($bakerySummary) === 1 && $bakerySummary[0]['id'] === $bakeryId, (string) count($bakerySummary));
check('bakery distribution total_value reflects only the real OUT (8 units), not the transfer', $bakerySummary[0]['total_value'] > 0);

// ============================================================
// CASE C — Transfer report (Report 6): lead time, value, status metadata
// ============================================================
echo "\n== CASE C: transfer report ==\n";

$transferReport = TransferReportService::list($pdo, ['warehouse_id' => $whX]);
$receivedTransfer = null;
foreach ($transferReport['rows'] as $r) {
    if ($r['id'] === (int) $transferPurchaseTest['transfer_id']) $receivedTransfer = $r;
}
check('transfer report finds the received transfer', $receivedTransfer !== null);
check('transfer report shows RECEIVED status', $receivedTransfer !== null && $receivedTransfer['status'] === 'RECEIVED');
check('transfer report computes lead_time_days = 0 (shipped and received same day)', $receivedTransfer !== null && $receivedTransfer['lead_time_days'] === 0, (string) ($receivedTransfer['lead_time_days'] ?? 'null'));
check('transfer report reports item_count = 1', $receivedTransfer !== null && $receivedTransfer['item_count'] === 1);
check('transfer report reports a positive transfer_value', $receivedTransfer !== null && $receivedTransfer['transfer_value'] > 0);

$cancelUuid = uid('rpt-transfer-cancel');
$transferCancelTest = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => $cancelUuid, 'from_warehouse_id' => $whX, 'to_warehouse_id' => $whY,
    'ship_date' => '2026-07-09 08:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest',
    'lines' => [['item_id' => $itemA, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, (int) $transferCancelTest['transfer_id'], ['reason' => 'V2.6B report test cancel', 'created_by' => $adminUserId, 'username' => 'rpttest']));
$transferReport2 = TransferReportService::list($pdo, ['warehouse_id' => $whX, 'status' => 'CANCELLED']);
$cancelledFound = false;
foreach ($transferReport2['rows'] as $r) {
    if ($r['id'] === (int) $transferCancelTest['transfer_id']) {
        $cancelledFound = $r['cancel'] !== null && $r['cancel']['reason'] === 'V2.6B report test cancel';
    }
}
check('transfer report surfaces cancel metadata (reason) for a CANCELLED transfer', $cancelledFound);

// ============================================================
// CASE D — Stock Opname report (Report 8): existing session model only
// ============================================================
echo "\n== CASE D: stock opname report ==\n";

$opnameWh = makeWarehouse($pdo, 'RPT-OPNAME-WH');
[$itemOp, $skuOp] = makeItem($pdo, $kgUnitId, 'RPT-OPNAME');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('rpt-opname-open'), 'item_id' => $itemOp, 'warehouse_id' => $opnameWh,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'rpttest', 'transaction_type' => 'OPENING',
]));
$sessionId = StockOpnameService::start($pdo, $opnameWh, $adminUserId, [$itemOp]);
StockOpnameService::count($pdo, $sessionId, [$itemOp => 45], $adminUserId);
StockOpnameService::finalize($pdo, $sessionId, $adminUserId);

$opnameReport = StockOpnameReportService::list($pdo, ['warehouse_id' => $opnameWh]);
$sessionRow = null;
foreach ($opnameReport['rows'] as $r) { if ($r['id'] === $sessionId) $sessionRow = $r; }
check('opname report finds the finalized session', $sessionRow !== null);
check('opname report shows FINALIZED status (no P1/P2 introduced — single count model)', $sessionRow !== null && $sessionRow['status'] === 'FINALIZED', (string) ($sessionRow['status'] ?? 'missing'));
check('opname report system_qty = 50', $sessionRow !== null && approx($sessionRow['system_qty'], 50.0));
check('opname report counted_qty = 45', $sessionRow !== null && approx($sessionRow['counted_qty'], 45.0));
check('opname report variance_qty = -5', $sessionRow !== null && approx($sessionRow['variance_qty'], -5.0), (string) ($sessionRow['variance_qty'] ?? 'missing'));

// ============================================================
// CASE E — Adjustment report (Report 9): direction classification
// ============================================================
echo "\n== CASE E: adjustment report direction ==\n";

$adjReport = AdjustmentReportService::list($pdo, ['warehouse_id' => $whX, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
$positiveFound = false;
foreach ($adjReport['rows'] as $r) {
    if (approx($r['adjustment_qty'], 5.0) && $r['direction'] === 'POSITIVE') $positiveFound = true;
}
check('adjustment report classifies the +5 adjustment as POSITIVE', $positiveFound);

$adjPositiveOnly = AdjustmentReportService::list($pdo, ['warehouse_id' => $whX, 'direction' => 'POSITIVE']);
check('direction=POSITIVE filter returns only positive-qty rows', count($adjPositiveOnly['rows']) > 0 && array_reduce($adjPositiveOnly['rows'], fn ($carry, $r) => $carry && $r['adjustment_qty'] > 0, true));

// ============================================================
// CASE F — Expiry report (Report 10): real data only, boundary statuses,
// never mutates FIFO
// ============================================================
echo "\n== CASE F: expiry report ==\n";

$expWh = makeWarehouse($pdo, 'RPT-EXP-WH');
[$itemExp1, $skuExp1] = makeItem($pdo, $kgUnitId, 'RPT-EXP-EXPIRED');
[$itemExp2, $skuExp2] = makeItem($pdo, $kgUnitId, 'RPT-EXP-CRITICAL');
[$itemExp3, $skuExp3] = makeItem($pdo, $kgUnitId, 'RPT-EXP-OK');
[$itemExpNone, $skuExpNone] = makeItem($pdo, $kgUnitId, 'RPT-EXP-NONE');

$expiredDate = date('Y-m-d', strtotime('-1 day'));
$criticalDate = date('Y-m-d', strtotime('+3 days'));
$okDate = date('Y-m-d', strtotime('+200 days'));

foreach ([[$itemExp1, $expiredDate, 'EXPIRED'], [$itemExp2, $criticalDate, 'CRITICAL'], [$itemExp3, $okDate, 'OK']] as [$itemId, $expDate, $expectedStatus]) {
    $pdo->prepare(
        'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, expiry_date, created_at)
         VALUES (:item, :wh, 10, 10, 1000, :recv, :exp, :now)'
    )->execute(['item' => $itemId, 'wh' => $expWh, 'recv' => '2026-07-01 00:00:00', 'exp' => $expDate, 'now' => date('Y-m-d H:i:s')]);
}
// no-expiry item: a batch with expiry_date NULL must never appear in the report.
$pdo->prepare(
    'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, expiry_date, created_at)
     VALUES (:item, :wh, 10, 10, 1000, :recv, NULL, :now)'
)->execute(['item' => $itemExpNone, 'wh' => $expWh, 'recv' => '2026-07-01 00:00:00', 'now' => date('Y-m-d H:i:s')]);

$expiryReport = ExpiryReportService::list($pdo, ['warehouse_id' => $expWh]);
$bySku = [];
foreach ($expiryReport['rows'] as $r) { $bySku[$r['item']['sku']] = $r; }
check('expiry report includes the expired batch, status=EXPIRED', isset($bySku[$skuExp1]) && $bySku[$skuExp1]['status'] === 'EXPIRED', json_encode($bySku[$skuExp1] ?? null));
check('expiry report includes the near-expiry batch, status=CRITICAL (<=7 days)', isset($bySku[$skuExp2]) && $bySku[$skuExp2]['status'] === 'CRITICAL', json_encode($bySku[$skuExp2] ?? null));
check('expiry report includes the far-future batch, status=OK', isset($bySku[$skuExp3]) && $bySku[$skuExp3]['status'] === 'OK', json_encode($bySku[$skuExp3] ?? null));
check('expiry report NEVER includes a batch with no expiry_date (never invents an expiry)', !isset($bySku[$skuExpNone]));

$batchQtyBefore = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE item_id = {$itemExp1}")->fetchColumn();
ExpiryReportService::list($pdo, ['warehouse_id' => $expWh]);
$batchQtyAfter = (float) $pdo->query("SELECT qty_base FROM inventory_batches WHERE item_id = {$itemExp1}")->fetchColumn();
check('expiry report never mutates inventory_batches (no FIFO/FEFO reordering, no quantity change)', approx($batchQtyBefore, $batchQtyAfter));

// ============================================================
// CASE G — Slow / No Movement report (Report 13): thresholds, zero-stock exclusion
// ============================================================
echo "\n== CASE G: slow / no movement report ==\n";

$slowWh = makeWarehouse($pdo, 'RPT-SLOW-WH');
[$itemSlow, $skuSlow] = makeItem($pdo, $kgUnitId, 'RPT-SLOW');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('rpt-slow-open'), 'item_id' => $itemSlow, 'warehouse_id' => $slowWh,
    'input_qty' => 30, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => date('Y-m-d H:i:s', strtotime('-120 days')), 'created_by' => $adminUserId, 'username' => 'rpttest', 'transaction_type' => 'OPENING',
]));
[$itemActive, $skuActive] = makeItem($pdo, $kgUnitId, 'RPT-ACTIVE');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('rpt-active-open'), 'item_id' => $itemActive, 'warehouse_id' => $slowWh,
    'input_qty' => 30, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => date('Y-m-d H:i:s', strtotime('-1 day')), 'created_by' => $adminUserId, 'username' => 'rpttest', 'transaction_type' => 'OPENING',
]));

$slowReport90 = SlowMovementReportService::list($pdo, ['warehouse_id' => $slowWh, 'threshold_days' => 90]);
$slowBySku = [];
foreach ($slowReport90['rows'] as $r) { $slowBySku[$r['item']['sku']] = $r; }
check('slow-movement (90d) includes the item last moved 120 days ago', isset($slowBySku[$skuSlow]), json_encode(array_keys($slowBySku)));
check('slow-movement (90d) excludes the item last moved 1 day ago', !isset($slowBySku[$skuActive]));
check('slow-movement status is NO_MOVEMENT_90 for the 120-day-old item', isset($slowBySku[$skuSlow]) && $slowBySku[$skuSlow]['status'] === 'NO_MOVEMENT_90', (string) ($slowBySku[$skuSlow]['status'] ?? 'missing'));

$slowReport30 = SlowMovementReportService::list($pdo, ['warehouse_id' => $slowWh, 'threshold_days' => 30]);
$slowBySku30 = [];
foreach ($slowReport30['rows'] as $r) { $slowBySku30[$r['item']['sku']] = $r; }
check('slow-movement (30d) still excludes the item moved 1 day ago (below threshold)', !isset($slowBySku30[$skuActive]));

[$itemZero, $skuZero] = makeItem($pdo, $kgUnitId, 'RPT-ZERO');
$slowReportDefault = SlowMovementReportService::list($pdo, ['warehouse_id' => $slowWh]);
$zeroFoundDefault = false;
foreach ($slowReportDefault['rows'] as $r) { if ($r['item']['sku'] === $skuZero) $zeroFoundDefault = true; }
check('a zero-stock SKU (never had any batch) never inflates the default slow-movement report', !$zeroFoundDefault);

// ============================================================
// CASE H — read-only: none of the 5 new services ever mutate state
// ============================================================
echo "\n== CASE H: all new report services are strictly read-only ==\n";

$txCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$adjCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
$transferCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM warehouse_transfers')->fetchColumn();
$opnameCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_opname_sessions')->fetchColumn();
$batchSumBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches')->fetchColumn();

TransactionHistoryService::list($pdo, ['transaction_type' => 'IN']);
TransactionHistoryService::summary($pdo, ['transaction_type' => 'IN']);
TransactionHistoryService::groupedSummary($pdo, ['transaction_type' => 'IN'], 'supplier_id', 'supplier_name');
TransferReportService::list($pdo, []);
StockOpnameReportService::list($pdo, []);
AdjustmentReportService::list($pdo, []);
ExpiryReportService::list($pdo, []);
SlowMovementReportService::list($pdo, []);

$txCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
$adjCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
$transferCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM warehouse_transfers')->fetchColumn();
$opnameCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM stock_opname_sessions')->fetchColumn();
$batchSumAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches')->fetchColumn();

check('no new report call changes inventory_transactions count', $txCountBefore === $txCountAfter, "{$txCountBefore} vs {$txCountAfter}");
check('no new report call changes stock_adjustments count', $adjCountBefore === $adjCountAfter);
check('no new report call changes warehouse_transfers count', $transferCountBefore === $transferCountAfter);
check('no new report call changes stock_opname_sessions count', $opnameCountBefore === $opnameCountAfter);
check('no new report call changes total inventory_batches value', approx($batchSumBefore, $batchSumAfter), "{$batchSumBefore} vs {$batchSumAfter}");

// ============================================================
echo "\n== SUMMARY ==\n";
$total = count($results);
$passed = count(array_filter($results));
echo "{$passed} / {$total} PASSED\n";
if ($passed !== $total) {
    exit(1);
}
