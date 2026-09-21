<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.6B — Report 9 "Adjustment / Selisih". Read-only reporting
 * layer over the EXISTING stock_adjustments table StockAdjustmentService
 * already owns — this class never writes to it. OPENING is never an
 * "ordinary adjustment" by construction here: stock_openings/OPENING-
 * type transactions are a completely separate table/transaction_type
 * from stock_adjustments, so this report can never accidentally
 * misclassify one as the other.
 */
final class AdjustmentReportService
{
    /**
     * @param array{date_from:?string, date_to:?string, warehouse_id:?int, direction:?string,
     *              adjustment_type:?string, item_id:?int, page:int, per_page:int} $params
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
        $stmt = $pdo->prepare("{$baseSql} WHERE {$where} ORDER BY t.transaction_date DESC, sa.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($bind);

        return [
            'rows' => array_map(static fn ($r) => self::formatRow($r), $stmt->fetchAll()),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    private static function baseSql(): string
    {
        return "SELECT
                sa.id, sa.item_id, sa.warehouse_id, sa.adjustment_type, sa.qty_base_delta,
                sa.unit_cost_base, sa.transaction_id, sa.reference_no, sa.reason, sa.created_at,
                i.sku, i.name AS item_name,
                w.code AS warehouse_code, w.name AS warehouse_name,
                cu.username AS created_by_username,
                t.status AS transaction_status, t.transaction_date
             FROM stock_adjustments sa
             JOIN items i ON i.id = sa.item_id
             JOIN warehouses w ON w.id = sa.warehouse_id
             JOIN users cu ON cu.id = sa.created_by
             LEFT JOIN inventory_transactions t ON t.id = sa.transaction_id
        ";
    }

    /** @return array{0:string,1:array} */
    private static function buildFilters(array $params): array
    {
        // Filtered by the linked transaction's business-effective
        // transaction_date — same convention every other report in this
        // pack uses — never sa.created_at (posting/entry time), which can
        // legitimately differ from when the adjustment was effective.
        $where = ['1=1'];
        $bind = [];
        if (!empty($params['date_from'])) {
            $where[] = 't.transaction_date >= :date_from';
            $bind['date_from'] = $params['date_from'] . ' 00:00:00';
        }
        if (!empty($params['date_to'])) {
            $where[] = 't.transaction_date <= :date_to';
            $bind['date_to'] = $params['date_to'] . ' 23:59:59';
        }
        if (!empty($params['warehouse_id'])) {
            $where[] = 'sa.warehouse_id = :wh';
            $bind['wh'] = $params['warehouse_id'];
        }
        if (!empty($params['item_id'])) {
            $where[] = 'sa.item_id = :item';
            $bind['item'] = $params['item_id'];
        }
        if (!empty($params['adjustment_type'])) {
            $where[] = 'sa.adjustment_type = :atype';
            $bind['atype'] = $params['adjustment_type'];
        }
        if (!empty($params['direction'])) {
            $where[] = $params['direction'] === 'POSITIVE' ? 'sa.qty_base_delta > 0' : 'sa.qty_base_delta < 0';
        }
        return [implode(' AND ', $where), $bind];
    }

    private static function formatRow(array $r): array
    {
        $delta = (float) $r['qty_base_delta'];
        return [
            'id' => (int) $r['id'],
            'date' => $r['transaction_date'] ?? $r['created_at'],
            'warehouse' => ['id' => (int) $r['warehouse_id'], 'code' => $r['warehouse_code'], 'name' => $r['warehouse_name']],
            'item' => ['id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name']],
            'adjustment_qty' => round($delta, 6),
            'adjustment_value' => round($delta * (float) $r['unit_cost_base'], 4),
            'direction' => $delta >= 0 ? 'POSITIVE' : 'NEGATIVE',
            'reason' => $r['reason'],
            'source_module' => $r['adjustment_type'],
            'created_by' => $r['created_by_username'],
            'transaction_id' => $r['transaction_id'] !== null ? (int) $r['transaction_id'] : null,
            'status' => $r['transaction_status'] ?? 'UNKNOWN',
        ];
    }
}
