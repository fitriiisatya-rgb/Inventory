<?php
declare(strict_types=1);

/**
 * Shared fixture for the Dashboard tests: a small but complete inventory
 * history built through the application's own services (FIFO posting,
 * transfers, adjustments, void) across THREE warehouses, so every card and
 * every drill-down can be proven against the existing report engines:
 *
 *   A "SCM"       opening + purchases + usage + transfers out + adjustment -
 *   B "CIBADAK"   opening + purchases + usage + transfer in + adjustment +
 *   C "KT"        warehouse onboarded MID-period by an OPENING (cutover case)
 *
 * Dates are fixed in June of the (past) fixture year so ranges are exact;
 * "today"-dated rows are added for the Hari Ini / Bulan Ini cases. A
 * throwaway OPENING far in the past pins live_opening_date, exactly like
 * tests/inventory_movement_report_v26b_test.php.
 */

foreach (glob(__DIR__ . '/../../services/*.php') ?: [] as $f) {
    if (basename($f) === 'Exceptions.php') {
        require_once $f;
    }
}
foreach (glob(__DIR__ . '/../../services/*.php') ?: [] as $f) {
    require_once $f;
}

use App\Services\Database;
use App\Services\FifoService;
use App\Services\StockAdjustmentService;
use App\Services\TransferService;
use App\Services\UnitConversionService;
use App\Services\VoidService;

function df_uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

function df_wh(PDO $pdo, string $name): int
{
    $pdo->prepare('INSERT INTO warehouses (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => df_uid('DF'), 'n' => $name]);
    return (int) $pdo->lastInsertId();
}
function df_item(PDO $pdo, int $unit, string $tag, ?int $cat, float $min = 0): array
{
    $sku = df_uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:s,:n,:u,:c,:m,\'ACTIVE\')')
        ->execute(['s' => $sku, 'n' => "Item {$sku}", 'u' => $unit, 'c' => $cat, 'm' => $min]);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unit, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return ['id' => $id, 'sku' => $sku, 'name' => "Item {$sku}"];
}
function df_in(int $item, int $wh, float $qty, float $cost, string $date, int $by, int $unit, string $type = 'IN', ?string $ref = null): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => df_uid('df-in'), 'item_id' => $item, 'warehouse_id' => $wh, 'input_qty' => $qty, 'input_unit_id' => $unit,
        'unit_price_input' => $cost, 'transaction_date' => $date, 'created_by' => $by, 'username' => 'dftest', 'transaction_type' => $type,
        'reference_no' => $ref,
    ]));
    return (int) $r['transaction_id'];
}
function df_out(int $item, int $wh, float $qty, string $date, int $by, int $unit, ?string $ref = null): int
{
    $r = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => df_uid('df-out'), 'item_id' => $item, 'warehouse_id' => $wh, 'input_qty' => $qty, 'input_unit_id' => $unit,
        'transaction_type' => 'OUT', 'transaction_date' => $date, 'created_by' => $by, 'username' => 'dftest', 'reference_no' => $ref,
    ]));
    return (int) $r['transaction_id'];
}
function df_adj(int $item, int $wh, float $delta, string $date, int $by): void
{
    Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, [
        'transaction_uuid' => df_uid('df-adj'), 'item_id' => $item, 'warehouse_id' => $wh, 'qty_base_delta' => $delta,
        'adjustment_type' => 'CORRECTION', 'reason' => 'dashboard fixture', 'transaction_date' => $date, 'created_by' => $by,
    ]));
}
function df_transfer(int $item, int $from, int $to, float $qty, string $ship, int $unit, int $by, bool $receive): int
{
    $c = Database::transaction(fn (PDO $tx) => TransferService::create($tx, [
        'transfer_uuid' => df_uid('df-trf'), 'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'ship_date' => $ship,
        'created_by' => $by, 'username' => 'dftest', 'lines' => [['item_id' => $item, 'input_qty' => $qty, 'input_unit_id' => $unit]],
    ]));
    if ($receive) {
        Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $c['transfer_id'], ['created_by' => $by, 'username' => 'dftest']));
    }
    return (int) $c['transfer_id'];
}

