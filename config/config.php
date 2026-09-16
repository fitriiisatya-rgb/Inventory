<?php
declare(strict_types=1);

/**
 * Minimal .env loader (no composer dependency, so deployment on a plain
 * aaPanel/VPS PHP install never depends on packagist reachability).
 *
 * This file is included with plain `require` (not `require_once`) by code
 * that needs a fresh copy of the config array on every call (e.g.
 * FifoService reading anomaly thresholds) — the function guards below let
 * that happen safely without "cannot redeclare function" fatals.
 */
if (!function_exists('inv_load_env')) {
    function inv_load_env(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('inv_env')) {
    function inv_env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        $lower = strtolower((string) $value);
        return match ($lower) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}

$root = dirname(__DIR__);
inv_load_env($root . '/.env');

return [
    'app' => [
        'env'   => inv_env('APP_ENV', 'production'),
        'debug' => (bool) inv_env('APP_DEBUG', false),
        'url'   => inv_env('APP_URL', ''),
    ],
    'db' => [
        'host'    => inv_env('DB_HOST', '127.0.0.1'),
        'port'    => inv_env('DB_PORT', '3306'),
        'database'=> inv_env('DB_DATABASE', ''),
        'username'=> inv_env('DB_USERNAME', ''),
        'password'=> inv_env('DB_PASSWORD', ''),
        'charset' => inv_env('DB_CHARSET', 'utf8mb4'),
    ],
    'session' => [
        'cookie_name'     => inv_env('SESSION_COOKIE_NAME', 'inv_session'),
        'cookie_secure'   => (bool) inv_env('SESSION_COOKIE_SECURE', true),
        'cookie_httponly' => (bool) inv_env('SESSION_COOKIE_HTTPONLY', true),
        'cookie_samesite' => inv_env('SESSION_COOKIE_SAMESITE', 'Strict'),
        'lifetime_minutes'=> (int) inv_env('SESSION_LIFETIME_MINUTES', 480),
    ],
    'cors' => [
        'allowed_origins' => array_filter(array_map('trim', explode(',', (string) inv_env('CORS_ALLOWED_ORIGINS', '')))),
    ],
    'inventory' => [
        'price_anomaly_high_multiplier' => (float) inv_env('PRICE_ANOMALY_HIGH_MULTIPLIER', 5),
        'price_anomaly_low_multiplier'  => (float) inv_env('PRICE_ANOMALY_LOW_MULTIPLIER', 0.2),
        'allow_negative_stock_default'  => (bool) inv_env('ALLOW_NEGATIVE_STOCK_DEFAULT', false),
    ],
    'log' => [
        'path' => $root . '/' . inv_env('LOG_PATH', 'storage/logs/app.log'),
    ],
];
