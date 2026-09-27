<?php
declare(strict_types=1);

/**
 * PHASE V2.14.6 — SAFETY HOTFIX: base-unit-only safe matching.
 *
 * V2.14.5's matchItems() considered a source unit "compatible" (eligible
 * for mapping_status=MATCHED) when its normalized id equalled the item's
 * base_unit_id OR appeared in ANY item_unit_conversions row for that item
 * (any version, current or historical). That second condition is unsafe
 * given the CURRENT cutover data model: resolveLine()'s ACCEPT_SOURCE
 * takes theoretical_closing_qty/source_price AS-IS into approved_qty/
 * approved_unit_cost (no unit conversion applied), and loadOpening() later
 * posts approved_qty as a quantity IN THE ITEM'S BASE UNIT. So a line whose
 * source_unit was a genuine, correctly-configured NON-base unit (e.g. Gram
 * on a KG-base item, with a real 1 GR = 0.001 KG conversion on file) could
 * be classified MATCHED under V2.14.5, and ACCEPT_SOURCE could then post
 * the source's Gram quantity as though it were that many base-unit KG — a
 * 1000x quantity/cost error, not a harmless alias false positive.
 *
 * V2.14.6 corrects this: a source unit is compatible (may become MATCHED)
 * ONLY when its normalized unit_id equals the item's own base_unit_id.
 * ANY other resolved unit — even one with a real, currently-open OR a
 * historical/closed item_unit_conversions row — stays UNIT_MISMATCH:
 * item_id may still be set, but an explicit human BUSINESS_OVERRIDE (with
 * its own approved_qty/approved_unit_cost, never auto-derived from the
 * source's non-base figures) is required before it can ever be accepted.
 *
 * This test proves: (1) base-unit alias resolution still works (the good
 * part of V2.14.5 is kept), (2) the CRITICAL SAFETY CASE — a genuine
 * non-base unit conversion must never auto-elevate to MATCHED, and the
 * full resolveLine()->loadOpening() chain actually rejects ACCEPT_SOURCE
 * and requires BUSINESS_OVERRIDE, (3) a HISTORICAL/closed conversion row
 * must not elevate to MATCHED either, (4) NOT_FOUND/NAME_MISMATCH are
 * unchanged, (5) re-running matchItems() is safe and idempotent, (6) zero
 * inventory mutation from matchItems() itself, (7) 999208/999209 retain
 * their historical reconciliation_status/exception_codes untouched, and
 * (8) a realistic before(V2.14.5)/after(V2.14.6) comparison against the
 * real accepted 1,004-row Karang Tengah reconciliation fixture.
 *
 * Usage: php tests/inventory_v2_14_6_base_unit_safe_matching_test.php
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
use App\Services\ValidationException;

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
    $u = uid('v2146admin');
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

/** A genuinely valid, CURRENTLY OPEN non-base conversion (valid_to IS NULL). */
function addOpenNonBaseConversion(PDO $pdo, int $itemId, int $unitId, float $factor, string $note): void
{
    UnitConversionService::openNewVersion($pdo, $itemId, $unitId, $factor, '2020-01-01 00:00:00', null, $note);
}

/** A genuinely valid but now HISTORICAL/CLOSED conversion (valid_to IS NOT NULL). */
function addClosedNonBaseConversion(PDO $pdo, int $itemId, int $unitId, float $factor, string $note): void
{
    UnitConversionService::openNewVersion($pdo, $itemId, $unitId, $factor, '2019-01-01 00:00:00', null, $note);
    $pdo->prepare('UPDATE item_unit_conversions SET valid_to = :vt WHERE item_id = :iid AND unit_id = :uid AND valid_to IS NULL')
        ->execute(['vt' => '2021-01-01 00:00:00', 'iid' => $itemId, 'uid' => $unitId]);
}

function makeCutoverLine(PDO $pdo, int $cutoverId, string $sku, string $name, string $unit, int $rowNum, float $qty = 10.0, float $price = 1000.0, string $reconStatus = 'PASS'): void
{
    $pdo->prepare(
        'INSERT INTO warehouse_cutover_lines (cutover_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, source_row_reference)
         VALUES (:cid, :sku, :name, :unit, :qty, :price, :status, :mapping, :decision, :rownum)'
    )->execute([
        'cid' => $cutoverId, 'sku' => $sku, 'name' => $name, 'unit' => $unit,
        'qty' => $qty, 'price' => $price, 'status' => $reconStatus, 'mapping' => 'NOT_FOUND', 'decision' => 'PENDING', 'rownum' => $rowNum,
    ]);
}

