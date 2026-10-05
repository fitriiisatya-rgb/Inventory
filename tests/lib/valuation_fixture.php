<?php
declare(strict_types=1);

/**
 * Shared fixture for the "Laporan Nilai Stok & HPP" (FIFO + Average) tests: REAL postings through FifoService / TransferService / StockAdjustmentService /
 * VoidService. Period 2026-10-01 .. 2026-10-31. Every expectation in 'expect' is HAND-COMPUTED here from the inputs (independent of the report code).
 *
 *   P  W1  IN 100@1000 (10-01), IN 100@1100 (10-02), OUT 80 (10-03)                       <- the mandatory addendum fixture
 *   Q  W1  OPENING 50@2000 at 10-01 00:00:00 (= beginning inventory), IN 50@2400 (10-02), OUT 70 (10-04)   [multi-layer OUT]
 *   R  W1/W2  W1 IN 50@500, IN 50@700, transfer 60 W1->W2 (10-03, received), W2 OUT 30 (10-04)   [transfer: layer cost vs average]
 *   S  W1  IN 100@300, IN 100@400, adjustment -50 (10-03), adjustment +10 (10-04, cost = last batch cost 400)
 *   T  W1  IN 100@10, OUT 30 (10-02), VOIDED (reversal re-dated to 10-03 12:00)
 *   U  W1  IN 10@100, OUT 15 with the negative-stock override (10-02)                      <- Average cannot be reconstructed
 *   O  W1  OPENING 30@800 (2026-09-20, before the period), IN 30@1000 (10-02), OUT 40 (10-04)
 *   H  historical import on P (inventory_effect 0) — must never appear
 * Categories: A = {P, Q}, B = {R, S, T, U, O}.
 */

require_once __DIR__ . '/dashboard_fixture.php';
require_once __DIR__ . '/../../services/InventoryValuationService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\VoidService;

