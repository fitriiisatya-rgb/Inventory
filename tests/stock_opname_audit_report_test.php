<?php
declare(strict_types=1);

/**
 * "Laporan Stock Opname" (audit redesign) — StockOpnameAuditReportService + GET /reports/opname-audit/*.
 *
 * Builds one LEGACY_DUAL_COUNT (CIBADAK-like) and one FINDINGS_V1 (SCM-like) POSTED session through the application's own services
 * (tests/lib/jejak_real_fixture.php — real findings, a voided finding, real evidence photos, real posted adjustments) and proves, against
 * HAND-COMPUTED expectations: session summary (P1/P2/supervisor/finalizer, timestamps, match/mismatch buckets, variance, adjustment), the
 * wide item rows (Good / Expired / Rusak / Deadstock split, system vs final, HPP and values, petugas, timestamps, evidence, notes, adjustment
 * linkage), the item drawer (count history incl. the voided finding, evidence, reconciliation, adjustment, audit), filters / search, KPI,
 * every export (== the screen), warehouse scope over HTTP, evidence file serving, and that NOTHING is written.
 *
 * Usage: php tests/stock_opname_audit_report_test.php
 */

require_once __DIR__ . '/lib/jejak_real_fixture.php';
require_once __DIR__ . '/../services/StockOpnameAuditReportService.php';
require_once __DIR__ . '/../services/ReportExportService.php';

