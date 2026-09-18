<?php
declare(strict_types=1);

/**
 * Manual smoke test against a REAL MySQL/MariaDB instance (not SQLite) —
 * exercises the real Database::connection()/transaction()/lockFifoBatches()
 * path, including the actual `FOR UPDATE` row-locking clause that the
 * sqlite-based tests/run.php cannot reach.
 *
 * Requires a configured .env pointing at a throwaway/dev database — this is
 * NOT wired into a default CI run (tests/run.php + tests/import_test.php
 * are), because it needs a live server. See docs/PHASE_F_TESTS.md.
 *
 * Usage: php tests/mysql_smoke_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\InsufficientStockException;
use App\Services\PriceAnomalyException;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function uid(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(4)); }

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function approx(float $a, float $b, float $eps = 0.0001): bool { return abs($a - $b) < $eps; }

// ---- fixtures (idempotent-ish: unique codes per run) ----
$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('SMOKE-WH', 'Smoke Test Warehouse')
            ON DUPLICATE KEY UPDATE name = VALUES(name)");
$warehouseId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='SMOKE-WH'")->fetchColumn();

$pdo->exec("INSERT INTO users (username, password_hash, full_name, role_id, is_active)
            SELECT 'smoke_user', '" . password_hash('irrelevant', PASSWORD_BCRYPT) . "', 'Smoke User', id, 1
            FROM roles WHERE code='SUPERADMIN'
            ON DUPLICATE KEY UPDATE full_name = VALUES(full_name)");
$userId = (int) $pdo->query("SELECT id FROM users WHERE username='smoke_user'")->fetchColumn();

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$sku = uid('SMOKE-SKU');
$pdo->prepare("INSERT INTO items (sku, name, base_unit_id) VALUES (:sku, :name, :unit)")
    ->execute(['sku' => $sku, 'name' => $sku, 'unit' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', $userId, 'base identity');

echo "== Real-MySQL smoke test: FIFO across two layers, real FOR UPDATE lock ==\n";
Database::transaction(function (PDO $tx) use ($itemId, $warehouseId, $userId) {
    $kg = (int) $tx->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    FifoService::postIn($tx, [
        'transaction_uuid' => uid('in1'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
        'input_qty' => 100, 'input_unit_id' => $kg, 'unit_price_input' => 10000,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
    ]);
});
Database::transaction(function (PDO $tx) use ($itemId, $warehouseId, $userId) {
    $kg = (int) $tx->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    FifoService::postIn($tx, [
        'transaction_uuid' => uid('in2'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
        'input_qty' => 100, 'input_unit_id' => $kg, 'unit_price_input' => 12000,
        'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $userId,
    ]);
});
$out = Database::transaction(function (PDO $tx) use ($itemId, $warehouseId, $userId) {
    $kg = (int) $tx->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    return FifoService::postOut($tx, [
        'transaction_uuid' => uid('out'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
        'input_qty' => 150, 'input_unit_id' => $kg,
        'transaction_date' => '2026-09-03 08:00:00', 'created_by' => $userId,
    ]);
});
check('FIFO across real MySQL rows: HPP = Rp1.600.000', approx($out['total_cost'], 1600000), "got {$out['total_cost']}");
$stock = FifoService::currentStock($pdo, $itemId, $warehouseId);
check('Remaining stock (real MySQL) = 50kg @ value Rp600.000', approx($stock['qty_base'], 50) && approx($stock['value'], 600000), json_encode($stock));

echo "\n== Real-MySQL: OUT greater than stock is rejected ==\n";
$rejected = false;
try {
    Database::transaction(function (PDO $tx) use ($itemId, $warehouseId, $userId) {
        $kg = (int) $tx->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
        FifoService::postOut($tx, [
            'transaction_uuid' => uid('overdraw'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
            'input_qty' => 99999, 'input_unit_id' => $kg,
            'transaction_date' => '2026-09-04 08:00:00', 'created_by' => $userId,
        ]);
    });
} catch (InsufficientStockException $e) {
    $rejected = true;
}
check('OUT > stock rejected AND transaction rolled back (real MySQL)', $rejected);
$stockAfterReject = FifoService::currentStock($pdo, $itemId, $warehouseId);
check('Stock unchanged after rejected OUT (rollback proven)', approx($stockAfterReject['qty_base'], 50), "got {$stockAfterReject['qty_base']}");

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
