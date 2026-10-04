<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Pergerakan Stok Harian redesign) — public/index.php
 *
 * Three insertions, nothing else is touched (the existing /reports/movement/* routes stay as they are):
 *   1. +1 require_once (services/MovementDailyReportService.php) right after the InventoryMovementReportService require;
 *   2. the query-parsing helper inv_movement_params() (payload/mvr_index_php_helper.txt) immediately before the docblock of
 *      inv_hpp_resolve_warehouse_scope() (which it calls, so a STOCK user can never widen the warehouse scope);
 *   3. five READ-ONLY GET routes (payload/mvr_index_php_routes.txt: overview, day-items, item-trail, period-transactions, export)
 *      immediately before the 'GET /reports/reconciliation/movement' route.
 * Every anchor must match EXACTLY ONCE; the two payload files must hash to the tested values.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the payload hashes match, the file does not
 * already reference MovementDailyReportService / inv_movement_params / the new routes, and every anchor matches once.
 * Dry-run by default; --apply backs up (<file>.pre-mvr-backup), writes atomically, re-verifies, records <file>.mvr-patch.json
 * for rollback_mvr_production.php.
 *
 * Usage:
 *   php scripts/patch_mvr_index_php_production.php <index.php> <helper.txt> <routes.txt> \
 *       --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-mvr-backup');
define('JP_META_SUFFIX', '.mvr-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_mvr_index_php_production.php <index.php> <helper.txt> <routes.txt> --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]';
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
jp_refuse_if_contains($source, ['MovementDailyReportService', 'inv_movement_params', "'GET /reports/movement/overview'", "'GET /reports/movement/day-items'", "'GET /reports/movement/item-trail'", "'GET /reports/movement/export'"]);

$require = "require_once __DIR__ . '/../services/InventoryMovementReportService.php';\n";
$patched = jp_replace_exactly_once($source, $require, $require . "require_once __DIR__ . '/../services/MovementDailyReportService.php';\n", 'require_once InventoryMovementReportService.php');
$docAnchor = "/**\n * Same \"STOCK is always forced to their own warehouse, never a\n";
$patched = jp_replace_exactly_once($patched, $docAnchor, $payload['helper'] . $docAnchor, 'inv_hpp_resolve_warehouse_scope docblock');
$routeAnchor = "    'GET /reports/reconciliation/movement' => function () use (\$pdo, \$query) {\n";
$patched = jp_replace_exactly_once($patched, $routeAnchor, $payload['routes'] . $routeAnchor, "'GET /reports/reconciliation/movement' route");
jp_finish($path, $source, $patched, $apply);