use App\Services\Database;
use App\Services\StockOpnameAuditReportService as R;
use App\Services\StockOpnameJejakService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.005): bool
{
    return ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $eps;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
$fx = jejak_build_fixture($pdo);
$L = $fx['legacy'];
$V = $fx['findings'];

function snapshotTables(PDO $pdo): array
{
    $out = [];
    foreach (['stock_opname_sessions', 'stock_opname_lines', 'stock_opname_findings', 'stock_opname_finding_quantities', 'stock_opname_finding_photos', 'stock_opname_team_members',
              'stock_opname_reference_rows', 'stock_opname_reference_movements', 'stock_adjustments', 'inventory_transactions', 'inventory_transaction_lines',
              'inventory_batches', 'fifo_allocations', 'audit_logs'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $out;
}
$before = snapshotTables($pdo);

$byKey = static function (array $built, array $items): array {
    $m = [];
    foreach ($items as $k => $it) {
        foreach ($built['items'] as $r) {
            if ($r['item_id'] === $it['id']) {
                $m[$k] = $r;
            }
        }
    }
    return $m;
};
$bl = R::build($pdo, $L['session_id']);
$bf = R::build($pdo, $V['session_id']);
$li = $byKey($bl, $L['items']);
$fi = $byKey($bf, $V['items']);
$sl = $bl['session_row'];
$sf = $bf['session_row'];
$jl = StockOpnameJejakService::detail($pdo, $L['session_id']);
$jf = StockOpnameJejakService::detail($pdo, $V['session_id']);
$u = $L['users'];
$w = $V['users'];

// ======================================================================= LEGACY
echo "== LEGACY_DUAL_COUNT (CIBADAK-like) ==\n";
check('[L] session identity: number, date, warehouse, POSTED, model', str_starts_with((string) $sl['session_number'], 'SO-') && $sl['session_date'] === '2026-09-30' && str_contains((string) $sl['warehouse'], 'CIBADAK') && $sl['status'] === 'POSTED' && $sl['model'] === 'Legacy', json_encode([$sl['session_number'], $sl['status'], $sl['model']]));
check('[L] actors: created_by / finalizer / posted_by are real usernames; posted_at real', $sl['created_by'] === $fx['admin']['username'] && $sl['finalizer'] === $fx['admin']['username'] && $sl['posted_by'] === $fx['admin']['username'] && $sl['posted_at'] !== null && $sl['finalized_at'] !== null);
check('[L] P1 team = A + B, P2 team = C + D (assigned members)', $sl['p1'] === [$u['A']['username'], $u['B']['username']] && $sl['p2'] === [$u['C']['username'], $u['D']['username']], json_encode([$sl['p1'], $sl['p2']]));
check('[L] 6 items; buckets MATCH 4 (L1,L2,L3,L6) + RECOUNTED 1 (L4) + EXCLUDED 1 (L5), MISMATCH 0, PENDING 0', $sl['total_items'] === 6 && $sl['match'] === 4 && $sl['recounted'] === 1 && $sl['excluded'] === 1 && $sl['mismatch'] === 0 && $sl['pending'] === 0, json_encode([$sl['match'], $sl['recounted'], $sl['excluded'], $sl['mismatch'], $sl['pending']]));
check('[L] reconciliation B: Match + Mismatch + Recount + Pending + Excluded == total items', $sl['match'] + $sl['mismatch'] + $sl['recounted'] + $sl['pending'] + $sl['excluded'] === $sl['total_items']);
check('[L] session Selisih Nilai = hand-computed 2.500 and equals the Jejak KPI', near($sl['variance_value'], (float) $L['expect']['selisih_nominal']) && near($sl['variance_value'], $jl['kpi']['selisih_nominal']['value']), (string) $sl['variance_value']);
check('[L] Selisih Qty is shown PER UNIT, never one mixed total: "KG -1"', $sl['variance_by_unit'] === 'KG -1', (string) $sl['variance_by_unit']);
check('[L] adjustment: Terposting, 3 real adjustments, value = hand-computed 3.500 (differs from raw variance value 2.500 — not faked equal)', $sl['adj_status'] === 'Terposting' && $sl['adj_count'] === 3 && near($sl['adj_value'], (float) $L['expect']['adjustment_bersih']) && !near($sl['adj_value'], $sl['variance_value']), json_encode([$sl['adj_status'], $sl['adj_count'], $sl['adj_value']]));

foreach ($L['expect']['hpp'] as $k => $hpp) {
    $r = $li[$k];
    check("[L/$k] HPP {$hpp}, system / final / variance == hand-computed (total physical final)", near($r['hpp'], $hpp) && near($r['system_qty'], $L['expect']['system'][$k]) && near($r['final_qty'], $L['expect']['final'][$k]) && near($r['variance_qty'], $L['expect']['variance'][$k]), json_encode([$r['system_qty'], $r['final_qty'], $r['variance_qty']]));
    if ($L['expect']['final'][$k] !== null) {
        check("[L/$k] Nilai Fisik = final x HPP; Nilai Sistem = system x HPP; Selisih Nilai = variance x HPP", near($r['final_value'], round($L['expect']['final'][$k] * $hpp, 2)) && near($r['system_value'], round($L['expect']['system'][$k] * $hpp, 2)) && near($r['variance_value'], round($L['expect']['variance'][$k] * $hpp, 2)));
    }
}
$r = $li['L1'];
check('[L/L1] condition split: Rusak 5, Expired 0, Deadstock 0, Good = 95 − 5 = 90 (Fisik = Good + E + R + D)', near($r['rusak_qty'], 5.0) && near($r['expired_qty'], 0.0) && near($r['deadstock_qty'], 0.0) && near($r['good_qty'], 90.0) && near($r['final_qty'], $r['good_qty'] + $r['rusak_qty'] + $r['expired_qty'] + $r['deadstock_qty']));
check('[L/L3] Deadstock 30, Good 0', near($li['L3']['deadstock_qty'], 30.0) && near($li['L3']['good_qty'], 0.0));
check('[L/L1] petugas: hitung = A (P1 line actor), verifikasi = C; Catatan Petugas = "P1: pecah saat dus jatuh"', $r['p_hitung'] === [$u['A']['username']] && $r['p_verifikasi'] === [$u['C']['username']] && $r['note_petugas'] === 'P1: pecah saat dus jatuh', json_encode([$r['p_hitung'], $r['p_verifikasi'], $r['note_petugas']]));
check('[L/L6] different people on different lines: hitung B, verifikasi D', $li['L6']['p_hitung'] === [$u['B']['username']] && $li['L6']['p_verifikasi'] === [$u['D']['username']]);
$raw = static fn (int $lineId) => $GLOBALS['pdo']->query("SELECT p1_submitted_at, p2_submitted_at, recount_submitted_at FROM stock_opname_lines WHERE id = {$lineId}")->fetch();
$rw = $raw($r['line_id']);
check('[L/L1] timestamps = the line\'s real p1_submitted_at / p2_submitted_at; final input = the later of the two', $r['ts_hitung'] === $rw['p1_submitted_at'] && $r['ts_verifikasi'] === $rw['p2_submitted_at'] && $r['ts_final'] === max($rw['p1_submitted_at'], $rw['p2_submitted_at']) && $r['ts_hitung'] !== null);
$r4 = $li['L4'];
$rw4 = $raw($r4['line_id']);
check('[L/L4] RECOUNTED: final 19, Count 01 by B, Count 02 by C, final-input time = recount_submitted_at, status "Recount"', near($r4['final_qty'], 19.0) && $r4['p_hitung'] === [$u['B']['username']] && $r4['p_verifikasi'] === [$u['C']['username']] && $r4['ts_final'] === $rw4['recount_submitted_at'] && $r4['line_status'] === 'Recount' && $rw4['recount_submitted_at'] !== null);
$r5 = $li['L5'];
check('[L/L5] excluded: no final / variance / actors / timestamps (unknown stays unknown), status "Dikecualikan"', $r5['final_qty'] === null && $r5['variance_qty'] === null && $r5['p_hitung'] === [] && $r5['ts_hitung'] === null && $r5['line_status'] === 'Dikecualikan' && $r5['good_qty'] === null);
check('[L] legacy has NO evidence storage: every line "evidence" = []', array_sum(array_map(static fn ($x) => count($x['evidence']), $bl['items'])) === 0);
$adjL1 = $r['adj_value'];
check('[L/L1] adjustment linkage: real reference, qty −5, value −5.000 (FIFO oldest batch @1000, NOT variance x session HPP −6.000), by + timestamp', $r['adj_ref'] !== null && near($r['adj_qty'], -5.0) && near($adjL1, -5000.0) && !near($adjL1, $r['variance_value']) && $r['adj_by'] === $fx['admin']['username'] && $r['adj_at'] !== null, json_encode([$r['adj_ref'], $r['adj_qty'], $adjL1]));
check('[L] line without adjustment (L3 variance 0, L6) → adj_* are null, not 0', $li['L3']['adj_ref'] === null && $li['L3']['adj_value'] === null);
// conditions never recorded on a legacy line stay unknown (null), not zero
$none = null;
foreach ($li as $k => $x) {
    if (!$x['conditions_recorded'] && !$x['is_excluded']) {
        $none = $x;
    }
}
check('[L] a legacy line where NO condition was ever recorded shows Expired/Rusak/Deadstock/Good as null (— in the UI), never 0', $none === null || ($none['rusak_qty'] === null && $none['expired_qty'] === null && $none['deadstock_qty'] === null && $none['good_qty'] === null), $none ? $none['sku'] : 'all lines recorded conditions');

// ================================================================== FINDINGS_V1
echo "\n== FINDINGS_V1 (SCM-like) ==\n";
check('[V] identity: POSTED, model FINDINGS_V1, 9 items', $sf['status'] === 'POSTED' && $sf['model'] === 'FINDINGS_V1' && $sf['total_items'] === 9 && str_contains((string) $sf['warehouse'], 'SCM'));
check('[V] P1 actors = f1a + f1b ONLY — f1c (assigned, but its only finding was VOIDED) is never shown as an actor; P2 = f2a', $sf['p1'] === [$w['f1a']['username'], $w['f1b']['username']] || $sf['p1'] === [$w['f1b']['username'], $w['f1a']['username']], json_encode($sf['p1']));
check('[V] P2 actor list = [f2a]', $sf['p2'] === [$w['f2a']['username']], json_encode($sf['p2']));
check('[V] no voided author anywhere in the items\' petugas', !in_array($w['f1c']['username'], array_merge(...array_map(static fn ($x) => array_merge($x['p_hitung'], $x['p_verifikasi']), $bf['items'])), true));
check('[V] buckets sum to total items', $sf['match'] + $sf['mismatch'] + $sf['recounted'] + $sf['pending'] + $sf['excluded'] === 9, json_encode([$sf['match'], $sf['mismatch'], $sf['recounted'], $sf['pending'], $sf['excluded']]));
check('[V] session Selisih Nilai == hand-computed and == Jejak KPI', near($sf['variance_value'], (float) $V['expect']['selisih_nominal']) && near($sf['variance_value'], $jf['kpi']['selisih_nominal']['value']), (string) $sf['variance_value']);
check('[V] FINDINGS_V1 session posted without adjustments (Checkpoint B not implemented) → "Posted — tanpa adjustment", value null', $sf['adj_status'] === 'Posted — tanpa adjustment' && $sf['adj_count'] === 0 && $sf['adj_value'] === null);
foreach ($V['expect']['hpp'] as $k => $hpp) {
    $x = $fi[$k];
    check("[V/$k] HPP {$hpp}; system / GOOD final / variance == hand-computed", near($x['hpp'], $hpp) && near($x['system_qty'], $V['expect']['system'][$k]) && near($x['good_qty'], $V['expect']['final'][$k]) && near($x['variance_qty'], $V['expect']['variance'][$k]), json_encode([$x['system_qty'], $x['good_qty'], $x['variance_qty']]));
}
$m = $fi['mix'];
check('[V/mix] Good 90 + Expired 3 + Rusak 5 + Deadstock 2 → Qty Fisik Total 100; Nilai Fisik = 100 x 700 = 70.000; Selisih Nilai −7.000', near($m['good_qty'], 90.0) && near($m['expired_qty'], 3.0) && near($m['rusak_qty'], 5.0) && near($m['deadstock_qty'], 2.0) && near($m['final_qty'], 100.0) && near($m['final_value'], 70000.0) && near($m['variance_value'], -7000.0));
check('[V/nobase] no EOD baseline: system null, variance null, good 12, total 12 (unknown stays unknown)', $fi['nobase']['system_qty'] === null && $fi['nobase']['variance_qty'] === null && near($fi['nobase']['good_qty'], 12.0) && near($fi['nobase']['final_qty'], 12.0));
check('[V/excl] excluded line: status "Dikecualikan", no final', $fi['excl']['line_status'] === 'Dikecualikan' && $fi['excl']['good_qty'] === null);
check('[V/pos] only f1b is the petugas hitung (voided f1c excluded); no P2 → verifikasi [] and null timestamp', $fi['pos']['p_hitung'] === [$w['f1b']['username']] && $fi['pos']['p_verifikasi'] === [] && $fi['pos']['ts_verifikasi'] === null);
check('[V/pos] timestamps come from the NON-voided finding\'s counted_at = 2026-09-29 12:00:00', $fi['pos']['ts_hitung'] === '2026-09-29 12:00:00' && $fi['pos']['ts_final'] === '2026-09-29 12:00:00');
check('[V/rusak] hitung f1a, verifikasi f2a; both timestamps 2026-09-29 12:00:00', $fi['rusak']['p_hitung'] === [$w['f1a']['username']] && $fi['rusak']['p_verifikasi'] === [$w['f2a']['username']] && $fi['rusak']['ts_verifikasi'] === '2026-09-29 12:00:00');
check('[V/move] later count time 2026-09-30 10:00:00 (per-line, not session-level)', $fi['move']['ts_hitung'] === '2026-09-30 10:00:00');
check('[V] session P1 start/end = MIN/MAX of its lines (2026-09-29 12:00:00 → 2026-09-30 10:00:00); P2 the same', $sf['p1_start'] === '2026-09-29 12:00:00' && $sf['p1_end'] === '2026-09-30 10:00:00' && $sf['p2_start'] === '2026-09-29 12:00:00' && $sf['p2_end'] === '2026-09-30 10:00:00', json_encode([$sf['p1_start'], $sf['p1_end'], $sf['p2_start'], $sf['p2_end']]));
check('[V/rusak] notes: Catatan Petugas = "P1: kemasan sobek"', $fi['rusak']['note_petugas'] === 'P1: kemasan sobek', (string) $fi['rusak']['note_petugas']);

// ---- evidence
$ev = static fn (string $k) => $fi[$k]['evidence'];
check('[V/rusak] 2 evidence photos (P1 + P2, DAMAGED); dead 2; mix 6; pos 0 (no condition → no photo)', count($ev('rusak')) === 2 && count($ev('dead')) === 2 && count($ev('mix')) === 6 && count($ev('pos')) === 0, json_encode([count($ev('rusak')), count($ev('dead')), count($ev('mix')), count($ev('pos'))]));
$e0 = $ev('rusak')[0];
check('[V] evidence refs: url, condition, team, uploader, upload timestamp, file exists on disk', str_starts_with($e0['url'], '/api/reports/opname-audit/photo/') && $e0['condition'] === 'DAMAGED' && in_array($e0['team'], ['P1', 'P2'], true) && $e0['uploaded_by'] !== '' && $e0['uploaded_at'] !== null && $e0['file_ok'] === true && $e0['voided'] === false);
check('[V] reconciliation G: EVERY evidence reference exists as a real photo row + a real file', (function () use ($bf, $pdo) {
    foreach ($bf['items'] as $it) {
        foreach ($it['evidence'] as $e) {
            $st = $pdo->prepare('SELECT storage_path, finding_id FROM stock_opname_finding_photos WHERE id = :id');
            $st->execute(['id' => $e['id']]);
            $row = $st->fetch();
            if (!$row || $row['finding_id'] === null || !is_file(\App\Services\StockOpnamePhotoService::absolutePath($row['storage_path']))) {
                return false;
            }
        }
    }
    return true;
})());

// ---- count history (drawer)
$pos = $fi['pos'];
$d = R::itemDetail($pdo, $V['session_id'], $pos['line_id']);
$hist = $d['count_history'];
$voided = array_values(array_filter($hist, static fn ($h) => $h['voided'] !== null));
check('[V/pos drawer] count history lists BOTH findings — f1c\'s is flagged VOID with reason + who/when, f1b\'s is live', count($hist) === 2 && count($voided) === 1 && $voided[0]['actor'] === $w['f1c']['username'] && $voided[0]['voided']['reason'] === 'salah input' && $voided[0]['voided']['by'] === $fx['admin']['username']);
check('[V/pos drawer] reconciliation block: model, formula, final decision by/at, no recount; audit includes the finding VOID + CREATE events', $d['reconciliation']['model'] === 'FINDINGS_V1' && str_contains($d['reconciliation']['formula'], 'EOD') && $d['reconciliation']['final_decision_by'] === $fx['admin']['username']
    && count(array_filter($d['audit'], static fn ($a) => $a['action'] === 'STOCK_OPNAME_FINDING_VOID')) === 1 && count(array_filter($d['audit'], static fn ($a) => $a['action'] === 'STOCK_OPNAME_FINDING_CREATE')) >= 2, json_encode(array_count_values(array_column($d['audit'], 'action'))));
$dm = R::itemDetail($pdo, $V['session_id'], $fi['mix']['line_id']);
check('[V/mix drawer] 2 live findings (P1, P2) with per-condition input quantities + unit; 6 photos; book-stock reconciliation row present', count($dm['count_history']) === 2 && count($dm['evidence']) === 6 && $dm['count_history'][0]['quantities'] !== [] && $dm['reconciliation']['book_stock'] !== null && $dm['summary']['good_qty'] === 90.0);
$dl = R::itemDetail($pdo, $L['session_id'], $li['L4']['line_id']);
check('[L/L4 drawer] history: P1 18 by B, P2 22 by C, RECOUNT 19 by supervisor with reason; recount block; audit has the real P1/P2/RECOUNT events', count($dl['count_history']) === 3 && $dl['count_history'][2]['role'] === 'RECOUNT' && near($dl['count_history'][2]['total_qty'], 19.0) && $dl['count_history'][2]['notes'] === 'supervisor recount' && $dl['reconciliation']['recount']['qty'] === 19.0
    && count(array_filter($dl['audit'], static fn ($a) => $a['action'] === 'STOCK_OPNAME_RECOUNT')) === 1 && count(array_filter($dl['audit'], static fn ($a) => in_array($a['action'], ['STOCK_OPNAME_P1_COUNT', 'STOCK_OPNAME_P2_COUNT'], true))) === 2, json_encode(array_count_values(array_column($dl['audit'], 'action'))));
$d1 = R::itemDetail($pdo, $L['session_id'], $li['L1']['line_id']);
check('[L/L1 drawer] adjustment block: real reference, qty, FIFO value, by, at', count($d1['adjustment']) === 1 && near($d1['adjustment'][0]['value'], -5000.0) && $d1['adjustment'][0]['created_by'] === $fx['admin']['username']);
try { R::itemDetail($pdo, $L['session_id'], $fi['pos']['line_id']); check('[drawer] a line of ANOTHER session is refused (404)', false); } catch (\App\Services\NotFoundException $e) { check('[drawer] a line of ANOTHER session is refused (404)', true); }

// ================================================================ cross-checks
echo "\n== reconciliation: report == Jejak == hand-computed ==\n";
foreach (['L' => [$bl, $jl], 'V' => [$bf, $jf]] as $label => [$b, $j]) {
    $okRows = true;
    foreach ($b['items'] as $it) {
        foreach ($j['items'] as $ji) {
            if ($ji['line_id'] === $it['line_id'] && !(near($it['system_value'], $ji['system_value']) && near($it['variance_value'], $ji['variance_value']) && near($it['variance_qty'], $ji['variance_qty']) && near($it['hpp'], $ji['hpp']) && near($it['system_qty'], $ji['system_qty']))) {
                $okRows = false;
            }
        }
    }
    check("[$label] A: item rows == Jejak items (system / variance / HPP / values) — no second formula", $okRows && count($b['items']) === count($j['items']) && $b['session_row']['total_items'] === $j['summary']['total_items']);
    $sumVar = round(array_sum(array_map(static fn ($x) => ($x['variance_qty'] !== null && abs($x['variance_qty']) > 1e-7) ? $x['variance_value'] : 0.0, $b['items'])), 2);
    check("[$label] C: Σ item Selisih Nilai == session Selisih Nilai", near($sumVar, $b['session_row']['variance_value']), "{$sumVar}");
    $adjSum = round(array_sum(array_column($b['adjustments'], 'value')), 2);
    check("[$label] E: Σ adjustment (rows) == Jejak adjustment KPI == session adj value", near($adjSum, $j['kpi']['adjustment_bersih']['value']) && near($adjSum, (float) ($b['session_row']['adj_value'] ?? 0)));
    $dbAdj = (float) $pdo->query("SELECT COALESCE(SUM(sa.qty_base_delta * sa.unit_cost_base),0) FROM stock_adjustments sa WHERE sa.adjustment_type='OPNAME' AND sa.id IN (" . (implode(',', array_column($b['adjustments'], 'adjustment_id')) ?: '0') . ')')->fetchColumn();
    check("[$label] E: adjustment total == independent SQL over stock_adjustments", near($adjSum, round($dbAdj, 2)));
}
$actorsOk = true;
foreach ([$bl, $bf] as $b) {
    foreach ($b['items'] as $it) {
        foreach (array_merge($it['p_hitung'], $it['p_verifikasi']) as $name) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
            $c->execute(['u' => $name]);
            $actorsOk = $actorsOk && (int) $c->fetchColumn() === 1;
        }
    }
}
check('F: every petugas shown corresponds to a real user row', $actorsOk);

// ================================================================== list / filters / KPI
echo "\n== sessions list / filters / KPI ==\n";
$all = R::sessions($pdo, ['page' => 1, 'per_page' => 25]);
$mine = array_values(array_filter($all['rows'], static fn ($r) => in_array($r['id'], [$L['session_id'], $V['session_id']], true)));
check('sessions list returns both fixture sessions, newest first, with the column catalogue', count($mine) === 2 && count($all['columns']) === count(R::SESSION_COLUMNS) && $all['pagination']['total'] >= 2);
$f2 = ['date_from' => '2026-09-30', 'date_to' => '2026-09-30'];
check('date filter keeps exactly the two sessions of 2026-09-30; outside the range → none', count(R::sessions($pdo, $f2)['rows']) === 2 && R::sessions($pdo, ['date_from' => '2020-01-01', 'date_to' => '2020-01-02'])['rows'] === []);
check('warehouse filter: CIBADAK → only the legacy session; SCM → only the V1 session', array_column(R::sessions($pdo, $f2 + ['warehouse_id' => $L['warehouse_id']])['rows'], 'id') === [$L['session_id']] && array_column(R::sessions($pdo, $f2 + ['warehouse_id' => $V['warehouse_id']])['rows'], 'id') === [$V['session_id']]);
check('status filter: POSTED → 2; OPEN → 0; CANCELLED → 0', count(R::sessions($pdo, $f2 + ['status' => 'POSTED'])['rows']) === 2 && R::sessions($pdo, $f2 + ['status' => 'OPEN'])['rows'] === [] && R::sessions($pdo, $f2 + ['status' => 'CANCELLED'])['rows'] === []);
$ids = static fn (string $q) => array_column(R::sessions($pdo, $f2 + ['q' => $q])['rows'], 'id');
check('search: session number', $ids((string) $sl['session_number']) === [$L['session_id']]);
check('search: SKU (JFLL… of the legacy session)', $ids($li['L2']['sku']) === [$L['session_id']] && $ids($fi['dead']['sku']) === [$V['session_id']]);
check('search: item name', $ids($li['L3']['name']) === [$L['session_id']]);
check('search: petugas name — a V1 counter finds the V1 session; a legacy P2 member finds the legacy one', $ids($w['f1b']['username']) === [$V['session_id']] && $ids($u['D']['username']) === [$L['session_id']]);
check('search: line note ("dus jatuh" → legacy; "sobek" → V1)', $ids('dus jatuh') === [$L['session_id']] && $ids('sobek') === [$V['session_id']]);
check('search: f1c is an ASSIGNED P1 member (finds the session) but his only finding is VOIDED, so he is nobody\'s line actor: item search by his name → 0 lines', $ids($w['f1c']['username']) === [$V['session_id']] && R::items($pdo, [$V['session_id']], ['q' => $w['f1c']['username'], 'per_page' => 50])['pagination']['total'] === 0);
check('search: nothing → empty', $ids('zzzz-no-such') === []);
$k = R::sessions($pdo, $f2)['kpi'];
$expMatch = $sl['match'] + $sf['match'];
check('KPI: 2 sessions, 15 items; match/mismatch/recount/pending/excluded are the sums of the rows', $k['sessions'] === 2 && $k['items'] === 15 && $k['match'] === $expMatch && $k['mismatch'] === 0 && $k['recounted'] === 1 && $k['excluded'] === $sl['excluded'] + $sf['excluded'] && $k['verified'] === $k['match'] + $k['mismatch'] + $k['recounted'], json_encode([$k['items'], $k['match'], $k['verified']]));
check('KPI: match % = MATCH / verified', near($k['match_pct'], round($k['match'] * 100 / $k['verified'], 1), 0.05), (string) $k['match_pct']);
check('KPI: Selisih Nilai = Σ of both sessions\' hand-computed values (2.500 + V1)', near($k['variance_value'], (float) ($L['expect']['selisih_nominal'] + $V['expect']['selisih_nominal'])) && near($k['adjustment_value'], 3500.0), json_encode([$k['variance_value'], $k['adjustment_value']]));
$rusakSku = 1 + 3; // legacy L1 + V1 rusak / mix / move
check('KPI conditions (by unit, never mixed): Rusak SKU 4 = KG 5 + 2 + 5 + 10 = 22; Deadstock SKU 3 (L3 30, dead 5, mix 2) = KG 37; Expired SKU 1 = KG 3', $k['conditions']['rusak']['sku'] === $rusakSku && near($k['conditions']['rusak']['by_unit']['KG'], 22.0) && $k['conditions']['deadstock']['sku'] === 3 && near($k['conditions']['deadstock']['by_unit']['KG'], 37.0) && $k['conditions']['expired']['sku'] === 1 && near($k['conditions']['expired']['by_unit']['KG'], 3.0), json_encode($k['conditions']));
$pg = R::sessions($pdo, ['per_page' => 10, 'page' => 1]);
check('pagination: per_page honoured, total counts all matches', $pg['pagination']['per_page'] === 10 && count($pg['rows']) <= 10);

// ================================================================== items list
echo "\n== items list ==\n";
$both = [$L['session_id'], $V['session_id']];
$items = R::items($pdo, $both, ['per_page' => 50]);
check('items: 15 rows over both sessions, column catalogue = the 35 report columns, rows numbered 1..15', $items['pagination']['total'] === 15 && count($items['rows']) === 15 && count($items['columns']) === count(R::ITEM_COLUMNS) && $items['rows'][14]['no'] === 15);
check('items: every column key of the catalogue exists on every row', (function () use ($items) { foreach ($items['rows'] as $r) { foreach (R::ITEM_COLUMNS as $c) { if (!array_key_exists($c[0], $r)) { return false; } } } return true; })());
$one = R::items($pdo, [$L['session_id']], ['per_page' => 50]);
check('items of ONE selected session: 6 rows, all of that session', $one['pagination']['total'] === 6 && count(array_unique(array_column($one['rows'], 'session_id'))) === 1);
$cnt = static fn (array $f) => R::items($pdo, $both, $f + ['per_page' => 50])['pagination']['total'];
check('filter condition rusak → 4 (L1, rusak, mix, move); expired → 1 (mix); deadstock → 3 (L3, dead, mix); good>0 → 12', $cnt(['condition' => 'rusak']) === 4 && $cnt(['condition' => 'expired']) === 1 && $cnt(['condition' => 'deadstock']) === 3, json_encode([$cnt(['condition' => 'rusak']), $cnt(['condition' => 'expired']), $cnt(['condition' => 'deadstock']), $cnt(['condition' => 'good'])]));
check('filter condition variance (non-zero): legacy L1, L2, L4 + V1 pos, neg, rusak, dead, mix, move = 9', $cnt(['condition' => 'variance']) === 9, (string) $cnt(['condition' => 'variance']));
check('filter evidence: lines with photos = rusak, dead, mix, move = 4; no_evidence = 11', $cnt(['condition' => 'evidence']) === 4 && $cnt(['condition' => 'no_evidence']) === 11, json_encode([$cnt(['condition' => 'evidence']), $cnt(['condition' => 'no_evidence'])]));
check('filter match_status: RECOUNTED → 1; MISMATCH → 0; EXCLUDED → 2', $cnt(['match_status' => 'RECOUNTED']) === 1 && $cnt(['match_status' => 'MISMATCH']) === 0 && $cnt(['match_status' => 'EXCLUDED']) === 2);
check('item search: by SKU, by petugas name, by note text; a session-level hit (session number) keeps all of its lines', $cnt(['q' => $li['L2']['sku']]) === 1 && $cnt(['q' => $w['f2a']['username']]) === 4 && $cnt(['q' => 'sobek']) === 1 && $cnt(['q' => (string) $sl['session_number']]) === 6, json_encode([$cnt(['q' => $w['f2a']['username']]), $cnt(['q' => (string) $sl['session_number']])]));
$ft = $items['footer'];
$sumRows = static fn (string $key) => round(array_sum(array_map(static fn ($r) => (float) ($r[$key] ?? 0), $items['rows'])), 2);
check('footer: money totals == Σ rows; quantities grouped BY UNIT (only KG here)', array_keys($ft['qty_by_unit']) === ['KG'] && near($ft['money']['system_value'], $sumRows('system_value')) && near($ft['money']['variance_value'], $sumRows('variance_value')) && near($ft['money']['final_value'], $sumRows('final_value')) && near($ft['money']['adj_value'], $sumRows('adj_value')));
check('footer: qty by unit == Σ rows of that unit', near($ft['qty_by_unit']['KG']['rusak_qty'], round(array_sum(array_map(static fn ($r) => (float) $r['rusak_qty'], $items['rows'])), 6)));
$p2 = R::items($pdo, $both, ['per_page' => 25, 'page' => 2]);
check('pagination of items: page 2 of 25 per page is empty here, total stays 15; 50/100/200 accepted', $p2['rows'] === [] && $p2['pagination']['total'] === 15 && R::items($pdo, $both, ['per_page' => 7])['pagination']['per_page'] === 50);

// ================================================================== export == screen
echo "\n== export ==\n";
$lbl = static fn (array $cols) => array_map(static fn ($c) => $c[1], $cols);
$tSes = R::exportTable($pdo, 'sessions', $both, []);
$tIt = R::exportTable($pdo, 'items', $both, []);
check('export sessions: headers == the screen\'s session columns (all business columns), one row per session', $tSes['headers'] === $lbl(R::SESSION_COLUMNS) && count($tSes['rows']) === 2);
check('export items: headers == the screen\'s item columns (Good/Expired/Rusak/Deadstock, petugas, timestamps, evidence, notes, adjustment …), 15 rows', $tIt['headers'] === $lbl(R::ITEM_COLUMNS) && count($tIt['rows']) === 15 && in_array('Good / Stok Layak', $tIt['headers'], true) && in_array('Deadstock', $tIt['headers'], true) && in_array('Evidence Foto', $tIt['headers'], true));
$col = static fn (array $t, string $label) => array_search($label, $t['headers'], true);
$cm = $col($tIt, 'Nilai Sistem');
check('export items: Σ Nilai Sistem cells == the screen footer', near(round(array_sum(array_filter(array_column($tIt['rows'], $cm), 'is_numeric')), 2), $ft['money']['system_value']));
$rowL1 = array_values(array_filter($tIt['rows'], static fn ($r) => $r[$col($tIt, 'SKU')] === $li['L1']['sku']))[0];
check('export items: L1 row — petugas "jfA", rusak 5, Good 90, adjustment −5.000, unknown cells "—"', $rowL1[$col($tIt, 'Petugas Hitung (P1)')] === $u['A']['username'] && $rowL1[$col($tIt, 'Rusak')] === 5.0 && $rowL1[$col($tIt, 'Good / Stok Layak')] === 90.0 && near((float) $rowL1[$col($tIt, 'Adjustment Nilai')], -5000.0) && $rowL1[$col($tIt, 'Evidence Foto')] === '—');
$rowMix = array_values(array_filter($tIt['rows'], static fn ($r) => $r[$col($tIt, 'SKU')] === $fi['mix']['sku']))[0];
check('export items: V1 mix row carries all 6 evidence refs (url + condition/team/uploader/time) in ONE cell', substr_count((string) $rowMix[$col($tIt, 'Evidence Foto')], '/api/reports/opname-audit/photo/') === 6);
$tEv = R::exportTable($pdo, 'evidence', $both, []);
check('export evidence: one row per photo (12 = rusak 2 + dead 2 + mix 6 + move 2), with uploader + timestamp + URL + file-exists', count($tEv['rows']) === 12 && $tEv['headers'] === ['No. Sesi', 'Gudang', 'SKU', 'Nama Barang', 'Kondisi', 'Tim', 'Evidence URL / Referensi', 'Diunggah Oleh', 'Timestamp Upload', 'Caption', 'Temuan di-VOID', 'File Ada'] && $tEv['rows'][0][11] === 'Ya');
$tHist = R::exportTable($pdo, 'history', $both, []);
check('export history: includes the VOID finding row with void info; legacy P1/P2/RECOUNT rows', count(array_filter($tHist['rows'], static fn ($r) => str_contains((string) $r[16], 'salah input'))) === 1 && count(array_filter($tHist['rows'], static fn ($r) => $r[4] === 'RECOUNT')) === 1);
$tAdj = R::exportTable($pdo, 'adjustments', $both, []);
check('export adjustments: 3 real rows (legacy), Σ value 3.500', count($tAdj['rows']) === 3 && near(round(array_sum(array_column($tAdj['rows'], 8)), 2), 3500.0));
$tAud = R::exportTable($pdo, 'audit', $both, []);
check('export audit: real audit events of both sessions (start, counts, finalize/post …)', count($tAud['rows']) > 20 && count(array_filter($tAud['rows'], static fn ($r) => $r[3] === 'STOCK_OPNAME_POST')) === 1);
$wb = R::exportWorkbook($pdo, $both, [], ['Laporan' => 'Laporan Stock Opname']);
check('workbook: sheets Ringkasan Sesi / Rincian Item / Evidence / Riwayat Hitung / Adjustment / Audit Log + Info', array_keys($wb) === ['Ringkasan Sesi', 'Rincian Item', 'Evidence', 'Riwayat Hitung', 'Adjustment', 'Audit Log', 'Info'] && count($wb['Rincian Item']['rows']) === 15 && count($wb['Evidence']['rows']) === 12);
$xl = sys_get_temp_dir() . '/soa_test_' . bin2hex(random_bytes(3)) . '.xlsx';
\App\Services\ExcelWriterService::write($xl, $wb);
check('workbook writes a real .xlsx (zip with 7 worksheets)', (function () use ($xl) { $z = new ZipArchive(); $ok = $z->open($xl) === true && $z->locateName('xl/worksheets/sheet7.xml') !== false && $z->locateName('xl/worksheets/sheet8.xml') === false; $z->close(); @unlink($xl); return $ok; })());
// ---- v3 presentation support (additive fields + typed workbook); none of it touches the reconciliation numbers
$v3 = R::sessions($pdo, $f2);
$rowL = array_values(array_filter($v3['rows'], static fn ($r) => $r['id'] === $L['session_id']))[0];
$rowV = array_values(array_filter($v3['rows'], static fn ($r) => $r['id'] === $V['session_id']))[0];
check('v3 session rows: Adjustment +/− split (legacy: positive + negative == the net 3.500, from the real adjustments); V1 without adjustments → null (unknown, not 0)', $rowL['adj_pos'] !== null && $rowL['adj_neg'] !== null && near($rowL['adj_pos'] + $rowL['adj_neg'], $rowL['adj_value'], 0.001) && $rowL['adj_neg'] < 0 && $rowV['adj_pos'] === null && $rowV['adj_neg'] === null);
check('v3 session rows: evidence_count = 12 photos on the V1 session, null (legacy keeps no evidence) on the legacy session', $rowV['evidence_count'] === 12 && $rowL['evidence_count'] === null);
check('v3 sessions options: ALL matching sessions (not only the page) as {id, session_number, session_date, warehouse, status, total_items}', array_column($v3['options'], 'id') === $v3['session_ids'] && count($v3['options']) === 2 && array_keys($v3['options'][0]) === ['id', 'session_number', 'session_date', 'warehouse', 'status', 'total_items'] && count(R::sessions($pdo, ['per_page' => 10, 'page' => 2])['options']) === count(R::sessions($pdo, ['per_page' => 10, 'page' => 1])['options']));
$v3i = R::items($pdo, [$V['session_id']], ['per_page' => 25]);
check('v3 items payload carries the summary row of the covered session(s) (context header / print), identical to the sessions row', count($v3i['sessions']) === 1 && $v3i['sessions'][0] === $rowV);
check('v3 KPI: per-condition "unknown" = item rows whose condition was never recorded (null) — the screen shows "—" instead of 0', isset($v3['kpi']['conditions']['good']['unknown']) && $v3['kpi']['conditions']['good']['unknown'] === count(array_filter($items['rows'], static fn ($r) => $r['good_qty'] === null)));
check('v3 workbook sheets carry explicit cell types (numbers stay numbers: qty / money formats, real dates / datetimes)', count($wb['Rincian Item']['types']) === count(R::ITEM_COLUMNS) && in_array('money', $wb['Rincian Item']['types'], true) && in_array('qty', $wb['Rincian Item']['types'], true) && in_array('ts', $wb['Rincian Item']['types'], true) && in_array('date', $wb['Rincian Item']['types'], true) && $wb['Ringkasan Sesi']['types'][array_search('Total Item', $wb['Ringkasan Sesi']['headers'], true)] === 'int');
$xl3 = sys_get_temp_dir() . '/soa_test3_' . bin2hex(random_bytes(3)) . '.xlsx';
\App\Services\ReportExportService::write($xl3, $wb);
check('v3 workbook through ReportExportService: a valid zip with 7 worksheets', (function () use ($xl3) { $z = new ZipArchive(); $ok = $z->open($xl3) === true && $z->locateName('xl/worksheets/sheet7.xml') !== false && $z->locateName('xl/worksheets/sheet8.xml') === false; $z->close(); @unlink($xl3); return $ok; })());
check('export honours the line filters (condition=rusak → 4 rows)', count(R::exportTable($pdo, 'items', $both, ['condition' => 'rusak'])['rows']) === 4);
check('export cell formatting: unknown → "—"; formula-looking text is neutralised', R::cell('text', null) === '—' && R::cell('text', '=SUM(A1)') === "'=SUM(A1)" && R::cell('qty', 3.5) === 3.5);

// ================================================================== read-only
echo "\n== read-only ==\n";
check('[read-only] D: row counts + content checksums of every opname / stock / adjustment / audit table are identical after all of the above', snapshotTables($pdo) === $before);
check('[read-only] the service source contains no INSERT/UPDATE/DELETE and no finalize/post/recount/submit call',
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b|StockOpnameService::(finalize|post|recount|submit)|StockAdjustmentService::post/i',
        preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(__DIR__ . '/../services/StockOpnameAuditReportService.php'))));

