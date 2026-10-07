<?php
declare(strict_types=1);

/**
 * PRODUCTION READ-ONLY VALIDATOR for Reports v3. SELECT only — it never writes the database (every sub-check opens a READ ONLY transaction and first PROVES that a write is rejected)
 * and never writes a file. It needs no shell: no child process, no escapeshellarg, no symlink, no temp file — the reconciliation scripts are included and run IN THIS PROCESS.
 * It runs the reconciliation of every report with the NEW code (the installed services, with the package's service files substituted in memory) against the REAL data:
 *   1. Stock Opname sessions 11 / 12 (default): the SOA reconciliation A–G
 *   2. Laporan Pergerakan Stok (v3 split), the daily movement service, IN / OUT / Transfer, Pembelian, Nilai HPP (FIFO layers = ledger, Average analytical)
 * The result is a table + an exit code (0 only when every check of every report passes). Nothing in the application tree is touched.
 *
 * TWO DISTINCT MODES (they prove different things and must each pass on their own):
 *   --mode=predeploy  (default; needs --package-dir)  candidate code: the installed services with the PACKAGE PAYLOAD substituted in memory. Proves the package's code works against the real
 *                                                      database BEFORE anything is installed. It says NOTHING about what is installed — it passes on a server where no file was ever written.
 *   --mode=installed  (FORBIDS --package-dir)         deployed code: loads ONLY <APP ROOT>/services. First asserts that every packaged backend file exists in the application with the packaged
 *                                                      sha256, that public/index.php carries the Reports v3 marker, that every V3 class resolves (Reflection) to a file under <APP ROOT>/services/ and never
 *                                                      under the package, and that the installed ReportsV3Routes.php defines every route. Any absent / different / package-loaded service = FAIL.
 *
 *   php rv3_readonly_check.php --app-root=<APP ROOT> --mode=predeploy --package-dir=<package> [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]
 *   php rv3_readonly_check.php --app-root=<APP ROOT> --mode=installed [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]
 */

require_once __DIR__ . '/rv3_lib.php';
require_once __DIR__ . '/rv3_bootstrap.php';

/** runs one reconciliation script in a function scope (its variables never leak) and returns [exit code, its output]. The scripts end through rv3_script_exit(), which throws here instead of exiting. */
function rv3_include_script(string $path, array $argv): array
{
    $argc = count($argv);
    ob_start();
    $rc = 255;
    try {
        include $path;
        $rc = 0;
    } catch (RV3ScriptExit $e) {
        $rc = $e->getCode();
    } catch (Throwable $e) {
        echo "\nFATAL " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        $rc = 255;
    }
    $text = (string) ob_get_clean();
    return [$rc, $text];
}

$app = $pkg = $sess = $start = $end = null;
$mode = 'predeploy';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--app-root=')) { $app = rtrim(substr($a, 11), '/'); }
    elseif (str_starts_with($a, '--package-dir=')) { $pkg = rtrim(substr($a, 14), '/'); }
    elseif (str_starts_with($a, '--mode=')) { $mode = substr($a, 7); }
    elseif (str_starts_with($a, '--session=')) { $sess = substr($a, 10); }
    elseif (str_starts_with($a, '--start=')) { $start = substr($a, 8); }
    elseif (str_starts_with($a, '--end=')) { $end = substr($a, 6); }
    else { rv3_die("unknown argument: {$a}", 2); }
}
if (!in_array($mode, ['predeploy', 'installed'], true)) {
    rv3_die("unknown --mode={$mode} (predeploy | installed)", 2);
}
if ($app === null || !is_dir("{$app}/services")) {
    rv3_die('usage: php rv3_readonly_check.php --app-root=<APP ROOT> --mode=predeploy --package-dir=<package> | --mode=installed  [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]', 2);
}
if ($mode === 'installed' && $pkg !== null) {
    rv3_die('--mode=installed refuses --package-dir: the post-deploy validator loads ONLY the installed application code, never the package payload.', 2);
}
if ($mode === 'predeploy' && ($pkg === null || !is_dir("{$pkg}/payload/services"))) {
    rv3_die('--mode=predeploy needs --package-dir=<package folder> with payload/services (this is the candidate code). To check what is INSTALLED use --mode=installed.', 2);
}
$app = realpath($app) ?: $app;
$sess ??= '11,12';
$end ??= date('Y-m-d');
$start ??= date('Y-m-01', strtotime('-2 months', strtotime($end)));
$payloadServices = $mode === 'predeploy' ? "{$pkg}/payload/services" : null;
$pkgRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);              // the package folder this script ships in (only its manifest.json DATA is read in installed mode)
$here = __DIR__;
$find = static function (string $n) use ($here): string {
    foreach ([$here, dirname($here)] as $d) {
        if (is_file("{$d}/{$n}")) { return "{$d}/{$n}"; }
    }
    rv3_die("validator script missing: {$n}");
};

