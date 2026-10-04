<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/assets/js/report-opname.js
 *
 * report-opname.js is the ACTIVE production Stock Opname report frontend
 * ("Laporan P1/P2 Stock Opname", tab laporan-opname). Three insertions:
 *   1. an "Aksi" column holding a "Lihat Detail" button that keeps the
 *      existing real TraceDrawer.openOpname(id) one click away;
 *   2. row click now opens StockOpnameJejak (the Jejak PREVIEW drawer,
 *      stock-opname-report-jejak.js — installed separately), falling back to
 *      the old TraceDrawer behaviour if that script is not loaded;
 *   3. the detailButton() helper (stopPropagation so the button never also
 *      triggers the row click).
 * Nothing else in the file is touched. Does NOT target
 * stock-opname-report.js (not loaded by production).
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT SHA256
 * exactly matches --expect-sha256, the file is not already patched, and
 * every anchor is found exactly once. Dry-run by default; --apply backs the
 * file up (.pre-patch-backup), writes atomically, re-verifies the written bytes and
 * records <target>.jejak-patch.json for rollback_jejak_production.php.
 *
 * Usage:
 *   php scripts/patch_report_opname_jejak_row_click_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_report_opname_jejak_row_click_production.php <path> --expect-sha256=<hash> --apply
 */

require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_report_opname_jejak_row_click_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['StockOpnameJejak', 'detailButton']);

$patched = $source;

$patched = jp_replace_exactly_once($patched,
    "                { key: 'finalized_at', label: 'Finalisasi', render: (r) => r.finalized_at || '-' },\n",
    "                { key: 'finalized_at', label: 'Finalisasi', render: (r) => r.finalized_at || '-' },\n"
    . "                { key: 'actions', label: 'Aksi', render: (r) => detailButton(r) },\n",
    'finalized_at column');

$oldRowClick = "            onRowClick: (row) => TraceDrawer.openOpname(row.id),\n";
$newRowClick = <<<'JS'
            // Row click opens the "Jejak Stock Opname" drawer (stock-opname-report-
            // jejak.js — PREVIEW, simulated data). The real session trace stays
            // one click away on the "Lihat Detail" button in the Aksi column.
            onRowClick: (row) => {
                if (typeof StockOpnameJejak !== 'undefined') StockOpnameJejak.open(row);
                else TraceDrawer.openOpname(row.id);
            },

JS;
$patched = jp_replace_exactly_once($patched, $oldRowClick, rtrim($newRowClick, "\n") . "\n", 'onRowClick');

$helperAnchor = "    function varianceCell(value, isMoney) {\n";
$helper = <<<'JS'
    // "Lihat Detail" keeps the pre-existing real TraceDrawer for the session;
    // stopPropagation keeps the click from also reaching the row's own
    // click handler (which opens the Jejak preview drawer).
    function detailButton(row) {
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, 'Lihat Detail');
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            TraceDrawer.openOpname(row.id);
        });
        return btn;
    }


JS;
$patched = jp_replace_exactly_once($patched, $helperAnchor, rtrim($helper, "\n") . "\n\n" . $helperAnchor, 'varianceCell()');

jp_finish($opts['path'], $source, $patched, $opts['apply']);
