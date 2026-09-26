<?php
declare(strict_types=1);

/**
 * FINAL FAST-TRACK GO_LIVE READINESS -- SCM + CIBADAK.
 * FINAL PRE-PRODUCTION DRY RUN, steps 4-7 of the instruction.
 *
 * Loads the exported staging inputs (migration/scripts/export_scm_cibadak_staging.py
 * output, migration/workspace/staging_export/*.csv) into a CLEAN STAGING
 * DATABASE using the real project import services (never a raw INSERT
 * bypass) -- and only ever into a database whose name contains "staging",
 * refusing to run otherwise, so this can never be pointed at the real
 * inventory_test dev/test DB or, worse, production by mistake.
 *
 * Order: warehouses (SCM+CIBADAK only) -> master items (base_unit +
 * approved purchase-unit conversions inline, via the normal
 * ImportMasterItemService.createItem() path -- no separate "promote"
 * step needed since items are created fresh) -> migration-negative
 * whitelist seed+approve -> historical (Opening 1 Sep + IN/OUT 1-15,
 * inventory_effect=0) -> LIVE Opening 16 Sep (Closing 15 Sep).
 *
 * Prints the exact validation counts + control totals the instruction's
 * Sections 5-6 ask for. NEVER touches production. Usage:
 *   DB_DATABASE=inventory_staging_scm_cibadak php scripts/staging_dry_run_scm_cibadak.php
 * (run scripts/run_staging_dry_run.sh instead to do the full reset+run in one step)
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';

use App\Services\Database;
use App\Services\ImportMasterItemService;
use App\Services\ImportSimpleMasterService;
use App\Services\ImportOpeningStockService;
use App\Services\ImportHistoricalTransactionService;
use App\Services\OpeningReconciliationService;
use App\Services\MigrationNegativeStockService;
use App\Services\InventoryService;
use App\Services\ImportValidationException;

$EXPORT_DIR = __DIR__ . '/../migration/workspace/staging_export';

$dbName = getenv('DB_DATABASE') ?: '';
if (!str_contains(strtolower($dbName), 'staging')) {
    fwrite(STDERR, "REFUSING TO RUN: DB_DATABASE ('{$dbName}') does not contain 'staging'. " .
        "This script is only ever allowed to run against a dedicated staging database, never inventory_test or production.\n");
    exit(1);
}

$pdo = Database::connection();
echo "Connected to STAGING database: {$dbName} (driver=" . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ")\n";

// A production database is never empty by this point in real life; this is
// an extra belt-and-braces check on top of the name check above.
$existingItems = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
if ($existingItems > 0) {
    fwrite(STDERR, "REFUSING TO RUN: 'items' table is not empty ({$existingItems} rows) -- this script expects a freshly-reset staging schema.\n");
    exit(1);
}

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id) VALUES ('dryrun_admin', :p, 'Dry Run Admin', :r)")
    ->execute(['p' => password_hash('DryRun#2026StagingOnly', PASSWORD_DEFAULT), 'r' => $roleId]);
$userId = (int) $pdo->lastInsertId();
echo "Seeded dry-run admin user (id={$userId}).\n\n";

function stage_commit_report(string $label, callable $stager, callable $committer, array $errorPatterns = []): array
{
    echo "== {$label} ==\n";
    $t0 = microtime(true);
    $batchId = $stager();
    $errorRows = [];
    $pdoLocal = \App\Services\Database::connection();
    $rows = $pdoLocal->prepare("SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status = 'ERROR'");
    $rows->execute(['id' => $batchId]);
    foreach ($rows->fetchAll() as $r) {
        $errorRows[] = ['row_no' => $r['row_no'], 'messages' => json_decode($r['messages'], true)];
    }
    if (!empty($errorRows)) {
        echo "  " . count($errorRows) . " ERROR row(s) -- commit REFUSED, printing first 10:\n";
        foreach (array_slice($errorRows, 0, 10) as $er) {
            echo "    row {$er['row_no']}: " . implode('; ', $er['messages']) . "\n";
        }
        return ['batch_id' => $batchId, 'committed' => false, 'error_rows' => $errorRows, 'elapsed_s' => round(microtime(true) - $t0, 2)];
    }
    $result = $committer($batchId);
    $elapsed = round(microtime(true) - $t0, 2);
    echo "  committed: " . json_encode($result) . " ({$elapsed}s)\n\n";
    return ['batch_id' => $batchId, 'committed' => true, 'result' => $result, 'error_rows' => [], 'elapsed_s' => $elapsed];
}

// ---------------------------------------------------------------------
// 1. Warehouses -- SCM + CIBADAK only. Karang Tengah is NOT created here,
//    so any transaction referencing it fails as an unknown warehouse --
//    functionally the rejection the instruction asks for, though note
//    honestly: this project has no dedicated warehouses.status =
//    PENDING_CUTOVER enum; that label is applied at the report level
//    (Section 6/10 below), not as a new schema/business rule.
// ---------------------------------------------------------------------
$whReport = stage_commit_report(
    'STEP 1: Warehouse catalog (SCM, CIBADAK)',
    fn () => ImportSimpleMasterService::stage($pdo, 'WAREHOUSE', "{$EXPORT_DIR}/warehouses.csv", 'warehouses.csv', $userId),
    fn ($id) => ImportSimpleMasterService::commit($pdo, 'WAREHOUSE', $id, $userId)
);

// ---------------------------------------------------------------------
// 2. Global Item Master (approved purchase-unit conversions are inline
//    columns in master_items.csv for the 33 owner-approved SKUs, so
//    ImportMasterItemService::createItem() already opens those
//    item_unit_conversions rows during item creation -- no separate
//    "promote" step is needed for a fresh import).
// ---------------------------------------------------------------------
$masterReport = stage_commit_report(
    'STEP 2: Global Item Master (SCM+CIBADAK population)',
    fn () => ImportMasterItemService::stage($pdo, "{$EXPORT_DIR}/master_items.csv", 'master_items.csv', $userId),
    fn ($id) => ImportMasterItemService::commit($pdo, $id, $userId)
);

// ---------------------------------------------------------------------
// 3. Migration-negative whitelist (POLICY CORRECTION) -- seed the 8
//    historical evidence rows, then approve exactly the 5 owner-named
//    rows. Re-uses the exact same scripts already built/verified for
//    this policy, run in-process here via include so they operate
//    against THIS staging connection.
// ---------------------------------------------------------------------
echo "== STEP 3: Migration-negative whitelist (seed + approve) ==\n";
require_once __DIR__ . '/seed_movement_reconciliation_review.php';
require_once __DIR__ . '/approve_migration_negative_whitelist.php';
echo "\n";

// ---------------------------------------------------------------------
// 4. Historical: Opening 1 Sep + IN/OUT 1-15 Sep, inventory_effect=0.
// ---------------------------------------------------------------------
$histReport = stage_commit_report(
    'STEP 4: Historical records (Opening 1 Sep + IN/OUT 1-15 Sep, inventory_effect=0)',
    fn () => ImportHistoricalTransactionService::stage($pdo, "{$EXPORT_DIR}/historical.csv", 'historical.csv', $userId),
    fn ($id) => ImportHistoricalTransactionService::commit($pdo, $id, $userId)
);

// ---------------------------------------------------------------------
// 5. LIVE Opening 16 Sep = Closing 15 Sep (Opening 1 Sep + IN - OUT).
// ---------------------------------------------------------------------
echo "== STEP 5: LIVE Opening 16 Sep (SCM + CIBADAK) ==\n";
$openingId = ImportOpeningStockService::stage($pdo, "{$EXPORT_DIR}/opening_16sep.csv", 'opening_16sep.csv', $userId);
$errCountStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = :id AND row_status = 'ERROR'");
$errCountStmt->execute(['id' => $openingId]);
$errCount = (int) $errCountStmt->fetchColumn();
echo "  Staged opening batch id={$openingId}, ERROR rows={$errCount}\n";
if ($errCount > 0) {
    $errRows = $pdo->prepare("SELECT id, item_id, warehouse_id, qty_base, row_messages FROM stock_opening_lines WHERE stock_opening_id = :id AND row_status = 'ERROR' LIMIT 10");
    $errRows->execute(['id' => $openingId]);
    foreach ($errRows->fetchAll() as $er) {
        echo "    line {$er['id']} (item {$er['item_id']}, wh {$er['warehouse_id']}, qty {$er['qty_base']}): {$er['row_messages']}\n";
    }
}
$openingCommitResult = null;
$openingCommitError = null;
try {
    $openingCommitResult = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingId, $userId));
    echo "  COMMITTED: " . json_encode($openingCommitResult) . "\n\n";
} catch (ImportValidationException $e) {
    $openingCommitError = implode('; ', $e->errors);
    echo "  COMMIT REFUSED: {$openingCommitError}\n\n";
}

// ---------------------------------------------------------------------
// 6. Required validations (Section 5 of the instruction)
// ---------------------------------------------------------------------
echo "== STEP 6: Required validation counts ==\n";
$reconciliation = OpeningReconciliationService::report($pdo, $openingId);

$histUnknownSku = 0; $histUnknownWh = 0;
foreach ($histReport['error_rows'] as $er) {
    foreach ($er['messages'] as $m) {
        if (str_contains($m, 'unknown SKU')) { $histUnknownSku++; }
        if (str_contains($m, 'unknown warehouse_code')) { $histUnknownWh++; }
    }
}
$masterDuplicates = 0;
foreach ($masterReport['error_rows'] as $er) {
    foreach ($er['messages'] as $m) {
        if (str_contains($m, 'already exists') || str_contains($m, 'duplicate SKU')) { $masterDuplicates++; }
    }
}
$histEffectNonzero = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE is_historical_import = 1 AND inventory_effect <> 0")->fetchColumn();

$validation = [
    'unknown_sku' => $reconciliation['checks']['unknown_sku'] + $histUnknownSku,
    'unknown_warehouse' => $reconciliation['checks']['unknown_warehouse'] + $histUnknownWh,
    'unit_mismatch' => $reconciliation['checks']['base_unit_mismatch'],
    'duplicate_item' => $masterDuplicates,
    'duplicate_opening' => $reconciliation['checks']['duplicate_opening'],
    'missing_required_cost' => $reconciliation['checks']['missing_cost'],
    'ordinary_negative_opening' => $reconciliation['checks']['negative_qty'],
    'whitelisted_migration_negative_rows' => $reconciliation['migration_negative_count'],
    'historical_inventory_effect_nonzero' => $histEffectNonzero,
];
echo json_encode($validation, JSON_PRETTY_PRINT) . "\n\n";

$requiredExpected = [
    'unknown_sku' => 0, 'unknown_warehouse' => 0, 'unit_mismatch' => 0, 'duplicate_opening' => 0,
    'missing_required_cost' => 0, 'ordinary_negative_opening' => 0,
    'whitelisted_migration_negative_rows' => 5, 'historical_inventory_effect_nonzero' => 0,
];
$allMet = true;
foreach ($requiredExpected as $k => $expected) {
    $got = $validation[$k];
    $ok = $got === $expected;
    $allMet = $allMet && $ok;
    echo "  " . ($ok ? 'PASS' : 'FAIL') . " - {$k}: expected {$expected}, got {$got}\n";
}
echo "\nALL REQUIRED VALIDATIONS MET: " . ($allMet ? 'YES' : 'NO') . "\n\n";

// ---------------------------------------------------------------------
// 7. Control totals (Section 6)
// ---------------------------------------------------------------------
echo "== STEP 7: Opening control totals ==\n";
function warehouse_totals(PDO $pdo, string $code): array
{
    $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :c');
    $whStmt->execute(['c' => $code]);
    $whId = (int) $whStmt->fetchColumn();

    // One row per item's CURRENT balance (summed across its batches, e.g.
    // a resolved migration-negative item may now have 2 batches netting
    // positive) -- not one row per batch, so "positive/zero/negative rows"
    // reads as "how many SKUs", matching Section 6's "item balance rows".
    $stmt = $pdo->prepare(
        'SELECT item_id, SUM(qty_base) AS qty, SUM(qty_base * unit_cost_base) AS value
         FROM inventory_batches WHERE warehouse_id = :wh GROUP BY item_id'
    );
    $stmt->execute(['wh' => $whId]);
    $rows = $stmt->fetchAll();

    $positive = 0; $zero = 0; $negative = 0; $value = 0.0;
    foreach ($rows as $r) {
        $q = round((float) $r['qty'], 6);
        if ($q > 0) { $positive++; } elseif ($q < 0) { $negative++; } else { $zero++; }
        $value += (float) $r['value'];
    }
    return [
        'warehouse' => $code, 'item_balance_rows' => count($rows), 'positive_rows' => $positive,
        'zero_rows' => $zero, 'negative_migration_rows' => $negative, 'inventory_value' => round($value, 4),
    ];
}
$scmTotals = warehouse_totals($pdo, 'SCM');
$cibadakTotals = warehouse_totals($pdo, 'CIBADAK');
$companyValue = InventoryService::companyTotalValue($pdo);
echo "SCM: " . json_encode($scmTotals) . "\n";
echo "CIBADAK: " . json_encode($cibadakTotals) . "\n";
echo "COMPANY LIVE TOTAL (SCM+CIBADAK only): " . json_encode($companyValue) . "\n";
echo "KARANG_TENGAH = PENDING_CUTOVER / EXCLUDED FROM LIVE TOTAL (warehouse not created in this staging DB)\n\n";

// ---------------------------------------------------------------------
// Persist a machine-readable summary for the final report step.
// ---------------------------------------------------------------------
$summary = [
    'db_name' => $dbName,
    'warehouses' => $whReport['result'] ?? null,
    'master_items' => $masterReport['result'] ?? null,
    'master_items_error_count' => count($masterReport['error_rows']),
    'historical' => $histReport['result'] ?? null,
    'historical_error_count' => count($histReport['error_rows']),
    'opening_commit' => $openingCommitResult,
    'opening_commit_error' => $openingCommitError,
    'opening_error_rows_at_stage' => $errCount,
    'validation' => $validation,
    'all_required_validations_met' => $allMet,
    'scm_totals' => $scmTotals,
    'cibadak_totals' => $cibadakTotals,
    'company_total' => $companyValue,
    'opening_reconciliation' => $reconciliation,
    'migration_negative_review' => MigrationNegativeStockService::reviewList($pdo),
];
file_put_contents(__DIR__ . '/../migration/workspace/staging_export/dry_run_summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "Wrote migration/workspace/staging_export/dry_run_summary.json\n";