function statusFor(PDO $pdo, int $cutoverId, string $sku): array
{
    $stmt = $pdo->prepare('SELECT mapping_status, item_id FROM warehouse_cutover_lines WHERE cutover_id = :cid AND source_sku = :sku');
    $stmt->execute(['cid' => $cutoverId, 'sku' => $sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$grId = unitId($pdo, 'GR');
$kgId = unitId($pdo, 'KG');
$ltrId = unitId($pdo, 'LTR');
$pcsId = unitId($pdo, 'PCS');

// ============================================================
// Micro-scenarios 1-12 — a dedicated DRAFT cutover, direct-service calls.
// ============================================================
echo "== Micro-scenarios (direct service) ==\n";
$cutoverId = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2146-micro', 'opening_as_of' => '2026-09-28', 'created_by' => $adminUserId,
]);

// 1. source "Gram" vs base GR -> MATCHED
$sku1 = uid('V2146-1'); makeItemWithBase($pdo, $sku1, 'Item One', $grId);
makeCutoverLine($pdo, $cutoverId, $sku1, 'Item One', 'Gram', 2);

// 2. source "gram" (lowercase) vs base GR -> MATCHED
$sku2 = uid('V2146-2'); makeItemWithBase($pdo, $sku2, 'Item Two', $grId);
makeCutoverLine($pdo, $cutoverId, $sku2, 'Item Two', 'gram', 3);

// 3. source "Gr" vs base GR -> MATCHED
$sku3 = uid('V2146-3'); makeItemWithBase($pdo, $sku3, 'Item Three', $grId);
makeCutoverLine($pdo, $cutoverId, $sku3, 'Item Three', 'Gr', 4);

// 4. source "KG" vs base KG -> MATCHED
$sku4 = uid('V2146-4'); makeItemWithBase($pdo, $sku4, 'Item Four', $kgId);
makeCutoverLine($pdo, $cutoverId, $sku4, 'Item Four', 'KG', 5);

// 5. source "liter" vs base LTR -> MATCHED
$sku5 = uid('V2146-5'); makeItemWithBase($pdo, $sku5, 'Item Five', $ltrId);
makeCutoverLine($pdo, $cutoverId, $sku5, 'Item Five', 'liter', 6);

// 6. CRITICAL SAFETY CASE: source GR on a KG-base item, with a genuinely
// valid, currently OPEN GR item_unit_conversions row on file -> must stay
// UNIT_MISMATCH (item_id still mapped). Never auto-elevated to MATCHED.
$sku6 = uid('V2146-6-SAFETY'); $item6 = makeItemWithBase($pdo, $sku6, 'Item Six Safety', $kgId);
addOpenNonBaseConversion($pdo, $item6, $grId, 0.001, 'purchase unit: 1 Gram = 0.001 KG (genuinely valid, currently open)');
makeCutoverLine($pdo, $cutoverId, $sku6, 'Item Six Safety', 'Gram', 7);

// 7. source PCS on a KG-base item, with a HISTORICAL/CLOSED PCS conversion
// (valid_to IS NOT NULL, no longer the open version) -> must ALSO stay
// UNIT_MISMATCH — a historical row must never by itself permit MATCHED.
$sku7 = uid('V2146-7-HIST'); $item7 = makeItemWithBase($pdo, $sku7, 'Item Seven Historical', $kgId);
addClosedNonBaseConversion($pdo, $item7, $pcsId, 1.0, 'historical Pcs conversion, now closed');
makeCutoverLine($pdo, $cutoverId, $sku7, 'Item Seven Historical', 'Pcs', 8);

// 8. no matching conversion at all -> UNIT_MISMATCH (baseline, unchanged)
$sku8 = uid('V2146-8'); makeItemWithBase($pdo, $sku8, 'Item Eight', $kgId);
makeCutoverLine($pdo, $cutoverId, $sku8, 'Item Eight', 'Liter', 9);

// 9. case-only difference ("GRAM" vs GR) must never create UNIT_MISMATCH
$sku9 = uid('V2146-9'); makeItemWithBase($pdo, $sku9, 'Item Nine', $grId);
makeCutoverLine($pdo, $cutoverId, $sku9, 'Item Nine', 'GRAM', 10);

// 10. NOT_FOUND unchanged — no item at all for this SKU
$sku10 = uid('V2146-10-NOTFOUND');
makeCutoverLine($pdo, $cutoverId, $sku10, 'Ghost Item', 'Gram', 11);

// 11. NAME_MISMATCH unchanged — unit matches, name does not
$sku11 = uid('V2146-11'); makeItemWithBase($pdo, $sku11, 'Real Master Name', $pcsId);
makeCutoverLine($pdo, $cutoverId, $sku11, 'Totally Different Source Name', 'Pcs', 12);

$counts = WarehouseCutoverService::matchItems($pdo, $cutoverId);
echo "matchItems() counts: " . json_encode($counts) . "\n";

check('1. source "Gram" vs base GR -> MATCHED', statusFor($pdo, $cutoverId, $sku1)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku1)));
check('2. source "gram" (lowercase) vs base GR -> MATCHED', statusFor($pdo, $cutoverId, $sku2)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku2)));
check('3. source "Gr" vs base GR -> MATCHED', statusFor($pdo, $cutoverId, $sku3)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku3)));
check('4. source "KG" vs base KG -> MATCHED', statusFor($pdo, $cutoverId, $sku4)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku4)));
check('5. source "liter" vs base LTR -> MATCHED', statusFor($pdo, $cutoverId, $sku5)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku5)));

