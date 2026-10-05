<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Stock Opname audit redesign) — public/assets/js/app.js
 *
 * ONE line: the route of the existing "Laporan Stock Opname" report page (data-tab "laporan-opname", container #tab-laporan-opname — the page that is active in
 * production today and opens the Jejak drawer) is re-pointed from the old report to the new audit report:
 *     ReportOpname.render(document.getElementById('tab-laporan-opname'));
 *  -> StockOpnameReport.render(document.getElementById('tab-laporan-opname'));
 * The sidebar link, the permission, the container, the old report-opname.js file / script tag and the Jejak drawer are untouched (the old report simply is no
 * longer routed to; rollback restores the line byte for byte). The anchor must match EXACTLY ONCE.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the line is not already re-pointed and the anchor matches once.
 * Dry-run by default; --apply backs up (<file>.pre-soa-backup), writes atomically, re-verifies, records <file>.soa-patch.json for rollback_soa_production.php.
 *
 * Usage: php scripts/patch_soa_app_js_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-soa-backup');
define('JP_META_SUFFIX', '.soa-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_soa_app_js_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ["StockOpnameReport.render(document.getElementById('tab-laporan-opname'))"]);

$old = "ReportOpname.render(document.getElementById('tab-laporan-opname'));";
$patched = jp_replace_exactly_once($source, $old, "StockOpnameReport.render(document.getElementById('tab-laporan-opname'));", "app.js 'laporan-opname' route line");
jp_finish($opts['path'], $source, $patched, $opts['apply']);
