<?php
declare(strict_types=1);

/**
 * READ-ONLY FILE check for the Laporan Nilai Stok & HPP (dual valuation) package (never needs the database, never writes):
 *
 *   php scripts/val_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *
 * After applying: the six targets carry the new markers exactly once, the existing /reports/inventory-hpp/* routes (and the old report-hpp.js tag) are still there,
 * the app.js route points at the new page, PHP files lint, the CSS braces balance, index.html carries the token on the three tags and every state file exists.
 * The DATABASE check is scripts/valuation_reconcile_check.php (also in this package). Exit code 1 if any check fails.
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
    fwrite(STDERR, "usage: php scripts/val_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files\n");
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
$js = $read("{$public}/assets/js/report-valuation.js");
$svc = $read("{$services}/InventoryValuationService.php");

$check('index.php: InventoryValuationService required once, the helper once', $once($php, "services/InventoryValuationService.php';") && $once($php, 'function inv_val_filters('));
$check('index.php: the three new GET routes present once each', $once($php, "'GET /reports/inventory-valuation' =>") && $once($php, "'GET /reports/inventory-valuation/item' =>") && $once($php, "'GET /reports/inventory-valuation/export' =>"));
$check('index.php: the existing HPP report routes are still there once each (summary / warehouses / daily / day-detail / variance-bridge / export)', count(array_filter(['summary', 'warehouses', 'daily', 'day-detail', 'variance-bridge', 'export'], static fn ($r) => $once($php, "'GET /reports/inventory-hpp/{$r}' =>"))) === 6);
$check('index.php: the PHASE V2.6C docblock is intact directly below the new helper', str_contains($php, "}\n\n/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok."));
$check('index.php: no POST / PUT / DELETE route was added under /reports/inventory-valuation (read-only report)', !preg_match("/'(POST|PUT|PATCH|DELETE) \\/reports\\/inventory-valuation/", $php));
$check('app.js: the Laporan Nilai HPP route (tab-laporan-hpp) now renders ReportValuation; the old ReportHpp.render line for it is gone', $once($appjs, "ReportValuation.render(document.getElementById('tab-laporan-hpp'));") && !str_contains($appjs, "ReportHpp.render(document.getElementById('tab-laporan-hpp'))"));
$check('index.html: the old report-hpp.js tag is still there exactly once (kept for rollback / its endpoints)', preg_match_all('#<script src="assets/js/report-hpp\.js\?v=[A-Za-z0-9._-]+"></script>#', $html) === 1);
$check('app.css: Nilai Stok & HPP block present once', $once($css, '/* Nilai Stok & HPP dual valuation (report-valuation.js') && str_contains($css, '.val-kpi'));
$depth = 0; $min = 0;
foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
$check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
$tagOk = $once($html, 'app.css?v=20261016-val');
foreach (['app.js', 'report-valuation.js'] as $f) { $tagOk = $tagOk && $once($html, "assets/js/{$f}?v=20261016-val"); }
$check('index.html: app.css + app.js + report-valuation.js carry token 20261016-val (once each)', $tagOk);
$check('report-valuation.js: the dual-valuation page (ReportValuation, val- classes, own read-only GET helper, no InvApi)', str_contains($js, 'const ReportValuation') && str_contains($js, 'val-items-table') && str_contains($js, "'/reports/inventory-valuation'") && !str_contains($js, 'InvApi.'));
$check('report-valuation.js issues only GET requests (no POST/PUT/DELETE)', !preg_match("/method: '(POST|PUT|PATCH|DELETE)'/", $js) && !preg_match("/request\\('(POST|PUT|PATCH|DELETE)'/", $js));
$check('InventoryValuationService.php present, read-only (no INSERT / UPDATE / DELETE statement)', str_contains($svc, 'final class InventoryValuationService') && !preg_match('/\\b(INSERT\\s+INTO|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM)\\b/i', preg_replace(['#/\\*.*?\\*/#s', '#^\\s*//.*$#m'], '', $svc)));
foreach (["{$public}/index.php", "{$services}/InventoryValuationService.php"] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . basename($f), $rc === 0, $rc === 0 ? '' : implode(' ', $out));
    $out = [];
}
$targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/app.js", "{$public}/assets/js/report-valuation.js", "{$services}/InventoryValuationService.php"];
$missing = [];
foreach ($targets as $t) {
    $meta = is_file($t . '.val-patch.json') ? json_decode((string) file_get_contents($t . '.val-patch.json'), true) : null;
    if (!is_array($meta) || (empty($meta['new_file']) && !is_file($t . '.pre-val-backup'))) { $missing[] = basename($t); }
}
$check('state files beside all 6 targets (a backup for every file the package changed; none for the files it created)', !$missing, implode(',', $missing));
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
