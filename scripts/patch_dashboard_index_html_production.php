<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Dashboard redesign) — public/index.html
 *
 * Cache-busting only: dashboard.js and app.css changed, so their ?v= tokens
 * move to the fresh, never-used 20261009-dash1 (otherwise browsers keep the
 * stale copies). Each tag is matched with ANY current token (production may
 * be on the Jejak v2 or v3 token) but must match EXACTLY ONCE:
 *   <script src="assets/js/dashboard.js?v=...">   and
 *   <link rel="stylesheet" href="assets/css/app.css?v=...">
 * No other tag is touched. Independent of whether Jejak v3 is applied.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256,
 * the new token is not already present, and both anchors match exactly once.
 * Dry-run by default; --apply backs up (<file>.pre-dash-backup), writes
 * atomically, re-verifies, records <file>.dashboard-patch.json for
 * rollback_dashboard_production.php.
 *
 * Usage:
 *   php scripts/patch_dashboard_index_html_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_dashboard_index_html_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-dash-backup');
define('JP_META_SUFFIX', '.dashboard-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_dashboard_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const DASH_TOKEN = '20261009-dash1';
jp_refuse_if_contains($source, [DASH_TOKEN]);

$patched = jp_regex_replace_exactly_once($source,
    '#(<script src="assets/js/dashboard\.js\?v=)[A-Za-z0-9._-]+("></script>)#',
    static fn (array $m): string => $m[1] . DASH_TOKEN . $m[2],
    'dashboard.js <script> tag');
$patched = jp_regex_replace_exactly_once($patched,
    '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#',
    static fn (array $m): string => $m[1] . DASH_TOKEN . $m[2],
    'app.css <link> tag');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
