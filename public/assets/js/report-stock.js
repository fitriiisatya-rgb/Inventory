/**
 * PHASE V2.6D — "Laporan Stok" rebuilt into a practical stock-control
 * page: every ACTIVE product (including zero-stock, including negative
 * migration-review items — never hidden), Stok Tersedia next to Stok
 * Minimal, an AMAN/WARNING/HABIS classification, clickable Stok Tersedia
 * opening Kartu Stok (StockCard.js), and Semua/Aman/Warning/Habis
 * counters computed over the full filtered dataset (never just the
 * current page).
 *
 * Still 100% reuse: GET /reports/stock (StockReportService, unchanged
 * FIFO/valuation), GET /reports/stock/status-counts (same service, a new
 * aggregate query — see its own docblock), GET /reports/stock/card +
 * StockCard.js (InventoryService::ledger()/currentStock(), the SAME
 * ledger the "Mutasi Stok / Ledger" tab already uses). No new inventory
 * computation anywhere in this file.
 */
const ReportStock = (() => {
    let dtHandle = null;

    function render(container) {
        container.innerHTML = '';
        const user = Auth.user();
        const isStock = user.role_code === 'STOCK';

        // Karang Tengah (or any other PENDING_CUTOVER warehouse) is
        // is_active=0 and must never appear as a selectable "live"
        // warehouse — filtered here, at the one place this page builds
        // its own Gudang dropdown.
        const activeWarehouses = Master.warehouses().filter((w) => Number(w.is_active) === 1);
        const whOptions = activeWarehouses.map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));

        container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, 'Laporan Stok'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Semua produk (termasuk stok 0), status Aman/Warning/Habis, dan Kartu Stok — data dari Master Barang & FIFO batch.'),
            ]),
        ]));

        const counterRow = UI.el('div', { class: 'stock-counter-row' });
        container.appendChild(counterRow);

        const tableHost = UI.el('div', { class: 'card' });
        container.appendChild(tableHost);

        const filters = [];
        if (!isStock) {
            filters.push({ key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions, placeholder: 'Semua Gudang' });
        }
        filters.push({ key: 'category_id', label: 'Kategori', type: 'select', options: catOptions, placeholder: 'Semua Kategori' });
        filters.push({ key: 'q', label: 'Cari SKU / Nama Produk', type: 'text', placeholder: 'Cari SKU / Nama Produk...' });
        filters.push({
            key: 'item_status', label: 'Status Produk', type: 'select', placeholder: 'Aktif (default)',
            options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Nonaktif' }, { value: 'ALL', label: 'Semua' }],
        });

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-report-stock-v2',
            filters,
            pageSize: 50,
            defaultSort: 'name',
            columns: [
                { key: 'sku', label: 'SKU', sortable: true, render: (r) => r.sku },
                { key: 'name', label: 'Nama Produk', sortable: true, render: (r) => r.name },
                { key: 'category', label: 'Kategori', render: (r) => (r.category && r.category.name) || '-' },
                { key: 'unit', label: 'Satuan', render: (r) => r.unit.code },
                { key: 'qty_base', label: 'Stok Tersedia', sortable: true, render: (r) => stockLink(r) },
                { key: 'minimum_stock', label: 'Stok Minimal', render: (r) => UI.formatNumber(r.minimum_stock) },
                { key: 'report_status', label: 'Status', render: (r) => StockCard.statusBadge(r.report_status) },
                { key: 'value', label: 'Nilai Stok', sortable: true, render: (r) => UI.formatMoney(r.value) },
            ],
            fetchPage: async ({ page, perPage, filters }) => {
                const params = requestParams(filters, page, perPage);
                const result = await InvApi.stockReport(params);
                return { rows: result.rows, pagination: result.pagination };
            },
            exportHref: (filters) => InvApi.stockReportExportUrl(requestParams(filters, undefined, undefined, true)),
            onLoaded: () => loadCounters(dtHandle.getFilters()),
            emptyMessage: 'Tidak ada produk untuk filter ini.',
        });

        loadCounters({});
    }

    /** Builds the actual API query object from DataTable's raw filters (item_status='ALL' means "omit the filter", not a literal value). */
    function requestParams(filters, page, perPage, forExport) {
        const p = Object.assign({}, filters);
        if (p.item_status === 'ALL') delete p.item_status;
        if (!forExport) {
            if (page !== undefined) p.page = page;
            if (perPage !== undefined) p.per_page = perPage;
        }
        Object.keys(p).forEach((k) => { if (p[k] === undefined || p[k] === '') delete p[k]; });
        return p;
    }

    async function loadCounters(rawFilters) {
        // Counters ignore report_status itself (see StockReportService::
        // statusCounts()'s docblock) — they must stay stable reference
        // points while the table narrows.
        const params = requestParams(rawFilters);
        delete params.report_status;
        let counts;
        try {
            counts = await InvApi.stockStatusCounts(params);
        } catch (err) {
            UI.handleApiError(err);
            return;
        }
        renderCounters(counts, rawFilters.report_status);
    }

    function renderCounters(counts, activeStatus) {
        const host = document.querySelector('#tab-laporan-stok .stock-counter-row') || document.querySelector('.stock-counter-row');
        if (!host) return;
        host.innerHTML = '';
        const chips = [
            { key: undefined, label: 'Semua Produk', value: counts.total },
            { key: 'AMAN', label: 'Aman', value: counts.aman },
            { key: 'WARNING', label: 'Warning', value: counts.warning },
            { key: 'HABIS', label: 'Habis', value: counts.habis },
        ];
        chips.forEach((c) => {
            const isActive = (c.key || undefined) === (activeStatus || undefined);
            const chip = UI.el('div', { class: `stock-counter-chip${isActive ? ' active' : ''}` }, [
                c.label, UI.el('span', { class: 'stock-counter-value' }, UI.formatNumber(c.value, 0)),
            ]);
            chip.addEventListener('click', () => {
                if (!dtHandle) return;
                dtHandle.setFilter('report_status', c.key);
            });
            host.appendChild(chip);
        });
    }

    function stockLink(row) {
        const el = UI.el('span', { class: 'stock-qty-link' }, UI.formatNumber(row.qty_base));
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            openStockClick(row);
        });
        return el;
    }

    function openStockClick(row) {
        const filters = dtHandle ? dtHandle.getFilters() : {};
        const user = Auth.user();
        const isStock = user.role_code === 'STOCK';
        const selectedWh = filters.warehouse_id ? Number(filters.warehouse_id) : (isStock ? Number(user.warehouse_id) : null);
        const ctx = {
            item_id: row.item_id, sku: row.sku, name: row.name,
            category: row.category && row.category.name, unit: row.unit.code,
            minimum_stock: row.minimum_stock,
        };
        if (selectedWh) {
            const wh = Master.warehouseById(selectedWh);
            StockCard.open(Object.assign({}, ctx, { warehouse_id: selectedWh, warehouse_name: (wh && wh.name) || `#${selectedWh}` }));
        } else {
            StockCard.openWarehouseBreakdown(ctx);
        }
    }

    return { render };
})();
