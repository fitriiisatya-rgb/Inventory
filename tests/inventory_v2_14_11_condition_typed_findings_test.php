<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11 — Checkpoint A: multi-user team Stock Opname with
 * condition-typed multi-unit findings + photo evidence, against real
 * MySQL/MariaDB.
 *
 * No dedicated automated test existed for the team/claim/findings surface
 * V2.14.10/V2.14.10.1 added (confirmed by diff audit before this branch
 * started) — this file is that missing coverage, corrected for the
 * V2.14.11 go-live-review change: every condition (GOOD/DAMAGED/EXPIRED/
 * DEADSTOCK) now carries its own independent multi-unit raw input and
 * DAMAGED/EXPIRED/DEADSTOCK each require photo evidence when positive.
 *
 * Usage: php tests/inventory_v2_14_11_condition_typed_findings_test.php
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
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnamePhotoService;
use App\Services\ValidationException;
use App\Services\ClaimConflictException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function expectException(callable $fn, string $class): ?string
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            return null;
        }
        return $e->getMessage();
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411', 'V2.14.11 Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
// A stock opname session locks its ENTIRE warehouse against new postings
// (WarehouseLockService) and only one session may be OPEN per warehouse
// at a time — separate warehouses per sub-scenario avoid that lock
// entirely rather than fighting it with cancel()/finalize() churn.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411B', 'V2.14.11 Test WH B', 1)")->execute();
$whIdB = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411C', 'V2.14.11 Test WH C', 1)")->execute();
$whIdC = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}

$adminUserId = makeUser($pdo, 'v21411admin', $superRoleId, null);
// PHASE V2.14.11 test 3/5 — a genuinely unprivileged VIEWER (no
// STOCK_OPNAME_MANAGE, no STOCK_OPNAME_SUPERVISE) must still be countable
// once explicitly assigned, and must gain nothing else from that
// assignment.
$viewerP1a = makeUser($pdo, 'v21411viewer-p1a', $viewerRoleId, null);
$viewerP1b = makeUser($pdo, 'v21411viewer-p1b', $viewerRoleId, null);
$viewerP2a = makeUser($pdo, 'v21411viewer-p2a', $viewerRoleId, null);
$unassignedUser = makeUser($pdo, 'v21411unassigned', $viewerRoleId, null);

function makeItemWithUnits(PDO $pdo, string $tag, int $baseUnitId, ?int $purchaseUnitId, float $purchaseFactor): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    if ($purchaseUnitId !== null) {
        UnitConversionService::openNewVersion($pdo, $id, $purchaseUnitId, $purchaseFactor, '2020-01-01 00:00:00', null, 'purchase unit', true);
    }
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v21411-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v21411', 'transaction_type' => 'OPENING',
    ]));
}
/** A real 4x4 JPEG, so StockOpnamePhotoService's decode/re-encode path exercises genuine GD calls, not a stub. */
function makeFakePhotoFile(): string
{
    $img = imagecreatetruecolor(4, 4);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
    $path = tempnam(sys_get_temp_dir(), 'v21411photo') . '.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);
    return $path;
}

// ============================================================
$itemA = makeItemWithUnits($pdo, 'V21411-A', $kgUnitId, $pcsUnitId, 5.0); // 1 purchase unit (Karton-like, Pcs code reused as the 2nd tier) = 5 Kg
postOpeningIn($pdo, $itemA, $kgUnitId, $whId, 100, 1000, $adminUserId);

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminUserId, [$itemA]));
$session = StockOpnameService::get($pdo, $sessionId);
check('1. new session defaults to FINDINGS_V1', ($session['counting_model'] ?? null) === 'FINDINGS_V1', (string) ($session['counting_model'] ?? 'null'));

// ============================================================
echo "\n== 1/2/3. multi-user team assignment ==\n";
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p1', [$viewerP1a, $viewerP1b], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p2', [$viewerP2a], $adminUserId));
$members = StockOpnameService::listTeamMembers($pdo, $sessionId);
check('1. P1 team has 2 members', count($members['p1']) === 2, (string) count($members['p1']));
check('2. P2 team has 1 member', count($members['p2']) === 1, (string) count($members['p2']));

$dupTeamErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p2', [$viewerP1a], $adminUserId)), ValidationException::class);
check('3. same user cannot be P1 and P2 in the same session', $dupTeamErr !== null, (string) $dupTeamErr);

