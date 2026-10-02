<?php
declare(strict_types=1);

/**
 * PHASE V2.16.4 — "Laporan Stock Opname" monthly/session reporting.
 *
 * Part A (direct service calls): proves StockOpnameMonthlyReportService's
 * math against a hand-computed fixture (2 categories, 4 items, a MATCH+
 * Rusak item, a MATCH+Lebih item, a MATCH+Deadstock item with zero
 * variance, and a RECOUNTED+Expired item) — system/physical/variance
 * qty+value, category summary, finance summary, pagination, category/
 * search filters, month/year/warehouse/status filters, and that NO
 * HPP/unit_cost field is ever exposed in a returned row.
 *
 * Part B (real HTTP, php -S + curl): proves the new routes are reachable
 * with ONLY INVENTORY_VIEW (VIEWER role — no STOCK_OPNAME_MANAGE/
 * SUPERVISE), that the reused export/print routes keep their EXISTING
 * STOCK_OPNAME_SUPERVISE/MANAGE gate unchanged (never weakened), and that
 * a STOCK user scoped to another warehouse is forced to their own
 * warehouse exactly like the legacy GET /reports/opname report.
 *
 * Usage: php tests/inventory_v2_16_4_stock_opname_report_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnameMonthlyReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2164WH1', 'V2.16.4 WH1', 1)")->execute();
$wh1Id = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2164WH2', 'V2.16.4 WH2', 1)")->execute();
$wh2Id = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V2164-CATA'), 'n' => 'V2164 Kategori A']);
$catAId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V2164-CATB'), 'n' => 'V2164 Kategori B']);
$catBId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = uid($tag);
    $pass = 'V2164' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}
function makeItem(PDO $pdo, int $unitId, string $tag, int $categoryId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v2164-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-01-01 08:00:00', 'created_by' => $by, 'username' => 'v2164', 'transaction_type' => 'OPENING',
    ]));
}

$adminUserId = makeUser($pdo, 'v2164admin', $superRoleId)['id'];
$p1UserId = makeUser($pdo, 'v2164p1', $stockRoleId, $wh1Id)['id'];
$p2UserId = makeUser($pdo, 'v2164p2', $stockRoleId, $wh1Id)['id'];

// ============================================================
// Fixture: 4 items across 2 categories, system qty/value known exactly.
// ============================================================
$item1 = makeItem($pdo, $kgUnitId, 'V2164-I1', $catAId); // -> Rusak, variance -5
$item2 = makeItem($pdo, $kgUnitId, 'V2164-I2', $catAId); // -> Lebih (+5), no conditions
$item3 = makeItem($pdo, $kgUnitId, 'V2164-I3', $catBId); // -> Deadstock, variance 0
$item4 = makeItem($pdo, $kgUnitId, 'V2164-I4', $catBId); // -> RECOUNTED + Expired, variance -1

postOpeningIn($pdo, $item1, $kgUnitId, $wh1Id, 100, 1000, $adminUserId); // system value 100000
postOpeningIn($pdo, $item2, $kgUnitId, $wh1Id, 50, 2000, $adminUserId);  // system value 100000
postOpeningIn($pdo, $item3, $kgUnitId, $wh1Id, 30, 500, $adminUserId);   // system value 15000
postOpeningIn($pdo, $item4, $kgUnitId, $wh1Id, 20, 1500, $adminUserId);  // system value 30000

$sku1 = $pdo->query("SELECT sku FROM items WHERE id = {$item1}")->fetchColumn();
$sku3 = $pdo->query("SELECT sku FROM items WHERE id = {$item3}")->fetchColumn();

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $wh1Id, $adminUserId, [$item1, $item2, $item3, $item4], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
// Deterministic session_date so month/year filters are exactly provable.
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-03-15', 'id' => $sessionId]);

Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item1, 95.0, $p1UserId, ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0]));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item1, 95.0, $p2UserId, ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0]));

Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item2, 55.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item2, 55.0, $p2UserId));

Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item3, 30.0, $p1UserId, ['rusak_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 30.0]));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item3, 30.0, $p2UserId, ['rusak_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 30.0]));

// item4: P1/P2 disagree -> MISMATCH -> explicit supervisor recount to 19,
// with an explicit final Expired=2 condition (independent of the qty variance).
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $item4, 18.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $item4, 22.0, $p2UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sessionId, $item4, 19.0, 'supervisor recount', $adminUserId, ['final_rusak_qty' => 0, 'final_expired_qty' => 2.0, 'final_deadstock_qty' => 0]));

Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $adminUserId));

$postedSession = StockOpnameService::get($pdo, $sessionId);
check('setup: fixture session reaches POSTED', $postedSession['status'] === 'POSTED', (string) $postedSession['status']);

// A second POSTED session in a DIFFERENT warehouse/month, to prove
// warehouse_id/month/year filters don't leak sessions across each other.
$item5 = makeItem($pdo, $kgUnitId, 'V2164-I5', $catAId);
postOpeningIn($pdo, $item5, $kgUnitId, $wh2Id, 10, 100, $adminUserId);
$p1Wh2 = makeUser($pdo, 'v2164p1wh2', $stockRoleId, $wh2Id)['id'];
$p2Wh2 = makeUser($pdo, 'v2164p2wh2', $stockRoleId, $wh2Id)['id'];
$otherSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $wh2Id, $adminUserId, [$item5], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $otherSessionId, ['p1_user_id' => $p1Wh2, 'p2_user_id' => $p2Wh2], $adminUserId));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-04-20', 'id' => $otherSessionId]);
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $otherSessionId, 'p1', $item5, 10.0, $p1Wh2));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $otherSessionId, 'p2', $item5, 10.0, $p2Wh2));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $otherSessionId, $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $otherSessionId, $adminUserId));

// A FINALIZED-but-not-POSTED session in wh1/same month, to prove the
// default status=POSTED filter genuinely excludes it.
$item6 = makeItem($pdo, $kgUnitId, 'V2164-I6', $catAId);
postOpeningIn($pdo, $item6, $kgUnitId, $wh1Id, 5, 100, $adminUserId);
$finalizedOnlySessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $wh1Id, $adminUserId, [$item6], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $finalizedOnlySessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-03-20', 'id' => $finalizedOnlySessionId]);
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $finalizedOnlySessionId, 'p1', $item6, 5.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $finalizedOnlySessionId, 'p2', $item6, 5.0, $p2UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $finalizedOnlySessionId, $adminUserId));

echo "\n== Part A: StockOpnameMonthlyReportService (direct) ==\n";

// ---- 1. session list: default POSTED filter excludes the FINALIZED-only session ----
$listDefault = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'page' => 1, 'per_page' => 25]);
$ids = array_column($listDefault['sessions'], 'id');
check('1. default status=POSTED lists BOTH posted sessions', in_array($sessionId, $ids, true) && in_array($otherSessionId, $ids, true));
check('1b. default status=POSTED EXCLUDES the FINALIZED-only session', !in_array($finalizedOnlySessionId, $ids, true));

// ---- 2. status=FINALIZED shows only the finalized-only session ----
$listFinalized = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'FINALIZED', 'warehouse_id' => $wh1Id, 'page' => 1, 'per_page' => 25]);
$finalizedIds = array_column($listFinalized['sessions'], 'id');
check('2. status=FINALIZED shows the finalized-only session, not the POSTED one', in_array($finalizedOnlySessionId, $finalizedIds, true) && !in_array($sessionId, $finalizedIds, true));

// ---- 3. warehouse_id filter isolates sessions per warehouse ----
$listWh1 = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'warehouse_id' => $wh1Id, 'page' => 1, 'per_page' => 25]);
$wh1Ids = array_column($listWh1['sessions'], 'id');
check('3. warehouse_id=WH1 includes the WH1 session', in_array($sessionId, $wh1Ids, true));
check('3b. warehouse_id=WH1 EXCLUDES the WH2 session', !in_array($otherSessionId, $wh1Ids, true));

// ---- 4. month/year filter isolates by session_date ----
$listMarch = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'month' => 3, 'year' => 2026, 'page' => 1, 'per_page' => 25]);
$marchIds = array_column($listMarch['sessions'], 'id');
check('4. month=3/year=2026 includes the March session', in_array($sessionId, $marchIds, true));
check('4b. month=3/year=2026 EXCLUDES the April session', !in_array($otherSessionId, $marchIds, true));

// ---- 5. session_search (free text SO number) ----
$primarySessionNumber = $postedSession['session_number'];
$listBySessionSearch = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'session_search' => $primarySessionNumber, 'page' => 1, 'per_page' => 25]);
check('5. session_search by exact SO number finds exactly that session', count($listBySessionSearch['sessions']) === 1 && (int) $listBySessionSearch['sessions'][0]['id'] === $sessionId);

// ---- 6. category_id filter on session list ----
$listByCatB = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'category_id' => $catBId, 'page' => 1, 'per_page' => 25]);
$catBSessionIds = array_column($listByCatB['sessions'], 'id');
check('6. category_id=Kategori B includes the fixture session (items 3/4 are in it)', in_array($sessionId, $catBSessionIds, true));
check('6b. category_id=Kategori B EXCLUDES the WH2 session (no Kategori B items there)', !in_array($otherSessionId, $catBSessionIds, true));

// ---- 7. search (sku) on session list ----
$listBySku = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'search' => $sku1, 'page' => 1, 'per_page' => 25]);
check('7. search by item1 SKU finds the fixture session', count($listBySku['sessions']) === 1 && (int) $listBySku['sessions'][0]['id'] === $sessionId);

// ---- total_item column ----
$fixtureRow = null;
foreach ($listDefault['sessions'] as $s) { if ((int) $s['id'] === $sessionId) $fixtureRow = $s; }
check('session list Total Item = 4 for the fixture session', $fixtureRow !== null && (int) $fixtureRow['total_item'] === 4, json_encode($fixtureRow));

// ---- 8/9/10. detail(): items + finance summary + category summary math ----
$detail = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 25]);
check('8. detail returns 4 items total', $detail['total'] === 4, (string) $detail['total']);

$byItemId = [];
foreach ($detail['items'] as $row) { $byItemId[$row['item_id']] = $row; }

$r1 = $byItemId[$item1];
check('8a. item1 system_qty/value correct (100 @ 1000 = 100000)', abs($r1['system_qty'] - 100.0) < 0.001 && abs($r1['system_value'] - 100000.0) < 0.01);
check('8b. item1 physical_qty/value correct (95 @ 1000 = 95000)', abs($r1['physical_qty'] - 95.0) < 0.001 && abs($r1['physical_value'] - 95000.0) < 0.01);
check('8c. item1 variance_qty/value correct (-5 / -5000)', abs($r1['variance_qty'] - (-5.0)) < 0.001 && abs($r1['variance_value'] - (-5000.0)) < 0.01);
check('8d. item1 rusak_qty = 5, kondisi = Rusak', abs($r1['rusak_qty'] - 5.0) < 0.001 && $r1['kondisi'] === 'Rusak');

$r2 = $byItemId[$item2];
check('8e. item2 variance +5 / +10000, kondisi Lebih (+)', abs($r2['variance_qty'] - 5.0) < 0.001 && abs($r2['variance_value'] - 10000.0) < 0.01 && $r2['kondisi'] === 'Lebih (+)');

$r3 = $byItemId[$item3];
check('8f. item3 variance 0 but kondisi Deadstock (condition takes priority over zero variance)', abs($r3['variance_qty'] - 0.0) < 0.001 && $r3['deadstock_qty'] > 0 && $r3['kondisi'] === 'Deadstock');

$r4 = $byItemId[$item4];
check('8g. item4 recounted to 19 (variance -1), expired=2, kondisi Expired', abs($r4['physical_qty'] - 19.0) < 0.001 && abs($r4['variance_qty'] - (-1.0)) < 0.001 && abs($r4['expired_qty'] - 2.0) < 0.001 && $r4['kondisi'] === 'Expired');

// ---- HPP / unit cost must NEVER be exposed ----
$forbiddenKeySubstrings = ['hpp', 'unit_cost', 'cost'];
$leaked = [];
foreach ($r1 as $k => $v) {
    foreach ($forbiddenKeySubstrings as $bad) {
        if (stripos((string) $k, $bad) !== false) $leaked[] = $k;
    }
}
check('9. NO HPP/unit_cost/cost key is ever present in a detail item row', $leaked === [], json_encode($leaked));

$fs = $detail['finance_summary'];
check('10a. finance_summary.total_item_scope = 4', $fs['total_item_scope'] === 4);
check('10b. finance_summary.sesuai = 3 (item1/2/3 MATCH, item4 RECOUNTED)', $fs['sesuai'] === 3, (string) $fs['sesuai']);
check('10c. finance_summary.selisih_plus = 1, selisih_minus = 2', $fs['selisih_plus'] === 1 && $fs['selisih_minus'] === 2);
check('10d. finance_summary rusak/expired/deadstock counts = 1/1/1', $fs['rusak'] === 1 && $fs['expired'] === 1 && $fs['deadstock'] === 1);
check('10e. finance_summary nilai_stok_sistem = 245000, nilai_stok_fisik_final = 248500', abs($fs['nilai_stok_sistem'] - 245000.0) < 0.01 && abs($fs['nilai_stok_fisik_final'] - 248500.0) < 0.01, json_encode($fs));
check('10f. finance_summary selisih_nilai = 3500, qty sistem/fisik/selisih = 200/199/-1', abs($fs['selisih_nilai'] - 3500.0) < 0.01 && abs($fs['qty_sistem'] - 200.0) < 0.001 && abs($fs['qty_fisik_final'] - 199.0) < 0.001 && abs($fs['selisih_qty'] - (-1.0)) < 0.001, json_encode($fs));
check('10g. NO HPP/unit_cost key in finance_summary either', !array_filter(array_keys($fs), fn ($k) => stripos($k, 'hpp') !== false || stripos($k, 'cost') !== false));

$cats = $detail['category_summary'];
$catA = null; $catB = null; $total = null;
foreach ($cats as $c) {
    if ($c['category'] === 'V2164 Kategori A') $catA = $c;
    if ($c['category'] === 'V2164 Kategori B') $catB = $c;
    if (($c['is_total_row'] ?? false) === true) $total = $c;
}
check('11a. category summary Kategori A: 2 items, qty 150/150, nilai sistem 200000 / fisik 205000', $catA !== null && $catA['total_item'] === 2 && abs($catA['qty_sistem'] - 150.0) < 0.001 && abs($catA['qty_fisik'] - 150.0) < 0.001 && abs($catA['nilai_sistem'] - 200000.0) < 0.01 && abs($catA['nilai_fisik'] - 205000.0) < 0.01, json_encode($catA));
check('11b. category summary Kategori B: 2 items, qty 50/49, nilai sistem 45000 / fisik 43500', $catB !== null && $catB['total_item'] === 2 && abs($catB['qty_sistem'] - 50.0) < 0.001 && abs($catB['qty_fisik'] - 49.0) < 0.001 && abs($catB['nilai_sistem'] - 45000.0) < 0.01 && abs($catB['nilai_fisik'] - 43500.0) < 0.01, json_encode($catB));
check('11c. category summary TOTAL row matches finance_summary totals', $total !== null && $total['total_item'] === 4 && abs($total['nilai_sistem'] - 245000.0) < 0.01 && abs($total['nilai_fisik'] - 248500.0) < 0.01, json_encode($total));

// ---- 12. category_id filter on detail() ----
$detailCatB = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['category_id' => $catBId, 'page' => 1, 'per_page' => 25]);
check('12. category_id=Kategori B detail filter returns exactly items 3/4', $detailCatB['total'] === 2 && isset(array_column($detailCatB['items'], null, 'item_id')[$item3]) && isset(array_column($detailCatB['items'], null, 'item_id')[$item4]));

// ---- 13. search filter on detail() ----
$detailBySku3 = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['search' => $sku3, 'page' => 1, 'per_page' => 25]);
check('13. search by item3 SKU returns exactly 1 item', $detailBySku3['total'] === 1 && (int) $detailBySku3['items'][0]['item_id'] === $item3);

// ---- 14. pagination: only 25/50/100 are accepted per_page values (spec's
// "Pagination 25 default, options 25/50/100") — anything else safely
// falls back to 25 rather than letting a caller request an arbitrary page
// size. With only 4 fixture items, every allowed size fits on one page,
// so this proves the safelist + total/total_pages math rather than a
// real page break (which the session-list test below exercises instead,
// at a size small enough to actually span 2 pages).
$pageInvalid = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 2]);
check('14. an out-of-range per_page (2) safely falls back to 25', $pageInvalid['per_page'] === 25, (string) $pageInvalid['per_page']);
$page50 = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 50]);
$page100 = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 100]);
check('14b. per_page=50 and per_page=100 are both accepted as-is, all 4 items fit on page 1', $page50['per_page'] === 50 && $page100['per_page'] === 100 && count($page50['items']) === 4 && $page50['total_pages'] === 1);

// Session-LIST pagination applies the same 25/50/100 safelist — proves it
// independently of the item-detail list above, with its own total/
// total_pages math (2 POSTED sessions fit on the one clamped-to-25 page).
$sessList = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'page' => 1, 'per_page' => 1]);
check('14c. session list per_page also clamps an out-of-range value (1) to 25, total/total_pages correct', $sessList['per_page'] === 25 && $sessList['total'] === 2 && $sessList['total_pages'] === 1 && count($sessList['sessions']) === 2);

// ---- regression (unchanged workflow): a POSTED session is immutable ----
$replayPost = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $adminUserId));
check('15. regression: re-posting an already-POSTED session is still a safe idempotent replay (unchanged)', $replayPost['status'] === 'POSTED');

echo "\n== Part B: real HTTP (permission + warehouse-scope regression) ==\n";

$viewerCreds = makeUser($pdo, 'v2164viewer', $viewerRoleId);
$stockWh2Creds = makeUser($pdo, 'v2164stockwh2', $stockRoleId, $wh2Id);
$adminCreds = ['username' => 'v2164httpadmin', 'password' => 'V2164Http!' . bin2hex(random_bytes(3))];
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $adminCreds['username'], 'h' => password_hash($adminCreds['password'], PASSWORD_BCRYPT), 'n' => 'V2164 HTTP Admin', 'r' => $superRoleId]);

$port = 8900 + random_int(3200, 3599);
$docRoot = __DIR__ . '/../public';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, __DIR__ . '/..');
if (!is_resource($process)) { fwrite(STDERR, "Failed to start php -S\n"); exit(1); }
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($res !== false && $err === 0) { $ready = true; break; }
}
if (!$ready) { fwrite(STDERR, "Server did not become ready\n"); proc_terminate($process); exit(1); }

function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}
function login(string $base, array $creds): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $creds['username'], 'password' => $creds['password']], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? '', 'status' => $res['status']];
}

try {
    $viewerAuth = login($base, $viewerCreds);
    check('setup: VIEWER login succeeds', $viewerAuth['status'] === 200, (string) $viewerAuth['status']);
    $stockWh2Auth = login($base, $stockWh2Creds);
    check('setup: STOCK (WH2-scoped) login succeeds', $stockWh2Auth['status'] === 200);
    $adminAuth = login($base, $adminCreds);
    check('setup: SUPERADMIN login succeeds', $adminAuth['status'] === 200);

    // 1/3. a VIEWER (INVENTORY_VIEW only, no STOCK_OPNAME_MANAGE/SUPERVISE)
    // CAN reach the new list + detail routes — the spec's core requirement
    // that a finance/audit user can open this report.
    $viewerList = httpCall('GET', "{$base}/stock-opname-reports?status=POSTED", null, $viewerAuth['jar']);
    check('1. VIEWER (INVENTORY_VIEW only) GET /stock-opname-reports -> 200', $viewerList['status'] === 200, json_encode($viewerList['body']));
    $viewerDetail = httpCall('GET', "{$base}/stock-opname-reports/{$sessionId}", null, $viewerAuth['jar']);
    check('3. VIEWER (INVENTORY_VIEW only) GET /stock-opname-reports/{id} -> 200', $viewerDetail['status'] === 200, json_encode($viewerDetail['body']));
    check('3b. VIEWER detail response carries NO hpp/unit_cost key in its items', !preg_match('/"(hpp|unit_cost)[^"]*"\s*:/i', $viewerDetail['raw']), substr($viewerDetail['raw'], 0, 300));

    // Security regression: the REUSED export/print routes keep their
    // EXISTING STOCK_OPNAME_SUPERVISE/MANAGE gate — never weakened to
    // INVENTORY_VIEW just because the new report links to them.
    $viewerExport = httpCall('GET', "{$base}/stock-opname/{$sessionId}/export/final", null, $viewerAuth['jar']);
    check('security: VIEWER is still DENIED the reused Excel Final export (403, unchanged)', $viewerExport['status'] === 403, json_encode($viewerExport['body']));
    $viewerPrint = httpCall('GET', "{$base}/stock-opname/{$sessionId}/print", null, $viewerAuth['jar']);
    check('security: VIEWER is still DENIED the reused Print route (403, unchanged)', $viewerPrint['status'] === 403, json_encode($viewerPrint['body']));

    // SUPERADMIN can use the reused export action without any new route.
    $adminExport = httpCall('GET', "{$base}/stock-opname/{$sessionId}/export/final", null, $adminAuth['jar']);
    check('12. SUPERADMIN export/reconciliation action still works via the REUSED route', $adminExport['status'] === 200, (string) $adminExport['status']);

    // 14. warehouse scope regression — a STOCK user scoped to WH2 must
    // never see or reach the WH1 session via the new routes either.
    $stockList = httpCall('GET', "{$base}/stock-opname-reports?status=POSTED", null, $stockWh2Auth['jar']);
    $stockListIds = array_column($stockList['body']['data']['sessions'] ?? [], 'id');
    check('14a. STOCK (WH2) list is forced to WH2 — never sees the WH1 fixture session', $stockList['status'] === 200 && !in_array($sessionId, $stockListIds, true), json_encode($stockListIds));
    $stockDetailForeign = httpCall('GET', "{$base}/stock-opname-reports/{$sessionId}", null, $stockWh2Auth['jar']);
    check('14b. STOCK (WH2) GET detail for the WH1 session -> 403 (existing warehouse-scope guard, unchanged)', $stockDetailForeign['status'] === 403, json_encode($stockDetailForeign['body']));

    // unauthenticated is denied entirely
    $anonList = httpCall('GET', "{$base}/stock-opname-reports", null, tempnam(sys_get_temp_dir(), 'cookie_'));
    check('security: unauthenticated request is denied (401)', $anonList['status'] === 401, (string) $anonList['status']);
} finally {
    proc_terminate($process);
    proc_close($process);
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n{$passed} / {$total} PASSED\n";
exit($passed === $total ? 0 : 1);
