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
        const canManage = Auth.hasPermission('MASTER_ITEM_MANAGE');
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
                body.appendChild(Drawer.section('📷 Barcode / Satuan', buildBarcodeSection(row, canManage)));
            },
        });
    }

    // ============================================================
    // PHASE V2.10 — Part C5: Master Barang barcode maintenance
    // (add/edit/activate-deactivate, choose-unit, duplicate-validation).
    // Reads from the already-loaded Master.itemBarcodes() cache (Part A4
    // convention); every mutation goes through POST/PUT /item-barcodes,
    // gated on MASTER_ITEM_MANAGE (same permission this whole page's
    // Edit/Aktifkan/Hapus actions already require), then reloads that one
    // cache and fully re-renders the drawer — no separate barcode-list
    // endpoint, no duplicate-validation logic on the frontend (the backend
    // is the real guard; a duplicate submit here simply surfaces the
    // server's ValidationException).
    // ============================================================
    function buildBarcodeSection(row, canManage) {
        const wrap = UI.el('div', {});
        const listHost = UI.el('div', { class: 'table-wrapper', style: 'margin-bottom:12px;' });
        wrap.appendChild(listHost);
        renderBarcodeList(listHost, row, canManage);

        if (canManage) {
            const addBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '+ Tambah Barcode');
            addBtn.addEventListener('click', () => openBarcodeForm(row, null));
            wrap.appendChild(addBtn);
        }
        return wrap;
    }

    function renderBarcodeList(host, row, canManage) {
        host.innerHTML = '';
        const mappings = Master.itemBarcodes().filter((b) => Number(b.item_id) === Number(row.item_id));
        if (mappings.length === 0) {
            host.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.82rem; padding:8px 0;' }, 'Belum ada barcode terdaftar untuk barang ini.'));
            return;
        }
        const unitLabel = (b) => (b.unit_id ? `${b.unit_code || ''} ${b.unit_name ? `(${b.unit_name})` : ''}`.trim() : 'Semua Satuan');
        const rows = mappings.map((b) => UI.el('tr', {}, [
            UI.el('td', {}, b.barcode),
            UI.el('td', {}, unitLabel(b)),
            UI.el('td', {}, MasterCommon.statusBadge(!!b.is_active)),
            UI.el('td', {}, canManage ? MasterCommon.actionsMenu([
                { label: 'Edit', onClick: () => openBarcodeForm(row, b) },
                { label: b.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleBarcodeActive(row, b) },
            ]) : ''),
        ]));
        host.appendChild(UI.el('table', {}, [
            UI.el('thead', {}, [UI.el('tr', {}, ['Barcode', 'Satuan', 'Status', canManage ? 'Aksi' : ''].map((h) => UI.el('th', {}, h)))]),
            UI.el('tbody', {}, rows),
        ]));
    }

    async function openBarcodeForm(row, existing) {
        let units = [];
        try {
            units = await InvApi.itemUnits(row.item_id);
        } catch (err) {
            UI.handleApiError(err);
            return;
        }
        const unitOptions = units.map((u) => ({ value: u.id, label: `${u.code} (${u.name})` }));

        const values = await MasterCommon.formModal({
            title: existing ? `✏️ Edit Barcode — ${row.sku}` : `+ Tambah Barcode — ${row.sku}`,
            submitLabel: 'Simpan',
            initial: {
                barcode: existing ? existing.barcode : '',
                unit_id: existing && existing.unit_id ? existing.unit_id : '',
            },
            fields: [
                { key: 'barcode', label: 'Barcode', type: 'text', required: true },
                { key: 'unit_id', label: 'Satuan (kosongkan = berlaku untuk semua satuan)', type: 'select', options: unitOptions, emptyLabel: 'Semua Satuan' },
            ],
        });
        if (!values) return;

        try {
            const payload = { barcode: values.barcode, unit_id: values.unit_id !== null ? Number(values.unit_id) : null };
            if (existing) {
                await InvApi.updateItemBarcode(existing.id, payload);
                UI.toast('Barcode berhasil diperbarui.', 'success');
            } else {
                await InvApi.createItemBarcode({ ...payload, item_id: row.item_id });
                UI.toast('Barcode berhasil ditambahkan.', 'success');
            }
            await Master.reloadItemBarcodes();
            openDetail(row);
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function toggleBarcodeActive(row, mapping) {
        const isActive = !!mapping.is_active;
        const confirmed = isActive
            ? await MasterCommon.confirmDeactivate(`barcode "${mapping.barcode}"`)
            : await MasterCommon.confirmActivate(`barcode "${mapping.barcode}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateItemBarcode(mapping.id, { is_active: !isActive });
            UI.toast(`Barcode berhasil ${isActive ? 'dinonaktifkan' : 'diaktifkan'}.`, 'success');
            await Master.reloadItemBarcodes();
            openDetail(row);
        } catch (err) {
            UI.handleApiError(err);
        }
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
