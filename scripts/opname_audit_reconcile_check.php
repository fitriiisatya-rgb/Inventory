<?php
declare(strict_types=1);

if (!class_exists('RV3ScriptExit', false)) { final class RV3ScriptExit extends RuntimeException {} }
if (!function_exists('rv3_script_exit')) { function rv3_script_exit(int $c): never { if (defined('RV3_INPROCESS')) { throw new RV3ScriptExit('exit', $c); } exit($c); } }   // run in-process by the package validator: no child process, no shell

/**
 * READ-ONLY reconciliation of "Laporan Stock Opname" (audit report) against the real Stock Opname data. Never writes (READ ONLY
 * transaction; a write is proved to be rejected first). For every selected session (default: every session; or --session=11,12):
 *   A. item rows == COUNT(stock_opname_lines) == the session's Total Item
 *   B. Match + Mismatch + Recount + Pending + Excluded == Total Item (and == a per-status SQL count)
 *   C. Σ item Selisih Nilai == the session Selisih Nilai == the Jejak KPI (production rounding: per-row round(qty x HPP, 2))
 *   D. conditions: Good + Expired + Rusak + Deadstock == Qty Fisik Final where all are known; every shown condition equals its PERSISTED snapshot
 *      (stock_opname_lines.final_*; NOT reconstructed from findings — a posted FINDINGS_V1 session may have a snapshot without findings); the finding history is
 *      compared and every difference is disclosed by the report, never hidden or inferred
 *   E. adjustment total == independent SQL Σ(qty_base_delta × unit_cost_base) over the real linked stock_adjustments at RAW precision (no per-row rounding;
 *      tolerance Rp 0.001 = floating-point accumulation bound only, stated in the output)
 *   F. every petugas shown is a real user AND, for FINDINGS_V1, has a NON-voided finding on that line (no voided actor is ever shown)
 *   G. every evidence reference exists as a photo row attached to a finding AND the file exists on disk
 * Any difference is printed with session / SKU / amount and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php scripts/opname_audit_reconcile_check.php --app-root=<dir with services/> [--session=11,12]
 */

$appRoot = $sessArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--session=')) { $sessArg = substr($arg, 10); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/opname_audit_reconcile_check.php --app-root=<dir with services/> [--session=11,12]\n");
    rv3_script_exit(2);
}
if (!defined('RV3_INPROCESS')) {   // in-process (package validator) the services are already loaded: the installed ones, with the package's substituted in memory
    foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) { if (basename($f) !== 'ReportsV3Routes.php') { require_once $f; } }
}

use App\Services\Database;
use App\Services\StockOpnameAuditReportService as R;
use App\Services\StockOpnameJejakService;
use App\Services\StockOpnamePhotoService;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction.\n");
    $pdo->exec('ROLLBACK');
    rv3_script_exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}
$fail = 0; $n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$near = static fn (?float $a, ?float $b, float $e = 0.01): bool => ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $e;

