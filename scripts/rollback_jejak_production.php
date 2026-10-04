<?php
declare(strict_types=1);

/**
 * PRODUCTION ROLLBACK for the Jejak Stock Opname package.
 *
 * Reads the <target>.jejak-patch.json state files the patchers/installer
 * wrote and, for the three patched files (assets/js/report-opname.js,
 * assets/css/app.css, index.html), restores the byte-exact backup; for the
 * new assets/js/stock-opname-report-jejak.js, deletes it.
 *
 * TWO-PHASE and FAIL-CLOSED: phase 1 verifies EVERY target before phase 2
 * touches ANY of them. A target is only reverted if
 *   - its current SHA256 still equals the postimage recorded when it was
 *     patched (nobody has edited it since), and
 *   - for patched files, the backup still hashes to the recorded preimage.
 * If anything is off, nothing is changed. Dry-run by default.
 *
 * Usage:
 *   php scripts/rollback_jejak_production.php --public-dir=<path to public/>          (dry run)
 *   php scripts/rollback_jejak_production.php --public-dir=<path to public/> --apply
 */

require_once __DIR__ . '/lib/jejak_patch_common.php';

$publicDir = null;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--public-dir=')) {
        $publicDir = rtrim(substr($arg, strlen('--public-dir=')), '/');
    } else {
        jp_fail("unknown argument: {$arg}\nusage: php scripts/rollback_jejak_production.php --public-dir=<dir> [--apply]");
    }
}
if ($publicDir === null || !is_dir($publicDir)) {
    jp_fail('--public-dir=<existing public/ directory> is required');
}

$patched = ['assets/js/report-opname.js', 'assets/css/app.css', 'index.html'];
$newFile = 'assets/js/stock-opname-report-jejak.js';
$plan = [];

// ---- phase 1: verify everything, change nothing ----
foreach ($patched as $rel) {
    $path = "{$publicDir}/{$rel}";
    $metaFile = jp_meta_path($path);
    if (!file_exists($metaFile)) {
        jp_fail("{$rel}: no state file ({$metaFile}) — it was not patched by this package (or already rolled back). Aborting; nothing changed.");
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($meta) || empty($meta['preimage_sha256']) || empty($meta['postimage_sha256'])) {
        jp_fail("{$rel}: state file is unreadable/incomplete. Aborting; nothing changed.");
    }
    if (!is_file($path) || hash_file('sha256', $path) !== $meta['postimage_sha256']) {
        jp_fail("{$rel}: current file no longer matches the patched postimage (edited since?). Aborting; nothing changed.");
    }
    $backup = jp_backup_path($path);
    if (!is_file($backup) || hash_file('sha256', $backup) !== $meta['preimage_sha256']) {
        jp_fail("{$rel}: backup missing or does not match the recorded preimage. Aborting; nothing changed.");
    }
    $plan[] = ['rel' => $rel, 'path' => $path, 'backup' => $backup, 'meta' => $metaFile, 'pre' => $meta['preimage_sha256']];
    echo "OK — {$rel}: will restore preimage {$meta['preimage_sha256']}\n";
}
$newPath = "{$publicDir}/{$newFile}";
$newMeta = jp_meta_path($newPath);
$removeNew = false;
if (file_exists($newMeta)) {
    $meta = json_decode((string) file_get_contents($newMeta), true);
    if (!is_array($meta) || !is_file($newPath) || hash_file('sha256', $newPath) !== ($meta['postimage_sha256'] ?? '')) {
        jp_fail("{$newFile}: current file does not match what the installer wrote. Aborting; nothing changed.");
    }
    $removeNew = true;
    echo "OK — {$newFile}: will be removed.\n";
} else {
    echo "NOTE — {$newFile}: no state file; not touching it.\n";
}

if (!$apply) {
    echo "\nDRY RUN ONLY — nothing was changed. Re-run with --apply to roll back.\n";
    exit(0);
}

// ---- phase 2 ----
foreach ($plan as $p) {
    if (!copy($p['backup'], $p['path']) || hash_file('sha256', $p['path']) !== $p['pre']) {
        jp_fail("{$p['rel']}: restore failed or did not verify — STOP and restore from {$p['backup']} manually.");
    }
    unlink($p['meta']);
    echo "RESTORED {$p['rel']} (preimage {$p['pre']}); backup kept at {$p['backup']}\n";
}
if ($removeNew) {
    unlink($newPath);
    unlink($newMeta);
    echo "REMOVED {$newFile}\n";
}
echo "\nROLLBACK COMPLETE. index.html is back on production's original cache-bust tokens, so browsers fetch the original assets again.\n";
