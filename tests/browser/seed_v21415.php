<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11.5 — seeds a fresh (already-empty) inventory_test DB with
 * exactly the fixtures the Playwright browser smoke
 * (tests/browser/playwright_v21415.mjs) drives, then prints a JSON blob
 * of credentials/ids on stdout for that script to consume.
 *
 * Users:
 *   admin        — SUPERADMIN
 *   counterOne   — OPNAME_COUNTER, exactly ONE active assigned session
 *                  (sessionOne, Tim P1) -> direct-entry / autocomplete /
 *                  claim-conflict / already-counted scenarios
 *   counterOneB  — OPNAME_COUNTER, SAME session + SAME team (P1) as
 *                  counterOne -> same-team claim-conflict scenario
 *   counterZero  — OPNAME_COUNTER, no team membership anywhere -> zero-
 *                  session message
 *   counterMulti — OPNAME_COUNTER, assigned P1 on BOTH sessionOne and
 *                  sessionTwo -> multi-session picker
 *
 * Items (all on sessionOne unless noted): a "coklat" family (2 ACTIVE +
 * 1 INACTIVE, all matching a "coklat" search — required-11 proof that
 * INACTIVE items stay searchable), one unrelated item (must NOT match
 * "coklat"), one item with a real items.barcode value (exact-barcode
 * Enter test), one item pre-counted by counterOne before the browser
 * script even starts (the "already counted -> Tambah Temuan" scenario,
 * set up here directly via the service layer rather than through the UI
 * a second time), and one item reserved for the same-team claim-conflict
 * scenario.
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
$grUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();
$mlUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='ML'")->fetchColumn();
$ltrUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21415PW', 'V2.14.11.5 Playwright WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21415PW2', 'V2.14.11.5 Playwright WH2', 1)")->execute();
$whId2 = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'PwTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'pw5admin', $superRoleId);
$counterOne = makeUser($pdo, 'pw5c1', $counterRoleId);
$counterOneB = makeUser($pdo, 'pw5c1b', $counterRoleId);
$counterZero = makeUser($pdo, 'pw5c0', $counterRoleId);
$counterMulti = makeUser($pdo, 'pw5cm', $counterRoleId);

function makeItem(PDO $pdo, string $skuTag, string $name, int $unitId, string $status = 'ACTIVE', ?string $barcode = null): int
{
    $sku = uid($skuTag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status, barcode) VALUES (:sku,:name,:unit,0,:status,:barcode)')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $unitId, 'status' => $status, 'barcode' => $barcode]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('pw5-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'pw5', 'transaction_type' => 'OPENING',
    ]));
}

// PHASE V2.14.11.5 item 18 — an item with a SECOND configured unit
// conversion (e.g. base KG + GR), so StockOpnameService::start()'s own
// snapshotSessionUnits() freezes BOTH units for this item (it snapshots
// every currently-valid item_unit_conversions row, never just the base
// — see that method). Nothing here invents a conversion StockOpnameService
// itself wouldn't otherwise snapshot.
function makeItemWithSecondUnit(PDO $pdo, string $skuTag, string $name, int $baseUnitId, int $secondUnitId, float $secondConversionToBase, string $status = 'ACTIVE'): int
{
    $sku = uid($skuTag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $baseUnitId, 'status' => $status]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    UnitConversionService::openNewVersion($pdo, $id, $secondUnitId, $secondConversionToBase, '2020-01-01 00:00:00', null, 'secondary unit');
    return $id;
}

$itemCoklat1 = makeItem($pdo, 'RM-CK-001', 'Coklat Compound Dark 5kg', $kgUnitId, 'ACTIVE');
$itemCoklat2 = makeItem($pdo, 'RM-CK-002', 'Coklat Bubuk Premium', $kgUnitId, 'ACTIVE');
$itemCoklatInactive = makeItem($pdo, 'RM-CK-003', 'Dark Coklat Chips', $kgUnitId, 'INACTIVE');
$itemPlain = makeItem($pdo, 'RM-XX-100', 'Tepung Terigu', $kgUnitId, 'ACTIVE');
$barcodeValue = '899' . random_int(1000000, 9999999);
$itemBarcode = makeItem($pdo, 'RM-BC-001', 'Gula Pasir', $kgUnitId, 'ACTIVE', $barcodeValue);
$itemConflict = makeItem($pdo, 'RM-CF-001', 'Minyak Goreng', $kgUnitId, 'ACTIVE');
$itemAlready = makeItem($pdo, 'RM-AC-001', 'Susu Bubuk', $kgUnitId, 'ACTIVE');

