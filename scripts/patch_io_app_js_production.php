<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan IN / OUT / Transfer) — public/assets/js/app.js
 *
 * TWO lines: the routes of the existing "Laporan IN / OUT" page (data-tab "laporan-inout") and of the old "Laporan Transfer" page (data-tab "laporan-transfer", reached from the
 * dashboard / summary drill-downs) are re-pointed to the new one-page, three-tab report (it opens on the matching tab):
 *     ReportInOut.render(document.getElementById('tab-laporan-inout'));
 *  -> ReportIO.render(document.getElementById('tab-laporan-inout'), { tab: 'in' });
 *     ReportTransferList.render(document.getElementById('tab-laporan-transfer'));
 *  -> ReportIO.render(document.getElementById('tab-laporan-transfer'), { tab: 'transfer' });
 * The sidebar links, permissions, containers and the old report-inout.js / report-transfer.js files (and their endpoints) are untouched — the old pages simply are no longer routed
 * to; rollback restores both lines byte for byte. Each anchor must match EXACTLY ONCE.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, neither line is already re-pointed and both anchors match once.
 * Dry-run by default; --apply backs up (<file>.pre-io-backup), writes atomically, re-verifies, records <file>.io-patch.json for rollback_io_production.php.
 *
 * Usage: php scripts/patch_io_app_js_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-io-backup');
define('JP_META_SUFFIX', '.io-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_io_app_js_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['ReportIO.render(']);

$patched = jp_replace_exactly_once($source, "ReportInOut.render(document.getElementById('tab-laporan-inout'));", "ReportIO.render(document.getElementById('tab-laporan-inout'), { tab: 'in' });", "app.js 'laporan-inout' route line");
$patched = jp_replace_exactly_once($patched, "ReportTransferList.render(document.getElementById('tab-laporan-transfer'));", "ReportIO.render(document.getElementById('tab-laporan-transfer'), { tab: 'transfer' });", "app.js 'laporan-transfer' route line");
jp_finish($opts['path'], $source, $patched, $opts['apply']);
