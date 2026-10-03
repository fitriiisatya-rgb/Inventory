<?php
declare(strict_types=1);

/**
 * STABILIZATION — Task 4: permanent FINDINGS_V1 base-unit snapshot safety.
 *
 * Historical incident: some FINDINGS_V1 sessions were previously created
 * without a complete stock_opname_line_units snapshot for every item in
 * scope, which submitFinding()/getSnapshotUnitsForItem() depend on as
 * their ONLY unit source for counting/finalize/report/post. This proves
 * StockOpnameService::start() and upgradeToFindingsMode() (the only two
 * writers of that table — snapshotSessionUnits(), called from both) ALWAYS
 * leave every item in a FINDINGS_V1 session's scope with:
 *   - at least one snapshot row (its base unit, at minimum),
 *   - exactly one row flagged is_base_unit=1, matching the item's actual
 *     base_unit_id, with conversion_factor_snapshot = 1.0,
 *   - every OTHER currently-valid purchase/alternate unit also snapshotted
 *     (not just the base unit) when the item has one.
 * This is proven going forward only — this test never touches any
 * pre-existing session (none exist in a fresh disposable test DB; the
 * mechanism itself, read in services/StockOpnameService.php, only ever
 * INSERTs rows scoped to the NEW session's own line ids — see
 * snapshotSessionUnits()'s docblock).
 *
 * Usage: php tests/inventory_stabilization_findings_unit_snapshot_test.php
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

use App\Services\Database;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$gramUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='GR'")->fetchColumn();

$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => uid('stbsnap'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'stbsnap', 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Stabilization Snapshot WH', 1)")->execute(['c' => uid('STBSNAP-WH')]);
$whId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, string $tag, int $baseUnitId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}

// Item 1: base unit only (GR), no alternate purchase unit.
$itemBaseOnly = makeItem($pdo, 'STBSNAP-BASEONLY', $gramUnitId);

// Item 2: base unit (GR) PLUS an alternate purchase unit (KG, 1 KG = 1000 GR).
$itemMultiUnit = makeItem($pdo, 'STBSNAP-MULTI', $gramUnitId);
Database::transaction(fn (PDO $tx) => UnitConversionService::openNewVersion($tx, $itemMultiUnit, $kgUnitId, 1000.0, '2020-01-01 00:00:00', null, '1 KG = 1000 GR'));

// Item 3: base unit PCS, used to prove the PER-ITEM snapshot never mixes
// one item's units onto another's line.
$itemPcs = makeItem($pdo, 'STBSNAP-PCS', $pcsUnitId);

// ---- Task 4 proof A: a brand-new FINDINGS_V1 session (start()'s own
// default countingModel — the one every real "Mulai Opname" action uses).
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start(
    $tx, $whId, $adminId, [$itemBaseOnly, $itemMultiUnit, $itemPcs], 'FINDINGS_V1'
));

$lineIdByItem = [];
$lineStmt = $pdo->prepare('SELECT id, item_id FROM stock_opname_lines WHERE session_id = :sid');
$lineStmt->execute(['sid' => $sessionId]);
foreach ($lineStmt->fetchAll() as $row) {
    $lineIdByItem[(int) $row['item_id']] = (int) $row['id'];
}
check('1. every item in scope got its own stock_opname_lines row', count($lineIdByItem) === 3, (string) count($lineIdByItem));

function snapshotRowsForLine(PDO $pdo, int $lineId): array
{
    $stmt = $pdo->prepare('SELECT * FROM stock_opname_line_units WHERE stock_opname_line_id = :id');
    $stmt->execute(['id' => $lineId]);
    return $stmt->fetchAll();
}

$snapBaseOnly = snapshotRowsForLine($pdo, $lineIdByItem[$itemBaseOnly]);
check('2. base-unit-only item still gets a snapshot row (never zero rows)', count($snapBaseOnly) === 1, (string) count($snapBaseOnly));
check('2b. its one row is flagged is_base_unit=1', (int) $snapBaseOnly[0]['is_base_unit'] === 1);
check('2c. its base unit_id matches the item\'s actual base_unit_id', (int) $snapBaseOnly[0]['unit_id'] === $gramUnitId);
check('2d. base unit conversion_factor_snapshot is 1.0 (identity)', abs((float) $snapBaseOnly[0]['conversion_factor_snapshot'] - 1.0) < 0.000001);

$snapMulti = snapshotRowsForLine($pdo, $lineIdByItem[$itemMultiUnit]);
check('3. a multi-unit item gets BOTH its base unit AND its alternate purchase unit snapshotted', count($snapMulti) === 2, (string) count($snapMulti));
$baseRows = array_values(array_filter($snapMulti, fn ($r) => (int) $r['is_base_unit'] === 1));
$altRows = array_values(array_filter($snapMulti, fn ($r) => (int) $r['is_base_unit'] === 0));
check('3b. exactly ONE row is flagged as the base unit', count($baseRows) === 1, (string) count($baseRows));
check('3c. the base row is GR with factor 1.0', count($baseRows) === 1 && (int) $baseRows[0]['unit_id'] === $gramUnitId && abs((float) $baseRows[0]['conversion_factor_snapshot'] - 1.0) < 0.000001);
check('3d. the alternate row is KG with factor 1000.0 (1 KG = 1000 GR)', count($altRows) === 1 && (int) $altRows[0]['unit_id'] === $kgUnitId && abs((float) $altRows[0]['conversion_factor_snapshot'] - 1000.0) < 0.000001);

$snapPcs = snapshotRowsForLine($pdo, $lineIdByItem[$itemPcs]);
check('4. a THIRD item\'s snapshot never leaks another item\'s units onto its own line', count($snapPcs) === 1 && (int) $snapPcs[0]['unit_id'] === $pcsUnitId, (string) count($snapPcs));

// ---- Task 4 proof B: getSnapshotUnitsForItem() (the ONLY unit source the
// real counting screen ever reads) sees exactly this same data.
$fetched = StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemMultiUnit);
check('5. getSnapshotUnitsForItem() returns both units for the multi-unit item', count($fetched) === 2, (string) count($fetched));
$fetchedBase = array_values(array_filter($fetched, fn ($u) => $u['is_base_unit']));
check('5b. getSnapshotUnitsForItem() correctly flags the base unit among them', count($fetchedBase) === 1 && $fetchedBase[0]['code'] === 'GR');

// ---- Task 4 proof C: a LEGACY_DUAL_COUNT session upgraded to FINDINGS_V1
// (upgradeToFindingsMode() — the OTHER writer of this table) gets the
// exact same complete snapshot, not a partial one.
$itemUpgrade = makeItem($pdo, 'STBSNAP-UPGRADE', $gramUnitId);
Database::transaction(fn (PDO $tx) => UnitConversionService::openNewVersion($tx, $itemUpgrade, $pcsUnitId, 12.0, '2020-01-01 00:00:00', null, '1 lusin = 12 pcs'));
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Stabilization Snapshot WH2', 1)")->execute(['c' => uid('STBSNAP-WH2')]);
$whId2 = (int) $pdo->lastInsertId();
$legacySessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId2, $adminId, [$itemUpgrade], 'LEGACY_DUAL_COUNT'));
$beforeUpgrade = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_line_units WHERE session_id = :sid');
$beforeUpgrade->execute(['sid' => $legacySessionId]);
check('6. a LEGACY_DUAL_COUNT session has NO unit snapshot at all (never needed until upgraded)', (int) $beforeUpgrade->fetchColumn() === 0);

Database::transaction(fn (PDO $tx) => StockOpnameService::upgradeToFindingsMode($tx, $legacySessionId, $adminId));
$legacyLine = $pdo->prepare('SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
$legacyLine->execute(['sid' => $legacySessionId, 'item' => $itemUpgrade]);
$legacyLineId = (int) $legacyLine->fetchColumn();
$snapUpgraded = snapshotRowsForLine($pdo, $legacyLineId);
check('7. after upgradeToFindingsMode(), the session gets a COMPLETE snapshot (both units)', count($snapUpgraded) === 2, (string) count($snapUpgraded));

// ---- Task 4 proof D: idempotent re-snapshot never duplicates or corrupts
// rows (snapshotSessionUnits() uses ON DUPLICATE KEY UPDATE unit_id=unit_id).
Database::transaction(fn (PDO $tx) => StockOpnameService::upgradeToFindingsMode($tx, $legacySessionId, $adminId));
$snapUpgradedAgain = snapshotRowsForLine($pdo, $legacyLineId);
check('8. calling the upgrade again (idempotent replay) never duplicates snapshot rows', count($snapUpgradedAgain) === 2, (string) count($snapUpgradedAgain));

// ---- Task 4 proof E (explicit "do not mutate sessions 11/12" guard,
// proven structurally): snapshotSessionUnits() is called ONLY from
// start() and upgradeToFindingsMode(), each scoped to the ONE session id
// it was just given — grep-level proof that no code path re-snapshots an
// arbitrary EXISTING session's lines from a DIFFERENT call site.
$snapshotCallers = shell_exec('grep -n "snapshotSessionUnits(" ' . escapeshellarg(__DIR__ . '/../services/StockOpnameService.php'));
$callerLines = array_filter(array_map('trim', explode("\n", (string) $snapshotCallers)));
check('9. snapshotSessionUnits() has exactly 3 call sites (its own def + start() + upgradeToFindingsMode()) — no ad-hoc re-snapshot path exists', count($callerLines) === 3, (string) count($callerLines));

$failed = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $failed) . ' / ' . count($results) . " PASSED\n";
exit($failed > 0 ? 1 : 0);
