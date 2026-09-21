<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 13 "Slow / No Movement". Read-only, purely
 * descriptive: NO_MOVEMENT_30/60/90 is a factual "days since last
 * posted movement" bucket, never an automatic "obsolete"/"dead stock"
 * judgement (that call is left to the reader). Defaults to items that
 * currently HOLD stock (qty_base <> 0) — a zero-stock SKU that simply
 * hasn't been touched isn't "slow moving inventory" by itself, so it is
 * excluded unless `include_zero_stock` is explicitly requested.
 */
final class SlowMovementReportService
{
    /**
     * @param array{warehouse_id:?int, category_id:?int, threshold_days:int, include_zero_stock:bool, page:int, per_page:int} $params
     */
    public static function list(PDO $pdo, array $params): array
    {
        $requestedThreshold = (int) ($params['threshold_days'] ?? 30);
        $threshold = in_array($requestedThreshold, [30, 60, 90], true) ? $requestedThreshold : 30;
        [$where, $bind] = self::buildFilters($params);
        $page = max(1, (int) ($params['page'] ?? 1));
        // PHASE V2.6C: raised from 200 so CSV export can request the full
        // filtered set in one page — still bounded, never unbounded.
        $perPage = min(5000, max(1, (int) ($params['per_page'] ?? 50)));

        $baseSql = self::baseSql();
        $stmt = $pdo->prepare("{$baseSql} WHERE {$where}");
        $stmt->execute($bind);
        $allRows = array_map(static fn ($r) => self::formatRow($r), $stmt->fetchAll());

        // Threshold is a display/filter concern (days-since-movement is
        // computed from today at read time, never persisted) — applied
        // after the fetch, same reasoning as ExpiryReportService's status.
        $allRows = array_values(array_filter($allRows, static fn ($r) => $r['days_since_movement'] === null || $r['days_since_movement'] >= $threshold));
        foreach ($allRows as &$r) {
            $r['status'] = self::statusFor($r['days_since_movement']);
        }
        unset($r);

        $total = count($allRows);
        $offset = ($page - 1) * $perPage;

        return [
            'threshold_days' => $threshold,
            'rows' => array_slice($allRows, $offset, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil(max(1, $total) / $perPage)],
        ];
    }

    private static function baseSql(): string
    {
        return "SELECT
                s.item_id, s.warehouse_id, s.qty_base, s.value,
                i.sku, i.name AS item_name,
                w.code AS warehouse_code, w.name AS warehouse_name,
                mv.last_in, mv.last_out, mv.last_movement
             FROM (
                 SELECT item_id, warehouse_id, SUM(qty_base) AS qty_base, SUM(qty_base * unit_cost_base) AS value
                 FROM inventory_batches GROUP BY item_id, warehouse_id
             ) s
             JOIN items i ON i.id = s.item_id
             JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN (
                 SELECT l.item_id, l.warehouse_id,
                        MAX(CASE WHEN t.transaction_type = 'IN' THEN t.transaction_date END) AS last_in,
                        MAX(CASE WHEN t.transaction_type = 'OUT' THEN t.transaction_date END) AS last_out,
                        MAX(t.transaction_date) AS last_movement
                 FROM inventory_transaction_lines l
                 JOIN inventory_transactions t ON t.id = l.transaction_id
                 WHERE t.status = 'POSTED' AND t.inventory_effect = 1
                 GROUP BY l.item_id, l.warehouse_id
             ) mv ON mv.item_id = s.item_id AND mv.warehouse_id = s.warehouse_id
        ";
    }

    /** @return array{0:string,1:array} */
    private static function buildFilters(array $params): array
    {
        $where = ['1=1'];
        $bind = [];
        if (empty($params['include_zero_stock'])) {
            $where[] = 'ABS(s.qty_base) > 0.0000005';
        }
        if (!empty($params['warehouse_id'])) {
            $where[] = 's.warehouse_id = :wh';
            $bind['wh'] = $params['warehouse_id'];
        }
        if (!empty($params['category_id'])) {
            $where[] = 'i.category_id = :cat';
            $bind['cat'] = $params['category_id'];
        }
        return [implode(' AND ', $where), $bind];
    }

    private static function statusFor(?int $days): string
    {
        if ($days === null || $days >= 90) return 'NO_MOVEMENT_90';
        if ($days >= 60) return 'NO_MOVEMENT_60';
        return 'NO_MOVEMENT_30';
    }

    private static function formatRow(array $r): array
    {
        $lastMovement = $r['last_movement'];
        $daysSince = $lastMovement !== null ? (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime((string) $lastMovement)))) / 86400) : null;
        return [
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'item' => ['id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name']],
            'qty_on_hand' => round((float) $r['qty_base'], 6),
            'inventory_value' => round((float) $r['value'], 4),
            'last_in' => $r['last_in'],
            'last_out' => $r['last_out'],
            'last_movement' => $lastMovement,
            'days_since_movement' => $daysSince,
        ];
    }
}
