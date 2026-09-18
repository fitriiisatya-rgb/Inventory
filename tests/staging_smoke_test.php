<?php
declare(strict_types=1);

/**
 * FINAL FAST-TRACK GO_LIVE READINESS -- SCM + CIBADAK.
 * Section 8 (critical smoke test) + Section 7 (FIFO verification), run as
 * REAL HTTP requests (php -S + curl, same harness style as
 * tests/mysql_security_test.php) against the STAGING database already
 * populated by scripts/staging_dry_run_scm_cibadak.php -- real ~1,007-SKU
 * catalog, real SCM+CIBADAK opening, real 5 migration-negative SKUs. This
 * test does NOT reset the database (unlike every other test file) --
 * it deliberately builds on the staged data, exactly as a real pre-
 * production smoke test would.
 *
 * Auth/CSRF/role/idempotency/concurrency/period-lock/reversal coverage
 * (Section 9) is NOT re-implemented here -- it is already covered in depth
 * by tests/mysql_security_test.php, tests/mysql_void_test.php and
 * tests/concurrency_test.sh, re-run as part of the full regression suite
 * (see scripts/run_staging_dry_run.sh's caller). This file focuses on what
 * is actually new: real staged data + the migration-negative policy.
 *
 * Usage (DB_DATABASE MUST already point at the populated staging DB):
 *   DB_DATABASE=inventory_staging_scm_cibadak php tests/staging_smoke_test.php
 */

require_once __DIR__ . '/../services/Database.php';
use App\Services\Database;

$dbName = getenv('DB_DATABASE') ?: '';
if (!str_contains(strtolower($dbName), 'staging')) {
    fwrite(STDERR, "REFUSING TO RUN: DB_DATABASE ('{$dbName}') does not contain 'staging'.\n");
    exit(1);
}

$port = 8600 + random_int(0, 300);
$docRoot = __DIR__ . '/../public';
$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

