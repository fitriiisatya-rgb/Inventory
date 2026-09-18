<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Section 7 of the PHASE C2 brief: single source of truth for every stock
 * figure the API (and eventually every dashboard) reads. No other service
 * or endpoint should compute "current stock" with its own SQL — they all
 * call here, so a formula fix only ever needs to happen once.
 *
 * Ledger sign convention (documented once, used everywhere in this class):
 * inventory_transaction_lines.base_qty is stored POSITIVE for every
 * transaction type except ADJUSTMENT, where it is stored SIGNED (the
 * delta itself, from stock_adjustments.qty_base_delta). Direction for the
 * rest comes from transaction_type:
 *   +1 : IN, OPENING, TRANSFER_IN, PRODUCTION_OUT
 *   -1 : OUT, TRANSFER_OUT, PRODUCTION_IN
 *  as-is : ADJUSTMENT (already signed)
 */
final class InventoryService
{
    private const POSITIVE_TYPES = ['IN', 'OPENING', 'TRANSFER_IN', 'PRODUCTION_OUT'];
    private const NEGATIVE_TYPES = ['OUT', 'TRANSFER_OUT', 'PRODUCTION_IN'];

    public static function currentStock(PDO $pdo, int $itemId, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(qty_base), 0) AS qty, COALESCE(SUM(qty_base * unit_cost_base), 0) AS value
             FROM inventory_batches WHERE item_id = :item_id AND warehouse_id = :wh'
        );
        $stmt->execute(['item_id' => $itemId, 'wh' => $warehouseId]);
        $row = $stmt->fetch();
        $qty = round((float) $row['qty'], 6);
        $result = ['qty_base' => $qty, 'value' => round((float) $row['value'], 4)];
        // POLICY CORRECTION: surfaced here (the single source of truth for
        // every stock figure) so it reaches the dashboard, stock list, and
        // SKU detail screens without each of them needing its own lookup.
        return $result + MigrationNegativeStockService::flagsFor($pdo, $itemId, $warehouseId, $qty);
    }

    /** Same figure, summed across every warehouse (dashboard-level "total stock of this SKU"). */
    public static function currentStockAllWarehouses(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT warehouse_id, COALESCE(SUM(qty_base), 0) AS qty, COALESCE(SUM(qty_base * unit_cost_base), 0) AS value
             FROM inventory_batches WHERE item_id = :item_id GROUP BY warehouse_id'
        );
        $stmt->execute(['item_id' => $itemId]);
        $rows = $stmt->fetchAll();
        $total = ['qty_base' => 0.0, 'value' => 0.0];
        $anyUnresolved = false;
        foreach ($rows as &$r) {
            $r['qty_base'] = round((float) $r['qty'], 6);
            $r['value'] = round((float) $r['value'], 4);
            unset($r['qty'], $r['value']);
            $flags = MigrationNegativeStockService::flagsFor($pdo, $itemId, (int) $r['warehouse_id'], $r['qty_base']);
            $r += $flags;
            $anyUnresolved = $anyUnresolved || $flags['migration_negative_review'];
            $total['qty_base'] += (float) $r['qty_base'];
            $total['value'] += (float) $r['value'];
        }
        return [
            'by_warehouse' => $rows,
            'total' => ['qty_base' => round($total['qty_base'], 6), 'value' => round($total['value'], 4)],
            'migration_negative_review' => $anyUnresolved,
        ];
    }

    public static function batches(PDO $pdo, int $itemId, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM inventory_batches
             WHERE item_id = :item_id AND warehouse_id = :wh AND qty_base <> 0
             ORDER BY received_date ASC, id ASC'
        );
        $stmt->execute(['item_id' => $itemId, 'wh' => $warehouseId]);
        return $stmt->fetchAll();
    }

    /** Sum of qty_base*unit_cost_base across every batch in every warehouse — "on-hand" company value. */
    public static function companyOnHandValue(PDO $pdo): float
    {
        $value = $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM inventory_batches')->fetchColumn();
        return round((float) $value, 4);
    }

    /**
     * Value of stock shipped but not yet received (Section 1: "Barang
     * pending dianggap IN TRANSIT"). This is what keeps
     * on-hand + in-transit constant across a transfer's ship/receive cycle,
     * since the OUT side already removed the value from the source
     * warehouse's batches before the destination batch exists.
     */
    public static function inTransitValue(PDO $pdo): float
    {
        $value = $pdo->query(
            "SELECT COALESCE(SUM(wtl.qty_base * wtl.unit_cost_base), 0)
             FROM warehouse_transfer_lines wtl
             JOIN warehouse_transfers wt ON wt.id = wtl.transfer_id
             WHERE wt.status = 'PENDING'"
        )->fetchColumn();
        return round((float) $value, 4);
    }

    public static function companyTotalValue(PDO $pdo): array
    {
        $onHand = self::companyOnHandValue($pdo);
        $inTransit = self::inTransitValue($pdo);
        // POLICY CORRECTION: company totals include negative migration
        // balances honestly (SUM() above already does — nothing is excluded
        // or zeroed); this flag just marks the total as containing unresolved
        // migration-negative stock so a report can label it, never hide it.
        return [
            'on_hand_value' => $onHand,
            'in_transit_value' => $inTransit,
            'total_value' => round($onHand + $inTransit, 4),
            'contains_unresolved_migration_negative_stock' => MigrationNegativeStockService::hasUnresolved($pdo),
        ];
    }

    /**
     * Chronological IN/OUT/balance ledger for one item+warehouse — the
     * "why is stock 250kg" audit trail (Section 8 of the brief).
     *
     * POLICY CORRECTION Section 3: historical-import rows
     * (is_historical_import=1, inventory_effect=0 — never real FIFO
     * postings) are now included in this SAME chronological feed, tagged
     * `is_historical`, with their own `historical_running_balance` that
     * accumulates independently. `balance_qty` (the live/FIFO balance)
     * is updated ONLY by inventory_effect=1 rows and carries forward
     * unchanged across a historical row — a historical row can never move
     * it, by construction, not just by convention.
     */
    public static function ledger(PDO $pdo, int $itemId, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            "SELECT l.id AS line_id, t.id AS transaction_id, t.transaction_type, t.transaction_date,
                    t.reference_no, t.status, t.inventory_effect, l.base_qty, l.unit_cost_base, l.subtotal, l.notes
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             WHERE l.item_id = :item_id AND l.warehouse_id = :wh
               AND t.status = 'POSTED'
             ORDER BY t.transaction_date ASC, t.id ASC, l.id ASC"
        );
        $stmt->execute(['item_id' => $itemId, 'wh' => $warehouseId]);
        $rows = $stmt->fetchAll();

        $balance = 0.0;
        $historicalBalance = 0.0;
        $historicalSeen = false;
        $ledger = [];
        foreach ($rows as $row) {
            $type = $row['transaction_type'];
            $signedQty = self::signedQty($type, (float) $row['base_qty']);
            $isHistorical = (int) $row['inventory_effect'] === 0;

            if ($isHistorical) {
                $historicalSeen = true;
                $historicalBalance = round($historicalBalance + $signedQty, 6);
            } else {
                $balance = round($balance + $signedQty, 6);
            }

            $ledger[] = [
                'date' => $row['transaction_date'],
                'reference' => $row['reference_no'],
                'transaction_type' => $type,
                'transaction_id' => (int) $row['transaction_id'],
                'is_historical' => $isHistorical,
                'in_qty' => $signedQty > 0 ? $signedQty : 0,
                'out_qty' => $signedQty < 0 ? abs($signedQty) : 0,
                'balance_qty' => $balance,
                'historical_running_balance' => $historicalSeen ? $historicalBalance : null,
                'unit_cost_base' => round((float) $row['unit_cost_base'], 4),
                // Always the magnitude of this line's cost — direction is already conveyed by in_qty/out_qty above,
                // so this stays comparable whether the underlying subtotal was stored signed (ADJUSTMENT) or not.
                'value' => round(abs((float) $row['subtotal']), 4),
                'notes' => $row['notes'],
            ];
        }
        return $ledger;
    }

    private static function signedQty(string $transactionType, float $baseQty): float
    {
        if (in_array($transactionType, self::POSITIVE_TYPES, true)) {
            return abs($baseQty);
        }
        if (in_array($transactionType, self::NEGATIVE_TYPES, true)) {
            return -abs($baseQty);
        }
        // ADJUSTMENT (StockAdjustmentService) and REVERSAL (VoidService) both
        // store base_qty already signed at post time — used as-is.
        return $baseQty;
    }
}
