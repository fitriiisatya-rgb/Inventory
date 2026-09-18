<?php
declare(strict_types=1);

/**
 * PHASE V2 3d — owner decision #2: backfill each item's existing global
 * items.minimum_stock as the INITIAL per-warehouse minimum for the two
 * currently-live warehouses (SCM and Cibadak) only. buffer_stock_base is
 * never invented — every backfilled row is created with buffer NULL.
 *
 * Karang Tengah safety: this script hard-refuses to touch any warehouse
 * whose code is not exactly 'SCM' or 'CIBADAK' — it will not create rows
 * for Karang Tengah (or any other warehouse) under any circumstance, no
 * flag overrides this. Mirrors the same explicit-scope guard used in
 * scripts/production_cutover_scm_cibadak.php.
 *
 * Idempotent: re-running skips any (item, warehouse) pair that already has
 * an item_warehouse_stock_policy row (never overwrites a value someone —
 * human or a prior run — has already set, whether via this script or the
 * PUT /stock-policy endpoint).
 *
 * Usage:
 *   php scripts/backfill_stock_policy_scm_cibadak.php [--dry-run]
 *
 * Requires the warehouses table to already contain rows with code='SCM'
 * and code='CIBADAK' (this script does not create warehouses).
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$pdo = Database::connection();

$ALLOWED_CODES = ['SCM', 'CIBADAK'];

$whStmt = $pdo->query("SELECT id, code FROM warehouses WHERE code IN ('SCM', 'CIBADAK')");
$warehouses = $whStmt->fetchAll();

$foundCodes = array_map(fn ($w) => $w['code'], $warehouses);
$missing = array_diff($ALLOWED_CODES, $foundCodes);
if ($missing) {
    fwrite(STDERR, 'Missing expected warehouse(s): ' . implode(', ', $missing) . " — refusing to run.\n");
    fwrite(STDERR, "This script only ever targets warehouses literally coded SCM and CIBADAK; it will not guess or substitute another warehouse.\n");
    exit(1);
}

// Extra belt-and-braces: explicitly confirm no warehouse in this result set
// is Karang Tengah under any alternate code spelling that might exist.
foreach ($warehouses as $w) {
    if (!in_array($w['code'], $ALLOWED_CODES, true)) {
        fwrite(STDERR, "Refusing to run — unexpected warehouse code `{$w['code']}` in result set.\n");
        exit(1);
    }
}

echo 'Target warehouses: ' . implode(', ', array_map(fn ($w) => "{$w['code']} (id={$w['id']})", $warehouses)) . "\n";
if ($dryRun) {
    echo "--dry-run: no changes will be made.\n";
}
echo "\n";

$systemUserId = (int) $pdo->query("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE code = 'SUPERADMIN') ORDER BY id LIMIT 1")->fetchColumn();
if ($systemUserId <= 0) {
    fwrite(STDERR, "No SUPERADMIN user found to attribute this backfill to — refusing to run.\n");
    exit(1);
}

$created = 0;
$skippedExisting = 0;

foreach ($warehouses as $wh) {
    $whId = (int) $wh['id'];
    $items = $pdo->query('SELECT id, minimum_stock FROM items')->fetchAll();
    foreach ($items as $item) {
        $itemId = (int) $item['id'];

        $existing = $pdo->prepare('SELECT id FROM item_warehouse_stock_policy WHERE item_id = :i AND warehouse_id = :w');
        $existing->execute(['i' => $itemId, 'w' => $whId]);
        if ($existing->fetchColumn() !== false) {
            $skippedExisting++;
            continue;
        }

        if (!$dryRun) {
            $pdo->prepare(
                'INSERT INTO item_warehouse_stock_policy
                    (item_id, warehouse_id, minimum_stock_base, buffer_stock_base, is_active, notes, created_by, updated_by)
                 VALUES (:i, :w, :min, NULL, 1, :notes, :created_by, :updated_by)'
            )->execute([
                'i' => $itemId, 'w' => $whId, 'min' => (float) $item['minimum_stock'],
                'notes' => 'Phase V2 backfill from items.minimum_stock — buffer intentionally left unset',
                'created_by' => $systemUserId, 'updated_by' => $systemUserId,
            ]);
        }
        $created++;
    }
}

echo "Rows created: {$created}\n";
echo "Rows skipped (already had a policy row — never overwritten): {$skippedExisting}\n";
echo "\nDone.\n";
