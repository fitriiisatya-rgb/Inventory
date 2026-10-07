<?php
declare(strict_types=1);

/**
 * Builds the ONE production package for the five reports (Pergerakan, IN / OUT, Pembelian, Nilai HPP, Stock Opname + print / Excel + sidebar). NOT a deploy.
 *
 *   php scripts/build_rv3_package.php <output dir>            (run from anywhere inside the repository; reads the committed files at RV3_REV, default HEAD)
 *   RV3_REV=<commit> php scripts/build_rv3_package.php out/   -> out/reports_v3_production_deploy_package.tar.gz  (+ prints its SHA256)
 *
 * The package carries: payload/ (the files), scripts/ (engine + wrappers + read-only validators), tests/ (suites that proved it), README_DEPLOY.md, SHA256SUMS, manifest.json.
 */

require_once __DIR__ . '/rv3/rv3_lib.php';

$repo = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --show-toplevel'));
if ($repo === '' || !is_dir("{$repo}/.git")) {
    rv3_die('run inside the repository');
}
chdir($repo);
$rev = getenv('RV3_REV') ?: 'HEAD';
$revFull = trim((string) shell_exec('git rev-parse ' . escapeshellarg($rev)));
$out = $argv[1] ?? rv3_die('usage: php scripts/build_rv3_package.php <output dir>');
if (trim((string) shell_exec('git status --porcelain -- public services scripts/rv3 tests')) !== '' && $rev === 'HEAD') {
    fwrite(STDERR, "WARNING: uncommitted changes exist; the package is built from the COMMITTED files at {$revFull}, not from the working tree.\n");
}
$show = static function (string $path) use ($revFull): string {
    $d = shell_exec('git show ' . escapeshellarg("{$revFull}:{$path}"));
    if ($d === null || $d === '') {
        rv3_die("cannot read {$path} at {$revFull}");
    }
    return $d;
};
/** every version of the file that ever existed in the project history (sha256 of the content) — the "known earlier versions" a replace may start from */
$history = static function (string $path) use ($revFull): array {
    $hashes = [];                                    // sha256 => "commit date subject" (the newest commit that produced exactly that content)
    foreach (explode("\n", trim((string) shell_exec('git rev-list ' . escapeshellarg($revFull) . ' -- ' . escapeshellarg($path)))) as $c) {
        if ($c === '') {
            continue;
        }
        $d = shell_exec('git show ' . escapeshellarg("{$c}:{$path}") . ' 2>/dev/null');
        if ($d !== null && $d !== '') {
            $hashes[rv3_sha($d)] ??= trim((string) shell_exec('git log -1 --format="%h (%cs) %s" ' . escapeshellarg($c)));
        }
    }
    return $hashes;
};

// RV3_MODE=ui builds the FRONTEND-ONLY incremental package (production UI correction): the six report pages, the CSS blocks, app.js routes / labels and index.html. The validated backend
// (services/*.php + the one route include in index.php) is NOT shipped; it is a GATE — if it is not installed exactly as validated, the plan is BLOCKED and nothing is written.
$modeEnv = getenv('RV3_MODE') ?: 'full';
$dash = $modeEnv === 'dash';                       // dashboard-only package: DashboardInventoryService + dashboard.js + one CSS block, behind the same backend gates
$ui = $modeEnv === 'ui' || $dash;                  // gated (does not ship the Reports v3 backend)
$token = '20261020-dmv';
$name = ($dash ? 'dashboard_movement_' : ($ui ? 'reports_v3_ui_' : 'reports_v3_')) . substr($revFull, 0, 10);
$pkgName = $dash ? 'dashboard_movement_correction_package' : ($ui ? 'reports_v3_ui_correction_package' : 'reports_v3_recovery_package');
$tmp = sys_get_temp_dir() . '/rv3_build_' . bin2hex(random_bytes(4));
$R = "{$tmp}/{$pkgName}";
foreach (array_merge($ui && !$dash ? [] : ['payload/services'], ['payload/public/assets/js', 'payload/css', 'scripts', 'tests/browser/lib', 'tests/lib', 'state']) as $d) {
    mkdir("{$R}/{$d}", 0755, true);
}
$put = static function (string $rel, string $data) use ($R): void {
    if (!is_dir(dirname("{$R}/{$rel}"))) {
        mkdir(dirname("{$R}/{$rel}"), 0755, true);
    }
    file_put_contents("{$R}/{$rel}", $data);
};
$ops = [];
$sha = static fn (string $s): string => rv3_sha($s);

