<?php
declare(strict_types=1);

/**
 * PHASE G21 — creates a new user account with a temporary GENERATED
 * password (never a legacy-migrated one — Section G21: "Jangan
 * migrasikan password legacy. Buat akun baru."). The account is created
 * with must_change_password=1, so the temp password shown here works for
 * exactly one login before the account must set its own via
 * POST /auth/change-password (enforced server-side, not just a frontend
 * nag — see services/AuthService.php / public/index.php's
 * inv_require_auth()).
 *
 * The generated password is printed to stdout EXACTLY ONCE and is never
 * written anywhere else (not logged, not stored in plaintext in the
 * database — only its bcrypt hash is persisted). Whoever runs this script
 * is responsible for relaying it to the account holder through a secure
 * channel and then discarding it.
 *
 * Usage:
 *   php scripts/provision_user.php <username> "<full name>" <role_code> [division_code] [warehouse_code]
 *
 * role_code: SUPERADMIN | ADMIN | STOCK | DIVISION | VIEWER
 * division_code: required/meaningful only for DIVISION-scoped accounts (optional otherwise)
 * warehouse_code: required/meaningful only for STOCK-scoped accounts (optional otherwise)
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/provision_user.php <username> \"<full name>\" <role_code> [division_code] [warehouse_code]\n");
    fwrite(STDERR, "role_code: SUPERADMIN | ADMIN | STOCK | DIVISION | VIEWER\n");
    exit(1);
}

[$script, $username, $fullName, $roleCode] = $argv;
$divisionCode = $argv[4] ?? null;
$warehouseCode = $argv[5] ?? null;

$pdo = Database::connection();

$existing = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
$existing->execute(['u' => $username]);
if ((int) $existing->fetchColumn() > 0) {
    fwrite(STDERR, "Refusing to run: username '{$username}' already exists.\n");
    exit(1);
}

$roleId = $pdo->prepare('SELECT id FROM roles WHERE code = :code');
$roleId->execute(['code' => strtoupper($roleCode)]);
$roleId = $roleId->fetchColumn();
if ($roleId === false) {
    fwrite(STDERR, "Unknown role_code '{$roleCode}'. Valid roles: SUPERADMIN, ADMIN, STOCK, DIVISION, VIEWER.\n");
    exit(1);
}

$divisionId = null;
if ($divisionCode !== null && $divisionCode !== '') {
    $stmt = $pdo->prepare('SELECT id FROM divisions WHERE code = :code');
    $stmt->execute(['code' => $divisionCode]);
    $divisionId = $stmt->fetchColumn();
    if ($divisionId === false) {
        fwrite(STDERR, "Unknown division_code '{$divisionCode}'.\n");
        exit(1);
    }
}

$warehouseId = null;
if ($warehouseCode !== null && $warehouseCode !== '') {
    $stmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code');
    $stmt->execute(['code' => $warehouseCode]);
    $warehouseId = $stmt->fetchColumn();
    if ($warehouseId === false) {
        fwrite(STDERR, "Unknown warehouse_code '{$warehouseCode}'.\n");
        exit(1);
    }
}

// 16 chars from a readable, unambiguous alphabet (no 0/O/1/l/I) — meant to
// be read aloud or typed once, not memorized.
function generate_temp_password(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < 16; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

$tempPassword = generate_temp_password();
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, full_name, role_id, division_id, warehouse_id, is_active, must_change_password, created_at, updated_at)
     VALUES (:username, :hash, :full_name, :role_id, :division_id, :warehouse_id, 1, 1, :now, :now2)'
);
$stmt->execute([
    'username' => $username,
    'hash' => password_hash($tempPassword, PASSWORD_BCRYPT),
    'full_name' => $fullName,
    'role_id' => $roleId,
    'division_id' => $divisionId,
    'warehouse_id' => $warehouseId,
    'now' => $now, 'now2' => $now,
]);
$userId = (int) $pdo->lastInsertId();

fwrite(STDOUT, "Created user '{$username}' (id {$userId}, role " . strtoupper($roleCode) . ").\n");
fwrite(STDOUT, "Temporary password (shown ONCE, not stored anywhere in plaintext): {$tempPassword}\n");
fwrite(STDOUT, "This account MUST change its password on first login — enforced server-side.\n");
