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
    $hashes = [];
    foreach (explode("\n", trim((string) shell_exec('git rev-list ' . escapeshellarg($revFull) . ' -- ' . escapeshellarg($path)))) as $c) {
        if ($c === '') {
            continue;
        }
        $d = shell_exec('git show ' . escapeshellarg("{$c}:{$path}") . ' 2>/dev/null');
        if ($d !== null && $d !== '') {
            $hashes[rv3_sha($d)] = true;
        }
    }
    return array_keys($hashes);
};

$token = '20261018-rv3';
$name = 'reports_v3_' . substr($revFull, 0, 10);
$pkgName = 'reports_v3_production_deploy_package';
$tmp = sys_get_temp_dir() . '/rv3_build_' . bin2hex(random_bytes(4));
$R = "{$tmp}/{$pkgName}";
foreach (['payload/services', 'payload/public/assets/js', 'payload/css', 'scripts', 'tests/browser/lib', 'tests/lib', 'state'] as $d) {
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
$files = [
    'services/ReportExportService.php', 'services/MovementReportV3Service.php', 'services/ReportsV3Routes.php',
    'services/PurchaseReportService.php', 'services/InOutReportService.php', 'services/InventoryValuationService.php', 'services/StockOpnameAuditReportService.php',
    'public/assets/js/report-tools.js', 'public/assets/js/report-pergerakan.js', 'public/assets/js/report-pembelian-v3.js', 'public/assets/js/report-nilai-hpp-v3.js',
    'public/assets/js/report-inout-v3.js', 'public/assets/js/report-opname-audit.js',
];
foreach ($files as $f) {
    $d = $show($f);
    $put("payload/{$f}", $d);
    $h = $sha($d);
    $ops[] = ['id' => 'file:' . basename($f), 'type' => 'file', 'target' => $f, 'payload' => $f, 'sha256' => $h, 'known' => array_values(array_diff($history($f), [$h]))];
}

// ---------------------------------------------------------------- css blocks (extracted by marker from the committed app.css)
$css = $show('public/assets/css/app.css');
foreach (['pur' => 'RV3 BLOCK pur 20261008', 'val' => 'RV3 BLOCK val 20261008', 'io' => 'RV3 BLOCK io 20261008', 'rv3' => 'RV3 REPORT FAMILY 20261008', 'soa3' => 'SOA3 AUDIT UI 20261008'] as $k => $label) {
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
foreach ([['laporan-pergerakan', false], ['laporan-pembelian', false], ['laporan-hpp', false], ['laporan-inout', false], ['laporan-transfer', true], ['laporan-opname', false], ['opname-laporan', true]] as [$tab, $optional]) {
    if (!preg_match('/\b[A-Za-z_]\w*\.render\(document\.getElementById\(\'tab-' . preg_quote($tab, '/') . '\'\)(?:,\s*\{[^}]*\})?\);/', $appjs, $m)) {
        rv3_die("app.js statement for {$tab} not found");
    }
    $ops[] = ['id' => "app.js:{$tab}", 'type' => 'app_route', 'target' => 'public/assets/js/app.js', 'tab' => $tab, 'statement' => $m[0], 'optional' => $optional];
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
$ops[] = ['id' => 'index.php:include', 'type' => 'php_include', 'target' => 'public/index.php', 'anchor' => $anchor, 'begin' => '// >>> RV3 reports_v3 BEGIN', 'end' => '// <<< RV3 reports_v3 END', 'text' => $phpText];

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
$ops[] = ['id' => 'index.html:scripts', 'type' => 'html_scripts', 'target' => 'public/index.html', 'tags' => $tags];
$ops[] = ['id' => 'index.html:tokens', 'type' => 'html_tokens', 'target' => 'public/index.html', 'assets' => [['path' => 'assets/js/app.js', 'token' => $token], ['path' => 'assets/css/app.css', 'token' => $token]]];
// sidebar: parse the approved + hidden markup out of the committed dev index.html
preg_match('#<div class="sidebar-group[^"]*"\s+data-group="laporan">#', $html, $gm, PREG_OFFSET_CAPTURE);
$gSpan = rv3_div_span($html, $gm[0][1]);
preg_match('#<div class="sidebar-submenu"[^>]*>#', substr($html, $gm[0][1], $gSpan[1] - $gm[0][1]), $sm, PREG_OFFSET_CAPTURE);
$sStart = $gm[0][1] + $sm[0][1];
$sSpan = rv3_div_span($html, $sStart);
$approved = rv3_anchors(substr($html, $sStart, $sSpan[1] - $sStart));
$cPos = strpos($html, '<div class="sidebar-legacy-routes"');
$cSpan = rv3_div_span($html, $cPos);
$legacy = rv3_anchors(substr($html, $cPos, $cSpan[1] - $cPos));
if (array_column($approved, 'tab') !== ['laporan-pergerakan', 'laporan-inout', 'laporan-pembelian', 'laporan-hpp', 'laporan-opname']) {
    rv3_die('dev index.html: the Laporan submenu is not the five approved reports in order: ' . implode(',', array_column($approved, 'tab')));
}
$cm0 = strrpos(substr($html, 0, $cPos), '<!-- Sidebar cleanup');
$legacyComment = $cm0 === false ? '' : trim(substr($html, $cm0, $cPos - $cm0));
preg_match('#<!-- "Laporan Stock Opname" \(data-tab opname-laporan\).*?-->#s', $html, $oc);
$ops[] = ['id' => 'index.html:sidebar', 'type' => 'html_sidebar', 'target' => 'public/index.html', 'approved' => $approved, 'legacy' => $legacy, 'legacy_comment_block' => $legacyComment,
    'opname_comment' => $oc[0] ?? '<!-- "Laporan Stock Opname" now lives in the Laporan menu. -->'];

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

$manifest = ['name' => $name, 'source_commit' => $revFull, 'built' => date('c'), 'token' => $token, 'requirements' => $requirements, 'ops' => $ops];
$put('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// ---------------------------------------------------------------- scripts + tests
foreach (['rv3/rv3_lib.php' => 'scripts/rv3_lib.php', 'rv3/rv3_engine.php' => 'scripts/rv3_engine.php', 'rv3/rv3_readonly_check.php' => 'scripts/rv3_readonly_check.php'] as $src => $dst) {
    $put($dst, $show("scripts/{$src}"));
}
foreach (['opname_audit_reconcile_check.php', 'movement_reconcile_check.php', 'inout_reconcile_check.php', 'purchase_reconcile_check.php', 'valuation_reconcile_check.php', 'rv3/movement_v3_reconcile_check.php'] as $f) {
    $put('scripts/' . basename($f), $show("scripts/{$f}"));
}
$wrap = static function (string $title, string $body): string {
    return "#!/usr/bin/env bash\n# {$title}\nset -eu\nHERE=\"\$(cd \"\$(dirname \"\$0\")\" && pwd)\"\nPHP=\"\${PHP_BIN:-php}\"\nexport PHP_BIN=\"\$PHP\"\n" . $body;
};
$put('scripts/preflight.sh', $wrap('PRE-FLIGHT (read-only): environment, package integrity, dependency self-check, state of every file.', "APP=\"\${1:?usage: preflight.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" preflight --app-root=\"\$APP\"\n"));
$put('scripts/dryrun.sh', $wrap('DRY-RUN (read-only for the application): writes state/plan.json + state/dryrun_report.txt inside the package folder.', "APP=\"\${1:?usage: dryrun.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" plan --app-root=\"\$APP\"\n"));
$put('scripts/apply.sh', $wrap('APPLY (writes). Needs a fresh dry-run plan; add --yes to really apply.', "APP=\"\${1:?usage: apply.sh <APP ROOT> [--yes]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" apply --app-root=\"\$APP\" \"\$@\"\n"));
$put('scripts/verify.sh', $wrap('POST-APPLY VERIFY (read-only).', "APP=\"\${1:?usage: verify.sh <APP ROOT> [--base-url=https://host]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" verify --app-root=\"\$APP\" \"\$@\"\n"));
$put('scripts/rollback.sh', $wrap('ROLLBACK (writes): two-phase, restores the exact pre-apply files.', "APP=\"\${1:?usage: rollback.sh <APP ROOT>}\"\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_engine.php\" rollback --app-root=\"\$APP\"\n"));
$put('scripts/readonly_validate.sh', $wrap('PRODUCTION READ-ONLY VALIDATOR: SO sessions 11/12 reconciliation (16/16) + the five reports against the REAL data, with the NEW code. SELECT only.', "APP=\"\${1:?usage: readonly_validate.sh <APP ROOT> [--session=11,12] [--start=YYYY-MM-DD --end=YYYY-MM-DD]}\"; shift\n\"\$PHP\" \${PHP_ARGS:-} \"\$HERE/rv3_readonly_check.php\" --app-root=\"\$APP\" --package-dir=\"\$HERE/..\" \"\$@\"\n"));
foreach (['movement_report_v3_test.php', 'inout_report_test.php', 'inventory_valuation_test.php', 'purchase_report_test.php', 'stock_opname_audit_report_test.php', 'report_export_test.php', 'rv3_package_rehearsal.sh'] as $t) {
    $put("tests/{$t}", $show("tests/{$t}"));
}
foreach (['lib/rv3.mjs'] as $t) {
    $put("tests/browser/{$t}", $show("tests/browser/{$t}"));
}
foreach (['playwright_pergerakan_v3.mjs', 'playwright_inout_report.mjs', 'playwright_purchase_report.mjs', 'playwright_valuation_report.mjs', 'playwright_so_audit_v3.mjs', 'seed_valuation_report.php', 'seed_inout_report.php', 'seed_purchase_report.php', 'seed_so_audit_v3.php'] as $t) {
    $put("tests/browser/{$t}", $show("tests/browser/{$t}"));
}
foreach (['xlsx_dump.php', 'valuation_fixture.php', 'inout_report_fixture.php', 'purchase_report_fixture.php', 'dashboard_fixture.php', 'jejak_real_fixture.php'] as $t) {
    $put("tests/lib/{$t}", $show("tests/lib/{$t}"));
}

// ---------------------------------------------------------------- README
$tpl = $show('scripts/rv3/README_DEPLOY.md.tpl');
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
