<?php
declare(strict_types=1);

/**
 * STABILIZATION — TRANSFER STOCK DISPLAY FOLLOWS SELECTED UNIT.
 * Seeds tests/browser/playwright_transfer_stock_display.mjs: one admin,
 * one source warehouse, and items covering every "Stok Tersedia" display
 * scenario the fixed transfers.js must render correctly (A-G from the
 * task). Numbers mirror the real production report (RM-KJ-26-004: base
 * stock 192 KG, 1 KARTON = 32 KG -> 6 KARTON available).
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
require_once __DIR__ . '/../../services/PeriodLockService.php';
require_once __DIR__ . '/../../services/WarehouseLockService.php';
require_once __DIR__ . '/../../services/WarehouseGuardService.php';
require_once __DIR__ . '/../../services/FifoService.php';

use App\Services\Database;
use App\Services\UnitConversionService;
use App\Services\FifoService;

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$kartonUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$packUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PACK'")->fetchColumn();
$boxUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='BOX'")->fetchColumn();
$rollUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='ROLL'")->fetchColumn();

$pass = 'StbSdTest' . bin2hex(random_bytes(4)) . '!1';
$username = uid('stbsd-admin');
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, 'Gudang Sumber SD UI', 'MAIN', 1)")->execute(['c' => uid('STBSD-SRC')]);
$srcWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, 'Gudang Tujuan SD UI', 'TRANSIT', 1)")->execute(['c' => uid('STBSD-DST')]);
$dstWhId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $baseUnitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId]);
    return (int) $pdo->lastInsertId();
}
function postStock(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, int $by): void
{
    if ($qty <= 0) { return; }
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('stbsd-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $by, 'username' => 'stbsd', 'transaction_type' => 'OPENING',
    ]));
}
function skuOf(PDO $pdo, int $itemId): string
{
    $stmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id');
    $stmt->execute(['id' => $itemId]);
    return (string) $stmt->fetchColumn();
}

// A/B/C/D — base KG, KARTON (factor 32, largest), PACK (factor 4, middle),
// stock = 192 KG. Mirrors RM-KJ-26-004 exactly.
$itemBcd = makeItem($pdo, $kgUnitId, 'STBSD-BCD');
UnitConversionService::openNewVersion($pdo, $itemBcd, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
UnitConversionService::openNewVersion($pdo, $itemBcd, $kartonUnitId, 32.0, '2026-01-01 00:00:00', null, 'karton', true);
UnitConversionService::openNewVersion($pdo, $itemBcd, $packUnitId, 4.0, '2026-01-01 00:00:00', null, 'pack', false);
postStock($pdo, $itemBcd, $srcWhId, $kgUnitId, 192, $adminId);
$skuBcd = skuOf($pdo, $itemBcd);

// E — base KG, ROLL factor 7 (deliberately not a clean divisor of 100 ->
// forces a decimal result).
$itemE = makeItem($pdo, $kgUnitId, 'STBSD-E-DECIMAL');
UnitConversionService::openNewVersion($pdo, $itemE, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
UnitConversionService::openNewVersion($pdo, $itemE, $rollUnitId, 7.0, '2026-01-01 00:00:00', null, 'roll', true);
postStock($pdo, $itemE, $srcWhId, $kgUnitId, 100, $adminId);
$skuE = skuOf($pdo, $itemE);

// F — zero stock, base KG + KARTON factor 10.
$itemF = makeItem($pdo, $kgUnitId, 'STBSD-F-ZERO');
UnitConversionService::openNewVersion($pdo, $itemF, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
UnitConversionService::openNewVersion($pdo, $itemF, $kartonUnitId, 10.0, '2026-01-01 00:00:00', null, 'karton', true);
// Deliberately no postStock() call — genuinely zero stock, not a posted-then-depleted item.
$skuF = skuOf($pdo, $itemF);

// G — base KG, BOX factor 500 (larger than the 192 on hand) -> available
// in BOX must be a fraction < 1, never floor()'d to 0 or negative.
$itemG = makeItem($pdo, $kgUnitId, 'STBSD-G-LARGEFACTOR');
UnitConversionService::openNewVersion($pdo, $itemG, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity');
UnitConversionService::openNewVersion($pdo, $itemG, $boxUnitId, 500.0, '2026-01-01 00:00:00', null, 'box', true);
postStock($pdo, $itemG, $srcWhId, $kgUnitId, 192, $adminId);
$skuG = skuOf($pdo, $itemG);

echo json_encode([
    'admin' => ['username' => $username, 'password' => $pass],
    'src_warehouse_id' => $srcWhId,
    'dst_warehouse_id' => $dstWhId,
    'sku_bcd' => $skuBcd,
    'sku_e_decimal' => $skuE,
    'sku_f_zero' => $skuF,
    'sku_g_largefactor' => $skuG,
]);
