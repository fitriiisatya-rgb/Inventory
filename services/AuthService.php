<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Section 23 / PHASE C3: server-side auth. Passwords are always
 * password_hash()/password_verify() — never compared client-side, unlike
 * the legacy inventory.html (Phase A analysis: hardcoded admin123/lihat123
 * hashes compared in the browser). Also backs the login rate limit and the
 * CSRF synchronizer token used by every mutating request.
 */
final class AuthService
{
    private const RATE_LIMIT_MAX_FAILURES = 5;
    private const RATE_LIMIT_WINDOW_MINUTES = 15;

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

    public static function attemptLogin(PDO $pdo, string $username, string $password, ?string $ip = null): ?array
    {
        self::assertNotRateLimited($pdo, $username, $ip);

        $stmt = $pdo->prepare(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.username = :username AND u.is_active = 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        $ok = $user && password_verify($password, $user['password_hash']);
        self::recordAttempt($pdo, $username, $ip, $ok);

        if (!$ok) {
            return null;
        }

        $pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $user['id']]);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_code'] = $user['role_code'];
        $_SESSION['division_id'] = $user['division_id'];
        $_SESSION['warehouse_id'] = $user['warehouse_id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return $user;
    }

    private static function assertNotRateLimited(PDO $pdo, string $username, ?string $ip): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND success = 0
               AND created_at >= (NOW() - INTERVAL " . self::RATE_LIMIT_WINDOW_MINUTES . " MINUTE)"
        );
        $stmt->execute(['username' => $username]);
        $failures = (int) $stmt->fetchColumn();
        if ($failures >= self::RATE_LIMIT_MAX_FAILURES) {
            throw new RateLimitedException(self::RATE_LIMIT_WINDOW_MINUTES * 60);
        }
    }

    private static function recordAttempt(PDO $pdo, string $username, ?string $ip, bool $success): void
    {
        $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success, created_at) VALUES (:u, :ip, :ok, :now)')
            ->execute(['u' => $username, 'ip' => $ip, 'ok' => $success ? 1 : 0, 'now' => date('Y-m-d H:i:s')]);
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
            'warehouse_id' => $_SESSION['warehouse_id'] ?? null,
        ];
    }

    public static function csrfToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }

    public static function verifyCsrf(string $token): bool
    {
        $expected = $_SESSION['csrf_token'] ?? null;
        return $expected !== null && hash_equals($expected, $token);
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

    /**
     * Section: STOCK is scoped to its own warehouse, DIVISION to its own
     * division. A user with warehouse_id/division_id = NULL is unscoped
     * (an ADMIN/SUPERADMIN acting in either role, or a STOCK/DIVISION user
     * deliberately granted all-warehouse/all-division access).
     */
    public static function assertWarehouseScope(array $user, int $warehouseId): void
    {
        if ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null && (int) $user['warehouse_id'] !== $warehouseId) {
            throw new ValidationException(["user is scoped to warehouse {$user['warehouse_id']}, not {$warehouseId}"]);
        }
    }

    public static function assertDivisionScope(array $user, ?int $divisionId): void
    {
        if ($user['role_code'] === 'DIVISION' && $user['division_id'] !== null) {
            if ($divisionId === null || (int) $user['division_id'] !== $divisionId) {
                throw new ValidationException(["user is scoped to division {$user['division_id']}"]);
            }
        }
    }
}
