<?php
declare(strict_types=1);

/**
 * STABILIZATION — TRANSFER UNIT OPTIONS.
 * Seeds tests/browser/playwright_transfer_unit_options.mjs: one admin, two
 * active warehouses (source + destination), and items covering the
 * dropdown-backfill scenarios the real item-selector.js fix must render
 * correctly in a live browser (A-D + dedup — the business-logic/base-qty
 * math itself is proven separately, at the PHP service level, by
 * tests/transfer_unit_options_test.php).
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
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$packUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PACK'")->fetchColumn();
$kartonUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();

$pass = 'StbTfTest' . bin2hex(random_bytes(4)) . '!1';
$username = uid('stbtf-admin');
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, 'Gudang Sumber TF UI', 'MAIN', 1)")->execute(['c' => uid('STBTF-SRC')]);
$srcWhId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, 'Gudang Tujuan TF UI', 'TRANSIT', 1)")->execute(['c' => uid('STBTF-DST')]);
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
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('stbtf-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => 1000,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $by, 'username' => 'stbtf', 'transaction_type' => 'OPENING',
    ]));
}

// A. Base unit only — no conversions at all (true data gap).
$skuBaseOnly = $pdo->prepare('SELECT sku FROM items WHERE id = :id');
$itemBaseOnly = makeItem($pdo, $pcsUnitId, 'STBTF-A-BASEONLY');
$skuBaseOnly->execute(['id' => $itemBaseOnly]);
$skuBaseOnlyVal = $skuBaseOnly->fetchColumn();

// B. Base + 1 conversion, base identity row MISSING (exact production shape).
$itemOneConv = makeItem($pdo, $pcsUnitId, 'STBTF-B-ONECONV');
UnitConversionService::openNewVersion($pdo, $itemOneConv, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
$stmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id'); $stmt->execute(['id' => $itemOneConv]); $skuOneConv = $stmt->fetchColumn();
postStock($pdo, $itemOneConv, $srcWhId, $kartonUnitId, 10, $adminId);

// C. Base + multiple conversions, base identity row MISSING.
$itemMulti = makeItem($pdo, $pcsUnitId, 'STBTF-C-MULTICONV');
UnitConversionService::openNewVersion($pdo, $itemMulti, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
UnitConversionService::openNewVersion($pdo, $itemMulti, $packUnitId, 10.0, '2026-01-01 00:00:00', null, 'pack', false);
$stmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id'); $stmt->execute(['id' => $itemMulti]); $skuMulti = $stmt->fetchColumn();
postStock($pdo, $itemMulti, $srcWhId, $kartonUnitId, 10, $adminId);

// Dedup. Base unit ALREADY has its own open conversion row — must never
// render a duplicate base-unit option.
$itemDedup = makeItem($pdo, $pcsUnitId, 'STBTF-DEDUP');
UnitConversionService::openNewVersion($pdo, $itemDedup, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity (base unit)');
UnitConversionService::openNewVersion($pdo, $itemDedup, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
$stmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id'); $stmt->execute(['id' => $itemDedup]); $skuDedup = $stmt->fetchColumn();
postStock($pdo, $itemDedup, $srcWhId, $kartonUnitId, 10, $adminId);

// F/G end-to-end UI submit proof: base + conversion, enough stock to
// transfer in either the base unit or the largest unit.
$itemSubmit = makeItem($pdo, $pcsUnitId, 'STBTF-SUBMIT');
UnitConversionService::openNewVersion($pdo, $itemSubmit, $pcsUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity (base unit)');
UnitConversionService::openNewVersion($pdo, $itemSubmit, $kartonUnitId, 100.0, '2026-01-01 00:00:00', null, 'karton', true);
$stmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id'); $stmt->execute(['id' => $itemSubmit]); $skuSubmit = $stmt->fetchColumn();
postStock($pdo, $itemSubmit, $srcWhId, $pcsUnitId, 500, $adminId);

echo json_encode([
    'admin' => ['username' => $username, 'password' => $pass],
    'src_warehouse_id' => $srcWhId,
    'dst_warehouse_id' => $dstWhId,
    'sku_base_only' => $skuBaseOnlyVal,
    'sku_one_conv' => $skuOneConv,
    'sku_multi_conv' => $skuMulti,
    'sku_dedup' => $skuDedup,
    'sku_submit' => $skuSubmit,
]);
