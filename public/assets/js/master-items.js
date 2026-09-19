/**
 * PHASE V2.1 — Master Barang: the enhanced item-master list per the owner's
 * spec (search SKU/name/barcode, warehouse/category/vendor/active/stock-
 * status filters, server-side sort+pagination, Detail/Edit/Aktifkan-
 * Nonaktifkan/Hapus Permanen actions). Reuses GET /items/report, which
 * itself reuses StockReportService — same single source of truth as "Stok
 * Barang" for stock/status computation, never a second implementation.
 *
 * Editing here is deliberately limited to name/category/supplier/barcode/
 * status (PUT /items/{id}'s own whitelist) — minimum/buffer stay on the
 * Stock Policy workflow, and historical FIFO qty/cost is never touched
 * from Master Barang.
 */
const MasterItems = (() => {
    let currentWarehouseId = null; // last fetch's resolved scope (null = company-wide)
    let dtHandle = null;

    function scopeLabel() {
        if (currentWarehouseId === null) return 'Semua Gudang';
        const wh = Master.warehouseById(currentWarehouseId);
        return wh ? wh.name : `Gudang #${currentWarehouseId}`;
    }

    function render(container) {
        container.innerHTML = '';
        const isStock = Auth.user().role_code === 'STOCK';
        const canManage = Auth.hasPermission('MASTER_ITEM_MANAGE');

        const warehouseOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const categoryOptions = Master.categories().filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }));
        const supplierOptions = Master.suppliers().filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name }));

        const summaryBox = UI.el('div', { class: 'grid-4', style: 'margin-bottom:14px;' });
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📦 Master Barang')]),
            summaryBox,
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        const filters = [
            { key: 'q', label: 'Cari', type: 'text', placeholder: 'Cari SKU / Nama / Barcode' },
            { key: 'category_id', label: 'Kategori', type: 'select', options: categoryOptions, placeholder: 'Semua Kategori' },
            { key: 'supplier_id', label: 'Vendor', type: 'select', options: supplierOptions, placeholder: 'Semua Vendor' },
            { key: 'active', label: 'Status Item', type: 'select', options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }], placeholder: 'Semua Status Item' },
            {
                key: 'stock_status_ui', label: 'Status Stock', type: 'select', placeholder: 'Semua Status Stock', options: [
                    { value: 'HAS_STOCK', label: 'Ada Stok' },
                    { value: 'ZERO_STOCK', label: 'Stok 0' },
                    { value: 'CRITICAL', label: 'Di Bawah Minimum' },
                    { value: 'NEEDS_ATTENTION', label: 'Need Attention' },
                    { value: 'MIGRATION_NEGATIVE_REVIEW', label: 'Migration Negative Review' },
                ],
            },
        ];
        if (!isStock) {
            filters.splice(1, 0, { key: 'warehouse_id', label: 'Gudang', type: 'select', options: warehouseOptions, placeholder: 'Semua Gudang (Company-wide)' });
        }

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-master-items',
            filters,
            defaultSort: 'name',
            defaultDir: 'asc',
            pageSize: 25,
            columns: [
                { key: 'sku', label: 'SKU', sortable: true, render: (r) => r.sku },
                { key: 'name', label: 'Nama Barang', sortable: true, render: (r) => r.name },
                { key: 'category', label: 'Kategori', render: (r) => (r.category ? r.category.name : '—') },
                { key: 'unit', label: 'Base Unit', render: (r) => r.unit.code },
                { key: 'supplier', label: 'Vendor', render: (r) => (r.supplier ? r.supplier.name : '—') },
                { key: 'scope', label: 'Gudang/Scope', render: () => scopeLabel() },
                { key: 'qty', label: 'Qty Stock', sortable: true, render: (r) => UI.formatNumber(r.qty_base) },
                { key: 'minimum_stock', label: 'Minimum', render: (r) => UI.formatNumber(r.minimum_stock) },
                { key: 'buffer_stock', label: 'Buffer', render: (r) => UI.bufferCell(r.buffer_configured, r.buffer_stock) },
                { key: 'status', label: 'Status Stock', sortable: true, render: (r) => UI.stockStatusBadge(r.status) },
                { key: 'item_status', label: 'Status Item', render: (r) => MasterCommon.statusBadge(r.item_status === 'ACTIVE') },
                { key: 'updated_at', label: 'Terakhir Update', sortable: true, defaultVisible: false, render: (r) => UI.formatDate(r.updated_at) },
                { key: 'actions', label: 'Aksi', render: (r) => buildActions(r, canManage) },
            ],
            fetchPage: async ({ page, perPage, sort, dir, filters: f }) => {
                const params = { page, per_page: perPage, sort, dir, ...f };
                if (isStock) delete params.warehouse_id;
                const uiStatus = params.stock_status_ui;
                delete params.stock_status_ui;
                if (uiStatus === 'HAS_STOCK' || uiStatus === 'ZERO_STOCK' || uiStatus === 'NEEDS_ATTENTION') {
                    params.stock_status = uiStatus;
                } else if (uiStatus === 'CRITICAL' || uiStatus === 'MIGRATION_NEGATIVE_REVIEW') {
                    params.status = uiStatus;
                }
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                const result = await InvApi.itemsReport(params);
                currentWarehouseId = result.warehouse_id;
                renderSummary(summaryBox, result.summary);
                return result;
            },
            onRowClick: (row) => openDetail(row),
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

    function buildActions(row, canManage) {
        const isActive = row.item_status === 'ACTIVE';
        return MasterCommon.actionsMenu([
            { label: 'Detail', onClick: () => openDetail(row) },
            { label: 'Lihat Jejak', onClick: () => TraceDrawer.openEntity('item', row.item_id) },
            canManage ? { label: 'Edit', onClick: () => openEdit(row) } : null,
            canManage ? { label: isActive ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(row) } : null,
            canManage ? { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(row) } : null,
        ]);
    }

    function openDetail(row) {
        Drawer.open({
            title: `${row.sku} — ${row.name}`,
            render: (body) => {
                body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                    ['SKU', row.sku],
                    ['Nama Barang', row.name],
                    ['Kategori', row.category ? row.category.name : '—'],
                    ['Vendor', row.supplier ? row.supplier.name : '—'],
                    ['Base Unit', row.unit.code],
                    ['Gudang/Scope', scopeLabel()],
                    ['Qty Stock', UI.formatNumber(row.qty_base)],
                    ['Rata-rata Biaya', row.average_cost !== null ? UI.formatMoney(row.average_cost) : '—'],
                    ['Nilai Stok', UI.formatMoney(row.value)],
                    ['Minimum', UI.formatNumber(row.minimum_stock)],
                    ['Buffer', UI.bufferCell(row.buffer_configured, row.buffer_stock)],
                    ['Status Stock', UI.stockStatusBadge(row.status)],
                    ['Status Item', MasterCommon.statusBadge(row.item_status === 'ACTIVE')],
                    ['Terakhir Masuk', UI.formatDate(row.last_in)],
                    ['Terakhir Keluar', UI.formatDate(row.last_out)],
                    ['Terakhir Update', UI.formatDate(row.updated_at)],
                ])));
            },
        });
    }

    async function openEdit(row) {
        const categoryOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));
        const supplierOptions = Master.suppliers().map((s) => ({ value: s.id, label: s.name }));
        const fullItem = Master.itemById(row.item_id); // GET /items includes barcode; the report row doesn't

        const values = await MasterCommon.formModal({
            title: `✏️ Edit Barang: ${row.name}`,
            submitLabel: 'Simpan',
            initial: {
                name: row.name,
                category_id: row.category ? row.category.id : '',
                default_supplier_id: row.supplier ? row.supplier.id : '',
                barcode: fullItem ? (fullItem.barcode || '') : '',
                status: row.item_status,
            },
            fields: [
                { key: 'name', label: 'Nama Barang', type: 'text', required: true },
                { key: 'category_id', label: 'Kategori', type: 'select', options: categoryOptions, emptyLabel: 'Tanpa Kategori' },
                { key: 'default_supplier_id', label: 'Vendor', type: 'select', options: supplierOptions, emptyLabel: 'Tanpa Vendor' },
                { key: 'barcode', label: 'Barcode', type: 'text' },
                { key: 'status', label: 'Status Item', type: 'select', allowEmpty: false, options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }] },
            ],
        });
        if (!values) return;

        try {
            await InvApi.updateItem(row.item_id, {
                name: values.name,
                category_id: values.category_id !== null ? Number(values.category_id) : null,
                default_supplier_id: values.default_supplier_id !== null ? Number(values.default_supplier_id) : null,
                barcode: values.barcode || null,
                status: values.status,
            });
            UI.toast('Barang berhasil diperbarui.', 'success');
            if (dtHandle) dtHandle.reload();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function toggleActive(row) {
        const isActive = row.item_status === 'ACTIVE';
        const confirmed = isActive
            ? await MasterCommon.confirmDeactivate(`barang "${row.name}"`)
            : await MasterCommon.confirmActivate(`barang "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateItem(row.item_id, { status: isActive ? 'INACTIVE' : 'ACTIVE' });
            UI.toast(`Barang berhasil ${isActive ? 'dinonaktifkan' : 'diaktifkan'}.`, 'success');
            if (dtHandle) dtHandle.reload();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function doDelete(row) {
        const confirmed = await MasterCommon.confirmDeletePermanent(`barang "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.deleteItem(row.item_id);
            UI.toast('Barang berhasil dihapus permanen.', 'success');
            if (dtHandle) dtHandle.reload();
        } catch (err) {
            const handled = await MasterCommon.handleDeleteError(err);
            if (!handled) UI.handleApiError(err);
        }
    }

    return { render };
})();
