<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCH — master.js
 *
 * Upgrades production's CURRENT master.js (which production confirmed
 * already carries a TEMPORARY, hand-applied resilience hotfix from the
 * incident — NOT this branch's clean fix, and NOT the original
 * Promise.all()) to the final Promise.allSettled() + explicit
 * CORE/OPTIONAL `Master.loadAll()` from commit 9ca81cd.
 *
 * SCOPE: this script touches ONLY the `loadAll()` function body. Every
 * other line in the file — Edit Barang's units()/unitById() additions,
 * whatever the incident hotfix did elsewhere, anything else — is left
 * completely untouched, byte for byte.
 *
 * FAILS CLOSED:
 *   - Refuses to write anything unless the file's CURRENT SHA256
 *     exactly matches --expect-sha256 (the hash production itself
 *     reported). A mismatch means production has moved on since that
 *     hash was taken, or you pointed this at the wrong file — either
 *     way, this script does nothing rather than guess.
 *   - Refuses to write anything unless `function loadAll(` (async or
 *     not) appears in the file EXACTLY ONCE with balanced braces.
 *
 * Usage:
 *   php scripts/patch_master_js_production.php <path-to-master.js> --expect-sha256=<hash>
 *     (dry-run: reports what WOULD happen, writes nothing, exits 0)
 *   php scripts/patch_master_js_production.php <path-to-master.js> --expect-sha256=<hash> --apply
 *     (writes <path>, and <path>.pre-patch-backup containing the
 *      original bytes, then prints the new file's SHA256)
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
    fail('usage: php scripts/patch_master_js_production.php <path-to-master.js> --expect-sha256=<hash> [--apply]');
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

    $newLoadAll = <<<'JS'
async function loadAll() {
        const [
            itemsR, warehousesR, suppliersR, categoriesR,
            divisionsR, bakeryR, barcodesR, unitsR,
        ] = await Promise.allSettled([
            InvApi.listItems(),
            InvApi.listWarehouses(),
            InvApi.listSuppliers(),
            InvApi.listCategories(),
            InvApi.listDivisions(),
            InvApi.listBakeryDestinations(),
            InvApi.listItemBarcodes(),
            InvApi.listUnits(),
        ]);

        // STABILIZATION — CORE lists (items/warehouses/suppliers/categories)
        // are what the rest of the app cannot function without; each is
        // assigned independently, so one failing call can never blank out
        // another list whose own fetch actually succeeded (the production
        // "No Options" warehouse-dropdown incident). OPTIONAL lists degrade
        // to an empty array on their own failure only.
        items = settle(itemsR, 'items');
        warehouses = settle(warehousesR, 'warehouses');
        suppliers = settle(suppliersR, 'suppliers');
        categories = settle(categoriesR, 'categories');
        divisions = settle(divisionsR, 'divisions');
        bakeryDestinations = settle(bakeryR, 'bakeryDestinations');
        itemBarcodes = settle(barcodesR, 'itemBarcodes');
        units = settle(unitsR, 'units');

        return { items, warehouses, suppliers, divisions, categories, bakeryDestinations, itemBarcodes, units };
    }
JS;

    $settleHelper = <<<'JS'
    function settle(result, label) {
        if (result.status === 'fulfilled') {
            return result.value;
        }
        // eslint-disable-next-line no-console
        console.error(`Master.loadAll(): "${label}" failed to load — degrading to an empty list rather than blocking every other master list.`, result.reason);
        return [];
    }

JS;

    $patched = replace_function($source, '/\basync\s+function\s+loadAll\s*\(\s*\)\s*\{/', $newLoadAll);
    echo "OK — loadAll() located exactly once and replaced.\n";

    if (!str_contains($patched, 'function settle(result, label)')) {
        $patched = insert_before_line_containing($patched, 'async function loadAll()', $settleHelper);
        echo "OK — settle() helper inserted immediately before loadAll().\n";
    } else {
        echo "OK — a settle(result, label) helper already exists in this file; not duplicating it. VERIFY MANUALLY that it matches the semantics above.\n";
    }
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

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
