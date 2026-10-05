<?php
declare(strict_types=1);

/**
 * PRODUCTION ROLLBACK for the Laporan IN / OUT / Transfer package — returns production to the exact files it had before.
 *
 * Reads the <target>.io-patch.json state files and
 *   restores     public/index.php, public/index.html (incl. the exact previous tokens), public/assets/css/app.css, public/assets/js/app.js
 *   deletes      public/assets/js/report-io.js and services/InOutReportService.php   (the two NEW files; a REPLACEd report js is restored instead)
 *
 * TWO-PHASE and FAIL-CLOSED: phase 1 verifies EVERY target — all six must have a state file; each must still be
 * byte-identical to what the package wrote (nobody edited it since); each backup must still hash to the recorded
 * preimage — before phase 2 touches ANY of them. If anything is off, nothing is changed. Other packages' state files
 * (Jejak / Dashboard / Stock IN-OUT V2 / UI2 / Master Data / sidebar cleanup / MVR / Pembelian / Nilai Stok & HPP / Stock Opname) are never read or modified. The report is read-only: it never created data, so there is no data to roll back.
 * Dry-run by default.
 *
 * Usage:
 *   php scripts/rollback_io_production.php --public-dir=<public/> --services-dir=<services/>          (dry run)
 *   php scripts/rollback_io_production.php --public-dir=<public/> --services-dir=<services/> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-io-backup');
define('JP_META_SUFFIX', '.io-patch.json');
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
    "{$public}/assets/js/app.js",
    "{$public}/assets/js/report-io.js",
    "{$services}/InOutReportService.php",
];

// ---- phase 1: verify everything, change nothing ----
$plan = [];
foreach ($targets as $path) {
    $label = basename($path);
    $metaFile = jp_meta_path($path);
    if (!file_exists($metaFile)) {
        jp_fail("{$label}: no Laporan IN / OUT / Transfer state file ({$metaFile}) — not patched by this package (or already rolled back). Aborting; nothing changed.");
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($meta) || empty($meta['postimage_sha256'])) {
        jp_fail("{$label}: state file unreadable/incomplete. Aborting; nothing changed.");
    }
    if (!is_file($path) || hash_file('sha256', $path) !== $meta['postimage_sha256']) {
        jp_fail("{$label}: current file no longer matches what the Laporan IN / OUT / Transfer package wrote (edited since?). Aborting; nothing changed.");
    }
    if (!empty($meta['new_file'])) {
        $plan[] = ['kind' => 'delete', 'path' => $path, 'meta' => $metaFile, 'label' => $label];
        echo "OK — {$label}: new file, will be removed.\n";
        continue;
    }
    $backup = jp_backup_path($path);
    if (empty($meta['preimage_sha256']) || !is_file($backup) || hash_file('sha256', $backup) !== $meta['preimage_sha256']) {
        jp_fail("{$label}: Laporan IN / OUT / Transfer backup missing or does not match the recorded preimage. Aborting; nothing changed.");
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
echo "\nROLLBACK COMPLETE — production is back on the files it had before the Laporan IN / OUT / Transfer package.\n";
