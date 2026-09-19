<?php
declare(strict_types=1);

/**
 * PHASE V2.3B — "FINAL V2.3 COSTING LOGIC SIGN-OFF" costing audit.
 *
 * Proves, with real numbers against a real MySQL database (never by
 * reasoning alone), the 8 deterministic reconciliation cases the sign-off
 * spec names (A-H) plus the multi-day Opening(day N+1) == Ending(day N)
 * identity. Each case uses its own isolated item/warehouse fixture so
 * totals stay hand-computable and cases cannot contaminate each other.
 *
 * This file specifically exists because writing it (Case F) is what
 * surfaced a real defect: InventoryHppReportService's opening/ending/
 * movement totals originally filtered `status = 'POSTED'` only, which
 * zeroed out a voided original transaction's real economic contribution
 * while still counting its REVERSAL's opposite-signed entry — a phantom
 * residue instead of a clean net-zero. Fixed by broadening those filters
 * to `status IN ('POSTED','VOID')` (the FIFO-HPP-from-OUT total is the
 * deliberate exception and stays POSTED-only — see the service's class
 * docblock). This test proves the fix, not just states it.
 *
 * Usage: php tests/inventory_hpp_costing_audit_test.php
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
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/ProductionService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;
use App\Services\VoidService;
use App\Services\TransferService;
use App\Services\ProductionService;
use App\Services\InventoryService;
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
    ->execute(['u' => uid('audituser'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Audit User', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeWarehouse(PDO $pdo, string $tag): int
{
    $pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid($tag) . "', '{$tag}')");
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid($tag);
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 5, :status)');
    $stmt->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpening(PDO $pdo, int $itemId, int $whId, float $qty, float $cost, string $date, int $createdBy, int $unitId): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('audit-open'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $cost,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'audit', 'transaction_type' => 'OPENING',
    ]));
}
function postIn(PDO $pdo, int $itemId, int $whId, float $qty, float $cost, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('audit-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $cost,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'audit',
    ]));
    return (int) $r['transaction_id'];
}
function postOut(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $createdBy, int $unitId): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('audit-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'transaction_type' => 'OUT',
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'audit',
    ]));
    return (int) $r['transaction_id'];
}

// ============================================================
// CASE A — Normal purchase + OUT -> Variance = 0
// ============================================================
echo "== CASE A: normal purchase + OUT -> Variance = 0 ==\n";
$whA = makeWarehouse($pdo, 'CASE-A-WH');
$itemA = makeItem($pdo, $kgUnitId, 'CASE-A-SKU');
postOpening($pdo, $itemA, $whA, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
postIn($pdo, $itemA, $whA, 50, 1200, '2026-04-05 08:00:00', $adminUserId, $kgUnitId);
postOut($pdo, $itemA, $whA, 30, '2026-04-10 08:00:00', $adminUserId, $kgUnitId);
$sA = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whA);
check('Case A: opening = 100,000', approx($sA['opening_value'], 100000.0), (string) $sA['opening_value']);
check('Case A: purchase = 60,000', approx($sA['external_purchase'], 60000.0), (string) $sA['external_purchase']);
check('Case A: fifo_hpp = 30,000', approx($sA['fifo_hpp'], 30000.0), (string) $sA['fifo_hpp']);
check('Case A: ending = 130,000 (100,000+60,000-30,000)', approx($sA['ending_value'], 130000.0), (string) $sA['ending_value']);
check('Case A: reconciliation = 30,000 (equals FIFO HPP)', approx($sA['hpp_reconciliation'], 30000.0), (string) $sA['hpp_reconciliation']);
check('Case A: Variance = 0', approx($sA['variance'], 0.0), (string) $sA['variance']);

// ============================================================
// CASE B — Internal warehouse transfer: consolidated Purchase/HPP
// unaffected, Transfer net = 0, Variance unaffected
// ============================================================
echo "\n== CASE B: internal transfer -> consolidated purchase/HPP unaffected, transfer net = 0 ==\n";
$whB1 = makeWarehouse($pdo, 'CASE-B-WH1');
$whB2 = makeWarehouse($pdo, 'CASE-B-WH2');
$itemB = makeItem($pdo, $kgUnitId, 'CASE-B-SKU');
postOpening($pdo, $itemB, $whB1, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);

// Company-wide (warehouse_id=null) totals span every fixture this whole
// test file creates, not just Case B's — so every consolidated check below
// compares a BEFORE/AFTER delta across exactly this transfer, never an
// absolute value, to stay valid regardless of what other cases have
// already posted.
$beforeCompany = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', null);

$transferResult = TransferService::create($pdo, [
    'transfer_uuid' => uid('audit-transfer'), 'from_warehouse_id' => $whB1, 'to_warehouse_id' => $whB2,
    'ship_date' => '2026-04-05 08:00:00', 'created_by' => $adminUserId, 'username' => 'audit',
    'lines' => [['item_id' => $itemB, 'input_qty' => 20, 'input_unit_id' => $kgUnitId]],
]);
TransferService::receive($pdo, (int) $transferResult['transfer_id'], ['created_by' => $adminUserId, 'username' => 'audit', 'receive_date' => '2026-04-05 09:00:00']);

$sB1 = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whB1);
$sB2 = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whB2);
$afterCompany = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', null);
check('Case B: warehouse 1 (source) shows transfer_out = -20,000', approx($sB1['non_hpp_movements']['transfer_out'], -20000.0), (string) $sB1['non_hpp_movements']['transfer_out']);
check('Case B: warehouse 2 (destination) shows transfer_in = +20,000', approx($sB2['non_hpp_movements']['transfer_in'], 20000.0), (string) $sB2['non_hpp_movements']['transfer_in']);
check('Case B: consolidated external_purchase is unaffected by the transfer (delta = 0) — a transfer is never a company purchase', approx($afterCompany['external_purchase'] - $beforeCompany['external_purchase'], 0.0));
check('Case B: consolidated fifo_hpp is unaffected by the transfer (delta = 0)', approx($afterCompany['fifo_hpp'] - $beforeCompany['fifo_hpp'], 0.0));
check('Case B: consolidated Variance is unaffected by the transfer (delta = 0) — both legs cancel at company level', approx($afterCompany['variance'] - $beforeCompany['variance'], 0.0));
check('Case B: warehouse 1 ending = 80,000 (100,000 - 20,000 transferred out)', approx($sB1['ending_value'], 80000.0), (string) $sB1['ending_value']);
check('Case B: warehouse 2 ending = 20,000 (received)', approx($sB2['ending_value'], 20000.0), (string) $sB2['ending_value']);
check('Case B: warehouse 1 FIFO HPP = 0 (a transfer OUT is not a sale)', approx($sB1['fifo_hpp'], 0.0), (string) $sB1['fifo_hpp']);
// At the SINGLE-warehouse level Variance is legitimately nonzero (the
// transfer reduces/adds Ending value without touching Purchase or FIFO
// HPP) — the spec's "Variance unaffected" is a consolidated-level claim,
// not a per-warehouse one. What matters per-warehouse is that the bridge
// fully explains it via the Transfer bucket, never leaves it a mystery.
check('Case B: warehouse 1 Variance (20,000) is fully explained by the bridge\'s Transfer component, not silently hidden', InventoryHppReportService::varianceBridge($pdo, '2026-04-01', '2026-04-30', $whB1)['is_fully_explained'] === true);
check('Case B: warehouse 2 Variance (-20,000) is fully explained by the bridge\'s Transfer component, not silently hidden', InventoryHppReportService::varianceBridge($pdo, '2026-04-01', '2026-04-30', $whB2)['is_fully_explained'] === true);

// ============================================================
// CASE C — Negative adjustment/shrinkage: FIFO HPP unchanged,
// Variance explains the shrinkage
// ============================================================
echo "\n== CASE C: negative adjustment (shrinkage) -> FIFO HPP unchanged, Variance explains it ==\n";
$whC = makeWarehouse($pdo, 'CASE-C-WH');
$itemC = makeItem($pdo, $kgUnitId, 'CASE-C-SKU');
postOpening($pdo, $itemC, $whC, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('audit-adj-neg'), 'item_id' => $itemC, 'warehouse_id' => $whC,
    'qty_base_delta' => -8, 'adjustment_type' => 'DAMAGE', 'reason' => 'Case C shrinkage',
    'transaction_date' => '2026-04-10 08:00:00', 'created_by' => $adminUserId,
]));
$sC = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whC);
check('Case C: fifo_hpp = 0 (no OUT sale happened — a DAMAGE adjustment is never sales HPP)', approx($sC['fifo_hpp'], 0.0), (string) $sC['fifo_hpp']);
check('Case C: adjustment_net = -8,000', approx($sC['non_hpp_movements']['adjustment_net'], -8000.0), (string) $sC['non_hpp_movements']['adjustment_net']);
check('Case C: Variance = 8,000 (explains the shrinkage, not silently zero)', approx($sC['variance'], 8000.0), (string) $sC['variance']);
$bridgeC = InventoryHppReportService::varianceBridge($pdo, '2026-04-01', '2026-04-30', $whC);
check('Case C: variance bridge is fully explained', $bridgeC['is_fully_explained'] === true, (string) $bridgeC['unexplained']);

// ============================================================
// CASE D — Positive adjustment: Variance explains the gain
// ============================================================
echo "\n== CASE D: positive adjustment (gain) -> Variance explains it ==\n";
$whD = makeWarehouse($pdo, 'CASE-D-WH');
$itemD = makeItem($pdo, $kgUnitId, 'CASE-D-SKU');
postOpening($pdo, $itemD, $whD, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('audit-adj-pos'), 'item_id' => $itemD, 'warehouse_id' => $whD,
    'qty_base_delta' => 6, 'adjustment_type' => 'CORRECTION', 'reason' => 'Case D found stock',
    'override_cost_base' => 1000, 'transaction_date' => '2026-04-10 08:00:00', 'created_by' => $adminUserId,
]));
$sD = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whD);
check('Case D: adjustment_net = +6,000', approx($sD['non_hpp_movements']['adjustment_net'], 6000.0), (string) $sD['non_hpp_movements']['adjustment_net']);
check('Case D: fifo_hpp = 0 (a found-stock gain is never sales HPP)', approx($sD['fifo_hpp'], 0.0), (string) $sD['fifo_hpp']);
check('Case D: Variance = -6,000 (explains the gain)', approx($sD['variance'], -6000.0), (string) $sD['variance']);

// ============================================================
// CASE E — Opname loss: not silently classified as sales HPP,
// disclosed in its own opname_net bucket distinct from generic Adjustment
// ============================================================
echo "\n== CASE E: opname loss -> disclosed separately, never folded into sales HPP ==\n";
$whE = makeWarehouse($pdo, 'CASE-E-WH');
$itemE = makeItem($pdo, $kgUnitId, 'CASE-E-SKU');
postOpening($pdo, $itemE, $whE, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('audit-opname'), 'item_id' => $itemE, 'warehouse_id' => $whE,
    'qty_base_delta' => -4, 'adjustment_type' => 'OPNAME', 'reason' => 'Case E opname count variance',
    'transaction_date' => '2026-04-10 08:00:00', 'created_by' => $adminUserId,
]));
$sE = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whE);
check('Case E: fifo_hpp = 0 (opname loss never counted as sales HPP)', approx($sE['fifo_hpp'], 0.0), (string) $sE['fifo_hpp']);
check('Case E: opname_net = -4,000 (own bucket)', approx($sE['non_hpp_movements']['opname_net'], -4000.0), (string) $sE['non_hpp_movements']['opname_net']);
check('Case E: adjustment_net also includes it (-4,000, since OPNAME is ledger-wise an ADJUSTMENT)', approx($sE['non_hpp_movements']['adjustment_net'], -4000.0), (string) $sE['non_hpp_movements']['adjustment_net']);
$bridgeE = InventoryHppReportService::varianceBridge($pdo, '2026-04-01', '2026-04-30', $whE);
$opnameComponent = null;
$nonOpnameAdjComponent = null;
foreach ($bridgeE['components'] as $c) {
    if ($c['label'] === 'Opname (Gain/Loss)') { $opnameComponent = $c; }
    if ($c['label'] === 'Adjustment (Non-Opname)') { $nonOpnameAdjComponent = $c; }
}
check('Case E: bridge shows the loss under "Opname (Gain/Loss)", not generic Adjustment', $opnameComponent !== null && approx($opnameComponent['raw_value'], -4000.0));
check('Case E: bridge "Adjustment (Non-Opname)" is 0 for this fixture (no double count)', $nonOpnameAdjComponent !== null && approx($nonOpnameAdjComponent['raw_value'], 0.0));

// ============================================================
// CASE F — Reversal (VOID): original and reversal net correctly.
// This is the case whose construction surfaced the real bug fixed in
// this same commit — proven here against the actual, trusted
// InventoryService::currentStock(), not just against the report's own
// internal arithmetic.
// ============================================================
echo "\n== CASE F: void/reversal nets correctly against real currentStock() ==\n";

// F1: void an IN (reverseBatchCreation path)
$whF1 = makeWarehouse($pdo, 'CASE-F1-WH');
$itemF1 = makeItem($pdo, $kgUnitId, 'CASE-F1-SKU');
$inTxF1 = postIn($pdo, $itemF1, $whF1, 100, 1000, '2026-04-01 08:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('audit-void-in'), 'transaction_id' => $inTxF1, 'reason' => 'Case F1 void', 'voided_by' => $adminUserId, 'username' => 'audit',
]));
$today = date('Y-m-d');
$sF1 = InventoryHppReportService::summary($pdo, '2026-04-01', $today, $whF1);
$realF1 = InventoryService::currentStock($pdo, $itemF1, $whF1);
check('Case F1 (void an IN): report ending_value matches real currentStock() (both 0)', approx($sF1['ending_value'], (float) $realF1['value']), "report={$sF1['ending_value']} real={$realF1['value']}");
check('Case F1: reversal_net = -100,000 (the offsetting entry, disclosed not hidden)', approx($sF1['non_hpp_movements']['reversal_net'], -100000.0), (string) $sF1['non_hpp_movements']['reversal_net']);
$bridgeF1 = InventoryHppReportService::varianceBridge($pdo, '2026-04-01', $today, $whF1);
check('Case F1: variance bridge is fully explained by the Reversal bucket', $bridgeF1['is_fully_explained'] === true, (string) $bridgeF1['unexplained']);

// F2: void an OUT (reverseBatchConsumption path)
$whF2 = makeWarehouse($pdo, 'CASE-F2-WH');
$itemF2 = makeItem($pdo, $kgUnitId, 'CASE-F2-SKU');
postOpening($pdo, $itemF2, $whF2, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
$outTxF2 = postOut($pdo, $itemF2, $whF2, 40, '2026-04-01 08:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('audit-void-out'), 'transaction_id' => $outTxF2, 'reason' => 'Case F2 void', 'voided_by' => $adminUserId, 'username' => 'audit',
]));
$sF2 = InventoryHppReportService::summary($pdo, '2026-01-01', $today, $whF2);
$realF2 = InventoryService::currentStock($pdo, $itemF2, $whF2);
check('Case F2 (void an OUT): report ending_value matches real currentStock() (both 100,000)', approx($sF2['ending_value'], (float) $realF2['value']), "report={$sF2['ending_value']} real={$realF2['value']}");
check('Case F2: fifo_hpp over the period is 0 (the OUT was voided — those goods never actually left)', approx($sF2['fifo_hpp'], 0.0), (string) $sF2['fifo_hpp']);
$bridgeF2 = InventoryHppReportService::varianceBridge($pdo, '2026-01-01', $today, $whF2);
check('Case F2: variance bridge is fully explained', $bridgeF2['is_fully_explained'] === true, (string) $bridgeF2['unexplained']);

// ============================================================
// CASE G — Production movement: PRODUCTION_OUT/PRODUCTION_IN net to ~0
// at company level (pure internal conversion, no value created/destroyed)
// ============================================================
echo "\n== CASE G: production (raw material consumed -> finished good) nets correctly ==\n";
$whG = makeWarehouse($pdo, 'CASE-G-WH');
$rawItemG = makeItem($pdo, $kgUnitId, 'CASE-G-RAW');
$finishedItemG = makeItem($pdo, $kgUnitId, 'CASE-G-FIN');
postOpening($pdo, $rawItemG, $whG, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);

$prod = ProductionService::create($pdo, [
    'production_uuid' => uid('audit-prod'), 'warehouse_id' => $whG, 'production_date' => '2026-04-05 08:00:00',
    'created_by' => $adminUserId, 'username' => 'audit',
    'inputs' => [['item_id' => $rawItemG, 'input_qty' => 30, 'input_unit_id' => $kgUnitId]],
    'output' => ['item_id' => $finishedItemG, 'output_qty' => 30, 'output_unit_id' => $kgUnitId],
]);
check('Case G: total input cost = 30,000', approx($prod['total_input_cost'], 30000.0), (string) $prod['total_input_cost']);

$sGCompany = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whG);
check('Case G: production_net ~= 0 at warehouse level (PRODUCTION_IN -30,000 + PRODUCTION_OUT +30,000)', approx($sGCompany['non_hpp_movements']['production_net'], 0.0), (string) $sGCompany['non_hpp_movements']['production_net']);
check('Case G: ending value unchanged by production (100,000 - pure conversion, no value created/destroyed)', approx($sGCompany['ending_value'], 100000.0), (string) $sGCompany['ending_value']);
check('Case G: fifo_hpp = 0 (production consumption/output is never a sale)', approx($sGCompany['fifo_hpp'], 0.0), (string) $sGCompany['fifo_hpp']);
check('Case G: Variance = 0', approx($sGCompany['variance'], 0.0), (string) $sGCompany['variance']);

$rawStock = InventoryService::currentStock($pdo, $rawItemG, $whG);
$finStock = InventoryService::currentStock($pdo, $finishedItemG, $whG);
check('Case G: raw material stock reduced by exactly the consumed qty (70 left)', approx((float) $rawStock['qty_base'], 70.0));
check('Case G: finished good created with the derived unit cost (30 @ 1000 = 30,000)', approx((float) $finStock['value'], 30000.0), (string) $finStock['value']);

// ============================================================
// CASE H — Historical inventory_effect=0: no economic impact whatsoever
// ============================================================
echo "\n== CASE H: historical import (inventory_effect=0) -> zero impact ==\n";
$whH = makeWarehouse($pdo, 'CASE-H-WH');
$itemH = makeItem($pdo, $kgUnitId, 'CASE-H-SKU');
postOpening($pdo, $itemH, $whH, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
postIn($pdo, $itemH, $whH, 20, 1000, '2026-04-05 08:00:00', $adminUserId, $kgUnitId);

$sHBefore = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whH);

// Mimic exactly what ImportHistoricalTransactionService::commit() writes for one
// row — is_historical_import=1, inventory_effect=0, status=POSTED, deliberately
// no inventory_batches row and no fifo_allocations (see that service's own
// comment) — without going through CSV staging, since this test's concern is
// the REPORT's exclusion logic, not the importer itself (covered by
// tests/mysql_importer_test.php).
$now = date('Y-m-d H:i:s');
$histStmt = $pdo->prepare(
    'INSERT INTO inventory_transactions
        (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
     VALUES (:uuid, \'IN\', :tx_date, :post_date, :wh, \'POSTED\', 1, 0, :created_by, :created_at)'
);
$histStmt->execute(['uuid' => uid('audit-hist'), 'tx_date' => '2026-04-15 08:00:00', 'post_date' => $now, 'wh' => $whH, 'created_by' => $adminUserId, 'created_at' => $now]);
$histTxId = (int) $pdo->lastInsertId();
$histLineStmt = $pdo->prepare(
    'INSERT INTO inventory_transaction_lines
        (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
     VALUES (:tx_id, 1, :item_id, \'hist\', 999, :unit, 1, 999, 5000, 5000, 4995000, :wh)'
);
$histLineStmt->execute(['tx_id' => $histTxId, 'item_id' => $itemH, 'unit' => $kgUnitId, 'wh' => $whH]);

$sHAfter = InventoryHppReportService::summary($pdo, '2026-04-01', '2026-04-30', $whH);
check('Case H: opening_value unchanged by the historical row', approx($sHBefore['opening_value'], $sHAfter['opening_value']));
check('Case H: external_purchase unchanged (the historical IN-type row contributes nothing)', approx($sHBefore['external_purchase'], $sHAfter['external_purchase']));
check('Case H: ending_value unchanged', approx($sHBefore['ending_value'], $sHAfter['ending_value']));
check('Case H: fifo_hpp unchanged', approx($sHBefore['fifo_hpp'], $sHAfter['fifo_hpp']));
check('Case H: Variance unchanged (still 0)', approx($sHAfter['variance'], 0.0), (string) $sHAfter['variance']);
$realHAfter = InventoryService::currentStock($pdo, $itemH, $whH);
check('Case H: real currentStock() also unaffected (no batch/allocation was ever created for the historical row)', approx((float) $realHAfter['value'], $sHAfter['ending_value']));

// ============================================================
// MULTI-DAY PROOF — Ending(day N) == Opening(day N+1) when there are no
// overnight corrections (a single-day summary's ending_value must equal
// the very next day's opening_value, proving the historical valuation
// method is a genuine point-in-time cutoff, not a "current state" snapshot).
// ============================================================
echo "\n== MULTI-DAY: Ending(day N) == Opening(day N+1) ==\n";
$whM = makeWarehouse($pdo, 'CASE-M-WH');
$itemM = makeItem($pdo, $kgUnitId, 'CASE-M-SKU');
postOpening($pdo, $itemM, $whM, 100, 1000, '2026-01-01', $adminUserId, $kgUnitId);
postIn($pdo, $itemM, $whM, 20, 1500, '2026-05-02 08:00:00', $adminUserId, $kgUnitId);
postOut($pdo, $itemM, $whM, 15, '2026-05-03 08:00:00', $adminUserId, $kgUnitId);
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('audit-multi-adj'), 'item_id' => $itemM, 'warehouse_id' => $whM,
    'qty_base_delta' => -3, 'adjustment_type' => 'DAMAGE', 'reason' => 'multi-day proof',
    'transaction_date' => '2026-05-04 08:00:00', 'created_by' => $adminUserId,
]));
postIn($pdo, $itemM, $whM, 10, 1100, '2026-05-05 08:00:00', $adminUserId, $kgUnitId);

$days = ['2026-05-01', '2026-05-02', '2026-05-03', '2026-05-04', '2026-05-05'];
$prevEnding = null;
$allMatched = true;
foreach ($days as $d) {
    $s = InventoryHppReportService::summary($pdo, $d, $d, $whM);
    if ($prevEnding !== null && !approx($s['opening_value'], $prevEnding)) {
        $allMatched = false;
        echo "  MISMATCH on {$d}: opening={$s['opening_value']} expected(prior ending)={$prevEnding}\n";
    }
    echo "  {$d}: opening={$s['opening_value']} ending={$s['ending_value']}\n";
    $prevEnding = $s['ending_value'];
}
check('Multi-day: every day\'s opening_value equals the prior day\'s ending_value (2026-05-01 .. 2026-05-05)', $allMatched);

$sWhole = InventoryHppReportService::summary($pdo, '2026-05-01', '2026-05-05', $whM);
$sLastDay = InventoryHppReportService::summary($pdo, '2026-05-05', '2026-05-05', $whM);
check('Multi-day: the whole-range ending_value equals the last single day\'s ending_value', approx($sWhole['ending_value'], $sLastDay['ending_value']));

// ============================================================
// HISTORICAL OPENING VALUE — prove the exact cutoff method for an
// arbitrary historical date: value strictly BEFORE that date's start, not
// "today's current value". Uses the multi-day fixture above, whose real
// current value (today, 2026-09-19) differs from its value as of an
// early-period historical date.
// ============================================================
echo "\n== HISTORICAL OPENING: proves the cutoff is the given date, not \"now\" ==\n";
$openingOn0502 = InventoryHppReportService::summary($pdo, '2026-05-02', '2026-05-02', $whM)['opening_value'];
check('Historical opening on 2026-05-02 = 100,000 (value immediately before that date\'s start — the 05-02 IN itself is NOT yet counted)', approx($openingOn0502, 100000.0), (string) $openingOn0502);
$realCurrentM = InventoryService::currentStock($pdo, $itemM, $whM);
check('Historical opening on 2026-05-02 differs from today\'s real current value (proves this is a point-in-time cutoff, not "now")', !approx($openingOn0502, (float) $realCurrentM['value']), "historical={$openingOn0502} current={$realCurrentM['value']}");

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
echo "==============================\n";
exit($passed === $total ? 0 : 1);
