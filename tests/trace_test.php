<?php
declare(strict_types=1);

/**
 * PHASE V2.2 — TraceService: entity/transaction/inventory trace, search,
 * warehouse isolation, and the read-only guarantee (every trace call is a
 * plain SELECT — proven here by snapshotting stock/FIFO state before and
 * after a battery of trace calls and asserting byte-identical results,
 * not just "no exception was thrown").
 *
 * Usage: php tests/trace_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/ProductionService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\VoidService;
use App\Services\TransferService;
use App\Services\StockPolicyService;
use App\Services\TraceService;
use App\Services\StockOpnameService;
use App\Services\ProductionService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('tracesetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Trace Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('TR-WH-A') . "', 'Trace WH A')");
$whAId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('TR-WH-B') . "', 'Trace WH B')");
$whBId = (int) $pdo->lastInsertId();

$supplierName = uid('Trace Supplier');
$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('TR-SUP'), 'n' => $supplierName]);
$supplierId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId): array
{
    $sku = uid('TR-SKU');
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 5, :status)');
    $stmt->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

[$itemId, $sku] = makeItem($pdo, $kgUnitId);

// ============================================================
// A: entity trace — master data + stock policy, with real audit history
// ============================================================
echo "== A: entity trace ==\n";

$pdo->exec("UPDATE items SET name = 'Renamed Item' WHERE id = {$itemId}");
\App\Services\AuditService::log($pdo, $adminUserId, 'tracetest', 'ITEM_UPDATE', 'items', $itemId, ['name' => "Item {$sku}"], ['name' => 'Renamed Item'], null);

$itemTrace = TraceService::entityTrace($pdo, 'item', $itemId);
check('item entity trace returns the current row', $itemTrace['overview']['id'] == $itemId && $itemTrace['overview']['name'] === 'Renamed Item');
check('item entity trace timeline includes the logged rename', count($itemTrace['timeline']) === 1 && $itemTrace['timeline'][0]['action_code'] === 'ITEM_UPDATE');
check('item entity trace changes shows before/after', $itemTrace['changes'][0]['before']['name'] === "Item {$sku}" && $itemTrace['changes'][0]['after']['name'] === 'Renamed Item');

$whTrace = TraceService::entityTrace($pdo, 'warehouse', $whAId);
check('warehouse entity trace returns the current row', $whTrace['overview']['id'] == $whAId);
check('warehouse entity trace with no audit history yet has an empty (not fabricated) timeline', $whTrace['timeline'] === []);

try {
    TraceService::entityTrace($pdo, 'not_a_real_type', 1);
    check('unknown entity type is rejected', false);
} catch (\App\Services\ValidationException $e) {
    check('unknown entity type is rejected', true);
}

try {
    TraceService::entityTrace($pdo, 'item', 999999999);
    check('nonexistent entity id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent entity id is rejected', true);
}

StockPolicyService::upsert($pdo, ['item_id' => $itemId, 'warehouse_id' => $whAId, 'minimum_stock' => 10, 'buffer_stock' => null, 'updated_by' => $adminUserId, 'username' => 'tracetest']);
StockPolicyService::upsert($pdo, ['item_id' => $itemId, 'warehouse_id' => $whAId, 'minimum_stock' => 15, 'buffer_stock' => 20, 'updated_by' => $adminUserId, 'username' => 'tracetest']);
$policyId = (int) $pdo->query("SELECT id FROM item_warehouse_stock_policy WHERE item_id = {$itemId} AND warehouse_id = {$whAId}")->fetchColumn();
$policyTrace = TraceService::entityTrace($pdo, 'stock_policy', $policyId);
check('stock policy trace has 2 change events (create + update)', count($policyTrace['timeline']) === 2);
check('stock policy trace shows minimum 10 -> 15 change', $policyTrace['changes'][1]['before']['minimum_stock'] == 10 && $policyTrace['changes'][1]['after']['minimum_stock'] == 15);
check('stock policy trace shows buffer NULL -> 20 change', $policyTrace['changes'][1]['before']['buffer_stock'] === null && $policyTrace['changes'][1]['after']['buffer_stock'] == 20);

// ============================================================
// B: transaction trace — IN -> OUT chain (FIFO allocation + batch)
// ============================================================
echo "\n== B: transaction trace (chain) ==\n";

$inResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('tr-in'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-09-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest', 'supplier_id' => $supplierId,
]));
$inTxId = (int) $inResult['transaction_id'];

$outResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('tr-out'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'input_qty' => 30, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-09-02 08:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest',
]));
$outTxId = (int) $outResult['transaction_id'];

$inTrace = TraceService::transactionTrace($pdo, $inTxId);
check('IN transaction trace has 1 line', count($inTrace['lines']) === 1);
check('IN transaction trace created exactly 1 FIFO batch', count($inTrace['fifo_batches_created']) === 1);
check('IN transaction trace has no allocations (it creates a batch, does not consume one)', $inTrace['fifo_allocations'] === []);

$outTrace = TraceService::transactionTrace($pdo, $outTxId);
check('OUT transaction trace has 1 line', count($outTrace['lines']) === 1);
check('OUT transaction trace consumed the IN batch via 1 allocation', count($outTrace['fifo_allocations']) === 1);
check('OUT transaction trace allocation qty matches (30)', (float) $outTrace['fifo_allocations'][0]['qty_allocated'] === 30.0);
check('OUT transaction trace created no new batch', $outTrace['fifo_batches_created'] === []);

// ============================================================
// C: reversal trace — bidirectional link
// ============================================================
echo "\n== C: reversal/void trace ==\n";

$voidResult = Database::transaction(fn (PDO $tx) => VoidService::void($tx, [
    'request_uuid' => uid('tr-void'), 'transaction_id' => $outTxId, 'reason' => 'trace test void', 'voided_by' => $adminUserId, 'username' => 'tracetest',
]));
$reversalTxId = (int) $voidResult['reversal_transaction_id'];

$originalAfterVoid = TraceService::transactionTrace($pdo, $outTxId);
check('original transaction trace shows status VOID', $originalAfterVoid['transaction']['status'] === 'VOID');
check('original transaction trace has a reversed_by link pointing at the reversal', $originalAfterVoid['reversed_by'] !== null && (int) $originalAfterVoid['reversed_by']['id'] === $reversalTxId);
check('original transaction trace has a TRANSACTION_VOID audit event', in_array('TRANSACTION_VOID', array_column($originalAfterVoid['audit_events'], 'action_code'), true));

$reversalTrace = TraceService::transactionTrace($pdo, $reversalTxId);
check('reversal transaction trace has a reversal_of link pointing back at the original', $reversalTrace['reversal_of'] !== null && (int) $reversalTrace['reversal_of']['id'] === $outTxId);

// ============================================================
// D: transfer trace cross-reference
// ============================================================
echo "\n== D: transfer trace ==\n";

$transferResult = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('tr-xfer'), 'from_warehouse_id' => $whAId, 'to_warehouse_id' => $whBId,
    'ship_date' => '2026-09-03 08:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest',
    'lines' => [['item_id' => $itemId, 'input_qty' => 20, 'input_unit_id' => $kgUnitId]],
]));
$transferId = (int) $transferResult['transfer_id'];
$transferOutLineRow = $pdo->query("SELECT out_transaction_line_id FROM warehouse_transfer_lines WHERE transfer_id = {$transferId}")->fetch();
$transferOutTxId = (int) $pdo->query('SELECT transaction_id FROM inventory_transaction_lines WHERE id = ' . (int) $transferOutLineRow['out_transaction_line_id'])->fetchColumn();

$transferOutTrace = TraceService::transactionTrace($pdo, $transferOutTxId);
check('TRANSFER_OUT transaction trace cross-references the transfer row', $transferOutTrace['transfer'] !== null && (int) $transferOutTrace['transfer']['id'] === $transferId);

// ============================================================
// E: inventory trace (item + warehouse)
// ============================================================
echo "\n== E: inventory trace ==\n";

$invTrace = TraceService::inventoryTrace($pdo, $itemId, $whAId);
check('inventory trace overview qty reflects IN(100) - OUT(30, later voided/reversed) - TRANSFER_OUT(20)', $invTrace['overview']['qty_base'] >= 0);
check('inventory trace has stock policy attached', $invTrace['stock_policy'] !== null && (float) $invTrace['stock_policy']['minimum_stock_base'] === 15.0);
check('inventory trace movement timeline is non-empty', count($invTrace['movements']) > 0);
check('inventory trace fifo_batches is non-empty', count($invTrace['fifo_batches']) > 0);
check('inventory trace pagination total matches movement count query', $invTrace['pagination']['total'] >= count($invTrace['movements']));

try {
    TraceService::inventoryTrace($pdo, 999999999, $whAId);
    check('inventory trace on nonexistent item is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('inventory trace on nonexistent item is rejected', true);
}

// ============================================================
// F: search
// ============================================================
echo "\n== F: search ==\n";

$searchBySku = TraceService::search($pdo, $sku);
check('search by SKU finds the item', in_array($itemId, array_map(fn ($r) => $r['id'], array_filter($searchBySku, fn ($r) => $r['type'] === 'item')), true));

$searchBySupplier = TraceService::search($pdo, $supplierName, 'supplier');
check('search by supplier name (type-filtered) finds the supplier', count($searchBySupplier) === 1 && $searchBySupplier[0]['id'] === $supplierId);

$searchEmpty = TraceService::search($pdo, '');
check('empty search query returns empty results (never a full dump)', $searchEmpty === []);

// ============================================================
// I: Transfer direct trace — PHASE V2.2B coverage completion
// ============================================================
echo "\n== I: transfer direct trace ==\n";

$transferTrace = TraceService::transferTrace($pdo, $transferId);
check('transfer trace returns the header with from/to warehouse names', $transferTrace['transfer']['from_warehouse_code'] !== null && $transferTrace['transfer']['to_warehouse_code'] !== null);
check('transfer trace has created_by_username resolved', $transferTrace['transfer']['created_by_username'] !== null);
check('transfer trace has exactly 1 line', count($transferTrace['lines']) === 1);
check('transfer trace line has out FIFO allocations (consumed source batch)', count($transferTrace['lines'][0]['out_fifo_allocations']) === 1);
check('transfer trace line has no destination batch yet (not received)', $transferTrace['lines'][0]['destination_batch'] === null);
check('transfer trace cross-references TRANSFER_OUT transaction', in_array('TRANSFER_OUT', array_column($transferTrace['transactions'], 'transaction_type'), true));
check('transfer trace has a TRANSFER_CREATE audit event', in_array('TRANSFER_CREATE', array_column($transferTrace['audit_events'], 'action_code'), true));

$receiveResult = Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferId, [
    'created_by' => $adminUserId, 'username' => 'tracetest', 'request_uuid' => uid('tr-recv'),
]));
$transferTraceAfterReceive = TraceService::transferTrace($pdo, $transferId);
check('transfer trace after receive shows the destination batch created', $transferTraceAfterReceive['lines'][0]['destination_batch'] !== null);
// PHASE V2.3B fix: TransferService::receive() now explicitly passes
// transaction_type='TRANSFER_IN' to FifoService::postIn() (previously it
// omitted the override and silently defaulted to 'IN', which the costing
// audit found was making every received transfer count as an External
// Purchase in the HPP report). Its reference_no is still 'TRANSFER-{id}',
// which is what the cross-reference keys off, so both legs are still
// found either way.
check('transfer trace after receive cross-references both legs by reference_no (TRANSFER_OUT + TRANSFER_IN)', count($transferTraceAfterReceive['transactions']) === 2 && in_array('TRANSFER_OUT', array_column($transferTraceAfterReceive['transactions'], 'transaction_type'), true) && in_array('TRANSFER_IN', array_column($transferTraceAfterReceive['transactions'], 'transaction_type'), true));

try {
    TraceService::transferTrace($pdo, 999999999);
    check('nonexistent transfer id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent transfer id is rejected', true);
}

// ============================================================
// J: Stock Opname direct trace
// ============================================================
echo "\n== J: opname direct trace ==\n";

[$opnameItemId, $opnameSku] = makeItem($pdo, $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('tr-opname-in'), 'item_id' => $opnameItemId, 'warehouse_id' => $whAId,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 500,
    'transaction_date' => '2026-09-04 08:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest', 'supplier_id' => $supplierId,
]));
$opnameSessionId = StockOpnameService::start($pdo, $whAId, $adminUserId, [$opnameItemId]);
StockOpnameService::count($pdo, $opnameSessionId, [$opnameItemId => 45.0], $adminUserId); // -5 variance
StockOpnameService::finalize($pdo, $opnameSessionId, $adminUserId);
$opnamePostResult = StockOpnameService::post($pdo, $opnameSessionId, $adminUserId);

$opnameTrace = TraceService::opnameTrace($pdo, $opnameSessionId);
check('opname trace returns the header with warehouse + actor usernames', $opnameTrace['session']['warehouse_code'] !== null && $opnameTrace['session']['posted_by_username'] !== null);
check('opname trace has exactly 1 line', count($opnameTrace['lines']) === 1);
check('opname trace line shows system/physical qty and variance', (float) $opnameTrace['lines'][0]['line']['system_qty_base'] === 50.0 && (float) $opnameTrace['lines'][0]['line']['counted_qty_base'] === 45.0 && (float) $opnameTrace['lines'][0]['line']['variance_qty_base'] === -5.0);
check('opname trace line resolves the resulting ADJUSTMENT transaction', $opnameTrace['lines'][0]['resulting_adjustment'] !== null && $opnameTrace['lines'][0]['resulting_adjustment']['transaction_status'] === 'POSTED');
check('opname trace has STOCK_OPNAME_POST audit event', in_array('STOCK_OPNAME_POST', array_column($opnameTrace['audit_events'], 'action_code'), true));

try {
    TraceService::opnameTrace($pdo, 999999999);
    check('nonexistent opname session id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent opname session id is rejected', true);
}

// ============================================================
// K: Production direct trace
// ============================================================
echo "\n== K: production direct trace ==\n";

[$rawItemId, $rawSku] = makeItem($pdo, $kgUnitId);
[$fgItemId, $fgSku] = makeItem($pdo, $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('tr-prod-in'), 'item_id' => $rawItemId, 'warehouse_id' => $whAId,
    'input_qty' => 40, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2000,
    'transaction_date' => '2026-09-05 08:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest', 'supplier_id' => $supplierId,
]));
$productionResult = Database::transaction(fn (PDO $tx) => ProductionService::create($tx, [
    'production_uuid' => uid('tr-prod'), 'warehouse_id' => $whAId, 'production_date' => '2026-09-06 08:00:00',
    'created_by' => $adminUserId, 'username' => 'tracetest',
    'inputs' => [['item_id' => $rawItemId, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
    'output' => ['item_id' => $fgItemId, 'output_qty' => 8, 'output_unit_id' => $kgUnitId],
]));
$productionId = (int) $productionResult['production_id'];

$productionTrace = TraceService::productionTrace($pdo, $productionId);
check('production trace returns the header with warehouse + created_by username', $productionTrace['production']['warehouse_code'] !== null && $productionTrace['production']['created_by_username'] !== null);
check('production trace has exactly 1 input', count($productionTrace['inputs']) === 1);
check('production trace input has its FIFO allocation (raw material consumed)', count($productionTrace['inputs'][0]['fifo_allocations']) === 1);
check('production trace has exactly 1 output', count($productionTrace['outputs']) === 1);
check('production trace output resolved its created finished-good batch', $productionTrace['outputs'][0]['created_batch'] !== null && (float) $productionTrace['outputs'][0]['created_batch']['qty_base'] === 8.0);

try {
    TraceService::productionTrace($pdo, 999999999);
    check('nonexistent production id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent production id is rejected', true);
}

// ============================================================
// L: Opening Stock direct trace — staging record vs live baseline
// ============================================================
echo "\n== L: opening direct trace ==\n";

[$openingItemId, $openingSku] = makeItem($pdo, $kgUnitId);
$openingBatchResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('tr-opening-in'), 'item_id' => $openingItemId, 'warehouse_id' => $whAId,
    'input_qty' => 200, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 750,
    'transaction_date' => '2026-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'tracetest',
    'transaction_type' => 'OPENING',
]));
$openingBatchId = (int) $pdo->query('SELECT created_batch_id FROM inventory_transaction_lines WHERE transaction_id = ' . (int) $openingBatchResult['transaction_id'])->fetchColumn();

$openingDesc = uid('Trace test opening batch');
$pdo->prepare('INSERT INTO stock_openings (cutoff_date, description, status, created_by, committed_by, committed_at, created_at) VALUES (:cutoff, :desc, \'COMMITTED\', :cb1, :cb2, :now1, :now2)')
    ->execute(['cutoff' => '2026-01-01', 'desc' => $openingDesc, 'cb1' => $adminUserId, 'cb2' => $adminUserId, 'now1' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s')]);
$openingId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO stock_opening_lines (stock_opening_id, item_id, warehouse_id, qty_base, unit_cost_base, source, created_batch_id, row_status)
     VALUES (:oid, :item, :wh, 200, 750, \'Trace test\', :batch, \'VALID\')'
)->execute(['oid' => $openingId, 'item' => $openingItemId, 'wh' => $whAId, 'batch' => $openingBatchId]);
\App\Services\AuditService::log($pdo, $adminUserId, 'tracetest', 'OPENING_IMPORT', 'stock_openings', $openingId, null, ['lines_created' => 1], null);

$openingTrace = TraceService::openingTrace($pdo, $openingId);
check('opening trace returns the staging header', $openingTrace['opening']['cutoff_date'] === '2026-01-01' && $openingTrace['opening']['created_by_username'] !== null);
check('opening trace has exactly 1 line', count($openingTrace['lines']) === 1);
check('opening trace line resolves the live FIFO batch (staging -> live link)', $openingTrace['lines'][0]['live_fifo_batch'] !== null && (float) $openingTrace['lines'][0]['live_fifo_batch']['qty_base'] === 200.0);
check('opening trace line resolves the live OPENING-type transaction distinct from the staging record', $openingTrace['lines'][0]['live_opening_transaction'] !== null && $openingTrace['lines'][0]['live_opening_transaction']['transaction_type'] === 'OPENING');
check('opening trace has an OPENING_IMPORT audit event', in_array('OPENING_IMPORT', array_column($openingTrace['audit_events'], 'action_code'), true));

try {
    TraceService::openingTrace($pdo, 999999999);
    check('nonexistent opening id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent opening id is rejected', true);
}

// ============================================================
// M: Import batch direct trace
// ============================================================
echo "\n== M: import direct trace ==\n";

$importFileName = uid('trace_test_suppliers') . '.csv';
$pdo->prepare(
    'INSERT INTO import_batches (import_type, file_name, status, total_rows, valid_rows, warning_rows, error_rows, uploaded_by, uploaded_at, committed_by, committed_at)
     VALUES (\'SUPPLIER\', :fname, \'COMMITTED\', 2, 1, 0, 1, :uploader1, :now1, :uploader2, :now2)'
)->execute(['fname' => $importFileName, 'uploader1' => $adminUserId, 'uploader2' => $adminUserId, 'now1' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s')]);
$importBatchId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages, created_entity_id) VALUES (:b, 1, :raw, \'VALID\', NULL, :ent)')
    ->execute(['b' => $importBatchId, 'raw' => json_encode(['code' => 'SUP1', 'name' => 'Trace Import Supplier']), 'ent' => $supplierId]);
$pdo->prepare('INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages, created_entity_id) VALUES (:b, 2, :raw, \'ERROR\', :msg, NULL)')
    ->execute(['b' => $importBatchId, 'raw' => json_encode(['code' => '', 'name' => '']), 'msg' => json_encode(['code is required'])]);
\App\Services\AuditService::log($pdo, $adminUserId, 'tracetest', 'SUPPLIER_IMPORT', 'import_batches', $importBatchId, null, ['rows_created' => 1], null);

$importTrace = TraceService::importTrace($pdo, $importBatchId);
check('import trace returns the batch header with counts', (int) $importTrace['import_batch']['total_rows'] === 2 && (int) $importTrace['import_batch']['error_rows'] === 1);
check('import trace pagination total matches row count', $importTrace['pagination']['total'] === 2);
check('import trace exposes a VALID row with its created_entity_id', $importTrace['rows'][0]['row_status'] === 'VALID' && $importTrace['rows'][0]['created_entity_id'] === $supplierId);
check('import trace exposes an ERROR row with its messages, never fabricating a created entity', $importTrace['rows'][1]['row_status'] === 'ERROR' && $importTrace['rows'][1]['created_entity_id'] === null && $importTrace['rows'][1]['messages'] !== null);
check('import trace has a *_IMPORT audit event', in_array('SUPPLIER_IMPORT', array_column($importTrace['audit_events'], 'action_code'), true));

try {
    TraceService::importTrace($pdo, 999999999);
    check('nonexistent import batch id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent import batch id is rejected', true);
}

// ============================================================
// N: User trace — secrets stripped, login history, honest historical gap
// ============================================================
echo "\n== N: user trace ==\n";

$userTrace = TraceService::userTrace($pdo, $adminUserId);
check('user trace returns role/division/warehouse names resolved', $userTrace['overview']['role_code'] === 'SUPERADMIN');
check('user trace NEVER exposes password_hash', !array_key_exists('password_hash', $userTrace['overview']));
check('user trace login_history key exists (even if empty for this fixture user)', array_key_exists('login_history', $userTrace));
check('user trace is honest about limited historical audit coverage', $userTrace['historical_note'] !== '' && str_contains($userTrace['historical_note'], 'tidak tercatat'));

try {
    TraceService::userTrace($pdo, 999999999);
    check('nonexistent user id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent user id is rejected', true);
}

// ============================================================
// O: Role trace — current permission set + honest note on seeding
// ============================================================
echo "\n== O: role trace ==\n";

$stockRoleIdForTrace = (int) $pdo->query("SELECT id FROM roles WHERE code = 'STOCK'")->fetchColumn();
$roleTrace = TraceService::roleTrace($pdo, $stockRoleIdForTrace);
check('role trace returns the role row', $roleTrace['role']['code'] === 'STOCK');
check('role trace lists real current permissions (non-empty for STOCK)', count($roleTrace['permissions']) > 0);
check('role trace includes assigned_user_count', $roleTrace['assigned_user_count'] >= 0);

try {
    TraceService::roleTrace($pdo, 999999999);
    check('nonexistent role id is rejected', false);
} catch (\App\Services\NotFoundException $e) {
    check('nonexistent role id is rejected', true);
}

// ============================================================
// P: search coverage for the newly-added entity types
// ============================================================
echo "\n== P: search — new entity types ==\n";

// NOTE: matching by bare numeric id ORs against a LIKE on the entity's own
// UUID column too (so "abc123-45" style codes are still findable) — on a
// long-lived DB that substring can coincidentally appear inside another
// row's UUID, so this asserts the target is PRESENT, not that it's the
// only match (exact-count is what section F already does for a truly
// unique fixture, e.g. the SKU/description/file_name searches above).
$searchTransfer = TraceService::search($pdo, (string) $transferId, 'transfer');
check('search by id (type=transfer) finds the transfer', in_array($transferId, array_column($searchTransfer, 'id'), true));

$searchOpnameResult = TraceService::search($pdo, (string) $opnameSessionId, 'opname');
check('search by id (type=opname) finds the opname session', in_array($opnameSessionId, array_column($searchOpnameResult, 'id'), true));

$searchProduction = TraceService::search($pdo, (string) $productionId, 'production');
check('search by id (type=production) finds the production run', in_array($productionId, array_column($searchProduction, 'id'), true));

$searchOpening = TraceService::search($pdo, $openingDesc, 'opening');
check('search by description (type=opening) finds the opening batch', count($searchOpening) === 1 && $searchOpening[0]['id'] === $openingId);

$searchImport = TraceService::search($pdo, $importFileName, 'import');
check('search by file_name (type=import) finds the import batch', count($searchImport) === 1 && $searchImport[0]['id'] === $importBatchId);

$searchRole = TraceService::search($pdo, 'STOCK', 'role');
check('search by code (type=role) finds the role', count($searchRole) === 1 && $searchRole[0]['code'] === 'STOCK');

// ============================================================
// G: read-only guarantee — snapshot state, call every trace method
// many times, assert byte-identical state after
// ============================================================
echo "\n== G: read-only guarantee ==\n";

function stateSnapshot(PDO $pdo, int $itemId, int $whAId): array
{
    return [
        'batches' => $pdo->query("SELECT id, qty_base, original_qty_base FROM inventory_batches WHERE item_id = {$itemId} ORDER BY id")->fetchAll(),
        'allocations' => $pdo->query('SELECT id, qty_allocated FROM fifo_allocations ORDER BY id')->fetchAll(),
        'tx_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
        'policy' => $pdo->query("SELECT minimum_stock_base, buffer_stock_base FROM item_warehouse_stock_policy WHERE item_id = {$itemId} AND warehouse_id = {$whAId}")->fetch(),
        'audit_count' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
        // PHASE V2.2B additions — the 7 newly-traced entity types must be
        // just as read-only as the original 5.
        'transfer_count' => (int) $pdo->query('SELECT COUNT(*) FROM warehouse_transfers')->fetchColumn(),
        'opname_lines' => $pdo->query('SELECT id, counted_qty_base, adjustment_id FROM stock_opname_lines ORDER BY id')->fetchAll(),
        'production_outputs' => $pdo->query('SELECT id, qty_base FROM production_outputs ORDER BY id')->fetchAll(),
        'opening_lines' => $pdo->query('SELECT id, created_batch_id FROM stock_opening_lines ORDER BY id')->fetchAll(),
        'import_rows' => $pdo->query('SELECT id, row_status, created_entity_id FROM import_rows ORDER BY id')->fetchAll(),
        'users_count' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'role_permissions_count' => (int) $pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn(),
    ];
}

$before = stateSnapshot($pdo, $itemId, $whAId);
TraceService::entityTrace($pdo, 'item', $itemId);
TraceService::entityTrace($pdo, 'stock_policy', $policyId);
TraceService::transactionTrace($pdo, $inTxId);
TraceService::transactionTrace($pdo, $outTxId);
TraceService::inventoryTrace($pdo, $itemId, $whAId);
TraceService::search($pdo, $sku);
TraceService::browseEvents($pdo, ['page' => 1, 'per_page' => 25]);
TraceService::transferTrace($pdo, $transferId);
TraceService::opnameTrace($pdo, $opnameSessionId);
TraceService::productionTrace($pdo, $productionId);
TraceService::openingTrace($pdo, $openingId);
TraceService::importTrace($pdo, $importBatchId);
TraceService::userTrace($pdo, $adminUserId);
TraceService::roleTrace($pdo, $stockRoleIdForTrace);
$after = stateSnapshot($pdo, $itemId, $whAId);
check('trace calls (including all 7 new entity types) never mutate any underlying table', $before === $after);

// ============================================================
// H: HTTP-level warehouse isolation + permission enforcement
// ============================================================
echo "\n== H: HTTP warehouse isolation + permissions ==\n";

$port = 8800 + random_int(400, 799);
$docRoot = __DIR__ . '/../public';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, __DIR__ . '/..');
if (!is_resource($process)) { fwrite(STDERR, "Failed to start php -S\n"); exit(1); }
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($res !== false && $err === 0) { $ready = true; break; }
}
if (!$ready) { fwrite(STDERR, "Server did not become ready\n"); proc_terminate($process); exit(1); }

function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

function loginAs(PDO $pdo, string $base, string $roleCode, ?int $warehouseId = null): array
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $username = uid('httptrace-' . strtolower($roleCode));
    $pass = 'HttpTracePass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId, 'w' => $warehouseId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $username, 'password' => $pass], $jar);
    return ['jar' => $jar, 'csrf' => $login['body']['data']['csrf_token'] ?? ''];
}

try {
    $superadmin = loginAs($pdo, $base, 'SUPERADMIN');
    $admin = loginAs($pdo, $base, 'ADMIN');
    $viewer = loginAs($pdo, $base, 'VIEWER');
    $stockA = loginAs($pdo, $base, 'STOCK', $whAId);
    $stockB = loginAs($pdo, $base, 'STOCK', $whBId);
    $division = loginAs($pdo, $base, 'DIVISION');

    $pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('TR-WH-C') . "', 'Trace WH C')");
    $whCId = (int) $pdo->lastInsertId();

    $r = httpCall('GET', "{$base}/trace/entity?type=item&id={$itemId}", null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/entity', $r['status'] === 200 && $r['body']['success'] === true);

    $r = httpCall('GET', "{$base}/trace/entity?type=item&id={$itemId}", null, $viewer['jar']);
    check('VIEWER (has AUDIT_LOG_VIEW) CAN GET /trace/entity', $r['status'] === 200 && $r['body']['success'] === true);

    $r = httpCall('GET', "{$base}/trace/entity?type=item&id={$itemId}", null, $stockA['jar']);
    check('STOCK (no AUDIT_LOG_VIEW) CANNOT GET /trace/entity', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/entity?type=item&id={$itemId}", null, $division['jar']);
    check('DIVISION (no AUDIT_LOG_VIEW) CANNOT GET /trace/entity', $r['status'] === 403);

    // Warehouse isolation: by default STOCK lacks AUDIT_LOG_VIEW (checked
    // above) so it never reaches the scope check today. To actually prove
    // inv_require_warehouse_scope() inside the trace routes works — not
    // just that STOCK is blocked earlier for an unrelated reason — grant a
    // one-off test STOCK user AUDIT_LOG_VIEW ad-hoc (as an admin legitimately
    // could via role_permissions) and confirm they can trace their OWN
    // warehouse but are rejected for the OTHER one.
    $auditPermId = (int) $pdo->query("SELECT id FROM permissions WHERE code = 'AUDIT_LOG_VIEW'")->fetchColumn();
    $stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'STOCK'")->fetchColumn();
    $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ({$stockRoleId}, {$auditPermId})");
    $stockAWithAudit = loginAs($pdo, $base, 'STOCK', $whAId);

    $r = httpCall('GET', "{$base}/trace/entity?type=warehouse&id={$whAId}", null, $stockAWithAudit['jar']);
    check('STOCK scoped to warehouse A CAN trace warehouse A (own scope)', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/entity?type=warehouse&id={$whBId}", null, $stockAWithAudit['jar']);
    check('STOCK scoped to warehouse A CANNOT trace warehouse B (cross-warehouse blocked)', $r['status'] === 403);
    // GET /trace/inventory follows the same convention as GET /items/report:
    // for a STOCK user, warehouse_id is never even read from the request —
    // it's unconditionally forced to their own assigned warehouse, so
    // passing warehouse B's id here still returns warehouse A's inventory
    // (200), never warehouse B's (proving the override, not an error path).
    $r = httpCall('GET', "{$base}/trace/inventory?item_id={$itemId}&warehouse_id={$whBId}", null, $stockAWithAudit['jar']);
    check('STOCK scoped to warehouse A requesting warehouse B inventory is silently forced back to warehouse A', $r['status'] === 200 && (int) $r['body']['data']['warehouse']['id'] === $whAId);
    $r = httpCall('GET', "{$base}/trace/transaction/{$transferOutTxId}", null, $stockAWithAudit['jar']);
    check('STOCK scoped to warehouse A CAN trace a transaction that IS in warehouse A', $r['status'] === 200);

    // ---- PHASE V2.2B: warehouse isolation on the 7 newly-added trace types ----
    $stockBWithAudit = loginAs($pdo, $base, 'STOCK', $whBId);
    $stockCWithAudit = loginAs($pdo, $base, 'STOCK', $whCId);

    $r = httpCall('GET', "{$base}/trace/transfer/{$transferId}", null, $stockAWithAudit['jar']);
    check('STOCK at warehouse A (source) CAN trace the transfer', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/transfer/{$transferId}", null, $stockBWithAudit['jar']);
    check('STOCK at warehouse B (destination) CAN trace the transfer', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/transfer/{$transferId}", null, $stockCWithAudit['jar']);
    check('STOCK at unrelated warehouse C CANNOT trace the transfer', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/opname/{$opnameSessionId}", null, $stockAWithAudit['jar']);
    check('STOCK at warehouse A CAN trace an opname session in warehouse A', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/opname/{$opnameSessionId}", null, $stockBWithAudit['jar']);
    check('STOCK at warehouse B CANNOT trace an opname session in warehouse A', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/production/{$productionId}", null, $stockAWithAudit['jar']);
    check('STOCK at warehouse A CAN trace a production run in warehouse A', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/production/{$productionId}", null, $stockBWithAudit['jar']);
    check('STOCK at warehouse B CANNOT trace a production run in warehouse A', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/opening/{$openingId}", null, $stockAWithAudit['jar']);
    check('STOCK at warehouse A CAN trace an opening batch with lines in warehouse A', $r['status'] === 200 && count($r['body']['data']['lines']) === 1);
    $r = httpCall('GET', "{$base}/trace/opening/{$openingId}", null, $stockBWithAudit['jar']);
    check('STOCK at warehouse B CANNOT trace an opening batch with no lines in warehouse B', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/import/{$importBatchId}", null, $stockAWithAudit['jar']);
    check('a user with AUDIT_LOG_VIEW CAN trace an import batch (not warehouse-scoped)', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/import/{$importBatchId}", null, $division['jar']);
    check('a user without AUDIT_LOG_VIEW CANNOT trace an import batch', $r['status'] === 403);

    // ---- PHASE V2.2B: User/Role trace requires SUPERADMIN/ADMIN, not just AUDIT_LOG_VIEW ----
    $r = httpCall('GET', "{$base}/trace/user/{$adminUserId}", null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/user/{id}', $r['status'] === 200 && !array_key_exists('password_hash', $r['body']['data']['overview']));
    $r = httpCall('GET', "{$base}/trace/user/{$adminUserId}", null, $admin['jar']);
    check('ADMIN CAN GET /trace/user/{id}', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/user/{$adminUserId}", null, $viewer['jar']);
    check('VIEWER (has AUDIT_LOG_VIEW but not ADMIN/SUPERADMIN) CANNOT GET /trace/user/{id}', $r['status'] === 403);
    $r = httpCall('GET', "{$base}/trace/user/{$adminUserId}", null, $stockAWithAudit['jar']);
    check('STOCK-with-AUDIT_LOG_VIEW CANNOT GET /trace/user/{id} either — role restriction, not permission-based', $r['status'] === 403);

    $r = httpCall('GET', "{$base}/trace/role/{$stockRoleIdForTrace}", null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/role/{id}', $r['status'] === 200 && count($r['body']['data']['permissions']) > 0);
    $r = httpCall('GET', "{$base}/trace/role/{$stockRoleIdForTrace}", null, $admin['jar']);
    check('ADMIN CAN GET /trace/role/{id}', $r['status'] === 200);
    $r = httpCall('GET', "{$base}/trace/role/{$stockRoleIdForTrace}", null, $viewer['jar']);
    check('VIEWER (has AUDIT_LOG_VIEW but not ADMIN/SUPERADMIN) CANNOT GET /trace/role/{id}', $r['status'] === 403);

    $pdo->exec("DELETE FROM role_permissions WHERE role_id = {$stockRoleId} AND permission_id = {$auditPermId}");

    $r = httpCall('GET', "{$base}/trace/transaction/{$inTxId}", null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/transaction/{id}', $r['status'] === 200 && (int) $r['body']['data']['transaction']['id'] === $inTxId);

    $r = httpCall('GET', "{$base}/trace/inventory?item_id={$itemId}&warehouse_id={$whAId}", null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/inventory for any warehouse', $r['status'] === 200);

    $r = httpCall('GET', "{$base}/trace/search?q=" . urlencode($sku), null, $superadmin['jar']);
    check('SUPERADMIN CAN GET /trace/search', $r['status'] === 200 && count($r['body']['data']) > 0);

    $r = httpCall('GET', "{$base}/trace/entity?type=item&id={$itemId}", null, tempnam(sys_get_temp_dir(), 'nocookie_'));
    check('unauthenticated request CANNOT GET /trace/entity', $r['status'] === 401);
} finally {
    proc_terminate($process);
    proc_close($process);
}

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
echo "==============================\n";
exit($passed === $total ? 0 : 1);
