<?php
declare(strict_types=1);

/**
 * "Jejak Stock Opname" REAL-DATA read-only endpoint
 * (GET /reports/opname/{id}/jejak -> StockOpnameJejakService).
 *
 * Builds one LEGACY_DUAL_COUNT and one FINDINGS_V1 POSTED session through the
 * application's own services (tests/lib/jejak_real_fixture.php) and proves,
 * against HAND-COMPUTED expectations:
 *   A. identity, line count, per-line system/Count 01/Count 02/final/variance,
 *      per-line counter usernames (multi-counter, voided finding excluded),
 *      HPP, dead stock / rusak
 *   B. the six KPIs equal the hand-computed figures AND equal the sum of
 *      their own drill-down rows (reconciliation), shortage/excess split
 *   C. posted adjustments are the REAL stock_adjustments (FIFO cost), which
 *      differ from "variance x session HPP" on the two-batch item
 *   D. nothing is written: row counts + content checksums of every
 *      stock/opname/adjustment table are identical before and after
 *   E. HTTP: route reachable with INVENTORY_VIEW only, 401 unauthenticated,
 *      403 for an account scoped to another warehouse, 404 for a missing
 *      session, and no write verb reaches the route
 *
 * Usage: php tests/inventory_stock_opname_jejak_test.php
 */

require_once __DIR__ . '/lib/jejak_real_fixture.php';

use App\Services\Database;
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
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) <= $eps;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";
$fx = jejak_build_fixture($pdo);

/** Content checksum + row count of every table the Jejak read could conceivably touch. */
function snapshotTables(PDO $pdo): array
{
    $out = [];
    foreach (['stock_opname_sessions', 'stock_opname_lines', 'stock_opname_findings', 'stock_opname_finding_quantities', 'stock_opname_team_members',
              'stock_opname_reference_rows', 'stock_opname_reference_movements', 'stock_adjustments', 'inventory_transactions',
              'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $out[$t] = $count . ':' . $row[1];
    }
    return $out;
}

/** Re-derives each KPI from the drill-down ROWS only, exactly as the UI does. */
function drilldownTotals(array $d): array
{
    $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) $r[$k], $rows)), 2);
    $items = $d['items'];
    $selisih = array_filter($items, static fn ($i) => $i['variance_qty'] !== null && abs($i['variance_qty']) >= 0.0000001);
    $dead = array_filter($items, static fn ($i) => ($i['dead_qty'] ?? 0) > 0);
    $rusak = array_filter($items, static fn ($i) => ($i['rusak_qty'] ?? 0) > 0);
    $sys = array_filter($items, static fn ($i) => $i['system_value'] !== null);
    $fin = array_filter($items, static fn ($i) => $i['final_value'] !== null);
    return [
        'nilai_stok_sistem' => [$sum($sys, 'system_value'), count($sys)],
        'nilai_final_count' => [$sum($fin, 'final_value'), count($fin)],
        'selisih_nominal' => [$sum($selisih, 'variance_value'), count($selisih)],
        'dead_stock' => [$sum($dead, 'dead_value'), count($dead)],
        'rusak' => [$sum($rusak, 'rusak_value'), count($rusak)],
        'adjustment_bersih' => [$sum($d['adjustments'], 'value'), count($d['adjustments'])],
    ];
}

$before = snapshotTables($pdo);

