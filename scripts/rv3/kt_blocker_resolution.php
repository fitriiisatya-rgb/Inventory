<?php
declare(strict_types=1);

/**
 * Karang Tengah opening — BLOCKER RESOLUTION (read-only analysis).
 *
 *   php kt_blocker_resolution.php --app-root=<app> --source=<Hasil_SO xlsx> --out=<dir outside the app> [--package-dir=<package>] [--warehouse-code=KARANG_TENGAH]
 *
 * One READ ONLY transaction (the server is proved to refuse a write first). Takes the baseline opening plan (no resolutions), then
 *   · classifies every NOT_FOUND row against the production master: A existing item under another code · B existing item, name variant · C truly new master · D ambiguous
 *   · collects the unit evidence for every UNIT_UNRESOLVED row (and for mapped candidates whose unit would not resolve) and proposes a factor ONLY from hard evidence
 *   · prepares the master creation PLAN (nothing is created)
 * and writes blocker_resolution_summary.json, not_found_resolution.csv, unit_resolution.csv, proposed_new_master.csv, ambiguous_items.csv and karang_resolutions_PROPOSED.csv
 * (approved_by EMPTY — a person approves rows; kt_opening.php preview --resolutions=<csv> applies only approved rows).
 *
 * It never creates or changes a master item, conversion, supplier, category, ledger row or Stock Opname value, and never posts the opening.
 * Exit: 0 analysis written · 2 usage · 3 abort.
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/kt_opening_lib.php';
require_once __DIR__ . '/kt_resolve_lib.php';

$payload = rv3_bootstrap_args($argv);
$opt = ['app-root' => null, 'source' => null, 'out' => null, 'warehouse-code' => KT_WAREHOUSE_CODE];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        rv3_script_exit(2);
    }
}
if ($opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || $opt['source'] === null || $opt['out'] === null) {
    fwrite(STDERR, "usage: php kt_blocker_resolution.php --app-root=<dir with services/> --source=<Hasil_SO xlsx> --out=<dir outside the app> [--package-dir=<package>]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\XlsxReaderService']);

use App\Services\Database;

$real = realpath($opt['out']) ?: $opt['out'];
if (str_starts_with(rtrim($real, '/') . '/', $appRoot . '/')) {
    fwrite(STDERR, "ABORT: --out must be OUTSIDE the application tree ({$appRoot}) — a read-only analysis never writes into it.\n");
    rv3_script_exit(3);
}
try {
    $source = kt_read_source($opt['source']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    rv3_script_exit(3);
}
$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    $pdo->exec('ROLLBACK');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
    rv3_script_exit(3);
} catch (PDOException $e) {
    // expected: the server refused the write
}
try {
    $plan = kt_plan($pdo, $source, (string) $opt['warehouse-code']);
    $r = kr_resolve($pdo, $plan, $source);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
}
foreach (kr_report_lines($r, $plan) as $l) {
    echo $l . "\n";
}
echo "\n";
foreach (kr_write_outputs($r, $plan, $opt['out']) as $f) {
    echo "wrote {$f}\n";
}
echo "\nNOTHING WAS CREATED, MAPPED OR CHANGED. Next: a person reviews the CSVs and fills approved_by in karang_resolutions_PROPOSED.csv; then\n";
echo "  bash scripts/karang_preview.sh <APP ROOT> --resolutions=<approved csv>\n";
rv3_script_exit(0);