$line6 = statusFor($pdo, $cutoverId, $sku6);
check('6. CRITICAL SAFETY: source GR on KG-base item w/ a valid OPEN GR conversion -> UNIT_MISMATCH (never auto-MATCHED)', $line6['mapping_status'] === 'UNIT_MISMATCH', json_encode($line6));
check('6. CRITICAL SAFETY: item_id remains mapped to the real item despite UNIT_MISMATCH', $line6['item_id'] !== null && (int) $line6['item_id'] === $item6, json_encode($line6));

$line7 = statusFor($pdo, $cutoverId, $sku7);
check('7. source PCS w/ a HISTORICAL/CLOSED PCS conversion on file -> UNIT_MISMATCH (a closed row never elevates to MATCHED)', $line7['mapping_status'] === 'UNIT_MISMATCH', json_encode($line7));
check('7. item_id remains mapped despite the historical conversion not counting', $line7['item_id'] !== null && (int) $line7['item_id'] === $item7, json_encode($line7));

check('8. no matching conversion at all -> UNIT_MISMATCH', statusFor($pdo, $cutoverId, $sku8)['mapping_status'] === 'UNIT_MISMATCH', json_encode(statusFor($pdo, $cutoverId, $sku8)));
check('9. case-only difference ("GRAM" vs GR) does not create UNIT_MISMATCH', statusFor($pdo, $cutoverId, $sku9)['mapping_status'] === 'MATCHED', json_encode(statusFor($pdo, $cutoverId, $sku9)));
check('10. NOT_FOUND unchanged for a SKU with no master item at all', statusFor($pdo, $cutoverId, $sku10)['mapping_status'] === 'NOT_FOUND', json_encode(statusFor($pdo, $cutoverId, $sku10)));
check('11. NAME_MISMATCH unchanged when unit matches but name does not', statusFor($pdo, $cutoverId, $sku11)['mapping_status'] === 'NAME_MISMATCH', json_encode(statusFor($pdo, $cutoverId, $sku11)));