echo $mode === 'installed' ? "== Reports v3 — POST-DEPLOY INSTALLED validator (read-only) ==\n" : "== Reports v3 — PRE-DEPLOY validator (read-only; candidate code from the package payload) ==\n";
echo "application: {$app} (read-only: nothing is written, copied, linked or executed in a shell)\n";
echo $mode === 'installed'
    ? "code under test: ONLY the files installed in {$app}/services — the package payload is NOT loaded (this mode refuses --package-dir)\n"
    : "code under test: the installed services with the package's payload service files substituted IN MEMORY — this proves the candidate works, NOT that it is installed. Run  installed_verify.sh  after the apply.\n";
echo "period: {$start} .. {$end}   Stock Opname sessions: {$sess}\n";

$assertFail = 0;
if ($mode === 'installed') {
    $manifest = json_decode((string) rv3_read("{$pkgRoot}/manifest.json"), true);
    if (!is_array($manifest)) {
        rv3_die("manifest.json not found next to the scripts ({$pkgRoot}) — needed only as DATA (expected hashes), never as code", 2);
    }
    $assert = static function (string $name, bool $ok, string $detail = '') use (&$assertFail): void {
        if (!$ok) {
            $assertFail++;
        }
        echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    };
    echo "\n######## INSTALLED CODE ASSERTIONS (files on disk in the application) ########\n";
    $appReal = realpath($app) ?: $app;
    $assert('the application root is not inside the package folder and the package is not the application', !str_starts_with($appReal . '/', $pkgRoot . '/') && $appReal !== $pkgRoot, "{$appReal} vs {$pkgRoot}");
    rv3_installed_assertions($appReal, $manifest, "{$pkgRoot}/payload", $assert);
    if ($assertFail > 0) {
        echo "\nPOST-DEPLOY INSTALLED VALIDATION: FAILED — the Reports v3 backend is NOT (fully) installed in {$appReal}. NOTHING was validated against package code. EXIT_CODE=1\n";
        exit(1);
    }
}

