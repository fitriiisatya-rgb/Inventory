<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Nilai Stok & HPP (dual valuation)) — public/index.php
 *
 * Three insertions, nothing else is touched (the existing GET /reports/inventory-hpp/* routes and every other report route stay as they are):
 *   1. +1 require_once (services/InventoryValuationService.php) right after the InventoryHppReportService require;
 *   2. one request helper (inv_val_filters — payload/val_index_php_helper.txt) immediately before the docblock of "PHASE V2.6C — interactive-period guard for
 *      Rekonsiliasi Arus Stok." (it calls the existing inv_hpp_resolve_warehouse_scope(), so a STOCK user / warehouse-scoped ADMIN can never widen the scope);
 *   3. three READ-ONLY GET routes (payload/val_index_php_routes.txt: /reports/inventory-valuation, /item, /export) immediately before the 'GET /reports/inventory-hpp/summary' route.
 * Every anchor must match EXACTLY ONCE; the two payload files must hash to the tested values.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the payload hashes match, the file does not
 * already reference InventoryValuationService / inv_val_filters / the new routes, and every anchor matches once.
 * Dry-run by default; --apply backs up (<file>.pre-val-backup), writes atomically, re-verifies, records <file>.val-patch.json
 * for rollback_val_production.php.
 *
 * Usage:
 *   php scripts/patch_val_index_php_production.php <index.php> <helper.txt> <routes.txt> \
 *       --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-val-backup');
define('JP_META_SUFFIX', '.val-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_val_index_php_production.php <index.php> <helper.txt> <routes.txt> --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]';
$apply = false;
$e = ['sha256' => null, 'helper-sha256' => null, 'routes-sha256' => null];
$pos = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    $matched = false;
    foreach (array_keys($e) as $k) {
        if (str_starts_with($arg, "--expect-{$k}=")) {
            $e[$k] = strtolower(substr($arg, strlen("--expect-{$k}=")));
            $matched = true;
        }
    }
    if (!$matched) {
        if (str_starts_with($arg, '--')) {
            jp_fail("unknown argument: {$arg}\nusage: {$usage}");
        }
        $pos[] = $arg;
    }
}
$hex = static fn (?string $v): bool => $v !== null && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
if (count($pos) !== 3 || !$hex($e['sha256']) || !$hex($e['helper-sha256']) || !$hex($e['routes-sha256'])) {
    jp_fail("usage: {$usage}");
}
[$path, $helperPath, $routesPath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $e['sha256']);
$payload = [];
foreach (['helper' => $helperPath, 'routes' => $routesPath] as $k => $p) {
    $payload[$k] = jp_read($p);
    $h = hash('sha256', $payload[$k]);
    if (!hash_equals($e["{$k}-sha256"], $h)) {
        jp_fail("{$k} payload SHA256 mismatch — refusing. expected=" . $e["{$k}-sha256"] . " actual={$h}");
    }
}
echo "OK — helper / routes payload hashes match.\n";
jp_refuse_if_contains($source, ['InventoryValuationService', 'inv_val_filters', "'GET /reports/inventory-valuation'", "'GET /reports/inventory-valuation/item'", "'GET /reports/inventory-valuation/export'"]);

$require = "require_once __DIR__ . '/../services/InventoryHppReportService.php';\n";
$patched = jp_replace_exactly_once($source, $require, $require . "require_once __DIR__ . '/../services/InventoryValuationService.php';\n", 'require_once InventoryHppReportService.php');
$docAnchor = "/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.";
$patched = jp_replace_exactly_once($patched, $docAnchor, $payload['helper'] . $docAnchor, 'PHASE V2.6C docblock');
$routeAnchor = "    'GET /reports/inventory-hpp/summary' => function () use (\$pdo, \$query) {\n";
$patched = jp_replace_exactly_once($patched, $routeAnchor, $payload['routes'] . $routeAnchor, "'GET /reports/inventory-hpp/summary' route");
jp_finish($path, $source, $patched, $apply);
