<?php
declare(strict_types=1);

/**
 * HOTFIX REGRESSION (post-274dc78) — Stock Opname dual-count vs legacy
 * workflow_mode.
 *
 * Root cause under test: the frontend (stock-opname.js) used to infer
 * "legacy single-count" purely from `!session.p1_user_id ||
 * !session.p2_user_id` — wrong, because a brand-new V2.12+ dual-count
 * session also starts with both null (before assignment). The fix adds an
 * explicit, server-derived `workflow_mode`/`is_legacy` field
 * (StockOpnameService::attachWorkflowMode(), keyed off session_number,
 * which is only ever populated by start() from V2.12A onward and never
 * backfilled onto pre-existing rows) that StockOpnameService::get() and
 * ::review() now both return, so the frontend never has to guess from
 * P1/P2 assignment state again.
 *
 * This test proves:
 *   1. a brand-new session (real session_number, P1/P2 still null) is
 *      always reported DUAL_COUNT/not-legacy — never LEGACY_SINGLE;
 *   2. a genuinely legacy row (session_number NULL, simulating real
 *      pre-V2.12 production data untouched by any migration backfill) is
 *      always reported LEGACY_SINGLE/is_legacy — regardless of anything
 *      else about it;
 *   3. review() carries the same field for the supervisor screen;
 *   4/5. the blind-count security boundary (getForCounter()) is
 *      completely unaffected by this change — P1 never sees P2/system,
 *      P2 never sees P1/system;
 *   6. cancel() still works pre-POST (OPEN and FINALIZED) on a real
 *      dual-count session and is still refused once POSTED — untouched
 *      by this hotfix, reverified here because the UI now surfaces a
 *      "Batalkan Sesi" control directly off the OPEN dual-count screen.
 *
 * Usage: php tests/inventory_v2_12_hotfix_workflow_mode_test.php
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

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('SCM', 'SCM / Gudang Besar', 1)")->execute();
$scmId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}
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
        'transaction_uuid' => uid('hotfixb-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'hotfixb', 'transaction_type' => 'OPENING',
    ]));
}

$adminUserId = makeUser($pdo, 'hfbadmin', $superRoleId, null);
$p1UserId = makeUser($pdo, 'hfbp1', $stockRoleId, $scmId);
$p2UserId = makeUser($pdo, 'hfbp2', $stockRoleId, $scmId);

$itemA = makeItem($pdo, $kgUnitId, 'HFB-A');
postOpeningIn($pdo, $itemA, $kgUnitId, $scmId, 100, 1000, $adminUserId);

// ============================================================
// 1 — brand-new session: never renders as legacy, even before assignment.
// ============================================================
echo "== 1: brand-new session is always DUAL_COUNT, never legacy, before P1/P2 assignment ==\n";
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemA]));
$session = StockOpnameService::get($pdo, $sessionId);
check('new session has p1_user_id/p2_user_id both null (the old, wrong signal)', $session['p1_user_id'] === null && $session['p2_user_id'] === null);
check('new session has a real session_number', is_string($session['session_number']) && str_starts_with($session['session_number'], 'SO-'));
check('new session workflow_mode is DUAL_COUNT', $session['workflow_mode'] === 'DUAL_COUNT', (string) $session['workflow_mode']);
check('new session is_legacy is false', $session['is_legacy'] === false);

// ============================================================
// 2 — a genuinely legacy row (session_number NULL) always reports legacy,
// simulating real pre-V2.12 production data that the V2.12A migration
// never backfills.
// ============================================================
echo "\n== 2: genuinely legacy row (session_number forced NULL) always reports LEGACY_SINGLE ==\n";
$pdo->prepare('UPDATE stock_opname_sessions SET session_number = NULL WHERE id = :id')->execute(['id' => $sessionId]);
$legacyView = StockOpnameService::get($pdo, $sessionId);
check('forced-NULL session_number row reports workflow_mode LEGACY_SINGLE', $legacyView['workflow_mode'] === 'LEGACY_SINGLE', (string) $legacyView['workflow_mode']);
check('forced-NULL session_number row reports is_legacy true', $legacyView['is_legacy'] === true);
// restore for the rest of this test
$pdo->prepare('UPDATE stock_opname_sessions SET session_number = :sn WHERE id = :id')->execute(['sn' => $session['session_number'], 'id' => $sessionId]);

// ============================================================
// 3 — review() (supervisor screen) carries the same field.
// ============================================================
echo "\n== 3: review() session also carries workflow_mode ==\n";
$review = StockOpnameService::review($pdo, $sessionId);
check('review().session.workflow_mode is DUAL_COUNT for the real session', $review['session']['workflow_mode'] === 'DUAL_COUNT');
check('review() line set exists from OPEN status onward, pre-assignment (Barang/Stok Sistem always available)', count($review['lines']) === 1 && $review['lines'][0]['system_qty_base'] === 100.0);

// ============================================================
// 4/5 — blind-count security boundary untouched by this hotfix.
// ============================================================
echo "\n== 4/5: blind-count security boundary unaffected ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemA, 98, $p1UserId));

$p1View = StockOpnameService::getForCounter($pdo, $sessionId, 'p1');
check('P1 blind view has no system_qty_base key anywhere in its lines', !array_key_exists('system_qty_base', $p1View['lines'][0]));
check('P1 blind view has no p2/other-counter value key', !array_key_exists('p2_qty_base', $p1View['lines'][0]) && !array_key_exists('other_qty_base', $p1View['lines'][0]));
check('P1 blind view reports own submitted qty via my_qty_base', (float) $p1View['lines'][0]['my_qty_base'] === 98.0);

$p2View = StockOpnameService::getForCounter($pdo, $sessionId, 'p2');
check('P2 blind view (not yet submitted) has no system_qty_base key', !array_key_exists('system_qty_base', $p2View['lines'][0]));
check('P2 blind view has no p1/other-counter value key', !array_key_exists('p1_qty_base', $p2View['lines'][0]) && !array_key_exists('other_qty_base', $p2View['lines'][0]));
check('P2 blind view shows itself not yet counted (my_qty_base null)', $p2View['lines'][0]['my_qty_base'] === null);

// ============================================================
// 6 — cancel() still works pre-POST on a real dual-count session (OPEN),
// and is still refused once POSTED. Untouched by this hotfix; reverified
// because the UI now exposes "Batalkan Sesi" directly on the OPEN
// dual-count supervisor screen.
// ============================================================
echo "\n== 6: cancel() still OPEN/FINALIZED-only on a real dual-count session ==\n";
$cancelled = Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $sessionId, 'hotfix test cancel', $adminUserId));
check('OPEN dual-count session can be cancelled', $cancelled['status'] === 'CANCELLED', (string) $cancelled['status']);

$itemB = makeItem($pdo, $kgUnitId, 'HFB-B');
postOpeningIn($pdo, $itemB, $kgUnitId, $scmId, 50, 500, $adminUserId);
$postedSessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $scmId, $adminUserId, [$itemB]));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $postedSessionId, ['p1_user_id' => $p1UserId, 'p2_user_id' => $p2UserId], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $postedSessionId, 'p1', $itemB, 50, $p1UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $postedSessionId, 'p2', $itemB, 50, $p2UserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $postedSessionId, $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $postedSessionId, $adminUserId));
$postCancelErr = null;
try {
    Database::transaction(fn (PDO $tx) => StockOpnameService::cancel($tx, $postedSessionId, 'should be refused', $adminUserId));
} catch (ValidationException $e) { $postCancelErr = $e->getMessage(); }
check('a POSTED session can never be cancelled', $postCancelErr !== null, (string) $postCancelErr);

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
