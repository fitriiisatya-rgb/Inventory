<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCH — public/assets/js/master-items.js
 *
 * Fixes the Edit Barang "No Options" unit-dropdown bug. Root cause (see
 * the accompanying report): Base Unit / Unit Conversion read ONLY from
 * the shared Master.units() cache, which Master.loadAll()'s own CORE/
 * OPTIONAL split (already deployed) lets go empty SILENTLY whenever its
 * one GET /units call fails — independently of whichever CORE lists
 * succeeded and let the screen open at all. Satuan untuk Harga reads a
 * SEPARATE per-item list (GET /items/{id}/units) that can legitimately
 * come back with nothing beyond nothing for an item with no
 * item_unit_conversions row (a data gap, not a code bug).
 *
 * This patch, inside openEdit() only:
 *   1. Falls back to the item's own base unit (already known from the
 *      list row, server-provided) for "Satuan untuk Harga" when the
 *      per-item units call returns empty — never fabricates a unit,
 *      never guesses a conversion factor beyond the trivial identity (1).
 *   2. Retries Master.loadAll() ONCE if Master.units() is empty when
 *      Edit Barang opens, and refuses to open the modal with a VISIBLE
 *      error if it is still empty afterward — never a silent empty
 *      <select>.
 *
 * Does not touch any other function, any backend route, any DB data,
 * or Stock Opname/inventory logic.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, and unless each anchor below
 * is found EXACTLY ONCE within openEdit()'s own body.
 *
 * Usage:
 *   php scripts/patch_master_items_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_master_items_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_master_items_production.php <path> --expect-sha256=<hash> [--apply]');
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

$openEditSig = '/\basync\s+function\s+openEdit\s*\(\s*row\s*,\s*refreshDetail\s*=\s*false\s*\)\s*\{/';

$applied = [];
$skipped = [];
$patched = $source;

// Step 1: Satuan untuk Harga fallback — anchored on the itemUnits() call
// WITHIN openEdit() specifically (this call also appears in openDetail(),
// so a whole-file anchor would be ambiguous on purpose — scoping to
// openEdit() keeps it exact).
try {
    $patched = insert_before_line_containing_within_function(
        $patched,
        $openEditSig,
        'const categoryOptions = Master.categories().map',
        <<<'JS'
        // STABILIZATION — see openEdit()'s own top-of-function fetch of
        // `units` above: item_unit_conversions legitimately has nothing
        // beyond the base-unit identity row for most items; if even THAT
        // is missing, "Satuan untuk Harga" must never fall back to zero
        // options — the item's own base unit (already known via row.unit)
        // is always a valid pricing target. Never fabricates a conversion
        // factor beyond the trivial base-unit identity (1).
        if (units.length === 0 && row.unit) {
            units = [{ id: row.unit.id, code: row.unit.code, name: row.unit.name, conversion_to_base: 1, is_purchase_default: false, reference_price: null, price_source: null }];
        }

        // STABILIZATION — Master.units() is the shared cache
        // Master.loadAll() populates at app start; Base Unit and Unit
        // Conversion both read ONLY from it. If its own GET /units call
        // failed independently of whatever let this screen open at all,
        // blindly trusting an empty cache here renders three dropdowns
        // with zero options and no visible error. One retry, then a
        // visible, specific failure — never a silent empty <select>.
        if (Master.units().length === 0) {
            try { await Master.loadAll(); } catch (err) { /* loadAll() itself never throws — defensive only */ }
        }
        if (Master.units().length === 0) {
            UI.toast('Gagal memuat daftar satuan (GET /units) — Edit Barang tidak bisa dibuka. Coba muat ulang halaman atau hubungi IT.', 'error');
            return;
        }

JS
    );
    $applied[] = 'Satuan untuk Harga fallback + Master.units() retry/visible-failure guard (before categoryOptions, inside openEdit())';
} catch (JsPatchFailure $e) {
    $skipped[] = "openEdit() units guard: {$e->getMessage()}";
}

if (!$apply) {
    echo "\n--- APPLIED (" . count($applied) . ") ---\n" . implode("\n", array_map(fn ($s) => " - {$s}", $applied)) . "\n";
    echo "\n--- SKIPPED / NEEDS MANUAL REVIEW (" . count($skipped) . ") ---\n" . implode("\n", array_map(fn ($s) => " - {$s}", $skipped)) . "\n";
    echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to write {$path}.\n";
    echo "Resulting file would hash to: " . hash('sha256', $patched) . "\n";
    exit(0);
}

if ($applied === []) {
    fail('nothing could be applied (anchor not found) — refusing to write a no-op patch');
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
