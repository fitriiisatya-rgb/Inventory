<?php
declare(strict_types=1);

/**
 * Shared fixture for the Laporan Pembelian tests: REAL Stock IN V2 purchases posted through the application's own PurchaseInvoiceService
 * (+ FIFO, costing gateway), plus a legacy purchase, a voided invoice and a historical import. Period 2026-09-01 .. 2026-09-30.
 *
 *   A  WH1 / S1 / "INV-A"   2026-09-05  3 rows, item discounts (10 % / Rp 1.000), PPN 11 % / 11 % / 0 %, invoice discount 5 %, freight 15.000 (expensed)
 *   B  WH2 / S2 / (no ref)  2026-09-12  1 row, custom PPN 5 %, no discounts, freight 0
 *   C  WH1 / S1 / "INV-C"   2026-09-20  2 rows, PPN 11 %, invoice discount Rp 3.000, freight 6.000 CAPITALISED; same items as A at DIFFERENT prices
 *   D  WH1 / S1 / "INV-VOID" 2026-09-25 1 row, voided afterwards (must be listed as VOID, never counted)
 *   L  legacy purchase (plain Stock IN, no costing rows) 20 KG @ 400 = 8.000, WH1, no supplier, ref "PO-LEGACY", 2026-09-15
 *   H  historical import (reporting only, inventory_effect 0) 5 KG @ 100, WH1, 2026-09-16
 *
 * Every expectation in 'expect' is HAND-COMPUTED here from the inputs (see the arithmetic in PurchaseInvoiceService's docblock), independent of the report code.
 */

require_once __DIR__ . '/dashboard_fixture.php';
require_once __DIR__ . '/../../services/PurchaseReportService.php';

use App\Services\Database;
use App\Services\PurchaseInvoiceService as P;
use App\Services\VoidService;

function prf_line(array $item, int $unit, float $qty, float $price, float $ppn = 0, string $dt = 'NONE', float $dv = 0): array
{
    return ['item_id' => $item['id'], 'input_unit_id' => $unit, 'input_qty' => $qty, 'unit_price_input' => $price, 'ppn_rate' => $ppn, 'discount_type' => $dt, 'discount_value' => $dv];
}

