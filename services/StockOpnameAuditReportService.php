<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Laporan Stock Opname" (audit redesign) — READ-ONLY. Answers, from REAL session data only: when / where / who (P1, P2, supervisor,
 * finalizer) / when each side counted / system vs final quantity / Good, Expired, Rusak, Deadstock / HPP and value / variance / notes /
 * evidence photos / the adjustment that was posted — for every Stock Opname session, per session and per item.
 *
 * It never writes (stock_opname_*, stock_adjustments, inventory_*, audit_logs, files) and never calls anything that could
 * (StockOpnameBookStockService::reconciliation() — used for FINDINGS_V1 — is itself read-only). It does NOT re-derive any Stock Opname rule:
 * every line is built on StockOpnameJejakService::detail() (the approved Jejak read model: system / final / variance / conditions / HPP / values /
 * per-line counters / linked adjustments) and only ADDS what the audit report needs on top — timestamps, per-condition split, evidence,
 * count history, notes split, adjustment references, audit events — each from a column the workflow itself writes.
 *
 * SEMANTICS (documented, never mixed silently — each row carries its counting model):
 *   LEGACY_DUAL_COUNT  final = counted_qty_base = TOTAL physical; Rusak / Expired / Deadstock are SUBSETS of it (V2.14.9: they never change
 *                      inventory), so Good = total - (rusak + expired + deadstock) when the conditions were recorded; variance = counted - system.
 *   FINDINGS_V1        Jejak's final = GOOD physical at end of day; Rusak / Expired / Deadstock are separate, total physical = good + the three;
 *                      system = end-of-day book stock; variance = good EOD - book stock.
 *   Unknown stays unknown ("—" in the UI, null here): a legacy line on which no condition was ever recorded has NULL conditions, never 0.
 * Match / Mismatch are the production per-line match_status (P1 count == P2 count, MATCH; both counted but different, MISMATCH; a recount
 * resolves it, RECOUNTED), exactly the buckets StockOpnameReportService / StockOpnameService::review() already report.
 *
 * TIMESTAMP DERIVATION (no timestamp is ever inferred from the session date):
 *   FINDINGS_V1  per team and line: MIN / MAX of COALESCE(counted_at, created_at) over the NON-VOIDED findings of that team (a voided finding
 *                is never shown as a final actor or time). Session level P1/P2 start/end = MIN / MAX over its lines.
 *   LEGACY       per line: p1_submitted_at / p2_submitted_at; "final input" = recount_submitted_at when recounted, else the later of the two.
 *   "Timestamp Final Input" (V1) = latest non-voided finding time of either team on that line.
 *
 * Money: exactly Jejak's rule — round(qty x unit_cost_base, 2) per row, unit_cost_base being the HPP snapshotted at session start
 * (never today's price). A session's / KPI's value is the sum of its rounded rows, so cards and tables cannot disagree.
 */
final class StockOpnameAuditReportService
{
    public const MAX_SESSIONS = 200;
    private const EPS = 0.0000001;

    /** UI + export column catalogue for "Rincian Item" (the UI renders from this very list, so export == screen by construction). */
    public const ITEM_COLUMNS = [
        ['no', 'No.', 'text', true], ['session_number', 'No. Sesi', 'text', true], ['session_date', 'Tanggal', 'date', true], ['warehouse', 'Gudang', 'text', true],
        ['sku', 'SKU', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan', 'text', true],
        ['system_qty', 'Qty Sistem', 'qty', true], ['final_qty', 'Qty Fisik Final (Total)', 'qty', true],
        ['good_qty', 'Good / Stok Layak', 'qty', true], ['expired_qty', 'Expired', 'qty', true], ['rusak_qty', 'Rusak', 'qty', true], ['deadstock_qty', 'Deadstock', 'qty', true],
        ['variance_qty', 'Selisih Qty', 'variance', true], ['hpp', 'HPP / Unit Cost', 'money', true],
        ['system_value', 'Nilai Sistem', 'money', true], ['final_value', 'Nilai Fisik', 'money', true], ['variance_value', 'Selisih Nilai', 'variance_money', true],
        ['p_hitung', 'Petugas Hitung (P1)', 'people', true], ['p_verifikasi', 'Petugas Verifikasi (P2)', 'people', true],
        ['ts_hitung', 'Timestamp Hitung', 'ts', true], ['ts_verifikasi', 'Timestamp Verifikasi', 'ts', true], ['ts_final', 'Timestamp Final Input', 'ts', false],
        ['evidence', 'Evidence Foto', 'evidence', true],
        ['note_petugas', 'Catatan Petugas', 'text', true], ['note_supervisor', 'Catatan Supervisor', 'text', false], ['note_general', 'Catatan Lain', 'text', false],
        ['adj_ref', 'Adjustment Ref', 'text', true], ['adj_qty', 'Adjustment Qty', 'variance', false], ['adj_value', 'Adjustment Nilai', 'variance_money', true],
        ['adj_at', 'Adjustment Timestamp', 'ts', false], ['adj_by', 'Adjustment Oleh', 'text', false],
        ['line_status', 'Status Line', 'status', true], ['model', 'Model', 'text', false],
    ];

    /** Column catalogue for "Ringkasan Sesi". */
    public const SESSION_COLUMNS = [
        ['session_number', 'No. Sesi', 'text', true], ['session_date', 'Tanggal Sesi', 'date', true], ['warehouse', 'Gudang', 'text', true], ['status', 'Status', 'status', true],
        ['model', 'Model', 'text', false], ['supervisor', 'Supervisor', 'text', true],
        ['p1', 'Petugas P1', 'people', true], ['p1_start', 'P1 Mulai', 'ts', true], ['p1_end', 'P1 Selesai', 'ts', true],
        ['p2', 'Petugas P2', 'people', true], ['p2_start', 'P2 Mulai', 'ts', true], ['p2_end', 'P2 Selesai', 'ts', true],
        ['finalizer', 'Finalizer', 'text', true], ['finalized_at', 'Timestamp Final', 'ts', true], ['posted_by', 'Diposting Oleh', 'text', true], ['posted_at', 'Timestamp Posting', 'ts', true],
        ['total_items', 'Total Item', 'int', true], ['match', 'Match', 'int', true], ['mismatch', 'Mismatch', 'int', true], ['recounted', 'Recount', 'int', false],
        ['pending', 'Belum Dihitung', 'int', false], ['excluded', 'Dikecualikan', 'int', false],
        ['variance_by_unit', 'Selisih Qty (per satuan)', 'text', true], ['variance_value', 'Selisih Nilai', 'variance_money', true],
        ['adj_status', 'Status Adjustment', 'text', true], ['adj_count', 'Jumlah Adjustment', 'int', false], ['adj_value', 'Nilai Adjustment', 'variance_money', true],
        ['created_by', 'Dibuat Oleh', 'text', false], ['created_at', 'Timestamp Dibuat', 'ts', false],
    ];

    private const LINE_STATUS = ['PENDING' => 'Belum dihitung', 'MATCH' => 'Match', 'MISMATCH' => 'Mismatch', 'RECOUNTED' => 'Recount', 'EXCLUDED' => 'Dikecualikan'];

    /** @var array<int,array<string,mixed>> */
    private static array $built = [];

    // ======================================================================
    // sessions
    // ======================================================================

    /**
     * @param array{date_from?:?string,date_to?:?string,warehouse_id?:?int,status?:?string,q?:?string,page?:int,per_page?:int} $f
     */
    public static function sessions(PDO $pdo, array $f): array
    {
        $ids = self::sessionIds($pdo, $f);
        $truncated = count($ids) > self::MAX_SESSIONS;
        $ids = array_slice($ids, 0, self::MAX_SESSIONS);
        $built = array_map(fn (int $id) => self::build($pdo, $id), $ids);

        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = in_array((int) ($f['per_page'] ?? 25), [10, 25, 50, 100], true) ? (int) ($f['per_page'] ?? 25) : 25;
        $rows = array_map(static fn (array $b) => $b['session_row'], array_slice($built, ($page - 1) * $perPage, $perPage));

        return [
            'rows' => $rows,
            'columns' => self::columns(self::SESSION_COLUMNS),
            'kpi' => self::kpi($built),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($ids), 'total_pages' => max(1, (int) ceil(count($ids) / $perPage))],
            'truncated' => $truncated,
            'session_ids' => $ids,
            'statuses' => ['OPEN' => 'Open / Counting', 'FINALIZED' => 'Finalized', 'POSTED' => 'Posted', 'CANCELLED' => 'Cancelled'],
        ];
    }

    /** @return list<int> matching session ids, newest first */
    public static function sessionIds(PDO $pdo, array $f): array
    {
        $where = ['1=1'];
        $bind = [];
        if (!empty($f['date_from'])) { $where[] = 'sos.session_date >= :df'; $bind['df'] = $f['date_from']; }
        if (!empty($f['date_to'])) { $where[] = 'sos.session_date <= :dt'; $bind['dt'] = $f['date_to']; }
        if (!empty($f['warehouse_id'])) { $where[] = 'sos.warehouse_id = :wh'; $bind['wh'] = (int) $f['warehouse_id']; }
        if (!empty($f['status']) && in_array($f['status'], ['OPEN', 'FINALIZED', 'POSTED', 'CANCELLED'], true)) { $where[] = 'sos.status = :st'; $bind['st'] = $f['status']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $n = 0;
            $p = static function () use (&$n, &$bind, $like): string { $k = 'q' . (++$n); $bind[$k] = $like; return ':' . $k; };
            $where[] = '(' . implode(' OR ', [
                "sos.session_number LIKE {$p()}",
                "EXISTS (SELECT 1 FROM users u WHERE u.id IN (sos.created_by, sos.p1_user_id, sos.p2_user_id, sos.supervisor_id, sos.finalized_by, sos.posted_by) AND (u.username LIKE {$p()} OR u.full_name LIKE {$p()}))",
                "EXISTS (SELECT 1 FROM stock_opname_team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.session_id = sos.id AND (u.username LIKE {$p()} OR u.full_name LIKE {$p()}))",
                "EXISTS (SELECT 1 FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id WHERE sol.session_id = sos.id AND (i.sku LIKE {$p()} OR i.name LIKE {$p()} OR sol.notes LIKE {$p()} OR sol.final_notes LIKE {$p()} OR sol.p1_notes LIKE {$p()} OR sol.p2_notes LIKE {$p()}))",
                "EXISTS (SELECT 1 FROM stock_opname_findings fd JOIN users u ON u.id = fd.counter_user_id WHERE fd.session_id = sos.id AND fd.voided_at IS NULL AND (fd.counter_username_snapshot LIKE {$p()} OR u.username LIKE {$p()} OR u.full_name LIKE {$p()} OR fd.notes LIKE {$p()}))",
            ]) . ')';
        }
        $stmt = $pdo->prepare('SELECT sos.id FROM stock_opname_sessions sos WHERE ' . implode(' AND ', $where) . ' ORDER BY sos.session_date DESC, sos.id DESC');
        $stmt->execute($bind);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ======================================================================
    // items
    // ======================================================================

    /**
     * @param list<int> $sessionIds
     * @param array{q?:?string,condition?:?string,match_status?:?string,page?:int,per_page?:int} $f
     */
    public static function items(PDO $pdo, array $sessionIds, array $f): array
    {
        $rows = self::itemRows($pdo, $sessionIds, $f);
        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = in_array((int) ($f['per_page'] ?? 50), [25, 50, 100, 200], true) ? (int) ($f['per_page'] ?? 50) : 50;
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        foreach ($slice as $i => &$r) {
            $r['no'] = ($page - 1) * $perPage + $i + 1;
        }
        unset($r);
        return [
            'rows' => $slice,
            'columns' => self::columns(self::ITEM_COLUMNS),
            'footer' => self::itemFooter($rows),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'total_pages' => max(1, (int) ceil(count($rows) / $perPage))],
            'session_ids' => array_values($sessionIds),
        ];
    }

    /** All item rows (filtered, unpaged) over the given sessions — shared by the screen and every export. @return list<array<string,mixed>> */
    public static function itemRows(PDO $pdo, array $sessionIds, array $f): array
    {
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $cond = (string) ($f['condition'] ?? '');
        $ms = (string) ($f['match_status'] ?? '');
        $out = [];
        foreach ($sessionIds as $sid) {
            $b = self::build($pdo, (int) $sid);
            // A search that names the SESSION itself (number / supervisor / finalizer / poster / creator) keeps all of its lines; a petugas name is a LINE fact (P1/P2 differ per line).
            $sr = $b['session_row'];
            $sessionHit = $q !== '' && str_contains(mb_strtolower(implode(' ', [$sr['session_number'], $sr['supervisor'], $sr['finalizer'], $sr['posted_by'], $sr['created_by']])), $q);
            foreach ($b['items'] as $r) {
                if ($q !== '' && !$sessionHit) {
                    $hay = mb_strtolower(implode(' ', [$r['sku'], $r['name'], $r['category'], $r['note_petugas'] ?? '', $r['note_supervisor'] ?? '', $r['note_general'] ?? '',
                        implode(' ', $r['p_hitung']), implode(' ', $r['p_verifikasi'])]));
                    if (!str_contains($hay, $q)) {
                        continue;
                    }
                }
                $pass = match ($cond) {
                    'good' => ($r['good_qty'] ?? 0) > self::EPS,
                    'expired' => ($r['expired_qty'] ?? 0) > self::EPS,
                    'rusak' => ($r['rusak_qty'] ?? 0) > self::EPS,
                    'deadstock' => ($r['deadstock_qty'] ?? 0) > self::EPS,
                    'variance' => $r['variance_qty'] !== null && abs((float) $r['variance_qty']) > self::EPS,
                    'evidence' => $r['evidence'] !== [],
                    'no_evidence' => $r['evidence'] === [],
                    default => true,
                };
                if (!$pass || ($ms !== '' && $r['match_status'] !== $ms)) {
                    continue;
                }
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Totals over ALL filtered rows. Quantities are NEVER added across units — they are grouped BY UNIT. */
    private static function itemFooter(array $rows): array
    {
        $byUnit = [];
        $money = ['system_value' => 0.0, 'final_value' => 0.0, 'variance_value' => 0.0, 'adj_value' => 0.0];
        foreach ($rows as $r) {
            $u = (string) $r['unit'];
            $byUnit[$u] ??= ['system_qty' => 0.0, 'final_qty' => 0.0, 'good_qty' => 0.0, 'expired_qty' => 0.0, 'rusak_qty' => 0.0, 'deadstock_qty' => 0.0, 'variance_qty' => 0.0];
            foreach (array_keys($byUnit[$u]) as $k) {
                $byUnit[$u][$k] += (float) ($r[$k] ?? 0);
            }
            foreach (array_keys($money) as $k) {
                $money[$k] += (float) ($r[$k] ?? 0);
            }
        }
        foreach ($byUnit as &$q) {
            $q = array_map(static fn ($v) => round($v, 6), $q);
        }
        unset($q);
        return ['count' => count($rows), 'money' => array_map(static fn ($v) => round($v, 2), $money), 'qty_by_unit' => $byUnit];
    }

    // ======================================================================
    // item drawer
    // ======================================================================

    public static function itemDetail(PDO $pdo, int $sessionId, int $lineId): array
    {
        $b = self::build($pdo, $sessionId);
        $item = null;
        foreach ($b['items'] as $r) {
            if ($r['line_id'] === $lineId) {
                $item = $r;
            }
        }
        if ($item === null) {
            throw new NotFoundException("opname line {$lineId} not found in session {$sessionId}");
        }
        $s = $b['session'];

        return [
            'item' => $item,
            'session' => $b['session_row'],
            'summary' => [
                'sku' => $item['sku'], 'name' => $item['name'], 'unit' => $item['unit'], 'system_qty' => $item['system_qty'], 'final_qty' => $item['final_qty'],
                'good_qty' => $item['good_qty'], 'expired_qty' => $item['expired_qty'], 'rusak_qty' => $item['rusak_qty'], 'deadstock_qty' => $item['deadstock_qty'],
                'variance_qty' => $item['variance_qty'], 'variance_value' => $item['variance_value'], 'hpp' => $item['hpp'],
            ],
            'count_history' => $b['history_by_line'][$lineId] ?? [],
            'evidence' => $item['evidence'],
            'reconciliation' => [
                'model' => $item['model'], 'match_status' => $item['match_status'], 'line_status' => $item['line_status'],
                'formula' => $b['formula'],
                'system_source' => $b['sources']['system_qty'], 'final_source' => $b['sources']['final_qty'], 'variance_source' => $b['sources']['variance'],
                'final_decision_by' => $s['finalized_by'], 'final_decision_at' => $s['finalized_at'], 'supervisor' => $s['supervisor'],
                'note_supervisor' => $item['note_supervisor'], 'recount' => $b['recount_by_line'][$lineId] ?? null,
                'book_stock' => $b['recon_by_sku'][$item['sku']] ?? null,
            ],
            'adjustment' => $b['adjustments_by_line'][$lineId] ?? [],
            'audit' => self::auditEvents($pdo, $sessionId, $lineId, $b['finding_ids_by_line'][$lineId] ?? []),
        ];
    }

    // ======================================================================
    // evidence / history / adjustments (export sheets + reconciliation)
    // ======================================================================

    /** @return list<array<string,mixed>> */
    public static function evidenceRows(PDO $pdo, array $sessionIds, array $f): array
    {
        $keep = [];
        foreach (self::itemRows($pdo, $sessionIds, $f) as $r) {
            $keep[$r['line_id']] = $r;
        }
        $out = [];
        foreach ($sessionIds as $sid) {
            foreach (self::build($pdo, (int) $sid)['items'] as $r) {
                if (!isset($keep[$r['line_id']])) {
                    continue;
                }
                foreach ($r['evidence'] as $e) {
                    $out[] = ['session_number' => $r['session_number'], 'warehouse' => $r['warehouse'], 'sku' => $r['sku'], 'name' => $r['name']] + $e;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function historyRows(PDO $pdo, array $sessionIds, array $f): array
    {
        $keep = [];
        foreach (self::itemRows($pdo, $sessionIds, $f) as $r) {
            $keep[$r['line_id']] = true;
        }
        $out = [];
        foreach ($sessionIds as $sid) {
            $b = self::build($pdo, (int) $sid);
            foreach ($b['items'] as $r) {
                if (!isset($keep[$r['line_id']])) {
                    continue;
                }
                foreach ($b['history_by_line'][$r['line_id']] ?? [] as $h) {
                    $out[] = ['session_number' => $r['session_number'], 'sku' => $r['sku'], 'name' => $r['name'], 'unit' => $r['unit']] + $h;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function adjustmentRows(PDO $pdo, array $sessionIds): array
    {
        $out = [];
        foreach ($sessionIds as $sid) {
            $b = self::build($pdo, (int) $sid);
            foreach ($b['adjustments'] as $a) {
                $out[] = ['session_number' => $b['session_row']['session_number'], 'warehouse' => $b['session_row']['warehouse']] + $a;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function auditRows(PDO $pdo, int $sessionId): array
    {
        return self::auditEvents($pdo, $sessionId, null, []);
    }

    // ======================================================================
    // session build (cached per request): Jejak read model + audit extras
    // ======================================================================

    /** @return array<string,mixed> */
    public static function build(PDO $pdo, int $sessionId): array
    {
        if (isset(self::$built[$sessionId])) {
            return self::$built[$sessionId];
        }
        $jejak = StockOpnameJejakService::detail($pdo, $sessionId);
        $s = $jejak['session'];
        $model = (string) $s['counting_model'];
        $isV1 = $model === 'FINDINGS_V1';

        $lines = [];
        $st = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid');
        $st->execute(['sid' => $sessionId]);
        foreach ($st->fetchAll() as $l) {
            $lines[(int) $l['id']] = $l;
        }

        // ---- findings (V1): per-line, per-team non-voided times and the full history incl. voided ----
        $fs = $pdo->prepare(
            "SELECT f.*, COALESCE(f.counter_username_snapshot, u.username) AS counter_name, vu.username AS voided_by_name
               FROM stock_opname_findings f JOIN users u ON u.id = f.counter_user_id LEFT JOIN users vu ON vu.id = f.voided_by
              WHERE f.session_id = :sid ORDER BY f.stock_opname_line_id, f.team_role, f.round, f.created_at, f.id"
        );
        $fs->execute(['sid' => $sessionId]);
        $findings = $fs->fetchAll();
        $qtyByFinding = [];
        if ($findings) {
            $qs = $pdo->prepare(
                'SELECT q.finding_id, q.condition_type, q.unit_code_snapshot, q.input_qty, q.base_qty_contribution
                   FROM stock_opname_finding_quantities q JOIN stock_opname_findings f ON f.id = q.finding_id WHERE f.session_id = :sid ORDER BY q.id'
            );
            $qs->execute(['sid' => $sessionId]);
            foreach ($qs->fetchAll() as $q) {
                $qtyByFinding[(int) $q['finding_id']][] = ['condition' => $q['condition_type'], 'input_qty' => (float) $q['input_qty'], 'unit' => $q['unit_code_snapshot'], 'base_qty' => (float) $q['base_qty_contribution']];
            }
        }
        $times = [];       // line => role => [min, max] (non-voided)
        $fnotes = [];      // line => role => list<string> (non-voided finding notes)
        $history = [];     // line => list
        $findingIds = [];  // line => list<int>
        foreach ($findings as $f) {
            $lid = (int) $f['stock_opname_line_id'];
            $at = $f['counted_at'] ?? $f['created_at'];
            $findingIds[$lid][] = (int) $f['id'];
            $voided = $f['voided_at'] !== null;
            if (!$voided) {
                $t = $times[$lid][$f['team_role']] ?? [$at, $at];
                $times[$lid][$f['team_role']] = [min($t[0], $at), max($t[1], $at)];
                if ($f['notes'] !== null && trim((string) $f['notes']) !== '') {
                    $fnotes[$lid][$f['team_role']][] = (string) $f['notes'];
                }
            }
            $history[$lid][] = [
                'source' => 'FINDING', 'finding_id' => (int) $f['id'], 'role' => $f['team_role'], 'round' => (int) $f['round'], 'actor' => $f['counter_name'],
                'action' => $voided ? 'Temuan di-VOID' : 'Temuan dihitung', 'counted_at' => $at, 'recorded_at' => $f['created_at'],
                'backfilled' => $f['counted_at_backfilled_at'] !== null,
                'good_qty' => (float) $f['finding_good_base_qty'], 'rusak_qty' => (float) $f['finding_damaged_base_qty'],
                'expired_qty' => (float) $f['finding_expired_base_qty'], 'deadstock_qty' => (float) $f['finding_deadstock_base_qty'],
                'quantities' => $qtyByFinding[(int) $f['id']] ?? [], 'notes' => $f['notes'],
                'voided' => $voided ? ['by' => $f['voided_by_name'], 'at' => $f['voided_at'], 'reason' => $f['void_reason']] : null,
            ];
        }

        // ---- evidence (V1 photos attached to a finding) ----
        $evidence = [];
        $ps = $pdo->prepare(
            "SELECT p.id, p.stock_opname_line_id AS line_id, p.team_role, p.condition_type, p.finding_id, p.storage_path, p.mime_type, p.caption, p.uploaded_at, p.attached_at,
                    u.username AS uploaded_by, f.voided_at AS finding_voided_at
               FROM stock_opname_finding_photos p JOIN users u ON u.id = p.uploaded_by LEFT JOIN stock_opname_findings f ON f.id = p.finding_id
              WHERE p.session_id = :sid AND p.finding_id IS NOT NULL ORDER BY p.id"
        );
        $ps->execute(['sid' => $sessionId]);
        foreach ($ps->fetchAll() as $p) {
            $evidence[(int) $p['line_id']][] = [
                'id' => (int) $p['id'], 'url' => '/api/reports/opname-audit/photo/' . (int) $p['id'], 'condition' => $p['condition_type'], 'team' => $p['team_role'],
                'uploaded_by' => $p['uploaded_by'], 'uploaded_at' => $p['uploaded_at'], 'caption' => $p['caption'], 'mime' => $p['mime_type'],
                'finding_id' => (int) $p['finding_id'], 'voided' => $p['finding_voided_at'] !== null,
                'file_ok' => is_file(StockOpnamePhotoService::absolutePath((string) $p['storage_path'])),
            ];
        }

        // ---- adjustments (same linkage rule as Jejak) with reference / timestamp / actor ----
        $adjustments = self::adjustments($pdo, $sessionId);
        $adjByLine = [];
        foreach ($adjustments as $a) {
            $adjByLine[$a['line_id']][] = $a;
        }

        $recon = [];
        if ($isV1) {
            foreach (StockOpnameBookStockService::reconciliation($pdo, $sessionId) as $row) {
                $recon[$row['sku']] = $row;
            }
        }

        $items = [];
        $recount = [];
        $legacyHistory = [];
        foreach ($jejak['items'] as $j) {
            $lid = (int) $j['line_id'];
            $l = $lines[$lid];
            [$tHit, $tVer, $tFinal] = $isV1 ? self::v1Times($times[$lid] ?? []) : self::legacyTimes($l);
            $recorded = $isV1 || self::conditionsRecorded($l);
            $rusak = $recorded ? $j['rusak_qty'] : null;
            $expired = $recorded ? $j['expired_qty'] : null;
            $dead = $recorded ? $j['dead_qty'] : null;
            $condKnown = $rusak !== null && $expired !== null && $dead !== null;
            if ($isV1) {
                $good = $j['final_qty'];
                $total = ($good !== null && $condKnown) ? round($good + $rusak + $expired + $dead, 6) : null;
            } else {
                $total = $j['final_qty'];
                $good = ($total !== null && $condKnown) ? round($total - ($rusak + $expired + $dead), 6) : null;
            }
            $hpp = (float) $j['hpp'];
            $line = $adjByLine[$lid] ?? [];
            $last = $line ? end($line) : null;
            $ms = (string) $l['match_status'];
            $items[] = [
                'line_id' => $lid, 'item_id' => $j['item_id'], 'session_id' => $sessionId,
                'session_number' => $s['session_number'], 'session_date' => $s['session_date'], 'warehouse' => $s['warehouse_name'], 'warehouse_id' => $s['warehouse_id'],
                'sku' => $j['sku'], 'name' => $j['name'], 'category' => $j['category'], 'unit' => $j['unit'],
                'system_qty' => $j['system_qty'], 'final_qty' => $total, 'good_qty' => $good, 'expired_qty' => $expired, 'rusak_qty' => $rusak, 'deadstock_qty' => $dead,
                'variance_qty' => $j['variance_qty'], 'hpp' => $hpp,
                'system_value' => $j['system_value'], 'final_value' => $total === null ? null : round($total * $hpp, 2), 'variance_value' => $j['variance_value'],
                'p_hitung' => $j['count1_users'], 'p_verifikasi' => $j['count2_users'],
                'ts_hitung' => $tHit, 'ts_verifikasi' => $tVer, 'ts_final' => $tFinal,
                'evidence' => $evidence[$lid] ?? [],
                'note_petugas' => $isV1 ? self::notePetugasV1($fnotes[$lid] ?? []) : self::notePetugas($l), 'note_supervisor' => self::nz($l['final_notes']), 'note_general' => self::nz($l['notes']),
                'adj_ref' => $line ? implode(', ', array_values(array_unique(array_filter(array_map(static fn ($a) => $a['reference_no'] ?? ('ADJ-' . $a['adjustment_id']), $line))))) : null,
                'adj_qty' => $line ? round(array_sum(array_column($line, 'qty')), 6) : null,
                'adj_value' => $line ? round(array_sum(array_column($line, 'value')), 2) : null,
                'adj_at' => $last['created_at'] ?? null, 'adj_by' => $last['created_by'] ?? null,
                'match_status' => $ms, 'is_excluded' => (int) $l['is_excluded'] === 1,
                'line_status' => self::LINE_STATUS[$ms] ?? $ms, 'model' => $isV1 ? 'FINDINGS_V1' : 'Legacy',
                'condition_basis' => $j['condition_basis'], 'conditions_recorded' => $recorded,
            ];
            if (!$isV1) {
                $legacyHistory[$lid] = self::legacyHistory($pdo, $l);
                $recount[$lid] = $l['recount_qty_base'] === null ? null : [
                    'qty' => (float) $l['recount_qty_base'], 'reason' => $l['recount_reason'], 'at' => $l['recount_submitted_at'],
                    'by' => $l['recount_user_id'] ? self::username($pdo, (int) $l['recount_user_id']) : null,
                ];
            }
        }
        $historyByLine = $isV1 ? $history : $legacyHistory;

        $built = [
            'session' => $s, 'jejak' => $jejak, 'items' => $items, 'adjustments' => $adjustments, 'adjustments_by_line' => $adjByLine,
            'history_by_line' => $historyByLine, 'recount_by_line' => $recount, 'finding_ids_by_line' => $findingIds, 'recon_by_sku' => $recon,
            'sources' => $jejak['sources'],
            'formula' => $isV1
                ? 'Sistem = stok buku EOD; Final (Good) = fisik EOD − (Rusak + Expired + Deadstock); Selisih = Good EOD − stok buku EOD; Qty Fisik Total = Good + Expired + Rusak + Deadstock.'
                : 'Sistem = snapshot awal sesi; Qty Fisik Final (Total) = hasil hitung P1 = P2 / recount; Rusak/Expired/Deadstock adalah SUBSET dari total (tidak mengubah stok); Good = Total − (Rusak + Expired + Deadstock); Selisih = Total − Sistem.',
        ];
        $built['session_row'] = self::sessionRow($pdo, $s, $jejak, $items, $adjustments, $model);
        return self::$built[$sessionId] = $built;
    }

    /** Forget the per-request cache (tests). */
    public static function resetCache(): void
    {
        self::$built = [];
    }

    // ----------------------------------------------------------------------
    // session row + KPI
    // ----------------------------------------------------------------------

    private static function sessionRow(PDO $pdo, array $s, array $jejak, array $items, array $adjustments, string $model): array
    {
        $isV1 = $model === 'FINDINGS_V1';
        $bucket = ['MATCH' => 0, 'MISMATCH' => 0, 'RECOUNTED' => 0, 'PENDING' => 0, 'EXCLUDED' => 0];
        $role = ['hit' => [], 'ver' => []];
        $byUnit = [];
        foreach ($items as $it) {
            $bucket[$it['match_status']] = ($bucket[$it['match_status']] ?? 0) + 1;
            foreach (['ts_hitung' => 'hit', 'ts_verifikasi' => 'ver'] as $k => $r) {
                if ($it[$k] !== null) {
                    $role[$r][] = $it[$k];
                }
            }
            if ($it['variance_qty'] !== null && abs((float) $it['variance_qty']) > self::EPS) {
                $byUnit[$it['unit']] = ($byUnit[$it['unit']] ?? 0.0) + (float) $it['variance_qty'];
            }
        }
        ksort($byUnit);
        $varText = $byUnit ? implode(' · ', array_map(static fn ($u, $q) => sprintf('%s %s%s', $u, $q > 0 ? '+' : '', rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.')), array_keys($byUnit), $byUnit)) : null;

        $adjValue = round(array_sum(array_column($adjustments, 'value')), 2);
        $status = (string) $s['status'];
        if ($status === 'POSTED') {
            $adjStatus = $adjustments ? 'Terposting' : 'Posted — tanpa adjustment';
        } elseif ($isV1) {
            $adjStatus = 'Belum diposting (FINDINGS_V1)';
        } elseif ($status === 'CANCELLED') {
            $adjStatus = 'Dibatalkan';
        } else {
            $adjStatus = 'Belum diposting';
        }

        $team = $s['team'];
        $p1 = $team['P1'] ?: array_values(array_filter([$s['p1_user']]));
        $p2 = $team['P2'] ?: array_values(array_filter([$s['p2_user']]));
        // FINDINGS_V1: only people with a NON-voided finding are actors (a voided author is never a final actor).
        if ($isV1) {
            $act = ['P1' => [], 'P2' => []];
            foreach ($items as $it) {
                foreach ($it['p_hitung'] as $n) { $act['P1'][$n] = true; }
                foreach ($it['p_verifikasi'] as $n) { $act['P2'][$n] = true; }
            }
            $p1 = array_keys($act['P1']) ?: $p1;
            $p2 = array_keys($act['P2']) ?: $p2;
        }

        return [
            'id' => $s['id'], 'session_number' => $s['session_number'], 'session_date' => $s['session_date'],
            'warehouse' => $s['warehouse_name'], 'warehouse_code' => $s['warehouse_code'], 'warehouse_id' => $s['warehouse_id'],
            'status' => $status, 'model' => $isV1 ? 'FINDINGS_V1' : 'Legacy', 'counting_model' => $model, 'scope' => $s['scope'],
            'supervisor' => $s['supervisor'], 'p1' => $p1, 'p1_start' => $role['hit'] ? min($role['hit']) : null, 'p1_end' => $role['hit'] ? max($role['hit']) : null,
            'p2' => $p2, 'p2_start' => $role['ver'] ? min($role['ver']) : null, 'p2_end' => $role['ver'] ? max($role['ver']) : null,
            'finalizer' => $s['finalized_by'], 'finalized_at' => $s['finalized_at'], 'posted_by' => $s['posted_by'], 'posted_at' => $s['posted_at'],
            'cancelled_by' => $s['cancelled_by'], 'cancelled_at' => $s['cancelled_at'],
            'total_items' => count($items), 'match' => $bucket['MATCH'], 'mismatch' => $bucket['MISMATCH'], 'recounted' => $bucket['RECOUNTED'],
            'pending' => $bucket['PENDING'], 'excluded' => $bucket['EXCLUDED'],
            'variance_by_unit' => $varText, 'variance_by_unit_raw' => $byUnit, 'variance_value' => $jejak['kpi']['selisih_nominal']['value'],
            'adj_status' => $adjStatus, 'adj_count' => count($adjustments), 'adj_value' => $adjustments ? $adjValue : null,
            'created_by' => $s['created_by'], 'created_at' => $s['created_at'],
            'data_quality' => $jejak['data_quality']['notes'],
        ];
    }

    /** @param list<array<string,mixed>> $built */
    private static function kpi(array $built): array
    {
        $k = ['sessions' => count($built), 'items' => 0, 'verified' => 0, 'match' => 0, 'mismatch' => 0, 'recounted' => 0, 'pending' => 0, 'excluded' => 0,
            'variance_value' => 0.0, 'variance_positive' => 0.0, 'variance_negative' => 0.0, 'adjustment_value' => 0.0, 'adjustment_count' => 0];
        $cond = [];
        foreach (['good', 'expired', 'rusak', 'deadstock'] as $c) {
            $cond[$c] = ['sku' => 0, 'by_unit' => []];
        }
        foreach ($built as $b) {
            $r = $b['session_row'];
            $k['items'] += $r['total_items'];
            $k['match'] += $r['match'];
            $k['mismatch'] += $r['mismatch'];
            $k['recounted'] += $r['recounted'];
            $k['pending'] += $r['pending'];
            $k['excluded'] += $r['excluded'];
            $k['adjustment_value'] += (float) ($r['adj_value'] ?? 0);
            $k['adjustment_count'] += $r['adj_count'];
            foreach ($b['items'] as $it) {
                if ($it['variance_value'] !== null && $it['variance_qty'] !== null && abs((float) $it['variance_qty']) > self::EPS) {
                    $k['variance_value'] += $it['variance_value'];
                    $k[$it['variance_value'] < 0 ? 'variance_negative' : 'variance_positive'] += $it['variance_value'];
                }
                foreach (['good' => 'good_qty', 'expired' => 'expired_qty', 'rusak' => 'rusak_qty', 'deadstock' => 'deadstock_qty'] as $c => $key) {
                    if ($it[$key] !== null && $it[$key] > self::EPS) {
                        $cond[$c]['sku']++;
                        $cond[$c]['by_unit'][$it['unit']] = round(($cond[$c]['by_unit'][$it['unit']] ?? 0.0) + $it[$key], 6);
                    }
                }
            }
        }
        $k['verified'] = $k['match'] + $k['mismatch'] + $k['recounted'];
        $k['match_pct'] = $k['verified'] > 0 ? round($k['match'] * 100 / $k['verified'], 1) : null;
        foreach (['variance_value', 'variance_positive', 'variance_negative', 'adjustment_value'] as $m) {
            $k[$m] = round($k[$m], 2);
        }
        $k['conditions'] = $cond;
        return $k;
    }

    // ======================================================================
    // export — built from the SAME rows + column catalogues the screen uses (export == screen by construction)
    // ======================================================================

    /** One export cell, formatted like the screen: unknown = "—", people joined, evidence as URL list, numbers stay numbers. */
    public static function cell(string $type, mixed $v): int|float|string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if ($type === 'people') {
            return implode(', ', (array) $v);
        }
        if ($type === 'evidence') {
            return implode('; ', array_map(static fn (array $e) => sprintf('%s [%s %s, %s, %s]%s', $e['url'], $e['condition'], $e['team'], $e['uploaded_by'], $e['uploaded_at'], $e['file_ok'] ? '' : ' (file hilang)'), (array) $v));
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        return ExcelWriterService::sanitizeCellText((string) $v);
    }

    /** @param list<array{0:string,1:string,2:string,3:bool}> $cols @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function table(array $cols, array $rows): array
    {
        return [
            'headers' => array_map(static fn (array $c) => $c[1], $cols),
            'rows' => array_map(static fn (array $r) => array_map(static fn (array $c) => self::cell($c[2], $r[$c[0]] ?? null), $cols), $rows),
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} one CSV / one sheet */
    public static function exportTable(PDO $pdo, string $kind, array $sessionIds, array $f): array
    {
        $sessions = array_map(fn (int $id) => self::build($pdo, $id)['session_row'], $sessionIds);
        switch ($kind) {
            case 'sessions':
                return self::table(self::SESSION_COLUMNS, $sessions);
            case 'items':
                $rows = self::itemRows($pdo, $sessionIds, $f);
                foreach ($rows as $i => &$r) {
                    $r['no'] = $i + 1;
                }
                unset($r);
                return self::table(self::ITEM_COLUMNS, $rows);
            case 'evidence':
                $cols = [['session_number', 'No. Sesi', 'text', true], ['warehouse', 'Gudang', 'text', true], ['sku', 'SKU', 'text', true], ['name', 'Nama Barang', 'text', true],
                    ['condition', 'Kondisi', 'text', true], ['team', 'Tim', 'text', true], ['url', 'Evidence URL / Referensi', 'text', true], ['uploaded_by', 'Diunggah Oleh', 'text', true],
                    ['uploaded_at', 'Timestamp Upload', 'ts', true], ['caption', 'Caption', 'text', true], ['voided', 'Temuan di-VOID', 'text', true], ['file_ok', 'File Ada', 'text', true]];
                $rows = array_map(static fn (array $e) => array_merge($e, ['voided' => $e['voided'] ? 'Ya' : 'Tidak', 'file_ok' => $e['file_ok'] ? 'Ya' : 'Tidak']), self::evidenceRows($pdo, $sessionIds, $f));
                return self::table($cols, $rows);
            case 'history':
                $cols = [['session_number', 'No. Sesi', 'text', true], ['sku', 'SKU', 'text', true], ['name', 'Nama Barang', 'text', true], ['unit', 'Satuan', 'text', true],
                    ['role', 'P1/P2', 'text', true], ['round', 'Ronde', 'int', true], ['actor', 'Petugas', 'text', true], ['action', 'Aksi', 'text', true],
                    ['total_qty', 'Qty Total (legacy)', 'qty', true], ['good_qty', 'Good', 'qty', true], ['expired_qty', 'Expired', 'qty', true], ['rusak_qty', 'Rusak', 'qty', true],
                    ['deadstock_qty', 'Deadstock', 'qty', true], ['counted_at', 'Timestamp Hitung', 'ts', true], ['recorded_at', 'Timestamp Dicatat', 'ts', true],
                    ['backfilled', 'Backfill Waktu', 'text', true], ['void_info', 'VOID (oleh / waktu / alasan)', 'text', true], ['notes', 'Catatan', 'text', true]];
                $rows = array_map(static fn (array $h) => array_merge($h, [
                    'backfilled' => !empty($h['backfilled']) ? 'Ya' : 'Tidak',
                    'void_info' => !empty($h['voided']) ? sprintf('%s / %s / %s', $h['voided']['by'], $h['voided']['at'], $h['voided']['reason'] ?? '—') : null,
                ]), self::historyRows($pdo, $sessionIds, $f));
                return self::table($cols, $rows);
            case 'adjustments':
                $cols = [['session_number', 'No. Sesi', 'text', true], ['warehouse', 'Gudang', 'text', true], ['sku', 'SKU', 'text', true], ['name', 'Nama Barang', 'text', true],
                    ['reference_no', 'Adjustment Ref', 'text', true], ['adjustment_id', 'Adjustment ID', 'int', true], ['qty', 'Qty', 'variance', true], ['hpp', 'HPP Adjustment', 'money', true],
                    ['value', 'Nilai', 'variance_money', true], ['reason', 'Alasan', 'text', true], ['created_by', 'Dibuat Oleh', 'text', true], ['created_at', 'Timestamp', 'ts', true]];
                return self::table($cols, self::adjustmentRows($pdo, $sessionIds));
            case 'audit':
                $cols = [['session_number', 'No. Sesi', 'text', true], ['at', 'Timestamp', 'ts', true], ['actor', 'Pelaku', 'text', true], ['action', 'Aksi', 'text', true],
                    ['entity', 'Entitas', 'text', true], ['entity_id', 'ID Entitas', 'int', true], ['reason', 'Alasan', 'text', true], ['detail', 'Detail (sesudah)', 'text', true]];
                $rows = [];
                foreach ($sessionIds as $sid) {
                    $no = self::build($pdo, (int) $sid)['session_row']['session_number'];
                    foreach (self::auditRows($pdo, (int) $sid) as $a) {
                        $rows[] = $a + ['session_number' => $no, 'detail' => $a['after'] !== null ? json_encode($a['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null];
                    }
                }
                return self::table($cols, $rows);
        }
        throw new ValidationException(['unknown export kind']);
    }

    /** @param array<string,string> $meta @return array<string,array<string,mixed>> the six-sheet workbook + an Info sheet */
    public static function exportWorkbook(PDO $pdo, array $sessionIds, array $f, array $meta): array
    {
        $sheets = [];
        foreach (['Ringkasan Sesi' => 'sessions', 'Rincian Item' => 'items', 'Evidence' => 'evidence', 'Riwayat Hitung' => 'history', 'Adjustment' => 'adjustments', 'Audit Log' => 'audit'] as $name => $kind) {
            $sheets[$name] = self::exportTable($pdo, $kind, $sessionIds, $f) + ['freeze_header' => true, 'autofilter' => true];
        }
        $sheets['Info'] = ['headers' => ['Keterangan', 'Nilai'], 'rows' => array_map(static fn ($k, $v) => [$k, $v], array_keys($meta), array_values($meta))];
        return $sheets;
    }

    // ----------------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------------

    private static function columns(array $cols): array
    {
        return array_map(static fn (array $c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2], 'default' => $c[3]], $cols);
    }

    /** @return array{0:?string,1:?string,2:?string} hitung, verifikasi, final-input */
    private static function v1Times(array $byRole): array
    {
        $p1 = $byRole['P1'][1] ?? null;
        $p2 = $byRole['P2'][1] ?? null;
        $all = array_filter([$p1, $p2]);
        return [$p1, $p2, $all ? max($all) : null];
    }

    private static function legacyTimes(array $l): array
    {
        $p1 = $l['p1_submitted_at'];
        $p2 = $l['p2_submitted_at'];
        $both = array_filter([$p1, $p2]);
        return [$p1, $p2, $l['recount_submitted_at'] ?? ($both ? max($both) : null)];
    }

    /** True when ANY condition column of the line was ever written (legacy sessions older than V2.14.9 have none: unknown, not 0). */
    private static function conditionsRecorded(array $l): bool
    {
        foreach (['final', 'p1', 'p2'] as $p) {
            foreach (['rusak', 'expired', 'deadstock'] as $c) {
                if ($l["{$p}_{$c}_qty"] !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function notePetugas(array $l): ?string
    {
        $parts = [];
        foreach (['p1_notes' => 'P1', 'p2_notes' => 'P2'] as $col => $label) {
            if ($l[$col] !== null && trim((string) $l[$col]) !== '') {
                $parts[] = "{$label}: {$l[$col]}";
            }
        }
        return $parts ? implode(' · ', $parts) : null;
    }

    /** @param array<string,list<string>> $byRole */
    private static function notePetugasV1(array $byRole): ?string
    {
        $parts = [];
        foreach (['P1', 'P2'] as $role) {
            foreach ($byRole[$role] ?? [] as $n) {
                $parts[] = "{$role}: {$n}";
            }
        }
        return $parts ? implode(' · ', $parts) : null;
    }

    private static function nz(mixed $v): ?string
    {
        return $v !== null && trim((string) $v) !== '' ? (string) $v : null;
    }

    /** @return list<array<string,mixed>> legacy P1 / P2 / recount entries read from the line columns */
    private static function legacyHistory(PDO $pdo, array $l): array
    {
        $out = [];
        foreach (['p1' => 'P1', 'p2' => 'P2'] as $p => $role) {
            if ($l["{$p}_qty_base"] === null) {
                continue;
            }
            $out[] = [
                'source' => 'LEGACY', 'finding_id' => null, 'role' => $role, 'round' => 1,
                'actor' => $l["{$p}_user_id"] ? self::username($pdo, (int) $l["{$p}_user_id"]) : null, 'action' => "Hitung {$role}",
                'counted_at' => $l["{$p}_submitted_at"], 'recorded_at' => $l["{$p}_submitted_at"], 'backfilled' => false,
                'total_qty' => (float) $l["{$p}_qty_base"], 'rusak_qty' => self::fl($l["{$p}_rusak_qty"]), 'expired_qty' => self::fl($l["{$p}_expired_qty"]), 'deadstock_qty' => self::fl($l["{$p}_deadstock_qty"]),
                'good_qty' => null, 'quantities' => [], 'notes' => $l["{$p}_notes"], 'voided' => null,
            ];
        }
        if ($l['recount_qty_base'] !== null) {
            $out[] = [
                'source' => 'LEGACY', 'finding_id' => null, 'role' => 'RECOUNT', 'round' => 2,
                'actor' => $l['recount_user_id'] ? self::username($pdo, (int) $l['recount_user_id']) : null, 'action' => 'Hitung ulang (supervisor)',
                'counted_at' => $l['recount_submitted_at'], 'recorded_at' => $l['recount_submitted_at'], 'backfilled' => false,
                'total_qty' => (float) $l['recount_qty_base'], 'rusak_qty' => self::fl($l['final_rusak_qty']), 'expired_qty' => self::fl($l['final_expired_qty']), 'deadstock_qty' => self::fl($l['final_deadstock_qty']),
                'good_qty' => null, 'quantities' => [], 'notes' => $l['recount_reason'], 'voided' => null,
            ];
        }
        return $out;
    }

    private static function fl(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    /** @return list<array<string,mixed>> — same linkage StockOpnameJejakService uses, plus line id kept */
    private static function adjustments(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT sol.id AS line_id, sa.id AS adjustment_id, sa.adjustment_type, sa.reason, sa.reference_no,
                    sa.qty_base_delta, sa.unit_cost_base, sa.created_at, i.sku, i.name, cu.username AS created_by
               FROM stock_opname_lines sol
               JOIN stock_opname_sessions sos ON sos.id = sol.session_id
               JOIN items i ON i.id = sol.item_id
               LEFT JOIN inventory_transactions it ON it.transaction_uuid = CONCAT(sos.session_uuid, ':', sol.item_id)
               JOIN stock_adjustments sa ON sa.adjustment_type = 'OPNAME' AND (sa.id = sol.adjustment_id OR (it.id IS NOT NULL AND sa.transaction_id = it.id))
               LEFT JOIN users cu ON cu.id = sa.created_by
              WHERE sol.session_id = :sid
              ORDER BY i.sku, sa.id"
        );
        $stmt->execute(['sid' => $sessionId]);
        return array_map(static function (array $r): array {
            $qty = (float) $r['qty_base_delta'];
            $cost = (float) $r['unit_cost_base'];
            return [
                'line_id' => (int) $r['line_id'], 'adjustment_id' => (int) $r['adjustment_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'type' => $r['adjustment_type'],
                'reference_no' => $r['reference_no'], 'reason' => $r['reason'], 'qty' => $qty, 'hpp' => $cost, 'value' => round($qty * $cost, 2),
                'created_by' => $r['created_by'], 'created_at' => $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    /** @param list<int> $findingIds @return list<array<string,mixed>> */
    private static function auditEvents(PDO $pdo, int $sessionId, ?int $lineId, array $findingIds): array
    {
        $conds = ["(a.entity_type = 'stock_opname_sessions' AND a.entity_id = :a_sid)"];
        $bind = ['a_sid' => $sessionId];
        if ($lineId !== null) {
            $conds = [];
            $conds[] = '(a.entity_type = \'stock_opname_lines\' AND a.entity_id = :a_lid)';
            $bind = ['a_lid' => $lineId];
        } else {
            $conds[] = "(a.entity_type = 'stock_opname_lines' AND a.entity_id IN (SELECT id FROM stock_opname_lines WHERE session_id = :a_ls))";
            $bind['a_ls'] = $sessionId;
            $conds[] = "(a.entity_type = 'stock_opname_findings' AND a.entity_id IN (SELECT id FROM stock_opname_findings WHERE session_id = :a_fs))";
            $bind['a_fs'] = $sessionId;
        }
        if ($findingIds) {
            $ph = [];
            foreach ($findingIds as $i => $fid) {
                $ph[] = ':a_f' . $i;
                $bind['a_f' . $i] = $fid;
            }
            $conds[] = "(a.entity_type = 'stock_opname_findings' AND a.entity_id IN (" . implode(',', $ph) . '))';
        }
        $stmt = $pdo->prepare(
            'SELECT a.id, a.action_code, a.entity_type, a.entity_id, a.username_snapshot, a.created_at, a.reason, a.before_data, a.after_data
               FROM audit_logs a WHERE ' . implode(' OR ', $conds) . ' ORDER BY a.created_at, a.id LIMIT 500'
        );
        $stmt->execute($bind);
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'action' => $r['action_code'], 'entity' => $r['entity_type'], 'entity_id' => (int) $r['entity_id'], 'actor' => $r['username_snapshot'],
            'at' => $r['created_at'], 'reason' => $r['reason'],
            'before' => $r['before_data'] !== null ? json_decode((string) $r['before_data'], true) : null,
            'after' => $r['after_data'] !== null ? json_decode((string) $r['after_data'], true) : null,
        ], $stmt->fetchAll());
    }

    private static function username(PDO $pdo, int $userId): ?string
    {
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
