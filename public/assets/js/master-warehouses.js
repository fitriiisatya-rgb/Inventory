/**
 * PHASE V2.1 — Master Gudang: enhanced warehouse-master list (search code/
 * name, active filter, sort by name/SKU-count/qty/value, Detail/Edit/
 * Aktifkan-Nonaktifkan/Hapus Permanen). Never creates or lists Karang
 * Tengah — this only reads whatever warehouses already exist (GET
 * /warehouses/report), same as every other warehouse read in the app.
 * Edit is limited to name/is_active — code is immutable master data.
 */
const MasterWarehouses = (() => {
    let dtHandle = null;

    function render(container) {
        container.innerHTML = '';
        const canManage = Auth.hasPermission('MASTER_WAREHOUSE_MANAGE');

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🏬 Master Gudang')]),
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-master-warehouses',
            filters: [
                { key: 'q', label: 'Cari', type: 'text', placeholder: 'Cari Kode / Nama Gudang' },
                { key: 'active', label: 'Status', type: 'select', placeholder: 'Semua Status', options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }] },
            ],
            defaultSort: 'name',
            defaultDir: 'asc',
            pageSize: 25,
            columns: [
                { key: 'code', label: 'Code', render: (r) => r.code },
                { key: 'name', label: 'Warehouse', sortable: true, render: (r) => r.name },
                { key: 'sku_count', label: 'Active SKU', sortable: true, render: (r) => UI.formatNumber(r.sku_count, 0) },
                { key: 'qty', label: 'Qty on Hand', sortable: true, render: (r) => UI.formatNumber(r.qty_on_hand) },
                { key: 'value', label: 'Inventory Value', sortable: true, render: (r) => UI.formatMoney(r.inventory_value) },
                { key: 'is_active', label: 'Status', render: (r) => MasterCommon.statusBadge(r.is_active) },
                { key: 'actions', label: 'Aksi', render: (r) => buildActions(r, canManage) },
            ],
            fetchPage: async ({ page, perPage, sort, dir, filters: f }) => {
                const params = { ...f, sort, dir };
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                const result = await InvApi.warehousesReport(params);
                // WarehouseReportService returns the full unpaginated row set
                // (company only ever has a handful of warehouses) — slice
                // client-side into DataTable's page shape rather than adding
                // LIMIT/OFFSET server-side for a list this small.
                const total = result.rows.length;
                const start = (page - 1) * perPage;
                return {
                    rows: result.rows.slice(start, start + perPage),
                    pagination: { page, per_page: perPage, total, total_pages: Math.max(1, Math.ceil(total / perPage)) },
                };
            },
            onRowClick: (row) => openDetail(row),
            emptyMessage: 'Tidak ada gudang yang cocok dengan filter ini.',
        });
    }

    function buildActions(row, canManage) {
        return MasterCommon.actionsMenu([
            { label: 'Detail', onClick: () => openDetail(row) },
            canManage ? { label: 'Edit', onClick: () => openEdit(row) } : null,
            canManage ? { label: row.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(row) } : null,
            canManage ? { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(row) } : null,
        ]);
    }

    function openDetail(row) {
        Drawer.open({
            title: `${row.code} — ${row.name}`,
            render: (body) => {
                body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                    ['Code', row.code],
                    ['Warehouse', row.name],
                    ['Active SKU', UI.formatNumber(row.sku_count, 0)],
                    ['Qty on Hand', UI.formatNumber(row.qty_on_hand)],
                    ['Inventory Value', UI.formatMoney(row.inventory_value)],
                    ['Status', MasterCommon.statusBadge(row.is_active)],
                ])));
            },
        });
    }

    async function openEdit(row) {
        const values = await MasterCommon.formModal({
            title: `✏️ Edit Gudang: ${row.name}`,
            submitLabel: 'Simpan',
            initial: { name: row.name, is_active: row.is_active ? 'ACTIVE' : 'INACTIVE' },
            fields: [
                { key: 'name', label: 'Nama Gudang', type: 'text', required: true },
                { key: 'is_active', label: 'Status', type: 'select', allowEmpty: false, options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }] },
            ],
        });
        if (!values) return;
        try {
            await InvApi.updateWarehouse(row.id, { name: values.name, is_active: values.is_active === 'ACTIVE' });
            UI.toast('Gudang berhasil diperbarui.', 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function toggleActive(row) {
        const confirmed = row.is_active
            ? await MasterCommon.confirmDeactivate(`gudang "${row.name}"`)
            : await MasterCommon.confirmActivate(`gudang "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateWarehouse(row.id, { is_active: !row.is_active });
            UI.toast(`Gudang berhasil ${row.is_active ? 'dinonaktifkan' : 'diaktifkan'}.`, 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function doDelete(row) {
        const confirmed = await MasterCommon.confirmDeletePermanent(`gudang "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.deleteWarehouse(row.id);
            UI.toast('Gudang berhasil dihapus permanen.', 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            const handled = await MasterCommon.handleDeleteError(err);
            if (!handled) UI.handleApiError(err);
        }
    }

    return { render };
})();
