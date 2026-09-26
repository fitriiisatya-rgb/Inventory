<?php
declare(strict_types=1);

/** Creates a fresh item+warehouse with 100kg stock and prints "item_id warehouse_id user_id" for the concurrency test to consume. */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;

$pdo = Database::connection();

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();

$username = uid('concurrency-user');
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u, :h, :u2, :r, 1)")
    ->execute(['u' => $username, 'h' => password_hash('x', PASSWORD_BCRYPT), 'u2' => $username, 'r' => $roleId]);
$userId = (int) $pdo->lastInsertId();

$whCode = uid('WH-CONC');
$pdo->prepare("INSERT INTO warehouses (code, name) VALUES (:c, :c2)")->execute(['c' => $whCode, 'c2' => $whCode]);
$warehouseId = (int) $pdo->lastInsertId();

$sku = uid('SKU-CONC');
$pdo->prepare("INSERT INTO items (sku, name, base_unit_id) VALUES (:s, :n, :u)")
    ->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('conc-in'), 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $userId,
]));

echo "{$itemId} {$warehouseId} {$userId}\n";
