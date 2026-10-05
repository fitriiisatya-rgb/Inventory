<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Nilai Stok & HPP (dual valuation)) — public/assets/js/app.js
 *
 * ONE line: the route of the existing "Laporan Nilai Stok & HPP" report page (data-tab "laporan-hpp", container #tab-laporan-hpp — the page that is active in
 * production today) is re-pointed from the old report to the new dual-valuation report:
 *     ReportHpp.render(document.getElementById('tab-laporan-hpp'));
 *  -> ReportValuation.render(document.getElementById('tab-laporan-hpp'));
 * The sidebar link, the permission, the container and the old report-hpp.js file / script tag (and its /reports/inventory-hpp/* endpoints) are untouched (the old report simply is no
 * longer routed to; rollback restores the line byte for byte). The anchor must match EXACTLY ONCE.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the line is not already re-pointed and the anchor matches once.
 * Dry-run by default; --apply backs up (<file>.pre-val-backup), writes atomically, re-verifies, records <file>.val-patch.json for rollback_val_production.php.
 *
 * Usage: php scripts/patch_val_app_js_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-val-backup');
define('JP_META_SUFFIX', '.val-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_val_app_js_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ["ReportValuation.render(document.getElementById('tab-laporan-hpp'))"]);

$old = "ReportHpp.render(document.getElementById('tab-laporan-hpp'));";
$patched = jp_replace_exactly_once($source, $old, "ReportValuation.render(document.getElementById('tab-laporan-hpp'));", "app.js 'laporan-hpp' route line");
jp_finish($opts['path'], $source, $patched, $opts['apply']);
