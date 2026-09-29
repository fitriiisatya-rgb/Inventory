<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $submitted): bool
    {
        if (empty($_SESSION['csrf_token']) || !is_string($submitted)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }

    /** Call at the top of every state-changing API endpoint. */
    public static function requireValid(): void
    {
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!self::verify($submitted)) {
            Response::error('Invalid or missing CSRF token', 419);
        }
    }
}
