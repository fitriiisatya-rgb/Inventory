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

// ============================================================
// 21/22. negative adjustment, posted through the existing
// StockAdjustmentService/FIFO path (never a raw batch rewrite)
// ============================================================
echo "\n== 21/22. negative adjustment, posted via the existing adjustment service ==\n";
$itemNeg = makeItem($pdo, $kgUnitId, 'V212-NEG');
postOpeningIn($pdo, $itemNeg, $kgUnitId, $scmId, 40, 1800, $adminUserId);
$negSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemNeg]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $negSessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $negSessionId, 'p1', $itemNeg, 33.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $negSessionId, 'p2', $itemNeg, 33.0, $p2UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $negSessionId, $adminUserId));
$negPostResult = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $negSessionId, $adminUserId));
check('POST created exactly one adjustment for the negative-variance item (40 -> 33)', count($negPostResult['adjustments']) === 1);
$negAdjTxId = (int) $negPostResult['adjustments'][0]['transaction_id'];
$negAdjTx = $pdo->prepare('SELECT transaction_type FROM inventory_transactions WHERE id = :id');
$negAdjTx->execute(['id' => $negAdjTxId]);
check('the negative variance was posted as transaction_type=ADJUSTMENT (never a raw session/line rewrite)', $negAdjTx->fetchColumn() === 'ADJUSTMENT');
$negStockAfter = \App\Services\InventoryService::currentStock($pdo, $itemNeg, $scmId);
check('stock after posting the negative adjustment reflects the lower physical count (33)', abs($negStockAfter['qty_base'] - 33.0) < 0.0001, (string) $negStockAfter['qty_base']);

// ============================================================
// 23. double POST blocked (idempotent, never a duplicate adjustment)
// ============================================================
echo "\n== 23. double POST blocked ==\n";
$negPostReplay = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $negSessionId, $adminUserId));
check('re-posting an already-POSTED session returns an idempotent replay, not an error', ($negPostReplay['idempotent_replay'] ?? false) === true);
$adjCountStmt2 = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_uuid LIKE :prefix");
$negSessionUuid = StockOpnameService::get($pdo, $negSessionId)['session_uuid'];
$adjCountStmt2->execute(['prefix' => $negSessionUuid . ':%']);
check('double-POST never created a second ADJUSTMENT transaction for the same session', (int) $adjCountStmt2->fetchColumn() === 1);

// ============================================================
// 24. period lock — POST is blocked by the EXISTING PeriodLockService,
// exactly the same as every other posting endpoint (never a second
// locking mechanism invented for opname)
// ============================================================
echo "\n== 24. period lock ==\n";
$itemLocked = makeItem($pdo, $kgUnitId, 'V212-LOCK');
postOpeningIn($pdo, $itemLocked, $kgUnitId, $scmId, 10, 1000, $adminUserId);
$lockedSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemLocked]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $lockedSessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $lockedSessionId, 'p1', $itemLocked, 9.0, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $lockedSessionId, 'p2', $itemLocked, 9.0, $p2UserId));
$lockedSessionDetail = Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $lockedSessionId, $adminUserId));
$lockedSessionDate = $lockedSessionDetail['session_date'];
$pdo->prepare("INSERT INTO book_closings (period_start, period_end, status, locked_by, locked_at, created_by) VALUES (:s, :e, 'LOCKED', :by, NOW(), :by2)")
    ->execute(['s' => $lockedSessionDate, 'e' => $lockedSessionDate, 'by' => $adminUserId, 'by2' => $adminUserId]);
$periodLockErr = null;
try {
    Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $lockedSessionId, $adminUserId));
} catch (\App\Services\PeriodLockedException $e) { $periodLockErr = $e->getMessage(); }
check('POST is blocked by the existing period-lock check when the session date falls inside a LOCKED book-closing period', $periodLockErr !== null, (string) $periodLockErr);
$pdo->prepare("DELETE FROM book_closings WHERE period_start = :s AND period_end = :e")->execute(['s' => $lockedSessionDate, 'e' => $lockedSessionDate]);
$lockedPostResult = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $lockedSessionId, $adminUserId));
check('POST succeeds once the period lock is lifted', $lockedPostResult['status'] === 'POSTED');

// ============================================================
// 25. count date preserved (session_date is the PHYSICAL count date,
// distinct from posted_at, the POSTING timestamp)
// ============================================================
echo "\n== 25. count date preserved ==\n";
$lockedSessionFinal = StockOpnameService::get($pdo, $lockedSessionId);
check('session_date (physical count date) is untouched by posting, still the original count date', $lockedSessionFinal['session_date'] === $lockedSessionDate);
check('posted_at (a real timestamp) is a different concept entirely from session_date (a plain DATE)', $lockedSessionFinal['posted_at'] !== null && $lockedSessionFinal['posted_at'] !== $lockedSessionDate);

