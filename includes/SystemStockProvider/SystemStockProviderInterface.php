<?php
declare(strict_types=1);

/**
 * Seam between "what does the system think we have" and the SO session
 * freeze logic (Phase 4). V1 ships ImportSystemStockProvider only; a
 * future ApiSystemStockProvider can implement this same interface to
 * pull from an external inventory/ERP system without session code
 * changing at all — see Phase 2 design review, decision on system_qty
 * source (2026-09-29).
 */
interface SystemStockProviderInterface
{
    /**
     * @return SystemStockResult|null null when no known stock figure exists
     *         for this item+location yet (e.g. never imported).
     */
    public function getSystemStock(int $itemId, int $locationId): ?SystemStockResult;
}
