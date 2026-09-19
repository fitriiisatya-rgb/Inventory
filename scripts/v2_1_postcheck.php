<?php
declare(strict_types=1);

/**
 * Phase V2.1 — postcheck for database/migrations/2026_09_19_v2_1_master_data_management.sql.
 * Run immediately after the migration.
 *
 * Usage: php scripts/v2_1_postcheck.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "== V2.1 master-data-management postcheck against database: {$dbName} ==\n\n";

$problems = [];
$checks = 0;
$passed = 0;

function check(string $name, bool $pass, array &$problems, int &$checks, int &$passed): void
{
    $checks++;
    if ($pass) {
        $passed++;
        echo "PASS - {$name}\n";
    } else {
        echo "FAIL - {$name}\n";
        $problems[] = $name;
    }
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

$codes = ['MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE'];
foreach ($codes as $code) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE code = :c');
    $stmt->execute(['c' => $code]);
    check("permission `{$code}` exists", (int) $stmt->fetchColumn() > 0, $problems, $checks, $passed);

    check("SUPERADMIN is granted `{$code}`", roleHasPermission($pdo, 'SUPERADMIN', $code), $problems, $checks, $passed);
    check("ADMIN is granted `{$code}`", roleHasPermission($pdo, 'ADMIN', $code), $problems, $checks, $passed);
    foreach (['STOCK', 'DIVISION', 'VIEWER'] as $excludedRole) {
        check("{$excludedRole} is NOT granted `{$code}`", !roleHasPermission($pdo, $excludedRole, $code), $problems, $checks, $passed);
    }
}

echo "\n==============================\n";
echo "TOTAL: {$checks}  PASSED: {$passed}  FAILED: " . ($checks - $passed) . "\n";
if ($problems) {
    echo "\nPOSTCHECK FAILED.\n";
    exit(1);
}
echo "\nPOSTCHECK PASSED.\n";
exit(0);
