<?php
declare(strict_types=1);

/**
 * Shared fixture for the "Laporan IN / OUT / Transfer" tests: REAL postings through the application's own services (PurchaseInvoiceService, StockOutService,
 * FifoService, TransferService, DistributionOrderService). Period 2026-09-01 .. 2026-09-30. Builds on the Laporan Pembelian fixture (IN side: invoices A/B/C, voided D,
 * legacy L, historical H — all hand-computed there) and adds:
 *
 *   OUT1  WH1 -> Bakery 1  2026-09-22  shipping 10.000   X 12 PCS (spans two FIFO layers: 10 from invoice A + 2 from invoice C), Y 3 KG    markup Roti +10 %, Bahan +Rp 500
 *   OUT2  WH1 -> Bakery 2  2026-09-27  shipping 0        Z 5 LTR, X 4 PCS
 *   OUT3  WH1 -> Bakery 1  2026-09-28  shipping 5.000    Y 2 KG — reversed afterwards (DO CANCELLED, ledger REVERSED): listed, never counted
 *   OUTL  legacy OUT (no DO / invoice)  WH1  2026-09-18  L 5 KG
 *   T1    WH1 -> WH2  ship 2026-09-23  X 10 PCS + Y 2 KG, RECEIVED 2026-09-24 15:00
 *   T2    WH1 -> WH2  ship 2026-09-26  Z 3 LTR, PENDING
 *   T3    WH1 -> WH2  ship 2026-09-26  X 1 PCS, CANCELLED
 *   T4    WH2 -> WH1  ship 2026-09-25  W 20 KG, RECEIVED 2026-09-26 09:00
 *   T5    WH1 -> WH2  ship 2026-09-24  Z 1 LTR, received then REVERSED
 *
 * HPP side: read from the stored cost of the IN lines the OUT consumed (independent of the report code). Selling side: the test recomputes Harga Jual from the STORED
 * invoice-line reference price + markup with the documented formula (the reference price comes from item_price_history — see StockOutService).
 * Transfers are back-dated in created_at (simulated history) so lead times are positive: T1 30 h, T4 26 h.
 */

require_once __DIR__ . '/purchase_report_fixture.php';
require_once __DIR__ . '/../../services/InOutReportService.php';

use App\Services\Database;
use App\Services\DistributionOrderService;
use App\Services\StockOutService;
use App\Services\TransferService;

