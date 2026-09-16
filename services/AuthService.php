<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Section 23: server-side auth. Passwords are always password_hash()/password_verify() — never compared client-side. */
final class AuthService
{
    public static function bootSession(array $sessionConfig): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => $sessionConfig['lifetime_minutes'] * 60,
            'path'     => '/',
            'secure'   => $sessionConfig['cookie_secure'],
            'httponly' => $sessionConfig['cookie_httponly'],
            'samesite' => $sessionConfig['cookie_samesite'],
        ]);
        session_name($sessionConfig['cookie_name']);
        session_start();
    }

    public static function attemptLogin(PDO $pdo, string $username, string $password): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.username = :username AND u.is_active = 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $user['id']]);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_code'] = $user['role_code'];
        $_SESSION['division_id'] = $user['division_id'];

        return $user;
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role_code' => $_SESSION['role_code'],
            'division_id' => $_SESSION['division_id'] ?? null,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function hasPermission(PDO $pdo, string $roleCode, string $permissionCode): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code = :role_code AND p.code = :perm_code'
        );
        $stmt->execute(['role_code' => $roleCode, 'perm_code' => $permissionCode]);
        return ((int) $stmt->fetchColumn()) > 0;
    }
}
