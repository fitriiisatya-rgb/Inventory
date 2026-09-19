<?php
declare(strict_types=1);

/**
 * Phase V2.1 — precheck for database/migrations/2026_09_19_v2_1_master_data_management.sql.
 * Read-only. Refuses to let the migration run if the target DB is already
 * in an unexpected state.
 *
 * Usage: php scripts/v2_1_precheck.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "== V2.1 master-data-management precheck against database: {$dbName} ==\n\n";

$problems = [];

function permCodeExists(PDO $pdo, string $code): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE code = :c');
    $stmt->execute(['c' => $code]);
    return (int) $stmt->fetchColumn() > 0;
}

foreach (['MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE'] as $code) {
    if (permCodeExists($pdo, $code)) {
        $problems[] = "Permission `{$code}` already exists — migration would be a no-op re-run; "
            . 'confirm this is intentional before proceeding (the migration itself is idempotent and safe to re-run, this is only a heads-up).';
    }
}

foreach (['roles', 'permissions', 'role_permissions', 'items', 'warehouses', 'divisions', 'suppliers', 'bakery_destinations', 'categories'] as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute(['t' => $table]);
    if ((int) $stmt->fetchColumn() === 0) {
        $problems[] = "Baseline table `{$table}` does not exist — is this the right database / has V2 already been migrated?";
    }
}

foreach ($problems as $p) {
    // Only the "already exists" ones are non-fatal heads-up notices; missing
    // baseline tables are fatal.
    if (str_starts_with($p, 'Baseline table')) {
        echo "FATAL - {$p}\n";
    } else {
        echo "NOTICE - {$p}\n";
    }
}

$fatal = array_filter($problems, fn ($p) => str_starts_with($p, 'Baseline table'));
if ($fatal) {
    echo "\nPRECHECK FAILED — do not run the migration.\n";
    exit(1);
}

echo "\nPRECHECK PASSED — safe to run database/migrations/2026_09_19_v2_1_master_data_management.sql\n";
exit(0);
