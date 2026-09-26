<?php
declare(strict_types=1);

/**
 * PHASE V2.14.1 — final hardening proof that a controlled cutover's
 * opening load never contaminates ordinary Daily IN/OUT reporting, HPP,
 * or Transaction History — and that the decision-import bulk validation
 * (Section F) behaves exactly as specified. DEVELOPMENT/TEST DB ONLY.
 *
 * Scenario (per the task spec):
 *   1. KARANG_TENGAH inactive + activation_locked=1
 *   2. approved cutover with several opening SKUs
 *   3. load opening
 *   4. still inactive+locked after load
 *   5. temporarily activate a TEST FIXTURE ONLY (never production)
 *   6. real operational Stock IN/OUT on two distinct days
 *   7. query the SAME Daily Movement API (GET /reports/movement/daily)
 *      the frontend uses
 *
 * Usage: php tests/inventory_v2_14_1_opening_report_hardening_test.php
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
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';
require_once __DIR__ . '/../services/InventorySummaryReportService.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/WarehouseCutoverService.php';
require_once __DIR__ . '/../services/WarehouseCutoverImportService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\WarehouseCutoverService;
use App\Services\InventoryMovementReportService;
use App\Services\InventorySummaryReportService;
use App\Services\TransactionHistoryService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('GUDANG_BESAR', 'Gudang Besar', 'MAIN', 1, 0)")->execute();
$gudangBesarId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 0, 1)")->execute();
$karangTengahId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): int
{
    $u = uid($tag);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $unitId, string $sku, string $name): int
{
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => $name, 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
// Ordinary operational Stock IN (baseline SCM activity, seed stock for
// Day1/Day2 items) — NEVER 'OPENING'. Only WarehouseCutoverService::loadOpening()
// posts real OPENING transactions in this test.
function postIn(PDO $pdo, int $itemId, int $whId, int $unitId, float $qty, float $price, int $by, string $date): array
{
    return Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v2141-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $by, 'username' => 'v2141', 'transaction_type' => 'IN',
    ]));
}

$adminUserId = makeUser($pdo, 'v2141admin', $superRoleId);
// Baseline SCM activity, so global go-live is well before Sept.
postIn($pdo, makeItem($pdo, $kgUnitId, uid('V2141-BASE'), 'Baseline Item'), $gudangBesarId, $kgUnitId, 100, 5000, $adminUserId, '2026-07-01 08:00:00');

// ============================================================
// 1. KARANG_TENGAH inactive + activation_locked=1
// ============================================================
echo "== 1. Karang Tengah starting state ==\n";
$ktRow = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('1. Karang Tengah is_active=0', (int) $ktRow['is_active'] === 0);
check('1. Karang Tengah activation_locked=1', (int) $ktRow['activation_locked'] === 1);

// ============================================================
// 2/3. Approved cutover with several opening SKUs, then load
// ============================================================
echo "\n== 2/3. Approved cutover + load ==\n";
$cutoverId = WarehouseCutoverService::create($pdo, [
    'warehouse_id' => $karangTengahId, 'source_name' => 'v2141-hardening', 'opening_as_of' => '2026-09-18', 'created_by' => $adminUserId,
]);
$openingSkus = [];
foreach (['A', 'B', 'C'] as $tag) {
    $sku = uid("V2141-OPEN-{$tag}");
    $itemId = makeItem($pdo, $kgUnitId, $sku, "Opening Item {$tag}");
    $openingSkus[] = ['sku' => $sku, 'item_id' => $itemId, 'qty' => 100.0, 'cost' => 2000.0];
    $pdo->prepare(
        'INSERT INTO warehouse_cutover_lines (cutover_id, item_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, approved_qty, approved_unit_cost, approved_by, approved_at, source_row_reference)
         VALUES (:cid, :item_id, :sku, :name, :unit, :qty, :cost, :status, :mapping, :decision, :qty2, :cost2, :by, NOW(), :rownum)'
    )->execute([
        'cid' => $cutoverId, 'item_id' => $itemId, 'sku' => $sku, 'name' => "Opening Item {$tag}", 'unit' => 'KG',
        'qty' => 100.0, 'cost' => 2000.0, 'qty2' => 100.0, 'cost2' => 2000.0, 'status' => 'PASS', 'mapping' => 'MATCHED', 'decision' => 'ACCEPT_SOURCE',
        'by' => $adminUserId, 'rownum' => 2,
    ]);
}
$pdo->prepare("UPDATE warehouse_cutovers SET status='APPROVED', approved_by=:by, approved_at=NOW(), total_rows=3, pass_rows=3 WHERE id=:id")->execute(['by' => $adminUserId, 'id' => $cutoverId]);

$companyBeforeLoad = $pdo->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetch(PDO::FETCH_NUM);
$loadResult = WarehouseCutoverService::loadOpening($pdo, $cutoverId, $adminUserId, 'v2141');
check('3. load posts exactly 3 lines', $loadResult['line_count'] === 3, (string) $loadResult['line_count']);
check('3. load total_value = 3 x 100 x 2000 = 600,000', abs($loadResult['total_value'] - 600000.0) < 0.01, (string) $loadResult['total_value']);

// ============================================================
// 4. Still inactive + locked after load
// ============================================================
echo "\n== 4. Still inactive+locked after load ==\n";
$ktAfterLoad = $pdo->query("SELECT is_active, activation_locked FROM warehouses WHERE id={$karangTengahId}")->fetch(PDO::FETCH_ASSOC);
check('4. still is_active=0 after load', (int) $ktAfterLoad['is_active'] === 0);
check('4. still activation_locked=1 after load', (int) $ktAfterLoad['activation_locked'] === 1);

// ============================================================
// 5. Temporarily activate a TEST FIXTURE ONLY (never production; direct
// SQL, exactly the same convention tests/inventory_v2_13_karang_tengah_test.php
// already uses for this exact purpose).
// ============================================================
echo "\n== 5. Activate TEST FIXTURE ONLY ==\n";
$pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = :id')->execute(['id' => $karangTengahId]);
check('5. fixture flipped active for this test only', (int) $pdo->query("SELECT is_active FROM warehouses WHERE id={$karangTengahId}")->fetchColumn() === 1);

// ============================================================
// 6/7. Real operational transactions on two days, via the SAME HTTP API
// path the frontend uses, then query the SAME Daily Movement API.
// ============================================================
echo "\n== 6/7. Real operational transactions + Daily Movement API (HTTP) ==\n";
$port = 8900 + random_int(1600, 1999);
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
        CURLOPT_HEADER => true,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

try {
    $httpUser = uid('v2141http'); $httpPass = 'V2141HttpPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $httpUser, 'h' => password_hash($httpPass, PASSWORD_BCRYPT), 'n' => $httpUser, 'r' => $superRoleId]);
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => $httpUser, 'password' => $httpPass], $jar);
    $csrf = $login['body']['data']['csrf_token'] ?? '';
    check('HTTP login succeeds', $login['status'] === 200, json_encode($login['body']));

    $opDay1Item = makeItem($pdo, $kgUnitId, uid('V2141-OP1'), 'Operational Item Day1');
    $opDay2Item = makeItem($pdo, $kgUnitId, uid('V2141-OP2'), 'Operational Item Day2');
    // Seed enough stock to allow the real OUTs below. Dated BEFORE the
    // report window's Day1/Day2 (2026-09-19/20) so this seed IN is not
    // itself counted inside either day's measured barang_masuk.
    postIn($pdo, $opDay1Item, $karangTengahId, $kgUnitId, 200, 1000, $adminUserId, '2026-09-17 06:00:00');
    postIn($pdo, $opDay2Item, $karangTengahId, $kgUnitId, 200, 1000, $adminUserId, '2026-09-17 06:00:00');

    // Day 1 (2026-09-19): real Stock IN + real Stock OUT
    $day1In = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('v2141-d1-in'), 'item_id' => $opDay1Item, 'warehouse_id' => $karangTengahId,
        'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1200, 'transaction_date' => '2026-09-19 09:00:00',
    ], $jar, $csrf);
    check('6. Day1 real Stock IN succeeds via HTTP', $day1In['status'] === 200, json_encode($day1In['body']));
    $day1Out = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => uid('v2141-d1-out'), 'item_id' => $opDay1Item, 'warehouse_id' => $karangTengahId,
        'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-19 14:00:00',
    ], $jar, $csrf);
    check('6. Day1 real Stock OUT succeeds via HTTP', $day1Out['status'] === 200, json_encode($day1Out['body']));

    // Day 2 (2026-09-20): real Stock IN + real Stock OUT
    $day2In = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('v2141-d2-in'), 'item_id' => $opDay2Item, 'warehouse_id' => $karangTengahId,
        'input_qty' => 80, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1500, 'transaction_date' => '2026-09-20 09:00:00',
    ], $jar, $csrf);
    check('6. Day2 real Stock IN succeeds via HTTP', $day2In['status'] === 200, json_encode($day2In['body']));
    $day2Out = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => uid('v2141-d2-out'), 'item_id' => $opDay2Item, 'warehouse_id' => $karangTengahId,
        'input_qty' => 30, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-20 15:00:00',
    ], $jar, $csrf);
    check('6. Day2 real Stock OUT succeeds via HTTP', $day2Out['status'] === 200, json_encode($day2Out['body']));

    // 7. Query the SAME Daily Movement API the frontend uses.
    $dailyReport = httpCall('GET', "{$base}/reports/movement/daily?" . http_build_query(['start_date' => '2026-09-17', 'end_date' => '2026-09-20', 'warehouse_id' => $karangTengahId]), null, $jar, $csrf);
    check('7. Daily Movement API call succeeds', $dailyReport['status'] === 200, json_encode($dailyReport['body']['error'] ?? null));
    $rowsByDate = [];
    foreach ($dailyReport['body']['data']['rows'] as $r) { $rowsByDate[$r['date']] = $r; }

    // EXPECTED: opening date (09-18) shows ZERO Barang Masuk/Keluar.
    check('EXPECTED: 2026-09-18 (opening load date) shows barang_masuk=0 via the real HTTP Daily Movement API', abs(($rowsByDate['2026-09-18']['barang_masuk'] ?? -1) - 0.0) < 0.01, json_encode($rowsByDate['2026-09-18'] ?? null));
    check('EXPECTED: 2026-09-18 barang_keluar=0 too', abs(($rowsByDate['2026-09-18']['barang_keluar'] ?? -1) - 0.0) < 0.01, json_encode($rowsByDate['2026-09-18'] ?? null));

    // EXPECTED: Day 1 shows ONLY the real operational IN (50*1200=60,000)
    // and OUT (20 units, FIFO-costed from the oldest batch — the 200-unit
    // seed posted at cost 1000 — = 20,000) — nothing from the opening load
    // bleeding in.
    check('EXPECTED: Day1 (2026-09-19) barang_masuk = 60,000 (exactly the real Stock IN, nothing else)', abs(($rowsByDate['2026-09-19']['barang_masuk'] ?? 0) - 60000.0) < 0.01, json_encode($rowsByDate['2026-09-19'] ?? null));
    check('EXPECTED: Day1 barang_keluar = 20,000 (exactly the real Stock OUT, FIFO-costed from the seed batch)', abs(($rowsByDate['2026-09-19']['barang_keluar'] ?? 0) - 20000.0) < 0.01, json_encode($rowsByDate['2026-09-19'] ?? null));

    // EXPECTED: Day 2 shows ONLY the real operational IN (80*1500=120,000)
    // and OUT (30 units FIFO-costed from its own 200-unit seed batch @1000 = 30,000).
    check('EXPECTED: Day2 (2026-09-20) barang_masuk = 120,000', abs(($rowsByDate['2026-09-20']['barang_masuk'] ?? 0) - 120000.0) < 0.01, json_encode($rowsByDate['2026-09-20'] ?? null));
    check('EXPECTED: Day2 barang_keluar = 30,000 (exactly the real Stock OUT, FIFO-costed from the seed batch)', abs(($rowsByDate['2026-09-20']['barang_keluar'] ?? 0) - 30000.0) < 0.01, json_encode($rowsByDate['2026-09-20'] ?? null));

    // Day-breakdown drill-down: opening still visible, in its own category.
    $breakdown = httpCall('GET', "{$base}/reports/movement/day-breakdown?" . http_build_query(['date' => '2026-09-18', 'warehouse_id' => $karangTengahId]), null, $jar, $csrf);
    $openingCat = null;
    foreach ($breakdown['body']['data']['categories'] as $c) { if ($c['key'] === 'opening_in') { $openingCat = $c; } }
    check('The opening load is still fully disclosed via day-breakdown\'s distinct "opening_in" category (never hidden, only excluded from Barang Masuk)', $openingCat !== null && abs($openingCat['value'] - 600000.0) < 0.01, json_encode($openingCat));

    // Transaction History identifies the opening load distinctly.
    $historyOpening = TransactionHistoryService::list($pdo, ['warehouse_id' => $karangTengahId, 'transaction_type' => 'OPENING'], 1, 50);
    check('Transaction History can identify the opening-load transactions (transaction_type=OPENING filter)', count($historyOpening['rows']) === 3, (string) count($historyOpening['rows']));

    // Audit trail identifies CUTOVER_LOAD.
    $auditRow = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='warehouse_cutovers' AND entity_id={$cutoverId} AND action_code='CUTOVER_LOAD'")->fetch(PDO::FETCH_ASSOC);
    check('Audit trail identifies CUTOVER_LOAD with actor/timestamp', $auditRow !== false && $auditRow['user_id'] == $adminUserId, json_encode($auditRow));

    // HPP: opening value is available as opening inventory, never counted
    // as normal purchase/operational IN.
    $hppSummary = InventorySummaryReportService::summary($pdo, '2026-09-01', '2026-09-20', $karangTengahId);
    check('HPP/summary: opening inventory value is captured (beginning/ending reflect the loaded opening, not zero)', $hppSummary['ending_inventory_value'] > 0, json_encode($hppSummary['ending_inventory_value'] ?? null));
    check('HPP/summary: external_purchase (Pembelian) is ONLY the real Day1+Day2 IN (60,000+120,000=180,000), never includes the 600,000 opening', abs(($hppSummary['external_purchase'] ?? -1) - 180000.0) < 0.01, json_encode($hppSummary['external_purchase'] ?? null));

    // FIFO layers correct: 3 opening batches at exactly qty=100/cost=2000 each.
    $batches = $pdo->query("SELECT qty_base, unit_cost_base FROM inventory_batches WHERE warehouse_id={$karangTengahId} AND item_id IN (" . implode(',', array_column($openingSkus, 'item_id')) . ") ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('FIFO: exactly 3 opening batches created, each qty=100 cost=2000', count($batches) === 3 && array_reduce($batches, fn ($c, $b) => $c && abs((float) $b['qty_base'] - 100.0) < 0.0001 && abs((float) $b['unit_cost_base'] - 2000.0) < 0.0001, true), json_encode($batches));

    // No fake dates: every OPENING transaction for this cutover is dated
    // exactly opening_as_of (2026-09-18), never a fabricated 01-17 Sep date.
    $openingDates = $pdo->query("SELECT DISTINCT DATE(transaction_date) FROM inventory_transactions WHERE transaction_type='OPENING' AND warehouse_id={$karangTengahId}")->fetchAll(PDO::FETCH_COLUMN);
    check('No fake dates: every OPENING transaction for Karang Tengah is dated exactly 2026-09-18', $openingDates === ['2026-09-18'], json_encode($openingDates));

    // ============================================================
    // Section F — decision import bulk validation, on a SECOND, separate
    // cutover (never touches the already-loaded one above).
    // ============================================================
    echo "\n== F. Decision import validation ==\n";
    $cutover2Id = WarehouseCutoverService::create($pdo, ['warehouse_id' => $karangTengahId, 'source_name' => 'decision-import-test', 'opening_as_of' => '2026-09-21', 'created_by' => $adminUserId]);
    $skuGood = uid('V2141-DEC-GOOD');
    $itemGood = makeItem($pdo, $kgUnitId, $skuGood, 'Decision Good Item');
    $skuNoReason = uid('V2141-DEC-NOREASON');
    $itemNoReason = makeItem($pdo, $kgUnitId, $skuNoReason, 'Decision No Reason Item');
    $skuBadQty = uid('V2141-DEC-BADQTY');
    $itemBadQty = makeItem($pdo, $kgUnitId, $skuBadQty, 'Decision Bad Qty Item');
    $skuNoCost = uid('V2141-DEC-NOCOST');
    $itemNoCost = makeItem($pdo, $kgUnitId, $skuNoCost, 'Decision No Cost Item');
    foreach ([[$skuGood, $itemGood], [$skuNoReason, $itemNoReason], [$skuBadQty, $itemBadQty], [$skuNoCost, $itemNoCost]] as $i => [$sku, $itemId]) {
        $pdo->prepare(
            'INSERT INTO warehouse_cutover_lines (cutover_id, item_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, source_row_reference)
             VALUES (:cid, :item_id, :sku, :name, :unit, :qty, NULL, :status, :mapping, :decision, :rownum)'
        )->execute(['cid' => $cutover2Id, 'item_id' => $itemId, 'sku' => $sku, 'name' => 'x', 'unit' => 'KG', 'qty' => 10.0, 'status' => 'CRITICAL', 'mapping' => 'MATCHED', 'decision' => 'PENDING', 'rownum' => $i + 2]);
    }
    $skuNotFound = 'SKU-DOES-NOT-EXIST-ON-CUTOVER';
    $skuBadItem = uid('V2141-DEC-BADITEM');
    $itemForBadItem = makeItem($pdo, $kgUnitId, $skuBadItem, 'Decision Bad Item Row');
    $pdo->prepare(
        'INSERT INTO warehouse_cutover_lines (cutover_id, item_id, source_sku, source_name, source_unit, theoretical_closing_qty, source_price, reconciliation_status, mapping_status, decision, source_row_reference)
         VALUES (:cid, :item_id, :sku, :name, :unit, :qty, NULL, :status, :mapping, :decision, :rownum)'
    )->execute(['cid' => $cutover2Id, 'item_id' => $itemForBadItem, 'sku' => $skuBadItem, 'name' => 'x', 'unit' => 'KG', 'qty' => 10.0, 'status' => 'CRITICAL', 'mapping' => 'MATCHED', 'decision' => 'PENDING', 'rownum' => 6]);

    $decisionsCall = httpCall('POST', "{$base}/warehouse-cutovers/{$cutover2Id}/import-decisions", ['decisions' => [
        ['source_sku' => $skuGood, 'decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 10, 'approved_unit_cost' => 500, 'reason' => 'Physical count confirmed 10 units'],
        ['source_sku' => $skuNoReason, 'decision' => 'EXCLUDE'], // missing reason -> rejected
        ['source_sku' => $skuBadQty, 'decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => -5, 'approved_unit_cost' => 500, 'reason' => 'test'], // qty<=0 -> rejected
        ['source_sku' => $skuNoCost, 'decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 10, 'reason' => 'test'], // no cost, no source_price -> rejected
        ['source_sku' => $skuNotFound, 'decision' => 'EXCLUDE', 'reason' => 'test'], // SKU not on this cutover -> rejected
        ['source_sku' => $skuBadItem, 'decision' => 'BUSINESS_OVERRIDE', 'item_id' => 999999999, 'approved_qty' => 10, 'approved_unit_cost' => 500, 'reason' => 'test'], // invalid item_id -> rejected
    ]], $jar, $csrf);
    check('F. decision import HTTP call succeeds (per-row results, not an all-or-nothing failure)', $decisionsCall['status'] === 200, json_encode($decisionsCall['body']));
    $decResults = $decisionsCall['body']['data']['results'] ?? [];
    check('F. valid BUSINESS_OVERRIDE row applied', ($decResults[$skuGood]['status'] ?? null) === 'applied', json_encode($decResults[$skuGood] ?? null));
    check('F. EXCLUDE without reason rejected', ($decResults[$skuNoReason]['status'] ?? null) === 'rejected', json_encode($decResults[$skuNoReason] ?? null));
    check('F. approved_qty <= 0 rejected for an included row', ($decResults[$skuBadQty]['status'] ?? null) === 'rejected', json_encode($decResults[$skuBadQty] ?? null));
    check('F. missing cost rejected when cost required', ($decResults[$skuNoCost]['status'] ?? null) === 'rejected', json_encode($decResults[$skuNoCost] ?? null));
    check('F. SKU not matching a line on this cutover rejected', ($decResults[$skuNotFound]['status'] ?? null) === 'rejected', json_encode($decResults[$skuNotFound] ?? null));
    check('F. invalid (non-existent) item_id rejected', ($decResults[$skuBadItem]['status'] ?? null) === 'rejected', json_encode($decResults[$skuBadItem] ?? null));

    // Original source values were never overwritten by the import.
    $goodLineAfter = $pdo->query("SELECT * FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$skuGood}'")->fetch(PDO::FETCH_ASSOC);
    check('F. original source_name/source_unit/theoretical_closing_qty untouched by decision import', $goodLineAfter['source_name'] === 'x' && $goodLineAfter['source_unit'] === 'KG' && abs((float) $goodLineAfter['theoretical_closing_qty'] - 10.0) < 0.0001);
    check('F. approved_qty/approved_unit_cost correctly applied for the good row', abs((float) $goodLineAfter['approved_qty'] - 10.0) < 0.0001 && abs((float) $goodLineAfter['approved_unit_cost'] - 500.0) < 0.0001);

    // Rejected rows left completely untouched (still PENDING).
    $noReasonLine = $pdo->query("SELECT decision FROM warehouse_cutover_lines WHERE cutover_id={$cutover2Id} AND source_sku='{$skuNoReason}'")->fetchColumn();
    check('F. rejected row (missing reason) left at PENDING, not partially applied', $noReasonLine === 'PENDING', (string) $noReasonLine);

    // Re-import is idempotent/deterministic: re-applying the SAME good
    // decision again reports "unchanged", not a duplicate audit action.
    $auditCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='warehouse_cutover_lines' AND entity_id={$goodLineAfter['id']}")->fetchColumn();
    $reimport = httpCall('POST', "{$base}/warehouse-cutovers/{$cutover2Id}/import-decisions", ['decisions' => [
        ['source_sku' => $skuGood, 'decision' => 'BUSINESS_OVERRIDE', 'approved_qty' => 10, 'approved_unit_cost' => 500, 'reason' => 'Physical count confirmed 10 units'],
    ]], $jar, $csrf);
    $reimportResults = $reimport['body']['data']['results'] ?? [];
    check('F. re-importing the identical decision reports "unchanged" (deterministic/idempotent)', ($reimportResults[$skuGood]['status'] ?? null) === 'unchanged', json_encode($reimportResults[$skuGood] ?? null));
    $auditCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='warehouse_cutover_lines' AND entity_id={$goodLineAfter['id']}")->fetchColumn();
    check('F. re-importing the identical decision adds NO new audit row (no redundant write)', $auditCountAfter === $auditCountBefore, "{$auditCountBefore} -> {$auditCountAfter}");
} finally {
    proc_terminate($process);
    usleep(200_000);
    proc_close($process);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
