<?php
declare(strict_types=1);

/**
 * Database backup before any risky bulk write (legacy migration commit,
 * or the standalone bin/backup_database.php cron/manual run). Tries the
 * real `mysqldump` binary first (fast, complete, handles routines/
 * triggers); many shared-hosting plans disable shell_exec/exec entirely,
 * so it falls back to a pure-PHP SQL dump over the existing PDO
 * connection, which works anywhere PDO itself works.
 */
final class BackupService
{
    public function __construct(private PDO $pdo, private array $dbConfig, private string $backupDir)
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /** @return array{path:string, filename:string, size_bytes:int, method:string} */
    public function run(int $actorId, string $label = 'manual'): array
    {
        $timestamp = date('Ymd_His');
        $filename = "backup_{$label}_{$timestamp}.sql";
        $path = rtrim($this->backupDir, '/') . '/' . $filename;

        $method = $this->tryMysqldump($path);
        if ($method === null) {
            $this->phpNativeDump($path);
            $method = 'php_native';
        }

        $size = is_file($path) ? filesize($path) : 0;
        Audit::log($actorId, 'DATABASE_BACKUP', 'system', 0, null, [
            'file' => $filename, 'method' => $method, 'size_bytes' => $size,
        ]);

        return ['path' => $path, 'filename' => $filename, 'size_bytes' => (int) $size, 'method' => $method];
    }

    private function tryMysqldump(string $outPath): ?string
    {
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return null;
        }
        $which = @shell_exec('command -v mysqldump 2>/dev/null');
        if (!$which || trim($which) === '') {
            return null;
        }

        // A temp --defaults-extra-file keeps the DB password out of the
        // process list (visible to other users via `ps aux` on shared
        // hosting) — never pass --password=... directly on argv.
        $defaultsFile = tempnam(sys_get_temp_dir(), 'sodump_');
        file_put_contents($defaultsFile, sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $this->dbConfig['host'], $this->dbConfig['port'], $this->dbConfig['user'], $this->dbConfig['pass']
        ));
        chmod($defaultsFile, 0600);

        $errPath = $outPath . '.err';
        $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($defaultsFile)
            . ' --single-transaction --routines --triggers '
            . escapeshellarg($this->dbConfig['name'])
            . ' > ' . escapeshellarg($outPath) . ' 2>' . escapeshellarg($errPath);
        exec($cmd, $unusedOutput, $exitCode);
        @unlink($defaultsFile);
        @unlink($errPath);

        if ($exitCode !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
            @unlink($outPath);
            return null;
        }
        return 'mysqldump';
    }

    private function phpNativeDump(string $outPath): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $fh = fopen($outPath, 'w');
        fwrite($fh, "-- PHP-native backup, generated " . date('c') . "\n"
            . "-- mysqldump was not available on this host; this is a portable fallback dump.\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            if ($table === 'schema_migrations') {
                continue; // structural bookkeeping, recreated by bin/migrate.php on restore
            }
            $createRow = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow['Create Table'] . ";\n\n");

            $colsStmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $columns = array_column($colsStmt->fetchAll(), 'Field');
            $colList = '`' . implode('`,`', $columns) . '`';

            $stmt = $this->pdo->query('SELECT * FROM `' . $table . '`');
            $batch = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(function ($v) {
                    return $v === null ? 'NULL' : $this->pdo->quote((string) $v);
                }, array_values($row));
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 500) {
                    fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }
}