// ---------------------------------------------------------------- whole files
$backendFiles = [
    'services/ReportExportService.php', 'services/MovementReportV3Service.php', 'services/MovementDailyReportService.php', 'services/ReportsV3Routes.php',
    'services/PurchaseReportService.php', 'services/InOutReportService.php', 'services/InventoryValuationService.php', 'services/StockOpnameAuditReportService.php',
];
$files = [
    'public/assets/js/report-tools.js', 'public/assets/js/report-pergerakan.js', 'public/assets/js/report-pembelian-v3.js', 'public/assets/js/report-nilai-hpp-v3.js',
    'public/assets/js/report-inout-v3.js', 'public/assets/js/report-opname-audit.js',
];
if ($dash) {
    $files = ['services/DashboardInventoryService.php', 'public/assets/js/dashboard.js'];
}
if (!$ui) {
    $files = array_merge($backendFiles, $files);
} else {
    foreach ($backendFiles as $f) {                                  // GATE: the validated backend must already be installed (never written by this package)
        $ops[] = ['id' => 'gate:' . basename($f), 'type' => 'require_file', 'target' => $f, 'accept' => [$sha($show($f))]];
    }
}
foreach ($files as $f) {
    $d = $show($f);
    $put("payload/{$f}", $d);
    $h = $sha($d);
    $hist = $history($f);
    unset($hist[$h]);
    $ops[] = ['id' => 'file:' . basename($f), 'type' => 'file', 'target' => $f, 'payload' => $f, 'sha256' => $h, 'known' => array_keys($hist), 'known_info' => $hist];
}

// ---------------------------------------------------------------- css blocks (extracted by marker from the committed app.css)
$css = $show('public/assets/css/app.css');
foreach (['pur' => 'RV3 BLOCK pur 20261008', 'val' => 'RV3 BLOCK val 20261008', 'io' => 'RV3 BLOCK io 20261008', 'rv3' => 'RV3 REPORT FAMILY 20261008', 'soa3' => 'SOA3 AUDIT UI 20261008', 'uic' => 'RV3 UI CORRECTION 20261019', 'dashmv' => 'RV3 DASHBOARD MOVEMENT 20261020'] as $k => $label) {
    if ($dash ? $k !== 'dashmv' : $k === 'dashmv') {
        continue;
    }
    $b = "/* ===== {$label} BEGIN =====";
    $e = "/* ===== {$label} END ===== */";
    if (substr_count($css, $b) !== 1 || substr_count($css, $e) !== 1) {
        rv3_die("css markers for {$k} not found exactly once");
    }
    $p0 = strpos($css, $b);
    $p1 = strpos($css, $e) + strlen($e);
    $block = substr($css, $p0, $p1 - $p0);
    $put("payload/css/{$k}.css", $block . "\n");
    $ops[] = ['id' => "css:{$k}", 'type' => 'css_block', 'target' => 'public/assets/css/app.css', 'payload' => "css/{$k}.css", 'sha256' => $sha($block . "\n"), 'begin' => $b, 'end' => $e];
}

// ---------------------------------------------------------------- app.js routes (statements copied from the committed app.js)
$appjs = $show('public/assets/js/app.js');
foreach ($dash ? [] : [['laporan-pergerakan', false], ['laporan-pembelian', false], ['laporan-hpp', false], ['laporan-inout', false], ['laporan-transfer', true], ['laporan-opname', false], ['opname-laporan', true]] as [$tab, $optional]) {
    if (!preg_match('/\b[A-Za-z_]\w*\.render\(document\.getElementById\(\'tab-' . preg_quote($tab, '/') . '\'\)(?:,\s*\{[^}]*\})?\);/', $appjs, $m)) {
        rv3_die("app.js statement for {$tab} not found");
    }
    $ops[] = ['id' => "app.js:{$tab}", 'type' => 'app_route', 'target' => 'public/assets/js/app.js', 'tab' => $tab, 'statement' => $m[0], 'optional' => $optional];
}

