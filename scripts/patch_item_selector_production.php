<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCH — public/assets/js/item-selector.js (or whatever
 * file production's own index.html actually references for this shared
 * component — CONFIRM FIRST, do not assume the unversioned filename is
 * active; see this round's own audit, which found transfers.js/
 * report-transfer.js unversioned-and-active but api-client-v2163eod.js/
 * stock-opname-v2163eod.js NOT, on this exact codebase).
 *
 * Root cause: GET /items/{id}/units only ever returns item_unit_conversions
 * rows (by design — see that route's own comment in index.php: the table
 * holds "the purchase/middle unit being defined", not the base unit).
 * ItemSelector's loadUnitsForItem() renders that result verbatim, so any
 * item with at least one real conversion defined but no open conversion
 * row for its OWN base unit (the exact shape confirmed for RM-TF-26-032 —
 * "KARTON and CTN only") silently never offers its base unit in ANY
 * ItemSelector-based picker, Transfer included.
 *
 * Fix: after fetching units from the endpoint, backfill the item's base
 * unit (via Master.itemById()/Master.unitById(), both already loaded by
 * Master.loadAll()) ONLY when it is not already present — appended, never
 * prepended, so today's default-selected option (first element, per the
 * endpoint's own ORDER BY) is never disturbed. Touches nothing else in
 * this file; does not touch the backend endpoint, the DB, TransferService,
 * FifoService, or StockOpnameService.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the fix is not already present
 * (searches for a stable marker unique to this patch), and the exact
 * anchor line `units = await InvApi.itemUnits(itemId);` is found EXACTLY
 * ONCE in the whole file (it is the one call site inside
 * loadUnitsForItem() — never guessed, never fuzzy-matched).
 *
 * Usage:
 *   php scripts/patch_item_selector_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_item_selector_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_item_selector_production.php <path> --expect-sha256=<hash> [--apply]');
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

// Refuse to double-patch: this exact marker string only ever exists once
// this fix has already been applied.
if (str_contains($source, 'STABILIZATION_TRANSFER_UNIT_BASE_BACKFILL')) {
    fail('base-unit backfill already present in this file (STABILIZATION_TRANSFER_UNIT_BASE_BACKFILL marker found) — refusing to add it a second time. If the dropdown is still missing the base unit, re-run the diagnosis, do not re-patch.');
}

$anchor = "units = await InvApi.itemUnits(itemId);";

$insertText = <<<'JS'
                // STABILIZATION_TRANSFER_UNIT_BASE_BACKFILL — GET /items/{id}/units
                // only ever returns item_unit_conversions rows (by design —
                // that table holds "the purchase/middle unit being defined",
                // not the base unit). The item's own base unit therefore
                // silently drops out of every unit picker built on this
                // endpoint whenever the item has at least one real
                // conversion defined. Backfill it here, once, for every
                // ItemSelector-based screen (Transfer included) — appended,
                // never prepended, so today's default-selected option
                // (first item, per the endpoint's own ORDER BY) is
                // unchanged; skipped entirely if the base unit already has
                // its own open conversion row, so it's never duplicated.
                const selectedItem = Master.itemById(itemId);
                const baseUnitId = selectedItem ? selectedItem.base_unit_id : null;
                if (baseUnitId !== null && baseUnitId !== undefined
                    && !units.some((u) => String(u.id) === String(baseUnitId))) {
                    const baseUnit = Master.unitById(baseUnitId);
                    if (baseUnit) {
                        units = units.concat([{
                            id: baseUnit.id,
                            code: baseUnit.code,
                            name: baseUnit.name,
                            conversion_to_base: 1,
                            is_purchase_default: false,
                            reference_price: null,
                            price_source: null,
                        }]);
                    }
                }
JS;

try {
    $patched = insert_after_line_containing($source, $anchor, $insertText);
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

echo "OK — inserted after anchor: {$anchor}\n";

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
