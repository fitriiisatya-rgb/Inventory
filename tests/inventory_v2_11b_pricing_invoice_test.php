<?php
declare(strict_types=1);

/**
 * PHASE V2.11B — Pricing Policy (company/category/SKU) + Invoice
 * generation from a dispatched Delivery Order, against real MySQL/MariaDB.
 *
 * Reuses ItemPriceService unmodified for the reference purchase price
 * (same source as V2.10's Stock IN auto-fill) — this file never
 * re-implements or re-audits that; it only proves PricingPolicyService's
 * hierarchy/arithmetic and DistributionInvoiceService's snapshot/
 * immutability guarantees on top of it.
 *
 * Usage: php tests/inventory_v2_11b_pricing_invoice_test.php
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
require_once __DIR__ . '/../services/ItemPriceService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/DistributionOrderService.php';
require_once __DIR__ . '/../services/PricingPolicyService.php';
require_once __DIR__ . '/../services/DistributionInvoiceService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\DistributionOrderService;
use App\Services\PricingPolicyService;
use App\Services\DistributionInvoiceService;
use App\Services\ValidationException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) < $eps; }

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$boxUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='BOX'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v211bsetup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V2.11B Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('SCM', 'SCM / Gudang Besar', 1)")->execute();
$scmId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO bakery_destinations (code, name, address, is_active) VALUES (:c,:n,:a,1)')
    ->execute(['c' => uid('BAKERY'), 'n' => 'Bakery Test', 'a' => 'Jl. Test No. 1']);
$bakeryId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('CAT-A'), 'n' => 'Bahan Baku']);
$categoryAId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => uid('CAT-B'), 'n' => 'Packaging']);
$categoryBId = (int) $pdo->lastInsertId();

function makeItem(PDO $pdo, int $unitId, string $tag, ?int $categoryId = null): array
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
        'transaction_uuid' => uid('v211b-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-08-01 08:00:00', 'created_by' => $by, 'username' => 'v211b', 'transaction_type' => 'OPENING',
    ]));
}
/** Creates + fully dispatches a single-line DO, returns the DO detail array (with lines). */
function dispatchedDo(PDO $pdo, int $itemId, int $unitId, int $whId, int $bakeryId, float $qty, int $by): array
{
    $do = DistributionOrderService::create($pdo, [
        'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $whId,
        'created_by' => $by, 'lines' => [['item_id' => $itemId, 'input_qty' => $qty, 'input_unit_id' => $unitId]],
    ]);
    DistributionOrderService::approve($pdo, $do['do_id'], ['created_by' => $by]);
    DistributionOrderService::startPicking($pdo, $do['do_id'], ['created_by' => $by]);
    DistributionOrderService::dispatch($pdo, $do['do_id'], ['created_by' => $by, 'request_uuid' => uid('req')]);
    return DistributionOrderService::get($pdo, $do['do_id']);
}

// ============================================================
// PRICING POLICY — hierarchy + methods
// ============================================================
echo "== PRICING: resolve() hierarchy (Part 9) ==\n";
[$itemH1, $skuH1] = makeItem($pdo, $kgUnitId, 'V211B-H1', $categoryAId);

$noPolicy = PricingPolicyService::resolve($pdo, $itemH1, $categoryAId);
check('no policy at any level -> resolve() returns null (never invents AT_COST)', $noPolicy === null);

PricingPolicyService::upsert($pdo, ['scope' => 'COMPANY', 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 5, 'created_by' => $adminUserId]);
$companyOnly = PricingPolicyService::resolve($pdo, $itemH1, $categoryAId);
check('company default resolves when nothing more specific exists', $companyOnly !== null && $companyOnly['scope'] === 'COMPANY' && abs($companyOnly['margin_value'] - 5) < 0.001, json_encode($companyOnly));