/** @return array<string,mixed> */
function inout_build_fixture(PDO $pdo): array
{
    $F = purchase_build_fixture($pdo);
    $admin = $F['admin'];
    $by = $admin['id'];
    $W1 = $F['wh']['1'];
    $W2 = $F['wh']['2'];
    $kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
    $ltr = (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn();
    [$X, $Y, $Z, $W, $L] = [$F['items']['X'], $F['items']['Y'], $F['items']['Z'], $F['items']['W'], $F['items']['L']];

    $mkBakery = static function (string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO bakery_destinations (code, name, address, pic_name, is_active) VALUES (:c,:n,:a,:p,1)')->execute(['c' => df_uid('IOB'), 'n' => $name, 'a' => 'Jl. Uji 1', 'p' => 'PIC']);
        return (int) $pdo->lastInsertId();
    };
    $B1 = $mkBakery('IO Bakery Satu');
    $B2 = $mkBakery('IO Bakery Dua');
    $markups = [(string) $F['cat']['1'] => ['mode' => 'PERCENT', 'value' => 10], (string) $F['cat']['2'] => ['mode' => 'AMOUNT', 'value' => 500]];
    $out = static function (array $in) use ($pdo, $by, $markups): array {
        $in['transaction_uuid'] = df_uid('ioreq');
        $in['markups'] = $markups;
        return Database::transaction(fn (PDO $tx) => StockOutService::post($tx, $in, $by, 'iotest'));
    };
    $ln = static fn (array $item, int $unit, float $qty): array => ['item_id' => $item['id'], 'input_unit_id' => $unit, 'input_qty' => $qty];

    $O1 = $out(['warehouse_id' => $W1, 'bakery_destination_id' => $B1, 'transaction_date' => '2026-09-22', 'shipping_amount' => 10000, 'reference_no' => 'REF-O1', 'lines' => [$ln($X, $pcs, 12), $ln($Y, $kg, 3)]]);
    $O2 = $out(['warehouse_id' => $W1, 'bakery_destination_id' => $B2, 'transaction_date' => '2026-09-27', 'shipping_amount' => 0, 'lines' => [$ln($Z, $ltr, 5), $ln($X, $pcs, 4)]]);
    $O3 = $out(['warehouse_id' => $W1, 'bakery_destination_id' => $B1, 'transaction_date' => '2026-09-28', 'shipping_amount' => 5000, 'lines' => [$ln($Y, $kg, 2)]]);
    Database::transaction(fn (PDO $tx) => DistributionOrderService::reverse($tx, (int) $O3['do_id'], ['created_by' => $by, 'username' => 'iotest', 'reason' => 'io fixture reversal', 'request_uuid' => df_uid('iorev')]));
    $legacyOutTx = df_out($L['id'], $W1, 5, '2026-09-18 00:00:00', $by, $kg, 'OUT-LEGACY');

    $trf = static function (int $from, int $to, string $ship, array $lines, ?string $receiveDate) use ($by): int {
        $c = Database::transaction(fn (PDO $tx) => TransferService::create($tx, ['transfer_uuid' => df_uid('io-trf'), 'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'ship_date' => $ship,
            'created_by' => $by, 'username' => 'iotest', 'lines' => $lines]));
        if ($receiveDate !== null) {
            Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $c['transfer_id'], ['created_by' => $by, 'username' => 'iotest', 'receive_date' => $receiveDate]));
        }
        return (int) $c['transfer_id'];
    };
    $T1 = $trf($W1, $W2, '2026-09-23 00:00:00', [$ln($X, $pcs, 10), $ln($Y, $kg, 2)], '2026-09-24 15:00:00');
    $backdate = static function (int $id, string $at) use ($pdo): void {
        $pdo->prepare('UPDATE warehouse_transfers SET created_at = :c WHERE id = :id')->execute(['c' => $at, 'id' => $id]);
        $pdo->prepare("UPDATE inventory_transactions SET created_at = :c WHERE reference_no = :r AND transaction_type = 'TRANSFER_OUT'")->execute(['c' => $at, 'r' => "TRANSFER-{$id}"]);
    };
    $backdate($T1, '2026-09-23 09:00:00');   // dispatched 09-23 09:00, received 09-24 15:00 -> lead time 30 h
    $T2 = $trf($W1, $W2, '2026-09-26 00:00:00', [$ln($Z, $ltr, 3)], null);
    $T3 = $trf($W1, $W2, '2026-09-26 00:00:00', [$ln($X, $pcs, 1)], null);
    Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, $T3, ['created_by' => $by, 'username' => 'iotest', 'reason' => 'io fixture cancel']));
    $T4 = $trf($W2, $W1, '2026-09-25 00:00:00', [$ln($W, $kg, 20)], '2026-09-26 09:00:00');
    $backdate($T4, '2026-09-25 07:00:00');   // dispatched 09-25 07:00, received 09-26 09:00 -> lead time 26 h
    $T5 = $trf($W1, $W2, '2026-09-24 00:00:00', [$ln($Z, $ltr, 1)], '2026-09-24 18:00:00');
    Database::transaction(fn (PDO $tx) => TransferService::reverse($tx, $T5, ['created_by' => $by, 'username' => 'iotest', 'reason' => 'io fixture reverse']));

    // ---- hand-computed expectations. HPP from the stored unit cost (per base unit) of the layers consumed.
    $unitCost = static function (int $txId) use ($pdo): float {
        return (float) $pdo->query("SELECT unit_cost_base FROM inventory_transaction_lines WHERE transaction_id = " . (int) $txId . ' LIMIT 1')->fetchColumn();
    };
    $aX = $unitCost($F['tx']['A'][0]);   // invoice A, row X
    $aY = $unitCost($F['tx']['A'][1]);
    $aZ = $unitCost($F['tx']['A'][2]);
    $cX = $unitCost($F['tx']['C'][0]);
    $lCost = 400.0;
    $exp = [
        // HPP = stored unit cost (per base unit) of the FIFO layers consumed. OUT1: X 12 = 10 from invoice A + 2 from C; Y 3 from A. OUT2: Z 5 from A, X 4 from C.
        'O1' => ['hpp_x' => round(10 * $aX + 2 * $cX, 4), 'hpp_y' => round(3 * $aY, 4), 'shipping' => 10000.0],
        'O2' => ['hpp_z' => round(5 * $aZ, 4), 'hpp_x' => round(4 * $cX, 4), 'shipping' => 0.0],
        'OL' => ['hpp' => round(5 * $lCost, 4)],
    ];
    $exp['O1']['hpp'] = round($exp['O1']['hpp_x'] + $exp['O1']['hpp_y'], 4);
    $exp['O2']['hpp'] = round($exp['O2']['hpp_z'] + $exp['O2']['hpp_x'], 4);
    $exp['hpp_total'] = round($exp['O1']['hpp'] + $exp['O2']['hpp'] + $exp['OL']['hpp'], 4);
    $exp['shipping_total'] = 10000.0;

    return $F + [
        'bakery' => ['1' => $B1, '2' => $B2], 'out' => ['O1' => $O1, 'O2' => $O2, 'O3' => $O3], 'legacy_out_tx' => $legacyOutTx,
        'trf' => ['T1' => $T1, 'T2' => $T2, 'T3' => $T3, 'T4' => $T4, 'T5' => $T5], 'io_expect' => $exp, 'units' => ['kg' => $kg, 'pcs' => $pcs, 'ltr' => $ltr],
    ];
}
