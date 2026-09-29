<?php
declare(strict_types=1);

/**
 * CLI migration runner: `php bin/migrate.php`
 * Applies database/schema.sql once (tracked as 0001_initial_schema.sql),
 * then any database/migrations/*.sql files not yet recorded, in order.
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

$files = ['0001_initial_schema.sql' => __DIR__ . '/../database/schema.sql'];
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
