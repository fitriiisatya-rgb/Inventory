<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $cfg = $GLOBALS['SO_CONFIG']['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['name'],
                $cfg['charset']
            );
            self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Without this, MySQL/MariaDB reports rowCount() after an
                // UPDATE as rows CHANGED, not rows MATCHED — an UPDATE whose
                // WHERE matches a row but whose SET values happen to already
                // be identical (e.g. two heartbeats landing in the same
                // second) would report 0 and be mistaken for "no such row".
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        }
        return self::$instance;
    }

    /** Allows tests to inject a fresh connection against a different config. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
