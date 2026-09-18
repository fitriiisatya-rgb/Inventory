/**
 * PHASE V2 — "Stok Barang" / Laporan Stok. GET /reports/stock via
 * DataTable (server-side pagination/sort/filter), row click opens the
 * item detail drawer (Overview/FIFO Layers/Movement tabs).
 */
const StockReport = (() => {
    function render(container) {
        container.innerHTML = '';

        const warehouseOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const categoryOptions = Master.categories().filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }));
        const statusOptions = [
            { value: 'SAFE', label: 'Aman' }, { value: 'LOW', label: 'Warning' },
            { value: 'CRITICAL', label: 'Kritis' }, { value: 'OUT_OF_STOCK', label: 'Habis' },
            { value: 'MIGRATION_NEGATIVE_REVIEW', label: 'Review' },
        ];

        const summaryBox = UI.el('div', { class: 'grid-4', style: 'margin-bottom:14px;' });

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📦 Stok Barang')]),
            summaryBox,
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        const isStock = Auth.user().role_code === 'STOCK';
        const filters = [
            { key: 'q', label: 'Cari SKU / Nama', type: 'text', placeholder: 'Cari SKU / Nama Barang' },
            { key: 'category_id', label: 'Kategori', type: 'select', options: categoryOptions, placeholder: 'Semua Kategori' },
            { key: 'status', label: 'Status', type: 'select', options: statusOptions, placeholder: 'Semua Status' },
        ];
        if (!isStock) {
            filters.splice(1, 0, { key: 'warehouse_id', label: 'Gudang', type: 'select', options: warehouseOptions, placeholder: 'Semua Gudang (Company-wide)' });
        }

        DataTable.render(tableHost, {
            storageKey: 'dt-stock-report',
            filters,
            columns: [
                { key: 'sku', label: 'SKU', sortable: true, render: (r) => r.sku },
                { key: 'name', label: 'Nama Barang', sortable: true, render: (r) => r.name },
                { key: 'category', label: 'Kategori', defaultVisible: true, render: (r) => (r.category ? r.category.name : '—') },
                { key: 'unit', label: 'Satuan', render: (r) => r.unit.code },
                { key: 'qty', label: 'Stok', sortable: true, render: (r) => UI.formatNumber(r.qty_base) },
                { key: 'minimum_stock', label: 'Minimum', render: (r) => UI.formatNumber(r.minimum_stock) },
                { key: 'buffer_stock', label: 'Buffer', render: (r) => UI.bufferCell(r.buffer_configured, r.buffer_stock) },
                { key: 'status', label: 'Status', sortable: true, render: (r) => UI.stockStatusBadge(r.status) },
                { key: 'average_cost', label: 'Avg Cost', defaultVisible: false, render: (r) => (r.average_cost !== null ? UI.formatMoney(r.average_cost) : '—') },
                { key: 'value', label: 'Nilai Stok', sortable: true, render: (r) => UI.formatMoney(r.value) },
                { key: 'last_movement', label: 'Terakhir Bergerak', defaultVisible: false, render: (r) => UI.formatDate(r.last_movement) },
            ],
            fetchPage: async ({ page, perPage, sort, dir, filters: f }) => {
                const sortMap = { sku: 'sku', name: 'name', qty: 'qty', value: 'value', status: 'status' };
                const params = { page, per_page: perPage, sort: sortMap[sort] || 'name', dir, ...f };
                if (isStock) delete params.warehouse_id;
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                const result = await InvApi.stockReport(params);
                renderSummary(summaryBox, result.summary);
                return result;
            },
            exportHref: (f) => {
                const params = { ...f };
                if (isStock) delete params.warehouse_id;
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                return InvApi.stockReportCsvUrl(params);
            },
            onRowClick: (row) => openItemDetail(row),
            emptyMessage: 'Tidak ada barang yang cocok dengan filter ini.',
        });
    }

    function renderSummary(box, summary) {
        box.innerHTML = '';
        if (!summary) return;
        const cards = [
            ['Total Item', UI.formatNumber(summary.total_items, 0)],
            ['Item Ada Stok', UI.formatNumber(summary.items_with_stock, 0)],
            ['Total Nilai', UI.formatMoney(summary.total_value)],
            ['Perlu Perhatian', UI.formatNumber(summary.out_of_stock_count + summary.critical_count + summary.review_count, 0)],
        ];
        cards.forEach(([label, value]) => {
            box.appendChild(UI.el('div', { class: 'kpi-card' }, [
                UI.el('div', { class: 'kpi-label' }, label),
                UI.el('div', { class: 'kpi-value' }, value),
            ]));
        });
    }

    async function openItemDetail(row) {
        const warehouseId = Auth.user().role_code === 'STOCK' ? Auth.user().warehouse_id : (Master.warehouses()[0] && Master.warehouses()[0].id);
        Drawer.open({
            title: `${row.sku} — ${row.name}`,
            tabs: [
                { key: 'overview', label: 'Overview', render: (body) => renderOverviewTab(body, row) },
                { key: 'fifo', label: 'FIFO Layers', render: (body) => renderFifoTab(body, row, warehouseId) },
                { key: 'movement', label: 'Movement', render: (body) => renderMovementTab(body, row, warehouseId) },
            ],
        });
    }

    function renderOverviewTab(body, row) {
        body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
            ['Stok Saat Ini', UI.formatNumber(row.qty_base)],
            ['Rata-rata Biaya', row.average_cost !== null ? UI.formatMoney(row.average_cost) : '—'],
            ['Nilai Stok', UI.formatMoney(row.value)],
            ['Minimum', UI.formatNumber(row.minimum_stock)],
            ['Buffer', UI.bufferCell(row.buffer_configured, row.buffer_stock)],
            ['Status', UI.stockStatusBadge(row.status)],
            ['Terakhir Masuk', UI.formatDate(row.last_in)],
            ['Terakhir Keluar', UI.formatDate(row.last_out)],
        ])));
    }

    async function renderFifoTab(body, row, warehouseId) {
        body.innerHTML = '<div class="alert alert-info">Memuat FIFO layers...</div>';
        if (!warehouseId) {
            body.innerHTML = '<div class="alert alert-warning">Pilih tampilan per-gudang untuk melihat FIFO layers.</div>';
            return;
        }
        try {
            const batches = await InvApi.batches(row.item_id, warehouseId);
            body.innerHTML = '';
            const note = UI.el('p', { style: 'color:var(--text3); font-size:0.78rem; margin-bottom:10px;' }, 'FIFO layer bersifat read-only — tidak dapat diubah manual. Koreksi hanya melalui Stock Opname / Stock Adjustment.');
            const rows = batches.map((b) => UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(b.received_date)),
                UI.el('td', {}, UI.formatNumber(b.qty_base)),
                UI.el('td', {}, UI.formatMoney(b.unit_cost_base)),
                UI.el('td', {}, UI.formatMoney(b.qty_base * b.unit_cost_base)),
            ]));
            body.appendChild(note);
            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Received Date', 'Qty Sisa', 'Unit Cost', 'Nilai'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Tidak ada layer aktif')])]),
                ]),
            ]));
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat FIFO layers: ${(err && err.message) || ''}</div>`;
        }
    }

    async function renderMovementTab(body, row, warehouseId) {
        body.innerHTML = '<div class="alert alert-info">Memuat riwayat pergerakan...</div>';
        try {
            const result = await InvApi.transactionReport({ item_id: row.item_id, per_page: 20, sort: 'date', dir: 'desc', ...(warehouseId ? { warehouse_id: warehouseId } : {}) });
            body.innerHTML = '';
            const rows = (result.rows || []).map((t) => UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(t.transaction_date)),
                UI.el('td', {}, t.transaction_type),
                UI.el('td', {}, UI.formatNumber(t.input_qty) + ' ' + t.input_unit.code),
                UI.el('td', {}, t.warehouse.name),
            ]));
            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Jenis', 'Qty', 'Gudang'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Belum ada pergerakan')])]),
                ]),
            ]));
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat pergerakan: ${(err && err.message) || ''}</div>`;
        }
    }

    return { render };
})();
