<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Nilai Stok & HPP — dual valuation) — public/index.html
 *
 * Anchored on the script tag of the existing report page that production really loads:
 *     <script src="assets/js/report-hpp.js?v=<any token>"></script>      (must match EXACTLY ONCE; never modified — the old page stays loaded for rollback)
 * Edits (nothing else in the file is touched):
 *   1. INSERT <script src="assets/js/report-valuation.js?v=20261016-val"></script> immediately AFTER the report-hpp.js tag
 *      (if production already has exactly one report-valuation.js tag only its ?v= moves; two or more → refuse);
 *   2. the app.css <link> ?v= token (the .val-* block is appended to app.css) — ANY current token (e.g. 20261013-mvr), exactly once;
 *   3. the app.js <script> ?v= token (its Laporan Nilai HPP route is re-pointed to the new page) — ANY current token, exactly once.
 * api-client.js / api-client-v2163eod.js are NOT touched (the page does its own read-only GETs) and no other package's token is rewritten.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the new token is not already referenced and every anchor matches as
 * above. Dry-run by default; --apply backs up (<file>.pre-val-backup), writes atomically, re-verifies, records <file>.val-patch.json for rollback_val_production.php.
 *
 * Usage: php scripts/patch_val_index_html_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-val-backup');
define('JP_META_SUFFIX', '.val-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_val_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const VAL_TOKEN = '20261016-val';
jp_refuse_if_contains($source, [VAL_TOKEN]);

$hppRe = '#<script src="assets/js/report-hpp\.js\?v=[A-Za-z0-9._-]+"></script>#';
$valRe = '#<script src="assets/js/report-valuation\.js\?v=[A-Za-z0-9._-]+"></script>#';
$hppCount = preg_match_all($hppRe, $source, $hm);
if ($hppCount !== 1) {
    jp_fail("anchor 'report-hpp.js <script> tag' matched {$hppCount} times (expected exactly 1) — production differs from the audited layout; nothing was written.");
}
echo "OK — anchor 'report-hpp.js <script> tag' found exactly once ({$hm[0][0]}); it is kept byte-identical.\n";
$valCount = preg_match_all($valRe, $source);
$newTag = '<script src="assets/js/report-valuation.js?v=' . VAL_TOKEN . '"></script>';
if ($valCount === 0) {
    echo "OK — production has no report-valuation.js tag: the new tag is inserted right after the report-hpp.js tag.\n";
    $patched = jp_regex_replace_exactly_once($source, $hppRe, static fn (array $m): string => $m[0] . "\n" . $newTag, 'report-hpp.js tag (insert point)');
} elseif ($valCount === 1) {
    echo "OK — production already has one report-valuation.js tag: only its token moves.\n";
    $patched = jp_regex_replace_exactly_once($source, $valRe, static fn (array $m): string => $newTag, 'report-valuation.js <script> tag');
} else {
    jp_fail("anchor 'report-valuation.js <script> tag' matched {$valCount} times (expected 0 or 1) — nothing was written.");
}
$patched = jp_regex_replace_exactly_once($patched, '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#', static fn (array $m): string => $m[1] . VAL_TOKEN . $m[2], 'app.css <link> tag');
$patched = jp_regex_replace_exactly_once($patched, '#(<script src="assets/js/app\.js\?v=)[A-Za-z0-9._-]+("></script>)#', static fn (array $m): string => $m[1] . VAL_TOKEN . $m[2], 'app.js <script> tag');
if (substr_count($patched, $hm[0][0]) !== 1) {
    jp_fail('internal check failed: the report-hpp.js tag is no longer present exactly once after patching — nothing was written.');
}
jp_finish($opts['path'], $source, $patched, $opts['apply']);
