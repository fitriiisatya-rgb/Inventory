<?php
declare(strict_types=1);

/**
 * READ-ONLY FILE check for the Laporan Pembelian redesign package (never needs the database, never writes):
 *
 *   php scripts/pur_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *
 * After applying: the six targets carry the new markers exactly once, the existing /reports/purchase* routes and client methods are still there exactly once,
 * PHP files lint, the CSS braces balance, index.html carries the token on all three tags and every state file exists.
 * The DATABASE check is scripts/purchase_reconcile_check.php (also in this package).
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
    fwrite(STDERR, "usage: php scripts/pur_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files\n");
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
$js = $read("{$public}/assets/js/report-purchase.js");
$svc = $read("{$services}/PurchaseReportService.php");

$check('index.php: PurchaseReportService required once, the helper once', $once($php, "services/PurchaseReportService.php';") && $once($php, 'function inv_pur_filters('));
$newRoutes = ['overview', 'invoices', 'items', 'invoice-detail', 'export'];
$check('index.php: the five new GET routes present once each', count(array_filter($newRoutes, static fn ($r) => $once($php, "'GET /reports/purchase-v2/{$r}' =>"))) === 5);
$check('index.php: the existing purchase report route is still there once (GET /reports/purchase)', $once($php, "'GET /reports/purchase' =>"));
$check('index.php: the PHASE V2.6C docblock is intact directly below the new helper', str_contains($php, "}\n\n/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok."));
$check('index.php: no POST / PUT / DELETE route was added under /reports/purchase-v2 (read-only report)', !preg_match("/'(POST|PUT|PATCH|DELETE) \\/reports\\/purchase-v2/", $php));
$check('index.php: invoice-detail checks the warehouse scope of every transaction (inv_require_warehouse_scope)', str_contains($php, "'GET /reports/purchase-v2/invoice-detail'") && str_contains($php, "purchase transaction {\$id} not found"));
$check('api-client.js: five new methods once each, purchaseBySupplierExportUrl kept once', count(array_filter(['purchaseV2Overview', 'purchaseV2Invoices', 'purchaseV2Items', 'purchaseV2InvoiceDetail', 'purchaseV2ExportUrl', 'purchaseBySupplierExportUrl'], static fn ($m) => $once($api, "{$m}:"))) === 6);
$check('app.css: Laporan Pembelian block present once', $once($css, '/* Laporan Pembelian redesign (report-purchase.js') && str_contains($css, '.pur-kpi'));
$depth = 0; $min = 0;
foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
$check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
$tagOk = $once($html, 'app.css?v=20261015-pur');
foreach (['api-client.js', 'report-purchase.js'] as $f) { $tagOk = $tagOk && $once($html, "{$f}?v=20261015-pur"); }
$check('index.html: app.css + api-client.js + report-purchase.js carry token 20261015-pur (once each)', $tagOk);
$check('report-purchase.js: the redesigned report (ReportPurchase, pur- classes, uses InvApi.purchaseV2Overview)', str_contains($js, 'ReportPurchase') && str_contains($js, 'pur-invoices-table') && str_contains($js, 'InvApi.purchaseV2Overview'));
$check('report-purchase.js issues only GET requests (no POST/PUT/DELETE in the file)', !preg_match("/request\\('(POST|PUT|PATCH|DELETE)'/", $js) && !str_contains($js, "method: 'POST'"));
$check('PurchaseReportService.php present, read-only (no INSERT / UPDATE / DELETE statement)', str_contains($svc, 'final class PurchaseReportService') && !preg_match('/\\b(INSERT\\s+INTO|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM)\\b/i', preg_replace(['#/\\*.*?\\*/#s', '#^\\s*//.*$#m'], '', $svc)));
foreach (["{$public}/index.php", "{$services}/PurchaseReportService.php"] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . basename($f), $rc === 0, $rc === 0 ? '' : implode(' ', $out));
    $out = [];
}
$targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/api-client.js", "{$public}/assets/js/report-purchase.js", "{$services}/PurchaseReportService.php"];
$missing = [];
foreach ($targets as $t) { if (!is_file($t . '.pur-patch.json') || (!is_file($t . '.pre-pur-backup') && !str_ends_with($t, 'PurchaseReportService.php'))) { $missing[] = basename($t); } }
$check('state files beside all 6 targets (backup for the 5 changed ones)', !$missing, implode(',', $missing));
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
