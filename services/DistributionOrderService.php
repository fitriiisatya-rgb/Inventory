<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11A — SCM -> Bakery Delivery Order lifecycle.
 *
 * DRAFT -> APPROVED -> PICKING -> DISPATCHED -> RECEIVED[_WITH_DISCREPANCY] -> COMPLETED
 *                                                                 (or CANCELLED at any pre-DISPATCHED step)
 *
 * Stock is untouched through DRAFT/APPROVED/PICKING. The ONLY place real
 * inventory moves is dispatch(), which calls the existing, unmodified
 * FifoService::postOut() once per line — never a second inventory engine,
 * never a manual UPDATE of inventory_batches/inventory_transactions.
 *
 * This is a genuinely different concept from warehouse_transfers
 * (TransferService) — that stays reserved for warehouse-to-warehouse
 * movement (e.g. SCM -> CIBADAK). A distribution_orders row always names a
 * bakery_destination_id, never a to_warehouse_id, and dispatch() posts
 * transaction_type='OUT' with bakery_destination_id set, not TRANSFER_OUT.
 */
final class DistributionOrderService
{
    /**
     * The stable warehouse CODE that identifies "SCM / Gudang Besar" for
     * this installation. Distribution orders may only originate from this
     * warehouse (Section 3) — resolved by code, never a hard-coded numeric
     * id, and enforced here at the service layer so an API caller can never
     * bypass it by simply posting a different from_warehouse_id.
     */
    public const SCM_WAREHOUSE_CODE = 'SCM';

