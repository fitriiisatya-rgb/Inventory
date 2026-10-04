<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/assets/css/app.css.
 *
 * Adds ONE new, purely additive CSS rule: .drawer-xl, a width modifier
 * used only by the new "Jejak Stock Opname" PREVIEW mockup drawer to
 * widen itself beyond the shared .drawer default (560px). Every other
 * screen's drawer is completely unaffected — .drawer itself is never
 * touched.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the rule is not already
 * present, and the anchor is found exactly once.
 *
 * Usage:
 *   php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
 */

require_once __DIR__ . '/lib/js_function_patch.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit(1);
}

$args = $argv;
array_shift($args);
$apply = false;
$expectSha256 = null;
$path = null;
foreach ($args as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--expect-sha256=')) {
        $expectSha256 = substr($arg, strlen('--expect-sha256='));
    } elseif ($path === null) {
        $path = $arg;
    }
}
if ($path === null || $expectSha256 === null) {
    fail('usage: php scripts/patch_app_css_jejak_drawer_xl_production.php <path> --expect-sha256=<hash> [--apply]');
}
if (!is_file($path)) {
    fail("file not found: {$path}");
}
$source = file_get_contents($path);
if ($source === false) {
    fail("could not read: {$path}");
}

try {
    assert_preimage_hash($source, $expectSha256);
    echo "OK — preimage hash matches. Proceeding.\n";
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

if (str_contains($source, '.drawer-xl')) {
    fail('.drawer-xl already present in this file — refusing to patch a second time.');
}

$anchor = '.drawer-kv .v { font-size: 0.9rem; font-weight: 600; }';
$count = substr_count($source, $anchor);
if ($count !== 1) {
    fail("anchor matched {$count} times (expected exactly 1): {$anchor}");
}

$insert = <<<'CSS'

/* MOCKUP — wide drawer modifier (Jejak Stock Opname and similar dense
   report-style detail panels). Added alongside .drawer, never replacing
   it, so every other screen's 560px drawer is completely unaffected. */
.drawer-xl { width: min(1200px, 94vw); }
@media (max-width: 900px) { .drawer-xl { width: 100vw; } }
CSS;

try {
    $patched = insert_after_line_containing($source, $anchor, rtrim($insert));
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

echo "OK — .drawer-xl rule inserted.\n";

if (!$apply) {
    echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to write {$path}.\n";
    echo "Resulting file would hash to: " . hash('sha256', $patched) . "\n";
    exit(0);
}

$backupPath = $path . '.pre-patch-backup';
if (!copy($path, $backupPath)) {
    fail("could not write backup to {$backupPath} — aborting before touching {$path}");
}
if (file_put_contents($path, $patched) === false) {
    fail("could not write {$path} (backup is safe at {$backupPath})");
}

echo "\nAPPLIED. Backup saved at: {$backupPath}\n";
echo "New SHA256: " . hash('sha256', $patched) . "\n";
