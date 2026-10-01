<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16 — Final SO Excel export (Section H-M). Two modes:
 *   - draft():   available on ANY session, at any status. Always carries
 *                the literal visible text "DRAFT — BELUM FINAL" on
 *                Sheet 1. Never claims to be the official result.
 *   - official(): refused unless the session is POSTED (which, for a
 *                 FINDINGS_V1 session, is structurally impossible today —
 *                 StockOpnameService::post() calls assertNotFindingsV1()
 *                 unconditionally; see FindingsCheckpointBRequiredException
 *                 — so this method NEVER weakens or works around that
 *                 gate; it simply reads the EXISTING status machine's own
 *                 POSTED gate, which already enforces "Checkpoint B must
 *                 be complete first" for FINDINGS_V1 on its own).
 *
 * Reads ONLY. Writes nothing to inventory_batches/stock_adjustments/
 * stock_opname_lines/findings/reference_* — purely a report over
 * existing, already-written data.
 *
 * Immutability (Section O): once a session is POSTED, finalize()/post()
 * can never run again on it (finalize() requires status=OPEN; post()'s
 * own idempotent-replay branch never re-touches stock_opname_lines), so
 * every business-value column read here is already frozen — re-running
 * official() on the same POSTED session twice yields identical business
 * values, differing only in exported_at/exported_by (both of which are
 * themselves stamped into Sheet 1 precisely so two exports are
 * distinguishable without the underlying figures ever moving).
 */
final class StockOpnameFinalExportService
{
    public static function draft(PDO $pdo, int $sessionId, int $userId): string
    {
        return self::build($pdo, $sessionId, $userId, false);
    }

    public static function official(PDO $pdo, int $sessionId, int $userId): string
    {
        $session = self::loadSession($pdo, $sessionId);
        if ($session['status'] !== 'POSTED') {
            if (($session['counting_model'] ?? 'LEGACY_DUAL_COUNT') === 'FINDINGS_V1') {
                throw new FindingsCheckpointBRequiredException(
                    "official Final SO export requires this FINDINGS_V1 session to be POSTED, which cannot happen until the Checkpoint B reconciliation/final-result workflow is completed — use the draft export in the meantime"
                );
            }
            throw new ValidationException(["official Final SO export requires the session to be POSTED (currently {$session['status']}) — use the draft export until then"]);
        }
        return self::build($pdo, $sessionId, $userId, true);
    }

    private static function build(PDO $pdo, int $sessionId, int $userId, bool $isOfficial): string
    {
        $session = self::loadSession($pdo, $sessionId);
        $lines = self::loadLines($pdo, $sessionId);
        $scmByItem = StockOpnameReferenceImportService::scmAdjustedReferenceByItem($pdo, $sessionId);
        $lateMovesByItem = self::lateMovesByItem($pdo, $sessionId);

        $rows = [];
        foreach ($lines as $i => $l) {
            $rows[] = self::buildFinalSoRow($i + 1, $l, $scmByItem[(int) $l['item_id']] ?? null, $lateMovesByItem[(int) $l['item_id']] ?? null);
        }

        $exportedBy = self::username($pdo, $userId);
        $sheets = [
            'Ringkasan' => self::buildRingkasanSheet($pdo, $session, $lines, $rows, $exportedBy, $isOfficial),
            'Final SO' => self::buildFinalSoSheet($rows, $isOfficial),
            'Selisih & Masalah' => self::buildIssuesSheet($rows),
            'Audit Trail' => self::buildAuditTrailSheet($pdo, $sessionId, $lines),
        ];

        $dir = sys_get_temp_dir() . '/so_export';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $prefix = $isOfficial ? 'FINAL' : 'DRAFT';
        $path = $dir . '/' . $prefix . '_' . ($session['session_number'] ?: "SO{$sessionId}") . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        ExcelWriterService::write($path, $sheets);
        return $path;
    }

    /** @return array{headers:list<string>, rows:list<list<mixed>>, freeze_header:bool, autofilter:bool, col_widths:list<float>} */
    private static function buildRingkasanSheet(PDO $pdo, array $session, array $lines, array $finalRows, string $exportedBy, bool $isOfficial): array
    {
        $totalSku = count($lines);
        $completed = 0;
        $match = 0;
        $mismatch = 0;
        $recount = 0;
        foreach ($lines as $l) {
            if ((int) $l['is_counted'] === 1) {
                $completed++;
            }
            if ($l['match_status'] === 'MATCH') {
                $match++;
            } elseif ($l['match_status'] === 'MISMATCH') {
                $mismatch++;
            } elseif ($l['match_status'] === 'RECOUNTED') {
                $recount++;
            }
        }
        $unresolved = ($totalSku - $completed) + $mismatch;

        $totalScm = 0.0;
        $totalGood = 0.0;
        $totalDamaged = 0.0;
        $totalExpired = 0.0;
        $totalDeadstock = 0.0;
        foreach ($finalRows as $r) {
            $totalScm += (float) ($r['scm_adjusted_reference'] ?? 0);
            $totalGood += (float) ($r['final_good'] ?? 0);
            $totalDamaged += (float) ($r['final_damaged'] ?? 0);
            $totalExpired += (float) ($r['final_expired'] ?? 0);
            $totalDeadstock += (float) ($r['final_deadstock'] ?? 0);
        }
        $totalPhysical = $totalGood + $totalDamaged + $totalExpired + $totalDeadstock;

        $warehouseName = self::warehouseName($pdo, (int) $session['warehouse_id']);
        $finalizedBy = $session['finalized_by'] ? self::username($pdo, (int) $session['finalized_by']) : null;

        $rows = [];
        if (!$isOfficial) {
            $rows[] = ['DRAFT — BELUM FINAL', 'Dokumen ini BUKAN hasil resmi. Data dapat berubah sebelum sesi difinalisasi/diposting.'];
            $rows[] = [null, null];
        }
        $rows = array_merge($rows, [
            ['SO Number', $session['session_number']],
            ['Warehouse', $warehouseName],
            ['Session Date', $session['session_date']],
            ['Cutoff At', $session['session_date'] . ' 23:59:59'],
            ['Started At', $session['created_at']],
            ['Completed At', $completed === $totalSku ? ($session['finalized_at'] ?? '') : ''],
            ['Finalized At', $session['finalized_at'] ?? ''],
            [null, null],
            ['Total SKU', $totalSku],
            ['Completed', $completed],
            ['Match', $match],
            ['Mismatch', $mismatch],
            ['Recount', $recount],
            ['Unresolved', $unresolved],
            [null, null],
            ['Total SCM Reference', round($totalScm, 6)],
            ['Total Final GOOD', round($totalGood, 6)],
            ['Total Damaged', round($totalDamaged, 6)],
            ['Total Expired', round($totalExpired, 6)],
            ['Total Deadstock', round($totalDeadstock, 6)],
            ['Total Physical', round($totalPhysical, 6)],
            [null, null],
            ['Finalized By', $finalizedBy],
            ['Exported By', $exportedBy],
            ['Exported At', date('Y-m-d H:i:s')],
        ]);

        return ['headers' => ['Field', 'Value'], 'rows' => $rows, 'freeze_header' => true, 'autofilter' => false, 'col_widths' => [28, 40]];
    }

    private static function buildFinalSoRow(int $no, array $l, ?array $scm, ?array $lateMoves): array
    {
        $p1Good = $l['p1_qty_base'] !== null ? (float) $l['p1_qty_base'] : null;
        $p1Damaged = $l['p1_rusak_qty'] !== null ? (float) $l['p1_rusak_qty'] : null;
        $p1Expired = $l['p1_expired_qty'] !== null ? (float) $l['p1_expired_qty'] : null;
        $p1Deadstock = $l['p1_deadstock_qty'] !== null ? (float) $l['p1_deadstock_qty'] : null;
        $p1Total = ($p1Good ?? 0) + ($p1Damaged ?? 0) + ($p1Expired ?? 0) + ($p1Deadstock ?? 0);

        $p2Good = $l['p2_qty_base'] !== null ? (float) $l['p2_qty_base'] : null;
        $p2Damaged = $l['p2_rusak_qty'] !== null ? (float) $l['p2_rusak_qty'] : null;
        $p2Expired = $l['p2_expired_qty'] !== null ? (float) $l['p2_expired_qty'] : null;
        $p2Deadstock = $l['p2_deadstock_qty'] !== null ? (float) $l['p2_deadstock_qty'] : null;
        $p2Total = ($p2Good ?? 0) + ($p2Damaged ?? 0) + ($p2Expired ?? 0) + ($p2Deadstock ?? 0);

        // Section J note: this schema only ever captures a single RECOUNT
        // Good quantity (recount_qty_base) — there is no per-condition
        // recount breakdown to report, so Recount Damaged/Expired/
        // Deadstock are left genuinely blank rather than fabricated as 0.
        $recountGood = $l['recount_qty_base'] !== null ? (float) $l['recount_qty_base'] : null;
        $recountTotal = $recountGood;

        $finalGood = $l['counted_qty_base'] !== null ? (float) $l['counted_qty_base'] : null;
        $finalDamaged = $l['final_rusak_qty'] !== null ? (float) $l['final_rusak_qty'] : null;
        $finalExpired = $l['final_expired_qty'] !== null ? (float) $l['final_expired_qty'] : null;
        $finalDeadstock = $l['final_deadstock_qty'] !== null ? (float) $l['final_deadstock_qty'] : null;
        $finalTotalPhysical = ($finalGood ?? 0) + ($finalDamaged ?? 0) + ($finalExpired ?? 0) + ($finalDeadstock ?? 0);

        $scmReference = $scm['scm_reference'] ?? null;
        $lateIn = $lateMoves['in_qty'] ?? 0.0;
        $lateOut = $lateMoves['out_qty'] ?? 0.0;
        $scalingAdj = $lateMoves['scaling_adjustment'] ?? 0.0;
        $scmAdjusted = $scm['scm_adjusted_reference'] ?? null;

        $physicalVariance = $scmAdjusted !== null ? round($finalTotalPhysical - $scmAdjusted, 6) : null;
        $goodVariance = ($scmAdjusted !== null && $finalGood !== null) ? round($finalGood - $scmAdjusted, 6) : null;

        $status = (int) $l['is_excluded'] === 1 ? 'EXCLUDED' : $l['match_status'];

        return [
            'no' => $no, 'item_id' => (int) $l['item_id'], 'sku' => $l['sku'], 'name' => $l['name'],
            'category' => $l['category_name'] ?? '(Tanpa Kategori)', 'base_unit' => $l['base_unit_code'],
            'scm_reference' => $scmReference, 'late_in' => $lateIn, 'late_out' => $lateOut, 'scaling_adjustment' => $scalingAdj,
            'scm_adjusted_reference' => $scmAdjusted,
            'system_snapshot' => (float) $l['system_qty_base'],
            'p1_good' => $p1Good, 'p1_damaged' => $p1Damaged, 'p1_expired' => $p1Expired, 'p1_deadstock' => $p1Deadstock, 'p1_total' => $p1Total,
            'p2_good' => $p2Good, 'p2_damaged' => $p2Damaged, 'p2_expired' => $p2Expired, 'p2_deadstock' => $p2Deadstock, 'p2_total' => $p2Total,
            'recount_good' => $recountGood, 'recount_damaged' => null, 'recount_expired' => null, 'recount_deadstock' => null, 'recount_total' => $recountTotal,
            'final_good' => $finalGood, 'final_damaged' => $finalDamaged, 'final_expired' => $finalExpired, 'final_deadstock' => $finalDeadstock, 'final_total_physical' => $finalTotalPhysical,
            'physical_variance_vs_scm' => $physicalVariance, 'good_variance_vs_scm' => $goodVariance,
            'system_adjusted_at_cutoff' => (float) $l['system_qty_base'], 'posting_adjustment' => $l['variance_qty_base'] !== null ? (float) $l['variance_qty_base'] : null,
            'status' => $status, 'decision_source' => $l['match_status'], 'keterangan' => $l['final_notes'] ?? $l['notes'],
        ];
    }

    private static function buildFinalSoSheet(array $rows, bool $isOfficial): array
    {
        $headers = [
            'No', 'SKU', 'Nama Barang', 'Kategori', 'Base Unit',
            'Reference SCM Awal', 'Late IN', 'Late OUT', 'Scaling Adjustment', 'Reference SCM Adjusted',
            'System Snapshot',
            'P1 Good', 'P1 Damaged', 'P1 Expired', 'P1 Deadstock', 'P1 Total',
            'P2 Good', 'P2 Damaged', 'P2 Expired', 'P2 Deadstock', 'P2 Total',
            'Recount Good', 'Recount Damaged', 'Recount Expired', 'Recount Deadstock', 'Recount Total',
            'Final Good', 'Final Damaged', 'Final Expired', 'Final Deadstock', 'Final Total Physical',
            'Physical Variance vs SCM', 'Good Variance vs SCM',
            'System Adjusted At Cutoff', 'Posting Adjustment',
            'Status', 'Decision Source', 'Keterangan',
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['no'], self::sanitize((string) $r['sku']), self::sanitize((string) $r['name']), self::sanitize((string) $r['category']), $r['base_unit'],
                $r['scm_reference'], $r['late_in'], $r['late_out'], $r['scaling_adjustment'], $r['scm_adjusted_reference'],
                $r['system_snapshot'],
                $r['p1_good'], $r['p1_damaged'], $r['p1_expired'], $r['p1_deadstock'], $r['p1_total'],
                $r['p2_good'], $r['p2_damaged'], $r['p2_expired'], $r['p2_deadstock'], $r['p2_total'],
                $r['recount_good'], $r['recount_damaged'], $r['recount_expired'], $r['recount_deadstock'], $r['recount_total'],
                $r['final_good'], $r['final_damaged'], $r['final_expired'], $r['final_deadstock'], $r['final_total_physical'],
                $r['physical_variance_vs_scm'], $r['good_variance_vs_scm'],
                $r['system_adjusted_at_cutoff'], $r['posting_adjustment'],
                $r['status'], $r['decision_source'], $r['keterangan'] !== null ? self::sanitize((string) $r['keterangan']) : null,
            ];
        }
        return [
            'headers' => $headers, 'rows' => $out, 'freeze_header' => true, 'autofilter' => true,
            'col_widths' => array_fill(0, count($headers), 14),
        ];
    }

    private static function buildIssuesSheet(array $rows): array
    {
        $headers = ['No', 'SKU', 'Nama Barang', 'Issue', 'Status', 'Physical Variance vs SCM', 'Good Variance vs SCM', 'Keterangan'];
        $out = [];
        foreach ($rows as $r) {
            $issues = [];
            if (in_array($r['status'], ['MISMATCH'], true)) {
                $issues[] = 'MISMATCH';
            }
            if ($r['status'] === 'MISMATCH' && $r['recount_good'] === null) {
                $issues[] = 'RECOUNT_REQUIRED';
            }
            if ($r['scm_reference'] === null && $r['scm_adjusted_reference'] === null) {
                $issues[] = 'UNMATCHED_OR_NO_REFERENCE';
            }
            $physVar = (float) ($r['physical_variance_vs_scm'] ?? 0);
            $goodVar = (float) ($r['good_variance_vs_scm'] ?? 0);
            if (($r['physical_variance_vs_scm'] !== null && abs($physVar) > 0.0000001) || ($r['good_variance_vs_scm'] !== null && abs($goodVar) > 0.0000001)) {
                $issues[] = 'NON_ZERO_VARIANCE';
            }
            if (empty($issues)) {
                continue;
            }
            $out[] = [
                $r['no'], self::sanitize((string) $r['sku']), self::sanitize((string) $r['name']), implode('; ', $issues),
                $r['status'], $r['physical_variance_vs_scm'], $r['good_variance_vs_scm'],
                $r['keterangan'] !== null ? self::sanitize((string) $r['keterangan']) : null,
            ];
        }
        return ['headers' => $headers, 'rows' => $out, 'freeze_header' => true, 'autofilter' => true, 'col_widths' => [6, 18, 30, 24, 12, 18, 18, 30]];
    }

    private static function buildAuditTrailSheet(PDO $pdo, int $sessionId, array $lines): array
    {
        $lineIds = array_column($lines, 'id');
        $skuByLineId = [];
        foreach ($lines as $l) {
            $skuByLineId[(int) $l['id']] = $l['sku'];
        }

        $events = [];

        $sessionEvents = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type = 'stock_opname_sessions' AND entity_id = :id ORDER BY created_at");
        $sessionEvents->execute(['id' => $sessionId]);
        foreach ($sessionEvents->fetchAll() as $e) {
            $events[] = ['sku' => null] + $e;
        }

        if (!empty($lineIds)) {
            $placeholders = implode(',', array_fill(0, count($lineIds), '?'));

            $excludeEvents = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type = 'stock_opname_lines' AND entity_id IN ({$placeholders}) ORDER BY created_at");
            $excludeEvents->execute($lineIds);
            foreach ($excludeEvents->fetchAll() as $e) {
                $events[] = ['sku' => $skuByLineId[(int) $e['entity_id']] ?? null] + $e;
            }

            $findingStmt = $pdo->prepare("SELECT id, stock_opname_line_id FROM stock_opname_findings WHERE stock_opname_line_id IN ({$placeholders})");
            $findingStmt->execute($lineIds);
            $findingLineById = [];
            foreach ($findingStmt->fetchAll() as $f) {
                $findingLineById[(int) $f['id']] = (int) $f['stock_opname_line_id'];
            }
            if (!empty($findingLineById)) {
                $fPlaceholders = implode(',', array_fill(0, count($findingLineById), '?'));
                $findingEvents = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type = 'stock_opname_findings' AND entity_id IN ({$fPlaceholders}) ORDER BY created_at");
                $findingEvents->execute(array_keys($findingLineById));
                foreach ($findingEvents->fetchAll() as $e) {
                    $lineId = $findingLineById[(int) $e['entity_id']] ?? null;
                    $events[] = ['sku' => $lineId !== null ? ($skuByLineId[$lineId] ?? null) : null] + $e;
                }
            }
        }

        foreach (['stock_opname_reference_batches' => 'id', 'stock_opname_reference_rows' => 'id', 'stock_opname_reference_movements' => 'id'] as $table => $idCol) {
            $idStmt = $pdo->prepare("SELECT {$idCol} AS id FROM {$table} WHERE session_id = :sid");
            $idStmt->execute(['sid' => $sessionId]);
            $ids = array_column($idStmt->fetchAll(), 'id');
            if (empty($ids)) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $evStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type = '{$table}' AND entity_id IN ({$placeholders}) ORDER BY created_at");
            $evStmt->execute($ids);
            foreach ($evStmt->fetchAll() as $e) {
                $events[] = ['sku' => null] + $e;
            }
        }

        usort($events, static fn (array $a, array $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

        $headers = ['Session', 'SKU', 'Event Type', 'Old State', 'New State', 'User', 'Timestamp', 'Reason/Reference'];
        $rows = [];
        foreach ($events as $e) {
            $rows[] = [
                $sessionId, $e['sku'] !== null ? self::sanitize((string) $e['sku']) : null, $e['action_code'],
                $e['before_data'] !== null ? self::sanitize((string) $e['before_data']) : null,
                $e['after_data'] !== null ? self::sanitize((string) $e['after_data']) : null,
                $e['username_snapshot'], $e['created_at'], $e['reason'] !== null ? self::sanitize((string) $e['reason']) : null,
            ];
        }
        return ['headers' => $headers, 'rows' => $rows, 'freeze_header' => true, 'autofilter' => true, 'col_widths' => [10, 18, 32, 40, 40, 16, 20, 30]];
    }

    private static function sanitize(string $value): string
    {
        return ExcelWriterService::sanitizeCellText($value);
    }

    private static function lateMovesByItem(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT item_id, movement_type, SUM(qty_base) AS total FROM stock_opname_reference_movements
             WHERE session_id = :sid AND late_pre_cutoff = 1 GROUP BY item_id, movement_type"
        );
        $stmt->execute(['sid' => $sessionId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $itemId = (int) $r['item_id'];
            if (!isset($out[$itemId])) {
                $out[$itemId] = ['in_qty' => 0.0, 'out_qty' => 0.0, 'scaling_adjustment' => 0.0];
            }
            $total = (float) $r['total'];
            if ($r['movement_type'] === 'IN') {
                $out[$itemId]['in_qty'] = $total;
            } elseif ($r['movement_type'] === 'OUT') {
                $out[$itemId]['out_qty'] = $total;
            } else {
                $out[$itemId]['scaling_adjustment'] += $total;
            }
        }
        return $out;
    }

    private static function loadLines(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name, u.code AS base_unit_code, c.name AS category_name
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE sol.session_id = :sid
              ORDER BY i.sku'
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
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

    private static function warehouseName(PDO $pdo, int $warehouseId): string
    {
        $stmt = $pdo->prepare('SELECT name FROM warehouses WHERE id = :id');
        $stmt->execute(['id' => $warehouseId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private static function username(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
