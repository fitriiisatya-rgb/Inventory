<?php
declare(strict_types=1);

/**
 * PRODUCTION INSTALL (UI2) — REPLACES one whole file with the package copy:
 *   public/assets/js/dashboard.js, public/assets/js/sidebar.js, services/DashboardInventoryService.php
 *
 * Gates (all fail closed):
 *   --expect-payload-sha256   the package file being installed is exactly the tested one
 *   --replace-expect-sha256   the target's CURRENT SHA256 must equal it (production's hash, collected by
 *                             collect_production_hashes_ui2.sh); a file that differs is never overwritten
 *   (without --replace-expect-sha256 the target must NOT exist — never used by this package)
 * Dry-run by default; --apply backs up (<target>.pre-ui2-backup), writes atomically, re-verifies and records
 * <target>.ui2-patch.json for rollback_ui2_production.php.
 *
 * Usage:
 *   php scripts/install_ui2_files_production.php <payload> <target> --expect-payload-sha256=<hash> --replace-expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-ui2-backup');
define('JP_META_SUFFIX', '.ui2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/install_ui2_files_production.php <payload> <target> --expect-payload-sha256=<hash> [--replace-expect-sha256=<hash>] [--apply]';
$apply = false;
$expectPayload = null;
$replaceExpect = null;
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--expect-payload-sha256=')) {
        $expectPayload = strtolower(substr($arg, strlen('--expect-payload-sha256=')));
    } elseif (str_starts_with($arg, '--replace-expect-sha256=')) {
        $replaceExpect = strtolower(substr($arg, strlen('--replace-expect-sha256=')));
    } elseif (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    } else {
        jp_fail("unknown argument: {$arg}\nusage: {$usage}");
    }
}
$hex = static fn (?string $v): bool => $v !== null && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
if (count($positional) !== 2 || !$hex($expectPayload) || !$hex($replaceExpect)) {
    jp_fail("usage: {$usage}");
}
[$payloadPath, $target] = $positional;

$payload = jp_read($payloadPath);
$actual = hash('sha256', $payload);
if (!hash_equals($expectPayload, $actual)) {
    jp_fail("payload SHA256 mismatch — refusing to install. expected={$expectPayload} actual={$actual}");
}
echo "OK — payload hash matches ({$actual}).\n";

if (file_exists(jp_meta_path($target)) || file_exists(jp_backup_path($target))) {
    jp_fail('a UI2 state file (.ui2-patch.json / .pre-ui2-backup) already exists beside the target — refusing to apply over a previous run. Roll back first.');
}

if ($replaceExpect !== null) {
    // REPLACE mode
    $current = jp_read($target);
    jp_assert_preimage($current, $replaceExpect);
    if ($current === $payload) {
        jp_fail('target already equals the payload — nothing to do; refusing.');
    }
    jp_finish($target, $current, $payload, $apply);
    exit(0);
}

// NEW-file mode
if (file_exists($target)) {
    jp_fail("{$target} already exists — refusing to overwrite (use --replace-expect-sha256 to replace a known file).");
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