// breadcrumb / title labels of the five reports (app.js label map): the Nilai HPP report is called exactly "Laporan Nilai HPP"
foreach ($dash ? [] : ['laporan-pergerakan' => 'Laporan Pergerakan Stok', 'laporan-inout' => 'Laporan IN / OUT', 'laporan-pembelian' => 'Laporan Pembelian', 'laporan-hpp' => 'Laporan Nilai HPP', 'laporan-opname' => 'Laporan Stock Opname'] as $tab => $label) {
    if (!preg_match('/\'' . preg_quote($tab, '/') . '\'\s*:\s*\'' . preg_quote($label, '/') . '\'/', $appjs)) {
        rv3_die("app.js label map: '{$tab}' must be '{$label}' in the committed app.js");
    }
    $ops[] = ['id' => "app.js:label:{$tab}", 'type' => 'app_label', 'target' => 'public/assets/js/app.js', 'tab' => $tab, 'label' => $label];
}

// ---------------------------------------------------------------- index.php: ONE include
$anchor = '// ---- dispatch: exact match first, then {param} patterns ----';
$phpText = "// >>> RV3 reports_v3 BEGIN — read-only report routes (keys defined there replace older routes of the same key). Remove this block to disable.\n"
    . "if (is_file(__DIR__ . '/../services/ReportsV3Routes.php')) {\n    \$routes = array_merge(\$routes, require __DIR__ . '/../services/ReportsV3Routes.php');\n}\n"
    . '// <<< RV3 reports_v3 END';
$idx = $show('public/index.php');
if (!str_contains($idx, $phpText) || substr_count($idx, $anchor) !== 1) {
    // the dev index.php carries the same include in its own form: the package block must be semantically the same one — fail loudly if they diverge
    if (!str_contains($idx, "require __DIR__ . '/../services/ReportsV3Routes.php'")) {
        rv3_die('dev index.php does not include ReportsV3Routes.php');
    }
}
if ($ui) {
    $ops[] = ['id' => 'gate:index.php:include', 'type' => 'require_marker', 'target' => 'public/index.php', 'marker' => '// >>> RV3 reports_v3 BEGIN'];
} else {
    $ops[] = ['id' => 'index.php:include', 'type' => 'php_include', 'target' => 'public/index.php', 'anchor' => $anchor, 'begin' => '// >>> RV3 reports_v3 BEGIN', 'end' => '// <<< RV3 reports_v3 END', 'text' => $phpText];
}

// ---------------------------------------------------------------- index.html
$html = $show('public/index.html');
$tags = [];
foreach (['report-tools.js', 'report-pergerakan.js', 'report-pembelian-v3.js', 'report-nilai-hpp-v3.js', 'report-inout-v3.js', 'report-opname-audit.js'] as $f) {
    if (preg_match_all(rv3_script_re($f), $html, $m) !== 1) {
        rv3_die("dev index.html must carry the {$f} tag exactly once");
    }
    preg_match('/\?v=([^"]+)"/', $m[0][0], $tm);
    $tags[] = ['file' => $f, 'token' => $tm[1]];
}
if ($dash) {
    // the dashboard package changes dashboard.js and app.css only: their cache-bust tokens (the dashboard script tag must already exist exactly once)
    $ops[] = ['id' => 'index.html:tokens', 'type' => 'html_tokens', 'target' => 'public/index.html', 'assets' => [['path' => 'assets/js/dashboard.js', 'token' => $token], ['path' => 'assets/css/app.css', 'token' => $token]]];
} else {
    $ops[] = ['id' => 'index.html:scripts', 'type' => 'html_scripts', 'target' => 'public/index.html', 'tags' => $tags];
    $ops[] = ['id' => 'index.html:tokens', 'type' => 'html_tokens', 'target' => 'public/index.html', 'assets' => [['path' => 'assets/js/app.js', 'token' => $token], ['path' => 'assets/css/app.css', 'token' => $token]]];
    // sidebar: the approved + hidden markup is parsed out of the committed dev index.html (rv3_sidebar_op_from_html — the same function the sidebar unit test uses)
    $ops[] = ['id' => 'index.html:sidebar', 'type' => 'html_sidebar', 'target' => 'public/index.html'] + rv3_sidebar_op_from_html($html);
}

