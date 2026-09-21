/**
 * PHASE V2.6B — Report 10 "Expired / Near Expired". OWNER FINAL RULE:
 * informational only — never changes FIFO, HPP, batch selection, or
 * blocks OUT. Only real, already-persisted expiry_date values are shown
 * (ExpiryReportService never invents one).
 */
const ReportExpiry = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));
        const statusOptions = [
            { value: 'EXPIRED', label: 'Expired' }, { value: 'CRITICAL', label: 'Critical (≤7 hari)' },
            { value: 'WARNING', label: 'Warning (≤30 hari)' }, { value: 'WATCH', label: 'Watch (≤90 hari)' },
            { value: 'OK', label: 'OK' },
        ];

        ReportCommon.render(container, {
            title: 'Expired / Near Expired',
            subtitle: 'Informasi kedaluwarsa dari data yang sudah tersimpan.',
            storageKey: 'dt-report-expiry',
            exportUrl: (state) => InvApi.expiryReportExportUrl(state),
            note: 'ℹ️ Laporan ini bersifat informasi saja — TIDAK mengubah FIFO, HPP, atau alokasi batch, dan tidak memblokir transaksi OUT.',
            filters: [
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'category_id', label: 'Kategori', type: 'select', options: catOptions },
                { key: 'status', label: 'Status', type: 'select', options: statusOptions },
            ],
            columns: [
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Barang', render: (r) => r.item.name },
                { key: 'batch_id', label: 'Batch', render: (r) => `#${r.batch_id}` },
                { key: 'qty_remaining', label: 'Qty Sisa', render: (r) => UI.formatNumber(r.qty_remaining) },
                { key: 'inventory_value', label: 'Nilai', render: (r) => UI.formatMoney(r.inventory_value) },
                { key: 'expiry_date', label: 'Tanggal Expiry', render: (r) => r.expiry_date },
                { key: 'days_remaining', label: 'Sisa Hari', render: (r) => `${r.days_remaining} hari` },
                { key: 'status', label: 'Status', render: (r) => statusBadge(r.status) },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') clean[k] = state[k]; });
                return InvApi.expiryReport(clean);
            },
            emptyMessage: 'Tidak ada batch dengan data expiry untuk filter ini.',
        });
    }

    function statusBadge(status) {
        const colors = { EXPIRED: 'var(--red, #e5484d)', CRITICAL: 'var(--orange)', WARNING: 'var(--orange)', WATCH: 'var(--accent)', OK: 'var(--green)' };
        return UI.el('span', { style: `color:${colors[status] || 'inherit'}; font-weight:600;` }, status);
    }

    return { render };
})();
