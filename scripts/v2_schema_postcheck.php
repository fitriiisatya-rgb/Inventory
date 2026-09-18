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

// 2. New FKs resolve.
foreach (['fk_items_category', 'fk_iwsp_item', 'fk_iwsp_warehouse', 'fk_tx_bakery_destination'] as $fk) {
    check("foreign key `{$fk}` exists", fkExists($pdo, $fk), $problems, $checks, $passed);
}

// 3. The bakery_destination_id CHECK constraint actually rejects a
//    disallowed insert (proves it is enforced, not just parsed) — tested
//    against a throwaway row in a transaction that is always rolled back.
$pdo->beginTransaction();
try {
    // Never assume pre-existing rows (a fresh/empty DB has none) — the probe
    // creates its own throwaway warehouse/role/user inside this same
    // transaction, which is always rolled back below regardless of outcome.
    $pdo->exec("INSERT INTO warehouses (code, name) VALUES ('POSTCHECK-PROBE-WH', 'POSTCHECK-PROBE-WH')");
    $whId = (int) $pdo->lastInsertId();

    $roleId = (int) $pdo->query('SELECT id FROM roles LIMIT 1')->fetchColumn();
    if ($roleId <= 0) {
        $pdo->exec("INSERT INTO roles (code, name) VALUES ('POSTCHECK_PROBE_ROLE', 'Postcheck Probe Role')");
        $roleId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('postcheck-probe-user', 'x', 'Postcheck Probe', :r, 1)")
        ->execute(['r' => $roleId]);
    $userId = (int) $pdo->lastInsertId();

    $bdStmt = $pdo->prepare("INSERT INTO bakery_destinations (code, name) VALUES (:c, 'Postcheck Probe')");
    $bdStmt->execute(['c' => 'POSTCHECK-PROBE-' . bin2hex(random_bytes(4))]);
    $bdId = (int) $pdo->lastInsertId();

    $violated = false;
    try {
        $pdo->prepare(
            "INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id,
                 bakery_destination_id, status, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, 'TRANSFER_OUT', NOW(), NOW(), :wh, :bd, 'POSTED', 0, 1, :u, NOW())"
        )->execute(['uuid' => 'postcheck-probe-' . bin2hex(random_bytes(4)), 'wh' => $whId, 'bd' => $bdId, 'u' => $userId]);
    } catch (PDOException $e) {
        $violated = true;
    }
    check(
        'CHECK constraint rejects bakery_destination_id on a non-OUT transaction_type (TRANSFER_OUT probe)',
        $violated,
        $problems, $checks, $passed,
        $violated ? '' : 'insert unexpectedly succeeded — constraint is not enforced by this server version'
    );
} finally {
    $pdo->rollBack();
}

// 4. No pre-existing business data changed — compare against the precheck snapshot.
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
