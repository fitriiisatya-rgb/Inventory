<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 2: while a Stock Opname session is OPEN or FINALIZED for
 * a warehouse, that warehouse's stock must not move underneath the count.
 * Other warehouses are never affected (`assertNotLocked` only ever checks
 * the one warehouse_id it's given).
 */
final class WarehouseLockService
{
    public static function isLocked(PDO $pdo, int $warehouseId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM stock_opname_sessions
             WHERE warehouse_id = :wh AND status IN ('OPEN','FINALIZED')"
        );
        $stmt->execute(['wh' => $warehouseId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function assertNotLocked(PDO $pdo, int $warehouseId): void
    {
        if (self::isLocked($pdo, $warehouseId)) {
            throw new WarehouseLockedException($warehouseId);
        }
    }
}
