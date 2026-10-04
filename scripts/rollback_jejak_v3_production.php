<?php
declare(strict_types=1);

/**
 * PRODUCTION ROLLBACK for the Jejak v3 package — returns production to the
 * Jejak v2 state it was in before v3 was applied.
 *
 * Reads the <target>.jejak-v3-patch.json state files and, for
 *   public/assets/js/stock-opname-report-jejak.js   restores the v2 module
 *   public/assets/css/app.css                        restores the v2 CSS
 *   public/index.html                                restores the v2 tokens
 *   public/index.php                                 removes the new route + require
 *   services/StockOpnameJejakService.php             deletes the NEW file
 *
 * TWO-PHASE and FAIL-CLOSED: phase 1 verifies EVERY target — all five must
 * have a v3 state file; each must still be byte-identical to what v3 wrote
 * (nobody edited it since); each backup must still hash to the recorded
 * preimage — before phase 2 touches ANY of them. If anything is off,
 * nothing is changed. v2's own state files (.pre-patch-backup /
 * .jejak-patch.json) are never read or modified here. Dry-run by default.
 *
 * Usage:
 *   php scripts/rollback_jejak_v3_production.php --public-dir=<public/> --services-dir=<services/>          (dry run)
 *   php scripts/rollback_jejak_v3_production.php --public-dir=<public/> --services-dir=<services/> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-v3-backup');
define('JP_META_SUFFIX', '.jejak-v3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$public = null;
$services = null;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--public-dir=')) {
        $public = rtrim(substr($arg, strlen('--public-dir=')), '/');
    } elseif (str_starts_with($arg, '--services-dir=')) {
        $services = rtrim(substr($arg, strlen('--services-dir=')), '/');
    } else {
        jp_fail("unknown argument: {$arg}");
    }
}
if ($public === null || $services === null || !is_dir($public) || !is_dir($services)) {
    jp_fail('--public-dir=<existing public/> and --services-dir=<existing services/> are both required');
}

$targets = [
    "{$public}/assets/js/stock-opname-report-jejak.js",
    "{$public}/assets/css/app.css",
    "{$public}/index.html",
    "{$public}/index.php",
    "{$services}/StockOpnameJejakService.php",
];

// ---- phase 1: verify everything, change nothing ----
$plan = [];
foreach ($targets as $path) {
    $label = basename($path);
    $metaFile = jp_meta_path($path);
    if (!file_exists($metaFile)) {
        jp_fail("{$label}: no v3 state file ({$metaFile}) — not patched by v3 (or already rolled back). Aborting; nothing changed.");
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($meta) || empty($meta['postimage_sha256'])) {
        jp_fail("{$label}: state file unreadable/incomplete. Aborting; nothing changed.");
    }
    if (!is_file($path) || hash_file('sha256', $path) !== $meta['postimage_sha256']) {
        jp_fail("{$label}: current file no longer matches what v3 wrote (edited since?). Aborting; nothing changed.");
    }
    if (!empty($meta['new_file'])) {
        $plan[] = ['kind' => 'delete', 'path' => $path, 'meta' => $metaFile, 'label' => $label];
        echo "OK — {$label}: new file, will be removed.\n";
        continue;
    }
    $backup = jp_backup_path($path);
    if (empty($meta['preimage_sha256']) || !is_file($backup) || hash_file('sha256', $backup) !== $meta['preimage_sha256']) {
        jp_fail("{$label}: v3 backup missing or does not match the recorded preimage. Aborting; nothing changed.");
    }
    $plan[] = ['kind' => 'restore', 'path' => $path, 'backup' => $backup, 'meta' => $metaFile, 'pre' => $meta['preimage_sha256'], 'label' => $label];
    echo "OK — {$label}: will restore preimage {$meta['preimage_sha256']}\n";
}

if (!$apply) {
    echo "\nDRY RUN ONLY — nothing was changed. Re-run with --apply to roll back.\n";
    exit(0);
}

// ---- phase 2 ----
foreach ($plan as $p) {
    if ($p['kind'] === 'delete') {
        unlink($p['path']);
        unlink($p['meta']);
        echo "REMOVED {$p['label']}\n";
        continue;
    }
    if (!copy($p['backup'], $p['path']) || hash_file('sha256', $p['path']) !== $p['pre']) {
        jp_fail("{$p['label']}: restore failed or did not verify — STOP and restore from {$p['backup']} manually.");
    }
    unlink($p['meta']);
    echo "RESTORED {$p['label']} (preimage {$p['pre']}); backup kept at {$p['backup']}\n";
}
echo "\nROLLBACK COMPLETE — production is back on the Jejak v2 files.\n";
