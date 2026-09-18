<?php
declare(strict_types=1);

/**
 * FINAL FAST-TRACK GO_LIVE READINESS -- SCM + CIBADAK.
 * PRODUCTION CUTOVER SCRIPT -- Section 11 of the instruction: "prepare but
 * DO NOT execute". This file is the production twin of
 * scripts/staging_dry_run_scm_cibadak.php (proven end-to-end against
 * migration/workspace/staging_export/*.csv, see
 * docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md) -- SAME steps, SAME
 * import services, so there is no drift between what was tested and what
 * cutover would actually run. It has NOT been run against production. It
 * will not run against anything until every guard below is satisfied.
 *
 * Differences from the staging script, all deliberate:
 *  - Refuses to run unless CUTOVER_CONFIRM=I-HAVE-OWNER-APPROVAL-FOR-PRODUCTION-CUTOVER
 *    is set (an explicit, hard-to-fat-finger opt-in -- distinct from the
 *    staging script's "DB name must contain staging" guard, which would
 *    obviously never match a real production DB name).
 *  - Never creates a throwaway admin user. Takes CUTOVER_USER_ID (the id
 *    of an already-provisioned real admin account, per the Phase G21 user
 *    provisioning process) and refuses to run without it.
 *  - Still refuses if the 'items' table is not already empty -- this is a
 *    ONE-TIME population of a fresh schema, never a merge into existing data.
 *  - Does NOT reset/create the database itself -- schema migration
 *    (Command 2 in the checklist) is its own explicit, separate step.
 *
 * Usage (only ever run by hand, after every checklist item above it is
 * done, never as part of any automation):
 *   CUTOVER_CONFIRM=I-HAVE-OWNER-APPROVAL-FOR-PRODUCTION-CUTOVER \
 *   CUTOVER_USER_ID=<real admin user id> \
 *   DB_DATABASE=<real production db name> \
 *   php scripts/production_cutover_scm_cibadak.php
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
use App\Services\ImportValidationException;

$EXPORT_DIR = __DIR__ . '/../migration/workspace/staging_export';

$confirm = getenv('CUTOVER_CONFIRM') ?: '';
if ($confirm !== 'I-HAVE-OWNER-APPROVAL-FOR-PRODUCTION-CUTOVER') {
    fwrite(STDERR, "REFUSING TO RUN: CUTOVER_CONFIRM is not set to the exact required value.\n" .
        "This is a one-way production data load -- it will not run without explicit, deliberate confirmation.\n");
    exit(1);
}
$userId = (int) (getenv('CUTOVER_USER_ID') ?: 0);
if ($userId <= 0) {
    fwrite(STDERR, "REFUSING TO RUN: CUTOVER_USER_ID must be set to a real, already-provisioned admin user id.\n");
    exit(1);
}

$dbName = getenv('DB_DATABASE') ?: '';
$pdo = Database::connection();
echo "Connected to: {$dbName} (driver=" . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ")\n";

$userCheck = $pdo->prepare('SELECT username FROM users WHERE id = :id');
$userCheck->execute(['id' => $userId]);
$username = $userCheck->fetchColumn();
if ($username === false) {
    fwrite(STDERR, "REFUSING TO RUN: CUTOVER_USER_ID={$userId} does not exist in this database.\n");
    exit(1);
}
echo "Cutover will be attributed to user: {$username} (id={$userId})\n";

$existingItems = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
if ($existingItems > 0) {
    fwrite(STDERR, "REFUSING TO RUN: 'items' table is not empty ({$existingItems} rows) -- " .
        "this script is a one-time population of a freshly-migrated schema, never a merge.\n");
    exit(1);
}

function stage_commit_report(string $label, callable $stager, callable $committer): array
{
    echo "== {$label} ==\n";
    $batchId = $stager();
    $pdoLocal = \App\Services\Database::connection();
    $rows = $pdoLocal->prepare("SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status = 'ERROR'");
    $rows->execute(['id' => $batchId]);
    $errorRows = $rows->fetchAll();
    if (!empty($errorRows)) {
        echo "  " . count($errorRows) . " ERROR row(s) -- commit REFUSED. Fix the source data and re-export, do not force a partial import.\n";
        foreach (array_slice($errorRows, 0, 20) as $er) {
            echo "    row {$er['row_no']}: " . implode('; ', json_decode($er['messages'], true)) . "\n";
        }
        exit(1);
    }
    $result = $committer($batchId);
    echo "  committed: " . json_encode($result) . "\n\n";
    return $result;
}

stage_commit_report(
    'STEP 1: Warehouse catalog (SCM, CIBADAK)',
    fn () => ImportSimpleMasterService::stage($pdo, 'WAREHOUSE', "{$EXPORT_DIR}/warehouses.csv", 'warehouses.csv', $userId),
    fn ($id) => ImportSimpleMasterService::commit($pdo, 'WAREHOUSE', $id, $userId)
);

stage_commit_report(
    'STEP 2: Global Item Master (SCM+CIBADAK population)',
    fn () => ImportMasterItemService::stage($pdo, "{$EXPORT_DIR}/master_items.csv", 'master_items.csv', $userId),
    fn ($id) => ImportMasterItemService::commit($pdo, $id, $userId)
);

echo "== STEP 3: Migration-negative whitelist (seed + approve) ==\n";
require_once __DIR__ . '/seed_movement_reconciliation_review.php';
require_once __DIR__ . '/approve_migration_negative_whitelist.php';
echo "\n";

stage_commit_report(
    'STEP 4: Historical records (Opening 1 Sep + IN/OUT 1-15 Sep, inventory_effect=0)',
    fn () => ImportHistoricalTransactionService::stage($pdo, "{$EXPORT_DIR}/historical.csv", 'historical.csv', $userId),
    fn ($id) => ImportHistoricalTransactionService::commit($pdo, $id, $userId)
);

echo "== STEP 5: LIVE Opening 16 Sep (SCM + CIBADAK) ==\n";
$openingId = ImportOpeningStockService::stage($pdo, "{$EXPORT_DIR}/opening_16sep.csv", 'opening_16sep.csv', $userId);
$errCountStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = :id AND row_status = 'ERROR'");
$errCountStmt->execute(['id' => $openingId]);
$errCount = (int) $errCountStmt->fetchColumn();
if ($errCount > 0) {
    fwrite(STDERR, "REFUSING TO COMMIT: {$errCount} ERROR row(s) in the staged opening batch (id={$openingId}). " .
        "Review via GET /import/opening-stock/{$openingId}/reconciliation before retrying.\n");
    exit(1);
}
try {
    $openingCommitResult = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $openingId, $userId));
    echo "  COMMITTED: " . json_encode($openingCommitResult) . "\n\n";
} catch (ImportValidationException $e) {
    fwrite(STDERR, 'COMMIT REFUSED: ' . implode('; ', $e->errors) . "\n");
    exit(1);
}

echo "== STEP 6: Post-import reconciliation (Command 8 in the checklist runs this again, standalone) ==\n";
$reconciliation = OpeningReconciliationService::report($pdo, $openingId);
echo json_encode($reconciliation, JSON_PRETTY_PRINT) . "\n";
if ($reconciliation['go_live_ready'] !== true) {
    fwrite(STDERR, "\nWARNING: go_live_ready is FALSE after commit. Data has already been committed -- " .
        "do NOT attempt to 'fix' this by re-running. Investigate via the reconciliation report and the rollback procedure in PRODUCTION_CUTOVER_CHECKLIST.md.\n");
    exit(1);
}
echo "\nOpening batch id={$openingId} committed. go_live_ready=true. Run the post-import smoke test next (Command 9).\n";
