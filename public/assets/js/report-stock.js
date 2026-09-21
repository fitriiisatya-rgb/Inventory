/**
 * PHASE V2.6B — Report 3 "Laporan Stok". Pure reuse: calls the EXISTING
 * GET /reports/stock (StockReportService, unchanged since V2) — no new
 * backend, no duplicated valuation logic. This file only adds a report-
 * style compact presentation under the Laporan menu (the original "Stok
 * Barang" tab under Inventory is untouched and still exists separately).
 */
const ReportStock = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));
        const statusOptions = [
            { value: 'SAFE', label: 'Aman' }, { value: 'LOW', label: 'Rendah' },
            { value: 'CRITICAL', label: 'Kritis' }, { value: 'OUT_OF_STOCK', label: 'Habis' },
            { value: 'MIGRATION_NEGATIVE_REVIEW', label: 'Migration Negative' },
        ];

        ReportCommon.render(container, {
            title: 'Laporan Stok',
            subtitle: 'Qty, nilai, dan status stok per gudang — data dari Master Barang & FIFO batch (bukan average cost).',
            storageKey: 'dt-report-stock',
            filters: [
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'category_id', label: 'Kategori', type: 'select', options: catOptions },
                { key: 'q', label: 'SKU / Nama Barang', type: 'text', placeholder: 'Cari...' },
                { key: 'status', label: 'Status Stok', type: 'select', options: statusOptions },
            ],
            exportUrl: (state) => `/api/reports/stock?${new URLSearchParams(cleanParams(Object.assign({}, state, { format: 'csv' }))).toString()}`,
            loadSummary: async (state) => {
                const result = await InvApi.stockReport(Object.assign({}, cleanParams(state), { per_page: 1 }));
                const s = result.summary;
                return ReportCommon.kpiRow([
                    ReportCommon.kpiCard('📦', 'Total SKU', s.total_items, true),
                    ReportCommon.kpiCard('✅', 'Ada Stok', s.items_with_stock, true),
                    ReportCommon.kpiCard('💰', 'Total Nilai', s.total_value),
                    ReportCommon.kpiCard('⚠️', 'Kritis + Habis', s.critical_count + s.out_of_stock_count, true),
                ]);
            },
            columns: [
                { key: 'sku', label: 'SKU', render: (r) => r.sku },
                { key: 'name', label: 'Nama Barang', render: (r) => r.name },
                { key: 'category', label: 'Kategori', render: (r) => (r.category && r.category.name) || '-' },
                { key: 'unit', label: 'Satuan', render: (r) => (r.unit && r.unit.code) || '-' },
                { key: 'qty_base', label: 'Qty On Hand', render: (r) => UI.formatNumber(r.qty_base) },
                { key: 'value', label: 'Nilai Inventory', render: (r) => UI.formatMoney(r.value) },
                { key: 'minimum_stock', label: 'Min. Stok', render: (r) => UI.formatNumber(r.minimum_stock) },
                { key: 'status', label: 'Status', render: (r) => statusBadge(r.status) },
            ],
            fetchPage: async (state) => {
                const result = await InvApi.stockReport(cleanParams(state));
                return { rows: result.rows, pagination: result.pagination };
            },
        });
    }

    function cleanParams(state) {
        const clean = {};
        Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== null && state[k] !== '') clean[k] = state[k]; });
        return clean;
    }

    function statusBadge(status) {
        const map = {
            SAFE: 'Aman', LOW: 'Rendah', CRITICAL: 'Kritis', OUT_OF_STOCK: 'Habis',
            MIGRATION_NEGATIVE_REVIEW: 'Migration Negative',
        };
        return map[status] || status;
    }

    return { render };
})();
