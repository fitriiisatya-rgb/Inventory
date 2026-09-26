<?php
declare(strict_types=1);

/**
 * PHASE V2.14.3 — dynamic user permission hydration hotfix test.
 *
 * Proves POST /auth/login and GET /auth/me both return a `permissions`
 * array read FRESH from role_permissions/roles/permissions on every call
 * (AuthService::permissionsForRole()) — never cached in $_SESSION — so a
 * grant or revoke made directly in the DB is reflected on the very next
 * /auth/me call without a new login, and so the frontend's
 * Auth.applyRoleVisibility() (which now reads currentUser.permissions
 * instead of a hard-coded map) can never drift out of sync with the
 * backend's own inv_require_permission()/AuthService::hasPermission()
 * authority. Also proves backend enforcement itself is completely
 * unchanged, and (via a real headless-browser check) that the Warehouse
 * Cutover sidebar link is actually shown/hidden accordingly in the UI.
 *
 * Usage: php tests/inventory_v2_14_3_permission_hydration_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\AuthService;

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

check('0. WAREHOUSE_CUTOVER_MANAGE permission exists (from the V2.14 migration/schema)', (bool) $pdo->query("SELECT COUNT(*) FROM permissions WHERE code='WAREHOUSE_CUTOVER_MANAGE'")->fetchColumn());

// ============================================================
// 0. AuthService::permissionsForRole() direct (no HTTP) sanity check.
// ============================================================
echo "== 0. AuthService::permissionsForRole() direct ==\n";
$superPerms = AuthService::permissionsForRole($pdo, 'SUPERADMIN');
$adminPerms = AuthService::permissionsForRole($pdo, 'ADMIN');
$stockPerms = AuthService::permissionsForRole($pdo, 'STOCK');
$divisionPerms = AuthService::permissionsForRole($pdo, 'DIVISION');
$viewerPerms = AuthService::permissionsForRole($pdo, 'VIEWER');
check('0. SUPERADMIN permissionsForRole() includes WAREHOUSE_CUTOVER_MANAGE', in_array('WAREHOUSE_CUTOVER_MANAGE', $superPerms, true));
check('0. ADMIN permissionsForRole() includes WAREHOUSE_CUTOVER_MANAGE', in_array('WAREHOUSE_CUTOVER_MANAGE', $adminPerms, true));
check('0. STOCK permissionsForRole() does NOT include WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $stockPerms, true));
check('0. DIVISION permissionsForRole() does NOT include WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $divisionPerms, true));
check('0. VIEWER permissionsForRole() does NOT include WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $viewerPerms, true));
check('0. permissionsForRole() returns unique codes (no duplicates)', count($superPerms) === count(array_unique($superPerms)));

// ============================================================
// HTTP-level: real POST /auth/login + GET /auth/me
// ============================================================
$port = 8950 + random_int(1, 300);
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

function makeRoleUser(PDO $pdo, string $roleCode, string $tag): array
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $u = "v2143-{$tag}-" . bin2hex(random_bytes(4));
    $pass = 'V2143Pass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId]);
    return ['username' => $u, 'password' => $pass];
}

$creds = [
    'superadmin' => makeRoleUser($pdo, 'SUPERADMIN', 'super'),
    'admin' => makeRoleUser($pdo, 'ADMIN', 'admin'),
    'stock' => makeRoleUser($pdo, 'STOCK', 'stock'),
    'division' => makeRoleUser($pdo, 'DIVISION', 'division'),
    'viewer' => makeRoleUser($pdo, 'VIEWER', 'viewer'),
];

try {
    echo "\n== 1-5. GET /auth/me permission list per role ==\n";
    $meByRole = [];
    foreach ($creds as $role => $cred) {
        $jar = tempnam(sys_get_temp_dir(), 'cookie_');
        $login = httpCall('POST', "{$base}/auth/login", ['username' => $cred['username'], 'password' => $cred['password']], $jar);
        if ($login['status'] !== 200) { check("HTTP login succeeds for {$role}", false, json_encode($login['body'])); continue; }
        $me = httpCall('GET', "{$base}/auth/me", null, $jar, $login['body']['data']['csrf_token'] ?? null);
        $meByRole[$role] = ['login' => $login, 'me' => $me, 'jar' => $jar, 'csrf' => $login['body']['data']['csrf_token'] ?? null];
    }

    $superMePerms = $meByRole['superadmin']['me']['body']['data']['permissions'] ?? [];
    check('1. SUPERADMIN GET /auth/me contains WAREHOUSE_CUTOVER_MANAGE', in_array('WAREHOUSE_CUTOVER_MANAGE', $superMePerms, true), json_encode($superMePerms));

    $adminMePerms = $meByRole['admin']['me']['body']['data']['permissions'] ?? [];
    check('2. ADMIN GET /auth/me contains WAREHOUSE_CUTOVER_MANAGE', in_array('WAREHOUSE_CUTOVER_MANAGE', $adminMePerms, true), json_encode($adminMePerms));

    $stockMePerms = $meByRole['stock']['me']['body']['data']['permissions'] ?? [];
    check('3. STOCK GET /auth/me does NOT contain WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $stockMePerms, true), json_encode($stockMePerms));

    $divisionMePerms = $meByRole['division']['me']['body']['data']['permissions'] ?? [];
    check('4. DIVISION GET /auth/me does NOT contain WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $divisionMePerms, true), json_encode($divisionMePerms));

    $viewerMePerms = $meByRole['viewer']['me']['body']['data']['permissions'] ?? [];
    check('5. VIEWER GET /auth/me does NOT contain WAREHOUSE_CUTOVER_MANAGE', !in_array('WAREHOUSE_CUTOVER_MANAGE', $viewerMePerms, true), json_encode($viewerMePerms));

    echo "\n== 6. login response permission list matches auth/me ==\n";
    $superLoginPerms = $meByRole['superadmin']['login']['body']['data']['permissions'] ?? [];
    sort($superLoginPerms);
    $superMeSorted = $superMePerms;
    sort($superMeSorted);
    check('6. POST /auth/login permissions == GET /auth/me permissions (same set) for SUPERADMIN', $superLoginPerms === $superMeSorted, json_encode(['login' => $superLoginPerms, 'me' => $superMeSorted]));

    echo "\n== 7/8. DB grant/revoke reflected on next auth/me, same session, no new login ==\n";
    $viewerJar = $meByRole['viewer']['jar'];
    $viewerCsrf = $meByRole['viewer']['csrf'];
    $viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
    $wcmPermId = (int) $pdo->query("SELECT id FROM permissions WHERE code='WAREHOUSE_CUTOVER_MANAGE'")->fetchColumn();

    $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)')->execute(['r' => $viewerRoleId, 'p' => $wcmPermId]);
    $meAfterGrant = httpCall('GET', "{$base}/auth/me", null, $viewerJar, $viewerCsrf);
    $permsAfterGrant = $meAfterGrant['body']['data']['permissions'] ?? [];
    check('7. Granting VIEWER the permission in the DB makes it appear on the VERY NEXT auth/me call (same session, no new login)', in_array('WAREHOUSE_CUTOVER_MANAGE', $permsAfterGrant, true), json_encode($permsAfterGrant));

    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :r AND permission_id = :p')->execute(['r' => $viewerRoleId, 'p' => $wcmPermId]);
    $meAfterRevoke = httpCall('GET', "{$base}/auth/me", null, $viewerJar, $viewerCsrf);
    $permsAfterRevoke = $meAfterRevoke['body']['data']['permissions'] ?? [];
    check('8. Revoking it again in the DB makes it disappear on the VERY NEXT auth/me call (same session)', !in_array('WAREHOUSE_CUTOVER_MANAGE', $permsAfterRevoke, true), json_encode($permsAfterRevoke));

    echo "\n== 9. Backend enforcement unchanged (inv_require_permission still the real gate) ==\n";
    $stockJar = $meByRole['stock']['jar'];
    $stockCsrf = $meByRole['stock']['csrf'];
    $whId = (int) $pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn();
    if ($whId === 0) {
        $pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('V2143WH', 'V2143 Test Warehouse', 'MAIN', 1, 0)");
        $whId = (int) $pdo->lastInsertId();
    }
    $forbidden = httpCall('POST', "{$base}/warehouse-cutovers", ['warehouse_id' => $whId, 'source_name' => 'v2143', 'opening_as_of' => '2026-09-27'], $stockJar, $stockCsrf);
    check('9. STOCK (no WAREHOUSE_CUTOVER_MANAGE) is still rejected by the real backend permission check', $forbidden['status'] === 403 && ($forbidden['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($forbidden['body']));

    $superJar = $meByRole['superadmin']['jar'];
    $superCsrf = $meByRole['superadmin']['csrf'];
    $allowed = httpCall('POST', "{$base}/warehouse-cutovers", ['warehouse_id' => $whId, 'source_name' => 'v2143', 'opening_as_of' => '2026-09-27'], $superJar, $superCsrf);
    check('9b. SUPERADMIN (has WAREHOUSE_CUTOVER_MANAGE) is still allowed by the real backend permission check', $allowed['status'] === 201, json_encode($allowed['body']));

    // ============================================================
    // 10/11. Real headless-browser proof of sidebar menu visibility.
    // Skipped (not failed) only if this environment has no `node` binary
    // at all — every other precondition (playwright, chromium) is
    // expected to be present, same as any other browser-smoke check in
    // this project, so a missing one there is a real failure, not a skip.
    // ============================================================
    echo "\n== 10/11. Real browser: sidebar menu visibility ==\n";
    $nodeAvailable = trim((string) shell_exec('command -v node')) !== '';
    if (!$nodeAvailable) {
        echo "SKIP - node binary not found in this environment; skipping browser-level menu visibility check (items 10/11)\n";
    } else {
        $credsFile = tempnam(sys_get_temp_dir(), 'v2143creds_') . '.json';
        file_put_contents($credsFile, json_encode($creds));
        $scriptPath = __DIR__ . '/browser/inventory_v2_14_3_menu_visibility_check.js';
        $globalNodeModules = trim((string) shell_exec('npm root -g 2>/dev/null'));
        $env = array_merge(getenv(), [
            'NODE_PATH' => $globalNodeModules,
            'PLAYWRIGHT_CHROMIUM_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') !== false ? '/opt/pw-browsers/chromium' : '',
        ]);
        $envPrefix = '';
        foreach (['NODE_PATH', 'PLAYWRIGHT_CHROMIUM_PATH'] as $k) {
            $envPrefix .= $k . '=' . escapeshellarg($env[$k]) . ' ';
        }
        $cmd = sprintf('%snode %s %s %s 2>&1', $envPrefix, escapeshellarg($scriptPath), escapeshellarg("http://127.0.0.1:{$port}"), escapeshellarg($credsFile));
        $browserOutput = shell_exec($cmd);
        echo $browserOutput . "\n";
        foreach (explode("\n", (string) $browserOutput) as $line) {
            if (str_starts_with($line, 'PASS - ') || str_starts_with($line, 'FAIL - ')) {
                check(trim(substr($line, 7)), str_starts_with($line, 'PASS - '));
            }
        }
        check('Browser check produced a summary line (script actually ran, not silently skipped)', str_contains((string) $browserOutput, 'BROWSER SUMMARY'), $browserOutput === null ? 'null output' : '');
    }
} finally {
    proc_terminate($process);
    usleep(200_000);
    proc_close($process);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
