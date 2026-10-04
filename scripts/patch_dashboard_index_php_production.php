<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Dashboard redesign) — public/index.php (API front controller)
 *
 * Adds the two READ-ONLY routes  GET /dashboard/inventory  and
 * GET /dashboard/inventory/detail  and the require_once for
 * services/DashboardInventoryService.php (installed separately). Two anchors,
 * each required exactly once:
 *   1. the require_once of InventoryHppReportService.php -> the new require is
 *      appended right after it;
 *   2. the existing  'GET /inventory/value' => function () use ($pdo) {  route
 *      -> the new routes are inserted immediately BEFORE it (above that route's
 *      own leading // comment block, if any).
 * No existing route, permission check or function is changed; the new routes use
 * inv_require_auth + INVENTORY_VIEW + inv_hpp_resolve_warehouse_scope (the same
 * guards as the other inventory reports). No `use` statement is added.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256,
 * it is not already patched, and every anchor is found exactly once. Dry-run by
 * default; --apply backs up (<file>.pre-dash-backup), writes atomically,
 * re-verifies and records <file>.dashboard-patch.json for
 * rollback_dashboard_production.php. Independent of whether Jejak v3 is applied.
 *
 * Usage:
 *   php scripts/patch_dashboard_index_php_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_dashboard_index_php_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-dash-backup');
define('JP_META_SUFFIX', '.dashboard-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_dashboard_index_php_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['DashboardInventoryService', '/dashboard/inventory']);

$patched = jp_replace_exactly_once($source,
    "require_once __DIR__ . '/../services/InventoryHppReportService.php';\n",
    "require_once __DIR__ . '/../services/InventoryHppReportService.php';\n"
    . "require_once __DIR__ . '/../services/DashboardInventoryService.php';\n",
    'require_once InventoryHppReportService.php');

$route = <<<'PHPCODE'
    // Main Dashboard (READ-ONLY): one overview call for every card, plus the
    // paginated rows behind a clicked card. INVENTORY_VIEW; a warehouse-scoped
    // STOCK user is forced to their own warehouse, anyone else may ask for one
    // warehouse or the whole company — same rule as the other inventory reports.
    'GET /dashboard/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $requested = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $requested);
        inv_ok(\App\Services\DashboardInventoryService::overview(
            $pdo, $warehouseId, (string) ($query['period'] ?? 'month'), $query['date_from'] ?? null, $query['date_to'] ?? null
        ), 'OK');
    },

    'GET /dashboard/inventory/detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $requested = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $requested);
        inv_ok(\App\Services\DashboardInventoryService::detail(
            $pdo, (string) ($query['type'] ?? ''), $warehouseId, (string) ($query['period'] ?? 'month'),
            $query['date_from'] ?? null, $query['date_to'] ?? null,
            [
                'q' => $query['q'] ?? null,
                'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
                // the dashboard's own warehouse selector is `warehouse_id`; this one only narrows rows INSIDE a company-wide view
                'warehouse_id' => isset($query['row_warehouse_id']) && $query['row_warehouse_id'] !== '' ? (int) $query['row_warehouse_id'] : null,
                'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
            ]
        ), 'OK');
    },


PHPCODE;

$routeAnchor = "    'GET /inventory/value' => function () use (\$pdo) {\n";
if (substr_count($patched, $routeAnchor) !== 1) {
    jp_fail("anchor 'GET /inventory/value' route matched " . substr_count($patched, $routeAnchor) . ' times (expected exactly 1) — nothing was written.');
}
echo "OK — anchor 'GET /inventory/value' route found exactly once.\n";
$pos = strpos($patched, $routeAnchor);
// walk up over the contiguous // comment lines that belong to that route
$insertAt = $pos;
while ($insertAt > 0) {
    $prevEnd = $insertAt - 1;
    $prevStart = strrpos(substr($patched, 0, $prevEnd), "\n");
    $prevStart = $prevStart === false ? 0 : $prevStart + 1;
    if (!str_starts_with(ltrim(substr($patched, $prevStart, $prevEnd - $prevStart)), '//')) {
        break;
    }
    $insertAt = $prevStart;
}
$patched = substr($patched, 0, $insertAt) . $route . substr($patched, $insertAt);

jp_finish($opts['path'], $source, $patched, $opts['apply']);
