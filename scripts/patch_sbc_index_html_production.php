<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Sidebar "Laporan" cleanup) — public/index.html
 *
 * Navigation markup only; no script, style, route, permission or API change. Two edits, nothing else:
 *   1. the Laporan submenu (the exact audited block, payload/sbc_laporan_old.txt) becomes the five approved reports
 *      (Laporan Pergerakan Stok, Laporan IN / OUT, Laporan Pembelian, Laporan Nilai HPP, Laporan Stock Opname) followed by a
 *      HIDDEN container that keeps every other report link in the DOM (internal navigation activates a page by clicking
 *      its [data-tab] link) — payload/sbc_laporan_new.txt;
 *   2. the "Laporan Stock Opname" link under the Stock Opname group (payload/sbc_opname_old.txt) is replaced by a comment
 *      (payload/sbc_opname_new.txt) — the same data-tab now lives in the Laporan menu (one link per tab keeps the active
 *      highlight unambiguous).
 * Each old block must match EXACTLY ONCE; the four payload files must hash to the tested values. No ?v= cache token is touched
 * (no .js/.css changed, so nothing needs busting; index.html itself is fetched without a token).
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the payload hashes match, the file does not
 * already contain the cleanup, and both anchors match once. Dry-run by default; --apply backs up (<file>.pre-sbc-backup),
 * writes atomically, re-verifies, records <file>.sbc-patch.json for rollback_sbc_production.php.
 *
 * Usage:
 *   php scripts/patch_sbc_index_html_production.php <index.html> <laporan_old> <laporan_new> <opname_old> <opname_new> \
 *       --expect-sha256=<h> --expect-laporan-old-sha256=<h> --expect-laporan-new-sha256=<h> --expect-opname-old-sha256=<h> --expect-opname-new-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-sbc-backup');
define('JP_META_SUFFIX', '.sbc-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_sbc_index_html_production.php <index.html> <laporan_old> <laporan_new> <opname_old> <opname_new> --expect-sha256=<h> --expect-laporan-old-sha256=<h> --expect-laporan-new-sha256=<h> --expect-opname-old-sha256=<h> --expect-opname-new-sha256=<h> [--apply]';
$apply = false;
$e = ['sha256' => null, 'laporan-old-sha256' => null, 'laporan-new-sha256' => null, 'opname-old-sha256' => null, 'opname-new-sha256' => null];
$pos = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    $matched = false;
    foreach (array_keys($e) as $k) {
        if (str_starts_with($arg, "--expect-{$k}=")) {
            $e[$k] = strtolower(substr($arg, strlen("--expect-{$k}=")));
            $matched = true;
        }
    }
    if (!$matched) {
        if (str_starts_with($arg, '--')) {
            jp_fail("unknown argument: {$arg}\nusage: {$usage}");
        }
        $pos[] = $arg;
    }
}
$hex = static fn (?string $v): bool => $v !== null && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
if (count($pos) !== 5) {
    jp_fail("usage: {$usage}");
}
foreach ($e as $v) {
    if (!$hex($v)) {
        jp_fail("usage: {$usage}");
    }
}
[$path, $lOldPath, $lNewPath, $oOldPath, $oNewPath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $e['sha256']);
$payload = [];
foreach (['laporan-old' => $lOldPath, 'laporan-new' => $lNewPath, 'opname-old' => $oOldPath, 'opname-new' => $oNewPath] as $k => $p) {
    $payload[$k] = jp_read($p);
    $h = hash('sha256', $payload[$k]);
    if (!hash_equals($e["{$k}-sha256"], $h)) {
        jp_fail("{$k} payload SHA256 mismatch — refusing. expected=" . $e["{$k}-sha256"] . " actual={$h}");
    }
}
echo "OK — the four payload hashes match.\n";
jp_refuse_if_contains($source, ['sidebar-legacy-routes', 'data-tab="laporan-pergerakan" data-require-permission="INVENTORY_VIEW"><span class="icon">📊</span> Laporan Pergerakan Stok']);

$patched = jp_replace_exactly_once($source, $payload['laporan-old'], $payload['laporan-new'], 'Laporan submenu block (exact audited text)');
$patched = jp_replace_exactly_once($patched, $payload['opname-old'], $payload['opname-new'], 'Stock Opname group "Laporan Stock Opname" link (exact audited text)');
jp_finish($path, $source, $patched, $apply);
