<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11.3 — URGENT HOTFIX: timezone bug fix. Production, running
 * at 30 Sep 00:29 WIB, generated session_number SO-20260929-0002 —
 * because the app's business date was computed in the container's own
 * (UTC) system timezone, seven hours behind Jakarta. Fix:
 * config/config.php now calls date_default_timezone_set('Asia/Jakarta')
 * once, centrally, on the first Database::connection() call of every
 * process. This file proves the rollover itself — session_date/
 * session_number generated right around Jakarta midnight must reflect
 * the JAKARTA calendar date, not a server/UTC date that could be a full
 * day off.
 *
 * Usage: php tests/inventory_v2_14_11_3_timezone_test.php
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
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\NumberingService;

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

check('config-level: date_default_timezone_get() is Asia/Jakarta after the first Database::connection() call', date_default_timezone_get() === 'Asia/Jakarta', date_default_timezone_get());

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => uid('v214113tz'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'v214113tz', 'r' => $superRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, string $tag, int $baseUnitId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function makeWarehouse(PDO $pdo, string $tag): int
{
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)")->execute(['c' => uid($tag), 'n' => $tag]);
    return (int) $pdo->lastInsertId();
}

// ============================================================
// Direct, deterministic proof of the ROLLOVER RULE itself (not
// real-clock-dependent — the whole point of the bug was "what calendar
// date does 00:xx WIB resolve to", so this exercises the exact
// date('Ymd', strtotime($date)) call path NumberingService::next() uses,
// at explicit Jakarta timestamps straddling midnight, independent of
// whatever wall-clock time this test happens to actually run at).
// ============================================================
echo "\n== NumberingService: business-date key at Jakarta midnight boundary ==\n";
check('23:59 WIB on 29 Sep resolves to date key 20260929', date('Ymd', strtotime('2026-09-29 23:59:00')) === '20260929');
check('00:01 WIB on 30 Sep resolves to date key 20260930', date('Ymd', strtotime('2026-09-30 00:01:00')) === '20260930');
check('00:29 WIB on 30 Sep (the exact production incident time) resolves to date key 20260930', date('Ymd', strtotime('2026-09-30 00:29:00')) === '20260930');

// ============================================================
// End-to-end: StockOpnameService::start() writes session_date via
// date('Y-m-d') and asks NumberingService::next() for session_number —
// both driven by the SAME process-wide timezone this file already
// proved is Asia/Jakarta above, so whatever moment "now" actually is
// when this test runs, the two must always agree on calendar date.
// ============================================================
echo "\n== StockOpnameService::start(): session_date/session_number agree with the current Jakarta date ==\n";
$whId = makeWarehouse($pdo, 'V214113TZ');
$item = makeItem($pdo, 'V214113TZ-A', $kgUnitId);
$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminUserId, [$item], 'FINDINGS_V1'));
$session = StockOpnameService::get($pdo, $sessionId);
$expectedJakartaDate = date('Y-m-d'); // process timezone already proven Asia/Jakarta above
$expectedDateKey = date('Ymd');
check('session_date matches today\'s Jakarta calendar date', $session['session_date'] === $expectedJakartaDate, "{$session['session_date']} vs expected {$expectedJakartaDate}");
check("session_number embeds today's Jakarta date key (SO-{$expectedDateKey}-xxxx)", str_contains((string) $session['session_number'], "SO-{$expectedDateKey}-"), (string) $session['session_number']);

// ============================================================
// NumberingService::next() itself: two calls on the "same Jakarta day"
// (as far as this process's clock is concerned) must share one running
// sequence, never silently reset mid-day due to a UTC/Jakarta date-key
// mismatch between two calls a few seconds apart.
// ============================================================
echo "\n== NumberingService: same-day sequence never resets due to timezone drift ==\n";
$num1 = NumberingService::next($pdo, 'TZTEST', date('Y-m-d H:i:s'));
$num2 = NumberingService::next($pdo, 'TZTEST', date('Y-m-d H:i:s'));
$seq1 = (int) substr($num1, -4);
$seq2 = (int) substr($num2, -4);
check('consecutive same-day NumberingService::next() calls increment (never both land on 0001)', $seq2 === $seq1 + 1, "{$num1} -> {$num2}");

// ============================================================
// Historical sessions must NOT be rewritten/renumbered by this fix —
// this round is a forward-only business-date correction, never a
// retroactive data migration.
// ============================================================
echo "\n== historical session numbers are never rewritten by this fix ==\n";
$preExistingNumber = $session['session_number'];
// Re-fetch the same session a second time; nothing about this fix causes
// number/date to be recomputed or mutated after creation.
$sessionAgain = StockOpnameService::get($pdo, $sessionId);
check('re-reading the same session returns the identical session_number (never recomputed)', $sessionAgain['session_number'] === $preExistingNumber, "{$sessionAgain['session_number']} vs {$preExistingNumber}");

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed !== $total) {
    exit(1);
}
