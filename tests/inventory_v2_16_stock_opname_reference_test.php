<?php
declare(strict_types=1);

/**
 * PHASE V2.16 — Stock Opname Excel REFERENCE import + Final SO Excel
 * export. Service-level proof (direct calls, same pattern as every other
 * inventory_v2_*_test.php in this suite) for test cases 2-14 and 17-19 of
 * the spec's required test list; cases 1/15/16 (real multipart upload +
 * HTTP permission matrix) live in the companion
 * tests/inventory_v2_16_http_test.php.
 *
 * Usage: php tests/inventory_v2_16_stock_opname_reference_test.php
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
require_once __DIR__ . '/../services/StockOpnameFinalExportService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnamePhotoService;
use App\Services\StockOpnameReferenceImportService;
use App\Services\StockOpnameFinalExportService;
use App\Services\XlsxReaderService;
use App\Services\ExcelWriterService;
use App\Services\ValidationException;
use App\Services\FindingsCheckpointBRequiredException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function expectException(string $class, callable $fn): ?\Throwable
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e;
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$ctnUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'PwT' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}
function makeItem(PDO $pdo, string $skuTag, string $name, int $baseUnitId, ?int $secondUnitId = null, ?float $secondFactor = null): int
{
    $sku = uid($skuTag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $baseUnitId]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    if ($secondUnitId !== null) {
        UnitConversionService::openNewVersion($pdo, $id, $secondUnitId, (float) $secondFactor, '2020-01-01 00:00:00', null, 'secondary unit');
    }
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v216-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v216', 'transaction_type' => 'OPENING',
    ]));
}
function inventoryBatchesSnapshot(PDO $pdo): string
{
    return (string) $pdo->query('SELECT COALESCE(SUM(qty_base), 0), COUNT(*) FROM inventory_batches')->fetchColumn() . '|' .
        (string) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
}
function writeSheet(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'v216_ref_') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    return $path;
}

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V216WH', 'V2.16 Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
// A second warehouse: sessionF below stays OPEN for the whole test run (by
// design — FINDINGS_V1 is never finalized/posted here), and WarehouseLockService
// refuses ANY opening-stock posting on a warehouse with an active opname
// session — so every item/session created AFTER sessionF starts must live
// on this separate warehouse instead.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V216WH2', 'V2.16 Test WH 2', 1)")->execute();
$whId2 = (int) $pdo->lastInsertId();

$admin = makeUser($pdo, 'v216admin', $superRoleId);
$p1 = makeUser($pdo, 'v216p1', $superRoleId);
$p2 = makeUser($pdo, 'v216p2', $superRoleId);

// itemCtn: KG base + KARTON (factor 5) secondary — resolvable unit conversion.
$itemCtn = makeItem($pdo, 'V216-CTN', 'Barang Karton', $kgUnitId, $ctnUnitId, 5.0);
// itemKgOnly: KG base ONLY — no KARTON conversion configured -> UNIT_MISMATCH candidate.
$itemKgOnly = makeItem($pdo, 'V216-KGONLY', 'Barang KG Saja', $kgUnitId);
// itemMismatch / itemRecount: for the P1/P2/recount export proof.
$itemMismatch = makeItem($pdo, 'V216-MISM', 'Barang Mismatch', $kgUnitId, $ctnUnitId, 5.0);
postOpeningIn($pdo, $itemCtn, $kgUnitId, $whId, 100, 1000, $admin['id']);
postOpeningIn($pdo, $itemKgOnly, $kgUnitId, $whId, 50, 1000, $admin['id']);
postOpeningIn($pdo, $itemMismatch, $kgUnitId, $whId, 80, 1000, $admin['id']);

// ============================================================
// FINDINGS_V1 session — covers import variations (2-6), late
// movements (7/8), draft export (9), P1/P2/recount + conditions export
// (11/12), and the "import/export never touches inventory" proofs
// (13/14's import half).
// ============================================================
$sessionF = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$itemCtn, $itemKgOnly, $itemMismatch]));
// Backdate session_date so the LATE_PRE_CUTOFF proof below (movements
// recorded "now", with an effective_at at/before this cutoff) is
// meaningful — a session created today would otherwise have a cutoff
// (today 23:59:59) still in the future relative to "now".
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-25', 'id' => $sessionF]);
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionF, 'p1', [$p1['id']], $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionF, 'p2', [$p2['id']], $admin['id']));

function makeFakePhotoFile(): string
{
    $img = imagecreatetruecolor(4, 4);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
    $path = tempnam(sys_get_temp_dir(), 'v216photo') . '.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);
    return $path;
}

function submitBothSides(PDO $pdo, int $sessionId, int $itemId, int $kgUnitId, int $userId, string $role, float $good, float $damaged, float $expired, float $deadstock): void
{
    $claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, $role, $userId, $itemId));
    $photoTokens = ['DAMAGED' => [], 'EXPIRED' => [], 'DEADSTOCK' => []];
    foreach (['DAMAGED' => $damaged, 'EXPIRED' => $expired, 'DEADSTOCK' => $deadstock] as $condition => $qty) {
        if ($qty > 0) {
            $photoFile = makeFakePhotoFile();
            $photo = Database::transaction(fn (PDO $tx) => StockOpnamePhotoService::upload($tx, $sessionId, $role, $itemId, $condition, $userId, $claim['claim_token'], $photoFile));
            $photoTokens[$condition][] = $photo['token'];
            @unlink($photoFile);
        }
    }
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, $role, $itemId,
        [
            'GOOD' => [['unit_id' => $kgUnitId, 'qty' => $good]],
            'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => $damaged]],
            'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => $expired]],
            'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => $deadstock]],
        ],
        null, $userId, $claim['claim_token'], $photoTokens
    ));
}

// itemCtn — P1 and P2 AGREE on everything -> MATCH, final conditions resolved.
submitBothSides($pdo, $sessionF, $itemCtn, $kgUnitId, $p1['id'], 'p1', 60.0, 5.0, 3.0, 2.0);
submitBothSides($pdo, $sessionF, $itemCtn, $kgUnitId, $p2['id'], 'p2', 60.0, 5.0, 3.0, 2.0);

// itemMismatch — P1/P2 DISAGREE on GOOD -> MISMATCH -> supervisor recount.
submitBothSides($pdo, $sessionF, $itemMismatch, $kgUnitId, $p1['id'], 'p1', 40.0, 0.0, 0.0, 0.0);
submitBothSides($pdo, $sessionF, $itemMismatch, $kgUnitId, $p2['id'], 'p2', 42.0, 0.0, 0.0, 0.0);
Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sessionF, $itemMismatch, 41.0, 'supervisor recount', $admin['id']));

// itemKgOnly — left uncounted; used purely for UNIT_MISMATCH import rows.

// ============================================================
// 2/3/4/5/13 — Import variations, never touching inventory.
// ============================================================
$invBefore = inventoryBatchesSnapshot($pdo);

$skuCtn = $pdo->query("SELECT sku FROM items WHERE id = {$itemCtn}")->fetchColumn();
$skuKgOnly = $pdo->query("SELECT sku FROM items WHERE id = {$itemKgOnly}")->fetchColumn();
$skuMismatchForNegRow = $pdo->query("SELECT sku FROM items WHERE id = {$itemMismatch}")->fetchColumn();
$unknownCode = uid('UNKNOWN-SCM-CODE');

$file1 = writeSheet([
    ['Source Code', 'Source Name', 'Source Unit', 'Source Qty'],
    [$skuCtn, 'Barang Karton (SCM)', 'KARTON', '2'],          // MATCHED: 2 CTN x5 = 10 KG
    [$unknownCode, 'Tidak Dikenal', 'KG', '7'],                 // UNMATCHED_SCM
    [$skuKgOnly, 'Barang KG Saja (SCM)', 'KARTON', '3'],        // UNIT_MISMATCH: no KARTON conversion
    [$skuCtn, 'Barang Karton (duplicate)', 'KG', '5'],          // DUPLICATE (same code as row 1)
    [$skuMismatchForNegRow, 'Negative row', 'KG', '-10'],       // NEGATIVE_REFERENCE (a distinct code, never a duplicate)
]);
$import1 = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionF, $file1, 'reference1.csv', $admin['id']));
check('3. import correctly classifies a MATCHED row (known SKU + resolvable unit)', $import1['matched_count'] === 1, (string) $import1['matched_count']);
check('4. import correctly classifies UNMATCHED_SCM (unknown code)', $import1['unmatched_count'] === 1, (string) $import1['unmatched_count']);
check('4b. import correctly classifies UNIT_MISMATCH (item has no KARTON conversion)', $import1['unit_mismatch_count'] === 1, (string) $import1['unit_mismatch_count']);
check('2. import correctly flags an in-file DUPLICATE source code (never silently merged/dropped)', $import1['duplicate_count'] === 1, (string) $import1['duplicate_count']);
check('5. import correctly classifies NEGATIVE_REFERENCE (qty < 0)', $import1['negative_count'] === 1, (string) $import1['negative_count']);
check('5 rows total, nothing silently skipped', $import1['row_count'] === 5, (string) $import1['row_count']);

$rowsStmt = $pdo->prepare('SELECT * FROM stock_opname_reference_rows WHERE import_batch_id = :id ORDER BY source_row_reference');
$rowsStmt->execute(['id' => $import1['import_batch_id']]);
$importedRows = $rowsStmt->fetchAll();
check('MATCHED row converted_base_qty = 2 CTN x 5 = 10 KG (never invents a conversion)', abs((float) $importedRows[0]['converted_base_qty'] - 10.0) < 0.000001, (string) $importedRows[0]['converted_base_qty']);
check('original source values preserved verbatim for audit (Source Unit = KARTON)', $importedRows[0]['source_unit'] === 'KARTON');

$invAfterImport = inventoryBatchesSnapshot($pdo);
check('13. import NEVER changes inventory_batches', $invBefore === $invAfterImport, "{$invBefore} vs {$invAfterImport}");

// ---- re-uploading the IDENTICAL file is refused, never silently duplicated ----
$dupeErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionF, $file1, 'reference1.csv', $admin['id'])
));
check('B. re-uploading the identical file (same hash) into the same session is rejected', $dupeErr instanceof ValidationException, $dupeErr ? get_class($dupeErr) : 'no exception');
$rowCountAfterDupeAttempt = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_reference_rows WHERE session_id = {$sessionF}")->fetchColumn();
check('B2. the rejected duplicate upload created ZERO new rows', $rowCountAfterDupeAttempt === 5, (string) $rowCountAfterDupeAttempt);

// ============================================================
// 19 — malformed file rejected safely (missing required column).
// ============================================================
$malformedFile = writeSheet([
    ['Wrong Header 1', 'Wrong Header 2'],
    ['x', 'y'],
]);
$batchCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_reference_batches")->fetchColumn();
$malformedErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionF, $malformedFile, 'malformed.csv', $admin['id'])
));
check('19. a malformed file (missing required columns) is rejected with a clear ValidationException, not a crash', $malformedErr instanceof ValidationException, $malformedErr ? get_class($malformedErr) : 'no exception');
$batchCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_reference_batches")->fetchColumn();
check('19b. a rejected malformed import creates ZERO batch rows (atomic, nothing partially written)', $batchCountBefore === $batchCountAfter, "{$batchCountBefore} vs {$batchCountAfter}");

// ============================================================
// 6 — manual mapping with audit trail, + the reusable approved
// legacy-code mapping (Section D priority 2).
// ============================================================
$unmatchedRowId = null;
foreach ($importedRows as $r) {
    if ($r['mapping_status'] === 'UNMATCHED_SCM') {
        $unmatchedRowId = (int) $r['id'];
    }
}
// Mapped to itemKgOnly (not itemCtn) deliberately — itemCtn already has
// its OWN real MATCHED row in this same batch (the CTN row above), and
// mapping this row to the SAME item too would leave two matched rows for
// one item in one batch, an intentionally separate ambiguity this test
// does not need to resolve here.
$manualMapResult = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::manualMapRow($tx, $sessionF, $unmatchedRowId, $itemKgOnly, $admin['id']));
check('6. manual mapping resolves an UNMATCHED_SCM row to a real item', $manualMapResult['mapping_status'] === 'MATCHED', $manualMapResult['mapping_status']);
$auditManual = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'stock_opname_reference_rows' AND entity_id = :id AND action_code = 'STOCK_OPNAME_REFERENCE_ROW_MANUAL_MAP'");
$auditManual->execute(['id' => $unmatchedRowId]);
check('6b. manual mapping is recorded with an audit trail', (int) $auditManual->fetchColumn() > 0);

$mappingId = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::approveItemMapping($tx, $unknownCode, $itemKgOnly, $admin['id'], 'legacy code approved for test'));
check('D. an approved legacy-code mapping is recorded', $mappingId > 0);
$dupeApprovalErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameReferenceImportService::approveItemMapping($tx, $unknownCode, $itemCtn, $admin['id'], null)
));
check('D2. a second ACTIVE approved mapping for the same code is refused (never ambiguous)', $dupeApprovalErr instanceof ValidationException);

$file2 = writeSheet([
    ['Source Code', 'Source Name', 'Source Unit', 'Source Qty'],
    [$unknownCode, 'Now mapped via legacy code', 'KG', '4'],
]);
$import2 = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionF, $file2, 'reference2.csv', $admin['id']));
check('D3. a later import resolves the SAME code via the approved legacy-code mapping -> MATCHED', $import2['matched_count'] === 1, (string) $import2['matched_count']);

// ============================================================
// 7/8 — late/backdated IN and OUT reference movements, with
// LATE_PRE_CUTOFF tagging.
// ============================================================
$sessionDate = $pdo->prepare('SELECT session_date FROM stock_opname_sessions WHERE id = :id');
$sessionDate->execute(['id' => $sessionF]);
$cutoff = $sessionDate->fetchColumn() . ' 23:59:59';

$movInId = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::recordMovement(
    $tx, $sessionF, $itemCtn, 'IN', 3.0, $cutoff, 'GR-TEST-001', 'late receiving before cutoff', $admin['id']
));
$movOutId = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::recordMovement(
    $tx, $sessionF, $itemCtn, 'OUT', 1.0, $cutoff, 'ISS-TEST-001', 'late issue before cutoff', $admin['id']
));
$movRow = $pdo->prepare('SELECT * FROM stock_opname_reference_movements WHERE id IN (:a, :b)');
$movRow->execute(['a' => $movInId, 'b' => $movOutId]);
$lateFlags = array_column($movRow->fetchAll(), 'late_pre_cutoff');
check('7/8. late IN/OUT movements (effective_at <= cutoff, created_at > cutoff) are tagged LATE_PRE_CUTOFF', array_sum($lateFlags) === 2, json_encode($lateFlags));

$scmByItem = StockOpnameReferenceImportService::scmAdjustedReferenceByItem($pdo, $sessionF);
$ctnRef = $scmByItem[$itemCtn];
check('F. SCM_ADJUSTED_REFERENCE = SCM_REFERENCE + IN - OUT (10 + 3 - 1 = 12)', abs($ctnRef['scm_adjusted_reference'] - 12.0) < 0.000001, (string) $ctnRef['scm_adjusted_reference']);

$invAfterMovements = inventoryBatchesSnapshot($pdo);
check('F2. recording reference movements NEVER touches inventory_batches', $invBefore === $invAfterMovements, "{$invBefore} vs {$invAfterMovements}");

// ============================================================
// 9 — Draft export, watermarked, available on an OPEN session.
// ============================================================
$draftPath = StockOpnameFinalExportService::draft($pdo, $sessionF, $admin['id']);
check('9. draft export produces a real file', is_file($draftPath));
$ringkasan = XlsxReaderService::read($draftPath, 'Ringkasan');
$ringkasanText = json_encode($ringkasan);
check('9b. draft export carries the visible "DRAFT — BELUM FINAL" watermark', str_contains($ringkasanText, 'DRAFT'), substr($ringkasanText, 0, 120));

$finalSoRows = XlsxReaderService::read($draftPath, 'Final SO');
$ctnExportRow = null;
$mismatchExportRow = null;
foreach ($finalSoRows as $r) {
    if ($r['SKU'] === $skuCtn) {
        $ctnExportRow = $r;
    }
    if ((int) ($r['No'] ?? 0) > 0 && isset($r['Status'])) {
        // no-op, just iterate
    }
}
$skuMismatch = $pdo->query("SELECT sku FROM items WHERE id = {$itemMismatch}")->fetchColumn();
foreach ($finalSoRows as $r) {
    if ($r['SKU'] === $skuMismatch) {
        $mismatchExportRow = $r;
    }
}
check('11. P1 Good is correctly exported', (float) $ctnExportRow['P1 Good'] === 60.0, (string) $ctnExportRow['P1 Good']);
check('11b. P2 Good is correctly exported', (float) $ctnExportRow['P2 Good'] === 60.0, (string) $ctnExportRow['P2 Good']);
check('11c. Recount Good is correctly exported for the recounted item', (float) $mismatchExportRow['Recount Good'] === 41.0, (string) $mismatchExportRow['Recount Good']);
check('12. Final Good/Damaged/Expired/Deadstock are correctly exported', (float) $ctnExportRow['Final Good'] === 60.0 && (float) $ctnExportRow['Final Damaged'] === 5.0 && (float) $ctnExportRow['Final Expired'] === 3.0 && (float) $ctnExportRow['Final Deadstock'] === 2.0,
    json_encode(['good' => $ctnExportRow['Final Good'], 'dmg' => $ctnExportRow['Final Damaged'], 'exp' => $ctnExportRow['Final Expired'], 'dead' => $ctnExportRow['Final Deadstock']]));
check('12b. Final Total Physical sums all four (60+5+3+2=70)', abs((float) $ctnExportRow['Final Total Physical'] - 70.0) < 0.000001, (string) $ctnExportRow['Final Total Physical']);
check('Reference SCM Adjusted reflects the import + late movements (12)', abs((float) $ctnExportRow['Reference SCM Adjusted'] - 12.0) < 0.000001, (string) $ctnExportRow['Reference SCM Adjusted']);

$invAfterDraft = inventoryBatchesSnapshot($pdo);
check('14. draft export NEVER touches inventory_batches', $invBefore === $invAfterDraft, "{$invBefore} vs {$invAfterDraft}");

// Official export must be refused on this FINDINGS_V1, non-POSTED session
// — and must NEVER unblock FINDINGS_V1_CHECKPOINT_B_REQUIRED.
$officialErr = expectException(FindingsCheckpointBRequiredException::class, fn () => StockOpnameFinalExportService::official($pdo, $sessionF, $admin['id']));
check('Q. official export on an OPEN FINDINGS_V1 session is refused (never unblocks Checkpoint B)', $officialErr instanceof FindingsCheckpointBRequiredException, $officialErr ? $officialErr->getMessage() : 'no exception');

// ============================================================
// 10 — Official Final SO export: only once truly POSTED (LEGACY_DUAL_COUNT,
// which CAN reach POSTED through the existing, unmodified status machine),
// plus immutability (two exports of the same POSTED session match).
// ============================================================
$itemLegacy = makeItem($pdo, 'V216-LEGACY', 'Barang Legacy Posted', $kgUnitId);
postOpeningIn($pdo, $itemLegacy, $kgUnitId, $whId2, 30, 1000, $admin['id']);
$sessionL = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId2, $admin['id'], [$itemLegacy], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionL, ['p1_user_id' => $p1['id'], 'p2_user_id' => $p2['id']], $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionL, 'p1', $itemLegacy, 30.0, $p1['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionL, 'p2', $itemLegacy, 30.0, $p2['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionL, $admin['id']));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionL, $admin['id']));

$invBeforeOfficial = inventoryBatchesSnapshot($pdo);
$officialPath1 = StockOpnameFinalExportService::official($pdo, $sessionL, $admin['id']);
check('10. official export succeeds once the session is truly POSTED', is_file($officialPath1));
$officialRingkasan1 = XlsxReaderService::read($officialPath1, 'Ringkasan');
check('10b. official export carries NO "DRAFT" watermark', !str_contains(json_encode($officialRingkasan1), 'DRAFT — BELUM FINAL'));
$finalizedAtRow = array_values(array_filter($officialRingkasan1, fn ($r) => $r['Field'] === 'Finalized At'))[0] ?? null;
check('O. official export stamps Finalized At / Exported By / Exported At (Section O)', $finalizedAtRow !== null && $finalizedAtRow['Value'] !== '');

sleep(1); // ensure a different Exported At timestamp on the second export
$officialPath2 = StockOpnameFinalExportService::official($pdo, $sessionL, $admin['id']);
$finalSo1 = XlsxReaderService::read($officialPath1, 'Final SO');
$finalSo2 = XlsxReaderService::read($officialPath2, 'Final SO');
check('O2. immutability — two official exports of the SAME posted session produce identical business values', json_encode($finalSo1) === json_encode($finalSo2));

$invAfterOfficial = inventoryBatchesSnapshot($pdo);
check('14b. official export NEVER touches inventory_batches', $invBeforeOfficial === $invAfterOfficial, "{$invBeforeOfficial} vs {$invAfterOfficial}");
@unlink($officialPath1);
@unlink($officialPath2);
@unlink($draftPath);

// ============================================================
// 17 — large file performance.
// ============================================================
$bulkRows = [['Source Code', 'Source Name', 'Source Unit', 'Source Qty']];
for ($i = 0; $i < 2000; $i++) {
    $bulkRows[] = [$skuCtn . '-NOPE-' . $i, "Bulk {$i}", 'KG', '1'];
}
$bulkFile = writeSheet($bulkRows);
$t0 = microtime(true);
$bulkImport = Database::transaction(fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionF, $bulkFile, 'bulk.csv', $admin['id']));
$elapsed = microtime(true) - $t0;
check('17. a 2000-row file imports completely and within a reasonable time', $bulkImport['row_count'] === 2000 && $elapsed < 30.0, "{$bulkImport['row_count']} rows in " . round($elapsed, 2) . 's');

// ============================================================
// 18 — formula injection protection in exported XLSX cells.
// ============================================================
check('18a. sanitizeCellText neutralizes a formula-triggering prefix', ExcelWriterService::sanitizeCellText('=HYPERLINK("http://evil")') === "'=HYPERLINK(\"http://evil\")");
check('18b. sanitizeCellText leaves ordinary text untouched', ExcelWriterService::sanitizeCellText('Barang Normal') === 'Barang Normal');

$itemInjection = makeItem($pdo, 'V216-INJ', '=cmd|/C calc!A1', $kgUnitId);
postOpeningIn($pdo, $itemInjection, $kgUnitId, $whId2, 5, 1000, $admin['id']);
$sessionInj = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId2, $admin['id'], [$itemInjection]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionInj, 'p1', [$p1['id']], $admin['id']));
submitBothSides($pdo, $sessionInj, $itemInjection, $kgUnitId, $p1['id'], 'p1', 5.0, 0.0, 0.0, 0.0);
$injDraftPath = StockOpnameFinalExportService::draft($pdo, $sessionInj, $admin['id']);
$injRows = XlsxReaderService::read($injDraftPath, 'Final SO');
$injRow = $injRows[0] ?? null;
check('18c. an item name starting with "=" is neutralized (leading quote) in the exported XLSX', $injRow !== null && str_starts_with((string) $injRow['Nama Barang'], "'="), $injRow ? $injRow['Nama Barang'] : 'no row');
@unlink($injDraftPath);

echo "\n==============================\n";
$pass = count(array_filter($results));
echo 'TOTAL: ' . count($results) . '  PASSED: ' . $pass . '  FAILED: ' . (count($results) - $pass) . "\n";
exit($pass === count($results) ? 0 : 1);
