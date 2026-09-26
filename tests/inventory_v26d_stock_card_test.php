<?php
declare(strict_types=1);

/**
 * PHASE V2.6D — Laporan Stok "all products + stock card" enhancement.
 * Covers: all-product population (incl. zero-stock/never-transacted
 * items), the AMAN/WARNING/HABIS formula, stock figures agreeing with
 * the existing source of truth (InventoryService::currentStock()),
 * signed negative-migration display, company-wide vs per-warehouse
 * aggregation, Aman/Warning/Habis counters over the FULL filtered
 * dataset (never just the current page), Kartu Stok (GET
 * /reports/stock/card — every legitimate movement type, correct running
 * balance, historical 1-15 Sep excluded from the live balance,
 * traceable rows resolve via the existing Trace Center), the new
 * 8-column "Laporan Stok" CSV export (zero-stock included, warehouse
 * scope respected, formula-injection protection still applies), the old
 * 14-column "Stok Barang" export left byte-identical, and read-only
 * enforcement. Runs PHP-level assertions directly against the services,
 * plus one HTTP section (php -S) for warehouse-scope/export/read-only
 * checks — same harness pattern as tests/inventory_v26_final_gate_test.php.
 *
 * Usage: php tests/inventory_v26d_stock_card_test.php
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
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/StockReportService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use App\Services\StockReportService;
use App\Services\TransferService;
use App\Services\UnitConversionService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v26dsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V26D Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V26D-A'), 'n' => 'V26D Warehouse A']);
$whA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V26D-B'), 'n' => 'V26D Warehouse B']);
$whB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO categories (code, name) VALUES ('" . uid('V26D-STATUS-CAT') . "', 'V26D Status Fixtures')");
$catStatus = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag, float $minimumStock = 0, ?int $categoryId = null): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, category_id, status) VALUES (:sku, :name, :unit, :min, :cat, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'min' => $minimumStock, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

function approveMigrationNegative(PDO $pdo, string $sku, string $whCode, float $ending): void
{
    $pdo->prepare(
        'INSERT INTO movement_reconciliation_reviews
            (sku, warehouse_code, historical_opening, historical_in, historical_out, historical_calculated_ending,
             status, is_migration_negative_approved, migration_negative_approved_by_name, migration_negative_note)
         VALUES (:sku, :wh, 0, 0, 0, :ending, \'PENDING_FINAL_STOCK\', 1, \'Test Owner\', \'test whitelist row\')'
    )->execute(['sku' => $sku, 'wh' => $whCode, 'ending' => $ending]);
}

$whACode = (string) $pdo->query("SELECT code FROM warehouses WHERE id = {$whA}")->fetchColumn();

// Pin live_opening_date far in the past so 2026 fixture dates run fully live.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-pin'), 'item_id' => makeItem($pdo, $kgUnitId, 'V26D-PIN')[0], 'warehouse_id' => $whA,
    'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1,
    'transaction_date' => '2020-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));

// ============================================================
// A — ALL PRODUCT population + B/C — STATUS + STOCK formula fixtures
// ============================================================
echo "== A/B/C: all-product population, status formula, signed stock ==\n";

// Never transacted at all — must still appear, qty 0, HABIS.
[$itemNeverBatched, $skuNeverBatched] = makeItem($pdo, $kgUnitId, 'V26D-NEVER', 10, $catStatus);

// Zero stock after being fully consumed (had a batch once, now 0).
[$itemZeroAfterOut, $skuZeroAfterOut] = makeItem($pdo, $kgUnitId, 'V26D-ZEROOUT', 10, $catStatus);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-zo-in'), 'item_id' => $itemZeroAfterOut, 'warehouse_id' => $whA,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('v26d-zo-out'), 'item_id' => $itemZeroAfterOut, 'warehouse_id' => $whA,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-07-02 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d',
]));

// Positive stock below minimum -> WARNING.
[$itemBelowMin, $skuBelowMin] = makeItem($pdo, $kgUnitId, 'V26D-BELOWMIN', 10, $catStatus);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-below'), 'item_id' => $itemBelowMin, 'warehouse_id' => $whA,
    'input_qty' => 5, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));

// Stock exactly at minimum -> WARNING (owner spec: "<=" not "<").
[$itemExactMin, $skuExactMin] = makeItem($pdo, $kgUnitId, 'V26D-EXACTMIN', 10, $catStatus);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-exact'), 'item_id' => $itemExactMin, 'warehouse_id' => $whA,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));

// Stock above minimum -> AMAN.
[$itemAboveMin, $skuAboveMin] = makeItem($pdo, $kgUnitId, 'V26D-ABOVEMIN', 10, $catStatus);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-above'), 'item_id' => $itemAboveMin, 'warehouse_id' => $whA,
    'input_qty' => 11, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));

// Negative migration item -> HABIS, signed display preserved (never clamped to 0).
[$itemMigNeg, $skuMigNeg] = makeItem($pdo, $kgUnitId, 'V26D-MIGNEG', 10, $catStatus);
approveMigrationNegative($pdo, $skuMigNeg, $whACode, -0.5);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-migneg'), 'item_id' => $itemMigNeg, 'warehouse_id' => $whA,
    'input_qty' => -0.5, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_type' => 'OPENING', 'transaction_date' => '2026-07-01 00:00:00',
    'created_by' => $adminUserId, 'username' => 'v26d', 'allow_migration_negative_opening' => true,
]));

$allReport = StockReportService::list($pdo, ['warehouse_id' => $whA, 'category_id' => $catStatus, 'page' => 1, 'per_page' => 200]);
$bySku = [];
foreach ($allReport['rows'] as $r) { $bySku[$r['sku']] = $r; }

check('item never transacted still appears (all-product, never fabricates a batch)', isset($bySku[$skuNeverBatched]));
check('never-transacted item shows qty_base = 0', isset($bySku[$skuNeverBatched]) && $bySku[$skuNeverBatched]['qty_base'] === 0.0);
check('never-transacted item shows value = 0', isset($bySku[$skuNeverBatched]) && $bySku[$skuNeverBatched]['value'] === 0.0);
check('never-transacted item report_status = HABIS', isset($bySku[$skuNeverBatched]) && $bySku[$skuNeverBatched]['report_status'] === 'HABIS');

check('item that went to zero after OUT still appears', isset($bySku[$skuZeroAfterOut]));
check('zero-after-OUT item report_status = HABIS', isset($bySku[$skuZeroAfterOut]) && $bySku[$skuZeroAfterOut]['report_status'] === 'HABIS');

check('qty 5, min 10 -> WARNING', isset($bySku[$skuBelowMin]) && $bySku[$skuBelowMin]['report_status'] === 'WARNING', $bySku[$skuBelowMin]['report_status'] ?? 'N/A');
check('qty exactly 10, min 10 -> WARNING (owner spec: <=, not <)', isset($bySku[$skuExactMin]) && $bySku[$skuExactMin]['report_status'] === 'WARNING', $bySku[$skuExactMin]['report_status'] ?? 'N/A');
check('qty 11, min 10 -> AMAN', isset($bySku[$skuAboveMin]) && $bySku[$skuAboveMin]['report_status'] === 'AMAN', $bySku[$skuAboveMin]['report_status'] ?? 'N/A');
check('negative migration item -> HABIS', isset($bySku[$skuMigNeg]) && $bySku[$skuMigNeg]['report_status'] === 'HABIS', $bySku[$skuMigNeg]['report_status'] ?? 'N/A');
check('negative migration item keeps its SIGNED qty (-0.5), never clamped to 0', isset($bySku[$skuMigNeg]) && $bySku[$skuMigNeg]['qty_base'] === -0.5, json_encode($bySku[$skuMigNeg]['qty_base'] ?? null));

// STOCK: displayed figure must equal the existing source of truth.
$sourceOfTruth = InventoryService::currentStock($pdo, $itemAboveMin, $whA);
check('Laporan Stok qty_base equals InventoryService::currentStock() (same source of truth, not recomputed)', $bySku[$skuAboveMin]['qty_base'] === $sourceOfTruth['qty_base'], "{$bySku[$skuAboveMin]['qty_base']} vs {$sourceOfTruth['qty_base']}");
check('Laporan Stok value equals InventoryService::currentStock() value', $bySku[$skuAboveMin]['value'] === $sourceOfTruth['value']);

// Pagination still surfaces the full master population (not silently dropped).
$page1 = StockReportService::list($pdo, ['warehouse_id' => $whA, 'category_id' => $catStatus, 'page' => 1, 'per_page' => 2]);
$page2 = StockReportService::list($pdo, ['warehouse_id' => $whA, 'category_id' => $catStatus, 'page' => 2, 'per_page' => 2]);
check('pagination total matches the full 6-item fixture population', $page1['pagination']['total'] === 6, (string) $page1['pagination']['total']);
check('page 1 (per_page=2) returns 2 rows', count($page1['rows']) === 2);
check('page 2 returns the next distinct 2 rows (no duplication/loss across pages)', count($page2['rows']) === 2 && $page1['rows'][0]['sku'] !== $page2['rows'][0]['sku']);

// ============================================================
// D — WAREHOUSE: all-warehouse aggregate vs per-warehouse scope
// ============================================================
echo "\n== D: warehouse aggregation ==\n";

[$itemMulti, $skuMulti] = makeItem($pdo, $kgUnitId, 'V26D-MULTI', 5);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-multi-a'), 'item_id' => $itemMulti, 'warehouse_id' => $whA,
    'input_qty' => 125, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-multi-b'), 'item_id' => $itemMulti, 'warehouse_id' => $whB,
    'input_qty' => 55, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));

$companyWide = StockReportService::list($pdo, ['warehouse_id' => null, 'q' => $skuMulti, 'page' => 1, 'per_page' => 10]);
$scopedA = StockReportService::list($pdo, ['warehouse_id' => $whA, 'q' => $skuMulti, 'page' => 1, 'per_page' => 10]);
$scopedB = StockReportService::list($pdo, ['warehouse_id' => $whB, 'q' => $skuMulti, 'page' => 1, 'per_page' => 10]);
check('SUPERADMIN "Semua Gudang" (warehouse_id=null) shows ONE ROW with the company total (125+55=180)', ($companyWide['rows'][0]['qty_base'] ?? null) === 180.0, json_encode($companyWide['rows'][0]['qty_base'] ?? null));
check('scoped to whA shows only 125 (FIFO never merged across warehouses)', ($scopedA['rows'][0]['qty_base'] ?? null) === 125.0);
check('scoped to whB shows only 55', ($scopedB['rows'][0]['qty_base'] ?? null) === 55.0);

$breakdown = InventoryService::currentStockAllWarehouses($pdo, $itemMulti);
check('per-warehouse breakdown (used by the "Semua Gudang" click-through) totals to 180 too', $breakdown['total']['qty_base'] === 180.0);
$breakdownByWh = [];
foreach ($breakdown['by_warehouse'] as $r) { $breakdownByWh[(int) $r['warehouse_id']] = $r; }
check('breakdown shows 125 for whA', ($breakdownByWh[$whA]['qty_base'] ?? null) === 125.0);
check('breakdown shows 55 for whB', ($breakdownByWh[$whB]['qty_base'] ?? null) === 55.0);

// ============================================================
// V2.6 FINAL PRE-DEPLOY CHECK — a PENDING_CUTOVER warehouse (Karang
// Tengah today) must be excluded from the all-warehouse breakdown at
// the BACKEND, structurally — never relying only on the frontend's own
// is_active filter, and never relying merely on the empirical fact that
// such a warehouse has never been transacted. Simulated here by an
// inactive warehouse that (unrealistically, but as a defensive worst
// case) DOES have a stray batch row — currentStockAllWarehouses() must
// still never surface it.
// ============================================================
echo "\n== D2: inactive/PENDING_CUTOVER warehouse never leaks into the all-warehouse breakdown ==\n";

$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 0)')->execute(['c' => uid('V26D-KT'), 'n' => 'V26D Karang Tengah (PENDING_CUTOVER)']);
$whKT = (int) $pdo->lastInsertId();
// A stray batch — simulating an accidental/erroneous write, not a real
// transaction (Karang Tengah is never transacted through in normal use).
$pdo->prepare(
    'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, created_at)
     VALUES (:item, :wh, 999, 999, 1000, :recv, :now)'
)->execute(['item' => $itemMulti, 'wh' => $whKT, 'recv' => '2026-07-01 00:00:00', 'now' => date('Y-m-d H:i:s')]);

$breakdownAfterStray = InventoryService::currentStockAllWarehouses($pdo, $itemMulti);
$strayByWh = [];
foreach ($breakdownAfterStray['by_warehouse'] as $r) { $strayByWh[(int) $r['warehouse_id']] = $r; }
check('inactive (PENDING_CUTOVER) warehouse never appears in by_warehouse, even with a stray batch row', !isset($strayByWh[$whKT]), json_encode(array_keys($strayByWh)));
check('by_warehouse contains exactly the 2 active warehouses (whA, whB), never a 3rd', count($breakdownAfterStray['by_warehouse']) === 2, json_encode(array_keys($strayByWh)));
check('total qty_base still 180 — the stray inactive-warehouse batch (999) is never folded in', $breakdownAfterStray['total']['qty_base'] === 180.0, (string) $breakdownAfterStray['total']['qty_base']);

// ============================================================
// E — COUNTERS: full filtered dataset, follows filters, never page-limited
// ============================================================
echo "\n== E: Aman/Warning/Habis counters ==\n";

// Isolated to catStatus, whA: expect exactly 6 total — HABIS(never-
// transacted, zero-after-out, migration-negative)=3, WARNING(below,
// exact)=2, AMAN(above)=1.
$counts = StockReportService::statusCounts($pdo, ['warehouse_id' => $whA, 'category_id' => $catStatus]);
check('counters total = 6 (the isolated fixture population)', $counts['total'] === 6, json_encode($counts));
check('counters habis = 3', $counts['habis'] === 3, json_encode($counts));
check('counters warning = 2', $counts['warning'] === 2, json_encode($counts));
check('counters aman = 1', $counts['aman'] === 1, json_encode($counts));

// Counters follow the category filter: an unrelated category (only
// $itemMulti, which is AMAN — qty 125 >= min 5) must show a different,
// non-overlapping breakdown.
$countsMultiCategory = StockReportService::statusCounts($pdo, ['warehouse_id' => $whA, 'q' => $skuMulti]);
check('counters follow the active search filter (q=skuMulti isolates just that 1 AMAN item)', $countsMultiCategory['total'] === 1 && $countsMultiCategory['aman'] === 1, json_encode($countsMultiCategory));

// Counters are computed over the FULL filtered set, never just the
// current (paginated) page — per_page=2 in list() must not shrink the
// counters' total below the true population of 6.
check('counters are NOT limited to the current page (list() per_page=2 above still returned counters.total=6 via a separate full query)', $counts['total'] === 6 && $page1['pagination']['total'] === 6);

// ============================================================
// F — KARTU STOK: every movement type, running balance, historical split
// ============================================================
echo "\n== F: Kartu Stok (movements + running balance + historical split) ==\n";

[$itemLedger, $skuLedger] = makeItem($pdo, $kgUnitId, 'V26D-LEDGER', 5);

// Historical (1-15 Sep 2026, inventory_effect=0) — must be excluded from
// the live running balance entirely, never bridged into it.
$now = date('Y-m-d H:i:s');
$histTxStmt = $pdo->prepare(
    "INSERT INTO inventory_transactions (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
     VALUES (:uuid, 'IN', :d, :now1, :wh, 'POSTED', 1, 0, :cb, :now2)"
);
$histTxStmt->execute(['uuid' => uid('v26d-hist'), 'd' => '2026-09-05 09:00:00', 'now1' => $now, 'now2' => $now, 'wh' => $whA, 'cb' => $adminUserId]);
$histTxId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO inventory_transaction_lines (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
     VALUES (:tx,1,:item,:name,999,:unit,1,999,500,500,499500,:wh)'
)->execute(['tx' => $histTxId, 'item' => $itemLedger, 'name' => 'hist', 'unit' => $kgUnitId, 'wh' => $whA]);

// OPENING 100 (16 Sep, live).
$openTx = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-open'), 'item_id' => $itemLedger, 'warehouse_id' => $whA,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-16 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26d', 'transaction_type' => 'OPENING',
]));
// IN (purchase) +50 -> 150.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26d-in'), 'item_id' => $itemLedger, 'warehouse_id' => $whA,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1100,
    'transaction_date' => '2026-09-17 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26d',
]));
// OUT -20 -> 130.
Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('v26d-out'), 'item_id' => $itemLedger, 'warehouse_id' => $whA,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-09-18 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26d',
]));
// ADJUSTMENT -5 (damage) -> 125.
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('v26d-adj'), 'item_id' => $itemLedger, 'warehouse_id' => $whA,
    'qty_base_delta' => -5, 'adjustment_type' => 'DAMAGE', 'reason' => 'V2.6D test damage',
    'transaction_date' => '2026-09-19 08:00:00', 'created_by' => $adminUserId,
]));
// TRANSFER whA -> whB, 25 units (received) -> whA 100, whB +25.
$transfer = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v26d-trf'), 'from_warehouse_id' => $whA, 'to_warehouse_id' => $whB,
    'ship_date' => '2026-09-20 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26d',
    'lines' => [['item_id' => $itemLedger, 'input_qty' => 25, 'input_unit_id' => $kgUnitId]],
]));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $transfer['transfer_id'], ['created_by' => $adminUserId, 'username' => 'v26d', 'receive_date' => '2026-09-20 10:00:00']));

$ledgerA = InventoryService::ledger($pdo, $itemLedger, $whA);
$liveA = array_values(array_filter($ledgerA, fn ($r) => !$r['is_historical']));
$histA = array_values(array_filter($ledgerA, fn ($r) => $r['is_historical']));

$typesSeen = array_column($liveA, 'transaction_type');
check('live ledger includes OPENING', in_array('OPENING', $typesSeen, true), json_encode($typesSeen));
check('live ledger includes IN', in_array('IN', $typesSeen, true));
check('live ledger includes OUT', in_array('OUT', $typesSeen, true));
check('live ledger includes ADJUSTMENT', in_array('ADJUSTMENT', $typesSeen, true));
check('live ledger includes TRANSFER_OUT', in_array('TRANSFER_OUT', $typesSeen, true));
check('historical section has exactly 1 row (the 1-15 Sep IN)', count($histA) === 1, (string) count($histA));
check('historical row is_historical=true, never mixed into the live array', $histA[0]['is_historical'] === true);

$lastLiveBalance = end($liveA)['balance_qty'];
check('final running balance = 100 (100+50-20-5-25 transfer-out)', $lastLiveBalance === 100.0, (string) $lastLiveBalance);
$currentA = InventoryService::currentStock($pdo, $itemLedger, $whA);
check('final running balance reconciles EXACTLY to current available stock (no artificial forcing)', $lastLiveBalance === $currentA['qty_base'], "{$lastLiveBalance} vs {$currentA['qty_base']}");
check('historical running balance (999) never leaks into the live balance (150 != 999+150)', $liveA[0]['balance_qty'] < 999, (string) $liveA[0]['balance_qty']);

$ledgerB = InventoryService::ledger($pdo, $itemLedger, $whB);
$typesSeenB = array_column($ledgerB, 'transaction_type');
check('receiving warehouse ledger includes TRANSFER_IN', in_array('TRANSFER_IN', $typesSeenB, true), json_encode($typesSeenB));
$currentB = InventoryService::currentStock($pdo, $itemLedger, $whB);
check('receiving warehouse balance (25) reconciles to current stock', $currentB['qty_base'] === 25.0, (string) $currentB['qty_base']);

// Every live row carries a real transaction_id a Trace target can resolve.
$allHaveTxId = true;
foreach ($liveA as $r) { if (empty($r['transaction_id'])) { $allHaveTxId = false; break; } }
check('every live movement row carries a transaction_id (links to the EXISTING TraceDrawer/Trace Center, never a second detail view)', $allHaveTxId);

// ============================================================
// HTTP section — warehouse isolation, Kartu Stok pagination, export,
// read-only enforcement.
// ============================================================
$port = 8900 + random_int(0, 400);
$docRoot = __DIR__ . '/../public';
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
if (!$ready) { fwrite(STDERR, "Server did not become ready\n"); proc_terminate($process); exit(1); }

function httpCall(string $method, string $url, ?array $body, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $headerSize);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'headers' => $headers, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

try {
    $superUser = uid('v26dsuper'); $superPass = 'V26dSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);

    $stockAUser = uid('v26dstockA'); $stockAPass = 'V26dStockAPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockAUser, 'h' => password_hash($stockAPass, PASSWORD_BCRYPT), 'n' => $stockAUser, 'r' => $stockRoleId, 'w' => $whA]);
    $stockAJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $stockAUser, 'password' => $stockAPass], $stockAJar);

    echo "\n== D (HTTP): STOCK cannot override warehouse ==\n";
    $stockAStock = httpCall('GET', "{$base}/reports/stock?q=" . urlencode($skuMulti), null, $stockAJar);
    check('STOCK-A GET /reports/stock (no warehouse_id) is forced to their own warehouse (125, never company 180)', ($stockAStock['body']['data']['rows'][0]['qty_base'] ?? null) == 125, json_encode($stockAStock['body']['data']['rows'][0] ?? null));
    $stockACross = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whB}&q=" . urlencode($skuMulti), null, $stockAJar);
    check('STOCK-A GET /reports/stock?warehouse_id=whB is rejected with 403 (never silently re-scoped to a DIFFERENT warehouse than their own)', $stockACross['status'] === 403, (string) $stockACross['status']);
    $stockACounts = httpCall('GET', "{$base}/reports/stock/status-counts?warehouse_id={$whB}", null, $stockAJar);
    check('STOCK-A GET /reports/stock/status-counts?warehouse_id=whB is rejected with 403', $stockACounts['status'] === 403, (string) $stockACounts['status']);
    $stockACard = httpCall('GET', "{$base}/reports/stock/card?item_id={$itemLedger}&warehouse_id={$whB}", null, $stockAJar);
    check('STOCK-A GET /reports/stock/card?warehouse_id=whB (Kartu Stok for a DIFFERENT warehouse) is rejected with 403', $stockACard['status'] === 403, (string) $stockACard['status']);

    echo "\n== D2 (HTTP): all-warehouse breakdown endpoint never leaks the inactive warehouse ==\n";
    $breakdownHttp = httpCall('GET', "{$base}/inventory/current/{$skuMulti}", null, $superJar);
    check('SUPERADMIN GET /inventory/current/{sku} (the actual endpoint the "Semua Gudang" breakdown calls) returns 200', $breakdownHttp['status'] === 200, (string) $breakdownHttp['status']);
    $byWhHttp = $breakdownHttp['body']['data']['by_warehouse'] ?? [];
    $byWhHttpIds = array_column($byWhHttp, 'warehouse_id');
    check('response includes SCM-equivalent warehouse (whA)', in_array($whA, $byWhHttpIds, true), json_encode($byWhHttpIds));
    check('response includes CIBADAK-equivalent warehouse (whB)', in_array($whB, $byWhHttpIds, true), json_encode($byWhHttpIds));
    check('response NEVER includes the inactive/PENDING_CUTOVER warehouse (whKT), even though it holds a stray batch row', !in_array($whKT, $byWhHttpIds, true), json_encode($byWhHttpIds));
    check('response contains exactly 2 warehouse rows, never a 3rd', count($byWhHttp) === 2, json_encode($byWhHttpIds));

    // STOCK cannot use this endpoint to reach another warehouse either —
    // without warehouse_id, a STOCK user is forced to their OWN scope
    // (never the all-warehouse breakdown, never the inactive warehouse).
    $stockBreakdownHttp = httpCall('GET', "{$base}/inventory/current/{$skuMulti}", null, $stockAJar);
    check('STOCK-A GET /inventory/current/{sku} (no warehouse_id) is forced to their own warehouse, never the all-warehouse breakdown', ($stockBreakdownHttp['body']['data']['qty_base'] ?? null) == 125, json_encode($stockBreakdownHttp['body']['data'] ?? null));
    $stockBreakdownKt = httpCall('GET', "{$base}/inventory/current/{$skuMulti}?warehouse_id={$whKT}", null, $stockAJar);
    check('STOCK-A explicitly requesting the inactive warehouse_id is rejected with 403 (never silently served)', $stockBreakdownKt['status'] === 403, (string) $stockBreakdownKt['status']);

    echo "\n== F (HTTP): Kartu Stok pagination + Trace link ==\n";
    $cardP1 = httpCall('GET', "{$base}/reports/stock/card?item_id={$itemLedger}&warehouse_id={$whA}&per_page=3&page=1", null, $superJar);
    check('Kartu Stok HTTP route returns 200', $cardP1['status'] === 200, (string) $cardP1['status']);
    $cardData = $cardP1['body']['data'] ?? [];
    check('Kartu Stok pagination.per_page honors the requested value (3)', ($cardData['pagination']['per_page'] ?? null) == 3, json_encode($cardData['pagination'] ?? null));
    check('Kartu Stok movements never exceed the requested per_page', count($cardData['movements'] ?? []) <= 3);
    check('Kartu Stok current.qty_base matches the PHP-level reconciliation above (100)', ($cardData['current']['qty_base'] ?? null) == 100, json_encode($cardData['current'] ?? null));
    check('Kartu Stok historical section is present and separate from movements', isset($cardData['historical']) && count($cardData['historical']) === 1);

    $firstMovementTxId = $cardData['movements'][0]['transaction_id'] ?? null;
    if ($firstMovementTxId) {
        $traceResp = httpCall('GET', "{$base}/trace/transaction/{$firstMovementTxId}", null, $superJar);
        check('a Kartu Stok movement row\'s transaction_id resolves via the EXISTING /trace/transaction/{id} (TraceDrawer target), never a new detail endpoint', $traceResp['status'] === 200, (string) $traceResp['status']);
    } else {
        check('a Kartu Stok movement row carries a transaction_id to trace', false, 'no movements returned');
    }

    echo "\n== G (HTTP): Laporan Stok CSV export (view=report) ==\n";
    $exportReport = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whA}&category_id={$catStatus}&view=report&format=csv", null, $superJar);
    check('Laporan Stok export returns 200 text/csv', $exportReport['status'] === 200 && str_contains($exportReport['headers'], 'Content-Type: text/csv'), (string) $exportReport['status']);
    $csvBody = str_replace("\xEF\xBB\xBF", '', $exportReport['raw']);
    $csvLines = array_values(array_filter(explode("\n", $csvBody), fn ($l) => trim($l) !== ''));
    check('export header is exactly the 8 owner-specified columns', ($csvLines[0] ?? '') === 'SKU,"Nama Produk",Kategori,Satuan,"Stok Tersedia","Stok Minimal",Status,"Nilai Stok"', $csvLines[0] ?? 'N/A');
    check('export includes the zero-stock (never-transacted) item', str_contains($csvBody, $skuNeverBatched));
    check('export includes a HABIS status cell', str_contains($csvBody, 'HABIS'));
    check('export includes a WARNING status cell', str_contains($csvBody, 'WARNING'));
    check('export includes an AMAN status cell', str_contains($csvBody, 'AMAN'));

    // Old "Stok Barang" export (no view=report) stays byte-identical.
    $exportOld = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whA}&format=csv", null, $superJar);
    $oldBody = str_replace("\xEF\xBB\xBF", '', $exportOld['raw']);
    check('the ORIGINAL (Stok Barang) export shape is untouched — still starts with the old 14-column header', str_starts_with($oldBody, 'SKU,"Nama Barang",Kategori,Satuan,Qty,Nilai'), substr($oldBody, 0, 60));

    // Warehouse scope respected on export for a STOCK user.
    $exportStockA = httpCall('GET', "{$base}/reports/stock?view=report&format=csv", null, $stockAJar);
    check('STOCK-A export (no warehouse_id) never leaks whB-only data (multi-warehouse SKU shows 125, not 180)', str_contains($exportStockA['raw'], '125') , substr($exportStockA['raw'], 0, 100));

    // CSV formula-injection protection still applies to this NEW export path.
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, category_id, status) VALUES (:sku,:name,:unit,0,:cat,:status)')
        ->execute(['sku' => uid('V26D-INJ'), 'name' => '=2+2', 'unit' => $kgUnitId, 'cat' => $catStatus, 'status' => 'ACTIVE']);
    $injItemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $injItemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    $exportInj = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whA}&category_id={$catStatus}&view=report&format=csv", null, $superJar);
    check('CSV formula-injection protection still neutralizes an item name starting with "=" in this new export (never regressed)', str_contains($exportInj['raw'], "'=2+2") && !preg_match('/[,\n]=2\+2/', $exportInj['raw']), substr($exportInj['raw'], 0, 400));
    $injApi = httpCall('GET', "{$base}/reports/stock?warehouse_id={$whA}&category_id={$catStatus}&q=" . urlencode('=2+2'), null, $superJar);
    $injApiName = $injApi['body']['data']['rows'][0]['name'] ?? null;
    check('the JSON API still returns the raw, unmutated "=2+2" name (sanitization is export-layer only)', $injApiName === '=2+2', json_encode($injApiName));

    echo "\n== H: read-only enforcement ==\n";
    $txBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $adjBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
    $batchValueBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches')->fetchColumn();

    httpCall('GET', "{$base}/reports/stock?warehouse_id={$whA}", null, $superJar);
    httpCall('GET', "{$base}/reports/stock/status-counts?warehouse_id={$whA}", null, $superJar);
    httpCall('GET', "{$base}/reports/stock/card?item_id={$itemLedger}&warehouse_id={$whA}", null, $superJar);
    httpCall('GET', "{$base}/reports/stock?view=report&format=csv&warehouse_id={$whA}", null, $superJar);
    httpCall('GET', "{$base}/reports/stock/card?item_id={$itemLedger}&warehouse_id={$whA}", null, $stockAJar);

    $txAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $adjAfter = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
    $batchValueAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches')->fetchColumn();
    check('V2.6D routes never change inventory_transactions count', $txBefore === $txAfter, "{$txBefore} vs {$txAfter}");
    check('V2.6D routes never change stock_adjustments count', $adjBefore === $adjAfter, "{$adjBefore} vs {$adjAfter}");
    check('V2.6D routes never change total inventory_batches value', $batchValueBefore === $batchValueAfter, "{$batchValueBefore} vs {$batchValueAfter}");

    @unlink($superJar);
    @unlink($stockAJar);
} finally {
    proc_terminate($process);
    proc_close($process);
}

// ============================================================
echo "\n== SUMMARY ==\n";
$total = count($results);
$passed = count(array_filter($results));
echo "{$passed} / {$total} PASSED\n";
if ($passed !== $total) {
    exit(1);
}
