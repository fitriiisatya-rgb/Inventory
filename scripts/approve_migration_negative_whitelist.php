<?php
declare(strict_types=1);

/**
 * POLICY CORRECTION — KEEP KNOWN MIGRATION NEGATIVE STOCK VISIBLE.
 *
 * Marks exactly the 5 owner-approved rows in movement_reconciliation_reviews
 * as is_migration_negative_approved = 1. These are the same 5 rows already
 * seeded by scripts/seed_movement_reconciliation_review.php with
 * reason THEORETICAL_NEGATIVE_ENDING / NEGATIVE_CLOSING_THEORETICAL — this
 * script does not invent new data, it only flips the approval flag after
 * verifying each row's historical_calculated_ending still matches the
 * owner's own numbers exactly (safety check before write, same pattern as
 * every other override script this project uses).
 *
 * Safe to re-run: idempotent UPDATE by (sku, warehouse_code).
 *
 * Usage: php scripts/approve_migration_negative_whitelist.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

// sku => [warehouse_code, expected historical_calculated_ending]
$expected = [
    '100304' => ['SCM', -0.5],
    '777419' => ['SCM', -0.5],
    '400201' => ['CIBADAK', -466.5],
    '555410' => ['CIBADAK', -250.0],
    '800401' => ['CIBADAK', -162.0],
];

$approvedByName = 'Owner (POLICY CORRECTION — KEEP KNOWN MIGRATION NEGATIVE STOCK VISIBLE)';
$note = 'Owner decision: do NOT zero or provisionally adjust. Preserve the actual calculated migration balance; resolve only via audited Stock Opname/Stock Adjustment.';

$pdo = Database::connection();

$select = $pdo->prepare('SELECT * FROM movement_reconciliation_reviews WHERE sku = :sku AND warehouse_code = :wh');
$update = $pdo->prepare(
    'UPDATE movement_reconciliation_reviews
     SET is_migration_negative_approved = 1, migration_negative_approved_by_name = :approved_by, migration_negative_note = :note, updated_at = :now
     WHERE sku = :sku AND warehouse_code = :wh'
);

$now = date('Y-m-d H:i:s');
$approved = [];
$mismatches = [];

foreach ($expected as $sku => [$wh, $expectedEnding]) {
    $select->execute(['sku' => $sku, 'wh' => $wh]);
    $row = $select->fetch();
    if (!$row) {
        $mismatches[] = "{$sku}/{$wh}: no movement_reconciliation_reviews row found — run seed_movement_reconciliation_review.php first";
        continue;
    }
    $actualEnding = $row['historical_calculated_ending'] !== null ? (float) $row['historical_calculated_ending'] : null;
    if ($actualEnding === null || abs($actualEnding - $expectedEnding) > 0.000001) {
        $mismatches[] = "{$sku}/{$wh}: expected historical_calculated_ending {$expectedEnding}, found " . var_export($actualEnding, true);
        continue;
    }
    $approved[] = $sku;
}

if ($mismatches) {
    fwrite(STDERR, "REFUSING TO APPROVE — mismatch against the owner's known figures:\n" . implode("\n", $mismatches) . "\n");
    exit(1);
}

foreach ($expected as $sku => [$wh, $expectedEnding]) {
    $update->execute(['approved_by' => $approvedByName, 'note' => $note, 'now' => $now, 'sku' => $sku, 'wh' => $wh]);
}

echo 'Approved ' . count($approved) . " migration-negative whitelist row(s): " . implode(', ', array_map(
    fn ($sku) => "{$sku}/{$expected[$sku][0]}={$expected[$sku][1]}",
    $approved
)) . "\n";
