<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/**
 * Manual backup trigger for the legacy migration wizard's "backup first"
 * step. The dump is written server-side under backups/ (denied to the
 * web via backups/.htaccess) — never streamed over HTTP, since it
 * contains every row in the system including password hashes. Retrieve
 * it via SSH/cPanel File Manager/FTP, same as bin/backup_database.php.
 */

$user = Auth::requireLogin();
Permissions::require($user['role'], 'master.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$pdo = Database::pdo();
$backupDir = $GLOBALS['SO_CONFIG']['app']['backup_dir'] ?? (__DIR__ . '/../../backups');
$service = new BackupService($pdo, $GLOBALS['SO_CONFIG']['db'], $backupDir);

try {
    $result = $service->run($user['id'], 'pre_legacy_import');
    Response::json(['data' => [
        'filename' => $result['filename'], 'size_bytes' => $result['size_bytes'], 'method' => $result['method'],
    ]]);
} catch (Throwable $e) {
    Response::error('Backup gagal: ' . $e->getMessage(), 500);
}
