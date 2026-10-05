<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan IN / OUT / Transfer) — public/index.html
 *
 * Anchored on the script tag of the existing report page that production really loads:
 *     <script src="assets/js/report-inout.js?v=<any token>"></script>      (must match EXACTLY ONCE; never modified — the old page stays loaded for rollback)
 * Edits (nothing else in the file is touched):
 *   1. INSERT <script src="assets/js/report-io.js?v=20261017-io"></script> immediately AFTER the report-inout.js tag
 *      (if production already has exactly one report-io.js tag only its ?v= moves; two or more → refuse);
 *   2. the app.css <link> ?v= token (the .io-* block is appended to app.css) — ANY current token (e.g. 20261013-mvr), exactly once;
 *   3. the app.js <script> ?v= token (its two report routes are re-pointed to the new page) — ANY current token, exactly once.
 * api-client.js / api-client-v2163eod.js are NOT touched (the page does its own read-only GETs) and no other package's token is rewritten.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the new token is not already referenced and every anchor matches as
 * above. Dry-run by default; --apply backs up (<file>.pre-io-backup), writes atomically, re-verifies, records <file>.io-patch.json for rollback_io_production.php.
 *
 * Usage: php scripts/patch_io_index_html_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-io-backup');
define('JP_META_SUFFIX', '.io-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_io_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const IO_TOKEN = '20261017-io';
jp_refuse_if_contains($source, [IO_TOKEN]);

$anchorRe = '#<script src="assets/js/report-inout\.js\?v=[A-Za-z0-9._-]+"></script>#';
$ioRe = '#<script src="assets/js/report-io\.js\?v=[A-Za-z0-9._-]+"></script>#';
$anchorCount = preg_match_all($anchorRe, $source, $am);
if ($anchorCount !== 1) {
    jp_fail("anchor 'report-inout.js <script> tag' matched {$anchorCount} times (expected exactly 1) — production differs from the audited layout; nothing was written.");
}
echo "OK — anchor 'report-inout.js <script> tag' found exactly once ({$am[0][0]}); it is kept byte-identical.\n";
$ioCount = preg_match_all($ioRe, $source);
$newTag = '<script src="assets/js/report-io.js?v=' . IO_TOKEN . '"></script>';
if ($ioCount === 0) {
    echo "OK — production has no report-io.js tag: the new tag is inserted right after the report-inout.js tag.\n";
    $patched = jp_regex_replace_exactly_once($source, $anchorRe, static fn (array $m): string => $m[0] . "\n" . $newTag, 'report-inout.js tag (insert point)');
} elseif ($ioCount === 1) {
    echo "OK — production already has one report-io.js tag: only its token moves.\n";
    $patched = jp_regex_replace_exactly_once($source, $ioRe, static fn (array $m): string => $newTag, 'report-io.js <script> tag');
} else {
    jp_fail("anchor 'report-io.js <script> tag' matched {$ioCount} times (expected 0 or 1) — nothing was written.");
}
$patched = jp_regex_replace_exactly_once($patched, '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#', static fn (array $m): string => $m[1] . IO_TOKEN . $m[2], 'app.css <link> tag');
$patched = jp_regex_replace_exactly_once($patched, '#(<script src="assets/js/app\.js\?v=)[A-Za-z0-9._-]+("></script>)#', static fn (array $m): string => $m[1] . IO_TOKEN . $m[2], 'app.js <script> tag');
if (substr_count($patched, $am[0][0]) !== 1) {
    jp_fail('internal check failed: the report-inout.js tag is no longer present exactly once after patching — nothing was written.');
}
jp_finish($opts['path'], $source, $patched, $opts['apply']);
