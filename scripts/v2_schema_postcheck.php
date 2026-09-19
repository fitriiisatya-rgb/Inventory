<?php
declare(strict_types=1);

/**
 * Phase V2 3b — postcheck for database/migrations/2026_09_18_v2_schema.sql.
 * Run immediately after the migration. Verifies the new shape exists AND
 * that no pre-existing business data changed — the row-count snapshot
 * written by v2_schema_precheck.php must match exactly for every
 * pre-existing table (this migration is additive-only; if any of these
 * counts differ, something touched data it should not have, and this
 * must be treated as a failed migration requiring investigation before
 * anything proceeds further, not a rollback-and-retry).
 *
 * PRODUCTION SAFETY (fixed after a Phase 5A production pre-flight review
 * found the prior version unsafe): the CHECK-constraint enforcement probe
 * below commits NOTHING. It runs entirely inside one outer transaction
 * that is unconditionally ROLLED BACK at the end — never committed — using
 * SAVEPOINT/ROLLBACK TO SAVEPOINT around each individual insert attempt so
 * one probe's rejection never disturbs the next probe or anything set up
 * before it. There is no cleanup DELETE anywhere in this script, because
 * there is nothing to clean up: a killed/interrupted process at ANY point
 * during the probe leaves the database exactly as it was (an uncommitted
 * transaction is simply discarded when the connection drops). The one
 * documented exception is AUTO_INCREMENT: InnoDB never reclaims an
 * auto-increment value once allocated, even on ROLLBACK (this is
 * standard, well-documented InnoDB behavior, not a bug in this script) —
 * a small, bounded, harmless gap in `bakery_destinations.id` (one
 * probe row) and possibly `inventory_transactions.id` (empirically,
 * only the single ACCEPTED probe consumed a value on this codebase's
 * target MariaDB versions; CHECK-constraint-rejected attempts did not) is
 * an unavoidable consequence of proving server-side enforcement with a
 * real INSERT, not something any zero-persistence design can eliminate.
 * Row counts, unlike auto-increment counters, are verified exactly
 * unchanged (see section 5 below) — that is the guarantee that matters.
 *
 * Usage: php scripts/v2_schema_postcheck.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "== V2 schema postcheck against database: {$dbName} ==\n\n";

$problems = [];
$checks = 0;
$passed = 0;

function check(string $name, bool $pass, array &$problems, int &$checks, int &$passed, string $detail = ''): void
{
    $checks++;
    if ($pass) {
        $passed++;
        echo "PASS - {$name}\n";
    } else {
        echo "FAIL - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        $problems[] = $name;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}
function fkExists(PDO $pdo, string $constraintName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :n');
    $stmt->execute(['n' => $constraintName]);
    return (int) $stmt->fetchColumn() > 0;
}

// 1. New tables exist with expected shape.
foreach (['categories', 'item_warehouse_stock_policy', 'bakery_destinations'] as $table) {
    check("table `{$table}` exists", tableExists($pdo, $table), $problems, $checks, $passed);
}
check('items.category_id exists', columnExists($pdo, 'items', 'category_id'), $problems, $checks, $passed);
check('inventory_transactions.bakery_destination_id exists', columnExists($pdo, 'inventory_transactions', 'bakery_destination_id'), $problems, $checks, $passed);
check('suppliers.address exists', columnExists($pdo, 'suppliers', 'address'), $problems, $checks, $passed);
check('suppliers.email exists', columnExists($pdo, 'suppliers', 'email'), $problems, $checks, $passed);

// 2. Every new FK this migration adds resolves — all 6, not a subset.
foreach (['fk_items_category', 'fk_iwsp_item', 'fk_iwsp_warehouse', 'fk_iwsp_created_by', 'fk_iwsp_updated_by', 'fk_tx_bakery_destination'] as $fk) {
    check("foreign key `{$fk}` exists", fkExists($pdo, $fk), $problems, $checks, $passed);
}

// 3. Permission seed: all 4 new codes exist, granted to SUPERADMIN and
//    ADMIN only, and explicitly NOT granted to STOCK/DIVISION/VIEWER —
//    matches database/migrations/2026_09_18_v2_schema.sql's own comment:
//    "STOCK/DIVISION/VIEWER get none of these four; their reads of the new
//    endpoints reuse INVENTORY_VIEW."
function permissionExists(PDO $pdo, string $code): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE code = :c');
    $stmt->execute(['c' => $code]);
    return (int) $stmt->fetchColumn() > 0;
}

$v2PermissionCodes = ['MASTER_CATEGORY_MANAGE', 'MASTER_SUPPLIER_MANAGE', 'MASTER_BAKERY_DESTINATION_MANAGE', 'STOCK_POLICY_MANAGE'];
foreach ($v2PermissionCodes as $code) {
    check("permission `{$code}` exists", permissionExists($pdo, $code), $problems, $checks, $passed);
}

function roleHasPermission(PDO $pdo, string $roleCode, string $permissionCode): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE r.code = :r AND p.code = :p'
    );
    $stmt->execute(['r' => $roleCode, 'p' => $permissionCode]);
    return (int) $stmt->fetchColumn() > 0;
}

foreach ($v2PermissionCodes as $code) {
    check("SUPERADMIN is granted `{$code}`", roleHasPermission($pdo, 'SUPERADMIN', $code), $problems, $checks, $passed);
    check("ADMIN is granted `{$code}`", roleHasPermission($pdo, 'ADMIN', $code), $problems, $checks, $passed);
    foreach (['STOCK', 'DIVISION', 'VIEWER'] as $excludedRole) {
        check(
            "{$excludedRole} is NOT granted `{$code}`",
            !roleHasPermission($pdo, $excludedRole, $code),
            $problems, $checks, $passed,
            'this role should reuse INVENTORY_VIEW for read access — it must not hold a *_MANAGE permission'
        );
    }
}

// 4. The bakery_destination_id CHECK constraint actually enforces the full
//    rule (proves it is enforced, not just parsed), against every one of
//    the 10 transaction_type values — not just a single TRANSFER_OUT
//    sample. ZERO-PERSISTENCE: everything below runs inside ONE outer
//    transaction that is unconditionally rolled back at the very end —
//    never committed — so no probe row, and no throwaway fixture, is ever
//    visible to any other connection or survives an interrupted process.
//    SAVEPOINT/ROLLBACK TO SAVEPOINT (reusing one named savepoint) undoes
//    exactly the attempted insert after each probe, proven empirically
//    safe on MariaDB: a rejected insert does not poison the surrounding
//    transaction (unlike Postgres), and the transaction remains fully
//    usable for the next probe immediately after ROLLBACK TO SAVEPOINT.
$pdo->beginTransaction();
try {
    // Reuse an EXISTING warehouse/user if the database already has one
    // (true for any real production database) — zero auto_increment cost.
    // Only fall back to a throwaway INSERT (still inside this same
    // transaction, still never committed) for a genuinely empty/fresh
    // database, e.g. a disposable test DB.
    $whId = $pdo->query('SELECT id FROM warehouses LIMIT 1')->fetchColumn();
    if ($whId === false) {
        $pdo->exec("INSERT INTO warehouses (code, name) VALUES ('POSTCHECK-PROBE-WH', 'POSTCHECK-PROBE-WH')");
        $whId = $pdo->lastInsertId();
    }
    $whId = (int) $whId;

    $userId = $pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();
    if ($userId === false) {
        $roleId = $pdo->query('SELECT id FROM roles LIMIT 1')->fetchColumn();
        if ($roleId === false) {
            $pdo->exec("INSERT INTO roles (code, name) VALUES ('POSTCHECK_PROBE_ROLE', 'Postcheck Probe Role')");
            $roleId = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('postcheck-probe-user', 'x', 'Postcheck Probe', :r, 1)")
            ->execute(['r' => (int) $roleId]);
        $userId = $pdo->lastInsertId();
    }
    $userId = (int) $userId;

    // bakery_destinations is a brand-new V2 table — a freshly migrated
    // production database has zero rows in it, so there is nothing to
    // reuse here. This one throwaway row (never committed) is the only
    // unavoidable insert in this whole probe.
    $bdStmt = $pdo->prepare("INSERT INTO bakery_destinations (code, name) VALUES (:c, 'Postcheck Probe')");
    $bdStmt->execute(['c' => 'POSTCHECK-PROBE-' . bin2hex(random_bytes(4))]);
    $bdId = (int) $pdo->lastInsertId();

    $pdo->exec('SAVEPOINT postcheck_probe');

    $probe = function (string $type, ?int $bd) use ($pdo, $whId, $userId): bool {
        $accepted = true;
        try {
            $pdo->prepare(
                "INSERT INTO inventory_transactions
                    (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id,
                     bakery_destination_id, status, is_historical_import, inventory_effect, created_by, created_at)
                 VALUES (:uuid, :type, NOW(), NOW(), :wh, :bd, 'POSTED', 0, 1, :u, NOW())"
            )->execute(['uuid' => 'postcheck-probe-' . bin2hex(random_bytes(4)), 'type' => $type, 'wh' => $whId, 'bd' => $bd, 'u' => $userId]);
        } catch (PDOException $e) {
            $accepted = false;
        } finally {
            // Undoes exactly this attempt — the outer transaction (and
            // everything set up before this savepoint) remains open and
            // usable for the next probe.
            $pdo->exec('ROLLBACK TO SAVEPOINT postcheck_probe');
        }
        return $accepted;
    };

    // Positive cases: OUT must accept both a real bakery destination and NULL.
    check('OUT + bakery_destination_id SET is accepted', $probe('OUT', $bdId), $problems, $checks, $passed);
    check('OUT + bakery_destination_id NULL is accepted', $probe('OUT', null), $problems, $checks, $passed);

    // Negative cases: every OTHER transaction_type must reject a set bakery_destination_id.
    $nonOutTypes = ['IN', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT', 'OPNAME', 'PRODUCTION_IN', 'PRODUCTION_OUT', 'OPENING', 'REVERSAL'];
    foreach ($nonOutTypes as $type) {
        $accepted = $probe($type, $bdId);
        check(
            "CHECK constraint rejects bakery_destination_id on transaction_type={$type}",
            !$accepted,
            $problems, $checks, $passed,
            $accepted ? 'insert unexpectedly succeeded — constraint is not enforced by this server version' : ''
        );
    }
} finally {
    // Unconditional — this transaction is NEVER committed under any code
    // path above. Whether every probe ran cleanly or an exception escaped
    // mid-probe, the only correct action here is to discard everything:
    // the bakery_destination probe row, any throwaway warehouse/user/role
    // fixture, and every attempted inventory_transactions insert.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// 5. No pre-existing business data changed — compare against the precheck snapshot.
$snapshotPath = __DIR__ . '/../storage/v2_schema_precheck_snapshot.json';
if (!file_exists($snapshotPath)) {
    check('precheck row-count snapshot found', false, $problems, $checks, $passed, "expected at {$snapshotPath} — run v2_schema_precheck.php before the migration next time");
} else {
    $snapshot = json_decode((string) file_get_contents($snapshotPath), true);
    foreach ($snapshot as $table => $expectedCount) {
        $actualCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        check(
            "row count for `{$table}` unchanged by migration ({$expectedCount})",
            $actualCount === $expectedCount,
            $problems, $checks, $passed,
            "expected {$expectedCount}, got {$actualCount}"
        );
    }
}

echo "\n==============================\n";
echo "TOTAL: {$checks}  PASSED: {$passed}  FAILED: " . ($checks - $passed) . "\n";
if ($problems) {
    echo "\nPOSTCHECK FAILED. Do not proceed to data backfill or further Phase 3 steps until resolved.\n";
    exit(1);
}
echo "\nPOSTCHECK PASSED.\n";
exit(0);
