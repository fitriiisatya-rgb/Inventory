<?php
declare(strict_types=1);

/**
 * PHASE V2.14.11 — HTTP-level proof against the REAL application routes
 * (php -S + curl, exactly the pattern inventory_v2_12_dual_count_opname_test.php
 * already uses), not a re-implementation of the service layer. Exists
 * specifically because the service-level test file cannot prove what the
 * actual HTTP response body does or doesn't contain (blindness must be
 * proven from the real wire response, not from calling PHP methods
 * directly) — see item 11 of the Checkpoint A completion requirements.
 *
 * Usage: php tests/inventory_v2_14_11_http_findings_test.php
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
$pcsUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411H', 'V2.14.11 HTTP Test WH', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V21411H2', 'V2.14.11 HTTP Test WH 2', 1)")->execute();
$whId2 = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId): array
{
    $u = uid($tag);
    $pass = 'HttpTest' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

$adminUserId = makeUser($pdo, 'v21411hadmin', $superRoleId)['id'];
$viewerP1a = makeUser($pdo, 'v21411hviewer-p1a', $viewerRoleId);
$viewerP1b = makeUser($pdo, 'v21411hviewer-p1b', $viewerRoleId);
$viewerP2a = makeUser($pdo, 'v21411hviewer-p2a', $viewerRoleId);
$unassignedUser = makeUser($pdo, 'v21411hunassigned', $viewerRoleId);
$adminCreds = ['username' => uid('v21411hadmin2'), 'password' => 'HttpTestAdmin123!'];
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $adminCreds['username'], 'h' => password_hash($adminCreds['password'], PASSWORD_BCRYPT), 'n' => $adminCreds['username'], 'r' => $superRoleId]);
$adminCreds['id'] = (int) $pdo->lastInsertId();

function makeItemWithUnits(PDO $pdo, string $tag, int $baseUnitId, ?int $purchaseUnitId, float $purchaseFactor): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku,:name,:unit,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $baseUnitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $baseUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    if ($purchaseUnitId !== null) {
        UnitConversionService::openNewVersion($pdo, $id, $purchaseUnitId, $purchaseFactor, '2020-01-01 00:00:00', null, 'purchase unit', true);
    }
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v21411h-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v21411h', 'transaction_type' => 'OPENING',
    ]));
}
function makeFakePhotoFile(): string
{
    $img = imagecreatetruecolor(6, 6);
    imagefill($img, 0, 0, imagecolorallocate($img, 40, 90, 200));
    $path = tempnam(sys_get_temp_dir(), 'v21411hphoto') . '.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);
    return $path;
}

$itemA = makeItemWithUnits($pdo, 'V21411H-A', $kgUnitId, $pcsUnitId, 5.0);
postOpeningIn($pdo, $itemA, $kgUnitId, $whId, 100, 1000, $adminUserId);
$itemB = makeItemWithUnits($pdo, 'V21411H-B', $kgUnitId, null, 1.0);
postOpeningIn($pdo, $itemB, $kgUnitId, $whId2, 10, 500, $adminUserId);

// Baseline inventory snapshot, taken BEFORE the HTTP server does anything —
// re-checked at the very end (item 14: inventory must be exactly unchanged).
$invBefore = $pdo->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(qty_base*unit_cost_base),0) AS val FROM inventory_batches')->fetch();

// ============================================================
// Boot the real app over HTTP
// ============================================================
$port = 8900 + random_int(2800, 3199);
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

function httpUpload(string $url, string $filePath, array $fields, string $cookieJar, ?string $csrfToken = null): array
{
    $ch = curl_init($url);
    $fields['photo'] = new CURLFile($filePath, 'image/jpeg', 'evidence.jpg');
    $headers = [];
    if ($csrfToken !== null) { $headers[] = "X-CSRF-Token: {$csrfToken}"; }
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
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
    $jar = tempnam(sys_get_temp_dir(), 'cookie_');
    $res = httpCall('POST', "{$base}/auth/login", ['username' => $creds['username'], 'password' => $creds['password']], $jar);
    return ['jar' => $jar, 'csrf' => $res['body']['data']['csrf_token'] ?? '', 'status' => $res['status']];
}

try {
    $adminAuth = login($base, $adminCreds);
    check('setup: admin login succeeds', $adminAuth['status'] === 200, (string) $adminAuth['status']);
    $p1aAuth = login($base, $viewerP1a);
    $p1bAuth = login($base, $viewerP1b);
    $p2aAuth = login($base, $viewerP2a);
    $unassignedAuth = login($base, $unassignedUser);

    // ---- start FINDINGS_V1 session, assign teams, over HTTP ----
    $startRes = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whId, 'item_ids' => [$itemA]], $adminAuth['jar'], $adminAuth['csrf']);
    $sessionId = $startRes['body']['data']['session_id'] ?? null;
    check('HTTP: session starts as FINDINGS_V1 by default', $sessionId !== null, json_encode($startRes['body']));

    $assignP1 = httpCall('POST', "{$base}/stock-opname/{$sessionId}/assign-team", ['role' => 'p1', 'user_ids' => [$viewerP1a['id'], $viewerP1b['id']]], $adminAuth['jar'], $adminAuth['csrf']);
    check('HTTP: SUPERADMIN assigns 2-member P1 team', $assignP1['status'] === 200, json_encode($assignP1['body']));
    $assignP2 = httpCall('POST', "{$base}/stock-opname/{$sessionId}/assign-team", ['role' => 'p2', 'user_ids' => [$viewerP2a['id']]], $adminAuth['jar'], $adminAuth['csrf']);
    check('HTTP: SUPERADMIN assigns P2 team', $assignP2['status'] === 200, json_encode($assignP2['body']));

    // 1. assigned VIEWER-role ACTIVE user can access the session
    $p1aGet = httpCall('GET', "{$base}/stock-opname/{$sessionId}", null, $p1aAuth['jar']);
    check('1. assigned VIEWER (no global STOCK_OPNAME_MANAGE) CAN GET the session (real HTTP 200)', $p1aGet['status'] === 200 && ($p1aGet['body']['data']['role'] ?? '') === 'p1', json_encode($p1aGet['body']));

    // 2. unassigned rejected
    $unassignedGet = httpCall('GET', "{$base}/stock-opname/{$sessionId}", null, $unassignedAuth['jar']);
    check('2. an unassigned active user is rejected (real HTTP 403)', $unassignedGet['status'] === 403, json_encode($unassignedGet['body']));

    // 4/5. same-team claim conflict / P1+P2 same SKU simultaneous
    $claimP1a = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p1', 'item_id' => $itemA], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('setup: P1a claims item A', $claimP1a['status'] === 200, json_encode($claimP1a['body']));
    $claimP1b = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p1', 'item_id' => $itemA], $p1bAuth['jar'], $p1bAuth['csrf']);
    check('4. same-team (P1b) claim on an already-claimed item is rejected over HTTP', $claimP1b['status'] !== 200, json_encode($claimP1b['body']));
    $claimP2a = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p2', 'item_id' => $itemA], $p2aAuth['jar'], $p2aAuth['csrf']);
    check('5. P1 and P2 CAN claim the SAME SKU simultaneously over HTTP', $claimP2a['status'] === 200, json_encode($claimP2a['body']));

    // 8. frozen unit conversion — snapshot survives a live master change
    $unitsBefore = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/units", null, $p1aAuth['jar']);
    $pcsFactorBefore = null;
    foreach (($unitsBefore['body']['data'] ?? []) as $u) { if ((int) $u['unit_id'] === $pcsUnitId) $pcsFactorBefore = (float) $u['conversion_to_base']; }
    UnitConversionService::openNewVersion($pdo, $itemA, $pcsUnitId, 777.0, date('Y-m-d H:i:s'), $adminUserId, 'live change mid-session');
    $unitsAfter = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/units", null, $p1aAuth['jar']);
    $pcsFactorAfter = null;
    foreach (($unitsAfter['body']['data'] ?? []) as $u) { if ((int) $u['unit_id'] === $pcsUnitId) $pcsFactorAfter = (float) $u['conversion_to_base']; }
    check('8. frozen unit snapshot unaffected by a live master conversion change (real HTTP)', $pcsFactorBefore !== null && $pcsFactorBefore === $pcsFactorAfter, "{$pcsFactorBefore} vs {$pcsFactorAfter}");

    // 9. foreign unit rejected
    $foreignUnitSubmit = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claimP1a['body']['data']['claim_token'],
        'conditions' => [
            'GOOD' => [['unit_id' => 9999999, 'qty' => 1]],
            'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        ],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('9. a foreign unit_id is rejected over HTTP (422)', $foreignUnitSubmit['status'] === 422, json_encode($foreignUnitSubmit['body']));

    // 13. Tambah Temuan no-write: claiming alone must not create a finding
    $myFindingsBefore = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/my-findings", null, $p1aAuth['jar']);
    check('13. claiming an item creates ZERO findings (Tambah Temuan / claim never writes a finding by itself)', count($myFindingsBefore['body']['data'] ?? []) === 0, json_encode($myFindingsBefore['body']));

    // 10/11/12. zero count, multi-unit finding, photo required per condition
    $goodOnlyZero = [
        'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
    ];
    $zeroSubmit = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claimP1a['body']['data']['claim_token'], 'conditions' => $goodOnlyZero,
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('10. first finding with physical=0 accepted over HTTP (COUNTED ZERO)', $zeroSubmit['status'] === 200, json_encode($zeroSubmit['body']));

    // Void that zero finding isn't available at counter level; instead
    // re-claim and submit a real multi-unit finding with a positive DAMAGED
    // that lacks a photo, to prove 12 (photo required).
    $claim2 = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p1', 'item_id' => $itemA], $p1aAuth['jar'], $p1aAuth['csrf']);
    $noPhotoSubmit = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claim2['body']['data']['claim_token'],
        'conditions' => [
            'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10], ['unit_id' => $pcsUnitId, 'qty' => 2]],
            'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 3]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        ],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('12. DAMAGED > 0 without a photo is rejected over HTTP (422)', $noPhotoSubmit['status'] === 422, json_encode($noPhotoSubmit['body']));

    $photoFile = makeFakePhotoFile();
    $photoUpload = httpUpload("{$base}/stock-opname/{$sessionId}/items/{$itemA}/photos", $photoFile, [
        'role' => 'p1', 'condition_type' => 'DAMAGED', 'claim_token' => $claim2['body']['data']['claim_token'],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('12b. photo upload over real multipart HTTP succeeds', $photoUpload['status'] === 200, json_encode($photoUpload['body']));

    // PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 1D): the
    // remove-photo route over real HTTP — upload a throwaway photo, remove
    // it, then prove naming its token in a finding is refused exactly like
    // an unnamed photo (it no longer exists to attach).
    $throwawayPhotoFile = makeFakePhotoFile();
    $throwawayPhotoUpload = httpUpload("{$base}/stock-opname/{$sessionId}/items/{$itemA}/photos", $throwawayPhotoFile, [
        'role' => 'p1', 'condition_type' => 'DAMAGED', 'claim_token' => $claim2['body']['data']['claim_token'],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('12c. throwaway photo upload succeeds (setup for remove test)', $throwawayPhotoUpload['status'] === 200, json_encode($throwawayPhotoUpload['body']));
    $removeCall = httpCall('POST', "{$base}/stock-opname/{$sessionId}/photos/{$throwawayPhotoUpload['body']['data']['photo_id']}/remove", ['token' => $throwawayPhotoUpload['body']['data']['token']], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('12d. POST /stock-opname/{id}/photos/{photoId}/remove succeeds over real HTTP', $removeCall['status'] === 200, json_encode($removeCall['body']));
    $removedPhotoSubmit = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claim2['body']['data']['claim_token'],
        'conditions' => [
            'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10]],
            'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 3]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        ],
        'photos' => ['DAMAGED' => [$throwawayPhotoUpload['body']['data']['token']], 'EXPIRED' => [], 'DEADSTOCK' => []],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('12e. naming a REMOVED photo token over HTTP is rejected (422)', $removedPhotoSubmit['status'] === 422, json_encode($removedPhotoSubmit['body']));

    // PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 1B): the
    // uploaded photo alone is not enough; its token must be named
    // explicitly here or the finding is refused exactly like test 12 above.
    $withPhotoSubmit = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claim2['body']['data']['claim_token'],
        'conditions' => [
            'GOOD' => [['unit_id' => $kgUnitId, 'qty' => 10], ['unit_id' => $pcsUnitId, 'qty' => 2]],
            'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 3]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]],
        ],
        'photos' => ['DAMAGED' => [$photoUpload['body']['data']['token']], 'EXPIRED' => [], 'DEADSTOCK' => []],
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('11. multi-unit GOOD finding (10 Kg + 2x5 Kg = 20) succeeds with an explicitly-named photo token', $withPhotoSubmit['status'] === 200, json_encode($withPhotoSubmit['body']));
    $myLineA = null;
    foreach (($withPhotoSubmit['body']['data']['lines'] ?? []) as $l) { if ($l['item_id'] === $itemA) $myLineA = $l; }
    check('11b. total computed correctly over HTTP', $myLineA !== null && abs($myLineA['my_qty_base'] - 20.0) < 0.0001, json_encode($myLineA));

    // 3. blindness — inspect the RAW response body text directly
    check('3. P1 raw HTTP response never contains "system_qty" text', !str_contains($p1aGet['raw'], 'system_qty'));
    check('3b. P1 raw HTTP response never contains "mismatch"/"variance" text', !str_contains(strtolower($withPhotoSubmit['raw']), 'mismatch') && !str_contains(strtolower($withPhotoSubmit['raw']), 'variance'));
    check('3c. P1 raw HTTP response never contains a "p2_" prefixed key', !str_contains($withPhotoSubmit['raw'], '"p2_'));
    $p2Get = httpCall('GET', "{$base}/stock-opname/{$sessionId}", null, $p2aAuth['jar']);
    check('3d. P2 raw HTTP response never contains a "p1_" prefixed key', !str_contains($p2Get['raw'], '"p1_'));

    // 6/7. stale token / expired claim rejected over HTTP
    $claim3 = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p1', 'item_id' => $itemA], $p1aAuth['jar'], $p1aAuth['csrf']);
    $wrongToken = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => 'not-the-real-token', 'conditions' => $goodOnlyZero,
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('6. a wrong claim_token is rejected over HTTP (409 CLAIM_LOST)', $wrongToken['status'] === 409, json_encode($wrongToken['body']));

    $pdo->prepare("UPDATE stock_opname_lines SET p1_claimed_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE session_id = :sid AND item_id = :item")
        ->execute(['sid' => $sessionId, 'item' => $itemA]);
    $expiredClaim = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claim3['body']['data']['claim_token'], 'conditions' => $goodOnlyZero,
    ], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('7. an expired claim lease is rejected over HTTP (409 CLAIM_LOST)', $expiredClaim['status'] === 409, json_encode($expiredClaim['body']));

    // 14/15. exactly-one-finding-per-save + duplicate submit (same token) never creates a duplicate
    $findingsSoFar = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/my-findings", null, $p1aAuth['jar']);
    $countBeforeDup = count($findingsSoFar['body']['data'] ?? []);
    $claim4 = httpCall('POST', "{$base}/stock-opname/{$sessionId}/claim", ['role' => 'p1', 'item_id' => $itemA], $p1aAuth['jar'], $p1aAuth['csrf']);
    $singlePayload = [
        'role' => 'p1', 'item_id' => $itemA, 'claim_token' => $claim4['body']['data']['claim_token'],
        'conditions' => ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 4]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]],
    ];
    $firstSave = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", $singlePayload, $p1aAuth['jar'], $p1aAuth['csrf']);
    $findingsAfterOne = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/my-findings", null, $p1aAuth['jar']);
    check('14. Simpan creates exactly ONE new finding', count($findingsAfterOne['body']['data'] ?? []) === $countBeforeDup + 1, (string) count($findingsAfterOne['body']['data'] ?? []));
    $duplicateRetry = httpCall('POST', "{$base}/stock-opname/{$sessionId}/findings", $singlePayload, $p1aAuth['jar'], $p1aAuth['csrf']);
    check('15. retrying the exact same request (same consumed claim_token) is rejected, not duplicated', $duplicateRetry['status'] === 409, json_encode($duplicateRetry['body']));
    $findingsAfterDup = httpCall('GET', "{$base}/stock-opname/{$sessionId}/items/{$itemA}/my-findings", null, $p1aAuth['jar']);
    check('15b. finding count unchanged after the rejected duplicate retry', count($findingsAfterDup['body']['data'] ?? []) === count($findingsAfterOne['body']['data'] ?? []));

    // 16. PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 2):
    // FINDINGS_V1 has no approved reconciliation/final-result workflow yet
    // (that is Checkpoint B's entire scope), so a session started as
    // FINDINGS_V1 (the default) must be HARD-BLOCKED from finalize/post,
    // over the real HTTP route, even with a full P1==P2 MATCH already
    // recorded — this replaces the OLD assertion here (which used to prove
    // finalize succeeded); Checkpoint A must now prove the opposite.
    $itemC = makeItemWithUnits($pdo, 'V21411H-C', $kgUnitId, null, 1.0);
    postOpeningIn($pdo, $itemC, $kgUnitId, $whId2, 15, 900, $adminUserId);
    $startC = httpCall('POST', "{$base}/stock-opname", ['warehouse_id' => $whId2, 'item_ids' => [$itemC]], $adminAuth['jar'], $adminAuth['csrf']);
    $sessionC = $startC['body']['data']['session_id'];
    httpCall('POST', "{$base}/stock-opname/{$sessionC}/assign-team", ['role' => 'p1', 'user_ids' => [$viewerP1a['id']]], $adminAuth['jar'], $adminAuth['csrf']);
    httpCall('POST', "{$base}/stock-opname/{$sessionC}/assign-team", ['role' => 'p2', 'user_ids' => [$viewerP2a['id']]], $adminAuth['jar'], $adminAuth['csrf']);
    $claimCp1 = httpCall('POST', "{$base}/stock-opname/{$sessionC}/claim", ['role' => 'p1', 'item_id' => $itemC], $p1aAuth['jar'], $p1aAuth['csrf']);
    $cGood = ['GOOD' => [['unit_id' => $kgUnitId, 'qty' => 15]], 'DAMAGED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $kgUnitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $kgUnitId, 'qty' => 0]]];
    httpCall('POST', "{$base}/stock-opname/{$sessionC}/findings", array_merge(['role' => 'p1', 'item_id' => $itemC, 'claim_token' => $claimCp1['body']['data']['claim_token']], ['conditions' => $cGood]), $p1aAuth['jar'], $p1aAuth['csrf']);
    $claimCp2 = httpCall('POST', "{$base}/stock-opname/{$sessionC}/claim", ['role' => 'p2', 'item_id' => $itemC], $p2aAuth['jar'], $p2aAuth['csrf']);
    httpCall('POST', "{$base}/stock-opname/{$sessionC}/findings", array_merge(['role' => 'p2', 'item_id' => $itemC, 'claim_token' => $claimCp2['body']['data']['claim_token']], ['conditions' => $cGood]), $p2aAuth['jar'], $p2aAuth['csrf']);
    $finalizeC = httpCall('POST', "{$base}/stock-opname/{$sessionC}/finalize", [], $adminAuth['jar'], $adminAuth['csrf']);
    check('16a. POST /stock-opname/{id}/finalize is HARD BLOCKED for a FINDINGS_V1 session even with P1==P2 MATCH (409 FINDINGS_V1_CHECKPOINT_B_REQUIRED)', $finalizeC['status'] === 409 && ($finalizeC['body']['error']['code'] ?? $finalizeC['body']['code'] ?? '') === 'FINDINGS_V1_CHECKPOINT_B_REQUIRED', json_encode($finalizeC['body']));
    $postC = httpCall('POST', "{$base}/stock-opname/{$sessionC}/post", [], $adminAuth['jar'], $adminAuth['csrf']);
    check('16b. POST /stock-opname/{id}/post is HARD BLOCKED for a FINDINGS_V1 session, independently of finalize (409 FINDINGS_V1_CHECKPOINT_B_REQUIRED)', $postC['status'] === 409 && ($postC['body']['error']['code'] ?? $postC['body']['code'] ?? '') === 'FINDINGS_V1_CHECKPOINT_B_REQUIRED', json_encode($postC['body']));
    // The session must remain OPEN — the block must happen BEFORE any
    // status transition, not after a silent partial finalize.
    $sessionCAfter = httpCall('GET', "{$base}/stock-opname/{$sessionC}", null, $adminAuth['jar']);
    check('16c. the blocked session\'s status is still OPEN, never silently advanced', ($sessionCAfter['body']['data']['status'] ?? '') === 'OPEN', json_encode($sessionCAfter['body']['data'] ?? null));
    // A still-OPEN session must still allow a normal claim (not accidentally locked out by the blocked finalize attempt).
    $claimAfterBlockedFinalize = httpCall('POST', "{$base}/stock-opname/{$sessionC}/claim", ['role' => 'p1', 'item_id' => $itemC], $p1aAuth['jar'], $p1aAuth['csrf']);
    check('16d. the session remains otherwise usable (a claim still succeeds) after the blocked finalize/post attempts', $claimAfterBlockedFinalize['status'] === 200, json_encode($claimAfterBlockedFinalize['body']));

    // 16e. LEGACY_DUAL_COUNT finalize/post still work exactly as before, over real HTTP.
    $itemD = makeItemWithUnits($pdo, 'V21411H-D', $kgUnitId, null, 1.0);
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)")->execute(['c' => uid('V21411HD'), 'n' => 'V2.14.11.1 HTTP Legacy WH']);
    $whIdD = (int) $pdo->lastInsertId();
    postOpeningIn($pdo, $itemD, $kgUnitId, $whIdD, 12, 850, $adminUserId);
    $startD = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whIdD, $adminUserId, [$itemD], 'LEGACY_DUAL_COUNT'));
    Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $startD, ['p1_user_id' => $viewerP1a['id'], 'p2_user_id' => $viewerP2a['id']], $adminUserId));
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $startD, 'p1', $itemD, 12.0, $viewerP1a['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $startD, 'p2', $itemD, 12.0, $viewerP2a['id']));
    $finalizeD = httpCall('POST', "{$base}/stock-opname/{$startD}/finalize", [], $adminAuth['jar'], $adminAuth['csrf']);
    check('16f. LEGACY_DUAL_COUNT finalize still succeeds over HTTP (backward compatibility unchanged)', $finalizeD['status'] === 200, json_encode($finalizeD['body']));
    $postD = httpCall('POST', "{$base}/stock-opname/{$startD}/post", [], $adminAuth['jar'], $adminAuth['csrf']);
    check('16g. LEGACY_DUAL_COUNT post still succeeds over HTTP (backward compatibility unchanged)', $postD['status'] === 200, json_encode($postD['body']));

    // 17. inventory unchanged by the BLOCKED FINDINGS_V1 attempts specifically
    // (the legacy D session above legitimately posts and IS expected to add
    // exactly one adjustment-driven batch if variance != 0 — qty matches
    // exactly here so no adjustment batch is created either way; this
    // check isolates the invariant under audit: nothing FINDINGS_V1-related
    // ever reaches StockAdjustmentService/FIFO).
    $invAfter = $pdo->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(qty_base*unit_cost_base),0) AS val FROM inventory_batches')->fetch();
    // $invBefore was captured AFTER itemA/itemB's fixture opening postings
    // (2 batches already included) but BEFORE the server started; itemC's
    // and itemD's opening postings (2 more fixture batches) happened
    // mid-test. The invariant under test is that everything AFTER that —
    // every httpCall()-driven Stock Opname action (claims/findings/photos/
    // the two BLOCKED finalize+post attempts on the FINDINGS_V1 session,
    // and the legitimate LEGACY_DUAL_COUNT finalize+post) — added ZERO
    // batches beyond those 2 known fixture postings (P1==P2 exact match on
    // both C and D means no variance adjustment batch either).
    $expectedBatches = (int) $invBefore['cnt'] + 2; // itemC's + itemD's fixture opening postings
    check('17. inventory_batches count only reflects the 2 mid-test fixture OPENING postings — no Stock Opname action (blocked or legitimate) created an unexpected batch', (int) $invAfter['cnt'] === $expectedBatches, "{$invAfter['cnt']} vs expected {$expectedBatches}");

} finally {
    if (getenv('V21411_DEBUG_STDERR')) {
        fwrite(STDERR, "\n--- php -S stderr ---\n" . stream_get_contents($pipes[2]) . "\n");
    }
    proc_terminate($process);
    proc_close($process);
}

echo "\n==============================\n";
$total = count($results);
$passed = count(array_filter($results));
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
if ($passed !== $total) {
    exit(1);
}
