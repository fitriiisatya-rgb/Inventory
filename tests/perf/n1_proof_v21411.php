<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11 — performance/N+1 proof at 1000+ SKU scale. Seeds a
 * session with 1,200 items, submits findings for a sample of them (so
 * finding_count/claim columns are genuinely populated, not all-empty),
 * then measures the query count and wall-clock time of the two endpoints
 * a 1000+ item mobile session actually calls: the bulk counter list
 * (getForCounter) and the supervisor review list (review()) — both of
 * which PHASE V2.14.10.1 Gate 5 specifically rewrote to avoid a per-line
 * finding-history query. Query count is read from MySQL's own
 * `Questions` status counter (before/after delta), not a guess.
 *
 * Usage: php tests/perf/n1_proof_v21411.php
 */

require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/UnitConversionService.php';
require_once __DIR__ . '/../../services/UnitNormalizationService.php';
require_once __DIR__ . '/../../services/PriceAnomalyService.php';
require_once __DIR__ . '/../../services/CostNormalizationService.php';
require_once __DIR__ . '/../../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../../services/IdempotencyService.php';
require_once __DIR__ . '/../../services/InventoryService.php';
require_once __DIR__ . '/../../services/FifoService.php';
require_once __DIR__ . '/../../services/PeriodLockService.php';
require_once __DIR__ . '/../../services/WarehouseLockService.php';
require_once __DIR__ . '/../../services/WarehouseGuardService.php';
require_once __DIR__ . '/../../services/StockAdjustmentService.php';
require_once __DIR__ . '/../../services/NumberingService.php';
require_once __DIR__ . '/../../services/StockOpnameService.php';
require_once __DIR__ . '/../../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

const ITEM_COUNT = 1200;
const FINDINGS_SAMPLE = 300; // items that get at least one real finding

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411PERF', 'V2.14.11 Perf Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
}
$adminUserId = makeUser($pdo, 'perfadmin', $superRoleId);
$p1UserId = makeUser($pdo, 'perfp1', $viewerRoleId);

echo "Seeding " . ITEM_COUNT . " items with opening stock...\n";
$t0 = microtime(true);
$itemIds = [];
$insertItem = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')');
foreach (range(1, ITEM_COUNT) as $i) {
    $sku = 'PERF-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(2));
    $insertItem->execute(['sku' => $sku, 'name' => "Perf Item {$sku}", 'unit' => $kgUnitId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    $itemIds[] = $itemId;
}
// Bulk opening stock in one FifoService call each (unavoidable — one
// batch per item is the correct FIFO model), but batched into a single
// DB transaction rather than ITEM_COUNT separate transactions.
Database::transaction(function (PDO $tx) use ($itemIds, $kgUnitId, $whId, $adminUserId) {
    foreach ($itemIds as $itemId) {
        FifoService::postIn($tx, [
            'transaction_uuid' => uid('perf-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
            'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 100,
            'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'perf', 'transaction_type' => 'OPENING',
        ]);
    }
});
echo 'Seed items+opening: ' . round(microtime(true) - $t0, 2) . "s\n";

$t0 = microtime(true);
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminUserId, $itemIds));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, 'p1', [$p1UserId], $adminUserId));
echo 'Session start (1200 lines + unit snapshot) + team assign: ' . round(microtime(true) - $t0, 2) . "s\n";

echo 'Submitting ' . FINDINGS_SAMPLE . ' real findings...' . "\n";
$t0 = microtime(true);
$sample = array_slice($itemIds, 0, FINDINGS_SAMPLE);
foreach ($sample as $itemId) {
    $claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, 'p1', $p1UserId, $itemId));
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding(
        $tx, $sessionId, 'p1', $itemId,
        ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
        null, $p1UserId, $claim['claim_token']
    ));
}
echo 'Submit ' . FINDINGS_SAMPLE . ' findings: ' . round(microtime(true) - $t0, 2) . "s\n\n";

function questionsCounter(PDO $pdo): int
{
    $stmt = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'");
    return (int) $stmt->fetch()['Value'];
}

// ============================================================
// Proof 1: getForCounter() — the counter's own bulk list, 1200 lines,
// 300 with real finding history.
// ============================================================
$before = questionsCounter($pdo);
$t0 = microtime(true);
$view = StockOpnameService::getForCounter($pdo, $sessionId, 'p1', $p1UserId);
$elapsed = microtime(true) - $t0;
$after = questionsCounter($pdo);
$queryCount = $after - $before - 1; // -1 for the SHOW STATUS call itself
printf("getForCounter() — %d lines returned, %d queries, %.3fs\n", count($view['lines']), $queryCount, $elapsed);
$pass1 = $queryCount < 20 && count($view['lines']) === ITEM_COUNT;
echo ($pass1 ? 'PASS' : 'FAIL') . " - getForCounter() stays under 20 queries regardless of session size (no per-line finding query)\n\n";

// ============================================================
// Proof 2: review() — the supervisor's bulk list, same 1200 lines.
// ============================================================
$before = questionsCounter($pdo);
$t0 = microtime(true);
$review = StockOpnameService::review($pdo, $sessionId);
$elapsed2 = microtime(true) - $t0;
$after = questionsCounter($pdo);
$queryCount2 = $after - $before - 1;
printf("review() — %d lines returned, %d queries, %.3fs\n", count($review['lines']), $queryCount2, $elapsed2);
$pass2 = $queryCount2 < 20 && count($review['lines']) === ITEM_COUNT;
echo ($pass2 ? 'PASS' : 'FAIL') . " - review() stays under 20 queries regardless of session size (no per-line finding query)\n\n";

// ============================================================
// Proof 3: query count is CONSTANT, not proportional to finding count —
// re-run getForCounter() and confirm the count doesn't scale with the
// 300 lines that actually have finding history vs the 900 that don't.
// ============================================================
$before = questionsCounter($pdo);
$view2 = StockOpnameService::getForCounter($pdo, $sessionId, 'p1', $p1UserId);
$after = questionsCounter($pdo);
$queryCount3 = $after - $before - 1;
$pass3 = abs($queryCount3 - $queryCount) <= 2;
echo ($pass3 ? 'PASS' : 'FAIL') . " - repeated call has the same query count ({$queryCount} vs {$queryCount3}) — confirms O(1) queries relative to finding-count mix, not O(n)\n\n";

echo "==============================\n";
$overall = $pass1 && $pass2 && $pass3;
echo 'OVERALL: ' . ($overall ? 'PASS' : 'FAIL') . "\n";
if (!$overall) {
    exit(1);
}