// ============================================================
// 12. Re-match safety: rerun matchItems(), confirm idempotent + every
// source/decision field is preserved untouched.
// ============================================================
echo "\n== 12. Re-match safety ==\n";
$line6Id = (int) $pdo->query("SELECT id FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} AND source_sku='{$sku6}'")->fetchColumn();
WarehouseCutoverService::resolveLine($pdo, $cutoverId, $line6Id, ['decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 42.5, 'approved_unit_cost' => 777.0, 'notes' => 'manual decision before rerun', 'actor_id' => $adminUserId, 'actor_username' => 'v2146']);

$beforeRerun = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, decision, approved_qty, approved_unit_cost, notes FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$countsRerun = WarehouseCutoverService::matchItems($pdo, $cutoverId);
$afterRerun = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, decision, approved_qty, approved_unit_cost, notes FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

check('12. Rerunning matchItems() produces the identical counts (idempotent)', $countsRerun === $counts, json_encode(['first' => $counts, 'rerun' => $countsRerun]));
check('12. Rerunning matchItems() preserves every source_sku/source_name/source_unit/theoretical_closing_qty/source_price/reconciliation_status/decision/approved_qty/approved_unit_cost/notes field exactly', $beforeRerun === $afterRerun, 'diff: ' . json_encode(array_diff(array_map('json_encode', $beforeRerun), array_map('json_encode', $afterRerun))));

// ============================================================
// 13. Zero inventory mutation from matchItems() (direct-service + rerun).
// ============================================================
echo "\n== 13. Zero inventory mutation ==\n";
$batchesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$txBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
WarehouseCutoverService::matchItems($pdo, $cutoverId);
$batchesAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$txAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
check('13. matchItems() creates zero inventory_batches rows', $batchesAfter === $batchesBefore, "{$batchesBefore} -> {$batchesAfter}");
check('13. matchItems() creates zero inventory_transactions rows', $txAfter === $txBefore, "{$txBefore} -> {$txAfter}");

$ktCheck = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('13b. KARANG_TENGAH remains is_active=0/activation_locked=1 throughout', (int) $ktCheck['is_active'] === 0 && (int) $ktCheck['activation_locked'] === 1);

// ============================================================
// 14. THE FULL SAFETY CHAIN: matchItems() -> resolveLine() ->
// approve() -> loadOpening(). Proves ACCEPT_SOURCE is actually
// REJECTED for a UNIT_MISMATCH line (not merely classified as one), and
// that BUSINESS_OVERRIDE with an explicit, reviewed approved_qty is the
// only way such a line can load — and that it loads the REVIEWED figure,
// never the dangerous auto-derived source figure.
// ============================================================
echo "\n== 14. Full safety chain: ACCEPT_SOURCE rejected, BUSINESS_OVERRIDE required ==\n";
$safetyItemId = makeItemWithBase($pdo, uid('V2146-SAFETYCHAIN'), 'Safety Chain Item', $kgId);
addOpenNonBaseConversion($pdo, $safetyItemId, $grId, 0.001, 'purchase unit: 1 Gram = 0.001 KG (genuinely valid, currently open)');
$safetySku = $pdo->query("SELECT sku FROM items WHERE id={$safetyItemId}")->fetchColumn();

$cutoverSafetyId = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2146-safety-chain', 'opening_as_of' => '2026-09-28', 'created_by' => $adminUserId,
]);
// Bypass the importer for this direct-service test (as the V2.14.5 test
// does), but move status straight to VALIDATED so approve()/loadOpening()
// are reachable — a real cutover reaches VALIDATED via
// WarehouseCutoverImportService::import().
makeCutoverLine($pdo, $cutoverSafetyId, $safetySku, 'Safety Chain Item', 'Gram', 2, 1000.0, 50000.0, 'PASS');
$pdo->prepare("UPDATE warehouse_cutovers SET status = 'VALIDATED' WHERE id = :id")->execute(['id' => $cutoverSafetyId]);

WarehouseCutoverService::matchItems($pdo, $cutoverSafetyId);
$safetyLineId = (int) $pdo->query("SELECT id FROM warehouse_cutover_lines WHERE cutover_id={$cutoverSafetyId} AND source_sku='{$safetySku}'")->fetchColumn();
$safetyLine = statusFor($pdo, $cutoverSafetyId, $safetySku);
check('14. matchItems() classifies the Gram-on-KG-base line UNIT_MISMATCH', $safetyLine['mapping_status'] === 'UNIT_MISMATCH', json_encode($safetyLine));

