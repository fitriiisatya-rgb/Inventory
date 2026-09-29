<?php
declare(strict_types=1);

/**
 * CLI migration runner: `php bin/migrate.php`
 * Applies every database/migrations/*.sql file not yet recorded, in
 * filename order, tracked in schema_migrations.
 *
 * database/schema.sql is a separate, always-current FULL reference copy
 * for documentation/manual import — it is NOT read by this runner. It
 * must never be re-derived as "migration 0001" here: once 0001 has
 * shipped, its migration file is frozen forever; later schema changes
 * only ever arrive as new migrations/000N_*.sql files (this is exactly
 * how the Phase 4 change was caught: schema.sql had already been edited
 * to the final cumulative shape, so treating it as migration 0001 again
 * double-applied the Phase 4 columns on a fresh install).
 */

require __DIR__ . '/../includes/bootstrap.php';

function splitSqlStatements(string $sql): array
{
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        // Strip inline "-- comment" tails too, not just whole-line comments —
        // a comment containing a literal ";" would otherwise fool the naive
        // split below (this schema has none in string literals, so a plain
        // "whitespace + --" match is safe here).
        $line = preg_replace('/\s+--.*$/', '', $line) ?? $line;
        if (str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

$pdo = Database::pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_migration_filename (filename)
    ) ENGINE=InnoDB'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = [];
foreach (glob(__DIR__ . '/../database/migrations/*.sql') as $path) {
    $files[basename($path)] = $path;
}
ksort($files);

foreach ($files as $name => $path) {
    if (in_array($name, $applied, true)) {
        echo "SKIP  {$name} (already applied)\n";
        continue;
    }
    echo "APPLY {$name} ... ";
    $statements = splitSqlStatements(file_get_contents($path));
    try {
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        echo 'OK (' . count($statements) . " statements)\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Migrations up to date.\n";
