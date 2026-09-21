<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 10 "Expired / Near Expired". OWNER FINAL RULE:
 * expiry is INFORMATION ONLY. This class only ever READS
 * inventory_batches.expiry_date — a column FifoService already writes at
 * batch-creation time when the source data provides one (e.g. an Opening
 * import row) and never invents here. It never influences FIFO
 * consumption order (still strictly received_date/id — see
 * inventory_batches' own table comment), never enforces FEFO, never
 * blocks an OUT, and never touches HPP. A batch with no expiry_date is
 * simply excluded — absence of data is never fabricated into a status.
 */
final class ExpiryReportService
{
    private const DAY_SECONDS = 86400;

    /**
     * @param array{warehouse_id:?int, category_id:?int, status:?string, page:int, per_page:int} $params
     */
    public static function list(PDO $pdo, array $params): array
    {
        [$where, $bind] = self::buildFilters($params);
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

        $baseSql = self::baseSql();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$baseSql} WHERE {$where}) counted");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        // Status is derived in PHP from today's date (never persisted, so
        // it can never go stale) — filtering by it therefore happens after
        // the fetch, over the full matching set, then re-paginated. Batch
        // datasets with real expiry data are small (a minority of SKUs
        // typically), so this stays well within safe in-memory bounds.
        $stmt = $pdo->prepare("{$baseSql} WHERE {$where} ORDER BY b.expiry_date ASC");
        $stmt->execute($bind);
        $allRows = array_map(static fn ($r) => self::formatRow($r), $stmt->fetchAll());

        $statusFilter = $params['status'] ?? null;
        if ($statusFilter) {
            $allRows = array_values(array_filter($allRows, static fn ($r) => $r['status'] === $statusFilter));
        }

        $filteredTotal = $statusFilter ? count($allRows) : $total;
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($allRows, $offset, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $filteredTotal, 'total_pages' => (int) ceil(max(1, $filteredTotal) / $perPage)],
        ];
    }

    private static function baseSql(): string
    {
        return "SELECT
                b.id AS batch_id, b.item_id, b.warehouse_id, b.qty_base, b.unit_cost_base, b.expiry_date,
                i.sku, i.name AS item_name,
                w.code AS warehouse_code, w.name AS warehouse_name
             FROM inventory_batches b
             JOIN items i ON i.id = b.item_id
             JOIN warehouses w ON w.id = b.warehouse_id
        ";
    }

    /** @return array{0:string,1:array} */
    private static function buildFilters(array $params): array
    {
        $where = ['b.qty_base > 0', 'b.expiry_date IS NOT NULL'];
        $bind = [];
        if (!empty($params['warehouse_id'])) {
            $where[] = 'b.warehouse_id = :wh';
            $bind['wh'] = $params['warehouse_id'];
        }
        if (!empty($params['category_id'])) {
            $where[] = 'i.category_id = :cat';
            $bind['cat'] = $params['category_id'];
        }
        return [implode(' AND ', $where), $bind];
    }

    private static function formatRow(array $r): array
    {
        $expiry = (string) $r['expiry_date'];
        $daysRemaining = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / self::DAY_SECONDS);
        $status = match (true) {
            $daysRemaining < 0 => 'EXPIRED',
            $daysRemaining <= 7 => 'CRITICAL',
            $daysRemaining <= 30 => 'WARNING',
            $daysRemaining <= 90 => 'WATCH',
            default => 'OK',
        };
        return [
            'batch_id' => (int) $r['batch_id'],
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'item' => ['id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name']],
            'qty_remaining' => round((float) $r['qty_base'], 6),
            'inventory_value' => round((float) $r['qty_base'] * (float) $r['unit_cost_base'], 4),
            'expiry_date' => $expiry,
            'days_remaining' => $daysRemaining,
            'status' => $status,
        ];
    }
}