PricingPolicyService::upsert($pdo, ['scope' => 'CATEGORY', 'category_id' => $categoryAId, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 3, 'created_by' => $adminUserId]);
$categoryBeatsCompany = PricingPolicyService::resolve($pdo, $itemH1, $categoryAId);
check('CATEGORY policy beats COMPANY default', $categoryBeatsCompany['scope'] === 'CATEGORY' && abs($categoryBeatsCompany['margin_value'] - 3) < 0.001, json_encode($categoryBeatsCompany));

PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemH1, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 6, 'created_by' => $adminUserId]);
$skuBeatsCategory = PricingPolicyService::resolve($pdo, $itemH1, $categoryAId);
check('SKU override beats CATEGORY', $skuBeatsCategory['scope'] === 'SKU' && abs($skuBeatsCategory['margin_value'] - 6) < 0.001, json_encode($skuBeatsCategory));

[$itemH2, $skuH2] = makeItem($pdo, $kgUnitId, 'V211B-H2', $categoryAId); // same category, no SKU override
$categoryFallbackForOtherSku = PricingPolicyService::resolve($pdo, $itemH2, $categoryAId);
check('a different SKU in the same category without its own override uses the CATEGORY policy (3%)', $categoryFallbackForOtherSku['scope'] === 'CATEGORY' && abs($categoryFallbackForOtherSku['margin_value'] - 3) < 0.001, json_encode($categoryFallbackForOtherSku));

PricingPolicyService::upsert($pdo, ['scope' => 'CATEGORY', 'category_id' => $categoryBId, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 8, 'created_by' => $adminUserId]);
[$itemH3, $skuH3] = makeItem($pdo, $kgUnitId, 'V211B-H3', $categoryBId);
$categoryBPolicy = PricingPolicyService::resolve($pdo, $itemH3, $categoryBId);
check('Category A (3%) and Category B (8%) resolve independently', abs($categoryBPolicy['margin_value'] - 8) < 0.001, json_encode($categoryBPolicy));

echo "\n== PRICING: AT_COST / COST_PLUS_PERCENT / COST_PLUS_AMOUNT arithmetic ==\n";
check('AT_COST: selling = reference exactly', approx(PricingPolicyService::calculateSellingPrice(100.0, 'AT_COST', 0), 100.0));
check('COST_PLUS_PERCENT: 100 + 10% = 110', approx(PricingPolicyService::calculateSellingPrice(100.0, 'COST_PLUS_PERCENT', 10), 110.0));
check('COST_PLUS_AMOUNT: 100 + 15 = 115', approx(PricingPolicyService::calculateSellingPrice(100.0, 'COST_PLUS_AMOUNT', 15), 115.0));

echo "\n== PRICING: invalid pricing rejected ==\n";
$invalidMethodRejected = false;
try {
    PricingPolicyService::upsert($pdo, ['scope' => 'COMPANY', 'pricing_method' => 'MADE_UP_METHOD', 'margin_value' => 5, 'created_by' => $adminUserId]);
} catch (ValidationException $e) { $invalidMethodRejected = true; }
check('invalid pricing_method rejected', $invalidMethodRejected);

$negativeMarginRejected = false;
try {
    PricingPolicyService::upsert($pdo, ['scope' => 'COMPANY', 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => -5, 'created_by' => $adminUserId]);
} catch (ValidationException $e) { $negativeMarginRejected = true; }
check('negative margin_value rejected', $negativeMarginRejected);