// resolveLine() itself deliberately does NOT gate on mapping_status (by
// design — loadOpening() is the final safety net), so this succeeds and
// stores the DANGEROUS as-is source figures (1000 "Gram" -> approved_qty).
WarehouseCutoverService::resolveLine($pdo, $cutoverSafetyId, $safetyLineId, [
    'decision' => 'ACCEPT_SOURCE', 'actor_id' => $adminUserId, 'actor_username' => 'v2146',
]);
$afterAcceptSource = $pdo->query("SELECT decision, approved_qty, mapping_status FROM warehouse_cutover_lines WHERE id={$safetyLineId}")->fetch(PDO::FETCH_ASSOC);
check('14. resolveLine(ACCEPT_SOURCE) is permitted at the line level (loadOpening is the enforcement point)', $afterAcceptSource['decision'] === 'ACCEPT_SOURCE' && (float) $afterAcceptSource['approved_qty'] === 1000.0, json_encode($afterAcceptSource));

WarehouseCutoverService::approve($pdo, $cutoverSafetyId, $adminUserId, 'v2146');
$approvedStatus = $pdo->query("SELECT status FROM warehouse_cutovers WHERE id={$cutoverSafetyId}")->fetchColumn();
check('14. cutover reaches APPROVED (reconciliation_status=PASS, nothing unresolved)', $approvedStatus === 'APPROVED', (string) $approvedStatus);

$rejected = false;
$rejectMessage = '';
try {
    WarehouseCutoverService::loadOpening($pdo, $cutoverSafetyId, $adminUserId, 'v2146');
} catch (ValidationException $e) {
    $rejected = true;
    $rejectMessage = implode('; ', $e->errors);
}
check('14. CRITICAL: loadOpening() REJECTS the ACCEPT_SOURCE/UNIT_MISMATCH line — the 1000x error is actually blocked, not just classified', $rejected, $rejectMessage);
check('14. rejection message names the SKU and requires BUSINESS_OVERRIDE', str_contains($rejectMessage, 'not MATCHED') && str_contains($rejectMessage, 'BUSINESS_OVERRIDE'), $rejectMessage);

$batchesBeforeOverride = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
check('14. no inventory_batches row was created by the rejected load attempt', $batchesBeforeOverride === $batchesAfter, "{$batchesAfter} -> {$batchesBeforeOverride}");

// Now the human reviewer explicitly overrides with a REAL, reviewed
// base-unit quantity (e.g. having manually converted 1000 Gram = 1 KG
// themselves) — never auto-derived from the source's non-base figures.
WarehouseCutoverService::resolveLine($pdo, $cutoverSafetyId, $safetyLineId, [
    'decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 1.0, 'approved_unit_cost' => 50000000.0,
    'notes' => 'manually reviewed: 1000 Gram = 1 KG', 'actor_id' => $adminUserId, 'actor_username' => 'v2146',
]);

$loadResult = null;
$loadThrew = false;
try {
    $loadResult = WarehouseCutoverService::loadOpening($pdo, $cutoverSafetyId, $adminUserId, 'v2146');
} catch (\Throwable $e) {
    $loadThrew = true;
    echo "Unexpected exception on BUSINESS_OVERRIDE load: " . $e->getMessage() . "\n";
}
check('14. loadOpening() SUCCEEDS once BUSINESS_OVERRIDE supplies an explicit reviewed approved_qty', !$loadThrew && $loadResult !== null, $loadThrew ? 'threw' : json_encode($loadResult));

