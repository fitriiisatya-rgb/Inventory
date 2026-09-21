/**
 * PHASE V2.6B — Report 9 "Adjustment / Selisih". Read-only over
 * AdjustmentReportService (existing stock_adjustments table,
 * StockAdjustmentService's own posting logic untouched). OPENING is
 * never classified here — it lives in a separate table/transaction_type
 * entirely, never mixed with ordinary adjustments.
 */
const ReportAdjustment = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const typeOptions = ['OPNAME', 'CORRECTION', 'DAMAGE', 'EXPIRED', 'LOSS', 'OTHER', 'NEGATIVE_OVERRIDE'].map((t) => ({ value: t, label: t }));

        ReportCommon.render(container, {
            title: 'Adjustment / Selisih',
            subtitle: 'Riwayat koreksi stok — opening TIDAK termasuk (tabel/jenis transaksi terpisah).',
            storageKey: 'dt-report-adjustment',
            exportUrl: (state) => InvApi.adjustmentReportExportUrl(state),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'direction', label: 'Arah', type: 'select', options: [{ value: 'POSITIVE', label: 'Positif' }, { value: 'NEGATIVE', label: 'Negatif' }] },
                { key: 'adjustment_type', label: 'Sumber', type: 'select', options: typeOptions },
            ],
            columns: [
                { key: 'date', label: 'Tanggal', render: (r) => fmtDate(r.date) },
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Barang', render: (r) => r.item.name },
                { key: 'adjustment_qty', label: 'Qty', render: (r) => qtyCell(r) },
                { key: 'adjustment_value', label: 'Nilai', render: (r) => qtyCell(r, true) },
                { key: 'source_module', label: 'Sumber', render: (r) => r.source_module },
                { key: 'reason', label: 'Alasan', render: (r) => r.reason },
                { key: 'created_by', label: 'User', render: (r) => r.created_by },
                { key: 'status', label: 'Status', render: (r) => r.status },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') clean[k] = state[k]; });
                return InvApi.adjustmentReport(clean);
            },
            onRowClick: (row) => { if (row.transaction_id) TraceDrawer.openTransaction(row.transaction_id); },
            emptyMessage: 'Tidak ada adjustment untuk filter ini.',
        });
    }

    function qtyCell(r, isMoney) {
        const value = isMoney ? r.adjustment_value : r.adjustment_qty;
        const text = isMoney ? UI.formatMoney(value) : UI.formatNumber(value);
        return UI.el('span', { style: `color:${r.direction === 'POSITIVE' ? 'var(--green)' : 'var(--orange)'};` }, text);
    }
    function fmtDate(value) {
        if (!value) return '-';
        return new Date(String(value).replace(' ', 'T')).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    return { render };
})();
