<?php
declare(strict_types=1);

/**
 * READ-ONLY check for the UI2 package. Two modes in one script:
 *
 *   php scripts/ui2_readonly_check.php --app-root=<app dir with services/> [--service-dir=<dir holding the package DashboardInventoryService.php>]
 *       DATABASE check (BEFORE deploying use --service-dir=payload): loads the dashboard service, runs
 *       overview()/detail('deadstock') inside a READ ONLY transaction (a write is proved to be rejected first) and checks
 *       that Dead Stock now equals the latest POSTED opname's final_deadstock_qty x unit_cost_base (independent SQL).
 *
 *   php scripts/ui2_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *       FILE check (AFTER applying): the five targets carry the UI2 markers exactly once / the old CSS is gone /
 *       braces balance / index.html tokens / state files present. Reads files only, never needs the DB.
 *
 * Exit code 1 if any check fails.
 */

$appRoot = $serviceDir = $public = $services = null;
$files = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--service-dir=')) { $serviceDir = rtrim(substr($arg, 14), '/'); }
    elseif (str_starts_with($arg, '--public-dir=')) { $public = rtrim(substr($arg, 13), '/'); }
    elseif (str_starts_with($arg, '--services-dir=')) { $services = rtrim(substr($arg, 15), '/'); }
    elseif ($arg === '--files') { $files = true; }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
$fail = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};

if ($files) {
    if ($public === null || $services === null) { fwrite(STDERR, "--files needs --public-dir and --services-dir\n"); exit(2); }
    $css = (string) @file_get_contents("{$public}/assets/css/app.css");
    $html = (string) @file_get_contents("{$public}/index.html");
    $dash = (string) @file_get_contents("{$public}/assets/js/dashboard.js");
    $side = (string) @file_get_contents("{$public}/assets/js/sidebar.js");
    $svc = (string) @file_get_contents("{$services}/DashboardInventoryService.php");
    $check('app.css: refined dashboard block present once', substr_count($css, '/* Dashboard redesign (dashboard.js)') === 1 && substr_count($css, '--dz-kpi:') >= 1);
    $check('app.css: previous dashboard sizing is gone', !str_contains($css, 'clamp(1.15rem, 2vw, 1.75rem)') && !str_contains($css, '.dash-tile { flex: 0 0 auto; width: 46px;'));
    $check('app.css: sidebar rail block present once', substr_count($css, '/* UI2 — collapsible sidebar rail') === 1 && str_contains($css, '.sidebar.sidebar-collapsed { width: 68px; }'));
    $depth = 0; $min = 0;
    foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
    $check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
    $check('index.html: app.css / sidebar.js / dashboard.js carry token 20261011-ui2 (once each)', substr_count($html, 'app.css?v=20261011-ui2') === 1 && substr_count($html, 'sidebar.js?v=20261011-ui2') === 1 && substr_count($html, 'dashboard.js?v=20261011-ui2') === 1);
    $check('dashboard.js: compact Rupiah fallback + Dead Stock drill-down columns', str_contains($dash, 'function compactRupiah') && str_contains($dash, 'deadstock: [['));
    $check('sidebar.js: collapsible rail', str_contains($side, 'inv_sidebar_collapsed') && str_contains($side, 'sidebar-collapsed'));
    $check('DashboardInventoryService.php: Dead Stock follows the latest opname', str_contains($svc, "'final_deadstock_qty'") && str_contains($svc, "'deadstock'") && !str_contains($svc, 'SlowMovementReportService::list'));
    foreach (["{$public}/assets/css/app.css", "{$public}/index.html", "{$public}/assets/js/dashboard.js", "{$public}/assets/js/sidebar.js", "{$services}/DashboardInventoryService.php"] as $t) {
        $check('state file beside ' . basename($t), is_file($t . '.ui2-patch.json') && is_file($t . '.pre-ui2-backup'));
    }
    echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
    exit($fail ? 1 : 0);
}

if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/ui2_readonly_check.php --app-root=<dir with services/> [--service-dir=<dir>]   |   --public-dir=<..> --services-dir=<..> --files\n");
    exit(2);
}
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) {
    if (basename($f) !== 'DashboardInventoryService.php') {
        require_once $f;
    }
}
$svcPath = ($serviceDir ?? "{$appRoot}/services") . '/DashboardInventoryService.php';
if (!is_file($svcPath)) { fwrite(STDERR, "missing service file: {$svcPath}\n"); exit(2); }
require_once $svcPath;

use App\Services\Database;
use App\Services\DashboardInventoryService as D;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction.\n");
    $pdo->exec('ROLLBACK');
    exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}
$near = static fn (float $a, float $b, float $e = 0.01): bool => abs($a - $b) <= $e;
$check('column stock_opname_lines.final_deadstock_qty exists', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_opname_lines' AND column_name = 'final_deadstock_qty'")->fetchColumn() === 1);
$ov = D::overview($pdo, null, 'month');
$att = [];
foreach ($ov['attention'] as $a) { $att[$a['key']] = $a; }
$check('overview returns a Dead Stock card whose action opens the deadstock drill-down', isset($att['dead_stock']) && ($att['dead_stock']['action'] ?? null) === ['type' => 'detail', 'detail' => 'deadstock'] && $att['dead_stock']['hint'] === 'Hasil Stock Opname terakhir');
$exp = $pdo->query(
    "SELECT COUNT(DISTINCT sol.item_id) c, COALESCE(SUM(ROUND(sol.final_deadstock_qty * sol.unit_cost_base, 2)),0) v
       FROM stock_opname_lines sol JOIN stock_opname_sessions s ON s.id = sol.session_id
      WHERE s.status = 'POSTED' AND sol.final_deadstock_qty > 0
        AND s.id = (SELECT s2.id FROM stock_opname_sessions s2 WHERE s2.warehouse_id = s.warehouse_id AND s2.status = 'POSTED' ORDER BY s2.session_date DESC, s2.id DESC LIMIT 1)"
)->fetch();
$check('Dead Stock (all warehouses) == independent SQL over the latest POSTED opname per warehouse', $att['dead_stock']['sku_count'] === (int) $exp['c'] && $near((float) $att['dead_stock']['value'], (float) $exp['v']), "{$att['dead_stock']['sku_count']} SKU / {$att['dead_stock']['value']} vs {$exp['c']} / {$exp['v']}");
$dd = D::detail($pdo, 'deadstock', null, 'month', null, null, ['page' => 1, 'per_page' => 25]);
$check('drill-down GRAND TOTAL == card value; kind=deadstock', $dd['kind'] === 'deadstock' && $near((float) $dd['grand_total']['value'], (float) $att['dead_stock']['value']) && $dd['grand_total']['sku_count'] === $att['dead_stock']['sku_count'], json_encode($dd['grand_total']));
$rd = D::detail($pdo, 'rusak', null, 'month', null, null, ['page' => 1, 'per_page' => 25]);
$check('Rusak drill-down still works (shared query) and equals its card', $rd['kind'] === 'rusak' && $near((float) $rd['grand_total']['value'], (float) $att['rusak']['value']));
foreach (['opening_stock', 'closing_stock', 'purchase_in', 'stock_out', 'current_stock'] as $type) {
    $d = D::detail($pdo, $type, null, 'month', null, null, ['page' => 1, 'per_page' => 5]);
    $check("detail '{$type}' still answers", isset($d['grand_total']));
}
echo "\nstock value / movement figures (unchanged by this package, for your eyes): stock_value.total=" . json_encode($ov['summary']['stock_value']['total'] ?? null) . "\n";
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
