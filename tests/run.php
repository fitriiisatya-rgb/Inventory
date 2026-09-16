<?php
declare(strict_types=1);

/**
 * Runs the Section 27 test scenarios against an in-memory SQLite database
 * loaded with tests/sqlite_schema.sql, exercising the real FifoService /
 * UnitConversionService / PriceAnomalyService classes (not a re-implementation
 * of their logic). No MySQL server is reachable in this sandbox, so this is
 * the offline substitute — see docs/PHASE_F_TESTS.md for why, and re-run the
 * same scenarios against a real MySQL instance before go-live.
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';

use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\InsufficientStockException;
use App\Services\PriceAnomalyException;

function fresh_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(file_get_contents(__DIR__ . '/sqlite_schema.sql'));
    return $pdo;
}

function seed_item(PDO $pdo, string $sku, string $baseUnitCode, array $purchaseUnits = []): array
{
    $unitCache = [];
    $unitId = function (string $code) use ($pdo, &$unitCache) {
        if (!isset($unitCache[$code])) {
            $pdo->prepare('INSERT OR IGNORE INTO units (code, name) VALUES (:c, :c)')->execute(['c' => $code]);
            $stmt = $pdo->prepare('SELECT id FROM units WHERE code = :c');
            $stmt->execute(['c' => $code]);
            $unitCache[$code] = (int) $stmt->fetchColumn();
        }
        return $unitCache[$code];
    };

    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES ("WH1","Gudang Utama")')->execute();
    $warehouseId = (int) $pdo->lastInsertId();

    $baseUnitId = $unitId($baseUnitCode);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:sku, :name, :unit)')
        ->execute(['sku' => $sku, 'name' => $sku, 'unit' => $baseUnitId]);
    $itemId = (int) $pdo->lastInsertId();

    // Base unit always has an implicit 1:1 conversion to itself.
    UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base unit identity');

    foreach ($purchaseUnits as $code => $factor) {
        UnitConversionService::openNewVersion($pdo, $itemId, $unitId($code), $factor, '2020-01-01 00:00:00', null, 'test fixture');
    }

    return ['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'base_unit_id' => $baseUnitId, 'unit_id' => $unitId];
}

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    echo ($pass ? "PASS" : "FAIL") . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function approx(float $a, float $b, float $eps = 0.0001): bool
{
    return abs($a - $b) < $eps;
}

// ---------------------------------------------------------------------------
echo "== Test 1: simple opening + OUT ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T1', 'KG');
FifoService::postIn($pdo, [
    'transaction_uuid' => 't1-in', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 100, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
]);
$out = FifoService::postOut($pdo, [
    'transaction_uuid' => 't1-out', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 40, 'input_unit_id' => $f['base_unit_id'],
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => 1,
]);
$stock = FifoService::currentStock($pdo, $f['item_id'], $f['warehouse_id']);
check('T1 remaining stock = 60kg', approx($stock['qty_base'], 60), "got {$stock['qty_base']}");
check('T1 HPP of the OUT = Rp400.000', approx($out['total_cost'], 400000), "got {$out['total_cost']}");

// ---------------------------------------------------------------------------
echo "\n== Test 2: FIFO across two layers ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T2', 'KG');
FifoService::postIn($pdo, [
    'transaction_uuid' => 't2-in1', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 100, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
]);
FifoService::postIn($pdo, [
    'transaction_uuid' => 't2-in2', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 100, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 12000,
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => 1,
]);
$out = FifoService::postOut($pdo, [
    'transaction_uuid' => 't2-out', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 150, 'input_unit_id' => $f['base_unit_id'],
    'transaction_date' => '2026-09-03 08:00:00', 'created_by' => 1,
]);
check('T2 HPP = Rp1.600.000 (100x10.000 + 50x12.000)', approx($out['total_cost'], 1600000), "got {$out['total_cost']}");
$stock = FifoService::currentStock($pdo, $f['item_id'], $f['warehouse_id']);
check('T2 remaining = 50kg', approx($stock['qty_base'], 50), "got {$stock['qty_base']}");
check('T2 remaining value = Rp600.000 (50 x 12.000)', approx($stock['value'], 600000), "got {$stock['value']}");

// ---------------------------------------------------------------------------
echo "\n== Test 3: unit conversion (1 carton = 20 kg) ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T3', 'KG', ['KARTON' => 20]);
$cartonUnitId = ($f['unit_id'])('KARTON');
$in = FifoService::postIn($pdo, [
    'transaction_uuid' => 't3-in', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 2, 'input_unit_id' => $cartonUnitId, 'unit_price_input' => 500000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
]);
check('T3 unit_cost_base = Rp25.000/Kg', approx($in['unit_cost_base'], 25000), "got {$in['unit_cost_base']}");
check('T3 base_qty = 40kg', approx($in['base_qty'], 40), "got {$in['base_qty']}");
$stock = FifoService::currentStock($pdo, $f['item_id'], $f['warehouse_id']);
check('T3 stock value = Rp1.000.000', approx($stock['value'], 1000000), "got {$stock['value']}");

// ---------------------------------------------------------------------------
echo "\n== Test 4: idempotent double-submit ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T4', 'KG');
$params = [
    'transaction_uuid' => 't4-dup', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 10, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 5000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
];
FifoService::postIn($pdo, $params);
$second = FifoService::postIn($pdo, $params); // simulates a retried / double-clicked request
$count = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
check('T4 exactly 1 transaction row after duplicate submit', $count === 1, "got {$count}");
check('T4 second call reports idempotent replay', $second['idempotent_replay'] === true);

// ---------------------------------------------------------------------------
echo "\n== Test 5: OUT greater than stock is rejected ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T5', 'KG');
FifoService::postIn($pdo, [
    'transaction_uuid' => 't5-in', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 10, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 5000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
]);
$rejected = false;
try {
    FifoService::postOut($pdo, [
        'transaction_uuid' => 't5-out', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
        'input_qty' => 999, 'input_unit_id' => $f['base_unit_id'],
        'transaction_date' => '2026-09-02 08:00:00', 'created_by' => 1,
    ]);
} catch (InsufficientStockException $e) {
    $rejected = true;
}
check('T5 OUT > stock throws InsufficientStockException', $rejected);

// ---------------------------------------------------------------------------
echo "\n== Test 6: price anomaly (100x reference) ==\n";
$pdo = fresh_pdo();
$f = seed_item($pdo, 'SKU-T6', 'KG');
FifoService::postIn($pdo, [
    'transaction_uuid' => 't6-in1', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 10, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => 1,
]);
$flagged = false;
try {
    FifoService::postIn($pdo, [
        'transaction_uuid' => 't6-in2', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
        'input_qty' => 10, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 1000000, // 100x
        'transaction_date' => '2026-09-02 08:00:00', 'created_by' => 1,
    ]);
} catch (PriceAnomalyException $e) {
    $flagged = true;
}
check('T6 100x historical price throws PriceAnomalyException', $flagged);

// Confirm the SAME transaction posts once an approver is attached (the
// documented override path — Section 9's "explicit confirmation / admin approval").
$approved = FifoService::postIn($pdo, [
    'transaction_uuid' => 't6-in2-approved', 'item_id' => $f['item_id'], 'warehouse_id' => $f['warehouse_id'],
    'input_qty' => 10, 'input_unit_id' => $f['base_unit_id'], 'unit_price_input' => 1000000,
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => 1, 'anomaly_approved_by' => 1,
    'anomaly_reason' => 'confirmed with supplier, price genuinely increased',
]);
check('T6 posts after explicit anomaly approval', $approved['success'] === true);

// ---------------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results, fn ($r) => $r['pass']));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