    /** @param array{do_date:string, bakery_destination_id:int, from_warehouse_id:int, created_by:int, username?:string, reference_no?:?string, notes?:?string, driver_name?:?string, vehicle_no?:?string, delivery_notes?:?string, lines:list<array{item_id:int, input_qty:float, input_unit_id:int, notes?:?string}>} $p */
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['do_date', 'bakery_destination_id', 'from_warehouse_id', 'created_by', 'lines']);

        if (empty($p['lines'])) {
            throw new ValidationException(['at least one item line is required']);
        }

        $fromWarehouseId = (int) $p['from_warehouse_id'];
        self::assertIsScmWarehouse($pdo, $fromWarehouseId);

        $bakery = self::findActiveBakery($pdo, (int) $p['bakery_destination_id']);

        $doNumber = NumberingService::next($pdo, 'DO', $p['do_date']);

        $header = $pdo->prepare(
            'INSERT INTO distribution_orders
                (do_number, do_date, from_warehouse_id, bakery_destination_id, delivery_address_snapshot,
                 reference_no, notes, driver_name, vehicle_no, delivery_notes, status, created_by, created_at)
             VALUES (:do_number, :do_date, :from_wh, :bakery, :address, :ref, :notes, :driver, :vehicle, :delivery_notes, \'DRAFT\', :created_by, :now)'
        );
        $now = date('Y-m-d H:i:s');
        $header->execute([
            'do_number' => $doNumber, 'do_date' => $p['do_date'], 'from_wh' => $fromWarehouseId,
            'bakery' => $bakery['id'], 'address' => $bakery['address'],
            'ref' => $p['reference_no'] ?? null, 'notes' => $p['notes'] ?? null,
            'driver' => $p['driver_name'] ?? null, 'vehicle' => $p['vehicle_no'] ?? null,
            'delivery_notes' => $p['delivery_notes'] ?? null,
            'created_by' => $p['created_by'], 'now' => $now,
        ]);
        $doId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO distribution_order_lines
                (do_id, line_no, item_id, sku_snapshot, item_name_snapshot, category_id_snapshot,
                 input_qty, input_unit_id, qty_base, notes, created_at)
             VALUES (:do_id, :line_no, :item_id, :sku, :name, :category, :qty, :unit, :qty_base, :notes, :now)'
        );
        $lineNo = 1;
        foreach ($p['lines'] as $line) {
            $item = self::findActiveItem($pdo, (int) $line['item_id']);
            $qty = (float) $line['input_qty'];
            if (!($qty > 0)) {
                throw new ValidationException(["line {$lineNo}: input_qty must be > 0"]);
            }
            $conversion = UnitConversionService::getActiveConversion($pdo, $item['id'], (int) $line['input_unit_id'], $p['do_date']);
            if ($conversion === null) {
                throw new UnitConversionNotApprovedException($item['id'], (int) $line['input_unit_id']);
            }
            $qtyBase = round($qty * (float) $conversion['conversion_to_base'], 6);

            $lineStmt->execute([
                'do_id' => $doId, 'line_no' => $lineNo, 'item_id' => $item['id'],
                'sku' => $item['sku'], 'name' => $item['name'], 'category' => $item['category_id'],
                'qty' => $qty, 'unit' => (int) $line['input_unit_id'], 'qty_base' => $qtyBase,
                'notes' => $line['notes'] ?? null, 'now' => $now,
            ]);
            $lineNo++;
        }

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_CREATE',
            'distribution_orders', $doId, null,
            ['do_number' => $doNumber, 'bakery_destination_id' => $bakery['id'], 'lines' => $lineNo - 1],
            null
        );

        return ['success' => true, 'do_id' => $doId, 'do_number' => $doNumber];
    }

    public static function approve(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        self::assertStatus($do, 'DRAFT', 'approved');

        $pdo->prepare('UPDATE distribution_orders SET status = \'APPROVED\', approved_by = :by, approved_at = :now WHERE id = :id')
            ->execute(['by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $doId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_APPROVE', 'distribution_orders', $doId, ['status' => 'DRAFT'], ['status' => 'APPROVED'], null);

        return ['success' => true, 'do_id' => $doId];
    }

    public static function startPicking(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        self::assertStatus($do, 'APPROVED', 'moved to picking');

        $pdo->prepare('UPDATE distribution_orders SET status = \'PICKING\', picking_started_by = :by, picking_started_at = :now WHERE id = :id')
            ->execute(['by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $doId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_PICKING', 'distribution_orders', $doId, ['status' => 'APPROVED'], ['status' => 'PICKING'], null);

        return ['success' => true, 'do_id' => $doId];
    }

    /**
     * PICKING -> DISPATCHED. The ONLY step that moves real inventory: one
     * FifoService::postOut() call per line, warehouse = the DO's own
     * from_warehouse_id (already verified = SCM at create()),
     * bakery_destination_id = the DO's bakery, reference_no = the DO
     * number. Idempotent both at the DO level (dispatch_request_uuid) and
     * per-line (a deterministic "{do_number}:OUT:{item_id}:{line_id}"
     * transaction_uuid also hits FifoService::postOut()'s own idempotency
     * check) — so a retried/duplicate dispatch call can never double-post.
     */
    public static function dispatch(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        $requestUuid = $p['request_uuid'] ?? null;

        if ($do['status'] === 'DISPATCHED' || in_array($do['status'], ['RECEIVED', 'RECEIVED_WITH_DISCREPANCY', 'COMPLETED'], true)) {
            if ($requestUuid !== null && $requestUuid === $do['dispatch_request_uuid']) {
                return ['success' => true, 'idempotent_replay' => true, 'do_id' => $doId];
            }
            throw new ValidationException(["distribution order {$do['do_number']} has already been dispatched"]);
        }
        self::assertStatus($do, 'PICKING', 'dispatched');

        $lines = $pdo->prepare('SELECT * FROM distribution_order_lines WHERE do_id = :id ORDER BY line_no');
        $lines->execute(['id' => $doId]);
        $lines = $lines->fetchAll();

        foreach ($lines as $line) {
            $outResult = FifoService::postOut($pdo, [
                'transaction_uuid' => "{$do['do_number']}:OUT:{$line['id']}",
                'item_id' => (int) $line['item_id'],
                'warehouse_id' => (int) $do['from_warehouse_id'],
                'input_qty' => (float) $line['input_qty'],
                'input_unit_id' => (int) $line['input_unit_id'],
                'transaction_type' => 'OUT',
                'transaction_date' => $do['do_date'] . ' 00:00:00',
                'reference_no' => $do['do_number'],
                'bakery_destination_id' => (int) $do['bakery_destination_id'],
                'created_by' => $p['created_by'],
                'username' => $p['username'] ?? 'system',
                'allow_negative_stock' => $p['allow_negative_stock'] ?? false,
                'negative_stock_reason' => $p['negative_stock_reason'] ?? null,
            ]);

            $pdo->prepare('UPDATE distribution_order_lines SET qty_sent_base = :qty, out_transaction_line_id = :line_id WHERE id = :id')
                ->execute(['qty' => $outResult['base_qty'], 'line_id' => $outResult['line_id'], 'id' => $line['id']]);
        }

        $pdo->prepare(
            'UPDATE distribution_orders
             SET status = \'DISPATCHED\', dispatched_by = :by, dispatched_at = :now, dispatch_request_uuid = :req
             WHERE id = :id'
        )->execute(['by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'req' => $requestUuid, 'id' => $doId]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_DISPATCH',
            'distribution_orders', $doId, ['status' => 'PICKING'],
            ['status' => 'DISPATCHED', 'lines_dispatched' => count($lines)], null
        );

        return ['success' => true, 'do_id' => $doId, 'lines_dispatched' => count($lines)];
    }

    /**
     * DISPATCHED -> RECEIVED or RECEIVED_WITH_DISCREPANCY. Never touches
     * FIFO (Section 6) — records what the bakery actually counted, and the
     * per-line difference, as pure receiving data. A real stock/value
     * correction for a discrepancy is a separate, explicit follow-up
     * (Stock Adjustment / a future correction flow), never an automatic
     * silent mutation of the original dispatch.
     *
     * @param array{created_by:int, username?:string, lines:list<array{do_line_id:int, qty_received:float, discrepancy_reason?:?string, discrepancy_notes?:?string}>} $p
     */
    public static function receive(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        self::assertStatus($do, 'DISPATCHED', 'received');
        assert_required_fields($p, ['created_by', 'lines']);

        $existingLines = $pdo->prepare('SELECT * FROM distribution_order_lines WHERE do_id = :id ORDER BY line_no');
        $existingLines->execute(['id' => $doId]);
        $existingLines = array_column($existingLines->fetchAll(), null, 'id');

        $submitted = array_column($p['lines'], null, 'do_line_id');
        if (count($submitted) !== count($existingLines)) {
            throw new ValidationException(['every line on this delivery order must be receipted together']);
        }

        $hasDiscrepancy = false;
        $update = $pdo->prepare(
            'UPDATE distribution_order_lines
             SET qty_received_base = :received, difference_qty_base = :diff, discrepancy_reason = :reason, discrepancy_notes = :notes
             WHERE id = :id AND do_id = :do_id'
        );

        foreach ($existingLines as $lineId => $line) {
            if (!isset($submitted[$lineId])) {
                throw new ValidationException(["line {$lineId} does not belong to this delivery order"]);
            }
            $received = round((float) $submitted[$lineId]['qty_received'], 6);
            if ($received < 0) {
                throw new ValidationException(["line {$lineId}: qty_received cannot be negative"]);
            }
            $sent = (float) $line['qty_sent_base'];
            $diff = round($received - $sent, 6);
            $reason = $submitted[$lineId]['discrepancy_reason'] ?? null;

            if (abs($diff) > 0.000001) {
                $hasDiscrepancy = true;
                if ($reason === null || $reason === '') {
                    throw new ValidationException(["line {$lineId}: discrepancy_reason is required when qty_received differs from qty_sent"]);
                }
                self::assertValidDiscrepancyReason($reason);
            } else {
                $reason = null; // no discrepancy -> never persist a stray reason
            }

            $update->execute([
                'received' => $received, 'diff' => $diff, 'reason' => $reason,
                'notes' => $submitted[$lineId]['discrepancy_notes'] ?? null,
                'id' => $lineId, 'do_id' => $doId,
            ]);
        }

        $newStatus = $hasDiscrepancy ? 'RECEIVED_WITH_DISCREPANCY' : 'RECEIVED';
        $pdo->prepare('UPDATE distribution_orders SET status = :status, received_by = :by, received_at = :now WHERE id = :id')
            ->execute(['status' => $newStatus, 'by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $doId]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_RECEIVE',
            'distribution_orders', $doId, ['status' => 'DISPATCHED'], ['status' => $newStatus], null
        );

        return ['success' => true, 'do_id' => $doId, 'status' => $newStatus];
    }

    public static function complete(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        if (!in_array($do['status'], ['RECEIVED', 'RECEIVED_WITH_DISCREPANCY'], true)) {
            throw new ValidationException(["distribution order is {$do['status']}, must be RECEIVED before it can be completed"]);
        }

        $pdo->prepare('UPDATE distribution_orders SET status = \'COMPLETED\', completed_by = :by, completed_at = :now WHERE id = :id')
            ->execute(['by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $doId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_COMPLETE', 'distribution_orders', $doId, ['status' => $do['status']], ['status' => 'COMPLETED'], null);

        return ['success' => true, 'do_id' => $doId];
    }

    /** Pre-DISPATCHED cancellation — pure status flip, stock was never touched (Section 23). */
    public static function cancel(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        if (!in_array($do['status'], ['DRAFT', 'APPROVED', 'PICKING'], true)) {
            throw new ValidationException(["distribution order is {$do['status']} — use reverse() for a dispatched order, never cancel()"]);
        }
        $reason = trim((string) ($p['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['a cancellation reason of at least 5 characters is required']);
        }

        $pdo->prepare('UPDATE distribution_orders SET status = \'CANCELLED\', cancel_reason = :reason, cancelled_by = :by, cancelled_at = :now WHERE id = :id')
            ->execute(['reason' => $reason, 'by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $doId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_CANCEL', 'distribution_orders', $doId, ['status' => $do['status']], ['status' => 'CANCELLED'], $reason);

        return ['success' => true, 'do_id' => $doId];
    }

    /**
     * Post-DISPATCHED correction — restores every consumed FIFO layer
     * exactly as TransferService::cancel() restores a transfer's OUT side
     * (relative `qty_base = qty_base + allocated`, so it stays correct
     * even if the batch was already further touched by something else
     * meanwhile), flips each posted OUT transaction to REVERSED, and sets
     * the DO itself to CANCELLED. A distribution never creates a
     * destination batch (the bakery is not a warehouse in this system), so
     * — unlike TransferService::reverse() — there is no destination-side
     * dependency to protect against.
     */
    public static function reverse(PDO $pdo, int $doId, array $p): array
    {
        $do = self::find($pdo, $doId);
        $requestUuid = $p['request_uuid'] ?? null;

        if ($do['status'] === 'CANCELLED') {
            if ($requestUuid !== null && $requestUuid === $do['reverse_request_uuid']) {
                return ['success' => true, 'idempotent_replay' => true, 'do_id' => $doId];
            }
            throw new ValidationException(["distribution order {$do['do_number']} is already cancelled/reversed"]);
        }
        if (!in_array($do['status'], ['DISPATCHED', 'RECEIVED', 'RECEIVED_WITH_DISCREPANCY'], true)) {
            throw new ValidationException(["distribution order is {$do['status']} — use cancel() before dispatch, reverse() only applies after"]);
        }
        $reason = trim((string) ($p['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['a reversal reason of at least 5 characters is required']);
        }

        $lines = $pdo->prepare('SELECT * FROM distribution_order_lines WHERE do_id = :id AND out_transaction_line_id IS NOT NULL');
        $lines->execute(['id' => $doId]);
        $lines = $lines->fetchAll();

        $txIds = [];
        foreach ($lines as $line) {
            $restore = $pdo->prepare(
                'UPDATE inventory_batches b
                 JOIN fifo_allocations fa ON fa.batch_id = b.id
                 SET b.qty_base = b.qty_base + fa.qty_allocated
                 WHERE fa.transaction_line_id = :out_line_id'
            );
            $restore->execute(['out_line_id' => $line['out_transaction_line_id']]);

            $txRow = $pdo->prepare('SELECT transaction_id FROM inventory_transaction_lines WHERE id = :id');
            $txRow->execute(['id' => $line['out_transaction_line_id']]);
            $txIds[] = (int) $txRow->fetchColumn();
        }
        $txIds = array_values(array_unique($txIds));

        foreach ($txIds as $txId) {
            $pdo->prepare("UPDATE inventory_transactions SET status = 'REVERSED' WHERE id = :id")->execute(['id' => $txId]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE distribution_orders
             SET status = \'CANCELLED\', cancel_reason = :reason, cancelled_by = :by, cancelled_at = :now, reverse_request_uuid = :req
             WHERE id = :id'
        )->execute(['reason' => $reason, 'by' => $p['created_by'], 'now' => $now, 'req' => $requestUuid, 'id' => $doId]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_ORDER_REVERSE',
            'distribution_orders', $doId, ['status' => $do['status']],
            ['status' => 'CANCELLED', 'reversed_transaction_ids' => $txIds], $reason
        );

        return ['success' => true, 'do_id' => $doId, 'reversed_transaction_ids' => $txIds];
    }

    public static function get(PDO $pdo, int $doId): array
    {
        $do = self::find($pdo, $doId);
        $lines = $pdo->prepare('SELECT * FROM distribution_order_lines WHERE do_id = :id ORDER BY line_no');
        $lines->execute(['id' => $doId]);
        $do['lines'] = $lines->fetchAll();
        return $do;
    }

    public static function listAll(PDO $pdo, array $filters = []): array
    {
        $sql = 'SELECT do.*, bd.name AS bakery_name, w.name AS from_warehouse_name
                FROM distribution_orders do
                JOIN bakery_destinations bd ON bd.id = do.bakery_destination_id
                JOIN warehouses w ON w.id = do.from_warehouse_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND do.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['bakery_destination_id'])) {
            $sql .= ' AND do.bakery_destination_id = :bakery';
            $params['bakery'] = (int) $filters['bakery_destination_id'];
        }
        $sql .= ' ORDER BY do.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function assertIsScmWarehouse(PDO $pdo, int $warehouseId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM warehouses WHERE id = :id AND code = :code AND is_active = 1');
        $stmt->execute(['id' => $warehouseId, 'code' => self::SCM_WAREHOUSE_CODE]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationException(['distribution orders may only originate from the SCM warehouse (code=' . self::SCM_WAREHOUSE_CODE . ')']);
        }
    }

    private static function findActiveBakery(PDO $pdo, int $bakeryId): array
    {
        $stmt = $pdo->prepare('SELECT id, name, address FROM bakery_destinations WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $bakeryId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(["bakery destination {$bakeryId} is unknown or not active"]);
        }
        return $row;
    }

    private static function findActiveItem(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare('SELECT id, sku, name, category_id, status FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("item {$itemId}");
        }
        if ($row['status'] !== 'ACTIVE') {
            throw new ValidationException(["item {$row['sku']} is not active"]);
        }
        return $row;
    }

    private static function assertValidDiscrepancyReason(string $reason): void
    {
        if (!in_array($reason, ['KURANG', 'RUSAK', 'REJECT', 'SALAH_BARANG', 'LAINNYA'], true)) {
            throw new ValidationException(["invalid discrepancy_reason: {$reason}"]);
        }
    }

    private static function assertStatus(array $do, string $expected, string $actionLabel): void
    {
        if ($do['status'] !== $expected) {
            throw new ValidationException(["distribution order {$do['do_number']} is {$do['status']}, expected {$expected} before it can be {$actionLabel}"]);
        }
    }

    private static function find(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM distribution_orders WHERE id = :id');
        $stmt->execute(['id' => $doId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("distribution order {$doId}");
        }
        return $row;
    }
}