/** @return array<string,mixed> */
function valuation_build_fixture(PDO $pdo): array
{
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
    $superRole = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $viewerRole = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
    $stockRole = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
    $mkUser = function (string $tag, int $role, ?int $wh = null) use ($pdo): array {
        $u = df_uid($tag);
        $pass = 'Vl' . bin2hex(random_bytes(4)) . '!1';
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
            ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $role, 'w' => $wh]);
        return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
    };
    $W1 = df_wh($pdo, 'Gudang VLZ-SCM');
    $W2 = df_wh($pdo, 'Gudang VLZ-Cibadak');
    $admin = $mkUser('vladmin', $superRole);
    $viewer = $mkUser('vlviewer', $viewerRole);
    $stock2 = $mkUser('vlstock2', $stockRole, $W2);
    $mkCat = function (string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('VLC'), 'n' => $name]);
        return (int) $pdo->lastInsertId();
    };
    $CA = $mkCat('VLZ Kategori A');
    $CB = $mkCat('VLZ Kategori B');
    $P = df_item($pdo, $pcs, 'VLZP', $CA);
    $Q = df_item($pdo, $pcs, 'VLZQ', $CA);
    $R = df_item($pdo, $pcs, 'VLZR', $CB);
    $S = df_item($pdo, $pcs, 'VLZS', $CB);
    $T = df_item($pdo, $pcs, 'VLZT', $CB);
    $U = df_item($pdo, $pcs, 'VLZU', $CB);
    $O = df_item($pdo, $pcs, 'VLZO', $CB);
    $by = $admin['id'];

    // ---- P
    df_in($P['id'], $W1, 100, 1000, '2026-10-01 08:00:00', $by, $pcs, 'IN', 'PO-P1');
    df_in($P['id'], $W1, 100, 1100, '2026-10-02 08:00:00', $by, $pcs, 'IN', 'PO-P2');
    df_out($P['id'], $W1, 80, '2026-10-03 09:00:00', $by, $pcs, 'SO-001');
    // ---- Q
    df_in($Q['id'], $W1, 50, 2000, '2026-10-01 00:00:00', $by, $pcs, 'OPENING', 'OPEN-Q');
    df_in($Q['id'], $W1, 50, 2400, '2026-10-02 08:30:00', $by, $pcs, 'IN', 'PO-Q1');
    df_out($Q['id'], $W1, 70, '2026-10-04 10:00:00', $by, $pcs, 'SO-Q1');
    // ---- R
    df_in($R['id'], $W1, 50, 500, '2026-10-01 09:00:00', $by, $pcs, 'IN', 'PO-R1');
    df_in($R['id'], $W1, 50, 700, '2026-10-02 09:00:00', $by, $pcs, 'IN', 'PO-R2');
    $trf = df_transfer($R['id'], $W1, $W2, 60, '2026-10-03 10:00:00', $pcs, $by, true);
    df_out($R['id'], $W2, 30, '2026-10-04 11:00:00', $by, $pcs, 'SO-R2');
    // ---- S
    df_in($S['id'], $W1, 100, 300, '2026-10-01 10:00:00', $by, $pcs, 'IN', 'PO-S1');
    df_in($S['id'], $W1, 100, 400, '2026-10-02 10:00:00', $by, $pcs, 'IN', 'PO-S2');
    df_adj($S['id'], $W1, -50, '2026-10-03 11:00:00', $by);
    df_adj($S['id'], $W1, 10, '2026-10-04 12:00:00', $by);
    // ---- T (voided OUT)
    df_in($T['id'], $W1, 100, 10, '2026-10-01 11:00:00', $by, $pcs, 'IN', 'PO-T1');
    $tOut = df_out($T['id'], $W1, 30, '2026-10-02 11:00:00', $by, $pcs, 'SO-VOID');
    Database::transaction(fn (PDO $tx) => VoidService::void($tx, ['request_uuid' => df_uid('vlvoid'), 'transaction_id' => $tOut, 'reason' => 'valuation fixture void', 'voided_by' => $by, 'username' => 'vltest']));
    $pdo->prepare("UPDATE inventory_transactions SET transaction_date = '2026-10-03 12:00:00' WHERE reversal_of_id = :o")->execute(['o' => $tOut]);
    // ---- U (negative-stock override)
    df_in($U['id'], $W1, 10, 100, '2026-10-01 12:00:00', $by, $pcs, 'IN', 'PO-U1');
    Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => df_uid('vl-neg'), 'item_id' => $U['id'], 'warehouse_id' => $W1, 'input_qty' => 15, 'input_unit_id' => $pcs, 'transaction_type' => 'OUT',
        'transaction_date' => '2026-10-02 12:00:00', 'created_by' => $by, 'username' => 'vltest', 'reference_no' => 'SO-NEG', 'allow_negative_stock' => true, 'negative_stock_reason' => 'valuation fixture',
    ]));
    // ---- O
    df_in($O['id'], $W1, 30, 800, '2026-09-20 08:00:00', $by, $pcs, 'OPENING', 'OPEN-O');
    df_in($O['id'], $W1, 30, 1000, '2026-10-02 13:00:00', $by, $pcs, 'IN', 'PO-O1');
    df_out($O['id'], $W1, 40, '2026-10-04 13:00:00', $by, $pcs, 'SO-O1');
    // ---- H: historical import (reporting only) raw rows exactly as the importer writes them
    $pdo->prepare("INSERT INTO inventory_transactions (transaction_uuid, transaction_type, transaction_date, warehouse_id, reference_no, status, is_historical_import, inventory_effect, created_by)
                   VALUES (:u, 'IN', '2026-10-02 09:00:00', :w, 'PO-HIST', 'POSTED', 1, 0, :by)")->execute(['u' => df_uid('vlhist'), 'w' => $W1, 'by' => $by]);
    $hTx = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory_transaction_lines (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
                   VALUES (:t, 1, :i, 'hist', 5, :u, 1, 5, 9999, 9999, 49995, :w)")->execute(['t' => $hTx, 'i' => $P['id'], 'u' => $pcs, 'w' => $W1]);

    $expect = [
        // company-wide, whole fixture (W1 + W2), 2026-10-01 .. 2026-10-31
        'fifo' => ['opening' => 124000.0, 'cost_in' => 492000.0, 'hpp' => 278500.0, 'transfer' => 0.0, 'adjustment' => -11000.0, 'closing' => 326500.0, 'layers' => 11, 'skus_with_stock' => 6],
        // Average over the six reconstructable items (U excluded)
        'average' => ['opening' => 124000.0, 'cost_in' => 491000.0, 'hpp' => 292000.0, 'transfer' => 0.0, 'adjustment' => -13500.0, 'closing' => 309500.0],
        // FIFO restricted to the same six items
        'fifo_known' => ['hpp' => 277000.0, 'closing' => 327000.0],
        'diff' => ['hpp' => 15000.0, 'closing' => -17500.0],
        'per_item' => [
            'P' => ['qty_close' => 120.0, 'fifo_close' => 130000.0, 'fifo_hpp' => 80000.0, 'avg_close' => 126000.0, 'avg_hpp' => 84000.0, 'avg_cost' => 1050.0, 'layers' => 2],
            'Q' => ['qty_close' => 30.0, 'fifo_close' => 72000.0, 'fifo_hpp' => 148000.0, 'avg_close' => 66000.0, 'avg_hpp' => 154000.0, 'avg_cost' => 2200.0, 'layers' => 1, 'fifo_open' => 100000.0],
            'R' => ['qty_close' => 70.0, 'fifo_close' => 45000.0, 'fifo_hpp' => 15000.0, 'avg_close' => 42000.0, 'avg_hpp' => 18000.0, 'layers' => 3],
            'S' => ['qty_close' => 160.0, 'fifo_close' => 59000.0, 'fifo_hpp' => 0.0, 'avg_close' => 56500.0, 'avg_hpp' => 0.0, 'layers' => 3],
            'T' => ['qty_close' => 100.0, 'fifo_close' => 1000.0, 'fifo_hpp' => 0.0, 'avg_close' => 1000.0, 'avg_hpp' => 0.0, 'layers' => 1],
            'U' => ['qty_close' => -5.0, 'fifo_close' => -500.0, 'fifo_hpp' => 1500.0, 'layers' => 0],
            'O' => ['qty_close' => 20.0, 'fifo_close' => 20000.0, 'fifo_hpp' => 34000.0, 'avg_close' => 18000.0, 'avg_hpp' => 36000.0, 'layers' => 1, 'fifo_open' => 24000.0],
        ],
        // per warehouse for R
        'r_w1' => ['fifo_close' => 28000.0, 'avg_close' => 24000.0, 'fifo_trf' => -32000.0, 'avg_trf' => -36000.0],
        'r_w2' => ['fifo_close' => 17000.0, 'avg_close' => 18000.0, 'fifo_trf' => 32000.0, 'avg_trf' => 36000.0, 'fifo_hpp' => 15000.0, 'avg_hpp' => 18000.0],
    ];
    return [
        'admin' => $admin, 'viewer' => $viewer, 'stock2' => $stock2, 'wh' => ['1' => $W1, '2' => $W2], 'cat' => ['A' => $CA, 'B' => $CB],
        'items' => ['P' => $P, 'Q' => $Q, 'R' => $R, 'S' => $S, 'T' => $T, 'U' => $U, 'O' => $O], 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'expect' => $expect, 'transfer' => $trf,
    ];
}
