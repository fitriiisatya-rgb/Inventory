<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11.3 — seeds a fresh (already-empty) inventory_test DB with
 * exactly the fixtures the Playwright browser smoke
 * (tests/browser/playwright_v21413.mjs) drives: one SUPERADMIN and one
 * OPEN, unassigned FINDINGS_V1 session, so the browser script can drive
 * the ENTIRE "Petugas Stock Opname" account-creation + P1/P2 assignment
 * flow itself through the real UI, exactly as a real SUPERADMIN would.
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

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21413PW', 'V2.14.11.3 Playwright WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'PwTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'pw3admin', $superRoleId);

function makeItem(PDO $pdo, string $tag, int $unitId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('pw3-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'pw3', 'transaction_type' => 'OPENING',
    ]));
}

$itemA = makeItem($pdo, 'PW3-A', $kgUnitId);
postOpeningIn($pdo, $itemA, $kgUnitId, $whId, 50, 1000, $admin['id']);

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$itemA], 'FINDINGS_V1'));

echo json_encode([
    'admin' => $admin, 'warehouse_id' => $whId, 'session_id' => $sessionId, 'itemA' => $itemA,
], JSON_PRETTY_PRINT);
