<?php
declare(strict_types=1);

if (!class_exists('RV3ScriptExit', false)) { final class RV3ScriptExit extends RuntimeException {} }
if (!function_exists('rv3_script_exit')) { function rv3_script_exit(int $c): never { if (defined('RV3_INPROCESS')) { throw new RV3ScriptExit('exit', $c); } exit($c); } }   // run in-process by the package validator: no child process, no shell

/**
 * READ-ONLY reconciliation of "Laporan Nilai Stok & HPP" (FIFO + Average) against the real ledger / FIFO tables. Never writes (a READ ONLY transaction; a write is
 * proved to be rejected first). Per warehouse scope:
 *   FIFO     remaining layer qty = ledger qty per item/warehouse · remaining layer value = FIFO stock value · rebuilt layers = inventory_batches.qty_base (when the
 *            period reaches today) · allocation qty = OUT qty · allocation cost = OUT HPP (every OUT line of the period)
 *   Average  per item: opening + cost in − HPP out ± transfer ± adjustment = closing, and closing ≈ qty × moving average · company-wide internal transfers net to 0
 *   plus the list of items whose Average cannot be reconstructed ("Average tidak dapat direkonstruksi") and the FIFO-vs-Average comparison.
 * Any difference is printed with item / amount and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php scripts/valuation_reconcile_check.php --app-root=<dir with services/> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>] [--category=<id>] [--q=<text>]
 */

$appRoot = $start = $end = $whArg = $cat = $q = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--start=')) { $start = substr($arg, 8); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--warehouse=')) { $whArg = substr($arg, 12); }
    elseif (str_starts_with($arg, '--category=')) { $cat = substr($arg, 11); }
    elseif (str_starts_with($arg, '--q=')) { $q = substr($arg, 4); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
$end ??= date('Y-m-d');
$start ??= date('Y-m-01', strtotime($end));
if ($appRoot === null || !is_dir("{$appRoot}/services") || strtotime($start) === false || strtotime($end) === false || $start > $end) {
    fwrite(STDERR, "usage: php scripts/valuation_reconcile_check.php --app-root=<dir> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>] [--category=<id>] [--q=<text>]\n");
    rv3_script_exit(2);
}
if (!defined('RV3_INPROCESS')) {   // in-process (package validator) the services are already loaded: the installed ones, with the package's substituted in memory
    foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) { if (basename($f) !== 'ReportsV3Routes.php') { require_once $f; } }
}

use App\Services\Database;
use App\Services\InventoryValuationService as V;

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
$scopes = [];
if ($whArg === null || $whArg === 'all') { $scopes['Semua Gudang'] = null; }
foreach ($pdo->query('SELECT id, code, name FROM warehouses ORDER BY id')->fetchAll() as $w) {
    if ($whArg === 'all' || $whArg === $w['code'] || $whArg === (string) $w['id']) { $scopes["{$w['code']} ({$w['name']})"] = (int) $w['id']; }
}
echo "Period {$start} .. {$end}\n";
foreach ($scopes as $label => $wh) {
    echo "\n== {$label} ==\n";
    $f = ['start_date' => $start, 'end_date' => $end, 'warehouse_id' => $wh, 'category_id' => $cat, 'q' => $q, 'method' => 'fifo', 'view' => 'item', 'per_page' => 500];
    $ov = V::overview($pdo, $f);
    foreach ($ov['reconciliation']['checks'] as $c) {
        $n++;
        if (!$c['ok']) { $fail++; }
        echo ($c['ok'] ? 'PASS' : 'FAIL') . " - {$c['name']} ({$c['detail']})\n";
    }
    $k = $ov['kpi'];
    $cmp = $ov['comparison'];
    echo "    FIFO: awal {$k['fifo']['opening']} + masuk {$k['fifo']['cost_in']} − HPP {$k['fifo']['hpp']} ± transfer {$k['fifo']['transfer']} ± adj {$k['fifo']['adjustment']} = akhir {$k['fifo']['closing']} | layer aktif {$k['fifo']['layers']} | SKU berstok {$k['fifo']['skus_with_stock']}\n";
    echo "    Average: awal {$k['average']['opening']} + masuk {$k['average']['cost_in']} − pemakaian {$k['average']['usage']} ± lainnya {$k['average']['other_net']} = akhir {$k['average']['closing']} | HPP {$k['average']['hpp']}\n";
    echo "    Perbandingan ({$cmp['items']} barang): HPP FIFO {$cmp['fifo']['hpp']} vs Average {$cmp['average']['hpp']} (Δ {$cmp['diff']['hpp']}); nilai akhir FIFO {$cmp['fifo']['closing']} vs Average {$cmp['average']['closing']} (Δ {$cmp['diff']['closing']})\n";
    if ($ov['unreconstructable'] !== []) {
        echo '    Average tidak dapat direkonstruksi (' . count($ov['unreconstructable']) . " barang — tidak dihitung, tidak dikarang):\n";
        foreach (array_slice($ov['unreconstructable'], 0, 25) as $u) { echo "      - {$u['sku']} {$u['name']}: {$u['reason']}\n"; }
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
