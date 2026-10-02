<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16.4/V2.16.5 — "Laporan Stock Opname": monthly/session
 * reporting over Stock Opname sessions for finance/accounting/audit,
 * separate from the "Proses Stock Opname" admin workflow
 * (StockOpnameService) and from the pre-existing P1/P2 dual-count report
 * (StockOpnameReportService, still used unchanged by GET /reports/opname).
 *
 * READ-ONLY. Never writes stock_opname_lines/stock_opname_sessions,
 * stock_adjustments, inventory_transactions, or inventory_batches.
 *
 * PHASE V2.16.5 CORRECTIVE — the authoritative "Stok Sistem"/"Stok Fisik
 * Final"/"Selisih" source is counting_model-DEPENDENT, and this class must
 * never read the wrong one:
 *
 *   - LEGACY_DUAL_COUNT: system_qty_base/counted_qty_base/variance_qty_base
 *     remain the authoritative source, exactly as V2.16.4 originally used —
 *     finalize() computes variance_qty_base = counted_qty_base -
 *     system_qty_base for this model, and that is this model's real,
 *     approved book-vs-physical comparison. UNCHANGED by this corrective.
 *
 *   - FINDINGS_V1: system_qty_base is only a session-START snapshot, NOT
 *     the authoritative EOD book stock — and finalize()/post() UNCONDITION-
 *     ALLY refuse to run for a FINDINGS_V1 session in this codebase
 *     (StockOpnameService::assertNotFindingsV1(), "Checkpoint B's entire
 *     scope" is explicitly not yet implemented here), so variance_qty_base
 *     is NEVER populated for this model — reading it would silently show
 *     0/NULL variance for every FINDINGS_V1 line regardless of the real
 *     result. The existing, approved authoritative source for this model
 *     is StockOpnameBookStockService::reconciliation() — the exact same
 *     book_stock_eod/final_physical_eod/variance computation already
 *     consumed by GET /stock-opname/{id}/eod-reconciliation and by
 *     StockOpnameFinalExportService's "Rekonsiliasi Final" export. This
 *     class calls that SAME method rather than re-deriving a second,
 *     independent formula.
 *
 * Internal cost note: StockOpnameBookStockService::reconciliation() is
 * O(lines) in query count (it already is, for the existing Rekonsiliasi
 * Final export) — calling it once per detail() request for a FINDINGS_V1
 * session carries that same, already-accepted cost; this class does not
 * try to out-optimize it (that would itself risk becoming a second,
 * subtly different formula).
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
        $countingModel = $session['counting_model'] ?? 'LEGACY_DUAL_COUNT';
        $isFindingsV1 = $countingModel === 'FINDINGS_V1';

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

        // PHASE V2.16.5 — computed ONCE per request (session-wide, not
        // filtered — StockOpnameBookStockService::reconciliation() has no
        // filter parameter, same as the existing Rekonsiliasi Final export
        // that already calls it this way), then reused for the paginated
        // item rows below AND for the finance/category summaries, so a
        // FINDINGS_V1 session never pays for this twice in one request.
        $reconBySku = $isFindingsV1 ? self::reconciliationBySku($pdo, $sessionId) : null;

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
        $lines = array_map(static fn (array $l) => self::formatLine($l, $isFindingsV1, $reconBySku), $stmt->fetchAll());

        if ($isFindingsV1) {
            [$financeSummary, $categorySummary] = self::findingsV1Summaries($pdo, $sessionId, $filters, $reconBySku);
        } else {
            $financeSummary = self::financeSummary($pdo, $sessionId, $filters);
            $categorySummary = self::categorySummary($pdo, $sessionId, $filters);
        }

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
                'stock_source_label' => $isFindingsV1 ? 'Rekonsiliasi EOD (Stok Buku)' : 'Snapshot Sistem (P1/P2)',
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

    /**
     * @param array<string,array<string,mixed>>|null $reconBySku only set
     *     (and only consulted) for a FINDINGS_V1 session — see this
     *     class's own docblock for why LEGACY_DUAL_COUNT never touches it.
     */
    private static function formatLine(array $l, bool $isFindingsV1, ?array $reconBySku): array
    {
        $unitCost = (float) $l['unit_cost_base'];

        if ($isFindingsV1) {
            $recon = ($reconBySku ?? [])[$l['sku']] ?? null;
            // book_stock_eod/variance are null only when this SKU's EOD
            // baseline/movement reconciliation is incomplete — never
            // fabricated as 0 (same null-propagation the existing
            // Rekonsiliasi Final export already uses).
            $systemQty = $recon['book_stock_eod'] ?? null;
            $physicalQty = $recon['final_physical_eod'] ?? null;
            $varianceQty = $recon['variance'] ?? null;
        } else {
            $systemQty = (float) $l['system_qty_base'];
            $physicalQty = $l['counted_qty_base'] !== null ? (float) $l['counted_qty_base'] : null;
            $varianceQty = $l['variance_qty_base'] !== null ? (float) $l['variance_qty_base'] : null;
        }

        $systemValue = $systemQty !== null ? round($systemQty * $unitCost, 2) : null;
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
            'kondisi' => self::kondisi($l, $varianceQty, $rusak, $expired, $deadstock, $isFindingsV1),
            'keterangan' => $l['final_notes'] ?? $l['notes'],
        ];
    }

    private static function kondisi(array $l, ?float $varianceQty, float $rusak, float $expired, float $deadstock, bool $isFindingsV1): string
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
            // FINDINGS_V1: the EOD book-stock reconciliation for this SKU
            // is not complete yet (no baseline match) — distinct from
            // "nobody counted it", which can't happen on a POSTED session.
            return $isFindingsV1 ? 'Belum Direkonsiliasi' : 'Belum Dihitung';
        }
        if (abs($varianceQty) < 0.0000001) {
            return 'Sesuai';
        }
        if ($varianceQty > 0) {
            return 'Lebih (+)';
        }
        return 'Kurang (-)';
    }

    /**
     * LEGACY_DUAL_COUNT only — see this class's docblock for why
     * FINDINGS_V1 uses findingsV1Summaries() instead. "Sesuai" is the
     * authoritative variance being exactly zero (variance_qty_base = 0),
     * never match_status='MATCH' alone — P1/P2 agreeing with EACH OTHER
     * is not the same claim as the agreed physical count matching the
     * system snapshot (e.g. both P1 and P2 independently count 95 against
     * a system snapshot of 100: match_status is MATCH, but there is a
     * real -5 variance against the book figure).
     */
    private static function financeSummary(PDO $pdo, int $sessionId, array $filters): array
    {
        [$where, $params] = self::buildLineWhere($sessionId, $filters);
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_item_scope,
                SUM(CASE WHEN sol.variance_qty_base = 0 THEN 1 ELSE 0 END) AS sesuai,
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

    /** LEGACY_DUAL_COUNT only — see findingsV1Summaries() for FINDINGS_V1. */
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

    /**
     * FINDINGS_V1 only. Builds BOTH the finance summary and the category
     * summary in a single pass over the session's FILTERED lines (not
     * paginated — same full-filtered-set scope the legacy SQL aggregates
     * above already use), joining each line's authoritative book_stock_eod
     * /final_physical_eod/variance from $reconBySku (computed once by the
     * caller via reconciliationBySku()). "Sesuai"/"Selisih (+)"/"Selisih
     * (-)" are bucketed by that authoritative variance, exactly like
     * financeSummary() above does for LEGACY_DUAL_COUNT — never by
     * match_status (FINDINGS_V1 doesn't even have one: match_status stays
     * at its PENDING default for a FINDINGS_V1 line, since only legacy's
     * submitCount() path writes it). A line whose reconciliation is
     * incomplete (variance null) falls into none of those three buckets —
     * same null-propagation StockOpnameBookStockService's own Ringkasan
     * sheet already uses ("Belum Dapat Direkonsiliasi"), not an invented
     * exclusion rule.
     *
     * @param array<string,array<string,mixed>> $reconBySku
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private static function findingsV1Summaries(PDO $pdo, int $sessionId, array $filters, array $reconBySku): array
    {
        [$where, $params] = self::buildLineWhere($sessionId, $filters);
        $stmt = $pdo->prepare(
            "SELECT sol.item_id, sol.unit_cost_base, sol.is_excluded,
                    sol.final_rusak_qty, sol.final_expired_qty, sol.final_deadstock_qty,
                    i.sku, COALESCE(c.name, '(Tanpa Kategori)') AS category
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE {$where}
              ORDER BY category"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalItemScope = 0;
        $sesuai = 0;
        $selisihPlus = 0;
        $selisihMinus = 0;
        $rusakCount = 0;
        $expiredCount = 0;
        $deadstockCount = 0;
        $nilaiSistemTotal = 0.0;
        $nilaiFisikTotal = 0.0;
        $qtySistemTotal = 0.0;
        $qtyFisikTotal = 0.0;

        $byCategory = [];

        foreach ($rows as $r) {
            $recon = $reconBySku[$r['sku']] ?? null;
            $systemQty = $recon['book_stock_eod'] ?? null;
            $physicalQty = $recon['final_physical_eod'] ?? null;
            $variance = $recon['variance'] ?? null;
            $unitCost = (float) $r['unit_cost_base'];

            $nilaiSistem = $systemQty !== null ? $systemQty * $unitCost : null;
            $nilaiFisik = $physicalQty !== null ? $physicalQty * $unitCost : null;

            $totalItemScope++;
            if ($variance !== null) {
                if (abs($variance) < 0.0000001) {
                    $sesuai++;
                } elseif ($variance > 0) {
                    $selisihPlus++;
                } else {
                    $selisihMinus++;
                }
            }
            if ((float) $r['final_rusak_qty'] > 0) {
                $rusakCount++;
            }
            if ((float) $r['final_expired_qty'] > 0) {
                $expiredCount++;
            }
            if ((float) $r['final_deadstock_qty'] > 0) {
                $deadstockCount++;
            }
            if ($systemQty !== null) {
                $qtySistemTotal += $systemQty;
                $nilaiSistemTotal += $nilaiSistem;
            }
            if ($physicalQty !== null) {
                $qtyFisikTotal += $physicalQty;
                $nilaiFisikTotal += $nilaiFisik;
            }

            $cat = $r['category'];
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['category' => $cat, 'total_item' => 0, 'qty_sistem' => 0.0, 'nilai_sistem' => 0.0, 'qty_fisik' => 0.0, 'nilai_fisik' => 0.0];
            }
            $byCategory[$cat]['total_item']++;
            if ($systemQty !== null) {
                $byCategory[$cat]['qty_sistem'] += $systemQty;
                $byCategory[$cat]['nilai_sistem'] += $nilaiSistem;
            }
            if ($physicalQty !== null) {
                $byCategory[$cat]['qty_fisik'] += $physicalQty;
                $byCategory[$cat]['nilai_fisik'] += $nilaiFisik;
            }
        }

        $financeSummary = [
            'total_item_scope' => $totalItemScope,
            'sesuai' => $sesuai,
            'selisih_plus' => $selisihPlus,
            'selisih_minus' => $selisihMinus,
            'rusak' => $rusakCount,
            'expired' => $expiredCount,
            'deadstock' => $deadstockCount,
            'nilai_stok_sistem' => round($nilaiSistemTotal, 2),
            'nilai_stok_fisik_final' => round($nilaiFisikTotal, 2),
            'selisih_nilai' => round($nilaiFisikTotal - $nilaiSistemTotal, 2),
            'qty_sistem' => round($qtySistemTotal, 6),
            'qty_fisik_final' => round($qtyFisikTotal, 6),
            'selisih_qty' => round($qtyFisikTotal - $qtySistemTotal, 6),
        ];

        $categorySummary = [];
        foreach ($byCategory as $c) {
            $categorySummary[] = [
                'category' => $c['category'],
                'total_item' => $c['total_item'],
                'qty_sistem' => round($c['qty_sistem'], 6),
                'nilai_sistem' => round($c['nilai_sistem'], 2),
                'qty_fisik' => round($c['qty_fisik'], 6),
                'nilai_fisik' => round($c['nilai_fisik'], 2),
                'selisih_qty' => round($c['qty_fisik'] - $c['qty_sistem'], 6),
                'selisih_nilai' => round($c['nilai_fisik'] - $c['nilai_sistem'], 2),
            ];
        }
        $categorySummary[] = [
            'category' => 'TOTAL',
            'total_item' => $totalItemScope,
            'qty_sistem' => round($qtySistemTotal, 6),
            'nilai_sistem' => round($nilaiSistemTotal, 2),
            'qty_fisik' => round($qtyFisikTotal, 6),
            'nilai_fisik' => round($nilaiFisikTotal, 2),
            'selisih_qty' => round($qtyFisikTotal - $qtySistemTotal, 6),
            'selisih_nilai' => round($nilaiFisikTotal - $nilaiSistemTotal, 2),
            'is_total_row' => true,
        ];

        return [$financeSummary, $categorySummary];
    }

    /**
     * Keys StockOpnameBookStockService::reconciliation()'s own rows by
     * SKU (unique per session — stock_opname_lines has a UNIQUE KEY on
     * (session_id, item_id), and item.sku is unique) so callers can join
     * back to a stock_opname_lines row without touching that service's
     * internals or re-deriving its formula.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function reconciliationBySku(PDO $pdo, int $sessionId): array
    {
        $bySku = [];
        foreach (StockOpnameBookStockService::reconciliation($pdo, $sessionId) as $row) {
            $bySku[$row['sku']] = $row;
        }
        return $bySku;
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
