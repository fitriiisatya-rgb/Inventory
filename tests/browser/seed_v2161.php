<?php
declare(strict_types=1);

/**
 * PHASE V2.16.1 — seeds a fresh inventory_test DB with exactly the
 * fixtures tests/browser/playwright_v2161.mjs drives: one supervisor
 * (also assigned as the session's own P1 counter, so the same browser
 * script can exercise both the "STOK BUKU SO" supervisor card AND the
 * counter-facing counted_at backdated-entry checkbox without a second
 * login), one FINDINGS_V1 session backdated to 2026-09-30 (so "now" is
 * always past its SO EOD cutoff), and one item with 100 KG opening
 * stock. Prints a JSON blob of credentials/ids on stdout.
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
$counterRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='OPNAME_COUNTER'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2161PW', 'V2.16.1 Playwright WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'PwTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'pw161admin', $superRoleId);
// A plain OPNAME_COUNTER (no STOCK_OPNAME_SUPERVISE) — GET /stock-opname/
// {id} always prefers the privileged supervisor shape when the caller
// CAN supervise (see that route's own precedence rule in index.php), so
// the counted_at backdated-entry flow can only be exercised through an
// account that genuinely has no supervisor permission at all.
$counter = makeUser($pdo, 'pw161counter', $counterRoleId);

$sku = uid('PW161-ITEM');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')')
    ->execute(['sku' => $sku, 'name' => 'Item Playwright V2.16.1', 'unit' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('pw161-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $admin['id'], 'username' => 'pw161', 'transaction_type' => 'OPENING',
]));

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$itemId]));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $sessionId]);
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p1', [$counter['id']], $admin['id']));

echo json_encode([
    'admin' => $admin,
    'counter' => $counter,
    'session_id' => $sessionId,
    'warehouse_id' => $whId,
    'item_id' => $itemId,
    'sku' => $sku,
]);
