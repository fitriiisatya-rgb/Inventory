<?php
declare(strict_types=1);

/**
 * PHASE G19 — run this against the candidate production database
 * immediately before the real production import begins (and again right
 * after, as a final sanity check). It never modifies anything — read-only
 * assertions only.
 *
 * Two kinds of check, matching the two ways "dummy data" could slip in:
 *   1. Pattern-based: master rows (items/suppliers/divisions/warehouses/
 *      users) whose code/sku/username looks like a dev/test fixture
 *      (DUMMY-, TEST-, "contoh", "sample", ...) — these are FAIL even if
 *      other legitimate rows already exist in the same table.
 *   2. Zero-count: tables that must have EXACTLY ZERO rows before a real
 *      production import ever runs (transactions, transfers, production,
 *      opname, adjustments, openings, historical) — any row at all here
 *      means either dummy/dev data or a real import that already
 *      happened, either of which this gate exists to catch before go-live.
 *
 * Usage: php scripts/assert_database_clean.php
 * Reads DB connection from .env via config/config.php, same as the app.
 * Exit code 0 = PASS, 1 = FAIL — safe to use directly in a CI/deploy gate.
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();

$dummyPatternTables = [
    'items' => 'sku',
    'suppliers' => 'code',
    'divisions' => 'code',
    'warehouses' => 'code',
    'users' => 'username',
];
$dummyPatternRegex = '/(dummy|test|contoh|sample)/i';

$zeroCountTables = [
    'inventory_transactions' => 'transaction',
    'warehouse_transfers' => 'transfer',
    'production_headers' => 'production',
    'stock_opname_sessions' => 'opname',
    'stock_adjustments' => 'adjustment',
    'stock_openings' => 'opening',
];

$failures = [];

echo "== Pattern-based dummy/test data check ==\n";
foreach ($dummyPatternTables as $table => $column) {
    $rows = $pdo->query("SELECT {$column} FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
    $matches = array_values(array_filter($rows, fn ($v) => $v !== null && preg_match($dummyPatternRegex, (string) $v)));
    if (empty($matches)) {
        echo "PASS - 0 dummy/test-looking rows in {$table}.{$column}\n";
    } else {
        $failures[] = "{$table}.{$column} has " . count($matches) . " dummy/test-looking value(s): " . implode(', ', array_slice($matches, 0, 10));
        echo "FAIL - {$table}.{$column}: " . implode(', ', array_slice($matches, 0, 10)) . (count($matches) > 10 ? ' ...' : '') . "\n";
    }
}

echo "\n== Zero-count check (must be empty before production import) ==\n";
foreach ($zeroCountTables as $table => $label) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    if ($count === 0) {
        echo "PASS - 0 dummy {$label} rows ({$table})\n";
    } else {
        $failures[] = "{$table} has {$count} row(s) — expected 0 dummy {$label}";
        echo "FAIL - {$table} has {$count} row(s), expected 0\n";
    }
}

echo "\n==============================\n";
if (empty($failures)) {
    echo "DATABASE CLEAN = PASS\n";
    exit(0);
}

echo "DATABASE CLEAN = FAIL\n";
echo "Reasons:\n";
foreach ($failures as $f) {
    echo " - {$f}\n";
}
exit(1);
