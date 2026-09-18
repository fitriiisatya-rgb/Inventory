<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE G-DATA 2 — validation rules for final_opening_stock_template.xlsx
 * rows, on top of ImportOpeningStockService's existing per-row checks.
 *
 * Central policy (Section 1 of the phase spec): a LOW-confidence or
 * missing PURCHASE-unit conversion never blocks an opening row — opening
 * is always supplied in the item's Global Base Unit with a cost per that
 * base unit, so no purchase-unit conversion is needed to post it at all.
 * "no approved purchase conversion" is therefore only ever a WARNING
 * (informational), never an ERROR.
 */
final class OpeningValidationService
{
    private const LARGE_QTY_THRESHOLD = 1_000_000.0;
    private const PRICE_ANOMALY_HIGH = 5.0;
    private const PRICE_ANOMALY_LOW = 0.2;

    /**
     * Validates one row. Returns ['status' => VALID|WARNING|ERROR,
     * 'messages' => string[], 'item_id' => ?int, 'warehouse_id' => ?int].
     * Never throws — every outcome is a row_status + messages, so a whole
     * file can be staged and reviewed even with some ERROR rows in it
     * (ImportOpeningStockService::commit refuses to commit while any
     * ERROR row remains, per the existing Section 14 contract).
     */
    public static function validateRow(PDO $pdo, array $row): array
    {
        $messages = [];

        // ---- Warehouse ----
        $whCode = trim((string) ($row['warehouse_code'] ?? ''));
        $whStmt = $pdo->prepare('SELECT id, code FROM warehouses WHERE code = :code');
        $whStmt->execute(['code' => $whCode]);
        $warehouse = $whStmt->fetch();
        if (!$warehouse) {
            return ['status' => 'ERROR', 'messages' => ["unknown warehouse_code: {$whCode}"], 'item_id' => null, 'warehouse_id' => null];
        }
        $warehouseId = (int) $warehouse['id'];

        // ---- SKU / item identity ----
        $sku = trim((string) ($row['sku'] ?? ''));
        $itemStmt = $pdo->prepare('SELECT id, sku, name, category, base_unit_id, status FROM items WHERE sku = :sku');
        $itemStmt->execute(['sku' => $sku]);
        $item = $itemStmt->fetch();
        if (!$item) {
            return ['status' => 'ERROR', 'messages' => ["unknown SKU: {$sku}"], 'item_id' => null, 'warehouse_id' => $warehouseId];
        }
        $itemId = (int) $item['id'];

        if (($item['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            $messages[] = "item {$sku} is not ACTIVE — verify this identity before posting opening stock";
        }

        // ---- Global Base Unit cross-check (reference only, but a real
        // mismatch means the template was built against the wrong item
        // identity and must not be silently accepted) ----
        $templateBaseUnit = strtoupper(trim((string) ($row['global_base_unit'] ?? '')));
        if ($templateBaseUnit !== '') {
            $unitStmt = $pdo->prepare('SELECT code FROM units WHERE id = :id');
            $unitStmt->execute(['id' => $item['base_unit_id']]);
            $actualBaseUnit = strtoupper((string) $unitStmt->fetchColumn());
            if ($actualBaseUnit !== '' && $actualBaseUnit !== $templateBaseUnit) {
                return [
                    'status' => 'ERROR',
                    'messages' => ["Global Base Unit mismatch for {$sku}: template says {$templateBaseUnit}, item master says {$actualBaseUnit}"],
                    'item_id' => $itemId, 'warehouse_id' => $warehouseId,
                ];
            }
        }

        // ---- Quantity ----
        $qtyRaw = $row['opening_qty_base'] ?? $row['quantity_base'] ?? '';
        if ($qtyRaw === '' || !is_numeric($qtyRaw)) {
            return ['status' => 'ERROR', 'messages' => ['Opening Qty Base is required and must be numeric'], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
        }
        $qty = (float) $qtyRaw;
        if ($qty < 0) {
            // POLICY CORRECTION: a negative opening quantity is rejected for
            // every item EXCEPT the owner-approved migration-negative
            // whitelist (MigrationNegativeStockService) — those 5 known
            // rows are allowed to carry their actual calculated balance
            // forward as-is. Falls through to the remaining checks below
            // (cost/expiry/etc.) rather than returning early.
            if (!MigrationNegativeStockService::isWhitelisted($pdo, $itemId, $warehouseId)) {
                return ['status' => 'ERROR', 'messages' => ['negative opening quantity is not allowed — final opening rejects negative stock, no exceptions'], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
            }
            $messages[] = 'MIGRATION_NEGATIVE_REVIEW: negative opening quantity accepted — this item+warehouse is on the owner-approved migration-negative whitelist; NEEDS_STOCK_OPNAME to resolve via an audited Stock Opname/Stock Adjustment';
        }

        // ---- Cost ----
        $costRaw = $row['unit_cost_base'] ?? '';
        if ($costRaw !== '' && !is_numeric($costRaw)) {
            return ['status' => 'ERROR', 'messages' => ['Unit Cost Base must be numeric'], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
        }
        $cost = $costRaw === '' ? 0.0 : (float) $costRaw;
        if ($cost < 0) {
            return ['status' => 'ERROR', 'messages' => ['Unit Cost Base must be >= 0'], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
        }
        if ($qty > 0 && $cost <= 0) {
            return ['status' => 'ERROR', 'messages' => ['COST_REQUIRED: positive quantity requires a positive Unit Cost Base'], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
        }

        // ---- Expiry date ----
        $expiryRaw = trim((string) ($row['expiry_date'] ?? $row['expired_date'] ?? ''));
        if ($expiryRaw !== '') {
            $expiry = \DateTime::createFromFormat('Y-m-d', $expiryRaw);
            if (!$expiry || $expiry->format('Y-m-d') !== $expiryRaw) {
                return ['status' => 'ERROR', 'messages' => ["invalid Expiry Date (expected YYYY-MM-DD): {$expiryRaw}"], 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
            }
        }

        // ---- Warnings (never block) ----
        if ($qty === 0.0) {
            $messages[] = 'zero opening quantity — row will not create a FIFO batch';
        }
        if ($qty > self::LARGE_QTY_THRESHOLD) {
            $messages[] = sprintf('very large opening quantity (%.2f) — please verify', $qty);
        }
        if (empty($item['category'])) {
            $messages[] = 'NEEDS_CATEGORY: item has no category set';
        }
        if ($cost > 0) {
            $anomaly = PriceAnomalyService::evaluate($pdo, $itemId, $cost, self::PRICE_ANOMALY_HIGH, self::PRICE_ANOMALY_LOW);
            if ($anomaly['is_anomaly']) {
                $messages[] = sprintf(
                    'unit cost Rp%.4f differs materially from the trusted reference Rp%.4f (ratio %.2fx)',
                    $cost, $anomaly['reference'], $anomaly['ratio']
                );
            }
        }
        $purchaseConv = $pdo->prepare(
            "SELECT COUNT(*) FROM item_unit_conversions
             WHERE item_id = :item_id AND unit_id <> :base_unit_id AND valid_to IS NULL"
        );
        $purchaseConv->execute(['item_id' => $itemId, 'base_unit_id' => $item['base_unit_id']]);
        if ((int) $purchaseConv->fetchColumn() === 0) {
            // Per Section 1: this NEVER blocks opening — base-unit qty/cost
            // are already sufficient. Informational only.
            $messages[] = 'no approved purchase conversion for this item — opening accepted in Global Base Unit; CARTON/PACK/etc. transactions will be rejected until a conversion is approved';
        }

        return ['status' => empty($messages) ? 'VALID' : 'WARNING', 'messages' => $messages, 'item_id' => $itemId, 'warehouse_id' => $warehouseId];
    }

    /**
     * Batch-level check (Section 13): all rows in one final-opening upload
     * should share ONE cutoff date across all three warehouses. Returns an
     * error string if more than one distinct Cutoff Date is present, or
     * null if consistent (or the column is absent/blank, e.g. a legacy CSV
     * upload that only sets cutoff at the header/file level).
     */
    public static function checkCutoffConsistency(array $rows): ?string
    {
        $dates = [];
        foreach ($rows as $row) {
            $d = trim((string) ($row['cutoff_date'] ?? ''));
            if ($d !== '') {
                $dates[$d] = true;
            }
        }
        if (count($dates) > 1) {
            return 'cutoff date differs across rows (' . implode(', ', array_keys($dates)) .
                ') — all warehouses must share one cutoff date; reconcile before import';
        }
        return null;
    }
}
