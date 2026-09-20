<?php
declare(strict_types=1);

/**
 * PHASE V2.3 — InventoryHppReportService: formula correctness against a
 * hand-computable synthetic scenario, warehouse scoping, pagination,
 * Excel export structure, and trace-linkage (every row this service
 * returns must carry real ids the EXISTING TraceService can resolve —
 * this test proves that link, it does not re-implement tracing).
 *
 * Usage: php tests/inventory_hpp_report_test.php
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
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;
use App\Services\TraceService;
use App\Services\InventoryHppReportService;
use App\Services\ExcelWriterService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) < $eps; }

$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('hppsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'HPP Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('HPP-WH-A') . "', 'HPP WH A')");
$whAId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO warehouses (code, name) VALUES ('" . uid('HPP-WH-B') . "', 'HPP WH B')");
$whBId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('HPP-SUP'), 'n' => 'HPP Supplier']);
$supplierId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId): array
{
    $sku = uid('HPP-SKU');
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 5, :status)');
    $stmt->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

[$itemId, $sku] = makeItem($pdo, $kgUnitId);

// ============================================================
// A: build a hand-computable scenario
//   OPENING 100 @ 1000 on 2026-01-01 (BEFORE the report period)
//   Period: 2026-02-01 .. 2026-02-28
//     IN       50 @ 1200 on 2026-02-05  -> purchase = 60,000
//     OUT      30        on 2026-02-10  -> FIFO consumes 30 @ 1000 = 30,000
//     ADJUSTMENT -5 (DAMAGE) on 2026-02-15, consumes FIFO @ 1000    = -5,000
//   Expected:
//     opening (before 2026-02-01)          = 100,000
//     purchase                              =  60,000
//     fifo_hpp (OUT only, excludes the ADJ) =  30,000
//     ending (<= 2026-02-28)                 = 100,000+60,000-30,000-5,000 = 125,000
//     reconciliation = opening+purchase-ending = 100,000+60,000-125,000    =  35,000
//     variance = reconciliation - fifo_hpp                                 =   5,000
//     adjustment_net                                                       =  -5,000
//     (variance == -adjustment_net, the algebraic identity this report's
//      docblock claims, proven here with a real number, not just by reasoning)
// ============================================================
echo "== A: fixture setup ==\n";

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('hpp-open'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'hpptest',
    'transaction_type' => 'OPENING',
]));

$inResult = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('hpp-in'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1200,
    'transaction_date' => '2026-02-05 08:00:00', 'created_by' => $adminUserId, 'username' => 'hpptest', 'supplier_id' => $supplierId,
]));
$inTxId = (int) $inResult['transaction_id'];

$outResult = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('hpp-out'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'input_qty' => 30, 'input_unit_id' => $kgUnitId, 'transaction_type' => 'OUT',
    'transaction_date' => '2026-02-10 08:00:00', 'created_by' => $adminUserId, 'username' => 'hpptest',
]));
$outTxId = (int) $outResult['transaction_id'];

$adjResult = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('hpp-adj'), 'item_id' => $itemId, 'warehouse_id' => $whAId,
    'qty_base_delta' => -5, 'adjustment_type' => 'DAMAGE', 'reason' => 'HPP test damage',
    'transaction_date' => '2026-02-15 08:00:00', 'created_by' => $adminUserId,
]));

$startDate = '2026-02-01';
$endDate = '2026-02-28';

// ============================================================
// B: summary() formula correctness
// ============================================================
echo "\n== B: summary formula correctness ==\n";

$summary = InventoryHppReportService::summary($pdo, $startDate, $endDate, $whAId);
check('opening_value = 100,000', approx($summary['opening_value'], 100000.0), (string) $summary['opening_value']);
check('external_purchase = 60,000', approx($summary['external_purchase'], 60000.0), (string) $summary['external_purchase']);
check('fifo_hpp = 30,000 (OUT only, excludes the ADJUSTMENT allocation)', approx($summary['fifo_hpp'], 30000.0), (string) $summary['fifo_hpp']);
check('ending_value = 125,000', approx($summary['ending_value'], 125000.0), (string) $summary['ending_value']);
check('hpp_reconciliation = opening+purchase-ending = 35,000', approx($summary['hpp_reconciliation'], 35000.0), (string) $summary['hpp_reconciliation']);
check('variance = reconciliation - fifo_hpp = 5,000', approx($summary['variance'], 5000.0), (string) $summary['variance']);
check('adjustment_net = -5,000 (disclosed, never hidden)', approx($summary['non_hpp_movements']['adjustment_net'], -5000.0), (string) $summary['non_hpp_movements']['adjustment_net']);
check('variance == -adjustment_net (algebraic identity holds on real data)', approx($summary['variance'], -$summary['non_hpp_movements']['adjustment_net']));
check('reversal_net = 0 (none posted)', approx($summary['non_hpp_movements']['reversal_net'], 0.0));
check('transfer_net = 0 (no transfers)', approx($summary['non_hpp_movements']['transfer_net'], 0.0));

// Company-wide (no warehouse filter) must equal the single-warehouse figure
// here since whB has zero activity.
$summaryCompany = InventoryHppReportService::summary($pdo, $startDate, $endDate, null);
check('company-wide summary matches single-active-warehouse summary when the other warehouse is empty', approx($summaryCompany['hpp_reconciliation'], $summary['hpp_reconciliation']));

// ============================================================
// C: warehouseBreakdown()
// ============================================================
echo "\n== C: warehouse breakdown ==\n";

// PHASE V2.3D: warehouseBreakdown() now returns {cutover, panels} — cutover
// metadata (requested/effective start, live_opening_date) alongside the
// same panel list this test always expected.
['panels' => $panels] = InventoryHppReportService::warehouseBreakdown($pdo, $startDate, $endDate, null);
$panelA = null;
foreach ($panels as $p) {
    if ((int) $p['warehouse']['id'] === $whAId) {
        $panelA = $p;
    }
}
check('warehouse breakdown includes warehouse A with correct fifo_hpp', $panelA !== null && approx($panelA['fifo_hpp'], 30000.0));
check('warehouse breakdown daily_trend is non-empty and covers the period', $panelA !== null && count($panelA['daily_trend']) === 28);

['panels' => $scopedPanels] = InventoryHppReportService::warehouseBreakdown($pdo, $startDate, $endDate, $whAId);
check('warehouse-scoped breakdown returns exactly 1 panel', count($scopedPanels) === 1 && (int) $scopedPanels[0]['warehouse']['id'] === $whAId);

// ============================================================
// D: dailyRecap() — every calendar day present, running balance correct
// ============================================================
echo "\n== D: daily recap ==\n";

$daily = InventoryHppReportService::dailyRecap($pdo, $startDate, $endDate, $whAId, 1, 50);
check('daily recap covers all 28 days of February', $daily['pagination']['total'] === 28);
$byDate = [];
foreach ($daily['rows'] as $r) {
    $byDate[$r['date']] = $r;
}
check('2026-02-05 shows the purchase', isset($byDate['2026-02-05']) && approx($byDate['2026-02-05']['pembelian'], 60000.0));
check('2026-02-10 shows the FIFO OUT', isset($byDate['2026-02-10']) && approx($byDate['2026-02-10']['fifo_out'], 30000.0));
check('2026-02-15 shows the adjustment (negative)', isset($byDate['2026-02-15']) && approx($byDate['2026-02-15']['adjustment'], -5000.0));
check('2026-02-28 (last day) running stok_akhir equals period ending_value', approx(end($daily['rows'])['stok_akhir'] ?? 0, $summary['ending_value']));
check('2026-02-01 (first day) stok_awal equals period opening_value', $byDate['2026-02-01']['stok_awal'] === $daily['rows'][0]['stok_awal'] && approx($daily['rows'][0]['stok_awal'], 100000.0));
check('a zero-movement day (2026-02-02) is still present with 0 pembelian/fifo_out', isset($byDate['2026-02-02']) && approx($byDate['2026-02-02']['pembelian'], 0.0) && approx($byDate['2026-02-02']['fifo_out'], 0.0));

// pagination
$page1 = InventoryHppReportService::dailyRecap($pdo, $startDate, $endDate, $whAId, 1, 10);
$page2 = InventoryHppReportService::dailyRecap($pdo, $startDate, $endDate, $whAId, 2, 10);
check('page 1 has 10 rows, page 2 has 10 rows, no overlap', count($page1['rows']) === 10 && count($page2['rows']) === 10 && $page1['rows'][0]['date'] !== $page2['rows'][0]['date']);
check('pagination total_pages = 3 for 28 days at 10/page', $page1['pagination']['total_pages'] === 3);

try {
    InventoryHppReportService::dailyRecap($pdo, '2020-01-01', '2026-12-31', null, 1, 10);
    check('an absurdly large date range is rejected', false);
} catch (\App\Services\ValidationException $e) {
    check('an absurdly large date range is rejected', true);
}

// ============================================================
// E: dayDetail() — trace-linkage proof (real ids resolvable by TraceService)
// ============================================================
echo "\n== E: day detail + trace linkage ==\n";

$detail = InventoryHppReportService::dayDetail($pdo, '2026-02-10', $whAId);
check('day detail for 2026-02-10 finds the OUT allocation', count($detail['lines']) === 1);
check('day detail total_fifo_hpp = 30,000', approx($detail['total_fifo_hpp'], 30000.0));
check('day detail line carries the real transaction_id', (int) $detail['lines'][0]['transaction_id'] === $outTxId);

// Prove the linkage is REAL: TraceService (existing, not reimplemented)
// must be able to open this exact transaction_id and see the same item/qty.
$traceResult = TraceService::transactionTrace($pdo, (int) $detail['lines'][0]['transaction_id']);
check('TraceService.transactionTrace() resolves the transaction_id the HPP report returned', $traceResult['transaction']['id'] == $outTxId);
check('the trace shows the same FIFO allocation the HPP day-detail counted', count($traceResult['fifo_allocations']) === 1 && (float) $traceResult['fifo_allocations'][0]['subtotal'] === 30000.0);

$emptyDetail = InventoryHppReportService::dayDetail($pdo, '2026-02-02', $whAId);
check('day detail for a day with no OUT activity returns an empty (not fabricated) list', $emptyDetail['lines'] === [] && $emptyDetail['total_fifo_hpp'] === 0.0);

// ============================================================
// F: category/SKU filter narrows results correctly
// ============================================================
echo "\n== F: category / SKU filter ==\n";

[$otherItemId, $otherSku] = makeItem($pdo, $kgUnitId);
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('hpp-other-in'), 'item_id' => $otherItemId, 'warehouse_id' => $whAId,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 500,
    'transaction_date' => '2026-02-06 08:00:00', 'created_by' => $adminUserId, 'username' => 'hpptest', 'supplier_id' => $supplierId,
]));

$unfiltered = InventoryHppReportService::summary($pdo, $startDate, $endDate, $whAId);
check('unfiltered purchase now includes both items (60,000 + 5,000)', approx($unfiltered['external_purchase'], 65000.0));

$filteredBySku = InventoryHppReportService::summary($pdo, $startDate, $endDate, $whAId, null, $sku);
check('filtering by the original SKU excludes the other item\'s purchase', approx($filteredBySku['external_purchase'], 60000.0));

// ============================================================
// G: read-only guarantee
// ============================================================
echo "\n== G: read-only guarantee ==\n";

function hppStateSnapshot(PDO $pdo): array
{
    return [
        'batches' => $pdo->query('SELECT id, qty_base FROM inventory_batches ORDER BY id')->fetchAll(),
        'allocations' => $pdo->query('SELECT id, qty_allocated FROM fifo_allocations ORDER BY id')->fetchAll(),
        'tx_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
        'lines_count' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transaction_lines')->fetchColumn(),
    ];
}
$before = hppStateSnapshot($pdo);
InventoryHppReportService::summary($pdo, $startDate, $endDate, null);
InventoryHppReportService::warehouseBreakdown($pdo, $startDate, $endDate, null);
InventoryHppReportService::dailyRecap($pdo, $startDate, $endDate, $whAId, 1, 10);
InventoryHppReportService::dayDetail($pdo, '2026-02-10', $whAId);
InventoryHppReportService::buildExportSheets($pdo, $startDate, $endDate, $whAId, null, null);
$after = hppStateSnapshot($pdo);
check('every HPP report call is read-only (byte-identical state before/after)', $before === $after);

// ============================================================
// H: Excel export — real, valid, openable XLSX with correct sheet names/data
// ============================================================
echo "\n== H: Excel export ==\n";

$sheets = InventoryHppReportService::buildExportSheets($pdo, $startDate, $endDate, $whAId, null, null);
check('export has exactly the 5 expected sheets', array_keys($sheets) === ['Ringkasan', 'Breakdown Gudang', 'Rekap Harian', 'Detail Transaksi FIFO', 'Pergerakan Non-HPP']);
check('Rekap Harian sheet has 28 data rows', count($sheets['Rekap Harian']['rows']) === 28);
check('Detail Transaksi FIFO sheet includes the OUT allocation row', count($sheets['Detail Transaksi FIFO']['rows']) === 1);
check('Pergerakan Non-HPP sheet includes the DAMAGE adjustment row', count($sheets['Pergerakan Non-HPP']['rows']) === 1 && $sheets['Pergerakan Non-HPP']['rows'][0][1] === 'ADJUSTMENT');

$exportPath = sys_get_temp_dir() . '/hpp_export_test_' . uniqid() . '.xlsx';
ExcelWriterService::write($exportPath, $sheets);
check('exported file exists and is non-trivially sized', file_exists($exportPath) && filesize($exportPath) > 1000);
$zip = new ZipArchive();
$opened = $zip->open($exportPath);
check('exported file is a valid, openable ZIP/XLSX container', $opened === true);
if ($opened === true) {
    check('exported XLSX contains all 5 worksheet parts', $zip->locateName('xl/worksheets/sheet5.xml') !== false);
    $zip->close();
}
@unlink($exportPath);

// ============================================================
// I: HTTP-level warehouse scoping (STOCK forced to own warehouse, never
// consolidated) + permission gate
// ============================================================
echo "\n== I: HTTP warehouse scoping ==\n";

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

function hppHttpCall(string $method, string $url, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}
function hppLoginAs(PDO $pdo, string $base, string $roleCode, ?int $warehouseId = null): array
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $username = uid('httphpp-' . strtolower($roleCode));
    $pass = 'HttpHppPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId, 'w' => $warehouseId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $ch = curl_init("{$base}/auth/login");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $pass])]);
    curl_exec($ch);
    curl_close($ch);
    return ['jar' => $jar];
}

try {
    $superadmin = hppLoginAs($pdo, $base, 'SUPERADMIN');
    $stockA = hppLoginAs($pdo, $base, 'STOCK', $whAId);
    $stockB = hppLoginAs($pdo, $base, 'STOCK', $whBId);
    $viewer = hppLoginAs($pdo, $base, 'VIEWER');

    $qs = "start_date={$startDate}&end_date={$endDate}";

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}", $superadmin['jar']);
    check('SUPERADMIN CAN GET company-wide HPP summary (no warehouse_id)', $r['status'] === 200 && $r['body']['success'] === true);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}", $viewer['jar']);
    check('VIEWER (has INVENTORY_VIEW) CAN GET HPP summary', $r['status'] === 200);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}", $stockA['jar']);
    check('STOCK at warehouse A is silently forced to their own warehouse (never company-wide)', $r['status'] === 200 && (int) $r['body']['data']['period']['warehouse_id'] === $whAId);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}&warehouse_id={$whBId}", $stockA['jar']);
    check('STOCK at warehouse A requesting warehouse B is forced back to warehouse A, not rejected nor honored as B', $r['status'] === 200 && (int) $r['body']['data']['period']['warehouse_id'] === $whAId);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}", $stockB['jar']);
    check('STOCK at warehouse B (no activity) sees zero, not warehouse A\'s numbers', $r['status'] === 200 && (float) $r['body']['data']['fifo_hpp'] === 0.0);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/daily?{$qs}&warehouse_id={$whAId}&per_page=10", $superadmin['jar']);
    check('SUPERADMIN CAN GET the paginated daily recap for warehouse A', $r['status'] === 200 && $r['body']['data']['pagination']['total'] === 28);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/day-detail?date=2026-02-10&warehouse_id={$whAId}", $superadmin['jar']);
    check('SUPERADMIN CAN GET day-detail and it carries a real transaction_id', $r['status'] === 200 && (int) $r['body']['data']['lines'][0]['transaction_id'] === $outTxId);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/warehouses?{$qs}", $superadmin['jar']);
    check('SUPERADMIN CAN GET the warehouse breakdown panels', $r['status'] === 200 && count($r['body']['data']['panels']) >= 2);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?start_date=&end_date=", $superadmin['jar']);
    check('missing start_date/end_date is rejected with VALIDATION_ERROR', $r['status'] === 422);

    $r = hppHttpCall('GET', "{$base}/reports/inventory-hpp/summary?{$qs}", tempnam(sys_get_temp_dir(), 'nocookie_'));
    check('unauthenticated request CANNOT GET the HPP report', $r['status'] === 401);
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
