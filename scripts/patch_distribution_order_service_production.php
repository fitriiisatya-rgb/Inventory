<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — services/DistributionOrderService.php.
 *
 * Companion to patch_unit_conversion_service_production.php (apply that
 * one FIRST). Same fix as FifoService: DO create() required a REAL
 * item_unit_conversions row even for an item's own base unit; now uses
 * UnitConversionService::resolveConversionFactor(), which treats the
 * base unit as intrinsically factor 1. Nothing else in this file changes.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the fix is not already
 * present, and the exact 4-line anchor block is found exactly once in
 * the whole file.
 *
 * Usage:
 *   php scripts/patch_distribution_order_service_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_distribution_order_service_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
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
    fail('usage: php scripts/patch_distribution_order_service_production.php <path> --expect-sha256=<hash> [--apply]');
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

if (str_contains($source, 'resolveConversionFactor')) {
    fail('resolveConversionFactor already referenced in this file — refusing to patch a second time.');
}

$oldBlock = <<<'PHP'
            $conversion = UnitConversionService::getActiveConversion($pdo, $item['id'], (int) $line['input_unit_id'], $p['do_date']);
            if ($conversion === null) {
                throw new UnitConversionNotApprovedException($item['id'], (int) $line['input_unit_id']);
            }
            $qtyBase = round($qty * (float) $conversion['conversion_to_base'], 6);
PHP;
$newBlock = <<<'PHP'
            $factor = UnitConversionService::resolveConversionFactor($pdo, $item['id'], (int) $line['input_unit_id'], $p['do_date']);
            if ($factor === null) {
                throw new UnitConversionNotApprovedException($item['id'], (int) $line['input_unit_id']);
            }
            $qtyBase = round($qty * $factor, 6);
PHP;

$count = substr_count($source, $oldBlock);
if ($count !== 1) {
    fail("expected anchor block matched {$count} times (expected exactly 1)");
}
$patched = str_replace($oldBlock, $newBlock, $source);

echo "OK — patched create().\n";

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
