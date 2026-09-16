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
        if (trim((string) ($p['reason'] ?? '')) === '') {
            throw new ValidationException(['a cancel reason is required']);
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

    public static function get(PDO $pdo, int $transferId): array
    {
        $transfer = self::find($pdo, $transferId);
        $lines = $pdo->prepare('SELECT * FROM warehouse_transfer_lines WHERE transfer_id = :id');
        $lines->execute(['id' => $transferId]);
        $transfer['lines'] = $lines->fetchAll();
        return $transfer;
    }

    public static function listAll(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM warehouse_transfers ORDER BY created_at DESC')->fetchAll();
    }

    public static function listPending(PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM warehouse_transfers WHERE status = 'PENDING' ORDER BY ship_date ASC")->fetchAll();
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
