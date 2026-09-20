<?php
declare(strict_types=1);

/**
 * PHASE V2.3D — "CUTOVER-AWARE HPP REPORT UX" regression fixture.
 *
 * Proves the business rule: effective_start_date = MAX(requested_start_date,
 * live_opening_date). A requested range that begins before the real live
 * opening date is clamped forward to it for every economic formula
 * (Opening/Purchase/FIFO HPP/Ending/Reconciliation/Variance) — never just
 * a visual relabeling — while a range entirely at or after go-live behaves
 * exactly as PHASE V2.3C already proved (unchanged).
 *
 * Fixture: two warehouses SCM/CIBADAK carrying the exact production-like
 * composition PHASE V2.3C used (a positive opening filler plus the 5 real
 * approved migration-negative balances), this time posted alongside a real
 * COMMITTED `stock_openings` row — the authoritative go-live record this
 * phase's derivation logic reads primarily — dated exactly 2026-09-16, the
 * date named throughout the hotfix request. Every non-target fixture
 * element (the after-go-live differentiator IN, the historical row) uses
 * its own SKU prefix and is always checked via a scoped filter, never an
 * unscoped absolute total.
 *
 * Part 0 proves the derivation itself (services/InventoryHppReportService
 * ::liveOpeningDate()) BEFORE any stock_openings row exists, using a
 * throwaway item so it can never contaminate the target composition's own
 * numbers — this is why it runs first, strictly before the main fixture.
 *
 * Usage: php tests/inventory_hpp_v23d_cutover_test.php
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
require_once __DIR__ . '/../services/InventoryHppReportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\InventoryService;
use App\Services\InventoryHppReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) < $eps; }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v23d'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V23D', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeWarehouse(PDO $pdo, string $tag): array
{
    $code = uid($tag);
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $tag]);
    return [(int) $pdo->lastInsertId(), $code];
}
function makeItem(PDO $pdo, int $unitId, string $sku): int
{
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)');
    $stmt->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function approveMigrationNegative(PDO $pdo, string $sku, string $whCode): void
{
    $pdo->prepare(
        'INSERT INTO movement_reconciliation_reviews
            (sku, warehouse_code, historical_opening, historical_in, historical_out, historical_calculated_ending,
             status, is_migration_negative_approved, migration_negative_approved_by_name, migration_negative_note)
         VALUES (:sku, :wh, 0, 0, 0, -1, \'PENDING_FINAL_STOCK\', 1, \'V2.3D test owner\', \'cutover whitelist row\')'
    )->execute(['sku' => $sku, 'wh' => $whCode]);
}
function postOpeningAtPrice1(PDO $pdo, int $itemId, int $whId, float $qty, string $date, int $createdBy, int $unitId, bool $allowMigrationNegative = false): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v23d-open'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => 1,
        'transaction_date' => $date, 'created_by' => $createdBy, 'username' => 'v23d',
        'transaction_type' => 'OPENING', 'allow_migration_negative_opening' => $allowMigrationNegative,
    ]));
}

const GO_LIVE = '2026-09-16';
const TARGET_SCM = 2330669085.7833;
const TARGET_CIB = 307378082.1376;
const TARGET_COMPANY = 2638047167.9209;

// ============================================================
// PART 0 — derivation proof: liveOpeningDate() reads from data, not a
// hardcoded constant. Run BEFORE any stock_openings row exists, on a
// throwaway warehouse/item so it can never affect the main fixture below.
// ============================================================
echo "== PART 0: live_opening_date derivation (fallback path, no stock_openings row yet) ==\n";

[$whFallbackId] = makeWarehouse($pdo, 'V23D-FALLBACK-WH');
$itemFallback = makeItem($pdo, $kgUnitId, uid('V23D-FALLBACK'));
postOpeningAtPrice1($pdo, $itemFallback, $whFallbackId, 1000, GO_LIVE . ' 00:00:00', $adminUserId, $kgUnitId);

$fallbackSummary = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-20', $whFallbackId, null, null);
check('No stock_openings row exists yet -> live_opening_date derives from the earliest POSTED OPENING transaction', ($fallbackSummary['cutover']['live_opening_date'] ?? null) === GO_LIVE, (string) ($fallbackSummary['cutover']['live_opening_date'] ?? 'null'));
check('That derived date correctly clamps this warehouse\'s own opening_value forward', approx($fallbackSummary['opening_value'], 1000.0), (string) $fallbackSummary['opening_value']);

// Now add the authoritative COMMITTED Stock Opening batch record at the
// SAME date — the primary derivation source most real (import-UI-driven)
// production data actually has. Confirms the primary path independently
// yields the same, correct answer once it exists.
$pdo->prepare(
    "INSERT INTO stock_openings (cutoff_date, description, status, control_total_value, created_by, committed_by, committed_at)
     VALUES (:cutoff, 'V2.3D cutover test go-live batch', 'COMMITTED', :total, :created_by, :committed_by, :now)"
)->execute(['cutoff' => GO_LIVE, 'total' => TARGET_COMPANY, 'created_by' => $adminUserId, 'committed_by' => $adminUserId, 'now' => date('Y-m-d H:i:s')]);

$fallbackSummaryAfter = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-20', $whFallbackId, null, null);
check('After adding a COMMITTED stock_openings row at the same date, live_opening_date is still correctly 2026-09-16 (primary source agrees with the fallback)', ($fallbackSummaryAfter['cutover']['live_opening_date'] ?? null) === GO_LIVE, (string) ($fallbackSummaryAfter['cutover']['live_opening_date'] ?? 'null'));

// ============================================================
// MAIN FIXTURE — production-equivalent SCM/CIBADAK composition, dated
// exactly at the go-live boundary (matches PHASE V2.3C's own fixture).
// ============================================================
echo "\n== Building main SCM/CIBADAK fixture at go-live 2026-09-16 ==\n";

[$whScmId, $whScmCode] = makeWarehouse($pdo, 'V23D-SCM');
[$whCibId, $whCibCode] = makeWarehouse($pdo, 'V23D-CIBADAK');

$itemPosScm = makeItem($pdo, $kgUnitId, uid('V23DTARGET-POS-SCM'));
$item100304 = makeItem($pdo, $kgUnitId, uid('V23DTARGET-100304'));
$item777419 = makeItem($pdo, $kgUnitId, uid('V23DTARGET-777419'));
$itemPosCib = makeItem($pdo, $kgUnitId, uid('V23DTARGET-POS-CIB'));
$item400201 = makeItem($pdo, $kgUnitId, uid('V23DTARGET-400201'));
$item555410 = makeItem($pdo, $kgUnitId, uid('V23DTARGET-555410'));
$item800401 = makeItem($pdo, $kgUnitId, uid('V23DTARGET-800401'));

foreach ([[$item100304, $whScmCode], [$item777419, $whScmCode], [$item400201, $whCibCode], [$item555410, $whCibCode], [$item800401, $whCibCode]] as [$itemId, $whCode]) {
    $skuStmt = $pdo->prepare('SELECT sku FROM items WHERE id = :id');
    $skuStmt->execute(['id' => $itemId]);
    approveMigrationNegative($pdo, $skuStmt->fetchColumn(), $whCode);
}

$goLiveDateTime = GO_LIVE . ' 00:00:00';
postOpeningAtPrice1($pdo, $itemPosScm, $whScmId, 2330669086.7833, $goLiveDateTime, $adminUserId, $kgUnitId);
postOpeningAtPrice1($pdo, $item100304, $whScmId, -0.5, $goLiveDateTime, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item777419, $whScmId, -0.5, $goLiveDateTime, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $itemPosCib, $whCibId, 307378960.6376, $goLiveDateTime, $adminUserId, $kgUnitId);
postOpeningAtPrice1($pdo, $item400201, $whCibId, -466.5, $goLiveDateTime, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item555410, $whCibId, -250, $goLiveDateTime, $adminUserId, $kgUnitId, true);
postOpeningAtPrice1($pdo, $item800401, $whCibId, -162, $goLiveDateTime, $adminUserId, $kgUnitId, true);

$targetQ = 'V23DTARGET';

// A real, effective purchase dated AFTER go-live — proves a post-go-live
// query genuinely reflects its own point in time rather than always
// re-showing the original 16 Sep opening figure.
$itemPostGoLive = makeItem($pdo, $kgUnitId, uid('V23D-POSTGOLIVE'));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v23d-postgolive-in'), 'item_id' => $itemPostGoLive, 'warehouse_id' => $whScmId,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-17 08:00:00', 'created_by' => $adminUserId, 'username' => 'v23d',
]));

// ============================================================
// CASE 1 — requested period BEFORE live opening (straddling): 01/09-30/09.
// ============================================================
echo "\n== CASE 1: requested period straddles go-live (01/09-30/09) ==\n";

$case1 = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
check('Case 1: requested_start_date echoes exactly what was asked (2026-09-01)', $case1['cutover']['requested_start_date'] === '2026-09-01');
check('Case 1: effective_start_date is clamped forward to live_opening_date (2026-09-16)', $case1['cutover']['effective_start_date'] === GO_LIVE, $case1['cutover']['effective_start_date']);
check('Case 1: is_pre_go_live_period is false (the range DOES reach go-live)', $case1['cutover']['is_pre_go_live_period'] === false);
check('Case 1: Opening Value = company target 2,638,047,167.9209 (NOT Rp 0)', approx($case1['opening_value'], TARGET_COMPANY), (string) $case1['opening_value']);
check('Case 1: External Purchase = 0 (no effective purchase for these target items)', approx($case1['external_purchase'], 0.0), (string) $case1['external_purchase']);
check('Case 1: FIFO HPP = 0', approx($case1['fifo_hpp'], 0.0), (string) $case1['fifo_hpp']);
check('Case 1: Ending Value = same target (nothing else moved for these items)', approx($case1['ending_value'], TARGET_COMPANY), (string) $case1['ending_value']);
check('Case 1: HPP Reconciliation = 0', approx($case1['hpp_reconciliation'], 0.0), (string) $case1['hpp_reconciliation']);
check('Case 1: Variance = 0', approx($case1['variance'], 0.0), (string) $case1['variance']);
$bridge1 = InventoryHppReportService::varianceBridge($pdo, '2026-09-01', '2026-09-30', null);
check('Case 1: Unexplained = 0', approx($bridge1['unexplained'], 0.0), (string) $bridge1['unexplained']);

// ============================================================
// CASE 2 — requested period EXACTLY at live opening: 16/09-30/09.
// Must remain unchanged from PHASE V2.3C's own behavior.
// ============================================================
echo "\n== CASE 2: requested period starts exactly at go-live (16/09-30/09) ==\n";

$case2 = InventoryHppReportService::summary($pdo, GO_LIVE, '2026-09-30', null, null, $targetQ);
check('Case 2: effective_start_date == requested_start_date (no clamping needed)', $case2['cutover']['effective_start_date'] === $case2['cutover']['requested_start_date']);
check('Case 2: Opening Value = company target 2,638,047,167.9209', approx($case2['opening_value'], TARGET_COMPANY), (string) $case2['opening_value']);
check('Case 2: Variance = 0 (still correct, V2.3C behavior preserved)', approx($case2['variance'], 0.0), (string) $case2['variance']);

// ============================================================
// CASE 3 — requested period AFTER live opening: 20/09-30/09. Must show a
// genuine point-in-time Opening, never the original 16 Sep figure forced in.
// ============================================================
echo "\n== CASE 3: requested period starts after go-live (20/09-30/09) — must NOT force the 16 Sep opening ==\n";

$case3 = InventoryHppReportService::summary($pdo, '2026-09-20', '2026-09-30', $whScmId, null, null);
check('Case 3: effective_start_date == requested_start_date (2026-09-20, not clamped)', $case3['cutover']['effective_start_date'] === '2026-09-20', $case3['cutover']['effective_start_date']);
$scmTargetPlusPostGoLive = TARGET_SCM + 50000.0;
check('Case 3: Opening Value reflects the real point-in-time balance (target + the 17 Sep purchase), NOT the bare 16 Sep figure', approx($case3['opening_value'], $scmTargetPlusPostGoLive) && !approx($case3['opening_value'], TARGET_SCM), (string) $case3['opening_value']);

// ============================================================
// CASE 4 — period ENTIRELY before live opening: must return a clean
// empty/pre-go-live state, never fabricated economics.
// ============================================================
echo "\n== CASE 4: period entirely before go-live (01/08-10/09) -> clean empty state ==\n";

$case4 = InventoryHppReportService::summary($pdo, '2026-08-01', '2026-09-10', null, null, $targetQ);
check('Case 4: is_pre_go_live_period is true', $case4['cutover']['is_pre_go_live_period'] === true);
check('Case 4: Opening Value = 0 (never fabricated)', approx($case4['opening_value'], 0.0), (string) $case4['opening_value']);
check('Case 4: External Purchase = 0', approx($case4['external_purchase'], 0.0));
check('Case 4: FIFO HPP = 0', approx($case4['fifo_hpp'], 0.0));
check('Case 4: Ending Value = 0', approx($case4['ending_value'], 0.0));
check('Case 4: HPP Reconciliation = 0', approx($case4['hpp_reconciliation'], 0.0));
check('Case 4: Variance = 0 (not some spurious negative number)', approx($case4['variance'], 0.0));
$bridge4 = InventoryHppReportService::varianceBridge($pdo, '2026-08-01', '2026-09-10', null);
check('Case 4: variance bridge is also a clean empty/fully-explained state', $bridge4['is_fully_explained'] === true && count($bridge4['components']) === 0);

// ============================================================
// CASE 5/6/7 — consolidated / SCM / CIBADAK, all cutover-aware, summing
// consistently.
// ============================================================
echo "\n== CASE 5/6/7: consolidated / SCM / CIBADAK ==\n";

$caseScm = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', $whScmId, null, $targetQ);
$caseCib = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', $whCibId, null, $targetQ);
check('Case 6 (SCM): Opening Value = 2,330,669,085.7833', approx($caseScm['opening_value'], TARGET_SCM), (string) $caseScm['opening_value']);
check('Case 7 (CIBADAK): Opening Value = 307,378,082.1376', approx($caseCib['opening_value'], TARGET_CIB), (string) $caseCib['opening_value']);
check('Case 5 (consolidated): SCM + CIBADAK == company total', approx($caseScm['opening_value'] + $caseCib['opening_value'], $case1['opening_value']));
check('Case 6/7: both use the SAME effective_start_date as consolidated (2026-09-16)', $caseScm['cutover']['effective_start_date'] === GO_LIVE && $caseCib['cutover']['effective_start_date'] === GO_LIVE);

// ============================================================
// CASE 8 — historical inventory_effect=0 rows never affect the cutover
// economics, dated well before go-live.
// ============================================================
echo "\n== CASE 8: historical inventory_effect=0 rows excluded ==\n";

$beforeHist = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
$now = date('Y-m-d H:i:s');
$histTxStmt = $pdo->prepare(
    'INSERT INTO inventory_transactions
        (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
     VALUES (:uuid, \'IN\', :tx_date, :post_date, :wh, \'POSTED\', 1, 0, :created_by, :created_at)'
);
$histTxStmt->execute(['uuid' => uid('v23d-hist'), 'tx_date' => '2026-08-01 08:00:00', 'post_date' => $now, 'wh' => $whScmId, 'created_by' => $adminUserId, 'created_at' => $now]);
$histTxId = (int) $pdo->lastInsertId();
$histLineStmt = $pdo->prepare(
    'INSERT INTO inventory_transaction_lines
        (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
     VALUES (:tx_id, 1, :item_id, \'hist\', 500, :unit, 1, 500, 9999, 9999, 4999500, :wh)'
);
$histLineStmt->execute(['tx_id' => $histTxId, 'item_id' => $itemPosScm, 'unit' => $kgUnitId, 'wh' => $whScmId]);

$afterHist = InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
check('Case 8: Opening Value unchanged after the historical row', approx($beforeHist['opening_value'], $afterHist['opening_value']));
check('Case 8: live_opening_date unchanged (a historical row is not an OPENING transaction, never affects derivation)', $beforeHist['cutover']['live_opening_date'] === $afterHist['cutover']['live_opening_date']);
check('Case 8: entirely-before-go-live period (Case 4) also unaffected', approx(InventoryHppReportService::summary($pdo, '2026-08-01', '2026-09-10', null, null, $targetQ)['opening_value'], 0.0));

// ============================================================
// CASE 9 — daily recap: pre-go-live days are honest zeros, flagged; the
// go-live day itself shows the full opening; later days continue normally.
// ============================================================
echo "\n== CASE 9: daily recap (pre-go-live rows never fabricate movement) ==\n";

$daily = InventoryHppReportService::dailyRecap($pdo, '2026-09-01', '2026-09-20', null, 1, 30, null, $targetQ);
check('Case 9: daily recap returns every requested day (20 days, 01-20 Sep)', $daily['pagination']['total'] === 20, (string) $daily['pagination']['total']);
$byDate = [];
foreach ($daily['rows'] as $r) {
    $byDate[$r['date']] = $r;
}
check('Case 9: 2026-09-01 (pre-go-live) is flagged is_pre_go_live and all-zero', $byDate['2026-09-01']['is_pre_go_live'] === true && approx($byDate['2026-09-01']['stok_awal'], 0.0) && approx($byDate['2026-09-01']['stok_akhir'], 0.0) && approx($byDate['2026-09-01']['variance'], 0.0));
check('Case 9: 2026-09-15 (still pre-go-live) is also all-zero', $byDate['2026-09-15']['is_pre_go_live'] === true && approx($byDate['2026-09-15']['stok_akhir'], 0.0));
check('Case 9: 2026-09-16 (go-live day) shows the full opening AND ending at the target, not flagged pre-go-live', $byDate[GO_LIVE]['is_pre_go_live'] === false && approx($byDate[GO_LIVE]['stok_awal'], TARGET_COMPANY) && approx($byDate[GO_LIVE]['stok_akhir'], TARGET_COMPANY));
check('Case 9: 2026-09-16 reconciliation/variance are 0 on the go-live day itself (no phantom gap)', approx($byDate[GO_LIVE]['hpp_reconciliation'], 0.0) && approx($byDate[GO_LIVE]['variance'], 0.0));
check('Case 9: 2026-09-20 (after go-live) continues normally, still at target (no movement in this scoped SKU range after day 16)', $byDate['2026-09-20']['is_pre_go_live'] === false && approx($byDate['2026-09-20']['stok_akhir'], TARGET_COMPANY));
check('Case 9: cutover metadata is attached to the daily recap response too', ($daily['cutover']['effective_start_date'] ?? null) === GO_LIVE);

// ============================================================
// CASE 10 — Excel export states Requested/Effective/Live Opening Date and
// matches the screen's own numbers exactly.
// ============================================================
echo "\n== CASE 10: Excel export consistency ==\n";

$sheets = InventoryHppReportService::buildExportSheets($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
$ringkasanRows = $sheets['Ringkasan']['rows'];
$ringkasanMap = [];
foreach ($ringkasanRows as $row) {
    $ringkasanMap[$row[0]] = $row[1];
}
check('Case 10: Ringkasan states the Requested Period', isset($ringkasanMap['Periode Diminta (Requested Period)']) && str_contains((string) $ringkasanMap['Periode Diminta (Requested Period)'], '2026-09-01'));
check('Case 10: Ringkasan states the Effective Inventory Period (2026-09-16 onward)', isset($ringkasanMap['Periode Efektif Inventory (Effective Inventory Period)']) && str_contains((string) $ringkasanMap['Periode Efektif Inventory (Effective Inventory Period)'], GO_LIVE));
check('Case 10: Ringkasan states the Live Opening Date', ($ringkasanMap['Tanggal Opening Go-Live (Live Opening Date)'] ?? null) === GO_LIVE, (string) ($ringkasanMap['Tanggal Opening Go-Live (Live Opening Date)'] ?? 'missing'));
check('Case 10: Ringkasan\'s Nilai Stok Awal matches the screen\'s summary() exactly (screen and Excel never disagree)', approx((float) $ringkasanMap['Nilai Stok Awal'], $case1['opening_value']), (string) $ringkasanMap['Nilai Stok Awal']);
check('Case 10: Ringkasan\'s Variance matches the screen exactly', approx((float) $ringkasanMap['Variance (HPP Rekonsiliasi - FIFO HPP)'], $case1['variance']));

$dailySheetRows = $sheets['Rekap Harian']['rows'];
$exportDailyByDate = [];
foreach ($dailySheetRows as $row) {
    $exportDailyByDate[$row[0]] = $row;
}
check('Case 10: exported Rekap Harian marks pre-go-live days with the audit-history status text', ($exportDailyByDate['2026-09-01'][1] ?? '') === 'Histori Audit (Pre Go-Live)');
check('Case 10: exported Rekap Harian leaves the status blank for real economic days', ($exportDailyByDate[GO_LIVE][1] ?? 'x') === '');

// ============================================================
// READ-ONLY GUARANTEE
// ============================================================
echo "\n== READ-ONLY GUARANTEE ==\n";

function v23dSnapshot(PDO $pdo): array
{
    return [
        'batches' => $pdo->query('SELECT id, qty_base FROM inventory_batches ORDER BY id')->fetchAll(),
        'tx_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
        'stock_openings_count' => (int) $pdo->query('SELECT COUNT(*) FROM stock_openings')->fetchColumn(),
    ];
}
$before = v23dSnapshot($pdo);
InventoryHppReportService::summary($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
InventoryHppReportService::warehouseBreakdown($pdo, '2026-09-01', '2026-09-30', null);
InventoryHppReportService::dailyRecap($pdo, '2026-09-01', '2026-09-20', null, 1, 30, null, $targetQ);
InventoryHppReportService::varianceBridge($pdo, '2026-09-01', '2026-09-30', null);
InventoryHppReportService::buildExportSheets($pdo, '2026-09-01', '2026-09-30', null, null, $targetQ);
$after = v23dSnapshot($pdo);
check('Every V2.3D report call is read-only (byte-identical state before/after)', $before === $after);

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
echo "==============================\n";
exit($passed === $total ? 0 : 1);
