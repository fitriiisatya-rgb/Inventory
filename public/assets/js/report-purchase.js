/**
 * PHASE V2.6B — Report 4 "Laporan Pembelian". Qualifying purchase = type
 * IN, POSTED — warehouse transfer/production/opening/adjustment/historical
 * import never counts (TransactionHistoryService's own filter + summary()'s
 * unconditional historical exclusion). PPN/discount/freight are explicitly
 * V2.7 — never fabricated here as fake zero columns. Row click opens the
 * EXISTING TraceDrawer.
 */
const ReportPurchase = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const supOptions = Master.suppliers().map((s) => ({ value: s.id, label: s.name }));

        ReportCommon.render(container, {
            title: 'Laporan Pembelian',
            subtitle: 'Pembelian eksternal dari supplier (transfer/produksi/opening/adjustment TIDAK dihitung sebagai pembelian).',
            storageKey: 'dt-report-purchase',
            exportUrl: (state) => InvApi.purchaseReportExportUrl(clean(state)),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'supplier_id', label: 'Supplier', type: 'select', options: supOptions },
                { key: 'historical', label: 'Data Historis', type: 'select', options: [{ value: '0', label: 'Live saja' }, { value: '1', label: 'Historis saja' }], allLabel: 'Semua (Live + Historis)' },
            ],
            loadSummary: async (state) => {
                const s = await InvApi.purchaseReportSummary(clean(state));
                return ReportCommon.kpiRow([
                    ReportCommon.kpiCard('🛒', 'Total Nilai Pembelian', s.total_value),
                    ReportCommon.kpiCard('🧾', 'Jumlah Transaksi', s.transaction_count, true),
                    ReportCommon.kpiCard('🏭', 'Jumlah Supplier', s.counterparty_count, true),
                    ReportCommon.kpiCard('📊', 'Rata-rata / Transaksi', s.average_transaction_value),
                ]);
            },
            columns: [
                {
                    key: 'transaction_date', label: 'Tanggal', render: (r) => r.is_historical
                        ? UI.el('span', {}, [ReportCommon.historicalBadge(), ' ', fmtDate(r.transaction_date)])
                        : fmtDate(r.transaction_date),
                },
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'supplier', label: 'Supplier', render: (r) => (r.supplier && r.supplier.name) || '-' },
                { key: 'reference_no', label: 'Referensi', render: (r) => r.reference_no || '-' },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Barang', render: (r) => r.item.name },
                { key: 'input_qty', label: 'Qty Input', render: (r) => `${UI.formatNumber(r.input_qty)} ${r.input_unit.code}` },
                { key: 'base_qty', label: 'Base Qty', render: (r) => UI.formatNumber(r.base_qty) },
                // PHASE V2.7 — only ever populated for a purchase posted
                // through the costed Stock IN flow; every older/other
                // transaction (OPENING/TRANSFER_IN/historical) shows '-',
                // never a fabricated 0.
                { key: 'gross', label: 'Gross', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.gross_amount) : '-') },
                { key: 'line_discount', label: 'Diskon Baris', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.line_discount_amount) : '-') },
                { key: 'invoice_discount', label: 'Diskon Invoice', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.invoice_discount_allocated) : '-') },
                { key: 'net_purchase', label: 'Net Purchase', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.net_purchase_before_tax) : '-') },
                { key: 'ppn', label: 'PPN', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.ppn_allocated) : '-') },
                { key: 'freight', label: 'Freight', render: (r) => (r.purchase_costing ? UI.formatMoney(r.purchase_costing.freight_allocated) : '-') },
                { key: 'unit_cost_base', label: 'Harga Satuan (Final)', render: (r) => UI.formatMoney(r.unit_cost_base) },
                { key: 'subtotal', label: 'Nilai Pembelian (FIFO Cost)', render: (r) => UI.formatMoney(r.subtotal) },
                { key: 'created_by', label: 'Dibuat Oleh', render: (r) => r.created_by.username },
            ],
            fetchPage: async (state) => InvApi.purchaseReport(clean(state)),
            onRowClick: (row) => TraceDrawer.openTransaction(row.transaction_id),
            emptyMessage: 'Tidak ada pembelian untuk filter ini.',
        });
    }

    function clean(state) {
        const c = {};
        Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') c[k] = state[k]; });
        return c;
    }
    function fmtDate(value) {
        if (!value) return '-';
        return new Date(value.replace(' ', 'T')).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    return { render };
})();
