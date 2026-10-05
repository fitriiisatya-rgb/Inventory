<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Laporan IN / OUT / Transfer" — READ-ONLY report over three REAL flows, one tab each. Never writes; every figure is a stored column of the posting that
 * created it, or a value derived from stored columns by a rule stated here. Nothing is read from a master price. Quantities are never added across units.
 *
 *  IN  (Barang Masuk)   = external purchases, inventory_transactions.transaction_type = 'IN'. Invoice = the set of transactions one Stock IN V2 entry posted
 *      (uuid "<uuid>:IN:<n>"). Arithmetic is the Stock IN V2 one (purchase_invoice_headers / purchase_line_costs): Grand Total = Subtotal Barang + PPN − Diskon
 *      Invoice + Ongkos Kirim. Legacy / historical rows have only the recorded value; unknown components stay NULL ("—"), never a fabricated 0.
 *  OUT (Barang Keluar)  = goods issued to a bakery, transaction_type = 'OUT'. A Stock OUT V2 document = distribution_orders (DO) + distribution_invoices (one per DO)
 *      linked line by line through distribution_order_lines.out_transaction_line_id. HPP = the line's stored FIFO cost (inventory_transaction_lines.subtotal, the sum of
 *      its fifo_allocations). Nilai Jual = distribution_invoice_lines.subtotal (selling price stored AT the posting, never today's master price). Margin = Nilai Jual − HPP
 *      (goods only; shipping is a pass-through shown separately). Only the invoice HEADER stores shipping, so the per-line "Alokasi Ongkir" is DERIVED proportionally to
 *      the line's selling subtotal (last line takes the rounding remainder, so allocations add up to the header exactly) — and labelled as such.
 *  TRANSFER             = warehouse_transfers / warehouse_transfer_lines (one row per FIFO layer consumed). The cost of a layer is preserved end to end: TRANSFER_OUT value =
 *      TRANSFER_IN value for a received transfer, and in-transit value = value of PENDING transfers. Receive is full-only (no partial), so Qty Diterima = Qty Transfer
 *      for RECEIVED and unknown (NULL) while PENDING.
 *
 * INCLUSION: POSTED rows are counted. VOID / CANCELLED / REVERSED rows are LISTED (struck through) and disclosed, never counted. Historical imports are excluded unless
 * asked for. The period is the business date of the document (transaction_date; transfers: ship_date).
 */
final class InOutReportService
{
    public const MAX_DAYS = 800;
    private const EPS = 0.0001;

    public const IN_COLUMNS = [
        ['date', 'Tanggal', 'date', true], ['time', 'Waktu Input', 'time', true], ['reference', 'No. Invoice / Referensi', 'text', true], ['supplier', 'Supplier', 'text', true],
        ['warehouse', 'Gudang', 'text', true], ['items', 'Jml Item', 'int', true], ['skus', 'Jml SKU', 'int', false], ['qty_text', 'Qty (per satuan dasar)', 'text', true],
        ['subtotal', 'Subtotal Barang', 'money', true], ['item_discount', 'Diskon Barang', 'money', true], ['invoice_discount', 'Diskon Invoice', 'money', true],
        ['ppn', 'PPN', 'money', true], ['freight', 'Ongkos Kirim', 'money', true], ['total', 'Grand Total', 'money', true], ['created_by', 'Dibuat Oleh', 'text', true],
        ['posted_at', 'Diposting Pada', 'ts', false], ['status', 'Status', 'status', true], ['source', 'Jenis IN', 'text', false], ['gross', 'Gross Barang', 'money', false],
        ['ppn_rate', 'Tarif PPN', 'text', false], ['freight_treatment', 'Perlakuan Ongkir', 'text', false], ['inventory_cost', 'Nilai Persediaan (FIFO)', 'money', false],
    ];
    public const IN_LINE_COLUMNS = [
        ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan', 'text', true], ['qty', 'Qty', 'qty', true],
        ['price', 'Harga Beli', 'money', true], ['item_discount', 'Diskon Item', 'money', true], ['dpp', 'DPP / Net Barang', 'money', true], ['ppn', 'PPN', 'money', true],
        ['invoice_discount', 'Alokasi Diskon Invoice', 'money', true], ['freight', 'Alokasi Ongkos Kirim', 'money', true], ['total', 'Total Nilai', 'money', true],
        ['inventory_cost', 'Inventory Cost (FIFO)', 'money', true], ['created_by', 'Petugas', 'text', true], ['created_at', 'Timestamp', 'ts', true], ['notes', 'Catatan', 'text', true],
        ['gross', 'Gross', 'money', false], ['ppn_rate', 'PPN %', 'rate', false], ['status', 'Status', 'status', false], ['source', 'Jenis IN', 'text', false],
    ];
    public const OUT_COLUMNS = [
        ['date', 'Tanggal', 'date', true], ['time', 'Waktu Input', 'time', true], ['do_number', 'No. DO', 'text', true], ['invoice_number', 'No. Invoice', 'text', true],
        ['warehouse', 'Gudang Asal', 'text', true], ['bakery', 'Bakery Tujuan', 'text', true], ['division', 'Divisi', 'text', false], ['items', 'Jml Item', 'int', true],
        ['skus', 'Jml SKU', 'int', false], ['qty_text', 'Qty (per satuan dasar)', 'text', true], ['hpp', 'HPP Total', 'money', true], ['sell', 'Nilai Jual', 'money', true],
        ['shipping', 'Ongkir', 'money', true], ['grand_total', 'Grand Total Invoice', 'money', true], ['margin', 'Margin', 'money', true], ['margin_pct', 'Margin %', 'pct', false],
        ['created_by', 'Dibuat Oleh', 'text', true], ['dispatched_by', 'Dispatched Oleh', 'text', true], ['dispatched_at', 'Dispatched Pada', 'ts', false], ['status', 'Status', 'status', true],
        ['source', 'Jenis OUT', 'text', false], ['reference_no', 'Referensi', 'text', false],
    ];
    public const OUT_LINE_COLUMNS = [
        ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan', 'text', true], ['qty', 'Qty', 'qty', true],
        ['reference_price', 'Harga Modal Referensi', 'money', true], ['hpp_unit', 'HPP FIFO Aktual / satuan', 'money', true], ['markup_type', 'Markup Type', 'text', true],
        ['markup_value', 'Markup Value', 'num', true], ['sell_price', 'Harga Jual', 'money', true], ['sell', 'Subtotal Jual', 'money', true], ['shipping', 'Alokasi Ongkir (turunan)', 'money', true],
        ['line_total', 'Total (Jual + Ongkir)', 'money', true], ['hpp', 'HPP Total', 'money', true], ['margin', 'Margin', 'money', true], ['layers', 'Layer FIFO', 'int', true],
        ['created_by', 'Petugas', 'text', false], ['created_at', 'Timestamp', 'ts', true], ['notes', 'Catatan', 'text', true], ['status', 'Status', 'status', false],
    ];
    public const FIFO_COLUMNS = [
        ['do_number', 'No. DO', 'text', true], ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['batch_id', 'Batch / Layer', 'int', true],
        ['batch_date', 'Tanggal Layer', 'date', true], ['batch_source', 'Sumber Layer', 'text', true], ['qty', 'Qty FIFO (satuan dasar)', 'qty', true], ['base_unit', 'Satuan Dasar', 'text', true],
        ['unit_cost', 'Cost / satuan dasar', 'money', true], ['value', 'Nilai FIFO', 'money', true],
    ];
    public const TRF_COLUMNS = [
        ['created_date', 'Tanggal Dibuat', 'date', true], ['created_time', 'Waktu Dibuat', 'time', true], ['number', 'No. Transfer', 'text', true], ['from', 'Gudang Asal', 'text', true],
        ['to', 'Gudang Tujuan', 'text', true], ['ship_date', 'Tanggal Kirim', 'date', false], ['items', 'Jml Item', 'int', true], ['skus', 'Jml SKU', 'int', true],
        ['qty_text', 'Qty (per satuan dasar)', 'text', true], ['value', 'Nilai Cost', 'money', true], ['status', 'Status', 'status', true], ['created_by', 'Dibuat Oleh', 'text', true],
        ['dispatched_by', 'Dispatched Oleh', 'text', false], ['dispatched_at', 'Dispatched Pada', 'ts', false], ['received_by', 'Diterima Oleh', 'text', true],
        ['received_at', 'Diterima Pada', 'ts', true], ['lead_time', 'Lead Time', 'text', true], ['notes', 'Catatan', 'text', false],
    ];
    public const TRF_LINE_COLUMNS = [
        ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan Dasar', 'text', true],
        ['qty', 'Qty Transfer', 'qty', true], ['qty_received', 'Qty Diterima', 'qty', true], ['diff', 'Selisih', 'qty', true], ['unit_cost', 'Unit Cost (rata-rata tertimbang)', 'money', true],
        ['value', 'Nilai', 'money', true], ['layers', 'Layer Sumber', 'int', true], ['status', 'Status', 'status', true],
    ];
    public const TRF_LAYER_COLUMNS = [
        ['number', 'No. Transfer', 'text', true], ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['batch_id', 'Batch / Layer', 'int', true],
        ['batch_date', 'Tanggal Layer', 'date', true], ['qty', 'Qty (satuan dasar)', 'qty', true], ['unit_cost', 'Unit Cost Layer', 'money', true], ['value', 'Nilai', 'money', true],
        ['received', 'Diterima', 'text', true],
    ];

    // ======================================================================
    // options
    // ======================================================================

    /** Filter options: only values that exist in the master data (warehouse-limited users get only their own warehouse). */
    public static function options(PDO $pdo, ?int $scopeWarehouseId): array
    {
        $wh = $pdo->query('SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();
        if ($scopeWarehouseId !== null) {
            $wh = array_values(array_filter($wh, static fn ($w) => (int) $w['id'] === $scopeWarehouseId));
        }
        return [
            'warehouses' => array_map(static fn ($w) => ['id' => (int) $w['id'], 'code' => $w['code'], 'name' => $w['name']], $wh),
            'all_warehouses' => array_map(static fn ($w) => ['id' => (int) $w['id'], 'code' => $w['code'], 'name' => $w['name']], $pdo->query('SELECT id, code, name FROM warehouses ORDER BY name')->fetchAll()),
            'categories' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll()),
            'suppliers' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll()),
            'bakeries' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $pdo->query('SELECT id, name FROM bakery_destinations ORDER BY name')->fetchAll()),
            'divisions' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $pdo->query('SELECT id, name FROM divisions ORDER BY name')->fetchAll()),
            'in_sources' => [['value' => 'V2', 'label' => 'Stock IN V2 (rincian lengkap)'], ['value' => 'Legacy', 'label' => 'Legacy (nilai tercatat)'], ['value' => 'Historis', 'label' => 'Historis (hanya laporan)']],
            'in_status' => [['value' => 'POSTED', 'label' => 'Posted'], ['value' => 'VOID', 'label' => 'Void']],
            'out_sources' => [['value' => 'V2', 'label' => 'Stock OUT V2 (DO + Invoice)'], ['value' => 'Legacy', 'label' => 'Legacy (tanpa DO)'], ['value' => 'Historis', 'label' => 'Historis (hanya laporan)']],
            'out_status' => array_map(static fn ($s) => ['value' => $s, 'label' => $s], ['DISPATCHED', 'RECEIVED', 'RECEIVED_WITH_DISCREPANCY', 'COMPLETED', 'CANCELLED', 'POSTED', 'VOID']),
            'trf_status' => array_map(static fn ($s) => ['value' => $s, 'label' => $s], ['PENDING', 'RECEIVED', 'CANCELLED', 'REVERSED']),
        ];
    }

    // ======================================================================
    // IN
    // ======================================================================

    public static function inOverview(PDO $pdo, array $f): array
    {
        $d = self::inDataset($pdo, $f);
        $bucket = self::bucket($f);
        $prev = self::previousPeriod($f);
        $pk = $prev !== null ? self::inKpi(self::inDataset($pdo, $prev)) : null;
        $k = self::inKpi($d);
        return [
            'tab' => 'in', 'period' => ['start' => $f['start_date'], 'end' => $f['end_date'], 'bucket' => $bucket, 'previous' => $prev !== null ? ['start' => $prev['start_date'], 'end' => $prev['end_date']] : null],
            'kpi' => $k, 'previous_kpi' => $pk, 'delta' => $pk !== null ? self::deltas($k['nominal'], $pk['nominal'], ['total', 'invoices', 'skus', 'suppliers', 'ppn', 'freight', 'discount', 'avg_invoice']) : null,
            'trend' => self::inTrend($d, (string) $f['start_date'], (string) $f['end_date'], $bucket), 'disclosures' => $d['disclosures'], 'filters' => self::filterEcho($f),
        ];
    }

    public static function inList(PDO $pdo, array $f): array
    {
        $d = self::inDataset($pdo, $f);
        $rows = self::sortRows(self::inSearch($d['invoices'], (string) ($f['gq'] ?? '')), $f, ['total' => 'total', 'supplier' => 'supplier', 'reference' => 'reference', 'date' => 'transaction_at']);
        return self::page($rows, $f) + ['columns' => self::columns(self::IN_COLUMNS), 'footer' => self::inFooter($rows)];
    }

    public static function inDetail(PDO $pdo, array $txIds, ?int $scopeWarehouseId): array
    {
        $lines = self::inEnrich(self::inFetch($pdo, ['tx_ids' => $txIds, 'warehouse_id' => $scopeWarehouseId, 'historical' => 'all'], false));
        if (!$lines) {
            throw new NotFoundException('invoice IN not found');
        }
        $inv = array_values(self::inGroup($lines)['invoices'])[0];
        $posted = array_values(array_filter($inv['lines'], static fn ($l) => $l['counted']));
        $v2 = array_values(array_filter($posted, static fn ($l) => $l['source'] === 'V2'));
        $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$k] ?? 0), $rows)), 4);
        $arith = null;
        if ($v2) {
            $arith = ['gross' => $sum($v2, 'gross'), 'item_discount' => $sum($v2, 'item_discount'), 'subtotal' => $sum($v2, 'dpp'), 'ppn' => $sum($v2, 'ppn'), 'invoice_discount' => $sum($v2, 'invoice_discount'),
                'freight' => $sum($v2, 'freight'), 'inventory_cost' => $sum($v2, 'inventory_cost'), 'stored_grand_total' => $sum($v2, 'total')];
            $arith['grand_total'] = round($arith['subtotal'] + $arith['ppn'] - $arith['invoice_discount'] + $arith['freight'], 4);
        }
        return ['invoice' => $inv, 'lines' => $inv['lines'], 'columns' => self::columns(self::IN_LINE_COLUMNS), 'arithmetic' => $arith,
            'notes' => ['Angka adalah nilai invoice pembelian; Inventory Cost adalah nilai persediaan FIFO hasil costing Stock IN V2 (PPN kredit tidak masuk HPP).']];
    }

    public static function inExport(PDO $pdo, array $f, array $meta): array
    {
        $d = self::inDataset($pdo, $f);
        $k = self::inKpi($d);
        $n = $k['nominal'];
        $rows = self::inSearch($d['invoices'], (string) ($f['gq'] ?? ''));
        $inv = self::table(self::IN_COLUMNS, $rows);
        $inv['rows'][] = self::totalRow(self::IN_COLUMNS, self::inFooter($rows)['totals'], 'TOTAL');
        $lines = [];
        foreach ($rows as $i) {
            foreach ($i['lines'] as $l) {
                if ($l['matched']) {
                    $lines[] = $l + ['reference' => $i['reference'], 'supplier' => $i['supplier'], 'warehouse' => $i['warehouse']];
                }
            }
        }
        $cols = array_merge([['date', 'Tanggal', 'date', true], ['reference', 'No. Invoice / Referensi', 'text', true], ['supplier', 'Supplier', 'text', true], ['warehouse', 'Gudang', 'text', true]], self::IN_LINE_COLUMNS);
        $lt = self::table($cols, $lines);
        $tot = [];
        foreach (['dpp', 'item_discount', 'ppn', 'invoice_discount', 'freight', 'total', 'inventory_cost', 'gross'] as $c) {
            $tot[$c] = round(array_sum(array_map(static fn ($r) => $r['counted'] ? (float) ($r[$c] ?? 0) : 0.0, $lines)), 4);
        }
        $lt['rows'][] = self::totalRow($cols, $tot, 'TOTAL (POSTED)');
        $sum = [];
        foreach ($meta as $key => $val) {
            $sum[] = [$key, $val];
        }
        $sum[] = ['', ''];
        foreach ([['Total Nilai Masuk', $n['total']], ['Subtotal Barang', $n['subtotal']], ['Diskon Barang', $n['item_discount']], ['Diskon Invoice', $n['invoice_discount']], ['PPN', $n['ppn']],
            ['Ongkos Kirim', $n['freight']], ['Jumlah Transaksi', $n['invoices']], ['Jumlah SKU', $n['skus']], ['Jumlah Supplier', $n['suppliers']],
            ['Invoice VOID (tidak dihitung) — jumlah', $d['disclosures']['void']['invoices']], ['Invoice VOID (tidak dihitung) — nilai', $d['disclosures']['void']['amount']]] as $r) {
            $sum[] = [$r[0], self::cell('money', $r[1])];
        }
        foreach ($k['qty_by_unit'] as $u) {
            $sum[] = ["Qty masuk — {$u['unit']}", $u['qty']];
        }
        return ['Ringkasan IN' => ['headers' => ['Keterangan', 'Nilai'], 'rows' => $sum], 'Transaksi IN' => $inv + ['freeze_header' => true, 'autofilter' => true],
            'Rincian Item IN' => $lt + ['freeze_header' => true, 'autofilter' => true]];
    }

    /** @return array{invoices:list<array<string,mixed>>, lines:list<array<string,mixed>>, disclosures:array<string,mixed>} */
    private static function inDataset(PDO $pdo, array $f): array
    {
        self::assertPeriod($f);
        $hist = (string) ($f['in_source'] ?? '') === 'Historis' ? '1' : '';
        $lines = self::inEnrich(self::inFetch($pdo, $f + ['historical' => $hist], true));
        $src = (string) ($f['in_source'] ?? '');
        $st = (string) ($f['status'] ?? '');
        if (in_array($src, ['V2', 'Legacy'], true)) {
            $lines = array_values(array_filter($lines, static fn ($l) => $l['source'] === $src));
        }
        if (in_array($st, ['POSTED', 'VOID'], true)) {
            $lines = array_values(array_filter($lines, static fn ($l) => $st === 'POSTED' ? $l['status'] === 'POSTED' : $l['status'] !== 'POSTED'));
        }
        $g = self::inGroup($lines);
        // an item filter narrows which LINES match; flag an invoice that only partly matches (sibling lines counted WITHOUT the item filter)
        $itemFilter = !empty($f['category_id']) || !empty($f['item_id']) || trim((string) ($f['q'] ?? '')) !== '';
        $total = [];
        if ($itemFilter && $lines) {
            $all = self::inEnrich(self::inFetch($pdo, array_diff_key($f, ['category_id' => 1, 'q' => 1, 'item_id' => 1]) + ['historical' => $hist], false));
            foreach ($all as $l) {
                $total[$l['group_key']] = ($total[$l['group_key']] ?? 0) + 1;
            }
        }
        foreach ($g['invoices'] as &$inv) {
            $inv['lines_matched'] = count($inv['lines']);
            $inv['lines_total'] = $total[$inv['group_key']] ?? count($inv['lines']);
            $inv['partial'] = $inv['lines_total'] > $inv['lines_matched'];
        }
        unset($inv);
        return $g + ['lines' => $lines];
    }

    private static function inFetch(PDO $pdo, array $f, bool $itemFilters): array
    {
        $where = ["t.transaction_type = 'IN'", "t.status IN ('POSTED','VOID','REVERSED')"];
        $bind = [];
        if (!empty($f['tx_ids'])) {
            $ph = [];
            foreach (array_values($f['tx_ids']) as $i => $id) {
                $ph[] = ':tx' . $i;
                $bind['tx' . $i] = (int) $id;
            }
            $where[] = 't.id IN (' . implode(',', $ph) . ')';
        } else {
            $where[] = 'DATE(t.transaction_date) >= :d1';
            $bind['d1'] = $f['start_date'];
            $where[] = 'DATE(t.transaction_date) <= :d2';
            $bind['d2'] = $f['end_date'];
        }
        if (!empty($f['warehouse_id'])) { $where[] = 't.warehouse_id = :wh'; $bind['wh'] = (int) $f['warehouse_id']; }
        if (!empty($f['supplier_id'])) { $where[] = 't.supplier_id = :sup'; $bind['sup'] = (int) $f['supplier_id']; }
        $hist = (string) ($f['historical'] ?? '');
        if ($hist === '1') { $where[] = 't.is_historical_import = 1'; }
        elseif ($hist !== 'all') { $where[] = 't.is_historical_import = 0 AND t.inventory_effect = 1'; }
        if ($itemFilters) {
            [$w, $b] = self::itemWhere($f, 'i', 'l');
            $where = array_merge($where, $w);
            $bind += $b;
        }
        $stmt = $pdo->prepare(
            "SELECT t.id AS tx_id, t.transaction_uuid, t.transaction_date, t.created_at, t.posting_date, t.reference_no, t.status, t.is_historical_import,
                    t.warehouse_id, w.name AS wh_name, t.supplier_id, s.name AS supplier_name, u.username AS created_by_name,
                    l.id AS line_id, l.item_id, i.sku, i.name AS item_name, i.category_id, c.name AS category_name,
                    l.input_qty, l.input_unit_id, iu.code AS input_unit, l.base_qty, bu.code AS base_unit, l.unit_price_input, l.subtotal, l.notes,
                    plc.gross_unit_price_input, plc.gross_amount, plc.line_discount_amount, plc.net_after_line_discount, plc.invoice_discount_allocated,
                    plc.net_purchase_before_tax, plc.ppn_allocated, plc.freight_allocated, plc.final_inventory_cost,
                    plc.id AS plc_id, pih.id AS pih_id, pih.ppn_treatment, pih.ppn_rate, pih.freight_treatment, pih.freight_amount, pih.invoice_total
               FROM inventory_transactions t
               JOIN inventory_transaction_lines l ON l.transaction_id = t.id
               JOIN items i ON i.id = l.item_id
               LEFT JOIN categories c ON c.id = i.category_id
               JOIN units iu ON iu.id = l.input_unit_id
               JOIN units bu ON bu.id = i.base_unit_id
               JOIN warehouses w ON w.id = t.warehouse_id
               LEFT JOIN suppliers s ON s.id = t.supplier_id
               LEFT JOIN users u ON u.id = t.created_by
               LEFT JOIN purchase_line_costs plc ON plc.transaction_line_id = l.id
               LEFT JOIN purchase_invoice_headers pih ON pih.transaction_id = t.id
              WHERE " . implode(' AND ', $where) . ' ORDER BY t.transaction_date, t.id, l.line_no, l.id'
        );
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }

    private static function inEnrich(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $uuid = (string) $r['transaction_uuid'];
            $ref = $r['reference_no'] !== null && trim((string) $r['reference_no']) !== '' ? trim((string) $r['reference_no']) : null;
            $date = substr((string) $r['transaction_date'], 0, 10);
            if (preg_match('/^(.*):IN:\d+$/', $uuid, $m)) {
                $key = 'U:' . $m[1];
            } elseif ($ref !== null) {
                $key = "R:{$r['supplier_id']}|{$r['warehouse_id']}|{$date}|{$ref}";
            } else {
                $key = 'T:' . $r['tx_id'];
            }
            $isHist = (int) $r['is_historical_import'] === 1;
            $hasV2 = $r['plc_id'] !== null && $r['pih_id'] !== null;
            $l = [
                'tx_id' => (int) $r['tx_id'], 'line_id' => (int) $r['line_id'], 'group_key' => $key, 'date' => $date, 'transaction_at' => $r['transaction_date'], 'created_at' => $r['created_at'], 'posted_at' => $r['posting_date'],
                'reference' => $ref, 'status' => $r['status'], 'counted' => $r['status'] === 'POSTED', 'source' => $isHist ? 'Historis' : ($hasV2 ? 'V2' : 'Legacy'), 'matched' => true,
                'supplier_id' => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null, 'supplier' => $r['supplier_name'], 'warehouse_id' => (int) $r['warehouse_id'], 'warehouse' => $r['wh_name'],
                'created_by' => $r['created_by_name'], 'item_id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name'], 'category' => $r['category_name'] ?? '(Tanpa Kategori)',
                'unit' => $r['input_unit'], 'qty' => (float) $r['input_qty'], 'base_qty' => (float) $r['base_qty'], 'base_unit' => $r['base_unit'], 'notes' => $r['notes'],
                'price' => null, 'gross' => null, 'item_discount' => null, 'dpp' => null, 'ppn_rate' => null, 'ppn' => null, 'invoice_discount' => null, 'freight' => null, 'total' => null,
                'inventory_cost' => null, 'freight_treatment' => null,
            ];
            if ($hasV2) {
                $rate = (float) $r['ppn_rate'];
                $dpp = round((float) $r['net_after_line_discount'], 4);
                $ppnBefore = round($dpp * $rate / 100, 4);
                $l = array_merge($l, [
                    'price' => round((float) $r['gross_unit_price_input'], 4), 'gross' => round((float) $r['gross_amount'], 4), 'item_discount' => round((float) $r['line_discount_amount'], 4), 'dpp' => $dpp,
                    'ppn_rate' => $rate, 'ppn' => $ppnBefore, 'invoice_discount' => round($dpp + $ppnBefore - round((float) $r['net_purchase_before_tax'], 4) - round((float) $r['ppn_allocated'], 4), 4),
                    'freight' => round((float) $r['freight_amount'], 4), 'total' => round((float) $r['invoice_total'], 4), 'inventory_cost' => round((float) $r['final_inventory_cost'], 4), 'freight_treatment' => $r['freight_treatment'],
                ]);
            } else {
                $l['price'] = round((float) $r['unit_price_input'], 4);
                $l['total'] = round((float) $r['subtotal'], 4);
            }
            $out[] = $l;
        }
        return $out;
    }

    private static function inGroup(array $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['group_key']][] = $l;
        }
        $invoices = [];
        $void = ['invoices' => 0, 'amount' => 0.0];
        $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$k] ?? 0), $rows)), 4);
        foreach ($groups as $key => $ls) {
            $counted = array_values(array_filter($ls, static fn ($x) => $x['counted']));
            $v2 = array_values(array_filter($counted, static fn ($x) => $x['source'] === 'V2'));
            $sources = array_values(array_unique(array_column($ls, 'source')));
            $statuses = array_values(array_unique(array_column($ls, 'status')));
            $first = $ls[0];
            $known = $v2 !== [];
            $rates = array_values(array_unique(array_map(static fn ($x) => rtrim(rtrim(number_format((float) $x['ppn_rate'], 4, '.', ''), '0'), '.') . '%', $v2)));
            sort($rates);
            $inv = [
                'group_key' => $key, 'tx_ids' => array_values(array_unique(array_column($ls, 'tx_id'))), 'date' => $first['date'], 'transaction_at' => $first['transaction_at'], 'created_at' => $first['created_at'],
                'time' => substr((string) $first['created_at'], 11, 5), 'posted_at' => $first['posted_at'], 'reference' => $first['reference'] ?? '—', 'supplier' => $first['supplier'] ?? '—', 'warehouse' => $first['warehouse'],
                'created_by' => $first['created_by'], 'status' => count($statuses) === 1 ? $statuses[0] : 'SEBAGIAN VOID', 'source' => count($sources) === 1 ? $sources[0] : 'Campuran',
                'items' => count($counted), 'skus' => count(array_unique(array_column($counted, 'item_id'))), 'components_known' => $known, 'components_complete' => $known && count($v2) === count($counted),
                'gross' => $known ? $sum($v2, 'gross') : null, 'item_discount' => $known ? $sum($v2, 'item_discount') : null, 'subtotal' => $known ? $sum($v2, 'dpp') : null,
                'invoice_discount' => $known ? $sum($v2, 'invoice_discount') : null, 'ppn' => $known ? $sum($v2, 'ppn') : null, 'freight' => $known ? $sum($v2, 'freight') : null,
                'total' => $counted ? $sum($counted, 'total') : 0.0, 'inventory_cost' => $known ? $sum($v2, 'inventory_cost') : null, 'ppn_rate' => $known ? implode(' / ', $rates) : null,
                'freight_treatment' => $known ? (implode(' / ', array_values(array_unique(array_filter(array_column($v2, 'freight_treatment'), static fn ($t) => $t !== 'NONE' && $t !== null)))) ?: 'Tanpa ongkir') : null,
                'voided_total' => round(array_sum(array_map(static fn ($x) => $x['counted'] ? 0.0 : (float) ($x['total'] ?? 0), $ls)), 4), 'qty_text' => self::qtyText($counted), 'lines' => $ls,
            ];
            if (!$counted) {
                $void['invoices']++;
                $void['amount'] += $inv['voided_total'];
            }
            $invoices[] = $inv;
        }
        usort($invoices, static fn ($a, $b) => [$b['transaction_at'], $b['tx_ids'][0]] <=> [$a['transaction_at'], $a['tx_ids'][0]]);
        return ['invoices' => $invoices, 'disclosures' => ['void' => ['invoices' => $void['invoices'], 'amount' => round($void['amount'], 4)]]];
    }

    private static function inKpi(array $d): array
    {
        $n = ['total' => 0.0, 'subtotal' => 0.0, 'item_discount' => 0.0, 'invoice_discount' => 0.0, 'discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'invoices' => 0, 'lines' => 0];
        $sup = [];
        $skus = [];
        $qty = [];
        foreach ($d['lines'] as $l) {
            if (!$l['counted']) {
                continue;
            }
            $n['lines']++;
            $n['total'] += (float) $l['total'];
            $skus[$l['item_id']] = true;
            if ($l['supplier_id'] !== null) {
                $sup[$l['supplier_id']] = true;
            }
            $qty[(string) $l['base_unit']] = ($qty[(string) $l['base_unit']] ?? 0.0) + (float) $l['base_qty'];
            if ($l['source'] === 'V2') {
                foreach (['item_discount', 'invoice_discount', 'ppn', 'freight'] as $k) {
                    $n[$k] += (float) $l[$k];
                }
                $n['subtotal'] += (float) $l['dpp'];
            }
        }
        $n['invoices'] = count(array_filter($d['invoices'], static fn ($i) => $i['items'] > 0));
        $n['discount'] = $n['item_discount'] + $n['invoice_discount'];
        foreach (['total', 'subtotal', 'item_discount', 'invoice_discount', 'discount', 'ppn', 'freight'] as $k) {
            $n[$k] = round($n[$k], 4);
        }
        $n['skus'] = count($skus);
        $n['suppliers'] = count($sup);
        $n['avg_invoice'] = $n['invoices'] > 0 ? round($n['total'] / $n['invoices'], 4) : null;
        ksort($qty);
        return ['nominal' => $n, 'qty_by_unit' => array_map(static fn ($u, $q) => ['unit' => $u, 'qty' => round($q, 6)], array_keys($qty), $qty)];
    }

    private static function inTrend(array $d, string $start, string $end, string $bucket): array
    {
        $b = self::axis($start, $end, $bucket, ['total' => 0.0, 'count' => 0]);
        $label = self::labeler($bucket);
        foreach ($d['invoices'] as $i) {
            $cnt = array_filter($i['lines'], static fn ($x) => $x['counted']);
            $k = $label($i['date']);
            if (!$cnt || !isset($b[$k])) {
                continue;
            }
            $b[$k]['count']++;
            foreach ($cnt as $l) {
                $b[$k]['total'] += (float) $l['total'];
            }
        }
        return array_values(array_map(static fn ($r) => ['total' => round($r['total'], 4)] + $r, $b));
    }

    private static function inSearch(array $invoices, string $q): array
    {
        return self::search($invoices, $q, static function (array $i): array {
            $h = [$i['date'], (string) $i['reference'], (string) $i['supplier'], (string) $i['warehouse'], (string) $i['created_by']];
            foreach ($i['lines'] as $l) {
                $h[] = $l['sku'];
                $h[] = $l['name'];
            }
            return $h;
        });
    }

    private static function inFooter(array $rows): array
    {
        $t = ['items' => 0, 'gross' => 0.0, 'item_discount' => 0.0, 'subtotal' => 0.0, 'invoice_discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'total' => 0.0, 'inventory_cost' => 0.0];
        foreach ($rows as $r) {
            $t['items'] += $r['items'];
            $t['total'] += (float) $r['total'];
            foreach (['gross', 'item_discount', 'subtotal', 'invoice_discount', 'ppn', 'freight', 'inventory_cost'] as $k) {
                $t[$k] += (float) ($r[$k] ?? 0);
            }
        }
        foreach ($t as $k => $v) {
            $t[$k] = $k === 'items' ? $v : round($v, 4);
        }
        return ['totals' => $t, 'count' => count($rows), 'incomplete_components' => count(array_filter($rows, static fn ($r) => !$r['components_complete'] && $r['items'] > 0))];
    }

    // ======================================================================
    // OUT
    // ======================================================================

    public static function outOverview(PDO $pdo, array $f): array
    {
        $d = self::outDataset($pdo, $f);
        $bucket = self::bucket($f);
        $prev = self::previousPeriod($f);
        $pk = $prev !== null ? self::outKpi(self::outDataset($pdo, $prev)) : null;
        $k = self::outKpi($d);
        return [
            'tab' => 'out', 'period' => ['start' => $f['start_date'], 'end' => $f['end_date'], 'bucket' => $bucket, 'previous' => $prev !== null ? ['start' => $prev['start_date'], 'end' => $prev['end_date']] : null],
            'kpi' => $k, 'previous_kpi' => $pk, 'delta' => $pk !== null ? self::deltas($k['nominal'], $pk['nominal'], ['hpp', 'sell', 'documents', 'skus', 'bakeries', 'shipping', 'margin']) : null,
            'trend' => self::outTrend($d, (string) $f['start_date'], (string) $f['end_date'], $bucket), 'disclosures' => $d['disclosures'], 'filters' => self::filterEcho($f),
        ];
    }

    public static function outList(PDO $pdo, array $f): array
    {
        $d = self::outDataset($pdo, $f);
        $rows = self::sortRows(self::outSearch($d['documents'], (string) ($f['gq'] ?? '')), $f, ['hpp' => 'hpp', 'sell' => 'sell', 'bakery' => 'bakery', 'do_number' => 'do_number', 'date' => 'transaction_at']);
        return self::page($rows, $f) + ['columns' => self::columns(self::OUT_COLUMNS), 'footer' => self::outFooter($rows)];
    }

    /** One OUT document (DO or a legacy transaction) with its lines and the FIFO layers each line consumed. */
    public static function outDetail(PDO $pdo, ?int $doId, ?int $txId, ?int $scopeWarehouseId): array
    {
        $f = ['historical' => 'all', 'warehouse_id' => $scopeWarehouseId];
        if ($doId !== null) {
            $f['do_id'] = $doId;
        } elseif ($txId !== null) {
            $f['tx_id'] = $txId;
        } else {
            throw new ValidationException(['do_id or tx_id is required']);
        }
        $lines = self::outEnrich(self::outFetch($pdo, $f));
        if (!$lines) {
            throw new NotFoundException('document OUT not found');
        }
        $doc = array_values(self::outGroup($lines)['documents'])[0];
        $ids = array_map(static fn ($l) => (int) $l['line_id'], $doc['lines']);
        $layers = self::fifoLayers($pdo, $ids);
        $byLine = [];
        foreach ($layers as $a) {
            $byLine[$a['line_id']][] = $a;
        }
        foreach ($doc['lines'] as &$l) {
            $l['layer_rows'] = $byLine[$l['line_id']] ?? [];
            $l['fifo_qty'] = round(array_sum(array_column($l['layer_rows'], 'qty')), 6);
            $l['fifo_value'] = round(array_sum(array_column($l['layer_rows'], 'value')), 4);
        }
        unset($l);
        $counted = array_values(array_filter($doc['lines'], static fn ($l) => $l['counted']));
        return ['document' => array_diff_key($doc, ['lines' => 1]), 'lines' => $doc['lines'], 'columns' => self::columns(self::OUT_LINE_COLUMNS), 'layer_columns' => self::columns(self::FIFO_COLUMNS),
            'check' => ['fifo_qty_equals_out_qty' => self::allTrue($counted, static fn ($l) => abs($l['fifo_qty'] - $l['base_qty']) <= 0.000001),
                'fifo_value_equals_hpp' => self::allTrue($counted, static fn ($l) => abs($l['fifo_value'] - $l['hpp']) <= 0.01)],
            'notes' => ['Harga Modal Referensi = harga beli referensi yang tersimpan pada invoice saat posting; HPP FIFO Aktual = biaya layer FIFO yang benar-benar terpakai. Margin = Subtotal Jual − HPP Total.',
                'Ongkir hanya tersimpan di header invoice; alokasi per baris adalah turunan proporsional terhadap Subtotal Jual (total alokasi = ongkir invoice).']];
    }

    public static function outExport(PDO $pdo, array $f, array $meta): array
    {
        $d = self::outDataset($pdo, $f);
        $k = self::outKpi($d);
        $n = $k['nominal'];
        $rows = self::outSearch($d['documents'], (string) ($f['gq'] ?? ''));
        $docT = self::table(self::OUT_COLUMNS, $rows);
        $docT['rows'][] = self::totalRow(self::OUT_COLUMNS, self::outFooter($rows)['totals'], 'TOTAL');
        $lines = [];
        $ids = [];
        foreach ($rows as $doc) {
            foreach ($doc['lines'] as $l) {
                if ($l['matched']) {
                    $lines[] = $l + ['do_number' => $doc['do_number'], 'invoice_number' => $doc['invoice_number'], 'bakery' => $doc['bakery'], 'warehouse' => $doc['warehouse'], 'date' => $doc['date']];
                    $ids[$l['line_id']] = ['do_number' => $doc['do_number'], 'sku' => $l['sku'], 'name' => $l['name'], 'base_unit' => $l['base_unit']];
                }
            }
        }
        $cols = array_merge([['date', 'Tanggal', 'date', true], ['do_number', 'No. DO', 'text', true], ['invoice_number', 'No. Invoice', 'text', true], ['bakery', 'Bakery Tujuan', 'text', true], ['warehouse', 'Gudang Asal', 'text', true]], self::OUT_LINE_COLUMNS);
        $lt = self::table($cols, $lines);
        $tot = [];
        foreach (['sell', 'shipping', 'line_total', 'hpp', 'margin'] as $c) {
            $tot[$c] = round(array_sum(array_map(static fn ($r) => $r['counted'] ? (float) ($r[$c] ?? 0) : 0.0, $lines)), 4);
        }
        $lt['rows'][] = self::totalRow($cols, $tot, 'TOTAL (POSTED)');
        $fifoRows = [];
        foreach (self::fifoLayers($pdo, array_keys($ids)) as $a) {
            $fifoRows[] = $a + $ids[$a['line_id']];
        }
        $ft = self::table(self::FIFO_COLUMNS, $fifoRows);
        $ft['rows'][] = self::totalRow(self::FIFO_COLUMNS, ['value' => round(array_sum(array_column($fifoRows, 'value')), 4)], 'TOTAL');
        $sum = [];
        foreach ($meta as $key => $val) {
            $sum[] = [$key, $val];
        }
        $sum[] = ['', ''];
        foreach ([['Total HPP Keluar', $n['hpp']], ['Total Nilai Jual (Invoice, barang)', $n['sell']], ['Ongkir', $n['shipping']], ['Total Invoice (Nilai Jual + Ongkir)', $n['grand_total']], ['Margin (Nilai Jual − HPP)', $n['margin']],
            ['Jumlah Transaksi (DO / dokumen)', $n['documents']], ['Jumlah SKU', $n['skus']], ['Jumlah Bakery', $n['bakeries']],
            ['HPP tanpa nilai jual (Legacy / invoice belum terbit)', $n['hpp_without_sell']], ['Dokumen VOID/CANCELLED (tidak dihitung) — jumlah', $d['disclosures']['void']['documents']],
            ['Dokumen VOID/CANCELLED (tidak dihitung) — HPP', $d['disclosures']['void']['hpp']]] as $r) {
            $sum[] = [$r[0], self::cell('money', $r[1])];
        }
        foreach ($k['qty_by_unit'] as $u) {
            $sum[] = ["Qty keluar — {$u['unit']}", $u['qty']];
        }
        return ['Ringkasan OUT' => ['headers' => ['Keterangan', 'Nilai'], 'rows' => $sum], 'Transaksi OUT' => $docT + ['freeze_header' => true, 'autofilter' => true],
            'Rincian Item OUT' => $lt + ['freeze_header' => true, 'autofilter' => true], 'FIFO Allocation' => $ft + ['freeze_header' => true, 'autofilter' => true]];
    }

    private static function outDataset(PDO $pdo, array $f): array
    {
        self::assertPeriod($f);
        $hist = (string) ($f['out_source'] ?? '') === 'Historis' ? '1' : '';
        $lines = self::outEnrich(self::outFetch($pdo, $f + ['historical' => $hist]));
        $src = (string) ($f['out_source'] ?? '');
        if (in_array($src, ['V2', 'Legacy'], true)) {
            $lines = array_values(array_filter($lines, static fn ($l) => $l['source'] === $src));
        }
        // item filters narrow which LINES match; a document keeps all its lines so the invoice-level shipping allocation stays whole
        $hasItem = !empty($f['category_id']) || !empty($f['item_id']) || trim((string) ($f['q'] ?? '')) !== '';
        if ($hasItem) {
            $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
            foreach ($lines as &$l) {
                $l['matched'] = (empty($f['category_id']) || $l['category_id'] === (int) $f['category_id']) && (empty($f['item_id']) || $l['item_id'] === (int) $f['item_id'])
                    && ($q === '' || str_contains(mb_strtolower($l['sku'] . ' ' . $l['name']), $q));
            }
            unset($l);
        }
        $st = (string) ($f['status'] ?? '');
        $g = self::outGroup($lines);
        $docs = array_values(array_filter($g['documents'], static fn ($doc) => $doc['lines_matched'] > 0 && ($st === '' || $doc['status'] === $st)));
        $void = ['documents' => 0, 'hpp' => 0.0];
        foreach ($docs as $doc) {
            if ($doc['items'] === 0) {
                $void['documents']++;
                $void['hpp'] += (float) $doc['voided_hpp'];
            }
        }
        return ['documents' => $docs, 'disclosures' => ['void' => ['documents' => $void['documents'], 'hpp' => round($void['hpp'], 4)]]];
    }

    private static function outFetch(PDO $pdo, array $f): array
    {
        $where = ["t.transaction_type = 'OUT'", "t.status IN ('POSTED','VOID','REVERSED')"];
        $bind = [];
        if (!empty($f['do_id'])) {
            $where[] = 'dor.id = :doid';
            $bind['doid'] = (int) $f['do_id'];
        } elseif (!empty($f['tx_id'])) {
            $where[] = 't.id = :txid';
            $bind['txid'] = (int) $f['tx_id'];
        } else {
            $where[] = 'DATE(t.transaction_date) >= :d1';
            $bind['d1'] = $f['start_date'];
            $where[] = 'DATE(t.transaction_date) <= :d2';
            $bind['d2'] = $f['end_date'];
        }
        if (!empty($f['warehouse_id'])) { $where[] = 't.warehouse_id = :wh'; $bind['wh'] = (int) $f['warehouse_id']; }
        if (!empty($f['bakery_destination_id'])) { $where[] = 'COALESCE(dor.bakery_destination_id, t.bakery_destination_id) = :bk'; $bind['bk'] = (int) $f['bakery_destination_id']; }
        if (!empty($f['division_id'])) { $where[] = 't.division_id = :dv'; $bind['dv'] = (int) $f['division_id']; }
        $hist = (string) ($f['historical'] ?? '');
        if ($hist === '1') { $where[] = 't.is_historical_import = 1'; }
        elseif ($hist !== 'all') { $where[] = 't.is_historical_import = 0 AND t.inventory_effect = 1'; }
        $stmt = $pdo->prepare(
            "SELECT t.id AS tx_id, t.transaction_uuid, t.transaction_date, t.created_at, t.reference_no, t.status AS tx_status, t.is_historical_import, t.warehouse_id, w.name AS wh_name,
                    t.division_id, dv.name AS division_name, u.username AS tx_created_by,
                    l.id AS line_id, l.line_no, l.item_id, i.sku, i.name AS item_name, i.category_id, c.name AS category_name, l.input_qty, iu.code AS input_unit, l.base_qty, bu.code AS base_unit,
                    l.unit_cost_base, l.subtotal AS hpp_total, l.notes,
                    (SELECT COALESCE(SUM(fa.qty_allocated), 0) FROM fifo_allocations fa WHERE fa.transaction_line_id = l.id) AS alloc_qty,
                    (SELECT COALESCE(SUM(fa.subtotal), 0) FROM fifo_allocations fa WHERE fa.transaction_line_id = l.id) AS alloc_value,
                    (SELECT COUNT(*) FROM fifo_allocations fa WHERE fa.transaction_line_id = l.id) AS alloc_layers,
                    dol.id AS dol_id, dor.id AS do_id, dor.do_number, dor.status AS do_status, dor.reference_no AS do_reference, dor.notes AS do_notes, dor.dispatched_at, dispu.username AS dispatched_by_name,
                    dcu.username AS do_created_by, dor.bakery_destination_id AS do_bakery,
                    inv.id AS inv_id, inv.invoice_number, inv.status AS inv_status, inv.subtotal AS inv_subtotal, inv.shipping_amount, inv.discount_amount, inv.tax_amount, inv.grand_total, inv.issued_at,
                    il.reference_purchase_price, il.pricing_method, il.margin_value, il.selling_unit_price, il.subtotal AS sell_subtotal,
                    bd.id AS bakery_id, bd.name AS bakery_name
               FROM inventory_transactions t
               JOIN inventory_transaction_lines l ON l.transaction_id = t.id
               JOIN items i ON i.id = l.item_id
               LEFT JOIN categories c ON c.id = i.category_id
               JOIN units iu ON iu.id = l.input_unit_id
               JOIN units bu ON bu.id = i.base_unit_id
               JOIN warehouses w ON w.id = t.warehouse_id
               LEFT JOIN divisions dv ON dv.id = t.division_id
               LEFT JOIN users u ON u.id = t.created_by
               LEFT JOIN distribution_order_lines dol ON dol.out_transaction_line_id = l.id
               LEFT JOIN distribution_orders dor ON dor.id = dol.do_id
               LEFT JOIN users dispu ON dispu.id = dor.dispatched_by
               LEFT JOIN users dcu ON dcu.id = dor.created_by
               LEFT JOIN distribution_invoices inv ON inv.do_id = dor.id
               LEFT JOIN distribution_invoice_lines il ON il.do_line_id = dol.id AND il.invoice_id = inv.id
               LEFT JOIN bakery_destinations bd ON bd.id = COALESCE(dor.bakery_destination_id, t.bakery_destination_id)
              WHERE " . implode(' AND ', $where) . ' ORDER BY t.transaction_date, t.id, l.line_no, l.id'
        );
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }

    private static function outEnrich(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $isHist = (int) $r['is_historical_import'] === 1;
            $hasDo = $r['do_id'] !== null;
            $hasInv = $r['inv_id'] !== null && $r['sell_subtotal'] !== null;
            $issued = $hasInv && $r['inv_status'] === 'ISSUED';
            $counted = $r['tx_status'] === 'POSTED';
            $hpp = round((float) $r['hpp_total'], 4);
            $qty = (float) $r['input_qty'];
            $sell = $issued ? round((float) $r['sell_subtotal'], 4) : null;
            $mode = null;
            if ($hasInv) {
                $mode = $r['pricing_method'] === 'COST_PLUS_PERCENT' ? 'Persen (%)' : ($r['pricing_method'] === 'COST_PLUS_AMOUNT' ? 'Nominal (Rp)' : 'Tanpa markup');
            }
            $status = $hasDo ? (string) $r['do_status'] : (string) $r['tx_status'];
            $out[] = [
                'tx_id' => (int) $r['tx_id'], 'line_id' => (int) $r['line_id'], 'group_key' => $hasDo ? 'D:' . $r['do_id'] : 'T:' . $r['tx_id'], 'do_id' => $hasDo ? (int) $r['do_id'] : null,
                'date' => substr((string) $r['transaction_date'], 0, 10), 'transaction_at' => $r['transaction_date'], 'created_at' => $r['created_at'],
                'do_number' => $r['do_number'], 'invoice_id' => $r['inv_id'] !== null ? (int) $r['inv_id'] : null, 'invoice_number' => $r['invoice_number'], 'invoice_status' => $r['inv_status'],
                'status' => $status, 'tx_status' => $r['tx_status'], 'counted' => $counted, 'source' => $isHist ? 'Historis' : ($hasDo && $hasInv ? 'V2' : 'Legacy'), 'matched' => true,
                'warehouse_id' => (int) $r['warehouse_id'], 'warehouse' => $r['wh_name'], 'bakery_id' => $r['bakery_id'] !== null ? (int) $r['bakery_id'] : null, 'bakery' => $r['bakery_name'] ?? '—',
                'division' => $r['division_name'], 'division_id' => $r['division_id'] !== null ? (int) $r['division_id'] : null, 'reference_no' => $r['do_reference'] ?? $r['reference_no'],
                'created_by' => $r['do_created_by'] ?? $r['tx_created_by'], 'dispatched_by' => $r['dispatched_by_name'], 'dispatched_at' => $r['dispatched_at'], 'issued_at' => $r['issued_at'],
                'item_id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name'], 'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'category' => $r['category_name'] ?? '(Tanpa Kategori)', 'unit' => $r['input_unit'], 'qty' => $qty, 'base_qty' => (float) $r['base_qty'], 'base_unit' => $r['base_unit'], 'notes' => $r['notes'],
                'hpp' => $hpp, 'hpp_unit' => $qty > 0 ? round($hpp / $qty, 4) : null, 'alloc_qty' => round((float) $r['alloc_qty'], 6), 'alloc_value' => round((float) $r['alloc_value'], 4), 'layers' => (int) $r['alloc_layers'],
                'reference_price' => $hasInv && $r['reference_purchase_price'] !== null ? round((float) $r['reference_purchase_price'], 4) : null, 'markup_type' => $mode,
                'markup_value' => $hasInv ? round((float) $r['margin_value'], 4) : null, 'sell_price' => $hasInv ? round((float) $r['selling_unit_price'], 4) : null, 'sell' => $sell,
                'shipping' => null, 'line_total' => null, 'margin' => null, 'inv_shipping' => $issued ? round((float) $r['shipping_amount'], 4) : null,
                'inv_subtotal' => $hasInv ? round((float) $r['inv_subtotal'], 4) : null, 'inv_grand' => $hasInv ? round((float) $r['grand_total'], 4) : null,
            ];
        }
        return $out;
    }

    private static function outGroup(array $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['group_key']][] = $l;
        }
        $docs = [];
        $r4 = static fn (float $v): float => round($v, 4);
        foreach ($groups as $key => $ls) {
            // shipping allocation: over the counted lines of an ISSUED invoice, proportional to selling subtotal; the last line takes the remainder
            $base = array_values(array_filter(array_keys($ls), static fn ($i) => $ls[$i]['counted'] && $ls[$i]['sell'] !== null));
            $ship = $base ? (float) $ls[$base[0]]['inv_shipping'] : 0.0;
            $sellSum = array_sum(array_map(static fn ($i) => (float) $ls[$i]['sell'], $base));
            $given = 0.0;
            foreach ($base as $pos => $i) {
                $share = $pos === count($base) - 1 ? $r4($ship - $given) : $r4($sellSum > 0 ? $ship * (float) $ls[$i]['sell'] / $sellSum : $ship / count($base));
                $given += $share;
                $ls[$i]['shipping'] = $share;
                $ls[$i]['line_total'] = $r4((float) $ls[$i]['sell'] + $share);
                $ls[$i]['margin'] = $r4((float) $ls[$i]['sell'] - (float) $ls[$i]['hpp']);
            }
            $counted = array_values(array_filter($ls, static fn ($x) => $x['counted']));
            $matched = array_values(array_filter($counted, static fn ($x) => $x['matched']));
            $priced = array_values(array_filter($matched, static fn ($x) => $x['sell'] !== null));
            $first = $ls[0];
            $statuses = array_values(array_unique(array_column($ls, 'tx_status')));
            $sources = array_values(array_unique(array_column($ls, 'source')));
            $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$k] ?? 0), $rows)), 4);
            $allPriced = $matched !== [] && count($priced) === count($matched);
            $hpp = $sum($matched, 'hpp');
            $docStatus = $first['status'];
            if ($first['do_id'] === null && count($statuses) > 1) {
                $docStatus = 'SEBAGIAN VOID';
            }
            $docs[] = [
                'group_key' => $key, 'do_id' => $first['do_id'], 'tx_ids' => array_values(array_unique(array_column($ls, 'tx_id'))), 'invoice_id' => $first['invoice_id'],
                'date' => $first['date'], 'transaction_at' => $first['transaction_at'], 'created_at' => $first['created_at'], 'time' => substr((string) $first['created_at'], 11, 5),
                'do_number' => $first['do_number'] ?? '—', 'invoice_number' => $first['invoice_number'] ?? '—', 'invoice_status' => $first['invoice_status'], 'warehouse' => $first['warehouse'],
                'bakery' => $first['bakery'], 'division' => $first['division'] ?? '—', 'status' => $docStatus, 'source' => count($sources) === 1 ? $sources[0] : 'Campuran',
                'reference_no' => $first['reference_no'], 'created_by' => $first['created_by'], 'dispatched_by' => $first['dispatched_by'], 'dispatched_at' => $first['dispatched_at'],
                'items' => count($matched), 'skus' => count(array_unique(array_column($matched, 'item_id'))), 'lines_matched' => count(array_filter($ls, static fn ($x) => $x['matched'])), 'lines_total' => count($ls),
                'partial' => count(array_filter($ls, static fn ($x) => $x['matched'])) < count($ls), 'qty_text' => self::qtyText($matched),
                'hpp' => $hpp, 'sell' => $priced ? $sum($priced, 'sell') : null, 'shipping' => $priced ? $sum($priced, 'shipping') : null,
                'grand_total' => $priced ? $sum($priced, 'line_total') : null, 'margin' => $priced ? $sum($priced, 'margin') : null,
                'margin_pct' => $priced && $sum($priced, 'sell') > 0 ? round($sum($priced, 'margin') * 100 / $sum($priced, 'sell'), 2) : null,
                'priced_complete' => $allPriced, 'hpp_unpriced' => round($hpp - $sum($priced, 'hpp'), 4),
                'voided_hpp' => round(array_sum(array_map(static fn ($x) => $x['counted'] ? 0.0 : (float) $x['hpp'], $ls)), 4), 'lines' => $ls,
            ];
        }
        usort($docs, static fn ($a, $b) => [$b['transaction_at'], $b['tx_ids'][0]] <=> [$a['transaction_at'], $a['tx_ids'][0]]);
        return ['documents' => $docs];
    }

    private static function outKpi(array $d): array
    {
        $n = ['hpp' => 0.0, 'sell' => 0.0, 'shipping' => 0.0, 'grand_total' => 0.0, 'margin' => 0.0, 'hpp_without_sell' => 0.0, 'documents' => 0, 'lines' => 0];
        $skus = [];
        $bk = [];
        $qty = [];
        foreach ($d['documents'] as $doc) {
            $had = false;
            foreach ($doc['lines'] as $l) {
                if (!$l['counted'] || !$l['matched']) {
                    continue;
                }
                $had = true;
                $n['lines']++;
                $n['hpp'] += (float) $l['hpp'];
                $skus[$l['item_id']] = true;
                $qty[(string) $l['base_unit']] = ($qty[(string) $l['base_unit']] ?? 0.0) + (float) $l['base_qty'];
                if ($l['sell'] !== null) {
                    $n['sell'] += (float) $l['sell'];
                    $n['shipping'] += (float) $l['shipping'];
                    $n['grand_total'] += (float) $l['line_total'];
                    $n['margin'] += (float) $l['margin'];
                } else {
                    $n['hpp_without_sell'] += (float) $l['hpp'];
                }
            }
            if ($had) {
                $n['documents']++;
                if ($doc['bakery'] !== '—') {
                    $bk[$doc['bakery']] = true;
                }
            }
        }
        foreach (['hpp', 'sell', 'shipping', 'grand_total', 'margin', 'hpp_without_sell'] as $k) {
            $n[$k] = round($n[$k], 4);
        }
        $n['skus'] = count($skus);
        $n['bakeries'] = count($bk);
        $priced = $n['sell'] > 0;
        $n['margin_pct'] = $priced ? round($n['margin'] * 100 / $n['sell'], 2) : null;
        ksort($qty);
        return ['nominal' => $n, 'qty_by_unit' => array_map(static fn ($u, $q) => ['unit' => $u, 'qty' => round($q, 6)], array_keys($qty), $qty)];
    }

    private static function outTrend(array $d, string $start, string $end, string $bucket): array
    {
        $b = self::axis($start, $end, $bucket, ['hpp' => 0.0, 'sell' => 0.0, 'count' => 0]);
        $label = self::labeler($bucket);
        foreach ($d['documents'] as $doc) {
            $k = $label($doc['date']);
            $cnt = array_filter($doc['lines'], static fn ($x) => $x['counted'] && $x['matched']);
            if (!$cnt || !isset($b[$k])) {
                continue;
            }
            $b[$k]['count']++;
            foreach ($cnt as $l) {
                $b[$k]['hpp'] += (float) $l['hpp'];
                $b[$k]['sell'] += (float) ($l['sell'] ?? 0);
            }
        }
        return array_values(array_map(static fn ($r) => ['hpp' => round($r['hpp'], 4), 'sell' => round($r['sell'], 4)] + $r, $b));
    }

    private static function outSearch(array $docs, string $q): array
    {
        return self::search($docs, $q, static function (array $d): array {
            $h = [$d['date'], (string) $d['do_number'], (string) $d['invoice_number'], (string) $d['warehouse'], (string) $d['bakery'], (string) $d['created_by'], (string) $d['reference_no']];
            foreach ($d['lines'] as $l) {
                $h[] = $l['sku'];
                $h[] = $l['name'];
            }
            return $h;
        });
    }

    private static function outFooter(array $rows): array
    {
        $t = ['items' => 0, 'hpp' => 0.0, 'sell' => 0.0, 'shipping' => 0.0, 'grand_total' => 0.0, 'margin' => 0.0];
        foreach ($rows as $r) {
            $t['items'] += $r['items'];
            foreach (['hpp', 'sell', 'shipping', 'grand_total', 'margin'] as $k) {
                $t[$k] += (float) ($r[$k] ?? 0);
            }
        }
        foreach ($t as $k => $v) {
            $t[$k] = $k === 'items' ? $v : round($v, 4);
        }
        return ['totals' => $t, 'count' => count($rows), 'unpriced' => count(array_filter($rows, static fn ($r) => !$r['priced_complete'] && $r['items'] > 0))];
    }

    /** @return list<array<string,mixed>> FIFO layers consumed by the given OUT/TRANSFER_OUT transaction lines. */
    private static function fifoLayers(PDO $pdo, array $lineIds): array
    {
        if (!$lineIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $lineIds))), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare(
                "SELECT fa.transaction_line_id AS line_id, fa.batch_id, fa.qty_allocated, fa.unit_cost_base, fa.subtotal, b.received_date, b.is_negative_layer,
                        st.transaction_type AS src_type, st.reference_no AS src_ref, sup.name AS supplier_name
                   FROM fifo_allocations fa
                   JOIN inventory_batches b ON b.id = fa.batch_id
                   LEFT JOIN inventory_transaction_lines sl ON sl.id = b.source_transaction_line_id
                   LEFT JOIN inventory_transactions st ON st.id = sl.transaction_id
                   LEFT JOIN suppliers sup ON sup.id = b.supplier_id
                  WHERE fa.transaction_line_id IN ({$ph}) ORDER BY fa.transaction_line_id, b.received_date, fa.id"
            );
            $st->execute($chunk);
            foreach ($st->fetchAll() as $r) {
                $src = $r['src_type'] !== null ? $r['src_type'] . ($r['src_ref'] ? ' · ' . $r['src_ref'] : '') . ($r['supplier_name'] ? ' · ' . $r['supplier_name'] : '') : ((int) $r['is_negative_layer'] === 1 ? 'Layer negatif' : '—');
                $out[] = ['line_id' => (int) $r['line_id'], 'batch_id' => (int) $r['batch_id'], 'batch_date' => substr((string) $r['received_date'], 0, 10), 'batch_source' => $src,
                    'qty' => round((float) $r['qty_allocated'], 6), 'unit_cost' => round((float) $r['unit_cost_base'], 4), 'value' => round((float) $r['subtotal'], 4)];
            }
        }
        return $out;
    }

    // ======================================================================
    // TRANSFER
    // ======================================================================

    public static function trfOverview(PDO $pdo, array $f): array
    {
        $d = self::trfDataset($pdo, $f);
        $bucket = self::bucket($f);
        $prev = self::previousPeriod($f);
        $pk = $prev !== null ? self::trfKpi(self::trfDataset($pdo, $prev)) : null;
        $k = self::trfKpi($d);
        return [
            'tab' => 'transfer', 'period' => ['start' => $f['start_date'], 'end' => $f['end_date'], 'bucket' => $bucket, 'previous' => $prev !== null ? ['start' => $prev['start_date'], 'end' => $prev['end_date']] : null],
            'kpi' => $k, 'previous_kpi' => $pk, 'delta' => $pk !== null ? self::deltas($k['nominal'], $pk['nominal'], ['transfers', 'pending', 'received', 'skus', 'value']) : null,
            'trend' => self::trfTrend($d, (string) $f['start_date'], (string) $f['end_date'], $bucket), 'disclosures' => $d['disclosures'], 'filters' => self::filterEcho($f),
        ];
    }

    public static function trfList(PDO $pdo, array $f): array
    {
        $d = self::trfDataset($pdo, $f);
        $rows = self::sortRows(self::trfSearch($d['transfers'], (string) ($f['gq'] ?? '')), $f, ['value' => 'value', 'number' => 'id', 'from' => 'from', 'to' => 'to', 'date' => 'created_at']);
        return self::page($rows, $f) + ['columns' => self::columns(self::TRF_COLUMNS), 'footer' => self::trfFooter($rows)];
    }

    public static function trfDetail(PDO $pdo, int $id, ?int $scopeWarehouseId): array
    {
        $d = self::trfDataset($pdo, ['transfer_id' => $id, 'warehouse_id' => $scopeWarehouseId, 'start_date' => '1970-01-01', 'end_date' => '2999-12-31'], true);
        if (!$d['transfers']) {
            throw new NotFoundException('transfer not found');
        }
        $t = $d['transfers'][0];
        $layers = self::fifoLayers($pdo, array_values(array_unique(array_filter(array_column($t['layer_lines'], 'out_line_id')))));
        $byLine = [];
        foreach ($layers as $a) {
            $byLine[$a['line_id']][] = $a;
        }
        $items = [];
        foreach ($t['items_detail'] as $it) {
            $it['layer_rows'] = [];
            foreach ($it['out_line_ids'] as $lid) {
                foreach ($byLine[$lid] ?? [] as $a) {
                    $it['layer_rows'][] = $a;
                }
            }
            $items[] = $it;
        }
        return ['transfer' => array_diff_key($t, ['layer_lines' => 1, 'items_detail' => 1]), 'lines' => $items, 'columns' => self::columns(self::TRF_LINE_COLUMNS), 'layer_columns' => self::columns(self::TRF_LAYER_COLUMNS),
            'notes' => ['Cost layer dipertahankan: nilai TRANSFER_OUT = nilai TRANSFER_IN. Penerimaan hanya penuh (tanpa parsial), sehingga Qty Diterima = Qty Transfer untuk status RECEIVED dan kosong selama PENDING.']];
    }

    public static function trfExport(PDO $pdo, array $f, array $meta): array
    {
        $d = self::trfDataset($pdo, $f, true);
        $k = self::trfKpi($d);
        $n = $k['nominal'];
        $rows = self::trfSearch($d['transfers'], (string) ($f['gq'] ?? ''));
        $tt = self::table(self::TRF_COLUMNS, $rows);
        $tt['rows'][] = self::totalRow(self::TRF_COLUMNS, self::trfFooter($rows)['totals'], 'TOTAL');
        $items = [];
        $layerRows = [];
        foreach ($rows as $t) {
            foreach ($t['items_detail'] as $it) {
                if ($it['matched']) {
                    $items[] = $it + ['number' => $t['number'], 'from' => $t['from'], 'to' => $t['to'], 'created_date' => $t['created_date']];
                }
            }
        }
        $cols = array_merge([['number', 'No. Transfer', 'text', true], ['from', 'Gudang Asal', 'text', true], ['to', 'Gudang Tujuan', 'text', true]], self::TRF_LINE_COLUMNS);
        $it = self::table($cols, $items);
        $it['rows'][] = self::totalRow($cols, ['value' => round(array_sum(array_map(static fn ($r) => $r['counted'] ? (float) $r['value'] : 0.0, $items)), 4)], 'TOTAL (aktif)');
        $outIds = [];
        $meta2 = [];
        foreach ($rows as $t) {
            foreach ($t['items_detail'] as $itd) {
                if ($itd['matched']) {
                    foreach ($itd['out_line_ids'] as $lid) {
                        $outIds[$lid] = true;
                        $meta2[$lid] = ['number' => $t['number'], 'sku' => $itd['sku'], 'name' => $itd['name'], 'received' => $t['status'] === 'RECEIVED' ? 'Ya' : ($t['status'] === 'PENDING' ? 'Belum (PENDING)' : $t['status'])];
                    }
                }
            }
        }
        foreach (self::fifoLayers($pdo, array_keys($outIds)) as $a) {
            $layerRows[] = $a + $meta2[$a['line_id']];
        }
        $lt = self::table(self::TRF_LAYER_COLUMNS, $layerRows);
        $lt['rows'][] = self::totalRow(self::TRF_LAYER_COLUMNS, ['value' => round(array_sum(array_column($layerRows, 'value')), 4)], 'TOTAL');
        $sum = [];
        foreach ($meta as $key => $val) {
            $sum[] = [$key, $val];
        }
        $sum[] = ['', ''];
        foreach ([['Total Transfer (aktif)', $n['transfers']], ['Pending', $n['pending']], ['Diterima', $n['received']], ['SKU dipindahkan', $n['skus']], ['Nilai Cost (aktif)', self::cell('money', $n['value'])],
            ['Nilai Cost dalam perjalanan (PENDING)', self::cell('money', $n['in_transit_value'])], ['Rata-rata Lead Time (jam)', $n['avg_lead_hours'] ?? '—'],
            ['Dibatalkan / Di-reverse (tidak dihitung)', $d['disclosures']['inactive']['transfers']]] as $r) {
            $sum[] = [$r[0], $r[1]];
        }
        foreach ($k['qty_by_unit'] as $u) {
            $sum[] = ["Qty dipindahkan — {$u['unit']}", $u['qty']];
        }
        return ['Ringkasan Transfer' => ['headers' => ['Keterangan', 'Nilai'], 'rows' => $sum], 'Daftar Transfer' => $tt + ['freeze_header' => true, 'autofilter' => true],
            'Rincian Item Transfer' => $it + ['freeze_header' => true, 'autofilter' => true], 'Layer Cost Transfer' => $lt + ['freeze_header' => true, 'autofilter' => true]];
    }

    private static function trfDataset(PDO $pdo, array $f, bool $withDetail = false): array
    {
        if (empty($f['transfer_id'])) {
            self::assertPeriod($f);
        }
        $where = ['1=1'];
        $bind = [];
        if (!empty($f['transfer_id'])) {
            $where[] = 'wt.id = :tid';
            $bind['tid'] = (int) $f['transfer_id'];
        } else {
            $where[] = 'DATE(wt.ship_date) >= :d1';
            $bind['d1'] = $f['start_date'];
            $where[] = 'DATE(wt.ship_date) <= :d2';
            $bind['d2'] = $f['end_date'];
        }
        if (!empty($f['warehouse_id'])) {
            $where[] = '(wt.from_warehouse_id = :w1 OR wt.to_warehouse_id = :w2)';
            $bind['w1'] = (int) $f['warehouse_id'];
            $bind['w2'] = (int) $f['warehouse_id'];
        }
        if (!empty($f['from_warehouse_id'])) { $where[] = 'wt.from_warehouse_id = :fw'; $bind['fw'] = (int) $f['from_warehouse_id']; }
        if (!empty($f['to_warehouse_id'])) { $where[] = 'wt.to_warehouse_id = :tw'; $bind['tw'] = (int) $f['to_warehouse_id']; }
        if (in_array((string) ($f['status'] ?? ''), ['PENDING', 'RECEIVED', 'CANCELLED', 'REVERSED'], true)) { $where[] = 'wt.status = :st'; $bind['st'] = $f['status']; }
        $st = $pdo->prepare(
            "SELECT wt.id, wt.status, wt.ship_date, wt.receive_date, wt.created_at, wt.cancel_reason, wt.cancelled_at, wt.reverse_reason, wt.reversed_at,
                    fw.id AS fw_id, fw.name AS fw_name, tw.id AS tw_id, tw.name AS tw_name, cu.username AS created_by, ru.username AS received_by, cau.username AS cancelled_by, rvu.username AS reversed_by
               FROM warehouse_transfers wt
               JOIN warehouses fw ON fw.id = wt.from_warehouse_id JOIN warehouses tw ON tw.id = wt.to_warehouse_id
               JOIN users cu ON cu.id = wt.created_by LEFT JOIN users ru ON ru.id = wt.received_by
               LEFT JOIN users cau ON cau.id = wt.cancelled_by LEFT JOIN users rvu ON rvu.id = wt.reversed_by
              WHERE " . implode(' AND ', $where) . ' ORDER BY wt.created_at DESC, wt.id DESC'
        );
        $st->execute($bind);
        $heads = $st->fetchAll();
        $byId = [];
        foreach ($heads as $h) {
            $byId[(int) $h['id']] = $h;
        }
        $lines = [];
        foreach (array_chunk(array_keys($byId), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $ls = $pdo->prepare(
                "SELECT wtl.id, wtl.transfer_id, wtl.item_id, wtl.qty_base, wtl.unit_cost_base, wtl.out_transaction_line_id, wtl.in_transaction_line_id, i.sku, i.name, i.category_id, c.name AS category_name, bu.code AS base_unit,
                        ol.subtotal AS out_value, il.subtotal AS in_value, il.base_qty AS in_qty, ot.created_at AS out_created_at, ouu.username AS out_created_by
                   FROM warehouse_transfer_lines wtl
                   JOIN items i ON i.id = wtl.item_id LEFT JOIN categories c ON c.id = i.category_id JOIN units bu ON bu.id = i.base_unit_id
                   LEFT JOIN inventory_transaction_lines ol ON ol.id = wtl.out_transaction_line_id
                   LEFT JOIN inventory_transactions ot ON ot.id = ol.transaction_id LEFT JOIN users ouu ON ouu.id = ot.created_by
                   LEFT JOIN inventory_transaction_lines il ON il.id = wtl.in_transaction_line_id
                  WHERE wtl.transfer_id IN ({$ph}) ORDER BY wtl.transfer_id, wtl.item_id, wtl.id"
            );
            $ls->execute($chunk);
            foreach ($ls->fetchAll() as $r) {
                $lines[(int) $r['transfer_id']][] = $r;
            }
        }
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $hasItem = !empty($f['category_id']) || !empty($f['item_id']) || $q !== '';
        $transfers = [];
        $inactive = ['transfers' => 0, 'value' => 0.0];
        foreach ($heads as $h) {
            $id = (int) $h['id'];
            $active = in_array($h['status'], ['PENDING', 'RECEIVED'], true);
            $ls = $lines[$id] ?? [];
            $items = [];
            foreach ($ls as $l) {
                $key = (int) $l['item_id'];
                $match = (empty($f['category_id']) || (int) $l['category_id'] === (int) $f['category_id']) && (empty($f['item_id']) || (int) $l['item_id'] === (int) $f['item_id'])
                    && ($q === '' || str_contains(mb_strtolower($l['sku'] . ' ' . $l['name']), $q));
                $items[$key] ??= ['item_id' => $key, 'sku' => $l['sku'], 'name' => $l['name'], 'category' => $l['category_name'] ?? '(Tanpa Kategori)', 'unit' => $l['base_unit'], 'base_unit' => $l['base_unit'],
                    'qty' => 0.0, 'value' => 0.0, 'in_qty' => 0.0, 'in_value' => 0.0, 'out_value' => 0.0, 'layers' => 0, 'out_line_ids' => [], 'matched' => $match, 'counted' => $active, 'status' => $h['status']];
                $it = &$items[$key];
                $it['qty'] += (float) $l['qty_base'];
                $it['value'] += (float) $l['qty_base'] * (float) $l['unit_cost_base'];
                $it['layers']++;
                $it['out_value'] += (float) ($l['out_value'] ?? 0);
                if ($l['in_transaction_line_id'] !== null) {
                    $it['in_qty'] += (float) $l['in_qty'];
                    $it['in_value'] += (float) $l['in_value'];
                }
                if ($l['out_transaction_line_id'] !== null && !in_array((int) $l['out_transaction_line_id'], $it['out_line_ids'], true)) {
                    $it['out_line_ids'][] = (int) $l['out_transaction_line_id'];
                }
                unset($it);
            }
            $detail = [];
            foreach ($items as $it) {
                $received = $h['status'] === 'RECEIVED' ? round($it['in_qty'], 6) : null;
                $detail[] = [
                    'item_id' => $it['item_id'], 'sku' => $it['sku'], 'name' => $it['name'], 'category' => $it['category'], 'unit' => $it['unit'], 'base_unit' => $it['base_unit'], 'qty' => round($it['qty'], 6),
                    'qty_received' => $received, 'diff' => $received !== null ? round($received - $it['qty'], 6) : null, 'unit_cost' => $it['qty'] > 0 ? round($it['value'] / $it['qty'], 4) : null,
                    'value' => round($it['value'], 4), 'layers' => $it['layers'], 'out_line_ids' => $it['out_line_ids'], 'out_value' => round($it['out_value'], 4), 'in_value' => $h['status'] === 'RECEIVED' ? round($it['in_value'], 4) : null,
                    'matched' => $it['matched'], 'counted' => $it['counted'], 'status' => $it['status'],
                ];
            }
            $matched = array_values(array_filter($detail, static fn ($x) => $x['matched']));
            if ($hasItem && !$matched) {
                continue;
            }
            $value = round(array_sum(array_column($matched, 'value')), 4);
            $first = $ls[0] ?? null;
            $dispAt = $first['out_created_at'] ?? $h['created_at'];
            $recv = $h['receive_date'];
            $leadH = null;
            if ($h['status'] === 'RECEIVED' && $recv !== null) {
                $diff = (strtotime((string) $recv) - strtotime((string) $dispAt)) / 3600;
                $leadH = $diff >= 0 ? round($diff, 2) : null;
            }
            if (!$active) {
                $inactive['transfers']++;
                $inactive['value'] += $value;
            }
            $transfers[] = [
                'id' => $id, 'number' => 'TRF-' . $id, 'status' => $h['status'], 'active' => $active, 'created_at' => $h['created_at'], 'created_date' => substr((string) $h['created_at'], 0, 10), 'created_time' => substr((string) $h['created_at'], 11, 5),
                'ship_date' => substr((string) $h['ship_date'], 0, 10), 'from' => $h['fw_name'], 'from_id' => (int) $h['fw_id'], 'to' => $h['tw_name'], 'to_id' => (int) $h['tw_id'],
                'items' => count($matched), 'skus' => count($matched), 'qty_text' => self::qtyText($matched, 'qty', 'base_unit'), 'value' => $value, 'created_by' => $h['created_by'],
                'dispatched_by' => $first['out_created_by'] ?? $h['created_by'], 'dispatched_at' => $dispAt, 'received_by' => $h['received_by'], 'received_at' => $recv, 'lead_hours' => $leadH,
                'lead_time' => $leadH === null ? null : ($leadH >= 24 ? str_replace('.', ',', (string) round($leadH / 24, 1)) . ' hari' : str_replace('.', ',', (string) round($leadH, 1)) . ' jam'),
                'notes' => $h['status'] === 'CANCELLED' ? $h['cancel_reason'] : ($h['status'] === 'REVERSED' ? $h['reverse_reason'] : null),
                'cancel' => $h['status'] === 'CANCELLED' ? ['reason' => $h['cancel_reason'], 'at' => $h['cancelled_at'], 'by' => $h['cancelled_by']] : null,
                'reverse' => $h['status'] === 'REVERSED' ? ['reason' => $h['reverse_reason'], 'at' => $h['reversed_at'], 'by' => $h['reversed_by']] : null,
                'items_detail' => $detail, 'layer_lines' => array_map(static fn ($l) => ['out_line_id' => $l['out_transaction_line_id']], $ls),
                'out_value' => round(array_sum(array_column($matched, 'out_value')), 4), 'in_value' => $h['status'] === 'RECEIVED' ? round(array_sum(array_map(static fn ($x) => (float) $x['in_value'], $matched)), 4) : null,
                'partial' => count($matched) < count($detail),
            ];
        }
        return ['transfers' => $transfers, 'disclosures' => ['inactive' => ['transfers' => $inactive['transfers'], 'value' => round($inactive['value'], 4)]]];
    }

    private static function trfKpi(array $d): array
    {
        $n = ['transfers' => 0, 'pending' => 0, 'received' => 0, 'value' => 0.0, 'in_transit_value' => 0.0, 'received_value' => 0.0];
        $skus = [];
        $qty = [];
        $lead = [];
        foreach ($d['transfers'] as $t) {
            if (!$t['active']) {
                continue;
            }
            $n['transfers']++;
            $n[$t['status'] === 'PENDING' ? 'pending' : 'received']++;
            $n['value'] += (float) $t['value'];
            $n[$t['status'] === 'PENDING' ? 'in_transit_value' : 'received_value'] += (float) $t['value'];
            foreach ($t['items_detail'] as $it) {
                if ($it['matched']) {
                    $skus[$it['item_id']] = true;
                    $qty[(string) $it['base_unit']] = ($qty[(string) $it['base_unit']] ?? 0.0) + (float) $it['qty'];
                }
            }
            if ($t['lead_hours'] !== null) {
                $lead[] = (float) $t['lead_hours'];
            }
        }
        foreach (['value', 'in_transit_value', 'received_value'] as $k) {
            $n[$k] = round($n[$k], 4);
        }
        $n['skus'] = count($skus);
        $n['avg_lead_hours'] = $lead ? round(array_sum($lead) / count($lead), 2) : null;
        $n['lead_samples'] = count($lead);
        ksort($qty);
        return ['nominal' => $n, 'qty_by_unit' => array_map(static fn ($u, $q) => ['unit' => $u, 'qty' => round($q, 6)], array_keys($qty), $qty)];
    }

    private static function trfTrend(array $d, string $start, string $end, string $bucket): array
    {
        $b = self::axis($start, $end, $bucket, ['value' => 0.0, 'count' => 0]);
        $label = self::labeler($bucket);
        foreach ($d['transfers'] as $t) {
            $k = $label($t['ship_date']);
            if (!$t['active'] || !isset($b[$k])) {
                continue;
            }
            $b[$k]['count']++;
            $b[$k]['value'] += (float) $t['value'];
        }
        return array_values(array_map(static fn ($r) => ['value' => round($r['value'], 4)] + $r, $b));
    }

    private static function trfSearch(array $rows, string $q): array
    {
        return self::search($rows, $q, static function (array $t): array {
            $h = [$t['number'], $t['created_date'], (string) $t['from'], (string) $t['to'], (string) $t['created_by'], (string) $t['received_by'], $t['status']];
            foreach ($t['items_detail'] as $it) {
                $h[] = $it['sku'];
                $h[] = $it['name'];
            }
            return $h;
        });
    }

    private static function trfFooter(array $rows): array
    {
        $v = 0.0;
        $items = 0;
        foreach ($rows as $r) {
            if ($r['active']) {
                $v += (float) $r['value'];
                $items += $r['items'];
            }
        }
        return ['totals' => ['items' => $items, 'value' => round($v, 4)], 'count' => count($rows)];
    }

    // ======================================================================
    // reconciliation (read-only; used by the CLI and the tests)
    // ======================================================================

    /**
     * Independent re-derivation of the three tabs from the ledger. Every check returns [name, ok, detail]. Read-only.
     * @return list<array{0:string,1:bool,2:string}>
     */
    public static function reconcile(PDO $pdo, array $f): array
    {
        $checks = [];
        $add = static function (string $n, bool $ok, string $d) use (&$checks): void { $checks[] = [$n, $ok, $d]; };

        // IN: stored invoice_total == derived grand total per V2 invoice; screen == export footer
        $in = self::inDataset($pdo, $f);
        $bad = 0;
        $v2inv = 0;
        foreach ($in['invoices'] as $i) {
            if (!$i['components_complete'] || $i['items'] === 0) {
                continue;
            }
            $v2inv++;
            $derived = round((float) $i['subtotal'] + (float) $i['ppn'] - (float) $i['invoice_discount'] + (float) $i['freight'], 4);
            if (abs($derived - (float) $i['total']) > 0.02) {
                $bad++;
            }
        }
        $add('IN1 Grand Total = Subtotal + PPN − Diskon Invoice + Ongkir (per invoice V2)', $bad === 0, "{$v2inv} invoice V2, {$bad} selisih");
        $kin = self::inKpi($in);
        $ft = self::inFooter(self::inSearch($in['invoices'], ''));
        $add('IN2 KPI Total Nilai Masuk = jumlah tabel', abs($kin['nominal']['total'] - $ft['totals']['total']) <= 0.01, "KPI {$kin['nominal']['total']} / tabel {$ft['totals']['total']}");

        // OUT: FIFO qty + value per counted line; invoice arithmetic; KPI == table == export
        $out = self::outDataset($pdo, $f);
        $qtyBad = 0;
        $valBad = 0;
        $lineN = 0;
        $invBad = 0;
        $invN = 0;
        $shipBad = 0;
        foreach ($out['documents'] as $doc) {
            foreach ($doc['lines'] as $l) {
                if (!$l['counted']) {
                    continue;
                }
                $lineN++;
                if (abs($l['alloc_qty'] - $l['base_qty']) > 0.000001) { $qtyBad++; }
                if (abs($l['alloc_value'] - $l['hpp']) > 0.01) { $valBad++; }
            }
            if ($doc['source'] === 'V2' && $doc['invoice_status'] === 'ISSUED' && !$doc['partial'] && $doc['items'] > 0 && $doc['items'] === $doc['lines_total']) {
                $invN++;
                $first = array_values(array_filter($doc['lines'], static fn ($x) => $x['counted']))[0];
                if (abs((float) $doc['sell'] - (float) $first['inv_subtotal']) > 0.01 || abs((float) $doc['grand_total'] - (float) $first['inv_grand']) > 0.01) { $invBad++; }
                if (abs((float) $doc['shipping'] - (float) $first['inv_shipping']) > 0.0001) { $shipBad++; }
            }
        }
        $add('OUT1 Σ qty alokasi FIFO = qty OUT (per baris)', $qtyBad === 0, "{$lineN} baris, {$qtyBad} selisih");
        $add('OUT2 Σ nilai alokasi FIFO = HPP tersimpan (per baris)', $valBad === 0, "{$lineN} baris, {$valBad} selisih");
        $add('OUT3 Σ Nilai Jual = subtotal invoice, + ongkir = grand total invoice', $invBad === 0, "{$invN} invoice, {$invBad} selisih");
        $add('OUT4 Σ alokasi ongkir baris = ongkir header invoice', $shipBad === 0, "{$invN} invoice, {$shipBad} selisih");
        $ko = self::outKpi($out);
        $fo = self::outFooter($out['documents']);
        $add('OUT5 KPI HPP/Nilai Jual = jumlah tabel', abs($ko['nominal']['hpp'] - $fo['totals']['hpp']) <= 0.01 && abs($ko['nominal']['sell'] - $fo['totals']['sell']) <= 0.01,
            "HPP {$ko['nominal']['hpp']}/{$fo['totals']['hpp']}, jual {$ko['nominal']['sell']}/{$fo['totals']['sell']}");
        $add('OUT6 Margin = Nilai Jual − HPP (baris ber-invoice)', abs($ko['nominal']['margin'] - ($ko['nominal']['sell'] - ($ko['nominal']['hpp'] - $ko['nominal']['hpp_without_sell']))) <= 0.02, "margin {$ko['nominal']['margin']}");

        // TRANSFER: value/qty preserved out -> in, in-transit = pending, company-wide net zero
        $tr = self::trfDataset($pdo, $f, true);
        $valBad = 0;
        $qtyBad = 0;
        $recvN = 0;
        $ledgerBad = 0;
        $outSum = 0.0;
        $recvSum = 0.0;
        $pendSum = 0.0;
        foreach ($tr['transfers'] as $t) {
            if (!$t['active']) {
                continue;
            }
            $outSum += (float) $t['out_value'];
            if ($t['status'] === 'RECEIVED') {
                $recvN++;
                $recvSum += (float) $t['in_value'];
                if (abs((float) $t['out_value'] - (float) $t['in_value']) > 0.01) { $valBad++; }
                foreach ($t['items_detail'] as $it) {
                    if ($it['matched'] && abs((float) $it['diff']) > 0.000001) { $qtyBad++; }
                }
            } else {
                $pendSum += (float) $t['out_value'];
            }
            if (abs((float) $t['out_value'] - (float) $t['value']) > 0.01 * max(1, count($t['items_detail']))) { $ledgerBad++; }
        }
        $add('TRF1 Nilai TRANSFER_OUT = nilai TRANSFER_IN (RECEIVED)', $valBad === 0, "{$recvN} transfer, {$valBad} selisih");
        $add('TRF2 Qty diterima = qty transfer (RECEIVED)', $qtyBad === 0, "{$recvN} transfer, {$qtyBad} selisih");
        $add('TRF3 Nilai baris transfer = nilai ledger TRANSFER_OUT', $ledgerBad === 0, "{$ledgerBad} selisih");
        $add('TRF4 Net nol: OUT = IN diterima + dalam perjalanan', abs($outSum - $recvSum - $pendSum) <= 0.02 * max(1, count($tr['transfers'])), 'out ' . round($outSum, 4) . ' = in ' . round($recvSum, 4) . ' + transit ' . round($pendSum, 4));
        $kt = self::trfKpi($tr);
        $add('TRF5 Nilai Cost KPI = jumlah tabel', abs($kt['nominal']['value'] - self::trfFooter($tr['transfers'])['totals']['value']) <= 0.01, "KPI {$kt['nominal']['value']}");
        return $checks;
    }

    // ======================================================================
    // shared helpers
    // ======================================================================

    private static function assertPeriod(array $f): void
    {
        $start = (string) ($f['start_date'] ?? '');
        $end = (string) ($f['end_date'] ?? '');
        if (strtotime($start) === false || strtotime($end) === false || $start > $end) {
            throw new ValidationException(['start_date and end_date are required and start_date must not be after end_date']);
        }
        if ((strtotime($end) - strtotime($start)) / 86400 > self::MAX_DAYS) {
            throw new ValidationException(['the period is limited to ' . self::MAX_DAYS . ' days']);
        }
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private static function itemWhere(array $f, string $i, string $l): array
    {
        $w = [];
        $b = [];
        if (!empty($f['category_id'])) { $w[] = "{$i}.category_id = :cat"; $b['cat'] = (int) $f['category_id']; }
        if (!empty($f['item_id'])) { $w[] = "{$l}.item_id = :iid"; $b['iid'] = (int) $f['item_id']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = "({$i}.sku LIKE :q1 OR {$i}.name LIKE :q2)";
            $b['q1'] = $like;
            $b['q2'] = $like;
        }
        return [$w, $b];
    }

    private static function bucket(array $f): string
    {
        return in_array($f['bucket'] ?? 'day', ['day', 'week', 'month'], true) ? (string) ($f['bucket'] ?? 'day') : 'day';
    }

    /** The equally long period immediately before the selected one; null when it would leave the supported range. */
    private static function previousPeriod(array $f): ?array
    {
        $days = (int) round((strtotime((string) $f['end_date']) - strtotime((string) $f['start_date'])) / 86400) + 1;
        $pe = date('Y-m-d', strtotime('-1 day', strtotime((string) $f['start_date'])));
        $ps = date('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($pe)));
        return ['start_date' => $ps, 'end_date' => $pe] + $f;
    }

    /** @return array<string,array{value:float|int|null,previous:float|int|null,pct:?float}> % change only when the previous value is a positive number */
    private static function deltas(array $cur, array $prev, array $keys): array
    {
        $o = [];
        foreach ($keys as $k) {
            $c = $cur[$k] ?? null;
            $p = $prev[$k] ?? null;
            $o[$k] = ['value' => $c, 'previous' => $p, 'pct' => (is_numeric($c) && is_numeric($p) && (float) $p > 0) ? round(((float) $c - (float) $p) * 100 / (float) $p, 1) : null];
        }
        return $o;
    }

    private static function labeler(string $bucket): \Closure
    {
        return static function (string $date) use ($bucket): string {
            if ($bucket === 'month') {
                return substr($date, 0, 7);
            }
            if ($bucket === 'week') {
                $ts = strtotime($date);
                return date('Y-m-d', strtotime('-' . ((int) date('N', $ts) - 1) . ' days', $ts));
            }
            return $date;
        };
    }

    /** Continuous axis: a bucket without activity is a KNOWN zero (the period was queried). @return array<string,array<string,mixed>> */
    private static function axis(string $start, string $end, string $bucket, array $zero): array
    {
        $label = self::labeler($bucket);
        $b = [];
        for ($cur = strtotime($start), $last = strtotime($end); $cur <= $last; $cur = strtotime('+1 day', $cur)) {
            $k = $label(date('Y-m-d', $cur));
            $b[$k] ??= ['bucket' => $k] + $zero;
        }
        return $b;
    }

    private static function search(array $rows, string $q, \Closure $hay): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $r) => str_contains(mb_strtolower(implode(' ', $hay($r))), $q)));
    }

    /** @param array<string,string> $keys sort key => row field */
    private static function sortRows(array $rows, array $f, array $keys): array
    {
        $field = $keys[(string) ($f['sort'] ?? '')] ?? null;
        if ($field === null) {
            return $rows;
        }
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($rows, static function ($a, $b) use ($field, $dir) {
            $x = $a[$field] ?? null;
            $y = $b[$field] ?? null;
            return $dir * (is_numeric($x) && is_numeric($y) ? (float) $x <=> (float) $y : mb_strtolower((string) $x) <=> mb_strtolower((string) $y));
        });
        return $rows;
    }

    private static function page(array $rows, array $f): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        $per = (int) ($f['per_page'] ?? 25);
        $per = $per >= 1 ? min($per, 100) : 25;
        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'pagination' => ['page' => $page, 'per_page' => $per, 'total' => count($rows), 'total_pages' => max(1, (int) ceil(count($rows) / $per))]];
    }

    private static function allTrue(array $rows, \Closure $fn): bool
    {
        foreach ($rows as $r) {
            if (!$fn($r)) {
                return false;
            }
        }
        return true;
    }

    /** "KG 5 · PCS 10" — quantities grouped BY unit (never one mixed total). */
    private static function qtyText(array $rows, string $qtyKey = 'base_qty', string $unitKey = 'base_unit'): ?string
    {
        $by = [];
        foreach ($rows as $l) {
            $by[(string) $l[$unitKey]] = ($by[(string) $l[$unitKey]] ?? 0.0) + (float) $l[$qtyKey];
        }
        if (!$by) {
            return null;
        }
        ksort($by);
        return implode(' · ', array_map(static fn ($u, $q) => $u . ' ' . rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'), array_keys($by), $by));
    }

    private static function columns(array $cols): array
    {
        return array_map(static fn (array $c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2], 'default' => $c[3]], $cols);
    }

    public static function cell(string $type, mixed $v): int|float|string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if (is_int($v) || is_float($v)) {
            return $type === 'pct' ? round((float) $v, 2) : $v;
        }
        return ExcelWriterService::sanitizeCellText((string) $v);
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function table(array $cols, array $rows): array
    {
        return ['headers' => array_map(static fn (array $c) => $c[1], $cols),
            'rows' => array_map(static fn (array $r) => array_map(static fn (array $c) => self::cell($c[2], $r[$c[0]] ?? null), $cols), $rows)];
    }

    private static function totalRow(array $cols, array $totals, string $label): array
    {
        $row = [];
        foreach ($cols as $i => $c) {
            if ($i === 0) {
                $row[] = $label;
            } elseif (array_key_exists($c[0], $totals) && in_array($c[2], ['money', 'int', 'qty'], true)) {
                $row[] = $totals[$c[0]];
            } else {
                $row[] = '';
            }
        }
        return $row;
    }

    private static function filterEcho(array $f): array
    {
        return array_intersect_key($f, array_flip(['start_date', 'end_date', 'warehouse_id', 'supplier_id', 'category_id', 'item_id', 'q', 'bakery_destination_id', 'division_id', 'from_warehouse_id', 'to_warehouse_id', 'status', 'in_source', 'out_source']));
    }
}
