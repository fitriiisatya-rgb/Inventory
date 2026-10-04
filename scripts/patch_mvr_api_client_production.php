<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Pergerakan Stok Harian redesign) — public/assets/js/api-client.js
 *
 * Adds five one-line client methods:
 *   movementOverview, movementDayItems, movementItemTrail, movementPeriodTransactions — directly after the existing
 *     movementHistoricalTransactions line;
 *   movementExportUrl — directly after the existing movementDailyExportUrl line.
 * Both anchor lines must match EXACTLY ONCE; nothing else in the file is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256 and the new methods are absent.
 * Dry-run by default; --apply backs up (<file>.pre-mvr-backup), writes atomically, re-verifies, records <file>.mvr-patch.json.
 *
 * Usage: php scripts/patch_mvr_api_client_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-mvr-backup');
define('JP_META_SUFFIX', '.mvr-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_mvr_api_client_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['movementOverview:', 'movementDayItems:', 'movementItemTrail:', 'movementPeriodTransactions:', 'movementExportUrl:']);

$hist = "        movementHistoricalTransactions: (params) => request('GET', `/reports/movement/historical-transactions\${qs(params)}`),\n";
$patched = jp_replace_exactly_once($source, $hist, $hist
    . "        movementOverview: (params) => request('GET', `/reports/movement/overview\${qs(params)}`),\n"
    . "        movementDayItems: (params) => request('GET', `/reports/movement/day-items\${qs(params)}`),\n"
    . "        movementItemTrail: (params) => request('GET', `/reports/movement/item-trail\${qs(params)}`),\n"
    . "        movementPeriodTransactions: (params) => request('GET', `/reports/movement/period-transactions\${qs(params)}`),\n", 'movementHistoricalTransactions line');
$exp = "        movementDailyExportUrl: (params) => `/api/reports/movement/daily\${qs(Object.assign({}, params, { format: 'csv' }))}`,\n";
$patched = jp_replace_exactly_once($patched, $exp, $exp . "        movementExportUrl: (params) => `/api/reports/movement/export\${qs(params)}`,\n", 'movementDailyExportUrl line');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