/** @return array<string,mixed> */
function dashboard_build_fixture(PDO $pdo): array
{
    $kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    $superRole = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $viewerRole = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
    $stockRole = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();

    $mk = function (string $tag, int $role, ?int $wh = null) use ($pdo): array {
        $u = df_uid($tag);
        $pass = 'Df' . bin2hex(random_bytes(4)) . '!1';
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
            ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $role, 'w' => $wh]);
        return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
    };
    $admin = $mk('dfadmin', $superRole);

    $A = df_wh($pdo, 'Gudang SCM / Gudang Besar');
    $B = df_wh($pdo, 'Gudang Cibadak');
    $C = df_wh($pdo, 'Gudang Karang Tengah');
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('DFCA'), 'n' => 'DF Roti']);
    $catA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('DFCB'), 'n' => 'DF Bahan']);
    $catB = (int) $pdo->lastInsertId();

    $viewer = $mk('dfviewer', $viewerRole);
    $stockA = $mk('dfstockA', $stockRole, $A);

    $it = [
        'tepung' => df_item($pdo, $kg, 'DFTEP', $catB, 50),
        'gula' => df_item($pdo, $kg, 'DFGUL', $catB, 30),
        'roti' => df_item($pdo, $kg, 'DFROT', $catA, 0),
        'mentega' => df_item($pdo, $kg, 'DFMEN', $catB, 10),
        'coklat' => df_item($pdo, $kg, 'DFCOK', $catA, 0),
        'ragi' => df_item($pdo, $kg, 'DFRAG', $catB, 40),
    ];
    $by = $admin['id'];

    // throwaway OPENING far in the past pins live_opening_date (so every period below is "live")
    $pin = df_item($pdo, $kg, 'DFPIN', $catA);
    df_in($pin['id'], $A, 1, 1, '2020-01-01 00:00:00', $by, $kg, 'OPENING');

    // --- before the range: opening balances + a purchase that lands in Stok Awal
    df_in($it['tepung']['id'], $A, 200, 1000, '2026-05-31 08:00:00', $by, $kg, 'OPENING');
    df_in($it['gula']['id'], $A, 100, 2000, '2026-05-31 08:00:00', $by, $kg, 'OPENING');
    df_in($it['roti']['id'], $B, 80, 500, '2026-05-31 08:00:00', $by, $kg, 'OPENING');
    df_in($it['mentega']['id'], $B, 40, 3000, '2026-05-31 08:00:00', $by, $kg, 'OPENING');
    df_in($it['tepung']['id'], $A, 50, 1200, '2026-06-05 09:00:00', $by, $kg, 'IN', 'PO-BEFORE');   // before 06-10 -> inside Stok Awal

    // --- RANGE 2026-06-10 .. 2026-06-20
    df_in($it['tepung']['id'], $A, 100, 1100, '2026-06-12 09:00:00', $by, $kg, 'IN', 'PO-A-1');
    df_in($it['gula']['id'], $A, 60, 2100, '2026-06-13 10:00:00', $by, $kg, 'IN', 'PO-A-2');
    df_in($it['roti']['id'], $B, 50, 520, '2026-06-13 11:00:00', $by, $kg, 'IN', 'PO-B-1');
    df_out($it['tepung']['id'], $A, 30, '2026-06-15 08:00:00', $by, $kg, 'OUT-A-1');
    df_out($it['roti']['id'], $B, 20, '2026-06-16 08:00:00', $by, $kg, 'OUT-B-1');
    df_out($it['mentega']['id'], $B, 5, '2026-06-17 08:00:00', $by, $kg, 'OUT-B-2');
    df_transfer($it['gula']['id'], $A, $B, 20, '2026-06-14 08:00:00', $kg, $by, true);       // received: out day 14 / in same day
    df_transfer($it['tepung']['id'], $A, $B, 10, '2026-06-18 08:00:00', $kg, $by, false);    // pending (in transit across the end of range scope)
    df_adj($it['roti']['id'], $B, 5, '2026-06-17 12:00:00', $by);
    df_adj($it['gula']['id'], $A, -3, '2026-06-17 12:00:00', $by);
    // a purchase that is later VOIDED: the original stays on its own date as status VOID (-> "Lainnya"), the reversal lands today
    $voidIn = df_in($it['mentega']['id'], $B, 10, 3100, '2026-06-19 09:00:00', $by, $kg, 'IN', 'PO-VOIDED');
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => df_uid('df-void'), 'transaction_id' => $voidIn, 'reason' => 'dashboard fixture', 'voided_by' => $by, 'username' => 'dftest']));
    // warehouse C onboarded mid-period by an OPENING (cutover)
    df_in($it['coklat']['id'], $C, 30, 4000, '2026-06-15 07:00:00', $by, $kg, 'OPENING');
    df_in($it['ragi']['id'], $C, 8, 900, '2026-06-15 07:00:00', $by, $kg, 'OPENING');         // below its minimum (40) -> attention
    df_out($it['coklat']['id'], $C, 4, '2026-06-18 08:00:00', $by, $kg, 'OUT-C-1');

    // --- today (for Hari Ini / Bulan Ini, activity, recent)
    $today = date('Y-m-d');
    df_in($it['gula']['id'], $B, 25, 2200, $today . ' 06:00:00', $by, $kg, 'IN', 'PO-TODAY');
    df_out($it['gula']['id'], $A, 5, $today . ' 06:30:00', $by, $kg, 'OUT-TODAY');
    df_transfer($it['tepung']['id'], $A, $B, 5, $today . ' 06:45:00', $kg, $by, true);

    // --- Stock Opname "rusak": an OLD posted session and a NEWER one for warehouse A (only the newer counts), none for B
    $mkSession = function (int $wh, string $date, string $num) use ($pdo, $by): int {
        $uuid = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
        $pdo->prepare("INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model, created_by, posted_by, posted_at) VALUES (:w,:d,:u,:n,'SELECTED_ITEMS','POSTED','LEGACY_DUAL_COUNT',:c,:c2,NOW())")
            ->execute(['w' => $wh, 'd' => $date, 'u' => $uuid, 'n' => $num, 'c' => $by, 'c2' => $by]);
        return (int) $pdo->lastInsertId();
    };
    $oldS = $mkSession($A, '2026-05-01', 'SO-DF-OLD');
    $newS = $mkSession($A, '2026-06-01', 'SO-DF-NEW');
    $line = $pdo->prepare('INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base, final_rusak_qty, final_deadstock_qty) VALUES (:s,:i,10,10,1,:h,:r,:d)');
    $line->execute(['s' => $oldS, 'i' => $it['tepung']['id'], 'h' => 1000, 'r' => 99, 'd' => 50]);  // must NOT count (older session)
    $line->execute(['s' => $newS, 'i' => $it['tepung']['id'], 'h' => 1000, 'r' => 3, 'd' => 4]);    // rusak 3 x 1000 = 3.000 · dead stock 4 x 1000 = 4.000
    $line->execute(['s' => $newS, 'i' => $it['gula']['id'], 'h' => 2000, 'r' => 2, 'd' => 1]);      // rusak 2 x 2000 = 4.000 · dead stock 1 x 2000 = 2.000
    // an OPEN session on B so Stock Opname Aktif = 1
    $uuid = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
    $pdo->prepare("INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model, created_by) VALUES (:w,:d,:u,'SO-DF-OPEN','SELECTED_ITEMS','OPEN','LEGACY_DUAL_COUNT',:c)")
        ->execute(['w' => $B, 'd' => $today, 'u' => $uuid, 'c' => $by]);

    return [
        'admin' => $admin, 'viewer' => $viewer, 'stockA' => $stockA, 'kg' => $kg,
        'wh' => ['A' => $A, 'B' => $B, 'C' => $C], 'items' => $it, 'cat' => ['roti' => $catA, 'bahan' => $catB],
        'range' => ['from' => '2026-06-10', 'to' => '2026-06-20'], 'today' => $today,
    ];
}