// PHASE V2.14.11.5 item 18 — unit-family fixtures for SISA SATUAN TERKECIL.
$itemKgGr = makeItemWithSecondUnit($pdo, 'RM-KG-GR', 'Keju Cheddar Blok', $kgUnitId, $grUnitId, 0.001);
$itemLtrMl = makeItemWithSecondUnit($pdo, 'RM-LTR-ML', 'Susu Cair UHT', $ltrUnitId, $mlUnitId, 0.001);
$itemPcsOnly = makeItem($pdo, 'RM-PC-001', 'Sendok Plastik', $pcsUnitId, 'ACTIVE');
$itemKgOnly = makeItem($pdo, 'RM-KG-ONLY', 'Beras Curah', $kgUnitId, 'ACTIVE');

foreach ([$itemCoklat1, $itemCoklat2, $itemCoklatInactive, $itemPlain, $itemBarcode, $itemConflict, $itemAlready] as $it) {
    postOpeningIn($pdo, $it, $kgUnitId, $whId, 50, 1000, $admin['id']);
}
postOpeningIn($pdo, $itemKgGr, $kgUnitId, $whId, 30, 2000, $admin['id']);
postOpeningIn($pdo, $itemLtrMl, $ltrUnitId, $whId, 40, 1500, $admin['id']);
postOpeningIn($pdo, $itemPcsOnly, $pcsUnitId, $whId, 100, 200, $admin['id']);
postOpeningIn($pdo, $itemKgOnly, $kgUnitId, $whId, 60, 800, $admin['id']);

$sessionOne = Database::transaction(fn (PDO $tx) => StockOpnameService::start(
    $tx, $whId, $admin['id'], [
        $itemCoklat1, $itemCoklat2, $itemCoklatInactive, $itemPlain, $itemBarcode, $itemConflict, $itemAlready,
        $itemKgGr, $itemLtrMl, $itemPcsOnly, $itemKgOnly,
    ]
));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionOne, 'p1', [$counterOne['id'], $counterOneB['id'], $counterMulti['id']], $admin['id']));

// A second warehouse/session so counterMulti has TWO active assigned
// sessions (requirement 2's "more than one -> simple picker" branch).
$itemWh2 = makeItem($pdo, 'RM-W2-001', 'Mentega Putih', $kgUnitId, 'ACTIVE');
postOpeningIn($pdo, $itemWh2, $kgUnitId, $whId2, 20, 500, $admin['id']);
$sessionTwo = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId2, $admin['id'], [$itemWh2]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionTwo, 'p1', [$counterMulti['id']], $admin['id']));

// Pre-count itemAlready for counterOne (requirement 14 — reopening an
// already-counted item must open SUMMARY first, never straight into a
// blank form) via the service layer directly, exactly the same write
// path the real UI's Simpan Hitungan uses.
$baseUnitRow = $pdo->prepare('SELECT solu.unit_id FROM stock_opname_lines sol JOIN stock_opname_line_units solu ON solu.stock_opname_line_id = sol.id WHERE sol.session_id = :sid AND sol.item_id = :item AND solu.is_base_unit = 1');
$baseUnitRow->execute(['sid' => $sessionOne, 'item' => $itemAlready]);
$baseUnitId = (int) $baseUnitRow->fetchColumn();
$claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionOne, 'p1', $counterOne['id'], $itemAlready));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $sessionOne, 'p1', $itemAlready,
    [
        'GOOD' => [['unit_id' => $baseUnitId, 'qty' => 15]],
        'DAMAGED' => [['unit_id' => $baseUnitId, 'qty' => 0]],
        'EXPIRED' => [['unit_id' => $baseUnitId, 'qty' => 0]],
        'DEADSTOCK' => [['unit_id' => $baseUnitId, 'qty' => 0]],
    ],
    null, $counterOne['id'], $claim['claim_token']
));

echo json_encode([
    'admin' => $admin,
    'counterOne' => $counterOne, 'counterOneB' => $counterOneB,
    'counterZero' => $counterZero, 'counterMulti' => $counterMulti,
    'warehouse_id' => $whId, 'warehouse_id2' => $whId2,
    'session_id' => $sessionOne, 'session_id2' => $sessionTwo,
    'itemCoklat1' => $itemCoklat1, 'itemCoklat2' => $itemCoklat2, 'itemCoklatInactive' => $itemCoklatInactive,
    'itemPlain' => $itemPlain, 'itemBarcode' => $itemBarcode, 'barcodeValue' => $barcodeValue,
    'itemConflict' => $itemConflict, 'itemAlready' => $itemAlready,
    'itemKgGr' => $itemKgGr, 'itemLtrMl' => $itemLtrMl, 'itemPcsOnly' => $itemPcsOnly, 'itemKgOnly' => $itemKgOnly,
], JSON_PRETTY_PRINT);
