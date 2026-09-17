<?php
declare(strict_types=1);

/**
 * PHASE G-DATA 2 — promotes APPROVED rows from unit_conversion_candidates
 * into the real, FIFO-facing item_unit_conversions table, via
 * UnitConversionService::openNewVersion(). A separate, explicit step from
 * loading the candidates (load_approved_conversions_v5.php) — never
 * automatic, exactly as documented on the unit_conversion_candidates
 * table itself (schema.sql Section 5B).
 *
 * NOT RUN as part of this phase: every row here is skipped with a clear
 * reason until the real item master (SKU -> items.id) has actually been
 * imported into production. Running this script against an empty items
 * table promotes zero rows and reports why.
 *
 * Usage: php scripts/promote_approved_conversions.php [--valid-from=YYYY-MM-DD HH:MM:SS] [--dry-run]
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/UnitConversionService.php';

use App\Services\Database;
use App\Services\UnitConversionService;

$dryRun = in_array('--dry-run', $argv, true);
$validFrom = date('Y-m-d H:i:s');
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--valid-from=')) {
        $validFrom = substr($arg, strlen('--valid-from='));
    }
}

$pdo = Database::connection();
$candidates = $pdo->query(
    "SELECT * FROM unit_conversion_candidates WHERE review_status = 'APPROVED' AND approved = 'YES' ORDER BY sku"
)->fetchAll();

echo "Found " . count($candidates) . " APPROVED candidates" . ($dryRun ? " (--dry-run, nothing will be written)" : "") . ".\n";

$promoted = 0;
$skippedNoItem = 0;
$skippedNoUnit = 0;
$skippedNoFactor = 0;

foreach ($candidates as $c) {
    $itemStmt = $pdo->prepare('SELECT id, base_unit_id FROM items WHERE sku = :sku');
    $itemStmt->execute(['sku' => $c['sku']]);
    $item = $itemStmt->fetch();
    if (!$item) {
        echo "  SKIP {$c['sku']}: item does not exist in production items table yet\n";
        $skippedNoItem++;
        continue;
    }

    if (empty($c['approved_purchase_unit']) || empty($c['approved_purchase_conversion'])) {
        echo "  SKIP {$c['sku']}: no approved_purchase_unit/conversion recorded (base-unit-only decision — nothing to promote as a purchase conversion)\n";
        $skippedNoFactor++;
        continue;
    }

    $unitStmt = $pdo->prepare('SELECT id FROM units WHERE code = :code');
    $unitStmt->execute(['code' => strtoupper((string) $c['approved_purchase_unit'])]);
    $unitId = $unitStmt->fetchColumn();
    if ($unitId === false) {
        echo "  SKIP {$c['sku']}: approved_purchase_unit '{$c['approved_purchase_unit']}' is not a known unit code\n";
        $skippedNoUnit++;
        continue;
    }

    if ($dryRun) {
        echo "  WOULD PROMOTE {$c['sku']}: 1 {$c['approved_purchase_unit']} = {$c['approved_purchase_conversion']} base units\n";
        $promoted++;
        continue;
    }

    Database::transaction(function ($tx) use ($item, $unitId, $c, $validFrom) {
        UnitConversionService::openNewVersion(
            $tx, (int) $item['id'], (int) $unitId, (float) $c['approved_purchase_conversion'],
            $validFrom, null,
            "Promoted from unit_conversion_candidates (Phase G-DATA 1B/1B.3): {$c['conversion_source']}",
            true
        );
    });
    echo "  PROMOTED {$c['sku']}: 1 {$c['approved_purchase_unit']} = {$c['approved_purchase_conversion']} base units\n";
    $promoted++;
}

echo "\nSummary: promoted={$promoted} skipped_no_item={$skippedNoItem} skipped_no_unit={$skippedNoUnit} skipped_no_purchase_factor={$skippedNoFactor}\n";