// ================================================================== HTTP
echo "\n== HTTP ==\n";
$port = 8900 + random_int(2200, 2599);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null, bool $raw = false): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($csrf) { $headers[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => $raw]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return $raw ? ['status' => $status, 'headers' => substr((string) $out, 0, $hs), 'raw' => substr((string) $out, $hs), 'body' => []] : ['status' => $status, 'body' => json_decode((string) $out, true) ?: []];
}
function loginAs(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'soa_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($base, $fx['viewer']);
    $outsider = loginAs($base, $fx['outsider']);
    $admin = loginAs($base, $fx['admin']);
    check('logins succeed', $viewer['status'] === 200 && $outsider['status'] === 200 && $admin['status'] === 200);
    $q = 'date_from=2026-09-30&date_to=2026-09-30';

    $r = http('GET', "{$base}/reports/opname-audit/sessions?{$q}", null, $viewer['jar']);
    check('VIEWER (INVENTORY_VIEW only) → sessions 200 with rows/kpi/columns', $r['status'] === 200 && isset($r['body']['data']['rows'], $r['body']['data']['kpi'], $r['body']['data']['columns']) && count($r['body']['data']['rows']) === 2, (string) $r['status']);
    check('HTTP sessions payload == service result (same KPI)', ($r['body']['data']['kpi'] ?? null) === json_decode(json_encode(R::sessions($pdo, ['date_from' => '2026-09-30', 'date_to' => '2026-09-30'])['kpi']), true));
    check('unauthenticated → 401', http('GET', "{$base}/reports/opname-audit/sessions?{$q}")['status'] === 401);
    $ro = http('GET', "{$base}/reports/opname-audit/sessions?{$q}&warehouse_id={$V['warehouse_id']}", null, $outsider['jar']);
    check('D warehouse permission: a STOCK user scoped to ANOTHER warehouse asking for SCM still gets ONLY their own warehouse (0 sessions)', $ro['status'] === 200 && ($ro['body']['data']['rows'] ?? null) === [], json_encode($ro['body']['data']['rows'] ?? 'x'));
    $ri = http('GET', "{$base}/reports/opname-audit/items?session_ids={$V['session_id']}", null, $outsider['jar']);
    check('items for a session of another warehouse → 403', $ri['status'] === 403, (string) $ri['status']);
    check('item-detail for a session of another warehouse → 403', http('GET', "{$base}/reports/opname-audit/item-detail?session_id={$V['session_id']}&line_id={$fi['mix']['line_id']}", null, $outsider['jar'])['status'] === 403);
    check('export for a session of another warehouse → 403', http('GET', "{$base}/reports/opname-audit/export?kind=items&session_ids={$V['session_id']}", null, $outsider['jar'], null, true)['status'] === 403);
    $ri = http('GET', "{$base}/reports/opname-audit/items?session_ids={$V['session_id']},{$L['session_id']}&per_page=50", null, $viewer['jar']);
    check('VIEWER items for both sessions → 15 rows + footer', $ri['status'] === 200 && count($ri['body']['data']['rows']) === 15 && isset($ri['body']['data']['footer']));
    $rd = http('GET', "{$base}/reports/opname-audit/item-detail?session_id={$V['session_id']}&line_id={$fi['mix']['line_id']}", null, $viewer['jar']);
    check('item-detail → 200 with count_history / evidence / reconciliation / adjustment / audit', $rd['status'] === 200 && isset($rd['body']['data']['count_history'], $rd['body']['data']['evidence'], $rd['body']['data']['reconciliation'], $rd['body']['data']['audit']));
    check('item-detail with missing params → 422; unknown line → 404', http('GET', "{$base}/reports/opname-audit/item-detail", null, $viewer['jar'])['status'] === 422 && http('GET', "{$base}/reports/opname-audit/item-detail?session_id={$V['session_id']}&line_id=99999999", null, $viewer['jar'])['status'] === 404);
    check('items with an unknown session → 404', http('GET', "{$base}/reports/opname-audit/items?session_ids=99999999", null, $viewer['jar'])['status'] === 404);

    // evidence photo serving
    $photoId = $fi['rusak']['evidence'][0]['id'];
    $ph = http('GET', "{$base}/reports/opname-audit/photo/{$photoId}", null, $viewer['jar'], null, true);
    check('W evidence: VIEWER gets the real image (200, image/jpeg, JPEG bytes)', $ph['status'] === 200 && stripos($ph['headers'], 'image/jpeg') !== false && str_starts_with($ph['raw'], "\xFF\xD8"));
    check('evidence: another warehouse\'s user → 403 (no leak); unauthenticated → 401; unknown id → 404', http('GET', "{$base}/reports/opname-audit/photo/{$photoId}", null, $outsider['jar'], null, true)['status'] === 403 && http('GET', "{$base}/reports/opname-audit/photo/{$photoId}", null, null, null, true)['status'] === 401 && http('GET', "{$base}/reports/opname-audit/photo/99999999", null, $viewer['jar'], null, true)['status'] === 404);
    $pend = $pdo->query('SELECT id FROM stock_opname_finding_photos WHERE finding_id IS NULL LIMIT 1')->fetchColumn();
    check('evidence: a pending (never attached) photo is never served', $pend === false || http('GET', "{$base}/reports/opname-audit/photo/{$pend}", null, $admin['jar'], null, true)['status'] === 404);

    // exports over HTTP
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=items&{$q}", null, $viewer['jar'], null, true);
    $csv = ltrim($x['raw'], "\xEF\xBB\xBF");
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    check('CSV items over HTTP: 200 text/csv, header == column labels, 15 data rows', $x['status'] === 200 && stripos($x['headers'], 'text/csv') !== false && str_getcsv($lines[0], ',', '"', '\\') === $lbl(R::ITEM_COLUMNS) && count($lines) === 16, (string) count($lines));
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=sessions&{$q}", null, $viewer['jar'], null, true);
    check('CSV sessions over HTTP: header == session column labels, 2 rows', $x['status'] === 200 && str_getcsv(explode("\n", ltrim($x['raw'], "\xEF\xBB\xBF"))[0], ',', '"', '\\') === $lbl(R::SESSION_COLUMNS) && count(array_filter(explode("\n", trim($x['raw'])))) === 3);
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=workbook&{$q}", null, $viewer['jar'], null, true);
    check('workbook over HTTP: 200, xlsx content-type, valid zip with 7 sheets', $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml') !== false && (function () use ($x) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName('xl/worksheets/sheet7.xml') !== false; $z->close(); @unlink($f); return $ok; })());
    // v3 workbook delivery: file name = Laporan_Stock_Opname_<SO number | period>.xlsx; ?format=json = the print tables
    $vNo = (string) R::build($pdo, $V['session_id'])['session_row']['session_number'];
    $fn = static fn (array $x): string => preg_match('/filename="([^"]+)"/', $x['headers'], $m) ? $m[1] : '';
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=workbook&session_ids={$V['session_id']}&{$q}", null, $viewer['jar'], null, true);
    check('v3 workbook of ONE selected session is named Laporan_Stock_Opname_<SO number>.xlsx', $x['status'] === 200 && $fn($x) === "Laporan_Stock_Opname_{$vNo}.xlsx", $fn($x));
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=workbook&date_from=2026-09-01&date_to=2026-09-30", null, $viewer['jar'], null, true);
    check('v3 workbook without a selected session is named Laporan_Stock_Opname_<from>_<to>.xlsx', $fn($x) === 'Laporan_Stock_Opname_2026-09-01_2026-09-30.xlsx', $fn($x));
    $x = http('GET', "{$base}/reports/opname-audit/export?kind=workbook&{$q}", null, $viewer['jar'], null, true);
    check('v3 workbook for a single day is named Laporan_Stock_Opname_<date>.xlsx', $fn($x) === 'Laporan_Stock_Opname_2026-09-30.xlsx', $fn($x));
    $j = http('GET', "{$base}/reports/opname-audit/export?kind=workbook&format=json&session_ids={$V['session_id']}", null, $viewer['jar']);
    $jd = $j['body']['data'] ?? [];
    check('v3 workbook ?format=json → print tables: 7 sheets with types, 9 item rows for the V1 session, file name echoed, meta lists the selected session', $j['status'] === 200 && count($jd['sheets'] ?? []) === 7 && ($jd['file_name'] ?? '') === "Laporan_Stock_Opname_{$vNo}.xlsx" && count($jd['sheets'][1]['rows']) === 9 && in_array('money', $jd['sheets'][1]['types'], true) && str_contains(json_encode($jd['meta']), $vNo));
    check('v3 workbook of a session of another warehouse → 403 (scope enforced before any file is built)', http('GET', "{$base}/reports/opname-audit/export?kind=workbook&session_ids={$V['session_id']}", null, $outsider['jar'], null, true)['status'] === 403);
    check('export with an unknown kind → 422', http('GET', "{$base}/reports/opname-audit/export?kind=nope&{$q}", null, $viewer['jar'], null, true)['status'] === 422);
    check('existing routes unaffected: GET /reports/opname 200, GET /reports/opname/{id}/jejak 200', http('GET', "{$base}/reports/opname", null, $admin['jar'])['status'] === 200 && http('GET', "{$base}/reports/opname/{$V['session_id']}/jejak", null, $admin['jar'])['status'] === 200);

    $beforeHttp = snapshotTables($pdo);
    foreach (['sessions', 'items', 'item-detail', 'export'] as $route) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $wr = http($verb, "{$base}/reports/opname-audit/{$route}", [], $admin['jar'], $admin['csrf']);
            if ($wr['status'] !== 404) {
                check("{$verb} /reports/opname-audit/{$route} → 404", false, (string) $wr['status']);
            }
        }
    }
    check('POST/PUT/PATCH/DELETE on every report route → 404 (no write route exists)', true);
    check('HTTP reads + rejected write verbs changed nothing in the database', snapshotTables($pdo) === $beforeHttp);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ================================================================== SESSION-11-LIKE: real posted FINDINGS_V1 data the first fixture did not cover