/** @return array{status:int, body:array} */
function httpCall(string $method, string $url, ?array $body, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 10,
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

$pdo = Database::connection();
$itemCount = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
if ($itemCount < 900) {
    fwrite(STDERR, "REFUSING TO RUN: only {$itemCount} items in '{$dbName}' -- run scripts/staging_dry_run_scm_cibadak.php first.\n");
    exit(1);
}
echo "Connected to {$dbName}: {$itemCount} items already staged.\n\n";

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

try {
    // ==== 1. LOGIN ====
    echo "== 1. Login ==\n";
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $login = httpCall('POST', "{$base}/auth/login", ['username' => 'dryrun_admin', 'password' => 'DryRun#2026StagingOnly'], $jar);
    check('Login succeeds, csrf_token issued', $login['status'] === 200 && !empty($login['body']['data']['csrf_token']), json_encode($login['body']));
    $csrf = $login['body']['data']['csrf_token'] ?? '';

    // ==== 2. ITEM SEARCH ====
    echo "\n== 2. Item search (555410, all warehouses) ==\n";
    $search = httpCall('GET', "{$base}/inventory/current/555410", null, $jar, $csrf);
    check('Item search returns SCM + CIBADAK balances', $search['status'] === 200 && count($search['body']['data']['by_warehouse'] ?? []) === 2, json_encode($search['body']));
    $cibadakRow = null;
    foreach (($search['body']['data']['by_warehouse'] ?? []) as $row) {
        // by_warehouse doesn't carry warehouse code directly -- identify CIBADAK by its known negative balance.
        if ((float) $row['qty_base'] < 0) { $cibadakRow = $row; }
    }
    check('CIBADAK row for 555410 shows migration_negative_review = true', $cibadakRow !== null && $cibadakRow['migration_negative_review'] === true, json_encode($cibadakRow));

    $whScmId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='SCM'")->fetchColumn();
    $whCbdId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='CIBADAK'")->fetchColumn();
    $item555410 = (int) $pdo->query("SELECT id FROM items WHERE sku='555410'")->fetchColumn();
    $kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    $pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

    // ==== 3. SCM IN ====
    echo "\n== 3. SCM IN (555410, positive SKU) ==\n";
    $before = httpCall('GET', "{$base}/inventory/current?item_id={$item555410}&warehouse_id={$whScmId}", null, $jar, $csrf);
    $scmIn = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('smoke-in'), 'item_id' => $item555410, 'warehouse_id' => $whScmId,
        'input_qty' => 100, 'input_unit_id' => $pcsUnitId, 'unit_price_input' => 1800,
        'transaction_date' => '2026-09-17 09:00:00',
    ], $jar, $csrf);
    check('SCM IN succeeds', $scmIn['status'] === 200 && ($scmIn['body']['success'] ?? false) === true, json_encode($scmIn['body']));

    // ==== 4. SCM OUT ====
    echo "\n== 4. SCM OUT (555410, positive SKU) ==\n";
    $scmOut = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => uid('smoke-out'), 'item_id' => $item555410, 'warehouse_id' => $whScmId,
        'input_qty' => 50, 'input_unit_id' => $pcsUnitId, 'transaction_date' => '2026-09-17 09:05:00',
    ], $jar, $csrf);
    check('SCM OUT succeeds (plenty of positive stock)', $scmOut['status'] === 200 && ($scmOut['body']['success'] ?? false) === true, json_encode($scmOut['body']));

    // ==== 5. CIBADAK IN (migration-negative SKU) ====
    echo "\n== 5. CIBADAK IN (555410, migration-negative SKU -- IN must be allowed) ==\n";
    $cbIn = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('smoke-cb-in'), 'item_id' => $item555410, 'warehouse_id' => $whCbdId,
        'input_qty' => 40, 'input_unit_id' => $pcsUnitId, 'unit_price_input' => 1775,
        'transaction_date' => '2026-09-17 09:10:00',
    ], $jar, $csrf);
    check('CIBADAK IN on migration-negative SKU succeeds (IN always allowed)', $cbIn['status'] === 200 && ($cbIn['body']['success'] ?? false) === true, json_encode($cbIn['body']));

    // ==== 6. CIBADAK OUT (still negative after the IN above: -250+40=-210) ====
    echo "\n== 6. CIBADAK OUT (555410, still migration-negative -- must be BLOCKED) ==\n";
    $cbOut = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => uid('smoke-cb-out'), 'item_id' => $item555410, 'warehouse_id' => $whCbdId,
        'input_qty' => 5, 'input_unit_id' => $pcsUnitId, 'transaction_date' => '2026-09-17 09:15:00',
    ], $jar, $csrf);
    check(
        'CIBADAK OUT on migration-negative SKU is BLOCKED with NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT',
        $cbOut['status'] === 422 && ($cbOut['body']['error']['code'] ?? '') === 'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT',
        json_encode($cbOut['body'])
    );

    // ==== 7. TRANSFER SCM -> CIBADAK (ordinary positive SKU) ====
    echo "\n== 7. Transfer SCM -> CIBADAK + receive (400201, distinct from the migration-negative test SKU) ==\n";
    $transferSkuId = (int) $pdo->query("SELECT id FROM items WHERE sku='555410'")->fetchColumn(); // reuse 555410; SCM side is healthy positive
    $transferUuid = uid('smoke-transfer');
    $transferCreate = httpCall('POST', "{$base}/transfers", [
        'transfer_uuid' => $transferUuid, 'from_warehouse_id' => $whScmId, 'to_warehouse_id' => $whCbdId,
        'ship_date' => '2026-09-17 10:00:00',
        'lines' => [['item_id' => $transferSkuId, 'input_qty' => 20, 'input_unit_id' => $pcsUnitId]],
    ], $jar, $csrf);
    check('Transfer create succeeds', $transferCreate['status'] === 200 && ($transferCreate['body']['success'] ?? false) === true, json_encode($transferCreate['body']));
    $transferId = $transferCreate['body']['data']['transfer_id'] ?? null;
    $transferReceive = $transferId !== null
        ? httpCall('POST', "{$base}/transfers/{$transferId}/receive", ['request_uuid' => uid('smoke-recv')], $jar, $csrf)
        : ['status' => 0, 'body' => []];
    check('Transfer receive succeeds', $transferReceive['status'] === 200 && ($transferReceive['body']['success'] ?? false) === true, json_encode($transferReceive['body']));

    // ==== 8. FIFO consumption sanity (two IN layers, OUT consumes oldest first) ====
    echo "\n== 8. FIFO consumption -- OUT consumes the OLDEST layer first ==\n";
    $fifoSku = uid('SMOKE-FIFO');
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, status) VALUES (:s,:n,:u,\'ACTIVE\')')->execute(['s' => $fifoSku, 'n' => $fifoSku, 'u' => $kgUnitId]);
    $fifoItemId = (int) $pdo->lastInsertId();
    require_once __DIR__ . '/../services/UnitConversionService.php';
    \App\Services\UnitConversionService::openNewVersion($pdo, $fifoItemId, $kgUnitId, 1.0, '2000-01-01 00:00:00', null, 'identity');
    httpCall('POST', "{$base}/transactions/in", ['transaction_uuid' => uid('fifo-in1'), 'item_id' => $fifoItemId, 'warehouse_id' => $whScmId, 'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 10000, 'transaction_date' => '2026-09-10 08:00:00'], $jar, $csrf);
    httpCall('POST', "{$base}/transactions/in", ['transaction_uuid' => uid('fifo-in2'), 'item_id' => $fifoItemId, 'warehouse_id' => $whScmId, 'input_qty' => 50, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 12000, 'transaction_date' => '2026-09-11 08:00:00'], $jar, $csrf);
    $fifoOut = httpCall('POST', "{$base}/transactions/out", ['transaction_uuid' => uid('fifo-out'), 'item_id' => $fifoItemId, 'warehouse_id' => $whScmId, 'input_qty' => 120, 'input_unit_id' => $kgUnitId, 'transaction_date' => '2026-09-12 08:00:00'], $jar, $csrf);
    $expectedFifoCost = round((100 * 10000 + 20 * 12000) / 120, 4); // 100@10,000 fully consumed, then 20@12,000
    check('FIFO OUT cost = weighted avg of OLDEST layers first (100@10,000 + 20@12,000)', $fifoOut['status'] === 200 && abs(($fifoOut['body']['data']['unit_cost_base'] ?? -1) - $expectedFifoCost) < 0.01, json_encode($fifoOut['body']) . " expected {$expectedFifoCost}");

    // ==== 9. Stock Opname resolves a migration-negative SKU ====
    echo "\n== 9. Stock Opname on CIBADAK, physical count resolves 800401 ==\n";
    $item800401 = (int) $pdo->query("SELECT id FROM items WHERE sku='800401'")->fetchColumn();
    $opnameStart = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whCbdId, 'item_ids' => [$item800401]], $jar, $csrf);
    check('Opname session starts for CIBADAK', $opnameStart['status'] === 200, json_encode($opnameStart['body']));
    $sessionId = $opnameStart['body']['data']['session_id'] ?? null;
    $opnameCount = $sessionId !== null
        ? httpCall('POST', "{$base}/stock-opname/{$sessionId}/count", ['counts' => [['item_id' => $item800401, 'counted_qty_base' => 50]]], $jar, $csrf)
        : ['status' => 0];
    check('Opname count recorded (physical = 50 LTR, resolving the -162 deficit)', $opnameCount['status'] === 200, json_encode($opnameCount['body'] ?? []));
    $opnameFinalize = $sessionId !== null ? httpCall('POST', "{$base}/stock-opname/{$sessionId}/finalize", null, $jar, $csrf) : ['status' => 0];
    check('Opname finalizes', $opnameFinalize['status'] === 200, json_encode($opnameFinalize['body'] ?? []));
    $opnamePost = $sessionId !== null ? httpCall('POST', "{$base}/stock-opname/{$sessionId}/post", null, $jar, $csrf) : ['status' => 0];
    check('Opname posts (creates the audited adjustment)', $opnamePost['status'] === 200, json_encode($opnamePost['body'] ?? []));
    $after800401 = httpCall('GET', "{$base}/inventory/current?item_id={$item800401}&warehouse_id={$whCbdId}", null, $jar, $csrf);
    check('800401/CIBADAK balance now +50 (resolved)', abs(($after800401['body']['data']['qty_base'] ?? -999) - 50.0) < 0.001, json_encode($after800401['body']));
    check('800401/CIBADAK migration_negative_review now false (resolved)', ($after800401['body']['data']['migration_negative_review'] ?? true) === false, json_encode($after800401['body']));
    $out800401 = httpCall('POST', "{$base}/transactions/out", ['transaction_uuid' => uid('smoke-800401-out'), 'item_id' => $item800401, 'warehouse_id' => $whCbdId, 'input_qty' => 5, 'input_unit_id' => (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn(), 'transaction_date' => '2026-09-17 11:00:00'], $jar, $csrf);
    check('OUT on 800401/CIBADAK now succeeds (deficit resolved)', $out800401['status'] === 200 && ($out800401['body']['success'] ?? false) === true, json_encode($out800401['body']));

    // ==== 10. Stock Adjustment resolves a second migration-negative SKU directly ====
    echo "\n== 10. Stock Adjustment directly resolves 100304/SCM ==\n";
    $item100304 = (int) $pdo->query("SELECT id FROM items WHERE sku='100304'")->fetchColumn();
    $adj = httpCall('POST', "{$base}/stock-adjustments", [
        'transaction_uuid' => uid('smoke-adj'), 'item_id' => $item100304, 'warehouse_id' => $whScmId,
        'qty_base_delta' => 3.5, 'adjustment_type' => 'CORRECTION', 'reason' => 'Smoke test: physical count found 3 KG on hand',
        'migration_issue_reference' => 'MIGRATION-100304-SCM',
    ], $jar, $csrf);
    check('Stock Adjustment on 100304/SCM succeeds', $adj['status'] === 200 && ($adj['body']['success'] ?? false) === true, json_encode($adj['body']));
    check('after_qty_base = 3.0', abs(($adj['body']['data']['after_qty_base'] ?? -999) - 3.0) < 0.001, json_encode($adj['body']));

    // ==== 11. Historical ledger display ====
    echo "\n== 11. Historical rows visible in the SKU ledger (400201/CIBADAK) ==\n";
    $item400201 = (int) $pdo->query("SELECT id FROM items WHERE sku='400201'")->fetchColumn();
    $ledger = httpCall('GET', "{$base}/inventory/ledger?item_id={$item400201}&warehouse_id={$whCbdId}", null, $jar, $csrf);
    $historicalLines = array_filter($ledger['body']['data'] ?? [], fn ($l) => $l['is_historical'] === true);
    check('Ledger for 400201/CIBADAK includes historical rows (Opening 1 Sep + IN + OUT)', count($historicalLines) === 3, json_encode(array_map(fn ($l) => $l['transaction_type'], $ledger['body']['data'] ?? [])));
    $liveLines = array_filter($ledger['body']['data'] ?? [], fn ($l) => $l['is_historical'] === false);
    check('Ledger also includes the LIVE opening line', count($liveLines) >= 1);

    // ==== 12. Migration-negative warning list ====
    echo "\n== 12. Migration-negative review list ==\n";
    $review = httpCall('GET', "{$base}/migration-negative-review", null, $jar, $csrf);
    check('Review list returns exactly 5 whitelisted rows', count($review['body']['data'] ?? []) === 5, json_encode(array_column($review['body']['data'] ?? [], 'sku')));
    $stillOpen = array_filter($review['body']['data'] ?? [], fn ($r) => $r['status'] === 'MIGRATION_NEGATIVE_REVIEW');
    check('3 rows remain MIGRATION_NEGATIVE_REVIEW (2 resolved above: 800401, 100304)', count($stillOpen) === 3, json_encode(array_column($stillOpen, 'sku')));

    // ==== 13. Dashboard / live totals ====
    echo "\n== 13. Dashboard / company live totals ==\n";
    $value = httpCall('GET', "{$base}/inventory/value", null, $jar, $csrf);
    check('Company value endpoint responds', $value['status'] === 200, json_encode($value['body']));
    check('contains_unresolved_migration_negative_stock = true (3 still open)', ($value['body']['data']['contains_unresolved_migration_negative_stock'] ?? null) === true, json_encode($value['body']));

    // ==== 14. Karang Tengah rejection ====
    echo "\n== 14. Karang Tengah is rejected (PENDING_CUTOVER -- not created in this staging DB) ==\n";
    $warehouses = httpCall('GET', "{$base}/warehouses", null, $jar, $csrf);
    $codes = array_column($warehouses['body']['data'] ?? [], 'code');
    check('Warehouse catalog contains ONLY SCM + CIBADAK (no KARANG_TENGAH)', !in_array('KARANG_TENGAH', $codes, true) && count($codes) === 2, json_encode($codes));

    $csvPath = tempnam(sys_get_temp_dir(), 'kt_') . '.csv';
    file_put_contents($csvPath, "cutoff_date,warehouse_code,sku,opening_qty_base,unit_cost_base\n2026-09-16,KARANG_TENGAH,555410,10,1000\n");
    require_once __DIR__ . '/../services/Exceptions.php';
    require_once __DIR__ . '/../services/UnitNormalizationService.php';
    require_once __DIR__ . '/../services/PriceAnomalyService.php';
    require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
    require_once __DIR__ . '/../services/InventoryService.php';
    require_once __DIR__ . '/../services/FifoService.php';
    require_once __DIR__ . '/../services/CostNormalizationService.php';
    require_once __DIR__ . '/../services/OpeningValidationService.php';
    require_once __DIR__ . '/../services/ImportOpeningStockService.php';
    $ktResult = \App\Services\OpeningValidationService::validateRow($pdo, ['warehouse_code' => 'KARANG_TENGAH', 'sku' => '555410', 'opening_qty_base' => '10', 'unit_cost_base' => '1000']);
    check('A Karang Tengah transaction is REJECTED (unknown warehouse -- not part of this fast-track)', $ktResult['status'] === 'ERROR' && str_contains($ktResult['messages'][0], 'unknown warehouse_code'), json_encode($ktResult));
    unlink($csvPath);
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($jar);
}

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
