<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 2. Flow: start (snapshot system qty, locks the
 * warehouse) -> count (physical count entries) -> finalize (locks counts,
 * computes variance) -> post (creates real StockAdjustmentService entries,
 * unlocks the warehouse). Nothing here ever overwrites a batch's qty_base
 * directly — posting always goes through StockAdjustmentService, which is
 * the only thing allowed to touch batches for a correction.
 */
final class StockOpnameService
{
    public static function start(PDO $pdo, int $warehouseId, int $createdBy, ?array $itemIds = null): int
    {
        if (WarehouseLockService::isLocked($pdo, $warehouseId)) {
            throw new ValidationException(["warehouse {$warehouseId} already has an active opname session"]);
        }

        $now = date('Y-m-d H:i:s');
        $uuid = self::uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, status, created_by, created_at)
             VALUES (:wh, :date, :uuid, \'OPEN\', :created_by, :now)'
        );
        $stmt->execute(['wh' => $warehouseId, 'date' => substr($now, 0, 10), 'uuid' => $uuid, 'created_by' => $createdBy, 'now' => $now]);
        $sessionId = (int) $pdo->lastInsertId();

        if ($itemIds === null) {
            $scan = $pdo->prepare('SELECT DISTINCT item_id FROM inventory_batches WHERE warehouse_id = :wh AND qty_base <> 0');
            $scan->execute(['wh' => $warehouseId]);
            $itemIds = array_map('intval', array_column($scan->fetchAll(), 'item_id'));
        }

        $lineStmt = $pdo->prepare(
            'INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base)
             VALUES (:session_id, :item_id, :system_qty, NULL, 0, :cost)'
        );
        foreach ($itemIds as $itemId) {
            $stock = InventoryService::currentStock($pdo, (int) $itemId, $warehouseId);
            $cost = $stock['qty_base'] != 0 ? round($stock['value'] / $stock['qty_base'], 4) : 0.0;
            $lineStmt->execute(['session_id' => $sessionId, 'item_id' => $itemId, 'system_qty' => $stock['qty_base'], 'cost' => $cost]);
        }

        AuditService::log($pdo, $createdBy, 'system', 'STOCK_OPNAME_START', 'stock_opname_sessions', $sessionId, null, ['warehouse_id' => $warehouseId, 'item_count' => count($itemIds)], null);

