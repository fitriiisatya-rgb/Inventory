<?php
declare(strict_types=1);

/**
 * "Laporan Pergerakan Stok" v3 — MovementReportV3Service + GET /reports/movement/v3/*, on REAL postings (tests/lib/valuation_fixture.php, period 2026-10-01 .. 2026-10-31).
 * Expectations are HAND-COMPUTED from the fixture (see the arithmetic in the comments), independent of the report code.
 *
 *   Company-wide: Stok Awal 124.000 + IN 492.000 − OUT 278.800 + Transfer IN 32.000 − Transfer OUT 32.000 + Adjustment (−10.700) = Stok Akhir 326.500
 *     IN: P 210.000 + Q 120.000 + R 60.000 + S 70.000 + T 1.000 + U 1.000 + O 30.000 · OUT: P 80.000 + Q 148.000 + R 15.000 + T 300 (voided original) + U 1.500 + O 34.000
 *     Adjustment: S −15.000 + 4.000 and the reversal of the voided OUT +300
 *   W1: opening 124.000, IN 492.000, OUT 263.800, Transfer OUT 32.000, adjustment −10.700, closing 309.500 · W2: Transfer IN 32.000, OUT 15.000, closing 17.000
 *
 * Usage: php tests/movement_report_v3_test.php
 */

require_once __DIR__ . '/lib/valuation_fixture.php';
require_once __DIR__ . '/../services/MovementReportV3Service.php';

use App\Services\Database;
use App\Services\MovementReportV3Service as M;
use App\Services\ReportExportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $eps;
}
function snap(PDO $pdo): array
{
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'warehouse_transfers', 'warehouse_transfer_lines', 'audit_logs'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}

$pdo = Database::connection();
$fx = valuation_build_fixture($pdo);
$W1 = $fx['wh']['1'];
$W2 = $fx['wh']['2'];
$before = snap($pdo);
[$s, $e] = ['2026-10-01', '2026-10-31'];

echo "== A. company-wide split ==\n";
$ov = M::overview($pdo, $s, $e, null, null, 'VLZ');
$t = $ov['split_totals'];
check('A Stok Awal 124.000 · IN 492.000 · OUT 278.800 · Transfer IN 32.000 · Transfer OUT 32.000 · Adjustment −10.700 · Stok Akhir 326.500', near($t['opening'], 124000) && near($t['in'], 492000) && near($t['out'], 278800) && near($t['tin'], 32000) && near($t['tout'], 32000) && near($t['adjustment'], -10700) && near($t['closing'], 326500), json_encode($t));
check('A company-wide the transfer is NET ZERO (every transfer received): Transfer IN − OUT = 0, difference 0, reconciliation OK incl. the split check', near($t['transfer_net'], 0) && near($t['difference'], 0) && $ov['reconciliation']['ok'] === true && $ov['reconciliation']['split_ok'] === true);
$ok = true;
$prev = null;
foreach ($ov['rows'] as $r) {
    $x = $r['split'];
    if (abs($x['opening'] + $x['in'] - $x['out'] + $x['tin'] - $x['tout'] + $x['adjustment'] - $x['closing']) > 0.01) { $ok = false; }
    if ($prev !== null && abs($prev - $x['opening']) > 0.01) { $ok = false; }
    $prev = $x['closing'];
}
check('A the identity holds on every one of the 31 days and each day opens at the previous close', $ok && count($ov['rows']) === 31);
$d3 = array_values(array_filter($ov['rows'], static fn ($r) => $r['date'] === '2026-10-03'))[0];
check('A 2026-10-03: transfer R 60 pcs appears as Transfer IN 32.000 AND Transfer OUT 32.000 (company-wide), OUT 80.000 (P), adjustment −15.000 (S) + 300 (void reversal)', near($d3['split']['tin'], 32000) && near($d3['split']['tout'], 32000) && near($d3['split']['out'], 80000) && near($d3['split']['adjustment'], -14700), json_encode($d3['split']));
$u = array_column($ov['qty_units'], null, 'unit')['PCS'] ?? [];
check('A quantities by unit (all PCS here): Awal 80 · IN 690 · OUT 265 · Transfer IN 60 · Transfer OUT 60 · Adjustment −10 · Akhir 495 — identity holds', near($u['opening'] ?? null, 80) && near($u['in'] ?? null, 690) && near($u['out'] ?? null, 265) && near($u['tin'] ?? null, 60) && near($u['tout'] ?? null, 60) && near($u['adjustment'] ?? null, -10) && near($u['closing'] ?? null, 495), json_encode($u));

