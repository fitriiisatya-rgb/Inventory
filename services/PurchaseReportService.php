<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * "Laporan Pembelian" (redesign) — READ-ONLY purchasing analysis over REAL external purchases (Stock IN), at invoice level and item level.
 * Never writes. It does not invent a second accounting rule: every figure is a stored column of the Stock IN V2 posting
 * (purchase_invoice_headers + purchase_line_costs, written by PurchaseInvoiceService / PurchaseCostingGateway) or a value derived from them by the
 * exact V2 definitions. Reconciliation (scripts/purchase_reconcile_check.php) re-derives them independently.
 *
 * INVOICE = the set of inventory_transactions one Stock IN V2 entry posted (one transaction per item row; they share the request uuid
 * "<uuid>:IN:<line>"). A transaction without that pattern is grouped by (reference_no, supplier, warehouse, date) when it has a reference, else it is its own
 * "invoice". No invoice number is ever fabricated: no reference_no -> shown as "—".
 *
 * INCLUSION: transaction_type = 'IN' only (never TRANSFER_IN / OPENING / PRODUCTION / ADJUSTMENT / OPNAME / REVERSAL). POSTED lines are counted; VOID lines are LISTED
 * (status VOID, struck through) but excluded from every total and disclosed ("Invoice void — tidak dihitung") — the history is never silently deleted.
 * Historical imports (is_historical_import = 1, reporting only) are excluded unless explicitly requested (historical = '1' only them, 'all' both) and are always
 * labelled source "Historis".
 *
 * STOCK IN V2 ARITHMETIC (PurchaseInvoiceService docblock) — per item row i:
 *     gross_i   = qty_i x price_i                      (purchase_line_costs.gross_amount)
 *     itemDisc_i                                        (line_discount_amount)
 *     dpp_i     = gross_i - itemDisc_i                  (net_after_line_discount)        "Subtotal Barang"
 *     ppn_i     = dpp_i x rate_i                        (rate = purchase_invoice_headers.ppn_rate — never hard-coded; 0 / 11 / custom)
 *     total_i   = dpp_i + ppn_i                         the sheet's row "Total"
 *   The invoice discount is defined on the PPN-INCLUSIVE subtotal and is split across rows in proportion to total_i (PurchaseCostingService::
 *   allocateProportionally), then re-expressed on each row's DPP (share / (1 + rate)): stored as purchase_line_costs.invoice_discount_allocated
 *   (DPP basis). The row's payable is therefore  total_i - A_i  with  A_i = dpp_i + ppn_i - netDpp_i - ppnFinal_i  (the "Diskon Invoice" as the operator
 *   entered it, PPN-inclusive). Shipping is split in proportion to each row's net DPP (header.freight_amount of the row = its share).
 *       Grand Total = Subtotal Barang + PPN - Diskon Invoice + Ongkos Kirim            (== purchase_invoice_headers.invoice_total, summed)
 *   Screen / export columns use this operator (sheet) basis: PPN = PPN on the DPP before the invoice discount; Diskon Invoice = PPN-inclusive effective share.
 *   The DPP-basis stored allocation and the PPN after the invoice discount are exposed too (drawer + export) so both views reconcile.
 *   This is INVOICE FINANCIALS, not FIFO inventory cost (final_inventory_cost is shown in the drawer only, labelled as such).
 *
 * LEGACY / HISTORICAL purchases (no purchase_invoice_headers row): the recorded line value (FIFO subtotal) is the only known amount, so it is the row total;
 * Gross / discounts / PPN / freight are unknown and stay null ("—") — never a fabricated 0 — and are excluded from component sums. The unit price of such a line is
 * its recorded unit_price_input (the price entered at posting).
 *
 * HARGA BELI = the transaction's own gross unit price (purchase_line_costs.gross_unit_price_input; legacy: unit_price_input), per INPUT unit — never the
 * latest master / item_price_history price. Item breakdown groups by (item, input unit), so different purchase units are never mixed, and its weighted average
 * price is  sum(gross) / sum(qty)  (== sum(qty x price) / sum(qty)).
 *
 * Quantities are never added across units: totals are grouped BY (base) UNIT.
 */
final class PurchaseReportService
{
    public const MAX_DAYS = 800;
    private const EPS = 0.0001;

