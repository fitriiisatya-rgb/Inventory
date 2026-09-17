<?php
declare(strict_types=1);

/**
 * PHASE G-DATA 2 — seeds the 8 movement_reconciliation_reviews rows with
 * the historical evidence already gathered during Phase G-DATA 1B/1B.1's
 * real-data reconciliation (scm_reconciliation_01_15_sep_2026.xlsx /
 * cibadak_reconciliation_01_15_sep_2026.xlsx). Historical evidence only —
 * verified_final_opening stays NULL until the owner's final verified
 * stock file arrives; nothing here posts to stock_opening_lines or
 * inventory_batches.
 *
 * Safe to re-run: upserts by (sku, warehouse_code).
 *
 * Usage: php scripts/seed_movement_reconciliation_review.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$rows = [
    // SCM / Gudang Besar (SKU, name, unit, opening, in, out, ending, reason)
    ['100304', 'Meises Hagel @1X12.5 Kg', 'SCM', 'KG', 0, 37.5, 38.0, -0.5, 'THEORETICAL_NEGATIVE_ENDING', 'Small theoretical negative — timing/rounding of a partial-carton movement, likely'],
    ['150116', 'BIHUN', 'SCM', 'GR', 2240, 3840.0, 640.0, 5440.0, 'PRICE_ROUNDING_REVIEW', 'Ending is positive; flagged only for a price/rounding review, not a quantity discrepancy'],
    ['666408', 'TABUNG CYLINDER 7CM', 'SCM', 'PCS', 0, 0.0, 0.0, 0.0, 'PRICE_ROUNDING_REVIEW', 'Zero movement in period; flagged only for a price/rounding review'],
    ['777212', 'Kresek Nabura 28X40', 'SCM', 'PCS', 0, 0.0, 0.0, 0.0, 'PRICE_ROUNDING_REVIEW', 'Zero movement in period; flagged only for a price/rounding review'],
    ['777419', 'PLASTIK PE 50X85 CM', 'SCM', 'KG', 0, 7.5, 8.0, -0.5, 'THEORETICAL_NEGATIVE_ENDING', 'Small theoretical negative — timing/rounding of a partial-carton movement, likely'],
    // Cibadak
    ['400201', 'Pisang Ambon Matang', 'CIBADAK', 'KG', 1120, 1847.0, 3433.5, -466.5, 'NEGATIVE_CLOSING_THEORETICAL', 'Large theoretical negative — likely a missing IN movement or an opening qty understatement in the September file'],
    ['555410', 'Dus Bolu Pisang Original Baru', 'CIBADAK', 'PCS', 4500, 17250.0, 22000.0, -250.0, 'NEGATIVE_CLOSING_THEORETICAL', 'Theoretical negative — likely a missing IN movement or an opening qty understatement in the September file'],
    ['800401', 'Minyak Fortune @18 Liter', 'CIBADAK', 'LTR', 360, 1620.0, 2142.0, -162.0, 'NEGATIVE_CLOSING_THEORETICAL', 'Theoretical negative — likely a missing IN movement or an opening qty understatement in the September file'],
];

$pdo = Database::connection();
$upsert = $pdo->prepare(
    'INSERT INTO movement_reconciliation_reviews
        (sku, item_name, warehouse_code, unit, historical_opening, historical_in, historical_out, historical_calculated_ending, status, reason, source, created_at, updated_at)
     VALUES (:sku, :name, :wh, :unit, :opening, :in_qty, :out_qty, :ending, \'PENDING_FINAL_STOCK\', :reason, :source, :now, :now2)
     ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), unit = VALUES(unit), historical_opening = VALUES(historical_opening),
        historical_in = VALUES(historical_in), historical_out = VALUES(historical_out), historical_calculated_ending = VALUES(historical_calculated_ending),
        reason = VALUES(reason), source = VALUES(source), updated_at = VALUES(updated_at)'
);
$now = date('Y-m-d H:i:s');
$count = 0;
foreach ($rows as [$sku, $name, $wh, $unit, $opening, $in, $out, $ending, $reasonCode, $note]) {
    $upsert->execute([
        'sku' => $sku, 'name' => $name, 'wh' => $wh, 'unit' => $unit,
        'opening' => $opening, 'in_qty' => $in, 'out_qty' => $out, 'ending' => $ending,
        'reason' => "{$reasonCode}: {$note}", 'source' => "Phase G-DATA 1B real-data reconciliation ({$reasonCode})",
        'now' => $now, 'now2' => $now,
    ]);
    $count++;
}

echo "Seeded/updated {$count} movement_reconciliation_reviews rows.\n";