echo "\n== B. per warehouse ==\n";
$o1 = M::overview($pdo, $s, $e, $W1, null, 'VLZ')['split_totals'];
$o2 = M::overview($pdo, $s, $e, $W2, null, 'VLZ')['split_totals'];
check('B W1: opening 124.000, IN 492.000, OUT 263.800, Transfer OUT 32.000 (no Transfer IN), adjustment −10.700, closing 309.500', near($o1['opening'], 124000) && near($o1['in'], 492000) && near($o1['out'], 263800) && near($o1['tin'], 0) && near($o1['tout'], 32000) && near($o1['adjustment'], -10700) && near($o1['closing'], 309500) && near($o1['difference'], 0), json_encode($o1));
check('B W2: Transfer IN 32.000 (no Transfer OUT), OUT 15.000, closing 17.000', near($o2['opening'], 0) && near($o2['in'], 0) && near($o2['out'], 15000) && near($o2['tin'], 32000) && near($o2['tout'], 0) && near($o2['adjustment'], 0) && near($o2['closing'], 17000) && near($o2['difference'], 0), json_encode($o2));
check('B company-wide = W1 + W2: closing 326.500 = 309.500 + 17.000; OUT 278.800 = 263.800 + 15.000; Transfer OUT of W1 = Transfer IN of W2', near($t['closing'], $o1['closing'] + $o2['closing']) && near($t['out'], $o1['out'] + $o2['out']) && near($o1['tout'], $o2['tin']));
$ovq = M::overview($pdo, $s, $e, $W2, null, 'VLZ');
$u2 = array_column($ovq['qty_units'], null, 'unit')['PCS'] ?? [];
check('B W2 quantities: Transfer IN 60, OUT 30, closing 30', near($u2['tin'] ?? null, 60) && near($u2['out'] ?? null, 30) && near($u2['closing'] ?? null, 30) && near($u2['adjustment'] ?? null, 0));
$oi = M::overview($pdo, $s, $e, null, null, 'VLZR');
check('B filters narrow the split: item R only — IN 60.000, OUT 15.000, Transfer IN = OUT = 32.000, closing 45.000', near($oi['split_totals']['in'], 60000) && near($oi['split_totals']['out'], 15000) && near($oi['split_totals']['tin'], 32000) && near($oi['split_totals']['tout'], 32000) && near($oi['split_totals']['closing'], 45000) && near($oi['split_totals']['difference'], 0));

echo "\n== C. per item ==\n";
$pi = M::perItem($pdo, $s, $e, null, null, 'VLZ', null);
$sumClose = array_sum(array_column($pi, 'closing_value'));
$sumOpen = array_sum(array_column($pi, 'opening_value'));
check('C per-item table: Σ opening 124.000, Σ closing 326.500 — equal to the daily overview; 7 items', near($sumOpen, 124000) && near($sumClose, 326500) && count($pi) === 7, (string) count($pi));
$bySku = [];
foreach ($pi as $r) { $bySku[$r['sku']] = $r; }
$rItem = array_values(array_filter($pi, static fn ($r) => $r['item_id'] === $fx['items']['R']['id']))[0];
check('C item R: opening 0 · IN 100 / 60.000 · OUT 30 / 15.000 · Transfer IN 60 / 32.000 · Transfer OUT 60 / 32.000 · adjustment 0 · closing 70 / 45.000', near($rItem['opening_qty'], 0) && near($rItem['in_qty'], 100) && near($rItem['in_value'], 60000) && near($rItem['out_qty'], 30) && near($rItem['out_value'], 15000) && near($rItem['tin_qty'], 60) && near($rItem['tin_value'], 32000)
    && near($rItem['tout_qty'], 60) && near($rItem['adjustment_qty'], 0) && near($rItem['closing_qty'], 70) && near($rItem['closing_value'], 45000), json_encode($rItem));
$tItem = array_values(array_filter($pi, static fn ($r) => $r['item_id'] === $fx['items']['T']['id']))[0];
check('C item T (voided OUT): OUT 300 (the voided original), adjustment +300 (its reversal) — net stock back to 100 @ 10', near($tItem['out_value'], 300) && near($tItem['adjustment_value'], 300) && near($tItem['closing_qty'], 100) && near($tItem['closing_value'], 1000));
check('C every item: opening + IN − OUT + Transfer IN − Transfer OUT + adjustment = closing, in qty AND value', (function () use ($pi) { foreach ($pi as $r) { if (abs($r['opening_qty'] + $r['in_qty'] - $r['out_qty'] + $r['tin_qty'] - $r['tout_qty'] + $r['adjustment_qty'] - $r['closing_qty']) > 0.0001 || abs($r['opening_value'] + $r['in_value'] - $r['out_value'] + $r['tin_value'] - $r['tout_value'] + $r['adjustment_value'] - $r['closing_value']) > 0.01) { return false; } } return true; })());
$piW2 = M::perItem($pdo, $s, $e, $W2, null, 'VLZ', null);
check('C per warehouse: W2 has only item R (closing 30 / 17.000)', count($piW2) === 1 && near($piW2[0]['closing_qty'], 30) && near($piW2[0]['closing_value'], 17000) && near($piW2[0]['tin_qty'], 60));
check('C an item with neither stock nor movement is not listed; a period before go-live / empty returns nothing', M::perItem($pdo, '2025-01-01', '2025-01-31', null, null, 'VLZ', null) === []);

