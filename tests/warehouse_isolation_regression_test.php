<?php
declare(strict_types=1);

/**
 * PHASE V2 3a — warehouse-isolation regression suite, mandated by the owner
 * to run BEFORE any V2 feature work, and again unchanged after the V2
 * schema migration (Phase 3b) as a regression gate. Real HTTP via curl
 * against a spawned `php -S`, same pattern as tests/mysql_security_test.php.
 *
 * Two real-shaped warehouses stand in for SCM and Cibadak (fresh per-run
 * unique codes, not the literal production codes — this suite runs against
 * a throwaway schema-only DB, never real business data).
 *
 * FIFO layer-consumption/transfer-value-preservation/cancel-restore/
 * receive-recreate-cost-layer invariants and reconciliation invariants are
 * NOT re-implemented here — they are already covered by
 * tests/mysql_integration_test.php, tests/mysql_smoke_test.php,
 * tests/opening_g_data_2_test.php and tests/migration_negative_stock_test.php,
 * which this suite's runner re-runs unchanged as the "existing invariants
 * unchanged" baseline (see docs/PHASE_V2_PHASE3_REPORT.md).
 *
 * Usage: php tests/warehouse_isolation_regression_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/UnitConversionService.php';

use App\Services\Database;
use App\Services\UnitConversionService;

$port = 8600 + random_int(0, 400);
$docRoot = __DIR__ . '/../public';

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

// ---- fixtures ----
$pdo = Database::connection();
$roles = [];
foreach (['SUPERADMIN', 'STOCK'] as $code) {
    $roles[$code] = (int) $pdo->query("SELECT id FROM roles WHERE code='{$code}'")->fetchColumn();
}
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

function makeWarehouse(PDO $pdo, string $code): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $code]);
    return (int) $pdo->lastInsertId();
}
function makeUser(PDO $pdo, string $username, string $password, int $roleId, ?int $warehouseId): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $username, 'h' => password_hash($password, PASSWORD_BCRYPT), 'n' => $username, 'r' => $roleId, 'w' => $warehouseId]);
    return (int) $pdo->lastInsertId();
}
function makeItem(PDO $pdo, int $kgUnitId): int
{
    $sku = uid('SKU-ISO');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id) VALUES (:s,:n,:u)')->execute(['s' => $sku, 'n' => $sku, 'u' => $kgUnitId]);
    $itemId = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $itemId;
}

$whScm = makeWarehouse($pdo, uid('ISO-SCM'));
$whCbd = makeWarehouse($pdo, uid('ISO-CBD'));

$scmUser = uid('scm'); $scmPass = 'ScmPass123!';
makeUser($pdo, $scmUser, $scmPass, $roles['STOCK'], $whScm);
$cbdUser = uid('cbd'); $cbdPass = 'CbdPass123!';
makeUser($pdo, $cbdUser, $cbdPass, $roles['STOCK'], $whCbd);
$adminUser = uid('admin'); $adminPass = 'AdminPass123!';
makeUser($pdo, $adminUser, $adminPass, $roles['SUPERADMIN'], null);

$itemScm = makeItem($pdo, $kgUnitId);
$itemCbd = makeItem($pdo, $kgUnitId);

// ---- spawn the server ----
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
if (!$ready) { fwrite(STDERR, "Server did not become ready on port {$port}\n"); proc_terminate($process); exit(1); }

/** @return array{status:int, body:array} */
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

function login(string $base, string $user, string $pass): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $user, 'password' => $pass], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? ''];
}

