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
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\TransferService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
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

// ============================================================
// A. Warehouse creation — apply the actual migration, verify the row
// ============================================================
echo "== A. Warehouse creation (real migration file) ==\n";
$migrationFile = __DIR__ . '/../database/migrations/2026_09_26_v2_13_karang_tengah_warehouse.sql';
check('migration file exists', is_file($migrationFile));

$batchesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$qtyValueBefore = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetch(PDO::FETCH_NUM);

applySqlFile($pdo, $migrationFile);

$kt = $pdo->query("SELECT * FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetch();
check('KARANG_TENGAH row exists after migration', $kt !== false);
check('name = Gudang Karang Tengah', $kt && $kt['name'] === 'Gudang Karang Tengah', (string) ($kt['name'] ?? 'MISSING'));
check('warehouse_type = TRANSIT', $kt && $kt['warehouse_type'] === 'TRANSIT', (string) ($kt['warehouse_type'] ?? 'MISSING'));
check('is_active = 0 (INACTIVE)', $kt && (int) $kt['is_active'] === 0, (string) ($kt['is_active'] ?? 'MISSING'));
$karangTengahId = (int) $kt['id'];

$batchCountForKt = (int) $pdo->prepare('SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = :id')->execute(['id' => $karangTengahId]);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = :id');
$stmt->execute(['id' => $karangTengahId]);
check('zero inventory_batches rows for Karang Tengah', (int) $stmt->fetchColumn() === 0);

// re-run migration: idempotency
applySqlFile($pdo, $migrationFile);
$ktCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetchColumn();
check('re-running migration creates no duplicate row (idempotent)', $ktCount === 1, "count={$ktCount}");

// ============================================================
// B. Safety — zero inventory effect, inactive warehouse cannot mutate stock
// ============================================================
echo "\n== B. Safety ==\n";
$batchesAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$qtyValueAfter = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
check('migration changes company inventory_batches ROW COUNT by 0', $batchesAfter === $batchesBefore, "before={$batchesBefore} after={$batchesAfter}");
check('migration changes company inventory qty by 0', (float) $qtyValueBefore[0] === (float) $qtyValueAfter[0], "before={$qtyValueBefore[0]} after={$qtyValueAfter[0]}");
check('migration changes company inventory value by 0', (float) $qtyValueBefore[1] === (float) $qtyValueAfter[1], "before={$qtyValueBefore[1]} after={$qtyValueAfter[1]}");

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

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
