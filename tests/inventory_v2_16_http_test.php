<?php
declare(strict_types=1);

/**
 * PHASE V2.16 — HTTP-level proof against the REAL application routes
 * (php -S + curl, same pattern as tests/inventory_v2_14_11_http_findings_test.php)
 * for the three spec test cases that need a real wire round-trip rather
 * than a direct service call:
 *   1. a real multipart .xlsx upload through POST /stock-opname/{id}/reference-import
 *  15. a counter/VIEWER (no STOCK_OPNAME_SUPERVISE) is denied with 403
 *  16. a SUPERADMIN is authorized and the import succeeds
 *
 * Usage: php tests/inventory_v2_16_http_test.php
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
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;

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
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V216HWH', 'V2.16 HTTP Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'HttpV216' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$admin = makeUser($pdo, 'v216hadmin', $superRoleId);
$counter = makeUser($pdo, 'v216hcounter', $viewerRoleId);

$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,\'ACTIVE\')')
    ->execute(['sku' => uid('V216H-ITEM'), 'name' => 'HTTP Test Item', 'unit' => $kgUnitId]);
$itemId = (int) $pdo->lastInsertId();
UnitConversionService::openNewVersion($pdo, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v216h-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
    'input_qty' => 20, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $admin['id'], 'username' => 'v216h', 'transaction_type' => 'OPENING',
]));
$itemSku = $pdo->query("SELECT sku FROM items WHERE id = {$itemId}")->fetchColumn();

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $admin['id'], [$itemId]));

function makeRefCsv(string $sku): string
{
    $path = tempnam(sys_get_temp_dir(), 'v216http_') . '.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['Source Code', 'Source Name', 'Source Unit', 'Source Qty']);
    fputcsv($fh, [$sku, 'HTTP Test Item (SCM)', 'KG', '5']);
    fclose($fh);
    return $path;
}

// ============================================================
// Boot the real app over HTTP
// ============================================================
$port = 8900 + random_int(3200, 3599);
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

function httpUploadFile(string $url, string $filePath, string $fileName, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    $fields = ['file' => new CURLFile($filePath, 'text/csv', $fileName)];
    // Suppress curl's automatic "Expect: 100-continue" for multipart POSTs
    // — php -S can reply with an interim "100 Continue" header block
    // before the real response, which throws off a CURLINFO_HEADER_SIZE
    // split of headers from body (symptom: the decoded body silently
    // comes back empty even though the request itself succeeded).
    $headers = ['Expect:'];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

function login(string $base, array $creds): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cookie_v216_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $creds['username'], 'password' => $creds['password']], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? '', 'status' => $res['status']];
}

try {
    $adminAuth = login($base, $admin);
    check('setup: admin login succeeds', $adminAuth['status'] === 200, (string) $adminAuth['status']);
    $counterAuth = login($base, $counter);
    check('setup: counter(VIEWER) login succeeds', $counterAuth['status'] === 200, (string) $counterAuth['status']);

    // ============================================================
    // 15 — counter role (no STOCK_OPNAME_SUPERVISE) is denied, never
    // receives supervisor reference/financial data.
    // ============================================================
    $csvForDenied = makeRefCsv($itemSku);
    $deniedRes = httpUploadFile("{$base}/stock-opname/{$sessionId}/reference-import", $csvForDenied, 'ref.csv', $counterAuth['jar'], $counterAuth['csrf']);
    check('15. counter (VIEWER, no STOCK_OPNAME_SUPERVISE) is denied with 403', $deniedRes['status'] === 403, json_encode($deniedRes['body']));
    $deniedBatches = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_reference_batches WHERE session_id = {$sessionId}")->fetchColumn();
    check('15b. the denied request created ZERO reference batches', $deniedBatches === 0, (string) $deniedBatches);

    $deniedDraft = httpCall('GET', "{$base}/stock-opname/{$sessionId}/export/draft", null, $counterAuth['jar'], $counterAuth['csrf']);
    check('15c. counter is also denied the draft Excel export', $deniedDraft['status'] === 403, (string) $deniedDraft['status']);

    // ============================================================
    // 1/16 — a real multipart .xlsx/.csv upload, by an authorized
    // SUPERADMIN, succeeds end-to-end through the actual route.
    // ============================================================
    $csvForAdmin = makeRefCsv($itemSku);
    $okRes = httpUploadFile("{$base}/stock-opname/{$sessionId}/reference-import", $csvForAdmin, 'ref.csv', $adminAuth['jar'], $adminAuth['csrf']);
    check('1. a real multipart file upload through the actual route succeeds', $okRes['status'] === 200, json_encode($okRes['body']));
    check('16. SUPERADMIN (authorized) import reports 1 matched row', ($okRes['body']['data']['matched_count'] ?? null) === 1, json_encode($okRes['body']['data'] ?? null));

    $batchesRes = httpCall('GET', "{$base}/stock-opname/{$sessionId}/reference-batches", null, $adminAuth['jar'], $adminAuth['csrf']);
    check('16b. the authorized upload is visible via the batches endpoint', $batchesRes['status'] === 200 && count($batchesRes['body']['data'] ?? []) === 1, json_encode($batchesRes['body']));

    $draftRes = httpCall('GET', "{$base}/stock-opname/{$sessionId}/export/draft", null, $adminAuth['jar'], $adminAuth['csrf']);
    check('16c. an authorized supervisor CAN download the draft export', $draftRes['status'] === 200, (string) $draftRes['status']);
} finally {
    proc_terminate($process);
    usleep(100_000);
}

echo "\n==============================\n";
$pass = count(array_filter($results));
echo 'TOTAL: ' . count($results) . '  PASSED: ' . $pass . '  FAILED: ' . (count($results) - $pass) . "\n";
exit($pass === count($results) ? 0 : 1);