$postedBatch = $pdo->query("SELECT qty_base, unit_cost_base FROM inventory_batches WHERE item_id={$safetyItemId} AND warehouse_id={$karangTengahId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('14. the posted batch uses the REVIEWED override qty (1.0 base KG), never the dangerous 1000 source-Gram-as-KG figure', $postedBatch !== false && abs((float) $postedBatch['qty_base'] - 1.0) < 0.0001, json_encode($postedBatch));

$ktCheckAfterLoad = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('14b. KARANG_TENGAH remains is_active=0/activation_locked=1 even after a successful test-DB load', (int) $ktCheckAfterLoad['is_active'] === 0 && (int) $ktCheckAfterLoad['activation_locked'] === 1);

// ============================================================
// Real fixture — full 1,004-row Karang Tengah reconciliation, imported
// exactly as V2.14.5's own test does, matched against a representative
// synthetic master catalog built FROM the fixture's own SKU/name/unit
// columns (this sandbox has no access to the real production item
// master — see the final report). Compares the UNSAFE V2.14.5 rule
// (base OR any item_unit_conversions row) against the SAFE V2.14.6 rule
// (base ONLY) on the identical dataset.
// ============================================================
echo "\n== Real 1,004-row fixture: V2.14.5(unsafe) vs V2.14.6(safe) evidence ==\n";
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/karang_tengah_reconciliation_v2.json'), true);
$fh = $fixture['headers'];
$fidx = array_flip($fh);
$frows = $fixture['rows'];
check('Fixture loaded with all 1,004 rows', count($frows) === 1004, (string) count($frows));

$xlsxPath = tempnam(sys_get_temp_dir(), 'v2146_fixture_') . '.xlsx';
ExcelWriterService::write($xlsxPath, ['Reconciliation' => ['headers' => $fh, 'rows' => $frows]]);

$cutover2Id = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2146-full-fixture', 'opening_as_of' => '2026-09-28', 'created_by' => $adminUserId,
]);
$importSummary = WarehouseCutoverImportService::import($pdo, $cutover2Id, $xlsxPath);
check('Full fixture import succeeds', ($importSummary['total_rows'] ?? null) === 1004, json_encode($importSummary));
$actualLineCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id}")->fetchColumn();
check('All 1,004 imported rows are present as cutover lines', $actualLineCount === 1004, (string) $actualLineCount);

// Build the same representative master catalog as V2.14.5's own test: the
// bulk of SKUs get an exact base-unit match (stable control group under
// EITHER rule); 5 SKUs + the 999208/999209 pair get a DIFFERENT base unit
// than their source, plus a genuinely valid NON-base conversion for their
// own source unit (this is exactly the pattern V2.14.5 mis-classified);
// one SKU gets no master item at all (NOT_FOUND control); one gets a
// deliberately renamed master (NAME_MISMATCH control).
$targetedUnitConflict = ['999208', '999209'];
$deliberateNonBaseConversion = ['700113', '700115', '800401', '800402', '800510'];
$deliberateNotFound = null;
$deliberateNameMismatch = null;
$madeSkus = [];
foreach ($frows as $r) {
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
    if ($normalizedUnitId === null) { continue; } // unresolvable unit string, skip (none expected)

    if (in_array($sku, $targetedUnitConflict, true)) {
        $pcsIdLocal = unitId($pdo, 'PCS');
        $kgIdLocal = unitId($pdo, 'KG');
        $itemId = makeItemWithBase($pdo, $sku, $name, $kgIdLocal);
        addOpenNonBaseConversion($pdo, $itemId, $pcsIdLocal, 1.0, 'valid Pcs conversion (production diagnosis)');
        continue;
    }
    if (in_array($sku, $deliberateNonBaseConversion, true)) {
        $altBase = $normalizedUnitId === unitId($pdo, 'GR') ? unitId($pdo, 'KG') : unitId($pdo, 'PCS');
        $itemId = makeItemWithBase($pdo, $sku, $name, $altBase);
        addOpenNonBaseConversion($pdo, $itemId, $normalizedUnitId, 0.5, 'purchase unit conversion (production diagnosis)');
        continue;
    }
    if ($deliberateNameMismatch === null && $r[$fidx['Reconciliation Status']] === 'PASS') {
        $deliberateNameMismatch = $sku;
        makeItemWithBase($pdo, $sku, $name . ' (MASTER RENAMED)', $normalizedUnitId);
        continue;
    }
    makeItemWithBase($pdo, $sku, $name, $normalizedUnitId);
}
echo "Deliberate NOT_FOUND control SKU: {$deliberateNotFound}\n";
echo "Deliberate NAME_MISMATCH control SKU: {$deliberateNameMismatch}\n";