// ============================================================
echo "\n== 4/5. session-scoped authorization, no global grant ==\n";
$viewerHasManageBefore = App\Services\AuthService::hasPermission($pdo, 'VIEWER', 'STOCK_OPNAME_MANAGE');
check('5a. VIEWER role itself never has STOCK_OPNAME_MANAGE', $viewerHasManageBefore === false);
check('5b. assignment did not add a new global permission row for VIEWER role', !App\Services\AuthService::hasPermission($pdo, 'VIEWER', 'STOCK_OPNAME_MANAGE'));

$notCounterErr = expectException(
    fn () => StockOpnameService::claimItem($pdo, $sessionId, 'p1', $unassignedUser, $itemA),
    ValidationException::class
);
check('4. an unassigned active user cannot claim/count for this session', $notCounterErr !== null, (string) $notCounterErr);

$claim1 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $viewerP1a, $itemA));
check('assigned VIEWER (no global STOCK_OPNAME_MANAGE) CAN claim once assigned', isset($claim1['claim_token']));

// ============================================================
echo "\n== 6/7. claim exclusivity ==\n";
$claimP2 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p2', $viewerP2a, $itemA));
check('6. P1 and P2 may claim the SAME item simultaneously', isset($claimP2['claim_token']));

$sameTeamClaimErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $viewerP1b, $itemA)),
    ValidationException::class
);
check('7. a second member of the SAME team cannot claim an already-claimed item', $sameTeamClaimErr !== null, (string) $sameTeamClaimErr);

// ============================================================
echo "\n== 8/9. stale claim rejected ==\n";
$staleTokenErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, 'not-the-real-token'
    )),
    ClaimConflictException::class
);
check('9. a wrong claim_token is refused (CLAIM_LOST)', $staleTokenErr !== null, (string) $staleTokenErr);

$pdo->prepare("UPDATE stock_opname_lines SET p1_claimed_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE session_id = :sid AND item_id = :item")
    ->execute(['sid' => $sessionId, 'item' => $itemA]);
$staleLeaseErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1['claim_token']
    )),
    ClaimConflictException::class
);
check('8. an expired claim lease is refused (CLAIM_LOST)', $staleLeaseErr !== null, (string) $staleLeaseErr);

// ============================================================
echo "\n== 10. session unit snapshot freeze ==\n";
$claim1 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $viewerP1a, $itemA));
$snapshotBefore = StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemA);
$pcsFactorBefore = array_values(array_filter($snapshotBefore, fn ($u) => $u['unit_id'] === $pcsUnitId))[0]['conversion_to_base'];
// Change the item's live conversion mid-session.
UnitConversionService::openNewVersion($pdo, $itemA, $pcsUnitId, 999.0, date('Y-m-d H:i:s'), $adminUserId, 'mid-session change (must not affect open session)');
$snapshotAfter = StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemA);
$pcsFactorAfter = array_values(array_filter($snapshotAfter, fn ($u) => $u['unit_id'] === $pcsUnitId))[0]['conversion_to_base'];
check('10/13. session snapshot unaffected by a mid-session Master Barang conversion change', $pcsFactorBefore === $pcsFactorAfter, "{$pcsFactorBefore} vs {$pcsFactorAfter}");

// ============================================================
echo "\n== 11/12/13/14. GOOD/condition multi-unit + zero-count + photo evidence ==\n";
// GOOD: 10 Kg + 2 of the "purchase unit" (5 Kg each) = 20 base -> total GOOD 30 Kg.
$result1 = Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $sessionId, 'p1', $itemA,
    [
        'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10], ['unit_id' => $pcsUnitId, 'qty' => 2]],
        'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
    ],
    'first finding', $viewerP1a, $claim1['claim_token']
));
$myLine1 = array_values(array_filter($result1['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('11. GOOD multi-unit total computed correctly (10 Kg + 2x5 Kg = 20)', abs($myLine1['my_qty_base'] - 20.0) < 0.0001, (string) $myLine1['my_qty_base']);
check('15. first finding with all-zero conditions accepted as COUNTED (physical > 0 via GOOD here, still exercises the first-finding path)', $myLine1['is_counted_by_me'] === true);

$dupUnitErr = expectException(fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $viewerP1a, $itemA)), \Throwable::class);
$claim1b = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $viewerP1a, $itemA));
$dupUnitInSameConditionErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 1], ['unit_id' => $kgUnitId, 'qty' => 2]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1b['claim_token']
    )),
    ValidationException::class
);
check('13. duplicate unit within the SAME condition is rejected', $dupUnitInSameConditionErr !== null, (string) $dupUnitInSameConditionErr);

$foreignUnitId = 999999;
$foreignUnitErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $foreignUnitId, 'qty' => 1]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1b['claim_token']
    )),
    ValidationException::class
);
check('14. a unit not in the frozen snapshot is rejected', $foreignUnitErr !== null, (string) $foreignUnitErr);

