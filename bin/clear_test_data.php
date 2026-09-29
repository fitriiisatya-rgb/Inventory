<?php
declare(strict_types=1);

/**
 * CLI: php bin/clear_test_data.php [--confirm] [--yes]
 *
 * DESTRUCTIVE. Wipes every transactional/session table (sessions, counts,
 * revisions, locks, finals, photos, recounts, stock import batches/rows,
 * legacy migration log, audit log) so a test-riddled database can be
 * reset to a clean slate right before go-live. Master Barang, Kategori,
 * Lokasi, and User accounts are NEVER touched — those are exactly the
 * data go-live needs kept.
 *
 * Without --confirm this only PRINTS row counts and exits (dry run) —
 * nothing is deleted. With --confirm but without --yes, it also asks for
 * an interactive "DELETE" typed confirmation before proceeding (skip
 * with --yes for a non-interactive/CI use, which must still pass
 * --confirm explicitly).
 */

require __DIR__ . '/../includes/bootstrap.php';

$confirm = in_array('--confirm', $argv, true);
$yes = in_array('--yes', $argv, true);

$pdo = Database::pdo();

// Order matters for the row-count report only; the actual wipe disables
// FK checks and truncates (see below), which also resets auto_increment
// for a genuinely clean slate — DELETE would leave old IDs behind.
$tables = [
    'stock_opname_photos', 'stock_opname_count_revisions', 'stock_opname_counts',
    'stock_opname_recounts', 'stock_opname_finals', 'stock_opname_item_locks',
    'stock_opname_session_items', 'stock_opname_session_counters', 'stock_opname_sessions',
    'item_stock_adjustments', 'item_stock', 'stock_import_rows', 'stock_import_batches',
    'legacy_migrations', 'audit_logs',
];
$preserved = ['items', 'categories', 'locations', 'users', 'schema_migrations'];

echo "=== Clear Test Data — DRY RUN report ===\n";
$totalRows = 0;
foreach ($tables as $t) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    $totalRows += $count;
    printf("  %-38s %6d rows %s\n", $t, $count, $count > 0 ? 'WILL BE WIPED' : '');
}
echo "\nPreserved (never touched): " . implode(', ', $preserved) . "\n";
foreach ($preserved as $t) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    printf("  %-38s %6d rows kept\n", $t, $count);
}
echo "\nTotal rows to be deleted: {$totalRows}\n";

if (!$confirm) {
    echo "\nDry run only — nothing was deleted. Re-run with --confirm to actually wipe.\n";
    exit(0);
}

if (!$yes) {
    echo "\nType DELETE to proceed (anything else cancels): ";
    $handle = fopen('php://stdin', 'r');
    $line = trim((string) fgets($handle));
    fclose($handle);
    if ($line !== 'DELETE') {
        echo "Cancelled — nothing was deleted.\n";
        exit(1);
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\nDone. {$totalRows} rows wiped across " . count($tables) . " tables. Master Barang/Kategori/Lokasi/User untouched.\n";
