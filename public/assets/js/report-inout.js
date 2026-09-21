/**
 * PHASE V2.6B — Report 5 "Laporan IN/OUT". Unified operational view of
 * both directions, backed by TransactionHistoryService (unchanged query
 * engine, no duplication). Historical rows are shown when requested,
 * clearly badged, and never folded into the live summary totals.
 */
const ReportInOut = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));
        const supOptions = Master.suppliers().map((s) => ({ value: s.id, label: s.name }));
        const bakeryOptions = Master.bakeryDestinations().map((b) => ({ value: b.id, label: b.name }));

        ReportCommon.render(container, {
            title: 'Laporan IN / OUT',
            subtitle: 'Seluruh transaksi barang masuk dan keluar operasional.',
            storageKey: 'dt-report-inout',
            exportUrl: (state) => InvApi.inOutReportExportUrl(clean(state)),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'direction', label: 'Arah', type: 'select', options: [{ value: 'IN', label: 'IN' }, { value: 'OUT', label: 'OUT' }] },
                { key: 'category_id', label: 'Kategori', type: 'select', options: catOptions },
                { key: 'supplier_id', label: 'Supplier', type: 'select', options: supOptions },
                { key: 'bakery_destination_id', label: 'Bakery Tujuan', type: 'select', options: bakeryOptions },
                { key: 'q', label: 'SKU / Referensi', type: 'text' },
            ],
            loadSummary: async (state) => {
                const s = await InvApi.inOutReportSummary(clean(state));
                return ReportCommon.kpiRow([
                    ReportCommon.kpiCard('📥', 'Total IN', s.total_in_value),
                    ReportCommon.kpiCard('📤', 'Total OUT', s.total_out_value),
                    ReportCommon.kpiCard('🧮', 'Net Movement', s.net_movement),
                    ReportCommon.kpiCard('🧾', 'Jumlah Transaksi', s.transaction_count, true),
                ]);
            },
            columns: [
                {
                    key: 'transaction_date', label: 'Tanggal', render: (r) => r.is_historical
                        ? UI.el('span', {}, [ReportCommon.historicalBadge(), ' ', fmtDate(r.transaction_date)])
                        : fmtDate(r.transaction_date),
                },
                { key: 'reference_no', label: 'Referensi', render: (r) => r.reference_no || '-' },
                { key: 'transaction_type', label: 'Tipe', render: (r) => r.transaction_type },
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Barang', render: (r) => r.item.name },
                { key: 'base_qty', label: 'Qty', render: (r) => UI.formatNumber(r.base_qty) },
                { key: 'subtotal', label: 'Nilai', render: (r) => UI.formatMoney(r.subtotal) },
                { key: 'counterparty', label: 'Supplier/Bakery', render: (r) => (r.supplier && r.supplier.name) || (r.bakery_destination && r.bakery_destination.name) || '-' },
                { key: 'created_by', label: 'User', render: (r) => r.created_by.username },
                { key: 'status', label: 'Status', render: (r) => r.status },
            ],
            fetchPage: async (state) => InvApi.inOutReport(clean(state)),
            onRowClick: (row) => TraceDrawer.openTransaction(row.transaction_id),
            emptyMessage: 'Tidak ada transaksi IN/OUT untuk filter ini.',
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
