<?php
declare(strict_types=1);

/**
 * PHASE V2.8 — "Import Transaksi Live" (Excel/CSV IN & OUT), against real
 * MySQL/MariaDB. Covers:
 *   A) ImportLiveTransactionService direct (mirrors tests/mysql_importer_test.php's
 *      pattern): IN row creates a REAL FIFO batch (never historical) with a
 *      full V2.7 purchase_invoice_headers/purchase_line_costs breakdown;
 *      OUT row consumes real stock; unsupported transaction_type (e.g.
 *      TRANSFER) is rejected pre-commit; an inactive/PENDING_CUTOVER
 *      warehouse_code (Karang-Tengah-style) is rejected, never silently
 *      posted; chronological (not file-order) processing lets a
 *      later-in-file IN satisfy an earlier-dated OUT; an insufficient-
 *      stock OUT rolls back the WHOLE batch atomically (no partial
 *      commit); a re-uploaded identical file is rejected at stage(); a
 *      double commit() call on the same batch is idempotent (no duplicate
 *      transactions); .xlsx input is read correctly via XlsxReaderService.
 *   B) HTTP integration: IMPORT_MANAGE gate (STOCK role forbidden, even
 *      though STOCK can post manual Stock IN/OUT); full real multipart
 *      upload -> stage -> preview -> commit round trip; the Download
 *      Template endpoint serves exactly ImportLiveTransactionService's
 *      accepted columns.
 *
 * Usage: php tests/inventory_v28_live_transaction_import_test.php
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
require_once __DIR__ . '/../services/PurchaseCostingGateway.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';
require_once __DIR__ . '/../services/ImportLiveTransactionService.php';
require_once __DIR__ . '/../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use App\Services\ExcelWriterService;
use App\Services\ImportTemplateService;
use App\Services\ImportLiveTransactionService;
use App\Services\ImportValidationException;
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
function tmpCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'v28_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

// ============================================================
// Fixtures
// ============================================================
$superadminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => uid('v28setup'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'V28 Setup', 'r' => $superadminRoleId]);
$adminUserId = (int) $pdo->lastInsertId();

$whCode = uid('V28-WH');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $whCode, 'n' => 'V28 Warehouse']);
$whId = (int) $pdo->lastInsertId();

// A Karang-Tengah-style PENDING_CUTOVER warehouse: exists in the DB but is_active=0.
$inactiveWhCode = uid('V28-KT');
$pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 0)')->execute(['c' => $inactiveWhCode, 'n' => 'V28 Pending Cutover']);

$supCode = uid('V28-SUP');
$pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => $supCode, 'n' => 'V28 Supplier']);

function makeItem(PDO $pdo, int $unitId, string $tag): array
{
    $sku = uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, minimum_stock, status) VALUES (:sku, :name, :unit, 0, :status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return [$id, $sku];
}

// ============================================================
// A1 — plain IN row: real FIFO batch + trivial (identity) V2.7 costing row.
// ============================================================
echo "== A1: IN row creates a REAL FIFO batch, never historical ==\n";
[$itemA1, $skuA1] = makeItem($pdo, $kgUnitId, 'V28-A1');
$csvA1 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-01,IN,{$whCode},{$skuA1},50,KG,10000,{$supCode},,REF-A1,catatan\n"
);
$batchA1 = ImportLiveTransactionService::stage($pdo, $csvA1, 'a1.csv', $adminUserId);
$resultA1 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA1, $adminUserId));
check('A1 stage produced exactly 1 VALID row', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchA1}")->fetchColumn());
check('A1 commit imported 1 row', $resultA1['imported'] === 1, json_encode($resultA1));

$stockA1 = InventoryService::currentStock($pdo, $itemA1, $whId);
check('A1 real stock created: 50kg @ Rp10.000 = Rp500.000', approx($stockA1['qty_base'], 50) && approx($stockA1['value'], 500000), "qty={$stockA1['qty_base']} value={$stockA1['value']}");

$txA1 = $pdo->query("SELECT * FROM inventory_transactions WHERE reference_no='REF-A1'")->fetch(PDO::FETCH_ASSOC);
check('A1 transaction is a REAL live posting: is_historical_import=0, inventory_effect=1', $txA1 && (int) $txA1['is_historical_import'] === 0 && (int) $txA1['inventory_effect'] === 1);
check('A1 transaction_type = IN', $txA1 && $txA1['transaction_type'] === 'IN');

$lineA1 = $pdo->query("SELECT * FROM inventory_transaction_lines WHERE transaction_id={$txA1['id']}")->fetch(PDO::FETCH_ASSOC);
$headerA1 = $pdo->query("SELECT * FROM purchase_invoice_headers WHERE transaction_id={$txA1['id']}")->fetch(PDO::FETCH_ASSOC);
check('A1 V2.7 purchase_invoice_headers row exists (real reuse of PurchaseCostingService, not a second engine)', $headerA1 !== false);
check('A1 identity case: gross_purchase == inventory_cost_total == 500.000 (no discount/ppn/freight given)', $headerA1 && approx((float) $headerA1['gross_purchase'], 500000) && approx((float) $headerA1['inventory_cost_total'], 500000));

// ============================================================
// A2 — IN row WITH the full PPN/discount/freight columns: reconciles exactly.
// ============================================================
echo "\n== A2: IN row with discount/PPN/freight columns reconciles ==\n";
[$itemA2, $skuA2] = makeItem($pdo, $kgUnitId, 'V28-A2');
$csvA2 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes,line_discount_type,line_discount_value,invoice_discount_type,invoice_discount_value,ppn_treatment,ppn_rate,ppn_creditable_pct,freight_treatment,freight_amount\n"
    . "2026-08-02,IN,{$whCode},{$skuA2},100,KG,10000,{$supCode},,REF-A2,,PERCENT,10,PERCENT,5,NON_CREDITABLE,11,0,CAPITALIZE,50000\n"
);
$batchA2 = ImportLiveTransactionService::stage($pdo, $csvA2, 'a2.csv', $adminUserId);
$resultA2 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA2, $adminUserId));
check('A2 commit imported 1 row', $resultA2['imported'] === 1, json_encode($resultA2));

// Same figures as V2.7's own B2 case: gross 1,000,000; line disc 10% =
// 100,000; net 900,000; invoice disc 5% of 900,000 = 45,000; net purchase
// 855,000; PPN 11% non-creditable = 94,050 (all into inventory cost);
// freight capitalized 50,000 -> inventory_cost_total = 999,050.
$txA2 = $pdo->query("SELECT * FROM inventory_transactions WHERE reference_no='REF-A2'")->fetch(PDO::FETCH_ASSOC);
$headerA2 = $pdo->query("SELECT * FROM purchase_invoice_headers WHERE transaction_id={$txA2['id']}")->fetch(PDO::FETCH_ASSOC);
check('A2 inventory_cost_total == 999.050 (matches real V2.7 costing math)', $headerA2 && approx((float) $headerA2['inventory_cost_total'], 999050), (string) ($headerA2['inventory_cost_total'] ?? 'n/a'));
$lineA2 = $pdo->query("SELECT * FROM inventory_transaction_lines WHERE transaction_id={$txA2['id']}")->fetch(PDO::FETCH_ASSOC);
check('A2 FIFO batch unit_cost_base == final_unit_cost_base == 9990.50 (999050/100)', $lineA2 && approx((float) $lineA2['unit_cost_base'], 9990.50), (string) ($lineA2['unit_cost_base'] ?? 'n/a'));
check('A2 supplier_code resolved to real supplier_id', $txA2 && $txA2['supplier_id'] !== null);

// ============================================================
// A3 — OUT row consumes real stock (from A1's batch).
// ============================================================
echo "\n== A3: OUT row consumes real stock ==\n";
$csvA3 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-03,OUT,{$whCode},{$skuA1},20,KG,,,,REF-A3,catatan keluar\n"
);
$batchA3 = ImportLiveTransactionService::stage($pdo, $csvA3, 'a3.csv', $adminUserId);
$resultA3 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA3, $adminUserId));
check('A3 commit imported 1 row', $resultA3['imported'] === 1, json_encode($resultA3));
$stockA3 = InventoryService::currentStock($pdo, $itemA1, $whId);
check('A3 stock reduced from 50 to 30 after OUT 20', approx($stockA3['qty_base'], 30), (string) $stockA3['qty_base']);
$txA3 = $pdo->query("SELECT * FROM inventory_transactions WHERE reference_no='REF-A3'")->fetch(PDO::FETCH_ASSOC);
check('A3 transaction_type = OUT, real effect', $txA3 && $txA3['transaction_type'] === 'OUT' && (int) $txA3['inventory_effect'] === 1);

// ============================================================
// A4 — unsupported transaction_type (TRANSFER) is rejected pre-commit.
// ============================================================
echo "\n== A4: unsupported transaction_type rejected ==\n";
$csvA4 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-04,TRANSFER,{$whCode},{$skuA1},5,KG,,,,REF-A4,\n"
);
$batchA4 = ImportLiveTransactionService::stage($pdo, $csvA4, 'a4.csv', $adminUserId);
check('A4 row staged as ERROR (only IN/OUT supported)', 1 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA4}")->fetchColumn());
$rejectedA4 = false;
try { Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA4, $adminUserId)); } catch (ImportValidationException $e) { $rejectedA4 = true; }
check('A4 commit rejected outright', $rejectedA4);

// ============================================================
// A5 — inactive/PENDING_CUTOVER warehouse_code rejected (Karang Tengah safety).
// ============================================================
echo "\n== A5: inactive warehouse_code (Karang-Tengah-style) rejected ==\n";
$csvA5 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-05,IN,{$inactiveWhCode},{$skuA1},5,KG,1000,,,REF-A5,\n"
);
$batchA5 = ImportLiveTransactionService::stage($pdo, $csvA5, 'a5.csv', $adminUserId);
check('A5 row staged as ERROR (unknown or inactive warehouse_code)', 1 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA5}")->fetchColumn());
$rejectedA5 = false;
try { Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA5, $adminUserId)); } catch (ImportValidationException $e) { $rejectedA5 = true; }
check('A5 commit rejected outright — a PENDING_CUTOVER warehouse can never be live-imported into', $rejectedA5);

// ============================================================
// A6 — chronological (not file-order) processing: a later-in-file IN
// satisfies an earlier-dated... no: an EARLIER-DATED IN satisfies a
// LATER-in-file-but-earlier-dated OUT even when the OUT is listed FIRST
// in the file.
// ============================================================
echo "\n== A6: chronological processing, not file order ==\n";
[$itemA6, $skuA6] = makeItem($pdo, $kgUnitId, 'V28-A6');
// File order: OUT first (dated 2026-08-11), IN second (dated 2026-08-10) —
// chronologically the IN happens first and must be available to the OUT.
$csvA6 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-11,OUT,{$whCode},{$skuA6},15,KG,,,,REF-A6-OUT,\n"
    . "2026-08-10,IN,{$whCode},{$skuA6},20,KG,1000,,,REF-A6-IN,\n"
);
$batchA6 = ImportLiveTransactionService::stage($pdo, $csvA6, 'a6.csv', $adminUserId);
$resultA6 = null;
$failedA6 = false;
try {
    $resultA6 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA6, $adminUserId));
} catch (\Throwable $e) {
    $failedA6 = true;
}
check('A6 commit succeeds despite file order (chronological processing lets the earlier-dated IN post first)', !$failedA6 && $resultA6 && $resultA6['imported'] === 2, $failedA6 ? 'threw' : json_encode($resultA6));
$stockA6 = InventoryService::currentStock($pdo, $itemA6, $whId);
check('A6 final stock = 20 IN - 15 OUT = 5', approx($stockA6['qty_base'], 5), (string) $stockA6['qty_base']);

// ============================================================
// A6b — SAME date/time tie-break: mandatory secondary sort key is the
// source Excel row_number, ASC — original file row order must be
// preserved exactly, never reordered by transaction_type or any other
// heuristic. Regression added after the V2.8 gate report per an explicit
// follow-up request. Both target rows sit at literal file rows 10/11
// (9 harmless filler rows precede them) so the tie-break is proven at a
// realistic mid-file position, not just rows 1 vs 2.
// ============================================================
echo "\n== A6b: same date/time tie-break preserves original file row order (row_no ASC) ==\n";

function fillerRows(string $whCode, string $fillSku, int $count): string
{
    $lines = '';
    for ($i = 1; $i <= $count; $i++) {
        $lines .= "2026-07-01,IN,{$whCode},{$fillSku},1,KG,1000,,,REF-FILL-{$i},filler\n";
    }
    return $lines;
}

// A6b-1 — forward order: row 10 = IN +100, row 11 = OUT -50 (same
// date/time). Expected: IN posts first (nothing to reorder — it's
// already first in the file AND chronologically tied), OUT consumes
// that stock, final qty = +50.
[$itemFillFwd, $skuFillFwd] = makeItem($pdo, $kgUnitId, 'V28-A6B-FILL-FWD');
[$itemTieFwd, $skuTieFwd] = makeItem($pdo, $kgUnitId, 'V28-A6B-TIE-FWD');
$csvA6bFwd = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . fillerRows($whCode, $skuFillFwd, 9) // rows 1-9
    . "2026-09-01 08:00:00,IN,{$whCode},{$skuTieFwd},100,KG,1000,,,REF-A6B-FWD-IN,\n"   // row 10
    . "2026-09-01 08:00:00,OUT,{$whCode},{$skuTieFwd},50,KG,,,,REF-A6B-FWD-OUT,\n"      // row 11
);
$batchA6bFwd = ImportLiveTransactionService::stage($pdo, $csvA6bFwd, 'a6b-fwd.csv', $adminUserId);
check('A6b-1 all 11 rows staged VALID (0 ERROR)', 0 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA6bFwd}")->fetchColumn());
$resultA6bFwd = null;
$failedA6bFwd = false;
try {
    $resultA6bFwd = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA6bFwd, $adminUserId));
} catch (\Throwable $e) {
    $failedA6bFwd = true;
}
check('A6b-1 forward order (row10=IN, row11=OUT, same date/time): commit succeeds, IN posts before OUT as filed', !$failedA6bFwd && $resultA6bFwd && $resultA6bFwd['imported'] === 11, $failedA6bFwd ? 'threw unexpectedly' : json_encode($resultA6bFwd));
$stockA6bFwd = InventoryService::currentStock($pdo, $itemTieFwd, $whId);
check('A6b-1 final stock = 100 IN - 50 OUT = 50 (IN really did post before OUT)', approx($stockA6bFwd['qty_base'], 50), (string) $stockA6bFwd['qty_base']);
$txOutA6bFwd = $pdo->query("SELECT id FROM inventory_transactions WHERE reference_no='REF-A6B-FWD-OUT'")->fetch(PDO::FETCH_ASSOC);
check('A6b-1 the OUT transaction was actually created (not skipped/rejected)', $txOutA6bFwd !== false);

// A6b-2 — INVERSE file order: row 10 = OUT -50, row 11 = IN +100 (same
// date/time, brand-new item with zero prior stock). The importer must
// NOT silently reorder this into "IN before OUT" just because that
// would make it succeed — it must preserve row 10's OUT running FIRST
// (as filed), which is invalid at that chronological point (no stock
// yet) and must be rejected by ordinary FIFO rules. The whole batch
// (including the 9 filler rows and the later IN) rolls back atomically.
[$itemFillInv, $skuFillInv] = makeItem($pdo, $kgUnitId, 'V28-A6B-FILL-INV');
[$itemTieInv, $skuTieInv] = makeItem($pdo, $kgUnitId, 'V28-A6B-TIE-INV');
$csvA6bInv = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . fillerRows($whCode, $skuFillInv, 9) // rows 1-9
    . "2026-09-01 08:00:00,OUT,{$whCode},{$skuTieInv},50,KG,,,,REF-A6B-INV-OUT,\n"      // row 10
    . "2026-09-01 08:00:00,IN,{$whCode},{$skuTieInv},100,KG,1000,,,REF-A6B-INV-IN,\n"   // row 11
);
$batchA6bInv = ImportLiveTransactionService::stage($pdo, $csvA6bInv, 'a6b-inv.csv', $adminUserId);
check('A6b-2 all 11 rows staged VALID (stock sufficiency is never checked at staging — see the service docblock)', 0 === (int) $pdo->query("SELECT error_rows FROM import_batches WHERE id={$batchA6bInv}")->fetchColumn());
$rejectedA6bInv = false;
$exceptionClassA6bInv = null;
try {
    Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA6bInv, $adminUserId));
} catch (\Throwable $e) {
    $rejectedA6bInv = true;
    $exceptionClassA6bInv = get_class($e);
}
check('A6b-2 inverse order (row10=OUT, row11=IN, same date/time): commit is REJECTED, never silently reordered to make it succeed', $rejectedA6bInv);
check('A6b-2 rejection is a genuine FIFO InsufficientStockException (row 10\'s OUT really did run before row 11\'s IN, exactly as filed)', $exceptionClassA6bInv === \App\Services\InsufficientStockException::class, (string) $exceptionClassA6bInv);
$txCountA6bInv = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no IN ('REF-A6B-INV-OUT','REF-A6B-INV-IN')")->fetchColumn();
check('A6b-2 NEITHER the OUT nor the IN was committed — whole batch rolled back atomically', $txCountA6bInv === 0, "count={$txCountA6bInv}");
$fillerCountA6bInv = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no LIKE 'REF-FILL-%' AND warehouse_id = {$whId}")->fetchColumn();
// The forward test (A6b-1) already committed 9 filler rows under the same
// REF-FILL-N reference pattern — so this checks total filler rows across
// BOTH batches equals exactly 9 (A6b-1's), never 18, proving A6b-2's 9
// filler rows were rolled back too.
check('A6b-2 filler rows from the FAILED batch were also rolled back (only A6b-1\'s 9 filler rows exist, not 18)', $fillerCountA6bInv === 9, "count={$fillerCountA6bInv}");

// ============================================================
// A7 — insufficient-stock OUT rolls back the WHOLE batch (atomicity).
// ============================================================
echo "\n== A7: insufficient stock OUT rolls back the whole batch ==\n";
[$itemA7, $skuA7] = makeItem($pdo, $kgUnitId, 'V28-A7');
// Row 1: a valid IN (would succeed on its own). Row 2: an OUT for far more
// than any stock that will ever exist for this brand-new item -> must fail
// and roll back row 1's IN as well (no partial commit).
$csvA7 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-20,IN,{$whCode},{$skuA7},10,KG,1000,,,REF-A7-IN,\n"
    . "2026-08-21,OUT,{$whCode},{$skuA7},99999,KG,,,,REF-A7-OUT,\n"
);
$batchA7 = ImportLiveTransactionService::stage($pdo, $csvA7, 'a7.csv', $adminUserId);
$rejectedA7 = false;
try {
    Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA7, $adminUserId));
} catch (\Throwable $e) {
    $rejectedA7 = true;
}
check('A7 commit throws (insufficient stock)', $rejectedA7);
$txA7Count = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no IN ('REF-A7-IN','REF-A7-OUT')")->fetchColumn();
check('A7 NEITHER row was committed — the valid IN was rolled back with the failing OUT (no partial import)', $txA7Count === 0, "count={$txA7Count}");
$batchA7Row = $pdo->query("SELECT status FROM import_batches WHERE id={$batchA7}")->fetch(PDO::FETCH_ASSOC);
check('A7 batch status was never flipped to COMMITTED', $batchA7Row && $batchA7Row['status'] !== 'COMMITTED', (string) ($batchA7Row['status'] ?? 'n/a'));

// ============================================================
// A8 — re-uploading the exact same (already committed) file is rejected at stage().
// ============================================================
echo "\n== A8: duplicate file re-upload rejected at stage() ==\n";
[$itemA8, $skuA8] = makeItem($pdo, $kgUnitId, 'V28-A8');
$dupContent = "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-25,IN,{$whCode},{$skuA8},1,KG,1000,,,REF-A8,\n";
$csvA8a = tmpCsv($dupContent);
$batchA8a = ImportLiveTransactionService::stage($pdo, $csvA8a, 'a8.csv', $adminUserId);
Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA8a, $adminUserId));

$csvA8b = tmpCsv($dupContent); // byte-identical content, different temp path/filename
$rejectedA8 = false;
try {
    ImportLiveTransactionService::stage($pdo, $csvA8b, 'a8-reupload.csv', $adminUserId);
} catch (ValidationException $e) {
    $rejectedA8 = true;
}
check('A8 re-uploading the identical already-committed file is rejected at stage()', $rejectedA8);

// ============================================================
// A9 — double commit() on the same batch is idempotent (no duplicate transactions).
// ============================================================
echo "\n== A9: double commit() is idempotent ==\n";
[$itemA9, $skuA9] = makeItem($pdo, $kgUnitId, 'V28-A9');
$csvA9 = tmpCsv(
    "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
    . "2026-08-26,IN,{$whCode},{$skuA9},7,KG,1000,,,REF-A9,\n"
);
$batchA9 = ImportLiveTransactionService::stage($pdo, $csvA9, 'a9.csv', $adminUserId);
$firstA9 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA9, $adminUserId));
$secondA9 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA9, $adminUserId));
check('A9 second commit() call is flagged idempotent_replay', !empty($secondA9['idempotent_replay']), json_encode($secondA9));
$txA9Count = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no='REF-A9'")->fetchColumn();
check('A9 only ONE real transaction exists despite two commit() calls', $txA9Count === 1, "count={$txA9Count}");
$stockA9 = InventoryService::currentStock($pdo, $itemA9, $whId);
check('A9 stock reflects only 7kg once, not 14kg', approx($stockA9['qty_base'], 7), (string) $stockA9['qty_base']);

// ============================================================
// A10 — .xlsx input parses correctly via XlsxReaderService.
// ============================================================
echo "\n== A10: .xlsx input parses correctly ==\n";
[$itemA10, $skuA10] = makeItem($pdo, $kgUnitId, 'V28-A10');
$xlsxPath = tempnam(sys_get_temp_dir(), 'v28_') . '.xlsx';
ExcelWriterService::write($xlsxPath, [
    'Instructions' => ['headers' => ['Info'], 'rows' => [['isi sheet Template']]],
    'Template' => [
        'headers' => ['transaction_date', 'transaction_type', 'warehouse_code', 'sku', 'input_qty', 'input_unit', 'unit_price_input', 'supplier_code', 'division_code', 'reference_no', 'notes'],
        'rows' => [['2026-08-27', 'IN', $whCode, $skuA10, '9', 'KG', '2000', '', '', 'REF-A10', '']],
    ],
]);
$batchA10 = ImportLiveTransactionService::stage($pdo, $xlsxPath, 'a10.xlsx', $adminUserId);
check('A10 .xlsx staged with 1 VALID row', 1 === (int) $pdo->query("SELECT valid_rows FROM import_batches WHERE id={$batchA10}")->fetchColumn());
$resultA10 = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, $batchA10, $adminUserId));
check('A10 .xlsx commit imported 1 row', $resultA10['imported'] === 1, json_encode($resultA10));
$stockA10 = InventoryService::currentStock($pdo, $itemA10, $whId);
check('A10 .xlsx-imported IN created real stock (9kg)', approx($stockA10['qty_base'], 9), (string) $stockA10['qty_base']);
unlink($xlsxPath);

// ============================================================
// Section A summary before starting the HTTP server for Section B.
// ============================================================
$aTotal = count($results);
$aPassed = count(array_filter($results));
echo "\n-- Section A: {$aPassed} / {$aTotal} PASSED --\n";

// ============================================================
// B — HTTP integration
// ============================================================
$port = 8900 + random_int(400, 799);
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

function httpUpload(string $url, string $filePath, string $cookieJar, string $csrfToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ["X-CSRF-Token: {$csrfToken}"],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($filePath, 'text/csv', 'upload.csv')],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

try {
    $superUser = uid('v28super'); $superPass = 'V28SuperPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
        ->execute(['u' => $superUser, 'h' => password_hash($superPass, PASSWORD_BCRYPT), 'n' => $superUser, 'r' => $superadminRoleId]);
    $superJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $superLogin = httpCall('POST', "{$base}/auth/login", ['username' => $superUser, 'password' => $superPass], $superJar);
    $superCsrf = $superLogin['body']['data']['csrf_token'] ?? '';

    $stockUser = uid('v28stock'); $stockPass = 'V28StockPass123!';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $stockUser, 'h' => password_hash($stockPass, PASSWORD_BCRYPT), 'n' => $stockUser, 'r' => $stockRoleId, 'w' => $whId]);
    $stockJar = tempnam(sys_get_temp_dir(), 'cookie_');
    $stockLogin = httpCall('POST', "{$base}/auth/login", ['username' => $stockUser, 'password' => $stockPass], $stockJar);
    $stockCsrf = $stockLogin['body']['data']['csrf_token'] ?? '';

    // --------------------------------------------------------
    // B1 — IMPORT_MANAGE gate: STOCK is forbidden even though it can post
    // manual Stock IN/OUT directly — this importer is ADMIN/SUPERADMIN
    // only, same as every other import type in this module.
    // --------------------------------------------------------
    echo "\n== B1: IMPORT_MANAGE permission gate ==\n";
    $stockStage = httpCall('POST', "{$base}/import/live-transaction/stage", ['file_path' => '/nonexistent', 'file_name' => 'x.csv'], $stockJar, $stockCsrf);
    check('B1 STOCK role is forbidden from live-transaction stage (403)', $stockStage['status'] === 403, json_encode($stockStage['body']));

    // --------------------------------------------------------
    // B2 — full real multipart upload -> stage -> preview -> commit.
    // --------------------------------------------------------
    echo "\n== B2: full upload -> stage -> preview -> commit round trip ==\n";
    [$itemB2, $skuB2] = makeItem($pdo, $kgUnitId, 'V28-B2');
    $csvB2 = tmpCsv(
        "transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,unit_price_input,supplier_code,division_code,reference_no,notes\n"
        . "2026-08-28,IN,{$whCode},{$skuB2},15,KG,3000,,,REF-B2,\n"
    );
    $uploadResp = httpUpload("{$base}/import/upload", $csvB2, $superJar, $superCsrf);
    check('B2 upload returns 200', $uploadResp['status'] === 200, json_encode($uploadResp['body']));
    $filePath = $uploadResp['body']['data']['file_path'] ?? null;
    $fileName = $uploadResp['body']['data']['file_name'] ?? null;
    check('B2 upload returns a server-side file_path', is_string($filePath) && $filePath !== '');

    $stageResp = httpCall('POST', "{$base}/import/live-transaction/stage", ['file_path' => $filePath, 'file_name' => $fileName], $superJar, $superCsrf);
    check('B2 stage returns 200 with an import_batch_id', $stageResp['status'] === 200 && !empty($stageResp['body']['data']['import_batch_id']), json_encode($stageResp['body']));
    $batchIdB2 = $stageResp['body']['data']['import_batch_id'];

    $previewResp = httpCall('GET', "{$base}/import/batches/{$batchIdB2}/rows", null, $superJar, $superCsrf);
    check('B2 preview (generic GET /import/batches/{id}/rows, fully reused) returns 200', $previewResp['status'] === 200);
    check('B2 preview shows exactly 1 VALID row', count($previewResp['body']['data']['rows'] ?? []) === 1 && $previewResp['body']['data']['rows'][0]['row_status'] === 'VALID', json_encode($previewResp['body']['data'] ?? null));

    $commitResp = httpCall('POST', "{$base}/import/live-transaction/{$batchIdB2}/commit", [], $superJar, $superCsrf);
    check('B2 commit returns 200, imported=1', $commitResp['status'] === 200 && ($commitResp['body']['data']['imported'] ?? 0) === 1, json_encode($commitResp['body']));

    $stockB2 = InventoryService::currentStock($pdo, $itemB2, $whId);
    check('B2 end-to-end HTTP round trip created real stock (15kg)', approx($stockB2['qty_base'], 15), (string) $stockB2['qty_base']);

    // --------------------------------------------------------
    // B3 — Download Template Excel exposes exactly the real accepted columns.
    // --------------------------------------------------------
    echo "\n== B3: Download Template Excel ==\n";
    $sheets = ImportTemplateService::build('LIVE_TRANSACTION');
    check('B3 template has an Instructions and a Template sheet', isset($sheets['Instructions']) && isset($sheets['Template']));
    check('B3 template headers include the required core columns', in_array('transaction_date', $sheets['Template']['headers'], true) && in_array('transaction_type', $sheets['Template']['headers'], true) && in_array('warehouse_code', $sheets['Template']['headers'], true) && in_array('sku', $sheets['Template']['headers'], true));
    check('B3 template headers include the V2.7 costing columns (reused, not reinvented)', in_array('ppn_treatment', $sheets['Template']['headers'], true) && in_array('freight_treatment', $sheets['Template']['headers'], true));
    $templateHttp = httpCall('GET', "{$base}/import/template/LIVE_TRANSACTION", null, $superJar, $superCsrf);
    check('B3 GET /import/template/LIVE_TRANSACTION is reachable (200, xlsx bytes)', $templateHttp['status'] === 200);
} finally {
    proc_terminate($process);
    proc_close($process);
}

$total = count($results);
$passed = count(array_filter($results));
echo "\n==============================\n";
echo "TOTAL: {$total}  PASSED: {$passed}  FAILED: " . ($total - $passed) . "\n";
exit($passed === $total ? 0 : 1);
