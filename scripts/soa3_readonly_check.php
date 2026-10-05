<?php
declare(strict_types=1);

/**
 * READ-ONLY FILE check for the Laporan Stock Opname audit redesign package (never needs the database, never writes):
 *
 *   php scripts/soa3_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *
 * After applying: the six targets carry the new markers exactly once, the existing Stock Opname report routes (and the Jejak drawer script reference) are still
 * there exactly once, the app.js route points at the new page, PHP files lint, the CSS braces balance, index.html carries the token on the three tags and every state file exists.
 * The DATABASE check is scripts/opname_audit_reconcile_check.php (also in this package).
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
    fwrite(STDERR, "usage: php scripts/soa3_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files\n");
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
$js = $read("{$public}/assets/js/stock-opname-report.js");
$svc = $read("{$services}/StockOpnameAuditReportService.php");

$check('index.php: StockOpnameAuditReportService required once, the three helpers once each', $once($php, "services/StockOpnameAuditReportService.php';") && $once($php, 'function inv_soa_filters(') && $once($php, 'function inv_soa_session_ids(') && $once($php, 'function inv_soa_line_filters('));
$newRoutes = ['sessions', 'items', 'item-detail', 'photo/{id}', 'export'];
$check('index.php: the five new GET routes present once each', count(array_filter($newRoutes, static fn ($r) => $once($php, "'GET /reports/opname-audit/{$r}' =>"))) === 5);
$check('index.php: the existing Stock Opname report routes are still there once each (GET /reports/opname, /reports/opname/{id}, /reports/opname/{id}/jejak)', $once($php, "'GET /reports/opname' =>") && $once($php, "'GET /reports/opname/{id}' =>") && $once($php, "'GET /reports/opname/{id}/jejak' =>"));
$check('index.php: the SO scope helper keeps its docblock directly above it', str_contains($php, " * \$requestedWarehouseId (or lack thereof) they asked for, unchanged.\n */\nfunction inv_so_resolve_warehouse_scope("));
$check('index.php: no POST / PUT / DELETE route was added under /reports/opname-audit (read-only report)', !preg_match("/'(POST|PUT|PATCH|DELETE) \\/reports\\/opname-audit/", $php));
$check('index.php: the photo route only serves photos attached to a finding', str_contains($php, 'p.finding_id IS NOT NULL') && str_contains($php, "'GET /reports/opname-audit/photo/{id}'"));
$check('app.js: the Laporan Stock Opname route (tab-laporan-opname) now renders StockOpnameReport; the old ReportOpname.render line for it is gone; the opname-laporan route (if any) is unchanged', $once($appjs, "StockOpnameReport.render(document.getElementById('tab-laporan-opname'));") && !str_contains($appjs, "ReportOpname.render(document.getElementById('tab-laporan-opname'))"));
$check('index.html: the Jejak drawer script tag (stock-opname-report-jejak.js) is still there exactly once — Jejak is preserved', preg_match_all('#<script src="assets/js/stock-opname-report-jejak\\.js\\?v=[A-Za-z0-9._-]+"></script>#', $html) === 1);
$check('app.css: Laporan Stock Opname block present once', $once($css, '/* Laporan Stock Opname audit redesign (stock-opname-report.js') && str_contains($css, '.soa-kpi'));
$depth = 0; $min = 0;
foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
$check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
$tagOk = $once($html, 'app.css?v=20261017-soa3');
foreach (['app.js', 'stock-opname-report.js'] as $f) { $tagOk = $tagOk && $once($html, "assets/js/{$f}?v=20261017-soa3"); }
$check('index.html: app.css + app.js + stock-opname-report.js carry token 20261017-soa3 (once each)', $tagOk);
$check('stock-opname-report.js: the audit report (StockOpnameReport, soa- classes, uses InvApi.opnameAuditSessions)', str_contains($js, 'const StockOpnameReport') && str_contains($js, 'soa-sessions-table') && str_contains($js, "'/reports/opname-audit/sessions'") && !str_contains($js, 'InvApi.'));
$check('stock-opname-report.js issues only GET requests (no POST/PUT/DELETE in the file)', !preg_match("/request\\('(POST|PUT|PATCH|DELETE)'/", $js) && !str_contains($js, "method: 'POST'"));
$check('StockOpnameAuditReportService.php present, read-only (no INSERT / UPDATE / DELETE statement)', str_contains($svc, 'final class StockOpnameAuditReportService') && str_contains($svc, 'function conditionAudit(') && str_contains($svc, '$qty * $cost,   // raw qty') && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z_]+\s+SET|DELETE\s+FROM)\b/i', preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $svc)));
foreach (["{$public}/index.php", "{$services}/StockOpnameAuditReportService.php"] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . basename($f), $rc === 0, $rc === 0 ? '' : implode(' ', $out));
    $out = [];
}
$targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/app.js", "{$public}/assets/js/stock-opname-report.js", "{$services}/StockOpnameAuditReportService.php"];
$missing = [];
foreach ($targets as $t) { $meta = is_file($t . '.soa3-patch.json') ? json_decode((string) file_get_contents($t . '.soa3-patch.json'), true) : null;
    if (!is_array($meta) || (empty($meta['new_file']) && ($meta['kind'] ?? '') !== 'verified_existing' && !is_file($t . '.pre-soa3-backup'))) { $missing[] = basename($t); } }
$check('state files beside all 6 targets (a backup for every file the package changed or replaced; none for files it created or only verified)', !$missing, implode(',', $missing));
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
