<?php
declare(strict_types=1);

/**
 * PHASE V2.14.4 — Warehouse Cutover sidebar link relocation hotfix test.
 *
 * public/index.html already contained a Warehouse Cutover sidebar link
 * (under Master Data, labeled "Karang Tengah Cutover") since V2.14 — this
 * phase MOVES it (not duplicates it) into the Audit & Control group,
 * alongside Audit Log/Trace Center, relabeled "Warehouse Cutover", using
 * the exact same data-tab/data-require-permission mechanism. This test
 * proves: exactly one link, exactly one tab-content div, exactly one JS
 * include, correct permission gating, a real click renders
 * WarehouseCutover.render(), and Audit Log/Trace Center are unaffected.
 *
 * Usage: php tests/inventory_v2_14_4_menu_relocation_test.php
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';

use App\Services\Database;

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

// ============================================================
// Static source checks (no server needed).
// ============================================================
echo "== Static source checks ==\n";
$html = file_get_contents(__DIR__ . '/../public/index.html');
check('1. Exactly ONE sidebar Warehouse Cutover link exists', substr_count($html, 'data-tab="warehouse-cutover"') === 1, (string) substr_count($html, 'data-tab="warehouse-cutover"'));
check('2. Exactly ONE tab-content div for warehouse-cutover exists', substr_count($html, 'id="tab-warehouse-cutover"') === 1, (string) substr_count($html, 'id="tab-warehouse-cutover"'));
check('3. Exactly ONE warehouse-cutover.js script include exists', substr_count($html, 'warehouse-cutover.js') === 1, (string) substr_count($html, 'warehouse-cutover.js'));
check('4. The sidebar link carries data-require-permission="WAREHOUSE_CUTOVER_MANAGE"', (bool) preg_match('/data-tab="warehouse-cutover"\s+data-require-permission="WAREHOUSE_CUTOVER_MANAGE"/', $html));
check('The link is inside the Audit & Control group (after Trace Center, per the requested placement)', (bool) preg_match('/data-tab="trace-center"[^\n]*\n\s*<a class="sidebar-link" data-tab="warehouse-cutover"/', $html));
check('The link label is "Warehouse Cutover" (not the old "Karang Tengah Cutover")', str_contains($html, 'Warehouse Cutover</a>') && !str_contains($html, 'Karang Tengah Cutover</a>'));
check('8a. Audit Log link is untouched', substr_count($html, 'data-tab="audit" data-require-permission="AUDIT_LOG_VIEW"') === 1);
check('8b. Trace Center link is untouched', substr_count($html, 'data-tab="trace-center" data-require-permission="AUDIT_LOG_VIEW"') === 1);

// ============================================================
// Real headless-browser proof (visibility per role + click renders).
// ============================================================
echo "\n== Real browser: menu relocation + click-through ==\n";
$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();

function makeRoleUser(PDO $pdo, string $roleCode, string $tag): array
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $u = "v2144-{$tag}-" . bin2hex(random_bytes(4));
    $pass = 'V2144Pass123!';
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

$docRoot = __DIR__ . '/../public';

function startPhpServer(string $docRoot): array
{
    $port = 8960 + random_int(1, 3000);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot)), $descriptors, $pipes, dirname($docRoot));
    if (!is_resource($process)) { fwrite(STDERR, "Failed to start php -S\n"); exit(1); }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000);
        $ch = curl_init("{$base}/api/auth/me");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($res !== false && $err === 0) { $ready = true; break; }
    }
    if (!$ready) { fwrite(STDERR, "Server did not become ready\n"); proc_terminate($process); exit(1); }
    return [$process, $base];
}

$nodeAvailable = trim((string) shell_exec('command -v node')) !== '';
if (!$nodeAvailable) {
    echo "\n== Real browser: menu relocation + click-through ==\n";
    echo "SKIP - node binary not found in this environment; skipping browser-level check\n";
} else {
    echo "\n== Real browser: menu relocation + click-through ==\n";
    $credsFile = tempnam(sys_get_temp_dir(), 'v2144creds_') . '.json';
    file_put_contents($credsFile, json_encode($creds));
    $scriptPath = __DIR__ . '/browser/inventory_v2_14_4_menu_relocation_check.js';
    $globalNodeModules = trim((string) shell_exec('npm root -g 2>/dev/null'));
    $envPrefix = 'NODE_PATH=' . escapeshellarg($globalNodeModules) . ' PLAYWRIGHT_CHROMIUM_PATH=' . escapeshellarg(getenv('PLAYWRIGHT_BROWSERS_PATH') !== false ? '/opt/pw-browsers/chromium' : '') . ' ';
    // Invoked ONCE PER ROLE — a fresh `node`/Chromium process AND a fresh
    // `php -S` dev server, each on its own port, per role. This
    // environment's single-threaded php -S dev server was empirically
    // confirmed (by reordering which role ran last, across several
    // reruns) to degrade after roughly 4 sequential full page loads
    // regardless of which client process talks to it — the failure always
    // followed whichever check happened to run 5th against the SAME
    // shared server process, never a specific role. Giving every role its
    // own server process removes that shared, degrading resource entirely.
    $summaryLineCount = 0;
    foreach (['superadmin', 'admin', 'stock', 'division', 'viewer'] as $role) {
        [$roleProcess, $roleBase] = startPhpServer($docRoot);
        try {
            $cmd = sprintf('%snode %s %s %s %s 2>&1', $envPrefix, escapeshellarg($scriptPath), escapeshellarg($roleBase), escapeshellarg($credsFile), escapeshellarg($role));
            $browserOutput = shell_exec($cmd);
            echo $browserOutput . "\n";
            foreach (explode("\n", (string) $browserOutput) as $line) {
                if (str_starts_with($line, 'PASS - ') || str_starts_with($line, 'FAIL - ')) {
                    check(trim(substr($line, 7)), str_starts_with($line, 'PASS - '));
                }
            }
            if (str_contains((string) $browserOutput, 'BROWSER SUMMARY')) { $summaryLineCount++; }
        } finally {
            proc_terminate($roleProcess);
            usleep(200_000);
            proc_close($roleProcess);
        }
    }
    check('Every one of the 5 per-role browser checks produced a summary line (script actually ran, not silently skipped)', $summaryLineCount === 5, (string) $summaryLineCount);
}

echo "\n=== SUMMARY: " . count(array_filter($results)) . "/" . count($results) . " PASS ===\n";
exit(in_array(false, $results, true) ? 1 : 0);
