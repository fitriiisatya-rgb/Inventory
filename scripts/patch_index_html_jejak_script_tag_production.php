<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/index.html
 *
 * Anchored on the ACTIVE `assets/js/report-opname.js` script tag (NOT on
 * stock-opname-report.js, which production does not load). Three edits:
 *   1. insert the new `stock-opname-report-jejak.js` <script> immediately
 *      after the report-opname.js tag;
 *   2. bump the report-opname.js cache-bust token (its bytes change, so the
 *      old ?v= would let browsers keep serving the stale copy);
 *   3. bump the app.css cache-bust token (same reason: .jejak-* CSS added).
 * The tokens' CURRENT values are not hard-coded (production's may differ
 * from dev's) — each tag is matched by regex and must occur exactly once.
 * The new token is a fresh, never-used one: 20261007-jejak2.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT SHA256
 * exactly matches --expect-sha256, the file is not already patched, and
 * every anchor is found exactly once. Dry-run by default; --apply backs the
 * file up (.pre-patch-backup), writes atomically, re-verifies the written bytes and
 * records <target>.jejak-patch.json for rollback_jejak_production.php.
 *
 * Usage:
 *   php scripts/patch_index_html_jejak_script_tag_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_index_html_jejak_script_tag_production.php <path> --expect-sha256=<hash> --apply
 */

require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_index_html_jejak_script_tag_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['stock-opname-report-jejak.js']);

const JEJAK_TOKEN = '20261007-jejak2';
foreach (['assets/js/report-opname.js?v=' . JEJAK_TOKEN, 'assets/css/app.css?v=' . JEJAK_TOKEN] as $used) {
    if (str_contains($source, $used)) {
        jp_fail("cache-bust token already in use: {$used}");
    }
}

// 1+2: the report-opname.js tag -> new token, followed by the new tag.
$patched = jp_regex_replace_exactly_once($source,
    '#<script src="assets/js/report-opname\.js\?v=[^"]+"></script>#',
    static fn (array $m): string => '<script src="assets/js/report-opname.js?v=' . JEJAK_TOKEN . '"></script>' . "\n"
        . '<script src="assets/js/stock-opname-report-jejak.js?v=' . JEJAK_TOKEN . '"></script>',
    'report-opname.js <script> tag');

// 3: app.css <link> token only.
$patched = jp_regex_replace_exactly_once($patched,
    '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[^"]+(">)#',
    static fn (array $m): string => $m[1] . JEJAK_TOKEN . $m[2],
    'app.css <link> tag');

jp_finish($opts['path'], $source, $patched, $opts['apply']);
