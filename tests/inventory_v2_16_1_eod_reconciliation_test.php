<?php
declare(strict_types=1);

/**
 * PHASE V2.16.1 — "STOK BUKU SO" EOD reconciliation. Service-level proof
 * for StockOpnameBookStockService: baseline import (multi-row-header SCM
 * detection), movement bulk import (inclusion_status classification,
 * duplicate protection), counted_at (explicit pass-through + controlled
 * backfill), and the Book Stock EOD / Physical EOD / variance formulas —
 * including the spec's own named CRITICAL TEST / LATE INPUT TEST /
 * AFTER-CUTOFF TEST numeric scenarios (Sections 21-23).
 *
 * Usage: php tests/inventory_v2_16_1_eod_reconciliation_test.php
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
require_once __DIR__ . '/../services/StockOpnameBookStockService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnameReferenceImportService;
use App\Services\StockOpnameFinalExportService;
use App\Services\StockOpnameBookStockService;
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
        'transaction_uuid' => uid('v2161-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v2161', 'transaction_type' => 'OPENING',
    ]));
}
function inventoryBatchesSnapshot(PDO $pdo): string
{
    return (string) $pdo->query('SELECT COALESCE(SUM(qty_base), 0), COUNT(*) FROM inventory_batches')->fetchColumn() . '|' .
        (string) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
}
function writeCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'v2161_') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    return $path;
}

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2161WH', 'V2.16.1 Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
// $session (the main classification fixture below) stays OPEN for the
// whole test run by design, and WarehouseLockService refuses ANY new
// opname session on a warehouse with one already active — every session
// created AFTER $session starts must live on a separate warehouse.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2161WH2', 'V2.16.1 Test WH 2', 1)")->execute();
$whId2 = (int) $pdo->lastInsertId();
// $sessionNoBaseline below ALSO stays OPEN on $whId2 for the rest of the
// run, so the CRITICAL TEST scenario needs its own third warehouse.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2161WH3', 'V2.16.1 Test WH 3', 1)")->execute();
$whId3 = (int) $pdo->lastInsertId();

$admin = makeUser($pdo, 'v2161admin', $superRoleId);
$p1 = makeUser($pdo, 'v2161p1', $superRoleId);

// ============================================================
// 1 — readScmWorkbook(): multi-row header detection, including the
// previously-buggy case where the real QTY column is the SECOND
// sub-column under a true Excel merge (group label text physically
// present only in the left-most cell of the merge — "Total Stok"
// first, "QTY" second — the exact ordering that the earlier forward-
// fill implementation silently lost).
// ============================================================
$scmFile = tempnam(sys_get_temp_dir(), 'v2161_scm_') . '.xlsx';
$scmSkuA = uid('SCM-A');
$scmSkuB = uid('SCM-B');
ExcelWriterService::write($scmFile, [
    'SCM' => [
        // Row 1 — group header row: "Stok Akhir" sits only above column E
        // (a true merge's label lives in its left-most cell only).
        'headers' => [null, null, null, null, 'Stok Akhir', null],
        'rows' => [
            // Row 2 — the LEAF header row (contains "Kode Barang" + "Nama
            // Barang", so this is what the detector must find). Columns
            // E/F are the merge's two sub-columns, "Total Stok (Rp)"
            // FIRST and "QTY" SECOND — the order that exposed the bug.
            ['No', 'Kode Barang', 'Nama Barang', 'Satuan', 'Total Stok (Rp)', 'QTY'],
            // Row 3+ — real data.
            [1, $scmSkuA, 'Barang A (SCM)', 'KG', 125000, 100],
            [2, $scmSkuB, 'Barang B (SCM)', 'KG', 50000, 40],
        ],
    ],
]);
[$scmRows, $scmLeafRow] = StockOpnameBookStockService::readScmWorkbook($scmFile);
check('1. readScmWorkbook finds the leaf header row (row 2, not row 1)', $scmLeafRow === 2, (string) $scmLeafRow);
check('1b. readScmWorkbook returns 2 data rows', count($scmRows) === 2, (string) count($scmRows));
check('1c. readScmWorkbook picks the QTY sub-column, never the Total Stok (Rp) value column, even as the SECOND cell of a true merge',
    $scmRows[0]['qty'] === '100' || $scmRows[0]['qty'] === 100, json_encode($scmRows[0]));
check('1d. readScmWorkbook preserves the source code verbatim', $scmRows[0]['code'] === $scmSkuA, (string) $scmRows[0]['code']);
@unlink($scmFile);

// A workbook whose "Stok Akhir" group has no qty/total hint at all must be
// refused, never guessed.
$ambiguousFile = tempnam(sys_get_temp_dir(), 'v2161_amb_') . '.xlsx';
ExcelWriterService::write($ambiguousFile, [
    'SCM' => [
        'headers' => [null, null, null, null, 'Stok Akhir', 'Stok Akhir'],
        'rows' => [
            ['No', 'Kode Barang', 'Nama Barang', 'Satuan', 'Kolom A', 'Kolom B'],
            [1, uid('SCM-X'), 'Barang X', 'KG', 1, 2],
        ],
    ],
]);
$ambiguousErr = expectException(ValidationException::class, fn () => StockOpnameBookStockService::readScmWorkbook($ambiguousFile));
check('1e. an unrecognizable "Stok Akhir" sub-column layout is refused, never guessed', $ambiguousErr instanceof ValidationException, $ambiguousErr ? get_class($ambiguousErr) : 'no exception');
@unlink($ambiguousFile);

// ============================================================
// 2-6 — importBaseline(): required coverage confirmation, classification,
// never touches inventory_batches, duplicate-file rejection.
// ============================================================
$itemA = makeItem($pdo, 'V2161-A', 'Item A', $kgUnitId);
$itemB = makeItem($pdo, 'V2161-B', 'Item B', $kgUnitId);
$itemMismatch = makeItem($pdo, 'V2161-MISM', 'Item Mismatch Unit', $kgUnitId); // no KARTON conversion
postOpeningIn($pdo, $itemA, $kgUnitId, $whId, 10, 1000, $admin['id']);
postOpeningIn($pdo, $itemB, $kgUnitId, $whId, 10, 1000, $admin['id']);
postOpeningIn($pdo, $itemMismatch, $kgUnitId, $whId, 10, 1000, $admin['id']);

// Session backdated so "now" (whenever this test actually runs) is always
// after the SO EOD cutoff — required for the LATE_PRE_CUTOFF /
// created_at-after-cutoff proofs below (same technique V2.16's own test
// uses for the identical reason).
$session = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$itemA, $itemB, $itemMismatch]));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $session]);
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $session, 'p1', [$p1['id']], $admin['id']));

$skuA = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemA}")->fetchColumn();
$skuB = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemB}")->fetchColumn();
$skuMismatch = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemMismatch}")->fetchColumn();
$unknownSku = uid('UNKNOWN');

$baselineFile = writeCsv([
    ['Kode Barang', 'Nama Barang', 'Satuan', 'Stok Akhir'],
    [$skuA, 'Item A', 'KG', '100'],
    [$skuB, 'Item B', 'KG', '50'],
    [$skuMismatch, 'Item Mismatch Unit', 'KARTON', '5'], // UNIT_MISMATCH: no KARTON conversion configured
    [$unknownSku, 'Tidak Dikenal', 'KG', '7'],            // UNMATCHED_SCM
]);

$invBefore = inventoryBatchesSnapshot($pdo);
$missingCoverageErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $session, $baselineFile, 'baseline.csv', $admin['id'], [])
));
check('2. importBaseline refuses when per-stream coverage is not confirmed', $missingCoverageErr instanceof ValidationException, $missingCoverageErr ? get_class($missingCoverageErr) : 'no exception');

$coverage = ['inout_through' => '2026-09-29 23:59:59', 'scaling_through' => '2026-09-29 23:59:59', 'adjustment_through' => '2026-09-29 23:59:59'];
$baselineResult = Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $session, $baselineFile, 'baseline.csv', $admin['id'], $coverage));
check('3. importBaseline classifies MATCHED rows correctly', $baselineResult['matched_count'] === 2, (string) $baselineResult['matched_count']);
check('4. importBaseline classifies UNIT_MISMATCH (no KARTON conversion for that item)', $baselineResult['unit_mismatch_count'] === 1, (string) $baselineResult['unit_mismatch_count']);
check('5. importBaseline classifies UNMATCHED_SCM (unknown code)', $baselineResult['unmatched_count'] === 1, (string) $baselineResult['unmatched_count']);
check('5b. importBaseline imports all 4 rows, nothing silently dropped', $baselineResult['row_count'] === 4, (string) $baselineResult['row_count']);

$invAfterBaseline = inventoryBatchesSnapshot($pdo);
check('6. importBaseline NEVER touches inventory_batches', $invBefore === $invAfterBaseline, "{$invBefore} vs {$invAfterBaseline}");

$batchRow = $pdo->prepare("SELECT * FROM stock_opname_reference_batches WHERE id = :id");
$batchRow->execute(['id' => $baselineResult['import_batch_id']]);
$batch = $batchRow->fetch();
check('6b. importBaseline stores the admin-confirmed coverage per stream, never inferred', $batch['baseline_inout_through'] === '2026-09-29 23:59:59', (string) $batch['baseline_inout_through']);

$dupeBaselineErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $session, $baselineFile, 'baseline.csv', $admin['id'], $coverage)
));
check('6c. re-importing the identical baseline file (same hash) is rejected', $dupeBaselineErr instanceof ValidationException, $dupeBaselineErr ? get_class($dupeBaselineErr) : 'no exception');

// ============================================================
// Movement import without a baseline is refused. A fresh item/warehouse
// ($whId2) is used since $session above stays OPEN on $whId for the
// whole test run (WarehouseLockService refuses a second concurrent
// opname session on the same warehouse).
// ============================================================
$itemNoBaseline = makeItem($pdo, 'V2161-NB', 'Item No Baseline', $kgUnitId);
$sessionNoBaseline = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId2, $admin['id'], [$itemNoBaseline]));
$skuNoBaseline = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemNoBaseline}")->fetchColumn();
$noBaselineFile = writeCsv([
    ['effective_at', 'document_reference', 'sku', 'nama_barang', 'movement_type', 'qty', 'unit'],
    ['2026-09-30 10:00:00', 'DOC-1', $skuNoBaseline, 'Item No Baseline', 'IN', '5', 'KG'],
]);
$noBaselineErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameBookStockService::importMovements($tx, $sessionNoBaseline, $noBaselineFile, 'moves.csv', $admin['id'])
));
check('7. importMovements refuses when no BASELINE batch exists yet for this session', $noBaselineErr instanceof ValidationException, $noBaselineErr ? get_class($noBaselineErr) : 'no exception');

// ============================================================
// 8-13 — importMovements(): inclusion_status classification covering
// INCLUDED / ALREADY_IN_BASELINE / AFTER_SO_CUTOFF (exact boundary) /
// UNMATCHED_ITEM / UNIT_MISMATCH / INVALID_DATE / DUPLICATE, plus the
// spec's own LATE INPUT TEST (Section 22) and AFTER-CUTOFF TEST
// (Section 23) by name.
// ============================================================
$movementFile = writeCsv([
    ['effective_at', 'document_reference', 'sku', 'nama_barang', 'movement_type', 'qty', 'unit'],
    ['2026-09-30 10:00:00', 'DOC-IN-1', $skuA, 'Item A', 'IN', '20', 'KG'],   // INCLUDED (before-count)
    ['2026-09-30 11:00:00', 'DOC-OUT-1', $skuA, 'Item A', 'OUT', '5', 'KG'],  // INCLUDED (before-count)
    ['2026-09-30 21:00:00', 'DOC-IN-2', $skuA, 'Item A', 'IN', '10', 'KG'],   // INCLUDED (after-count, LATE INPUT TEST row)
    ['2026-09-30 22:00:00', 'DOC-OUT-2', $skuA, 'Item A', 'OUT', '3', 'KG'],  // INCLUDED (after-count)
    ['2026-09-29 12:00:00', 'DOC-OLD', $skuA, 'Item A', 'IN', '999', 'KG'],   // ALREADY_IN_BASELINE (<= coverage cutoff)
    ['2026-10-01 00:00:00', 'DOC-FUTURE', $skuA, 'Item A', 'IN', '999', 'KG'], // AFTER_SO_CUTOFF — exact boundary, exclusive
    ['2026-09-30 09:00:00', 'DOC-UNKNOWN', $unknownSku, 'Tidak Dikenal', 'IN', '1', 'KG'], // UNMATCHED_ITEM
    ['2026-09-30 09:00:00', 'DOC-MISM', $skuMismatch, 'Item Mismatch Unit', 'IN', '1', 'KARTON'], // UNIT_MISMATCH
    ['not-a-date', 'DOC-BAD', $skuA, 'Item A', 'IN', '1', 'KG'],              // INVALID_DATE
]);
$moveResult = Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importMovements($tx, $session, $movementFile, 'moves.csv', $admin['id']));
check('8. importMovements classifies 4 INCLUDED rows', $moveResult['INCLUDED'] === 4, (string) $moveResult['INCLUDED']);
check('9. importMovements classifies ALREADY_IN_BASELINE (effective_at <= coverage cutoff)', $moveResult['ALREADY_IN_BASELINE'] === 1, (string) $moveResult['ALREADY_IN_BASELINE']);
check('10/23. AFTER-CUTOFF TEST: effective_at = SO EOD cutoff EXACTLY (2026-10-01 00:00:00) is EXCLUDED, boundary is exclusive', $moveResult['AFTER_SO_CUTOFF'] === 1, (string) $moveResult['AFTER_SO_CUTOFF']);
check('11. importMovements classifies UNMATCHED_ITEM', $moveResult['UNMATCHED_ITEM'] === 1, (string) $moveResult['UNMATCHED_ITEM']);
check('12. importMovements classifies UNIT_MISMATCH', $moveResult['UNIT_MISMATCH'] === 1, (string) $moveResult['UNIT_MISMATCH']);
check('13. importMovements classifies INVALID_DATE, never silently drops the row', $moveResult['INVALID_DATE'] === 1, (string) $moveResult['INVALID_DATE']);
check('13b. all 9 rows imported, nothing silently skipped', $moveResult['row_count'] === 9, (string) $moveResult['row_count']);

$lateRow = $pdo->prepare("SELECT late_pre_cutoff FROM stock_opname_reference_movements WHERE session_id = :sid AND document_reference = 'DOC-IN-2'");
$lateRow->execute(['sid' => $session]);
check('22. LATE INPUT TEST: effective_at 30 Sep (within session day), created_at after cutoff -> INCLUDED and tagged late_pre_cutoff', (int) $lateRow->fetchColumn() === 1);

$invAfterMovements = inventoryBatchesSnapshot($pdo);
check('14. importMovements NEVER touches inventory_batches', $invBefore === $invAfterMovements, "{$invBefore} vs {$invAfterMovements}");

// ---- Duplicate movement protection: re-importing the SAME rows (new file, same content) ----
$movementFileDupe = writeCsv([
    ['effective_at', 'document_reference', 'sku', 'nama_barang', 'movement_type', 'qty', 'unit'],
    ['2026-09-30 10:00:00', 'DOC-IN-1', $skuA, 'Item A', 'IN', '20', 'KG'], // identical movement -> DUPLICATE
    ['2026-09-30 15:00:00', 'DOC-IN-3', $skuA, 'Item A', 'IN', '2', 'KG'],  // genuinely new -> INCLUDED
]);
$moveResult2 = Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importMovements($tx, $session, $movementFileDupe, 'moves2.csv', $admin['id']));
check('15. a movement identical to an already-imported one (same item/effective_at/type/qty/doc) is tagged DUPLICATE, never silently re-applied', $moveResult2['DUPLICATE'] === 1, (string) $moveResult2['DUPLICATE']);
check('15b. a genuinely new movement in the same re-upload is still INCLUDED', $moveResult2['INCLUDED'] === 1, (string) $moveResult2['INCLUDED']);

// ============================================================
// 16 — Book Stock EOD formula (Section 7): baseline + eligible IN - OUT
// (+/- scaling/adjustment), using ONLY inclusion_status = INCLUDED.
// ============================================================
$bookStock = StockOpnameBookStockService::bookStockEodByItem($pdo, $session);
// itemA: baseline 100, eligible IN = 20+10+2 = 32, eligible OUT = 5+3 = 8 -> 100+32-8 = 124
check('16. Book Stock EOD = baseline + eligible IN - eligible OUT, excluding ALREADY_IN_BASELINE/AFTER_SO_CUTOFF/invalid/unmatched/duplicate rows',
    abs($bookStock[$itemA]['book_stock_eod'] - 124.0) < 0.000001, (string) $bookStock[$itemA]['book_stock_eod']);

// ============================================================
// 17-19 — counted_at: explicit pass-through on submitFinding(), default
// to "now" when omitted, and the controlled supervisor backfill path.
// ============================================================
function lastFindingId(PDO $pdo, int $sessionId, int $itemId, string $role): int
{
    $stmt = $pdo->prepare(
        'SELECT f.id FROM stock_opname_findings f
           JOIN stock_opname_lines l ON l.id = f.stock_opname_line_id
          WHERE l.session_id = :sid AND l.item_id = :item AND f.team_role = :role
          ORDER BY f.id DESC LIMIT 1'
    );
    $stmt->execute(['sid' => $sessionId, 'item' => $itemId, 'role' => strtoupper($role)]);
    return (int) $stmt->fetchColumn();
}

$claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $session, 'p1', $p1['id'], $itemB));
$explicitCountedAt = '2026-09-30 20:00:00';
Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $session, 'p1', $itemB,
    [
        'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 45.0]],
        'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
    ],
    null, $p1['id'], $claim['claim_token'], [], $explicitCountedAt
));
$findingIdB = lastFindingId($pdo, $session, $itemB, 'p1');
$findingRow = $pdo->prepare('SELECT counted_at FROM stock_opname_findings WHERE id = :id');
$findingRow->execute(['id' => $findingIdB]);
check('17. submitFinding() stores an explicit counted_at when provided (never silently defaulted to created_at)', $findingRow->fetchColumn() === $explicitCountedAt);

$claim2 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $session, 'p1', $p1['id'], $itemMismatch));
$beforeSubmit = date('Y-m-d H:i:s');
Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $session, 'p1', $itemMismatch,
    [
        'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10.0]],
        'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
    ],
    null, $p1['id'], $claim2['claim_token'], []
));
$findingIdMismatch = lastFindingId($pdo, $session, $itemMismatch, 'p1');
$findingRow2 = $pdo->prepare('SELECT counted_at FROM stock_opname_findings WHERE id = :id');
$findingRow2->execute(['id' => $findingIdMismatch]);
check('18. submitFinding() defaults counted_at to "now" when omitted', $findingRow2->fetchColumn() >= $beforeSubmit);

$backfillErr = expectException(ValidationException::class, fn () => Database::transaction(
    fn (PDO $tx) => StockOpnameService::backfillCountedAt($tx, $session, $findingIdB, '2026-09-29 08:00:00', $admin['id'], 'test backfill attempt')
));
check('19. backfillCountedAt() refuses to overwrite an already-set counted_at', $backfillErr instanceof ValidationException, $backfillErr ? get_class($backfillErr) : 'no exception');

// A pre-V2.16.1 finding with counted_at = NULL (simulated directly, since
// every finding submitted through this phase's own code path always sets
// one) IS eligible for the controlled backfill.
$pdo->prepare('UPDATE stock_opname_findings SET counted_at = NULL WHERE id = :id')->execute(['id' => $findingIdMismatch]);
Database::transaction(fn (PDO $tx) => StockOpnameService::backfillCountedAt($tx, $session, $findingIdMismatch, '2026-09-30 18:00:00', $admin['id'], 'historical remediation for test'));
$backfilledRow = $pdo->prepare('SELECT counted_at FROM stock_opname_findings WHERE id = :id');
$backfilledRow->execute(['id' => $findingIdMismatch]);
check('19b. backfillCountedAt() succeeds exactly once when counted_at is genuinely NULL', $backfilledRow->fetchColumn() === '2026-09-30 18:00:00', (string) $backfilledRow->fetchColumn());
$auditBackfill = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'stock_opname_findings' AND entity_id = :id AND action_code = 'STOCK_OPNAME_FINDING_COUNTED_AT_BACKFILL'");
$auditBackfill->execute(['id' => $findingIdMismatch]);
check('19c. counted_at backfill is recorded with an audit trail', (int) $auditBackfill->fetchColumn() > 0);

// ============================================================
// 20/21 — CRITICAL TEST (Section 21): Book Stock EOD vs Physical EOD,
// proving the two independent formulas agree to zero variance when the
// physical count exactly matches the theoretical pre-count book stock
// and all post-count movements are carried into both sides consistently
// (never double-counted between them — see
// StockOpnameBookStockService::physicalEodForLine()'s own docblock).
//
// A dedicated item/session is used so this scenario is never entangled
// with the classification fixtures above. Movement magnitudes (IN 20,
// OUT 5, IN 10, OUT 3) reproduce the spec's own CRITICAL TEST numbers;
// the physical raw count is set to exactly match the pre-count running
// book stock (100 + 20 - 5 = 115) so the mandated "Expected Variance =
// 0" outcome is demonstrated precisely, rather than conflated with a
// genuine opname discrepancy (a different, legitimate non-zero-variance
// scenario, not what Section 21 is testing).
// ============================================================
$itemCritical = makeItem($pdo, 'V2161-CRIT', 'Item Critical Test', $kgUnitId);
postOpeningIn($pdo, $itemCritical, $kgUnitId, $whId3, 10, 1000, $admin['id']);
$invBeforeCritical = inventoryBatchesSnapshot($pdo);
$sessionCrit = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId3, $admin['id'], [$itemCritical]));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $sessionCrit]);
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionCrit, 'p1', [$p1['id']], $admin['id']));
$skuCritical = (string) $pdo->query("SELECT sku FROM items WHERE id = {$itemCritical}")->fetchColumn();

$baselineCritFile = writeCsv([
    ['Kode Barang', 'Nama Barang', 'Satuan', 'Stok Akhir'],
    [$skuCritical, 'Item Critical Test', 'KG', '100'],
]);
Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $sessionCrit, $baselineCritFile, 'baseline-crit.csv', $admin['id'], $coverage));

$movementCritFile = writeCsv([
    ['effective_at', 'document_reference', 'sku', 'nama_barang', 'movement_type', 'qty', 'unit'],
    ['2026-09-30 10:00:00', 'CRIT-IN-1', $skuCritical, 'Item Critical Test', 'IN', '20', 'KG'],  // before count
    ['2026-09-30 11:00:00', 'CRIT-OUT-1', $skuCritical, 'Item Critical Test', 'OUT', '5', 'KG'], // before count
    ['2026-09-30 21:00:00', 'CRIT-IN-2', $skuCritical, 'Item Critical Test', 'IN', '10', 'KG'],  // after count
    ['2026-09-30 22:00:00', 'CRIT-OUT-2', $skuCritical, 'Item Critical Test', 'OUT', '3', 'KG'], // after count
]);
Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importMovements($tx, $sessionCrit, $movementCritFile, 'moves-crit.csv', $admin['id']));

$claimCrit = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionCrit, 'p1', $p1['id'], $itemCritical));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $sessionCrit, 'p1', $itemCritical,
    [
        'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 115.0]],
        'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
        'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0.0]],
    ],
    null, $p1['id'], $claimCrit['claim_token'], [], '2026-09-30 20:00:00'
));

$bookCrit = StockOpnameBookStockService::bookStockEodByItem($pdo, $sessionCrit);
check('21. CRITICAL TEST: Book Stock EOD = 100 + 20 - 5 + 10 - 3 = 122', abs($bookCrit[$itemCritical]['book_stock_eod'] - 122.0) < 0.000001, (string) $bookCrit[$itemCritical]['book_stock_eod']);

$reconCrit = StockOpnameBookStockService::reconciliation($pdo, $sessionCrit);
$critRow = null;
foreach ($reconCrit as $r) {
    if ($r['sku'] === $skuCritical) { $critRow = $r; }
}
check('21b. CRITICAL TEST: reconciliation() finds the line', $critRow !== null);
check('21c. CRITICAL TEST: Physical EOD = raw(115) + post-count IN(10) - OUT(3) = 122', abs($critRow['final_physical_eod'] - 122.0) < 0.000001, (string) $critRow['final_physical_eod']);
check('21d. CRITICAL TEST: Variance = Physical EOD - Book Stock EOD = 0 (exactly as mandated)', abs($critRow['variance']) < 0.000001, (string) $critRow['variance']);

$invAfterCritical = inventoryBatchesSnapshot($pdo);
check('21e. the entire CRITICAL TEST flow (baseline + movements + finding) NEVER touches inventory_batches beyond the test\'s own opening-stock postings', $invBeforeCritical === $invAfterCritical, "{$invBeforeCritical} vs {$invAfterCritical}");

// ============================================================
// 22 — Final EOD Excel export: draft always available, official refused
// on a non-POSTED FINDINGS_V1 session (never unblocks Checkpoint B).
// ============================================================
$draftPath = StockOpnameFinalExportService::draftReconciliationExport($pdo, $sessionCrit, $admin['id']);
check('22. draft EOD reconciliation export produces a real file', is_file($draftPath));
$reconSheet = XlsxReaderService::read($draftPath, 'Rekonsiliasi Final');
check('22b. draft export\'s Rekonsiliasi Final sheet contains the critical-test SKU', str_contains(json_encode($reconSheet), $skuCritical));
@unlink($draftPath);

$officialErr = expectException(FindingsCheckpointBRequiredException::class, fn () => StockOpnameFinalExportService::officialReconciliationExport($pdo, $sessionCrit, $admin['id']));
check('23. official EOD export on an OPEN FINDINGS_V1 session is refused, never unblocks Checkpoint B', $officialErr instanceof FindingsCheckpointBRequiredException, $officialErr ? get_class($officialErr) : 'no exception');

$checkpointGuard = $pdo->query("SHOW TABLES LIKE 'stock_opname_sessions'")->fetchColumn();
check('24. FINDINGS_V1_CHECKPOINT_B_REQUIRED guard still intact (finalize/post still blocked for FINDINGS_V1)',
    expectException(FindingsCheckpointBRequiredException::class, fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionCrit, $admin['id']))) instanceof FindingsCheckpointBRequiredException);

echo "\n" . count(array_filter($results)) . " / " . count($results) . " PASSED\n";
exit(count(array_filter($results)) === count($results) ? 0 : 1);
