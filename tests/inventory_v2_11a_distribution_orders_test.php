<?php
declare(strict_types=1);

/**
 * PHASE V2.11A — SCM -> Bakery Distribution Order lifecycle, against real
 * MySQL/MariaDB.
 *
 * Business rule this phase enforces (audited first, Section 3): a
 * distribution/sale to a Bakery may only originate from the SCM warehouse
 * — resolved by warehouse CODE ('SCM'), never a hard-coded numeric id, and
 * enforced at the SERVICE layer (DistributionOrderService::create()) so an
 * API caller cannot bypass it merely by posting a different
 * from_warehouse_id. This is a genuinely different concept from
 * warehouse_transfers (unmodified, still reserved for SCM -> CIBADAK).
 *
 * Real stock only leaves inventory at dispatch() — DRAFT/APPROVED/PICKING
 * never call FifoService. dispatch() calls the EXISTING, unmodified
 * FifoService::postOut() once per line — never a second inventory engine.
 *
 * Usage: php tests/inventory_v2_11a_distribution_orders_test.php
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
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/DistributionOrderService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\NumberingService;
use App\Services\DistributionOrderService;
use App\Services\ValidationException;
use App\Services\NotFoundException;

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

$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v210asetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V2.11A Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

// SCM by CODE — the exact resolution DistributionOrderService uses.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('SCM', 'SCM / Gudang Besar', 1)")->execute();
$scmId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('CIBADAK', 'Cibadak', 1)")->execute();
$cibadakId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO bakery_destinations (code, name, address, is_active) VALUES (:c,:n,:a,1)')
    ->execute(['c' => uid('BAKERY'), 'n' => 'Bakery Test', 'a' => 'Jl. Test No. 1']);
$bakeryId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}
function postOpeningIn(PDO $pdo, int $itemId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v210a-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $GLOBALS['kgUnitId'], 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v210a', 'transaction_type' => 'OPENING',
    ]));
}

// ============================================================
// 1/2 — SCM-only enforcement
// ============================================================
echo "== 1/2: DO creation is SCM-only, resolved by warehouse CODE ==\n";
[$item1, $sku1] = makeItem($pdo, $kgUnitId, 'V210A-1');
postOpeningIn($pdo, $item1, $scmId, 100, 5000, $adminUserId);

$createFromScm = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $item1, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]);
check('1. create DO from SCM succeeds', $createFromScm['success'] === true, json_encode($createFromScm));
check('DO number format DO-YYYYMMDD-####', preg_match('/^DO-20260923-\d{4}$/', $createFromScm['do_number']) === 1, $createFromScm['do_number']);

$rejectedFromCibadak = false;
try {
    DistributionOrderService::create($pdo, [
        'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $cibadakId,
        'created_by' => $adminUserId, 'lines' => [['item_id' => $item1, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
    ]);
} catch (ValidationException $e) {
    $rejectedFromCibadak = true;
}
check('2. DO creation from CIBADAK is rejected (service-level, never frontend-only)', $rejectedFromCibadak);

// ============================================================
// 3 — multi-item DO
// ============================================================
echo "\n== 3: multi-item DO ==\n";
[$item2, $sku2] = makeItem($pdo, $kgUnitId, 'V210A-3B');
postOpeningIn($pdo, $item2, $scmId, 50, 3000, $adminUserId);
$multiDo = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId,
    'lines' => [
        ['item_id' => $item1, 'input_qty' => 20, 'input_unit_id' => $kgUnitId],
        ['item_id' => $item2, 'input_qty' => 15, 'input_unit_id' => $kgUnitId],
    ],
]);
$multiDoDetail = DistributionOrderService::get($pdo, $multiDo['do_id']);
check('3. multi-item DO stores 2 lines', count($multiDoDetail['lines']) === 2, json_encode(array_column($multiDoDetail['lines'], 'item_id')));
check('DO status starts DRAFT', $multiDoDetail['status'] === 'DRAFT');

// ============================================================
// 4/5/6/7 — approve -> picking -> dispatch -> real FIFO OUT
// ============================================================
echo "\n== 4-7: approve -> picking -> dispatch (real FIFO Stock OUT occurs once) ==\n";
$stockBeforeDispatch1 = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item1} AND warehouse_id={$scmId}")->fetchColumn();

$approveResult = DistributionOrderService::approve($pdo, $multiDo['do_id'], ['created_by' => $adminUserId]);
check('4. approve DRAFT -> APPROVED', $approveResult['success'] === true);
check('4b. stock unchanged after approve', (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item1} AND warehouse_id={$scmId}")->fetchColumn() === $stockBeforeDispatch1);

$pickingResult = DistributionOrderService::startPicking($pdo, $multiDo['do_id'], ['created_by' => $adminUserId]);
check('5. approve -> PICKING', $pickingResult['success'] === true);
check('5b. stock still unchanged during picking', (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item1} AND warehouse_id={$scmId}")->fetchColumn() === $stockBeforeDispatch1);

$dispatchResult = DistributionOrderService::dispatch($pdo, $multiDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('req')]);
check('6. PICKING -> DISPATCHED', $dispatchResult['success'] === true && $dispatchResult['lines_dispatched'] === 2, json_encode($dispatchResult));

$stockAfterDispatch1 = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item1} AND warehouse_id={$scmId}")->fetchColumn();
check('7. real FIFO Stock OUT occurred: item1 SCM stock reduced by exactly 20', abs(($stockBeforeDispatch1 - $stockAfterDispatch1) - 20) < 0.000001, "{$stockBeforeDispatch1} -> {$stockAfterDispatch1}");

$dispatchedDo = DistributionOrderService::get($pdo, $multiDo['do_id']);
check('7b. line qty_sent_base populated from the real postOut() result', abs((float) $dispatchedDo['lines'][0]['qty_sent_base'] - 20) < 0.000001, json_encode($dispatchedDo['lines'][0]));
check('7c. out_transaction_line_id populated per line', $dispatchedDo['lines'][0]['out_transaction_line_id'] !== null);

// ============================================================
// 8 — double dispatch rejected / idempotent
// ============================================================
echo "\n== 8: double dispatch rejected / idempotent ==\n";
$sameRequestUuid = $dispatchedDo['dispatch_request_uuid'];
$idempotentReplay = DistributionOrderService::dispatch($pdo, $multiDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => $sameRequestUuid]);
check('8a. same request_uuid replay is a safe idempotent no-op', !empty($idempotentReplay['idempotent_replay']), json_encode($idempotentReplay));

$genuinelyNewAttemptRejected = false;
try {
    DistributionOrderService::dispatch($pdo, $multiDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('different')]);
} catch (ValidationException $e) {
    $genuinelyNewAttemptRejected = true;
}
check('8b. a genuinely NEW dispatch attempt (different request_uuid) on an already-DISPATCHED DO is rejected, never double-posts', $genuinelyNewAttemptRejected);

$stockAfterSecondAttempt = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item1} AND warehouse_id={$scmId}")->fetchColumn();
check('8c. stock never double-deducted', abs($stockAfterSecondAttempt - $stockAfterDispatch1) < 0.000001);

// ============================================================
// 9/10 — History / Trace see the OUT
// ============================================================
echo "\n== 9/10: History and Trace see the dispatched OUT ==\n";
$historyRow = $pdo->prepare('SELECT t.* FROM inventory_transactions t WHERE t.reference_no = :ref AND t.transaction_type = \'OUT\' LIMIT 1');
$historyRow->execute(['ref' => $dispatchedDo['do_number']]);
$historyRow = $historyRow->fetch();
check('9. History (inventory_transactions) shows a real POSTED OUT transaction referencing the DO number', $historyRow !== false && $historyRow['status'] === 'POSTED', json_encode($historyRow));

$traceLine = $pdo->prepare('SELECT * FROM inventory_transaction_lines WHERE id = :id');
$traceLine->execute(['id' => $dispatchedDo['lines'][0]['out_transaction_line_id']]);
$traceLine = $traceLine->fetch();
check('10. Trace (inventory_transaction_lines + fifo_allocations chain) resolves back to this exact line', $traceLine !== false && (int) $traceLine['item_id'] === $item1);
$allocCountStmt = $pdo->prepare('SELECT COUNT(*) FROM fifo_allocations WHERE transaction_line_id = :id');
$allocCountStmt->execute(['id' => $traceLine['id']]);
$allocCount = (int) $allocCountStmt->fetchColumn();
check('10b. FIFO allocation rows exist for the dispatched line (real consumption, not a stub)', $allocCount > 0, (string) $allocCount);

// ============================================================
// 11 — bakery destination persisted
// ============================================================
echo "\n== 11: bakery destination persisted on the posted transaction ==\n";
check('11. posted OUT transaction carries the DO\'s bakery_destination_id', (int) $historyRow['bakery_destination_id'] === $bakeryId, json_encode($historyRow));

// ============================================================
// 12/13/14 — receive exact / discrepancy / reason required
// ============================================================
echo "\n== 12-14: receiving — exact match, discrepancy, reason required ==\n";
$receiveExact = DistributionOrderService::receive($pdo, $multiDo['do_id'], [
    'created_by' => $adminUserId,
    'lines' => [
        ['do_line_id' => $dispatchedDo['lines'][0]['id'], 'qty_received' => (float) $dispatchedDo['lines'][0]['qty_sent_base']],
        ['do_line_id' => $dispatchedDo['lines'][1]['id'], 'qty_received' => (float) $dispatchedDo['lines'][1]['qty_sent_base']],
    ],
]);
check('12. receive with exact matching qty -> RECEIVED (not discrepancy)', $receiveExact['status'] === 'RECEIVED', json_encode($receiveExact));

// A second DO to test the discrepancy path independently.
[$item3, $sku3] = makeItem($pdo, $kgUnitId, 'V210A-13');
postOpeningIn($pdo, $item3, $scmId, 40, 4000, $adminUserId);
$discDo = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $item3, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]);
DistributionOrderService::approve($pdo, $discDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::startPicking($pdo, $discDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::dispatch($pdo, $discDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('req')]);
$discDoDispatched = DistributionOrderService::get($pdo, $discDo['do_id']);

$reasonRequiredRejected = false;
try {
    DistributionOrderService::receive($pdo, $discDo['do_id'], [
        'created_by' => $adminUserId,
        'lines' => [['do_line_id' => $discDoDispatched['lines'][0]['id'], 'qty_received' => 8]], // 10 sent, 8 received, no reason
    ]);
} catch (ValidationException $e) {
    $reasonRequiredRejected = true;
}
check('14. discrepancy_reason required when qty_received differs from qty_sent', $reasonRequiredRejected);

$receiveDiscrepancy = DistributionOrderService::receive($pdo, $discDo['do_id'], [
    'created_by' => $adminUserId,
    'lines' => [['do_line_id' => $discDoDispatched['lines'][0]['id'], 'qty_received' => 8, 'discrepancy_reason' => 'KURANG', 'discrepancy_notes' => 'Kurang 2kg']],
]);
check('13. receive with a difference -> RECEIVED_WITH_DISCREPANCY', $receiveDiscrepancy['status'] === 'RECEIVED_WITH_DISCREPANCY', json_encode($receiveDiscrepancy));
$discLineAfter = DistributionOrderService::get($pdo, $discDo['do_id'])['lines'][0];
check('13b. difference_qty_base computed and persisted (8 - 10 = -2)', abs((float) $discLineAfter['difference_qty_base'] - (-2)) < 0.000001, json_encode($discLineAfter));
check('13c. original dispatched qty_sent_base never silently mutated to match receiving', abs((float) $discLineAfter['qty_sent_base'] - 10) < 0.000001);

// ============================================================
// 15 — complete flow
// ============================================================
echo "\n== 15: complete flow ==\n";
$completeExact = DistributionOrderService::complete($pdo, $multiDo['do_id'], ['created_by' => $adminUserId]);
check('15a. RECEIVED -> COMPLETED', $completeExact['success'] === true);
$completeDiscrepancy = DistributionOrderService::complete($pdo, $discDo['do_id'], ['created_by' => $adminUserId]);
check('15b. RECEIVED_WITH_DISCREPANCY -> COMPLETED (discrepancy does not block completion)', $completeDiscrepancy['success'] === true);
$finalStatus = DistributionOrderService::get($pdo, $multiDo['do_id']);
check('15c. final status is COMPLETED', $finalStatus['status'] === 'COMPLETED');

// ============================================================
// EXTRA: cancel before dispatch never touches inventory; reverse after
// dispatch restores it exactly.
// ============================================================
echo "\n== EXTRA: cancel (pre-dispatch, no inventory change) ==\n";
[$item4, $sku4] = makeItem($pdo, $kgUnitId, 'V210A-CANCEL');
postOpeningIn($pdo, $item4, $scmId, 30, 2000, $adminUserId);
$cancelDo = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $item4, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]);
$stockBeforeCancel = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item4} AND warehouse_id={$scmId}")->fetchColumn();
$cancelResult = DistributionOrderService::cancel($pdo, $cancelDo['do_id'], ['created_by' => $adminUserId, 'reason' => 'Testing cancel path']);
check('EXTRA cancel succeeds pre-dispatch', $cancelResult['success'] === true);
check('EXTRA stock unchanged after pre-dispatch cancel', (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item4} AND warehouse_id={$scmId}")->fetchColumn() === $stockBeforeCancel);
$cancelAfterDispatchRejected = false;
try {
    DistributionOrderService::cancel($pdo, $discDo['do_id'], ['created_by' => $adminUserId, 'reason' => 'should be rejected']);
} catch (ValidationException $e) {
    $cancelAfterDispatchRejected = true;
}
check('EXTRA cancel() on an already-dispatched/completed DO is rejected (must use reverse())', $cancelAfterDispatchRejected);

echo "\n== EXTRA: reverse (post-dispatch, restores FIFO exactly) ==\n";
[$item5, $sku5] = makeItem($pdo, $kgUnitId, 'V210A-REVERSE');
postOpeningIn($pdo, $item5, $scmId, 60, 7000, $adminUserId);
$reverseDo = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $item5, 'input_qty' => 25, 'input_unit_id' => $kgUnitId]],
]);
DistributionOrderService::approve($pdo, $reverseDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::startPicking($pdo, $reverseDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::dispatch($pdo, $reverseDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('req')]);
$stockAfterReverseDispatch = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item5} AND warehouse_id={$scmId}")->fetchColumn();
$reverseResult = DistributionOrderService::reverse($pdo, $reverseDo['do_id'], ['created_by' => $adminUserId, 'reason' => 'Testing reverse path', 'request_uuid' => uid('rev')]);
check('EXTRA reverse succeeds post-dispatch', $reverseResult['success'] === true, json_encode($reverseResult));
$stockAfterReverse = (float) $pdo->query("SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id={$item5} AND warehouse_id={$scmId}")->fetchColumn();
check('EXTRA stock fully restored after reverse (back to pre-dispatch level)', abs(($stockAfterReverse - $stockAfterReverseDispatch) - 25) < 0.000001, "{$stockAfterReverseDispatch} -> {$stockAfterReverse}");
check('EXTRA reversed DO status = CANCELLED', DistributionOrderService::get($pdo, $reverseDo['do_id'])['status'] === 'CANCELLED');
$reverseTxRow = $pdo->prepare('SELECT status FROM inventory_transactions WHERE reference_no = :ref');
$reverseTxRow->execute(['ref' => DistributionOrderService::get($pdo, $reverseDo['do_id'])['do_number']]);
check('EXTRA underlying OUT transaction flipped to REVERSED (never deleted)', $reverseTxRow->fetchColumn() === 'REVERSED');

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Section (direct-service): {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// HTTP — permission enforcement, SCM-only enforced even via a raw
// crafted request (never frontend-only).
// ============================================================
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
    $superUser = uid('v210asuper'); $superPass = 'V210ASuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('v210astock'); $stockPass = 'V210AStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $scmId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    echo "\n== HTTP: SCM-only enforced even via a raw crafted POST (Part 3 — never frontend-only) ==\n";
    [$itemH1, $skuH1] = makeItem($pdo, $kgUnitId, 'V210A-HTTP1');
    $craftedCibadak = httpCall('POST', "{$base}/distribution-orders", [
        'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $cibadakId,
        'lines' => [['item_id' => $itemH1, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
    ], $superJar, $superCsrf);
    check('HTTP a SUPERADMIN-authenticated but CIBADAK-targeted DO create is still rejected', $craftedCibadak['status'] !== 200, json_encode($craftedCibadak['body']));

    echo "\n== HTTP: permission gates — STOCK can DISPATCH but not CREATE/APPROVE/RECEIVE ==\n";
    postOpeningIn($pdo, $itemH1, $scmId, 50, 4000, $adminUserId);
    $httpCreate = httpCall('POST', "{$base}/distribution-orders", [
        'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
        'lines' => [['item_id' => $itemH1, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
    ], $superJar, $superCsrf);
    check('HTTP SUPERADMIN can create a DO (200)', $httpCreate['status'] === 200, json_encode($httpCreate['body']));
    $httpDoId = $httpCreate['body']['data']['do_id'] ?? null;

    $stockCreateForbidden = httpCall('POST', "{$base}/distribution-orders", [
        'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
        'lines' => [['item_id' => $itemH1, 'input_qty' => 1, 'input_unit_id' => $kgUnitId]],
    ], $stockJar, $stockCsrf);
    check('HTTP STOCK cannot create a DO (403)', $stockCreateForbidden['status'] === 403, json_encode($stockCreateForbidden['body']));

    $stockApproveForbidden = httpCall('POST', "{$base}/distribution-orders/{$httpDoId}/approve", [], $stockJar, $stockCsrf);
    check('HTTP STOCK cannot approve a DO (403)', $stockApproveForbidden['status'] === 403, json_encode($stockApproveForbidden['body']));

    $superApprove = httpCall('POST', "{$base}/distribution-orders/{$httpDoId}/approve", [], $superJar, $superCsrf);
    check('HTTP SUPERADMIN can approve (200)', $superApprove['status'] === 200, json_encode($superApprove['body']));
    $superPicking = httpCall('POST', "{$base}/distribution-orders/{$httpDoId}/start-picking", [], $superJar, $superCsrf);
    check('HTTP SUPERADMIN can start picking (200)', $superPicking['status'] === 200, json_encode($superPicking['body']));

    $stockDispatch = httpCall('POST', "{$base}/distribution-orders/{$httpDoId}/dispatch", ['request_uuid' => uid('httpreq')], $stockJar, $stockCsrf);
    check('HTTP STOCK CAN dispatch (200) — the physical warehouse action, per the owner\'s operational-permissions-only instruction', $stockDispatch['status'] === 200, json_encode($stockDispatch['body']));

    $stockReceiveForbidden = httpCall('POST', "{$base}/distribution-orders/{$httpDoId}/receive", ['lines' => []], $stockJar, $stockCsrf);
    check('HTTP STOCK cannot record receiving (403)', $stockReceiveForbidden['status'] === 403, json_encode($stockReceiveForbidden['body']));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
