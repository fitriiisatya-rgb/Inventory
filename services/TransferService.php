<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 1. A transfer's OUT side runs immediately at create()
 * (Section: "Transfer keluar harus langsung mengurangi stok gudang asal").
 * Between create() and receive() the goods are IN TRANSIT: gone from the
 * source warehouse's batches, not yet in the destination's, and tracked
 * only via warehouse_transfer_lines with status PENDING — which is exactly
 * what InventoryService::inTransitValue() reads. Each transfer line is
 * stored one row PER FIFO LAYER consumed (not one averaged row per item),
 * so receive() can recreate the exact original cost layers instead of
 * re-averaging.
 */
final class TransferService
{
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['transfer_uuid', 'from_warehouse_id', 'to_warehouse_id', 'ship_date', 'created_by', 'lines']);

        $existing = self::findByUuid($pdo, $p['transfer_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => (int) $existing['id']];
        }

        if ((int) $p['from_warehouse_id'] === (int) $p['to_warehouse_id']) {
            throw new ValidationException(['from_warehouse_id and to_warehouse_id must differ']);
        }
        if (empty($p['lines'])) {
            throw new ValidationException(['at least one line is required']);
        }
        // PHASE V2.13: fail fast on BOTH ends before any stock leaves the
        // source — FifoService::postOut() below would also reject an
        // inactive from_warehouse_id, but only failing there would still
        // leave an inactive to_warehouse_id undetected until receive()
        // (potentially days later, with goods already sitting in transit).
        WarehouseGuardService::assertActive($pdo, (int) $p['from_warehouse_id']);
        WarehouseGuardService::assertActive($pdo, (int) $p['to_warehouse_id']);

        $now = date('Y-m-d H:i:s');
        $header = $pdo->prepare(
            'INSERT INTO warehouse_transfers (transfer_uuid, from_warehouse_id, to_warehouse_id, status, ship_date, created_by, created_at)
             VALUES (:uuid, :from_wh, :to_wh, \'PENDING\', :ship_date, :created_by, :now)'
        );
        $header->execute([
            'uuid' => $p['transfer_uuid'], 'from_wh' => $p['from_warehouse_id'], 'to_wh' => $p['to_warehouse_id'],
            'ship_date' => $p['ship_date'], 'created_by' => $p['created_by'], 'now' => $now,
        ]);
        $transferId = (int) $pdo->lastInsertId();

        foreach ($p['lines'] as $line) {
            $outResult = FifoService::postOut($pdo, [
                'transaction_uuid' => $p['transfer_uuid'] . ':OUT:' . $line['item_id'],
                'item_id' => $line['item_id'],
                'warehouse_id' => $p['from_warehouse_id'],
                'input_qty' => $line['input_qty'],
                'input_unit_id' => $line['input_unit_id'],
                'transaction_type' => 'TRANSFER_OUT',
                'transaction_date' => $p['ship_date'],
                'reference_no' => "TRANSFER-{$transferId}",
                'created_by' => $p['created_by'],
                'username' => $p['username'] ?? 'system',
                'allow_negative_stock' => $p['allow_negative_stock'] ?? false,
            ]);

            $outLineId = $outResult['line_id'];
            $allocations = $pdo->prepare('SELECT batch_id, qty_allocated, unit_cost_base FROM fifo_allocations WHERE transaction_line_id = :line_id');
            $allocations->execute(['line_id' => $outLineId]);

            $insertTransferLine = $pdo->prepare(
                'INSERT INTO warehouse_transfer_lines (transfer_id, item_id, qty_base, unit_cost_base, out_transaction_line_id)
                 VALUES (:transfer_id, :item_id, :qty, :cost, :out_line_id)'
            );
            foreach ($allocations->fetchAll() as $alloc) {
                $insertTransferLine->execute([
                    'transfer_id' => $transferId, 'item_id' => $line['item_id'],
                    'qty' => $alloc['qty_allocated'], 'cost' => $alloc['unit_cost_base'], 'out_line_id' => $outLineId,
                ]);
            }
        }

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSFER_CREATE', 'warehouse_transfers', $transferId, null, ['from' => $p['from_warehouse_id'], 'to' => $p['to_warehouse_id'], 'lines' => count($p['lines'])], null);

        return ['success' => true, 'transfer_id' => $transferId];
    }

    public static function receive(PDO $pdo, int $transferId, array $p): array
    {
        $transfer = self::find($pdo, $transferId);
        $requestUuid = $p['request_uuid'] ?? null;

        if ($transfer['status'] === 'RECEIVED') {
            // Same request retried (e.g. a network timeout) — safe no-op. A genuinely NEW
            // attempt (no matching request_uuid) against an already-received transfer is an
            // error: double receive must be impossible, not silently "successful" for someone
            // who didn't actually just receive it.
            if ($requestUuid !== null && $requestUuid === $transfer['receive_request_uuid']) {
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId];
            }
            throw new TransferAlreadyReceivedException($transferId);
        }
        if ($transfer['status'] !== 'PENDING') {
            throw new ValidationException(["transfer is {$transfer['status']}, cannot be received"]);
        }

        $receiveDate = $p['receive_date'] ?? date('Y-m-d H:i:s');
        // (FifoService::postIn below re-checks the period lock against ship_date itself.)

        $lines = $pdo->prepare('SELECT * FROM warehouse_transfer_lines WHERE transfer_id = :id AND in_transaction_line_id IS NULL');
        $lines->execute(['id' => $transferId]);
        $lines = $lines->fetchAll();

        if (empty($lines)) {
            throw new ValidationException(['nothing left to receive on this transfer (already fully received?)']);
        }

        // Partial receive is not supported yet — full receive only (Section 1: "kalau belum
        // diperlukan, reject dan wajib full receive"). Every PENDING line is received together.
        foreach ($lines as $line) {
            $baseUnitId = self::baseUnitId($pdo, (int) $line['item_id']);
            $inResult = FifoService::postIn($pdo, [
                'transaction_uuid' => $transfer['transfer_uuid'] . ':IN:' . $line['id'],
                'item_id' => $line['item_id'],
                'warehouse_id' => $transfer['to_warehouse_id'],
                'input_qty' => $line['qty_base'],
                'input_unit_id' => $baseUnitId, // base unit, factor 1 — preserves the exact layer cost, no re-averaging
                'unit_price_input' => $line['unit_cost_base'],
                'transaction_type' => 'TRANSFER_IN',
                'transaction_date' => $transfer['ship_date'], // Section 1: batch date = ship date, not receipt click time
                'reference_no' => "TRANSFER-{$transferId}",
                'created_by' => $p['created_by'],
                'username' => $p['username'] ?? 'system',
                'anomaly_approved_by' => $p['created_by'], // internal transfer cost is not a purchase — never anomaly-blocked
            ]);
            $pdo->prepare('UPDATE warehouse_transfer_lines SET in_transaction_line_id = :in_line_id WHERE id = :id')
                ->execute(['in_line_id' => $inResult['line_id'], 'id' => $line['id']]);
        }

        $pdo->prepare('UPDATE warehouse_transfers SET status = \'RECEIVED\', receive_date = :now, received_by = :by, receive_request_uuid = :req_uuid WHERE id = :id')
            ->execute(['now' => $receiveDate, 'by' => $p['created_by'], 'req_uuid' => $requestUuid, 'id' => $transferId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSFER_RECEIVE', 'warehouse_transfers', $transferId, null, ['lines_received' => count($lines)], null);

        return ['success' => true, 'transfer_id' => $transferId, 'lines_received' => count($lines)];
    }

    public static function cancel(PDO $pdo, int $transferId, array $p): array
    {
        $transfer = self::find($pdo, $transferId);
        $requestUuid = $p['request_uuid'] ?? null;

        if ($transfer['status'] === 'CANCELLED') {
            if ($requestUuid !== null && $requestUuid === $transfer['cancel_request_uuid']) {
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId];
            }
            throw new TransferAlreadyCancelledException($transferId);
        }
        if ($transfer['status'] !== 'PENDING') {
            throw new ValidationException(['only a PENDING (not yet received) transfer can be cancelled']);
        }
        $cancelReason = trim((string) ($p['reason'] ?? ''));
        if (mb_strlen($cancelReason) < 5) {
            throw new ValidationException(['a cancel reason of at least 5 characters is required']);
        }

        // Restore each consumed FIFO layer exactly as it was — no new batch, no re-averaging.
        $lines = $pdo->prepare('SELECT * FROM warehouse_transfer_lines WHERE transfer_id = :id');
        $lines->execute(['id' => $transferId]);
        foreach ($lines->fetchAll() as $line) {
            // Restore via the originating out_transaction_line_id's allocations, matched by cost+qty (unique enough for this line).
            $restore = $pdo->prepare(
                'UPDATE inventory_batches b
                 JOIN fifo_allocations fa ON fa.batch_id = b.id
                 SET b.qty_base = b.qty_base + fa.qty_allocated
                 WHERE fa.transaction_line_id = :out_line_id AND fa.qty_allocated = :qty AND fa.unit_cost_base = :cost
                 LIMIT 1'
            );
            $restore->execute(['out_line_id' => $line['out_transaction_line_id'], 'qty' => $line['qty_base'], 'cost' => $line['unit_cost_base']]);
        }

        $pdo->prepare(
            'UPDATE inventory_transactions SET status = \'REVERSED\' WHERE id = (
                SELECT transaction_id FROM inventory_transaction_lines WHERE id IN (
                    SELECT DISTINCT out_transaction_line_id FROM warehouse_transfer_lines WHERE transfer_id = :id
                ) LIMIT 1
             )'
        )->execute(['id' => $transferId]);

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE warehouse_transfers SET status = \'CANCELLED\', cancel_reason = :reason, cancelled_by = :by, cancelled_at = :now, cancel_request_uuid = :req_uuid WHERE id = :id')
            ->execute(['reason' => $p['reason'], 'by' => $p['created_by'], 'now' => $now, 'req_uuid' => $requestUuid, 'id' => $transferId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSFER_CANCEL', 'warehouse_transfers', $transferId, null, null, $p['reason']);

        return ['success' => true, 'transfer_id' => $transferId];
    }

    /**
     * PHASE V2.5: the RECEIVED-transfer correction flow. Reverses the WHOLE
     * chain atomically — destination batch(es), TRANSFER_IN status,
     * TRANSFER_OUT status, and the exact source FIFO allocations — never a
     * second "cancel". Blocked (never a naive partial reversal) if anything
     * has consumed from a destination batch since receipt: OUT, production,
     * another transfer, an adjustment, or an opname correction all leave a
     * live fifo_allocations claim against that batch, which this method
     * detects and refuses to touch.
     *
     * @param array $p { created_by, username, reason (required, >=5 chars), request_uuid }
     */
    public static function reverse(PDO $pdo, int $transferId, array $p): array
    {
        $transfer = self::find($pdo, $transferId);
        $requestUuid = $p['request_uuid'] ?? null;

        if ($transfer['status'] === 'REVERSED') {
            // Same request retried — safe no-op. A genuinely NEW attempt (no
            // matching request_uuid) against an already-reversed transfer is
            // an error, matching VoidService's/cancel()'s sibling pattern.
            if ($requestUuid !== null && $requestUuid === $transfer['reverse_request_uuid']) {
                return ['success' => true, 'idempotent_replay' => true, 'transfer_id' => $transferId];
            }
            throw new TransferAlreadyReversedException($transferId);
        }
        if ($transfer['status'] !== 'RECEIVED') {
            throw new ValidationException(["only a RECEIVED transfer can be reversed (current status: {$transfer['status']})"]);
        }

        $reason = trim((string) ($p['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['a reversal reason of at least 5 characters is required']);
        }

        $lines = $pdo->prepare('SELECT * FROM warehouse_transfer_lines WHERE transfer_id = :id');
        $lines->execute(['id' => $transferId]);
        $lines = $lines->fetchAll();

        // Step 6: dependency analysis — for every destination batch this
        // transfer created, verify its full received qty is still intact
        // (qty_base == original_qty_base). If not, something downstream has
        // already consumed from it and an automatic reversal cannot be
        // guaranteed correct.
        $dependencies = [];
        foreach ($lines as $line) {
            if ($line['in_transaction_line_id'] === null) {
                continue; // defensive: shouldn't happen once status=RECEIVED
            }
            $batch = $pdo->prepare('SELECT * FROM inventory_batches WHERE source_transaction_line_id = :id');
            $batch->execute(['id' => $line['in_transaction_line_id']]);
            $batch = $batch->fetch();
            if (!$batch) {
                continue;
            }
            if (abs((float) $batch['qty_base'] - (float) $batch['original_qty_base']) > 0.000001) {
                $depStmt = $pdo->prepare(
                    "SELECT DISTINCT t.id, t.transaction_uuid, t.transaction_type, t.transaction_date, t.reference_no
                     FROM fifo_allocations fa
                     JOIN inventory_transaction_lines l2 ON l2.id = fa.transaction_line_id
                     JOIN inventory_transactions t ON t.id = l2.transaction_id
                     WHERE fa.batch_id = :batch_id AND t.status = 'POSTED'
                     ORDER BY t.transaction_date"
                );
                $depStmt->execute(['batch_id' => $batch['id']]);
                foreach ($depStmt->fetchAll() as $row) {
                    $dependencies[(int) $row['id']] = $row; // de-dup by transaction id across lines
                }
            }
        }
        if (!empty($dependencies)) {
            throw new TransferReversalHasDownstreamDependenciesException($transferId, array_values($dependencies));
        }

        $now = date('Y-m-d H:i:s');
        $inTxIds = [];
        $outTxIds = [];

        foreach ($lines as $line) {
            // Step 7: reverse destination inventory — this transfer's own
            // batch is verified untouched above, so zeroing it out (never a
            // physical delete — batches are never removed) is exactly the
            // inverse of the receive() that created it.
            if ($line['in_transaction_line_id'] !== null) {
                $pdo->prepare(
                    'UPDATE inventory_batches SET qty_base = 0 WHERE source_transaction_line_id = :line_id'
                )->execute(['line_id' => $line['in_transaction_line_id']]);

                $inTxRow = $pdo->prepare('SELECT transaction_id FROM inventory_transaction_lines WHERE id = :id');
                $inTxRow->execute(['id' => $line['in_transaction_line_id']]);
                $inTxIds[] = (int) $inTxRow->fetchColumn();
            }

            // Step 10: restore the exact original FIFO source allocations —
            // never a re-average, never a new batch. A multi-layer transfer
            // line creates one warehouse_transfer_lines row PER FIFO layer,
            // but every one of those rows shares the SAME out_transaction_line_id
            // (create() ran exactly one postOut() per user-requested line —
            // see its class docblock). Restoring by "every fifo_allocations
            // row for that out_transaction_line_id" would therefore restore
            // every layer once PER warehouse_transfer_lines row — i.e. N
            // times for an N-layer consumption. Match this line's own
            // qty_base/unit_cost_base (LIMIT 1), the exact same
            // disambiguation cancel() already uses for this identical
            // shared-out_transaction_line_id shape.
            if ($line['out_transaction_line_id'] !== null) {
                $restoreBatch = $pdo->prepare(
                    'UPDATE inventory_batches b
                     JOIN fifo_allocations fa ON fa.batch_id = b.id
                     SET b.qty_base = b.qty_base + fa.qty_allocated
                     WHERE fa.transaction_line_id = :out_line_id AND fa.qty_allocated = :qty AND fa.unit_cost_base = :cost
                     LIMIT 1'
                );
                $restoreBatch->execute(['out_line_id' => $line['out_transaction_line_id'], 'qty' => $line['qty_base'], 'cost' => $line['unit_cost_base']]);

                $outTxRow = $pdo->prepare('SELECT transaction_id FROM inventory_transaction_lines WHERE id = :id');
                $outTxRow->execute(['id' => $line['out_transaction_line_id']]);
                $outTxIds[] = (int) $outTxRow->fetchColumn();
            }
        }

        $inTxIds = array_values(array_unique($inTxIds));
        $outTxIds = array_values(array_unique($outTxIds));

        // Steps 8-9: flip TRANSFER_IN / TRANSFER_OUT transaction status to
        // REVERSED — the original rows are never edited beyond status, never
        // deleted, matching VoidService's/cancel()'s status-flip convention.
        foreach ($inTxIds as $txId) {
            $pdo->prepare("UPDATE inventory_transactions SET status = 'REVERSED' WHERE id = :id")->execute(['id' => $txId]);
        }
        foreach ($outTxIds as $txId) {
            $pdo->prepare("UPDATE inventory_transactions SET status = 'REVERSED' WHERE id = :id")->execute(['id' => $txId]);
        }

        // Step 11: mark the transfer itself REVERSED.
        $pdo->prepare(
            "UPDATE warehouse_transfers
             SET status = 'REVERSED', reverse_reason = :reason, reversed_by = :by, reversed_at = :now, reverse_request_uuid = :req_uuid
             WHERE id = :id"
        )->execute(['reason' => $reason, 'by' => $p['created_by'], 'now' => $now, 'req_uuid' => $requestUuid, 'id' => $transferId]);

        // Step 12: full audit log — reuses the existing AuditService, no
        // parallel audit framework.
        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSFER_REVERSE',
            'warehouse_transfers', $transferId,
            ['status' => 'RECEIVED'],
            [
                'status' => 'REVERSED',
                'action_type' => 'REVERSE_TRANSFER',
                'original_entity_type' => 'warehouse_transfers',
                'original_entity_id' => $transferId,
                'transfer_id' => $transferId,
                'transfer_out_transaction_ids' => $outTxIds,
                'transfer_in_transaction_ids' => $inTxIds,
                'warehouse_id' => (int) $transfer['from_warehouse_id'],
                'before_status' => 'RECEIVED',
                'after_status' => 'REVERSED',
            ],
            $reason
        );

        return [
            'success' => true,
            'transfer_id' => $transferId,
            'transfer_out_transaction_ids' => $outTxIds,
            'transfer_in_transaction_ids' => $inTxIds,
        ];
    }

    public static function get(PDO $pdo, int $transferId): array
    {
        $transfer = self::find($pdo, $transferId);
        $lines = $pdo->prepare('SELECT * FROM warehouse_transfer_lines WHERE transfer_id = :id');
        $lines->execute(['id' => $transferId]);
        $transfer['lines'] = $lines->fetchAll();
        return $transfer;
    }

    public static function listAll(PDO $pdo, ?int $warehouseId = null): array
    {
        if ($warehouseId === null) {
            return $pdo
                ->query(
                    'SELECT * FROM warehouse_transfers
                     ORDER BY created_at DESC'
                )
                ->fetchAll();
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM warehouse_transfers
             WHERE from_warehouse_id = :from_wh
                OR to_warehouse_id = :to_wh
             ORDER BY created_at DESC'
        );

        $stmt->execute([
            'from_wh' => $warehouseId,
            'to_wh' => $warehouseId,
        ]);

        return $stmt->fetchAll();
    }

    public static function listPending(PDO $pdo, ?int $warehouseId = null): array
    {
        if ($warehouseId === null) {
            return $pdo
                ->query(
                    "SELECT *
                     FROM warehouse_transfers
                     WHERE status = 'PENDING'
                     ORDER BY ship_date ASC"
                )
                ->fetchAll();
        }

        $stmt = $pdo->prepare(
            "SELECT *
             FROM warehouse_transfers
             WHERE status = 'PENDING'
               AND (
                    from_warehouse_id = :from_wh
                    OR to_warehouse_id = :to_wh
               )
             ORDER BY ship_date ASC"
        );

        $stmt->execute([
            'from_wh' => $warehouseId,
            'to_wh' => $warehouseId,
        ]);

        return $stmt->fetchAll();
    }

    private static function find(PDO $pdo, int $transferId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM warehouse_transfers WHERE id = :id');
        $stmt->execute(['id' => $transferId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException('transfer not found');
        }
        return $row;
    }

    private static function findByUuid(PDO $pdo, string $uuid): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM warehouse_transfers WHERE transfer_uuid = :uuid');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function baseUnitId(PDO $pdo, int $itemId): int
    {
        $stmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (int) $stmt->fetchColumn();
    }
}
