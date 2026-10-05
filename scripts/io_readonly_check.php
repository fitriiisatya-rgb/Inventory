<?php
declare(strict_types=1);

/**
 * READ-ONLY FILE check for the Laporan IN / OUT / Transfer package (never needs the database, never writes):
 *
 *   php scripts/io_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *
 * After applying: the six targets carry the new markers exactly once, the existing /reports/in-out* and /reports/transfer routes (and the old report-inout.js / report-transfer.js tags) are
 * still there, the two app.js routes point at the new page, PHP files lint, the CSS braces balance, index.html carries the token on the three tags and every state file exists.
 * The DATABASE check is scripts/inout_reconcile_check.php (also in this package). Exit code 1 if any check fails.
 */

$public = $services = null;
$files = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-dir=')) { $public = rtrim(substr($arg, 13), '/'); }
    elseif (str_starts_with($arg, '--services-dir=')) { $services = rtrim(substr($arg, 15), '/'); }
    elseif ($arg === '--files') { $files = true; }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
if (!$files || $public === null || $services === null) {
    fwrite(STDERR, "usage: php scripts/io_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files\n");
    exit(2);
}
$fail = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$read = static fn (string $p): string => (string) @file_get_contents($p);
$once = static fn (string $hay, string $needle): bool => substr_count($hay, $needle) === 1;
$php = $read("{$public}/index.php");
$css = $read("{$public}/assets/css/app.css");
$html = $read("{$public}/index.html");
$appjs = $read("{$public}/assets/js/app.js");
$js = $read("{$public}/assets/js/report-io.js");
$svc = $read("{$services}/InOutReportService.php");

$check('index.php: InOutReportService required once, the helper once', $once($php, "services/InOutReportService.php';") && $once($php, 'function inv_io_filters('));
$check('index.php: the five new GET routes present once each', count(array_filter(['options', 'overview', 'list', 'detail', 'export'], static fn ($r) => $once($php, "'GET /reports/io/{$r}' =>"))) === 5);
$check('index.php: the existing routes are still there once each (/reports/in-out, /reports/in-out/summary, /reports/transfer, /reports/distribution/bakery)', count(array_filter(["'GET /reports/in-out'", "'GET /reports/in-out/summary'", "'GET /reports/transfer'", "'GET /reports/distribution/bakery'"], static fn ($r) => $once($php, "{$r} =>"))) === 4);
$check('index.php: the PHASE V2.6C docblock is intact directly below the new helper', str_contains($php, "}\n\n/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok."));
$check('index.php: no POST / PUT / DELETE route was added under /reports/io (read-only report)', !preg_match("/'(POST|PUT|PATCH|DELETE) \\/reports\\/io/", $php));
$check('app.js: the two routes (tab-laporan-inout / tab-laporan-transfer) now render ReportIO; the old ReportInOut / ReportTransferList lines for them are gone',
    $once($appjs, "ReportIO.render(document.getElementById('tab-laporan-inout'), { tab: 'in' });") && $once($appjs, "ReportIO.render(document.getElementById('tab-laporan-transfer'), { tab: 'transfer' });")
    && !str_contains($appjs, "ReportInOut.render(document.getElementById('tab-laporan-inout'))") && !str_contains($appjs, "ReportTransferList.render(document.getElementById('tab-laporan-transfer'))"));
$check('index.html: the old report-inout.js tag is still there exactly once (kept for rollback / its endpoints)', preg_match_all('#<script src="assets/js/report-inout\.js\?v=[A-Za-z0-9._-]+"></script>#', $html) === 1);
$check('app.css: Laporan IN / OUT / Transfer block present once', $once($css, '/* Laporan IN / OUT / Transfer (report-io.js)') && str_contains($css, '.io-kpi'));
$depth = 0; $min = 0;
foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
$check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
$tagOk = $once($html, 'app.css?v=20261017-io');
foreach (['app.js', 'report-io.js'] as $f) { $tagOk = $tagOk && $once($html, "assets/js/{$f}?v=20261017-io"); }
$check('index.html: app.css + app.js + report-io.js carry token 20261017-io (once each)', $tagOk);
$check('report-io.js: the three-tab page (ReportIO, io- classes, own read-only GET helper, no InvApi)', str_contains($js, 'const ReportIO') && str_contains($js, 'io-table') && str_contains($js, "'/reports/io/overview'") && !str_contains($js, 'InvApi.'));
$check('report-io.js issues only GET requests (no POST/PUT/DELETE)', !preg_match("/method: '(POST|PUT|PATCH|DELETE)'/", $js) && !preg_match("/request\\('(POST|PUT|PATCH|DELETE)'/", $js));
$check('InOutReportService.php present, read-only (no INSERT / UPDATE / DELETE statement)', str_contains($svc, 'final class InOutReportService') && !preg_match('/\\b(INSERT\\s+INTO|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM)\\b/i', preg_replace(['#/\\*.*?\\*/#s', '#^\\s*//.*$#m'], '', $svc)));
foreach (["{$public}/index.php", "{$services}/InOutReportService.php"] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . basename($f), $rc === 0, $rc === 0 ? '' : implode(' ', $out));
    $out = [];
}
$targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/app.js", "{$public}/assets/js/report-io.js", "{$services}/InOutReportService.php"];
$missing = [];
foreach ($targets as $t) {
    $meta = is_file($t . '.io-patch.json') ? json_decode((string) file_get_contents($t . '.io-patch.json'), true) : null;
    if (!is_array($meta) || (empty($meta['new_file']) && !is_file($t . '.pre-io-backup'))) { $missing[] = basename($t); }
}
$check('state files beside all 6 targets (a backup for every file the package changed; none for the files it created)', !$missing, implode(',', $missing));
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
