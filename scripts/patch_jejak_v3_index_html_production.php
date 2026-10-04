<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Jejak v3) — public/index.html
 *
 * Cache-busting only: the Jejak module and app.css changed, so their ?v=
 * tokens move from the v2 token 20261007-jejak2 to the fresh, never-used
 * 20261008-jejak3 (otherwise browsers keep serving the stale v2 copies).
 * Both tags are matched with the v2 token spelled out — if production's tag
 * carries anything else, this refuses. report-opname.js is unchanged in v3
 * and is NOT touched.
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
 *   php scripts/patch_jejak_v3_index_html_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_jejak_v3_index_html_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-v3-backup');
define('JP_META_SUFFIX', '.jejak-v3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_jejak_v3_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const V2_TOKEN = '20261007-jejak2';
const V3_TOKEN = '20261008-jejak3';
jp_refuse_if_contains($source, [V3_TOKEN]);

$patched = jp_regex_replace_exactly_once($source,
    '#(<script src="assets/js/stock-opname-report-jejak\.js\?v=)' . preg_quote(V2_TOKEN, '#') . '("></script>)#',
    static fn (array $m): string => $m[1] . V3_TOKEN . $m[2],
    'stock-opname-report-jejak.js <script> tag (v2 token)');
$patched = jp_regex_replace_exactly_once($patched,
    '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)' . preg_quote(V2_TOKEN, '#') . '(">)#',
    static fn (array $m): string => $m[1] . V3_TOKEN . $m[2],
    'app.css <link> tag (v2 token)');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
