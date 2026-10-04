<?php
declare(strict_types=1);

/**
 * PRODUCTION ROLLBACK for the Sidebar "Laporan" cleanup package — restores public/index.html to the exact bytes it had before.
 *
 * TWO-PHASE and FAIL-CLOSED: phase 1 verifies (state file present, current file still byte-identical to what the package
 * wrote — nobody edited it since, e.g. a LATER package that moved its cache tokens: roll that one back first —, backup still
 * hashes to the recorded preimage) before phase 2 touches anything. Other packages' state files are never read or modified.
 * Nothing but navigation markup is involved; no data. Dry-run by default.
 *
 * Usage: php scripts/rollback_sbc_production.php --public-dir=<public/> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-sbc-backup');
define('JP_META_SUFFIX', '.sbc-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$public = null;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--public-dir=')) {
        $public = rtrim(substr($arg, strlen('--public-dir=')), '/');
    } else {
        jp_fail("unknown argument: {$arg}");
    }
}
if ($public === null || !is_dir($public)) {
    jp_fail('--public-dir=<existing public/> is required');
}

$path = "{$public}/index.html";
$metaFile = jp_meta_path($path);
if (!file_exists($metaFile)) {
    jp_fail("index.html: no sidebar-cleanup state file ({$metaFile}) — not patched by this package (or already rolled back). Aborting; nothing changed.");
}
$meta = json_decode((string) file_get_contents($metaFile), true);
if (!is_array($meta) || empty($meta['postimage_sha256']) || empty($meta['preimage_sha256'])) {
    jp_fail('index.html: state file unreadable/incomplete. Aborting; nothing changed.');
}
if (!is_file($path) || hash_file('sha256', $path) !== $meta['postimage_sha256']) {
    jp_fail('index.html: current file no longer matches what the sidebar-cleanup package wrote (edited since — roll back any LATER package that changed index.html first). Aborting; nothing changed.');
}
$backup = jp_backup_path($path);
if (!is_file($backup) || hash_file('sha256', $backup) !== $meta['preimage_sha256']) {
    jp_fail('index.html: backup missing or does not match the recorded preimage. Aborting; nothing changed.');
}
echo "OK — index.html: will restore preimage {$meta['preimage_sha256']}\n";
if (!$apply) {
    echo "\nDRY RUN ONLY — nothing was changed. Re-run with --apply to roll back.\n";
    exit(0);
}
if (!copy($backup, $path) || hash_file('sha256', $path) !== $meta['preimage_sha256']) {
    jp_fail("restore failed or did not verify — STOP and restore from {$backup} manually.");
}
unlink($metaFile);
echo "RESTORED index.html (preimage {$meta['preimage_sha256']}); backup kept at {$backup}\n";
echo "\nROLLBACK COMPLETE — the sidebar is back to the full report list.\n";
