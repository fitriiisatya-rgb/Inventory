<?php
declare(strict_types=1);

/**
 * STABILIZATION — seeds tests/browser/playwright_stabilization.mjs:
 * one SUPERADMIN, three warehouses named exactly SCM/Cibadak/Karang Tengah
 * (Task 1's real-world names, proving the warehouse selector is a pure
 * Master.warehouses() consumer with nothing hardcoded), and two LEGACY
 * single-count sessions on the SCM warehouse, both already FINALIZED (not
 * yet posted) via the real, unmodified StockOpnameService:
 *   - $sessionReady  — a normal variance, no cost_required line -> Post
 *     should succeed cleanly.
 *   - $sessionCostRequired — one line with a positive variance on an item
 *     that has never been purchased (no inventory_batches row at all), so
 *     finalize()'s own existing rule flags cost_required=1 -> Post without
 *     filling the price override must be refused with COST_REQUIRED.
 * Prints a JSON blob on stdout.
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
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

// Task 1 proof: the real three production warehouse names, inserted as
// ordinary rows — nothing in the frontend ever hardcodes these.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STB-SCM', 'SCM', 1)")->execute();
$whScm = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STB-CBD', 'Cibadak', 1)")->execute();
$whCibadak = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STB-KT', 'Karang Tengah', 1)")->execute();
$whKarangTengah = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('STB-CAT'), 'n' => 'Stabilization Kategori']);
$catId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = uid($tag);
    $pass = 'StbTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'stbadmin', $superRoleId);

function makeItem(PDO $pdo, int $unitId, string $tag, int $categoryId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}

// ---- Session 1: clean variance, no cost_required -> Post should succeed.
$item1 = makeItem($pdo, $kgUnitId, 'STB-READY', $catId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('stb-in1'), 'item_id' => $item1, 'warehouse_id' => $whScm,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $admin['id'], 'username' => 'stb', 'transaction_type' => 'OPENING',
]));
$sessionReady = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whScm, $admin['id'], [$item1], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::count($tx, $sessionReady, [$item1 => 95.0], $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionReady, $admin['id']));

// ---- Session 2: a positive-variance item with NO inventory_batches row at
// all (never purchased) -> finalize()'s own existing cost_required rule
// flags it -> Post without an override must be refused with COST_REQUIRED.
// A DIFFERENT warehouse (Cibadak) than session 1 — WarehouseLockService
// only ever allows one ACTIVE (non-POSTED/CANCELLED) session per
// warehouse at a time, and session 1 is deliberately left FINALIZED
// (still "active") so the test can Post it itself.
$item2 = makeItem($pdo, $kgUnitId, 'STB-COSTREQ', $catId);
$sessionCostRequired = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whCibadak, $admin['id'], [$item2], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::count($tx, $sessionCostRequired, [$item2 => 10.0], $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionCostRequired, $admin['id']));

echo json_encode([
    'admin' => $admin,
    'warehouse_id' => $whScm,
    'warehouse_id_cibadak' => $whCibadak,
    'warehouse_id_karang_tengah' => $whKarangTengah,
    'session_ready' => $sessionReady,
    'session_cost_required' => $sessionCostRequired,
]);
