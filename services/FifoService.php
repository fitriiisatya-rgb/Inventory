<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Section 7/8/10: FIFO costing engine. Every public method here must be
 * called from inside Database::transaction() — none of them commit on
 * their own, so a caller composing IN+OUT (e.g. a transfer, or production)
 * can wrap both in one atomic unit.
 */
final class FifoService
{
    private const QTY_SCALE = 6;
    private const MONEY_SCALE = 4;

    /**
     * Records incoming stock: creates one new FIFO batch and one IN
     * transaction line. Returns the created transaction id, or the
     * previously-created one if $transactionUuid was already posted
     * (Section 12 idempotency).
     */
    /**
     * @param bool $bypassInactiveWarehouseGuard PHASE V2.14 — a genuine
     *   typed parameter, never an array key: every route in this codebase
     *   that reaches postIn() from a raw HTTP request forwards its $input
     *   array close to verbatim (e.g. POST /transactions/in), so any
     *   bypass keyed off $p[...] would be directly settable by an
     *   authenticated client's own JSON body — unlike bypass_warehouse_lock
     *   above (a narrower, lower-stakes concern), this guard is the one
     *   thing standing between "warehouse not yet live" and a real posted
     *   transaction, so it must be structurally unreachable from request
     *   input. Only WarehouseCutoverService::loadOpening() ever passes
     *   true here, and only after independently re-verifying the target
     *   warehouse is_active=0 AND activation_locked=1 immediately before
     *   the call — i.e. this bypass only ever fires for a warehouse that
     *   has already been confirmed not-yet-live, loading a human-approved
     *   opening balance into it, never a warehouse anyone could still
     *   mistake for a normal operational target.
     */
    public static function postIn(PDO $pdo, array $p, bool $bypassInactiveWarehouseGuard = false): array
    {
        assert_required_fields($p, ['transaction_uuid', 'item_id', 'warehouse_id', 'input_qty', 'input_unit_id', 'unit_price_input', 'transaction_date', 'created_by']);

        $existing = IdempotencyService::findTransaction($pdo, $p['transaction_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'transaction_id' => (int) $existing['id']];
        }

        // Callers sometimes forward a value straight out of a DB fetch (e.g. TransferService,
        // ProductionService) — MySQL/PDO returns DECIMAL columns as PHP strings, which strict_types
        // would otherwise reject at the first typed (int/float) parameter downstream.
        $p = self::normalizeNumeric($p, ['item_id', 'warehouse_id', 'input_unit_id', 'supplier_id', 'division_id', 'anomaly_approved_by'], ['input_qty', 'unit_price_input']);

        PeriodLockService::assertNotLocked($pdo, $p['transaction_date']);
        if (empty($p['bypass_warehouse_lock'])) {
            WarehouseLockService::assertNotLocked($pdo, $p['warehouse_id']);
        }
        // PHASE V2.13: unconditional for every ordinary caller, regardless
        // of bypass_warehouse_lock (which only ever concerns the opname
        // lock above, a different concern) — an inactive warehouse (e.g.
        // Karang Tengah before go-live) can never be a stock-mutating
        // source or destination. PHASE V2.14: the one narrow, structurally
        // request-unreachable exception is $bypassInactiveWarehouseGuard
        // (see this method's own docblock) for a controlled cutover
        // opening load.
        if (!$bypassInactiveWarehouseGuard) {
            WarehouseGuardService::assertActive($pdo, $p['warehouse_id']);
        }

        // A real purchase must have price > 0 (Section 9). The one documented exception is a
        // reviewed, explicitly-flagged zero-cost Opening Stock line (ImportOpeningStockService) —
        // never a silent default, and never available to a normal Transaksi Masuk.
        $priceFloor = !empty($p['allow_zero_price']) ? 0 : 0.0000001;
        // POLICY CORRECTION: an explicitly-flagged, owner-approved migration-
        // negative Opening line (ImportOpeningStockService, only ever set
        // after OpeningValidationService + MigrationNegativeStockService both
        // confirm this item+warehouse is on the whitelist) is the one place a
        // negative input_qty is allowed to post — never for a normal
        // Transaksi Masuk. The resulting batch is created as a real negative
        // FIFO layer below, so FifoService::postOut's migration-negative
        // guard picks it up on the very next consumption attempt.
        $allowMigrationNegativeOpening = !empty($p['allow_migration_negative_opening']);
        if ($allowMigrationNegativeOpening) {
            if ($p['input_qty'] == 0 || $p['unit_price_input'] < $priceFloor) {
                throw new ValidationException(['input_qty must not be zero, and unit_price_input must be >= floor']);
            }
        } elseif (!($p['input_qty'] > 0) || $p['unit_price_input'] < $priceFloor) {
            throw new ValidationException(['input_qty and unit_price_input must both be > 0']);
        }

        $conversion = UnitConversionService::getActiveConversion($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($conversion === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }
        $factor = (float) $conversion['conversion_to_base'];

        $baseQty = round($p['input_qty'] * $factor, self::QTY_SCALE);
        $unitCostBase = round($p['unit_price_input'] / $factor, self::MONEY_SCALE);
        $subtotal = round($p['input_qty'] * $p['unit_price_input'], self::MONEY_SCALE);

        $anomalyCfg = require dirname(__DIR__) . '/config/config.php';
        $anomaly = PriceAnomalyService::evaluate(
            $pdo,
            $p['item_id'],
            $unitCostBase,
            $anomalyCfg['inventory']['price_anomaly_high_multiplier'],
            $anomalyCfg['inventory']['price_anomaly_low_multiplier']
        );

        if ($anomaly['is_anomaly'] && empty($p['anomaly_approved_by'])) {
            throw new PriceAnomalyException((float) $anomaly['ratio'], (float) $anomaly['reference']);
        }

        $txStmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, supplier_id,
                 division_id, reference_no, status, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, :type, :tx_date, :post_date, :wh, :supplier, :division, :ref, :status_posted, 0, 1, :created_by, :created_at)'
        );
        $now = date('Y-m-d H:i:s');
        $txStmt->execute([
            'uuid' => $p['transaction_uuid'], 'type' => $p['transaction_type'] ?? 'IN', 'tx_date' => $p['transaction_date'],
            'post_date' => $now, 'wh' => $p['warehouse_id'], 'supplier' => $p['supplier_id'] ?? null,
            'division' => $p['division_id'] ?? null, 'ref' => $p['reference_no'] ?? null,
            'status_posted' => 'POSTED', 'created_by' => $p['created_by'], 'created_at' => $now,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $itemName = self::itemName($pdo, $p['item_id']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id,
                 is_price_anomaly, price_anomaly_ratio, anomaly_approved_by, anomaly_reason)
             VALUES (:tx_id, 1, :item_id, :item_name, :input_qty, :input_unit,
                     :factor, :base_qty, :price, :cost_base, :subtotal, :wh,
                     :is_anomaly, :ratio, :approved_by, :anomaly_reason)'
        );
        $lineStmt->execute([
            'tx_id' => $transactionId, 'item_id' => $p['item_id'], 'item_name' => $itemName,
            'input_qty' => $p['input_qty'], 'input_unit' => $p['input_unit_id'], 'factor' => $factor,
            'base_qty' => $baseQty, 'price' => $p['unit_price_input'], 'cost_base' => $unitCostBase,
            'subtotal' => $subtotal, 'wh' => $p['warehouse_id'],
            'is_anomaly' => $anomaly['is_anomaly'] ? 1 : 0, 'ratio' => $anomaly['ratio'],
            'approved_by' => $p['anomaly_approved_by'] ?? null, 'anomaly_reason' => $p['anomaly_reason'] ?? null,
        ]);
        $lineId = (int) $pdo->lastInsertId();

        $batchStmt = $pdo->prepare(
            'INSERT INTO inventory_batches
                (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date, expiry_date,
                 supplier_id, source_transaction_line_id, is_negative_layer, created_at)
             VALUES (:item_id, :wh, :qty, :qty2, :cost_base, :received_date, :expiry, :supplier, :line_id, :is_negative, :created_at)'
        );
        $batchStmt->execute([
            'item_id' => $p['item_id'], 'wh' => $p['warehouse_id'], 'qty' => $baseQty, 'qty2' => $baseQty,
            'is_negative' => $baseQty < 0 ? 1 : 0,
            'cost_base' => $unitCostBase, 'received_date' => $p['transaction_date'],
            'expiry' => $p['expiry_date'] ?? null, 'supplier' => $p['supplier_id'] ?? null,
            'line_id' => $lineId, 'created_at' => $now,
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE inventory_transaction_lines SET created_batch_id = :batch_id WHERE id = :id')
            ->execute(['batch_id' => $batchId, 'id' => $lineId]);

        PriceAnomalyService::recordPrice(
            $pdo, $p['item_id'], $p['supplier_id'] ?? null, $p['input_unit_id'],
            $p['unit_price_input'], $unitCostBase, $p['transaction_date'], $lineId
        );

        UnitConversionService::lockItemIfNeeded($pdo, $p['item_id']);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSACTION_IN_POST',
            'inventory_transactions', $transactionId, null,
            ['base_qty' => $baseQty, 'unit_cost_base' => $unitCostBase, 'batch_id' => $batchId],
            null
        );

        return [
            'success' => true, 'transaction_id' => $transactionId, 'line_id' => $lineId,
            'batch_id' => $batchId, 'base_qty' => $baseQty, 'unit_cost_base' => $unitCostBase,
        ];
    }

    /**
     * Consumes stock FIFO-first. Throws InsufficientStockException unless
     * $p['allow_negative_stock'] is explicitly true AND $p['negative_stock_reason']
     * is given (Section 8 — the controller is responsible for checking the
     * caller actually holds STOCK_ALLOW_NEGATIVE before ever setting that flag).
     */
    public static function postOut(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['transaction_uuid', 'item_id', 'warehouse_id', 'input_qty', 'input_unit_id', 'transaction_date', 'created_by']);

        $existing = IdempotencyService::findTransaction($pdo, $p['transaction_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'transaction_id' => (int) $existing['id']];
        }

        $p = self::normalizeNumeric($p, ['item_id', 'warehouse_id', 'input_unit_id', 'division_id'], ['input_qty']);

        PeriodLockService::assertNotLocked($pdo, $p['transaction_date']);
        if (empty($p['bypass_warehouse_lock'])) {
            WarehouseLockService::assertNotLocked($pdo, $p['warehouse_id']);
        }
        // PHASE V2.13: unconditional, never bypassed — see postIn() above.
        WarehouseGuardService::assertActive($pdo, $p['warehouse_id']);

        if (!($p['input_qty'] > 0)) {
            throw new ValidationException(['input_qty must be > 0']);
        }

        $conversion = UnitConversionService::getActiveConversion($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($conversion === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }
        $factor = (float) $conversion['conversion_to_base'];
        $baseQtyRequested = round($p['input_qty'] * $factor, self::QTY_SCALE);

        $batches = Database::lockFifoBatches($pdo, $p['item_id'], $p['warehouse_id']);
        $available = round(array_sum(array_column($batches, 'qty_base')), self::QTY_SCALE);

        // POLICY CORRECTION: a whitelisted migration-negative item+warehouse
        // whose TRUE NET balance (every batch, including the negative
        // layer — NOT $available above, which is deliberately only the
        // positive/consumable batches) is still <= 0 has OUT/TRANSFER_OUT/
        // PRODUCTION_IN blocked outright here, even if the caller set
        // allow_negative_stock, and no further negative batch is ever
        // created for it. Checking $available alone would wrongly let this
        // through the moment ANY positive batch exists (e.g. a partial IN)
        // even while the item is still net negative overall. The only way
        // past this is a real Stock Opname/Stock Adjustment (posted through
        // StockAdjustmentService, never through here) that brings the net
        // balance back above zero.
        if (MigrationNegativeStockService::isWhitelisted($pdo, (int) $p['item_id'], (int) $p['warehouse_id'])) {
            $netBalanceStmt = $pdo->prepare('SELECT COALESCE(SUM(qty_base), 0) FROM inventory_batches WHERE item_id = :item_id AND warehouse_id = :wh');
            $netBalanceStmt->execute(['item_id' => $p['item_id'], 'wh' => $p['warehouse_id']]);
            $netBalance = round((float) $netBalanceStmt->fetchColumn(), self::QTY_SCALE);
            if ($netBalance <= 0) {
                throw new NegativeMigrationStockRequiresAdjustmentException((int) $p['item_id'], (int) $p['warehouse_id'], $netBalance);
            }
        }

        $allowNegative = !empty($p['allow_negative_stock']);
        if ($baseQtyRequested > $available && !$allowNegative) {
            throw new InsufficientStockException($baseQtyRequested, $available);
        }

        $now = date('Y-m-d H:i:s');
        $txType = $p['transaction_type'] ?? 'OUT';

        // PHASE V2: bakery_destination_id is only ever persisted for a real
        // OUT transaction (matches the DB-level chk_tx_bakery_destination_out_only
        // CHECK constraint — see docs/PHASE_V2_SCHEMA_IMPACT.md Section 3).
        // TRANSFER_OUT/PRODUCTION_IN also flow through this method but must
        // never carry it, so this is enforced here at the service layer too,
        // not left to rely on the DB constraint alone.
        $bakeryDestinationId = $txType === 'OUT' ? ($p['bakery_destination_id'] ?? null) : null;

        $txStmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, supplier_id,
                 division_id, bakery_destination_id, reference_no, status, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, :type, :tx_date, :post_date, :wh, NULL, :division, :bakery_destination, :ref, :status_posted, 0, 1, :created_by, :created_at)'
        );
        $txStmt->execute([
            'uuid' => $p['transaction_uuid'], 'type' => $txType, 'tx_date' => $p['transaction_date'],
            'post_date' => $now, 'wh' => $p['warehouse_id'], 'division' => $p['division_id'] ?? null,
            'bakery_destination' => $bakeryDestinationId,
            'ref' => $p['reference_no'] ?? null, 'status_posted' => 'POSTED',
            'created_by' => $p['created_by'], 'created_at' => $now,
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $itemName = self::itemName($pdo, $p['item_id']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id,
                 allow_negative_stock, negative_stock_reason, negative_stock_approved_by, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :input_qty, :input_unit,
                     :factor, :base_qty, 0, 0, 0, :wh,
                     :allow_neg, :neg_reason, :neg_approved_by, :notes)'
        );
        $lineStmt->execute([
            'tx_id' => $transactionId, 'item_id' => $p['item_id'], 'item_name' => $itemName,
            'input_qty' => $p['input_qty'], 'input_unit' => $p['input_unit_id'], 'factor' => $factor,
            'base_qty' => $baseQtyRequested, 'wh' => $p['warehouse_id'],
            'allow_neg' => $allowNegative ? 1 : 0, 'neg_reason' => $p['negative_stock_reason'] ?? null,
            'neg_approved_by' => $p['negative_stock_approved_by'] ?? null, 'notes' => $p['notes'] ?? null,
        ]);
        $lineId = (int) $pdo->lastInsertId();

        $remaining = $baseQtyRequested;
        $totalCost = 0.0;
        $allocStmt = $pdo->prepare(
            'INSERT INTO fifo_allocations (transaction_line_id, batch_id, qty_allocated, unit_cost_base, subtotal, created_at)
             VALUES (:line_id, :batch_id, :qty, :cost_base, :subtotal, :created_at)'
        );
        $updateBatchStmt = $pdo->prepare('UPDATE inventory_batches SET qty_base = :qty WHERE id = :id');

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $batch['qty_base'], $remaining);
            $take = round($take, self::QTY_SCALE);
            if ($take <= 0) {
                continue;
            }
            $costBase = (float) $batch['unit_cost_base'];
            $subtotal = round($take * $costBase, self::MONEY_SCALE);
            $totalCost += $subtotal;

            $allocStmt->execute([
                'line_id' => $lineId, 'batch_id' => $batch['id'], 'qty' => $take,
                'cost_base' => $costBase, 'subtotal' => $subtotal, 'created_at' => $now,
            ]);
            $updateBatchStmt->execute(['qty' => round((float) $batch['qty_base'] - $take, self::QTY_SCALE), 'id' => $batch['id']]);

            $remaining = round($remaining - $take, self::QTY_SCALE);
        }

        if ($remaining > 0) {
            // Section 8: negative-stock override — the shortfall becomes an
            // explicit negative layer at the *last known* cost (or 0, flagged
            // costUnknown) rather than silently averaging into other batches.
            $lastCostStmt = $pdo->prepare(
                'SELECT unit_cost_base FROM inventory_batches WHERE item_id = :item_id ORDER BY received_date DESC, id DESC LIMIT 1'
            );
            $lastCostStmt->execute(['item_id' => $p['item_id']]);
            $lastCost = $lastCostStmt->fetchColumn();
            $lastCost = $lastCost !== false ? (float) $lastCost : 0.0;

            $negBatchStmt = $pdo->prepare(
                'INSERT INTO inventory_batches
                    (item_id, warehouse_id, qty_base, original_qty_base, unit_cost_base, received_date,
                     source_transaction_line_id, is_negative_layer, created_at)
                 VALUES (:item_id, :wh, :qty, :qty2, :cost_base, :received_date, :line_id, 1, :created_at)'
            );
            $negBatchStmt->execute([
                'item_id' => $p['item_id'], 'wh' => $p['warehouse_id'], 'qty' => -$remaining, 'qty2' => -$remaining,
                'cost_base' => $lastCost, 'received_date' => $p['transaction_date'], 'line_id' => $lineId, 'created_at' => $now,
            ]);
            $negBatchId = (int) $pdo->lastInsertId();

            $subtotal = round($remaining * $lastCost, self::MONEY_SCALE);
            $totalCost += $subtotal;
            $allocStmt->execute([
                'line_id' => $lineId, 'batch_id' => $negBatchId, 'qty' => $remaining,
                'cost_base' => $lastCost, 'subtotal' => $subtotal, 'created_at' => $now,
            ]);

            AuditService::log(
                $pdo, $p['created_by'], $p['username'] ?? 'system', 'STOCK_ADJUSTMENT',
                'inventory_batches', $negBatchId, ['available' => $available], ['deficit' => $remaining],
                $p['negative_stock_reason'] ?? 'negative stock override (no reason supplied)'
            );
        }

        $unitCostBaseAvg = $baseQtyRequested > 0 ? round($totalCost / $baseQtyRequested, self::MONEY_SCALE) : 0.0;
        $pdo->prepare('UPDATE inventory_transaction_lines SET unit_cost_base = :cost, subtotal = :subtotal WHERE id = :id')
            ->execute(['cost' => $unitCostBaseAvg, 'subtotal' => round($totalCost, self::MONEY_SCALE), 'id' => $lineId]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'TRANSACTION_OUT_POST',
            'inventory_transactions', $transactionId, null,
            ['base_qty' => $baseQtyRequested, 'unit_cost_base' => $unitCostBaseAvg, 'total_cost' => $totalCost],
            null
        );

        return [
            'success' => true, 'transaction_id' => $transactionId, 'line_id' => $lineId,
            'base_qty' => $baseQtyRequested, 'unit_cost_base' => $unitCostBaseAvg, 'total_cost' => round($totalCost, self::MONEY_SCALE),
        ];
    }

    /** @deprecated kept for call-site compatibility; delegates to the actual source of truth. */
    public static function currentStock(PDO $pdo, int $itemId, int $warehouseId): array
    {
        return InventoryService::currentStock($pdo, $itemId, $warehouseId);
    }

    private static function itemName(PDO $pdo, int $itemId): string
    {
        $stmt = $pdo->prepare('SELECT name FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @param string[] $intKeys @param string[] $floatKeys */
    private static function normalizeNumeric(array $p, array $intKeys, array $floatKeys): array
    {
        foreach ($intKeys as $key) {
            if (isset($p[$key])) {
                $p[$key] = (int) $p[$key];
            }
        }
        foreach ($floatKeys as $key) {
            if (isset($p[$key])) {
                $p[$key] = (float) $p[$key];
            }
        }
        return $p;
    }
}
