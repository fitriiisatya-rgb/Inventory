<?php
declare(strict_types=1);

/**
 * PRODUCTION CUTOVER RUNBOOK — STEP 13 and STEP 15 verification. Read-only:
 * prints the OpeningReconciliationService GO_LIVE_READY report for the
 * most recently committed stock_openings batch, plus SCM/CIBADAK/company
 * control totals. Safe to run repeatedly at any point after STEP 12 --
 * never writes anything.
 *
 * Usage: php scripts/cutover_report.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';

use App\Services\Database;
use App\Services\InventoryService;
use App\Services\MigrationNegativeStockService;
use App\Services\OpeningReconciliationService;

$pdo = Database::connection();

$openingId = (int) $pdo->query("SELECT id FROM stock_openings WHERE status = 'COMMITTED' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($openingId === 0) {
    fwrite(STDERR, "No COMMITTED stock_openings batch found -- run STEP 12 first.\n");
    exit(1);
}

$reconciliation = OpeningReconciliationService::report($pdo, $openingId);
echo "== Opening reconciliation (stock_opening_id={$openingId}) ==\n";
echo json_encode($reconciliation, JSON_PRETTY_PRINT) . "\n\n";

function warehouse_totals(PDO $pdo, string $code): ?array
{
    $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :c');
    $whStmt->execute(['c' => $code]);
    $whId = $whStmt->fetchColumn();
    if ($whId === false) {
        return null;
    }
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

$scm = warehouse_totals($pdo, 'SCM');
$cibadak = warehouse_totals($pdo, 'CIBADAK');
$kt = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE code = :c');
$kt->execute(['c' => 'KARANG_TENGAH']);
$ktExists = (int) $kt->fetchColumn() > 0;

echo "== Control totals ==\n";
echo 'SCM: ' . json_encode($scm) . "\n";
echo 'CIBADAK: ' . json_encode($cibadak) . "\n";
echo 'COMPANY LIVE TOTAL (InventoryService::companyTotalValue -- all warehouses present in this DB): '
    . json_encode(InventoryService::companyTotalValue($pdo)) . "\n";
echo 'KARANG_TENGAH warehouse exists in this DB: ' . ($ktExists ? 'YES -- STOP, this must be false for this fast-track' : 'no (correct — PENDING_CUTOVER)') . "\n\n";

$masterCount = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
$historicalCount = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE is_historical_import = 1')->fetchColumn();
$openingRows = (int) $pdo->query("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = {$openingId}")->fetchColumn();
$fifoBatches = (int) $pdo->query("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = {$openingId} AND created_batch_id IS NOT NULL")->fetchColumn();

echo "== Counts ==\n";
echo json_encode([
    'imported_master_sku_count' => $masterCount,
    'historical_transaction_count' => $historicalCount,
    'live_opening_row_count' => $openingRows,
    'fifo_opening_batch_count' => $fifoBatches,
    'migration_negative_count' => $reconciliation['migration_negative_count'],
], JSON_PRETTY_PRINT) . "\n\n";

$ready = $reconciliation['go_live_ready'] === true && !$ktExists;
echo $ready ? "OK — SCM_GO_LIVE_READY=true CIBADAK_GO_LIVE_READY=true KARANG_TENGAH_STATUS=PENDING_CUTOVER\n"
            : "STOP — review the output above before proceeding.\n";
exit($ready ? 0 : 1);