// ---------------------------------------------------------------- requirements the payload needs from files this package does NOT ship
$requirements = [
    ['class' => 'App\Services\AuthService', 'method' => 'assertWarehouseScope'], ['class' => 'App\Services\ExcelWriterService', 'method' => 'sanitizeCellText'],
    ['class' => 'App\Services\MovementDailyReportService', 'method' => 'overview'], ['class' => 'App\Services\MovementDailyReportService', 'method' => 'detailRows'], ['class' => 'App\Services\MovementDailyReportService', 'method' => 'periodTransactions'],
    ['class' => 'App\Services\MovementDailyReportService', 'const' => 'SIGNED_QTY_SQL'], ['class' => 'App\Services\InventoryHppReportService', 'const' => 'SIGNED_VALUE_SQL'],
    ['class' => 'App\Services\InventoryHppReportService', 'method' => 'cutoverContext'], ['class' => 'App\Services\StockOpnameJejakService', 'method' => 'detail'],
    ['class' => 'App\Services\StockOpnamePhotoService', 'method' => 'absolutePath'], ['class' => 'App\Services\ValidationException'],
    ['class' => 'App\Services\ReportExportService', 'method' => 'streamXlsx'], ['class' => 'App\Services\MovementReportV3Service', 'method' => 'itemsPage'],
    ['class' => 'App\Services\StockOpnameAuditReportService', 'method' => 'build'], ['class' => 'App\Services\InOutReportService', 'method' => 'exportAll'], ['class' => 'App\Services\PurchaseReportService', 'method' => 'exportWorkbook'],
    ['class' => 'App\Services\InventoryValuationService', 'method' => 'exportWorkbook'],
];

