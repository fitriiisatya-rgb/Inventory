<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — public/assets/js/transfers.js.
 *
 * Root cause: "Stok Tersedia" always rendered the raw BASE quantity
 * (entry.stockBase) regardless of which input unit was selected — e.g.
 * RM-KJ-26-004 showed 192 for KG, KARTON, and CTN alike, even though
 * 1 KARTON = 32 KG means only 6 KARTON are actually available. This is
 * a display-only defect: entry.stockBase itself (and every inventory
 * balance behind it) is never touched.
 *
 * Fix: adds two small helpers — currentUnitFactor() (reads the
 * currently-selected unit's conversion_to_base straight from
 * ItemSelector's own already-loaded state, exactly as checkQtyWarning()
 * already did) and renderStockDisplay() (renders
 * entry.stockBase / currentUnitFactor(idx)) — and uses them in three
 * places: refreshStockFor()'s render step, and checkQtyWarning()'s own
 * factor lookup + the number shown inside its warning text. The
 * base-vs-base insufficient-stock COMPARISON itself (qty * factor >
 * stockBase) is already correct and is NOT changed by this patch —
 * only what gets displayed.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the fix is not already
 * present, and each of the four anchors is found exactly once.
 *
 * Usage:
 *   php scripts/patch_transfers_stock_display_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_transfers_stock_display_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_transfers_stock_display_production.php <path> --expect-sha256=<hash> [--apply]');
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

if (str_contains($source, 'currentUnitFactor') || str_contains($source, 'renderStockDisplay')) {
    fail('currentUnitFactor/renderStockDisplay already present in this file — refusing to patch a second time.');
}

// --- Step 1: refreshStockFor() renders via the new helper, not a raw base number. ---
$oldRender = "            current.stockCell.textContent = UI.formatNumber(current.stockBase);";
$newRender = "            renderStockDisplay(idx);";
$count = substr_count($source, $oldRender);
if ($count !== 1) {
    fail("render anchor matched {$count} times (expected exactly 1)");
}
$patched = str_replace($oldRender, $newRender, $source);

// --- Step 2: checkQtyWarning() uses the (soon-to-exist) shared helper
// instead of its own inline lookup. Done BEFORE inserting the helper
// functions below — currentUnitFactor()'s own body contains this exact
// 3-line pattern too, so doing this after insertion would match twice. ---
$oldFactorBlock = <<<'JS'
        const state = entry.ctl.getState();
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));
        const factor = unit ? Number(unit.conversion_to_base) : 1;
JS;
$newFactorBlock = "        const factor = currentUnitFactor(idx);";
$count = substr_count($patched, $oldFactorBlock);
if ($count !== 1) {
    fail("checkQtyWarning factor-lookup anchor matched {$count} times (expected exactly 1)");
}
$patched = str_replace($oldFactorBlock, $newFactorBlock, $patched);

// --- Step 3: insert the two new helper functions before checkQtyWarning(). ---
$helpers = <<<'JS'
    // STABILIZATION — the conversion_to_base of whichever unit is
    // CURRENTLY selected in this line's ItemSelector — reads straight
    // from its own already-loaded state (never a new fetch). Base unit
    // itself always carries conversion_to_base = 1.
    function currentUnitFactor(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return 1;
        const state = entry.ctl.getState();
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));
        const factor = unit ? Number(unit.conversion_to_base) : 1;
        return factor > 0 ? factor : 1;
    }

    // STABILIZATION — renders entry.stockBase (the real, unconverted
    // base quantity — never mutated) divided by the selected unit's
    // factor: available_in_selected_unit = available_base_qty /
    // conversion_to_base. UI.formatNumber() already caps at 2 decimals,
    // the same convention every other quantity on this screen uses.
    function renderStockDisplay(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        if (entry.stockBase === null) { entry.stockCell.textContent = '-'; return; }
        entry.stockCell.textContent = UI.formatNumber(entry.stockBase / currentUnitFactor(idx));
    }

JS;

try {
    $patched = insert_before_line_containing($patched, '    function checkQtyWarning(idx) {', rtrim($helpers));
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

// --- Step 4: the warning text shows the SAME selected-unit-converted number "Stok Tersedia" now shows. ---
$oldWarnText = '            entry.qtyWarn.textContent = `⚠ Melebihi stok tersedia (${UI.formatNumber(entry.stockBase)})`;';
$newWarnText = '            entry.qtyWarn.textContent = `⚠ Melebihi stok tersedia (${UI.formatNumber(entry.stockBase / factor)})`;';
$count = substr_count($patched, $oldWarnText);
if ($count !== 1) {
    fail("warning-text anchor matched {$count} times (expected exactly 1)");
}
$patched = str_replace($oldWarnText, $newWarnText, $patched);

echo "OK — all four steps applied (render, helpers inserted, factor lookup, warning text).\n";

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
