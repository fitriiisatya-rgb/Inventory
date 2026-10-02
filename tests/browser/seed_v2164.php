<?php
declare(strict_types=1);

/**
 * PHASE V2.16.4 — seeds a fresh inventory_test DB with exactly the
 * fixture tests/browser/playwright_v2164.mjs drives for "Laporan Stock
 * Opname": one SUPERADMIN (sees everything, including the reused Excel
 * Final/Rekonsiliasi Final/Print export buttons), one VIEWER (INVENTORY_
 * VIEW only — proves the new report is reachable without STOCK_OPNAME_
 * MANAGE/SUPERVISE, and that those export buttons are hidden for them),
 * one category, two items (one with a Rusak condition so the Kondisi
 * badge and HPP-absence can both be checked in the rendered table), and
 * one POSTED LEGACY_DUAL_COUNT session. Prints a JSON blob on stdout.
 */

require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/UnitConversionService.php';
require_once __DIR__ . '/../../services/UnitNormalizationService.php';
require_once __DIR__ . '/../../services/PriceAnomalyService.php';
require_once __DIR__ . '/../../services/CostNormalizationService.php';
require_once __DIR__ . '/../../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../../services/IdempotencyService.php';
require_once __DIR__ . '/../../services/InventoryService.php';
require_once __DIR__ . '/../../services/FifoService.php';
require_once __DIR__ . '/../../services/PeriodLockService.php';
require_once __DIR__ . '/../../services/WarehouseLockService.php';
require_once __DIR__ . '/../../services/WarehouseGuardService.php';
require_once __DIR__ . '/../../services/StockAdjustmentService.php';
require_once __DIR__ . '/../../services/NumberingService.php';
require_once __DIR__ . '/../../services/StockOpnameService.php';
require_once __DIR__ . '/../../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2164PW', 'V2.16.4 Playwright WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('PW164-CAT'), 'n' => 'PW164 Kategori']);
$catId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = uid($tag);
    $pass = 'PwTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'pw164admin', $superRoleId);
$viewer = makeUser($pdo, 'pw164viewer', $viewerRoleId);

function makeItem(PDO $pdo, int $unitId, string $tag, int $categoryId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}

$item1 = makeItem($pdo, $kgUnitId, 'PW164-I1', $catId);
$item2 = makeItem($pdo, $kgUnitId, 'PW164-I2', $catId);

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('pw164-in1'), 'item_id' => $item1, 'warehouse_id' => $whId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $admin['id'], 'username' => 'pw164', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('pw164-in2'), 'item_id' => $item2, 'warehouse_id' => $whId,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $admin['id'], 'username' => 'pw164', 'transaction_type' => 'OPENING',
]));

$p1 = makeUser($pdo, 'pw164p1', $superRoleId);
$p2 = makeUser($pdo, 'pw164p2', $superRoleId);

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$item1, $item2], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p1_user_id' => $p1['id'], 'p2_user_id' => $p2['id']], $admin['id']));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => date('Y-m-d'), 'id' => $sessionId]);

Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item1, 95.0, $p1['id'], ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0]));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item1, 95.0, $p2['id'], ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0]));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item2, 50.0, $p1['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item2, 50.0, $p2['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $admin['id']));

$sessionRow = $pdo->query("SELECT session_number FROM stock_opname_sessions WHERE id = {$sessionId}")->fetch();

echo json_encode([
    'admin' => $admin,
    'viewer' => $viewer,
    'session_id' => $sessionId,
    'session_number' => $sessionRow['session_number'],
    'warehouse_id' => $whId,
]);