// Production Session 11 (SCM, FINDINGS_V1, POSTED) has final Deadstock quantities on its lines for items that have NO non-VOID condition finding, and 395 linked OPNAME
// adjustments whose Σ(qty × cost) at raw precision differs from the Σ of per-row 2-dp roundings. Both are reproduced here on the fixture's own V1 session (TEST database only):
// the DEADSTOCK / EXPIRED / DAMAGED quantity rows of two lines are removed (the persisted final_* snapshot stays) and fractional-cost adjustments are linked to eight lines.
echo "\n== Session-11-like data (snapshot without findings; raw-precision adjustments) ==\n";
R::resetCache();
$sid = $V['session_id'];
$wh = (int) $pdo->query("SELECT warehouse_id FROM stock_opname_sessions WHERE id = {$sid}")->fetchColumn();
$byItemB = static fn (array $built): array => array_column($built['items'], null, 'item_id');
$b0 = $byItemB(R::build($pdo, $sid));
$deadId = $V['items']['dead']['id'];
$mixId = $V['items']['mix']['id'];
$snapDead = $b0[$deadId]['deadstock_qty'];
$snapMixDead = $b0[$mixId]['deadstock_qty'];
$finalCols = $pdo->query("SELECT final_deadstock_qty d, final_rusak_qty r, final_expired_qty e FROM stock_opname_lines WHERE id = {$b0[$mixId]['line_id']}")->fetch();
// 1) snapshot WITHOUT findings: drop every condition quantity row of those two lines (the real findings remain; only their condition rows are gone)
$pdo->exec("DELETE q FROM stock_opname_finding_quantities q JOIN stock_opname_findings f ON f.id = q.finding_id WHERE f.session_id = {$sid} AND f.stock_opname_line_id IN ({$b0[$deadId]['line_id']}, {$b0[$mixId]['line_id']}) AND q.condition_type IN ('DEADSTOCK','EXPIRED','DAMAGED')");
// 2) eight fractional-cost OPNAME adjustments linked to V1 lines (sum of per-row 2-dp roundings ≠ raw sum)
$lineIds = array_slice(array_column($b0, 'line_id'), 0, 8);
$k = 0;
foreach ($lineIds as $lid) {
    $itemId = (int) $pdo->query("SELECT item_id FROM stock_opname_lines WHERE id = {$lid}")->fetchColumn();
    $k++;
    $qty = -2.5;                      // qty × cost = -(2500.01225 + 0.1k): every row rounds by 0.00225 in the same direction, so Σ of the roundings drifts from the raw Σ by 0.018
    $cost = round(1000.0049 + 0.04 * $k, 4);
    $pdo->prepare("INSERT INTO stock_adjustments (item_id, warehouse_id, adjustment_type, qty_base_delta, before_qty_base, after_qty_base, unit_cost_base, reference_no, reason, created_by)
                   VALUES (:i, :w, 'OPNAME', :q, 50, :a, :c, :r, 'session-11-like fixture', :u)")->execute(['i' => $itemId, 'w' => $wh, 'q' => $qty, 'a' => 50 + $qty, 'c' => $cost, 'r' => 'ADJ-S11-' . $k, 'u' => $V['users']['f1a']['id']]);
    $pdo->exec('UPDATE stock_opname_lines SET adjustment_id = ' . (int) $pdo->lastInsertId() . " WHERE id = {$lid}");
}
R::resetCache();
$b1 = R::build($pdo, $sid);
$i1 = $byItemB($b1);
check('S11 the persisted final Deadstock snapshot is shown UNCHANGED when its findings are absent (dead item: ' . $snapDead . '; mix item: ' . $snapMixDead . ') — not erased, zeroed or inferred', near($i1[$deadId]['deadstock_qty'], $snapDead) && near($i1[$mixId]['deadstock_qty'], $snapMixDead) && $snapDead > 0 && $snapMixDead > 0);
check('S11 the condition source is stated ("Snapshot final (stock_opname_lines.final_*)") and the missing finding is FLAGGED, per condition, on the row', str_contains($i1[$deadId]['condition_source'], 'Snapshot final') && $i1[$deadId]['condition_audit']['rows']['deadstock']['status'] === 'tanpa_temuan' && str_contains((string) $i1[$deadId]['condition_flag'], 'Deadstock') && $i1[$mixId]['condition_audit']['rows']['rusak']['status'] === 'tanpa_temuan' && $i1[$mixId]['condition_audit']['rows']['expired']['status'] === 'tanpa_temuan');
check('S11 an item whose findings DO record its condition stays "cocok" (rusak item) and has no flag', $i1[$V['items']['rusak']['id']]['condition_audit']['rows']['rusak']['status'] === 'cocok' && $i1[$V['items']['rusak']['id']]['condition_flag'] === null);
check('S11 the session discloses how many items have a snapshot without findings (cond_flagged = 2) and the data-quality note says nothing was erased / inferred', $b1['session_row']['cond_flagged'] === 2 && count(array_filter($b1['session_row']['data_quality'], static fn ($n) => str_contains($n, 'tidak dihapus'))) === 1);
$det = R::itemDetail($pdo, $sid, $i1[$deadId]['line_id']);
check('S11 the item drawer carries a conditions block (snapshot vs per-team findings) while the finding HISTORY is still listed exactly as recorded (findings exist, none with a deadstock quantity)', isset($det['conditions']['rows']['deadstock']) && $det['conditions']['rows']['deadstock']['findings'] === [] && count($det['count_history']) >= 2 && count(array_filter($det['count_history'], static fn ($h) => $h['source'] === 'FINDING' && count(array_filter($h['quantities'], static fn ($q) => $q['condition'] === 'DEADSTOCK')) > 0)) === 0);
$cols = array_column(R::exportTable($pdo, 'items', [$sid], [])['headers'] ?? [], null);
check('S11 the items export carries "Sumber Kondisi" and "Catatan Kondisi" (export == screen)', in_array('Sumber Kondisi', $cols, true) && in_array('Catatan Kondisi', $cols, true));
// ---- E: raw precision
$sqlRaw = (string) $pdo->query("SELECT SUM(qty_base_delta * unit_cost_base) FROM stock_adjustments WHERE reason = 'session-11-like fixture'")->fetchColumn();
$rowsRaw = $pdo->query("SELECT qty_base_delta * unit_cost_base FROM stock_adjustments WHERE reason = 'session-11-like fixture'")->fetchAll(PDO::FETCH_COLUMN);
$roundedSum = array_sum(array_map(static fn ($v) => round((float) $v, 2), $rowsRaw));
check('S11 fixture sensitivity: Σ per-row 2-dp roundings (' . number_format($roundedSum, 2, '.', '') . ') differs from the raw Σ (' . $sqlRaw . ') by more than the tolerance — the old behaviour would FAIL', abs($roundedSum - (float) $sqlRaw) > 0.001);
$fixAdj = array_values(array_filter($b1['adjustments'], static fn ($a) => $a['reason'] === 'session-11-like fixture'));
check('S11 every adjustment value is raw qty × unit cost (max row diff < 1e-6), 8 linked', count($fixAdj) === 8 && max(array_map(static fn ($a, $r) => abs($a['value'] - (float) $r), $fixAdj, $rowsRaw)) < 1e-6);
check('S11 session adjustment value == independent SQL Σ at raw precision within Rp 0.001 (report ' . $b1['session_row']['adj_value'] . ' / SQL ' . $sqlRaw . ')', abs((float) $b1['session_row']['adj_value'] - (float) $sqlRaw) <= 0.001);
$itemsAll = R::items($pdo, [$sid], ['per_page' => 100]);
check('S11 the items footer adjustment total (raw) == the session adjustment value, and the KPI adjustment value keeps the raw precision', abs((float) $itemsAll['footer']['money']['adj_value'] - (float) $sqlRaw) <= 0.001 && abs((float) R::sessions($pdo, ['warehouse_id' => $wh])['kpi']['adjustment_value'] - (float) $sqlRaw) <= 0.001);
// ---- the reconciliation CLI on exactly this data (READ-ONLY): every A-G check passes for BOTH models
$out = [];
exec('DB_DATABASE=' . escapeshellarg((string) getenv('DB_DATABASE')) . ' DB_USERNAME=' . escapeshellarg((string) getenv('DB_USERNAME')) . ' DB_PASSWORD=' . escapeshellarg((string) getenv('DB_PASSWORD')) . ' php ' . escapeshellarg(__DIR__ . '/../scripts/opname_audit_reconcile_check.php') . ' --app-root=' . escapeshellarg(__DIR__ . '/..') . " --session={$sid}," . $L['session_id'] . ' 2>&1', $out, $rc);
$txt = implode("\n", $out);
check('S11 reconciliation CLI over the FINDINGS_V1 session (snapshot without findings + raw adjustments) and the legacy session: exit 0, every D / E check PASS, the snapshot-without-finding case is announced', $rc === 0 && !str_contains($txt, 'FAIL -') && str_contains($txt, 'D3. finding history') && str_contains($txt, 'NOTE: kondisi final berasal dari snapshot posting') && preg_match('/E2\. adjustment total/', $txt) === 1, substr($txt, -400));
check('S11 the CLI states the explicit decimal tolerance and warns that per-row rounding would differ', str_contains($txt, 'tolerance Rp 0.001') && str_contains($txt, 'would be Rp'));

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
