<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Jejak v3) — public/index.php (API front controller)
 *
 * Adds the READ-ONLY route  GET /reports/opname/{id}/jejak  and the
 * require_once for services/StockOpnameJejakService.php (installed
 * separately). Two anchors, each required exactly once:
 *   1. the require_once of StockOpnameReportService.php  -> the new require
 *      is appended right after it;
 *   2. the existing  'GET /reports/opname/{id}' => function (...)  route
 *      -> the new route is inserted immediately BEFORE it (above that
 *      route's own leading comment block, so no comment is orphaned).
 * No existing route, permission check or function is changed; the new route
 * uses the same inv_require_auth / INVENTORY_VIEW / inv_require_so_warehouse_
 * scope guards as GET /reports/opname/{id}. No `use` statement is added (the
 * route calls the service by its fully-qualified name).
 *
 * Targets the files as they are AFTER the Jejak v2 package (the current
 * production state) — never the original pre-Jejak files. FAILS CLOSED:
 * refuses to write anything unless the file's CURRENT SHA256 equals
 * --expect-sha256, it is not already v3-patched, and every anchor is found
 * exactly once. Dry-run by default; --apply backs the file up
 * (<file>.pre-v3-backup — distinct from v2's .pre-patch-backup), writes
 * atomically, re-verifies the bytes and records <file>.jejak-v3-patch.json
 * for rollback_jejak_v3_production.php.
 *
 * Usage:
 *   php scripts/patch_jejak_v3_index_php_route_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_jejak_v3_index_php_route_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-v3-backup');
define('JP_META_SUFFIX', '.jejak-v3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_jejak_v3_index_php_route_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['StockOpnameJejakService', '/reports/opname/{id}/jejak']);

$patched = jp_replace_exactly_once($source,
    "require_once __DIR__ . '/../services/StockOpnameReportService.php';\n",
    "require_once __DIR__ . '/../services/StockOpnameReportService.php';\n"
    . "require_once __DIR__ . '/../services/StockOpnameJejakService.php';\n",
    'require_once StockOpnameReportService.php');

$route = <<<'PHPCODE'
    // "Jejak Stock Opname" drawer (report-opname.js row click): READ-ONLY
    // per-session trace — real lines, Count 01/02, final, variance, dead
    // stock, rusak, HPP and posted adjustments, plus the six KPI figures
    // computed from those same rows. Same permission + warehouse-scope
    // guards as GET /reports/opname/{id}; HPP is exposed under
    // INVENTORY_VIEW exactly like the existing HPP reports.
    'GET /reports/opname/{id}/jejak' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        inv_ok(\App\Services\StockOpnameJejakService::detail($pdo, $sessionId), 'OK');
    },


PHPCODE;

$routeAnchor = "    'GET /reports/opname/{id}' => function (array \$params) use (\$pdo, \$query) {\n";
if (substr_count($patched, $routeAnchor) !== 1) {
    jp_fail("anchor 'GET /reports/opname/{id}' route matched " . substr_count($patched, $routeAnchor) . ' times (expected exactly 1) — nothing was written.');
}
echo "OK — anchor 'GET /reports/opname/{id}' route found exactly once.\n";
$pos = strpos($patched, $routeAnchor);
// walk up over the contiguous // comment lines that belong to that route
$insertAt = $pos;
while ($insertAt > 0) {
    $prevEnd = $insertAt - 1;                       // the "\n" ending the previous line
    $prevStart = strrpos(substr($patched, 0, $prevEnd), "\n");
    $prevStart = $prevStart === false ? 0 : $prevStart + 1;
    if (!str_starts_with(ltrim(substr($patched, $prevStart, $prevEnd - $prevStart)), '//')) {
        break;
    }
    $insertAt = $prevStart;
}
$patched = substr($patched, 0, $insertAt) . $route . substr($patched, $insertAt);

jp_finish($opts['path'], $source, $patched, $opts['apply']);