    /** Column catalogues — the UI renders from these and the exports reuse them (export == screen by construction). [key, label, type, default-visible] */
    public const INVOICE_COLUMNS = [
        ['date', 'Tanggal', 'date', true], ['created_at', 'Timestamp', 'ts', true], ['reference', 'No. Invoice / Referensi', 'text', true], ['supplier', 'Supplier', 'text', true],
        ['warehouse', 'Gudang', 'text', true], ['items', 'Jumlah Item', 'int', true], ['skus', 'Jumlah SKU', 'int', true],
        ['subtotal', 'Subtotal Barang', 'money', true], ['item_discount', 'Diskon Barang', 'money', true], ['invoice_discount', 'Diskon Invoice', 'money', true],
        ['ppn', 'PPN', 'money', true], ['freight', 'Ongkos Kirim', 'money', true], ['total', 'Total Pembelian', 'money', true],
        ['created_by', 'Dibuat Oleh', 'text', true], ['status', 'Status', 'status', true],
        ['gross', 'Gross Barang', 'money', false], ['qty_text', 'Qty (satuan dasar)', 'text', false], ['source', 'Sumber', 'text', false], ['ppn_rate', 'Tarif PPN', 'text', false], ['freight_treatment', 'Perlakuan Ongkir', 'text', false],
        ['ppn_net', 'PPN setelah Diskon Invoice', 'money', false], ['invoice_discount_dpp', 'Diskon Invoice (basis DPP)', 'money', false], ['inventory_cost', 'Nilai Persediaan (FIFO)', 'money', false],
    ];
    public const ITEM_COLUMNS = [
        ['sku', 'Kode', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan', 'text', true],
        ['qty', 'Qty Beli', 'qty', true], ['frequency', 'Frekuensi Pembelian', 'int', true], ['price_min', 'Harga Beli Minimum', 'money', true], ['price_max', 'Harga Beli Maksimum', 'money', true],
        ['price_avg', 'Harga Beli Rata-rata (tertimbang)', 'money', true], ['gross', 'Gross', 'money', true], ['item_discount', 'Diskon Barang', 'money', true],
        ['invoice_discount', 'Alokasi Diskon Invoice', 'money', true], ['ppn', 'PPN', 'money', true], ['freight', 'Alokasi Ongkos Kirim', 'money', true], ['total', 'Total Pembelian', 'money', true],
        ['share_pct', '% terhadap Total', 'pct', true], ['base_qty', 'Qty (satuan dasar)', 'qty', false], ['base_unit', 'Satuan Dasar', 'text', false], ['subtotal', 'Subtotal Barang', 'money', false],
    ];
    /** Line level (one row per item per invoice) — export sheet "Baris Invoice-Barang" and the drawer. */
    public const LINE_COLUMNS = [
        ['date', 'Tanggal', 'date', true], ['reference', 'No. Invoice / Referensi', 'text', true], ['supplier', 'Supplier', 'text', true], ['warehouse', 'Gudang', 'text', true],
        ['sku', 'Kode Barang', 'text', true], ['name', 'Nama Barang', 'text', true], ['category', 'Kategori', 'text', true], ['unit', 'Satuan', 'text', true], ['qty', 'Qty', 'qty', true],
        ['price', 'Harga Beli', 'money', true], ['gross', 'Gross', 'money', true], ['item_discount', 'Diskon Item', 'money', true], ['dpp', 'DPP / Net Barang', 'money', true],
        ['ppn_rate', 'PPN %', 'rate', true], ['ppn', 'PPN Rp', 'money', true], ['invoice_discount', 'Alokasi Diskon Invoice', 'money', true], ['freight', 'Alokasi Ongkos Kirim', 'money', true],
        ['total', 'Total Baris', 'money', true], ['status', 'Status', 'status', true], ['source', 'Sumber', 'text', false], ['ppn_treatment', 'Perlakuan PPN', 'text', false],
        ['freight_treatment', 'Perlakuan Ongkir', 'text', false], ['inventory_cost', 'Nilai Persediaan (FIFO)', 'money', false],
    ];

    // ======================================================================
    // public API
    // ======================================================================

    /** KPI + trend + disclosures for the filter set. @param array<string,mixed> $f */
    public static function overview(PDO $pdo, array $f): array
    {
        $d = self::dataset($pdo, $f);
        $bucket = in_array($f['bucket'] ?? 'day', ['day', 'week', 'month'], true) ? (string) ($f['bucket'] ?? 'day') : 'day';
        return [
            'period' => ['start' => $f['start_date'], 'end' => $f['end_date'], 'bucket' => $bucket],
            'filters' => self::filterEcho($f),
            'kpi' => self::kpi($d),
            'trend' => self::trend($d, (string) $f['start_date'], (string) $f['end_date'], $bucket),
            'disclosures' => $d['disclosures'],
            'single_item' => $d['single_item'],
            'partial_by_item_filter' => $d['partial_filter'],
        ];
    }

    /** Invoice-level table. */
    public static function invoices(PDO $pdo, array $f): array
    {
        $d = self::dataset($pdo, $f);
        $rows = self::filterInvoices($d['invoices'], (string) ($f['inv_q'] ?? ''));
        $sort = (string) ($f['sort'] ?? 'date');
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 1 : -1;
        $keyFn = match ($sort) {
            'total' => static fn ($r) => (float) $r['total'], 'supplier' => static fn ($r) => mb_strtolower((string) $r['supplier']), 'reference' => static fn ($r) => mb_strtolower((string) $r['reference']),
            default => static fn ($r) => $r['transaction_at'] . '|' . $r['tx_ids'][0],
        };
        usort($rows, static fn ($a, $b) => $dir * ($keyFn($a) <=> $keyFn($b)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = in_array((int) ($f['per_page'] ?? 25), [10, 25, 50, 100], true) ? (int) $f['per_page'] : 25;
        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'columns' => self::columns(self::INVOICE_COLUMNS),
            'footer' => self::invoiceFooter($rows),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'total_pages' => max(1, (int) ceil(count($rows) / $perPage))],
        ];
    }

    /** "Rincian per Barang". */
    public static function items(PDO $pdo, array $f): array
    {
        $d = self::dataset($pdo, $f);
        $rows = self::itemRows($d);
        $q = mb_strtolower(trim((string) ($f['inv_q'] ?? '')));
        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = in_array((int) ($f['per_page'] ?? 25), [10, 25, 50, 100], true) ? (int) $f['per_page'] : 25;
        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'columns' => self::columns(self::ITEM_COLUMNS),
            'footer' => self::itemFooter($rows),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'total_pages' => max(1, (int) ceil(count($rows) / $perPage))],
            'query' => $q,
        ];
    }

    /** One invoice with ALL its lines (not only those matching an item filter) + the V2 arithmetic block. @param list<int> $txIds */
    public static function invoiceDetail(PDO $pdo, array $txIds): array
    {
        $lines = self::fetchLines($pdo, ['tx_ids' => $txIds, 'historical' => 'all'], false);
        if (!$lines) {
            throw new NotFoundException('purchase invoice not found');
        }
        $inv = self::groupLines(self::enrich($lines), [])['invoices'];
        $inv = array_values($inv)[0];
        $posted = array_values(array_filter($inv['lines'], static fn ($l) => $l['counted']));
        $v2 = array_values(array_filter($posted, static fn ($l) => $l['source'] === 'V2'));
        $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$k] ?? 0), $rows)), 4);
        $arith = null;
        if ($v2) {
            $gross = $sum($v2, 'gross');
            $itemDisc = $sum($v2, 'item_discount');
            $dpp = $sum($v2, 'dpp');
            $ppn = $sum($v2, 'ppn');
            $disc = $sum($v2, 'invoice_discount');
            $freight = $sum($v2, 'freight');
            $arith = [
                'gross' => $gross, 'item_discount' => $itemDisc, 'subtotal' => $dpp, 'ppn' => $ppn, 'invoice_discount' => $disc, 'freight' => $freight,
                'grand_total' => round($dpp + $ppn - $disc + $freight, 4), 'stored_grand_total' => $sum($v2, 'total'),
                'subtotal_incl_ppn' => round($dpp + $ppn, 4), 'ppn_net' => $sum($v2, 'ppn_net'), 'invoice_discount_dpp' => $sum($v2, 'invoice_discount_dpp'),
                'inventory_cost' => $sum($v2, 'inventory_cost'),
                'ppn_treatments' => self::sortedUnique(array_column($v2, 'ppn_treatment')),
                'freight_treatments' => self::sortedUnique(array_column($v2, 'freight_treatment')),
            ];
        }
        unset($inv['lines_raw']);
        return [
            'invoice' => $inv, 'lines' => $inv['lines'], 'columns' => self::columns(self::LINE_COLUMNS), 'arithmetic' => $arith,
            'notes' => [
                'allocation_discount' => 'Diskon invoice dialokasikan proporsional terhadap total baris (DPP + PPN), lalu dinyatakan pada DPP — aturan yang sama dengan Stock IN V2.',
                'allocation_freight' => 'Ongkos kirim dialokasikan proporsional terhadap DPP bersih (setelah diskon item + invoice), sesuai metode costing transaksi.',
                'not_inventory_cost' => 'Angka di laporan ini adalah nilai invoice (pembayaran), bukan nilai persediaan FIFO; PPN yang dapat dikreditkan tidak masuk HPP.',
            ],
        ];
    }

    /** Line-level rows for the filter set (export + reconciliation). @return list<array<string,mixed>> */
    public static function lineRows(PDO $pdo, array $f): array
    {
        $d = self::dataset($pdo, $f);
        $out = [];
        foreach ($d['invoices'] as $inv) {
            foreach ($inv['lines'] as $l) {
                if ($l['matched']) {
                    $out[] = $l;
                }
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------------
    // export
    // ----------------------------------------------------------------------

    public static function cell(string $type, mixed $v): int|float|string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if ($type === 'pct' && is_numeric($v)) {
            return round((float) $v, 2);
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        return ExcelWriterService::sanitizeCellText((string) $v);
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    public static function exportTable(PDO $pdo, string $kind, array $f): array
    {
        $d = self::dataset($pdo, $f);
        switch ($kind) {
            case 'invoices':
                $rows = self::filterInvoices($d['invoices'], (string) ($f['inv_q'] ?? ''));
                $table = self::table(self::INVOICE_COLUMNS, $rows);
                $table['rows'][] = self::totalRow(self::INVOICE_COLUMNS, self::invoiceFooter($rows)['totals'], 'TOTAL');
                return $table;
            case 'items':
                $rows = self::itemRows($d);
                $table = self::table(self::ITEM_COLUMNS, $rows);
                $table['rows'][] = self::totalRow(self::ITEM_COLUMNS, self::itemFooter($rows)['totals'], 'TOTAL');
                return $table;
            case 'lines':
                $rows = [];
                foreach ($d['invoices'] as $inv) {
                    foreach ($inv['lines'] as $l) {
                        if ($l['matched']) {
                            $rows[] = $l + ['reference' => $inv['reference'], 'supplier' => $inv['supplier'], 'warehouse' => $inv['warehouse']];
                        }
                    }
                }
                $t = self::table(self::LINE_COLUMNS, $rows);
                $sumKeys = ['gross', 'item_discount', 'dpp', 'ppn', 'invoice_discount', 'freight', 'total', 'inventory_cost'];
                $tot = [];
                foreach ($sumKeys as $k) {
                    $tot[$k] = round(array_sum(array_map(static fn ($r) => $r['counted'] ? (float) ($r[$k] ?? 0) : 0.0, $rows)), 4);
                }
                $t['rows'][] = self::totalRow(self::LINE_COLUMNS, $tot, 'TOTAL (POSTED)');
                return $t;
        }
        throw new ValidationException(['unknown export kind']);
    }

    /** "Ringkasan" sheet: filters + every KPI value (equal to the screen cards) + quantities per unit. @return array{headers:list<string>,rows:list<list<mixed>>} */
    public static function summaryTable(PDO $pdo, array $f, array $meta): array
    {
        $d = self::dataset($pdo, $f);
        $k = self::kpi($d);
        $n = $k['nominal'];
        $rows = [];
        foreach ($meta as $key => $val) {
            $rows[] = [$key, $val];
        }
        $rows[] = ['', ''];
        foreach ([
            ['Total Nilai Pembelian', $n['total']], ['Subtotal Barang', $n['subtotal']], ['Gross Barang', $n['gross']], ['Diskon Barang', $n['item_discount']], ['Diskon Invoice', $n['invoice_discount']],
            ['Total Diskon', $n['discount']], ['PPN', $n['ppn']], ['Ongkos Kirim', $n['freight']], ['Jumlah Supplier', $n['suppliers']], ['Jumlah Invoice / Transaksi', $n['invoices']],
            ['Jumlah SKU Dibeli', $n['skus']], ['Nilai V2 (rincian lengkap)', $n['sources']['V2']['total']], ['Nilai Legacy (nilai tercatat, tanpa rincian)', $n['sources']['Legacy']['total']],
            ['Nilai Historis (hanya bila dipilih)', $n['sources']['Historis']['total']], ['Invoice VOID (tidak dihitung) — jumlah', $d['disclosures']['void']['invoices']], ['Invoice VOID (tidak dihitung) — nilai', $d['disclosures']['void']['amount']],
        ] as $r) {
            $rows[] = [$r[0], self::cell('money', $r[1])];
        }
        $rows[] = ['', ''];
        foreach ($k['qty_by_unit'] as $u) {
            $rows[] = ["Qty dibeli — {$u['unit']}", $u['qty']];
        }
        return ['headers' => ['Keterangan', 'Nilai'], 'rows' => $rows];
    }

    /** @return array<string,array<string,mixed>> */
    public static function exportWorkbook(PDO $pdo, array $f, array $meta): array
    {
        return [
            'Ringkasan' => self::summaryTable($pdo, $f, $meta),
            'Detail Invoice' => self::exportTable($pdo, 'invoices', $f) + ['freeze_header' => true, 'autofilter' => true],
            'Detail Barang' => self::exportTable($pdo, 'items', $f) + ['freeze_header' => true, 'autofilter' => true],
            'Baris Invoice-Barang' => self::exportTable($pdo, 'lines', $f) + ['freeze_header' => true, 'autofilter' => true],
        ];
    }

    // ======================================================================
    // dataset
    // ======================================================================

    /**
     * Everything the report needs for ONE filter set, computed once per request.
     * @return array{invoices:list<array<string,mixed>>, lines:list<array<string,mixed>>, disclosures:array<string,mixed>, single_item:?array<string,mixed>, partial_filter:bool}
     */
    private static function dataset(PDO $pdo, array $f): array
    {
        $start = (string) ($f['start_date'] ?? '');
        $end = (string) ($f['end_date'] ?? '');
        if (strtotime($start) === false || strtotime($end) === false || $start > $end) {
            throw new ValidationException(['start_date and end_date are required and start_date must not be after end_date']);
        }
        if ((strtotime($end) - strtotime($start)) / 86400 > self::MAX_DAYS) {
            throw new ValidationException(['the period is limited to ' . self::MAX_DAYS . ' days']);
        }
        $itemFilter = !empty($f['category_id']) || trim((string) ($f['q'] ?? '')) !== '' || !empty($f['item_id']);
        $lines = self::enrich(self::fetchLines($pdo, $f, true));
        $totalByKey = [];
        if ($itemFilter && $lines) {
            // sibling line counts WITHOUT the item filter, so a partially matching invoice is flagged as partial
            $all = self::enrich(self::fetchLines($pdo, array_diff_key($f, ['category_id' => 1, 'q' => 1, 'item_id' => 1]), false));
            foreach ($all as $l) {
                $totalByKey[$l['group_key']] = ($totalByKey[$l['group_key']] ?? 0) + 1;
            }
        }
        $g = self::groupLines($lines, $totalByKey);
        return $g + ['lines' => $lines, 'partial_filter' => $itemFilter];
    }

    /** @return list<array<string,mixed>> raw joined rows */
    private static function fetchLines(PDO $pdo, array $f, bool $applyItemFilters): array
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
        if ($applyItemFilters) {
            if (!empty($f['category_id'])) { $where[] = 'i.category_id = :cat'; $bind['cat'] = (int) $f['category_id']; }
            if (!empty($f['item_id'])) { $where[] = 'l.item_id = :iid'; $bind['iid'] = (int) $f['item_id']; }
            $q = trim((string) ($f['q'] ?? ''));
            if ($q !== '') {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(i.sku LIKE :q1 OR i.name LIKE :q2)';
                $bind['q1'] = $like;
                $bind['q2'] = $like;
            }
        }
        $stmt = $pdo->prepare(
            "SELECT t.id AS tx_id, t.transaction_uuid, t.transaction_date, t.created_at, t.reference_no, t.status, t.is_historical_import, t.inventory_effect,
                    t.warehouse_id, w.code AS wh_code, w.name AS wh_name, t.supplier_id, s.name AS supplier_name, t.created_by, u.username AS created_by_name,
                    l.id AS line_id, l.line_no, l.item_id, i.sku, i.name AS item_name, i.category_id, c.name AS category_name,
                    l.input_qty, l.input_unit_id, iu.code AS input_unit, l.base_qty, bu.code AS base_unit, l.unit_price_input, l.unit_cost_base, l.subtotal, l.notes,
                    plc.gross_unit_price_input, plc.gross_amount, plc.line_discount_amount, plc.net_after_line_discount, plc.invoice_discount_allocated,
                    plc.net_purchase_before_tax, plc.ppn_allocated, plc.ppn_creditable_allocated, plc.ppn_non_creditable_allocated, plc.freight_allocated, plc.final_inventory_cost,
                    plc.id AS plc_id, pih.id AS pih_id, pih.ppn_treatment, pih.ppn_rate, pih.ppn_creditable_pct, pih.freight_treatment, pih.freight_amount, pih.invoice_total
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

    /** Adds the group key and the derived per-line financials. @return list<array<string,mixed>> */
    private static function enrich(array $rows): array
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
            $source = $isHist ? 'Historis' : ($hasV2 ? 'V2' : 'Legacy');
            $counted = $r['status'] === 'POSTED';
            $qty = (float) $r['input_qty'];
            $l = [
                'tx_id' => (int) $r['tx_id'], 'line_id' => (int) $r['line_id'], 'group_key' => $key, 'date' => $date, 'transaction_at' => $r['transaction_date'], 'created_at' => $r['created_at'],
                'reference' => $ref, 'status' => $r['status'], 'counted' => $counted, 'source' => $source, 'matched' => true,
                'supplier_id' => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null, 'supplier' => $r['supplier_name'], 'warehouse_id' => (int) $r['warehouse_id'], 'warehouse' => $r['wh_name'], 'warehouse_code' => $r['wh_code'],
                'created_by' => $r['created_by_name'], 'item_id' => (int) $r['item_id'], 'sku' => $r['sku'], 'name' => $r['item_name'], 'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'category' => $r['category_name'] ?? '(Tanpa Kategori)', 'unit' => $r['input_unit'], 'unit_id' => (int) $r['input_unit_id'], 'qty' => $qty,
                'base_qty' => (float) $r['base_qty'], 'base_unit' => $r['base_unit'], 'notes' => $r['notes'], 'recorded_value' => round((float) $r['subtotal'], 4),
                'price' => null, 'gross' => null, 'item_discount' => null, 'dpp' => null, 'ppn_rate' => null, 'ppn' => null, 'invoice_discount' => null, 'freight' => null, 'total' => null,
                'ppn_net' => null, 'invoice_discount_dpp' => null, 'inventory_cost' => null, 'ppn_treatment' => null, 'freight_treatment' => null,
            ];
            if ($hasV2) {
                $rate = (float) $r['ppn_rate'];
                $dpp = round((float) $r['net_after_line_discount'], 4);
                $ppnBefore = round($dpp * $rate / 100, 4);
                $netDpp = round((float) $r['net_purchase_before_tax'], 4);
                $ppnNet = round((float) $r['ppn_allocated'], 4);
                $l = array_merge($l, [
                    'price' => round((float) $r['gross_unit_price_input'], 4), 'gross' => round((float) $r['gross_amount'], 4), 'item_discount' => round((float) $r['line_discount_amount'], 4), 'dpp' => $dpp,
                    'ppn_rate' => $rate, 'ppn' => $ppnBefore, 'ppn_net' => $ppnNet, 'invoice_discount_dpp' => round((float) $r['invoice_discount_allocated'], 4),
                    'invoice_discount' => round($dpp + $ppnBefore - $netDpp - $ppnNet, 4), 'freight' => round((float) $r['freight_amount'], 4), 'total' => round((float) $r['invoice_total'], 4),
                    'inventory_cost' => round((float) $r['final_inventory_cost'], 4), 'ppn_treatment' => $r['ppn_treatment'], 'freight_treatment' => $r['freight_treatment'],
                    'net_dpp' => $netDpp, 'ppn_creditable' => round((float) $r['ppn_creditable_allocated'], 4), 'ppn_non_creditable' => round((float) $r['ppn_non_creditable_allocated'], 4),
                ]);
            } else {
                // legacy / historical: the entered price and the recorded value are the only known facts
                $l['price'] = round((float) $r['unit_price_input'], 4);
                $l['total'] = round((float) $r['subtotal'], 4);
            }
            $out[] = $l;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,int> $totalByKey
     * @return array{invoices:list<array<string,mixed>>, disclosures:array<string,mixed>, single_item:?array<string,mixed>}
     */
    private static function groupLines(array $lines, array $totalByKey): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['group_key']][] = $l;
        }
        $invoices = [];
        $void = ['invoices' => 0, 'amount' => 0.0];
        foreach ($groups as $key => $ls) {
            $counted = array_values(array_filter($ls, static fn ($x) => $x['counted']));
            $v2 = array_values(array_filter($counted, static fn ($x) => $x['source'] === 'V2'));
            $sources = array_values(array_unique(array_column($ls, 'source')));
            $statuses = array_values(array_unique(array_column($ls, 'status')));
            $status = count($statuses) === 1 ? $statuses[0] : 'SEBAGIAN VOID';
            $first = $ls[0];
            $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$k] ?? 0), $rows)), 4);
            $hasComponents = $v2 !== [];
            $ppnRates = array_values(array_unique(array_map(static fn ($x) => rtrim(rtrim(number_format((float) $x['ppn_rate'], 4, '.', ''), '0'), '.') . '%', $v2)));
            sort($ppnRates);
            $inv = [
                'group_key' => $key, 'tx_ids' => array_values(array_unique(array_column($ls, 'tx_id'))), 'date' => $first['date'], 'transaction_at' => $first['transaction_at'], 'created_at' => $first['created_at'],
                'reference' => $first['reference'] ?? '—', 'has_reference' => $first['reference'] !== null, 'supplier' => $first['supplier'] ?? '—', 'supplier_id' => $first['supplier_id'],
                'warehouse' => $first['warehouse'], 'warehouse_id' => $first['warehouse_id'], 'created_by' => $first['created_by'], 'status' => $status,
                'source' => count($sources) === 1 ? $sources[0] : 'Campuran', 'items' => count($counted), 'skus' => count(array_unique(array_column($counted, 'item_id'))),
                'lines_in_filter' => count($ls), 'lines_total' => $totalByKey[$key] ?? count($ls), 'partial' => isset($totalByKey[$key]) && $totalByKey[$key] > count($ls),
                'components_known' => $hasComponents, 'components_complete' => $hasComponents && count($v2) === count($counted),
                'gross' => $hasComponents ? $sum($v2, 'gross') : null, 'item_discount' => $hasComponents ? $sum($v2, 'item_discount') : null, 'subtotal' => $hasComponents ? $sum($v2, 'dpp') : null,
                'invoice_discount' => $hasComponents ? $sum($v2, 'invoice_discount') : null, 'ppn' => $hasComponents ? $sum($v2, 'ppn') : null, 'freight' => $hasComponents ? $sum($v2, 'freight') : null,
                'total' => $counted ? $sum($counted, 'total') : 0.0, 'ppn_net' => $hasComponents ? $sum($v2, 'ppn_net') : null, 'invoice_discount_dpp' => $hasComponents ? $sum($v2, 'invoice_discount_dpp') : null,
                'inventory_cost' => $hasComponents ? $sum($v2, 'inventory_cost') : null,
                'ppn_rate' => $hasComponents ? implode(' / ', $ppnRates) : null,
                'freight_treatment' => $hasComponents ? (implode(' / ', array_values(array_unique(array_filter(array_column($v2, 'freight_treatment'), static fn ($t) => $t !== 'NONE' && $t !== null)))) ?: 'Tanpa ongkir') : null,
                'voided_total' => round(array_sum(array_map(static fn ($x) => $x['counted'] ? 0.0 : (float) ($x['total'] ?? 0), $ls)), 4),
                'qty_text' => self::qtyText($counted),
                'lines' => $ls,
            ];
            if (!$counted) {
                $void['invoices']++;
                $void['amount'] += $inv['voided_total'];
            }
            $invoices[] = $inv;
        }
        usort($invoices, static fn ($a, $b) => [$b['transaction_at'], $b['tx_ids'][0]] <=> [$a['transaction_at'], $a['tx_ids'][0]]);

        $items = [];
        foreach ($lines as $l) {
            if ($l['counted']) {
                $items[$l['item_id']] = ['item_id' => $l['item_id'], 'sku' => $l['sku'], 'name' => $l['name'], 'base_unit' => $l['base_unit']];
            }
        }
        return [
            'invoices' => $invoices,
            'disclosures' => ['void' => ['invoices' => $void['invoices'], 'amount' => round($void['amount'], 4)]],
            'single_item' => count($items) === 1 ? array_values($items)[0] : null,
        ];
    }

    // ======================================================================
    // aggregates
    // ======================================================================

    private static function kpi(array $d): array
    {
        $n = ['total' => 0.0, 'gross' => 0.0, 'item_discount' => 0.0, 'subtotal' => 0.0, 'invoice_discount' => 0.0, 'discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'ppn_net' => 0.0,
            'inventory_cost' => 0.0, 'invoices' => 0, 'lines' => 0, 'sources' => ['V2' => ['invoices' => 0, 'total' => 0.0], 'Legacy' => ['invoices' => 0, 'total' => 0.0], 'Historis' => ['invoices' => 0, 'total' => 0.0]]];
        $suppliers = [];
        $skus = [];
        $rates = [];
        $qty = [];
        $counted = array_filter($d['invoices'], static fn ($i) => $i['items'] > 0);
        foreach ($d['lines'] as $l) {
            if (!$l['counted']) {
                continue;
            }
            $n['lines']++;
            $n['total'] += (float) $l['total'];
            $skus[$l['item_id']] = true;
            $suppliers[$l['supplier_id'] ?? 'x'] = true;
            $u = (string) $l['base_unit'];
            $qty[$u] = ($qty[$u] ?? 0.0) + (float) $l['base_qty'];
            if ($l['source'] === 'V2') {
                foreach (['gross', 'item_discount', 'invoice_discount', 'ppn', 'freight', 'ppn_net', 'inventory_cost'] as $k) {
                    $n[$k] += (float) $l[$k];
                }
                $n['subtotal'] += (float) $l['dpp'];
                $rates[(float) $l['ppn_rate']] = true;
            }
            $n['sources'][$l['source']]['total'] += (float) $l['total'];
        }
        foreach ($counted as $i) {
            $n['invoices']++;
            foreach (array_unique(array_column(array_filter($i['lines'], static fn ($x) => $x['counted']), 'source')) as $s) {
                $n['sources'][$s]['invoices']++;
            }
        }
        $n['discount'] = $n['item_discount'] + $n['invoice_discount'];
        foreach (['total', 'gross', 'item_discount', 'subtotal', 'invoice_discount', 'discount', 'ppn', 'freight', 'ppn_net', 'inventory_cost'] as $k) {
            $n[$k] = round($n[$k], 4);
        }
        foreach ($n['sources'] as &$s) {
            $s['total'] = round($s['total'], 4);
        }
        unset($s);
        ksort($qty);
        $rateList = array_keys($rates);
        sort($rateList);
        $n['suppliers'] = count(array_filter(array_keys($suppliers), static fn ($k) => $k !== 'x'));
        $n['skus'] = count($skus);
        $n['ppn_rates'] = array_map(static fn ($r) => rtrim(rtrim(number_format($r, 4, '.', ''), '0'), '.') . '%', $rateList);
        return ['nominal' => $n, 'qty_by_unit' => array_map(static fn ($u, $q) => ['unit' => (string) $u, 'qty' => round($q, 6)], array_keys($qty), $qty)];
    }

    /** @return list<array<string,mixed>> */
    private static function trend(array $d, string $start, string $end, string $bucket): array
    {
        $label = static function (string $date) use ($bucket): string {
            if ($bucket === 'month') {
                return substr($date, 0, 7);
            }
            if ($bucket === 'week') {
                $ts = strtotime($date);
                return date('Y-m-d', strtotime('-' . ((int) date('N', $ts) - 1) . ' days', $ts));
            }
            return $date;
        };
        $b = [];
        // continuous axis: a bucket with no purchase is a KNOWN zero (the period was queried), not a gap
        $cur = strtotime($start);
        $last = strtotime($end);
        while ($cur <= $last) {
            $b[$label(date('Y-m-d', $cur))] ??= null;
            $cur = strtotime('+1 day', $cur);
        }
        foreach (array_keys($b) as $k) {
            $b[$k] = ['bucket' => $k, 'total' => 0.0, 'subtotal' => 0.0, 'discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'invoices' => 0, 'skus' => 0, 'qty' => 0.0, '_sku' => []];
        }
        $single = $d['single_item'];
        foreach ($d['invoices'] as $i) {
            $k = $label($i['date']);
            if (!isset($b[$k])) {
                continue;
            }
            $cnt = array_values(array_filter($i['lines'], static fn ($x) => $x['counted']));
            if (!$cnt) {
                continue;
            }
            $b[$k]['invoices']++;
            foreach ($cnt as $l) {
                $b[$k]['total'] += (float) $l['total'];
                $b[$k]['_sku'][$l['item_id']] = true;
                if ($l['source'] === 'V2') {
                    $b[$k]['subtotal'] += (float) $l['dpp'];
                    $b[$k]['discount'] += (float) $l['item_discount'] + (float) $l['invoice_discount'];
                    $b[$k]['ppn'] += (float) $l['ppn'];
                    $b[$k]['freight'] += (float) $l['freight'];
                }
                if ($single !== null) {
                    $b[$k]['qty'] += (float) $l['base_qty'];
                }
            }
        }
        $out = [];
        foreach ($b as $row) {
            $row['skus'] = count($row['_sku']);
            unset($row['_sku']);
            foreach (['total', 'subtotal', 'discount', 'ppn', 'freight'] as $k) {
                $row[$k] = round($row[$k], 4);
            }
            $row['qty'] = $single !== null ? round($row['qty'], 6) : null;
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> Rincian per Barang: one row per (item, purchase unit) */
    private static function itemRows(array $d): array
    {
        $acc = [];
        $grand = 0.0;
        foreach ($d['lines'] as $l) {
            if (!$l['counted']) {
                continue;
            }
            $k = $l['item_id'] . '|' . $l['unit_id'];
            $acc[$k] ??= [
                'item_id' => $l['item_id'], 'sku' => $l['sku'], 'name' => $l['name'], 'category' => $l['category'], 'unit' => $l['unit'], 'base_unit' => $l['base_unit'],
                'qty' => 0.0, 'base_qty' => 0.0, 'invoices' => [], 'price_min' => null, 'price_max' => null, 'gross_for_avg' => 0.0, 'qty_for_avg' => 0.0,
                'gross' => 0.0, 'item_discount' => 0.0, 'invoice_discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'total' => 0.0, 'subtotal' => 0.0, 'v2_lines' => 0, 'legacy_lines' => 0,
            ];
            $a = &$acc[$k];
            $a['qty'] += $l['qty'];
            $a['base_qty'] += $l['base_qty'];
            $a['invoices'][$l['group_key']] = true;
            $p = (float) $l['price'];
            $a['price_min'] = $a['price_min'] === null ? $p : min($a['price_min'], $p);
            $a['price_max'] = $a['price_max'] === null ? $p : max($a['price_max'], $p);
            $a['qty_for_avg'] += $l['qty'];
            $a['gross_for_avg'] += $l['qty'] * $p;
            $a['total'] += (float) $l['total'];
            if ($l['source'] === 'V2') {
                $a['v2_lines']++;
                foreach (['gross', 'item_discount', 'invoice_discount', 'ppn', 'freight'] as $c) {
                    $a[$c] += (float) $l[$c];
                }
                $a['subtotal'] += (float) $l['dpp'];
            } else {
                $a['legacy_lines']++;
            }
            $grand += (float) $l['total'];
            unset($a);
        }
        $rows = [];
        foreach ($acc as $a) {
            $v2only = $a['v2_lines'] > 0;
            $rows[] = [
                'item_id' => $a['item_id'], 'sku' => $a['sku'], 'name' => $a['name'], 'category' => $a['category'], 'unit' => $a['unit'], 'qty' => round($a['qty'], 6), 'base_qty' => round($a['base_qty'], 6),
                'base_unit' => $a['base_unit'], 'frequency' => count($a['invoices']), 'price_min' => $a['price_min'], 'price_max' => $a['price_max'],
                'price_avg' => $a['qty_for_avg'] > 0 ? round($a['gross_for_avg'] / $a['qty_for_avg'], 4) : null,
                'gross' => $v2only ? round($a['gross'], 4) : null, 'item_discount' => $v2only ? round($a['item_discount'], 4) : null, 'subtotal' => $v2only ? round($a['subtotal'], 4) : null,
                'invoice_discount' => $v2only ? round($a['invoice_discount'], 4) : null, 'ppn' => $v2only ? round($a['ppn'], 4) : null, 'freight' => $v2only ? round($a['freight'], 4) : null,
                'total' => round($a['total'], 4), 'share_pct' => $grand > 0 ? round($a['total'] * 100 / $grand, 2) : null,
                'legacy_lines' => $a['legacy_lines'], 'components_complete' => $a['legacy_lines'] === 0,
            ];
        }
        usort($rows, static fn ($x, $y) => [$y['total'], $x['sku']] <=> [$x['total'], $y['sku']]);
        return $rows;
    }

    /** @param list<array<string,mixed>> $invoices */
    private static function filterInvoices(array $invoices, string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return $invoices;
        }
        return array_values(array_filter($invoices, static function (array $i) use ($q): bool {
            $hay = [$i['date'], (string) $i['reference'], (string) $i['supplier'], (string) $i['warehouse'], (string) $i['created_by']];
            foreach ($i['lines'] as $l) {
                $hay[] = $l['sku'];
                $hay[] = $l['name'];
            }
            return str_contains(mb_strtolower(implode(' ', $hay)), $q);
        }));
    }

    /** @return array{totals:array<string,float|int>, count:int} */
    private static function invoiceFooter(array $rows): array
    {
        $t = ['items' => 0, 'gross' => 0.0, 'item_discount' => 0.0, 'subtotal' => 0.0, 'invoice_discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'total' => 0.0, 'ppn_net' => 0.0, 'invoice_discount_dpp' => 0.0, 'inventory_cost' => 0.0];
        $voided = 0.0;
        foreach ($rows as $r) {
            $t['items'] += $r['items'];
            $t['total'] += (float) $r['total'];
            foreach (['gross', 'item_discount', 'subtotal', 'invoice_discount', 'ppn', 'freight', 'ppn_net', 'invoice_discount_dpp', 'inventory_cost'] as $k) {
                $t[$k] += (float) ($r[$k] ?? 0);
            }
            $voided += (float) $r['voided_total'];
        }
        foreach ($t as $k => $v) {
            $t[$k] = $k === 'items' ? $v : round($v, 4);
        }
        $legacy = count(array_filter($rows, static fn ($r) => !$r['components_complete'] && $r['items'] > 0));
        return ['totals' => $t, 'count' => count($rows), 'incomplete_components' => $legacy, 'voided_total' => round($voided, 4)];
    }

    private static function itemFooter(array $rows): array
    {
        $t = ['frequency' => 0, 'gross' => 0.0, 'item_discount' => 0.0, 'subtotal' => 0.0, 'invoice_discount' => 0.0, 'ppn' => 0.0, 'freight' => 0.0, 'total' => 0.0];
        $byUnit = [];
        foreach ($rows as $r) {
            foreach (['gross', 'item_discount', 'subtotal', 'invoice_discount', 'ppn', 'freight', 'total'] as $k) {
                $t[$k] += (float) ($r[$k] ?? 0);
            }
            $byUnit[$r['unit']] = ($byUnit[$r['unit']] ?? 0.0) + $r['qty'];
        }
        foreach ($t as $k => $v) {
            $t[$k] = round($v, 4);
        }
        ksort($byUnit);
        return ['totals' => $t, 'count' => count($rows), 'qty_by_unit' => array_map(static fn ($u, $q) => ['unit' => (string) $u, 'qty' => round($q, 6)], array_keys($byUnit), $byUnit)];
    }

    // ----------------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------------

    /** "KG 5 · PCS 10" — base-unit quantities of the counted rows, grouped BY unit (never one mixed total). */
    private static function qtyText(array $counted): ?string
    {
        $by = [];
        foreach ($counted as $l) {
            $by[(string) $l['base_unit']] = ($by[(string) $l['base_unit']] ?? 0.0) + (float) $l['base_qty'];
        }
        if (!$by) {
            return null;
        }
        ksort($by);
        return implode(' · ', array_map(static fn ($u, $q) => $u . ' ' . rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'), array_keys($by), $by));
    }

    /** @return list<string> */
    private static function sortedUnique(array $values): array
    {
        $v = array_values(array_unique(array_filter($values, static fn ($x) => $x !== null && $x !== '')));
        sort($v);
        return $v;
    }

    private static function columns(array $cols): array
    {
        return array_map(static fn (array $c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2], 'default' => $c[3]], $cols);
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    private static function table(array $cols, array $rows): array
    {
        return [
            'headers' => array_map(static fn (array $c) => $c[1], $cols),
            'types' => array_map(static fn (array $c) => self::exportType($c[2]), $cols),
            'rows' => array_map(static fn (array $r) => array_map(static fn (array $c) => self::cell($c[2], $r[$c[0]] ?? null), $cols), $rows),
        ];
    }

    /** catalogue type -> export column type (typed Excel cells + print formatting) */
    private static function exportType(string $t): string
    {
        return match ($t) { 'date' => 'date', 'ts' => 'ts', 'money' => 'money', 'qty' => 'qty', 'num' => 'qty', 'int' => 'int', 'pct', 'rate' => 'pct', default => 'text' };
    }

    /** The visible GRAND TOTAL row: money columns carry the footer sum, the first column the label, everything else blank. */
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
        return array_intersect_key($f, array_flip(['start_date', 'end_date', 'warehouse_id', 'supplier_id', 'category_id', 'q', 'item_id', 'historical']));
    }
}
