<?php
declare(strict_types=1);

require_once is_file(__DIR__ . '/rv3_bootstrap.php') ? __DIR__ . '/rv3_bootstrap.php' : __DIR__ . '/rv3/rv3_bootstrap.php';   // common bootstrap: rv3_script_exit(), --package-dir, service loader
$__rv3_payload = rv3_bootstrap_args($argv);

/**
 * READ-ONLY reconciliation of "Pergerakan Stok Harian" against the real ledger. Never writes (READ ONLY transaction; a write is
 * proved to be rejected first). For every warehouse and for "Semua Gudang":
 *   1. Saldo Awal + Masuk - Keluar ± Adjustment/Lain = Saldo Akhir, every day
 *   2. Saldo Akhir == independent ledger sum, every day
 *   3. Saldo Akhir of day N == Saldo Awal of day N+1
 *   4. daily row == Σ item rows (Rincian Per Barang), every day
 *   5. company Saldo Akhir == Σ warehouse Saldo Akhir (internal transfers create / destroy no value)
 *   6. Keluar (OUT) value == Σ fifo_allocations of those OUT lines (actual FIFO HPP)
 *   7. the last day's closing == the live batch valuation when the period ends today
 * Any difference is printed with warehouse / date / amount and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php scripts/movement_reconcile_check.php --app-root=<dir with services/> --start=YYYY-MM-DD --end=YYYY-MM-DD [--warehouse=<code|id|all>]
 */

$appRoot = $start = $end = $whArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--start=')) { $start = substr($arg, 8); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--warehouse=')) { $whArg = substr($arg, 12); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
if ($appRoot === null || !is_dir("{$appRoot}/services") || $start === null || $end === null || strtotime($start) === false || strtotime($end) === false || $start > $end) {
    fwrite(STDERR, "usage: php scripts/movement_reconcile_check.php --app-root=<dir> --start=YYYY-MM-DD --end=YYYY-MM-DD [--warehouse=<code|id|all>]\n");
    rv3_script_exit(2);
}
rv3_bootstrap_load($appRoot, $__rv3_payload);

use App\Services\Database;
use App\Services\MovementDailyReportService as M;

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
$near = static fn (float $a, float $b, float $e = 0.01): bool => abs($a - $b) <= $e;

$warehouses = $pdo->query('SELECT id, code, name FROM warehouses ORDER BY id')->fetchAll();
$scopes = [];
if ($whArg === null || $whArg === 'all') { $scopes['Semua Gudang'] = null; }
foreach ($warehouses as $w) {
    if ($whArg === null || $whArg === 'all' || $whArg === $w['code'] || $whArg === (string) $w['id']) { $scopes["{$w['code']} ({$w['name']})"] = (int) $w['id']; }
}
echo "Period {$start} .. {$end}\n";
$closings = [];
$today = date('Y-m-d');
foreach ($scopes as $label => $wh) {
    $ov = M::overview($pdo, $start, $end, $wh);
    $issues = $ov['reconciliation']['issues'];
    echo "\n== {$label} ==\n";
    $live = array_values(array_filter($ov['rows'], fn ($r) => !$r['is_pre_go_live']));
    $check("[{$label}] reconciliation (identity + independent ledger + carry-over) — over " . count($live) . ' live days', $ov['reconciliation']['ok'], $issues ? json_encode(array_slice($issues, 0, 5)) : '');
    foreach ($issues as $i) { echo "    {$i['type']} " . ($i['date'] ?? 'periode') . " selisih Rp {$i['difference']} — {$i['message']}\n"; }
    $itemMismatch = [];
    foreach ($live as $r) {
        $di = M::dayItems($pdo, $r['date'], $wh, null, null, null, null, 'sku', 'asc', 1, 1);
        $t = $di['totals']; $nm = $r['nominal'];
        if (!$near($t['opening_value'], $nm['opening']) || !$near($t['masuk_value'], $nm['masuk']) || !$near($t['keluar_value'], $nm['keluar']) || !$near($t['adjustment_value'], $nm['lain']) || !$near($t['closing_value'], $nm['closing'])) {
            $itemMismatch[] = $r['date'];
        }
    }
    $check("[{$label}] daily row == Σ item rows on every day", !$itemMismatch, implode(',', $itemMismatch));
    $hppBad = [];
    foreach ($live as $r) {
        $sql = "SELECT COALESCE(SUM(a.subtotal),0) FROM fifo_allocations a JOIN inventory_transaction_lines l ON l.id=a.transaction_line_id JOIN inventory_transactions t ON t.id=l.transaction_id
                WHERE t.transaction_type='OUT' AND t.status IN ('POSTED','VOID') AND t.inventory_effect=1 AND DATE(t.transaction_date)=:d" . ($wh !== null ? ' AND l.warehouse_id=:w' : '');
        $st = $pdo->prepare($sql);
        $st->execute($wh !== null ? ['d' => $r['date'], 'w' => $wh] : ['d' => $r['date']]);
        $alloc = (float) $st->fetchColumn();
        if (!$near($alloc, $r['buckets']['keluar_usage'], 0.05)) { $hppBad[] = "{$r['date']} (alloc {$alloc} vs report {$r['buckets']['keluar_usage']})"; }
    }
    $check("[{$label}] Keluar (OUT) value == Σ fifo_allocations (actual FIFO HPP) on every day", !$hppBad, implode('; ', array_slice($hppBad, 0, 3)));
    $closings[$label] = $live ? end($live)['nominal']['closing'] : 0.0;
    if ($end === $today && $live) {
        $sql = 'SELECT COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches' . ($wh !== null ? ' WHERE warehouse_id = ' . (int) $wh : '');
        $batch = (float) $pdo->query($sql)->fetchColumn();
        $check("[{$label}] closing today == live batch valuation", $near($closings[$label], $batch, 0.05), "{$closings[$label]} vs {$batch}");
    }
    $t = $ov['totals'];
    echo sprintf("    Saldo Awal %s | Masuk %s | Keluar %s | Adjustment/Lain %s | Saldo Akhir %s | SKU %d\n", number_format($t['opening'], 2), number_format($t['masuk'], 2), number_format($t['keluar'], 2), number_format($t['lain'], 2), number_format($t['closing'], 2), $t['sku_closing']);
}
if (isset($closings['Semua Gudang']) && count($closings) > 1) {
    $sum = 0.0;
    foreach ($closings as $k => $v) { if ($k !== 'Semua Gudang') { $sum += $v; } }
    if (count($scopes) === count($warehouses) + 1) {
        $check('Σ warehouse Saldo Akhir == Semua Gudang Saldo Akhir (internal transfers net to zero)', $near($sum, $closings['Semua Gudang'], 0.05), number_format($sum, 4) . ' vs ' . number_format($closings['Semua Gudang'], 4));
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED — DO NOT DEPLOY" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
