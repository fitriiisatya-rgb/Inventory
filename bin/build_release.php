<?php
declare(strict_types=1);

/**
 * CLI: php bin/build_release.php [output.zip]
 * Builds a shared-hosting-ready deployment ZIP: only what production
 * needs to run, nothing dev-only. Never requires DB/bootstrap — pure
 * filesystem copy, safe to run without a configured environment.
 *
 * Excluded on purpose:
 *   .git/, tests/, inventory.html, trace-stok-awal.js  — legacy
 *     reference material and this repo's own dev tooling, not part of
 *     the app being deployed.
 *   config/config.php                                  — real secrets;
 *     the target server gets its own from config.sample.php.
 *   bin/clear_test_data.php                             — destructive
 *     dev-only tool; deliberately not shipped so it can't be run
 *     against production by mistake. Copy it back manually if a staging
 *     refresh ever needs it.
 *   backups/*.sql, uploads/opname/* (contents)          — runtime data,
 *     never bundled; only the directories + their .htaccess/.gitkeep.
 */

$root = dirname(__DIR__);
$outZip = $argv[1] ?? ($root . '/release_' . date('Ymd_His') . '.zip');

$excludeDirs = ['.git', 'tests', 'node_modules'];
$excludeFiles = [
    'inventory.html', 'trace-stok-awal.js',
    'config/config.php', 'bin/clear_test_data.php', 'bin/build_release.php',
    '.gitignore', '.DS_Store',
];
// Runtime-only paths: keep the directory (and its .htaccess/.gitkeep)
// but never bundle actual data files that happen to exist in THIS
// working copy at build time.
$runtimeDataDirs = ['backups', 'uploads/opname'];
$runtimeKeepFiles = ['.gitkeep', '.htaccess'];

$zip = new ZipArchive();
if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Gagal membuat {$outZip}\n");
    exit(1);
}

$fileCount = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $relPath = ltrim(str_replace($root, '', $item->getPathname()), '/');
    if ($relPath === '') {
        continue;
    }
    $topDir = explode('/', $relPath)[0];
    if (in_array($topDir, $excludeDirs, true)) {
        continue;
    }
    if (in_array($relPath, $excludeFiles, true)) {
        continue;
    }
    if (basename($outZip) === basename($relPath) && dirname($item->getPathname()) === $root) {
        continue; // never bundle the zip we're currently writing
    }

    $isRuntimeData = false;
    foreach ($runtimeDataDirs as $rd) {
        if (str_starts_with($relPath, $rd . '/')) {
            $isRuntimeData = true;
            break;
        }
    }
    if ($isRuntimeData && !in_array(basename($relPath), $runtimeKeepFiles, true) && !$item->isDir()) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relPath);
        continue;
    }

    $zip->addFile($item->getPathname(), $relPath);
    $fileCount++;
}

$zip->close();
$size = filesize($outZip);
printf("Release built: %s (%d files, %.1f MB)\n", $outZip, $fileCount, $size / 1024 / 1024);
echo "Ingat: config/config.php TIDAK termasuk — buat dari config/config.sample.php di server tujuan.\n";
