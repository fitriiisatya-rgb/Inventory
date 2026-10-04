<?php
declare(strict_types=1);

/**
 * PRODUCTION ROLLBACK for the Master Data "Tambah ..." package — returns production to the exact files it had before.
 *
 * Reads the <target>.mdm-patch.json state files and
 *   restores     public/index.php, public/index.html, public/assets/css/app.css, public/assets/js/api-client.js,
 *                public/assets/js/master-{common,categories,vendors,bakery-destinations,items,warehouses,divisions}.js,
 *                services/SupplierService.php, services/BakeryDestinationService.php
 *   deletes      services/MasterRecordService.php   (the one NEW file)
 *
 * TWO-PHASE and FAIL-CLOSED: phase 1 verifies EVERY target — all fourteen must have a state file; each must still be
 * byte-identical to what the package wrote (nobody edited it since); each backup must still hash to the recorded
 * preimage — before phase 2 touches ANY of them. If anything is off, nothing is changed. Other packages' state files
 * (Jejak / Dashboard / Stock IN-OUT V2 / UI2) are never read or modified. Records created through the new screens
 * (items, warehouses, divisions, suppliers, bakeries, categories) are DATA: a rollback never touches them — see the README.
 * Dry-run by default.
 *
 * Usage:
 *   php scripts/rollback_mdm_production.php --public-dir=<public/> --services-dir=<services/>          (dry run)
 *   php scripts/rollback_mdm_production.php --public-dir=<public/> --services-dir=<services/> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-mdm-backup');
define('JP_META_SUFFIX', '.mdm-patch.json');
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
    "{$public}/index.php",
    "{$public}/index.html",
    "{$public}/assets/css/app.css",
    "{$public}/assets/js/api-client.js",
    "{$public}/assets/js/master-common.js",
    "{$public}/assets/js/master-categories.js",
    "{$public}/assets/js/master-vendors.js",
    "{$public}/assets/js/master-bakery-destinations.js",
    "{$public}/assets/js/master-items.js",
    "{$public}/assets/js/master-warehouses.js",
    "{$public}/assets/js/master-divisions.js",
    "{$services}/SupplierService.php",
    "{$services}/BakeryDestinationService.php",
    "{$services}/MasterRecordService.php",
];

// ---- phase 1: verify everything, change nothing ----
$plan = [];
foreach ($targets as $path) {
    $label = basename($path);
    $metaFile = jp_meta_path($path);
    if (!file_exists($metaFile)) {
        jp_fail("{$label}: no Master Data state file ({$metaFile}) — not patched by this package (or already rolled back). Aborting; nothing changed.");
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($meta) || empty($meta['postimage_sha256'])) {
        jp_fail("{$label}: state file unreadable/incomplete. Aborting; nothing changed.");
    }
    if (!is_file($path) || hash_file('sha256', $path) !== $meta['postimage_sha256']) {
        jp_fail("{$label}: current file no longer matches what the Master Data package wrote (edited since?). Aborting; nothing changed.");
    }
    if (!empty($meta['new_file'])) {
        $plan[] = ['kind' => 'delete', 'path' => $path, 'meta' => $metaFile, 'label' => $label];
        echo "OK — {$label}: new file, will be removed.\n";
        continue;
    }
    $backup = jp_backup_path($path);
    if (empty($meta['preimage_sha256']) || !is_file($backup) || hash_file('sha256', $backup) !== $meta['preimage_sha256']) {
        jp_fail("{$label}: Master Data backup missing or does not match the recorded preimage. Aborting; nothing changed.");
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
echo "\nROLLBACK COMPLETE — production is back on the files it had before the Master Data package.\n";
