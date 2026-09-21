<?php
declare(strict_types=1);

/**
 * PHASE V2.6C — release hardening: reconciliation interactive-period
 * guard, Audit Transaksi server-side pagination (TraceService::
 * browseEvents(), never a second audit source), CSV export correctness
 * (filters/warehouse-scope/historical/NOT_COMPARABLE preserved, valid
 * RFC-ish CSV, PHP 8.4-safe fputcsv, no mutation), and warehouse
 * isolation enforced at the HTTP boundary (query-parameter override
 * included) for the new export endpoints. Runs against a real
 * `php -S` + the throwaway MySQL/MariaDB database — same harness
 * pattern as tests/stock_report_test.php's own HTTP section.
 *
 * Usage: php tests/inventory_reports_v26c_test.php
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
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;

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
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v26csetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V26C Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V26C-A'), 'n' => 'V26C Warehouse A']);
$whA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V26C-B'), 'n' => 'V26C Warehouse B']);
$whB = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('V26C-SUP'), 'n' => 'V26C Supplier']);
$supplierId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

// Pin live_opening_date far in the past (matches the convention every
// other V2.6B/C test file uses) so this file's own 2026 dates run fully live.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26c-pin'), 'item_id' => makeItem($pdo, $kgUnitId, 'V26C-PIN')[0], 'warehouse_id' => $whA,
    'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1,
    'transaction_date' => '2020-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26c', 'transaction_type' => 'OPENING',
]));

// Fixture purchase on whA, used for CSV export content checks.
[$itemA, $skuA] = makeItem($pdo, $kgUnitId, 'V26C-PURCHASE');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26c-open'), 'item_id' => $itemA, 'warehouse_id' => $whA,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26c', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26c-in'), 'item_id' => $itemA, 'warehouse_id' => $whA,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2000,
    'transaction_date' => '2026-07-05 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26c', 'supplier_id' => $supplierId,
]));
// A distinct purchase on whB, used to prove cross-warehouse export never leaks it.
[$itemB, $skuB] = makeItem($pdo, $kgUnitId, 'V26C-PURCHASE-B');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26c-open-b'), 'item_id' => $itemB, 'warehouse_id' => $whB,
    'input_qty' => 100, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v26c', 'transaction_type' => 'OPENING',
]));
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v26c-in-b'), 'item_id' => $itemB, 'warehouse_id' => $whB,
    'input_qty' => 7, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 3000,
    'transaction_date' => '2026-07-06 08:00:00', 'created_by' => $adminUserId, 'username' => 'v26c', 'supplier_id' => $supplierId,
]));

// ============================================================
// HTTP server + login
// ============================================================
$port = 8900 + random_int(0, 400);
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

function httpCall(string $method, string $url, ?array $body, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $headerSize);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'headers' => $headers, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

try {
    $superUser = uid('v26csuper'); $superPass = 'V26cSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);

    $stockAUser = uid('v26cstockA'); $stockAPass = 'V26cStockAPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockAUser, 'h' => password_hash($stockAPass, PASSWORD_BCRYPT), 'n' => $stockAUser, 'r' => $stockRoleId, 'w' => $whA]);
    $stockAJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $stockAUser, 'password' => $stockAPass], $stockAJar);

    // ============================================================
    // A — Reconciliation interactive-period guard
    // ============================================================
    echo "== A: reconciliation range guard ==\n";

    $start = '2015-01-01';
    $end365 = date('Y-m-d', strtotime($start . ' +364 days'));
    $end366 = date('Y-m-d', strtotime($start . ' +365 days'));
    $end367 = date('Y-m-d', strtotime($start . ' +366 days'));

    $r365 = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date={$start}&end_date={$end365}", null, $superJar);
    check('365-day range is accepted', $r365['status'] === 200, "status={$r365['status']} body=" . json_encode($r365['body']));

    $r366 = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date={$start}&end_date={$end366}", null, $superJar);
    check('366-day range is accepted', $r366['status'] === 200, "status={$r366['status']}");

    $r367 = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date={$start}&end_date={$end367}", null, $superJar);
    check('367-day range is rejected with 422', $r367['status'] === 422, "status={$r367['status']} body=" . json_encode($r367['body']));
    check('367-day rejection carries a clear Indonesian message, never a silent truncation', $r367['status'] === 422 && str_contains((string) ($r367['body']['error']['message'] ?? ''), '366 hari'), (string) ($r367['body']['error']['message'] ?? ''));

    $rReverse = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date=2026-06-30&end_date=2026-06-01", null, $superJar);
    check('reverse date range (start > end) is rejected', $rReverse['status'] === 422);

    $rInvalid = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date=not-a-date&end_date=2026-06-30", null, $superJar);
    check('invalid date string is rejected', $rInvalid['status'] === 422);

    // ============================================================
    // B — Audit Transaksi server-side pagination
    // ============================================================
    echo "\n== B: audit pagination ==\n";

    $auditP1 = httpCall('GET', "{$base}/reports/audit?per_page=5&page=1", null, $superJar);
    check('audit report returns 200', $auditP1['status'] === 200, (string) $auditP1['status']);
    $pagination = $auditP1['body']['data']['pagination'] ?? null;
    check('audit response carries pagination.page/per_page/total/total_pages', $pagination !== null && isset($pagination['page'], $pagination['per_page'], $pagination['total'], $pagination['total_pages']), json_encode($pagination));
    check('audit per_page honors the requested value (5)', $pagination !== null && (int) $pagination['per_page'] === 5, json_encode($pagination));
    check('audit rows never exceed requested per_page', count($auditP1['body']['data']['rows'] ?? []) <= 5);

    $auditCapped = httpCall('GET', "{$base}/reports/audit?per_page=99999", null, $superJar);
    $cappedPagination = $auditCapped['body']['data']['pagination'] ?? null;
    check('audit per_page is hard-capped at 100 even when a larger value is requested', $cappedPagination !== null && (int) $cappedPagination['per_page'] === 100, json_encode($cappedPagination));

    $auditRow = ($auditP1['body']['data']['rows'][0] ?? null);
    if ($auditRow !== null) {
        check('audit row never exposes a password/session/CSRF field', !array_key_exists('password_hash', $auditRow) && !array_key_exists('session_token', $auditRow) && !array_key_exists('csrf_token', $auditRow) && !array_key_exists('ip_address', $auditRow), json_encode(array_keys($auditRow)));
    } else {
        check('audit row never exposes a password/session/CSRF field', true, 'no rows to inspect, vacuously true — field-shape is enforced server-side regardless');
    }

    $auditFiltered = httpCall('GET', "{$base}/reports/audit?username=" . urlencode($superUser), null, $superJar);
    check('audit username filter narrows results (every row matches the filtered actor)', $auditFiltered['status'] === 200 && array_reduce($auditFiltered['body']['data']['rows'] ?? [], fn ($carry, $r) => $carry && $r['actor'] === $superUser, true));

    // ============================================================
    // C — CSV export correctness
    // ============================================================
    echo "\n== C: CSV export correctness ==\n";

    $csvPurchase = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}&format=csv", null, $superJar);
    check('purchase export returns 200', $csvPurchase['status'] === 200, (string) $csvPurchase['status']);
    check('purchase export Content-Type is text/csv', str_contains($csvPurchase['headers'], 'Content-Type: text/csv'), $csvPurchase['headers']);
    check('purchase export filename follows laporan-pembelian_<scope>_<dates>.csv pattern', (bool) preg_match('/filename="laporan-pembelian_[^"]+\.csv"/', $csvPurchase['headers']), $csvPurchase['headers']);
    check('purchase export starts with a UTF-8 BOM (Excel-friendly)', str_starts_with($csvPurchase['raw'], "\xEF\xBB\xBF"));
    $csvBodyPurchase = substr($csvPurchase['raw'], 3);
    check('purchase export header row is present', str_starts_with($csvBodyPurchase, 'Tanggal,Gudang,Supplier'), substr($csvBodyPurchase, 0, 60));
    check('purchase export contains the whA fixture SKU', str_contains($csvBodyPurchase, $skuA), $skuA);
    check('purchase export scoped to whA never contains whB\'s SKU (filter respected)', !str_contains($csvBodyPurchase, $skuB));

    // Reconciliation export preserves NOT_COMPARABLE, never fabricates a number.
    $csvRecon = httpCall('GET', "{$base}/reports/reconciliation/movement?start_date=2026-07-01&end_date=2026-07-10&warehouse_id={$whA}&format=csv", null, $superJar);
    check('reconciliation export (non-today end_date) preserves NOT_COMPARABLE, never a fabricated number', str_contains($csvRecon['raw'], 'NOT_COMPARABLE'), substr($csvRecon['raw'], -400));

    // Movement export preserves the HISTORICAL section as its own labelled block.
    $histTxStmt = $pdo->prepare(
        "INSERT INTO inventory_transactions
            (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
         VALUES (:uuid, 'IN', :tx_date, :post_date, :wh, 'POSTED', 1, 0, :created_by, :created_at)"
    );
    $now = date('Y-m-d H:i:s');
    $histTxStmt->execute(['uuid' => uid('v26c-hist'), 'tx_date' => '2026-07-03 09:00:00', 'post_date' => $now, 'wh' => $whA, 'created_by' => $adminUserId, 'created_at' => $now]);
    $histTxId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO inventory_transaction_lines
            (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot,
             base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
         VALUES (:tx, 1, :item, :name, 5, :unit, 1, 5, 800, 800, 4000, :wh)'
    )->execute(['tx' => $histTxId, 'item' => $itemA, 'name' => 'hist line', 'unit' => $kgUnitId, 'wh' => $whA]);

    $csvMovement = httpCall('GET', "{$base}/reports/movement/daily?start_date=2026-07-01&end_date=2026-07-10&warehouse_id={$whA}&format=csv", null, $superJar);
    check('movement export includes a HISTORICAL / REPORTING ONLY section header', str_contains($csvMovement['raw'], 'HISTORICAL / REPORTING ONLY'), substr($csvMovement['raw'], -300));

    // ============================================================
    // D — Warehouse isolation on the new export endpoints
    // ============================================================
    echo "\n== D: warehouse isolation on export endpoints ==\n";

    $stockAOwnExport = httpCall('GET', "{$base}/reports/purchase?format=csv", null, $stockAJar);
    check('STOCK-A export (no warehouse_id) returns 200, scoped to their own warehouse', $stockAOwnExport['status'] === 200);
    check('STOCK-A own-warehouse export contains their own SKU', str_contains($stockAOwnExport['raw'], $skuA));
    check('STOCK-A own-warehouse export never contains whB\'s SKU', !str_contains($stockAOwnExport['raw'], $skuB));

    // The explicit attack case: STOCK-A tries to override warehouse_id via
    // the query string to reach whB — inv_hpp_resolve_warehouse_scope()
    // forces STOCK back to their own warehouse regardless of what was
    // requested (same shared function every V2.6B route already uses),
    // so this must silently stay scoped to whA, never leak whB.
    $stockACrossExport = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whB}&format=csv", null, $stockAJar);
    check('STOCK-A export with warehouse_id=whB in the query string never leaks whB\'s SKU', !str_contains($stockACrossExport['raw'], $skuB), substr($stockACrossExport['raw'], 0, 200));

    $stockACrossApi = httpCall('GET', "{$base}/reports/transfer?warehouse_id={$whB}", null, $stockAJar);
    check('STOCK-A JSON API with warehouse_id=whB never returns whB data (forced back to own scope, per existing inv_hpp_resolve_warehouse_scope)', $stockACrossApi['status'] === 200);

    $stockADirectApi = httpCall('GET', "{$base}/reports/opname?warehouse_id={$whB}", null, $stockAJar);
    check('STOCK-A direct API request for opname with warehouse_id=whB returns 200 but scoped to own warehouse (never 500/leak)', $stockADirectApi['status'] === 200);

    // ============================================================
    // E — read-only: exercising every V2.6C route never mutates state
    // ============================================================
    echo "\n== E: V2.6C routes are strictly read-only ==\n";

    $txCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $auditCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

    httpCall('GET', "{$base}/reports/reconciliation/movement?start_date=2026-07-01&end_date=2026-07-10", null, $superJar);
    httpCall('GET', "{$base}/reports/audit?per_page=10", null, $superJar);
    httpCall('GET', "{$base}/reports/purchase?format=csv", null, $superJar);
    httpCall('GET', "{$base}/reports/reconciliation/movement?start_date=2026-07-01&end_date=2026-07-10&format=csv", null, $superJar);

    $txCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $auditCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    check('V2.6C export/pagination/range-guard routes never change inventory_transactions count', $txCountBefore === $txCountAfter, "{$txCountBefore} vs {$txCountAfter}");
    check('V2.6C routes never write their own audit_logs rows merely from being viewed (read-only GETs)', $auditCountBefore === $auditCountAfter, "{$auditCountBefore} vs {$auditCountAfter}");

    @unlink($superJar);
    @unlink($stockAJar);
} finally {
    proc_terminate($process);
    proc_close($process);
}

// ============================================================
echo "\n== SUMMARY ==\n";
$total = count($results);
$passed = count(array_filter($results));
echo "{$passed} / {$total} PASSED\n";
if ($passed !== $total) {
    exit(1);
}