foreach (['legacy' => 'LEGACY_DUAL_COUNT (CIBADAK-like)', 'findings' => 'FINDINGS_V1 (SCM-like)'] as $key => $label) {
    echo "\n== {$label} ==\n";
    $f = $fx[$key];
    $e = $f['expect'];
    $d = StockOpnameJejakService::detail($pdo, $f['session_id']);
    $byKey = [];
    foreach ($f['items'] as $k => $item) {
        foreach ($d['items'] as $row) {
            if ($row['item_id'] === $item['id']) {
                $byKey[$k] = $row;
            }
        }
    }

    // ---- A. identity + lines
    $s = $d['session'];
    check("[$key] session identity: id/number/status/model/date", $s['id'] === $f['session_id'] && str_starts_with($s['session_number'], 'SO-') && $s['status'] === 'POSTED'
        && $s['counting_model'] === ($key === 'legacy' ? 'LEGACY_DUAL_COUNT' : 'FINDINGS_V1') && $s['session_date'] === '2026-09-30', json_encode($s));
    check("[$key] real warehouse name/code", str_contains((string) $s['warehouse_name'], $key === 'legacy' ? 'CIBADAK' : 'SCM') && $s['warehouse_code'] !== null);
    check("[$key] created_by/posted_by are real usernames", $s['created_by'] === $fx['admin']['username'] && $s['posted_by'] === $fx['admin']['username'] && $s['posted_at'] !== null);
    check("[$key] real line count = " . count($f['items']), count($d['items']) === count($f['items']) && $d['summary']['total_items'] === count($f['items']));

    foreach ($e['hpp'] as $k => $hpp) {
        $row = $byKey[$k];
        check("[$key/$k] HPP = {$hpp}", near($row['hpp'], $hpp));
        check("[$key/$k] system qty", near($row['system_qty'], $e['system'][$k]), json_encode($row['system_qty']));
        check("[$key/$k] final qty", near($row['final_qty'], $e['final'][$k]), json_encode($row['final_qty']));
        check("[$key/$k] variance qty", near($row['variance_qty'], $e['variance'][$k]), json_encode($row['variance_qty']));
        if ($e['system'][$k] !== null) {
            check("[$key/$k] system value = qty x HPP", near($row['system_value'], round($e['system'][$k] * $hpp, 2)));
        } else {
            check("[$key/$k] unknown system qty stays null (not 0) and unvalued", $row['system_value'] === null && $row['system_qty'] === null);
        }
        if ($e['variance'][$k] !== null) {
            check("[$key/$k] variance value = variance x HPP", near($row['variance_value'], round($e['variance'][$k] * $hpp, 2)));
        }
    }

    // ---- Count 01 / Count 02 + per-line users
    if ($key === 'legacy') {
        $u = $f['users'];
        check('[legacy] L1 Count 01=95 by A, Count 02=95 by C (per-line, not session-level)', near($byKey['L1']['count1_qty'], 95.0) && $byKey['L1']['count1_users'] === [$u['A']['username']] && near($byKey['L1']['count2_qty'], 95.0) && $byKey['L1']['count2_users'] === [$u['C']['username']]);
        check('[legacy] L4 Count 01=18 by B / Count 02=22 by C, recount 19 → final 19', near($byKey['L4']['count1_qty'], 18.0) && $byKey['L4']['count1_users'] === [$u['B']['username']] && near($byKey['L4']['count2_qty'], 22.0) && $byKey['L4']['count2_users'] === [$u['C']['username']] && near($byKey['L4']['recount_qty'], 19.0) && near($byKey['L4']['final_qty'], 19.0));
        check('[legacy] L6 P1 by B, P2 by D (different people on different lines)', $byKey['L6']['count1_users'] === [$u['B']['username']] && $byKey['L6']['count2_users'] === [$u['D']['username']]);
        check('[legacy] excluded L5: no counts, no users, no variance — shown as null, never synthesized', $byKey['L5']['count1_qty'] === null && $byKey['L5']['count2_qty'] === null && $byKey['L5']['count1_users'] === [] && $byKey['L5']['is_excluded'] === true && $byKey['L5']['variance_qty'] === null);
        check('[legacy] session team lists both P1 and both P2 members', count($s['team']['P1']) === 2 && count($s['team']['P2']) === 2);
    } else {
        $u = $f['users'];
        check('[findings] pos Count 01=55 by f1b only (the voided finding by f1c is NOT counted or attributed)', near($byKey['pos']['count1_qty'], 55.0) && $byKey['pos']['count1_users'] === [$u['f1b']['username']], json_encode($byKey['pos']['count1_users']));
        check('[findings] neg Count 01=95 by f1a; no Count 02 (P2 not counted) → null/[]', near($byKey['neg']['count1_qty'], 95.0) && $byKey['neg']['count1_users'] === [$u['f1a']['username']] && $byKey['neg']['count2_qty'] === null && $byKey['neg']['count2_users'] === []);
        check('[findings] rusak P1 by f1a / P2 by f2a, both 38', near($byKey['rusak']['count1_qty'], 38.0) && near($byKey['rusak']['count2_qty'], 38.0) && $byKey['rusak']['count1_users'] === [$u['f1a']['username']] && $byKey['rusak']['count2_users'] === [$u['f2a']['username']]);
        check('[findings] counter usernames differ across lines (f1a vs f1b)', $byKey['neg']['count1_users'] !== $byKey['zero']['count1_users']);
        check('[findings] session team P1 has 3 members, P2 has 1', count($s['team']['P1']) === 3 && count($s['team']['P2']) === 1);
        check('[findings] item with NO EOD baseline: system null, variance null, final still shown (12)', $byKey['nobase']['system_qty'] === null && $byKey['nobase']['variance_qty'] === null && near($byKey['nobase']['final_qty'], 12.0));
        check('[findings] stock source is the EOD reconciliation, not the start snapshot', str_contains($s['stock_source_label'], 'EOD') && str_contains($d['sources']['system_qty'], 'EOD'));
        check('[findings] mix: GOOD 90 vs book 100 → variance -10 (conditions NOT folded into final)', near($byKey['mix']['final_qty'], 90.0) && near($byKey['mix']['variance_qty'], -10.0));
        check('[findings] move: post-count +5 IN is in BOTH book (105) and physical → GOOD 95, variance -10', near($byKey['move']['system_qty'], 105.0) && near($byKey['move']['final_qty'], 95.0) && near($byKey['move']['variance_qty'], -10.0));
    }

    // ---- dead stock / rusak
    $deadRows = array_filter($d['items'], static fn ($i) => ($i['dead_qty'] ?? 0) > 0);
    $rusakRows = array_filter($d['items'], static fn ($i) => ($i['rusak_qty'] ?? 0) > 0);
    check("[$key] dead stock rows = recorded quantities only", count($deadRows) === $d['kpi']['dead_stock']['count']);
    check("[$key] rusak rows = recorded quantities only", count($rusakRows) === $d['kpi']['rusak']['count']);

    // ---- B. KPIs vs hand-computed AND vs drill-down rows
    foreach (['nilai_stok_sistem', 'nilai_final_count', 'selisih_nominal', 'dead_stock', 'rusak', 'adjustment_bersih'] as $k) {
        check("[$key] KPI {$k} = hand-computed " . $e[$k], near($d['kpi'][$k]['value'], (float) $e[$k]), (string) $d['kpi'][$k]['value']);
    }
    $dd = drilldownTotals($d);
    foreach ($dd as $k => [$total, $count]) {
        check("[$key] KPI {$k} reconciles EXACTLY with its drill-down rows (total + count)", near($d['kpi'][$k]['value'], $total, 0.0001) && $d['kpi'][$k]['count'] === $count, "kpi={$d['kpi'][$k]['value']}/{$d['kpi'][$k]['count']} rows={$total}/{$count}");
    }
    check("[$key] selisih shortage + excess = net", near($d['kpi']['selisih_nominal']['shortage'] + $d['kpi']['selisih_nominal']['excess'], $d['kpi']['selisih_nominal']['value']));
    check("[$key] no synthetic 'Lainnya' row anywhere in the payload", !str_contains(json_encode($d), 'Lainnya') && !str_contains(json_encode($d), 'simulasi'));
}

