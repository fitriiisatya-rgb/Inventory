<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 8 "Laporan Stock Opname". Read-only session-level
 * summary over the EXISTING stock_opname_sessions/stock_opname_lines
 * tables StockOpnameService already owns (start/count/finalize/post) —
 * this class never writes to them.
 *
 * PHASE V2.12C: now also surfaces the dual-count columns (session_number,
 * P1/P2/supervisor, match/mismatch/recounted/not-counted/excluded counts)
 * added in V2.12A/B — a legacy single-count session simply reports
 * p1/p2/supervisor as null and match/mismatch/recounted as 0, so nothing
 * about an old row's report row changes.
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
        // PHASE V2.6C: raised from 200 so CSV export can request the full
        // filtered set in one page — still bounded, never unbounded.
        $perPage = min(5000, max(1, (int) ($params['per_page'] ?? 50)));

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
                sos.id, sos.session_uuid, sos.session_number, sos.session_date, sos.scope, sos.status,
                sos.created_at, sos.finalized_at, sos.posted_at, sos.cancelled_at,
                w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                cu.username AS created_by_username, fu.username AS finalized_by_username, pu.username AS posted_by_username,
                p1u.username AS p1_username, p2u.username AS p2_username, svu.username AS supervisor_username,
                COALESCE(lc.item_count, 0) AS item_count,
                COALESCE(lc.system_qty, 0) AS system_qty,
                COALESCE(lc.counted_qty, 0) AS counted_qty,
                COALESCE(lc.variance_qty, 0) AS variance_qty,
                COALESCE(lc.variance_value, 0) AS variance_value,
                COALESCE(lc.match_count, 0) AS match_count,
                COALESCE(lc.mismatch_count, 0) AS mismatch_count,
                COALESCE(lc.recounted_count, 0) AS recounted_count,
                COALESCE(lc.not_counted_count, 0) AS not_counted_count,
                COALESCE(lc.excluded_count, 0) AS excluded_count,
                COALESCE(lc.adjustment_positive_value, 0) AS adjustment_positive_value,
                COALESCE(lc.adjustment_negative_value, 0) AS adjustment_negative_value
             FROM stock_opname_sessions sos
             JOIN warehouses w ON w.id = sos.warehouse_id
             JOIN users cu ON cu.id = sos.created_by
             LEFT JOIN users fu ON fu.id = sos.finalized_by
             LEFT JOIN users pu ON pu.id = sos.posted_by
             LEFT JOIN users p1u ON p1u.id = sos.p1_user_id
             LEFT JOIN users p2u ON p2u.id = sos.p2_user_id
             LEFT JOIN users svu ON svu.id = sos.supervisor_id
             LEFT JOIN (
                 SELECT session_id, COUNT(*) AS item_count,
                        SUM(system_qty_base) AS system_qty,
                        SUM(COALESCE(counted_qty_base, system_qty_base)) AS counted_qty,
                        SUM(COALESCE(variance_qty_base, 0)) AS variance_qty,
                        SUM(COALESCE(variance_qty_base, 0) * unit_cost_base) AS variance_value,
                        SUM(match_status = 'MATCH') AS match_count,
                        SUM(match_status = 'MISMATCH') AS mismatch_count,
                        SUM(match_status = 'RECOUNTED') AS recounted_count,
                        SUM(match_status = 'PENDING') AS not_counted_count,
                        SUM(match_status = 'EXCLUDED') AS excluded_count,
                        SUM(CASE WHEN COALESCE(variance_qty_base, 0) > 0 THEN variance_qty_base * unit_cost_base ELSE 0 END) AS adjustment_positive_value,
                        SUM(CASE WHEN COALESCE(variance_qty_base, 0) < 0 THEN variance_qty_base * unit_cost_base ELSE 0 END) AS adjustment_negative_value
                 FROM stock_opname_lines GROUP BY session_id
             ) lc ON lc.session_id = sos.id
        ";
    }

    /**
     * PHASE V2.12C — per-line detail (Section 21: "SKU, Item, System, P1,
     * P2, Recount, Final, Difference, Reason"). A thin pass-through to
     * StockOpnameService::review() — the report layer never re-derives
     * match/variance logic that service already owns.
     */
    public static function detail(PDO $pdo, int $sessionId): array
    {
        return StockOpnameService::review($pdo, $sessionId);
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
            'session_number' => $r['session_number'],
            'session_date' => $r['session_date'],
            'scope' => $r['scope'],
            'status' => $r['status'],
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'created_at' => $r['created_at'],
            'finalized_at' => $r['finalized_at'],
            'posted_at' => $r['posted_at'],
            'created_by' => $r['created_by_username'],
            'finalized_by' => $r['finalized_by_username'],
            'posted_by' => $r['posted_by_username'],
            'p1' => $r['p1_username'],
            'p2' => $r['p2_username'],
            'supervisor' => $r['supervisor_username'],
            'item_count' => (int) $r['item_count'],
            'system_qty' => round((float) $r['system_qty'], 6),
            'counted_qty' => round((float) $r['counted_qty'], 6),
            'variance_qty' => round((float) $r['variance_qty'], 6),
            'variance_value' => round((float) $r['variance_value'], 4),
            'match_count' => (int) $r['match_count'],
            'mismatch_count' => (int) $r['mismatch_count'],
            'recounted_count' => (int) $r['recounted_count'],
            'not_counted_count' => (int) $r['not_counted_count'],
            'excluded_count' => (int) $r['excluded_count'],
            'adjustment_positive_value' => round((float) $r['adjustment_positive_value'], 4),
            'adjustment_negative_value' => round((float) $r['adjustment_negative_value'], 4),
            'posted_transaction_ref' => $r['session_number'] ?? "OPNAME-{$r['id']}",
        ];
    }
}
