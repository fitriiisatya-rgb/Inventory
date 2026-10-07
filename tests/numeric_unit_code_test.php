<?php
declare(strict_types=1);

/**
 * Regression: a unit whose CODE is numeric ("12", "500") — PHP turns a numeric-string array key into an INTEGER key, so the per-unit maps of the movement reports held int AND string
 * keys mixed (PCS + 12 + 500) and `strcmp($a['unit'], $b['unit'])` threw "strcmp(): Argument #2 must be of type string, int given" under strict_types (seen on PRODUCTION with warehouse=all).
 * Real postings (tests/lib/valuation_fixture.php); afterwards items Q and R are moved to numeric base units. Quantities / values must be EXACTLY the ones of the same data with PCS units.
 *
 * Usage: php tests/numeric_unit_code_test.php
 */

require_once __DIR__ . '/lib/valuation_fixture.php';
require_once __DIR__ . '/../services/MovementReportV3Service.php';

use App\Services\Database;
use App\Services\MovementDailyReportService as D;
use App\Services\MovementReportV3Service as M;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

$pdo = Database::connection();
$fx = valuation_build_fixture($pdo);
[$s, $e] = ['2026-10-01', '2026-10-31'];
$base = M::overview($pdo, $s, $e, null, null, 'VLZ');
$baseTotals = $base['split_totals'];
$baseItems = array_column(M::perItem($pdo, $s, $e, null, null, 'VLZ', null), null, 'sku');

// ---- move two items to numeric unit codes (mixed with PCS)
$pdo->exec("INSERT INTO units (code, name) VALUES ('12', 'Lusin numerik'), ('500', 'Pack 500')");
$u12 = (int) $pdo->query("SELECT id FROM units WHERE code = '12'")->fetchColumn();
$u500 = (int) $pdo->query("SELECT id FROM units WHERE code = '500'")->fetchColumn();
$pdo->prepare('UPDATE items SET base_unit_id = :u WHERE id = :i')->execute(['u' => $u12, 'i' => $fx['items']['Q']['id']]);
$pdo->prepare('UPDATE items SET base_unit_id = :u WHERE id = :i')->execute(['u' => $u500, 'i' => $fx['items']['R']['id']]);

foreach ([['Semua Gudang', null], ['W1', $fx['wh']['1']], ['W2', $fx['wh']['2']]] as [$label, $wh]) {
    $err = '';
    try {
        $d = D::overview($pdo, $s, $e, $wh, null, 'VLZ');
        $v = M::overview($pdo, $s, $e, $wh, null, 'VLZ');
        $it = D::dayItems($pdo, '2026-10-03', $wh, null, null, null, null, 'sku', 'asc', 1, 50);
        $pi = M::perItem($pdo, $s, $e, $wh, null, 'VLZ', null);
        $wb = M::workbook($pdo, $s, $e, $wh, null, 'VLZ', null, ['Laporan' => 'x']);
    } catch (Throwable $x) {
        $err = get_class($x) . ': ' . $x->getMessage();
    }
    check("[{$label}] the daily service, the v3 overview, day items, per-item table and workbook all run with numeric unit codes (no TypeError)", $err === '', $err);
    if ($err !== '') {
        continue;
    }
    $units = array_column($d['totals']['qty_by_unit']['closing'], 'unit');
    check("[{$label}] every unit in qty_by_unit is a STRING, sorted", $units === array_map('strval', $units) && $units === (function ($x) { sort($x, SORT_STRING); return $x; })($units), json_encode($units));
    $vu = array_column($v['qty_units'], 'unit');
    check("[{$label}] the v3 per-unit summary: strings only", $vu === array_map('strval', $vu), json_encode($vu));
    check("[{$label}] the item rows keep numeric unit codes as strings", (function () use ($pi) { foreach ($pi as $r) { if (!is_string($r['unit'])) { return false; } } return true; })());
    json_encode($v, JSON_THROW_ON_ERROR);
}
$d = D::overview($pdo, $s, $e, null, null, 'VLZ');
$v = M::overview($pdo, $s, $e, null, null, 'VLZ');
$u = array_column($v['qty_units'], null, 'unit');
check('company-wide the units are 12, 500 and PCS (numeric + text codes), never summed together', array_map('strval', array_keys($u)) === ['12', '500', 'PCS'], implode(',', array_keys($u)));
check('values are IDENTICAL to the same data with PCS units (the fix touches types only, not one total)', json_encode($v['split_totals']) === json_encode($baseTotals));
$now = array_column(M::perItem($pdo, $s, $e, null, null, 'VLZ', null), null, 'sku');
$same = true;
foreach ($baseItems as $sku => $r) {
    $x = $now[$sku];
    unset($r['unit'], $x['unit']);
    $same = $same && $r === $x;
}
check('every item row (qty and value) is unchanged apart from its unit code', $same);
check('quantities of different units stay separate: Q in "12", R in "500", the rest PCS', ($u['12']['closing'] ?? null) === 30.0 && ($u['500']['closing'] ?? null) === 70.0 && ($u['PCS']['closing'] ?? null) === 395.0, json_encode(array_map(fn ($x) => $x['closing'], $u)));

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
