<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — services/UnitConversionService.php.
 *
 * Root cause (confirmed via production evidence: 661 real items have
 * base_unit_id with NO active item_unit_conversions row at
 * conversion_to_base=1 — normal data, not a handful of bad rows):
 * FifoService::postIn()/postOut(), DistributionOrderService::create(),
 * and PurchaseCostingGateway::preview() each independently required
 * UnitConversionService::getActiveConversion() to find a REAL stored row
 * even for an item's OWN base unit, and threw
 * UnitConversionNotApprovedException otherwise — even though
 * items.base_unit_id IS the canonical qty anchor and therefore
 * intrinsically has factor 1 by definition.
 *
 * Fix: adds ONE new method, resolveConversionFactor() — deliberately NOT
 * folded into getActiveConversion() itself, which write paths (Edit
 * Barang's PUT /items/{id}, the centralized import) depend on to keep
 * returning null for an unrecorded base-unit case, so they still know to
 * INSERT a new row when an admin explicitly adds one. Nothing else in
 * this file is touched; getActiveConversion() itself is byte-for-byte
 * unchanged.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, resolveConversionFactor does
 * not already exist (never double-add), and the exact anchor line
 * (the docblock immediately before changeBaseUnit()) is found exactly
 * once.
 *
 * Usage:
 *   php scripts/patch_unit_conversion_service_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_unit_conversion_service_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_unit_conversion_service_production.php <path> --expect-sha256=<hash> [--apply]');
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

if (preg_match('/function\s+resolveConversionFactor\s*\(/', $source)) {
    fail('resolveConversionFactor() already exists in this file — refusing to add it a second time.');
}

$anchor = '    /** Section 5: base unit itself may not change once the item has posted transactions. */';

$insertText = <<<'PHP'
    /**
     * STABILIZATION — resolves the conversion factor to use for item_id/
     * unit_id as of $asOf, for QTY/COST MATH ONLY (FifoService,
     * DistributionOrderService, PurchaseCostingGateway — i.e. every
     * caller that needs "what factor do I multiply by", not "does a
     * stored row exist").
     *
     * Root cause this fixes: items.base_unit_id is already the canonical
     * qty anchor for its item — by definition, 1 base unit = 1 base unit,
     * factor 1 — but FifoService/DistributionOrderService/
     * PurchaseCostingGateway each previously required getActiveConversion()
     * to find a REAL item_unit_conversions row even for the base unit
     * itself, and threw UnitConversionNotApprovedException otherwise.
     * item_unit_conversions was only ever meant to hold "the purchase/
     * middle unit being defined" (see its own schema comment) — a
     * base-unit identity row is optional, and production confirms 661
     * real items have no such row. This method returns 1.0 for the base
     * unit intrinsically, whether or not a row exists; every OTHER unit
     * is completely unchanged and still requires a real, approved,
     * currently-open row, returning null exactly as before when one is
     * missing — the caller still decides what to do with that null
     * (ValidationException/UnitConversionNotApprovedException, same as
     * always).
     *
     * Deliberately NOT folded into getActiveConversion() itself: that
     * method answers "does a REAL stored row exist?" and is also used by
     * write paths (Edit Barang's PUT /items/{id}, the centralized import)
     * to decide whether to INSERT a new row — those must keep seeing null
     * for an unrecorded base-unit case, or an admin explicitly adding a
     * real identity row through Edit Barang would be silently skipped as
     * a no-op the moment this method existed instead.
     */
    public static function resolveConversionFactor(PDO $pdo, int $itemId, int $unitId, string $asOf): ?float
    {
        $existing = self::getActiveConversion($pdo, $itemId, $unitId, $asOf);
        if ($existing !== null) {
            return (float) $existing['conversion_to_base'];
        }

        $itemStmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        $baseUnitId = (int) $itemStmt->fetchColumn();

        return $baseUnitId === $unitId ? 1.0 : null;
    }

PHP;

try {
    $patched = insert_before_line_containing($source, $anchor, rtrim($insertText) . "\n");
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

echo "OK — inserted before anchor: {$anchor}\n";

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
