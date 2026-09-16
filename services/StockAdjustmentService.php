<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 3: the one place any stock correction is allowed to
 * happen. Used directly by POST /api/stock-adjustments, and internally by
 * StockOpnameService::post() for opname variances — same rules either way:
 * a mandatory reason, a real ADJUSTMENT-type transaction (never a silent
 * batch-qty overwrite), and no invented cost for an IN adjustment that
 * doesn't have one.
 *
 * Ledger convention: unlike IN/OUT, an ADJUSTMENT line's base_qty is stored
 * SIGNED (positive = stock increase, negative = decrease) — see
 * InventoryService's class docblock.
 */
final class StockAdjustmentService
{
    private const QTY_SCALE = 6;
    private const MONEY_SCALE = 4;

    /**
     * @param array $p {
     *   transaction_uuid, item_id, warehouse_id, qty_base_delta (signed, != 0),
     *   adjustment_type: OPNAME|CORRECTION|DAMAGE|EXPIRED|LOSS|OTHER|NEGATIVE_OVERRIDE,
     *   reason (required), reference_no, created_by, username,
     *   override_cost_base (optional — required for a positive delta with no known cost),
     *   requires_approval, approved_by,
     *   transaction_date, bypass_warehouse_lock (internal use by StockOpnameService)
     * }
     */
    public static function post(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['transaction_uuid', 'item_id', 'warehouse_id', 'qty_base_delta', 'adjustment_type', 'reason', 'created_by']);

        $existing = IdempotencyService::findTransaction($pdo, $p['transaction_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'transaction_id' => (int) $existing['id']];
        }

        $delta = round((float) $p['qty_base_delta'], self::QTY_SCALE);
        if ($delta === 0.0) {
            throw new ValidationException(['qty_base_delta must not be zero']);
        }
        if (trim((string) ($p['reason'] ?? '')) === '') {
            throw new ValidationException(['reason is required for every stock adjustment']);
        }

        $transactionDate = $p['transaction_date'] ?? date('Y-m-d H:i:s');
        PeriodLockService::assertNotLocked($pdo, $transactionDate);
        if (empty($p['bypass_warehouse_lock'])) {
            WarehouseLockService::assertNotLocked($pdo, $p['warehouse_id']);
        }

        $before = InventoryService::currentStock($pdo, $p['item_id'], $p['warehouse_id']);
        $beforeQty = $before['qty_base'];

        if ($delta > 0) {
            $unitCostBase = self::resolveIncreaseCost($pdo, $p);
            [$lineId, $transactionId] = self::postIncrease($pdo, $p, $delta, $unitCostBase, $transactionDate);
        } else {
            $result = self::postDecrease($pdo, $p, abs($delta), $transactionDate);
            $lineId = $result['line_id'];
            $transactionId = $result['transaction_id'];
            $unitCostBase = $result['unit_cost_base'];
        }

        $afterQty = round($beforeQty + $delta, self::QTY_SCALE);

        $adjStmt = $pdo->prepare(
            'INSERT INTO stock_adjustments
                (item_id, warehouse_id, adjustment_type, qty_base_delta, before_qty_base, after_qty_base,
                 unit_cost_base, transaction_id, reference_no, reason, requires_approval, approved_by, approved_at,
                 created_by, created_at)
             VALUES (:item_id, :wh, :type, :delta, :before_qty, :after_qty,
                     :cost, :tx_id, :ref, :reason, :requires_approval, :approved_by, :approved_at,
                     :created_by, :now)'
        );
        $now = date('Y-m-d H:i:s');
        $adjStmt->execute([
            'item_id' => $p['item_id'], 'wh' => $p['warehouse_id'], 'type' => $p['adjustment_type'],
            'delta' => $delta, 'before_qty' => $beforeQty, 'after_qty' => $afterQty,
            'cost' => $unitCostBase, 'tx_id' => $transactionId, 'ref' => $p['reference_no'] ?? null,
            'reason' => $p['reason'], 'requires_approval' => !empty($p['requires_approval']) ? 1 : 0,
            'approved_by' => $p['approved_by'] ?? null,
            'approved_at' => !empty($p['approved_by']) ? $now : null,
            'created_by' => $p['created_by'], 'now' => $now,
        ]);
        $adjustmentId = (int) $pdo->lastInsertId();

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'STOCK_ADJUSTMENT',
            'stock_adjustments', $adjustmentId,
            ['qty_base' => $beforeQty], ['qty_base' => $afterQty, 'delta' => $delta, 'type' => $p['adjustment_type']],
            $p['reason']
        );