/** Reimplements the UNSAFE V2.14.5 rule exactly (base OR any conversion row) for comparison evidence only. */
function simulateV2145MatchItems(PDO $pdo, int $cutoverId): array
{
    $lines = $pdo->query("SELECT source_sku, source_name, source_unit FROM warehouse_cutover_lines WHERE cutover_id={$cutoverId}")->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt = $pdo->prepare('SELECT id, name, base_unit_id FROM items WHERE sku = :sku');
    $convStmt = $pdo->prepare('SELECT DISTINCT unit_id FROM item_unit_conversions WHERE item_id = :item_id');
    $counts = ['MATCHED' => 0, 'NOT_FOUND' => 0, 'NAME_MISMATCH' => 0, 'UNIT_MISMATCH' => 0];
    foreach ($lines as $line) {
        $itemStmt->execute(['sku' => $line['source_sku']]);
        $item = $itemStmt->fetch();
        if ($item === false) { $counts['NOT_FOUND']++; continue; }
        $sourceUnitId = UnitNormalizationService::resolveUnitId($pdo, (string) $line['source_unit']);
        $unitMatches = $sourceUnitId !== null && $sourceUnitId === (int) $item['base_unit_id'];
        if (!$unitMatches && $sourceUnitId !== null) {
            $convStmt->execute(['item_id' => $item['id']]);
            $validUnitIds = array_map('intval', $convStmt->fetchAll(PDO::FETCH_COLUMN));
            $unitMatches = in_array($sourceUnitId, $validUnitIds, true);
        }
        $nameMatches = strcasecmp(trim((string) $item['name']), trim((string) $line['source_name'])) === 0;
        $status = !$unitMatches ? 'UNIT_MISMATCH' : (!$nameMatches ? 'NAME_MISMATCH' : 'MATCHED');
        $counts[$status]++;
    }
    return $counts;
}

$unsafeV2145Counts = simulateV2145MatchItems($pdo, $cutover2Id);
echo "V2.14.5 (UNSAFE, base-or-any-conversion) counts: " . json_encode($unsafeV2145Counts) . "\n";

$safeV2146Counts = WarehouseCutoverService::matchItems($pdo, $cutover2Id);
echo "V2.14.6 (SAFE, base-only) counts:                " . json_encode($safeV2146Counts) . "\n";

check('Both rules sum to 1,004', array_sum($unsafeV2145Counts) === 1004 && array_sum($safeV2146Counts) === 1004, json_encode(['v2145_sum' => array_sum($unsafeV2145Counts), 'v2146_sum' => array_sum($safeV2146Counts)]));
check('V2.14.6 has MORE UNIT_MISMATCH than V2.14.5 (the 7 genuine non-base rows are correctly pulled back out of MATCHED)', $safeV2146Counts['UNIT_MISMATCH'] > $unsafeV2145Counts['UNIT_MISMATCH'], json_encode(['v2145' => $unsafeV2145Counts['UNIT_MISMATCH'], 'v2146' => $safeV2146Counts['UNIT_MISMATCH']]));
check('V2.14.6 UNIT_MISMATCH count equals exactly the 7 deliberately-non-base SKUs', $safeV2146Counts['UNIT_MISMATCH'] === count($deliberateNonBaseConversion) + count($targetedUnitConflict), json_encode($safeV2146Counts));
check('NOT_FOUND count is identical under both rules', $safeV2146Counts['NOT_FOUND'] === $unsafeV2145Counts['NOT_FOUND'], json_encode(['v2145' => $unsafeV2145Counts['NOT_FOUND'], 'v2146' => $safeV2146Counts['NOT_FOUND']]));
check('NAME_MISMATCH count is identical under both rules', $safeV2146Counts['NAME_MISMATCH'] === $unsafeV2145Counts['NAME_MISMATCH'], json_encode(['v2145' => $unsafeV2145Counts['NAME_MISMATCH'], 'v2146' => $safeV2146Counts['NAME_MISMATCH']]));

// 999208/999209 retain SOURCE_UNIT_CONFLICT + CRITICAL + NEGATIVE_THEORETICAL_CLOSING
// after the fix, AND now correctly read UNIT_MISMATCH (reverted from
// V2.14.5's unsafe MATCHED) because their master conversion is non-base.
foreach ($targetedUnitConflict as $sku) {
    $row = $pdo->query("SELECT mapping_status, reconciliation_status, exception_codes FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$sku}'")->fetch(PDO::FETCH_ASSOC);
    check("10. SKU {$sku} retains reconciliation_status=CRITICAL after the fix", $row['reconciliation_status'] === 'CRITICAL', json_encode($row));
    check("10. SKU {$sku} retains SOURCE_UNIT_CONFLICT in exception_codes after the fix", str_contains((string) $row['exception_codes'], 'SOURCE_UNIT_CONFLICT'), json_encode($row));
    check("10. SKU {$sku} retains NEGATIVE_THEORETICAL_CLOSING in exception_codes after the fix", str_contains((string) $row['exception_codes'], 'NEGATIVE_THEORETICAL_CLOSING'), json_encode($row));
    check("10. SKU {$sku} mapping_status is correctly UNIT_MISMATCH under V2.14.6 (was unsafely MATCHED under V2.14.5)", $row['mapping_status'] === 'UNIT_MISMATCH', json_encode($row));
}