        return $sessionId;
    }

    public static function get(PDO $pdo, int $sessionId): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new ValidationException(['opname session not found']);
        }
        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id WHERE session_id = :id ORDER BY i.name'
        );
        $lines->execute(['id' => $sessionId]);
        $session['lines'] = $lines->fetchAll();
        return $session;
    }

    /** @param array<int, float> $counts item_id => counted_qty_base */
    public static function count(PDO $pdo, int $sessionId, array $counts, int $userId): void
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        $upsert = $pdo->prepare(
            'UPDATE stock_opname_lines SET counted_qty_base = :qty, is_counted = 1 WHERE session_id = :session_id AND item_id = :item_id'
        );
        $insertIfMissing = $pdo->prepare(
            'INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base)
             SELECT :session_id, :item_id, 0, :qty, 1, 0
             WHERE NOT EXISTS (SELECT 1 FROM stock_opname_lines WHERE session_id = :session_id2 AND item_id = :item_id2)'
        );

        foreach ($counts as $itemId => $qty) {
            $upsert->execute(['qty' => $qty, 'session_id' => $sessionId, 'item_id' => $itemId]);
            $insertIfMissing->execute(['session_id' => $sessionId, 'item_id' => $itemId, 'qty' => $qty, 'session_id2' => $sessionId, 'item_id2' => $itemId]);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_COUNT', 'stock_opname_sessions', $sessionId, null, ['counted_items' => count($counts)], null);
    }

    public static function finalize(PDO $pdo, int $sessionId, int $userId): array
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        $uncountedStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND is_counted = 0');
        $uncountedStmt->execute(['id' => $sessionId]);
        $uncountedCount = (int) $uncountedStmt->fetchColumn();
        if ($uncountedCount > 0) {
            throw new ValidationException(["{$uncountedCount} item(s) have not been counted yet — finalize requires every line counted"]);
        }

        $lines = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :id');
        $lines->execute(['id' => $sessionId]);
        $lines = $lines->fetchAll();

        $updateVariance = $pdo->prepare(
            'UPDATE stock_opname_lines SET variance_qty_base = :variance, cost_required = :cost_required WHERE id = :id'
        );
        foreach ($lines as $line) {
            $variance = round((float) $line['counted_qty_base'] - (float) $line['system_qty_base'], 6);
            $costRequired = 0;
            if ($variance > 0) {
                $lastCost = $pdo->prepare('SELECT unit_cost_base FROM inventory_batches WHERE item_id = :item_id ORDER BY received_date DESC, id DESC LIMIT 1');
                $lastCost->execute(['item_id' => $line['item_id']]);
                $cost = $lastCost->fetchColumn();
                $costRequired = ($cost === false || (float) $cost <= 0) ? 1 : 0;
            }
            $updateVariance->execute(['variance' => $variance, 'cost_required' => $costRequired, 'id' => $line['id']]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_sessions SET status = \'FINALIZED\', finalized_by = :by, finalized_at = :now WHERE id = :id')
            ->execute(['by' => $userId, 'now' => $now, 'id' => $sessionId]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_FINALIZE', 'stock_opname_sessions', $sessionId, null, null, null);

        return self::get($pdo, $sessionId);
    }

    /** @param array<int, float> $costOverrides item_id => override unit cost, for lines flagged cost_required */
    public static function post(PDO $pdo, int $sessionId, int $userId, array $costOverrides = []): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new ValidationException(['opname session not found']);
        }

        if ($session['status'] === 'POSTED') {
            // Idempotent: already posted, return the adjustments already created rather than reposting.
            $existing = $pdo->prepare('SELECT id, item_id, adjustment_id FROM stock_opname_lines WHERE session_id = :id AND adjustment_id IS NOT NULL');
            $existing->execute(['id' => $sessionId]);
            return ['session_id' => $sessionId, 'status' => 'POSTED', 'idempotent_replay' => true, 'adjustments' => $existing->fetchAll()];
        }

        if ($session['status'] !== 'FINALIZED') {
            throw new ValidationException(['opname session must be FINALIZED before it can be posted']);
        }

        $lines = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :id AND variance_qty_base <> 0');
        $lines->execute(['id' => $sessionId]);
        $lines = $lines->fetchAll();

        foreach ($lines as $line) {
            if ((int) $line['cost_required'] === 1 && !isset($costOverrides[$line['item_id']])) {
                throw new CostRequiredException((int) $line['item_id']);
            }
        }

        $adjustments = [];
        foreach ($lines as $line) {
            $override = $costOverrides[$line['item_id']] ?? null;
            $result = StockAdjustmentService::post($pdo, [
                'transaction_uuid' => $session['session_uuid'] . ':' . $line['item_id'],
                'item_id' => (int) $line['item_id'],
                'warehouse_id' => (int) $session['warehouse_id'],
                'qty_base_delta' => (float) $line['variance_qty_base'],
                'adjustment_type' => 'OPNAME',
                'reason' => "Stock opname session #{$sessionId}",
                'reference_no' => "OPNAME-{$sessionId}",
                'transaction_date' => $session['session_date'] . ' 23:59:59',
                'created_by' => $userId,
                'override_cost_base' => $override,
                'bypass_warehouse_lock' => true,
            ]);

            $pdo->prepare('UPDATE stock_opname_lines SET adjustment_id = :adj_id WHERE id = :id')
                ->execute(['adj_id' => $result['adjustment_id'], 'id' => $line['id']]);
            $adjustments[] = $result;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_sessions SET status = \'POSTED\', posted_by = :by, posted_at = :now WHERE id = :id')
            ->execute(['by' => $userId, 'now' => $now, 'id' => $sessionId]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_POST', 'stock_opname_sessions', $sessionId, null, ['adjustments' => count($adjustments)], null);

        return ['session_id' => $sessionId, 'status' => 'POSTED', 'adjustments' => $adjustments];
    }

    private static function requireStatus(PDO $pdo, int $sessionId, string $expected): array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new ValidationException(['opname session not found']);
        }
        if ($session['status'] !== $expected) {
            throw new ValidationException(["opname session must be {$expected}, currently {$session['status']}"]);
        }
        return $session;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
