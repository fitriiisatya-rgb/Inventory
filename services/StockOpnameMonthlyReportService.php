<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16.4 — "Laporan Stock Opname": monthly/session reporting over
 * Stock Opname sessions for finance/accounting/audit, separate from the
 * "Proses Stock Opname" admin workflow (StockOpnameService) and from the
 * pre-existing P1/P2 dual-count report (StockOpnameReportService, still
 * used unchanged by GET /reports/opname).
 *
 * READ-ONLY. Never writes stock_opname_lines/stock_opname_sessions,
 * stock_adjustments, inventory_transactions, or inventory_batches — every
 * method here is a SELECT over data StockOpnameService::finalize()/post()
 * already wrote. No new value formula is invented: system/physical/
 * variance qty and value are read directly from stock_opname_lines'
 * existing authoritative columns (system_qty_base, counted_qty_base,
 * variance_qty_base, unit_cost_base, final_rusak_qty/final_expired_qty/
 * final_deadstock_qty, final_notes) — the same columns
 * StockOpnameFinalExportService::buildFinalSoRow() already treats as the
 * "final" result.
 *
 * HPP / Unit Cost is NEVER exposed by this service: unit_cost_base is read
 * only to multiply into a Rupiah VALUE (nilai), never returned as its own
 * field. Every array this service returns is safe to serialize directly
 * into an HPP-free report.
 */