// what the POST-DEPLOY validator asserts about the INSTALLED code: every V3 class resolves to <APP>/services/<file>, and the installed routes file defines every route key
$v3Classes = [];
foreach (array_merge($backendFiles) as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        $v3Classes['App\\Services\\' . basename($f, '.php')] = basename($f);
    }
}
if ($dash) {
    $v3Classes['App\\Services\\DashboardInventoryService'] = 'DashboardInventoryService.php';
}
$tmpRoutes = tempnam(sys_get_temp_dir(), 'rv3routes');
file_put_contents($tmpRoutes, $show('services/ReportsV3Routes.php'));
$routeKeys = json_decode((string) shell_exec('php -r ' . escapeshellarg('$pdo = new stdClass(); $query = []; $routes = []; echo json_encode(array_keys(require $argv[1]));') . ' ' . escapeshellarg($tmpRoutes)), true);
@unlink($tmpRoutes);
if (!is_array($routeKeys) || count($routeKeys) < 10) {
    rv3_die('could not read the route keys of ReportsV3Routes.php');
}
$manifest = ['name' => $name, 'mode' => $dash ? 'dash' : ($ui ? 'ui' : 'full'), 'source_commit' => $revFull, 'built' => date('c'), 'token' => $token, 'requirements' => $requirements, 'v3_classes' => $v3Classes, 'route_keys' => $routeKeys, 'ops' => $ops];
$put('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// ---------------------------------------------------------------- scripts + tests
foreach (['rv3/rv3_lib.php' => 'scripts/rv3_lib.php', 'rv3/rv3_bootstrap.php' => 'scripts/rv3_bootstrap.php', 'rv3/rv3_engine.php' => 'scripts/rv3_engine.php', 'rv3/rv3_readonly_check.php' => 'scripts/rv3_readonly_check.php'] as $src => $dst) {
    $put($dst, $show("scripts/{$src}"));
}
foreach (['opname_audit_reconcile_check.php', 'movement_reconcile_check.php', 'inout_reconcile_check.php', 'purchase_reconcile_check.php', 'valuation_reconcile_check.php', 'rv3/movement_v3_reconcile_check.php', 'rv3/dashboard_movement_reconcile_check.php'] as $f) {
    $put('scripts/' . basename($f), $show("scripts/{$f}"));
}
$wrap = static function (string $title, string $body): string {
    return "#!/usr/bin/env bash\n# {$title}\nset -eu\nHERE=\"\$(cd \"\$(dirname \"\$0\")\" && pwd)\"\nPHP=\"\${PHP_BIN:-php}\"\nexport PHP_BIN=\"\$PHP\"\n" . $body;
};
$put('scripts/preflight.sh', $wrap('PRE-FLIGHT (read-only): environment, package integrity, dependency self-check, state of every file.', "APP=\"\${1:?usage: preflight.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" preflight --app-root=\"\$APP\"\n"));
$put('scripts/dryrun.sh', $wrap('DRY-RUN (read-only for the application): writes state/plan.json + state/dryrun_report.txt inside the package folder.', "APP=\"\${1:?usage: dryrun.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" plan --app-root=\"\$APP\"\n"));
$put('scripts/apply.sh', $wrap('APPLY (writes). Needs a fresh dry-run plan; add --yes to really apply.', "APP=\"\${1:?usage: apply.sh <APP ROOT> [--yes]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" apply --app-root=\"\$APP\" \"\$@\"\n"));
$put('scripts/verify.sh', $wrap('POST-APPLY VERIFY (read-only).', "APP=\"\${1:?usage: verify.sh <APP ROOT> [--base-url=https://host]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" verify --app-root=\"\$APP\" \"\$@\"\n"));
$put('scripts/dashboard_before_after.sh', $wrap('DASHBOARD BEFORE / AFTER (read-only, real data): the INSTALLED dashboard service (BEFORE: what the dashboard shows now) vs the PACKAGE dashboard service (AFTER) vs Laporan Pergerakan Stok, for every warehouse and period.', "APP=\"\${1:?usage: dashboard_before_after.sh <APP ROOT> [--custom=YYYY-MM-DD:YYYY-MM-DD]}\"; shift\necho '################ BEFORE (installed dashboard) ################'\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/dashboard_movement_reconcile_check.php\" --app-root=\"\$APP\" --label=BEFORE \"\$@\" || true\necho\necho '################ AFTER (package dashboard) ################'\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/dashboard_movement_reconcile_check.php\" --app-root=\"\$APP\" --package-dir=\"\$(dirname \"\$HERE\")\" --label=AFTER \"\$@\"\n"));
$put('scripts/rollback.sh', $wrap('ROLLBACK (writes): two-phase, restores the exact pre-apply files.', "APP=\"\${1:?usage: rollback.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" rollback --app-root=\"\$APP\"\n"));
$put('scripts/predeploy_validate.sh', $wrap('PRE-DEPLOY READ-ONLY VALIDATOR (candidate code = the package payload, loaded IN MEMORY): proves the package works against the REAL database. It does NOT prove anything is installed.', "APP=\"\${1:?usage: predeploy_validate.sh <APP ROOT> [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_readonly_check.php\" --app-root=\"\$APP\" --mode=predeploy --package-dir=\"\$(dirname \"\$HERE\")\" \"\$@\"\n"));
$put('scripts/installed_verify.sh', $wrap('POST-DEPLOY INSTALLED VERIFICATION (read-only): (1) every packaged file exists IN THE APPLICATION with the packaged sha256, index.php carries the marker; (2) every V3 class is loaded from <APP>/services (Reflection) and never from the package; (3) all reports reconcile using ONLY the installed code. Fails if any backend file is absent.', "APP=\"\${1:?usage: installed_verify.sh <APP ROOT> [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--base-url=https://host]}\"; shift\nBASE=\"\"; REST=()\nfor a in \"\$@\"; do case \"\$a\" in --base-url=*) BASE=\"\$a\";; *) REST+=(\"\$a\");; esac; done\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" verify --app-root=\"\$APP\" \${BASE:+\"\$BASE\"} || { echo \"INSTALLED VERIFICATION FAILED (engine verify)\"; exit 1; }\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_readonly_check.php\" --app-root=\"\$APP\" --mode=installed \"\${REST[@]+\"\${REST[@]}\"}\"\n"));
foreach (['movement_report_v3_test.php', 'inout_report_test.php', 'inventory_valuation_test.php', 'purchase_report_test.php', 'stock_opname_audit_report_test.php', 'report_export_test.php', 'numeric_unit_code_test.php', 'rv3_sidebar_op_test.php', 'inventory_dashboard_test.php', 'rv3_package_rehearsal.sh', 'rv3_dash_package_rehearsal.sh'] as $t) {
    $put("tests/{$t}", $show("tests/{$t}"));
}
foreach (['lib/rv3.mjs'] as $t) {
    $put("tests/browser/{$t}", $show("tests/browser/{$t}"));
}
foreach (['playwright_pergerakan_v3.mjs', 'playwright_inout_report.mjs', 'playwright_purchase_report.mjs', 'playwright_valuation_report.mjs', 'playwright_so_audit_v3.mjs', 'playwright_sidebar_reports.mjs', 'playwright_dashboard_real_data.mjs', 'seed_dashboard_real.php', 'seed_valuation_report.php', 'seed_inout_report.php', 'seed_purchase_report.php', 'seed_so_audit_v3.php'] as $t) {
    $put("tests/browser/{$t}", $show("tests/browser/{$t}"));
}
foreach (['xlsx_dump.php', 'valuation_fixture.php', 'inout_report_fixture.php', 'purchase_report_fixture.php', 'dashboard_fixture.php', 'jejak_real_fixture.php'] as $t) {
    $put("tests/lib/{$t}", $show("tests/lib/{$t}"));
}

// ---------------------------------------------------------------- README
$tpl = $show($dash ? 'scripts/rv3/README_DASH.md.tpl' : ($ui ? 'scripts/rv3/README_UI.md.tpl' : 'scripts/rv3/README_DEPLOY.md.tpl'));
$rows = '';
foreach ($ops as $o) {
    if ($o['type'] === 'file') {
        $rows .= "| `{$o['target']}` | " . substr($o['sha256'], 0, 16) . "… | " . count($o['known']) . " earlier version(s) accepted |\n";
    }
}
$put('README_DEPLOY.md', strtr($tpl, ['@@NAME@@' => $name, '@@COMMIT@@' => $revFull, '@@FILES@@' => $rows, '@@TOKEN@@' => $token]));

// ---------------------------------------------------------------- shared hosting: many functions are REMOVED there (symlink, link, readlink, escapeshellarg, escapeshellcmd, exec, shell_exec, proc_open, popen, passthru, system …)
// Nothing that ships (scripts / payload; the dev tests/ are not run on the server) may call any of them. Method calls such as $pdo->exec() are fine: PDO is not a shell.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($R, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile() && preg_match('/\.(php|sh)$/', $f->getFilename()) && !str_contains($f->getPathname(), '/tests/')) {
        $src = preg_replace(['#/\*.*?\*/#s', '#^\s*(//|\#).*$#m'], '', (string) file_get_contents($f->getPathname()));
        if (str_ends_with($f->getFilename(), '.php')) {
            $src = preg_replace('/(["\'])(?:\\\\.|(?!\1).)*\1/s', '""', $src);          // string literals are not calls
            if (preg_match('/(?<![A-Za-z0-9_>:$])(symlink|link|readlink|linkinfo|escapeshellarg|escapeshellcmd|exec|shell_exec|proc_open|popen|passthru|system|pcntl_exec)\s*\(/', $src, $m)) {
                rv3_die("forbidden runtime call '{$m[1]}(' in " . substr($f->getPathname(), strlen($R) + 1));
            }
        } elseif (preg_match('/\bln\s+-s/', $src)) {
            rv3_die('forbidden "ln -s" in ' . substr($f->getPathname(), strlen($R) + 1));
        }
    }
}
// ---------------------------------------------------------------- SHA256SUMS + tarball
$lines = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($R, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getFilename() !== 'SHA256SUMS') {
        $lines[substr($f->getPathname(), strlen($R) + 1)] = rv3_sha((string) file_get_contents($f->getPathname()));
    }
}
ksort($lines);
$sums = '';
foreach ($lines as $p => $h) {
    $sums .= "{$h}  {$p}\n";
}
file_put_contents("{$R}/SHA256SUMS", $sums);
@mkdir($out, 0755, true);
$tar = rtrim($out, '/') . "/{$pkgName}.tar.gz";
@unlink($tar);
passthru('tar -C ' . escapeshellarg($tmp) . ' --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner -czf ' . escapeshellarg($tar) . ' ' . escapeshellarg($pkgName), $rc);
if ($rc !== 0) {
    rv3_die('tar failed');
}
echo "built {$tar}\n  from commit {$revFull}\n";
echo '  sha256 ' . hash_file('sha256', $tar) . "\n";
rv3_rmtree_build($tmp);

function rv3_rmtree_build(string $d): void
{
    if (!str_contains($d, 'rv3_build_')) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}
