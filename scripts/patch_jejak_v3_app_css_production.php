<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Jejak v3) — public/assets/css/app.css
 *
 * Replaces the Jejak v2 CSS block (the exact text v2 inserted, matched
 * verbatim and required exactly once) with the v3 block: scroll fix for
 * .drawer-xl (pinned top/bottom instead of 100vh, which is taller than the
 * visible area on iPad/mobile browsers with a dynamic toolbar), html+body
 * scroll lock, horizontal-only table scrolling, pager, modal sizing.
 * Scoped to .drawer-xl / .jejak-* — no other drawer is affected.
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
 *   php scripts/patch_jejak_v3_app_css_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_jejak_v3_app_css_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-v3-backup');
define('JP_META_SUFFIX', '.jejak-v3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_jejak_v3_app_css_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['jejak-scroll-lock']);

$old = <<<'CSS'
/* MOCKUP — wide drawer modifier (Jejak Stock Opname and similar dense
   report-style detail panels). Added alongside .drawer, never replacing
   it, so every other screen's 560px drawer is completely unaffected. */
.drawer-xl { width: min(1200px, 94vw); }
@media (max-width: 900px) { .drawer-xl { width: 100vw; } }

/* Jejak Stock Opname drawer + KPI drill-down modal — SELF-CONTAINED: uses
   only long-standing vars/classes (.card, .modal, .hpp-kpi-*, .drawer-*),
   never .so-report-* (V2.16.4, absent from production's app.css). */
.jejak-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px 20px; }
.jejak-info-cell .k { font-size: 0.68rem; color: var(--text3); }
.jejak-info-cell .v { font-size: 0.82rem; font-weight: 600; }
.jejak-kpi { position: relative; cursor: pointer; padding: 10px 12px; transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease; }
.jejak-kpi .hpp-kpi-value { white-space: nowrap; font-size: 0.95rem; margin-bottom: 0; }
.jejak-kpi::after { content: '\203A'; position: absolute; top: 6px; right: 9px; color: var(--text3); font-size: 1rem; line-height: 1; }
.jejak-kpi:hover { background: var(--bg3); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35); }
.jejak-kpi:hover::after { color: var(--accent); }
.jejak-kpi:active { transform: translateY(0); }
.jejak-kpi:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.jejak-table-wrap { overflow: auto; max-height: 420px; }
.jejak-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; white-space: nowrap; }
.jejak-table th, .jejak-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.jejak-table th { position: sticky; top: 0; z-index: 2; background: var(--bg3); color: var(--text2); font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; }
.jejak-table .text-right { text-align: right; }
.jejak-table tbody tr:hover { background: var(--bg3); }
.jejak-total-row td { position: sticky; bottom: 0; z-index: 1; background: var(--bg2); font-weight: 700; border-top: 1px solid var(--border); }
.jejak-rest-row td { color: var(--text3); font-style: italic; }
.modal-content.jejak-drill { max-width: 1040px; width: 94%; }
.jejak-drill-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.jejak-actions { position: sticky; bottom: -16px; z-index: 3; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin: 16px -20px -16px; padding: 12px 20px; background: var(--card); border-top: 1px solid var(--border); }
CSS;
$new = <<<'CSS'
/* Jejak Stock Opname drawer + KPI drill-down modal. Wide-drawer modifier
   (.drawer-xl) and self-contained .jejak-* rules — uses only long-standing
   vars/classes (.card, .modal, .hpp-kpi-*, .drawer-*), never .so-report-*
   (V2.16.4, absent from production's app.css). Everything is scoped to
   .drawer-xl / .jejak-*: no other drawer is affected.
   SCROLL: .drawer is height:100vh. On iPad/mobile browsers with a dynamic
   toolbar 100vh is TALLER than the visible area, so the bottom of the
   drawer (end of content, action footer) sits under the browser chrome and
   cannot be scrolled into view. .drawer-xl is therefore pinned with
   top:0/bottom:0 (the visible viewport) instead of a fixed height, the
   body scrolls on its own (min-height:0 + overscroll-behavior), tables
   scroll horizontally only (no nested vertical scroller to get trapped in),
   and the page behind (html AND body — html carries its own overflow) is
   locked while the drawer is open. */
.drawer.drawer-xl { width: min(1200px, 94vw); height: auto; top: 0; bottom: 0; max-height: 100%; }
@media (max-width: 900px) { .drawer.drawer-xl { width: 100vw; } }
.drawer.drawer-xl .drawer-header, .drawer.drawer-xl .drawer-tabs { flex: 0 0 auto; }
.drawer.drawer-xl .drawer-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
html.jejak-scroll-lock, body.jejak-scroll-lock { overflow: hidden; }
.jejak-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px 20px; }
.jejak-info-cell .k { font-size: 0.68rem; color: var(--text3); }
.jejak-info-cell .v { font-size: 0.82rem; font-weight: 600; overflow-wrap: anywhere; }
.jejak-kpi { position: relative; cursor: pointer; padding: 10px 12px; transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease; }
.jejak-kpi .hpp-kpi-value { white-space: nowrap; font-size: 0.95rem; margin-bottom: 0; }
.jejak-kpi::after { content: '\203A'; position: absolute; top: 6px; right: 9px; color: var(--text3); font-size: 1rem; line-height: 1; }
.jejak-kpi:hover { background: var(--bg3); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35); }
.jejak-kpi:hover::after { color: var(--accent); }
.jejak-kpi:active { transform: translateY(0); }
.jejak-kpi:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.jejak-table-wrap { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
.jejak-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; white-space: nowrap; }
.jejak-table th, .jejak-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.jejak-table th { background: var(--bg3); color: var(--text2); font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; }
.jejak-table .text-right { text-align: right; }
.jejak-table tbody tr:hover { background: var(--bg3); }
.jejak-total-row td { background: var(--bg2); font-weight: 700; border-top: 1px solid var(--border); }
.jejak-pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 2px 0; color: var(--text2); font-size: 0.8rem; }
.modal-content.jejak-drill { max-width: 1040px; width: 94%; max-height: 85vh; max-height: min(85vh, calc(100dvh - 32px)); overflow-y: auto; overscroll-behavior: contain; }
.jejak-drill-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.jejak-actions { position: sticky; bottom: -16px; z-index: 3; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin: 16px -20px -16px; padding: 12px 20px; background: var(--card); border-top: 1px solid var(--border); }
CSS;

$patched = jp_replace_exactly_once($source, $old, $new, 'Jejak v2 CSS block');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