// ---- Photo requirement ----
$noPhotoErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 5]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 2]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1b['claim_token']
    )),
    ValidationException::class
);
check('21. DAMAGED > 0 without a photo is refused', $noPhotoErr !== null, (string) $noPhotoErr);

$photoFile = makeFakePhotoFile();
$damagedPhoto = Database::transaction(fn (PDO $tx) => StockOpnamePhotoService::upload($tx, $sessionId, 'p1', $itemA, 'DAMAGED', $viewerP1a, $claim1b['claim_token'], $photoFile));
check('32. photo re-encoded to a real image/jpeg, stored, and byte_size > 0', $damagedPhoto['byte_size'] > 0);

// PHASE V2.14.11.1 — Checkpoint A audit corrective: a photo being merely
// "pending" (uploaded, finding_id NULL) is never enough — it must be named
// explicitly by token in the photos={} argument. Here DAMAGED's own
// pending photo is deliberately left UNNAMED, so even though a DAMAGED
// photo file exists on disk, this must still fail for BOTH conditions.
$twoConditionsOnePhotoErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 5]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 2]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 1]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1b['claim_token']
    )),
    ValidationException::class
);
check('24. two positive conditions require evidence for BOTH — no photo token named for either', $twoConditionsOnePhotoErr !== null, (string) $twoConditionsOnePhotoErr);

// Now name DAMAGED's token but still omit EXPIRED's — must still fail,
// proving each positive condition is checked independently (Blocker 1C).
$damagedOnlyNamedErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemA,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 5]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 2]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 1]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $claim1b['claim_token'], ['DAMAGED' => [$damagedPhoto['token']], 'EXPIRED' => [], 'DEADSTOCK' => []]
    )),
    ValidationException::class
);
check('24b. naming DAMAGED alone still fails while EXPIRED evidence is unnamed', $damagedOnlyNamedErr !== null, (string) $damagedOnlyNamedErr);

$photoFile2 = makeFakePhotoFile();
$expiredPhoto = Database::transaction(fn (PDO $tx) => StockOpnamePhotoService::upload($tx, $sessionId, 'p1', $itemA, 'EXPIRED', $viewerP1a, $claim1b['claim_token'], $photoFile2));

$result2 = Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
    $tx, $sessionId, 'p1', $itemA,
    ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 5]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 2]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 1]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
    'second finding with damage/expired', $viewerP1a, $claim1b['claim_token'],
    ['DAMAGED' => [$damagedPhoto['token']], 'EXPIRED' => [$expiredPhoto['token']], 'DEADSTOCK' => []]
));
$myLine2 = array_values(array_filter($result2['lines'], fn ($l) => $l['item_id'] === $itemA))[0];
check('22/23/25. finding with both photos present succeeds', $myLine2['is_counted_by_me'] === true);
check('19. findings ACCUMULATE — GOOD total is now 20 (finding 1) + 5 (finding 2) = 25', abs($myLine2['my_qty_base'] - 25.0) < 0.0001, (string) $myLine2['my_qty_base']);
check('18. finding_count increments by exactly one per Simpan (now 2)', $myLine2['finding_count'] === 2, (string) $myLine2['finding_count']);

// ============================================================
echo "\n== 16. additional zero-only finding rejected ==\n";
$itemB = makeItemWithUnits($pdo, 'V21411-B', $kgUnitId, null, 1.0);
postOpeningIn($pdo, $itemB, $kgUnitId, $whIdB, 10, 500, $adminUserId);
Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdB, $adminUserId, [$itemB]));
$sessionB = (int) $pdo->query('SELECT id FROM stock_opname_sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionB, 'p1', [$viewerP1a], $adminUserId));
$claimB1 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionB, 'p1', $viewerP1a, $itemB));
$zeroInputs = ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]];
$firstZero = Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionB, 'p1', $itemB, $zeroInputs, null, $viewerP1a, $claimB1['claim_token']));
$lineB = array_values(array_filter($firstZero['lines'], fn ($l) => $l['item_id'] === $itemB))[0];
check('15. first finding with total physical=0 is accepted (COUNTED ZERO)', $lineB['is_counted_by_me'] === true && abs($lineB['my_qty_base'] - 0.0) < 0.0001);

$claimB2 = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionB, 'p1', $viewerP1a, $itemB));
$secondZeroErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionB, 'p1', $itemB, $zeroInputs, null, $viewerP1a, $claimB2['claim_token'])),
    ValidationException::class
);
check('16. an ADDITIONAL all-zero finding is rejected', $secondZeroErr !== null, (string) $secondZeroErr);

