/**
 * PHASE V2.6B — Report 12 "Distribusi per Bakery". Qualifying rows are
 * OUT transactions with a real bakery_destination_id — a plain warehouse
 * transfer or an opening/adjustment can never appear here (see
 * TransactionHistoryService::groupedSummary()'s own filter).
 */
const ReportBakery = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));

        ReportCommon.render(container, {
            title: 'Distribusi per Bakery',
            subtitle: 'Distribusi OUT ke tujuan bakery — transfer antar gudang TIDAK dihitung sebagai distribusi bakery.',
            storageKey: 'dt-report-bakery',
            exportUrl: (state) => InvApi.bakeryDistributionExportUrl(state),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
            ],
            columns: [
                { key: 'name', label: 'Bakery', render: (r) => r.name },
                { key: 'transaction_count', label: 'Jumlah Transaksi', render: (r) => UI.formatNumber(r.transaction_count) },
                { key: 'total_value', label: 'Total Nilai OUT', render: (r) => UI.formatMoney(r.total_value) },
                { key: 'unique_sku', label: 'SKU Unik', render: (r) => UI.formatNumber(r.unique_sku) },
                { key: 'latest_date', label: 'Distribusi Terakhir', render: (r) => r.latest_date ? new Date(r.latest_date.replace(' ', 'T')).toLocaleDateString('id-ID') : '-' },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '' && k !== 'page' && k !== 'per_page') clean[k] = state[k]; });
                const rows = await InvApi.bakeryDistribution(clean);
                const page = state.page || 1;
                const perPage = state.per_page || 25;
                const start = (page - 1) * perPage;
                return { rows: rows.slice(start, start + perPage), pagination: { page, total_pages: Math.max(1, Math.ceil(rows.length / perPage)), total: rows.length } };
            },
            emptyMessage: 'Tidak ada distribusi bakery untuk filter ini.',
        });
    }

    return { render };
})();