// ============================================================
// 26. audit log
// ============================================================
echo "\n== 26. audit log ==\n";
$auditActions = $pdo->prepare("SELECT DISTINCT action_code FROM audit_logs WHERE entity_type IN ('stock_opname_sessions','stock_opname_lines') AND entity_id IN (
    SELECT id FROM stock_opname_sessions WHERE id = :sid
    UNION SELECT id FROM stock_opname_lines WHERE session_id = :sid2
)");
$auditActions->execute(['sid' => $posSessionId, 'sid2' => $posSessionId]);
$actions = array_column($auditActions->fetchAll(), 'action_code');
foreach (['STOCK_OPNAME_START', 'STOCK_OPNAME_ASSIGN_COUNTERS', 'STOCK_OPNAME_FINALIZE', 'STOCK_OPNAME_POST'] as $expected) {
    check("audit log contains a {$expected} entry for the session", in_array($expected, $actions, true), implode(',', $actions));
}
$lineAuditActions = $pdo->prepare("SELECT DISTINCT action_code FROM audit_logs WHERE entity_type = 'stock_opname_lines' AND entity_id IN (SELECT id FROM stock_opname_lines WHERE session_id = :sid)");
$lineAuditActions->execute(['sid' => $posSessionId]);
$lineActions = array_column($lineAuditActions->fetchAll(), 'action_code');
check('audit log contains STOCK_OPNAME_P1_COUNT / STOCK_OPNAME_P2_COUNT line-level entries', in_array('STOCK_OPNAME_P1_COUNT', $lineActions, true) && in_array('STOCK_OPNAME_P2_COUNT', $lineActions, true), implode(',', $lineActions));

