<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Laporan Nilai Stok & HPP" — DUAL VALUATION (FIFO + Average). STRICTLY READ-ONLY: nothing here writes, recalculates stock, rewrites a batch cost,
 * a FIFO allocation, item_price_history or any stored HPP. Everything is derived from the existing ledger (inventory_transactions /
 * inventory_transaction_lines), the FIFO cost trail (inventory_batches + fifo_allocations) and warehouse_transfer_lines.
 *
 * FIFO  (the system's OPERATIONAL method — FifoService::postOut consumes layers in (received_date, id) order):
 *   • every value is the ACTUAL posted ledger value (signed exactly like InventoryHppReportService::SIGNED_VALUE_SQL); HPP out = the actual
 *     fifo_allocations of the OUT line (which layer, which qty, which cost) — never reconstructed from a latest price;
 *   • "Layer Aktif" / remaining layer value are rebuilt per date from inventory_batches.original_qty_base minus the allocations whose consuming line
 *     is dated <= that date (a REVERSAL's mirror allocation restores / removes by the sign of its line) — the same quantities FifoService /
 *     VoidService / TransferService really moved. At "now" this equals inventory_batches.qty_base (checked by the reconciliation).
 *
 * Average (ANALYTICAL moving weighted average, per ITEM per WAREHOUSE; "Semua Gudang" = the sum of the per-warehouse results; never one average over
 *   different SKUs): events of an item are replayed in (transaction_date, id, line) order.
 *   • inbound with a recorded cost (IN, OPENING, PRODUCTION_OUT, positive ADJUSTMENT): value = the recorded line subtotal (Stock IN V2 inventory cost,
 *     opening value, adjustment cost), new average = (value before + value in) / (qty before + qty in);
 *   • outbound (OUT, PRODUCTION_IN, negative ADJUSTMENT, TRANSFER_OUT): value = qty x the CURRENT average; an outbound alone never changes the average;
 *   • TRANSFER_IN: carries the paired TRANSFER_OUT's average value (warehouse_transfer_lines links the two legs) so internal transfers net to zero;
 *     if the leg cannot be paired the recorded value is used and the event is flagged;
 *   • REVERSAL (void): the exact opposite of the ORIGINAL event's average value (reversal_of_id);
 *   • when the history cannot support an average — an outbound larger than the stock known at that moment (negative / migration-negative stock) — the
 *     item/warehouse is "Average tidak dapat direkonstruksi" from that event on (until its stock is exactly zero again): its Average figures are shown
 *     as "—" and NEVER summed into a total or invented.
 *
 * Column categories (identical for both methods, so Opening + Cost In − HPP ± Transfer ± Adjustment = Closing holds exactly):
 *   in  = POSTED IN / OPENING (mid-period) / PRODUCTION_OUT      out = POSTED OUT (HPP)       trf = POSTED TRANSFER_IN / TRANSFER_OUT
 *   adj = ADJUSTMENT, REVERSAL, PRODUCTION_IN and EVERY leg of a VOID transaction (a void original and its reversal net to zero)
 * An OPENING dated exactly at the period start 00:00:00 is beginning inventory, not a movement (same rule as InventoryHppReportService).
 * Only inventory_effect = 1 lines count (historical imports never moved stock).
 */
final class InventoryValuationService
{
    public const MAX_DAYS = 800;
    private const EPS = 1e-6;

    private const IN_TYPES = ['IN', 'OPENING', 'TRANSFER_IN', 'PRODUCTION_OUT'];
    private const OUT_TYPES = ['OUT', 'TRANSFER_OUT', 'PRODUCTION_IN'];

    public const TYPE_LABELS = [
        'IN' => 'Pembelian', 'OPENING' => 'Opening Stock', 'OUT' => 'Barang Keluar', 'TRANSFER_OUT' => 'Transfer Keluar', 'TRANSFER_IN' => 'Transfer Masuk',
        'ADJUSTMENT' => 'Adjustment', 'REVERSAL' => 'Reversal (Void)', 'PRODUCTION_IN' => 'Produksi (Bahan Keluar)', 'PRODUCTION_OUT' => 'Produksi (Hasil Masuk)',
        'OPNAME' => 'Stock Opname',
    ];

    // ======================================================================
    // public API
    // ======================================================================

    /** Overview: KPI cards of BOTH methods (the UI shows the selected one), item table or daily table, comparison, reconciliation. @return array<string,mixed> */
    public static function overview(PDO $pdo, array $f): array
    {
        $c = self::build($pdo, $f);
        $n = $c['f'];
        $out = [
            'method' => $n['method'], 'view' => $n['view'], 'system_method' => 'FIFO', 'period' => ['start' => $n['start'], 'end' => $n['end']],
            'scope' => ['warehouse_id' => $n['warehouse_id'], 'category_id' => $n['category_id'], 'q' => $n['q'], 'item_id' => $n['item_id']],
            'kpi' => self::kpis($c),
            'comparison' => self::comparison($c),
            'unreconstructable' => $c['unreconstructable'],
            'notes' => self::notes($c),
        ];
        if ($n['view'] === 'day') {
            $days = self::dailyRows($c);
            $out['bucket'] = $n['bucket'];
            $out['days'] = self::bucketRows($days, $n['bucket']);
            $out['days_footer'] = self::dailyFooter($days);
        } else {
            $rows = self::itemRows($c);
            $out['items'] = self::pageRows($rows, $n);
            $out['items_footer'] = self::itemFooter($rows);
            $out['page'] = ['page' => $n['page'], 'per_page' => $n['per_page'], 'total' => count($rows)];
        }
        $out['reconciliation'] = self::reconciliation($c);
        return $out;
    }

    /** One item: method-specific history, FIFO layers / Average formula example, FIFO-vs-Average comparison. @return array<string,mixed> */
    public static function itemDetail(PDO $pdo, array $f): array
    {
        if (empty($f['item_id'])) {
            throw new ValidationException(['item_id is required']);
        }
        $c = self::build($pdo, $f);
        $n = $c['f'];
        $id = (int) $n['item_id'];
        if (!isset($c['items'][$id])) {
            throw new NotFoundException('item not found');
        }
        $item = $c['items'][$id];
        $rows = self::itemRows($c);
        $agg = null;
        foreach ($rows as $r) {
            if ($r['item_id'] === $id) {
                $agg = $r;
            }
        }
        $hist = self::history($c, $id);
        $res = [
            'method' => $n['method'], 'system_method' => 'FIFO', 'period' => ['start' => $n['start'], 'end' => $n['end']],
            'item' => ['id' => $id, 'sku' => $item['sku'], 'name' => $item['name'], 'category' => $item['category'], 'unit' => $item['unit']],
            'summary' => $agg,
            'opening' => $hist['opening'],
            'rows' => $n['method'] === 'fifo' ? $hist['fifo'] : $hist['average'],
            'compare_rows' => $hist['compare'],
            'comparison' => self::itemComparison($c, $id, $agg, $hist),
            'average_unknown' => $agg !== null && !$agg['avg']['known'] ? $agg['avg']['reason'] : null,
        ];
        if ($n['method'] === 'fifo') {
            $res['layers'] = self::itemLayers($c, $id);
        } else {
            $res['formula'] = self::averageFormula($hist, $agg);
        }
        return $res;
    }

    /** Excel sheets for the selected method (+ one comparison sheet). @return array<string,array<string,mixed>> */
    public static function exportWorkbook(PDO $pdo, array $f, array $meta): array
    {
        $c = self::build($pdo, $f);
        $m = $c['f']['method'];
        $label = $m === 'fifo' ? 'FIFO' : 'AVERAGE';
        $rows = self::itemRows($c);
        $cmp = self::comparison($c);
        $kpi = self::kpis($c);
        $sheets = [];
        $view = (string) ($c['f']['view'] ?? 'item');
        // ---- Ringkasan (the active mode is stated on the first rows: Metode Penilaian / Tampilan; Average is labelled analytical)
        $sum = [];
        $modeRows = ['Metode Penilaian' => $label, 'Tampilan' => $view === 'day' ? 'Per Hari' : 'Per Barang', 'Metode operasional sistem' => 'FIFO'];
        if ($m === 'average') {
            $modeRows['Catatan metode'] = 'Analytical Average — tidak mengubah FIFO operasional';
        }
        foreach (array_merge($modeRows, $meta) as $k => $v) {
            $sum[] = [$k, (string) $v];
        }
        $sum[] = ['', ''];
        foreach ($m === 'fifo' ? [
            ['Nilai Stok Awal FIFO', $kpi['fifo']['opening']], ['Inventory Cost Masuk', $kpi['fifo']['cost_in']], ['HPP Keluar FIFO', $kpi['fifo']['hpp']], ['Transfer bersih', $kpi['fifo']['transfer']],
            ['Adjustment & Koreksi', $kpi['fifo']['adjustment']], ['Nilai Stok Akhir FIFO', $kpi['fifo']['closing']], ['Nilai Layer Aktif (akhir)', $kpi['fifo']['layer_value']],
            ['Jumlah Layer Aktif', $kpi['fifo']['layers']], ['SKU Memiliki Stok', $kpi['fifo']['skus_with_stock']], ['Variance FIFO (ledger − layer)', $kpi['fifo']['variance']],
        ] : [
            ['Nilai Stok Awal Average', $kpi['average']['opening']], ['Pembelian / Cost In', $kpi['average']['cost_in']], ['Pemakaian / Barang Keluar', $kpi['average']['usage']],
            ['HPP Average', $kpi['average']['hpp']], ['Transfer & koreksi bersih', $kpi['average']['other_net']], ['Nilai Stok Akhir Average', $kpi['average']['closing']],
            ['Selisih / Rekonsiliasi', $kpi['average']['variance']], ['Barang tidak dapat direkonstruksi (tidak dihitung)', $kpi['average']['unknown_items']],
        ] as $r) {
            $sum[] = [$r[0], self::cellNum($r[1])];
        }
        $sheets[$m === 'fifo' ? 'Ringkasan FIFO' : 'Ringkasan Average'] = ['headers' => ['Keterangan', 'Nilai'], 'rows' => $sum];
        // ---- the active view first ("Per Hari" or "Per Barang"), the other view + history + layers after it
        $itemName = $m === 'fifo' ? 'Nilai Stok per Barang' : 'Average per Barang';
        $dayName = $m === 'fifo' ? 'Nilai Stok Per Hari FIFO' : 'Average Per Hari';
        $itemSheet = self::itemSheet($rows, $m) + ['freeze_header' => true, 'autofilter' => true];
        $daySheet = self::daySheet($c, $m) + ['freeze_header' => true, 'autofilter' => true];
        if ($view === 'day') {
            $sheets[$dayName] = $daySheet;
            $sheets[$itemName] = $itemSheet;
        } else {
            $sheets[$itemName] = $itemSheet;
            $sheets[$dayName] = $daySheet;
        }
        $sheets[$m === 'fifo' ? 'Riwayat FIFO' : 'Riwayat Average'] = self::historySheet($c, $m) + ['freeze_header' => true, 'autofilter' => true];
        if ($m === 'fifo') {
            $sheets['Layer Aktif'] = self::layerSheet($c, true) + ['freeze_header' => true, 'autofilter' => true];
            $sheets['Layer Terpakai'] = self::layerSheet($c, false) + ['freeze_header' => true, 'autofilter' => true];
        }
        $rec = self::reconciliation($c);
        $rr = [];
        foreach ($rec['checks'] as $ck) {
            $rr[] = [$ck['name'], $ck['ok'] ? 'OK' : 'SELISIH', $ck['difference'] ?? 0, $ck['detail']];
        }
        $sheets['Rekonsiliasi'] = ['headers' => ['Pemeriksaan', 'Status', 'Selisih', 'Keterangan'], 'rows' => $rr];
        // ---- optional comparison sheet
        $cr = [];
        foreach (self::itemRows($c) as $r) {
            $known = $r['avg']['known'];
            $cr[] = [$r['sku'], ExcelWriterService::sanitizeCellText($r['name']), $r['qty_close'], $r['fifo']['closing'], $known ? $r['avg']['closing'] : 'Average tidak dapat direkonstruksi',
                $r['fifo']['hpp'], $known ? $r['avg']['hpp'] : '—', $known ? round($r['avg']['closing'] - $r['fifo']['closing'], 4) : '—', $known ? round($r['avg']['hpp'] - $r['fifo']['hpp'], 4) : '—'];
        }
        $cr[] = ['TOTAL (barang yang Average-nya dapat direkonstruksi)', '', '', $cmp['fifo']['closing'], $cmp['average']['closing'], $cmp['fifo']['hpp'], $cmp['average']['hpp'], $cmp['diff']['closing'], $cmp['diff']['hpp']];
        $sheets['FIFO vs Average'] = ['headers' => ['SKU', 'Item', 'Qty Akhir', 'Nilai FIFO', 'Nilai Average', 'HPP FIFO', 'HPP Average', 'Selisih Nilai', 'Selisih HPP'], 'rows' => $cr, 'freeze_header' => true];
        return $sheets;
    }

    /** Read-only reconciliation (also used by scripts/valuation_reconcile_check.php). @return array{checks:list<array<string,mixed>>,ok:bool} */
    public static function reconciliation(array|PDO $ctx, ?array $f = null): array
    {
        $c = $ctx instanceof PDO ? self::build($ctx, $f ?? []) : $ctx;
        return self::reconcile($c);
    }

    /** Full context for tools (CLI / tests). @return array<string,mixed> */
    public static function context(PDO $pdo, array $f): array
    {
        return self::build($pdo, $f);
    }

    // ======================================================================
    // filters
    // ======================================================================

    /** @return array<string,mixed> */
    private static function normalize(array $f): array
    {
        $start = (string) ($f['start_date'] ?? '');
        $end = (string) ($f['end_date'] ?? '');
        if (strtotime($start) === false || strtotime($end) === false || $start === '' || $end === '' || $start > $end) {
            throw new ValidationException(['start_date and end_date are required and start_date must not be after end_date']);
        }
        $method = strtolower((string) ($f['method'] ?? 'fifo'));
        if (!in_array($method, ['fifo', 'average'], true)) {
            throw new ValidationException(['method must be fifo or average']);
        }
        $view = (string) ($f['view'] ?? 'item');
        if (!in_array($view, ['item', 'day'], true)) {
            throw new ValidationException(['view must be item or day']);
        }
        if ($view === 'day' && (strtotime($end) - strtotime($start)) / 86400 > self::MAX_DAYS) {
            throw new ValidationException(['period too long for the daily view (max ' . self::MAX_DAYS . ' days)']);
        }
        $bucket = (string) ($f['bucket'] ?? 'day');
        $int = static fn (string $k): ?int => isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null ? (int) $f[$k] : null;
        return [
            'start' => date('Y-m-d', strtotime($start)), 'end' => date('Y-m-d', strtotime($end)), 'method' => $method, 'view' => $view,
            'bucket' => in_array($bucket, ['day', 'week', 'month'], true) ? $bucket : 'day',
            'warehouse_id' => $int('warehouse_id'), 'category_id' => $int('category_id'), 'item_id' => $int('item_id'),
            'q' => isset($f['q']) && trim((string) $f['q']) !== '' ? trim((string) $f['q']) : null,
            'sort' => (string) ($f['sort'] ?? 'name'), 'dir' => strtolower((string) ($f['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($f['page'] ?? 1)), 'per_page' => min(500, max(10, (int) ($f['per_page'] ?? 50))),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} item filter fragment (alias i) with UNIQUE placeholders */
    private static function itemFilter(array $n, string $sfx = ''): array
    {
        $sql = '';
        $bind = [];
        if ($n['category_id'] !== null) {
            $sql .= " AND i.category_id = :cat{$sfx}";
            $bind["cat{$sfx}"] = $n['category_id'];
        }
        if ($n['item_id'] !== null) {
            $sql .= " AND i.id = :iid{$sfx}";
            $bind["iid{$sfx}"] = $n['item_id'];
        }
        if ($n['q'] !== null) {
            $sql .= " AND (i.sku LIKE :qa{$sfx} OR i.name LIKE :qb{$sfx})";
            $bind["qa{$sfx}"] = $bind["qb{$sfx}"] = '%' . $n['q'] . '%';
        }
        return [$sql, $bind];
    }

    // ======================================================================
    // data
    // ======================================================================

    /** @return array<string,mixed> */
    private static function build(PDO $pdo, array $f): array
    {
        $n = self::normalize($f);
        $startTs = $n['start'] . ' 00:00:00';
        $endNext = date('Y-m-d 00:00:00', strtotime($n['end'] . ' +1 day'));
        [$fs, $fb] = self::itemFilter($n);

        $st = $pdo->prepare(
            "SELECT i.id, i.sku, i.name, COALESCE(c.name, '—') AS category, u.code AS unit
               FROM items i LEFT JOIN categories c ON c.id = i.category_id JOIN units u ON u.id = i.base_unit_id
              WHERE 1 = 1 {$fs} ORDER BY i.name, i.id"
        );
        $st->execute($fb);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $items[(int) $r['id']] = ['id' => (int) $r['id'], 'sku' => (string) $r['sku'], 'name' => (string) $r['name'], 'category' => (string) $r['category'], 'unit' => (string) $r['unit']];
        }
        $whNames = [];
        foreach ($pdo->query('SELECT id, code, name FROM warehouses')->fetchAll() as $w) {
            $whNames[(int) $w['id']] = ['code' => (string) $w['code'], 'name' => (string) $w['name']];
        }

        // ---- ledger events (all warehouses: the Average replay needs the transfer partner), up to the end of the period
        [$fs2, $fb2] = self::itemFilter($n, 'e');
        $st = $pdo->prepare(
            "SELECT l.id AS line_id, l.transaction_id AS tx_id, l.line_no, l.item_id, l.warehouse_id AS wh, l.base_qty, l.subtotal, l.notes,
                    t.transaction_type AS type, t.status, t.transaction_date AS ts, t.created_at, t.reference_no AS ref, t.reversal_of_id, t.created_by, u.username AS by_user
               FROM inventory_transaction_lines l
               JOIN inventory_transactions t ON t.id = l.transaction_id
               JOIN items i ON i.id = l.item_id
               LEFT JOIN users u ON u.id = t.created_by
              WHERE t.inventory_effect = 1 AND t.status IN ('POSTED','VOID') AND t.transaction_date < :endnext {$fs2}
              ORDER BY l.item_id, t.transaction_date, t.id, l.line_no"
        );
        $st->execute(['endnext' => $endNext] + $fb2);
        $byItem = [];
        foreach ($st->fetchAll() as $r) {
            $type = (string) $r['type'];
            $qty = (float) $r['base_qty'];
            $val = (float) $r['subtotal'];
            if (in_array($type, self::OUT_TYPES, true)) {
                $qty = -abs($qty);
                $val = -abs($val);
            }
            $status = (string) $r['status'];
            $cat = $status === 'VOID' || in_array($type, ['ADJUSTMENT', 'REVERSAL', 'PRODUCTION_IN', 'OPNAME'], true) ? 'adj'
                : ($type === 'OUT' ? 'out' : (in_array($type, ['TRANSFER_IN', 'TRANSFER_OUT'], true) ? 'trf' : 'in'));
            $ts = (string) $r['ts'];
            $byItem[(int) $r['item_id']][] = [
                'line_id' => (int) $r['line_id'], 'tx_id' => (int) $r['tx_id'], 'line_no' => (int) $r['line_no'], 'item_id' => (int) $r['item_id'], 'wh' => (int) $r['wh'],
                'type' => $type, 'status' => $status, 'ts' => $ts, 'created_at' => (string) $r['created_at'], 'ref' => $r['ref'] !== null ? (string) $r['ref'] : null,
                'reversal_of' => $r['reversal_of_id'] !== null ? (int) $r['reversal_of_id'] : null, 'by' => $r['by_user'] !== null ? (string) $r['by_user'] : null,
                'notes' => $r['notes'] !== null ? (string) $r['notes'] : null, 'qty' => round($qty, 6), 'val' => round($val, 4), 'cat' => $cat,
                'boundary' => $type === 'OPENING' && $ts === $startTs, 'raw_qty' => (float) $r['base_qty'],
            ];
        }
        // transfer legs: TRANSFER_IN line -> TRANSFER_OUT line
        $st = $pdo->prepare(
            "SELECT wtl.in_transaction_line_id AS in_line, wtl.out_transaction_line_id AS out_line
               FROM warehouse_transfer_lines wtl JOIN items i ON i.id = wtl.item_id
              WHERE wtl.in_transaction_line_id IS NOT NULL AND wtl.out_transaction_line_id IS NOT NULL {$fs}"
        );
        $st->execute($fb);
        $pairs = [];
        foreach ($st->fetchAll() as $r) {
            $pairs[(int) $r['in_line']] = (int) $r['out_line'];
        }

        $ctx = [
            'f' => $n, 'start_ts' => $startTs, 'end_next' => $endNext, 'items' => $items, 'wh_names' => $whNames, 'events' => [], 'pairs' => $pairs, 'pdo' => $pdo,
            'unreconstructable' => [], 'cache' => new \ArrayObject(),
        ];
        foreach ($byItem as $iid => $evs) {
            $ctx['events'][$iid] = self::replay($evs, $pairs, $iid);
        }
        self::loadLayers($pdo, $ctx, $n, $endNext);
        self::applyAverageKnown($ctx);
        return $ctx;
    }

    /**
     * Ledger running balance (FIFO method) + Average moving-weighted replay for ONE item (all warehouses, chronological).
     * @param list<array<string,mixed>> $evs
     * @param array<int,int> $pairs
     * @return list<array<string,mixed>>
     */
    private static function replay(array $evs, array $pairs, int $itemId): array
    {
        $led = [];
        $avg = [];
        $outAvgUnit = [];   // TRANSFER_OUT line_id => average unit cost it was valued at
        $origAval = [];     // tx_id => signed average value of that transaction's line (for REVERSAL)
        foreach ($evs as &$e) {
            $wh = $e['wh'];
            $L = &$led[$wh];
            $L ??= ['q' => 0.0, 'v' => 0.0];
            $e['fq_pre'] = $L['q'];
            $e['fv_pre'] = $L['v'];
            $L['q'] = round($L['q'] + $e['qty'], 6);
            $L['v'] = round($L['v'] + $e['val'], 4);
            $e['fq_post'] = $L['q'];
            $e['fv_post'] = $L['v'];
            unset($L);

            $S = &$avg[$wh];
            $S ??= ['q' => 0.0, 'v' => 0.0, 'unk' => false];
            if ($S['unk'] && abs($S['q']) < self::EPS) {
                $S['unk'] = false;
                $S['v'] = 0.0;
            }
            $e['aq_pre'] = $S['q'];
            $e['av_pre'] = $S['unk'] ? null : $S['v'];
            $e['aavg_pre'] = (!$S['unk'] && $S['q'] > self::EPS) ? $S['v'] / $S['q'] : null;
            $e['unk_pre'] = $S['unk'];
            $e['unk_reason'] = null;
            $e['flag'] = null;
            $qty = $e['qty'];
            $aval = null;
            $type = $e['type'];
            if ($type === 'REVERSAL') {
                $orig = $e['reversal_of'];
                if ($orig !== null && array_key_exists($orig, $origAval) && $origAval[$orig] !== null) {
                    $aval = -$origAval[$orig];
                } elseif ($orig !== null && array_key_exists($orig, $origAval)) {
                    $aval = null;   // the original was not valued in the Average world
                } else {
                    $aval = $e['val'];
                    $e['flag'] = 'nilai catatan produksi (transaksi asal tidak ditemukan)';
                }
            } elseif ($qty > 0) {
                if ($type === 'TRANSFER_IN') {
                    $out = $pairs[$e['line_id']] ?? null;
                    if ($out !== null && isset($outAvgUnit[$out])) {
                        $aval = round($qty * $outAvgUnit[$out], 4);
                    } else {
                        $aval = $e['val'];
                        $e['flag'] = 'nilai catatan produksi (transfer tidak dapat dipasangkan dengan nilai Average gudang asal)';
                    }
                } else {
                    $aval = $e['val'];
                }
            } elseif ($qty < 0) {
                if ($type === 'OPENING') {
                    $S['unk'] = true;
                    $e['unk_reason'] = 'opening negatif (migrasi) — nilai Average tidak dapat dibentuk';
                } elseif ($S['unk'] || $S['q'] <= self::EPS || -$qty > $S['q'] + self::EPS) {
                    if (!$S['unk']) {
                        $e['unk_reason'] = 'barang keluar ' . self::fmtQty(-$qty) . ' melebihi saldo yang diketahui ' . self::fmtQty(max(0.0, $S['q'])) . ' (stok negatif / riwayat tidak cukup)';
                    }
                    $S['unk'] = true;
                } else {
                    $aval = round($qty * ($S['v'] / $S['q']), 4);
                }
                if ($type === 'TRANSFER_OUT' && $aval !== null && $qty != 0.0) {
                    $outAvgUnit[$e['line_id']] = abs($aval) / abs($qty);
                }
            }
            if ($S['unk']) {
                $aval = null;
            }
            $S['q'] = round($S['q'] + $qty, 6);
            if (!$S['unk']) {
                if ($aval === null) {
                    $S['unk'] = true;
                    $e['unk_reason'] ??= 'nilai Average dari transaksi asal tidak tersedia';
                } else {
                    $S['v'] = round($S['v'] + $aval, 4);
                }
            }
            $origAval[$e['tx_id']] = $aval;
            $e['aval'] = $aval;
            $e['unk'] = $S['unk'];
            $e['aq_post'] = $S['q'];
            $e['av_post'] = $S['unk'] ? null : $S['v'];
            $e['aavg_post'] = (!$S['unk'] && $S['q'] > self::EPS) ? $S['v'] / $S['q'] : null;
            unset($S);
        }
        unset($e);
        return $evs;
    }

    private static function fmtQty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');
    }

    // ----------------------------------------------------------------------
    // FIFO layers
    // ----------------------------------------------------------------------

    /** Loads batches + the allocations on them and rebuilds per-date remaining quantity. */
    private static function loadLayers(PDO $pdo, array &$ctx, array $n, string $endNext): void
    {
        [$fs, $fb] = self::itemFilter($n, 'b');
        $st = $pdo->prepare(
            "SELECT b.id, b.item_id, b.warehouse_id AS wh, b.original_qty_base AS orig, b.qty_base AS cur, b.unit_cost_base AS cost, b.received_date AS rd, b.is_negative_layer AS neg,
                    t.reference_no AS src_ref, t.transaction_type AS src_type
               FROM inventory_batches b JOIN items i ON i.id = b.item_id
               LEFT JOIN inventory_transaction_lines sl ON sl.id = b.source_transaction_line_id
               LEFT JOIN inventory_transactions t ON t.id = sl.transaction_id
              WHERE b.received_date < :endnext {$fs}
              ORDER BY b.item_id, b.warehouse_id, b.received_date, b.id"
        );
        $st->execute(['endnext' => $endNext] + $fb);
        $layers = [];
        foreach ($st->fetchAll() as $r) {
            $layers[(int) $r['id']] = [
                'id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'wh' => (int) $r['wh'], 'orig' => (float) $r['orig'], 'cur' => (float) $r['cur'], 'cost' => (float) $r['cost'],
                'rd' => (string) $r['rd'], 'neg' => (int) $r['neg'] === 1, 'src_ref' => $r['src_ref'] !== null ? (string) $r['src_ref'] : null, 'src_type' => $r['src_type'] !== null ? (string) $r['src_type'] : null,
            ];
        }
        [$fs3, $fb3] = self::itemFilter($n, 'a');
        $st = $pdo->prepare(
            "SELECT fa.id, fa.batch_id, fa.qty_allocated AS qty, fa.unit_cost_base AS cost, fa.subtotal, fa.transaction_line_id AS line_id, l.base_qty AS line_qty,
                    t.transaction_date AS ts, t.transaction_type AS type, t.status, t.id AS tx_id
               FROM fifo_allocations fa
               JOIN inventory_batches b ON b.id = fa.batch_id
               JOIN items i ON i.id = b.item_id
               JOIN inventory_transaction_lines l ON l.id = fa.transaction_line_id
               JOIN inventory_transactions t ON t.id = l.transaction_id
              WHERE t.transaction_date < :endnext AND t.inventory_effect = 1 {$fs3}
              ORDER BY fa.id"
        );
        $st->execute(['endnext' => $endNext] + $fb3);
        $allocs = [];
        $byLine = [];
        $tl = [];   // timeline: [ts, order, layer_id, delta]
        $startTs = $n['start'] . ' 00:00:00';
        foreach ($layers as $L) {
            // an OPENING dated exactly at the period start is beginning inventory (same boundary rule as the ledger), so its layer exists "before" the start
            $ts = $L['src_type'] === 'OPENING' && $L['rd'] === $startTs ? date('Y-m-d H:i:s', strtotime($startTs) - 1) : $L['rd'];
            $tl[] = [$ts, 0, $L['id'], $L['orig']];
        }
        foreach ($st->fetchAll() as $r) {
            $a = ['id' => (int) $r['id'], 'batch_id' => (int) $r['batch_id'], 'qty' => (float) $r['qty'], 'cost' => (float) $r['cost'], 'subtotal' => (float) $r['subtotal'],
                'line_id' => (int) $r['line_id'], 'ts' => (string) $r['ts'], 'type' => (string) $r['type'], 'status' => (string) $r['status'], 'line_qty' => (float) $r['line_qty'], 'tx_id' => (int) $r['tx_id']];
            $allocs[] = $a;
            $byLine[$a['line_id']][] = $a;
            $L = $layers[$a['batch_id']] ?? null;
            if ($L === null || $L['neg']) {
                continue;   // a negative (deficit) layer is created BY its own allocation — it is not a consumption of stock
            }
            $delta = $a['type'] === 'REVERSAL' ? ($a['line_qty'] >= 0 ? $a['qty'] : -$a['qty']) : -$a['qty'];
            $tl[] = [$a['ts'], 1, $a['batch_id'], $delta];
        }
        usort($tl, static fn (array $x, array $y) => [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]]);
        $ctx['layers'] = $layers;
        $ctx['allocs'] = $allocs;
        $ctx['alloc_by_line'] = $byLine;
        $ctx['layer_timeline'] = $tl;
    }

    /**
     * Walks the timeline once and returns remaining-per-layer snapshots at the requested instants.
     * @param list<string> $instants ascending 'Y-m-d H:i:s' (exclusive upper bounds)
     * @return array<string,array{rem:array<int,float>}> keyed by instant
     */
    private static function layerSnapshots(array $ctx, array $instants): array
    {
        $rem = [];
        $out = [];
        $p = 0;
        $tl = $ctx['layer_timeline'];
        $cnt = count($tl);
        foreach ($instants as $t) {
            while ($p < $cnt && $tl[$p][0] < $t) {
                $rem[$tl[$p][2]] = round(($rem[$tl[$p][2]] ?? 0.0) + $tl[$p][3], 6);
                $p++;
            }
            $out[$t] = ['rem' => $rem];
        }
        return $out;
    }

    /** Scope test for a warehouse. */
    private static function inScope(array $ctx, int $wh): bool
    {
        return $ctx['f']['warehouse_id'] === null || $ctx['f']['warehouse_id'] === $wh;
    }

    /** @return array{count:int,value:float,qty:float} for layers in scope at a snapshot (optionally one item) */
    private static function layerTotals(array $ctx, array $rem, ?int $itemId = null): array
    {
        $c = 0;
        $v = 0.0;
        $q = 0.0;
        foreach ($rem as $id => $r) {
            $L = $ctx['layers'][$id] ?? null;
            if ($L === null || !self::inScope($ctx, $L['wh']) || ($itemId !== null && $L['item_id'] !== $itemId)) {
                continue;
            }
            if ($r > self::EPS) {
                $c++;
            }
            $v += $r * $L['cost'];
            $q += $r;
        }
        return ['count' => $c, 'value' => round($v, 4), 'qty' => round($q, 6)];
    }

    // ----------------------------------------------------------------------
    // Average known / unknown
    // ----------------------------------------------------------------------

    private static function applyAverageKnown(array &$ctx): void
    {
        $unknown = [];
        foreach ($ctx['events'] as $iid => $evs) {
            $lastByWh = [];
            $reason = null;
            $bad = false;
            foreach ($evs as $e) {
                if (!self::inScope($ctx, $e['wh'])) {
                    continue;
                }
                $lastByWh[$e['wh']] = $e;
                if ($e['unk_reason'] !== null && $reason === null) {
                    $reason = date('d M Y', strtotime($e['ts'])) . ' ' . ($e['ref'] ?? self::TYPE_LABELS[$e['type']] ?? $e['type']) . ': ' . $e['unk_reason'];
                }
                if (($e['unk_pre'] || $e['unk']) && $e['ts'] >= $ctx['start_ts'] && !$e['boundary']) {
                    $bad = true;
                }
            }
            foreach ($lastByWh as $e) {
                if ($e['unk']) {
                    $bad = true;
                }
            }
            if ($bad) {
                $unknown[$iid] = $reason ?? 'riwayat nilai tidak cukup untuk menghitung Average';
            }
        }
        $ctx['avg_unknown'] = $unknown;
        $list = [];
        foreach ($unknown as $iid => $why) {
            $list[] = ['item_id' => $iid, 'sku' => $ctx['items'][$iid]['sku'], 'name' => $ctx['items'][$iid]['name'], 'reason' => $why];
        }
        $ctx['unreconstructable'] = $list;
    }

    // ======================================================================
    // aggregation
    // ======================================================================

    private static function emptyBucket(): array
    {
        return ['open' => 0.0, 'in' => 0.0, 'out' => 0.0, 'trf' => 0.0, 'adj' => 0.0, 'use_other' => 0.0, 'pos_other' => 0.0];
    }

    /**
     * Per item: opening / movements / closing for BOTH methods over [fromTs, toNext) in the scope.
     * @return array<int,array<string,mixed>>
     */
    private static function aggregate(array $ctx, string $fromTs, string $toNext): array
    {
        $res = [];
        foreach ($ctx['events'] as $iid => $evs) {
            $a = [
                'item_id' => $iid, 'qty_open' => 0.0, 'qty_in' => 0.0, 'qty_out' => 0.0, 'qty_other' => 0.0, 'moves' => 0, 'f' => self::emptyBucket(), 'a' => self::emptyBucket(),
                'state_q' => 0.0, 'state_fv' => 0.0, 'state_av' => 0.0,
            ];
            $lastByWh = [];
            foreach ($evs as $e) {
                if (!self::inScope($ctx, $e['wh']) || $e['ts'] >= $toNext) {
                    continue;
                }
                $lastByWh[$e['wh']] = $e;
                $isOpen = $e['ts'] < $fromTs || ($e['boundary'] && $e['ts'] === $fromTs);
                $av = $e['aval'];
                if ($isOpen) {
                    $a['qty_open'] += $e['qty'];
                    $a['f']['open'] += $e['val'];
                    $a['a']['open'] += $av ?? 0.0;
                    continue;
                }
                $a['moves']++;
                $cat = $e['cat'];
                if ($cat === 'in') {
                    $a['qty_in'] += $e['qty'];
                } elseif ($cat === 'out') {
                    $a['qty_out'] += -$e['qty'];
                } else {
                    $a['qty_other'] += $e['qty'];
                }
                $a['f'][$cat] += $e['val'];
                $a['a'][$cat] += $av ?? 0.0;
                if ($e['status'] === 'POSTED') {
                    $posted = $e['type'] === 'PRODUCTION_IN' || ($e['type'] === 'ADJUSTMENT' && $e['qty'] < 0);
                    if ($posted) {
                        $a['f']['use_other'] += -$e['val'];
                        $a['a']['use_other'] += -($av ?? 0.0);
                    }
                }
            }
            foreach ($lastByWh as $e) {
                $a['state_q'] += $e['aq_post'];
                $a['state_fv'] += $e['fv_post'];
                $a['state_av'] += $e['av_post'] ?? 0.0;
            }
            $a['known'] = !isset($ctx['avg_unknown'][$iid]);
            $a['qty_close'] = round($a['qty_open'] + $a['qty_in'] - $a['qty_out'] + $a['qty_other'], 6);
            foreach (['f', 'a'] as $m) {
                $b = &$a[$m];
                $b['close'] = $b['open'] + $b['in'] + $b['out'] + $b['trf'] + $b['adj'];
                unset($b);
            }
            $res[$iid] = $a;
        }
        return $res;
    }

    /** Rows for the Per Barang table. @return list<array<string,mixed>> */
    private static function itemRows(array $ctx): array
    {
        if (isset($ctx['cache']['rows'])) {
            return $ctx['cache']['rows'];
        }
        $agg = self::aggregate($ctx, $ctx['start_ts'], $ctx['end_next']);
        $snap = self::layerSnapshots($ctx, [$ctx['start_ts'], $ctx['end_next']]);
        $rows = [];
        foreach ($agg as $iid => $a) {
            $hasStock = abs($a['qty_open']) > self::EPS || abs($a['qty_close']) > self::EPS || abs($a['f']['close']) > 0.00005;
            if (!$hasStock && $a['moves'] === 0) {
                continue;
            }
            $it = $ctx['items'][$iid];
            $lt = self::layerTotals($ctx, $snap[$ctx['end_next']]['rem'], $iid);
            $lo = self::layerTotals($ctx, $snap[$ctx['start_ts']]['rem'], $iid);
            $fclose = round($a['f']['close'], 4);
            $known = $a['known'];
            $aclose = round($a['a']['close'], 4);
            $rows[] = [
                'item_id' => $iid, 'sku' => $it['sku'], 'name' => $it['name'], 'category' => $it['category'], 'unit' => $it['unit'], 'moves' => $a['moves'],
                'qty_open' => round($a['qty_open'], 6), 'qty_in' => round($a['qty_in'], 6), 'qty_out' => round($a['qty_out'], 6), 'qty_other' => round($a['qty_other'], 6), 'qty_close' => $a['qty_close'],
                'fifo' => [
                    'opening' => round($a['f']['open'], 4), 'cost_in' => round($a['f']['in'], 4), 'hpp' => round(-$a['f']['out'], 4), 'transfer' => round($a['f']['trf'], 4), 'adjustment' => round($a['f']['adj'], 4),
                    'closing' => $fclose, 'unit_cost' => abs($a['qty_close']) > self::EPS ? round($fclose / $a['qty_close'], 4) : null, 'layers' => $lt['count'], 'layers_open' => $lo['count'],
                    'layer_value' => $lt['value'], 'layer_qty' => $lt['qty'], 'variance' => round($fclose - $lt['value'], 4), 'variance_qty' => round($a['qty_close'] - $lt['qty'], 6),
                ],
                'avg' => $known ? [
                    'known' => true, 'reason' => null, 'opening' => round($a['a']['open'], 4), 'cost_in' => round($a['a']['in'], 4), 'hpp' => round(-$a['a']['out'], 4), 'usage' => round(-$a['a']['out'] + $a['a']['use_other'], 4),
                    'transfer' => round($a['a']['trf'], 4), 'adjustment' => round($a['a']['adj'], 4), 'closing' => $aclose,
                    'unit_cost' => abs($a['qty_close']) > self::EPS ? round($aclose / $a['qty_close'], 4) : null,
                    'avg_open' => abs($a['qty_open']) > self::EPS ? round($a['a']['open'] / $a['qty_open'], 4) : null,
                    'variance' => round($a['state_av'] - $aclose, 4),
                ] : ['known' => false, 'reason' => $ctx['avg_unknown'][$iid]],
            ];
        }
        $ctx['cache']['rows'] = $rows;
        return $rows;
    }

    /** @return list<array<string,mixed>> the requested page, sorted */
    private static function pageRows(array $rows, array $n): array
    {
        $m = $n['method'] === 'fifo' ? 'fifo' : 'avg';
        $key = $n['sort'];
        usort($rows, static function (array $x, array $y) use ($key, $m) {
            $get = static fn (array $r) => match ($key) {
                'sku' => $r['sku'], 'value' => $r[$m]['closing'] ?? -INF, 'hpp' => $r[$m]['hpp'] ?? -INF, 'qty' => $r['qty_close'], default => mb_strtolower($r['name']),
            };
            return $get($x) <=> $get($y);
        });
        if ($n['dir'] === 'desc') {
            $rows = array_reverse($rows);
        }
        return array_slice($rows, ($n['page'] - 1) * $n['per_page'], $n['per_page']);
    }

    /** @return array<string,mixed> */
    private static function itemFooter(array $rows): array
    {
        $t = ['f' => ['opening' => 0.0, 'cost_in' => 0.0, 'hpp' => 0.0, 'transfer' => 0.0, 'adjustment' => 0.0, 'closing' => 0.0, 'layer_value' => 0.0, 'layers' => 0, 'variance' => 0.0],
              'a' => ['opening' => 0.0, 'cost_in' => 0.0, 'hpp' => 0.0, 'usage' => 0.0, 'transfer' => 0.0, 'adjustment' => 0.0, 'closing' => 0.0, 'variance' => 0.0], 'items' => count($rows), 'unknown_items' => 0];
        foreach ($rows as $r) {
            foreach (array_keys($t['f']) as $k) {
                $t['f'][$k] += $r['fifo'][$k];
            }
            if ($r['avg']['known']) {
                foreach (array_keys($t['a']) as $k) {
                    $t['a'][$k] += $r['avg'][$k];
                }
            } else {
                $t['unknown_items']++;
            }
        }
        foreach (['f', 'a'] as $m) {
            foreach ($t[$m] as $k => $v) {
                $t[$m][$k] = is_int($v) ? $v : round($v, 4);
            }
        }
        return $t;
    }

    // ----------------------------------------------------------------------
    // KPI / comparison
    // ----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function kpis(array $ctx): array
    {
        $rows = self::itemRows($ctx);
        $foot = self::itemFooter($rows);
        // previous window of the same length (for "vs periode lalu")
        $len = (int) round((strtotime($ctx['f']['end']) - strtotime($ctx['f']['start'])) / 86400) + 1;
        $pStart = date('Y-m-d 00:00:00', strtotime($ctx['f']['start'] . " -{$len} days"));
        $prev = self::aggregate($ctx, $pStart, $ctx['start_ts']);
        $pf = ['hpp' => 0.0, 'close' => 0.0, 'a_hpp' => 0.0, 'a_close' => 0.0];
        foreach ($prev as $iid => $a) {
            $pf['hpp'] += -$a['f']['out'];
            $pf['close'] += $a['f']['close'];
            if ($a['known']) {
                $pf['a_hpp'] += -$a['a']['out'];
                $pf['a_close'] += $a['a']['close'];
            }
        }
        $delta = static fn (float $now, float $then): ?float => abs($then) > 0.00005 ? round(($now - $then) / abs($then) * 100, 1) : null;
        $withStock = 0;
        foreach ($rows as $r) {
            if ($r['qty_close'] > self::EPS) {
                $withStock++;
            }
        }
        $F = $foot['f'];
        $A = $foot['a'];
        $layersOpen = 0;
        foreach ($rows as $r) {
            $layersOpen += $r['fifo']['layers_open'];
        }
        // Average other-net = everything that is neither cost-in nor usage: transfer + adjustment/void net + the usage that was already counted in "usage"
        $usageOther = 0.0;
        foreach ($rows as $r) {
            if ($r['avg']['known']) {
                $usageOther += $r['avg']['usage'] - $r['avg']['hpp'];
            }
        }
        $otherNet = round($A['transfer'] + $A['adjustment'] + $usageOther, 4);
        $aClosingCheck = round($A['opening'] + $A['cost_in'] - $A['usage'] + $otherNet, 4);
        return [
            'fifo' => [
                'opening' => $F['opening'], 'cost_in' => $F['cost_in'], 'hpp' => $F['hpp'], 'transfer' => $F['transfer'], 'adjustment' => $F['adjustment'], 'closing' => $F['closing'],
                'layers' => $F['layers'], 'layers_open' => $layersOpen, 'layer_value' => $F['layer_value'], 'skus_with_stock' => $withStock, 'variance' => round($F['variance'], 4),
                'closing_delta_pct' => $delta($F['closing'], $F['opening']), 'hpp_delta_pct' => $delta($F['hpp'], $pf['hpp']), 'layers_delta' => $F['layers'] - $layersOpen,
            ],
            'average' => [
                'opening' => $A['opening'], 'cost_in' => $A['cost_in'], 'usage' => $A['usage'], 'hpp' => $A['hpp'], 'transfer' => $A['transfer'], 'adjustment' => $A['adjustment'], 'other_net' => $otherNet, 'closing' => $A['closing'],
                'variance' => $A['variance'], 'unknown_items' => $foot['unknown_items'], 'identity' => $aClosingCheck,
                'closing_delta_pct' => $delta($A['closing'], $A['opening']), 'hpp_delta_pct' => $delta($A['hpp'], $pf['a_hpp']),
                'cost_in_delta_pct' => null, 'usage_delta_pct' => null,
            ],
            'items' => count($rows),
        ];
    }

    /** FIFO vs Average over the SAME set of items (those whose Average can be reconstructed). @return array<string,mixed> */
    private static function comparison(array $ctx): array
    {
        $rows = self::itemRows($ctx);
        $fc = $fh = $ac = $ah = 0.0;
        $used = 0;
        $excluded = 0;
        foreach ($rows as $r) {
            if (!$r['avg']['known']) {
                $excluded++;
                continue;
            }
            $used++;
            $fc += $r['fifo']['closing'];
            $fh += $r['fifo']['hpp'];
            $ac += $r['avg']['closing'];
            $ah += $r['avg']['hpp'];
        }
        return [
            'fifo' => ['hpp' => round($fh, 4), 'closing' => round($fc, 4)], 'average' => ['hpp' => round($ah, 4), 'closing' => round($ac, 4)],
            'diff' => ['hpp' => round($ah - $fh, 4), 'closing' => round($ac - $fc, 4)], 'items' => $used, 'excluded_items' => $excluded,
            'note' => 'Selisih ini adalah perbedaan metode penilaian, bukan kesalahan salah satunya. FIFO adalah metode operasional sistem; Average adalah pembanding analitis (read-only).',
        ];
    }

    /** @return list<string> */
    private static function notes(array $ctx): array
    {
        return [
            'Metode operasional sistem: FIFO',
            'FIFO = metode HPP operasional berdasarkan layer stok paling awal. Average = moving weighted average untuk analisis nilai persediaan (read-only; tidak mengubah posting / HPP tersimpan).',
            'Average dihitung per barang per gudang; "Semua Gudang" menjumlahkan hasil per gudang. Tidak pernah satu rata-rata untuk SKU yang berbeda.',
        ];
    }

    // ======================================================================
    // daily
    // ======================================================================

    /** @return list<array<string,mixed>> one row per calendar day of the period (both methods) */
    private static function dailyRows(array $ctx): array
    {
        $startTs = $ctx['start_ts'];
        $fopen = 0.0;
        $aopen = 0.0;
        $days = [];
        $d = $ctx['f']['start'];
        while ($d <= $ctx['f']['end']) {
            $days[$d] = ['date' => $d, 'f' => self::emptyBucket(), 'a' => self::emptyBucket(), 'a_hpp_events' => 0];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
        $known = fn (int $iid) => !isset($ctx['avg_unknown'][$iid]);
        $stateOpen = 0.0;   // Average value by the replay STATE (independent of the per-category sums)
        foreach ($ctx['events'] as $iid => $evs) {
            $k = $known($iid);
            foreach ($evs as $e) {
                if (!self::inScope($ctx, $e['wh'])) {
                    continue;
                }
                $stateDelta = $k ? ($e['av_post'] ?? 0.0) - ($e['av_pre'] ?? 0.0) : 0.0;
                $isOpen = $e['ts'] < $startTs || ($e['boundary'] && $e['ts'] === $startTs);
                if ($isOpen) {
                    $fopen += $e['val'];
                    $stateOpen += $stateDelta;
                    if ($k) {
                        $aopen += $e['aval'] ?? 0.0;
                    }
                    continue;
                }
                $day = substr($e['ts'], 0, 10);
                if (!isset($days[$day])) {
                    continue;
                }
                $days[$day]['f'][$e['cat']] += $e['val'];
                $days[$day]['state'] = ($days[$day]['state'] ?? 0.0) + $stateDelta;
                if ($k) {
                    $days[$day]['a'][$e['cat']] += $e['aval'] ?? 0.0;
                }
            }
        }
        // layers at each day end
        $instants = [];
        foreach ($days as $dd => $_) {
            $instants[] = date('Y-m-d 00:00:00', strtotime($dd . ' +1 day'));
        }
        $snaps = self::layerSnapshots($ctx, $instants);
        // single item: Average end-of-day cost
        $single = $ctx['f']['item_id'];
        $eod = [];
        if ($single !== null && isset($ctx['events'][$single]) && $known($single)) {
            $state = [];
            $byDay = [];
            foreach ($ctx['events'][$single] as $e) {
                if (!self::inScope($ctx, $e['wh'])) {
                    continue;
                }
                $state[$e['wh']] = [$e['aq_post'], $e['av_post'] ?? 0.0];
                $byDay[substr($e['ts'], 0, 10)] = $state;
            }
            $cur = [];
            foreach ($ctx['events'][$single] as $e) {
                if (self::inScope($ctx, $e['wh']) && $e['ts'] < $startTs) {
                    $cur[$e['wh']] = [$e['aq_post'], $e['av_post'] ?? 0.0];
                }
            }
            foreach ($days as $dd => $_) {
                if (isset($byDay[$dd])) {
                    $cur = $byDay[$dd];
                }
                $q = array_sum(array_column($cur, 0));
                $v = array_sum(array_column($cur, 1));
                $eod[$dd] = $q > self::EPS ? round($v / $q, 4) : null;
            }
        }
        $rows = [];
        $stateClose = $stateOpen;
        foreach ($days as $dd => $r) {
            $stateClose += $r['state'] ?? 0.0;
            $fcl = $fopen + $r['f']['in'] + $r['f']['out'] + $r['f']['trf'] + $r['f']['adj'];
            $acl = $aopen + $r['a']['in'] + $r['a']['out'] + $r['a']['trf'] + $r['a']['adj'];
            $lt = self::layerTotals($ctx, $snaps[date('Y-m-d 00:00:00', strtotime($dd . ' +1 day'))]['rem']);
            $rows[] = [
                'date' => $dd,
                'fifo' => ['opening' => round($fopen, 4), 'cost_in' => round($r['f']['in'], 4), 'hpp' => round(-$r['f']['out'], 4), 'transfer' => round($r['f']['trf'], 4), 'adjustment' => round($r['f']['adj'], 4),
                           'closing' => round($fcl, 4), 'layers' => $lt['count'], 'layer_value' => $lt['value'], 'variance' => round($fcl - $lt['value'], 4)],
                'avg' => ['opening' => round($aopen, 4), 'cost_in' => round($r['a']['in'], 4), 'hpp' => round(-$r['a']['out'], 4), 'transfer' => round($r['a']['trf'], 4), 'adjustment' => round($r['a']['adj'], 4),
                          'closing' => round($acl, 4), 'eod_cost' => $eod[$dd] ?? null, 'variance' => round($stateClose - $acl, 4)],
            ];
            $fopen = $fcl;
            $aopen = $acl;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function bucketRows(array $days, string $bucket): array
    {
        if ($bucket === 'day') {
            return $days;
        }
        $groups = [];
        foreach ($days as $r) {
            $t = strtotime($r['date']);
            $key = $bucket === 'week' ? date('Y-m-d', strtotime('monday this week', $t)) : date('Y-m', $t) . '-01';
            $groups[$key][] = $r;
        }
        $out = [];
        foreach ($groups as $key => $rs) {
            $first = $rs[0];
            $last = $rs[count($rs) - 1];
            $sum = static function (string $m, string $k) use ($rs): float {
                return round(array_sum(array_map(static fn ($r) => $r[$m][$k], $rs)), 4);
            };
            $out[] = [
                'date' => $key, 'to' => $last['date'], 'days' => count($rs),
                'fifo' => ['opening' => $first['fifo']['opening'], 'cost_in' => $sum('fifo', 'cost_in'), 'hpp' => $sum('fifo', 'hpp'), 'transfer' => $sum('fifo', 'transfer'), 'adjustment' => $sum('fifo', 'adjustment'),
                           'closing' => $last['fifo']['closing'], 'layers' => $last['fifo']['layers'], 'layer_value' => $last['fifo']['layer_value'], 'variance' => $last['fifo']['variance']],
                'avg' => ['opening' => $first['avg']['opening'], 'cost_in' => $sum('avg', 'cost_in'), 'hpp' => $sum('avg', 'hpp'), 'transfer' => $sum('avg', 'transfer'), 'adjustment' => $sum('avg', 'adjustment'),
                          'closing' => $last['avg']['closing'], 'eod_cost' => $last['avg']['eod_cost'], 'variance' => $last['avg']['variance']],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function dailyFooter(array $days): array
    {
        $f = ['cost_in' => 0.0, 'hpp' => 0.0, 'transfer' => 0.0, 'adjustment' => 0.0];
        $a = $f;
        foreach ($days as $r) {
            foreach (array_keys($f) as $k) {
                $f[$k] += $r['fifo'][$k];
                $a[$k] += $r['avg'][$k];
            }
        }
        $first = $days[0] ?? null;
        $last = $days[count($days) - 1] ?? null;
        return [
            'fifo' => array_map(static fn ($v) => round($v, 4), $f) + ['opening' => $first['fifo']['opening'] ?? 0.0, 'closing' => $last['fifo']['closing'] ?? 0.0],
            'avg' => array_map(static fn ($v) => round($v, 4), $a) + ['opening' => $first['avg']['opening'] ?? 0.0, 'closing' => $last['avg']['closing'] ?? 0.0],
        ];
    }

    // ======================================================================
    // item detail
    // ======================================================================

    /** @return array{opening:array<string,mixed>,fifo:list<array<string,mixed>>,average:list<array<string,mixed>>,compare:list<array<string,mixed>>} */
    private static function history(array $ctx, int $iid): array
    {
        $evs = $ctx['events'][$iid] ?? [];
        $startTs = $ctx['start_ts'];
        $oq = $ofv = $oav = 0.0;
        $lastWh = [];
        $fifo = [];
        $avg = [];
        $cmp = [];
        $multiWh = $ctx['f']['warehouse_id'] === null;
        foreach ($evs as $e) {
            if (!self::inScope($ctx, $e['wh'])) {
                continue;
            }
            $isOpen = $e['ts'] < $startTs || ($e['boundary'] && $e['ts'] === $startTs);
            if ($isOpen) {
                $oq += $e['qty'];
                $ofv += $e['val'];
                $oav += $e['aval'] ?? 0.0;
                continue;
            }
            $wh = $ctx['wh_names'][$e['wh']]['code'] ?? (string) $e['wh'];
            $label = (self::TYPE_LABELS[$e['type']] ?? $e['type']) . ($e['status'] === 'VOID' ? ' — VOID' : '');
            $note = trim(implode(' · ', array_filter([$e['notes'], $e['flag']])));
            $qin = $e['qty'] > 0 ? $e['qty'] : null;
            $qout = $e['qty'] < 0 ? -$e['qty'] : null;
            $layers = [];
            foreach ($ctx['alloc_by_line'][$e['line_id']] ?? [] as $a) {
                $L = $ctx['layers'][$a['batch_id']] ?? null;
                $layers[] = ['batch_id' => $a['batch_id'], 'received' => $L['rd'] ?? null, 'qty' => $a['qty'], 'cost' => $a['cost'], 'subtotal' => $a['subtotal'], 'restore' => $e['type'] === 'REVERSAL' && $e['qty'] > 0];
            }
            $common = ['date' => substr($e['ts'], 0, 10), 'timestamp' => $e['ts'], 'posted_at' => $e['created_at'], 'type' => $e['type'], 'type_label' => $label, 'status' => $e['status'], 'reference' => $e['ref'],
                       'warehouse' => $wh, 'by' => $e['by'], 'notes' => $note !== '' ? $note : null, 'tx_id' => $e['tx_id']];
            $fifo[] = $common + [
                'qty_in' => $qin, 'unit_cost_in' => $qin !== null && $qin > 0 ? round($e['val'] / $qin, 4) : null, 'qty_out' => $qout, 'layers' => $layers,
                'hpp' => $qout !== null ? round(abs($e['val']), 4) : null, 'value_in' => $qin !== null ? round($e['val'], 4) : null,
                'bal_qty' => $e['fq_post'], 'bal_value' => $e['fv_post'], 'cat' => $e['cat'],
            ];
            $known = !isset($ctx['avg_unknown'][$iid]);
            $av = $e['aval'];
            $avg[] = $common + [
                'qty_in' => $qin, 'qty_out' => $qout, 'unit_cost_in' => $qin !== null && $qin > 0 && $av !== null ? round($av / $qin, 4) : null, 'value_in' => $qin !== null && $av !== null ? round($av, 4) : null,
                'qty_before' => $e['aq_pre'], 'value_before' => $e['av_pre'] !== null ? round($e['av_pre'], 4) : null, 'avg_before' => $e['aavg_pre'] !== null ? round($e['aavg_pre'], 4) : null,
                'avg_after' => $e['aavg_post'] !== null ? round($e['aavg_post'], 4) : null, 'hpp' => $qout !== null && $av !== null ? round(abs($av), 4) : null,
                'bal_qty' => $e['aq_post'], 'bal_value' => $e['av_post'] !== null ? round($e['av_post'], 4) : null,
                'avg_changed' => $e['aavg_pre'] !== null && $e['aavg_post'] !== null && abs($e['aavg_pre'] - $e['aavg_post']) > 0.00005 || ($e['aavg_pre'] === null && $e['aavg_post'] !== null),
                'unknown' => $e['unk'], 'unknown_reason' => $e['unk_reason'], 'cat' => $e['cat'],
            ];
            if ($e['cat'] === 'out') {
                $cmp[] = ['date' => substr($e['ts'], 0, 10), 'timestamp' => $e['ts'], 'reference' => $e['ref'], 'warehouse' => $wh, 'qty' => $qout, 'hpp_fifo' => round(abs($e['val']), 4),
                          'hpp_avg' => $av !== null ? round(abs($av), 4) : null, 'diff' => $av !== null ? round(abs($av) - abs($e['val']), 4) : null, 'layers' => $layers];
            }
        }
        $opening = [
            'qty' => round($oq, 6), 'fifo_value' => round($ofv, 4), 'avg_value' => isset($ctx['avg_unknown'][$iid]) ? null : round($oav, 4),
            'avg_cost' => !isset($ctx['avg_unknown'][$iid]) && abs($oq) > self::EPS ? round($oav / $oq, 4) : null, 'fifo_unit_cost' => abs($oq) > self::EPS ? round($ofv / $oq, 4) : null,
        ];
        return ['opening' => $opening, 'fifo' => $fifo, 'average' => $avg, 'compare' => $cmp, 'multi_wh' => $multiWh];
    }

    /** @return array<string,mixed> FIFO layer lists of one item at the end of the period */
    private static function itemLayers(array $ctx, int $iid): array
    {
        $snap = self::layerSnapshots($ctx, [$ctx['end_next']])[$ctx['end_next']]['rem'];
        $active = [];
        $used = [];
        $consumedInPeriod = [];
        foreach ($ctx['allocs'] as $a) {
            if ($a['ts'] >= $ctx['start_ts']) {
                $consumedInPeriod[$a['batch_id']] = true;
            }
        }
        foreach ($ctx['layers'] as $L) {
            if ($L['item_id'] !== $iid || !self::inScope($ctx, $L['wh']) || $L['neg']) {
                continue;
            }
            $rem = $snap[$L['id']] ?? 0.0;
            $row = ['batch_id' => $L['id'], 'received' => $L['rd'], 'warehouse' => $ctx['wh_names'][$L['wh']]['code'] ?? (string) $L['wh'], 'orig_qty' => $L['orig'], 'cost' => $L['cost'],
                    'source' => $L['src_ref'], 'source_type' => $L['src_type']];
            if ($rem > self::EPS) {
                $row['remaining'] = round($rem, 6);
                $row['value'] = round($rem * $L['cost'], 4);
                $row['status'] = $rem + self::EPS < $L['orig'] ? 'Sebagian' : 'Tersisa';
                $active[] = $row;
            } elseif ($L['orig'] > 0 && isset($consumedInPeriod[$L['id']])) {
                $row['used'] = $L['orig'];
                $row['status'] = 'Habis';
                $used[] = $row;
            }
        }
        usort($active, static fn ($a, $b) => [$a['received'], $a['batch_id']] <=> [$b['received'], $b['batch_id']]);
        usort($used, static fn ($a, $b) => [$a['received'], $a['batch_id']] <=> [$b['received'], $b['batch_id']]);
        foreach ($active as $i => &$r) {
            $r['no'] = $i + 1;
        }
        unset($r);
        foreach ($used as $i => &$r) {
            $r['no'] = $i + 1;
        }
        unset($r);
        $deficit = [];
        foreach ($ctx['layers'] as $L) {
            if ($L['item_id'] === $iid && self::inScope($ctx, $L['wh']) && $L['neg']) {
                $deficit[] = ['batch_id' => $L['id'], 'received' => $L['rd'], 'qty' => $L['orig'], 'cost' => $L['cost']];
            }
        }
        return [
            'active' => $active, 'used' => $used, 'deficit_layers' => $deficit,
            'total_qty' => round(array_sum(array_column($active, 'remaining')), 6), 'total_value' => round(array_sum(array_column($active, 'value')), 4),
        ];
    }

    /** @return array<string,mixed>|null a REAL worked example from the selected item (no hard-coded numbers) */
    private static function averageFormula(array $hist, ?array $agg): ?array
    {
        if ($agg !== null && !$agg['avg']['known']) {
            return null;
        }
        $in = null;
        $out = null;
        foreach ($hist['average'] as $r) {
            if ($r['unknown']) {
                continue;
            }
            if ($r['qty_in'] !== null && $r['value_in'] !== null && $r['avg_after'] !== null && $r['type'] !== 'REVERSAL') {
                $in = $r;
            }
            if ($r['qty_out'] !== null && $r['hpp'] !== null && $r['avg_before'] !== null && $r['cat'] === 'out') {
                $out = $r;
            }
        }
        return [
            'inbound' => $in === null ? null : [
                'date' => $in['date'], 'reference' => $in['reference'], 'qty_before' => $in['qty_before'], 'value_before' => $in['value_before'], 'qty_in' => $in['qty_in'], 'value_in' => $in['value_in'],
                'qty_after' => round($in['qty_before'] + $in['qty_in'], 6), 'value_after' => round(($in['value_before'] ?? 0.0) + $in['value_in'], 4), 'avg_after' => $in['avg_after'],
            ],
            'outbound' => $out === null ? null : [
                'date' => $out['date'], 'reference' => $out['reference'], 'qty' => $out['qty_out'], 'avg' => $out['avg_before'], 'hpp' => $out['hpp'], 'qty_after' => $out['bal_qty'], 'value_after' => $out['bal_value'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function itemComparison(array $ctx, int $iid, ?array $agg, array $hist): array
    {
        if ($agg === null) {
            return ['available' => false];
        }
        $known = $agg['avg']['known'];
        return [
            'available' => true, 'known' => $known, 'reason' => $known ? null : $agg['avg']['reason'],
            'fifo' => ['hpp' => $agg['fifo']['hpp'], 'closing' => $agg['fifo']['closing']],
            'average' => $known ? ['hpp' => $agg['avg']['hpp'], 'closing' => $agg['avg']['closing']] : null,
            'diff' => $known ? ['hpp' => round($agg['avg']['hpp'] - $agg['fifo']['hpp'], 4), 'closing' => round($agg['avg']['closing'] - $agg['fifo']['closing'], 4)] : null,
            'qty_close' => $agg['qty_close'],
        ];
    }

    // ======================================================================
    // reconciliation
    // ======================================================================

    /** @return array{checks:list<array<string,mixed>>,ok:bool} */
    private static function reconcile(array $ctx): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, float $diff, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'difference' => round($diff, 4), 'detail' => $detail];
        };
        $tolV = 0.05;
        $tolQ = 0.0005;
        $rows = self::itemRows($ctx);
        $snap = self::layerSnapshots($ctx, [$ctx['end_next']])[$ctx['end_next']]['rem'];
        // 1. remaining FIFO qty == ledger quantity, per item+warehouse
        $worstQ = 0.0;
        $worstV = 0.0;
        $badQ = [];
        $badV = [];
        $perPair = [];
        foreach ($ctx['events'] as $iid => $evs) {
            foreach ($evs as $e) {
                if (self::inScope($ctx, $e['wh'])) {
                    $perPair["{$iid}:{$e['wh']}"] = [$iid, $e['wh'], $e['fq_post'], $e['fv_post']];
                }
            }
        }
        $layerQ = [];
        $layerV = [];
        foreach ($ctx['layers'] as $L) {
            if (!self::inScope($ctx, $L['wh'])) {
                continue;
            }
            $r = $snap[$L['id']] ?? 0.0;
            $k = "{$L['item_id']}:{$L['wh']}";
            $layerQ[$k] = ($layerQ[$k] ?? 0.0) + $r;
            $layerV[$k] = ($layerV[$k] ?? 0.0) + $r * $L['cost'];
            if (!isset($perPair[$k])) {
                $perPair[$k] = [$L['item_id'], $L['wh'], 0.0, 0.0];
            }
        }
        foreach ($perPair as $k => [$iid, $wh, $lq, $lv]) {
            $dq = ($layerQ[$k] ?? 0.0) - $lq;
            $dv = ($layerV[$k] ?? 0.0) - $lv;
            if (abs($dq) > $tolQ) {
                $badQ[] = ($ctx['items'][$iid]['sku'] ?? $iid) . '@' . ($ctx['wh_names'][$wh]['code'] ?? $wh) . ' ' . self::fmtQty($dq);
            }
            if (abs($dv) > $tolV) {
                $badV[] = ($ctx['items'][$iid]['sku'] ?? $iid) . '@' . ($ctx['wh_names'][$wh]['code'] ?? $wh) . ' ' . round($dv, 2);
            }
            $worstQ = max($worstQ, abs($dq));
            $worstV = max($worstV, abs($dv));
        }
        $add('FIFO: qty layer tersisa = qty ledger persediaan (per barang/gudang)', $badQ === [], $worstQ, $badQ === [] ? count($perPair) . ' pasangan barang/gudang cocok' : 'selisih: ' . implode(', ', array_slice($badQ, 0, 8)));
        $add('FIFO: nilai layer tersisa = nilai stok akhir FIFO (ledger)', $badV === [], $worstV, $badV === [] ? 'cocok (toleransi pembulatan Rp 0,05 per barang/gudang)' : 'selisih: ' . implode(', ', array_slice($badV, 0, 8)));
        // 2. at "now": layer remaining == inventory_batches.qty_base
        if ($ctx['f']['end'] >= date('Y-m-d')) {
            $bad = [];
            $worst = 0.0;
            foreach ($ctx['layers'] as $L) {
                if (!self::inScope($ctx, $L['wh']) || $L['neg']) {
                    continue;
                }
                $d = ($snap[$L['id']] ?? 0.0) - $L['cur'];
                $worst = max($worst, abs($d));
                if (abs($d) > $tolQ) {
                    $bad[] = '#' . $L['id'] . ' ' . self::fmtQty($d);
                }
            }
            $add('FIFO: layer yang dibangun ulang = inventory_batches.qty_base saat ini', $bad === [], $worst, $bad === [] ? 'semua layer cocok' : 'selisih: ' . implode(', ', array_slice($bad, 0, 8)));
        }
        // 3. allocation qty == OUT qty, allocation cost == OUT HPP (OUT lines in the period)
        $bq = [];
        $bc = [];
        $wq = 0.0;
        $wc = 0.0;
        $lines = 0;
        foreach ($ctx['events'] as $iid => $evs) {
            foreach ($evs as $e) {
                if ($e['type'] !== 'OUT' || $e['status'] !== 'POSTED' || !self::inScope($ctx, $e['wh']) || $e['ts'] < $ctx['start_ts']) {
                    continue;
                }
                $lines++;
                $sq = array_sum(array_column($ctx['alloc_by_line'][$e['line_id']] ?? [], 'qty'));
                $sc = array_sum(array_column($ctx['alloc_by_line'][$e['line_id']] ?? [], 'subtotal'));
                $dq = $sq - abs($e['qty']);
                $dc = $sc - abs($e['val']);
                $wq = max($wq, abs($dq));
                $wc = max($wc, abs($dc));
                if (abs($dq) > $tolQ) {
                    $bq[] = ($e['ref'] ?? '#' . $e['line_id']) . ' ' . self::fmtQty($dq);
                }
                if (abs($dc) > $tolV) {
                    $bc[] = ($e['ref'] ?? '#' . $e['line_id']) . ' ' . round($dc, 2);
                }
            }
        }
        $add('FIFO: qty alokasi layer = qty OUT (per baris OUT pada periode)', $bq === [], $wq, $bq === [] ? "{$lines} baris OUT cocok" : implode(', ', array_slice($bq, 0, 8)));
        $add('FIFO: biaya alokasi layer = HPP OUT (per baris OUT pada periode)', $bc === [], $wc, $bc === [] ? "{$lines} baris OUT cocok" : implode(', ', array_slice($bc, 0, 8)));
        // 4. Average: opening + in + out ± trf ± adj = closing (state) and closing = qty x moving average, per item
        $agg = self::aggregate($ctx, $ctx['start_ts'], $ctx['end_next']);
        $bi = [];
        $worstI = 0.0;
        $known = 0;
        foreach ($agg as $iid => $a) {
            if (!$a['known']) {
                continue;
            }
            $known++;
            $d = $a['state_av'] - $a['a']['close'];
            $worstI = max($worstI, abs($d));
            if (abs($d) > $tolV) {
                $bi[] = ($ctx['items'][$iid]['sku'] ?? $iid) . ' ' . round($d, 2);
            }
        }
        $add('Average: nilai awal + masuk − HPP keluar ± transfer ± adjustment = nilai akhir (per barang)', $bi === [], $worstI, $bi === [] ? "{$known} barang cocok" : 'selisih: ' . implode(', ', array_slice($bi, 0, 8)));
        $bm = [];
        $worstM = 0.0;
        foreach ($ctx['events'] as $iid => $evs) {
            if (isset($ctx['avg_unknown'][$iid])) {
                continue;
            }
            $last = [];
            foreach ($evs as $e) {
                if (self::inScope($ctx, $e['wh'])) {
                    $last[$e['wh']] = $e;
                }
            }
            foreach ($last as $e) {
                if ($e['aavg_post'] === null) {
                    continue;
                }
                $d = $e['aq_post'] * $e['aavg_post'] - ($e['av_post'] ?? 0.0);
                $worstM = max($worstM, abs($d));
                if (abs($d) > $tolV) {
                    $bm[] = ($ctx['items'][$iid]['sku'] ?? $iid) . ' ' . round($d, 2);
                }
            }
        }
        $add('Average: nilai akhir ≈ qty akhir × average cost akhir', $bm === [], $worstM, $bm === [] ? 'cocok dalam pembulatan' : implode(', ', array_slice($bm, 0, 8)));
        // 5. internal transfers net to zero company-wide (Average) when the whole company is in scope
        if ($ctx['f']['warehouse_id'] === null) {
            $net = 0.0;
            $netF = 0.0;
            foreach ($agg as $iid => $a) {
                if ($a['known']) {
                    $net += $a['a']['trf'];
                }
                $netF += $a['f']['trf'];
            }
            $add('Transfer internal: nilai bersih seluruh gudang = 0 (FIFO)', abs($netF) <= $tolV * 20, $netF, 'transfer FIFO bersih Rp ' . round($netF, 2) . ' (selisih ≠ 0 berarti ada transfer dalam perjalanan / belum diterima)');
            $add('Transfer internal: nilai bersih seluruh gudang = 0 (Average)', abs($net) <= $tolV * 20, $net, 'transfer Average bersih Rp ' . round($net, 2));
        }
        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return ['checks' => $checks, 'ok' => $ok, 'unreconstructable_items' => count($ctx['unreconstructable'])];
    }

    // ======================================================================
    // export helpers
    // ======================================================================

    private static function cellNum(mixed $v): int|float|string
    {
        return $v === null ? '—' : (is_int($v) || is_float($v) ? $v : (string) $v);
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function itemSheet(array $rows, string $m): array
    {
        $san = static fn (string $s): string => ExcelWriterService::sanitizeCellText($s);
        if ($m === 'fifo') {
            $h = ['SKU', 'Barang', 'Kategori', 'Satuan', 'Qty Awal', 'Qty Masuk', 'Qty Keluar', 'Qty Akhir', 'Nilai Awal FIFO', 'Inventory Cost Masuk', 'HPP Keluar FIFO', 'Transfer Bersih', 'Adjustment & Koreksi', 'Nilai Akhir FIFO', 'Unit Cost Akhir', 'Layer Aktif', 'Nilai Layer', 'Variance'];
            $out = [];
            foreach ($rows as $r) {
                $f = $r['fifo'];
                $out[] = [$r['sku'], $san($r['name']), $san($r['category']), $r['unit'], $r['qty_open'], $r['qty_in'], $r['qty_out'], $r['qty_close'], $f['opening'], $f['cost_in'], $f['hpp'], $f['transfer'], $f['adjustment'], $f['closing'], $f['unit_cost'] ?? '—', $f['layers'], $f['layer_value'], $f['variance']];
            }
            $t = self::itemFooter($rows)['f'];
            $out[] = ['TOTAL', '', '', '', '', '', '', '', $t['opening'], $t['cost_in'], $t['hpp'], $t['transfer'], $t['adjustment'], $t['closing'], '', $t['layers'], $t['layer_value'], $t['variance']];
            return ['headers' => $h, 'rows' => $out];
        }
        $h = ['SKU', 'Barang', 'Kategori', 'Satuan', 'Qty Awal', 'Qty Masuk', 'Qty Keluar', 'Qty Akhir', 'Nilai Awal Average', 'Pembelian / Cost In', 'Pemakaian / Barang Keluar', 'HPP Average', 'Transfer Bersih', 'Adjustment & Koreksi', 'Nilai Akhir Average', 'Average Cost Akhir', 'Variance'];
        $out = [];
        foreach ($rows as $r) {
            $a = $r['avg'];
            if (!$a['known']) {
                $out[] = [$r['sku'], $san($r['name']), $san($r['category']), $r['unit'], $r['qty_open'], $r['qty_in'], $r['qty_out'], $r['qty_close'], 'Average tidak dapat direkonstruksi', '—', '—', '—', '—', '—', '—', '—', ExcelWriterService::sanitizeCellText((string) $a['reason'])];
                continue;
            }
            $out[] = [$r['sku'], $san($r['name']), $san($r['category']), $r['unit'], $r['qty_open'], $r['qty_in'], $r['qty_out'], $r['qty_close'], $a['opening'], $a['cost_in'], $a['usage'], $a['hpp'], $a['transfer'], $a['adjustment'], $a['closing'], $a['unit_cost'] ?? '—', $a['variance']];
        }
        $t = self::itemFooter($rows)['a'];
        $out[] = ['TOTAL (barang yang dapat direkonstruksi)', '', '', '', '', '', '', '', $t['opening'], $t['cost_in'], $t['usage'], $t['hpp'], $t['transfer'], $t['adjustment'], $t['closing'], '', $t['variance']];
        return ['headers' => $h, 'rows' => $out];
    }

    /** Every ledger event of the scope in the period, one row each. @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function historySheet(array $ctx, string $m): array
    {
        $rows = [];
        foreach ($ctx['events'] as $iid => $evs) {
            $hist = self::history($ctx, $iid);
            $it = $ctx['items'][$iid];
            if ($m === 'fifo') {
                foreach ($hist['fifo'] as $r) {
                    $layers = implode(' + ', array_map(static fn ($l) => self::fmtQty($l['qty']) . ' @ ' . self::fmtQty($l['cost']), $r['layers']));
                    $rows[] = [$r['timestamp'], $it['sku'], ExcelWriterService::sanitizeCellText($it['name']), $r['warehouse'], $r['type_label'], $r['reference'] ?? '—', $r['qty_in'] ?? '', $r['unit_cost_in'] ?? '', $r['qty_out'] ?? '',
                               $layers !== '' ? $layers : '—', $r['hpp'] ?? '', $r['bal_qty'], $r['bal_value'], $r['by'] ?? '—', ExcelWriterService::sanitizeCellText((string) ($r['notes'] ?? ''))];
                }
            } else {
                foreach ($hist['average'] as $r) {
                    $rows[] = [$r['timestamp'], $it['sku'], ExcelWriterService::sanitizeCellText($it['name']), $r['warehouse'], $r['type_label'], $r['reference'] ?? '—', $r['qty_in'] ?? '', $r['qty_out'] ?? '', $r['unit_cost_in'] ?? '', $r['value_in'] ?? '',
                               $r['qty_before'], $r['value_before'] ?? 'Average tidak dapat direkonstruksi', $r['avg_before'] ?? '—', $r['avg_after'] ?? '—', $r['hpp'] ?? '', $r['bal_qty'], $r['bal_value'] ?? '—', $r['by'] ?? '—',
                               ExcelWriterService::sanitizeCellText((string) ($r['notes'] ?? ($r['unknown_reason'] ?? '')))];
                }
            }
        }
        usort($rows, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $headers = $m === 'fifo'
            ? ['Timestamp', 'SKU', 'Barang', 'Gudang', 'Jenis Transaksi', 'Referensi', 'Qty Masuk', 'Unit Cost FIFO Masuk', 'Qty Keluar', 'Layer Terpakai', 'HPP Keluar FIFO', 'Saldo Qty', 'Saldo Nilai FIFO', 'Petugas', 'Keterangan']
            : ['Timestamp', 'SKU', 'Barang', 'Gudang', 'Jenis Transaksi', 'Referensi', 'Qty Masuk', 'Qty Keluar', 'Unit Cost Masuk', 'Nilai Masuk', 'Saldo Qty Sebelum', 'Saldo Nilai Sebelum', 'Average Cost Sebelum', 'Average Cost Sesudah', 'HPP Keluar Average', 'Saldo Qty Akhir', 'Saldo Nilai Akhir', 'Petugas', 'Keterangan'];
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function layerSheet(array $ctx, bool $active): array
    {
        $snap = self::layerSnapshots($ctx, [$ctx['end_next']])[$ctx['end_next']]['rem'];
        $rows = [];
        if ($active) {
            foreach ($ctx['layers'] as $L) {
                $r = $snap[$L['id']] ?? 0.0;
                if (!self::inScope($ctx, $L['wh']) || $L['neg'] || $r <= self::EPS) {
                    continue;
                }
                $it = $ctx['items'][$L['item_id']] ?? ['sku' => (string) $L['item_id'], 'name' => ''];
                $rows[] = [$it['sku'], ExcelWriterService::sanitizeCellText($it['name']), $ctx['wh_names'][$L['wh']]['code'] ?? $L['wh'], $L['id'], substr($L['rd'], 0, 10), $L['src_ref'] ?? '—', $L['orig'], round($r, 6), $L['cost'], round($r * $L['cost'], 4), $r + self::EPS < $L['orig'] ? 'Sebagian' : 'Tersisa'];
            }
            return ['headers' => ['SKU', 'Barang', 'Gudang', 'Layer', 'Tgl Masuk', 'Sumber', 'Qty Awal', 'Qty Sisa', 'Harga Beli', 'Nilai Sisa', 'Status'], 'rows' => $rows];
        }
        foreach ($ctx['allocs'] as $a) {
            $L = $ctx['layers'][$a['batch_id']] ?? null;
            if ($L === null || !self::inScope($ctx, $L['wh']) || $a['ts'] < $ctx['start_ts']) {
                continue;
            }
            $it = $ctx['items'][$L['item_id']] ?? ['sku' => (string) $L['item_id'], 'name' => ''];
            $rows[] = [$it['sku'], ExcelWriterService::sanitizeCellText($it['name']), $ctx['wh_names'][$L['wh']]['code'] ?? $L['wh'], $a['ts'], $a['type'] . ($a['status'] === 'VOID' ? ' (VOID)' : ''), $L['id'], substr($L['rd'], 0, 10), $a['qty'], $a['cost'], $a['subtotal']];
        }
        return ['headers' => ['SKU', 'Barang', 'Gudang', 'Waktu Pemakaian', 'Jenis Transaksi', 'Layer', 'Tgl Masuk Layer', 'Qty Terpakai', 'Harga Layer', 'HPP (Rp)'], 'rows' => $rows];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function daySheet(array $ctx, string $m): array
    {
        $rows = [];
        foreach (self::dailyRows($ctx) as $r) {
            if ($m === 'fifo') {
                $a = $r['fifo'];
                $rows[] = [$r['date'], $a['opening'], $a['cost_in'], $a['hpp'], $a['transfer'], $a['adjustment'], $a['closing'], $a['layers'], $a['layer_value'], $a['variance']];
                continue;
            }
            $a = $r['avg'];
            $rows[] = [$r['date'], $a['opening'], $a['cost_in'], $a['hpp'], $a['transfer'], $a['adjustment'], $a['closing'], $a['eod_cost'] ?? '—'];
        }
        if ($m === 'fifo') {
            return ['headers' => ['Tanggal', 'Nilai Stok Awal FIFO', 'Nilai Masuk', 'HPP Keluar FIFO', 'Transfer Bersih', 'Adjustment & Koreksi', 'Nilai Stok Akhir FIFO', 'Jumlah Layer Aktif', 'Nilai Layer Aktif', 'Variance (ledger − layer)'], 'rows' => $rows];
        }
        return ['headers' => ['Tanggal', 'Nilai Stok Awal Average', 'Nilai Masuk', 'HPP Keluar Average', 'Transfer Bersih', 'Adjustment & Koreksi', 'Nilai Stok Akhir Average', 'Average Cost End-of-Day'], 'rows' => $rows];
    }
}
