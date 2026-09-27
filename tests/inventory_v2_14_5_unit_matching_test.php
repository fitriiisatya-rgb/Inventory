<?php
declare(strict_types=1);

/**
 * PHASE V2.14.5 — unit matching must honor valid item unit conversions.
 *
 * WarehouseCutoverService::matchItems() previously classified a line
 * UNIT_MISMATCH whenever source_unit didn't strcasecmp-equal the master
 * item's base unit CODE — never checking item_unit_conversions at all, and
 * never running the source string through the project's existing
 * UnitNormalizationService. This produced false positives whenever the
 * master item's compatible unit was a non-base conversion (e.g. Gram on a
 * KG-base item) or merely a differently-spelled/cased alias of the base
 * unit (e.g. "Gram" vs canonical "GR").
 *
 * This test proves: (1) the corrected matching logic against synthetic
 * items covering every required scenario, (2) that reconciliation_status/
 * exception_codes (a separate, already-computed historical fact) are
 * never touched, (3) NOT_FOUND/NAME_MISMATCH are unchanged, (4) re-running
 * matchItems() is safe and idempotent, (5) zero inventory mutation, and
 * (6) real BEFORE/AFTER evidence against the actual accepted 1,004-row
 * Karang Tengah reconciliation fixture.
 *
 * Usage: php tests/inventory_v2_14_5_unit_matching_test.php
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
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/WarehouseCutoverService.php';
require_once __DIR__ . '/../services/WarehouseCutoverImportService.php';

use App\Services\Database;
use App\Services\UnitConversionService;
use App\Services\UnitNormalizationService;
use App\Services\WarehouseCutoverService;
use App\Services\WarehouseCutoverImportService;
use App\Services\ExcelWriterService;

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
$adminUserId = (function () use ($pdo, $superRoleId) {
    $u = uid('v2145admin');
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $superRoleId]);
    return (int) $pdo->lastInsertId();
})();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 0, 1)")->execute();
$karangTengahId = (int) $pdo->lastInsertId();

function unitId(PDO $pdo, string $code): int
{
    return (int) $pdo->query("SELECT id FROM units WHERE code='{$code}'")->fetchColumn();
}

function makeItemWithBase(PDO $pdo, string $sku, string $name, int $baseUnitId): int
{
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'identity (base unit)');
    return $id;
}

function addNonBaseConversion(PDO $pdo, int $itemId, int $unitId, float $factor, string $note): void
{
    UnitConversionService::openNewVersion($pdo, $itemId, $unitId, $factor, '2020-01-01 00:00:00', null, $note);
}

function makeCutoverLine(PDO $pdo, int $cutoverId, string $sku, string $name, string $unit, int $rowNum): void
{
    $pdo->prepare(
        'INSERT INTO warehouse_cutover_lines (cutover_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, source_row_reference)
         VALUES (:cid, :sku, :name, :unit, :qty, :price, :status, :mapping, :decision, :rownum)'
    )->execute([
        'cid' => $cutoverId, 'sku' => $sku, 'name' => $name, 'unit' => $unit,
        'qty' => 10.0, 'price' => 1000.0, 'status' => 'PASS', 'mapping' => 'NOT_FOUND', 'decision' => 'PENDING', 'rownum' => $rowNum,
    ]);
}

$grId = unitId($pdo, 'GR');
$kgId = unitId($pdo, 'KG');
$ltrId = unitId($pdo, 'LTR');
$mlId = unitId($pdo, 'ML');
$pcsId = unitId($pdo, 'PCS');

// ============================================================
// Micro-scenarios 1-9 — a dedicated cutover with hand-built items/lines.
// ============================================================
echo "== Micro-scenarios (direct service) ==\n";
$cutoverId = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2145-micro', 'opening_as_of' => '2026-09-28', 'created_by' => $adminUserId,
]);

// 1. source "Gram" vs valid master gram/base conversion -> MATCHED
$sku1 = uid('V2145-1'); $item1 = makeItemWithBase($pdo, $sku1, 'Item One', $grId);
makeCutoverLine($pdo, $cutoverId, $sku1, 'Item One', 'Gram', 2);

// 2. source "gram" (lowercase) vs valid GR/base identity -> MATCHED
$sku2 = uid('V2145-2'); $item2 = makeItemWithBase($pdo, $sku2, 'Item Two', $grId);
makeCutoverLine($pdo, $cutoverId, $sku2, 'Item Two', 'gram', 3);

// 3. source "liter" vs valid liter conversion (base) -> MATCHED
$sku3 = uid('V2145-3'); $item3 = makeItemWithBase($pdo, $sku3, 'Item Three', $ltrId);
makeCutoverLine($pdo, $cutoverId, $sku3, 'Item Three', 'liter', 4);

// 4. source unit valid through a NON-base item_unit_conversions row.
// SUPERSEDED BY V2.14.6 (services/WarehouseCutoverService.php): this was
// originally asserted MATCHED under V2.14.5's rule (base_unit_id OR any
// item_unit_conversions row). That rule was found unsafe — ACCEPT_SOURCE
// takes theoretical_closing_qty as-is into approved_qty, and loadOpening()
// posts it in the item's BASE unit with no conversion step, so a genuine
// non-base unit like this must stay UNIT_MISMATCH and require an explicit
// BUSINESS_OVERRIDE. See tests/inventory_v2_14_6_base_unit_safe_matching_test.php.
$sku4 = uid('V2145-4'); $item4 = makeItemWithBase($pdo, $sku4, 'Item Four', $kgId);
addNonBaseConversion($pdo, $item4, $grId, 0.001, 'purchase unit: 1 Gram = 0.001 KG');
makeCutoverLine($pdo, $cutoverId, $sku4, 'Item Four', 'Gram', 5);

// 5. no matching conversion at all -> UNIT_MISMATCH
$sku5 = uid('V2145-5'); $item5 = makeItemWithBase($pdo, $sku5, 'Item Five', $kgId);
makeCutoverLine($pdo, $cutoverId, $sku5, 'Item Five', 'Liter', 6);

// 6. case-only differences must never create UNIT_MISMATCH
$sku6 = uid('V2145-6'); $item6 = makeItemWithBase($pdo, $sku6, 'Item Six', $grId);
makeCutoverLine($pdo, $cutoverId, $sku6, 'Item Six', 'GRAM', 7);

// 8. NOT_FOUND unchanged — no item at all for this SKU
$sku8 = uid('V2145-8-NOTFOUND');
makeCutoverLine($pdo, $cutoverId, $sku8, 'Ghost Item', 'Gram', 8);

// 9. NAME_MISMATCH unchanged — unit matches, name does not
$sku9 = uid('V2145-9'); $item9 = makeItemWithBase($pdo, $sku9, 'Real Master Name', $pcsId);
makeCutoverLine($pdo, $cutoverId, $sku9, 'Totally Different Source Name', 'Pcs', 9);

$counts = WarehouseCutoverService::matchItems($pdo, $cutoverId);
echo "matchItems() counts: " . json_encode($counts) . "\n";

function statusFor(PDO $pdo, int $cutoverId, string $sku): array
{
    $stmt = $pdo->prepare('SELECT mapping_status, item_id FROM warehouse_cutover_lines WHERE cutover_id = :cid AND source_sku = :sku');
    $stmt->execute(['cid' => $cutoverId, 'sku' => $sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

check('1. source "Gram" vs valid GR base conversion -> MATCHED', statusFor($pdo, $cutoverId, $sku1)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku1)));
check('2. source "gram" (lowercase) vs valid GR/base identity -> MATCHED', statusFor($pdo, $cutoverId, $sku2)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku2)));
check('3. source "liter" vs valid liter (base) conversion -> MATCHED', statusFor($pdo, $cutoverId, $sku3)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku3)));
check('4. source unit valid ONLY through a NON-base item_unit_conversions row -> UNIT_MISMATCH (V2.14.6 safety correction; item_id still mapped)', statusFor($pdo, $cutoverId, $sku4)['mapping_status'] === 'UNIT_MISMATCH' && (int) statusFor($pdo, $cutoverId, $sku4)['item_id'] === $item4, json_encode(statusFor($pdo, $cutoverId, $sku4)));
check('5. no matching conversion at all -> UNIT_MISMATCH', statusFor($pdo, $cutoverId, $sku5)['mapping_status'] === 'UNIT_MISMATCH', json_encode(statusFor($pdo, $cutoverId, $sku5)));
check('6. case-only difference ("GRAM" vs GR) does not create UNIT_MISMATCH', statusFor($pdo, $cutoverId, $sku6)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku6)));
check('8. NOT_FOUND unchanged for a SKU with no master item at all', statusFor($pdo, $cutoverId, $sku8)['mapping_status'] === 'NOT_FOUND', json_encode(statusFor($pdo, $cutoverId, $sku8)));
check('9. NAME_MISMATCH unchanged when unit matches but name does not', statusFor($pdo, $cutoverId, $sku9)['mapping_status'] === 'NAME_MISMATCH', json_encode(statusFor($pdo, $cutoverId, $sku9)));

// ============================================================
// 10. Re-match safety: rerun matchItems(), confirm idempotent + every
// source/decision field is preserved untouched.
// ============================================================
echo "\n== 10. Re-match safety ==\n";
// Manually resolve one line first (simulating a prior human decision) to
// prove matchItems() never disturbs decision fields.
$line4Id = (int) $pdo->query("SELECT id FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} AND source_sku='{$sku4}'")->fetchColumn();
WarehouseCutoverService::resolveLine($pdo, $cutoverId, $line4Id, ['decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 42.5, 'approved_unit_cost' => 777.0, 'notes' => 'manual decision before rerun', 'actor_id' => $adminUserId, 'actor_username' => 'v2145']);

$beforeRerun = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, decision, approved_qty, approved_unit_cost, notes FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$countsRerun = WarehouseCutoverService::matchItems($pdo, $cutoverId);
$afterRerun = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, decision, approved_qty, approved_unit_cost, notes FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

check('10. Rerunning matchItems() produces the identical counts (idempotent)', $countsRerun === $counts, json_encode(['first' => $counts, 'rerun' => $countsRerun]));
check('10. Rerunning matchItems() preserves every source_sku/source_name/source_unit/theoretical_closing_qty/source_price/reconciliation_status field exactly', $beforeRerun === $afterRerun, 'diff: ' . json_encode(array_diff(array_map('json_encode', $beforeRerun), array_map('json_encode', $afterRerun))));

// ============================================================
// 11. Zero inventory mutation from matchItems() (direct-service + rerun).
// ============================================================
echo "\n== 11. Zero inventory mutation ==\n";
$batchesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$txBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
WarehouseCutoverService::matchItems($pdo, $cutoverId);
$batchesAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$txAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
check('11. matchItems() creates zero inventory_batches rows', $batchesAfter === $batchesBefore, "{$batchesBefore} -> {$batchesAfter}");
check('11. matchItems() creates zero inventory_transactions rows', $txAfter === $txBefore, "{$txBefore} -> {$txAfter}");

$ktCheck = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('11b. KARANG_TENGAH remains is_active=0/activation_locked=1 throughout', (int) $ktCheck['is_active'] === 0 && (int) $ktCheck['activation_locked'] === 1);

// ============================================================
// Real fixture — full 1,004-row Karang Tengah reconciliation, imported
// exactly as V2.14's own test does, matched against a representative
// synthetic master catalog built FROM the fixture's own SKU/name/unit
// columns (this sandbox has no access to the real production item
// master, so exact production counts cannot be reproduced — see the
// final report). 7 SKUs get a DELIBERATE master-data setup mirroring the
// real production diagnosis (name/unit sourced from the fixture itself,
// plus targeted conversions), and the SOURCE_UNIT_CONFLICT pair
// (999208/999209) get a master item WITH a valid Pcs conversion, to prove
// mapping_status can now legitimately become MATCHED while
// reconciliation_status/exception_codes stay untouched.
// ============================================================
echo "\n== Real 1,004-row fixture: BEFORE/AFTER evidence ==\n";
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/karang_tengah_reconciliation_v2.json'), true);
$fh = $fixture['headers'];
$fidx = array_flip($fh);
$frows = $fixture['rows'];
check('Fixture loaded with all 1,004 rows', count($frows) === 1004, (string) count($frows));

$xlsxPath = tempnam(sys_get_temp_dir(), 'v2145_fixture_') . '.xlsx';
ExcelWriterService::write($xlsxPath, ['Reconciliation' => ['headers' => $fh, 'rows' => $frows]]);

$cutover2Id = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2145-full-fixture', 'opening_as_of' => '2026-09-28', 'created_by' => $adminUserId,
]);
$importSummary = WarehouseCutoverImportService::import($pdo, $cutover2Id, $xlsxPath);
check('Full fixture import succeeds, all 1,004 rows land on the cutover', ($importSummary['imported'] ?? $importSummary['total_rows'] ?? null) !== null);
$actualLineCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id}")->fetchColumn();
check('All 1,004 imported rows are present as cutover lines', $actualLineCount === 1004, (string) $actualLineCount);

// Build the representative master catalog. Every SKU gets an item whose
// name/unit is taken directly from the fixture's own Name/Unit columns —
// this is what makes the bulk of the 1,004 rows a stable, honest
// "already-correct" control group in BOTH the before and after run,
// since the fixture's own unit strings (kg/Kg/KG/gram/Gram/Gr/pcs/Pcs/...)
// all normalize to the SAME canonical base unit either way. A handful of
// SKUs are deliberately perturbed to mirror the real production
// diagnosis: some get a non-base conversion instead of a base-unit match
// (proving the fix's #2 condition), 999208/999209 get the documented
// SOURCE_UNIT_CONFLICT treatment, one SKU is left with no master item at
// all (NOT_FOUND control), and one SKU gets a deliberately different name
// (NAME_MISMATCH control).
$targetedUnitConflict = ['999208', '999209'];
$deliberateNonBaseConversion = ['700113', '700115', '800401', '800402', '800510'];
$deliberateNotFound = null;
$deliberateNameMismatch = null;
$madeSkus = [];
foreach ($frows as $i => $r) {
    $sku = (string) $r[$fidx['SKU']];
    $name = (string) $r[$fidx['Name']];
    $unitRaw = (string) $r[$fidx['Unit']];
    if (isset($madeSkus[$sku])) { continue; }
    $madeSkus[$sku] = true;

    if ($deliberateNotFound === null && $r[$fidx['Reconciliation Status']] === 'NO_ACTIVITY') {
        $deliberateNotFound = $sku;
        continue; // no master item created at all -> NOT_FOUND
    }
    $normalizedUnitId = UnitNormalizationService::resolveUnitId($pdo, $unitRaw);
    if ($normalizedUnitId === null) { continue; } // unresolvable unit string, skip (rare/none expected)

    if (in_array($sku, $targetedUnitConflict, true)) {
        // Master already has a valid Pcs conversion, per the production
        // diagnosis — but NEVER touches this line's own already-computed
        // reconciliation_status/exception_codes (SOURCE_UNIT_CONFLICT +
        // NEGATIVE_THEORETICAL_CLOSING, CRITICAL), which came from the
        // original import, not from matchItems().
        $pcsIdLocal = unitId($pdo, 'PCS');
        $kgIdLocal = unitId($pdo, 'KG');
        $itemId = makeItemWithBase($pdo, $sku, $name, $kgIdLocal);
        addNonBaseConversion($pdo, $itemId, $pcsIdLocal, 1.0, 'valid Pcs conversion (production diagnosis)');
        continue;
    }
    if (in_array($sku, $deliberateNonBaseConversion, true)) {
        // Give these a DIFFERENT base unit than the source, but a valid
        // non-base conversion for the source's own unit — proving
        // condition #2 (non-base item_unit_conversions row) specifically.
        $altBase = $normalizedUnitId === unitId($pdo, 'GR') ? unitId($pdo, 'KG') : unitId($pdo, 'PCS');
        $itemId = makeItemWithBase($pdo, $sku, $name, $altBase);
        addNonBaseConversion($pdo, $itemId, $normalizedUnitId, 0.5, 'purchase unit conversion (production diagnosis)');
        continue;
    }
    if ($deliberateNameMismatch === null && $r[$fidx['Reconciliation Status']] === 'PASS') {
        $deliberateNameMismatch = $sku;
        makeItemWithBase($pdo, $sku, $name . ' (MASTER RENAMED)', $normalizedUnitId);
        continue;
    }
    // Ordinary control-group row: exact name + exact (normalized) base unit.
    makeItemWithBase($pdo, $sku, $name, $normalizedUnitId);
}
echo "Deliberate NOT_FOUND control SKU: {$deliberateNotFound}\n";
echo "Deliberate NAME_MISMATCH control SKU: {$deliberateNameMismatch}\n";

// ---- BEFORE: simulate the OLD (pre-V2.14.5) matching logic exactly, on
// this same imported+seeded dataset, without touching the new
// matchItems() implementation's own DB writes yet. ----
function simulateOldMatchItems(PDO $pdo, int $cutoverId): array
{
    $lines = $pdo->query("SELECT source_sku, source_name, source_unit FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId}")->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt = $pdo->prepare('SELECT i.id, i.name, u.code AS unit_code FROM items i JOIN units u ON u.id = i.base_unit_id WHERE i.sku = :sku');
    $counts = ['MATCHED' => 0, 'NOT_FOUND' => 0, 'NAME_MISMATCH' => 0, 'UNIT_MISMATCH' => 0];
    foreach ($lines as $line) {
        $itemStmt->execute(['sku' => $line['source_sku']]);
        $item = $itemStmt->fetch();
        if ($item === false) { $counts['NOT_FOUND']++; continue; }
        $unitMatches = strcasecmp(trim((string) $item['unit_code']), trim((string) $line['source_unit'])) === 0;
        $nameMatches = strcasecmp(trim((string) $item['name']), trim((string) $line['source_name'])) === 0;
        $status = !$unitMatches ? 'UNIT_MISMATCH' : (!$nameMatches ? 'NAME_MISMATCH' : 'MATCHED');
        $counts[$status]++;
    }
    return $counts;
}

$beforeCounts = simulateOldMatchItems($pdo, $cutover2Id);
echo "BEFORE (old strcasecmp-vs-base-unit-code logic) counts: " . json_encode($beforeCounts) . "\n";

$afterCounts = WarehouseCutoverService::matchItems($pdo, $cutover2Id);
echo "AFTER  (V2.14.5 normalized base-or-conversion logic) counts: " . json_encode($afterCounts) . "\n";

check('BEFORE+AFTER counts sum to 1,004 both times', array_sum($beforeCounts) === 1004 && array_sum($afterCounts) === 1004, json_encode(['before_sum' => array_sum($beforeCounts), 'after_sum' => array_sum($afterCounts)]));
check('AFTER has fewer (or equal) UNIT_MISMATCH than BEFORE (the fix only ever RECLASSIFIES false positives toward MATCHED, never the reverse)', $afterCounts['UNIT_MISMATCH'] <= $beforeCounts['UNIT_MISMATCH'], json_encode(['before' => $beforeCounts['UNIT_MISMATCH'], 'after' => $afterCounts['UNIT_MISMATCH']]));
check('AFTER has more (or equal) MATCHED than BEFORE', $afterCounts['MATCHED'] >= $beforeCounts['MATCHED'], json_encode(['before' => $beforeCounts['MATCHED'], 'after' => $afterCounts['MATCHED']]));
check('NOT_FOUND count is unchanged between BEFORE and AFTER', $afterCounts['NOT_FOUND'] === $beforeCounts['NOT_FOUND'], json_encode(['before' => $beforeCounts['NOT_FOUND'], 'after' => $afterCounts['NOT_FOUND']]));

// 7. 999208/999209 retain SOURCE_UNIT_CONFLICT + CRITICAL after the fix,
// even though their mapping_status may now legitimately be MATCHED.
foreach ($targetedUnitConflict as $sku) {
    $row = $pdo->query("SELECT mapping_status, reconciliation_status, exception_codes FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$sku}'")->fetch(PDO::FETCH_ASSOC);
    check("7. SKU {$sku} retains reconciliation_status=CRITICAL after the fix", $row['reconciliation_status'] === 'CRITICAL', json_encode($row));
    check("7. SKU {$sku} retains SOURCE_UNIT_CONFLICT in exception_codes after the fix", str_contains((string) $row['exception_codes'], 'SOURCE_UNIT_CONFLICT'), json_encode($row));
}

// Category breakdown of whichever rows are STILL UNIT_MISMATCH after the fix.
$remainingUnitMismatch = $pdo->query("SELECT reconciliation_status, COUNT(*) c FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND mapping_status='UNIT_MISMATCH' GROUP BY reconciliation_status")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Remaining UNIT_MISMATCH rows by reconciliation_status: " . json_encode($remainingUnitMismatch) . "\n";

// Deliberate control checks.
if ($deliberateNotFound !== null) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$deliberateNotFound}'")->fetch(PDO::FETCH_ASSOC);
    check('8. NOT_FOUND control SKU (no master item created) is still NOT_FOUND after the fix', $row['mapping_status'] === 'NOT_FOUND', json_encode($row));
}
if ($deliberateNameMismatch !== null) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$deliberateNameMismatch}'")->fetch(PDO::FETCH_ASSOC);
    check('9. NAME_MISMATCH control SKU (renamed master) is still NAME_MISMATCH after the fix', $row['mapping_status'] === 'NAME_MISMATCH', json_encode($row));
}
// SUPERSEDED BY V2.14.6: these SKUs were originally asserted MATCHED (or
// better) under V2.14.5's unsafe "base OR any conversion row" rule. They
// now correctly stay UNIT_MISMATCH — see
// tests/inventory_v2_14_6_base_unit_safe_matching_test.php for the full
// safety proof (item_id stays mapped; ACCEPT_SOURCE is rejected by
// loadOpening(); BUSINESS_OVERRIDE is required to load such a line).
foreach ($deliberateNonBaseConversion as $sku) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$sku}'")->fetch(PDO::FETCH_ASSOC);
    check("Non-base-conversion control SKU {$sku} correctly stays UNIT_MISMATCH after the V2.14.6 safety correction", $row['mapping_status'] === 'UNIT_MISMATCH', json_encode($row));
}

// Rerun on the full fixture cutover too, confirming idempotence at scale
// and zero inventory mutation.
$batchesBeforeFull = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$sourceSnapshotBefore = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, reconciliation_status, exception_codes FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$afterCountsRerun = WarehouseCutoverService::matchItems($pdo, $cutover2Id);
$sourceSnapshotAfter = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, reconciliation_status, exception_codes FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$batchesAfterFull = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();

check('10. Full-fixture rerun of matchItems() is idempotent (identical counts)', $afterCountsRerun === $afterCounts, json_encode(['first' => $afterCounts, 'rerun' => $afterCountsRerun]));
check('10. Full-fixture rerun preserves every source field + reconciliation_status/exception_codes exactly', $sourceSnapshotBefore === $sourceSnapshotAfter);
check('11. Full-fixture matchItems() run+rerun creates zero inventory_batches rows', $batchesAfterFull === $batchesBeforeFull, "{$batchesBeforeFull} -> {$batchesAfterFull}");

echo "\n=== EVIDENCE SUMMARY ===\n";
echo "BEFORE: " . json_encode($beforeCounts) . "\n";
echo "AFTER:  " . json_encode($afterCounts) . "\n";
echo "Remaining UNIT_MISMATCH by reconciliation_status: " . json_encode($remainingUnitMismatch) . "\n";

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