foreach ($deliberateNonBaseConversion as $sku) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$sku}'")->fetch(PDO::FETCH_ASSOC);
    check("Genuine non-base-unit SKU {$sku} is correctly UNIT_MISMATCH under V2.14.6", $row['mapping_status'] === 'UNIT_MISMATCH', json_encode($row));
}

if ($deliberateNotFound !== null) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$deliberateNotFound}'")->fetch(PDO::FETCH_ASSOC);
    check('NOT_FOUND control SKU (no master item created) is still NOT_FOUND', $row['mapping_status'] === 'NOT_FOUND', json_encode($row));
}
if ($deliberateNameMismatch !== null) {
    $row = $pdo->query("SELECT mapping_status FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$deliberateNameMismatch}'")->fetch(PDO::FETCH_ASSOC);
    check('NAME_MISMATCH control SKU (renamed master) is still NAME_MISMATCH', $row['mapping_status'] === 'NAME_MISMATCH', json_encode($row));
}

// 11. Re-match safety + zero inventory mutation at scale.
$batchesBeforeFull = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$sourceSnapshotBefore = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, reconciliation_status, exception_codes, decision, approved_qty FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$safeV2146CountsRerun = WarehouseCutoverService::matchItems($pdo, $cutover2Id);
$sourceSnapshotAfter = $pdo->query("SELECT source_sku, source_name, source_unit, theoretical_closing_qty, reconciliation_status, exception_codes, decision, approved_qty FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$batchesAfterFull = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();

check('11. Full-fixture rerun of matchItems() is idempotent (identical counts)', $safeV2146CountsRerun === $safeV2146Counts, json_encode(['first' => $safeV2146Counts, 'rerun' => $safeV2146CountsRerun]));
check('11. Full-fixture rerun preserves every source field + reconciliation_status/exception_codes/decision/approved_qty exactly', $sourceSnapshotBefore === $sourceSnapshotAfter);
check('11. Full-fixture matchItems() run+rerun creates zero inventory_batches rows', $batchesAfterFull === $batchesBeforeFull, "{$batchesBeforeFull} -> {$batchesAfterFull}");

// ============================================================
// A/B/C/D before/after report categories.
// ============================================================
$categoryA = [];
foreach ([$sku1 => 'Gram', $sku2 => 'gram', $sku3 => 'Gr', $sku4 => 'KG', $sku5 => 'liter', $sku9 => 'GRAM'] as $sku => $unit) {
    $categoryA[] = "{$sku} (source unit '{$unit}')";
}
$categoryB = array_merge($deliberateNonBaseConversion, $targetedUnitConflict);

echo "\n=== A/B/C/D REPORT (real 1,004-row fixture; synthetic catalog — see final report caveat) ===\n";
echo "A. BASE-UNIT ALIAS FALSE POSITIVES FIXED (micro-scenarios; kept correct from V2.14.5): " . count($categoryA) . " hand-built cases + all naturally-aliased rows in the fixture control group\n";
echo "B. GENUINE NON-BASE UNIT ROWS LEFT AS UNIT_MISMATCH (corrected from V2.14.5's unsafe MATCHED): " . count($categoryB) . " -> " . json_encode($categoryB) . "\n";
echo "C. NOT_FOUND: " . $safeV2146Counts['NOT_FOUND'] . " (SKU: {$deliberateNotFound})\n";
echo "D. NAME_MISMATCH: " . $safeV2146Counts['NAME_MISMATCH'] . " (SKU: {$deliberateNameMismatch})\n";
echo "V2.14.5 (unsafe) full counts: " . json_encode($unsafeV2145Counts) . "\n";
echo "V2.14.6 (safe)   full counts: " . json_encode($safeV2146Counts) . "\n";

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
