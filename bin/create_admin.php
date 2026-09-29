<?php
declare(strict_types=1);

/**
 * CLI: php bin/create_admin.php <username> <password> "<Full Name>"
 * Creates (or resets the password of) the first SUPERADMIN account.
 * No password is ever hardcoded in seed data — this is the only way
 * to provision the initial login.
 */

require __DIR__ . '/../includes/bootstrap.php';

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
$fullName = $argv[3] ?? null;

if (!$username || !$password || !$fullName) {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <password> \"<Full Name>\"\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = Database::pdo();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, role = \'SUPERADMIN\', status = \'ACTIVE\' WHERE id = ?')
        ->execute([$hash, $fullName, $existing['id']]);
    echo "Updated existing user '{$username}' to SUPERADMIN with new password.\n";
} else {
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, ?, \'SUPERADMIN\', \'ACTIVE\')')
        ->execute([$username, $hash, $fullName]);
    echo "Created SUPERADMIN user '{$username}'.\n";
}
