<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE D0.1: the only sanctioned way to correct a POSTED transaction.
 * Never edits or deletes the original row — flips its status to VOID and
 * creates a brand-new REVERSAL transaction that undoes exactly what the
 * original did, using the original's OWN recorded batch/allocation, never
 * a FIFO recompute against current stock or current cost.
 *
 * Scope: this pass supports voiding IN, OUT, and ADJUSTMENT transactions
 * (matches the explicit D0.1 test list). TRANSFER_OUT/TRANSFER_IN have
 * their own paired-leg lifecycle — use TransferService::cancel() for those
 * instead. OPENING/PRODUCTION_IN/PRODUCTION_OUT voiding is not implemented
 * yet (documented gap, not silently accepted): voiding one leg of a
 * multi-row operation without touching its pair would leave that
 * operation's other side inconsistent.
 */
final class VoidService
{
    private const VOIDABLE_TYPES = ['IN', 'OUT', 'ADJUSTMENT'];

    /**
     * @param array $p {
     *   request_uuid, transaction_id, reason (required), voided_by, username,
     *   superadmin_override (bool — required truthy to void a transaction
     *   dated inside an already-LOCKED period; the router only allows this
     *   when the caller holds TRANSACTION_VOID_LOCKED_PERIOD)
     * }
     */
    public static function void(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['request_uuid', 'transaction_id', 'reason', 'voided_by']);

