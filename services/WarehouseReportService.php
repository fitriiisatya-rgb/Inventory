<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.1 — Master Gudang enhanced list: per-warehouse summary (active
 * SKU count, qty on hand, inventory value) with search/active/sort.
 * Read-only aggregation over existing tables — no schema change needed.
 */
final class WarehouseReportService
{
    private const SORTABLE = [
        'name' => 'w.name',
        'sku_count' => 'sku_count',
        'qty' => 'qty_on_hand',
        'value' => 'inventory_value',
    ];

    /**
     * @param array{q: ?string, active: ?string, sort: string, dir: string, warehouse_id: ?int} $params
     *        `warehouse_id` restricts to a single warehouse (used to scope a
     *        STOCK user to only their own row — never trusts the request,
     *        the caller re-derives it the same way every other warehouse-
     *        scoped endpoint in this codebase does).
     */
    public static function list(PDO $pdo, array $params): array
    {
        $q = trim((string) ($params['q'] ?? ''));
        $active = $params['active'] ?? null; // 'ACTIVE' | 'INACTIVE' | null (Semua)
        $sortKey = self::SORTABLE[$params['sort'] ?? 'name'] ?? 'w.name';
        $dir = strtoupper($params['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $singleWarehouseId = $params['warehouse_id'] ?? null;

        $where = ['1=1'];
        $bind = [];
        if ($singleWarehouseId !== null) {
            $where[] = 'w.id = :wid';
            $bind['wid'] = $singleWarehouseId;
        }
        if ($active === 'ACTIVE') {
            $where[] = 'w.is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'w.is_active = 0';
        }
        if ($q !== '') {
            $where[] = '(w.code LIKE :q_code OR w.name LIKE :q_name)';
            $bind['q_code'] = '%' . $q . '%';
            $bind['q_name'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                w.id, w.code, w.name, w.warehouse_type, w.is_active, w.activation_locked,
                COALESCE(sku.sku_count, 0) AS sku_count,
                COALESCE(bal.qty_on_hand, 0) AS qty_on_hand,
                COALESCE(bal.inventory_value, 0) AS inventory_value
            FROM warehouses w
            LEFT JOIN (
                SELECT warehouse_id, COUNT(DISTINCT item_id) AS sku_count
                FROM inventory_batches
                WHERE qty_base > 0
                GROUP BY warehouse_id
            ) sku ON sku.warehouse_id = w.id
            LEFT JOIN (
                SELECT warehouse_id, SUM(qty_base) AS qty_on_hand, SUM(qty_base * unit_cost_base) AS inventory_value
                FROM inventory_batches
                GROUP BY warehouse_id
            ) bal ON bal.warehouse_id = w.id
            WHERE {$whereSql}
            ORDER BY {$sortKey} {$dir}, w.name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'warehouse_type' => $r['warehouse_type'],
            'is_active' => (bool) $r['is_active'],
            'activation_locked' => (bool) $r['activation_locked'],
            'sku_count' => (int) $r['sku_count'],
            'qty_on_hand' => round((float) $r['qty_on_hand'], 6),
            'inventory_value' => round((float) $r['inventory_value'], 4),
        ], $rows);
    }
}
