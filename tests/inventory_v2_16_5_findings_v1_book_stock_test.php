<?php
declare(strict_types=1);

/**
 * PHASE V2.16.5 — CORRECTIVE: proves StockOpnameMonthlyReportService
 * reads the authoritative EOD BOOK STOCK (StockOpnameBookStockService::
 * reconciliation()) for a FINDINGS_V1 session, NEVER system_qty_base/
 * variance_qty_base (which finalize()/post() never populate for this
 * counting_model in this codebase — see assertNotFindingsV1() /
 * FindingsCheckpointBRequiredException).
 *
 * Checkpoint B (the production reconciliation/final-result workflow that
 * actually POSTS a FINDINGS_V1 session) is explicitly NOT implemented in
 * this DEV branch, so this fixture seeds a POSTED FINDINGS_V1 session
 * DIRECTLY in the disposable test DB — exactly as the corrective
 * instructions authorize ("You may seed a POSTED FINDINGS_V1 fixture
 * directly in the DISPOSABLE test DB for report testing only... does NOT
 * need to bypass or weaken the Checkpoint B production guard"). Every
 * other step (start, team assignment, baseline import, claim, finding
 * submission, exclusion) goes through the REAL, unmodified
 * StockOpnameService/StockOpnameBookStockService/StockOpnamePhotoService
 * methods — only the final status flip to POSTED is a direct UPDATE,
 * since no code path to reach it exists yet.
 *
 * Usage: php tests/inventory_v2_16_5_findings_v1_book_stock_test.php
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
require_once __DIR__ . '/../services/StockOpnamePhotoService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/StockOpnameReferenceImportService.php';
require_once __DIR__ . '/../services/StockOpnameBookStockService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnamePhotoService;
use App\Services\StockOpnameBookStockService;
use App\Services\StockOpnameMonthlyReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function writeCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'v2165_') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    return $path;
}
function makeFakePhotoFile(): string
{
    $img = imagecreatetruecolor(6, 6);
    imagefill($img, 0, 0, imagecolorallocate($img, 40, 90, 200));
    $path = tempnam(sys_get_temp_dir(), 'v2165photo') . '.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);
    return $path;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2165WH', 'V2.16.5 FINDINGS_V1 WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V2165-CATA'), 'n' => 'V2165 Kategori A']);
$catAId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V2165-CATB'), 'n' => 'V2165 Kategori B']);
$catBId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
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
        'transaction_uuid' => uid('v2165-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $by, 'username' => 'v2165', 'transaction_type' => 'OPENING',
    ]));
}

$adminId = makeUser($pdo, 'v2165admin', $superRoleId);
$p1Id = makeUser($pdo, 'v2165p1', $superRoleId);
$p2Id = makeUser($pdo, 'v2165p2', $superRoleId);

// Book stock (baseline) qty vs opening stock (FIFO, feeding unit_cost_base
// and the deliberately-WRONG system_qty_base below) are set INDEPENDENTLY
// per item, specifically so the test can prove the report never falls
// back to system_qty_base.
$itemPos = makeItem($pdo, $kgUnitId, 'V2165-POS', $catAId);       // book 50, physical 55 -> +5
$itemNeg = makeItem($pdo, $kgUnitId, 'V2165-NEG', $catAId);       // book 100, physical 95 -> -5 (the literal example from the corrective instructions)
$itemZero = makeItem($pdo, $kgUnitId, 'V2165-ZERO', $catAId);     // book 30, physical 30 -> 0, Sesuai
$itemExcluded = makeItem($pdo, $kgUnitId, 'V2165-EXCL', $catAId); // book 10, excluded, no finding at all
$itemRusak = makeItem($pdo, $kgUnitId, 'V2165-RUSAK', $catBId);   // book 40, physical 40 (38 GOOD + 2 Rusak) -> 0 but Kondisi=Rusak
$itemExpired = makeItem($pdo, $kgUnitId, 'V2165-EXP', $catBId);   // book 25, physical 25 (20 GOOD + 5 Expired) -> 0 but Kondisi=Expired
$itemDeadstock = makeItem($pdo, $kgUnitId, 'V2165-DEAD', $catBId); // book 15, physical 15 (10 GOOD + 5 Deadstock) -> 0 but Kondisi=Deadstock

postOpeningIn($pdo, $itemPos, $kgUnitId, $whId, 999, 100, $adminId);
postOpeningIn($pdo, $itemNeg, $kgUnitId, $whId, 999, 1000, $adminId);
postOpeningIn($pdo, $itemZero, $kgUnitId, $whId, 999, 500, $adminId);
postOpeningIn($pdo, $itemExcluded, $kgUnitId, $whId, 999, 200, $adminId);
postOpeningIn($pdo, $itemRusak, $kgUnitId, $whId, 999, 300, $adminId);
postOpeningIn($pdo, $itemExpired, $kgUnitId, $whId, 999, 400, $adminId);
postOpeningIn($pdo, $itemDeadstock, $kgUnitId, $whId, 999, 600, $adminId);

$allItemIds = [$itemPos, $itemNeg, $itemZero, $itemExcluded, $itemRusak, $itemExpired, $itemDeadstock];
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminId, $allItemIds, 'FINDINGS_V1'));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $sessionId]);

// Deliberately WRONG system_qty_base on the headline example, proving the
// report cannot be reading this column: a session-START snapshot of 999
// (the real FIFO balance) is explicitly overwritten to 0 here, so if the
// report were (incorrectly) reading system_qty_base, it would show 0 /
// +95, not the correct 100 / -5 from the book-stock reconciliation.
$pdo->prepare('UPDATE stock_opname_lines SET system_qty_base = 0 WHERE session_id = :sid AND item_id = :item')
    ->execute(['sid' => $sessionId, 'item' => $itemNeg]);
// Same treatment on every other line, so NONE of them could coincidentally
// "pass" by accident via the old (wrong) system_qty_base source either.
foreach ([$itemPos, $itemZero, $itemExcluded, $itemRusak, $itemExpired, $itemDeadstock] as $it) {
    $pdo->prepare('UPDATE stock_opname_lines SET system_qty_base = 0 WHERE session_id = :sid AND item_id = :item')
        ->execute(['sid' => $sessionId, 'item' => $it]);
}

Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p1', [$p1Id], $adminId));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p2', [$p2Id], $adminId));

// ---- Baseline import: the authoritative EOD BOOK STOCK for every item ----
$skuPos = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemPos}")->fetchColumn();
$skuNeg = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemNeg}")->fetchColumn();
$skuZero = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemZero}")->fetchColumn();
$skuExcluded = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemExcluded}")->fetchColumn();
$skuRusak = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemRusak}")->fetchColumn();
$skuExpired = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemExpired}")->fetchColumn();
$skuDeadstock = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemDeadstock}")->fetchColumn();

$baselineFile = writeCsv([
    ['Kode Barang', 'Nama Barang', 'Satuan', 'Stok Akhir'],
    [$skuPos, 'Item Pos', 'KG', '50'],
    [$skuNeg, 'Item Neg', 'KG', '100'],
    [$skuZero, 'Item Zero', 'KG', '30'],
    [$skuExcluded, 'Item Excluded', 'KG', '10'],
    [$skuRusak, 'Item Rusak', 'KG', '40'],
    [$skuExpired, 'Item Expired', 'KG', '25'],
    [$skuDeadstock, 'Item Deadstock', 'KG', '15'],
]);
$coverage = ['inout_through' => '2026-09-29 23:59:59', 'scaling_through' => '2026-09-29 23:59:59', 'adjustment_through' => '2026-09-29 23:59:59'];
$baselineResult = Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $sessionId, $baselineFile, 'baseline.csv', $adminId, $coverage));
check('setup: baseline import matches all 7 items', $baselineResult['matched_count'] === 7, (string) $baselineResult['matched_count']);

function submitGoodOnly(PDO $pdo, int $sessionId, string $role, int $itemId, int $userId, int $unitId, float $goodQty): void
{
    $claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, $role, $userId, $itemId));
    $conditions = [
        'GOOD' => [['unit_id' => $unitId, 'qty' => $goodQty]],
        'DAMAGED' => [['unit_id' => $unitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $unitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $unitId, 'qty' => 0]],
    ];
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionId, $role, $itemId, $conditions, null, $userId, $claim['claim_token'], [], '2026-09-29 12:00:00'));
}
function submitWithCondition(PDO $pdo, int $sessionId, string $role, int $itemId, int $userId, int $unitId, float $goodQty, string $conditionType, float $conditionQty): void
{
    $claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, $role, $userId, $itemId));
    $photoFile = makeFakePhotoFile();
    $photoUpload = StockOpnamePhotoService::upload($pdo, $sessionId, $role, $itemId, $conditionType, $userId, $claim['claim_token'], $photoFile);
    $conditions = [
        'GOOD' => [['unit_id' => $unitId, 'qty' => $goodQty]],
        'DAMAGED' => [['unit_id' => $unitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $unitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $unitId, 'qty' => 0]],
    ];
    $conditions[$conditionType] = [['unit_id' => $unitId, 'qty' => $conditionQty]];
    $photos = ['DAMAGED' => [], 'EXPIRED' => [], 'DEADSTOCK' => []];
    $photos[$conditionType] = [$photoUpload['token']];
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionId, $role, $itemId, $conditions, null, $userId, $claim['claim_token'], $photos, '2026-09-29 12:00:00'));
}

// Single-team (P1 only) findings — the realistic "admin masih melakukan
// input hasil SO" mid-session shape physicalEodForLine() is built to
// handle directly off the one active side's raw total.
submitGoodOnly($pdo, $sessionId, 'p1', $itemPos, $p1Id, $kgUnitId, 55.0);
submitGoodOnly($pdo, $sessionId, 'p1', $itemNeg, $p1Id, $kgUnitId, 95.0);
submitGoodOnly($pdo, $sessionId, 'p1', $itemZero, $p1Id, $kgUnitId, 30.0);

// Two-team AGREEING findings — both sides submit so counted_qty_base/
// final_{rusak,expired,deadstock}_qty actually resolve (same agreement
// mechanism LEGACY_DUAL_COUNT uses), proving the condition columns and
// the book-stock variance work together correctly.
submitWithCondition($pdo, $sessionId, 'p1', $itemRusak, $p1Id, $kgUnitId, 38.0, 'DAMAGED', 2.0);
submitWithCondition($pdo, $sessionId, 'p2', $itemRusak, $p2Id, $kgUnitId, 38.0, 'DAMAGED', 2.0);
submitWithCondition($pdo, $sessionId, 'p1', $itemExpired, $p1Id, $kgUnitId, 20.0, 'EXPIRED', 5.0);
submitWithCondition($pdo, $sessionId, 'p2', $itemExpired, $p2Id, $kgUnitId, 20.0, 'EXPIRED', 5.0);
submitWithCondition($pdo, $sessionId, 'p1', $itemDeadstock, $p1Id, $kgUnitId, 10.0, 'DEADSTOCK', 5.0);
submitWithCondition($pdo, $sessionId, 'p2', $itemDeadstock, $p2Id, $kgUnitId, 10.0, 'DEADSTOCK', 5.0);

Database::transaction(fn (PDO $tx) => StockOpnameService::excludeUncounted($tx, $sessionId, $itemExcluded, 'test fixture — never physically counted', $adminId));

// ---- Checkpoint B does not exist in this DEV branch yet (see this file's
// own docblock) — this is the ONLY direct-SQL step, explicitly authorized
// for report-testing purposes only. It does not call, weaken, or bypass
// assertNotFindingsV1()/FindingsCheckpointBRequiredException; it simply
// writes the status column a future Checkpoint B implementation would.
$pdo->prepare("UPDATE stock_opname_sessions SET status = 'POSTED', posted_by = :by, posted_at = :at WHERE id = :id")
    ->execute(['by' => $adminId, 'at' => '2026-10-01 09:00:00', 'id' => $sessionId]);

$postedCheck = $pdo->prepare('SELECT status, counting_model FROM stock_opname_sessions WHERE id = :id');
$postedCheck->execute(['id' => $sessionId]);
$postedCheck = $postedCheck->fetch();
check('setup: fixture session is POSTED and still FINDINGS_V1', $postedCheck['status'] === 'POSTED' && $postedCheck['counting_model'] === 'FINDINGS_V1');

// ============================================================
// Direct sanity check: StockOpnameBookStockService::reconciliation()
// itself (the authoritative source) returns what the test expects, BEFORE
// asking the report service to use it — isolates a report-service bug
// from a fixture-setup bug.
// ============================================================
$reconRows = StockOpnameBookStockService::reconciliation($pdo, $sessionId);
$reconBySku = [];
foreach ($reconRows as $r) {
    $reconBySku[$r['sku']] = $r;
}
check('setup: reconciliation() itself reports itemNeg book_stock_eod=100, physical_eod=95, variance=-5', abs($reconBySku[$skuNeg]['book_stock_eod'] - 100.0) < 0.001 && abs($reconBySku[$skuNeg]['final_physical_eod'] - 95.0) < 0.001 && abs($reconBySku[$skuNeg]['variance'] - (-5.0)) < 0.001, json_encode($reconBySku[$skuNeg]));

echo "\n== StockOpnameMonthlyReportService over a POSTED FINDINGS_V1 session ==\n";

$list = StockOpnameMonthlyReportService::listSessions($pdo, ['status' => 'POSTED', 'page' => 1, 'per_page' => 25]);
$listIds = array_column($list['sessions'], 'id');
check('1. listSessions finds the POSTED FINDINGS_V1 session under the default status=POSTED filter', in_array($sessionId, $listIds, true));

$detail = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 25]);
check('2. session.counting_model = FINDINGS_V1, stock_source_label names the EOD reconciliation', $detail['session']['counting_model'] === 'FINDINGS_V1' && str_contains($detail['session']['stock_source_label'], 'EOD'), json_encode($detail['session']));
check('3. detail returns all 7 items', $detail['total'] === 7, (string) $detail['total']);

$byItemId = [];
foreach ($detail['items'] as $row) { $byItemId[$row['item_id']] = $row; }

// ---- THE headline proof: system_qty_base=0 must NEVER leak through ----
$neg = $byItemId[$itemNeg];
check('4. [CRITICAL] itemNeg: Stok Sistem = 100 (book stock), NOT 0 (system_qty_base)', abs($neg['system_qty'] - 100.0) < 0.001, json_encode($neg));
check('4b. [CRITICAL] itemNeg: Stok Fisik Final = 95', abs($neg['physical_qty'] - 95.0) < 0.001, json_encode($neg));
check('4c. [CRITICAL] itemNeg: Selisih = -5, NOT +95', abs($neg['variance_qty'] - (-5.0)) < 0.001, json_encode($neg));
check('4d. itemNeg: Selisih Nilai = -5 * 1000 = -5000, Kondisi = Kurang (-)', abs($neg['variance_value'] - (-5000.0)) < 0.01 && $neg['kondisi'] === 'Kurang (-)', json_encode($neg));

$pos = $byItemId[$itemPos];
check('5. itemPos: book 50 / physical 55 / variance +5, Nilai Selisih +500, Kondisi Lebih (+)', abs($pos['system_qty'] - 50.0) < 0.001 && abs($pos['physical_qty'] - 55.0) < 0.001 && abs($pos['variance_qty'] - 5.0) < 0.001 && abs($pos['variance_value'] - 500.0) < 0.01 && $pos['kondisi'] === 'Lebih (+)', json_encode($pos));

$zero = $byItemId[$itemZero];
check('6. itemZero: book 30 / physical 30 / variance 0, Kondisi Sesuai', abs($zero['variance_qty']) < 0.001 && $zero['kondisi'] === 'Sesuai', json_encode($zero));

$excluded = $byItemId[$itemExcluded];
check('7. itemExcluded: Kondisi Dikecualikan regardless of its (unresolved) variance', $excluded['kondisi'] === 'Dikecualikan', json_encode($excluded));

$rusak = $byItemId[$itemRusak];
check('8. itemRusak: book 40 / physical 40 (38 Good + 2 Rusak, P1=P2 agree), variance 0, rusak_qty=2, Kondisi Rusak (condition beats zero variance)', abs($rusak['system_qty'] - 40.0) < 0.001 && abs($rusak['physical_qty'] - 40.0) < 0.001 && abs($rusak['variance_qty']) < 0.001 && abs($rusak['rusak_qty'] - 2.0) < 0.001 && $rusak['kondisi'] === 'Rusak', json_encode($rusak));

$expired = $byItemId[$itemExpired];
check('9. itemExpired: book 25 / physical 25 (20 Good + 5 Expired), variance 0, expired_qty=5, Kondisi Expired', abs($expired['system_qty'] - 25.0) < 0.001 && abs($expired['physical_qty'] - 25.0) < 0.001 && abs($expired['expired_qty'] - 5.0) < 0.001 && $expired['kondisi'] === 'Expired', json_encode($expired));

$deadstock = $byItemId[$itemDeadstock];
check('10. itemDeadstock: book 15 / physical 15 (10 Good + 5 Deadstock), variance 0, deadstock_qty=5, Kondisi Deadstock', abs($deadstock['system_qty'] - 15.0) < 0.001 && abs($deadstock['physical_qty'] - 15.0) < 0.001 && abs($deadstock['deadstock_qty'] - 5.0) < 0.001 && $deadstock['kondisi'] === 'Deadstock', json_encode($deadstock));

// ---- HPP / unit_cost absence, same check as the LEGACY test ----
$leaked = [];
foreach ($neg as $k => $v) {
    foreach (['hpp', 'unit_cost', 'cost'] as $bad) {
        if (stripos((string) $k, $bad) !== false) $leaked[] = $k;
    }
}
check('11. NO HPP/unit_cost/cost key in a FINDINGS_V1 detail item row either', $leaked === [], json_encode($leaked));

// ---- Finance summary: authoritative totals, Sesuai driven by variance ----
$fs = $detail['finance_summary'];
// Values (unit_cost_base is the FIFO price each item opened at):
// itemPos  50@100=5000 sys / 55@100=5500 phys
// itemNeg  100@1000=100000 sys / 95@1000=95000 phys
// itemZero 30@500=15000 sys / 30@500=15000 phys
// itemExcluded 10@200=2000 sys / physical null (never counted) -> excluded from physical/variance totals, included in system total
// itemRusak 40@300=12000 sys / 40@300=12000 phys
// itemExpired 25@400=10000 sys / 25@400=10000 phys
// itemDeadstock 15@600=9000 sys / 15@600=9000 phys
$expectedNilaiSistem = 5000 + 100000 + 15000 + 2000 + 12000 + 10000 + 9000;
$expectedNilaiFisik = 5500 + 95000 + 15000 + 12000 + 10000 + 9000; // itemExcluded contributes 0 (never counted)
check('12. finance_summary.total_item_scope = 7', $fs['total_item_scope'] === 7);
check('12b. finance_summary.nilai_stok_sistem includes the EXCLUDED item\'s book value too (same treatment reconciliation() itself gives it)', abs($fs['nilai_stok_sistem'] - $expectedNilaiSistem) < 0.01, json_encode($fs));
check('12c. finance_summary.nilai_stok_fisik_final excludes the never-counted EXCLUDED item (null physical, not fabricated 0)', abs($fs['nilai_stok_fisik_final'] - $expectedNilaiFisik) < 0.01, json_encode($fs));
check('13. finance_summary.sesuai = 4 (itemZero/itemRusak/itemExpired/itemDeadstock all have variance=0)', $fs['sesuai'] === 4, (string) $fs['sesuai']);
check('13b. finance_summary.selisih_plus = 1 (itemPos), selisih_minus = 1 (itemNeg)', $fs['selisih_plus'] === 1 && $fs['selisih_minus'] === 1, json_encode($fs));
check('13c. finance_summary rusak/expired/deadstock counts = 1/1/1', $fs['rusak'] === 1 && $fs['expired'] === 1 && $fs['deadstock'] === 1, json_encode($fs));

// ---- Category summary ----
$cats = $detail['category_summary'];
$catA = null; $catB = null; $total = null;
foreach ($cats as $c) {
    if ($c['category'] === 'V2165 Kategori A') $catA = $c;
    if ($c['category'] === 'V2165 Kategori B') $catB = $c;
    if (($c['is_total_row'] ?? false) === true) $total = $c;
}
check('14. category A (Pos/Neg/Zero/Excluded) has 4 items', $catA !== null && $catA['total_item'] === 4, json_encode($catA));
check('14b. category B (Rusak/Expired/Deadstock) has 3 items', $catB !== null && $catB['total_item'] === 3, json_encode($catB));
check('14c. category TOTAL row matches finance_summary totals', $total !== null && $total['total_item'] === 7 && abs($total['nilai_sistem'] - $fs['nilai_stok_sistem']) < 0.01 && abs($total['nilai_fisik'] - $fs['nilai_stok_fisik_final']) < 0.01, json_encode($total));

// ---- Pagination still works on a FINDINGS_V1 session ----
$page50 = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 50]);
check('15. per_page=50 still returns all 7 items on one page for a FINDINGS_V1 session', count($page50['items']) === 7 && $page50['total_pages'] === 1);

// ---- Regression: Checkpoint B / FINDINGS_V1 finalize()/post() guard is
// completely untouched — a DIFFERENT, still-OPEN FINDINGS_V1 session must
// still be refused by finalize()/post(), exactly as before this phase.
$guardItem = makeItem($pdo, $kgUnitId, 'V2165-GUARD', $catAId);
postOpeningIn($pdo, $guardItem, $kgUnitId, $whId, 10, 100, $adminId);
$guardSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminId, [$guardItem], 'FINDINGS_V1'));
$guardBlocked = null;
try {
    Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $guardSessionId, $adminId));
} catch (\App\Services\FindingsCheckpointBRequiredException $e) {
    $guardBlocked = $e;
}
check('16. regression: FindingsCheckpointBRequiredException still unconditionally blocks finalize() for a genuinely OPEN FINDINGS_V1 session (unchanged)', $guardBlocked !== null, $guardBlocked ? $guardBlocked->getMessage() : 'NOT BLOCKED');

$passed = count(array_filter($results));
$total = count($results);
echo "\n{$passed} / {$total} PASSED\n";
exit($passed === $total ? 0 : 1);
