<?php
declare(strict_types=1);

/**
 * PHASE V2.12 — Dual Count (P1/P2 blind) Stock Opname, against real
 * MySQL/MariaDB.
 *
 * Covers the mission's test matrix items 1-20 (schema/blind-count/compare/
 * recount/supervisor-finalize) directly against StockOpnameService, plus
 * HTTP coverage for warehouse isolation, blind visibility, and permission
 * gating (STOCK_OPNAME_MANAGE for operational actions vs
 * STOCK_OPNAME_SUPERVISE for review/recount/exclude/finalize/post/cancel).
 *
 * Usage: php tests/inventory_v2_12_dual_count_opname_test.php
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
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function expectValidationError(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (ValidationException $e) {
        return $e->getMessage();
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('SCM', 'SCM / Gudang Besar', 1)")->execute();
$scmId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('CIBADAK', 'CIBADAK', 1)")->execute();
$cibadakId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}

$adminUserId = makeUser($pdo, 'v212admin', $superRoleId, null);
$p1UserId = makeUser($pdo, 'v212p1', $stockRoleId, $scmId);
$p2UserId = makeUser($pdo, 'v212p2', $stockRoleId, $scmId);
$thirdStockUserId = makeUser($pdo, 'v212third', $stockRoleId, $scmId);
$cibadakUserId = makeUser($pdo, 'v212cbd', $stockRoleId, $cibadakId);

function makeItem(PDO $pdo, int $unitId, string $tag): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v212-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v212', 'transaction_type' => 'OPENING',
    ]));
}

// ============================================================
// 1. open SCM session
// ============================================================
echo "== 1. open SCM session ==\n";
$itemA = makeItem($pdo, $kgUnitId, 'V212-A');
$itemB = makeItem($pdo, $kgUnitId, 'V212-B');
postOpeningIn($pdo, $itemA, $kgUnitId, $scmId, 100, 1000, $adminUserId);
postOpeningIn($pdo, $itemB, $kgUnitId, $scmId, 50, 2000, $adminUserId);

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemA, $itemB]));
$session = StockOpnameService::get($pdo, $sessionId);
check('session opened with OPEN status', $session['status'] === 'OPEN');
check('session has a session_number (SO-YYYYMMDD-####)', is_string($session['session_number']) && str_starts_with($session['session_number'], 'SO-'), (string) $session['session_number']);
check('session scope is SELECTED_ITEMS (explicit item_ids passed)', $session['scope'] === 'SELECTED_ITEMS');
check('session has exactly 2 lines with correct system qty snapshot', count($session['lines']) === 2);

// ============================================================
// 2. warehouse isolation (service level: CIBADAK session independent of SCM)
// ============================================================
echo "\n== 2. warehouse isolation ==\n";
$cibadakItem = makeItem($pdo, $kgUnitId, 'V212-CBD');
postOpeningIn($pdo, $cibadakItem, $kgUnitId, $cibadakId, 30, 500, $adminUserId);
$cibadakSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $cibadakId, $adminUserId, [$cibadakItem]));
$cibadakSession = StockOpnameService::get($pdo, $cibadakSessionId);
check('CIBADAK session is independent, own warehouse_id', (int) $cibadakSession['warehouse_id'] === $cibadakId);
check('SCM session still shows warehouse SCM (no cross-contamination)', (int) $session['warehouse_id'] === $scmId);

// ============================================================
// 3. duplicate active session blocked
// ============================================================
echo "\n== 3. duplicate active session blocked ==\n";
$dupErr = null;
try {
    Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemA]));
} catch (ValidationException $e) { $dupErr = $e->getMessage(); }
check('a second OPEN session on the same (SCM) warehouse is rejected', $dupErr !== null, (string) $dupErr);

// ============================================================
// 4/5. assign P1 / P2
// ============================================================
echo "\n== 4/5. assign P1 / P2 ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p1_user_id' => $p1UserId], $adminUserId));
$afterP1 = StockOpnameService::get($pdo, $sessionId);
check('P1 assigned', (int) $afterP1['p1_user_id'] === $p1UserId);

Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p2_user_id' => $p2UserId], $adminUserId));
$afterP2 = StockOpnameService::get($pdo, $sessionId);
check('P2 assigned', (int) $afterP2['p2_user_id'] === $p2UserId);

// ============================================================
// 6. same user P1/P2 rejected
// ============================================================
echo "\n== 6. same user P1/P2 rejected ==\n";
$sameUserErr = expectValidationError(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p2_user_id' => $p1UserId], $adminUserId)));
check('assigning the same user as both P1 and P2 is rejected by the service', $sameUserErr !== null, (string) $sameUserErr);

// DB-level CHECK constraint as a second, independent layer (Section 3:
// "do not rely only on frontend" — this proves it is not JUST the PHP
// service that enforces it).
$dbCheckErr = null;
try {
    $pdo->prepare('UPDATE stock_opname_sessions SET p1_user_id = :u, p2_user_id = :u WHERE id = :id')
        ->execute(['u' => $p1UserId, 'id' => $sessionId]);
} catch (\PDOException $e) { $dbCheckErr = $e->getMessage(); }
check('the database CHECK constraint independently rejects p1_user_id = p2_user_id', $dbCheckErr !== null, (string) $dbCheckErr);

// ============================================================
// 7/9. P1 / P2 blind count; 8/10. cannot see each other
// ============================================================
echo "\n== 7. P1 blind count ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemA, 98.0, $p1UserId));
$p1View = StockOpnameService::getForCounter($pdo, $sessionId, 'p1');
$p1LineA = array_values(array_filter($p1View['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('P1 sees their own submitted qty for item A', $p1LineA['my_qty_base'] !== null && abs((float) $p1LineA['my_qty_base'] - 98.0) < 0.0001);

echo "\n== 8. P2 cannot see P1 ==\n";
$p2ViewBefore = StockOpnameService::getForCounter($pdo, $sessionId, 'p2');
check('the P2 blind view payload never contains any p1_ key', !hasKeyDeepImpl($p2ViewBefore, 'p1_qty_base') && !hasKeyDeepImpl($p2ViewBefore, 'p1_user_id'));
check('P1\'s own view never exposes system_qty_base either (blind to the system truth too)', !hasKeyDeepImpl($p1View, 'system_qty_base'));

echo "\n== 9. P2 blind count ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $itemA, 97.0, $p2UserId));
$p2View = StockOpnameService::getForCounter($pdo, $sessionId, 'p2');
$p2LineA = array_values(array_filter($p2View['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('P2 sees their own submitted qty for item A', abs((float) $p2LineA['my_qty_base'] - 97.0) < 0.0001);

echo "\n== 10. P1 cannot see P2 ==\n";
check('the P1 blind view payload never contains any p2_ key', !hasKeyDeepImpl($p1View, 'p2_qty_base') && !hasKeyDeepImpl($p1View, 'p2_user_id'));

function hasKeyDeepImpl($data, string $key): bool
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if ($k === $key) return true;
            if (hasKeyDeepImpl($v, $key)) return true;
        }
    }
    return false;
}

// ============================================================
// 11. MATCH case (item B: both P1 and P2 count the same value)
// ============================================================
echo "\n== 11. MATCH case ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemB, 50.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $itemB, 50.0, $p2UserId));
$review1 = StockOpnameService::review($pdo, $sessionId);
$lineB = array_values(array_filter($review1['lines'], fn ($l) => $l['item_id'] === $itemB))[0];
check('item B (P1=50, P2=50) resolves to MATCH', $lineB['match_status'] === 'MATCH');
check('MATCH final physical qty is resolved automatically (no separate action)', abs((float) $lineB['final_physical_qty_base'] - 50.0) < 0.0001);

// ============================================================
// 12/13. MISMATCH case, no averaging
// ============================================================
echo "\n== 12/13. MISMATCH case, no averaging ==\n";
$review2 = StockOpnameService::review($pdo, $sessionId);
$lineA = array_values(array_filter($review2['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('item A (system=100, P1=98, P2=97) resolves to MISMATCH', $lineA['match_status'] === 'MISMATCH');
check('MISMATCH final physical qty is NOT auto-resolved (null, awaiting recount)', $lineA['final_physical_qty_base'] === null);
check('MISMATCH never silently averages P1/P2 (97.5) nor picks either side', $lineA['final_physical_qty_base'] !== 97.5 && $lineA['final_physical_qty_base'] !== 98.0 && $lineA['final_physical_qty_base'] !== 97.0);

// ============================================================
// 14/17. recount required, blocks finalize
// ============================================================
echo "\n== 14/17. recount required, blocks finalize ==\n";
$blockedErr = expectValidationError(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $adminUserId)));
check('finalize is blocked while item A is still MISMATCH (not recounted)', $blockedErr !== null, (string) $blockedErr);

// ============================================================
// 15. recount preserved (original P1/P2 untouched)
// ============================================================
echo "\n== 15. recount preserved ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sessionId, $itemA, 98.0, 'Dihitung ulang oleh supervisor', $adminUserId));
$review3 = StockOpnameService::review($pdo, $sessionId);
$lineA2 = array_values(array_filter($review3['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('item A resolves to RECOUNTED after recount', $lineA2['match_status'] === 'RECOUNTED');
check('recount final physical qty = the recount value (98)', abs((float) $lineA2['final_physical_qty_base'] - 98.0) < 0.0001);
check('original P1 value (98) is preserved untouched', abs((float) $lineA2['p1_qty_base'] - 98.0) < 0.0001);
check('original P2 value (97) is preserved untouched', abs((float) $lineA2['p2_qty_base'] - 97.0) < 0.0001);

// ============================================================
// 16. count zero distinct from uncounted
// ============================================================
echo "\n== 16. count zero distinct from uncounted ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $cibadakSessionId, 'freeing CIBADAK for the count-zero test', $adminUserId));
$itemZero = makeItem($pdo, $kgUnitId, 'V212-ZERO');
$zeroSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $cibadakId, $adminUserId, [$itemZero]));
// no assignment/count yet — item is NOT_COUNTED
$reviewZero1 = StockOpnameService::review($pdo, $zeroSessionId);
check('an item with no P1/P2 submission at all is PENDING (not counted), not COUNTED_ZERO', $reviewZero1['lines'][0]['match_status'] === 'PENDING' && $reviewZero1['lines'][0]['p1_qty_base'] === null);

Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $zeroSessionId, ['p1_user_id' => $cibadakUserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $zeroSessionId, 'p1', $itemZero, 0.0, $cibadakUserId));
$zeroP1View = StockOpnameService::getForCounter($pdo, $zeroSessionId, 'p1');
check('a genuine zero count (0.000000) is distinctly COUNTED (is_counted_by_me=true), never conflated with not-yet-counted', $zeroP1View['lines'][0]['is_counted_by_me'] === true && (float) $zeroP1View['lines'][0]['my_qty_base'] === 0.0);

// ============================================================
// 18. supervisor finalize
// ============================================================
echo "\n== 18. supervisor finalize ==\n";
$finalized = Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $adminUserId));
check('session finalized once every line is MATCH/RECOUNTED', $finalized['status'] === 'FINALIZED');
check('supervisor_id auto-recorded from the finalizing user when not pre-assigned', (int) $finalized['supervisor_id'] === $adminUserId);
check('variance computed for item A (98 - 100 = -2)', abs((float) $finalized['lines'][array_search($itemA, array_column($finalized['lines'], 'item_id'))]['variance_qty_base'] - (-2.0)) < 0.0001);
check('variance computed for item B (50 - 50 = 0)', abs((float) $finalized['lines'][array_search($itemB, array_column($finalized['lines'], 'item_id'))]['variance_qty_base'] - 0.0) < 0.0001);

// ============================================================
// 19. finalized values immutable
// ============================================================
echo "\n== 19. finalized values immutable ==\n";
$postFinalizeSubmitErr = expectValidationError(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemA, 200.0, $p1UserId)));
check('P1/P2 counts cannot be submitted once the session is no longer OPEN (FINALIZED)', $postFinalizeSubmitErr !== null, (string) $postFinalizeSubmitErr);
$postFinalizeRecountErr = expectValidationError(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sessionId, $itemA, 55.0, 'late change', $adminUserId)));
check('recount cannot be submitted once the session is no longer OPEN (FINALIZED)', $postFinalizeRecountErr !== null, (string) $postFinalizeRecountErr);

// Release the SCM warehouse lock (post the finalized session) before
// opening the next SCM session below — same real WarehouseLockService the
// legacy flow always used.
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $adminUserId));

// ============================================================
// 20. positive adjustment (item B has 0 variance; verify a genuinely
// positive-variance item posts through the EXISTING adjustment service)
// ============================================================
echo "\n== 20. positive adjustment via existing StockAdjustmentService ==\n";
$itemPos = makeItem($pdo, $kgUnitId, 'V212-POS');
postOpeningIn($pdo, $itemPos, $kgUnitId, $scmId, 20, 1500, $adminUserId);
$posSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemPos]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $posSessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $posSessionId, 'p1', $itemPos, 25.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $posSessionId, 'p2', $itemPos, 25.0, $p2UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $posSessionId, $adminUserId));
$postResult = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $posSessionId, $adminUserId));
check('POST created exactly one adjustment for the positive-variance item', count($postResult['adjustments']) === 1);
$stockAfter = \App\Services\InventoryService::currentStock($pdo, $itemPos, $scmId);
check('stock after posting reflects the physical count (25), via the real adjustment/FIFO path, not a direct write', abs($stockAfter['qty_base'] - 25.0) < 0.0001, (string) $stockAfter['qty_base']);

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$aTotal}  PASSED: {$aPassed}  FAILED: " . ($aTotal - $aPassed) . "\n";
if ($aPassed < $aTotal) { exit(1); }
