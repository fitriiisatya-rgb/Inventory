<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Dashboard redesign) — public/assets/css/app.css
 *
 * APPENDS the self-contained .dash-* block (payload/dashboard_app_css_block.css)
 * at the very end of the file. No existing rule is read, changed or removed,
 * so every other screen is unaffected. Independent of whether Jejak v2/v3 CSS
 * is present.
 *
 * FAILS CLOSED: refuses unless (a) the file's CURRENT SHA256 equals
 * --expect-sha256, (b) the block file's SHA256 equals --expect-block-sha256
 * (it is exactly the tested block), (c) the file contains neither the block's
 * marker comment nor any '.dash-' selector (double-apply / collision).
 * Dry-run by default; --apply backs up (<file>.pre-dash-backup), writes
 * atomically, re-verifies, records <file>.dashboard-patch.json for
 * rollback_dashboard_production.php.
 *
 * Usage:
 *   php scripts/patch_dashboard_app_css_production.php <path> <block.css> --expect-sha256=<hash> --expect-block-sha256=<hash>          (dry run)
 *   ... --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-dash-backup');
define('JP_META_SUFFIX', '.dashboard-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_dashboard_app_css_production.php <path> <block.css> --expect-sha256=<hash> --expect-block-sha256=<hash> [--apply]';
$apply = false;
$expect = null;
$expectBlock = null;
$pos = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--expect-sha256=')) {
        $expect = strtolower(substr($arg, 16));
    } elseif (str_starts_with($arg, '--expect-block-sha256=')) {
        $expectBlock = strtolower(substr($arg, 22));
    } elseif (!str_starts_with($arg, '--')) {
        $pos[] = $arg;
    } else {
        jp_fail("unknown argument: {$arg}\nusage: {$usage}");
    }
}
$hex = static fn (?string $v): bool => $v !== null && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
if (count($pos) !== 2 || !$hex($expect) || !$hex($expectBlock)) {
    jp_fail("usage: {$usage}");
}
[$path, $blockPath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $expect);
$block = jp_read($blockPath);
if (!hash_equals($expectBlock, hash('sha256', $block))) {
    jp_fail('CSS block SHA256 mismatch — refusing. expected=' . $expectBlock . ' actual=' . hash('sha256', $block));
}
echo "OK — CSS block hash matches.\n";
if (!str_starts_with($block, '/* Dashboard redesign (dashboard.js)')) {
    jp_fail('CSS block does not start with the expected marker comment.');
}
jp_refuse_if_contains($source, ['/* Dashboard redesign (dashboard.js)', '.dash-']);

$patched = $source . (str_ends_with($source, "\n") ? '' : "\n") . "\n" . $block;
jp_finish($path, $source, $patched, $apply);
