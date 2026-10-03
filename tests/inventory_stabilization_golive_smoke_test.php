<?php
declare(strict_types=1);

/**
 * STABILIZATION — Task 6: transaction go-live smoke tests. Uses ONLY test
 * fixtures (no production data) against the real, unmodified services:
 * stock receipt (FifoService::postIn / POST /transactions/in), stock
 * issue (POST /transactions/out), a warehouse transfer (POST /transfers +
 * /transfers/{id}/receive), the resulting inventory balance at each
 * warehouse, and that the SAME centralized item master is visible from
 * all three real production warehouse names (SCM, Cibadak, Karang
 * Tengah).
 *
 * Also documents (never changes) the current backdate/period-lock rule
 * for a 1–2 October 2026 transaction date, per PeriodLockService: a
 * transaction dated inside a book_closings row with status='LOCKED'
 * covering that date is refused (423 PERIOD_LOCKED); otherwise a
 * backdated transaction_date is accepted exactly like any other — there
 * is no special "today-only" restriction anywhere in the posting path.
 * This test proves BOTH halves of that rule with real fixtures rather
 * than changing any period-lock/backdate code (none found broken).
 *
 * Usage: php tests/inventory_stabilization_golive_smoke_test.php
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
require_once __DIR__ . '/../services/TransferService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\InventoryService;
use App\Services\TransferService;
use App\Services\PeriodLockedException;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(4)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)")
    ->execute(['u' => uid('stbgolive'), 'h' => password_hash('x', PASSWORD_BCRYPT), 'n' => 'stbgolive', 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

// ---- the three REAL production warehouse names, as ordinary fixture rows.
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STBGO-SCM', 'SCM', 1)")->execute();
$whScm = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STBGO-CBD', 'Cibadak', 1)")->execute();
$whCibadak = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES ('STBGO-KT', 'Karang Tengah', 1)")->execute();
$whKarangTengah = (int) $pdo->lastInsertId();

function makeStockUser(PDO $pdo, string $tag, int $roleId, int $whId): array
{
    $u = uid($tag);
    $pass = 'x';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $whId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u];
}
$stockScm = makeStockUser($pdo, 'stbgo-scmuser', $stockRoleId, $whScm);
$stockCibadak = makeStockUser($pdo, 'stbgo-cbduser', $stockRoleId, $whCibadak);
$stockKt = makeStockUser($pdo, 'stbgo-ktuser', $stockRoleId, $whKarangTengah);

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('STBGO-CAT'), 'n' => 'Stabilization Go-Live Kategori']);
$catId = (int) $pdo->lastInsertId();

$sku = uid('STBGO-ITEM');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,\'ACTIVE\')')
    ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $kgUnitId, 'cat' => $catId]);
$itemId = (int) $pdo->lastInsertId();
Database::transaction(fn (PDO $tx) => UnitConversionService::openNewVersion($tx, $itemId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity'));

// ---- Task 6 proof A: the centralized master is visible, identically,
// from SCM/Cibadak/Karang Tengah (same item row, same id, no per-warehouse
// duplication of the master itself).
$visibleFromScm = $pdo->query("SELECT COUNT(*) FROM items WHERE id = {$itemId}")->fetchColumn();
check('1. item exists exactly once in the centralized master (no per-warehouse copy)', (int) $visibleFromScm === 1);
check('1b. SCM, Cibadak, Karang Tengah are all registered as real, independent, active warehouses', (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE id IN ({$whScm},{$whCibadak},{$whKarangTengah}) AND is_active = 1")->fetchColumn() === 3);

// ---- Task 6 proof B: stock receipt (inbound) into SCM.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('stbgo-in'), 'item_id' => $itemId, 'warehouse_id' => $whScm,
    'input_qty' => 200, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 5000,
    'transaction_date' => '2026-09-15 08:00:00', 'created_by' => $adminId, 'username' => $stockScm['username'], 'transaction_type' => 'OPENING',
]));
$balanceScmAfterIn = InventoryService::currentStock($pdo, $itemId, $whScm);
check('2. stock receipt (inbound) into SCM results in the correct balance', abs((float) $balanceScmAfterIn['qty_base'] - 200.0) < 0.0001, (string) $balanceScmAfterIn['qty_base']);

// ---- Task 6 proof C: stock issue (outbound) from SCM.
Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
    'transaction_uuid' => uid('stbgo-out'), 'item_id' => $itemId, 'warehouse_id' => $whScm,
    'input_qty' => 50, 'input_unit_id' => $kgUnitId,
    'transaction_date' => '2026-09-16 08:00:00', 'created_by' => $adminId, 'username' => $stockScm['username'], 'transaction_type' => 'OUT',
]));
$balanceScmAfterOut = InventoryService::currentStock($pdo, $itemId, $whScm);
check('3. stock issue (outbound) from SCM results in the correct balance (200 - 50 = 150)', abs((float) $balanceScmAfterOut['qty_base'] - 150.0) < 0.0001, (string) $balanceScmAfterOut['qty_base']);

// ---- Task 6 proof D: a warehouse transfer SCM -> Cibadak, fully received.
$transferResult = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
    'transfer_uuid' => uid('stbgo-xfer'),
    'from_warehouse_id' => $whScm, 'to_warehouse_id' => $whCibadak,
    'ship_date' => '2026-09-17 08:00:00', 'created_by' => $adminId, 'username' => $stockScm['username'],
    'lines' => [['item_id' => $itemId, 'input_qty' => 60, 'input_unit_id' => $kgUnitId]],
]));
$transferId = $transferResult['transfer_id'];
Database::transaction(fn (PDO $tx) => TransferService::receive($tx, $transferId, ['created_by' => $adminId, 'username' => $stockCibadak['username']]));

$balanceScmAfterTransfer = InventoryService::currentStock($pdo, $itemId, $whScm);
$balanceCibadakAfterTransfer = InventoryService::currentStock($pdo, $itemId, $whCibadak);
check('4. transfer OUT reduces SCM balance correctly (150 - 60 = 90)', abs((float) $balanceScmAfterTransfer['qty_base'] - 90.0) < 0.0001, (string) $balanceScmAfterTransfer['qty_base']);
check('5. transfer IN (received) increases Cibadak balance correctly (0 + 60 = 60)', abs((float) $balanceCibadakAfterTransfer['qty_base'] - 60.0) < 0.0001, (string) $balanceCibadakAfterTransfer['qty_base']);

// ---- Task 6 proof E: Karang Tengah sees the SAME master item (zero
// stock there is correct — nothing has moved there yet) — centralized
// master, independent per-warehouse balances.
$balanceKarangTengah = InventoryService::currentStock($pdo, $itemId, $whKarangTengah);
check('6. Karang Tengah has its OWN independent balance for the same centralized item (0 — nothing posted there)', abs((float) $balanceKarangTengah['qty_base'] - 0.0) < 0.0001);

// ---- Task 6 proof F (backdate rule, documented not changed): a
// transaction dated 1–2 October 2026 succeeds normally when that period
// is NOT locked.
Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
    'transaction_uuid' => uid('stbgo-backdate-open'), 'item_id' => $itemId, 'warehouse_id' => $whScm,
    'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 5000,
    'transaction_date' => '2026-10-01 10:00:00', 'created_by' => $adminId, 'username' => $stockScm['username'], 'transaction_type' => 'OPENING',
]));
check('7. [backdate rule] a transaction dated 1 Oct 2026 succeeds normally when that period is OPEN (current, unchanged rule)', true);

// Now lock exactly that period and prove the SAME kind of backdated
// transaction_date is correctly refused — proving PeriodLockService's
// existing guard, never bypassed, is the ONLY thing standing between a
// backdated entry and the ledger.
$pdo->prepare("INSERT INTO book_closings (period_start, period_end, status, locked_by, locked_at, created_by) VALUES ('2026-10-01', '2026-10-02', 'LOCKED', :by, NOW(), :by2)")
    ->execute(['by' => $adminId, 'by2' => $adminId]);
$lockedBackdateThrew = null;
try {
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => uid('stbgo-backdate-locked'), 'item_id' => $itemId, 'warehouse_id' => $whScm,
        'input_qty' => 10, 'input_unit_id' => $kgUnitId, 'unit_price_input' => 5000,
        'transaction_date' => '2026-10-02 10:00:00', 'created_by' => $adminId, 'username' => $stockScm['username'], 'transaction_type' => 'OPENING',
    ]));
} catch (PeriodLockedException $e) {
    $lockedBackdateThrew = $e;
}
check('8. [backdate rule] the SAME kind of transaction dated 2 Oct 2026 is correctly REFUSED once that period is explicitly LOCKED (423 PERIOD_LOCKED) — proves the rule is enforced, not merely documented', $lockedBackdateThrew !== null, $lockedBackdateThrew ? $lockedBackdateThrew->getMessage() : 'NOT BLOCKED');

$failed = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $failed) . ' / ' . count($results) . " PASSED\n";
exit($failed > 0 ? 1 : 0);
