<?php
declare(strict_types=1);

/**
 * PHASE V2.11C — Revenue/Margin/Category/Bakery reports + DO/Invoice
 * print documents, against real MySQL/MariaDB.
 *
 * Usage: php tests/inventory_v2_11c_reports_print_test.php
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
require_once __DIR__ . '/../services/ItemPriceService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/DistributionOrderService.php';
require_once __DIR__ . '/../services/PricingPolicyService.php';
require_once __DIR__ . '/../services/DistributionInvoiceService.php';
require_once __DIR__ . '/../services/DistributionReportService.php';
require_once __DIR__ . '/../services/DistributionPrintService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\DistributionOrderService;
use App\Services\PricingPolicyService;
use App\Services\DistributionInvoiceService;
use App\Services\DistributionReportService;
use App\Services\DistributionPrintService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) < $eps; }
function doNumberOf(PDO $pdo, int $doId): string
{
    $stmt = $pdo->prepare('SELECT do_number FROM distribution_orders WHERE id = :id');
    $stmt->execute(['id' => $doId]);
    return (string) $stmt->fetchColumn();
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v211csetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V2.11C Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('SCM', 'SCM / Gudang Besar', 1)")->execute();
$scmId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO bakery_destinations (code, name, address, is_active) VALUES (:c,:n,:a,1)')->execute(['c' => uid('BAKERY-A'), 'n' => 'Bakery A', 'a' => 'Jl. A']);
$bakeryAId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO bakery_destinations (code, name, address, is_active) VALUES (:c,:n,:a,1)')->execute(['c' => uid('BAKERY-B'), 'n' => 'Bakery B', 'a' => 'Jl. B']);
$bakeryBId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('CAT-X'), 'n' => 'Kategori X']);
$categoryXId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('CAT-Y'), 'n' => 'Kategori Y']);
$categoryYId = (int) $pdo->lastInsertId();

PricingPolicyService::upsert($pdo, ['scope' => 'COMPANY', 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 10, 'created_by' => $adminUserId]);

function makeItem(PDO $pdo, int $unitId, string $tag, ?int $categoryId): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku, :name, :unit, :cat, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v211c-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v211c', 'transaction_type' => 'OPENING',
    ]));
}
/** Creates, dispatches, and invoices+issues a single-line DO. Returns [doId, invoiceId, itemId]. */
function fullFlow(PDO $pdo, int $itemId, int $unitId, int $whId, int $bakeryId, float $qty, int $by, string $date = '2026-09-23'): array
{
    $do = DistributionOrderService::create($pdo, [
        'do_date' => $date, 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $whId,
        'created_by' => $by, 'lines' => [['item_id' => $itemId, 'input_qty' => $qty, 'input_unit_id' => $unitId]],
    ]);
    DistributionOrderService::approve($pdo, $do['do_id'], ['created_by' => $by]);
    DistributionOrderService::startPicking($pdo, $do['do_id'], ['created_by' => $by]);
    DistributionOrderService::dispatch($pdo, $do['do_id'], ['created_by' => $by, 'request_uuid' => uid('req')]);
    $inv = DistributionInvoiceService::create($pdo, ['do_id' => $do['do_id'], 'invoice_date' => $date, 'created_by' => $by]);
    return [$do['do_id'], $inv['invoice_id'], $itemId];
}

// ============================================================
// REPORTS — revenue/margin, cancelled/draft exclusion
// ============================================================
echo "== REPORTS: DRAFT invoice excluded from revenue (Part 34) ==\n";
[$itemD, $skuD] = makeItem($pdo, $kgUnitId, 'V211C-DRAFT', $categoryXId);
postOpeningIn($pdo, $itemD, $kgUnitId, $scmId, 20, 100, $adminUserId);
[$doD, $invD] = fullFlow($pdo, $itemD, $kgUnitId, $scmId, $bakeryAId, 5, $adminUserId); // creates but does NOT issue
$summaryBeforeIssue = DistributionReportService::summary($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'bakery_destination_id' => $bakeryAId]);
check('a DRAFT (not yet issued) invoice contributes 0 to reported revenue', approx($summaryBeforeIssue['total_revenue'], 0.0), json_encode($summaryBeforeIssue));

DistributionInvoiceService::issue($pdo, $invD, ['created_by' => $adminUserId]);
$summaryAfterIssue = DistributionReportService::summary($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'bakery_destination_id' => $bakeryAId]);
check('once ISSUED, the invoice now contributes to reported revenue', $summaryAfterIssue['total_revenue'] > 0, json_encode($summaryAfterIssue));
$expectedRevenue = 5 * 110.0; // 100 ref * 1.10 company policy
check('revenue matches the real invoice subtotal (5 * 110 = 550)', approx($summaryAfterIssue['total_revenue'], $expectedRevenue), (string) $summaryAfterIssue['total_revenue']);
check('Actual HPP matches the real FIFO cost (5 * 100 = 500), not the policy-derived 550', approx($summaryAfterIssue['total_hpp'], 500.0), (string) $summaryAfterIssue['total_hpp']);
check('Actual Margin = 550 - 500 = 50 (never the 10% policy figure)', approx($summaryAfterIssue['total_margin'], 50.0), (string) $summaryAfterIssue['total_margin']);

