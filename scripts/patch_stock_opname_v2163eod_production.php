<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCH — public/assets/js/stock-opname-v2163eod.js
 * (the file production actually loads for Stock Opname — NOT
 * public/assets/js/stock-opname.js, which production does not serve).
 *
 * We have never seen this file's bytes — only its confirmed SHA256 and
 * a human's confirmation that it contains loadForWarehouse(),
 * renderSession(), postOpname(), the literal call
 * InvApi.postOpname(currentSessionId, overrides), and button
 * disabled/re-enable logic. Every step below is a SEPARATE, narrowly
 * anchored insertion; each is independently skipped (never guessed)
 * if its anchor is not found EXACTLY once. Nothing outside these named
 * insertion points is touched.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256.
 *
 * Usage:
 *   php scripts/patch_stock_opname_v2163eod_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_stock_opname_v2163eod_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_stock_opname_v2163eod_production.php <path> --expect-sha256=<hash> [--apply]');
}
if (!is_file($path)) {
    fail("file not found: {$path}");
}
$source = file_get_contents($path);
if ($source === false) {
    fail("could not read: {$path}");
}

$skipped = [];
$applied = [];

try {
    assert_preimage_hash($source, $expectSha256);
    echo "OK — preimage hash matches. Proceeding.\n";
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

$patched = $source;

// Step 1: module-level renderGeneration token, inserted immediately
// before loadForWarehouse() (safe regardless of what else is in the
// enclosing module scope).
try {
    $decl = <<<'JS'
let renderGeneration = 0; // STABILIZATION — see loadForWarehouse()/renderSession() below

JS;
    $patched = insert_before_line_containing($patched, 'function loadForWarehouse(', $decl);
    $applied[] = 'renderGeneration declaration (before loadForWarehouse)';
} catch (JsPatchFailure $e) {
    $skipped[] = "renderGeneration declaration: {$e->getMessage()}";
}

// Step 2: capture generation at the top of loadForWarehouse().
try {
    $patched = insert_at_function_start(
        $patched,
        '/\bfunction\s+loadForWarehouse\s*\(\s*\)\s*\{/',
        "        const myGeneration = ++renderGeneration; // STABILIZATION\n"
    );
    $applied[] = 'myGeneration capture at top of loadForWarehouse()';
} catch (JsPatchFailure $e) {
    $skipped[] = "loadForWarehouse() generation capture: {$e->getMessage()}";
}

// Step 3: capture generation at the top of renderSession().
try {
    $patched = insert_at_function_start(
        $patched,
        '/\bfunction\s+renderSession\s*\(\s*sessionId\s*\)\s*\{/',
        "        const myGeneration = ++renderGeneration; // STABILIZATION\n        const stale = () => myGeneration !== renderGeneration;\n"
    );
    $applied[] = 'myGeneration capture + stale() helper at top of renderSession(sessionId)';
} catch (JsPatchFailure $e) {
    $skipped[] = "renderSession() generation capture: {$e->getMessage()}";
}

// Step 4: stale-check immediately after the FIRST await inside
// renderSession() — the call that fetches the session itself. This is
// the dominant path that caused the production double-button bug (the
// initial clear + status branch). If this file ALSO has further
// awaited sub-cards after that (like the dev branch's Reference SCM /
// Stok Buku SO cards), each needs the SAME one-line guard added
// manually — see MANUAL REVIEW ITEMS below; we never guess at code we
// have not seen.
try {
    $patched = insert_after_line_containing(
        $patched,
        'await InvApi.getOpname(sessionId)',
        '            if (stale()) return; // STABILIZATION — a newer render started while this fetch was in flight'
    );
    $applied[] = 'stale() check after the first await (InvApi.getOpname) in renderSession()';
} catch (JsPatchFailure $e) {
    $skipped[] = "renderSession() first-await stale check: {$e->getMessage()}";
}

// Step 5: postOpname() hardening — defensive re-entry guard + loading
// label + friendlier FINDINGS_V1_CHECKPOINT_B_REQUIRED message,
// anchored on the ONE line the human confirmed verbatim.
try {
    $patched = insert_before_line_containing(
        $patched,
        'InvApi.postOpname(currentSessionId, overrides)',
        <<<'JS'
        if (!btn || btn.disabled) return; // STABILIZATION — never double-submit
        const __stabOriginalLabel = btn.textContent;
        btn.textContent = 'Memposting…'; // STABILIZATION
JS
    );
    $applied[] = 'postOpname() re-entry guard + "Memposting…" label (before the InvApi.postOpname call)';
} catch (JsPatchFailure $e) {
    $skipped[] = "postOpname() entry guard/label: {$e->getMessage()}";
}
try {
    $patched = insert_after_line_containing(
        $patched,
        'InvApi.postOpname(currentSessionId, overrides)',
        "            btn.textContent = __stabOriginalLabel; // STABILIZATION — restore label on success path too\n            await renderSession(currentSessionId); // STABILIZATION — refresh the view (status -> POSTED) after a successful post"
    );
    $applied[] = 'postOpname() label restore + renderSession(currentSessionId) refresh after a successful post';
} catch (JsPatchFailure $e) {
    $skipped[] = "postOpname() label restore / post-success refresh: {$e->getMessage()}";
}

// Step 6 (best-effort — no human-confirmed anchor for postOpname()'s
// catch block exists, so this is independently skippable): a friendlier
// message for the EXISTING, unmodified FINDINGS_V1_CHECKPOINT_B_REQUIRED
// server gate, anchored on this codebase's own app-wide error-handling
// convention (UI.handleApiError(err)) wherever it appears inside
// postOpname(). Skipped cleanly (reported, not guessed) if that
// convention isn't present verbatim in this file.
try {
    [$postStart, $postEnd] = find_function_bounds($patched, '/\bfunction\s+postOpname\s*\(\s*\)\s*\{/');
    $postBody = substr($patched, $postStart, $postEnd - $postStart);
    if (substr_count($postBody, 'UI.handleApiError(err)') !== 1) {
        throw new JsPatchFailure('UI.handleApiError(err) not found exactly once inside postOpname()');
    }
    $patchedPostBody = str_replace(
        'UI.handleApiError(err)',
        "if (err && err.code === 'FINDINGS_V1_CHECKPOINT_B_REQUIRED') {\n" .
        "                UI.toast('Sesi Team/Findings ini belum bisa diposting lewat tombol ini — gunakan proses rekonsiliasi EOD dan hubungi supervisor/IT untuk posting terkontrol.', 'error');\n" .
        "            } else {\n" .
        "                UI.handleApiError(err);\n" .
        '            }',
        $postBody
    );
    $patched = substr($patched, 0, $postStart) . $patchedPostBody . substr($patched, $postEnd);
    $applied[] = 'friendly FINDINGS_V1_CHECKPOINT_B_REQUIRED message in postOpname() catch block';
} catch (JsPatchFailure $e) {
    $skipped[] = "friendly FINDINGS_V1_CHECKPOINT_B_REQUIRED message: {$e->getMessage()}";
}

if (!$apply) {
    echo "\n--- APPLIED (" . count($applied) . ") ---\n" . implode("\n", array_map(fn ($s) => " - {$s}", $applied)) . "\n";
    echo "\n--- SKIPPED / NEEDS MANUAL REVIEW (" . count($skipped) . ") ---\n" . implode("\n", array_map(fn ($s) => " - {$s}", $skipped)) . "\n";
    echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to write {$path}.\n";
    if ($skipped !== []) {
        echo "NOTE: skipped steps mean their exact anchor text was not found (0 or 2+ times) in this file.\n";
        echo "Apply those manually per DEPLOYMENT.md's \"manual review\" section, then re-run this\n";
        echo "script with the SAME --expect-sha256 to confirm it still fails closed afterward.\n";
    }
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

echo "\nAPPLIED (" . count($applied) . " steps). Backup saved at: {$backupPath}\n" . implode("\n", array_map(fn ($s) => " - {$s}", $applied)) . "\n";
if ($skipped !== []) {
    echo "\nSKIPPED / NEEDS MANUAL REVIEW (" . count($skipped) . "):\n" . implode("\n", array_map(fn ($s) => " - {$s}", $skipped)) . "\n";
}
echo "\nNew SHA256: " . hash('sha256', $patched) . "\n";