        $existing = IdempotencyService::findTransaction($pdo, $p['request_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'reversal_transaction_id' => (int) $existing['id']];
        }

        $reason = trim((string) $p['reason']);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['a void reason of at least 5 characters is required']);
        }

        $original = $pdo->prepare('SELECT * FROM inventory_transactions WHERE id = :id');
        $original->execute(['id' => $p['transaction_id']]);
        $original = $original->fetch();
        if (!$original) {
            throw new NotFoundException('original transaction not found');
        }

        // PHASE V2.5: OPENING is the authoritative go-live baseline — never
        // voidable through this generic flow, even by SUPERADMIN, and never
        // silently lumped in with the generic "unsupported type" message
        // below so the frontend can hide the button and the API can explain
        // exactly why with a dedicated code (OPENING_PROTECTED).
        if ($original['transaction_type'] === 'OPENING') {
            throw new OpeningProtectedException((int) $original['id']);
        }

        // PHASE V2.5: a historical-import row (inventory_effect=0) never
        // touched live stock/batches when it was posted — it exists for
        // audit visibility only (V2.3D). Voiding it through this flow would
        // fabricate a live FIFO reversal for a movement that was never live
        // to begin with, so it is blocked outright rather than silently
        // "succeeding" against batches/allocations that don't exist for it.
        if ((int) $original['inventory_effect'] === 0) {
            throw new ValidationException(["transaction {$original['id']} is a historical-import row (inventory_effect=0) and cannot be voided — it never affected live inventory"]);
        }

        if ($original['status'] !== 'POSTED') {
            // A genuinely new void request (different request_uuid) against an
            // already-VOIDED/REVERSED transaction is an error, not a silent
            // no-op — idempotency only covers a retried request_uuid (handled
            // above).
            throw new TransactionAlreadyVoidException((int) $original['id'], (string) $original['status']);
        }

        if (!in_array($original['transaction_type'], self::VOIDABLE_TYPES, true)) {
            throw new ValidationException(["voiding a {$original['transaction_type']} transaction directly is not supported — use the owning module's own cancel/reversal mechanism"]);
        }

        $isLocked = PeriodLockService::isLocked($pdo, $original['transaction_date']);
        if ($isLocked && empty($p['superadmin_override'])) {
            throw new PeriodLockedException($original['transaction_date']);
        }

        $line = $pdo->prepare('SELECT * FROM inventory_transaction_lines WHERE transaction_id = :id ORDER BY line_no LIMIT 1');
        $line->execute(['id' => $original['id']]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(['original transaction has no line to reverse']);
        }

        $now = date('Y-m-d H:i:s');

        $reversalStmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, reference_no,
                 status, reversal_of_id, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, \'REVERSAL\', :tx_date, :post_date, :wh, :ref, \'POSTED\', :reversal_of, 0, 1, :created_by, :now)'
        );
        $reversalStmt->execute([
            'uuid' => $p['request_uuid'], 'tx_date' => $now, 'post_date' => $now, 'wh' => $original['warehouse_id'],
            'ref' => "VOID-{$original['id']}", 'reversal_of' => $original['id'], 'created_by' => $p['voided_by'], 'now' => $now,
        ]);
        $reversalTransactionId = (int) $pdo->lastInsertId();

        if ($line['created_batch_id'] !== null) {
            $reversalBaseQty = self::reverseBatchCreation($pdo, $line, $reversalTransactionId, $now);
        } else {
            $reversalBaseQty = self::reverseBatchConsumption($pdo, $line, $reversalTransactionId, $now);
        }

        $pdo->prepare(
            'UPDATE inventory_transactions SET status = \'VOID\', void_reason = :reason, voided_by = :by, voided_at = :now WHERE id = :id'
        )->execute(['reason' => $reason, 'by' => $p['voided_by'], 'now' => $now, 'id' => $original['id']]);

        AuditService::log(
            $pdo, $p['voided_by'], $p['username'] ?? 'system', 'TRANSACTION_VOID',
            'inventory_transactions', $original['id'],
            ['status' => 'POSTED'],
            [
                'status' => 'VOID',
                'action_type' => 'VOID',
                'original_entity_type' => 'inventory_transactions',
                'original_transaction_id' => (int) $original['id'],
                'reversal_transaction_id' => $reversalTransactionId,
                'warehouse_id' => (int) $original['warehouse_id'],
                'before_status' => 'POSTED',
                'after_status' => 'VOID',
                'locked_period_override' => $isLocked,
            ],
            $reason
        );

        return [
            'success' => true,
            'original_transaction_id' => (int) $original['id'],
            'reversal_transaction_id' => $reversalTransactionId,
            'reversed_base_qty' => $reversalBaseQty,
        ];
    }

    /** Original line CREATED a batch (IN, or a positive ADJUSTMENT) — reduce that exact batch by the exact original qty. */
    private static function reverseBatchCreation(PDO $pdo, array $line, int $reversalTransactionId, string $now): float
    {
        $batch = $pdo->prepare('SELECT * FROM inventory_batches WHERE id = :id');
        $batch->execute(['id' => $line['created_batch_id']]);
        $batch = $batch->fetch();
        if (!$batch) {
            throw new ValidationException(['original batch no longer exists — cannot reverse']);
        }

        // PHASE V2.5: if anything has already consumed from this batch (a
        // later OUT/TRANSFER_OUT/PRODUCTION_IN/negative-ADJUSTMENT), its
        // current qty_base is below what this line originally created — a
        // naive `qty_base -= qty` here would drive it negative and corrupt
        // FIFO lineage. Block rather than attempt a partial/unsafe reversal;
        // the caller must void the downstream dependents first (their own
        // reversal restores the batch to original_qty_base, at which point
        // this void becomes safe).
        if (abs((float) $batch['qty_base'] - (float) $batch['original_qty_base']) > 0.000001) {
            $depStmt = $pdo->prepare(
                "SELECT DISTINCT t.id, t.transaction_uuid, t.transaction_type, t.transaction_date, t.reference_no
                 FROM fifo_allocations fa
                 JOIN inventory_transaction_lines l2 ON l2.id = fa.transaction_line_id
                 JOIN inventory_transactions t ON t.id = l2.transaction_id
                 WHERE fa.batch_id = :batch_id AND t.status = 'POSTED' AND t.id <> :original_tx_id
                 ORDER BY t.transaction_date"
            );
            $depStmt->execute(['batch_id' => $batch['id'], 'original_tx_id' => $line['transaction_id']]);
            throw new VoidHasDownstreamDependenciesException((int) $line['transaction_id'], $depStmt->fetchAll());
        }

        $qty = abs((float) $line['base_qty']);
        $cost = (float) $batch['unit_cost_base'];

        $pdo->prepare('UPDATE inventory_batches SET qty_base = qty_base - :qty WHERE id = :id')
            ->execute(['qty' => $qty, 'id' => $batch['id']]);

        $itemName = self::itemName($pdo, (int) $line['item_id']);
        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :neg_qty, :unit_id, 1, :neg_qty2, 0, :cost, :subtotal, :wh, :notes)'
        );
        $lineStmt->execute([
            'tx_id' => $reversalTransactionId, 'item_id' => $line['item_id'], 'item_name' => $itemName,
            'neg_qty' => -$qty, 'unit_id' => $line['input_unit_id'], 'neg_qty2' => -$qty,
            'cost' => $cost, 'subtotal' => round(-$qty * $cost, 4), 'wh' => $line['warehouse_id'],
            'notes' => 'reversal of transaction line ' . $line['id'],
        ]);
        $reversalLineId = (int) $pdo->lastInsertId();

        // Mirror allocation for audit symmetry: this reversal "consumed" exactly the batch it had created.
        $pdo->prepare(
            'INSERT INTO fifo_allocations (transaction_line_id, batch_id, qty_allocated, unit_cost_base, subtotal, created_at)
             VALUES (:line_id, :batch_id, :qty, :cost, :subtotal, :now)'
        )->execute(['line_id' => $reversalLineId, 'batch_id' => $batch['id'], 'qty' => $qty, 'cost' => $cost, 'subtotal' => round($qty * $cost, 4), 'now' => $now]);

        return -$qty;
    }

    /** Original line CONSUMED batches via FIFO (OUT, or a negative ADJUSTMENT) — restore exactly those batches by exactly what was taken. */
    private static function reverseBatchConsumption(PDO $pdo, array $line, int $reversalTransactionId, string $now): float
    {
        $allocations = $pdo->prepare('SELECT * FROM fifo_allocations WHERE transaction_line_id = :line_id');
        $allocations->execute(['line_id' => $line['id']]);
        $allocations = $allocations->fetchAll();
        if (empty($allocations)) {
            throw new ValidationException(['original transaction has no recorded FIFO allocation to reverse']);
        }

        $totalQty = 0.0;
        $totalValue = 0.0;
        foreach ($allocations as $alloc) {
            $totalQty += (float) $alloc['qty_allocated'];
            $totalValue += (float) $alloc['qty_allocated'] * (float) $alloc['unit_cost_base'];
        }
        $avgCost = $totalQty > 0 ? round($totalValue / $totalQty, 4) : 0;
        $itemName = self::itemName($pdo, (int) $line['item_id']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :qty, :unit_id, 1, :qty2, 0, :cost, :subtotal, :wh, :notes)'
        );
        $lineStmt->execute([
            'tx_id' => $reversalTransactionId, 'item_id' => $line['item_id'], 'item_name' => $itemName,
            'qty' => $totalQty, 'unit_id' => $line['input_unit_id'], 'qty2' => $totalQty,
            'cost' => $avgCost, 'subtotal' => round($totalValue, 4),
            'wh' => $line['warehouse_id'], 'notes' => 'reversal of transaction line ' . $line['id'],
        ]);
        $reversalLineId = (int) $pdo->lastInsertId();

        $restoreBatch = $pdo->prepare('UPDATE inventory_batches SET qty_base = qty_base + :qty WHERE id = :id');
        $insertAlloc = $pdo->prepare(
            'INSERT INTO fifo_allocations (transaction_line_id, batch_id, qty_allocated, unit_cost_base, subtotal, created_at)
             VALUES (:line_id, :batch_id, :qty, :cost, :subtotal, :now)'
        );
        foreach ($allocations as $alloc) {
            $qty = (float) $alloc['qty_allocated'];
            $cost = (float) $alloc['unit_cost_base'];
            $restoreBatch->execute(['qty' => $qty, 'id' => $alloc['batch_id']]);
            $insertAlloc->execute(['line_id' => $reversalLineId, 'batch_id' => $alloc['batch_id'], 'qty' => $qty, 'cost' => $cost, 'subtotal' => round($qty * $cost, 4), 'now' => $now]);
        }

        return $totalQty;
    }

    private static function itemName(PDO $pdo, int $itemId): string
    {
        $stmt = $pdo->prepare('SELECT name FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
