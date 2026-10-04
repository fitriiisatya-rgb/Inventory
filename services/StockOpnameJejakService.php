<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Jejak Stock Opname" — READ-ONLY per-session trace for the Jejak drawer
 * (report-opname.js row click). One call returns everything the drawer
 * shows for ONE session: identity, every line (system / Count 01 / Count 02
 * / final / variance / dead stock / rusak / HPP and the Rupiah values of
 * each) and the posted adjustments, plus the six KPI figures computed from
 * those very rows so a KPI card can never disagree with its drill-down.
 *
 * STRICTLY READ-ONLY: this class only SELECTs. It never writes
 * stock_opname_*, stock_adjustments, inventory_transactions or
 * inventory_batches, never calls StockOpnameService::finalize()/post(), and
 * never calls anything that could (StockOpnameBookStockService::
 * reconciliation() — used for FINDINGS_V1 — is itself read-only).
 *
 * AUTHORITATIVE SOURCES (nothing is guessed; every field below is a column
 * the Stock Opname workflow itself writes):
 *
 *   session identity     stock_opname_sessions (+ users, warehouses)
 *   HPP                  stock_opname_lines.unit_cost_base — the cost
 *                        snapshotted at session start; the same figure the
 *                        existing Laporan Stock Opname and P1/P2 report
 *                        multiply by to value a session
 *   Count 01 / Count 02  stock_opname_lines.p1_qty_base / p2_qty_base; who:
 *                        per-line distinct counters of the NON-VOIDED
 *                        stock_opname_findings rows (a team can have several
 *                        counters per line), falling back to
 *                        p{1,2}_user_id; never a session-level person
 *   Dead stock / Rusak   stock_opname_lines.final_deadstock_qty /
 *                        final_rusak_qty (the P1/P2-agreed or supervisor-
 *                        resolved quantities — no zero-count inference)
 *   counting_model-dependent (identical rules to
 *   StockOpnameMonthlyReportService, deliberately re-stated here so this
 *   class has no dependency on that V2.16.4 service):
 *     LEGACY_DUAL_COUNT  system = system_qty_base, final = counted_qty_base,
 *                        variance = variance_qty_base (set by finalize())
 *     FINDINGS_V1        system = EOD book stock, final = GOOD physical EOD
 *                        and variance = final - system, all from
 *                        StockOpnameBookStockService::reconciliation()
 *                        (variance is never read from variance_qty_base,
 *                        which finalize() never populates for this model)
 *   Adjustment           stock_adjustments rows (adjustment_type OPNAME)
 *                        linked to the session's lines either by
 *                        stock_opname_lines.adjustment_id or by the exact
 *                        key StockOpnameService::post() writes,
 *                        inventory_transactions.transaction_uuid =
 *                        '<session_uuid>:<item_id>'. Amount = qty_base_delta
 *                        x the adjustment's own unit_cost_base, i.e. what
 *                        was actually posted. A session with no linked
 *                        adjustment reports 0 rows / Rp 0 — never an
 *                        estimate.
 *
 * Money rule: every Rupiah figure is round(qty x HPP, 2) PER ROW, and every
 * KPI is the sum of those rounded rows, so cards and drill-down totals are
 * equal by construction.
 *
 * Unknown stays unknown: a null system/final/variance quantity (e.g. a
 * FINDINGS_V1 SKU without an EOD baseline yet) is returned as null, never
 * as 0, is excluded from the KPI sums, and is counted in data_quality.
 */