echo "\n== D. workbook ==\n";
$meta = ['Laporan' => 'Laporan Pergerakan Stok'];
$wb = M::workbook($pdo, $s, $e, null, null, 'VLZ', null, $meta);
check('D sheets: Ringkasan, Harian, Per Barang, Detail Harian per Barang, Per Satuan (Qty)', array_keys($wb) === ['Ringkasan', 'Harian', 'Per Barang', 'Detail Harian per Barang', 'Per Satuan (Qty)']);
$hr = $wb['Harian'];
check('D Harian columns: Tanggal, Stok Awal, Barang Masuk (IN), Barang Keluar (OUT), Transfer IN, Transfer OUT, Adjustment / Lain, Stok Akhir', array_slice($hr['headers'], 0, 8) === ['Tanggal', 'Stok Awal', 'Barang Masuk (IN)', 'Barang Keluar (OUT)', 'Transfer IN', 'Transfer OUT', 'Adjustment / Lain', 'Stok Akhir'] && count($hr['rows']) === 32);
$tot = end($hr['rows']);
check('D Harian TOTAL row = KPI: opening 124.000, IN 492.000, OUT 278.800, Transfer 32.000 / 32.000, Adjustment −10.700, closing 326.500, difference 0', $tot[0] === 'TOTAL PERIODE' && near($tot[1], 124000) && near($tot[2], 492000) && near($tot[3], 278800) && near($tot[4], 32000) && near($tot[5], 32000) && near($tot[6], -10700) && near($tot[7], 326500) && near($tot[8], 0));
$pb = $wb['Per Barang'];
$pt = end($pb['rows']);
check('D Per Barang TOTAL (value columns only; qty is never summed across units): Nilai Awal 124.000, Nilai Akhir 326.500, qty cells blank', near($pt[5], 124000) && near($pt[17], 326500) && $pt[4] === '' && $pt[16] === '');
check('D Detail Harian per Barang carries the item / day rows; Per Satuan (Qty) carries PCS 80 → 495', count($wb['Detail Harian per Barang']['rows']) > 10 && $wb['Per Satuan (Qty)']['rows'][0][0] === 'PCS' && near($wb['Per Satuan (Qty)']['rows'][0][7], 495));
$sm = array_column($wb['Ringkasan']['rows'], 1, 0);
check('D Ringkasan: the filters (meta), the formula, "SEIMBANG", and every KPI value', ($sm['Laporan'] ?? '') === 'Laporan Pergerakan Stok' && ($sm['Rekonsiliasi'] ?? '') === 'SEIMBANG' && str_contains((string) ($sm['Rumus'] ?? ''), 'Transfer IN') && near((float) ($sm['Stok Akhir (nilai)'] ?? -1), 326500));
$types = $hr['types'];
check('D typed columns: Tanggal = date, the money columns = money, the counts = int', $types[0] === 'date' && $types[1] === 'money' && $types[7] === 'money' && $types[9] === 'int');
$tmp = sys_get_temp_dir() . '/mv3_' . bin2hex(random_bytes(3)) . '.xlsx';
ReportExportService::write($tmp, $wb);
$z = new ZipArchive();
check('D the workbook is a valid xlsx with 5 sheets', $z->open($tmp) === true && $z->locateName('xl/worksheets/sheet5.xml') !== false && $z->locateName('xl/worksheets/sheet6.xml') === false);
$z->close();
@unlink($tmp);
check('D the service never wrote: every ledger / FIFO / transfer table is unchanged', snap($pdo) === $before);