// ---- C. adjustments
echo "\n== adjustments ==\n";
$dl = StockOpnameJejakService::detail($pdo, $fx['legacy']['session_id']);
$L = $fx['legacy']['items'];
$adjBySku = [];
foreach ($dl['adjustments'] as $a) { $adjBySku[$a['sku']] = $a; }
check('[legacy] exactly 3 real OPNAME adjustments (L1, L2, L4); zero-variance & excluded lines have none', count($dl['adjustments']) === 3 && isset($adjBySku[$L['L1']['sku']], $adjBySku[$L['L2']['sku']], $adjBySku[$L['L4']['sku']]));
check('[legacy] L1 adjustment is the REAL FIFO posting: -5 @ 1000 = -5000 (not variance x avg HPP 1200 = -6000)', near($adjBySku[$L['L1']['sku']]['value'], -5000.0) && near($adjBySku[$L['L1']['sku']]['hpp'], 1000.0));
$rowL1 = null; foreach ($dl['items'] as $r) { if ($r['item_id'] === $L['L1']['id']) $rowL1 = $r; }
check('[legacy] L1: Selisih Nominal (-6000) and Adjustment (-5000) are distinct, both real', near($rowL1['variance_value'], -6000.0) && near($rowL1['adjustment_value'], -5000.0));
check('[legacy] L2 IN adjustment +5 @ 2000 = +10000, type OPNAME, reason names the session', near($adjBySku[$L['L2']['sku']]['value'], 10000.0) && $adjBySku[$L['L2']['sku']]['type'] === 'OPNAME' && str_contains($adjBySku[$L['L2']['sku']]['reason'], (string) $fx['legacy']['session_id']));
check('[legacy] KPI Adjustment Bersih = -5000 + 10000 - 1500 = 3500 (positive 10000 / negative -6500)', near($dl['kpi']['adjustment_bersih']['value'], 3500.0) && near($dl['kpi']['adjustment_bersih']['positive'], 10000.0) && near($dl['kpi']['adjustment_bersih']['negative'], -6500.0));
$df = StockOpnameJejakService::detail($pdo, $fx['findings']['session_id']);
check('[findings] POSTED session with no linked adjustment reports 0 rows / Rp 0 and says so (never estimated)', $df['adjustments'] === [] && near($df['kpi']['adjustment_bersih']['value'], 0.0) && count($df['data_quality']['notes']) >= 1 && str_contains(implode(' ', $df['data_quality']['notes']), 'tidak ada adjustment'));
check('[findings] data_quality counts the unvalued SKU', $df['data_quality']['system_qty_missing'] === 1 && $df['kpi']['nilai_stok_sistem']['unvalued'] === 1);

