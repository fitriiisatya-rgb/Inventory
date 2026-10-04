<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (UI2: dashboard refinement + collapsible sidebar) — public/assets/css/app.css
 *
 * Two edits, nothing else:
 *   1. REPLACES the dashboard block deployed by the Dashboard package (payload/ui2_old_dashboard_block.css,
 *      the exact bytes of that deployment) with the refined block (payload/ui2_new_dashboard_block.css).
 *      The old block must occur EXACTLY ONCE, byte-for-byte — so this only works on a file that
 *      carries the deployed dashboard CSS unchanged (a Stock IN/OUT V2 block after it is fine).
 *   2. APPENDS the self-contained sidebar block (payload/ui2_sidebar_block.css) at the end of the file.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, all three block files hash
 * to the expected tested values, the file contains neither new marker ('--dz-kpi', '/* UI2 — collapsible sidebar rail')
 * (double-apply), and the old block is found exactly once. Dry-run by default; --apply backs up
 * (<file>.pre-ui2-backup), writes atomically, re-verifies, records <file>.ui2-patch.json.
 *
 * Usage:
 *   php scripts/patch_ui2_app_css_production.php <app.css> <old.css> <new.css> <sidebar.css> \
 *       --expect-sha256=<file> --expect-old-sha256=<h> --expect-new-sha256=<h> --expect-sidebar-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-ui2-backup');
define('JP_META_SUFFIX', '.ui2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_ui2_app_css_production.php <app.css> <old.css> <new.css> <sidebar.css> --expect-sha256=<h> --expect-old-sha256=<h> --expect-new-sha256=<h> --expect-sidebar-sha256=<h> [--apply]';
$apply = false;
$e = ['sha256' => null, 'old-sha256' => null, 'new-sha256' => null, 'sidebar-sha256' => null];
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
if (count($pos) !== 4 || !$hex($e['sha256']) || !$hex($e['old-sha256']) || !$hex($e['new-sha256']) || !$hex($e['sidebar-sha256'])) {
    jp_fail("usage: {$usage}");
}
[$path, $oldPath, $newPath, $sidePath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $e['sha256']);
$blocks = [];
foreach (['old' => $oldPath, 'new' => $newPath, 'sidebar' => $sidePath] as $k => $p) {
    $blocks[$k] = jp_read($p);
    $h = hash('sha256', $blocks[$k]);
    if (!hash_equals($e["{$k}-sha256"], $h)) {
        jp_fail("{$k} block SHA256 mismatch — refusing. expected=" . $e["{$k}-sha256"] . " actual={$h}");
    }
}
echo "OK — old / new / sidebar block hashes match.\n";
if (!str_starts_with($blocks['new'], '/* Dashboard redesign (dashboard.js)') || !str_starts_with($blocks['old'], '/* Dashboard redesign (dashboard.js)')
    || !str_starts_with($blocks['sidebar'], '/* UI2 — collapsible sidebar rail')) {
    jp_fail('a block file does not start with its expected marker comment.');
}
jp_refuse_if_contains($source, ['--dz-kpi', '/* UI2 — collapsible sidebar rail']);

$patched = jp_replace_exactly_once($source, $blocks['old'], $blocks['new'], 'deployed dashboard CSS block (exact bytes)');
$patched .= (str_ends_with($patched, "\n") ? '' : "\n") . "\n" . $blocks['sidebar'];
jp_finish($path, $source, $patched, $apply);
