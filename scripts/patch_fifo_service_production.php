<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH — services/FifoService.php.
 *
 * Companion to patch_unit_conversion_service_production.php (apply that
 * one FIRST — this file calls UnitConversionService::resolveConversionFactor(),
 * which must already exist). Replaces, inside EACH of postIn() and
 * postOut() separately (function-scoped, never a whole-file text
 * replace — the old call line is byte-identical in both functions), the
 * old "getActiveConversion()+null-check+throw+read conversion_to_base"
 * block with "resolveConversionFactor()+null-check+throw". Nothing else
 * in either function changes.
 *
 * FAILS CLOSED: refuses to write anything unless the file's CURRENT
 * SHA256 exactly matches --expect-sha256, the fix is not already present
 * in either function, and each old block is found EXACTLY ONCE within
 * its own function's body (never a whole-file match, since the same
 * text appears in both postIn() and postOut()).
 *
 * Usage:
 *   php scripts/patch_fifo_service_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_fifo_service_production.php <path> --expect-sha256=<hash> --apply   (writes <path> + <path>.pre-patch-backup)
 */

require_once __DIR__ . '/lib/js_function_patch.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit(1);
}

/** Replaces $oldBlock with $newBlock, but ONLY within the named function's body, and only if it appears there exactly once. */
function replace_within_function(string $source, string $signaturePattern, string $oldBlock, string $newBlock): string
{
    [$start, $end] = find_function_bounds($source, $signaturePattern);
    $body = substr($source, $start, $end - $start);
    $count = substr_count($body, $oldBlock);
    if ($count !== 1) {
        throw new JsPatchFailure("expected block matched {$count} times within this function (expected exactly 1) — signature: {$signaturePattern}");
    }
    $patchedBody = str_replace($oldBlock, $newBlock, $body);
    return substr($source, 0, $start) . $patchedBody . substr($source, $end);
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
    fail('usage: php scripts/patch_fifo_service_production.php <path> --expect-sha256=<hash> [--apply]');
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

$oldBlockPostIn = <<<'PHP'
        $conversion = UnitConversionService::getActiveConversion($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($conversion === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }
        $factor = (float) $conversion['conversion_to_base'];

        $baseQty = round($p['input_qty'] * $factor, self::QTY_SCALE);
PHP;
$newBlockPostIn = <<<'PHP'
        $factor = UnitConversionService::resolveConversionFactor($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($factor === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }

        $baseQty = round($p['input_qty'] * $factor, self::QTY_SCALE);
PHP;

$oldBlockPostOut = <<<'PHP'
        $conversion = UnitConversionService::getActiveConversion($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($conversion === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }
        $factor = (float) $conversion['conversion_to_base'];
        $baseQtyRequested = round($p['input_qty'] * $factor, self::QTY_SCALE);
PHP;
$newBlockPostOut = <<<'PHP'
        $factor = UnitConversionService::resolveConversionFactor($pdo, $p['item_id'], $p['input_unit_id'], $p['transaction_date']);
        if ($factor === null) {
            throw new UnitConversionNotApprovedException((int) $p['item_id'], (int) $p['input_unit_id']);
        }
        $baseQtyRequested = round($p['input_qty'] * $factor, self::QTY_SCALE);
PHP;

try {
    $patched = replace_within_function($source, '/public static function postIn\s*\(/', $oldBlockPostIn, $newBlockPostIn);
    $patched = replace_within_function($patched, '/public static function postOut\s*\(/', $oldBlockPostOut, $newBlockPostOut);
} catch (JsPatchFailure $e) {
    fail($e->getMessage());
}

echo "OK — patched postIn() and postOut().\n";

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
