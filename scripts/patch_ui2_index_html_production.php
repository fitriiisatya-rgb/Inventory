<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (UI2) — public/index.html
 *
 * Cache tokens only: the ?v= of app.css, sidebar.js and dashboard.js move to 20261011-ui2 (all three changed).
 * Each tag is matched with ANY current token but must match EXACTLY ONCE. No other tag is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the new token is not
 * already referenced, and every anchor matches exactly once. Dry-run by default; --apply backs up
 * (<file>.pre-ui2-backup), writes atomically, re-verifies, records <file>.ui2-patch.json.
 *
 * Usage:
 *   php scripts/patch_ui2_index_html_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-ui2-backup');
define('JP_META_SUFFIX', '.ui2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_ui2_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const UI2_TOKEN = '20261011-ui2';
jp_refuse_if_contains($source, [UI2_TOKEN]);

$patched = $source;
foreach ([
    ['#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#', 'app.css <link> tag'],
    ['#(<script src="assets/js/sidebar\.js\?v=)[A-Za-z0-9._-]+("></script>)#', 'sidebar.js <script> tag'],
    ['#(<script src="assets/js/dashboard\.js\?v=)[A-Za-z0-9._-]+("></script>)#', 'dashboard.js <script> tag'],
] as [$pattern, $label]) {
    $patched = jp_regex_replace_exactly_once($patched, $pattern, static fn (array $m): string => $m[1] . UI2_TOKEN . $m[2], $label);
}
jp_finish($opts['path'], $source, $patched, $opts['apply']);