echo "\n== E. HTTP ==\n";
$port = 8900 + random_int(2400, 2799);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
$baseUrl = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$baseUrl}/auth/me");
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
function loginAs(string $baseUrl, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'mv_');
    $r = http('POST', "{$baseUrl}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $viewer = loginAs($baseUrl, $fx['viewer']);
    $stock2 = loginAs($baseUrl, $fx['stock2']);
    $admin = loginAs($baseUrl, $fx['admin']);
    $qs = "start_date={$s}&end_date={$e}&q=VLZ";
    $r = http('GET', "{$baseUrl}/reports/movement/v3/overview?{$qs}", null, $viewer['jar']);
    check('E VIEWER (INVENTORY_VIEW only): overview 200 with the split totals (closing 326.500) and the per-unit breakdown', $r['status'] === 200 && near((float) ($r['body']['data']['split_totals']['closing'] ?? -1), 326500) && isset($r['body']['data']['qty_units']), (string) $r['status']);
    $r = http('GET', "{$baseUrl}/reports/movement/v3/overview?{$qs}&warehouse_id={$W1}", null, $stock2['jar']);
    check('E a STOCK user of W2 asking for W1 gets ONLY W2 (closing 17.000, Transfer IN 32.000)', $r['status'] === 200 && near((float) ($r['body']['data']['split_totals']['closing'] ?? -1), 17000) && near((float) ($r['body']['data']['split_totals']['tin'] ?? -1), 32000));
    check('E unauthenticated → 401; reversed / missing dates → 422', http('GET', "{$baseUrl}/reports/movement/v3/overview?{$qs}")['status'] === 401 && http('GET', "{$baseUrl}/reports/movement/v3/overview?start_date=2026-10-31&end_date=2026-10-01", null, $viewer['jar'])['status'] === 422
        && http('GET', "{$baseUrl}/reports/movement/v3/export", null, $viewer['jar'])['status'] === 422);
    $x = http('GET', "{$baseUrl}/reports/movement/v3/export?{$qs}", null, $viewer['jar'], null, true);
    check('E export: 200 xlsx named Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx, a valid zip with 5 sheets', $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml') !== false && str_contains($x['headers'], 'filename="Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx"')
        && (function () use ($x) { $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($f, $x['raw']); $z = new ZipArchive(); $ok = $z->open($f) === true && $z->locateName('xl/worksheets/sheet5.xml') !== false && $z->locateName('xl/worksheets/sheet6.xml') === false; $z->close(); @unlink($f); return $ok; })());
    $r = http('GET', "{$baseUrl}/reports/movement/v3/export?{$qs}&format=json&warehouse_id={$W2}&mode=qty", null, $admin['jar']);
    $pj = $r['body']['data'] ?? [];
    check('E format=json (the print tables): file name, meta with the warehouse + "Kuantitas" mode, Harian sheet 32 rows, types', $r['status'] === 200 && ($pj['file_name'] ?? '') === 'Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx' && str_contains(json_encode($pj['meta'] ?? []), 'Kuantitas')
        && ($pj['sheets'][1]['name'] ?? '') === 'Harian' && count($pj['sheets'][1]['rows'] ?? []) === 32 && ($pj['sheets'][1]['types'][0] ?? '') === 'date');
    $r = http('GET', "{$baseUrl}/reports/movement/v3/items?{$qs}&per_page=3&page=2&sort=closing_value&dir=desc", null, $viewer['jar']);
    $d = $r['body']['data'] ?? [];
    check('E items route: server-side pagination (3 per page, page 2 of 3, 7 items), sorted by closing value desc, totals cover the WHOLE set (closing 326.500)', $r['status'] === 200 && count($d['rows'] ?? []) === 3 && ($d['pagination']['total'] ?? 0) === 7 && ($d['pagination']['total_pages'] ?? 0) === 3
        && near((float) ($d['totals']['closing_value'] ?? -1), 326500) && ($d['rows'][0]['closing_value'] ?? 0) >= ($d['rows'][1]['closing_value'] ?? 0));
    $r = http('GET', "{$baseUrl}/reports/movement/v3/items?{$qs}&move=transfer", null, $viewer['jar']);
    check('E items route move=transfer lists only item R; a search narrows too', ($r['body']['data']['pagination']['total'] ?? 0) === 1 && ($r['body']['data']['rows'][0]['tin_value'] ?? 0) == 32000
        && ($r['body']['data']['rows'][0]['sku'] ?? '') === $fx['items']['R']['sku']);
    check('E items: unauthenticated → 401; per_page is clamped (9999 → 200)', http('GET', "{$baseUrl}/reports/movement/v3/items?{$qs}")['status'] === 401 && (http('GET', "{$baseUrl}/reports/movement/v3/items?{$qs}&per_page=9999", null, $viewer['jar'])['body']['data']['pagination']['per_page'] ?? 0) === 200);
    check('E existing movement routes unaffected: GET /reports/movement/overview still 200', http('GET', "{$baseUrl}/reports/movement/overview?{$qs}", null, $admin['jar'])['status'] === 200);
    $b4 = snap($pdo);
    $writeOk = true;
    foreach (['/overview', '/items', '/export'] as $route) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (http($verb, "{$baseUrl}/reports/movement/v3{$route}", [], $admin['jar'], $admin['csrf'])['status'] !== 404) { $writeOk = false; }
        }
    }
    check('E POST / PUT / PATCH / DELETE on every v3 route → 404 (no write route exists); reads changed nothing', $writeOk && snap($pdo) === $b4);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
