<?php
declare(strict_types=1);

/**
 * Phase V2 3b — precheck for database/migrations/2026_09_18_v2_schema.sql.
 * Refuses to let the migration run if the target DB is already in a
 * half-migrated or incompatible state. Read-only — makes no changes.
 *
 * Same safety posture as scripts/assert_database_clean.php and the
 * production cutover runbook's precheck steps: exit 0 = safe to proceed,
 * exit 1 = STOP, do not run the migration.
 *
 * Usage: php scripts/v2_schema_precheck.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

echo "== V2 schema precheck against database: {$dbName} ==\n\n";

$problems = [];

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

// 1. None of the 3 new tables may already exist — an incompatible partial
//    migration must be resolved by a human, never silently overwritten.
foreach (['categories', 'item_warehouse_stock_policy', 'bakery_destinations'] as $table) {
    if (tableExists($pdo, $table)) {
        $problems[] = "Table `{$table}` already exists — migration would fail on CREATE TABLE. "
            . 'If this is a genuine re-run after a successful migration, this script should not be re-run; '
            . 'if it is a partial/failed prior attempt, resolve manually (see rollback script) before retrying.';
    }
}

// 2. The 3 new columns must not already exist.
foreach ([['items', 'category_id'], ['inventory_transactions', 'bakery_destination_id'], ['suppliers', 'address'], ['suppliers', 'email']] as [$table, $column]) {
    if (columnExists($pdo, $table, $column)) {
        $problems[] = "Column `{$table}.{$column}` already exists — migration would fail on ADD COLUMN.";
    }
}

// 3. Baseline tables this migration depends on must exist (sanity check
//    against running this against an empty/wrong database). Includes
//    roles/permissions/role_permissions explicitly — the migration's
//    permission-seed step (section 7 of the migration file) INSERTs into
//    all three, and never assumes they exist merely because a normal
//    production database usually has them.
foreach (['items', 'warehouses', 'suppliers', 'inventory_transactions', 'users', 'roles', 'permissions', 'role_permissions'] as $table) {
    if (!tableExists($pdo, $table)) {
        $problems[] = "Baseline table `{$table}` does not exist — is this the right database? "
            . 'Run database/schema.sql first if this is meant to be a fresh install.';
    }
}

// 4. MariaDB/MySQL version must support enforced CHECK constraints
//    (MariaDB 10.2.1+, MySQL 8.0.16+) for the bakery_destination_id guard
//    to actually do anything rather than silently parse-and-ignore.
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
echo "Server version: {$version}\n";
$isMariaDb = stripos($version, 'mariadb') !== false;
if ($isMariaDb) {
    preg_match('/^(\d+)\.(\d+)/', $version, $m);
    $major = (int) ($m[1] ?? 0);
    $minor = (int) ($m[2] ?? 0);
    if ($major < 10 || ($major === 10 && $minor < 2)) {
        $problems[] = "MariaDB {$version} does not enforce CHECK constraints (needs 10.2.1+) — "
            . 'the bakery_destination_id CHECK would be silently ignored. Do not proceed without confirming enforcement.';
    }
} else {
    preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
    $major = (int) ($m[1] ?? 0);
    $minor = (int) ($m[2] ?? 0);
    $patch = (int) ($m[3] ?? 0);
    if ($major < 8 || ($major === 8 && $minor === 0 && $patch < 16)) {
        $problems[] = "MySQL {$version} does not enforce CHECK constraints (needs 8.0.16+) — "
            . 'the bakery_destination_id CHECK would be silently ignored. Do not proceed without confirming enforcement.';
    }
}

// 5. Row-count snapshot — the postcheck compares against this to prove no
//    business data was touched. Written to a sidecar file next to the
//    migration so postcheck can diff against it deterministically.
$snapshot = [];
foreach (['items', 'suppliers', 'inventory_transactions', 'inventory_batches', 'warehouses', 'users'] as $table) {
    $snapshot[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}
$snapshotPath = __DIR__ . '/../storage/v2_schema_precheck_snapshot.json';
@mkdir(dirname($snapshotPath), 0775, true);
file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT));
echo "Row-count snapshot written to {$snapshotPath}:\n" . json_encode($snapshot, JSON_PRETTY_PRINT) . "\n\n";

if ($problems) {
    echo "PRECHECK FAILED — do not run the migration:\n";
    foreach ($problems as $p) {
        echo " - {$p}\n";
    }
    exit(1);
}

echo "PRECHECK PASSED — safe to run database/migrations/2026_09_18_v2_schema.sql\n";
exit(0);