        return [
            'success' => true, 'adjustment_id' => $adjustmentId, 'transaction_id' => $transactionId,
            'before_qty_base' => $beforeQty, 'after_qty_base' => $afterQty, 'unit_cost_base' => $unitCostBase,
        ];
    }

    private static function resolveIncreaseCost(PDO $pdo, array $p): float
    {
        if (isset($p['override_cost_base']) && (float) $p['override_cost_base'] > 0) {
            return round((float) $p['override_cost_base'], self::MONEY_SCALE);
        }

        // "Last reliable cost": most recent batch cost for this item, any warehouse.
        $stmt = $pdo->prepare(
            'SELECT unit_cost_base FROM inventory_batches WHERE item_id = :item_id ORDER BY received_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['item_id' => $p['item_id']]);
        $lastCost = $stmt->fetchColumn();

        if ($lastCost === false || (float) $lastCost <= 0) {
            throw new CostRequiredException((int) $p['item_id']);
        }

        return round((float) $lastCost, self::MONEY_SCALE);
    }

    private static function postIncrease(PDO $pdo, array $p, float $delta, float $unitCostBase, string $transactionDate): array
    {
        $baseUnitId = self::baseUnitId($pdo, $p['item_id']);
        $now = date('Y-m-d H:i:s');

        $txId = self::insertTransactionHeader($pdo, $p, $transactionDate, $now);
        $itemName = self::itemName($pdo, $p['item_id']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :delta, :unit_id, 1, :delta2, :cost, :cost2, :subtotal, :wh, :notes)'
        );
        $subtotal = round($delta * $unitCostBase, self::MONEY_SCALE);
        $lineStmt->execute([
            'tx_id' => $txId, 'item_id' => $p['item_id'], 'item_name' => $itemName,
            'delta' => $delta, 'unit_id' => $baseUnitId, 'delta2' => $delta,
            'cost' => $unitCostBase, 'cost2' => $unitCostBase, 'subtotal' => $subtotal,
            'wh' => $p['warehouse_id'], 'notes' => $p['reason'] ?? null,
        ]);
        $lineId = (int) $pdo->lastInsertId();

        $batchStmt = $pdo->prepare(
            'INSERT INTO inventory_batches
                (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date,
                 source_transaction_line_id, is_negative_layer, created_at)
             VALUES (:item_id, :wh, :qty, :qty2, :cost, :received_date, :line_id, 0, :now)'
        );
        $batchStmt->execute([
            'item_id' => $p['item_id'], 'wh' => $p['warehouse_id'], 'qty' => $delta, 'qty2' => $delta,
            'cost' => $unitCostBase, 'received_date' => $transactionDate, 'line_id' => $lineId, 'now' => $now,
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE inventory_transaction_lines SET created_batch_id = :batch_id WHERE id = :id')
            ->execute(['batch_id' => $batchId, 'id' => $lineId]);

        return [$lineId, $txId];
    }

    private static function postDecrease(PDO $pdo, array $p, float $qtyToRemove, string $transactionDate): array
    {
        $baseUnitId = self::baseUnitId($pdo, $p['item_id']);
        $now = date('Y-m-d H:i:s');

        $batches = Database::lockFifoBatches($pdo, $p['item_id'], $p['warehouse_id']);
        $available = round(array_sum(array_column($batches, 'qty_base')), self::QTY_SCALE);
        if ($qtyToRemove > $available && empty($p['allow_negative_stock'])) {
            throw new NegativeStockException($qtyToRemove, $available);
        }

        $txId = self::insertTransactionHeader($pdo, $p, $transactionDate, $now);
        $itemName = self::itemName($pdo, $p['item_id']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :neg_qty, :unit_id, 1, :neg_qty2, 0, 0, 0, :wh, :notes)'
        );
        $lineStmt->execute([
            'tx_id' => $txId, 'item_id' => $p['item_id'], 'item_name' => $itemName,
            'neg_qty' => -$qtyToRemove, 'unit_id' => $baseUnitId, 'neg_qty2' => -$qtyToRemove,
            'wh' => $p['warehouse_id'], 'notes' => $p['reason'] ?? null,
        ]);
        $lineId = (int) $pdo->lastInsertId();

        $remaining = $qtyToRemove;
        $totalCost = 0.0;
        $allocStmt = $pdo->prepare(
            'INSERT INTO fifo_allocations (transaction_line_id, batch_id, qty_allocated, unit_cost_base, subtotal, created_at)
             VALUES (:line_id, :batch_id, :qty, :cost, :subtotal, :now)'
        );
        $updateBatchStmt = $pdo->prepare('UPDATE inventory_batches SET qty_base = :qty WHERE id = :id');

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = round(min((float) $batch['qty_base'], $remaining), self::QTY_SCALE);
            if ($take <= 0) {
                continue;
            }
            $cost = (float) $batch['unit_cost_base'];
            $subtotal = round($take * $cost, self::MONEY_SCALE);
            $totalCost += $subtotal;
            $allocStmt->execute(['line_id' => $lineId, 'batch_id' => $batch['id'], 'qty' => $take, 'cost' => $cost, 'subtotal' => $subtotal, 'now' => $now]);
            $updateBatchStmt->execute(['qty' => round((float) $batch['qty_base'] - $take, self::QTY_SCALE), 'id' => $batch['id']]);
            $remaining = round($remaining - $take, self::QTY_SCALE);
        }

        if ($remaining > 0) {
            // Explicit negative override, same pattern as FifoService::postOut.
            $lastCost = $pdo->prepare('SELECT unit_cost_base FROM inventory_batches WHERE item_id = :item_id ORDER BY received_date DESC, id DESC LIMIT 1');
            $lastCost->execute(['item_id' => $p['item_id']]);
            $cost = $lastCost->fetchColumn();
            $cost = $cost !== false ? (float) $cost : 0.0;

            $negBatch = $pdo->prepare(
                'INSERT INTO inventory_batches (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, source_transaction_line_id, is_negative_layer, created_at)
                 VALUES (:item_id, :wh, :qty, :qty2, :cost, :received_date, :line_id, 1, :now)'
            );
            $negBatch->execute(['item_id' => $p['item_id'], 'wh' => $p['warehouse_id'], 'qty' => -$remaining, 'qty2' => -$remaining, 'cost' => $cost, 'received_date' => $transactionDate, 'line_id' => $lineId, 'now' => $now]);
            $negBatchId = (int) $pdo->lastInsertId();
            $subtotal = round($remaining * $cost, self::MONEY_SCALE);
            $totalCost += $subtotal;
            $allocStmt->execute(['line_id' => $lineId, 'batch_id' => $negBatchId, 'qty' => $remaining, 'cost' => $cost, 'subtotal' => $subtotal, 'now' => $now]);
        }

        $unitCostAvg = $qtyToRemove > 0 ? round($totalCost / $qtyToRemove, self::MONEY_SCALE) : 0.0;
        $pdo->prepare('UPDATE inventory_transaction_lines SET unit_cost_base = :cost, subtotal = :subtotal WHERE id = :id')
            ->execute(['cost' => $unitCostAvg, 'subtotal' => round(-$totalCost, self::MONEY_SCALE), 'id' => $lineId]);

        return ['line_id' => $lineId, 'transaction_id' => $txId, 'unit_cost_base' => $unitCostAvg];
    }

    private static function insertTransactionHeader(PDO $pdo, array $p, string $transactionDate, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, reference_no,
                 status, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, \'ADJUSTMENT\', :tx_date, :post_date, :wh, :ref, \'POSTED\', 0, 1, :created_by, :created_at)'
        );
        $stmt->execute([
            'uuid' => $p['transaction_uuid'], 'tx_date' => $transactionDate, 'post_date' => $now,
            'wh' => $p['warehouse_id'], 'ref' => $p['reference_no'] ?? null,
            'created_by' => $p['created_by'], 'created_at' => $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function baseUnitId(PDO $pdo, int $itemId): int
    {
        $stmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (int) $stmt->fetchColumn();
    }

    private static function itemName(PDO $pdo, int $itemId): string
    {
        $stmt = $pdo->prepare('SELECT name FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
