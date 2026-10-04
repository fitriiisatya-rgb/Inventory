<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/assets/css/app.css
 *
 * Inserts ONE block right after the existing `.drawer-kv .v` rule: the
 * `.drawer-xl` wide-drawer modifier plus the self-contained `.jejak-*` rules
 * (KPI hover/click state, scrollable sticky-header tables, drill-down modal
 * sizing, sticky action footer). Purely additive — no existing selector is
 * edited. Deliberately does NOT rely on `.so-report-*` (V2.16.4 CSS that
 * production's app.css does not have).
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT SHA256
 * exactly matches --expect-sha256, the file is not already patched, and
 * every anchor is found exactly once. Dry-run by default; --apply backs the
 * file up (.pre-patch-backup), writes atomically, re-verifies the written bytes and
 * records <target>.jejak-patch.json for rollback_jejak_production.php.
 *
 * Usage:
 *   php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash> --apply
 */

require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['.drawer-xl', '.jejak-']);

$anchor = '.drawer-kv .v { font-size: 0.9rem; font-weight: 600; }';
$block = <<<'CSS'

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

$patched = jp_replace_exactly_once($source, $anchor . "\n", $anchor . "\n" . $block . "\n", '.drawer-kv .v rule');

jp_finish($opts['path'], $source, $patched, $opts['apply']);
