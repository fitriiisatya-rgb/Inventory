<?php
declare(strict_types=1);

/**
 * One-time CLI installer: creates the first SUPERADMIN user.
 * Run once after `database/schema.sql` has been loaded into a fresh,
 * empty database. Never run against a database that already has users.
 *
 * Usage: php migration/install.php <username> <full_name>
 * (prompts for the password interactively — never pass it as an argv,
 * it would otherwise land in shell history / process listing)
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php migration/install.php <username> <full_name>\n");
    exit(1);
}

[$script, $username, $fullName] = $argv;

$pdo = Database::connection();

$existing = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ((int) $existing > 0) {
    fwrite(STDERR, "Refusing to run: the users table is not empty. This installer is only for first-time setup.\n");
    exit(1);
}

fwrite(STDOUT, "Password for {$username}: ");
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$roleId = $pdo->query("SELECT id FROM roles WHERE code = 'SUPERADMIN'")->fetchColumn();
if ($roleId === false) {
    fwrite(STDERR, "SUPERADMIN role not found — did you load database/schema.sql?\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, full_name, role_id, is_active, created_at, updated_at)
     VALUES (:username, :hash, :full_name, :role_id, 1, :now, :now)'
);
$stmt->execute([
    'username' => $username,
    'hash' => password_hash($password, PASSWORD_BCRYPT),
    'full_name' => $fullName,
    'role_id' => $roleId,
    'now' => date('Y-m-d H:i:s'),
]);

fwrite(STDOUT, "Created SUPERADMIN user '{$username}' (id " . $pdo->lastInsertId() . ").\n");
