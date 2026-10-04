<?php
declare(strict_types=1);

/**
 * READ-ONLY FILE check for the Pergerakan Stok Harian redesign package (never needs the database, never writes):
 *
 *   php scripts/mvr_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *
 * After applying: the six targets carry the new markers exactly once, the legacy /reports/movement/* routes and client methods are still
 * there exactly once, PHP files lint, the CSS braces balance, index.html carries the token on all three tags and every state file exists.
 * The DATABASE check is scripts/movement_reconcile_check.php (also in this package).
 * Exit code 1 if any check fails.
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
    fwrite(STDERR, "usage: php scripts/mvr_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files\n");
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
$api = $read("{$public}/assets/js/api-client.js");
$js = $read("{$public}/assets/js/report-movement.js");
$svc = $read("{$services}/MovementDailyReportService.php");

$check('index.php: MovementDailyReportService required once, helper once', $once($php, "services/MovementDailyReportService.php';") && $once($php, 'function inv_movement_params('));
$newRoutes = ['overview', 'day-items', 'item-trail', 'period-transactions', 'export'];
$check('index.php: the five new GET routes present once each', count(array_filter($newRoutes, static fn ($r) => $once($php, "'GET /reports/movement/{$r}' =>"))) === 5);
$legacy = ['daily', 'day-breakdown', 'day-transactions', 'historical-transactions'];
$check('index.php: the legacy /reports/movement/* routes + reconciliation/movement are still there once each', count(array_filter($legacy, static fn ($r) => $once($php, "'GET /reports/movement/{$r}' =>"))) === 4 && $once($php, "'GET /reports/reconciliation/movement' =>"));
$check('index.php: the scope helper keeps its docblock directly above it', str_contains($php, " * routes that all need it identically.\n */\nfunction inv_hpp_resolve_warehouse_scope("));
$check('index.php: no POST / PUT / DELETE route was added (read-only report)', !preg_match("/'(POST|PUT|PATCH|DELETE) \\/reports\\/movement/", $php));
$check('api-client.js: five new methods once each, legacy movement methods once each', count(array_filter(['movementOverview', 'movementDayItems', 'movementItemTrail', 'movementPeriodTransactions', 'movementExportUrl', 'movementHistoricalTransactions', 'movementDailyExportUrl'], static fn ($m) => $once($api, "{$m}:"))) === 7);
$check('app.css: Pergerakan block present once', $once($css, '/* Pergerakan Stok Harian redesign (report-movement.js') && str_contains($css, '.mvr-kpi'));
$depth = 0; $min = 0;
foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
$check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
$tagOk = $once($html, 'app.css?v=20261013-mvr');
foreach (['api-client.js', 'report-movement.js'] as $f) { $tagOk = $tagOk && $once($html, "{$f}?v=20261013-mvr"); }
$check('index.html: app.css + api-client.js + report-movement.js carry token 20261013-mvr (once each)', $tagOk);
$check('report-movement.js: new report (ReportMovement, mvr- classes, uses InvApi.movementOverview)', str_contains($js, 'const ReportMovement') && str_contains($js, 'mvr-daily-table') && str_contains($js, 'InvApi.movementOverview'));
$check('report-movement.js sends only GET requests (no POST/PUT/DELETE in the file)', !preg_match("/request\\('(POST|PUT|PATCH|DELETE)'/", $js) && !str_contains($js, "method: 'POST'"));
$check('MovementDailyReportService.php present, read-only (no INSERT / UPDATE / DELETE statement)', str_contains($svc, 'final class MovementDailyReportService') && !preg_match('/\\b(INSERT\\s+INTO|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM)\\b/i', $svc));
foreach (["{$public}/index.php", "{$services}/MovementDailyReportService.php"] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . basename($f), $rc === 0, $rc === 0 ? '' : implode(' ', $out));
    $out = [];
}
$targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/api-client.js", "{$public}/assets/js/report-movement.js", "{$services}/MovementDailyReportService.php"];
$missing = [];
foreach ($targets as $t) { if (!is_file($t . '.mvr-patch.json') || (!is_file($t . '.pre-mvr-backup') && !str_ends_with($t, 'MovementDailyReportService.php'))) { $missing[] = basename($t); } }
$check('state files beside all 6 targets (backup for the 5 changed ones)', !$missing, implode(',', $missing));
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