$ids = $sessArg !== null
    ? array_values(array_filter(array_map('intval', explode(',', $sessArg)), static fn (int $i) => $i > 0))
    : array_map('intval', $pdo->query('SELECT id FROM stock_opname_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
echo 'Sessions: ' . ($ids ? implode(', ', $ids) : '(none)') . "\n";

foreach ($ids as $sid) {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_sessions WHERE id = {$sid}")->fetchColumn();
    if ($exists !== 1) { $check("session {$sid} exists", false); continue; }
    $b = R::build($pdo, $sid);
    $s = $b['session_row'];
    $tag = "#{$sid} {$s['session_number']} {$s['warehouse']} [{$s['model']}/{$s['status']}]";
    echo "\n== {$tag} ==\n";

    // A
    $dbLines = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = {$sid}")->fetchColumn();
    $check("A. item rows ({$dbLines} lines in DB) == report rows == Total Item", count($b['items']) === $dbLines && $s['total_items'] === $dbLines, count($b['items']) . ' rows / ' . $s['total_items'] . ' total');
    // B
    $sql = $pdo->query("SELECT match_status, COUNT(*) c FROM stock_opname_lines WHERE session_id = {$sid} GROUP BY match_status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok = ($sql['MATCH'] ?? 0) == $s['match'] && ($sql['MISMATCH'] ?? 0) == $s['mismatch'] && ($sql['RECOUNTED'] ?? 0) == $s['recounted'] && ($sql['PENDING'] ?? 0) == $s['pending'] && ($sql['EXCLUDED'] ?? 0) == $s['excluded'];
    $check("B. Match {$s['match']} + Mismatch {$s['mismatch']} + Recount {$s['recounted']} + Pending {$s['pending']} + Excluded {$s['excluded']} == Total {$s['total_items']} (and == SQL per status)",
        $s['match'] + $s['mismatch'] + $s['recounted'] + $s['pending'] + $s['excluded'] === $s['total_items'] && $ok, json_encode($sql));
    // C
    $sum = 0.0;
    foreach ($b['items'] as $it) {
        if ($it['variance_value'] !== null && $it['variance_qty'] !== null && abs((float) $it['variance_qty']) > 1e-7) { $sum += $it['variance_value']; }
    }
    $sum = round($sum, 2);
    $j = StockOpnameJejakService::detail($pdo, $sid);
    $check('C. Σ item Selisih Nilai == session Selisih Nilai == Jejak KPI', $near($sum, (float) $s['variance_value']) && $near($sum, $j['kpi']['selisih_nominal']['value']), "Σ {$sum} / session {$s['variance_value']} / jejak {$j['kpi']['selisih_nominal']['value']}");
    // D — the persisted FINAL condition snapshot is stock_opname_lines.final_{rusak,expired,deadstock}_qty (what Jejak / posting use). In a real POSTED FINDINGS_V1 session
    //     it can exist WITHOUT any non-VOID condition finding (e.g. Session 11 SCM), so findings are HISTORY: they are compared, disclosed, never required.
    $bad = [];
    foreach ($b['items'] as $it) {
        $parts = [$it['good_qty'], $it['expired_qty'], $it['rusak_qty'], $it['deadstock_qty']];
        if ($it['final_qty'] !== null && !in_array(null, $parts, true) && !$near((float) $it['final_qty'], round(array_sum($parts), 6), 0.000001)) { $bad[] = "{$it['sku']}: good+E+R+D != final"; }
    }
    $check('D1. Good + Expired + Rusak + Deadstock == Qty Fisik Final (Total) for every item where all four are known', $bad === [], implode(' | ', array_slice($bad, 0, 5)));
    $lineRows = [];
    foreach ($pdo->query("SELECT * FROM stock_opname_lines WHERE session_id = {$sid}")->fetchAll() as $r) { $lineRows[(int) $r['id']] = $r; }
    $bad = [];
    $snapshotRows = 0;
    foreach ($b['items'] as $it) {
        $r = $lineRows[$it['line_id']];
        foreach (['rusak', 'expired', 'deadstock'] as $col) {
            $shown = $it["{$col}_qty"];
            $final = $r["final_{$col}_qty"];
            if ($final !== null) {
                $snapshotRows++;
                if ($shown === null || !$near((float) $final, (float) $shown, 0.000001)) { $bad[] = "{$it['sku']}: {$col} shown " . var_export($shown, true) . " != persisted final_{$col}_qty {$final}"; }
            } elseif ($shown !== null) {
                // no persisted final: only a single team's own recorded value (or 0 once counted) may be shown
                $allowed = [0.0];
                foreach (['p1', 'p2'] as $side) { if ($r["{$side}_{$col}_qty"] !== null) { $allowed[] = (float) $r["{$side}_{$col}_qty"]; } }
                if (!in_array(round((float) $shown, 6), array_map(static fn ($v) => round($v, 6), $allowed), true)) { $bad[] = "{$it['sku']}: {$col} {$shown} has no persisted source"; }
            }
        }
    }
    $check("D2. every shown Rusak / Expired / Deadstock equals its PERSISTED snapshot (stock_opname_lines.final_*; {$snapshotRows} snapshot values), never recomputed from findings", $bad === [], implode(' | ', array_slice($bad, 0, 5)));
    // D3 — the finding history is compared with the snapshot and every difference is DISCLOSED by the report (flag + drawer), none hidden / erased / inferred
    $bad = [];
    $withoutFinding = 0;
    $differs = 0;
    if ($s['counting_model'] === 'FINDINGS_V1') {
        $q = $pdo->query(
            "SELECT f.stock_opname_line_id l, q.condition_type c, f.team_role t, SUM(q.base_qty_contribution) s
               FROM stock_opname_findings f JOIN stock_opname_finding_quantities q ON q.finding_id = f.id
              WHERE f.session_id = {$sid} AND f.voided_at IS NULL GROUP BY f.stock_opname_line_id, q.condition_type, f.team_role"
        )->fetchAll();
        $src = [];
        foreach ($q as $r) { $src[(int) $r['l']][$r['c']][$r['t']] = (float) $r['s']; }
        foreach ($b['items'] as $it) {
            foreach (['rusak' => ['DAMAGED', 'rusak_qty'], 'expired' => ['EXPIRED', 'expired_qty'], 'deadstock' => ['DEADSTOCK', 'deadstock_qty']] as $name => [$cond, $key]) {
                $snap = $it[$key];
                if ($snap === null) { continue; }
                $sides = array_map(static fn ($v) => round($v, 6), $src[$it['line_id']][$cond] ?? []);
                $expected = $sides === [] ? ($snap > 1e-9 ? 'tanpa_temuan' : 'cocok') : (in_array(round((float) $snap, 6), $sides, true) ? 'cocok' : 'berbeda');
                $got = $it['condition_audit']['rows'][$name]['status'] ?? null;
                if ($got !== $expected) { $bad[] = "{$it['sku']}: {$name} status {$got} != expected {$expected}"; }
                if ($expected !== 'cocok') {
                    $expected === 'tanpa_temuan' ? $withoutFinding++ : $differs++;
                    if ($it['condition_flag'] === null || !str_contains((string) $it['condition_flag'], ucfirst($name))) { $bad[] = "{$it['sku']}: {$name} difference from the findings is not flagged"; }
                }
            }
        }
    }
    $check("D3. finding history vs snapshot: {$withoutFinding} snapshot value(s) without any non-VOID finding and {$differs} differing — every one is flagged in the report (none hidden, erased or inferred)", $bad === [], implode(' | ', array_slice($bad, 0, 5)));
    if ($withoutFinding + $differs > 0) { echo "    NOTE: kondisi final berasal dari snapshot posting (final_*), bukan dari temuan — ditampilkan apa adanya; riwayat temuan ditampilkan terpisah.\n"; }
    // E — RAW precision: Σ(qty_base_delta × unit_cost_base) of the real linked stock_adjustments, never rounded per row. The tolerance is ONLY the floating-point accumulation
    //     bound of summing ~10^3 exact decimal products in a double (≈1e-6 each at Rp 10^9 magnitude): 0.001 Rp, three orders of magnitude below any real discrepancy.
    $ADJ_TOL = 0.001;
    $adjIds = array_column($b['adjustments'], 'adjustment_id');
    $dbAdjStr = $adjIds ? (string) $pdo->query('SELECT COALESCE(SUM(qty_base_delta * unit_cost_base),0) FROM stock_adjustments WHERE adjustment_type = \'OPNAME\' AND id IN (' . implode(',', array_map('intval', $adjIds)) . ')')->fetchColumn() : '0';
    $dbAdj = (float) $dbAdjStr;
    $rawSum = array_sum(array_column($b['adjustments'], 'value'));
    $perRow = true;
    $rowDiff = 0.0;
    $roundedSum = 0.0;
    $rawRows = $adjIds ? $pdo->query('SELECT id, qty_base_delta * unit_cost_base AS v FROM stock_adjustments WHERE id IN (' . implode(',', array_map('intval', $adjIds)) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    foreach ($b['adjustments'] as $a) {
        $d = abs($a['value'] - (float) $rawRows[$a['adjustment_id']]);
        $rowDiff = max($rowDiff, $d);
        if ($d > 1e-6) { $perRow = false; }
        $roundedSum += round((float) $rawRows[$a['adjustment_id']], 2);
    }
    $check('E1. every adjustment value == qty_base_delta × unit_cost_base at RAW precision (no per-row rounding; max row difference ' . number_format($rowDiff, 10, '.', '') . ')', $perRow);
    $itemAdj = array_sum(array_map(static fn ($i) => (float) ($i['adj_value'] ?? 0), $b['items']));
    $check("E2. adjustment total: report Rp " . number_format((float) ($s['adj_value'] ?? 0), 6, '.', '') . " == Σ item rows Rp " . number_format($itemAdj, 6, '.', '') . " == independent SQL Rp {$dbAdjStr} (tolerance Rp {$ADJ_TOL} = float-accumulation bound only)",
        abs((float) ($s['adj_value'] ?? 0) - $dbAdj) <= $ADJ_TOL && abs($rawSum - $dbAdj) <= $ADJ_TOL && abs($itemAdj - $dbAdj) <= $ADJ_TOL, count($adjIds) . ' linked adjustments');
    if (abs($roundedSum - $dbAdj) > 0.0005) { echo '    NOTE: Σ of per-row 2-dp rounded values would be Rp ' . number_format($roundedSum, 2, '.', '') . ' (differs from the raw total by Rp ' . number_format(abs($roundedSum - $dbAdj), 4, '.', '') . ') — the report does NOT round per row; only the currency display / export formatting rounds.' . "\n"; }
    if ($s['status'] === 'POSTED') { $check('E3. a POSTED session shows its adjustment status explicitly (never blank)', $s['adj_status'] !== ''); }
    // F
    $badActors = [];
    $userCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
    $liveCounter = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_findings f JOIN users u ON u.id = f.counter_user_id WHERE f.session_id = :s AND f.stock_opname_line_id = :l AND f.voided_at IS NULL AND (u.username = :u OR f.counter_username_snapshot = :u2)");
    foreach ($b['items'] as $it) {
        foreach (array_merge($it['p_hitung'], $it['p_verifikasi']) as $name) {
            $userCount->execute(['u' => $name]);
            if ((int) $userCount->fetchColumn() < 1) { $badActors[] = "{$it['sku']}: {$name} is not a user"; }
            if ($s['counting_model'] === 'FINDINGS_V1') {
                $liveCounter->execute(['s' => $sid, 'l' => $it['line_id'], 'u' => $name, 'u2' => $name]);
                if ((int) $liveCounter->fetchColumn() < 1) { $badActors[] = "{$it['sku']}: {$name} has no non-voided finding"; }
            }
        }
    }
    $check('F. every petugas shown is a real user' . ($s['counting_model'] === 'FINDINGS_V1' ? ' with a NON-voided finding on that line (no voided actor shown)' : ''), $badActors === [], implode(' | ', array_slice($badActors, 0, 5)));
    // G
    $badEv = [];
    $nEv = 0;
    foreach ($b['items'] as $it) {
        foreach ($it['evidence'] as $e) {
            $nEv++;
            $st = $pdo->prepare('SELECT storage_path, finding_id FROM stock_opname_finding_photos WHERE id = :id AND session_id = :s');
            $st->execute(['id' => $e['id'], 's' => $sid]);
            $row = $st->fetch();
            if (!$row || $row['finding_id'] === null) { $badEv[] = "{$it['sku']}: photo {$e['id']} missing / unattached"; }
            elseif (!is_file(StockOpnamePhotoService::absolutePath((string) $row['storage_path']))) { $badEv[] = "{$it['sku']}: photo {$e['id']} file missing on disk"; }
        }
    }
    $dbPhotos = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_finding_photos WHERE session_id = {$sid} AND finding_id IS NOT NULL")->fetchColumn();
    $check("G. all {$nEv} evidence references exist (row + file); none lost (DB has {$dbPhotos} attached photos)", $badEv === [] && $nEv === $dbPhotos, implode(' | ', array_slice($badEv, 0, 5)));
    echo "    Total Item {$s['total_items']} | Match {$s['match']} | Mismatch {$s['mismatch']} | Selisih Nilai {$s['variance_value']} | Adjustment " . ($s['adj_value'] ?? '—') . " | Evidence {$nEv}\n";
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
