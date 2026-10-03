<?php
declare(strict_types=1);

/**
 * PHASE V2.16.6 — proves the report-specific Print/PDF
 * (StockOpnameMonthlyReportPrintService + GET /stock-opname-reports/{id}/
 * print) is a genuinely separate, finance-oriented, A4-LANDSCAPE document
 * — never the old StockOpnamePrintService (A4 portrait, discrepancy-only,
 * no Rupiah values) — and that it prints EVERY item row, not only the
 * current UI page.
 *
 * Also proves StockOpnameMonthlyReportService::detailForPrint() returns
 * every matching line unpaginated while detail() (used by the on-screen
 * report) still honours page/per_page — both paths share the exact same
 * formatLine()/summary logic (buildDetail()), so they can never disagree.
 *
 * Usage: php tests/inventory_v2_16_6_print_service_test.php
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
require_once __DIR__ . '/../services/StockOpnameBookStockService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportPrintService.php';
require_once __DIR__ . '/../services/StockOpnamePrintService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnameMonthlyReportService;
use App\Services\StockOpnameMonthlyReportPrintService;
use App\Services\StockOpnamePrintService;

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
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('V2166WH1', 'V2.16.6 WH1', 1)")->execute();
$whId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('V2166-CAT'), 'n' => 'V2166 Kategori']);
$catId = (int) $pdo->lastInsertId();

function makeUser(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = uid($tag);
    $pass = 'V2166' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}
function makeItem(PDO $pdo, int $unitId, string $tag, int $categoryId): int
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return $id;
}
function postOpeningIn(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('v2166-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => '2026-01-01 08:00:00', 'created_by' => $by, 'username' => 'v2166', 'transaction_type' => 'OPENING',
    ]));
}

$adminId = makeUser($pdo, 'v2166admin', $superRoleId)['id'];
$p1Id = makeUser($pdo, 'v2166p1', $stockRoleId, $whId)['id'];
$p2Id = makeUser($pdo, 'v2166p2', $stockRoleId, $whId)['id'];

// ============================================================
// 30 items — deliberately MORE than the UI's largest per_page (25), so
// "the print shows every row, not only the current UI page" is a real
// test, not a tautology. One item gets Rusak+Expired+Deadstock so the
// print's condition columns have something nonzero to show.
// ============================================================
$itemIds = [];
for ($i = 1; $i <= 30; $i++) {
    $itemIds[] = makeItem($pdo, $kgUnitId, "V2166-I{$i}", $catId);
}
foreach ($itemIds as $itemId) {
    postOpeningIn($pdo, $itemId, $kgUnitId, $whId, 100.0, 1000.0, $adminId); // system value 100,000 each
}

$sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whId, $adminId, $itemIds, 'LEGACY_DUAL_COUNT'));
Database::transaction(fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, ['p1_user_id' => $p1Id, 'p2_user_id' => $p2Id], $adminId));
$pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-05-10', 'id' => $sessionId]);

foreach ($itemIds as $idx => $itemId) {
    if ($idx === 0) {
        // the one item with nonzero Rusak/Expired/Deadstock + a real variance.
        // P1/P2 QTY disagree (88 vs 90) -> MISMATCH, so recount() is valid.
        Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemId, 88.0, $p1Id, ['rusak_qty' => 5.0, 'expired_qty' => 3.0, 'deadstock_qty' => 2.0]));
        Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $itemId, 90.0, $p2Id, ['rusak_qty' => 5.0, 'expired_qty' => 3.0, 'deadstock_qty' => 2.0]));
        Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sessionId, $itemId, 90.0, 'supervisor resolve conditions', $adminId, ['final_rusak_qty' => 5.0, 'final_expired_qty' => 3.0, 'final_deadstock_qty' => 2.0]));
    } else {
        Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p1', $itemId, 100.0, $p1Id));
        Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, 'p2', $itemId, 100.0, $p2Id));
    }
}

Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sessionId, $adminId));
Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sessionId, $adminId));

$postedSession = StockOpnameService::get($pdo, $sessionId);
check('setup: 30-item fixture session reaches POSTED', $postedSession['status'] === 'POSTED', (string) $postedSession['status']);

echo "\n== detailForPrint() vs detail() pagination ==\n";

$page1 = StockOpnameMonthlyReportService::detail($pdo, $sessionId, ['page' => 1, 'per_page' => 25]);
check('1. detail() page 1/per_page 25 returns exactly 25 items (paginated)', count($page1['items']) === 25, (string) count($page1['items']));
check('1b. detail() reports total=30 even though this page only has 25', $page1['total'] === 30, (string) $page1['total']);

$printData = StockOpnameMonthlyReportService::detailForPrint($pdo, $sessionId);
check('2. detailForPrint() returns ALL 30 items unpaginated', count($printData['items']) === 30, (string) count($printData['items']));
check('2b. detailForPrint() total=30, matching its own item count', $printData['total'] === 30);
check('2c. detailForPrint() finance_summary total_item_scope=30 (same aggregate as detail())', $printData['finance_summary']['total_item_scope'] === 30);
check(
    '2d. detailForPrint() and detail() agree on finance_summary (same underlying computation)',
    $printData['finance_summary'] == $page1['finance_summary']
);

echo "\n== StockOpnameMonthlyReportPrintService::renderResult() ==\n";

$html = StockOpnameMonthlyReportPrintService::renderResult($pdo, $sessionId, 'v2166tester');

check('3. [A4 LANDSCAPE] print CSS declares @page size A4 landscape', (bool) preg_match('/@page\s*\{\s*size:\s*A4\s*landscape/i', $html));
check('3b. print brand header shows AMORCAKES AND BAKERY', str_contains($html, 'AMORCAKES AND BAKERY'));
check('3c. print brand header shows PT. Inovasi Sukses Persada', str_contains($html, 'PT. Inovasi Sukses Persada'));
check('3d. print title shows LAPORAN STOCK OPNAME', str_contains($html, 'LAPORAN STOCK OPNAME'));

foreach (['No. SO', 'Gudang', 'Tanggal SO', 'Status', 'Finalized', 'Posted', 'Counting Model'] as $label) {
    check("4. header info row '{$label}' present", str_contains($html, $label));
}

foreach (['Total Item', 'Sesuai', 'Selisih (+)', 'Selisih (-)', 'Rusak', 'Expired', 'Deadstock',
          'Nilai Stok Sistem', 'Nilai Stok Fisik Final', 'Selisih Nilai',
          'Qty Sistem', 'Qty Fisik Final', 'Selisih Qty'] as $label) {
    check("5. finance summary field '{$label}' present", str_contains($html, $label));
}

foreach (['No', 'Kode Barang', 'Nama Barang', 'Kategori', 'Satuan',
          'Stok Sistem Qty', 'Stok Sistem Nilai', 'Stok Fisik Final Qty', 'Stok Fisik Final Nilai',
          'Selisih Qty', 'Selisih Nilai', 'Rusak', 'Expired', 'Deadstock', 'Keterangan'] as $label) {
    check("6. detail table column '{$label}' present", str_contains($html, "<th>{$label}</th>"));
}

check('7. print contains Rupiah ("Rp ") values', str_contains($html, 'Rp '));
check('7b. print contains the known system value for one row (Rp 100.000)', str_contains($html, '100.000'));

$hppLeak = (bool) preg_match('/\bHPP\b|Unit Cost|Harga Pokok/i', $html);
check('9. [MANDATORY] print has NO HPP/Unit Cost/Harga Pokok text anywhere', !$hppLeak, $hppLeak ? 'LEAK DETECTED' : 'clean');

preg_match('/<tbody>(.*)<\/tbody>/s', $html, $tbodyMatch);
$rowCount = isset($tbodyMatch[1]) ? substr_count($tbodyMatch[1], '<tr>') : 0;
check('10. [MANDATORY] print includes ALL 30 item rows, not just the 25-row UI page', $rowCount === 30, (string) $rowCount);

check('repeated headers: thead set to display:table-header-group for pagination', str_contains($html, 'thead { display: table-header-group; }'));
check('printed datetime present ("Dicetak:")', str_contains($html, 'Dicetak:'));
check('printed-by username present when supplied', str_contains($html, 'v2166tester'));

echo "\n== Old StockOpnamePrintService (untouched) still renders its OWN different format ==\n";

$oldHtml = StockOpnamePrintService::renderResult($pdo, $sessionId);
check('11. old print is A4 PORTRAIT (unchanged)', (bool) preg_match('/@page\s*\{\s*size:\s*A4\s*portrait/i', $oldHtml));
check('11b. old print title is bare "STOCK OPNAME", not "LAPORAN STOCK OPNAME"', str_contains($oldHtml, '>STOCK OPNAME<') && !str_contains($oldHtml, 'LAPORAN STOCK OPNAME'));
check('11c. old print has NO Rupiah ("Rp ") values (it never had a finance summary)', !str_contains($oldHtml, 'Rp '));
check('11d. old print has NO AMORCAKES AND BAKERY / PT. Inovasi Sukses Persada header (that is this report\'s own branding)', !str_contains($oldHtml, 'AMORCAKES AND BAKERY'));

$passed = count(array_filter($results));
$total = count($results);
echo "\n{$passed} / {$total} PASSED\n";
exit($passed === $total ? 0 : 1);
