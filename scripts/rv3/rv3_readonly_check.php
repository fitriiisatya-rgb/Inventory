<?php
declare(strict_types=1);

/**
 * PRODUCTION READ-ONLY VALIDATOR for Reports v3. SELECT only — it never writes the database (every sub-check opens a READ ONLY transaction and first PROVES that a write is rejected).
 * It runs the reconciliation of every report with the NEW code (production services + this package's payload services loaded together) against the REAL data:
 *   1. Stock Opname sessions 11 / 12 (default): the SOA reconciliation A–G (16 checks for the two sessions)
 *   2. Laporan Pergerakan Stok (v3 split), the daily movement service, IN / OUT / Transfer, Pembelian, Nilai HPP (FIFO layers = ledger, Average analytical)
 * The result is a table + an exit code (0 only when every check of every report passes). Nothing in the application tree is touched.
 *
 *   php rv3_readonly_check.php --app-root=<APP ROOT> [--package-dir=<package>] [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]
 */

require_once __DIR__ . '/rv3_lib.php';

$app = $pkg = $sess = $start = $end = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--app-root=')) { $app = rtrim(substr($a, 11), '/'); }
    elseif (str_starts_with($a, '--package-dir=')) { $pkg = rtrim(substr($a, 14), '/'); }
    elseif (str_starts_with($a, '--session=')) { $sess = substr($a, 10); }
    elseif (str_starts_with($a, '--start=')) { $start = substr($a, 8); }
    elseif (str_starts_with($a, '--end=')) { $end = substr($a, 6); }
    else { rv3_die("unknown argument: {$a}", 2); }
}
if ($app === null || !is_dir("{$app}/services")) {
    rv3_die('usage: php rv3_readonly_check.php --app-root=<APP ROOT> [--package-dir=<package>] [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]', 2);
}
$sess ??= '11,12';
$end ??= date('Y-m-d');
$start ??= date('Y-m-01', strtotime('-2 months', strtotime($end)));
$payload = $pkg !== null && is_dir("{$pkg}/payload") ? "{$pkg}/payload" : null;
$here = __DIR__;
$find = static function (string $n) use ($here): string {
    foreach ([$here, dirname($here)] as $d) {
        if (is_file("{$d}/{$n}")) { return "{$d}/{$n}"; }
    }
    rv3_die("validator script missing: {$n}");
};

$overlay = sys_get_temp_dir() . '/rv3_validate_' . bin2hex(random_bytes(4));
rv3_overlay($app, $payload ?? '/nonexistent', $overlay);
echo "== Reports v3 — production READ-ONLY validator ==\n";
echo "application: {$app}\n" . ($payload ? "code under test: production services + package payload (overlay {$overlay})\n" : "code under test: the services installed in the application\n");
echo "period: {$start} .. {$end}   Stock Opname sessions: {$sess}\n";
$runs = [
    ['Stock Opname reconciliation (sessions ' . $sess . ')', 'opname_audit_reconcile_check.php', ["--session={$sess}"]],
    ['Laporan Pergerakan Stok v3 (Transfer IN / OUT split)', 'movement_v3_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Pergerakan Stok Harian (daily service the v3 report wraps)', 'movement_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan IN / OUT / Transfer', 'inout_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan Pembelian', 'purchase_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan Nilai HPP (FIFO layers = ledger; Average analytical)', 'valuation_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
];
$rows = [];
$bad = 0;
foreach ($runs as [$label, $script, $args]) {
    echo "\n######## {$label} ########\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($find($script)) . ' --app-root=' . escapeshellarg($overlay) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $o = [];
    exec($cmd, $o, $rc);
    $text = implode("\n", $o);
    echo $text . "\n";
    preg_match('#(\d+) / (\d+) checks passed#', $text, $m);
    $rows[] = [$label, $m ? "{$m[1]} / {$m[2]}" : 'n/a', $rc === 0 ? 'PASS' : 'FAIL', $rc];
    if ($rc !== 0) { $bad++; }
}
rv3_rmtree($overlay);
echo "\n================ SUMMARY ================\n";
foreach ($rows as [$label, $n, $st, $rc]) {
    echo sprintf("%-4s %-9s exit=%d  %s\n", $st, $n, $rc, $label);
}
echo $bad === 0 ? "\nREAD-ONLY VALIDATION: ALL REPORTS PASS (EXIT_CODE=0)\n" : "\nREAD-ONLY VALIDATION: {$bad} REPORT(S) FAILED — DO NOT APPLY\n";
exit($bad === 0 ? 0 : 1);
