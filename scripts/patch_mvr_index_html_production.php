<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Pergerakan Stok Harian redesign) — public/index.html
 *
 * Cache tokens only: the ?v= of app.css, api-client.js and report-movement.js moves to 20261013-mvr. Each tag is matched with ANY
 * current token but must match EXACTLY ONCE. No other tag (and no sidebar markup) is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the new token is not already referenced and every
 * tag matches exactly once. Dry-run by default; --apply backs up (<file>.pre-mvr-backup), writes atomically, re-verifies, records
 * <file>.mvr-patch.json for rollback_mvr_production.php.
 *
 * Usage: php scripts/patch_mvr_index_html_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-mvr-backup');
define('JP_META_SUFFIX', '.mvr-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_mvr_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const MVR_TOKEN = '20261013-mvr';
jp_refuse_if_contains($source, [MVR_TOKEN]);

$patched = jp_regex_replace_exactly_once($source,
    '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#',
    static fn (array $m): string => $m[1] . MVR_TOKEN . $m[2], 'app.css <link> tag');
foreach (['api-client.js', 'report-movement.js'] as $js) {
    $patched = jp_regex_replace_exactly_once($patched,
        '#(<script src="assets/js/' . preg_quote($js, '#') . '\?v=)[A-Za-z0-9._-]+("></script>)#',
        static fn (array $m): string => $m[1] . MVR_TOKEN . $m[2], "{$js} <script> tag");
}
jp_finish($opts['path'], $source, $patched, $opts['apply']);