// ============================================================
// UNIT CONVERSION PRICING
// ============================================================
echo "\n== PRICING: reference price respects the invoiced unit, never a silent unit-price error ==\n";
[$itemU, $skuU] = makeItem($pdo, $kgUnitId, 'V211B-UNIT');
UnitConversionService::openNewVersion($pdo, $itemU, $boxUnitId, 12.0, '2020-01-01 00:00:00', null, '1 BOX = 12 KG base');
postOpeningIn($pdo, $itemU, $boxUnitId, $scmId, 10, 120000, $adminUserId); // 10000/KG base
PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemU, 'pricing_method' => 'AT_COST', 'margin_value' => 0, 'created_by' => $adminUserId]);
$doUnit = dispatchedDo($pdo, $itemU, $boxUnitId, $scmId, $bakeryId, 2, $adminUserId);
$invUnit = DistributionInvoiceService::create($pdo, ['do_id' => $doUnit['id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
$invUnitDetail = DistributionInvoiceService::get($pdo, $invUnit['invoice_id']);
check('invoiced in BOX uses the real BOX purchase price (120000), never the raw per-KG figure', approx((float) $invUnitDetail['lines'][0]['reference_purchase_price'], 120000.0), json_encode($invUnitDetail['lines'][0]));

// ============================================================
// INVOICE — creation gates, snapshot immutability, override
// ============================================================
echo "\n== INVOICE: creation blocked before dispatch, allowed after ==\n";
[$itemG, $skuG] = makeItem($pdo, $kgUnitId, 'V211B-GATE');
postOpeningIn($pdo, $itemG, $kgUnitId, $scmId, 50, 1000, $adminUserId);
PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemG, 'pricing_method' => 'AT_COST', 'margin_value' => 0, 'created_by' => $adminUserId]);
$doGate = DistributionOrderService::create($pdo, [
    'do_date' => '2026-09-23', 'bakery_destination_id' => $bakeryId, 'from_warehouse_id' => $scmId,
    'created_by' => $adminUserId, 'lines' => [['item_id' => $itemG, 'input_qty' => 5, 'input_unit_id' => $kgUnitId]],
]);
$blockedBeforeDispatch = false;
try {
    DistributionInvoiceService::create($pdo, ['do_id' => $doGate['do_id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
} catch (ValidationException $e) { $blockedBeforeDispatch = true; }
check('Invoice creation blocked while DO is DRAFT', $blockedBeforeDispatch);

DistributionOrderService::approve($pdo, $doGate['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::startPicking($pdo, $doGate['do_id'], ['created_by' => $adminUserId]);
DistributionOrderService::dispatch($pdo, $doGate['do_id'], ['created_by' => $adminUserId, 'request_uuid' => uid('req')]);
$invGate = DistributionInvoiceService::create($pdo, ['do_id' => $doGate['do_id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
check('Invoice creation succeeds once DO is DISPATCHED', $invGate['success'] === true, json_encode($invGate));
check('Invoice number format INV-YYYYMMDD-####', preg_match('/^INV-20260923-\d{4}$/', $invGate['invoice_number']) === 1, $invGate['invoice_number']);

echo "\n== INVOICE: one invoice per DO enforced ==\n";
$secondInvoiceRejected = false;
try {
    DistributionInvoiceService::create($pdo, ['do_id' => $doGate['do_id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
} catch (ValidationException $e) { $secondInvoiceRejected = true; }
check('a second Invoice for the same DO is rejected', $secondInvoiceRejected);

echo "\n== INVOICE: policy change never alters an already-created invoice (Part 22) ==\n";
[$itemP, $skuP] = makeItem($pdo, $kgUnitId, 'V211B-POLICYCHANGE');
postOpeningIn($pdo, $itemP, $kgUnitId, $scmId, 40, 100, $adminUserId);
PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemP, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 10, 'created_by' => $adminUserId]);
$doPolicy = dispatchedDo($pdo, $itemP, $kgUnitId, $scmId, $bakeryId, 3, $adminUserId);
$invPolicy = DistributionInvoiceService::create($pdo, ['do_id' => $doPolicy['id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
$invPolicyDetailBefore = DistributionInvoiceService::get($pdo, $invPolicy['invoice_id']);
check('invoice line priced at 100 + 10% = 110', approx((float) $invPolicyDetailBefore['lines'][0]['selling_unit_price'], 110.0), json_encode($invPolicyDetailBefore['lines'][0]));

// Change the SKU policy AFTER the invoice already exists.
PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemP, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 50, 'created_by' => $adminUserId]);
$invPolicyDetailAfter = DistributionInvoiceService::get($pdo, $invPolicy['invoice_id']);
check('the existing invoice line price is UNCHANGED after the policy changed (still 110, not 150)', approx((float) $invPolicyDetailAfter['lines'][0]['selling_unit_price'], 110.0), json_encode($invPolicyDetailAfter['lines'][0]));

echo "\n== INVOICE: price override does not modify the underlying policy ==\n";
$policyBeforeOverride = PricingPolicyService::resolve($pdo, $itemP, null);
DistributionInvoiceService::overrideLinePrice($pdo, $invPolicy['invoice_id'], $invPolicyDetailAfter['lines'][0]['id'], [
    'selling_unit_price' => 999, 'override_reason' => 'Harga khusus negosiasi', 'created_by' => $adminUserId,
]);
$policyAfterOverride = PricingPolicyService::resolve($pdo, $itemP, null);
check('overriding an invoice line price leaves the SKU pricing policy margin unchanged (still 50%)', abs($policyAfterOverride['margin_value'] - 50) < 0.001, json_encode($policyAfterOverride));
$invAfterOverride = DistributionInvoiceService::get($pdo, $invPolicy['invoice_id']);
check('the overridden line now shows selling_unit_price=999 and is_price_overridden=1', approx((float) $invAfterOverride['lines'][0]['selling_unit_price'], 999.0) && (int) $invAfterOverride['lines'][0]['is_price_overridden'] === 1, json_encode($invAfterOverride['lines'][0]));
check('header subtotal/grand_total recalculated after the override', approx((float) $invAfterOverride['subtotal'], 999.0 * (float) $invAfterOverride['lines'][0]['qty']), json_encode($invAfterOverride));

echo "\n== INVOICE: override blocked once ISSUED (financial snapshot immutable, Part 22) ==\n";
DistributionInvoiceService::issue($pdo, $invPolicy['invoice_id'], ['created_by' => $adminUserId]);
$overrideAfterIssueBlocked = false;
try {
    DistributionInvoiceService::overrideLinePrice($pdo, $invPolicy['invoice_id'], $invAfterOverride['lines'][0]['id'], [
        'selling_unit_price' => 1, 'override_reason' => 'should be rejected', 'created_by' => $adminUserId,
    ]);
} catch (ValidationException $e) { $overrideAfterIssueBlocked = true; }
check('override rejected once the invoice is ISSUED', $overrideAfterIssueBlocked);

echo "\n== INVOICE: missing pricing policy blocks creation (never invents a price) ==\n";
[$itemNoPolicy, $skuNoPolicy] = makeItem($pdo, $kgUnitId, 'V211B-NOPOLICY');
postOpeningIn($pdo, $itemNoPolicy, $kgUnitId, $scmId, 20, 500, $adminUserId);
// Deliberately clear the company default so NOTHING resolves for this item.
$companyPolicyRow = $pdo->query("SELECT id FROM distribution_pricing_policies WHERE scope='COMPANY' AND is_active=1")->fetchColumn();
if ($companyPolicyRow) { PricingPolicyService::deactivate($pdo, (int) $companyPolicyRow, ['created_by' => $adminUserId]); }
$doNoPolicy = dispatchedDo($pdo, $itemNoPolicy, $kgUnitId, $scmId, $bakeryId, 2, $adminUserId);
$noPolicyBlocked = false;
try {
    DistributionInvoiceService::create($pdo, ['do_id' => $doNoPolicy['id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
} catch (ValidationException $e) { $noPolicyBlocked = true; }
check('Invoice creation blocked when no pricing policy resolves at any level (missing-policy fallback)', $noPolicyBlocked);

// ============================================================
// ACCOUNTING — actual margin != policy margin (Part 37's worked example)
// ============================================================
echo "\n== ACCOUNTING: Actual Margin derived from real FIFO HPP, never the policy percentage (Part 14/34/37) ==\n";
[$itemM, $skuM] = makeItem($pdo, $kgUnitId, 'V211B-MARGIN');
// Reference purchase price 100/KG, but a SECOND, more expensive purchase
// makes the REAL weighted FIFO cost of what gets dispatched different
// from the flat reference — exactly the "policy margin != actual margin"
// scenario the spec's worked example describes.
postOpeningIn($pdo, $itemM, $kgUnitId, $scmId, 5, 100, $adminUserId);
postOpeningIn($pdo, $itemM, $kgUnitId, $scmId, 5, 106, $adminUserId); // reference price is now 106 (latest), but FIFO will consume the cheaper layer first
PricingPolicyService::upsert($pdo, ['scope' => 'SKU', 'item_id' => $itemM, 'pricing_method' => 'COST_PLUS_PERCENT', 'margin_value' => 10, 'created_by' => $adminUserId]);
$doMargin = dispatchedDo($pdo, $itemM, $kgUnitId, $scmId, $bakeryId, 5, $adminUserId); // consumes exactly the first 5kg layer @ 100
$invMargin = DistributionInvoiceService::create($pdo, ['do_id' => $doMargin['id'], 'invoice_date' => '2026-09-23', 'created_by' => $adminUserId]);
$invMarginDetail = DistributionInvoiceService::get($pdo, $invMargin['invoice_id']);

$revenue = (float) $invMarginDetail['lines'][0]['subtotal'];
$doLineId = $invMarginDetail['lines'][0]['do_line_id'];
$doLineRow = $pdo->prepare('SELECT out_transaction_line_id, qty_sent_base FROM distribution_order_lines WHERE id = :id');
$doLineRow->execute(['id' => $doLineId]);
$doLineRow = $doLineRow->fetch();
$actualUnitCost = (float) $pdo->query('SELECT unit_cost_base FROM inventory_transaction_lines WHERE id = ' . (int) $doLineRow['out_transaction_line_id'])->fetchColumn();
$actualHpp = round($actualUnitCost * (float) $doLineRow['qty_sent_base'], 4);
$actualMargin = round($revenue - $actualHpp, 4);
$actualMarginPct = $actualHpp > 0 || $revenue > 0 ? round(($actualMargin / $revenue) * 100, 4) : 0;

// Worked out precisely: reference price = 106 (latest KG purchase, per
// ItemPriceService), selling = 106 * 1.10 = 116.6, revenue = 5 * 116.6 =
// 583.0. FIFO dispatches the OLDEST layer first (100/KG), so actual HPP =
// 5 * 100 = 500.0, actual margin = 83.0, actual margin% = 83/583*100 = 14.24%
// — deliberately NOT the configured 10% policy margin.
check('reference price resolved to the latest purchase (106), not the first (100)', approx((float) $invMarginDetail['lines'][0]['reference_purchase_price'], 106.0), json_encode($invMarginDetail['lines'][0]));
check('Revenue = 5 * (106 * 1.10) = 583.0', approx($revenue, 583.0), (string) $revenue);
check('Actual FIFO HPP reflects the REAL consumed layer cost (100/KG = 500.0 total), independent of the 106 reference price', approx($actualHpp, 500.0), (string) $actualHpp);
check('Actual Margin = Revenue - Actual HPP = 83.0 (never derived from the 10% policy figure)', approx($actualMargin, 83.0), (string) $actualMargin);
check('Actual Margin % ~= 14.24%, NOT the configured 10% policy margin', approx($actualMarginPct, 14.2367, 0.01), (string) $actualMarginPct);

$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$aTotal}  PASSED: {$aPassed}  FAILED: " . ($aTotal - $aPassed) . "\n";
exit($aPassed === $aTotal ? 0 : 1);
