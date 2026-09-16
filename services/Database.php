<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require dirname(__DIR__) . '/config/config.php';
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        try {
            self::$instance = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$instance;
    }

    /**
     * Runs $callback inside a single DB transaction. On any exception the
     * transaction is rolled back and the exception is re-thrown — callers
     * must never catch-and-swallow around this (Section 25/10).
     *
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Row-level lock helper: SELECT ... FOR UPDATE the FIFO batches for one
     * item+warehouse, ordered for consumption (received_date, id). Must be
     * called inside a transaction() callback.
     */
    public static function lockFifoBatches(PDO $pdo, int $itemId, int $warehouseId): array
    {
        // SQLite (used by the offline test-suite in /tests) has no FOR UPDATE
        // and does not need it there since tests run single-threaded; MySQL/
        // MariaDB production always takes the row lock.
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';

        $stmt = $pdo->prepare(
            'SELECT * FROM inventory_batches
             WHERE item_id = :item_id AND warehouse_id = :warehouse_id AND qty_base > 0
             ORDER BY received_date ASC, id ASC' . $forUpdate
        );
        $stmt->execute(['item_id' => $itemId, 'warehouse_id' => $warehouseId]);
        return $stmt->fetchAll();
    }
}