final class StockOpnameMonthlyReportService
{
    /**
     * @param array{warehouse_id?:?int, month?:?int, year?:?int, category_id?:?int,
     *              session_id?:?int, status?:?string, search?:?string,
     *              page?:int, per_page?:int} $filters
     */
    public static function listSessions(PDO $pdo, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        [$where, $params] = self::buildSessionWhere($filters);

        $countSql = "SELECT COUNT(*) FROM stock_opname_sessions sos WHERE {$where}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT sos.id, sos.session_number, sos.session_date, sos.warehouse_id, sos.status,
                       sos.counting_model, sos.finalized_at, sos.posted_at,
                       w.name AS warehouse_name, w.code AS warehouse_code,
                       (SELECT COUNT(*) FROM stock_opname_lines sol WHERE sol.session_id = sos.id) AS total_item
                  FROM stock_opname_sessions sos
                  JOIN warehouses w ON w.id = sos.warehouse_id
                 WHERE {$where}
                 ORDER BY sos.session_date DESC, sos.id DESC
                 LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $sessions = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'session_number' => $r['session_number'] ?? ('OPN-' . $r['id']),
                'session_date' => $r['session_date'],
                'warehouse_id' => (int) $r['warehouse_id'],
                'warehouse_name' => $r['warehouse_name'],
                'warehouse_code' => $r['warehouse_code'],
                'status' => $r['status'],
                'counting_model' => $r['counting_model'],
                'total_item' => (int) $r['total_item'],
                'finalized_at' => $r['finalized_at'],
                'posted_at' => $r['posted_at'],
            ];
        }, $rows);

        return [
            'sessions' => $sessions,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * @param array{category_id?:?int, search?:?string, page?:int, per_page?:int} $filters
     */
    public static function detail(PDO $pdo, int $sessionId, array $filters): array
    {
        $session = self::loadSession($pdo, $sessionId);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        [$where, $params] = self::buildLineWhere($sessionId, $filters);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
              WHERE {$where}"
        );
        $countStmt->execute($params);
        $totalItems = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT sol.*, i.sku, i.name, u.code AS base_unit_code, c.name AS category_name
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE {$where}
              ORDER BY i.sku
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $lines = array_map([self::class, 'formatLine'], $stmt->fetchAll());

        $financeSummary = self::financeSummary($pdo, $sessionId, $filters);
        $categorySummary = self::categorySummary($pdo, $sessionId, $filters);

        $warehouseStmt = $pdo->prepare('SELECT name, code FROM warehouses WHERE id = :id');
        $warehouseStmt->execute(['id' => (int) $session['warehouse_id']]);
        $warehouse = $warehouseStmt->fetch();

        $finalizedBy = $session['finalized_by'] ? self::username($pdo, (int) $session['finalized_by']) : null;
        $postedBy = $session['posted_by'] ? self::username($pdo, (int) $session['posted_by']) : null;

        return [
            'session' => [
                'id' => (int) $session['id'],
                'session_number' => $session['session_number'] ?? ('OPN-' . $session['id']),
                'session_date' => $session['session_date'],
                'warehouse_id' => (int) $session['warehouse_id'],
                'warehouse_name' => $warehouse['name'] ?? null,
                'warehouse_code' => $warehouse['code'] ?? null,
                'status' => $session['status'],
                'counting_model' => $session['counting_model'],
                'finalized_at' => $session['finalized_at'],
                'finalized_by' => $finalizedBy,
                'posted_at' => $session['posted_at'],
                'posted_by' => $postedBy,
            ],
            'items' => $lines,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalItems,
            'total_pages' => $perPage > 0 ? (int) ceil($totalItems / $perPage) : 0,
            'finance_summary' => $financeSummary,
            'category_summary' => $categorySummary,
        ];
    }

    private static function formatLine(array $l): array
    {
        $systemQty = (float) $l['system_qty_base'];
        $physicalQty = $l['counted_qty_base'] !== null ? (float) $l['counted_qty_base'] : null;
        $varianceQty = $l['variance_qty_base'] !== null ? (float) $l['variance_qty_base'] : null;
        $unitCost = (float) $l['unit_cost_base'];

        $systemValue = round($systemQty * $unitCost, 2);
        $physicalValue = $physicalQty !== null ? round($physicalQty * $unitCost, 2) : null;
        $varianceValue = $varianceQty !== null ? round($varianceQty * $unitCost, 2) : null;

        $rusak = $l['final_rusak_qty'] !== null ? (float) $l['final_rusak_qty'] : 0.0;
        $expired = $l['final_expired_qty'] !== null ? (float) $l['final_expired_qty'] : 0.0;
        $deadstock = $l['final_deadstock_qty'] !== null ? (float) $l['final_deadstock_qty'] : 0.0;

        return [
            'item_id' => (int) $l['item_id'],
            'sku' => $l['sku'],
            'name' => $l['name'],
            'category' => $l['category_name'] ?? '(Tanpa Kategori)',
            'unit' => $l['base_unit_code'],
            'system_qty' => $systemQty,
            'system_value' => $systemValue,
            'physical_qty' => $physicalQty,
            'physical_value' => $physicalValue,
            'variance_qty' => $varianceQty,
            'variance_value' => $varianceValue,
            'rusak_qty' => $rusak,
            'expired_qty' => $expired,
            'deadstock_qty' => $deadstock,
            'kondisi' => self::kondisi($l, $varianceQty, $rusak, $expired, $deadstock),
            'keterangan' => $l['final_notes'] ?? $l['notes'],
        ];
    }

    private static function kondisi(array $l, ?float $varianceQty, float $rusak, float $expired, float $deadstock): string
    {
        if ((int) $l['is_excluded'] === 1) {
            return 'Dikecualikan';
        }
        if ($deadstock > 0) {
            return 'Deadstock';
        }
        if ($expired > 0) {
            return 'Expired';
        }
        if ($rusak > 0) {
            return 'Rusak';
        }
        if ($varianceQty === null) {
            return 'Belum Dihitung';
        }
        if ($varianceQty > 0) {
            return 'Lebih (+)';
        }
        if ($varianceQty < 0) {
            return 'Kurang (-)';
        }
        return 'Sesuai';
    }

    private static function financeSummary(PDO $pdo, int $sessionId, array $filters): array
    {
        [$where, $params] = self::buildLineWhere($sessionId, $filters);
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_item_scope,
                SUM(CASE WHEN sol.match_status = 'MATCH' THEN 1 ELSE 0 END) AS sesuai,
                SUM(CASE WHEN sol.variance_qty_base > 0 THEN 1 ELSE 0 END) AS selisih_plus,
                SUM(CASE WHEN sol.variance_qty_base < 0 THEN 1 ELSE 0 END) AS selisih_minus,
                SUM(CASE WHEN sol.final_rusak_qty > 0 THEN 1 ELSE 0 END) AS rusak_count,
                SUM(CASE WHEN sol.final_expired_qty > 0 THEN 1 ELSE 0 END) AS expired_count,
                SUM(CASE WHEN sol.final_deadstock_qty > 0 THEN 1 ELSE 0 END) AS deadstock_count,
                SUM(sol.system_qty_base * sol.unit_cost_base) AS nilai_sistem,
                SUM(COALESCE(sol.counted_qty_base, 0) * sol.unit_cost_base) AS nilai_fisik,
                SUM(sol.system_qty_base) AS qty_sistem,
                SUM(COALESCE(sol.counted_qty_base, 0)) AS qty_fisik
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
              WHERE {$where}"
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];

        $nilaiSistem = round((float) ($r['nilai_sistem'] ?? 0), 2);
        $nilaiFisik = round((float) ($r['nilai_fisik'] ?? 0), 2);
        $qtySistem = round((float) ($r['qty_sistem'] ?? 0), 6);
        $qtyFisik = round((float) ($r['qty_fisik'] ?? 0), 6);

        return [
            'total_item_scope' => (int) ($r['total_item_scope'] ?? 0),
            'sesuai' => (int) ($r['sesuai'] ?? 0),
            'selisih_plus' => (int) ($r['selisih_plus'] ?? 0),
            'selisih_minus' => (int) ($r['selisih_minus'] ?? 0),
            'rusak' => (int) ($r['rusak_count'] ?? 0),
            'expired' => (int) ($r['expired_count'] ?? 0),
            'deadstock' => (int) ($r['deadstock_count'] ?? 0),
            'nilai_stok_sistem' => $nilaiSistem,
            'nilai_stok_fisik_final' => $nilaiFisik,
            'selisih_nilai' => round($nilaiFisik - $nilaiSistem, 2),
            'qty_sistem' => $qtySistem,
            'qty_fisik_final' => $qtyFisik,
            'selisih_qty' => round($qtyFisik - $qtySistem, 6),
        ];
    }

    private static function categorySummary(PDO $pdo, int $sessionId, array $filters): array
    {
        [$where, $params] = self::buildLineWhere($sessionId, $filters);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(c.name, '(Tanpa Kategori)') AS category,
                    COUNT(*) AS total_item,
                    SUM(sol.system_qty_base) AS qty_sistem,
                    SUM(sol.system_qty_base * sol.unit_cost_base) AS nilai_sistem,
                    SUM(COALESCE(sol.counted_qty_base, 0)) AS qty_fisik,
                    SUM(COALESCE(sol.counted_qty_base, 0) * sol.unit_cost_base) AS nilai_fisik
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE {$where}
              GROUP BY COALESCE(c.id, 0), c.name
              ORDER BY category"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $categories = [];
        $totalItem = 0;
        $totalQtySistem = 0.0;
        $totalNilaiSistem = 0.0;
        $totalQtyFisik = 0.0;
        $totalNilaiFisik = 0.0;

        foreach ($rows as $r) {
            $qtySistem = round((float) $r['qty_sistem'], 6);
            $nilaiSistem = round((float) $r['nilai_sistem'], 2);
            $qtyFisik = round((float) $r['qty_fisik'], 6);
            $nilaiFisik = round((float) $r['nilai_fisik'], 2);

            $categories[] = [
                'category' => $r['category'],
                'total_item' => (int) $r['total_item'],
                'qty_sistem' => $qtySistem,
                'nilai_sistem' => $nilaiSistem,
                'qty_fisik' => $qtyFisik,
                'nilai_fisik' => $nilaiFisik,
                'selisih_qty' => round($qtyFisik - $qtySistem, 6),
                'selisih_nilai' => round($nilaiFisik - $nilaiSistem, 2),
            ];

            $totalItem += (int) $r['total_item'];
            $totalQtySistem += $qtySistem;
            $totalNilaiSistem += $nilaiSistem;
            $totalQtyFisik += $qtyFisik;
            $totalNilaiFisik += $nilaiFisik;
        }

        $categories[] = [
            'category' => 'TOTAL',
            'total_item' => $totalItem,
            'qty_sistem' => round($totalQtySistem, 6),
            'nilai_sistem' => round($totalNilaiSistem, 2),
            'qty_fisik' => round($totalQtyFisik, 6),
            'nilai_fisik' => round($totalNilaiFisik, 2),
            'selisih_qty' => round($totalQtyFisik - $totalQtySistem, 6),
            'selisih_nilai' => round($totalNilaiFisik - $totalNilaiSistem, 2),
            'is_total_row' => true,
        ];

        return $categories;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function buildSessionWhere(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'sos.warehouse_id = :warehouse_id';
            $params[':warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(sos.session_date) = :month';
            $params[':month'] = (int) $filters['month'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(sos.session_date) = :year';
            $params[':year'] = (int) $filters['year'];
        }
        $where[] = 'sos.status = :status';
        $params[':status'] = $filters['status'] ?? 'POSTED';

        $sessionSearch = trim((string) ($filters['session_search'] ?? ''));
        if ($sessionSearch !== '') {
            $where[] = '(sos.session_number LIKE :session_search OR sos.id = :session_search_id)';
            $params[':session_search'] = '%' . $sessionSearch . '%';
            $params[':session_search_id'] = ctype_digit($sessionSearch) ? (int) $sessionSearch : -1;
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM stock_opname_lines sol2 JOIN items i2 ON i2.id = sol2.item_id
                                 WHERE sol2.session_id = sos.id AND i2.category_id = :category_id)';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // PDO_MySQL runs with ATTR_EMULATE_PREPARES=false (see
            // Database.php) — native prepared statements do NOT support
            // binding the same named placeholder more than once, so each
            // occurrence below gets its own uniquely-named copy of the
            // identical search value.
            $where[] = 'EXISTS (SELECT 1 FROM stock_opname_lines sol3 JOIN items i3 ON i3.id = sol3.item_id
                                 WHERE sol3.session_id = sos.id
                                   AND (i3.sku LIKE :search_sku OR i3.name LIKE :search_name
                                        OR EXISTS (SELECT 1 FROM item_barcodes ib WHERE ib.item_id = i3.id AND ib.barcode LIKE :search_barcode)))';
            $params[':search_sku'] = '%' . $search . '%';
            $params[':search_name'] = '%' . $search . '%';
            $params[':search_barcode'] = '%' . $search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function buildLineWhere(int $sessionId, array $filters): array
    {
        $where = ['sol.session_id = :session_id'];
        $params = [':session_id' => $sessionId];

        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // See buildSessionWhere()'s identical note on ATTR_EMULATE_PREPARES.
            $where[] = '(i.sku LIKE :search_sku OR i.name LIKE :search_name
                          OR EXISTS (SELECT 1 FROM item_barcodes ib WHERE ib.item_id = i.id AND ib.barcode LIKE :search_barcode))';
            $params[':search_sku'] = '%' . $search . '%';
            $params[':search_name'] = '%' . $search . '%';
            $params[':search_barcode'] = '%' . $search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function loadSession(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException("opname session {$sessionId} not found");
        }
        return $row;
    }

    private static function username(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
