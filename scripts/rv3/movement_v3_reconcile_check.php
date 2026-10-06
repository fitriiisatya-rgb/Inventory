<?php
declare(strict_types=1);

/**
 * READ-ONLY reconciliation of "Laporan Pergerakan Stok" (v3: Transfer IN / OUT split) against the real ledger. Never writes (READ ONLY transaction; a write is proved to be rejected first).
 * For "Semua Gudang" and every warehouse:
 *   1. Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment = Stok Akhir, on every day, and every day opens at the previous close (reconciliation.ok + split_ok)
 *   2. the per-item table: Σ Stok Awal / Stok Akhir (nilai) == the daily totals, and every item satisfies the identity in quantity AND value
 *   3. company-wide: Σ warehouse (IN, OUT, Transfer IN, Transfer OUT, Stok Akhir) == the Semua Gudang figure
 * Any difference is printed and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php movement_v3_reconcile_check.php --app-root=<dir with services/> --start=YYYY-MM-DD --end=YYYY-MM-DD [--warehouse=<code|id|all>]
 */

$appRoot = $start = $end = $whArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--start=')) { $start = substr($arg, 8); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--warehouse=')) { $whArg = substr($arg, 12); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
$end ??= date('Y-m-d');
$start ??= date('Y-m-01', strtotime($end));
if ($appRoot === null || !is_dir("{$appRoot}/services") || strtotime($start) === false || strtotime($end) === false || $start > $end) {
    fwrite(STDERR, "usage: php movement_v3_reconcile_check.php --app-root=<dir> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>]\n");
    exit(2);
}
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') { require_once $f; }
}

use App\Services\Database;
use App\Services\MovementReportV3Service as M;

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
$near = static fn (float $a, float $b, float $e = 0.05): bool => abs($a - $b) <= $e;

$warehouses = $pdo->query('SELECT id, code, name FROM warehouses ORDER BY id')->fetchAll();
$scopes = [];
if ($whArg === null || $whArg === 'all') { $scopes['Semua Gudang'] = null; }
foreach ($warehouses as $w) {
    if ($whArg === null || $whArg === 'all' || $whArg === $w['code'] || $whArg === (string) $w['id']) { $scopes["{$w['code']} ({$w['name']})"] = (int) $w['id']; }
}
echo "Period {$start} .. {$end}\n";
$tot = [];
foreach ($scopes as $label => $wh) {
    echo "\n== {$label} ==\n";
    $ov = M::overview($pdo, $start, $end, $wh);
    $t = $ov['split_totals'];
    $tot[$label] = $t;
    $check("[{$label}] identity on every day + carry-over (reconciliation.ok, split_ok)", $ov['reconciliation']['ok'] && $ov['reconciliation']['split_ok'], json_encode(array_slice(array_merge($ov['reconciliation']['issues'], $ov['reconciliation']['split_issues'] ?? []), 0, 3)));
    $check("[{$label}] period identity: Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment = Akhir", $near($t['opening'] + $t['in'] - $t['out'] + $t['tin'] - $t['tout'] + $t['adjustment'], $t['closing']), number_format($t['difference'], 4));
    $items = M::perItem($pdo, $start, $end, $wh, null, null, null);
    $so = $sc = 0.0; $bad = [];
    foreach ($items as $r) {
        $so += $r['opening_value']; $sc += $r['closing_value'];
        if (abs($r['opening_qty'] + $r['in_qty'] - $r['out_qty'] + $r['tin_qty'] - $r['tout_qty'] + $r['adjustment_qty'] - $r['closing_qty']) > 0.001
            || abs($r['opening_value'] + $r['in_value'] - $r['out_value'] + $r['tin_value'] - $r['tout_value'] + $r['adjustment_value'] - $r['closing_value']) > 0.05) { $bad[] = $r['sku']; }
    }
    $check("[{$label}] per-item table: Σ Stok Awal / Stok Akhir == the daily totals (" . count($items) . ' items)', $near($so, $t['opening']) && $near($sc, $t['closing']), number_format($so, 2) . ' / ' . number_format($sc, 2));
    $check("[{$label}] every item satisfies the identity in quantity AND value", $bad === [], implode(',', array_slice($bad, 0, 5)));
    echo sprintf("    Awal %s | IN %s | OUT %s | Transfer IN %s | Transfer OUT %s | Adjustment %s | Akhir %s\n", number_format($t['opening'], 2), number_format($t['in'], 2), number_format($t['out'], 2), number_format($t['tin'], 2), number_format($t['tout'], 2), number_format($t['adjustment'], 2), number_format($t['closing'], 2));
}
if (isset($tot['Semua Gudang']) && count($scopes) === count($warehouses) + 1) {
    $c = $tot['Semua Gudang'];
    $sum = fn (string $k) => array_sum(array_map(fn ($l) => $l === 'Semua Gudang' ? 0.0 : $tot[$l][$k], array_keys($tot)));
    foreach (['in' => 'IN', 'out' => 'OUT', 'tin' => 'Transfer IN', 'tout' => 'Transfer OUT', 'closing' => 'Stok Akhir'] as $k => $label) {
        $check("Σ warehouse {$label} == Semua Gudang", $near($sum($k), $c[$k]), number_format($sum($k), 2) . ' vs ' . number_format($c[$k], 2));
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED — DO NOT DEPLOY" : ' — all reconcile') . "\n";
exit($fail ? 1 : 0);
