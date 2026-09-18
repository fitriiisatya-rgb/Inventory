<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2 3d — per-item-per-warehouse minimum/buffer stock policy, and the
 * single-source-of-truth stock-status calculation
 * (docs/PHASE_V2_TECHNICAL_DESIGN.md Section 4/10, owner's Phase 2 approval
 * conditions). Mirrors InventoryService's own "one place computes this"
 * convention — no other service/endpoint should resolve a policy or
 * compute a status independently.
 *
 * Resolution: an item_warehouse_stock_policy row (is_active=1) for the
 * exact item+warehouse if one exists, else items.minimum_stock as the
 * fallback with buffer left unconfigured. Never invents a buffer value.
 */
final class StockPolicyService
{
    public const STATUS_REVIEW = 'MIGRATION_NEGATIVE_REVIEW';
    public const STATUS_OUT_OF_STOCK = 'OUT_OF_STOCK';
    public const STATUS_CRITICAL = 'CRITICAL';
    public const STATUS_LOW = 'LOW';
    public const STATUS_SAFE = 'SAFE';

    /**
     * @return array{minimum_stock: float, buffer_stock: ?float, buffer_configured: bool, source: string, policy_id: ?int}
     */
    public static function resolve(PDO $pdo, int $itemId, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, minimum_stock_base, buffer_stock_base
             FROM item_warehouse_stock_policy
             WHERE item_id = :item_id AND warehouse_id = :wh AND is_active = 1'
        );
        $stmt->execute(['item_id' => $itemId, 'wh' => $warehouseId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $buffer = $row['buffer_stock_base'];
            return [
                'minimum_stock' => round((float) $row['minimum_stock_base'], 6),
                'buffer_stock' => $buffer === null ? null : round((float) $buffer, 6),
                'buffer_configured' => $buffer !== null,
                'source' => 'policy',
                'policy_id' => (int) $row['id'],
            ];
        }

        // Fallback: no per-warehouse policy row — use the item's global
        // minimum_stock (never repurposed, kept exactly as it always was),
        // buffer stays unconfigured. Never invented.
        $fallback = $pdo->prepare('SELECT minimum_stock FROM items WHERE id = :id');
        $fallback->execute(['id' => $itemId]);
        $minimum = $fallback->fetchColumn();
        if ($minimum === false) {
            throw new NotFoundException("item {$itemId}");
        }

        return [
            'minimum_stock' => round((float) $minimum, 6),
            'buffer_stock' => null,
            'buffer_configured' => false,
            'source' => 'fallback',
            'policy_id' => null,
        ];
    }

    /**
     * Upsert exactly one (item, warehouse) row. Editing warehouse A's
     * policy never touches warehouse B's row for the same item — each
     * call targets exactly one pair.
     *
     * @param array{item_id:int, warehouse_id:int, minimum_stock:float, buffer_stock:?float, updated_by:int, username?:string} $p
     */
    public static function upsert(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['item_id', 'warehouse_id', 'minimum_stock', 'updated_by']);

        $itemId = (int) $p['item_id'];
        $warehouseId = (int) $p['warehouse_id'];
        $minimum = (float) $p['minimum_stock'];
        $buffer = array_key_exists('buffer_stock', $p) && $p['buffer_stock'] !== null ? (float) $p['buffer_stock'] : null;

        if ($minimum < 0) {
            throw new ValidationException(['minimum_stock cannot be negative']);
        }
        if ($buffer !== null && $buffer < 0) {
            throw new ValidationException(['buffer_stock cannot be negative']);
        }

        $itemCheck = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $itemCheck->execute(['id' => $itemId]);
        if ($itemCheck->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId}");
        }
        $whCheck = $pdo->prepare('SELECT id FROM warehouses WHERE id = :id');
        $whCheck->execute(['id' => $warehouseId]);
        if ($whCheck->fetchColumn() === false) {
            throw new NotFoundException("warehouse {$warehouseId}");
        }

        $existing = $pdo->prepare('SELECT id, minimum_stock_base, buffer_stock_base FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
        $existing->execute(['i' => $itemId, 'w' => $warehouseId]);
        $before = $existing->fetch();

        if ($before !== false) {
            $pdo->prepare(
                'UPDATE item_warehouse_stock_policy
                 SET minimum_stock_base = :min, buffer_stock_base = :buf, is_active = 1, updated_by = :by, notes = :notes
                 WHERE id = :id'
            )->execute([
                'min' => $minimum, 'buf' => $buffer, 'by' => $p['updated_by'],
                'notes' => $p['notes'] ?? null, 'id' => $before['id'],
            ]);
            $policyId = (int) $before['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO item_warehouse_stock_policy
                    (item_id, warehouse_id, minimum_stock_base, buffer_stock_base, is_active, notes, created_by, updated_by)
                 VALUES (:i, :w, :min, :buf, 1, :notes, :created_by, :updated_by)'
            )->execute([
                'i' => $itemId, 'w' => $warehouseId, 'min' => $minimum, 'buf' => $buffer,
                'notes' => $p['notes'] ?? null, 'created_by' => $p['updated_by'], 'updated_by' => $p['updated_by'],
            ]);
            $policyId = (int) $pdo->lastInsertId();
        }

        AuditService::log(
            $pdo, $p['updated_by'], $p['username'] ?? 'system', 'STOCK_POLICY_UPDATE',
            'item_warehouse_stock_policy', $policyId,
            $before === false ? null : ['minimum_stock' => (float) $before['minimum_stock_base'], 'buffer_stock' => $before['buffer_stock_base'] === null ? null : (float) $before['buffer_stock_base']],
            ['minimum_stock' => $minimum, 'buffer_stock' => $buffer],
            null
        );

        return ['success' => true, 'policy_id' => $policyId];
    }

    /**
     * Section 10 of the technical design — evaluated in this exact order.
     * migration_negative_review always wins: it must never be folded into
     * SAFE/LOW/CRITICAL/OUT_OF_STOCK. When buffer is not configured, LOW
     * never fires and the model degrades gracefully to a 3-state
     * OUT_OF_STOCK/CRITICAL/SAFE.
     */
    public static function stockStatus(float $qty, float $minimum, ?float $buffer, bool $migrationNegativeReview): string
    {
        if ($migrationNegativeReview) {
            return self::STATUS_REVIEW;
        }
        if ($qty <= 0) {
            return self::STATUS_OUT_OF_STOCK;
        }
        if ($qty < $minimum) {
            return self::STATUS_CRITICAL;
        }
        if ($buffer !== null && $qty < $minimum + $buffer) {
            return self::STATUS_LOW;
        }
        return self::STATUS_SAFE;
    }
}
