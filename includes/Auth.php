<?php
declare(strict_types=1);

final class Auth
{
    /**
     * @return array|null user row (without password_hash) on success, null on bad credentials
     */
    public static function attemptLogin(string $username, string $password): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'ACTIVE') {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Transparent rehash if the algorithm/cost changed since the hash was created.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$newHash, $user['id']]);
        }

        unset($user['password_hash']);

        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['team']       = $user['team'];
        session_regenerate_id(true);

        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id'        => (int) $_SESSION['user_id'],
            'username'  => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role'],
            'team'      => $_SESSION['team'] ?? null,
        ];
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::error('Not authenticated', 401);
        }
        return $user;
    }

    /** @param string[] $roles */
    public static function requireRole(array $roles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            Response::error('Forbidden', 403);
        }
        return $user;
    }
}
