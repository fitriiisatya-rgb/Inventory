/**
 * PHASE V2.6B — Report 11 "Pembelian per Supplier". Aggregates the exact
 * same qualifying-purchase definition as Report 4 (TransactionHistoryService
 * ::groupedSummary()), grouped by supplier. Click a supplier to see its
 * own purchase transaction detail (reuses Report 4's own list call,
 * scoped to that supplier).
 */
const ReportSupplier = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));

        ReportCommon.render(container, {
            title: 'Pembelian per Supplier',
            subtitle: 'Ringkasan pembelian eksternal dikelompokkan per supplier.',
            storageKey: 'dt-report-supplier',
            exportUrl: (state) => InvApi.purchaseBySupplierExportUrl(state),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
            ],
            columns: [
                { key: 'name', label: 'Supplier', render: (r) => r.name },
                { key: 'transaction_count', label: 'Jumlah Transaksi', render: (r) => UI.formatNumber(r.transaction_count) },
                { key: 'total_value', label: 'Total Pembelian', render: (r) => UI.formatMoney(r.total_value) },
                { key: 'average_value', label: 'Rata-rata', render: (r) => UI.formatMoney(r.average_value) },
                { key: 'unique_sku', label: 'SKU Unik', render: (r) => UI.formatNumber(r.unique_sku) },
                { key: 'latest_date', label: 'Pembelian Terakhir', render: (r) => r.latest_date ? new Date(r.latest_date.replace(' ', 'T')).toLocaleDateString('id-ID') : '-' },
                { key: 'share_pct', label: '% dari Total', render: (r) => `${r.share_pct}%` },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '' && k !== 'page' && k !== 'per_page') clean[k] = state[k]; });
                const rows = await InvApi.purchaseBySupplier(clean);
                const page = state.page || 1;
                const perPage = state.per_page || 25;
                const start = (page - 1) * perPage;
                return { rows: rows.slice(start, start + perPage), pagination: { page, total_pages: Math.max(1, Math.ceil(rows.length / perPage)), total: rows.length } };
            },
            onRowClick: (row) => openSupplierDetail(row),
            emptyMessage: 'Tidak ada data pembelian untuk filter ini.',
        });
    }

    async function openSupplierDetail(supplierRow) {
        Drawer.open({ title: `Pembelian — ${supplierRow.name}`, render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat detail...</div>'; } });
        try {
            const result = await InvApi.purchaseReport({ supplier_id: supplierRow.id, per_page: 100 });
            Drawer.open({
                title: `Pembelian — ${supplierRow.name}`,
                render: (body) => {
                    const rows = result.rows.map((r, i) => UI.el('tr', { class: 'hpp-trace-detail-row', 'data-tx-id': String(r.transaction_id) }, [
                        UI.el('td', {}, String(i + 1)),
                        UI.el('td', {}, r.reference_no || '-'),
                        UI.el('td', {}, `${r.item.sku} — ${r.item.name}`),
                        UI.el('td', {}, UI.formatMoney(r.subtotal)),
                    ]));
                    body.appendChild(UI.el('div', { class: 'table-wrapper hpp-trace-detail-table' }, [
                        UI.el('table', {}, [
                            UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Referensi', 'Barang', 'Nilai'].map((h) => UI.el('th', {}, h)))]),
                            UI.el('tbody', {}, rows),
                        ]),
                    ]));
                    body.querySelectorAll('.hpp-trace-detail-row').forEach((row) => {
                        row.addEventListener('click', () => TraceDrawer.openTransaction(Number(row.dataset.txId)));
                        row.style.cursor = 'pointer';
                    });
                },
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
