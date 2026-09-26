<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.13 (Karang Tengah) — a GENERIC rule, not a Karang-Tengah-only
 * hack: an inactive/not-yet-live warehouse (warehouses.is_active = 0) must
 * never be a source or destination of any stock-mutating operation.
 *
 * Called from the one place every stock-mutating write path in this
 * codebase already funnels through — FifoService::postIn()/postOut() —
 * which alone covers Stock IN/OUT, transfers (both the immediate OUT at
 * create() and the IN at receive()), production, adjustments (including
 * an opname's post()), imports, and Delivery Order dispatch. Two entry
 * points that do NOT go through FifoService are guarded explicitly at
 * their own call sites: TransferService::create() (so a transfer is
 * rejected before any stock leaves the source, rather than only failing
 * later at receive() against an inactive destination) and
 * StockOpnameService::start() (which never posts a FIFO transaction on
 * its own).
 */
final class WarehouseGuardService
{
    public static function assertActive(PDO $pdo, int $warehouseId): void
    {
        $stmt = $pdo->prepare('SELECT is_active FROM warehouses WHERE id = :id');
        $stmt->execute(['id' => $warehouseId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("warehouse {$warehouseId} not found");
        }
        if ((int) $row['is_active'] !== 1) {
            throw new WarehouseInactiveException($warehouseId);
        }
    }
}
