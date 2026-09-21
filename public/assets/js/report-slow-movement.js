/**
 * PHASE V2.6B — Report 13 "Slow / No Movement". Purely descriptive —
 * NO_MOVEMENT_30/60/90 is a factual days-since-last-movement bucket,
 * never an automatic "obsolete"/"dead stock" label. Zero-stock SKUs are
 * excluded by default (SlowMovementReportService's own convention).
 */
const ReportSlowMovement = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));

        ReportCommon.render(container, {
            title: 'Slow / No Movement',
            subtitle: 'SKU dengan stok yang belum bergerak dalam N hari terakhir (deskriptif, bukan vonis obsolete).',
            storageKey: 'dt-report-slow-movement',
            defaultFilters: { threshold_days: '30' },
            filters: [
                { key: 'threshold_days', label: 'Ambang (hari)', type: 'select', options: [{ value: '30', label: '30 hari' }, { value: '60', label: '60 hari' }, { value: '90', label: '90 hari' }] },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'category_id', label: 'Kategori', type: 'select', options: catOptions },
            ],
            columns: [
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Barang', render: (r) => r.item.name },
                { key: 'qty_on_hand', label: 'Qty On Hand', render: (r) => UI.formatNumber(r.qty_on_hand) },
                { key: 'inventory_value', label: 'Nilai', render: (r) => UI.formatMoney(r.inventory_value) },
                { key: 'last_in', label: 'Terakhir Masuk', render: (r) => r.last_in || '-' },
                { key: 'last_out', label: 'Terakhir Keluar', render: (r) => r.last_out || '-' },
                { key: 'days_since_movement', label: 'Hari Sejak Bergerak', render: (r) => r.days_since_movement !== null ? `${r.days_since_movement} hari` : 'Belum pernah' },
                { key: 'status', label: 'Status', render: (r) => r.status },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') clean[k] = state[k]; });
                return InvApi.slowMovementReport(clean);
            },
            emptyMessage: 'Tidak ada SKU slow-movement untuk ambang & filter ini.',
        });
    }

    return { render };
})();
