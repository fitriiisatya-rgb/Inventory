<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Stock Opname audit redesign) — public/index.html
 *
 * Anchored on the Jejak script tag that production really loads:
 *     <script src="assets/js/stock-opname-report-jejak.js?v=<any token>"></script>      (must match EXACTLY ONCE; never modified)
 * That file is the Jejak DRAWER component (StockOpnameJejak) the new report page calls — it stays exactly as it is, so Jejak keeps working and a rollback
 * leaves its reference byte-identical. Edits (nothing else in the file is touched):
 *   1. the new report script:
 *        - if production has NO stock-opname-report.js tag (the real production layout): insert
 *          <script src="assets/js/stock-opname-report.js?v=20261017-soa3"></script> immediately AFTER the Jejak tag;
 *        - if it already has exactly one such tag: only its ?v= moves to the new token; two or more → refuse;
 *   2. the app.css <link> ?v= token (the .soa-* block is appended to app.css) — ANY current token (e.g. 20261013-mvr) is matched, exactly once;
 *   3. the app.js <script> ?v= token (its Laporan-opname route is re-pointed to the new page) — ANY current token, exactly once.
 * api-client.js / api-client-v2163eod.js are NOT touched (the page does its own read-only GETs), and no other package's token (MVR, Pembelian, …) is rewritten.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the new token is not already referenced and every anchor matches as
 * above. Dry-run by default; --apply backs up (<file>.pre-soa3-backup), writes atomically, re-verifies, records <file>.soa3-patch.json for
 * rollback_soa3_production.php.
 *
 * Usage: php scripts/patch_soa3_index_html_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-soa3-backup');
define('JP_META_SUFFIX', '.soa3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_soa3_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const SOA_TOKEN = '20261017-soa3';
// 20261014-soa = the token of the SOA V2 frontend: its presence means V2's frontend is (partly) applied — this package does not upgrade that state; roll V2 back first.
jp_refuse_if_contains($source, [SOA_TOKEN, '20261014-soa']);

$jejakRe = '#<script src="assets/js/stock-opname-report-jejak\.js\?v=[A-Za-z0-9._-]+"></script>#';
$reportRe = '#<script src="assets/js/stock-opname-report\.js\?v=[A-Za-z0-9._-]+"></script>#';
$jejakCount = preg_match_all($jejakRe, $source, $jm);
if ($jejakCount !== 1) {
    jp_fail("anchor 'stock-opname-report-jejak.js <script> tag' matched {$jejakCount} times (expected exactly 1) — the Jejak drawer must be loaded by production for the new report; nothing was written.");
}
echo "OK — anchor 'stock-opname-report-jejak.js <script> tag' found exactly once ({$jm[0][0]}); it is kept byte-identical.\n";
$reportCount = preg_match_all($reportRe, $source);
$newTag = '<script src="assets/js/stock-opname-report.js?v=' . SOA_TOKEN . '"></script>';
if ($reportCount === 0) {
    echo "OK — production has no stock-opname-report.js tag: the new tag is inserted right after the Jejak tag.\n";
    $patched = jp_regex_replace_exactly_once($source, $jejakRe, static fn (array $m): string => $m[0] . "\n" . $newTag, 'Jejak tag (insert point)');
} elseif ($reportCount === 1) {
    echo "OK — production already has one stock-opname-report.js tag: only its token moves.\n";
    $patched = jp_regex_replace_exactly_once($source, $reportRe, static fn (array $m): string => $newTag, 'stock-opname-report.js <script> tag');
} else {
    jp_fail("anchor 'stock-opname-report.js <script> tag' matched {$reportCount} times (expected 0 or 1) — nothing was written.");
}
$patched = jp_regex_replace_exactly_once($patched, '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#', static fn (array $m): string => $m[1] . SOA_TOKEN . $m[2], 'app.css <link> tag');
$patched = jp_regex_replace_exactly_once($patched, '#(<script src="assets/js/app\.js\?v=)[A-Za-z0-9._-]+("></script>)#', static fn (array $m): string => $m[1] . SOA_TOKEN . $m[2], 'app.js <script> tag');
if (substr_count($patched, $jm[0][0]) !== 1) {
    jp_fail('internal check failed: the Jejak tag is no longer present exactly once after patching — nothing was written.');
}
jp_finish($opts['path'], $source, $patched, $opts['apply']);
