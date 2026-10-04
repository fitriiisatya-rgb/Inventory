<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/assets/js/stock-opname-report.js.
 *
 * Adds the row-click wiring that opens the new "Jejak Stock Opname"
 * PREVIEW mockup drawer (stock-opname-report-jejak.js — deployed
 * separately as a NEW file, see
 * COPY_stock_opname_report_jejak_production.txt) when a session row is
 * clicked. Ignores clicks on the existing "Lihat Detail"/Print/Excel
 * action buttons so both interactions coexist on the same row without
 * conflict. Does NOT touch renderDetail(), buildActionButtons(), or any
 * other existing behavior of this file.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the fix is not already
 * present, and each anchor is found exactly once.
 *
 * Usage:
 *   php scripts/patch_stock_opname_report_jejak_row_click_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_stock_opname_report_jejak_row_click_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_stock_opname_report_jejak_row_click_production.php <path> --expect-sha256=<hash> [--apply]');
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

if (str_contains($source, 'StockOpnameJejak')) {
    fail('StockOpnameJejak reference already present in this file — refusing to patch a second time.');
}

// --- Step 1: mark the row as clickable (cursor + tooltip only; no behavior yet). ---
$oldRowOpen = <<<'JS'
            sessions.forEach((s, idx) => {
                const tr = UI.el('tr', {}, [
JS;
$newRowOpen = <<<'JS'
            sessions.forEach((s, idx) => {
                const tr = UI.el('tr', { style: 'cursor:pointer;', title: 'Klik untuk lihat jejak' }, [
JS;
$count = substr_count($source, $oldRowOpen);
if ($count !== 1) {
    fail("row-open anchor matched {$count} times (expected exactly 1)");
}
$patched = str_replace($oldRowOpen, $newRowOpen, $source);

// --- Step 2: insert the click listener right before the row is appended. ---
$anchor = '                tbody.appendChild(tr);';
$count = substr_count($patched, $anchor);
if ($count !== 1) {
    fail("tbody.appendChild(tr) anchor matched {$count} times (expected exactly 1)");
}
$insert = <<<'JS'
                // MOCKUP — row click opens the "Jejak Stock Opname" detail
                // drawer (stock-opname-report-jejak.js), separate from the
                // existing "Lihat Detail"/Print/Excel buttons below, which
                // keep calling the real backend exactly as before. Ignores
                // clicks that originate from those action buttons so both
                // interactions coexist on the same row without conflict.
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('button')) return;
                    if (typeof StockOpnameJejak !== 'undefined') StockOpnameJejak.open(s);
                });
JS;
try {
    $patched = insert_before_line_containing($patched, $anchor, $insert);
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

echo "OK — both steps applied (clickable row style, click listener inserted).\n";

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