final class StockOpnameJejakService
{
    public static function detail(PDO $pdo, int $sessionId): array
    {
        $session = self::loadSession($pdo, $sessionId);
        $countingModel = $session['counting_model'] ?? 'LEGACY_DUAL_COUNT';
        $isFindingsV1 = $countingModel === 'FINDINGS_V1';

        if ($isFindingsV1 && !class_exists(StockOpnameBookStockService::class)) {
            // Never fall back to the session-start snapshot for a FINDINGS_V1
            // session (it is not the authoritative stock) — fail loudly.
            throw new \RuntimeException('StockOpnameBookStockService is not loaded — cannot compute the EOD reconciliation for a FINDINGS_V1 session');
        }
        $recon = $isFindingsV1 ? self::reconciliationBySku($pdo, $sessionId) : [];
        $countersByLine = self::countersByLine($pdo, $sessionId);

        $stmt = $pdo->prepare(
            "SELECT sol.*, i.sku, i.name, u.code AS base_unit_code, c.name AS category_name,
                    p1u.username AS p1_username, p2u.username AS p2_username
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
               LEFT JOIN users p1u ON p1u.id = sol.p1_user_id
               LEFT JOIN users p2u ON p2u.id = sol.p2_user_id
              WHERE sol.session_id = :sid
              ORDER BY i.sku"
        );
        $stmt->execute(['sid' => $sessionId]);
        $lines = $stmt->fetchAll();

        $adjustmentsByLine = [];
        $adjustments = self::loadAdjustments($pdo, $sessionId);
        foreach ($adjustments as $a) {
            $adjustmentsByLine[$a['line_id']][] = $a;
        }

        $items = [];
        foreach ($lines as $l) {
            $items[] = self::formatLine($l, $isFindingsV1, $recon[$l['sku']] ?? null, $countersByLine[(int) $l['id']] ?? [], $adjustmentsByLine[(int) $l['id']] ?? []);
        }

        return [
            'session' => self::sessionBlock($pdo, $session, $isFindingsV1),
            'items' => $items,
            'adjustments' => array_map(static function (array $a): array {
                unset($a['line_id']);
                return $a;
            }, $adjustments),
            'kpi' => self::kpi($items, $adjustments),
            'summary' => self::summary($items),
            'data_quality' => self::dataQuality($items, $adjustments, $session),
            'sources' => [
                'hpp' => 'stock_opname_lines.unit_cost_base (snapshot HPP awal sesi)',
                'system_qty' => $isFindingsV1 ? 'Rekonsiliasi EOD — stok buku (StockOpnameBookStockService)' : 'stock_opname_lines.system_qty_base (snapshot sistem)',
                'final_qty' => $isFindingsV1 ? 'Rekonsiliasi EOD — fisik GOOD' : 'stock_opname_lines.counted_qty_base',
                'variance' => $isFindingsV1 ? 'final GOOD EOD − stok buku EOD' : 'stock_opname_lines.variance_qty_base',
                'dead_rusak' => 'stock_opname_lines.final_deadstock_qty / final_rusak_qty',
                'adjustment' => 'stock_adjustments (OPNAME) × unit_cost_base adjustment',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // lines
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $recon StockOpnameBookStockService::reconciliation() row (FINDINGS_V1 only)
     * @param array<string,list<string>> $counters ['P1'=>[...usernames], 'P2'=>[...]]
     * @param list<array<string,mixed>> $lineAdjustments
     */
    private static function formatLine(array $l, bool $isFindingsV1, ?array $recon, array $counters, array $lineAdjustments): array
    {
        $hpp = (float) $l['unit_cost_base'];
        $excluded = (int) $l['is_excluded'] === 1;

        // Dead stock / Rusak / Expired: the agreed final_* column when
        // resolved; otherwise, while only ONE team has counted the line (the
        // ordinary mid-session state — final_* are only written once BOTH
        // sides agree), that one team's own recorded quantity, which is the
        // same "only value there is yet" rule
        // StockOpnameBookStockService::physicalEodForLine() applies to the
        // physical total. Both teams counted but disagreeing and not yet
        // resolved stays null and is flagged — never picked arbitrarily.
        [$dead, $rusak, $expired, $conditionBasis] = self::effectiveConditions($l);
        $unresolved = $conditionBasis === 'unresolved';

        if ($isFindingsV1) {
            $system = $recon['book_stock_eod'] ?? null;
            // reconciliation()['final_physical_eod'] is TOTAL physical (GOOD +
            // damaged + expired + deadstock); GOOD = total minus THESE three
            // quantities (the same ones displayed in this row).
            $final = ($recon !== null && $recon['final_physical_eod'] !== null)
                ? round((float) $recon['final_physical_eod'] - (float) ($rusak ?? 0.0) - (float) ($expired ?? 0.0) - (float) ($dead ?? 0.0), 6)
                : null;
            $variance = ($system !== null && $final !== null) ? round($final - $system, 6) : null;
        } else {
            $system = (float) $l['system_qty_base'];
            $final = $l['counted_qty_base'] !== null ? (float) $l['counted_qty_base'] : null;
            $variance = $l['variance_qty_base'] !== null ? (float) $l['variance_qty_base'] : null;
        }
        if ($excluded) {
            // An excluded line is never counted and never adjusted
            // (StockOpnameService::finalize() skips it) — no phantom variance.
            $variance = null;
        }

        $adjQty = 0.0;
        $adjValue = 0.0;
        foreach ($lineAdjustments as $a) {
            $adjQty += $a['qty'];
            $adjValue += $a['value'];
        }

        return [
            'line_id' => (int) $l['id'],
            'item_id' => (int) $l['item_id'],
            'sku' => $l['sku'],
            'name' => $l['name'],
            'category' => $l['category_name'] ?? '(Tanpa Kategori)',
            'unit' => $l['base_unit_code'],
            'system_qty' => $system,
            'count1_qty' => $l['p1_qty_base'] !== null ? (float) $l['p1_qty_base'] : null,
            'count1_users' => self::users($counters['P1'] ?? [], $l['p1_username'], $l['p1_qty_base'] !== null),
            'count2_qty' => $l['p2_qty_base'] !== null ? (float) $l['p2_qty_base'] : null,
            'count2_users' => self::users($counters['P2'] ?? [], $l['p2_username'], $l['p2_qty_base'] !== null),
            'recount_qty' => $l['recount_qty_base'] !== null ? (float) $l['recount_qty_base'] : null,
            'final_qty' => $final,
            'variance_qty' => $variance,
            'dead_qty' => $dead,
            'rusak_qty' => $rusak,
            'expired_qty' => $expired,
            'hpp' => $hpp,
            'system_value' => self::value($system, $hpp),
            'final_value' => self::value($final, $hpp),
            'variance_value' => self::value($variance, $hpp),
            'dead_value' => self::value($dead, $hpp),
            'rusak_value' => self::value($rusak, $hpp),
            'expired_value' => self::value($expired, $hpp),
            'match_status' => $l['match_status'],
            'is_excluded' => $excluded,
            'condition_unresolved' => $unresolved,
            'condition_basis' => $conditionBasis,
            'note' => self::note($l),
            'adjustment_qty' => $lineAdjustments ? round($adjQty, 6) : null,
            'adjustment_value' => $lineAdjustments ? round($adjValue, 2) : null,
        ];
    }

    /**
     * @return array{0:?float,1:?float,2:?float,3:string} [deadstock, rusak, expired, basis]
     *         basis: 'final' | 'single_side' | 'unresolved' | 'none'
     *
     * A condition nobody recorded on a line that WAS counted is 0 (nothing
     * was classified); on a line nobody counted it stays null (unknown).
     */
    private static function effectiveConditions(array $l): array
    {
        $cols = ['deadstock', 'rusak', 'expired'];
        $p1Counted = $l['p1_qty_base'] !== null;
        $p2Counted = $l['p2_qty_base'] !== null;
        $counted = $p1Counted || $p2Counted || $l['counted_qty_base'] !== null;

        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $l["final_{$c}_qty"] !== null ? (float) $l["final_{$c}_qty"] : null;
        }
        $basis = 'final';

        if (in_array(null, $out, true)) {
            if ($p1Counted xor $p2Counted) {
                // Only one team has counted: its own recorded quantity is the only value there is yet.
                $side = $p1Counted ? 'p1' : 'p2';
                foreach ($cols as $c) {
                    $out[$c] ??= $l["{$side}_{$c}_qty"] !== null ? (float) $l["{$side}_{$c}_qty"] : null;
                }
                $basis = 'single_side';
            } else {
                // Both teams counted (or nobody): a missing final_* beside a
                // positive P1/P2 value is a disagreement awaiting resolution.
                foreach ($cols as $c) {
                    if ($out[$c] === null && ((float) ($l["p1_{$c}_qty"] ?? 0) > 0 || (float) ($l["p2_{$c}_qty"] ?? 0) > 0)) {
                        $basis = 'unresolved';
                    }
                }
                if ($basis !== 'unresolved' && !$counted) {
                    $basis = 'none';
                }
            }
        }

        if ($basis !== 'unresolved' && $counted) {
            foreach ($cols as $c) {
                $out[$c] ??= 0.0;
            }
        }
        return [$out['deadstock'], $out['rusak'], $out['expired'], $basis];
    }

    private static function value(?float $qty, float $hpp): ?float
    {
        return $qty === null ? null : round($qty * $hpp, 2);
    }

    /** @param list<string> $fromFindings */
    private static function users(array $fromFindings, ?string $fallbackUsername, bool $hasCount): array
    {
        if ($fromFindings) {
            return $fromFindings;
        }
        return ($hasCount && $fallbackUsername !== null && $fallbackUsername !== '') ? [$fallbackUsername] : [];
    }

    /** Stored reason/note only — the agreed final note, else the line note, else what P1/P2 each wrote. */
    private static function note(array $l): ?string
    {
        foreach (['final_notes', 'notes'] as $col) {
            if ($l[$col] !== null && trim((string) $l[$col]) !== '') {
                return (string) $l[$col];
            }
        }
        $parts = [];
        foreach (['p1_notes' => 'P1', 'p2_notes' => 'P2'] as $col => $label) {
            if ($l[$col] !== null && trim((string) $l[$col]) !== '') {
                $parts[] = "{$label}: {$l[$col]}";
            }
        }
        return $parts ? implode(' · ', $parts) : null;
    }

    // ------------------------------------------------------------------
    // KPI / summary — computed from the very rows returned above
    // ------------------------------------------------------------------

    private static function kpi(array $items, array $adjustments): array
    {
        $sys = ['value' => 0.0, 'count' => 0, 'unvalued' => 0];
        $fin = ['value' => 0.0, 'count' => 0, 'unvalued' => 0];
        $var = ['value' => 0.0, 'shortage' => 0.0, 'excess' => 0.0, 'count' => 0, 'unvalued' => 0];
        $dead = ['value' => 0.0, 'qty' => 0.0, 'count' => 0];
        $rusak = ['value' => 0.0, 'qty' => 0.0, 'count' => 0];

        foreach ($items as $it) {
            if ($it['system_value'] === null) {
                $sys['unvalued']++;
            } else {
                $sys['value'] += $it['system_value'];
                $sys['count']++;
            }
            if ($it['final_value'] === null) {
                $fin['unvalued']++;
            } else {
                $fin['value'] += $it['final_value'];
                $fin['count']++;
            }
            if ($it['variance_value'] === null) {
                if (!$it['is_excluded']) {
                    $var['unvalued']++;
                }
            } elseif (abs((float) $it['variance_qty']) >= 0.0000001) {
                $var['value'] += $it['variance_value'];
                $var['count']++;
                if ($it['variance_value'] < 0) {
                    $var['shortage'] += $it['variance_value'];
                } else {
                    $var['excess'] += $it['variance_value'];
                }
            }
            if ($it['dead_qty'] !== null && $it['dead_qty'] > 0) {
                $dead['value'] += $it['dead_value'];
                $dead['qty'] += $it['dead_qty'];
                $dead['count']++;
            }
            if ($it['rusak_qty'] !== null && $it['rusak_qty'] > 0) {
                $rusak['value'] += $it['rusak_value'];
                $rusak['qty'] += $it['rusak_qty'];
                $rusak['count']++;
            }
        }

        $adj = ['value' => 0.0, 'positive' => 0.0, 'negative' => 0.0, 'count' => count($adjustments)];
        foreach ($adjustments as $a) {
            $adj['value'] += $a['value'];
            if ($a['value'] >= 0) {
                $adj['positive'] += $a['value'];
            } else {
                $adj['negative'] += $a['value'];
            }
        }

        $r2 = static fn (float $v): float => round($v, 2);
        return [
            'nilai_stok_sistem' => ['value' => $r2($sys['value']), 'count' => $sys['count'], 'unvalued' => $sys['unvalued']],
            'nilai_final_count' => ['value' => $r2($fin['value']), 'count' => $fin['count'], 'unvalued' => $fin['unvalued']],
            'selisih_nominal' => ['value' => $r2($var['value']), 'shortage' => $r2($var['shortage']), 'excess' => $r2($var['excess']), 'count' => $var['count'], 'unvalued' => $var['unvalued']],
            'dead_stock' => ['value' => $r2($dead['value']), 'qty' => round($dead['qty'], 6), 'count' => $dead['count']],
            'rusak' => ['value' => $r2($rusak['value']), 'qty' => round($rusak['qty'], 6), 'count' => $rusak['count']],
            'adjustment_bersih' => ['value' => $r2($adj['value']), 'positive' => $r2($adj['positive']), 'negative' => $r2($adj['negative']), 'count' => $adj['count']],
        ];
    }

    /**
     * "Cocok" = a known variance of exactly 0. "Perlu review" = every other
     * non-excluded line (non-zero variance OR variance not determinable yet).
     */
    private static function summary(array $items): array
    {
        $cocok = 0;
        $review = 0;
        $dead = 0;
        $rusak = 0;
        $excluded = 0;
        foreach ($items as $it) {
            if ($it['is_excluded']) {
                $excluded++;
            } elseif ($it['variance_qty'] !== null && abs($it['variance_qty']) < 0.0000001) {
                $cocok++;
            } else {
                $review++;
            }
            if ($it['dead_qty'] !== null && $it['dead_qty'] > 0) {
                $dead++;
            }
            if ($it['rusak_qty'] !== null && $it['rusak_qty'] > 0) {
                $rusak++;
            }
        }
        return ['total_items' => count($items), 'sku_cocok' => $cocok, 'perlu_review' => $review, 'dead_stock_sku' => $dead, 'rusak_sku' => $rusak, 'excluded' => $excluded];
    }

    private static function dataQuality(array $items, array $adjustments, array $session): array
    {
        $noSystem = 0;
        $noFinal = 0;
        $noVariance = 0;
        $unresolved = 0;
        foreach ($items as $it) {
            $noSystem += $it['system_qty'] === null ? 1 : 0;
            $noFinal += $it['final_qty'] === null ? 1 : 0;
            $noVariance += ($it['variance_qty'] === null && !$it['is_excluded']) ? 1 : 0;
            $unresolved += $it['condition_unresolved'] ? 1 : 0;
        }
        $notes = [];
        if ($session['status'] === 'POSTED' && !$adjustments) {
            $notes[] = 'Sesi berstatus POSTED tetapi tidak ada adjustment OPNAME yang terhubung ke baris sesi ini.';
        }
        if ($session['status'] !== 'POSTED') {
            $notes[] = "Sesi berstatus {$session['status']} — belum ada adjustment ter-posting.";
        }
        if ($noSystem > 0) {
            $notes[] = "{$noSystem} SKU belum memiliki qty sistem (belum direkonsiliasi) dan tidak dihitung dalam nilai.";
        }
        if ($unresolved > 0) {
            $notes[] = "{$unresolved} SKU memiliki klasifikasi Dead Stock/Rusak/Expired yang belum disepakati P1/P2.";
        }
        return [
            'system_qty_missing' => $noSystem, 'final_qty_missing' => $noFinal, 'variance_missing' => $noVariance,
            'condition_unresolved_lines' => $unresolved, 'adjustment_rows' => count($adjustments), 'notes' => $notes,
        ];
    }

    // ------------------------------------------------------------------
    // session / adjustments / counters
    // ------------------------------------------------------------------

    private static function sessionBlock(PDO $pdo, array $s, bool $isFindingsV1): array
    {
        $wh = $pdo->prepare('SELECT name, code FROM warehouses WHERE id = :id');
        $wh->execute(['id' => (int) $s['warehouse_id']]);
        $wh = $wh->fetch() ?: [];

        $names = [];
        foreach (['created_by', 'finalized_by', 'posted_by', 'cancelled_by', 'supervisor_id', 'p1_user_id', 'p2_user_id'] as $col) {
            $names[$col] = $s[$col] !== null ? self::username($pdo, (int) $s[$col]) : null;
        }

        $team = ['P1' => [], 'P2' => []];
        $tm = $pdo->prepare(
            'SELECT tm.team_role, u.username FROM stock_opname_team_members tm JOIN users u ON u.id = tm.user_id
              WHERE tm.session_id = :sid ORDER BY tm.team_role, tm.id'
        );
        $tm->execute(['sid' => (int) $s['id']]);
        foreach ($tm->fetchAll() as $row) {
            $team[$row['team_role']][] = $row['username'];
        }

        return [
            'id' => (int) $s['id'],
            'session_number' => $s['session_number'] ?? ('OPN-' . $s['id']),
            'session_date' => $s['session_date'],
            'scope' => $s['scope'],
            'warehouse_id' => (int) $s['warehouse_id'],
            'warehouse_name' => $wh['name'] ?? null,
            'warehouse_code' => $wh['code'] ?? null,
            'status' => $s['status'],
            'counting_model' => $s['counting_model'] ?? 'LEGACY_DUAL_COUNT',
            'stock_source_label' => $isFindingsV1 ? 'Rekonsiliasi EOD (Stok Buku)' : 'Snapshot Sistem (P1/P2)',
            'created_by' => $names['created_by'], 'created_at' => $s['created_at'],
            'finalized_by' => $names['finalized_by'], 'finalized_at' => $s['finalized_at'],
            'posted_by' => $names['posted_by'], 'posted_at' => $s['posted_at'],
            'cancelled_by' => $names['cancelled_by'], 'cancelled_at' => $s['cancelled_at'],
            'supervisor' => $names['supervisor_id'],
            'p1_user' => $names['p1_user_id'], 'p2_user' => $names['p2_user_id'],
            'team' => $team,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function loadAdjustments(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT sol.id AS line_id, sa.id AS adjustment_id, sa.adjustment_type, sa.reason, sa.reference_no,
                    sa.qty_base_delta, sa.unit_cost_base, sa.created_at, i.sku, i.name, cu.username AS created_by
               FROM stock_opname_lines sol
               JOIN stock_opname_sessions sos ON sos.id = sol.session_id
               JOIN items i ON i.id = sol.item_id
               LEFT JOIN inventory_transactions it ON it.transaction_uuid = CONCAT(sos.session_uuid, ':', sol.item_id)
               JOIN stock_adjustments sa ON sa.adjustment_type = 'OPNAME'
                                         AND (sa.id = sol.adjustment_id OR (it.id IS NOT NULL AND sa.transaction_id = it.id))
               LEFT JOIN users cu ON cu.id = sa.created_by
              WHERE sol.session_id = :sid
              ORDER BY i.sku, sa.id"
        );
        $stmt->execute(['sid' => $sessionId]);
        return array_map(static function (array $r): array {
            $qty = (float) $r['qty_base_delta'];
            $cost = (float) $r['unit_cost_base'];
            return [
                'line_id' => (int) $r['line_id'],
                'adjustment_id' => (int) $r['adjustment_id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'type' => $r['adjustment_type'],
                'jenis' => $qty >= 0 ? 'Selisih Lebih (Opname)' : 'Selisih Kurang (Opname)',
                'reason' => $r['reason'],
                'reference_no' => $r['reference_no'],
                'qty' => $qty,
                'hpp' => $cost,
                'value' => round($qty * $cost, 2),
                'created_by' => $r['created_by'],
                'created_at' => $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    /** @return array<int,array<string,list<string>>> line_id => ['P1'=>[names], 'P2'=>[names]] from NON-VOIDED findings */
    private static function countersByLine(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT f.stock_opname_line_id AS line_id, f.team_role, COALESCE(f.counter_username_snapshot, u.username) AS username
               FROM stock_opname_findings f
               JOIN users u ON u.id = f.counter_user_id
              WHERE f.session_id = :sid AND f.voided_at IS NULL
              ORDER BY f.created_at, f.id"
        );
        $stmt->execute(['sid' => $sessionId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $lineId = (int) $r['line_id'];
            $out[$lineId][$r['team_role']] ??= [];
            if (!in_array($r['username'], $out[$lineId][$r['team_role']], true)) {
                $out[$lineId][$r['team_role']][] = $r['username'];
            }
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private static function reconciliationBySku(PDO $pdo, int $sessionId): array
    {
        $bySku = [];
        foreach (StockOpnameBookStockService::reconciliation($pdo, $sessionId) as $row) {
            $bySku[$row['sku']] = $row;
        }
        return $bySku;
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

    private static function username(PDO $pdo, int $userId): ?string
    {
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
