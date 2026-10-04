<?php
declare(strict_types=1);

/**
 * PRODUCTION INSTALL — NEW file public/assets/js/stock-opname-report-jejak.js
 *
 * Copies the Jejak drawer payload to its production path. There is no
 * production preimage (the file is new), so the safety gates are:
 *   - the payload's SHA256 must equal --expect-payload-sha256 (the value
 *     printed in the package's SHA256SUMS), proving the file being installed
 *     is exactly the tested one;
 *   - the target must NOT exist (double-apply / overwrite refusal — an
 *     existing file is never replaced; roll back first).
 * Dry-run by default. --apply writes atomically, re-verifies the bytes and
 * records <target>.jejak-patch.json for rollback_jejak_production.php.
 *
 * Usage:
 *   php scripts/install_stock_opname_report_jejak_production.php <payload.js> <target-path> --expect-payload-sha256=<hash>          (dry run)
 *   php scripts/install_stock_opname_report_jejak_production.php <payload.js> <target-path> --expect-payload-sha256=<hash> --apply
 */

require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/install_stock_opname_report_jejak_production.php <payload.js> <target-path> --expect-payload-sha256=<hash> [--apply]';
$args = $argv;
array_shift($args);
$apply = false;
$expect = null;
$positional = [];
foreach ($args as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--expect-payload-sha256=')) {
        $expect = strtolower(substr($arg, strlen('--expect-payload-sha256=')));
    } elseif (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    } else {
        jp_fail("unknown argument: {$arg}\nusage: {$usage}");
    }
}
if (count($positional) !== 2 || $expect === null || !preg_match('/^[0-9a-f]{64}$/', $expect)) {
    jp_fail("usage: {$usage}");
}
[$payloadPath, $target] = $positional;

$payload = jp_read($payloadPath);
$actual = hash('sha256', $payload);
if (!hash_equals($expect, $actual)) {
    jp_fail("payload SHA256 mismatch — refusing to install. expected={$expect} actual={$actual}");
}
echo "OK — payload hash matches ({$actual}).\n";

if (!str_contains($payload, 'const StockOpnameJejak')) {
    jp_fail('payload does not define StockOpnameJejak — wrong file.');
}
if (file_exists($target) || file_exists(jp_meta_path($target))) {
    jp_fail("{$target} (or its state file) already exists — refusing to overwrite. Roll back first (rollback_jejak_production.php).");
}
if (!is_dir(dirname($target))) {
    jp_fail('target directory does not exist: ' . dirname($target));
}

if (!$apply) {
    echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to create {$target}.\n";
    exit(0);
}

$tmp = $target . '.jejak-tmp';
if (file_put_contents($tmp, $payload) === false || !rename($tmp, $target)) {
    @unlink($tmp);
    jp_fail("could not write {$target}");
}
if (hash_file('sha256', $target) !== $actual) {
    @unlink($target);
    jp_fail("post-write verification failed — removed {$target}.");
}
file_put_contents(jp_meta_path($target), json_encode([
    'target' => basename($target), 'new_file' => true, 'postimage_sha256' => $actual, 'installed_at' => gmdate('c'),
], JSON_PRETTY_PRINT) . "\n");
echo "\nINSTALLED {$target}\nSHA256: {$actual}\n";
