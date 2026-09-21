<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 6 "Laporan Transfer". Read-only reporting layer
 * over the EXISTING warehouse_transfers/warehouse_transfer_lines tables
 * that TransferService already owns — this class never writes to them
 * and never re-implements create/receive/cancel/reverse. Partial
 * receiving is out of scope (unchanged full-receive-only behavior).
 */
final class TransferReportService
{
    /**
     * @param array{date_from:?string, date_to:?string, from_warehouse_id:?int, to_warehouse_id:?int,
     *              status:?string, page:int, per_page:int} $params
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
        $stmt = $pdo->prepare("{$baseSql} WHERE {$where} ORDER BY wt.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($bind);

        return [
            'rows' => array_map(static fn ($r) => self::formatRow($r), $stmt->fetchAll()),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    private static function baseSql(): string
    {
        return "SELECT
                wt.id, wt.transfer_uuid, wt.status, wt.ship_date, wt.receive_date, wt.created_at,
                wt.cancel_reason, wt.cancelled_at, wt.reverse_reason, wt.reversed_at,
                fw.id AS from_warehouse_id, fw.code AS from_warehouse_code, fw.name AS from_warehouse_name,
                tw.id AS to_warehouse_id, tw.code AS to_warehouse_code, tw.name AS to_warehouse_name,
                cu.username AS created_by_username, ru.username AS received_by_username,
                canu.username AS cancelled_by_username, revu.username AS reversed_by_username,
                COALESCE(lc.item_count, 0) AS item_count,
                COALESCE(lc.total_qty, 0) AS total_qty,
                COALESCE(lc.total_value, 0) AS total_value
             FROM warehouse_transfers wt
             JOIN warehouses fw ON fw.id = wt.from_warehouse_id
             JOIN warehouses tw ON tw.id = wt.to_warehouse_id
             JOIN users cu ON cu.id = wt.created_by
             LEFT JOIN users ru ON ru.id = wt.received_by
             LEFT JOIN users canu ON canu.id = wt.cancelled_by
             LEFT JOIN users revu ON revu.id = wt.reversed_by
             LEFT JOIN (
                 SELECT transfer_id, COUNT(DISTINCT item_id) AS item_count,
                        SUM(qty_base) AS total_qty, SUM(qty_base * unit_cost_base) AS total_value
                 FROM warehouse_transfer_lines GROUP BY transfer_id
             ) lc ON lc.transfer_id = wt.id
        ";
    }

    /** @return array{0:string,1:array} */
    private static function buildFilters(array $params): array
    {
        $where = ['1=1'];
        $bind = [];
        if (!empty($params['date_from'])) {
            $where[] = 'wt.ship_date >= :date_from';
            $bind['date_from'] = $params['date_from'] . ' 00:00:00';
        }
        if (!empty($params['date_to'])) {
            $where[] = 'wt.ship_date <= :date_to';
            $bind['date_to'] = $params['date_to'] . ' 23:59:59';
        }
        if (!empty($params['status'])) {
            $where[] = 'wt.status = :status';
            $bind['status'] = $params['status'];
        }
        // Warehouse scope: a single "warehouse_id" (STOCK's forced own
        // warehouse, or a SUPERADMIN's explicit single-warehouse pick)
        // matches EITHER leg of the transfer — same "involves my
        // warehouse" convention TransferService::listAll() already uses.
        if (!empty($params['warehouse_id'])) {
            $where[] = '(wt.from_warehouse_id = :wh_scope OR wt.to_warehouse_id = :wh_scope2)';
            $bind['wh_scope'] = $params['warehouse_id'];
            $bind['wh_scope2'] = $params['warehouse_id'];
        }
        if (!empty($params['from_warehouse_id'])) {
            $where[] = 'wt.from_warehouse_id = :from_wh';
            $bind['from_wh'] = $params['from_warehouse_id'];
        }
        if (!empty($params['to_warehouse_id'])) {
            $where[] = 'wt.to_warehouse_id = :to_wh';
            $bind['to_wh'] = $params['to_warehouse_id'];
        }
        return [implode(' AND ', $where), $bind];
    }

    private static function formatRow(array $r): array
    {
        $leadTimeDays = null;
        if ($r['status'] === 'RECEIVED' && $r['receive_date'] !== null) {
            $leadTimeDays = (int) round((strtotime((string) $r['receive_date']) - strtotime((string) $r['ship_date'])) / 86400);
        }
        return [
            'id' => (int) $r['id'],
            'transfer_uuid' => $r['transfer_uuid'],
            'status' => $r['status'],
            'ship_date' => $r['ship_date'],
            'receive_date' => $r['receive_date'],
            'created_at' => $r['created_at'],
            'from_warehouse' => ['id' => (int) $r['from_warehouse_id'], 'code' => $r['from_warehouse_code'], 'name' => $r['from_warehouse_name']],
            'to_warehouse' => ['id' => (int) $r['to_warehouse_id'], 'code' => $r['to_warehouse_code'], 'name' => $r['to_warehouse_name']],
            'created_by' => $r['created_by_username'],
            'received_by' => $r['received_by_username'],
            'item_count' => (int) $r['item_count'],
            'total_qty' => round((float) $r['total_qty'], 6),
            'transfer_value' => round((float) $r['total_value'], 4),
            'lead_time_days' => $leadTimeDays,
            'cancel' => $r['status'] === 'CANCELLED' ? ['reason' => $r['cancel_reason'], 'at' => $r['cancelled_at'], 'by' => $r['cancelled_by_username']] : null,
            'reverse' => $r['status'] === 'REVERSED' ? ['reason' => $r['reverse_reason'], 'at' => $r['reversed_at'], 'by' => $r['reversed_by_username']] : null,
        ];
    }
}
