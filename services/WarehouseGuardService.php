<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.13 (Karang Tengah) — a GENERIC rule, not a Karang-Tengah-only
 * hack: an inactive/not-yet-live warehouse (warehouses.is_active = 0) must
 * never be a source or destination of any stock-mutating operation.
 *
 * Called from the one place most stock-mutating write paths in this
 * codebase funnel through — FifoService::postIn()/postOut() — which alone
 * covers Stock IN/OUT, transfers (both the immediate OUT at create() and
 * the IN at receive()), production, imports, and Delivery Order dispatch.
 * Three entry points that do NOT go through FifoService are guarded
 * explicitly at their own call sites: TransferService::create() (so a
 * transfer is rejected before any stock leaves the source, rather than
 * only failing later at receive() against an inactive destination),
 * StockOpnameService::start() (which never posts a FIFO transaction on its
 * own), and StockAdjustmentService::post() (which writes its own
 * inventory_batches/inventory_transaction_lines rows directly and is also
 * the method StockOpnameService::post() delegates to for opname-variance
 * resolution).
 *
 * PHASE V2.13.1 — a second, independent GENERIC rule: a warehouse can also
 * carry activation_locked = 1, which makes an is_active 0 -> 1 transition
 * through PUT /warehouses/{id} a hard server-side refusal, regardless of
 * the caller's permissions. This is unrelated to assertActive() above (a
 * locked warehouse is refused activation; an inactive warehouse is refused
 * stock mutation) — a warehouse could in principle be locked while already
 * active, though this release only ever creates locked+inactive rows.
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

    /**
     * Refuses an is_active 0 -> 1 transition when the warehouse is
     * activation_locked. Any other transition (staying inactive, staying
     * active, deactivating an active warehouse, or a plain rename with no
     * is_active change) is left untouched — the caller applies its own
     * update as normal.
     */
    public static function assertActivationAllowed(int $warehouseId, int $currentIsActive, int $currentActivationLocked, int $requestedIsActive): void
    {
        if ($currentIsActive === 0 && $requestedIsActive === 1 && $currentActivationLocked === 1) {
            throw new WarehouseActivationLockedException($warehouseId);
        }
    }
}