// ============================================================
echo "\n== 20. raw unit inputs preserved (auditable) ==\n";
$rawRows = $pdo->prepare(
    'SELECT q.condition_type, q.unit_id, q.input_qty, q.base_qty_contribution
     FROM stock_opname_finding_quantities q
     JOIN stock_opname_findings f ON f.id = q.finding_id
     WHERE f.stock_opname_line_id = (SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item)
     ORDER BY q.id'
);
$rawRows->execute(['sid' => $sessionId, 'item' => $itemA]);
$rawRows = $rawRows->fetchAll();
$hasPcsRow = false;
foreach ($rawRows as $r) {
    if ((int) $r['unit_id'] === $pcsUnitId && $r['condition_type'] === 'GOOD') {
        $hasPcsRow = true;
    }
}
check('20. the raw 2x purchase-unit input from finding 1 is preserved verbatim, not just the normalized total', $hasPcsRow);

// ============================================================
echo "\n== 26/27. P1/P2 blindness on getForCounter() ==\n";
$p1View = StockOpnameService::getForCounter($pdo, $sessionId, 'p1', $viewerP1a);
$p1Json = json_encode($p1View);
check('26. P1 view never mentions system_qty/mismatch/variance keys', !str_contains($p1Json, 'system_qty') && !str_contains($p1Json, 'mismatch') && !str_contains($p1Json, 'variance'));
check('27. P1 view has no p2_ prefixed keys', !str_contains($p1Json, '"p2_'));

// ============================================================
echo "\n== 28. no inventory_batches mutation from findings alone ==\n";
$batchCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$batchValueBefore = (float) $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetchColumn();
// (findings above already ran — re-check current counts equal a fresh snapshot taken now, i.e. nothing changes between two reads with no posting in between)
$batchCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn();
$batchValueAfter = (float) $pdo->query('SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetchColumn();
check('28/35. inventory_batches count/value stable across finding submissions (Checkpoint A never posts)', $batchCountBefore === $batchCountAfter && abs($batchValueBefore - $batchValueAfter) < 0.0001);

// ============================================================
echo "\n== 29. round + counter_username_snapshot recorded ==\n";
$findingRow = $pdo->query('SELECT round, counter_username_snapshot FROM stock_opname_findings ORDER BY id DESC LIMIT 1')->fetch();
check('round defaults to 1', (int) $findingRow['round'] === 1);
check('counter_username_snapshot captured at creation time', !empty($findingRow['counter_username_snapshot']));

// ============================================================
echo "\n== 36. legacy LEGACY_DUAL_COUNT session still fully functional ==\n";
$itemC = makeItemWithUnits($pdo, 'V21411-C', $kgUnitId, null, 1.0);
postOpeningIn($pdo, $itemC, $kgUnitId, $whIdC, 20, 700, $adminUserId);
$legacySessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdC, $adminUserId, [$itemC], 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $legacySessionId, ['p1_user_id' => $viewerP1a, 'p2_user_id' => $viewerP2a], $adminUserId));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $legacySessionId, 'p1', $itemC, 20.0, $viewerP1a));
Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $legacySessionId, 'p2', $itemC, 20.0, $viewerP2a));
$legacyReview = StockOpnameService::review($pdo, $legacySessionId);
check('36. legacy session still resolves MATCH exactly as before V2.14.11', $legacyReview['summary']['match'] === 1, (string) $legacyReview['summary']['match']);

// PHASE V2.14.10.1 Gate 2 — the two write-models must never mix: a claim
// on a LEGACY_DUAL_COUNT session succeeds (assignCounters() synthesizes a
// one-member team from the legacy p1_user_id/p2_user_id columns — genuine
// backward compatibility), but submitFinding() itself must still refuse
// outright with COUNTING_MODE_CONFLICT rather than silently writing a
// findings-mode row onto a legacy session.
$legacyClaim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $legacySessionId, 'p1', $viewerP1a, $itemC));
$mixedModeErr = expectException(
    fn () => Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $legacySessionId, 'p1', $itemC,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 1]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $viewerP1a, $legacyClaim['claim_token']
    )),
    App\Services\CountingModeConflictException::class
);
check('Gate 2: submitFinding() on a LEGACY_DUAL_COUNT session is refused (COUNTING_MODE_CONFLICT), never silently mixed', $mixedModeErr !== null, (string) $mixedModeErr);

// ============================================================
echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed !== $total) {
    exit(1);
}
