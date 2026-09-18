<?php
declare(strict_types=1);

/**
 * PHASE 5 — production control-totals snapshot. READ-ONLY: makes no
 * changes, safe to run against production at any time, any number of
 * times (before deployment, immediately after, and again post-smoke-test).
 *
 * Captures every metric the owner's Phase 5 pre-flight checklist (Section
 * D) requires in one shot, as a timestamped JSON snapshot, so a
 * before/after comparison never depends on someone's memory of what a
 * number "was" — it depends on a file. Run this BEFORE the migration to
 * establish the baseline, and again AFTER (Section 12 of the deployment
 * plan) to prove economics were unchanged by a schema/UI-only release.
 *
 * Usage: php scripts/v2_production_control_totals.php [--label=<text>]
 * Output: printed to stdout AND written to
 *   storage/reports/control_totals_<label-or-'snapshot'>_<timestamp>.json
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';

use App\Services\Database;
use App\Services\InventoryService;
use App\Services\MigrationNegativeStockService;

$args = array_slice($argv, 1);
$label = 'snapshot';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--label=')) {
        $label = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($arg, 8)) ?: 'snapshot';
    }
}

$pdo = Database::connection();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

function warehouseValue(PDO $pdo, string $code): ?array
{
    $whId = $pdo->prepare('SELECT id FROM warehouses WHERE code = :c');
    $whId->execute(['c' => $code]);
    $id = $whId->fetchColumn();
    if ($id === false) {
        return null;
    }
    $summary = InventoryService::warehouseDashboardSummary($pdo, (int) $id);
    return ['warehouse_id' => (int) $id, 'code' => $code, 'on_hand_value' => $summary['on_hand_value']];
}

$companyValue = InventoryService::companyTotalValue($pdo);
$scm = warehouseValue($pdo, 'SCM');
$cibadak = warehouseValue($pdo, 'CIBADAK');
$karangTengahExists = (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE code = 'KARANG_TENGAH'")->fetchColumn() > 0;

$migrationNegativeReview = MigrationNegativeStockService::reviewList($pdo);
$migrationNegativeUnresolvedCount = count(array_filter($migrationNegativeReview, fn ($r) => $r['status'] === 'MIGRATION_NEGATIVE_REVIEW'));

$totals = [
    'generated_at' => date('c'),
    'database' => $dbName,
    'item_count' => (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(),
    'warehouse_count' => (int) $pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn(),
    'karang_tengah_warehouse_exists' => $karangTengahExists,
    'user_count' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'transaction_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
    'historical_transaction_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE is_historical_import = 1')->fetchColumn(),
    'opening_transaction_count' => (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type = 'OPENING'")->fetchColumn(),
    'stock_openings_batch_count' => (int) $pdo->query('SELECT COUNT(*) FROM stock_openings')->fetchColumn(),
    'inventory_batch_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn(),
    'fifo_allocation_count' => (int) $pdo->query('SELECT COUNT(*) FROM fifo_allocations')->fetchColumn(),
    'migration_negative_whitelisted_count' => count($migrationNegativeReview),
    'migration_negative_unresolved_count' => $migrationNegativeUnresolvedCount,
    'scm_on_hand_value' => $scm['on_hand_value'] ?? null,
    'cibadak_on_hand_value' => $cibadak['on_hand_value'] ?? null,
    'company_on_hand_value' => $companyValue['on_hand_value'],
    'in_transit_value' => $companyValue['in_transit_value'],
    'company_total_value' => $companyValue['total_value'],
    'company_total_contains_unresolved_migration_negative' => $companyValue['contains_unresolved_migration_negative_stock'],
];

echo json_encode($totals, JSON_PRETTY_PRINT) . "\n";

$outDir = __DIR__ . '/../storage/reports';
@mkdir($outDir, 0775, true);
$outPath = $outDir . '/control_totals_' . $label . '_' . date('Ymd_His') . '.json';
file_put_contents($outPath, json_encode($totals, JSON_PRETTY_PRINT));
fwrite(STDERR, "\nSnapshot written to: {$outPath}\n");
fwrite(STDERR, "Reconciliation status was NOT re-run here (separate, potentially slower check) — run `GET /api/reconciliation` or the existing reconciliation test/endpoint separately and record its result alongside this snapshot.\n");