define('RV3_INPROCESS', true);
$err = rv3_load_services(rv3_service_files($app, $payloadServices));
if ($err !== null) {
    echo "\nFAIL - could not load the services: {$err}\n";
    exit(255);
}
if ($mode === 'installed') {
    echo "\n######## SOURCE FILE OF EVERY V3 CLASS (Reflection) ########\n";
    $svcDir = rtrim($appReal, '/') . '/services/';
    foreach ($manifest['v3_classes'] ?? [] as $class => $file) {
        $ok = class_exists($class);
        $src = $ok ? (new ReflectionClass($class))->getFileName() : false;
        $real = $src === false ? '' : (realpath($src) ?: $src);
        $assert("{$class} is loaded from {$svcDir}{$file}", $ok && $real === (realpath($svcDir . $file) ?: $svcDir . $file) && str_starts_with($real, $svcDir) && !str_starts_with($real, $pkgRoot . '/'), $ok ? $real : 'class not found');
    }
    $routesFile = realpath($svcDir . 'ReportsV3Routes.php') ?: $svcDir . 'ReportsV3Routes.php';
    $pdo = new stdClass();
    $query = [];
    $routes = [];
    try {
        $routes = require $routesFile;
    } catch (Throwable $e) {
        $assert('the installed ReportsV3Routes.php loads', false, get_class($e) . ': ' . $e->getMessage());
    }
    $missingKeys = array_values(array_diff($manifest['route_keys'] ?? [], is_array($routes) ? array_keys($routes) : []));
    $assert('the installed ReportsV3Routes.php (' . $routesFile . ') defines all ' . count($manifest['route_keys'] ?? []) . ' Reports v3 routes', is_array($routes) && $missingKeys === [], 'missing: ' . implode(', ', array_slice($missingKeys, 0, 5)));
    $assert('ReportsV3Routes.php was included from the application, not from the package', in_array($routesFile, array_map(static fn ($f) => realpath($f) ?: $f, get_included_files()), true) && !str_starts_with($routesFile, $pkgRoot . '/'));
    if ($assertFail > 0) {
        echo "\nPOST-DEPLOY INSTALLED VALIDATION: FAILED (installed code assertions). EXIT_CODE=1\n";
        exit(1);
    }
}
$runs = [
    ['Stock Opname reconciliation (sessions ' . $sess . ')', 'opname_audit_reconcile_check.php', ["--session={$sess}"]],
    ['Laporan Pergerakan Stok v3 (Transfer IN / OUT split)', 'movement_v3_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Pergerakan Stok Harian (daily service the v3 report wraps)', 'movement_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan IN / OUT / Transfer', 'inout_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan Pembelian', 'purchase_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
    ['Laporan Nilai HPP (FIFO layers = ledger; Average analytical)', 'valuation_reconcile_check.php', ["--start={$start}", "--end={$end}"]],
];
// the dashboard "Ringkasan Pergerakan Stok" must equal the report: checked whenever the package carries the dashboard service (pre-deploy: the payload copy) or is the dashboard package
$withDash = ($mode === 'predeploy' && $payloadServices !== null && is_file("{$payloadServices}/DashboardInventoryService.php")) || ($mode === 'installed' && in_array(($manifest['mode'] ?? ''), ['dash', 'cutover'], true));
if ($withDash) {
    $runs[] = ['Dashboard "Ringkasan Pergerakan Stok" == Laporan Pergerakan Stok (Reports v3)', 'dashboard_movement_reconcile_check.php', ['--periods=today,month,last7', "--custom={$start}:{$end}", '--label=' . ($mode === 'installed' ? 'INSTALLED' : 'CANDIDATE')]];
}
// period cutoff / opening-balance periodization (effective-date override, Karang Tengah opening): month continuity + override integrity, whenever the package carries InventoryEffectiveDateService
$withCut = ($mode === 'predeploy' && $payloadServices !== null && is_file("{$payloadServices}/InventoryEffectiveDateService.php")) || ($mode === 'installed' && (($manifest['mode'] ?? '') === 'cutover'));
if ($withCut) {
    $runs[] = ['Period cutoff / opening balance: override integrity + month continuity (closing m + controlled opening == opening m+1)', 'period_cutoff_reconcile_check.php', ['--months=4', "--end={$end}"]];
}
$rows = [];
$bad = 0;
foreach ($runs as [$label, $script, $args]) {
    echo "\n######## {$label} ########\n";
    [$rc, $text] = rv3_include_script($find($script), array_merge([$script, "--app-root={$app}"], $args));
    echo $text . "\n";
    preg_match('#(\d+) / (\d+) checks passed#', $text, $m);
    $rows[] = [$label, $m ? "{$m[1]} / {$m[2]}" : 'n/a', $rc === 0 ? 'PASS' : 'FAIL', $rc];
    if ($rc !== 0) { $bad++; }
}
if ($mode === 'installed') {
    $leak = array_values(array_filter(array_map(static fn ($f) => realpath($f) ?: $f, get_included_files()), static fn ($f) => str_starts_with($f, $pkgRoot . '/payload/')));
    echo "\n" . ($leak === [] ? 'PASS' : 'FAIL') . ' - no file of the package payload was loaded during this validation' . ($leak ? ' (' . implode(', ', $leak) . ')' : '') . "\n";
    if ($leak) {
        $bad++;
    }
}
echo "\n================ SUMMARY ================\n";
foreach ($rows as [$label, $n, $st, $rc]) {
    echo sprintf("%-4s %-9s exit=%d  %s\n", $st, $n, $rc, $label);
}
if ($mode === 'installed') {
    echo $bad === 0 ? "\nPOST-DEPLOY INSTALLED VALIDATION: PASS — the Reports v3 backend is installed in {$appReal}/services and every report reconciles using ONLY that code (EXIT_CODE=0)\n" : "\nPOST-DEPLOY INSTALLED VALIDATION: {$bad} CHECK(S) FAILED (EXIT_CODE=1)\n";
} else {
    echo $bad === 0 ? "\nPRE-DEPLOY VALIDATION: ALL REPORTS PASS with the candidate code (EXIT_CODE=0) — this does NOT prove anything is installed\n" : "\nPRE-DEPLOY VALIDATION: {$bad} REPORT(S) FAILED — DO NOT APPLY\n";
}
exit($bad === 0 ? 0 : 1);
