<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * POLICY CORRECTION (owner decision) — a small, explicit list of known
 * migration-negative balances (5 SKU+warehouse pairs, confirmed by the
 * owner) that are allowed to carry forward into LIVE Opening exactly as
 * calculated, instead of being zeroed or provisionally adjusted. Never
 * auto-fixed: the only way off this list is a real, audited Stock Opname
 * or Stock Adjustment that brings the balance back above zero.
 *
 * The whitelist lives in movement_reconciliation_reviews
 * (is_migration_negative_approved = 1) — the 5 owner-approved rows are
 * already a subset of the 8 historical movement-evidence rows seeded
 * there for Phase G-DATA 2, so no separate table was introduced. Lookups
 * join by sku/warehouse_code (both plain strings on that table) against
 * the real items/warehouses master, so this resolves correctly whether or
 * not the item master has been imported yet — no promotion step needed.
 *
 * "Status" is always computed live from current stock, never stored: a
 * whitelisted item+warehouse reads MIGRATION_NEGATIVE_REVIEW /
 * NEEDS_STOCK_OPNAME only while its current balance is <= 0. Once an
 * audited correction brings it back above zero, the flag clears itself.
 */
final class MigrationNegativeStockService
{
    public static function whitelistRow(PDO $pdo, int $itemId, int $warehouseId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT mrr.* FROM movement_reconciliation_reviews mrr
             JOIN items i ON i.sku = mrr.sku
             JOIN warehouses w ON w.code = mrr.warehouse_code
             WHERE i.id = :item_id AND w.id = :wh AND mrr.is_migration_negative_approved = 1
             LIMIT 1'
        );
        $stmt->execute(['item_id' => $itemId, 'wh' => $warehouseId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isWhitelisted(PDO $pdo, int $itemId, int $warehouseId): bool
    {
        return self::whitelistRow($pdo, $itemId, $warehouseId) !== null;
    }

    /**
     * Migration flags for one item+warehouse, given its current qty_base
     * (the caller already has this figure — avoids a second stock query).
     * Both flags are true only while whitelisted AND balance <= 0.
     */
    public static function flagsFor(PDO $pdo, int $itemId, int $warehouseId, float $currentQtyBase): array
    {
        $row = self::whitelistRow($pdo, $itemId, $warehouseId);
        $unresolved = $row !== null && $currentQtyBase <= 0;
        return [
            'migration_negative_review' => $unresolved,
            'needs_stock_opname' => $unresolved,
            'migration_issue_reference' => $row !== null ? self::issueReference($row['sku'], $row['warehouse_code']) : null,
        ];
    }

    public static function issueReference(string $sku, string $warehouseCode): string
    {
        return "MIGRATION-{$sku}-{$warehouseCode}";
    }

    /**
     * Full admin review list: every owner-approved row, joined against
     * current stock (via InventoryService — the single source of truth for
     * stock figures) so the dashboard/stock-list/SKU-detail/reconciliation
     * report/admin review list can all render from one place. Rows whose
     * SKU isn't in the item master yet (real item master not imported)
     * report current_qty_base = null rather than guessing.
     */
    public static function reviewList(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT mrr.id, mrr.sku, mrr.item_name, mrr.warehouse_code, mrr.unit,
                    mrr.historical_opening, mrr.historical_in, mrr.historical_out, mrr.historical_calculated_ending,
                    mrr.migration_negative_approved_by_name, mrr.migration_negative_note,
                    i.id AS item_id, w.id AS warehouse_id
             FROM movement_reconciliation_reviews mrr
             LEFT JOIN items i ON i.sku = mrr.sku
             LEFT JOIN warehouses w ON w.code = mrr.warehouse_code
             WHERE mrr.is_migration_negative_approved = 1
             ORDER BY mrr.sku, mrr.warehouse_code"
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['current_qty_base'] = null;
            $row['current_value'] = null;
            $row['status'] = 'PENDING_ITEM_MASTER';
            $row['needs_stock_opname'] = null;
            $row['migration_issue_reference'] = self::issueReference($row['sku'], $row['warehouse_code']);

            if ($row['item_id'] !== null && $row['warehouse_id'] !== null) {
                $stock = InventoryService::currentStock($pdo, (int) $row['item_id'], (int) $row['warehouse_id']);
                $row['current_qty_base'] = $stock['qty_base'];
                $row['current_value'] = $stock['value'];
                $row['needs_stock_opname'] = $stock['needs_stock_opname'];
                $row['status'] = $stock['migration_negative_review'] ? 'MIGRATION_NEGATIVE_REVIEW' : 'RESOLVED';
            }
        }
        return $rows;
    }

    /** True while at least one whitelisted row is still unresolved (balance <= 0). */
    public static function hasUnresolved(PDO $pdo): bool
    {
        foreach (self::reviewList($pdo) as $row) {
            if ($row['status'] === 'MIGRATION_NEGATIVE_REVIEW') {
                return true;
            }
        }
        return false;
    }
}
