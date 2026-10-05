<?php
declare(strict_types=1);

/**
 * READ-ONLY reconciliation of "Laporan Stock Opname" (audit report) against the real Stock Opname data. Never writes (READ ONLY
 * transaction; a write is proved to be rejected first). For every selected session (default: every session; or --session=11,12):
 *   A. item rows == COUNT(stock_opname_lines) == the session's Total Item
 *   B. Match + Mismatch + Recount + Pending + Excluded == Total Item (and == a per-status SQL count)
 *   C. Σ item Selisih Nilai == the session Selisih Nilai == the Jejak KPI (production rounding: per-row round(qty x HPP, 2))
 *   D. conditions: Good + Expired + Rusak + Deadstock == Qty Fisik Final where all are known; every shown condition equals the stored
 *      source (legacy final_* / one-sided p*_ columns; FINDINGS_V1 non-voided finding quantities)
 *   E. adjustment total == independent SQL over the real linked stock_adjustments
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
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/opname_audit_reconcile_check.php --app-root=<dir with services/> [--session=11,12]\n");
    exit(2);
}
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) { require_once $f; }

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
    exit(3);
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
    // D
    $bad = [];
    foreach ($b['items'] as $it) {
        $parts = [$it['good_qty'], $it['expired_qty'], $it['rusak_qty'], $it['deadstock_qty']];
        if ($it['final_qty'] !== null && !in_array(null, $parts, true) && !$near((float) $it['final_qty'], round(array_sum($parts), 6), 0.000001)) { $bad[] = "{$it['sku']}: good+E+R+D != final"; }
    }
    if ($s['counting_model'] === 'LEGACY_DUAL_COUNT') {
        $rows = $pdo->query("SELECT sol.id, sol.final_rusak_qty, sol.final_expired_qty, sol.final_deadstock_qty FROM stock_opname_lines sol WHERE sol.session_id = {$sid}")->fetchAll();
        $byLine = [];
        foreach ($rows as $r) { $byLine[(int) $r['id']] = $r; }
        foreach ($b['items'] as $it) {
            $r = $byLine[$it['line_id']];
            foreach (['rusak' => 'rusak_qty', 'expired' => 'expired_qty', 'deadstock' => 'deadstock_qty'] as $col => $key) {
                if ($r["final_{$col}_qty"] !== null && !$near((float) $r["final_{$col}_qty"], (float) $it[$key], 0.000001)) { $bad[] = "{$it['sku']}: {$col} {$it[$key]} != stored final {$r["final_{$col}_qty"]}"; }
            }
        }
    } else {
        $q = $pdo->query(
            "SELECT f.stock_opname_line_id l, q.condition_type c, f.team_role t, SUM(q.base_qty_contribution) s
               FROM stock_opname_findings f JOIN stock_opname_finding_quantities q ON q.finding_id = f.id
              WHERE f.session_id = {$sid} AND f.voided_at IS NULL GROUP BY f.stock_opname_line_id, q.condition_type, f.team_role"
        )->fetchAll();
        $src = [];
        foreach ($q as $r) { $src[(int) $r['l']][$r['c']][$r['t']] = (float) $r['s']; }
        foreach ($b['items'] as $it) {
            foreach (['rusak' => ['DAMAGED', 'rusak_qty'], 'expired' => ['EXPIRED', 'expired_qty'], 'deadstock' => ['DEADSTOCK', 'deadstock_qty']] as $name => [$cond, $key]) {
                if ($it[$key] === null) { continue; }
                $sides = $src[$it['line_id']][$cond] ?? [];
                // the shown value must be one the teams actually recorded (or 0 when nobody recorded that condition)
                if (!($sides === [] && abs((float) $it[$key]) < 1e-9) && !in_array(round((float) $it[$key], 6), array_map(static fn ($v) => round($v, 6), $sides), true)) { $bad[] = "{$it['sku']}: {$name} {$it[$key]} not among recorded " . json_encode($sides); }
            }
        }
    }
    $check('D. condition split: Good + Expired + Rusak + Deadstock == Qty Fisik Final, and every condition equals its stored source', $bad === [], implode(' | ', array_slice($bad, 0, 5)));
    // E
    $adjIds = array_column($b['adjustments'], 'adjustment_id');
    $dbAdj = $adjIds ? (float) $pdo->query('SELECT COALESCE(SUM(qty_base_delta * unit_cost_base),0) FROM stock_adjustments WHERE adjustment_type = \'OPNAME\' AND id IN (' . implode(',', array_map('intval', $adjIds)) . ')')->fetchColumn() : 0.0;
    $check('E. adjustment total == independent SQL over the linked stock_adjustments', $near(round(array_sum(array_column($b['adjustments'], 'value')), 2), round($dbAdj, 2)) && $near((float) ($s['adj_value'] ?? 0), round($dbAdj, 2)), count($adjIds) . " adjustments, Rp {$dbAdj}");
    if ($s['status'] === 'POSTED') { $check('E2. a POSTED session shows its adjustment status explicitly (never blank)', $s['adj_status'] !== ''); }
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
exit($fail ? 1 : 0);