echo "\n== REPORTS: CANCELLED invoice excluded from revenue ==\n";
[$itemC, $skuC] = makeItem($pdo, $kgUnitId, 'V211C-CANCEL', $categoryXId);
postOpeningIn($pdo, $itemC, $kgUnitId, $scmId, 20, 200, $adminUserId);
[$doC, $invC] = fullFlow($pdo, $itemC, $kgUnitId, $scmId, $bakeryAId, 3, $adminUserId);
DistributionInvoiceService::issue($pdo, $invC, ['created_by' => $adminUserId]);
DistributionInvoiceService::cancel($pdo, $invC, ['reason' => 'Testing cancel exclusion', 'created_by' => $adminUserId]);
$summaryWithCancelled = DistributionReportService::summary($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'bakery_destination_id' => $bakeryAId]);
check('a CANCELLED invoice no longer contributes to reported revenue (only the earlier ISSUED one counts)', approx($summaryWithCancelled['total_revenue'], $expectedRevenue), json_encode($summaryWithCancelled));

echo "\n== REPORTS: by-category aggregation (2 categories independently) ==\n";
[$itemY, $skuY] = makeItem($pdo, $kgUnitId, 'V211C-CATY', $categoryYId);
postOpeningIn($pdo, $itemY, $kgUnitId, $scmId, 20, 50, $adminUserId);
[, $invY] = fullFlow($pdo, $itemY, $kgUnitId, $scmId, $bakeryAId, 4, $adminUserId);
DistributionInvoiceService::issue($pdo, $invY, ['created_by' => $adminUserId]);
$byCategoryAll = DistributionReportService::byCategory($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);
$catX = array_values(array_filter($byCategoryAll, fn ($r) => $r['category_id'] === $categoryXId))[0] ?? null;
$catY = array_values(array_filter($byCategoryAll, fn ($r) => $r['category_id'] === $categoryYId))[0] ?? null;
check('Kategori X aggregate present with the DRAFT-test item\'s revenue (550)', $catX !== null && approx($catX['revenue'], 550.0), json_encode($catX));
check('Kategori Y aggregate present and independent from Kategori X', $catY !== null && $catY['revenue'] > 0, json_encode($catY));

echo "\n== REPORTS: by-bakery aggregation (2 bakeries independently) ==\n";
[$itemBB, $skuBB] = makeItem($pdo, $kgUnitId, 'V211C-BAKERYB', $categoryXId);
postOpeningIn($pdo, $itemBB, $kgUnitId, $scmId, 20, 300, $adminUserId);
[$doBB, $invBB] = fullFlow($pdo, $itemBB, $kgUnitId, $scmId, $bakeryBId, 2, $adminUserId);
DistributionInvoiceService::issue($pdo, $invBB, ['created_by' => $adminUserId]);
$byBakery = DistributionReportService::byBakery($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);
$bakeryARow = array_values(array_filter($byBakery, fn ($r) => $r['bakery_id'] == $bakeryAId))[0] ?? null;
$bakeryBRow = array_values(array_filter($byBakery, fn ($r) => $r['bakery_id'] == $bakeryBId))[0] ?? null;
check('Bakery A aggregate present (from the earlier ISSUED invoices)', $bakeryARow !== null && $bakeryARow['revenue'] > 0, json_encode($bakeryARow));
check('Bakery B aggregate present and independent (2 * 330 = 660)', $bakeryBRow !== null && approx($bakeryBRow['revenue'], 660.0), json_encode($bakeryBRow));
check('Bakery A do_count/invoice_count are real integers, not estimates', is_int($bakeryARow['do_count']) && $bakeryARow['do_count'] > 0, json_encode($bakeryARow));

echo "\n== REPORTS: discrepancy count reflects real receiving differences ==\n";
[$itemDisc, $skuDisc] = makeItem($pdo, $kgUnitId, 'V211C-DISC', $categoryXId);
postOpeningIn($pdo, $itemDisc, $kgUnitId, $scmId, 20, 400, $adminUserId);
$discDo = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryAId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $itemDisc, 'input_qty' => 10, 'input_unit_id' => $kgUnitId]],
]);
DistributionOrderService::approve($pdo, $discDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::startPicking($pdo, $discDo['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::dispatch($pdo, $discDo['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('req')]);
$discDoDetail = DistributionOrderService::get($pdo, $discDo['do_id']);
$summaryBeforeDiscrepancy = DistributionReportService::summary($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'bakery_destination_id' => $bakeryAId]);
DistributionOrderService::receive($pdo, $discDo['do_id'], [
    'created_by' => $adminUserId,
    'lines' => [['do_line_id' => $discDoDetail['lines'][0]['id'], 'qty_received' => 9, 'discrepancy_reason' => 'RUSAK']],
]);
$summaryAfterDiscrepancy = DistributionReportService::summary($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'bakery_destination_id' => $bakeryAId]);
check('discrepancy_count increases after a real receiving difference is recorded', $summaryAfterDiscrepancy['discrepancy_count'] > $summaryBeforeDiscrepancy['discrepancy_count'], "{$summaryBeforeDiscrepancy['discrepancy_count']} -> {$summaryAfterDiscrepancy['discrepancy_count']}");
check('discrepancy_count is tracked even though this DO has no Invoice yet (operational, not financial)', $summaryAfterDiscrepancy['discrepancy_count'] >= 1);