// the adjustment is found through the transaction_uuid key too, not only adjustment_id
$pdo->prepare('UPDATE stock_opname_lines SET adjustment_id = NULL WHERE session_id = :s')->execute(['s' => $fx['legacy']['session_id']]);
$dl2 = StockOpnameJejakService::detail($pdo, $fx['legacy']['session_id']);
check('[legacy] adjustments still found via the post() key <session_uuid>:<item_id> when adjustment_id is NULL', count($dl2['adjustments']) === 3 && near($dl2['kpi']['adjustment_bersih']['value'], 3500.0));
// (restore what post() wrote so later checks/other tests see the original row)
foreach ($dl['adjustments'] as $a) {
    $pdo->prepare('UPDATE stock_opname_lines sol JOIN items i ON i.id = sol.item_id SET sol.adjustment_id = :a WHERE sol.session_id = :s AND i.sku = :sku')
        ->execute(['a' => $a['adjustment_id'], 's' => $fx['legacy']['session_id'], 'sku' => $a['sku']]);
}

// ---- D. read-only proof (the test's own two restore UPDATEs above are the only writes since $before)
$after = snapshotTables($pdo);
$changed = array_keys(array_filter($before, static fn ($v, $k) => $after[$k] !== $v, ARRAY_FILTER_USE_BOTH));
check('[read-only] only stock_opname_lines changed (by the test\'s own adjustment_id null/restore round-trip); every other table is byte-identical', $changed === [] || $changed === ['stock_opname_lines'], json_encode($changed));
$beforeCalls = snapshotTables($pdo);
for ($i = 0; $i < 3; $i++) {
    StockOpnameJejakService::detail($pdo, $fx['legacy']['session_id']);
    StockOpnameJejakService::detail($pdo, $fx['findings']['session_id']);
}
check('[read-only] repeated detail() calls change NOTHING (all 12 tables identical, incl. stock_opname_lines)', snapshotTables($pdo) === $beforeCalls);
check('[read-only] the service source contains no INSERT/UPDATE/DELETE/finalize/post call',
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b|StockOpnameService::(finalize|post|recount|submit)|StockAdjustmentService::post/i',
        // comments stripped: the docblock legitimately NAMES these calls to say it never makes them
        preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(__DIR__ . '/../services/StockOpnameJejakService.php'))));

// ---- E. HTTP
echo "\n== HTTP ==\n";
$port = 8900 + random_int(3600, 3999);
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
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($csrf) { $headers[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $raw, true) ?: []];
}
function loginAs(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'jf_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($base, $fx['viewer']);
    $outsider = loginAs($base, $fx['outsider']);
    $admin = loginAs($base, $fx['admin']);
    check('logins succeed', $viewer['status'] === 200 && $outsider['status'] === 200 && $admin['status'] === 200);

    $sid = $fx['findings']['session_id'];
    $r = http('GET', "{$base}/reports/opname/{$sid}/jejak", null, $viewer['jar']);
    check('VIEWER (INVENTORY_VIEW only) -> 200 with items/kpi/adjustments', $r['status'] === 200 && isset($r['body']['data']['items'], $r['body']['data']['kpi'], $r['body']['data']['adjustments']), (string) $r['status']);
    check('HTTP payload equals the service result (same KPIs)', ($r['body']['data']['kpi'] ?? null) === json_decode(json_encode(StockOpnameJejakService::detail($pdo, $sid)['kpi']), true));
    check('unauthenticated -> 401', http('GET', "{$base}/reports/opname/{$sid}/jejak")['status'] === 401);
    check('STOCK user scoped to ANOTHER warehouse -> 403', http('GET', "{$base}/reports/opname/{$sid}/jejak", null, $outsider['jar'])['status'] === 403);
    check('missing session -> 404', http('GET', "{$base}/reports/opname/99999999/jejak", null, $admin['jar'])['status'] === 404);
    check('existing GET /reports/opname/{id} is unaffected (still 200)', http('GET', "{$base}/reports/opname/{$sid}", null, $admin['jar'])['status'] === 200);

    $beforeHttp = snapshotTables($pdo);
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
        $w = http($verb, "{$base}/reports/opname/{$sid}/jejak", [], $admin['jar'], $admin['csrf']);
        check("{$verb} on the Jejak route -> no route (404), nothing handled", $w['status'] === 404, (string) $w['status']);
    }
    check('HTTP reads + rejected write verbs changed nothing in the database', snapshotTables($pdo) === $beforeHttp);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