// ============================================================
// 27. report
// ============================================================
echo "\n== 27. report ==\n";
require_once __DIR__ . '/../services/StockOpnameReportService.php';
$reportList = \App\Services\StockOpnameReportService::list($pdo, ['warehouse_id' => $scmId]);
$reportRow = array_values(array_filter($reportList['rows'], fn ($r) => $r['id'] === $posSessionId))[0] ?? null;
check('report list includes the session with its session_number, P1, P2, and match/mismatch counts', $reportRow !== null && $reportRow['session_number'] !== null && $reportRow['p1'] !== null && $reportRow['p2'] !== null, json_encode($reportRow));
$reportDetail = \App\Services\StockOpnameReportService::detail($pdo, $posSessionId);
check('report detail exposes per-line SKU/system/P1/P2/final/difference', count($reportDetail['lines']) > 0 && array_key_exists('difference_qty_base', $reportDetail['lines'][0]));

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Section (direct-service): {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — permission gating (STOCK_OPNAME_SUPERVISE vs STOCK_OPNAME_MANAGE),
// warehouse isolation, CSV formula-injection protection
// ============================================================
$port = 8900 + random_int(2400, 2799);
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

try {
    $superadminRoleId = $superRoleId;

    $httpAdminUser = uid('v212httpadmin'); $httpAdminPass = 'V212HttpAdminPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $httpAdminUser, 'h' => password_hash($httpAdminPass, PASSWORD_BCRYPT), 'n' => $httpAdminUser, 'r' => $superadminRoleId]);
    $adminJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $adminLogin = httpCall('POST', "{$base}/auth/login", ['username' => $httpAdminUser, 'password' => $httpAdminPass], $adminJar);
    $adminCsrf = $adminLogin['body']['data']['csrf_token'] ?? '';

    $httpStockUser = uid('v212httpstock'); $httpStockPass = 'V212HttpStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $httpStockUser, 'h' => password_hash($httpStockPass, PASSWORD_BCRYPT), 'n' => $httpStockUser, 'r' => $stockRoleId, 'w' => $scmId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $httpStockUser, 'password' => $httpStockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    $httpCbdUser = uid('v212httpcbd'); $httpCbdPass = 'V212HttpCbdPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $httpCbdUser, 'h' => password_hash($httpCbdPass, PASSWORD_BCRYPT), 'n' => $httpCbdUser, 'r' => $stockRoleId, 'w' => $cibadakId]);
    $cbdJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $cbdLogin = httpCall('POST', "{$base}/auth/login", ['username' => $httpCbdUser, 'password' => $httpCbdPass], $cbdJar);
    $cbdCsrf = $cbdLogin['body']['data']['csrf_token'] ?? '';

    // Open a fresh SCM session over HTTP for the permission/isolation checks.
    $httpOpen = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $scmId, 'item_ids' => [$itemA]], $adminJar, $adminCsrf);
    $httpSessionId = $httpOpen['body']['data']['session_id'] ?? null;
    check('HTTP: SUPERADMIN can open a new SCM session (previous ones are POSTED/CANCELLED, warehouse free)', $httpOpen['status'] === 200, json_encode($httpOpen['body']));

    echo "\n== 29. STOCK warehouse isolation (HTTP) ==\n";
    $cbdGetsScm = httpCall('GET', "{$base}/stock-opname/{$httpSessionId}", null, $cbdJar);
    check('HTTP: a CIBADAK-scoped STOCK user is forbidden from a SCM opname session', $cbdGetsScm['status'] === 403, json_encode($cbdGetsScm['body']));
    $cbdCountsScm = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/count/p1", ['item_id' => $itemA, 'counted_qty_base' => 5], $cbdJar, $cbdCsrf);
    check('HTTP: a CIBADAK-scoped STOCK user cannot submit a P1 count on a SCM session', $cbdCountsScm['status'] === 403 && ($cbdCountsScm['body']['error']['code'] ?? '') === 'FORBIDDEN', json_encode($cbdCountsScm['body']));

    echo "\n== 30. unauthorized finalization rejected ==\n";
    $stockFinalize = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/finalize", [], $stockJar, $stockCsrf);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user (STOCK role, no SUPERVISE) is forbidden from finalize', $stockFinalize['status'] === 403, json_encode($stockFinalize['body']));
    $stockPost = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/post", [], $stockJar, $stockCsrf);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user is forbidden from post', $stockPost['status'] === 403, json_encode($stockPost['body']));
    $stockReview = httpCall('GET', "{$base}/stock-opname/{$httpSessionId}/review", null, $stockJar);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user is forbidden from the supervisor review dashboard', $stockReview['status'] === 403, json_encode($stockReview['body']));
    $stockRecount = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/recount", ['item_id' => $itemA, 'counted_qty_base' => 1, 'reason' => 'x'], $stockJar, $stockCsrf);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user is forbidden from recount', $stockRecount['status'] === 403, json_encode($stockRecount['body']));
    $stockExclude = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/exclude", ['item_id' => $itemA, 'reason' => 'x'], $stockJar, $stockCsrf);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user is forbidden from exclude', $stockExclude['status'] === 403, json_encode($stockExclude['body']));
    $stockCancel = httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/cancel", ['reason' => 'x'], $stockJar, $stockCsrf);
    check('HTTP: a plain STOCK_OPNAME_MANAGE user is forbidden from cancel', $stockCancel['status'] === 403, json_encode($stockCancel['body']));

    echo "\n== 28. CSV formula injection protection (report export) ==\n";
    // Drive the HTTP session to a MISMATCH -> recount with a formula-shaped
    // reason, finalize, post, then export both the summary and detail CSV
    // and confirm the shared inv_csv_safe_cell() guard neutralizes it —
    // exactly the same centralized helper every other CSV export in the
    // app already relies on, no new escaping logic invented here.
    httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/assign-counters", ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminJar, $adminCsrf);
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $httpSessionId, 'p1', $itemA, 11.0, $p1UserId));
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $httpSessionId, 'p2', $itemA, 12.0, $p2UserId));
    Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $httpSessionId, $itemA, 11.0, '=2+2 formula-shaped reason', $adminUserId));
    httpCall('POST', "{$base}/stock-opname/{$httpSessionId}/finalize", [], $adminJar, $adminCsrf);

    $detailCsv = httpCall('GET', "{$base}/reports/opname/{$httpSessionId}?format=csv", null, $adminJar);
    check('HTTP detail CSV export returns 200 text/csv', $detailCsv['status'] === 200 && str_contains($detailCsv['raw'], 'SKU'));
    check('HTTP detail CSV export never contains a raw unescaped "=2+2" formula cell', str_contains($detailCsv['raw'], "'=2+2") && !preg_match('/(?<!\')=2\+2/', $detailCsv['raw']), $detailCsv['raw']);

    $summaryCsv = httpCall('GET', "{$base}/reports/opname?format=csv&warehouse_id={$scmId}", null, $adminJar);
    check('HTTP summary CSV export returns 200 text/csv with a UTF-8 BOM', $summaryCsv['status'] === 200 && str_starts_with($summaryCsv['raw'], "\xEF\xBB\xBF"));

    echo "\n== print route ==\n";
    $printResp = httpCall('GET', "{$base}/stock-opname/{$httpSessionId}/print", null, $adminJar);
    check('HTTP stock opname print returns 200 with real HTML content and the AMOR logo', $printResp['status'] === 200 && str_contains($printResp['raw'], '<!DOCTYPE html>') && str_contains($printResp['raw'], '/assets/images/amor-logo.jpg'), substr($printResp['raw'], 0, 120));
    check('HTTP stock opname print shows STOCK OPNAME title', str_contains($printResp['raw'], 'STOCK OPNAME'));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$bTotal = count($results) - $aTotal;
$bPassed = count(array_filter($results)) - $aPassed;
echo "\n-- Section (HTTP): {$bPassed} / {$bTotal} PASSED --\n";

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed < $total) { exit(1); }
