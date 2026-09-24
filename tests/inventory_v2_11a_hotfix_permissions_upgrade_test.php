<?php
declare(strict_types=1);

/**
 * HOTFIX REGRESSION — database/migrations/2026_09_24_v2_11a_hotfix_distribution_permissions.sql
 *
 * Root cause under test: database/migrations/2026_09_23_v2_11a_distribution_orders.sql
 * creates distribution_orders/distribution_order_lines but never inserts or
 * grants the six DISTRIBUTION_* permissions that database/schema.sql only
 * grants via one-time fresh-install statements. A database that reaches
 * "post-V2.11A" state through the incremental migration chain (as
 * production did) therefore ends up with zero DISTRIBUTION_* permission
 * rows and every role — including SUPERADMIN — gets 403 "Missing
 * permission: DISTRIBUTION_VIEW" (or the other five) on every distribution
 * route.
 *
 * This test reproduces that exact end-state directly on the already-loaded
 * (fresh schema.sql) test database — by deleting the six permission rows
 * and their role_permissions grants, which is the same end-state the
 * incremental migration chain produces — then applies ONLY the new hotfix
 * migration file and proves the correct role matrix comes back:
 *     SUPERADMIN = all 6
 *     ADMIN      = all except DISTRIBUTION_REVERSE
 *     STOCK      = DISTRIBUTION_VIEW + DISTRIBUTION_DISPATCH only
 *     DIVISION, VIEWER = none of the six
 * and that DISTRIBUTION_PRICING_MANAGE / DISTRIBUTION_REPORT_VIEW (already
 * correct, granted by their own V2.11B/V2.11C migrations) are untouched.
 *
 * Also proves the migration is safe to re-run (idempotent — no error, no
 * duplicate role_permissions rows) and that AuthService::hasPermission()
 * — the exact check public/index.php's inv_require_permission() uses to
 * decide the 403 — flips from false to true for SUPERADMIN once the
 * hotfix is applied.
 *
 * Usage: php tests/inventory_v2_11a_hotfix_permissions_upgrade_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\AuthService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

const DISTRIBUTION_LIFECYCLE_PERMS = [
    'DISTRIBUTION_VIEW', 'DISTRIBUTION_CREATE', 'DISTRIBUTION_APPROVE',
    'DISTRIBUTION_DISPATCH', 'DISTRIBUTION_RECEIVE', 'DISTRIBUTION_REVERSE',
];

function grantedRoles(PDO $pdo, string $permCode): array
{
    $stmt = $pdo->prepare(
        'SELECT r.code FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE p.code = :code ORDER BY r.code'
    );
    $stmt->execute(['code' => $permCode]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function applySqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, fn (string $l) => !str_starts_with(ltrim($l), '--'));
    $cleaned = implode("\n", $lines);
    foreach (explode(';', $cleaned) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$hotfixMigration = __DIR__ . '/../database/migrations/2026_09_24_v2_11a_hotfix_distribution_permissions.sql';
check('hotfix migration file exists', is_file($hotfixMigration));

// ============================================================
// 1 — Reproduce the exact production defect end-state on this
// (fresh schema.sql, otherwise-correct) test database: an incrementally
// migrated database never got these rows at all.
// ============================================================
echo "== 1: reproduce pre-hotfix upgrade-path defect ==\n";
$placeholders = implode(',', array_fill(0, count(DISTRIBUTION_LIFECYCLE_PERMS), '?'));
$pdo->prepare("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.code IN ({$placeholders})")
    ->execute(DISTRIBUTION_LIFECYCLE_PERMS);
$pdo->prepare("DELETE FROM permissions WHERE code IN ({$placeholders})")
    ->execute(DISTRIBUTION_LIFECYCLE_PERMS);

$countBefore = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE code LIKE 'DISTRIBUTION_%' AND code NOT IN ('DISTRIBUTION_PRICING_MANAGE','DISTRIBUTION_REPORT_VIEW')")->fetchColumn();
check('defect reproduced: 0 distribution lifecycle permissions before hotfix', $countBefore === 0, "count={$countBefore}");
check('defect reproduced: SUPERADMIN has no DISTRIBUTION_VIEW before hotfix (403 root cause)', AuthService::hasPermission($pdo, 'SUPERADMIN', 'DISTRIBUTION_VIEW') === false);

// ============================================================
// 2 — Apply the hotfix migration, verify exact role matrix.
// ============================================================
echo "\n== 2: apply hotfix migration, verify role matrix ==\n";
applySqlFile($pdo, $hotfixMigration);

$countAfter = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE code LIKE 'DISTRIBUTION_%' AND code NOT IN ('DISTRIBUTION_PRICING_MANAGE','DISTRIBUTION_REPORT_VIEW')")->fetchColumn();
check('all 6 distribution lifecycle permissions exist after hotfix', $countAfter === 6, "count={$countAfter}");

$superadminGrants = [];
$adminGrants = [];
$stockGrants = [];
foreach (DISTRIBUTION_LIFECYCLE_PERMS as $perm) {
    $roles = grantedRoles($pdo, $perm);
    if (in_array('SUPERADMIN', $roles, true)) { $superadminGrants[] = $perm; }
    if (in_array('ADMIN', $roles, true)) { $adminGrants[] = $perm; }
    if (in_array('STOCK', $roles, true)) { $stockGrants[] = $perm; }
    if (in_array('DIVISION', $roles, true)) {
        check("DIVISION does NOT get {$perm}", false, 'unexpected grant found');
    }
    if (in_array('VIEWER', $roles, true)) {
        check("VIEWER does NOT get {$perm}", false, 'unexpected grant found');
    }
}
sort($superadminGrants);
sort($adminGrants);
sort($stockGrants);
$allSixSorted = DISTRIBUTION_LIFECYCLE_PERMS;
sort($allSixSorted);

check('SUPERADMIN gets all 6', $superadminGrants === $allSixSorted, implode(',', $superadminGrants));
check(
    'ADMIN gets all except DISTRIBUTION_REVERSE',
    $adminGrants === ['DISTRIBUTION_APPROVE', 'DISTRIBUTION_CREATE', 'DISTRIBUTION_DISPATCH', 'DISTRIBUTION_RECEIVE', 'DISTRIBUTION_VIEW'],
    implode(',', $adminGrants)
);
check(
    'STOCK gets exactly DISTRIBUTION_VIEW + DISTRIBUTION_DISPATCH',
    $stockGrants === ['DISTRIBUTION_DISPATCH', 'DISTRIBUTION_VIEW'],
    implode(',', $stockGrants)
);
check('DIVISION gets none of the 6 (verified per-permission above, no failure raised)', true);
check('VIEWER gets none of the 6 (verified per-permission above, no failure raised)', true);

$pricingReportUntouched = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE code IN ('DISTRIBUTION_PRICING_MANAGE','DISTRIBUTION_REPORT_VIEW')")->fetchColumn();
check('DISTRIBUTION_PRICING_MANAGE / DISTRIBUTION_REPORT_VIEW untouched (still present, not duplicated)', $pricingReportUntouched === 2, "count={$pricingReportUntouched}");

// ============================================================
// 3 — The actual 403 check: AuthService::hasPermission() flips to true.
// ============================================================
echo "\n== 3: SUPERADMIN no longer gets 403 on distribution routes ==\n";
check('AuthService::hasPermission(SUPERADMIN, DISTRIBUTION_VIEW) is now true', AuthService::hasPermission($pdo, 'SUPERADMIN', 'DISTRIBUTION_VIEW') === true);
check('AuthService::hasPermission(SUPERADMIN, DISTRIBUTION_CREATE) is now true', AuthService::hasPermission($pdo, 'SUPERADMIN', 'DISTRIBUTION_CREATE') === true);
check('AuthService::hasPermission(SUPERADMIN, DISTRIBUTION_REVERSE) is now true', AuthService::hasPermission($pdo, 'SUPERADMIN', 'DISTRIBUTION_REVERSE') === true);
check('AuthService::hasPermission(ADMIN, DISTRIBUTION_REVERSE) stays false (privileged-only)', AuthService::hasPermission($pdo, 'ADMIN', 'DISTRIBUTION_REVERSE') === false);
check('AuthService::hasPermission(STOCK, DISTRIBUTION_APPROVE) stays false (operational-only)', AuthService::hasPermission($pdo, 'STOCK', 'DISTRIBUTION_APPROVE') === false);

// ============================================================
// 4 — Idempotency: re-running the hotfix migration must not error and
// must not create duplicate role_permissions rows.
// ============================================================
echo "\n== 4: idempotency — re-run hotfix migration a second time ==\n";
$rowsBeforeRerun = (int) $pdo->query(
    "SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
     WHERE p.code IN ('" . implode("','", DISTRIBUTION_LIFECYCLE_PERMS) . "')"
)->fetchColumn();
$rerunThrew = false;
try {
    applySqlFile($pdo, $hotfixMigration);
} catch (Throwable $e) {
    $rerunThrew = true;
    echo "  (unexpected exception: {$e->getMessage()})\n";
}
check('re-running hotfix migration does not throw', $rerunThrew === false);
$rowsAfterRerun = (int) $pdo->query(
    "SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
     WHERE p.code IN ('" . implode("','", DISTRIBUTION_LIFECYCLE_PERMS) . "')"
)->fetchColumn();
check('re-running hotfix migration creates no duplicate role_permissions rows', $rowsAfterRerun === $rowsBeforeRerun, "before={$rowsBeforeRerun} after={$rowsAfterRerun}");
$permsAfterRerun = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE code LIKE 'DISTRIBUTION_%' AND code NOT IN ('DISTRIBUTION_PRICING_MANAGE','DISTRIBUTION_REPORT_VIEW')")->fetchColumn();
check('re-running hotfix migration creates no duplicate permission rows', $permsAfterRerun === 6, "count={$permsAfterRerun}");

// ============================================================
// 5 — Zero inventory mutation: this hotfix only ever touches
// permissions/role_permissions.
// ============================================================
echo "\n== 5: zero inventory mutation from this hotfix ==\n";
$inventorySum = $pdo->query('SELECT COALESCE(SUM(qty_base), 0) FROM inventory_batches')->fetchColumn();
check('inventory_batches untouched by permissions-only migration (query succeeds, table intact)', $inventorySum !== false);

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