try {
    $scm = login($base, $scmUser, $scmPass);
    $cbd = login($base, $cbdUser, $cbdPass);
    $admin = login($base, $adminUser, $adminPass);

    // seed stock: post 100 KG IN at each warehouse as SUPERADMIN (unscoped) so the isolation
    // reads below have real, distinguishable, non-zero values to check.
    foreach ([[$whScm, $itemScm, 'SCM'], [$whCbd, $itemCbd, 'CBD']] as [$wh, $item, $tag]) {
        $in = httpCall('POST', "{$base}/transactions/in", [
            'transaction_uuid' => uid("iso-in-{$tag}"), 'item_id' => $item, 'warehouse_id' => $wh,
            'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 5000,
            'transaction_date' => '2026-09-01 08:00:00',
        ], $admin['jar'], $admin['csrf']);
        check("seed: IN 100kg posted to {$tag} warehouse", ($in['body']['success'] ?? false) === true, json_encode($in['body']));
    }

    // ============================================================
    // WAREHOUSE ISOLATION
    // ============================================================

    $scmReadsCbd = httpCall('GET', "{$base}/inventory/current?item_id={$itemCbd}&warehouse_id={$whCbd}", null, $scm['jar']);
    check('SCM cannot read Cibadak inventory', $scmReadsCbd['status'] === 403 && $scmReadsCbd['body']['error']['code'] === 'FORBIDDEN');

    $cbdReadsScm = httpCall('GET', "{$base}/inventory/current?item_id={$itemScm}&warehouse_id={$whScm}", null, $cbd['jar']);
    check('Cibadak cannot read SCM inventory', $cbdReadsScm['status'] === 403 && $cbdReadsScm['body']['error']['code'] === 'FORBIDDEN');

    $scmValue = httpCall('GET', "{$base}/inventory/value", null, $scm['jar']);
    check(
        'SCM inventory value response is scoped to only SCM (scope_warehouse_id matches, own warehouse present)',
        ($scmValue['body']['data']['scope_warehouse_id'] ?? null) === $whScm,
        json_encode($scmValue['body']['data'] ?? null)
    );

    $cbdValue = httpCall('GET', "{$base}/inventory/value", null, $cbd['jar']);
    check(
        'Cibadak inventory value response is scoped to only Cibadak (scope_warehouse_id matches, own warehouse present)',
        ($cbdValue['body']['data']['scope_warehouse_id'] ?? null) === $whCbd,
        json_encode($cbdValue['body']['data'] ?? null)
    );

    // A STOCK user's warehouse-scoped value summary must not leak the sibling warehouse's row.
    $scmValueRows = $scmValue['body']['data']['value_per_warehouse'] ?? [];
    check(
        'SCM value_per_warehouse never contains the Cibadak warehouse id',
        !in_array($whCbd, array_map(fn ($r) => $r['warehouse_id'], $scmValueRows), true)
    );

    // SKU lookup without warehouse_id stays scoped to the STOCK user's own warehouse.
    $skuScm = $pdo->query("SELECT sku FROM items WHERE id = {$itemScm}")->fetchColumn();
    $scmSkuLookup = httpCall('GET', "{$base}/inventory/current/{$skuScm}", null, $scm['jar']);
    check(
        'SKU lookup without warehouse_id (SCM user, own SKU) returns single-warehouse shape, not all-warehouses',
        array_key_exists('qty_base', $scmSkuLookup['body']['data'] ?? []) && !array_key_exists('by_warehouse', $scmSkuLookup['body']['data'] ?? []),
        json_encode($scmSkuLookup['body']['data'] ?? null)
    );

    $skuCbd = $pdo->query("SELECT sku FROM items WHERE id = {$itemCbd}")->fetchColumn();
    $scmSkuLookupOther = httpCall('GET', "{$base}/inventory/current/{$skuCbd}", null, $scm['jar']);
    check(
        'SKU lookup without warehouse_id (SCM user, Cibadak-only SKU) reports SCM qty (0), never the real Cibadak qty (100)',
        (float) ($scmSkuLookupOther['body']['data']['qty_base'] ?? -1) === 0.0,
        json_encode($scmSkuLookupOther['body']['data'] ?? null)
    );

    // Warehouse-scoped reports (ledger, batches) follow the same rule.
    $scmLedgerOther = httpCall('GET', "{$base}/inventory/ledger?item_id={$itemCbd}&warehouse_id={$whCbd}", null, $scm['jar']);
    check('SCM cannot read Cibadak ledger', $scmLedgerOther['status'] === 403 && $scmLedgerOther['body']['error']['code'] === 'FORBIDDEN');

    $scmBatchesOther = httpCall('GET', "{$base}/inventory/batches?item_id={$itemCbd}&warehouse_id={$whCbd}", null, $scm['jar']);
    check('SCM cannot read Cibadak batches', $scmBatchesOther['status'] === 403 && $scmBatchesOther['body']['error']['code'] === 'FORBIDDEN');

    // ============================================================
    // STOCK OPNAME
    // ============================================================

    $openScm = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whScm], $scm['jar'], $scm['csrf']);
    $scmSessionId = $openScm['body']['data']['session_id'] ?? null;
    check('SCM user can open an opname session for their own warehouse', $scmSessionId !== null, json_encode($openScm['body']));

    $openCbd = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whCbd], $cbd['jar'], $cbd['csrf']);
    $cbdSessionId = $openCbd['body']['data']['session_id'] ?? null;
    check('Cibadak user can open an opname session for their own warehouse', $cbdSessionId !== null, json_encode($openCbd['body']));

    $cbdGetsScmSession = httpCall('GET', "{$base}/stock-opname/{$scmSessionId}", null, $cbd['jar']);
    check('Cibadak cannot access SCM opname session', $cbdGetsScmSession['status'] === 403 && $cbdGetsScmSession['body']['error']['code'] === 'FORBIDDEN');

    $scmGetsCbdSession = httpCall('GET', "{$base}/stock-opname/{$cbdSessionId}", null, $scm['jar']);
    check('SCM cannot access Cibadak opname session', $scmGetsCbdSession['status'] === 403 && $scmGetsCbdSession['body']['error']['code'] === 'FORBIDDEN');

    $cbdCountsScmSession = httpCall('POST', "{$base}/stock-opname/{$scmSessionId}/count", ['counts' => [['item_id' => $itemScm, 'counted_qty_base' => 100]]], $cbd['jar'], $cbd['csrf']);
    check('Cross-warehouse count is forbidden', $cbdCountsScmSession['status'] === 403 && $cbdCountsScmSession['body']['error']['code'] === 'FORBIDDEN');

    $cbdFinalizesScmSession = httpCall('POST', "{$base}/stock-opname/{$scmSessionId}/finalize", null, $cbd['jar'], $cbd['csrf']);
    check('Cross-warehouse finalize is forbidden', $cbdFinalizesScmSession['status'] === 403 && $cbdFinalizesScmSession['body']['error']['code'] === 'FORBIDDEN');

    $cbdPostsScmSession = httpCall('POST', "{$base}/stock-opname/{$scmSessionId}/post", null, $cbd['jar'], $cbd['csrf']);
    check('Cross-warehouse post is forbidden', $cbdPostsScmSession['status'] === 403 && $cbdPostsScmSession['body']['error']['code'] === 'FORBIDDEN');

    // clean up (cancel both sessions via SUPERADMIN so they don't linger OPEN — not scope-relevant,
    // just keeps the fixture tidy for anyone inspecting the DB afterward)
    $pdo->exec("UPDATE stock_opname_sessions SET status='CANCELLED' WHERE id IN ({$scmSessionId}, {$cbdSessionId})");

    // ============================================================
    // TRANSFER
    // ============================================================

    $scmCreatesFromCbd = httpCall('POST', "{$base}/transfers", [
        'transfer_uuid' => uid('iso-xfer'), 'from_warehouse_id' => $whCbd, 'to_warehouse_id' => $whScm,
        'ship_date' => '2026-09-05 08:00:00', 'lines' => [['item_id' => $itemCbd, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
    ], $scm['jar'], $scm['csrf']);
    check('STOCK cannot create a transfer FROM a warehouse that is not their own', $scmCreatesFromCbd['status'] === 403 && $scmCreatesFromCbd['body']['error']['code'] === 'FORBIDDEN');

    $validTransfer = httpCall('POST', "{$base}/transfers", [
        'transfer_uuid' => uid('iso-xfer-ok'), 'from_warehouse_id' => $whScm, 'to_warehouse_id' => $whCbd,
        'ship_date' => '2026-09-05 08:00:00', 'lines' => [['item_id' => $itemScm, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
    ], $scm['jar'], $scm['csrf']);
    $transferId = $validTransfer['body']['data']['transfer_id'] ?? null;
    check('STOCK CAN create a transfer FROM their own warehouse', $transferId !== null, json_encode($validTransfer['body']));

    $scmReceives = httpCall('POST', "{$base}/transfers/{$transferId}/receive", ['request_uuid' => uid('recv')], $scm['jar'], $scm['csrf']);
    check('Receive is forbidden for the SOURCE warehouse user (only destination may receive)', $scmReceives['status'] === 403 && $scmReceives['body']['error']['code'] === 'FORBIDDEN');

    $cbdReceives = httpCall('POST', "{$base}/transfers/{$transferId}/receive", ['request_uuid' => uid('recv')], $cbd['jar'], $cbd['csrf']);
    check('Receive is allowed for the DESTINATION warehouse user', ($cbdReceives['body']['data']['success'] ?? false) === true, json_encode($cbdReceives['body']));

    // Second transfer to test cancel scope (must stay PENDING, i.e. not received above).
    $transfer2 = httpCall('POST', "{$base}/transfers", [
        'transfer_uuid' => uid('iso-xfer-cancel'), 'from_warehouse_id' => $whScm, 'to_warehouse_id' => $whCbd,
        'ship_date' => '2026-09-05 08:00:00', 'lines' => [['item_id' => $itemScm, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
    ], $scm['jar'], $scm['csrf']);
    $transfer2Id = $transfer2['body']['data']['transfer_id'] ?? null;

    $cbdCancels = httpCall('POST', "{$base}/transfers/{$transfer2Id}/cancel", ['reason' => 'test'], $cbd['jar'], $cbd['csrf']);
    check('Cancel is forbidden for the DESTINATION warehouse user (only source may cancel)', $cbdCancels['status'] === 403 && $cbdCancels['body']['error']['code'] === 'FORBIDDEN');

    $scmCancels = httpCall('POST', "{$base}/transfers/{$transfer2Id}/cancel", ['reason' => 'test cleanup'], $scm['jar'], $scm['csrf']);
    check('Cancel is allowed for the SOURCE warehouse user', ($scmCancels['body']['data']['success'] ?? false) === true, json_encode($scmCancels['body']));

    // list/detail visibility: a transfer involving SCM<->Cibadak IS visible to both; an unrelated
    // warehouse must never see it.
    $whUnrelated = makeWarehouse($pdo, uid('ISO-UNREL'));
    $unrelatedUser = uid('unrel'); $unrelatedPass = 'UnrelPass123!';
    makeUser($pdo, $unrelatedUser, $unrelatedPass, $roles['STOCK'], $whUnrelated);
    $unrelated = login($base, $unrelatedUser, $unrelatedPass);

    $unrelatedDetail = httpCall('GET', "{$base}/transfers/{$transferId}", null, $unrelated['jar']);
    check('Transfer detail is forbidden to a warehouse not involved in the transfer', $unrelatedDetail['status'] === 403 && $unrelatedDetail['body']['error']['code'] === 'FORBIDDEN');

    $cbdDetail = httpCall('GET', "{$base}/transfers/{$transferId}", null, $cbd['jar']);
    check('Transfer detail IS visible to the destination warehouse', ($cbdDetail['body']['data']['id'] ?? null) === $transferId);

    $unrelatedList = httpCall('GET', "{$base}/transfers", null, $unrelated['jar']);
    $unrelatedListIds = array_map(fn ($t) => $t['id'], $unrelatedList['body']['data'] ?? []);
    check('Transfer list for an uninvolved warehouse never includes the SCM<->Cibadak transfer', !in_array($transferId, $unrelatedListIds, true));

    $scmList = httpCall('GET', "{$base}/transfers", null, $scm['jar']);
    $scmListIds = array_map(fn ($t) => $t['id'], $scmList['body']['data'] ?? []);
    check('Transfer list for the source warehouse includes the transfer it created', in_array($transferId, $scmListIds, true));

    foreach ([$scm['jar'], $cbd['jar'], $admin['jar'], $unrelated['jar']] as $jar) { @unlink($jar); }
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