// ============================================================
// PRINT DOCUMENTS — content correctness + strict no-leak guarantee
// ============================================================
echo "\n== PRINT: Delivery Order — required fields present, NO pricing/HPP/margin ==\n";
$doPrintHtml = DistributionPrintService::renderDeliveryOrder($pdo, $doD);
check('DO print contains "DELIVERY ORDER / SURAT JALAN"', str_contains($doPrintHtml, 'DELIVERY ORDER'), '');
check('DO print contains the DO number', str_contains($doPrintHtml, doNumberOf($pdo, $doD)));
check('DO print contains SKU/item name', str_contains($doPrintHtml, $skuD));
check('DO print NEVER contains the word "HPP"', !str_contains($doPrintHtml, 'HPP'));
$doPrintBodyOnly = preg_replace('#<style>.*?</style>#s', '', $doPrintHtml);
check('DO print body (excluding CSS spacing rules like "margin-top") NEVER mentions "margin"', !str_contains(strtolower($doPrintBodyOnly), 'margin'));
check('DO print NEVER contains a Rupiah price figure (no "Rp " anywhere)', !str_contains($doPrintHtml, 'Rp '));

echo "\n== PRINT: Invoice — required fields present, NO HPP/reference cost/margin ==\n";
$invPrintHtml = DistributionPrintService::renderInvoice($pdo, $invD);
check('Invoice print contains "INVOICE"', str_contains($invPrintHtml, '>INVOICE<'), '');
check('Invoice print contains the item SKU and selling price', str_contains($invPrintHtml, $skuD) && str_contains($invPrintHtml, 'Rp'));
check('Invoice print contains Grand Total', str_contains($invPrintHtml, 'Grand Total'));
check('Invoice print NEVER contains the word "HPP"', !str_contains($invPrintHtml, 'HPP'));
$invPrintBodyOnly = preg_replace('#<style>.*?</style>#s', '', $invPrintHtml);
check('Invoice print body (excluding CSS spacing rules) NEVER mentions "margin"', !str_contains(strtolower($invPrintBodyOnly), 'margin'));
check('Invoice print NEVER contains "referensi" (the internal reference purchase price label)', !str_contains(strtolower($invPrintHtml), 'referensi'));
check('Invoice print NEVER contains the raw reference_purchase_price figure (100.0000)', !str_contains($invPrintHtml, '100.0000'));

$aSectionTotal = count($results);
$aSectionPassed = count(array_filter($results));
echo "\n-- Section (direct-service): {$aSectionPassed} / {$aSectionTotal} PASSED --\n";

// ============================================================
// HTTP — STOCK forbidden from reports (never gains reporting access)
// ============================================================
$port = 8900 + random_int(2000, 2399);
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
    $superUser = uid('v211csuper'); $superPass = 'V211CSuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('v211cstock'); $stockPass = 'V211CStockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $scmId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);

    echo "\n== HTTP: STOCK is forbidden from every distribution report endpoint ==\n";
    foreach (['/reports/distribution/lines', '/reports/distribution/summary', '/reports/distribution/by-category', '/reports/distribution/by-bakery'] as $endpoint) {
        $resp = httpCall('GET', "{$base}{$endpoint}", null, $stockJar);
        check("HTTP STOCK forbidden from GET {$endpoint} (403)", $resp['status'] === 403, json_encode($resp['body']));
    }

    echo "\n== HTTP: SUPERADMIN can reach every distribution report endpoint ==\n";
    $summaryResp = httpCall('GET', "{$base}/reports/distribution/summary?date_from=2026-09-23&date_to=2026-09-23", null, $superJar);
    check('HTTP SUPERADMIN GET /reports/distribution/summary 200', $summaryResp['status'] === 200, json_encode($summaryResp['body']));

    echo "\n== HTTP: DO print route reachable and returns HTML, not JSON ==\n";
    $printResp = httpCall('GET', "{$base}/distribution-orders/{$doD}/print", null, $superJar);
    check('HTTP DO print returns 200 with real HTML content', $printResp['status'] === 200 && str_contains($printResp['raw'], '<!DOCTYPE html>'), substr($printResp['raw'], 0, 100));
} finally {
    proc_terminate($process);
    proc_close($process);
}

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$aTotal}  PASSED: {$aPassed}  FAILED: " . ($aTotal - $aPassed) . "\n";
exit($aPassed === $aTotal ? 0 : 1);
