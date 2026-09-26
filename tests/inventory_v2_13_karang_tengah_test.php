<?php
declare(strict_types=1);

/**
 * PHASE V2.13 — Karang Tengah warehouse onboarding (development phase
 * only — see database/migrations/2026_09_26_v2_13_karang_tengah_warehouse.sql).
 *
 * Karang Tengah is modeled exactly like Cibadak: warehouse_type = TRANSIT,
 * added INACTIVE, no opening inventory, no FIFO batches, no historical
 * transactions. This file proves:
 *   A. the migration creates exactly the right warehouse row and nothing else
 *   B. zero company inventory qty/value effect from the migration; an
 *      inactive warehouse cannot mutate stock via any entry point
 *   C. Cibadak's own behavior is completely unchanged, and the SAME
 *      generic service paths (no Karang-Tengah-specific code) support it
 *   D. once flipped active (TEST DB fixture only), a GUDANG_BESAR ->
 *      KARANG_TENGAH transfer follows the identical lifecycle as a
 *      GUDANG_BESAR -> CIBADAK transfer, FIFO value conserved
 *   E. native Stock IN/OUT against an active Karang Tengah fixture behaves
 *      identically to any other warehouse
 *   F. the Daily IN/OUT report is warehouse-generic and picks up Karang
 *      Tengah automatically once active, with correct per-day and
 *      All-Warehouse totals
 *   G. Stock Opname supports Karang Tengah via the same flow as Cibadak
 *   H. no permission regression
 *   I. this file itself is additive coverage — the existing full suite
 *      (run separately by tests/run_mysql_tests.sh) proves SCM/Cibadak/
 *      distribution/dual-count-opname/FIFO/HPP are all unaffected
 *
 * Usage: php tests/inventory_v2_13_karang_tengah_test.php
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
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/ProductionService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\TransferService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockAdjustmentService;
use App\Services\ProductionService;
use App\Services\AuthService;
use App\Services\InventoryMovementReportService;
use App\Services\ValidationException;
use App\Services\WarehouseInactiveException;

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

function applySqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, fn (string $l) => !str_starts_with(ltrim($l), '--'));
    $cleaned = implode("\n", $lines);
    foreach (explode(';', $cleaned) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('GUDANG_BESAR', 'Gudang Besar', 'MAIN', 1)")->execute();
$gudangBesarId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('CIBADAK', 'Cibadak', 'TRANSIT', 1)")->execute();
$cibadakId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by, string $date = '2026-08-01 08:00:00'): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v213-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $by, 'username' => 'v213', 'transaction_type' => 'OPENING',
    ]));
}
function postOut(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, int $by, string $date = '2026-08-05 08:00:00'): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => uid('v213-out'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId,
        'transaction_date' => $date, 'created_by' => $by, 'username' => 'v213', 'transaction_type' => 'OUT',
    ]));
}

$adminUserId = makeUser($pdo, 'v213admin', $superRoleId, null);

function warehouseQtyValue(PDO $pdo, int $whId): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches WHERE warehouse_id = :wh');
    $stmt->execute(['wh' => $whId]);
    return $stmt->fetch(PDO::FETCH_NUM);
}

// ============================================================
// A0. NON-ZERO INVENTORY FIXTURE — deterministic real FIFO stock in
// GUDANG_BESAR and CIBADAK, posted BEFORE the migration runs, so the
// zero-effect invariant below is proven against real, non-trivial
// numbers rather than an empty database.
// ============================================================
echo "== A0. Non-zero inventory fixture (pre-migration) ==\n";
$itemA0a = makeItem($pdo, $kgUnitId, 'V213-A0A');
$itemA0b = makeItem($pdo, $kgUnitId, 'V213-A0B');
$itemA0c = makeItem($pdo, $kgUnitId, 'V213-A0C');
postIn($pdo, $itemA0a, $gudangBesarId, $kgUnitId, 1000, 12000, $adminUserId, '2026-07-01 08:00:00');
postIn($pdo, $itemA0b, $gudangBesarId, $kgUnitId, 250.5, 8500, $adminUserId, '2026-07-02 08:00:00');
postOut($pdo, $itemA0a, $gudangBesarId, $kgUnitId, 150, $adminUserId, '2026-07-10 08:00:00');
postIn($pdo, $itemA0a, $cibadakId, $kgUnitId, 300, 12000, $adminUserId, '2026-07-03 08:00:00');
postIn($pdo, $itemA0c, $cibadakId, $kgUnitId, 75.25, 20000, $adminUserId, '2026-07-04 08:00:00');
postOut($pdo, $itemA0c, $cibadakId, $kgUnitId, 25, $adminUserId, '2026-07-11 08:00:00');
check('fixture setup: company batch count > 0 before migration', (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn() > 0);

// ============================================================
// A. Warehouse creation — apply the actual migration, verify the row,
// and verify the FULL non-zero-inventory invariant matrix.
// ============================================================
echo "\n== A. Warehouse creation (real migration file) + non-zero inventory invariant ==\n";
$migrationFile = __DIR__ . '/../database/migrations/2026_09_26_v2_13_karang_tengah_warehouse.sql';
check('migration file exists', is_file($migrationFile));

$companyBefore = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
$gbBefore = warehouseQtyValue($pdo, $gudangBesarId);
$cibBefore = warehouseQtyValue($pdo, $cibadakId);

echo "  BEFORE — company: qty={$companyBefore[0]} value={$companyBefore[1]} batches={$companyBefore[2]}\n";
echo "  BEFORE — GUDANG_BESAR: qty={$gbBefore[0]} value={$gbBefore[1]} batches={$gbBefore[2]}\n";
echo "  BEFORE — CIBADAK: qty={$cibBefore[0]} value={$cibBefore[1]} batches={$cibBefore[2]}\n";

applySqlFile($pdo, $migrationFile);

$kt = $pdo->query("SELECT * FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetch();
check('KARANG_TENGAH row exists after migration', $kt !== false);
check('name = Gudang Karang Tengah', $kt && $kt['name'] === 'Gudang Karang Tengah', (string) ($kt['name'] ?? 'MISSING'));
check('warehouse_type = TRANSIT', $kt && $kt['warehouse_type'] === 'TRANSIT', (string) ($kt['warehouse_type'] ?? 'MISSING'));
check('is_active = 0 (INACTIVE)', $kt && (int) $kt['is_active'] === 0, (string) ($kt['is_active'] ?? 'MISSING'));
$karangTengahId = (int) $kt['id'];

$companyAfter = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
$gbAfter = warehouseQtyValue($pdo, $gudangBesarId);
$cibAfter = warehouseQtyValue($pdo, $cibadakId);
$ktAfter = warehouseQtyValue($pdo, $karangTengahId);

echo "  AFTER  — company: qty={$companyAfter[0]} value={$companyAfter[1]} batches={$companyAfter[2]}\n";
echo "  AFTER  — GUDANG_BESAR: qty={$gbAfter[0]} value={$gbAfter[1]} batches={$gbAfter[2]}\n";
echo "  AFTER  — CIBADAK: qty={$cibAfter[0]} value={$cibAfter[1]} batches={$cibAfter[2]}\n";
echo "  AFTER  — KARANG_TENGAH: qty={$ktAfter[0]} value={$ktAfter[1]} batches={$ktAfter[2]}\n";

check('company total qty UNCHANGED', (float) $companyBefore[0] === (float) $companyAfter[0], "{$companyBefore[0]} -> {$companyAfter[0]}");
check('company total value UNCHANGED', (float) $companyBefore[1] === (float) $companyAfter[1], "{$companyBefore[1]} -> {$companyAfter[1]}");
check('company total batch count UNCHANGED', (int) $companyBefore[2] === (int) $companyAfter[2], "{$companyBefore[2]} -> {$companyAfter[2]}");
check('GUDANG_BESAR qty UNCHANGED', (float) $gbBefore[0] === (float) $gbAfter[0], "{$gbBefore[0]} -> {$gbAfter[0]}");
check('GUDANG_BESAR value UNCHANGED', (float) $gbBefore[1] === (float) $gbAfter[1], "{$gbBefore[1]} -> {$gbAfter[1]}");
check('GUDANG_BESAR batch count UNCHANGED', (int) $gbBefore[2] === (int) $gbAfter[2], "{$gbBefore[2]} -> {$gbAfter[2]}");
check('CIBADAK qty UNCHANGED', (float) $cibBefore[0] === (float) $cibAfter[0], "{$cibBefore[0]} -> {$cibAfter[0]}");
check('CIBADAK value UNCHANGED', (float) $cibBefore[1] === (float) $cibAfter[1], "{$cibBefore[1]} -> {$cibAfter[1]}");
check('CIBADAK batch count UNCHANGED', (int) $cibBefore[2] === (int) $cibAfter[2], "{$cibBefore[2]} -> {$cibAfter[2]}");
check('KARANG_TENGAH qty = 0', (float) $ktAfter[0] === 0.0, (string) $ktAfter[0]);
check('KARANG_TENGAH value = 0', (float) $ktAfter[1] === 0.0, (string) $ktAfter[1]);
check('KARANG_TENGAH batches = 0', (int) $ktAfter[2] === 0, (string) $ktAfter[2]);

// re-run migration: idempotency (must not touch the non-zero data either)
applySqlFile($pdo, $migrationFile);
$companyAfterRerun = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0), COUNT(*) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
check('re-running migration a second time still leaves company qty/value/batches unchanged', (float) $companyAfter[0] === (float) $companyAfterRerun[0] && (float) $companyAfter[1] === (float) $companyAfterRerun[1] && (int) $companyAfter[2] === (int) $companyAfterRerun[2]);
$ktCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetchColumn();
check('re-running migration creates no duplicate row (idempotent)', $ktCount === 1, "count={$ktCount}");

// ============================================================
// B. Safety — zero inventory effect, inactive warehouse cannot mutate stock
// ============================================================
echo "\n== B. Safety ==\n";
// The full non-zero-inventory invariant matrix (company/GUDANG_BESAR/
// CIBADAK/KARANG_TENGAH qty, value, batch count — before vs after the
// migration, and again after a second idempotent re-run) is already
// proven in Section A above against real, non-trivial FIFO fixtures.
// Section B focuses on the OTHER half of "safety": that an inactive
// warehouse cannot mutate stock through any entry point.

$itemB1 = makeItem($pdo, $kgUnitId, 'V213-B1');
$errIn = expectException(fn () => postIn($pdo, $itemB1, $karangTengahId, $kgUnitId, 10, 1000, $adminUserId), WarehouseInactiveException::class);
check('FifoService::postIn REJECTS inactive Karang Tengah', $errIn === WarehouseInactiveException::class, (string) $errIn);

// need at least one unit of stock somewhere to attempt an OUT against
postIn($pdo, $itemB1, $gudangBesarId, $kgUnitId, 100, 1000, $adminUserId);
$errOut = expectException(fn () => postOut($pdo, $itemB1, $karangTengahId, $kgUnitId, 5, $adminUserId), WarehouseInactiveException::class);
check('FifoService::postOut REJECTS inactive Karang Tengah', $errOut === WarehouseInactiveException::class, (string) $errOut);

$errTransferOut = expectException(fn () => Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v213-xfer'), 'from_warehouse_id' => $karangTengahId, 'to_warehouse_id' => $gudangBesarId,
    'ship_date' => '2026-08-06 08:00:00', 'created_by' => $adminUserId,
    'lines' => [['item_id' => $itemB1, 'input_qty' => 1, 'input_unit_id' => $kgUnitId]],
])), WarehouseInactiveException::class);
check('TransferService::create REJECTS inactive Karang Tengah as SOURCE', $errTransferOut === WarehouseInactiveException::class, (string) $errTransferOut);

$errTransferIn = expectException(fn () => Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v213-xfer'), 'from_warehouse_id' => $gudangBesarId, 'to_warehouse_id' => $karangTengahId,
    'ship_date' => '2026-08-06 08:00:00', 'created_by' => $adminUserId,
    'lines' => [['item_id' => $itemB1, 'input_qty' => 1, 'input_unit_id' => $kgUnitId]],
])), WarehouseInactiveException::class);
check('TransferService::create REJECTS inactive Karang Tengah as DESTINATION (fails fast, before any stock leaves the source)', $errTransferIn === WarehouseInactiveException::class, (string) $errTransferIn);

$errOpname = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $karangTengahId, $adminUserId)), WarehouseInactiveException::class);
check('StockOpnameService::start REJECTS inactive Karang Tengah', $errOpname === WarehouseInactiveException::class, (string) $errOpname);

// ============================================================
// C. Generic Cibadak parity — Cibadak behavior unaffected, same service
// paths, no Karang-Tengah-specific code
// ============================================================
echo "\n== C. Generic Cibadak parity ==\n";
$itemC1 = makeItem($pdo, $kgUnitId, 'V213-C1');
postIn($pdo, $itemC1, $gudangBesarId, $kgUnitId, 50, 2000, $adminUserId);
$cibXferResult = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v213-cib-xfer'), 'from_warehouse_id' => $gudangBesarId, 'to_warehouse_id' => $cibadakId,
    'ship_date' => '2026-08-06 08:00:00', 'created_by' => $adminUserId,
    'lines' => [['item_id' => $itemC1, 'input_qty' => 20, 'input_unit_id' => $kgUnitId]],
]));
check('Cibadak transfer (still active) succeeds exactly as before — no regression from the new guard', $cibXferResult['success'] === true, json_encode($cibXferResult));
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $cibXferResult['transfer_id'], ['created_by' => $adminUserId]));
$cibStock = \App\Services\InventoryService::currentStock($pdo, $itemC1, $cibadakId);
check('Cibadak received the transferred qty (20)', abs($cibStock['qty_base'] - 20.0) < 0.0001, (string) $cibStock['qty_base']);

// Prove no Karang-Tengah-specific BRANCH exists: source-grep for the
// quoted code literal 'KARANG_TENGAH' (what an `if (code === 'KARANG_TENGAH')`
// hard-coded special case would look like) in any of the touched runtime
// services used by this exact flow. A prose comment merely naming the
// warehouse (e.g. explaining WHY a generic guard matters here) is fine and
// expected — this codebase already comments this way for SCM/Cibadak too;
// only a quoted string literal actually driving a conditional is the
// hard-coding this test guards against.
$servicesToScan = ['FifoService.php', 'TransferService.php', 'StockOpnameService.php', 'StockAdjustmentService.php', 'InventoryMovementReportService.php', 'InventoryService.php'];
$foundHardcode = [];
foreach ($servicesToScan as $svc) {
    $content = file_get_contents(__DIR__ . '/../services/' . $svc);
    if (str_contains($content, "'KARANG_TENGAH'") || str_contains($content, '"KARANG_TENGAH"')) {
        $foundHardcode[] = $svc;
    }
}
check('no Karang-Tengah-specific quoted code literal in any core mutating service', empty($foundHardcode), implode(',', $foundHardcode));

// ============================================================
// D/E/F/G — flip Karang Tengah ACTIVE (TEST DB fixture only) and prove
// full parity with Cibadak's own lifecycle.
// ============================================================
echo "\n== D/E/F/G: active Karang Tengah fixture (TEST DB only) ==\n";
$pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = :id')->execute(['id' => $karangTengahId]);
check('fixture flipped ACTIVE for this test only', (int) $pdo->query("SELECT is_active FROM warehouses WHERE id={$karangTengahId}")->fetchColumn() === 1);

// D. Transfer GUDANG_BESAR -> KARANG_TENGAH, same lifecycle as Cibadak
$itemD1 = makeItem($pdo, $kgUnitId, 'V213-D1');
postIn($pdo, $itemD1, $gudangBesarId, $kgUnitId, 100, 5000, $adminUserId);
$companyValueBeforeXfer = $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches WHERE item_id=' . $itemD1)->fetchColumn();

$ktXfer = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v213-kt-xfer'), 'from_warehouse_id' => $gudangBesarId, 'to_warehouse_id' => $karangTengahId,
    'ship_date' => '2026-08-07 08:00:00', 'created_by' => $adminUserId,
    'lines' => [['item_id' => $itemD1, 'input_qty' => 30, 'input_unit_id' => $kgUnitId]],
]));
check('GUDANG_BESAR -> KARANG_TENGAH transfer create() succeeds (same lifecycle as Cibadak)', $ktXfer['success'] === true, json_encode($ktXfer));

$gbStockMidTransit = \App\Services\InventoryService::currentStock($pdo, $itemD1, $gudangBesarId);
check('source (Gudang Besar) reduced immediately at create() — same as Cibadak transfers', abs($gbStockMidTransit['qty_base'] - 70.0) < 0.0001, (string) $gbStockMidTransit['qty_base']);

Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $ktXfer['transfer_id'], ['created_by' => $adminUserId]));
$ktStock = \App\Services\InventoryService::currentStock($pdo, $itemD1, $karangTengahId);
check('destination (Karang Tengah) received correct quantity/base unit (30)', abs($ktStock['qty_base'] - 30.0) < 0.0001, (string) $ktStock['qty_base']);
check('destination received correct FIFO value (30 * 5000 = 150000)', abs($ktStock['value'] - 150000.0) < 0.01, (string) $ktStock['value']);

$companyValueAfterXfer = $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches WHERE item_id=' . $itemD1)->fetchColumn();
check('company inventory value conserved across the transfer (500,000 total either side)', abs((float) $companyValueBeforeXfer - (float) $companyValueAfterXfer) < 0.01, "{$companyValueBeforeXfer} vs {$companyValueAfterXfer}");

// E. Native Stock IN/OUT against active Karang Tengah
echo "\n== E. Native Stock IN/OUT ==\n";
$itemE1 = makeItem($pdo, $kgUnitId, 'V213-E1');
$inResult = postIn($pdo, $itemE1, $karangTengahId, $kgUnitId, 40, 1500, $adminUserId, '2026-09-20 09:00:00');
check('native IN posts to Karang Tengah', $inResult['success'] === true, json_encode($inResult));
$outResult = postOut($pdo, $itemE1, $karangTengahId, $kgUnitId, 15, $adminUserId, '2026-09-20 14:00:00');
check('native OUT consumes FIFO at Karang Tengah', $outResult['success'] === true, json_encode($outResult));
$e1Stock = \App\Services\InventoryService::currentStock($pdo, $itemE1, $karangTengahId);
check('Karang Tengah balance correct after IN 40 / OUT 15 = 25', abs($e1Stock['qty_base'] - 25.0) < 0.0001, (string) $e1Stock['qty_base']);

$historyStmt = $pdo->prepare(
    "SELECT t.warehouse_id FROM inventory_transactions t
     JOIN inventory_transaction_lines l ON l.transaction_id = t.id
     WHERE l.item_id = :item AND t.warehouse_id = :wh"
);
$historyStmt->execute(['item' => $itemE1, 'wh' => $karangTengahId]);
$historyRows = $historyStmt->fetchAll();
check('transaction history records warehouse_id correctly for Karang Tengah', count($historyRows) >= 2, (string) count($historyRows));

// F. Daily IN/OUT report — warehouse-generic
echo "\n== F. Daily report ==\n";
$itemF1 = makeItem($pdo, $kgUnitId, 'V213-F1');
postIn($pdo, $itemF1, $karangTengahId, $kgUnitId, 100, 1000, $adminUserId, '2026-09-01 08:00:00'); // Day 1 IN
postOut($pdo, $itemF1, $karangTengahId, $kgUnitId, 10, $adminUserId, '2026-09-01 15:00:00');       // Day 1 OUT
postIn($pdo, $itemF1, $karangTengahId, $kgUnitId, 50, 1000, $adminUserId, '2026-09-02 08:00:00');  // Day 2 IN
postOut($pdo, $itemF1, $karangTengahId, $kgUnitId, 20, $adminUserId, '2026-09-02 15:00:00');       // Day 2 OUT

$daily = InventoryMovementReportService::dailyMovement($pdo, '2026-09-01', '2026-09-02', $karangTengahId);
$byDate = [];
foreach ($daily['rows'] as $r) { $byDate[$r['date']] = $r; }
check('report filters warehouse=KARANG_TENGAH and returns both days', isset($byDate['2026-09-01']) && isset($byDate['2026-09-02']), json_encode(array_keys($byDate)));
// barang_masuk/barang_keluar are VALUE (Rupiah), not raw quantity —
// itemF1 posts at unit cost 1000, so IN 100/OUT 10 -> value 100,000/10,000.
check('Day 1 totals correct (IN value=100,000, OUT value=10,000)', abs($byDate['2026-09-01']['barang_masuk'] - 100000.0) < 0.01 && abs($byDate['2026-09-01']['barang_keluar'] - 10000.0) < 0.01, json_encode($byDate['2026-09-01'] ?? null));
check('Day 2 totals correct (IN value=50,000, OUT value=20,000)', abs($byDate['2026-09-02']['barang_masuk'] - 50000.0) < 0.01 && abs($byDate['2026-09-02']['barang_keluar'] - 20000.0) < 0.01, json_encode($byDate['2026-09-02'] ?? null));

$dailyAll = InventoryMovementReportService::dailyMovement($pdo, '2026-09-01', '2026-09-02', null);
$byDateAll = [];
foreach ($dailyAll['rows'] as $r) { $byDateAll[$r['date']] = $r; }
check('All Warehouse totals include Karang Tengah after activation (Day 1 IN value >= 100,000)', isset($byDateAll['2026-09-01']) && $byDateAll['2026-09-01']['barang_masuk'] >= 100000.0, json_encode($byDateAll['2026-09-01'] ?? null));

// G. Stock Opname supports Karang Tengah using the same flow as Cibadak
echo "\n== G. Stock Opname ==\n";
$itemG1 = makeItem($pdo, $kgUnitId, 'V213-G1');
postIn($pdo, $itemG1, $karangTengahId, $kgUnitId, 60, 1000, $adminUserId);
$ktSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $karangTengahId, $adminUserId, [$itemG1]));
$ktSession = StockOpnameService::get($pdo, $ktSessionId);
check('Stock Opname starts for Karang Tengah, same flow/shape as Cibadak', $ktSession['status'] === 'OPEN' && (int) $ktSession['warehouse_id'] === $karangTengahId, json_encode($ktSession['warehouse_id']));
check('Karang Tengah opname session has a real session_number (dual-count, same as any other warehouse)', is_string($ktSession['session_number']) && str_starts_with($ktSession['session_number'], 'SO-'));
Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $ktSessionId, 'V2.13 test cleanup', $adminUserId));

// ============================================================
// H. Permissions — no regression
// ============================================================
echo "\n== H. Permissions ==\n";
$stockUserOtherWh = makeUser($pdo, 'v213stockcib', $stockRoleId, $cibadakId);
check('a STOCK user scoped to Cibadak has no unintended Karang Tengah access via AuthService::assertWarehouseScope', expectException(function () use ($stockUserOtherWh, $karangTengahId) {
    $user = ['role_code' => 'STOCK', 'warehouse_id' => (string) $GLOBALS['cibadakId']];
    AuthService::assertWarehouseScope($user, $karangTengahId);
}, ValidationException::class) === ValidationException::class);

// ============================================================
// I. Inactive-warehouse guard matrix — remaining entry points not yet
// exercised above (production consume/output, adjustment, opname
// posting, and a transfer receive()-time defense-in-depth check for a
// warehouse deactivated AFTER a transfer was already created).
// ============================================================
echo "\n== I. Inactive-warehouse guard matrix (remaining entry points) ==\n";

// Deactivate Karang Tengah again for this section's own from-scratch
// proofs (it was flipped active for D-H above); use a second, disposable
// TRANSIT-style fixture warehouse so this doesn't disturb the state the
// remaining sections (J below) still rely on being active.
$pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = :id')->execute(['id' => $karangTengahId]); // keep KT active; matrix uses KT directly, this is a no-op safety re-assert

$itemI1 = makeItem($pdo, $kgUnitId, 'V213-I1-PROD-RAW');
$itemI2 = makeItem($pdo, $kgUnitId, 'V213-I2-PROD-OUT');
$pdo->prepare('UPDATE warehouses SET is_active = 0 WHERE id = :id')->execute(['id' => $karangTengahId]);

// I.1 — production consume + output (same warehouse_id for both sides in
// this codebase's ProductionService::create() — a single atomic call
// posts the raw-material OUT first, so an inactive warehouse is rejected
// before the output IN could ever be attempted; there is no code path in
// this application where "consume" could succeed while "output" fails on
// the active-warehouse check alone, since both reads happen against the
// exact same $p['warehouse_id']).
$errProd = expectException(fn () => Database::transaction(fn (PDO $tx) => ProductionService::create($tx, [
    'production_uuid' => uid('v213-prod'), 'warehouse_id' => $karangTengahId, 'production_date' => '2026-09-15 08:00:00',
    'created_by' => $adminUserId,
    'inputs' => [['item_id' => $itemI1, 'input_qty' => 1, 'input_unit_id' => $kgUnitId]],
    'output' => ['item_id' => $itemI2, 'output_qty' => 1, 'output_unit_id' => $kgUnitId],
])), WarehouseInactiveException::class);
check('ProductionService::create REJECTS inactive Karang Tengah (covers BOTH consume and output — same warehouse_id, same guard, raw-material OUT checked first)', $errProd === WarehouseInactiveException::class, (string) $errProd);

// I.2 — adjustment (StockAdjustmentService::post(), also what
// StockOpnameService::post() calls internally for an opname variance —
// identical code path, so this one test proves both matrix rows).
$errAdj = expectException(fn () => Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('v213-adj'), 'item_id' => $itemI1, 'warehouse_id' => $karangTengahId,
    'qty_base_delta' => 5.0, 'adjustment_type' => 'CORRECTION', 'reason' => 'V2.13 guard matrix proof',
    'created_by' => $adminUserId, 'override_cost_base' => 1000,
])), WarehouseInactiveException::class);
check('StockAdjustmentService::post REJECTS inactive Karang Tengah (identical code path StockOpnameService::post() uses for an opname variance)', $errAdj === WarehouseInactiveException::class, (string) $errAdj);

// I.3 — transfer receive() defense-in-depth: a transfer whose DESTINATION
// warehouse was ACTIVE at create() time but has since been deactivated
// before receive() is called. TransferService::create()'s fail-fast check
// (Section B) cannot catch this — the warehouse was active when create()
// ran — so this specifically proves the independent FifoService::postIn()
// guard inside receive() is real defense-in-depth, not just cosmetic.
$itemI3 = makeItem($pdo, $kgUnitId, 'V213-I3-XFER');
postIn($pdo, $itemI3, $gudangBesarId, $kgUnitId, 50, 3000, $adminUserId, '2026-09-16 08:00:00');
$pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = :id')->execute(['id' => $karangTengahId]);
$xferI3 = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('v213-i3-xfer'), 'from_warehouse_id' => $gudangBesarId, 'to_warehouse_id' => $karangTengahId,
    'ship_date' => '2026-09-16 09:00:00', 'created_by' => $adminUserId,
    'lines' => [['item_id' => $itemI3, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]));
check('transfer create() succeeds while Karang Tengah is still active', $xferI3['success'] === true, json_encode($xferI3));
$pdo->prepare('UPDATE warehouses SET is_active = 0 WHERE id = :id')->execute(['id' => $karangTengahId]); // deactivated AFTER create(), BEFORE receive()
$errReceive = expectException(fn () => Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $xferI3['transfer_id'], ['created_by' => $adminUserId])), WarehouseInactiveException::class);
check('TransferService::receive() independently REJECTS a destination deactivated after create() but before receive() (defense-in-depth, not just the create()-time fail-fast)', $errReceive === WarehouseInactiveException::class, (string) $errReceive);
// restore active + clean up the stuck-in-transit transfer for the rest of this file
$pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = :id')->execute(['id' => $karangTengahId]);
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $xferI3['transfer_id'], ['created_by' => $adminUserId]));

// ============================================================
// J. HTTP-level proof — the SAME service/API path the real UI uses
// (php -S + curl, not a direct PHP service call), against the
// already-active Karang Tengah fixture from Section D onward.
// ============================================================
echo "\n== J. HTTP-level proof (real API path) ==\n";
$port = 8900 + random_int(1600, 1999);
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

function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

try {
    $httpSuperUser = uid('v213http'); $httpSuperPass = 'V213HttpPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $httpSuperUser, 'h' => password_hash($httpSuperPass, PASSWORD_BCRYPT), 'n' => $httpSuperUser, 'r' => $superRoleId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $httpSuperUser, 'password' => $httpSuperPass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';
    check('HTTP login succeeds', $login['status'] === 200, json_encode($login['body']));

    // J.0 — frontend warehouse selector proof: GET /warehouses (the
    // operational-dropdown source) includes Karang Tengah NOW (active),
    // confirming the same generic endpoint the UI calls would show it.
    $whList = httpCall('GET', "{$base}/warehouses", null, $jar, $csrf);
    $whCodes = array_column($whList['body']['data'] ?? [], 'code');
    check('GET /warehouses (operational dropdown source) includes KARANG_TENGAH while active', in_array('KARANG_TENGAH', $whCodes, true), json_encode($whCodes));

    // J.1 — item resolvable via the same GET /items the UI's ItemSelector
    // loads from, for a brand-new SKU that has NEVER had a batch in
    // Karang Tengah (proves no catalog/pre-seeding is needed).
    $itemJ1 = makeItem($pdo, $kgUnitId, 'V213-J1-FIRSTIN');
    $itemsResp = httpCall('GET', "{$base}/items", null, $jar, $csrf);
    $itemIds = array_column($itemsResp['body']['data'] ?? [], 'id');
    check('item is resolvable via GET /items (same source ItemSelector reads) with no warehouse-specific catalog entry needed', in_array($itemJ1, $itemIds, true));

    // J.2 — FIRST-EVER Stock IN to Karang Tengah for this item, via the
    // exact route the UI's Stock IN screen posts to.
    $firstIn = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('v213-http-in'), 'item_id' => $itemJ1, 'warehouse_id' => $karangTengahId,
        'input_qty' => 80, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2500,
        'transaction_date' => '2026-09-25 08:00:00',
    ], $jar, $csrf);
    check('HTTP POST /transactions/in succeeds for a brand-new item with zero prior Karang Tengah batches', ($firstIn['body']['success'] ?? false) === true, json_encode($firstIn['body']));

    $afterFirstIn = httpCall('GET', "{$base}/inventory/current?item_id={$itemJ1}&warehouse_id={$karangTengahId}", null, $jar, $csrf);
    check('first-ever batch qty correct (80)', abs(($afterFirstIn['body']['data']['qty_base'] ?? -1) - 80.0) < 0.0001, json_encode($afterFirstIn['body']));
    check('first-ever batch value correct (80 * 2500 = 200,000)', abs(($afterFirstIn['body']['data']['value'] ?? -1) - 200000.0) < 0.01, json_encode($afterFirstIn['body']));

    $historyJ1 = httpCall('GET', "{$base}/reports/transactions?item_id={$itemJ1}&warehouse_id={$karangTengahId}", null, $jar, $csrf);
    check('transaction history (GET /reports/transactions) records the IN correctly', count($historyJ1['body']['data']['rows'] ?? []) === 1, json_encode($historyJ1['body']));

    $whReportJ1 = httpCall('GET', "{$base}/warehouses/report?q=KARANG_TENGAH", null, $jar, $csrf);
    $ktReportRow = ($whReportJ1['body']['data']['rows'] ?? [])[0] ?? null;
    check('warehouse report (Master Gudang API) reflects the new stock', $ktReportRow !== null && (float) $ktReportRow['qty_on_hand'] >= 80.0, json_encode($ktReportRow));

    // J.3 — Stock OUT part of that same quantity via the identical UI route.
    $firstOut = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => uid('v213-http-out'), 'item_id' => $itemJ1, 'warehouse_id' => $karangTengahId,
        'input_qty' => 30, 'input_unit_id' => $kgUnitId,
        'transaction_date' => '2026-09-25 15:00:00',
    ], $jar, $csrf);
    check('HTTP POST /transactions/out succeeds (FIFO consumption)', ($firstOut['body']['success'] ?? false) === true, json_encode($firstOut['body']));
    $afterFirstOut = httpCall('GET', "{$base}/inventory/current?item_id={$itemJ1}&warehouse_id={$karangTengahId}", null, $jar, $csrf);
    check('remaining balance correct after OUT (80 - 30 = 50)', abs(($afterFirstOut['body']['data']['qty_base'] ?? -1) - 50.0) < 0.0001, json_encode($afterFirstOut['body']));

    // J.4 — first-ever TRANSFER receipt to Karang Tengah for a DIFFERENT
    // brand-new item, via the same /transfers + /transfers/{id}/receive
    // routes the UI's Transfer screen uses — no manual catalog/pre-seeding.
    $itemJ2 = makeItem($pdo, $kgUnitId, 'V213-J2-FIRSTXFER');
    postIn($pdo, $itemJ2, $gudangBesarId, $kgUnitId, 60, 4000, $adminUserId, '2026-09-25 08:00:00');
    $xferCreate = httpCall('POST', "{$base}/transfers", [
        'transfer_uuid' => uid('v213-http-xfer'), 'from_warehouse_id' => $gudangBesarId, 'to_warehouse_id' => $karangTengahId,
        'ship_date' => '2026-09-25 09:00:00',
        'lines' => [['item_id' => $itemJ2, 'input_qty' => 20, 'input_unit_id' => $kgUnitId]],
    ], $jar, $csrf);
    check('HTTP POST /transfers succeeds (Gudang Besar -> Karang Tengah)', ($xferCreate['body']['success'] ?? false) === true, json_encode($xferCreate['body']));
    $xferId = $xferCreate['body']['data']['transfer_id'] ?? null;
    $xferReceive = httpCall('POST', "{$base}/transfers/{$xferId}/receive", [], $jar, $csrf);
    check('HTTP POST /transfers/{id}/receive succeeds — first-ever batch at destination created with no manual catalog/pre-seeding', ($xferReceive['body']['success'] ?? false) === true, json_encode($xferReceive['body']));
    $afterXfer = httpCall('GET', "{$base}/inventory/current?item_id={$itemJ2}&warehouse_id={$karangTengahId}", null, $jar, $csrf);
    check('destination received correct quantity via HTTP transfer path (20)', abs(($afterXfer['body']['data']['qty_base'] ?? -1) - 20.0) < 0.0001, json_encode($afterXfer['body']));

    // J.5 — Daily report API: real dates (today's release date and the
    // day after), via the exact route the frontend calls. No fake
    // historical 01-17 Sep data anywhere in this section.
    $itemJ3 = makeItem($pdo, $kgUnitId, 'V213-J3-DAILY');
    httpCall('POST', "{$base}/transactions/in", ['transaction_uuid' => uid('v213-d1-in'), 'item_id' => $itemJ3, 'warehouse_id' => $karangTengahId, 'input_qty' => 200, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000, 'transaction_date' => '2026-09-26 08:00:00'], $jar, $csrf);
    httpCall('POST', "{$base}/transactions/out", ['transaction_uuid' => uid('v213-d1-out'), 'item_id' => $itemJ3, 'warehouse_id' => $karangTengahId, 'input_qty' => 40, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-26 16:00:00'], $jar, $csrf);
    httpCall('POST', "{$base}/transactions/in", ['transaction_uuid' => uid('v213-d2-in'), 'item_id' => $itemJ3, 'warehouse_id' => $karangTengahId, 'input_qty' => 90, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000, 'transaction_date' => '2026-09-27 08:00:00'], $jar, $csrf);
    httpCall('POST', "{$base}/transactions/out", ['transaction_uuid' => uid('v213-d2-out'), 'item_id' => $itemJ3, 'warehouse_id' => $karangTengahId, 'input_qty' => 15, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-27 16:00:00'], $jar, $csrf);

    $dailyHttp = httpCall('GET', "{$base}/reports/movement/daily?start_date=2026-09-26&end_date=2026-09-27&warehouse_id={$karangTengahId}", null, $jar, $csrf);
    $rowsByDate = [];
    foreach ($dailyHttp['body']['data']['rows'] ?? [] as $r) { $rowsByDate[$r['date']] = $r; }
    check('GET /reports/movement/daily (warehouse=KARANG_TENGAH) returns both 26 Sep and 27 Sep', isset($rowsByDate['2026-09-26']) && isset($rowsByDate['2026-09-27']), json_encode(array_keys($rowsByDate)));
    check('26 Sep IN/OUT value correct (IN=200,000, OUT=40,000)', isset($rowsByDate['2026-09-26']) && abs($rowsByDate['2026-09-26']['barang_masuk'] - 200000.0) < 0.01 && abs($rowsByDate['2026-09-26']['barang_keluar'] - 40000.0) < 0.01, json_encode($rowsByDate['2026-09-26'] ?? null));
    check('27 Sep IN/OUT value correct (IN=90,000, OUT=15,000)', isset($rowsByDate['2026-09-27']) && abs($rowsByDate['2026-09-27']['barang_masuk'] - 90000.0) < 0.01 && abs($rowsByDate['2026-09-27']['barang_keluar'] - 15000.0) < 0.01, json_encode($rowsByDate['2026-09-27'] ?? null));
    $periodInTotal = array_sum(array_column($rowsByDate, 'barang_masuk'));
    $periodOutTotal = array_sum(array_column($rowsByDate, 'barang_keluar'));
    check('period total IN value = 290,000 (200,000+90,000)', abs($periodInTotal - 290000.0) < 0.01, (string) $periodInTotal);
    check('period total OUT value = 55,000 (40,000+15,000)', abs($periodOutTotal - 55000.0) < 0.01, (string) $periodOutTotal);

    $dailyAllHttp = httpCall('GET', "{$base}/reports/movement/daily?start_date=2026-09-26&end_date=2026-09-27", null, $jar, $csrf);
    $allByDate = [];
    foreach ($dailyAllHttp['body']['data']['rows'] ?? [] as $r) { $allByDate[$r['date']] = $r; }
    check('All Warehouse total (no warehouse_id filter) includes Karang Tengah activity on 26 Sep (IN value >= 200,000)', isset($allByDate['2026-09-26']) && $allByDate['2026-09-26']['barang_masuk'] >= 200000.0, json_encode($allByDate['2026-09-26'] ?? null));
} finally {
    proc_terminate($process);
    proc_close($process);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
