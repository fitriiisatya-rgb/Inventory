<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11C — Laporan Penjualan/Distribusi Bakery: revenue, actual
 * margin, and per-category/per-bakery aggregation.
 *
 * SOURCE OF TRUTH (Part 34, strictly followed):
 *   Revenue  = distribution_invoice_lines.subtotal, from ISSUED invoices
 *              only (a DRAFT invoice is not yet a real sale; a CANCELLED
 *              one never was) — never estimated, never from a DRAFT.
 *   Actual HPP = inventory_transaction_lines.unit_cost_base (the REAL
 *              FIFO-weighted cost FifoService::postOut() actually posted
 *              for that dispatch) x the DO line's real qty_sent_base —
 *              never the reference purchase price, never the configured
 *              pricing-policy margin.
 *   Actual Margin = Revenue - Actual HPP, computed fresh every query —
 *              never a copy of the policy's margin_value.
 *
 * Every method here is read-only and joins exactly the tables above;
 * nothing here writes, and nothing here re-derives a number a more
 * authoritative service already computed (FifoService for cost,
 * DistributionInvoiceService for the pricing snapshot).
 */
final class DistributionReportService
{
    /** @param array{date_from?:string, date_to?:string, bakery_destination_id?:int, category_id?:int, item_id?:int, pricing_method?:string} $filters */
    public static function lineDetail(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);
        $sql = self::baseSelect() . " WHERE {$where} ORDER BY do.do_date DESC, di.invoice_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'shapeLine'], $stmt->fetchAll());
    }

    /** @return array{total_revenue:float, total_hpp:float, total_margin:float, margin_pct:float, total_do:int, total_invoice:int, total_bakery_served:int, discrepancy_count:int} */
    public static function summary(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);
        $sql = "SELECT
                    COALESCE(SUM(dil.subtotal), 0) AS revenue,
                    COALESCE(SUM(itl.unit_cost_base * dol.qty_sent_base), 0) AS hpp,
                    COUNT(DISTINCT do.id) AS total_do,
                    COUNT(DISTINCT di.id) AS total_invoice,
                    COUNT(DISTINCT bd.id) AS total_bakery_served
                " . self::baseFrom() . " WHERE {$where}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $revenue = round((float) $row['revenue'], 4);
        $hpp = round((float) $row['hpp'], 4);
        $margin = round($revenue - $hpp, 4);

        return [
            'total_revenue' => $revenue,
            'total_hpp' => $hpp,
            'total_margin' => $margin,
            'margin_pct' => $revenue > 0 ? round(($margin / $revenue) * 100, 4) : 0.0,
            'total_do' => (int) $row['total_do'],
            'total_invoice' => (int) $row['total_invoice'],
            'total_bakery_served' => (int) $row['total_bakery_served'],
            'discrepancy_count' => self::discrepancyCount($pdo, $filters),
        ];
    }

    public static function byCategory(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);
        $sql = "SELECT
                    dil.category_id_snapshot AS category_id, c.name AS category_name,
                    COALESCE(SUM(dil.subtotal), 0) AS revenue,
                    COALESCE(SUM(itl.unit_cost_base * dol.qty_sent_base), 0) AS hpp,
                    COALESCE(SUM(dil.qty), 0) AS qty_distributed
                " . self::baseFrom() . "
                WHERE {$where}
                GROUP BY dil.category_id_snapshot, c.name
                ORDER BY revenue DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'shapeAggregate'], $stmt->fetchAll());
    }

    public static function byBakery(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);
        $sql = "SELECT
                    bd.id AS bakery_id, bd.name AS bakery_name,
                    COUNT(DISTINCT do.id) AS do_count, COUNT(DISTINCT di.id) AS invoice_count,
                    COALESCE(SUM(dil.subtotal), 0) AS revenue,
                    COALESCE(SUM(itl.unit_cost_base * dol.qty_sent_base), 0) AS hpp,
                    COALESCE(SUM(dil.qty), 0) AS qty_distributed
                " . self::baseFrom() . "
                WHERE {$where}
                GROUP BY bd.id, bd.name
                ORDER BY revenue DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = self::shapeAggregate($row);
            $row['bakery_id'] = (int) $row['bakery_id'];
            $row['do_count'] = (int) $row['do_count'];
            $row['invoice_count'] = (int) $row['invoice_count'];
            $row['discrepancy_count'] = self::discrepancyCount($pdo, array_merge($filters, ['bakery_destination_id' => $row['bakery_id']]));
        }
        unset($row);

        return $rows;
    }

    /**
     * Discrepancy count is an OPERATIONAL fact (a receiving-time
     * difference), never gated on invoice status the way revenue/HPP are
     * — a DO can have a real discrepancy whether or not it has been
     * invoiced yet.
     */
    private static function discrepancyCount(PDO $pdo, array $filters): int
    {
        $where = ['dol.difference_qty_base IS NOT NULL', 'dol.difference_qty_base <> 0'];
        $params = [];
        if (!empty($filters['date_from'])) { $where[] = 'do.do_date >= :date_from'; $params['date_from'] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where[] = 'do.do_date <= :date_to'; $params['date_to'] = $filters['date_to']; }
        if (!empty($filters['bakery_destination_id'])) { $where[] = 'do.bakery_destination_id = :bakery_id'; $params['bakery_id'] = (int) $filters['bakery_destination_id']; }

        $sql = 'SELECT COUNT(*) FROM distribution_order_lines dol
                JOIN distribution_orders do ON do.id = dol.do_id
                WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private static function baseFrom(): string
    {
        return 'FROM distribution_invoice_lines dil
                JOIN distribution_invoices di ON di.id = dil.invoice_id
                JOIN distribution_orders do ON do.id = di.do_id
                JOIN bakery_destinations bd ON bd.id = di.bakery_destination_id
                JOIN distribution_order_lines dol ON dol.id = dil.do_line_id
                JOIN inventory_transaction_lines itl ON itl.id = dol.out_transaction_line_id
                LEFT JOIN categories c ON c.id = dil.category_id_snapshot
                JOIN units u ON u.id = dil.unit_id';
    }

    private static function baseSelect(): string
    {
        return 'SELECT
                    dil.id AS line_id, di.id AS invoice_id, di.invoice_number, di.invoice_date, di.status AS invoice_status,
                    do.id AS do_id, do.do_number, do.do_date,
                    bd.id AS bakery_id, bd.name AS bakery_name,
                    dil.item_id, dil.sku_snapshot, dil.item_name_snapshot,
                    dil.category_id_snapshot AS category_id, c.name AS category_name,
                    dil.qty, u.code AS unit_code,
                    dil.reference_purchase_price, dil.pricing_source, dil.pricing_method, dil.margin_value,
                    dil.selling_unit_price, dil.subtotal AS revenue,
                    itl.unit_cost_base AS actual_unit_cost, dol.qty_sent_base,
                    (itl.unit_cost_base * dol.qty_sent_base) AS actual_hpp
                ' . self::baseFrom();
    }

    /** @return array{string, array<string,mixed>} */
    private static function buildWhere(array $filters): array
    {
        // Part 34: only ISSUED invoices are economically valid revenue —
        // a caller may widen this explicitly (e.g. an admin auditing
        // DRAFT invoices) but the default is always ISSUED-only.
        $where = ['di.status = :invoice_status'];
        $params = ['invoice_status' => $filters['invoice_status'] ?? 'ISSUED'];

        if (!empty($filters['date_from'])) { $where[] = 'do.do_date >= :date_from'; $params['date_from'] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where[] = 'do.do_date <= :date_to'; $params['date_to'] = $filters['date_to']; }
        if (!empty($filters['bakery_destination_id'])) { $where[] = 'bd.id = :bakery_id'; $params['bakery_id'] = (int) $filters['bakery_destination_id']; }
        if (!empty($filters['category_id'])) { $where[] = 'dil.category_id_snapshot = :category_id'; $params['category_id'] = (int) $filters['category_id']; }
        if (!empty($filters['item_id'])) { $where[] = 'dil.item_id = :item_id'; $params['item_id'] = (int) $filters['item_id']; }
        if (!empty($filters['pricing_method'])) { $where[] = 'dil.pricing_method = :pricing_method'; $params['pricing_method'] = $filters['pricing_method']; }

        return [implode(' AND ', $where), $params];
    }

    private static function shapeLine(array $row): array
    {
        $revenue = round((float) $row['revenue'], 4);
        $hpp = round((float) $row['actual_hpp'], 4);
        $row['actual_hpp'] = $hpp;
        $row['actual_margin'] = round($revenue - $hpp, 4);
        $row['actual_margin_pct'] = $revenue > 0 ? round(($row['actual_margin'] / $revenue) * 100, 4) : 0.0;
        return $row;
    }

    private static function shapeAggregate(array $row): array
    {
        $revenue = round((float) $row['revenue'], 4);
        $hpp = round((float) $row['hpp'], 4);
        return [
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'category_name' => $row['category_name'] ?? null,
            'bakery_id' => $row['bakery_id'] ?? null,
            'bakery_name' => $row['bakery_name'] ?? null,
            'do_count' => $row['do_count'] ?? null,
            'invoice_count' => $row['invoice_count'] ?? null,
            'revenue' => $revenue,
            'hpp' => $hpp,
            'margin' => round($revenue - $hpp, 4),
            'margin_pct' => $revenue > 0 ? round((($revenue - $hpp) / $revenue) * 100, 4) : 0.0,
            'qty_distributed' => round((float) $row['qty_distributed'], 6),
        ];
    }
}
