<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCH — public/assets/js/api-client-v2163eod.js
 *
 * Root cause (confirmed via live production evidence, not guessed):
 * index.html loads assets/js/api-client-v2163eod.js?v=20261002-v2163eod
 * (NOT public/assets/js/api-client.js — that file is never referenced
 * anywhere and is not what the browser executes). This file predates
 * the Edit Barang/units feature (mtime Oct 2, before the Oct 3 work)
 * and its InvApi object has no `listUnits` method at all. Calling
 * `InvApi.listUnits()` from master.js's loadAll() therefore throws
 * `TypeError: InvApi.listUnits is not a function` SYNCHRONOUSLY,
 * before any fetch() ever runs — which is exactly why GET /units never
 * appears in the access logs, and why Master.units() stays empty no
 * matter how many times Edit Barang's resilience patch retries
 * Master.loadAll(): the method it needs simply isn't there to call.
 *
 * Fix: add ONE method to the existing InvApi object —
 *   listUnits: () => request('GET', '/units'),
 * — matching this codebase's own established pattern for every other
 * list method (listItems/listWarehouses/listSuppliers/listCategories),
 * reusing the SAME request() this file already has. Nothing else in
 * this file is touched.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, `listUnits` does not already
 * exist in the file (never double-add), and exactly one of a small set
 * of known-stable anchor lines (this codebase's own existing list
 * methods, in its own consistent style) is found exactly once to
 * insert next to.
 *
 * Usage:
 *   php scripts/patch_api_client_v2163eod_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_api_client_v2163eod_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_api_client_v2163eod_production.php <path> --expect-sha256=<hash> [--apply]');
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

if (preg_match('/\blistUnits\s*:/', $source)) {
    fail('listUnits already exists in this file — refusing to add it a second time. If GET /units still fails, the problem is elsewhere; re-run the diagnosis, do not re-patch.');
}

// Try each anchor in order; the FIRST one that is found EXACTLY ONCE in
// the file wins. Each candidate is this codebase's own real method
// text for an ENDPOINT ALREADY CONFIRMED WORKING IN PRODUCTION (per the
// diagnosis), in its own established style — never a guess at a new
// pattern.
$anchors = [
    "listWarehouses: () => request('GET', '/warehouses'),",
    "listSuppliers: () => request('GET', '/suppliers'),",
    "listCategories: () => request('GET', '/categories'),",
    "listItems: () => request('GET', '/items'),",
];

$patched = null;
$usedAnchor = null;
$anchorErrors = [];
foreach ($anchors as $anchor) {
    try {
        $patched = insert_after_line_containing($source, $anchor, "        listUnits: () => request('GET', '/units'), // STABILIZATION — added, matches the existing pattern above");
        $usedAnchor = $anchor;
        break;
    } catch (JsPatchFailure $e) {
        $anchorErrors[] = "{$anchor} -> {$e->getMessage()}";
    }
}

if ($patched === null) {
    fail("no anchor matched exactly once — refusing to guess. Tried:\n  " . implode("\n  ", $anchorErrors));
}

echo "OK — inserted after anchor: {$usedAnchor}\n";

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
