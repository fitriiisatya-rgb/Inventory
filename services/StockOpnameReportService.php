<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 8 "Laporan Stock Opname". Read-only session-level
 * summary over the EXISTING stock_opname_sessions/stock_opname_lines
 * tables StockOpnameService already owns (start/count/finalize/post) —
 * this class never writes to them. Reports the CURRENT session model
 * only (single blind count) — P1/P2 dual-count is out of scope.
 */
final class StockOpnameReportService
{
    /**
     * @param array{date_from:?string, date_to:?string, warehouse_id:?int, status:?string, page:int, per_page:int} $params
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

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("{$baseSql} WHERE {$where} ORDER BY sos.session_date DESC, sos.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($bind);

        return [
            'rows' => array_map(static fn ($r) => self::formatRow($r), $stmt->fetchAll()),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    private static function baseSql(): string
    {
        return "SELECT
                sos.id, sos.session_uuid, sos.session_date, sos.status,
                sos.created_at, sos.finalized_at, sos.posted_at, sos.cancelled_at,
                w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                cu.username AS created_by_username, fu.username AS finalized_by_username, pu.username AS posted_by_username,
                COALESCE(lc.item_count, 0) AS item_count,
                COALESCE(lc.system_qty, 0) AS system_qty,
                COALESCE(lc.counted_qty, 0) AS counted_qty,
                COALESCE(lc.variance_qty, 0) AS variance_qty,
                COALESCE(lc.variance_value, 0) AS variance_value
             FROM stock_opname_sessions sos
             JOIN warehouses w ON w.id = sos.warehouse_id
             JOIN users cu ON cu.id = sos.created_by
             LEFT JOIN users fu ON fu.id = sos.finalized_by
             LEFT JOIN users pu ON pu.id = sos.posted_by
             LEFT JOIN (
                 SELECT session_id, COUNT(*) AS item_count,
                        SUM(system_qty_base) AS system_qty,
                        SUM(COALESCE(counted_qty_base, system_qty_base)) AS counted_qty,
                        SUM(COALESCE(variance_qty_base, 0)) AS variance_qty,
                        SUM(COALESCE(variance_qty_base, 0) * unit_cost_base) AS variance_value
                 FROM stock_opname_lines GROUP BY session_id
             ) lc ON lc.session_id = sos.id
        ";
    }

    /** @return array{0:string,1:array} */
    private static function buildFilters(array $params): array
    {
        $where = ['1=1'];
        $bind = [];
        if (!empty($params['date_from'])) {
            $where[] = 'sos.session_date >= :date_from';
            $bind['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 'sos.session_date <= :date_to';
            $bind['date_to'] = $params['date_to'];
        }
        if (!empty($params['warehouse_id'])) {
            $where[] = 'sos.warehouse_id = :wh';
            $bind['wh'] = $params['warehouse_id'];
        }
        if (!empty($params['status'])) {
            $where[] = 'sos.status = :status';
            $bind['status'] = $params['status'];
        }
        return [implode(' AND ', $where), $bind];
    }

    private static function formatRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'session_uuid' => $r['session_uuid'],
            'session_date' => $r['session_date'],
            'status' => $r['status'],
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'created_at' => $r['created_at'],
            'finalized_at' => $r['finalized_at'],
            'posted_at' => $r['posted_at'],
            'created_by' => $r['created_by_username'],
            'finalized_by' => $r['finalized_by_username'],
            'posted_by' => $r['posted_by_username'],
            'item_count' => (int) $r['item_count'],
            'system_qty' => round((float) $r['system_qty'], 6),
            'counted_qty' => round((float) $r['counted_qty'], 6),
            'variance_qty' => round((float) $r['variance_qty'], 6),
            'variance_value' => round((float) $r['variance_value'], 4),
        ];
    }
}
