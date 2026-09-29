<?php
declare(strict_types=1);

/**
 * CLI: php bin/backup_database.php [label]
 * Runs a full database backup via BackupService (mysqldump if available,
 * a verified pure-PHP fallback otherwise) and writes it under
 * config's app.backup_dir (default: backups/, which is denied to the
 * web via backups/.htaccess — never expose this directory publicly).
 *
 * Intended for a manual pre-go-live backup and for a cron job (shared
 * hosting cPanel "Cron Jobs" typically supports `php /path/to/bin/
 * backup_database.php nightly` on a schedule). No output is required
 * on success beyond the summary line, so cron mail stays quiet.
 */

require __DIR__ . '/../includes/bootstrap.php';

$label = $argv[1] ?? 'manual';
if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
    fwrite(STDERR, "Label boleh berisi huruf, angka, underscore, dan dash saja.\n");
    exit(1);
}

$pdo = Database::pdo();
$backupDir = $GLOBALS['SO_CONFIG']['app']['backup_dir'] ?? (__DIR__ . '/../backups');
$service = new BackupService($pdo, $GLOBALS['SO_CONFIG']['db'], $backupDir);

// actor_id 0 needs a real user row for the audit FK — CLI runs use the
// first SUPERADMIN found, or fall back to unattributed (actor_id NULL
// is not allowed by the schema, so a missing SUPERADMIN is a hard stop).
$actorStmt = $pdo->query("SELECT id FROM users WHERE role = 'SUPERADMIN' ORDER BY id LIMIT 1");
$actorId = $actorStmt->fetchColumn();
if (!$actorId) {
    fwrite(STDERR, "Tidak ada user SUPERADMIN — buat satu dulu dengan bin/create_admin.php sebelum menjalankan backup.\n");
    exit(1);
}

try {
    $result = $service->run((int) $actorId, $label);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup gagal: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Backup OK: %s (%s, %.1f KB)\n",
    $result['filename'], $result['method'], $result['size_bytes'] / 1024
);
