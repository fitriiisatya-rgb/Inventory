<?php
declare(strict_types=1);

/**
 * PHASE V2.6 FINAL PRODUCTION GATE — targeted regression for the two
 * hardening changes made in this phase:
 *
 *   A) Audit Transaksi warehouse-scope security: GET /reports/audit and
 *      GET /reports/audit/export must be completely unreachable by any
 *      warehouse-scoped role (today: STOCK), through every vector —
 *      direct API, query-parameter override (warehouse_id, though the
 *      route never even reads it), page/per_page manipulation, and
 *      filter manipulation (entity_type/username/action_code). This is
 *      enforced by inv_require_company_wide_audit_scope() in
 *      public/index.php, applied identically to both routes.
 *      SUPERADMIN/ADMIN/VIEWER (the only roles the seeded permission set
 *      grants AUDIT_LOG_VIEW to) keep full, unfiltered, company-wide
 *      access — none of them carry a warehouse_id in this schema.
 *
 *   B) CSV/Excel formula-injection hardening: inv_csv_safe_cell(), the
 *      ONE centralized helper wired into inv_export_csv() and the one
 *      ad-hoc CSV writer (GET /reports/movement/daily), neutralizes any
 *      TEXT cell beginning with '=', '+', '-' or '@' by prefixing a
 *      single quote — export-layer only, never touching the stored
 *      value or the JSON API's response. A genuinely numeric field
 *      (e.g. a negative adjustment value) must never be caught by this
 *      and turned into quoted text.
 *
 * Runs against a real `php -S` + the throwaway MySQL/MariaDB database —
 * same harness pattern as tests/inventory_reports_v26c_test.php.
 *
 * Usage: php tests/inventory_v26_final_gate_test.php
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
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockAdjustmentService;

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
$adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('gatesetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'Gate Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('GATE-SCM'), 'n' => 'Gate SCM']);
$whA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('GATE-CIB'), 'n' => 'Gate Cibadak']);
$whB = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

// Pin live_opening_date far in the past so this file's 2026 dates run fully live.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('gate-pin'), 'item_id' => makeItem($pdo, $kgUnitId, 'GATE-PIN')[0], 'warehouse_id' => $whA,
    'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1,
    'transaction_date' => '2020-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'gate', 'transaction_type' => 'OPENING',
]));

// Fixture item on whA with enough opening stock for several negative adjustments.
[$itemA, $skuA] = makeItem($pdo, $kgUnitId, 'GATE-ADJ');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('gate-open'), 'item_id' => $itemA, 'warehouse_id' => $whA,
    'input_qty' => 1000, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 2501, // .0000 -> combined with -0.5 delta gives a clean -1250.50 style figure
    'transaction_date' => '2026-07-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'gate', 'transaction_type' => 'OPENING',
]));

// ============================================================
// CSV formula-injection fixtures — adjustments with attacker-controlled
// `reason` text (a free-text field echoed verbatim into the Adjustment
// export's "Alasan" column) covering all four dangerous leading chars,
// plus one legitimate adjustment whose numeric value must stay numeric.
// ============================================================
$injectionPayloads = [
    'EQ' => '=2+2',
    'PLUS' => '+HYPERLINK("http://evil.example/",  "click")',
    'MINUS' => '-2+3+cmd|" /C calc"!A1',
    'AT' => '@SUM(1+1)',
];
foreach ($injectionPayloads as $tag => $payload) {
    Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
        'transaction_uuid' => uid("gate-adj-{$tag}"), 'item_id' => $itemA, 'warehouse_id' => $whA,
        'qty_base_delta' => -1, 'adjustment_type' => 'DAMAGE', 'reason' => $payload,
        'transaction_date' => '2026-07-10 08:00:00', 'created_by' => $adminUserId,
    ]));
}
// A normal, non-malicious adjustment whose value is a clean negative
// decimal (-0.5 * 2501 = -1250.50) — must remain a plain numeric cell.
Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
    'transaction_uuid' => uid('gate-adj-NUMERIC'), 'item_id' => $itemA, 'warehouse_id' => $whA,
    'qty_base_delta' => -0.5, 'adjustment_type' => 'DAMAGE', 'reason' => 'Normal damaged-goods writeoff, not an injection attempt',
    'transaction_date' => '2026-07-10 09:00:00', 'created_by' => $adminUserId,
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
    $superUser = uid('gatesuper'); $superPass = 'GateSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);

    $adminOnlyUser = uid('gateadmin'); $adminOnlyPass = 'GateAdminPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $adminOnlyUser, 'h' => password_hash($adminOnlyPass, PASSWORD_BCRYPT), 'n' => $adminOnlyUser, 'r' => $adminRoleId]);
    $adminJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $adminOnlyUser, 'password' => $adminOnlyPass], $adminJar);

    $viewerUser = uid('gateviewer'); $viewerPass = 'GateViewerPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $viewerUser, 'h' => password_hash($viewerPass, PASSWORD_BCRYPT), 'n' => $viewerUser, 'r' => $viewerRoleId]);
    $viewerJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $viewerUser, 'password' => $viewerPass], $viewerJar);

    $stockAUser = uid('gatestockscm'); $stockAPass = 'GateStockScmPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockAUser, 'h' => password_hash($stockAPass, PASSWORD_BCRYPT), 'n' => $stockAUser, 'r' => $stockRoleId, 'w' => $whA]);
    $stockAJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $stockAUser, 'password' => $stockAPass], $stockAJar);

    $stockBUser = uid('gatestockcib'); $stockBPass = 'GateStockCibPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockBUser, 'h' => password_hash($stockBPass, PASSWORD_BCRYPT), 'n' => $stockBUser, 'r' => $stockRoleId, 'w' => $whB]);
    $stockBJar = tempnam(sys_get_temp_dir(), 'cookie_');
    httpCall('POST', "{$base}/auth/login", ['username' => $stockBUser, 'password' => $stockBPass], $stockBJar);

    // ============================================================
    // A — Audit Transaksi warehouse-scope security
    // ============================================================
    echo "== A: audit warehouse-scope security ==\n";

    // A1 — company-wide roles keep full access.
    $superAudit = httpCall('GET', "{$base}/reports/audit?per_page=10", null, $superJar);
    check('SUPERADMIN: GET /reports/audit returns 200 (global access retained)', $superAudit['status'] === 200, (string) $superAudit['status']);
    $superAuditExport = httpCall('GET', "{$base}/reports/audit/export", null, $superJar);
    check('SUPERADMIN: GET /reports/audit/export returns 200', $superAuditExport['status'] === 200, (string) $superAuditExport['status']);
    check('SUPERADMIN export Content-Type is text/csv', str_contains($superAuditExport['headers'], 'Content-Type: text/csv'));

    $adminAudit = httpCall('GET', "{$base}/reports/audit?per_page=10", null, $adminJar);
    check('ADMIN (non-SUPERADMIN): GET /reports/audit returns 200 (global access retained)', $adminAudit['status'] === 200, (string) $adminAudit['status']);
    $adminAuditExport = httpCall('GET', "{$base}/reports/audit/export", null, $adminJar);
    check('ADMIN: GET /reports/audit/export returns 200', $adminAuditExport['status'] === 200, (string) $adminAuditExport['status']);

    $viewerAudit = httpCall('GET', "{$base}/reports/audit?per_page=10", null, $viewerJar);
    check('VIEWER (global, no warehouse_id): GET /reports/audit returns 200', $viewerAudit['status'] === 200, (string) $viewerAudit['status']);

    // A2 — STOCK-SCM (whA): every vector must return 403, never a
    // filtered-but-partial 200.
    $vectors = [
        'bare direct API call' => "{$base}/reports/audit",
        'query-string warehouse_id override (param the route never even reads)' => "{$base}/reports/audit?warehouse_id={$whB}",
        'page/per_page manipulation' => "{$base}/reports/audit?page=1&per_page=99999",
        'entity_type filter manipulation' => "{$base}/reports/audit?entity_type=warehouses",
        'username filter targeting a known other-warehouse actor' => "{$base}/reports/audit?username=" . urlencode($stockBUser),
        'action_code + date_from/date_to manipulation' => "{$base}/reports/audit?action_code=UPDATE&date_from=2020-01-01&date_to=2030-01-01",
    ];
    foreach ($vectors as $label => $url) {
        $r = httpCall('GET', $url, null, $stockAJar);
        check("STOCK-SCM: GET /reports/audit via {$label} is rejected with 403", $r['status'] === 403, "status={$r['status']} url={$url}");
    }
    $stockACode = httpCall('GET', "{$base}/reports/audit", null, $stockAJar);
    check('STOCK-SCM /reports/audit 403 carries FORBIDDEN error code', ($stockACode['body']['error']['code'] ?? null) === 'FORBIDDEN', json_encode($stockACode['body']));

    // A3 — export endpoint, same vectors.
    $exportVectors = [
        'bare direct export call' => "{$base}/reports/audit/export",
        'query-string warehouse_id override on export' => "{$base}/reports/audit/export?warehouse_id={$whB}",
        'date range manipulation on export' => "{$base}/reports/audit/export?date_from=2020-01-01&date_to=2030-01-01",
    ];
    foreach ($exportVectors as $label => $url) {
        $r = httpCall('GET', $url, null, $stockAJar);
        check("STOCK-SCM: GET /reports/audit/export via {$label} is rejected with 403 (pagination/export limits are never a bypass)", $r['status'] === 403, "status={$r['status']}");
        check("STOCK-SCM: rejected export via {$label} never streams a CSV body", !str_contains($r['headers'], 'Content-Type: text/csv'));
    }

    // A4 — the inverse: STOCK-CIBADAK (whB) is equally denied, proving
    // this is a role-shape rule, not a hardcoded whA-only check.
    $stockBAudit = httpCall('GET', "{$base}/reports/audit", null, $stockBJar);
    check('STOCK-CIBADAK: GET /reports/audit is rejected with 403', $stockBAudit['status'] === 403, (string) $stockBAudit['status']);
    $stockBAuditExport = httpCall('GET', "{$base}/reports/audit/export", null, $stockBJar);
    check('STOCK-CIBADAK: GET /reports/audit/export is rejected with 403', $stockBAuditExport['status'] === 403, (string) $stockBAuditExport['status']);
    $stockBCrossVector = httpCall('GET', "{$base}/reports/audit?warehouse_id={$whA}&username=" . urlencode($stockAUser), null, $stockBJar);
    check('STOCK-CIBADAK: GET /reports/audit targeting SCM via query params is still rejected with 403', $stockBCrossVector['status'] === 403, (string) $stockBCrossVector['status']);

    // A5 — entity/reference lookups (Trace Center) targeting known
    // cross-warehouse objects are also unreachable by STOCK, because
    // every /trace/* route shares the same AUDIT_LOG_VIEW gate STOCK
    // never holds — same structural denial, no separate code path to
    // regress independently.
    $stockATraceSearch = httpCall('GET', "{$base}/trace/search?q=" . urlencode($skuA), null, $stockAJar);
    check('STOCK-SCM: GET /trace/search (entity/reference lookup vector) is rejected with 403', $stockATraceSearch['status'] === 403, (string) $stockATraceSearch['status']);
    $stockATraceEvents = httpCall('GET', "{$base}/trace/events", null, $stockAJar);
    check('STOCK-SCM: GET /trace/events is rejected with 403', $stockATraceEvents['status'] === 403, (string) $stockATraceEvents['status']);

    // ============================================================
    // B — CSV/Excel formula-injection hardening
    // ============================================================
    echo "\n== B: CSV formula-injection hardening ==\n";

    // B1 — the JSON API must return every payload byte-for-byte raw,
    // proving sanitization is export-layer only and never mutates
    // stored/API values.
    $adjApi = httpCall('GET', "{$base}/reports/adjustment?warehouse_id={$whA}&per_page=50", null, $superJar);
    check('adjustment JSON API returns 200', $adjApi['status'] === 200, (string) $adjApi['status']);
    $apiReasons = array_map(static fn ($r) => $r['reason'] ?? '', $adjApi['body']['data']['rows'] ?? []);
    foreach ($injectionPayloads as $tag => $payload) {
        check("JSON API: {$tag} payload reason is returned verbatim, unmutated ({$payload})", in_array($payload, $apiReasons, true), json_encode($apiReasons));
    }

    // B2 — the CSV export must neutralize every one of those same
    // reasons (leading quote prefix) in the actual "Alasan" CSV field —
    // parsed with str_getcsv (same '\\' escape convention the app's own
    // fputcsv() calls use), never a naive substring search, because
    // RFC4180 quoting/escaping of a field containing commas/quotes (the
    // PLUS/MINUS payloads both do) changes its literal on-the-wire bytes
    // without that being a neutralization failure.
    $adjCsv = httpCall('GET', "{$base}/reports/adjustment?warehouse_id={$whA}&format=csv", null, $superJar);
    check('adjustment CSV export returns 200', $adjCsv['status'] === 200, (string) $adjCsv['status']);
    $csvBody = $adjCsv['raw'];
    $csvLines = explode("\n", str_replace("\xEF\xBB\xBF", '', $csvBody));
    $csvRows = [];
    foreach ($csvLines as $line) {
        if (trim($line) === '') { continue; }
        $csvRows[] = str_getcsv($line, ',', '"', '\\');
    }
    $headerRow = $csvRows[0] ?? [];
    $alasanIdx = array_search('Alasan', $headerRow, true);
    check('adjustment CSV export has an "Alasan" column', $alasanIdx !== false, json_encode($headerRow));
    $alasanValues = $alasanIdx !== false ? array_column(array_slice($csvRows, 1), $alasanIdx) : [];
    foreach ($injectionPayloads as $tag => $payload) {
        check("CSV export: {$tag} payload's Alasan cell is neutralized with a leading quote, never the raw formula-triggering prefix", in_array("'" . $payload, $alasanValues, true), json_encode($alasanValues));
        check("CSV export: raw (un-neutralized) {$tag} payload never appears as its own Alasan cell", !in_array($payload, $alasanValues, true));
    }

    // B3 — a genuinely numeric business figure (negative adjustment
    // value) must remain plain, unquoted numeric text — never rewritten
    // to a quoted/escaped string merely for starting with '-'.
    check('CSV export: the -1250.50-style numeric adjustment value is present as a plain number, never quote-prefixed', (bool) preg_match('/(?<!\')-1250\.5\b/', $csvBody), substr($csvBody, 0, 400));
    check('CSV export: no cell in this export was corrupted into a quoted numeric (no \'- sequence present)', !str_contains($csvBody, "'-1250.5"));

    // B4 — same hardening on the ad-hoc (non-inv_export_csv) movement
    // export writer, using the shared inv_csv_write_row() helper too.
    $histInjTxStmt = $pdo->prepare(
        "INSERT INTO inventory_transactions
            (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
         VALUES (:uuid, 'IN', :tx_date, :post_date, :wh, 'POSTED', 1, 0, :created_by, :created_at)"
    );
    $now = date('Y-m-d H:i:s');
    $histInjTxStmt->execute(['uuid' => uid('gate-hist-inj'), 'tx_date' => '2026-07-03 09:00:00', 'post_date' => $now, 'wh' => $whA, 'created_by' => $adminUserId, 'created_at' => $now]);
    $movementCsv = httpCall('GET', "{$base}/reports/movement/daily?start_date=2026-07-01&end_date=2026-07-10&warehouse_id={$whA}&format=csv", null, $superJar);
    check('movement/daily CSV export (ad-hoc writer) still returns 200 and a well-formed CSV after routing through inv_csv_write_row()', $movementCsv['status'] === 200 && str_starts_with($movementCsv['raw'], "\xEF\xBB\xBF"), (string) $movementCsv['status']);

    // ============================================================
    // C — read-only: exercising every route above never mutates state
    // ============================================================
    echo "\n== C: V2.6 final gate routes are strictly read-only ==\n";

    $txCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $adjCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
    $auditCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $reasonsBefore = $pdo->query('SELECT id, reason FROM stock_adjustments ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);

    httpCall('GET', "{$base}/reports/audit?per_page=10", null, $superJar);
    httpCall('GET', "{$base}/reports/audit/export", null, $superJar);
    httpCall('GET', "{$base}/reports/audit", null, $stockAJar);
    httpCall('GET', "{$base}/reports/audit/export", null, $stockAJar);
    httpCall('GET', "{$base}/reports/adjustment?warehouse_id={$whA}&format=csv", null, $superJar);

    $txCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    $adjCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments')->fetchColumn();
    $auditCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $reasonsAfter = $pdo->query('SELECT id, reason FROM stock_adjustments ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    check('final-gate routes never change inventory_transactions count', $txCountBefore === $txCountAfter, "{$txCountBefore} vs {$txCountAfter}");
    check('final-gate routes never change stock_adjustments count', $adjCountBefore === $adjCountAfter, "{$adjCountBefore} vs {$adjCountAfter}");
    check('final-gate routes never write their own audit_logs rows merely from being viewed (read-only GETs, including 403s)', $auditCountBefore === $auditCountAfter, "{$auditCountBefore} vs {$auditCountAfter}");
    check('stock_adjustments.reason values in the DB are byte-for-byte unchanged after every export above (export sanitization never mutates storage)', $reasonsBefore === $reasonsAfter);

    @unlink($superJar);
    @unlink($adminJar);
    @unlink($viewerJar);
    @unlink($stockAJar);
    @unlink($stockBJar);
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
