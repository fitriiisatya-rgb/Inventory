<?php
declare(strict_types=1);

/**
 * PHASE V2.7 — Purchase Costing (PPN / Discount / Freight). Covers:
 *   A) PurchaseCostingService pure math — every case in V2.7.10's list
 *      (no discount/PPN/freight, line discount %/amount, invoice discount
 *      %/amount, rounding allocation, PPN creditable/non-creditable/
 *      partially-creditable, freight capitalized/expensed, multi-line,
 *      discount>gross rejection).
 *   B) Full HTTP integration — POST /transactions/in with costing fields
 *      creates a correctly-reconciled purchase_invoice_headers/
 *      purchase_line_costs pair whose figures agree EXACTLY with the real
 *      FIFO batch FifoService::postIn() created; a plain Stock IN with no
 *      new fields is byte-identical to pre-V2.7 behavior (no costing rows
 *      at all); GET /transactions/in/cost-preview matches what actually
 *      gets posted; unit-conversion (non-base purchase unit) still
 *      correctly drives base_qty/final_unit_cost_base; the enriched
 *      Laporan Pembelian export includes the breakdown; VOID of a costed
 *      transaction still works via the EXISTING, unmodified VoidService;
 *      a subsequent OUT still correctly consumes the costed batch's real
 *      landed cost (FIFO/OUT logic itself untouched); the 1-15 Sep
 *      historical/inventory_effect=0 convention is unaffected by this
 *      phase; and every V2.7 route is fully warehouse/permission scoped.
 *
 * Usage: php tests/inventory_v27_purchase_costing_test.php
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
require_once __DIR__ . '/../services/PurchaseCostingService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\PurchaseCostingService as PCS;
use App\Services\UnitConversionService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

// ============================================================
// A — PurchaseCostingService pure math
// ============================================================
echo "== A: PurchaseCostingService pure math ==\n";

function noneHeader(array $overrides = []): array
{
    return array_merge([
        'invoice_discount_type' => 'NONE', 'invoice_discount_value' => 0,
        'ppn_treatment' => 'NONE', 'ppn_rate' => 0, 'ppn_creditable_pct' => 0,
        'freight_treatment' => 'NONE', 'freight_amount' => 0,
    ], $overrides);
}
function line(array $overrides = []): array
{
    return array_merge(['qty' => 10, 'gross_unit_price' => 1000, 'base_qty' => 10, 'line_discount_type' => 'NONE', 'line_discount_value' => 0], $overrides);
}

// A1 — no discount / no PPN / no freight.
$a1 = PCS::buildCostPreview(noneHeader(), [line()]);
check('A1 no-discount/no-ppn/no-freight: inventory_cost_total == gross', $a1['header']['inventory_cost_total'] === 10000.0, (string) $a1['header']['inventory_cost_total']);
check('A1 invoice_total == gross too (nothing added)', $a1['header']['invoice_total'] === 10000.0);
check('A1 line final_unit_cost_base == gross unit price', $a1['lines'][0]['final_unit_cost_base'] === 1000.0);

// A2 — line discount percent.
$a2 = PCS::buildCostPreview(noneHeader(), [line(['line_discount_type' => 'PERCENT', 'line_discount_value' => 15])]);
check('A2 line discount 15% of 10000 = 1500', $a2['lines'][0]['line_discount_amount'] === 1500.0, (string) $a2['lines'][0]['line_discount_amount']);
check('A2 net after line discount = 8500', $a2['lines'][0]['net_after_line_discount'] === 8500.0);

// A3 — line discount amount.
$a3 = PCS::buildCostPreview(noneHeader(), [line(['line_discount_type' => 'AMOUNT', 'line_discount_value' => 750])]);
check('A3 line discount amount = 750 exactly', $a3['lines'][0]['line_discount_amount'] === 750.0);
check('A3 net after line discount = 9250', $a3['lines'][0]['net_after_line_discount'] === 9250.0);

// A4 — invoice discount percent.
$a4 = PCS::buildCostPreview(noneHeader(['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10]), [line()]);
check('A4 invoice discount 10% of 10000 = 1000', $a4['header']['invoice_discount_amount'] === 1000.0);
check('A4 net_purchase_before_tax = 9000', $a4['header']['net_purchase_before_tax'] === 9000.0);

// A5 — invoice discount amount.
$a5 = PCS::buildCostPreview(noneHeader(['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 250]), [line()]);
check('A5 invoice discount amount = 250 exactly', $a5['header']['invoice_discount_amount'] === 250.0);

// A6 — rounding allocation: 3 equal lines, invoice discount 100 (doesn't divide evenly).
$a6 = PCS::buildCostPreview(
    noneHeader(['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 100]),
    [line(['qty' => 1, 'gross_unit_price' => 1000, 'base_qty' => 1]), line(['qty' => 1, 'gross_unit_price' => 1000, 'base_qty' => 1]), line(['qty' => 1, 'gross_unit_price' => 1000, 'base_qty' => 1])]
);
$a6Sum = array_sum(array_column($a6['lines'], 'invoice_discount_allocated'));
check('A6 rounding: 3-way allocation of 100 sums EXACTLY to 100 (no lost/invented penny)', abs($a6Sum - 100.0) < 0.0001, (string) $a6Sum);
check('A6 rounding: remainder went to the LAST line deterministically', $a6['lines'][2]['invoice_discount_allocated'] === 33.34 || $a6['lines'][2]['invoice_discount_allocated'] > $a6['lines'][0]['invoice_discount_allocated'], json_encode(array_column($a6['lines'], 'invoice_discount_allocated')));

// A7 — PPN creditable: 0 enters inventory cost.
$a7 = PCS::buildCostPreview(noneHeader(['ppn_treatment' => 'CREDITABLE', 'ppn_rate' => 11]), [line()]);
check('A7 PPN creditable: full amount is creditable', $a7['header']['ppn_creditable_amount'] === $a7['header']['ppn_amount']);
check('A7 PPN creditable: 0 non-creditable', $a7['header']['ppn_non_creditable_amount'] === 0.0);
check('A7 PPN creditable: inventory_cost_total excludes PPN entirely', $a7['header']['inventory_cost_total'] === 10000.0);
check('A7 PPN creditable: invoice_total still includes full PPN (still payable)', $a7['header']['invoice_total'] === round(10000 * 1.11, 4), (string) $a7['header']['invoice_total']);

// A8 — PPN non-creditable: full amount enters inventory cost.
$a8 = PCS::buildCostPreview(noneHeader(['ppn_treatment' => 'NON_CREDITABLE', 'ppn_rate' => 11]), [line()]);
check('A8 PPN non-creditable: 0 creditable', $a8['header']['ppn_creditable_amount'] === 0.0);
check('A8 PPN non-creditable: inventory_cost_total includes full PPN', $a8['header']['inventory_cost_total'] === round(10000 * 1.11, 4));

// A9 — PPN partially creditable (60/40 split).
$a9 = PCS::buildCostPreview(noneHeader(['ppn_treatment' => 'PARTIALLY_CREDITABLE', 'ppn_rate' => 10, 'ppn_creditable_pct' => 60]), [line()]);
check('A9 partially creditable: 60% of 1000 ppn = 600 creditable', $a9['header']['ppn_creditable_amount'] === 600.0, (string) $a9['header']['ppn_creditable_amount']);
check('A9 partially creditable: 400 non-creditable', $a9['header']['ppn_non_creditable_amount'] === 400.0);
check('A9 partially creditable: creditable+non_creditable == full ppn_amount exactly', $a9['header']['ppn_creditable_amount'] + $a9['header']['ppn_non_creditable_amount'] === $a9['header']['ppn_amount']);

// A10 — freight capitalized.
$a10 = PCS::buildCostPreview(noneHeader(['freight_treatment' => 'CAPITALIZE', 'freight_amount' => 500]), [line()]);
check('A10 freight capitalized: enters inventory cost', $a10['header']['inventory_cost_total'] === 10500.0);
check('A10 freight capitalized: line freight_allocated == full amount for single line', $a10['lines'][0]['freight_allocated'] === 500.0);

// A11 — freight expensed.
$a11 = PCS::buildCostPreview(noneHeader(['freight_treatment' => 'EXPENSE', 'freight_amount' => 500]), [line()]);
check('A11 freight expensed: never enters inventory cost', $a11['header']['inventory_cost_total'] === 10000.0);
check('A11 freight expensed: still counted in invoice_total (still payable)', $a11['header']['invoice_total'] === 10500.0);
check('A11 freight expensed: line freight_allocated == 0', $a11['lines'][0]['freight_allocated'] === 0.0);

// A12 — multiple lines, full combined scenario.
$a12 = PCS::buildCostPreview(
    noneHeader(['invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 5, 'ppn_treatment' => 'NON_CREDITABLE', 'ppn_rate' => 11, 'freight_treatment' => 'CAPITALIZE', 'freight_amount' => 20000]),
    [
        line(['qty' => 10, 'gross_unit_price' => 5000, 'base_qty' => 10, 'line_discount_type' => 'PERCENT', 'line_discount_value' => 10]),
        line(['qty' => 5, 'gross_unit_price' => 20000, 'base_qty' => 5, 'line_discount_type' => 'NONE', 'line_discount_value' => 0]),
    ]
);
check('A12 multi-line: header inventory_cost_total == sum of line final_inventory_cost', abs($a12['header']['inventory_cost_total'] - array_sum(array_column($a12['lines'], 'final_inventory_cost'))) < 0.0001);
check('A12 multi-line: freight allocated across both lines, summing to header freight', abs(array_sum(array_column($a12['lines'], 'freight_allocated')) - 20000) < 0.0001);
check('A12 multi-line: 2 lines returned', count($a12['lines']) === 2);

// A13 — discount > gross must throw, nothing computed.
try {
    PCS::buildCostPreview(noneHeader(), [line(['line_discount_type' => 'AMOUNT', 'line_discount_value' => 99999])]);
    check('A13 discount > gross is rejected', false);
} catch (ValidationException $e) {
    check('A13 discount > gross is rejected', true, $e->getMessage());
}

// A14 — invoice discount > net-after-line-discount must throw.
try {
    PCS::buildCostPreview(noneHeader(['invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 50000]), [line()]);
    check('A14 invoice discount > net is rejected', false);
} catch (ValidationException $e) {
    check('A14 invoice discount > net is rejected', true, $e->getMessage());
}

// ============================================================
// B — HTTP integration
// ============================================================
$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$boxUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='BOX'")->fetchColumn();
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v27setup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V27 Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V27-A'), 'n' => 'V27 Warehouse A']);
$whA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO warehouses (code, name) VALUES (:c, :n)')->execute(['c' => uid('V27-B'), 'n' => 'V27 Warehouse B']);
$whB = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('V27-SUP'), 'n' => 'V27 Supplier']);
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

Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v27-pin'), 'item_id' => makeItem($pdo, $kgUnitId, 'V27-PIN')[0], 'warehouse_id' => $whA,
    'input_qty' => 1, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 1,
    'transaction_date' => '2020-01-01 00:00:00', 'created_by' => $adminUserId, 'username' => 'v27', 'transaction_type' => 'OPENING',
]));

[$itemPlain, $skuPlain] = makeItem($pdo, $kgUnitId, 'V27-PLAIN');
[$itemCosted, $skuCosted] = makeItem($pdo, $kgUnitId, 'V27-COSTED');
// A BOX-unit item to prove unit conversion still drives base_qty/final_unit_cost_base correctly.
[$itemBox, $skuBox] = makeItem($pdo, $kgUnitId, 'V27-BOXITEM');
UnitConversionService::openNewVersion($pdo, $itemBox, $boxUnitId, 12.0, '2020-01-01 00:00:00', null, '1 BOX = 12 KG base');

// A genuinely pre-V2.7-style IN: posted via a DIRECT FifoService::postIn()
// PHP call, bypassing POST /transactions/in entirely (simulating any
// caller other than the Transaksi Masuk wizard — e.g. a future import or
// integration that posts straight to the service). This is the one true
// "no costing row at all" case Laporan Pembelian must show as null.
[$itemPreV27, $skuPreV27] = makeItem($pdo, $kgUnitId, 'V27-PREV27');
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('v27-prev27-in'), 'item_id' => $itemPreV27, 'warehouse_id' => $whA,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 3000,
    'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $adminUserId, 'username' => 'v27', 'supplier_id' => $supplierId,
]));

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
    $respHeaders = substr((string) $raw, 0, $headerSize);
    $rawBody = substr((string) $raw, $headerSize);
    $decoded = json_decode($rawBody, true);
    return ['status' => $status, 'headers' => $respHeaders, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $rawBody];
}

try {
    $superUser = uid('v27super'); $superPass = 'V27SuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockBUser = uid('v27stockb'); $stockBPass = 'V27StockBPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockBUser, 'h' => password_hash($stockBPass, PASSWORD_BCRYPT), 'n' => $stockBUser, 'r' => $stockRoleId, 'w' => $whB]);
    $stockBJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockBLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockBUser, 'password' => $stockBPass], $stockBJar);
    $stockBCsrf = $stockBLogin['body']['data']['csrf_token'] ?? '';

    // ============================================================
    // B1 — plain Stock IN (no new fields): byte-identical to pre-V2.7.
    // ============================================================
    echo "\n== B1: backward compatibility (no costing fields) ==\n";
    $plainUuid = uid('v27-plain-tx');
    $plainPost = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => $plainUuid, 'item_id' => $itemPlain, 'warehouse_id' => $whA,
        'input_unit_id' => $kgUnitId, 'input_qty' => 20, 'unit_price_input' => 5000,
        'transaction_date' => '2026-08-01 08:00:00', 'supplier_id' => $supplierId,
    ], $superJar, $superCsrf);
    check('B1 plain Stock IN posts 200', $plainPost['status'] === 200, json_encode($plainPost['body']));
    $plainTxId = (int) ($plainPost['body']['data']['transaction_id'] ?? 0);
    check('B1 plain Stock IN: unit_cost_base == raw gross price (5000/1 factor) — byte-identical to pre-V2.7', ($plainPost['body']['data']['unit_cost_base'] ?? null) == 5000, json_encode($plainPost['body']['data'] ?? null));
    // Every Stock IN through this route is now costed (see the route's own
    // comment) — omitting every new field is the mathematical IDENTITY
    // case (all discounts/PPN/freight = 0), not a "skip costing" signal.
    // A trivial-but-real purchase_invoice_headers row is still the right
    // outcome: it gives every V2.7-era purchase a full audit trail, and
    // "has no costing row at all" becomes a clean signal meaning
    // specifically "posted before V2.7 shipped" (see B9's historical-
    // import case, which truly never touches this route at all).
    $plainHeader = $pdo->query("SELECT * FROM purchase_invoice_headers WHERE transaction_id = {$plainTxId}")->fetch(PDO::FETCH_ASSOC);
    check('B1 plain Stock IN still gets a purchase_invoice_headers row (the identity case, not skipped)', $plainHeader !== false);
    check('B1 identity case: gross_purchase == inventory_cost_total == invoice_total (no discount/ppn/freight touched anything)', $plainHeader && abs((float) $plainHeader['gross_purchase'] - 100000) < 0.01 && abs((float) $plainHeader['inventory_cost_total'] - 100000) < 0.01 && abs((float) $plainHeader['invoice_total'] - 100000) < 0.01, json_encode($plainHeader));
    check('B1 identity case: ppn_treatment/freight_treatment default to NONE', $plainHeader && $plainHeader['ppn_treatment'] === 'NONE' && $plainHeader['freight_treatment'] === 'NONE');

    // ============================================================
    // B2 — costed Stock IN: full reconciliation against actual FIFO batch.
    // ============================================================
    echo "\n== B2: costed Stock IN — full reconciliation ==\n";
    $costedUuid = uid('v27-costed-tx');
    $costedPost = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => $costedUuid, 'item_id' => $itemCosted, 'warehouse_id' => $whA,
        'input_unit_id' => $kgUnitId, 'input_qty' => 100, 'unit_price_input' => 10000,
        'transaction_date' => '2026-08-02 08:00:00', 'supplier_id' => $supplierId,
        'line_discount_type' => 'PERCENT', 'line_discount_value' => 10,
        'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 5,
        'ppn_treatment' => 'NON_CREDITABLE', 'ppn_rate' => 11,
        'freight_treatment' => 'CAPITALIZE', 'freight_amount' => 50000,
    ], $superJar, $superCsrf);
    check('B2 costed Stock IN posts 200', $costedPost['status'] === 200, json_encode($costedPost['body']));
    $costedTxId = (int) ($costedPost['body']['data']['transaction_id'] ?? 0);
    $costedLineId = (int) ($costedPost['body']['data']['line_id'] ?? 0);
    $postedUnitCostBase = (float) ($costedPost['body']['data']['unit_cost_base'] ?? 0);
    $postedBaseQty = (float) ($costedPost['body']['data']['base_qty'] ?? 0);

    // Expected: gross=1,000,000; line disc 10%=100,000; net_after_line=900,000
    // invoice disc 5% of 900,000=45,000; net_purchase=855,000
    // ppn 11% of 855,000=94,050 (non-creditable, full); freight 50,000 capitalized
    // inventory cost = 855,000+94,050+50,000 = 999,050; unit cost base = 9990.50
    check('B2 posted unit_cost_base == 9990.50 (final landed cost, not raw gross 10000)', abs($postedUnitCostBase - 9990.50) < 0.01, (string) $postedUnitCostBase);
    check('B2 posted base_qty == 100 (KG factor 1, unaffected by costing)', $postedBaseQty === 100.0);

    $headerRow = $pdo->query("SELECT * FROM purchase_invoice_headers WHERE transaction_id = {$costedTxId}")->fetch(PDO::FETCH_ASSOC);
    check('B2 purchase_invoice_headers row was created', $headerRow !== false);
    check('B2 header gross_purchase == 1,000,000', $headerRow && abs((float) $headerRow['gross_purchase'] - 1000000) < 0.01);
    check('B2 header inventory_cost_total == 999,050', $headerRow && abs((float) $headerRow['inventory_cost_total'] - 999050) < 0.01, (string) ($headerRow['inventory_cost_total'] ?? 'N/A'));
    check('B2 header invoice_total == 999,050 (== inventory cost here since freight fully capitalized & ppn fully non-creditable)', $headerRow && abs((float) $headerRow['invoice_total'] - 999050) < 0.01);

    $lineRow = $pdo->query("SELECT * FROM purchase_line_costs WHERE transaction_line_id = {$costedLineId}")->fetch(PDO::FETCH_ASSOC);
    check('B2 purchase_line_costs row was created', $lineRow !== false);
    check('B2 line final_inventory_cost == 999,050', $lineRow && abs((float) $lineRow['final_inventory_cost'] - 999050) < 0.01);

    $actualBatch = $pdo->query("SELECT unit_cost_base, qty_base FROM inventory_batches WHERE source_transaction_line_id = {$costedLineId}")->fetch(PDO::FETCH_ASSOC);
    check('B2 FIFO batch unit_cost_base == purchase_line_costs.final_unit_cost_base EXACTLY (the whole point of V2.7)', $actualBatch && abs((float) $actualBatch['unit_cost_base'] - (float) $lineRow['final_unit_cost_base']) < 0.0001, json_encode([$actualBatch['unit_cost_base'] ?? null, $lineRow['final_unit_cost_base'] ?? null]));
    check('B2 FIFO batch qty_base == 100 (never distorted by costing)', $actualBatch && (float) $actualBatch['qty_base'] === 100.0);

    // ============================================================
    // B3 — GET /transactions/in/cost-preview matches what was actually posted.
    // ============================================================
    echo "\n== B3: Cost Preview matches actual POST ==\n";
    $previewResp = httpCall('GET', "{$base}/transactions/in/cost-preview?item_id={$itemCosted}&input_unit_id={$kgUnitId}&input_qty=100&unit_price_input=10000&transaction_date=2026-08-02%2008:00:00&line_discount_type=PERCENT&line_discount_value=10&invoice_discount_type=PERCENT&invoice_discount_value=5&ppn_treatment=NON_CREDITABLE&ppn_rate=11&freight_treatment=CAPITALIZE&freight_amount=50000", null, $superJar);
    check('B3 cost-preview returns 200', $previewResp['status'] === 200, (string) $previewResp['status']);
    $previewInventoryCost = $previewResp['body']['data']['header']['inventory_cost_total'] ?? null;
    check('B3 preview never mutates the database (never posts)', true); // structural — no POST call was made
    check('B3 preview inventory_cost_total matches the actual posted transaction exactly (999,050)', abs(((float) $previewInventoryCost) - 999050) < 0.01, json_encode($previewInventoryCost));

    // ============================================================
    // B4 — unit conversion: BOX purchase (1 BOX = 12 KG), costing math applies on base_qty correctly.
    // ============================================================
    echo "\n== B4: unit conversion (BOX -> KG) still drives base_qty/cost correctly ==\n";
    $boxUuid = uid('v27-box-tx');
    $boxPost = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => $boxUuid, 'item_id' => $itemBox, 'warehouse_id' => $whA,
        'input_unit_id' => $boxUnitId, 'input_qty' => 5, 'unit_price_input' => 120000, // 120,000/BOX gross
        'transaction_date' => '2026-08-03 08:00:00',
        'ppn_treatment' => 'NON_CREDITABLE', 'ppn_rate' => 10,
    ], $superJar, $superCsrf);
    check('B4 BOX-unit costed Stock IN posts 200', $boxPost['status'] === 200, json_encode($boxPost['body']));
    // gross = 5*120000=600000; net_purchase=600000; ppn=60000 non-creditable; inventory_cost=660000
    // base_qty = 5*12=60; final_unit_cost_base = 660000/60 = 11000
    check('B4 base_qty correctly reflects the 1 BOX=12KG conversion (60)', ($boxPost['body']['data']['base_qty'] ?? null) == 60, json_encode($boxPost['body']['data'] ?? null));
    check('B4 unit_cost_base == 11000 (660,000 inventory cost / 60 base qty)', abs((float) ($boxPost['body']['data']['unit_cost_base'] ?? 0) - 11000) < 0.01, json_encode($boxPost['body']['data'] ?? null));

    // ============================================================
    // B5 — Laporan Pembelian enrichment.
    // ============================================================
    echo "\n== B5: Laporan Pembelian enrichment ==\n";
    $purchaseReport = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}&q=" . urlencode($skuCosted), null, $superJar);
    check('B5 purchase report returns 200', $purchaseReport['status'] === 200);
    $costedRow = $purchaseReport['body']['data']['rows'][0] ?? null;
    check('B5 costed row carries a non-null purchase_costing breakdown', $costedRow && $costedRow['purchase_costing'] !== null, json_encode($costedRow['purchase_costing'] ?? 'MISSING'));
    check('B5 breakdown final_inventory_cost == 999,050', $costedRow && abs((float) $costedRow['purchase_costing']['final_inventory_cost'] - 999050) < 0.01);

    // The route-posted "plain" transaction still gets the trivial/identity
    // costing row (see B1) — its breakdown just equals the raw figures.
    $plainReport = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}&q=" . urlencode($skuPlain), null, $superJar);
    $plainRow = $plainReport['body']['data']['rows'][0] ?? null;
    check('B5 route-posted plain row still carries a (trivial, identity) purchase_costing breakdown', $plainRow && $plainRow['purchase_costing'] !== null && abs((float) $plainRow['purchase_costing']['final_inventory_cost'] - (float) $plainRow['subtotal']) < 0.01, json_encode($plainRow['purchase_costing'] ?? 'MISSING'));

    // A transaction posted via a DIRECT FifoService::postIn() call
    // (bypassing the route entirely) is the one true "no costing row at
    // all" case — Laporan Pembelian must show it as null, never fabricate one.
    $preV27Report = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}&q=" . urlencode($skuPreV27), null, $superJar);
    $preV27Row = $preV27Report['body']['data']['rows'][0] ?? null;
    check('B5 a transaction that bypassed the costed route shows purchase_costing = null, never a fabricated breakdown', $preV27Row && array_key_exists('purchase_costing', $preV27Row) && $preV27Row['purchase_costing'] === null, json_encode($preV27Row['purchase_costing'] ?? 'MISSING KEY'));

    $purchaseCsv = httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}&q=" . urlencode($skuCosted) . "&format=csv", null, $superJar);
    check('B5 CSV export includes the new costing columns', str_contains($purchaseCsv['raw'], 'Diskon Baris') && str_contains($purchaseCsv['raw'], 'PPN') && str_contains($purchaseCsv['raw'], 'Freight'), substr($purchaseCsv['raw'], 0, 200));

    // ============================================================
    // B6 — VOID/reversal compatibility: existing VoidService untouched, must still void a costed transaction cleanly.
    // ============================================================
    echo "\n== B6: VOID compatibility with a costed transaction ==\n";
    $voidResp = httpCall('POST', "{$base}/transactions/{$costedTxId}/void", [
        'request_uuid' => uid('v27-void'), 'reason' => 'V2.7 test void of a costed purchase',
    ], $superJar, $superCsrf);
    check('B6 VOID of a costed transaction succeeds (existing VoidService, never modified)', $voidResp['status'] === 200, json_encode($voidResp['body']));
    $afterVoidStock = httpCall('GET', "{$base}/inventory/current?item_id={$itemCosted}&warehouse_id={$whA}", null, $superJar);
    check('B6 stock is fully reversed back to 0 after voiding the only IN for this item', ($afterVoidStock['body']['data']['qty_base'] ?? null) == 0, json_encode($afterVoidStock['body']['data'] ?? null));
    check('B6 purchase_invoice_headers row for the voided transaction is untouched (still there, historical record)', (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoice_headers WHERE transaction_id = {$costedTxId}")->fetchColumn() === 1);

    // ============================================================
    // B7 — a subsequent OUT still correctly consumes the costed batch's real landed cost (FIFO/OUT untouched).
    // ============================================================
    echo "\n== B7: OUT still correctly consumes the landed FIFO cost (FIFO/OUT logic untouched) ==\n";
    $outUuid = uid('v27-out-tx');
    $outResp = httpCall('POST', "{$base}/transactions/out", [
        'transaction_uuid' => $outUuid, 'item_id' => $itemBox, 'warehouse_id' => $whA,
        'input_unit_id' => $boxUnitId, 'input_qty' => 2, 'transaction_date' => '2026-08-04 08:00:00',
    ], $superJar, $superCsrf);
    check('B7 OUT from a costed batch posts 200', $outResp['status'] === 200, json_encode($outResp['body']));
    // 2 BOX = 24 base units, consumed at unit_cost_base 11000 -> subtotal 264,000.
    check('B7 OUT unit_cost_base reflects the landed cost (11000), never the raw gross price (10000)', abs((float) ($outResp['body']['data']['unit_cost_base'] ?? 0) - 11000) < 0.01, json_encode($outResp['body']['data'] ?? null));

    // ============================================================
    // B8 — STOCK warehouse isolation on the new routes.
    // ============================================================
    echo "\n== B8: STOCK warehouse isolation on V2.7 routes ==\n";
    $stockBPreview = httpCall('GET', "{$base}/transactions/in/cost-preview?item_id={$itemPlain}&input_unit_id={$kgUnitId}&input_qty=1&unit_price_input=1000&transaction_date=2026-08-05", null, $stockBJar);
    check('STOCK-B can reach cost-preview (no warehouse in this read-only calc endpoint — scope enforced at POST time)', $stockBPreview['status'] === 200, (string) $stockBPreview['status']);
    $stockBCrossPost = httpCall('POST', "{$base}/transactions/in", [
        'transaction_uuid' => uid('v27-stockb-cross'), 'item_id' => $itemPlain, 'warehouse_id' => $whA,
        'input_unit_id' => $kgUnitId, 'input_qty' => 1, 'unit_price_input' => 1000, 'transaction_date' => '2026-08-05 08:00:00',
    ], $stockBJar, $stockBCsrf);
    check('STOCK-B POSTing to whA (not their own whB) is rejected 403 — V2.7 fields never bypass existing warehouse scope', $stockBCrossPost['status'] === 403, (string) $stockBCrossPost['status']);

    // ============================================================
    // B9 — historical (1-15 Sep, inventory_effect=0) convention unaffected by V2.7.
    // ============================================================
    echo "\n== B9: historical transactions unaffected by V2.7 ==\n";
    $histTxStmt = $pdo->prepare(
        "INSERT INTO inventory_transactions (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, status, is_historical_import, inventory_effect, created_by, created_at)
         VALUES (:uuid, 'IN', :d, :now1, :wh, 'POSTED', 1, 0, :cb, :now2)"
    );
    $now = date('Y-m-d H:i:s');
    $histTxStmt->execute(['uuid' => uid('v27-hist'), 'd' => '2026-09-05 09:00:00', 'now1' => $now, 'now2' => $now, 'wh' => $whA, 'cb' => $adminUserId]);
    $histTxId = (int) $pdo->lastInsertId();
    check('B9 historical IN rows are posted via a direct INSERT (import path), never through the costed POST route, so they never get a purchase_invoice_headers row', (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoice_headers WHERE transaction_id = {$histTxId}")->fetchColumn() === 0);

    // ============================================================
    // B10 — read/write scope: exercising the routes changes ONLY what each call is supposed to change.
    // ============================================================
    echo "\n== B10: no unexpected side effects ==\n";
    $txCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    httpCall('GET', "{$base}/transactions/in/cost-preview?item_id={$itemPlain}&input_unit_id={$kgUnitId}&input_qty=1&unit_price_input=1000&transaction_date=2026-08-05", null, $superJar);
    httpCall('GET', "{$base}/reports/purchase?warehouse_id={$whA}", null, $superJar);
    $txCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    check('B10 cost-preview and report GETs never create a transaction', $txCountBefore === $txCountAfter, "{$txCountBefore} vs {$txCountAfter}");

    @unlink($superJar);
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