/** @return array<string,mixed> */
function purchase_build_fixture(PDO $pdo): array
{
    $kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
    $ltr = (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn();
    $superRole = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $viewerRole = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
    $stockRole = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
    $mkUser = function (string $tag, int $role, ?int $wh = null) use ($pdo): array {
        $u = df_uid($tag);
        $pass = 'Pr' . bin2hex(random_bytes(4)) . '!1';
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
            ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $role, 'w' => $wh]);
        return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
    };
    $WH1 = df_wh($pdo, 'Gudang PR-SCM');
    $WH2 = df_wh($pdo, 'Gudang PR-Cibadak');
    $admin = $mkUser('pradmin', $superRole);
    $viewer = $mkUser('prviewer', $viewerRole);
    $stock2 = $mkUser('prstock2', $stockRole, $WH2);
    $mkSup = function (string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('PRS'), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    };
    $S1 = $mkSup('PR Supplier Satu');
    $S2 = $mkSup('PR Supplier Dua');
    $mkCat = function (string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('PRC'), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    };
    $C1 = $mkCat('PR Roti');
    $C2 = $mkCat('PR Bahan');
    $X = df_item($pdo, $pcs, 'PRX', $C1);
    $Y = df_item($pdo, $kg, 'PRY', $C2);
    $Z = df_item($pdo, $ltr, 'PRZ', $C2);
    $W = df_item($pdo, $kg, 'PRW', $C2);
    $L = df_item($pdo, $kg, 'PRL', $C2);
    $H = df_item($pdo, $kg, 'PRH', $C2);

    $post = static function (array $in) use ($admin, $pdo): array {
        $in['transaction_uuid'] = df_uid('prreq');
        return Database::transaction(fn (PDO $tx) => P::post($tx, $in, $admin['id'], 'prtest'));
    };
    $ids = static fn (array $r): array => array_column($r['transactions'], 'transaction_id');

    $A = $post(['warehouse_id' => $WH1, 'supplier_id' => $S1, 'reference_no' => 'INV-A', 'transaction_date' => '2026-09-05', 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 5,
        'freight_amount' => 15000, 'freight_capitalize' => false, 'notes' => 'invoice A',
        'lines' => [prf_line($X, $pcs, 10, 1000, 11, 'PERCENT', 10), prf_line($Y, $kg, 5, 2000, 11), prf_line($Z, $ltr, 20, 500, 0, 'AMOUNT', 1000)]]);
    $B = $post(['warehouse_id' => $WH2, 'supplier_id' => $S2, 'transaction_date' => '2026-09-12', 'lines' => [prf_line($W, $kg, 100, 300, 5)]]);
    $C = $post(['warehouse_id' => $WH1, 'supplier_id' => $S1, 'reference_no' => 'INV-C', 'transaction_date' => '2026-09-20', 'invoice_discount_type' => 'AMOUNT', 'invoice_discount_value' => 3000,
        'freight_amount' => 6000, 'freight_capitalize' => true,
        'lines' => [prf_line($X, $pcs, 30, 1100, 11), prf_line($Y, $kg, 10, 2100, 11)]]);
    $D = $post(['warehouse_id' => $WH1, 'supplier_id' => $S1, 'reference_no' => 'INV-VOID', 'transaction_date' => '2026-09-25', 'lines' => [prf_line($Y, $kg, 4, 1000, 11)]]);
    $dTx = (int) $D['transactions'][0]['transaction_id'];
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => df_uid('prvoid'), 'transaction_id' => $dTx, 'reason' => 'purchase report fixture', 'voided_by' => $admin['id'], 'username' => 'prtest']));
    $legacyTx = df_in($L['id'], $WH1, 20, 400, '2026-09-15 10:00:00', $admin['id'], $kg, 'IN', 'PO-LEGACY');

    // historical import: reporting only (inventory_effect 0) — raw rows exactly as the importer writes them
    $pdo->prepare("INSERT INTO inventory_transactions (transaction_uuid, transaction_type, transaction_date, warehouse_id, reference_no, status, is_historical_import, inventory_effect, created_by)
                   VALUES (:u, 'IN', '2026-09-16 09:00:00', :w, 'PO-HIST', 'POSTED', 1, 0, :by)")->execute(['u' => df_uid('prhist'), 'w' => $WH1, 'by' => $admin['id']]);
    $histTx = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory_transaction_lines (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
                   VALUES (:t, 1, :i, 'hist', 5, :u, 1, 5, 100, 100, 500, :w)")->execute(['t' => $histTx, 'i' => $H['id'], 'u' => $kg, 'w' => $WH1]);

    // ---- hand-computed expectations
    $expect = [
        'A' => [
            // X: 10x1000=10.000, disc 10 % = 1.000, dpp 9.000, ppn 11 % = 990, total 9.990 | Y: 5x2000=10.000, dpp 10.000, ppn 1.100, total 11.100 | Z: 20x500=10.000, disc Rp 1.000, dpp 9.000, ppn 0, total 9.000
            // sheet subtotal (incl. PPN) 30.090; invoice discount 5 % = 1.504,5 -> split by row total: 499,5 / 555 / 450; on DPP: 450 / 500 / 450 -> net DPP 8.550 / 9.500 / 8.550
            // PPN after discount 940,5 / 1.045 / 0; payable 9.490,5 / 10.545 / 8.550 = 28.585,5; freight 15.000 by net DPP (26.600): 4.821,4286 / 5.357,1429 / 4.821,4285
            'gross' => 30000.0, 'item_discount' => 2000.0, 'subtotal' => 28000.0, 'ppn' => 2090.0, 'invoice_discount' => 1504.5, 'freight' => 15000.0, 'total' => 43585.5,
            'ppn_net' => 1985.5, 'invoice_discount_dpp' => 1400.0,
            'rows' => ['X' => ['gross' => 10000.0, 'item_discount' => 1000.0, 'dpp' => 9000.0, 'ppn' => 990.0, 'invoice_discount' => 499.5, 'freight' => 4821.4286, 'total' => 14311.9286],
                'Y' => ['gross' => 10000.0, 'item_discount' => 0.0, 'dpp' => 10000.0, 'ppn' => 1100.0, 'invoice_discount' => 555.0, 'freight' => 5357.1429, 'total' => 15902.1429],
                'Z' => ['gross' => 10000.0, 'item_discount' => 1000.0, 'dpp' => 9000.0, 'ppn' => 0.0, 'invoice_discount' => 450.0, 'freight' => 4821.4285, 'total' => 13371.4285]],
        ],
        // B: 100x300 = 30.000, custom PPN 5 % = 1.500, total 31.500, no freight
        'B' => ['gross' => 30000.0, 'item_discount' => 0.0, 'subtotal' => 30000.0, 'ppn' => 1500.0, 'invoice_discount' => 0.0, 'freight' => 0.0, 'total' => 31500.0],
        // C: X 30x1100 = 33.000 (ppn 3.630), Y 10x2100 = 21.000 (ppn 2.310) -> subtotal incl. PPN 59.940; invoice discount Rp 3.000 (rounding folded into the effective one);
        // freight 6.000 capitalised -> grand total = 59.940 - 3.000 + 6.000 = 62.940
        'C' => ['gross' => 54000.0, 'item_discount' => 0.0, 'subtotal' => 54000.0, 'ppn' => 5940.0, 'invoice_discount' => 3000.0, 'freight' => 6000.0, 'total' => 62940.0],
        'D_total' => 4440.0, 'legacy_total' => 8000.0,
    ];
    $expect['v2_total'] = $expect['A']['total'] + $expect['B']['total'] + $expect['C']['total'];   // 138.025,5
    $expect['live_total'] = $expect['v2_total'] + $expect['legacy_total'];                           // 146.025,5

    return [
        'admin' => $admin, 'viewer' => $viewer, 'stock2' => $stock2, 'wh' => ['1' => $WH1, '2' => $WH2], 'sup' => ['1' => $S1, '2' => $S2], 'cat' => ['1' => $C1, '2' => $C2],
        'items' => ['X' => $X, 'Y' => $Y, 'Z' => $Z, 'W' => $W, 'L' => $L, 'H' => $H], 'tx' => ['A' => $ids($A), 'B' => $ids($B), 'C' => $ids($C), 'D' => [$dTx], 'L' => [$legacyTx], 'H' => [$histTx]],
        'range' => ['from' => '2026-09-01', 'to' => '2026-09-30'], 'expect' => $expect,
    ];
}
