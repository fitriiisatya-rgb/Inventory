/**
 * PHASE V2.1 — Master Divisi: search, Aktif/Tidak Aktif filter, A-Z/Z-A
 * sort, Edit, Activate/Deactivate, safe permanent delete when unused. No
 * stock-related filters — a division isn't a stock-holding location.
 */
const MasterDivisions = (() => {
    let dtHandle = null;

    function render(container) {
        container.innerHTML = '';
        const canManage = Auth.hasPermission('MASTER_DIVISION_MANAGE');

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🗂️ Master Divisi')]),
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-master-divisions',
            filters: [
                { key: 'q', label: 'Cari', type: 'text', placeholder: 'Cari Nama Divisi' },
                { key: 'active', label: 'Status', type: 'select', placeholder: 'Semua Status', options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }] },
            ],
            defaultSort: 'name',
            defaultDir: 'asc',
            pageSize: 25,
            columns: [
                { key: 'code', label: 'Kode', render: (r) => r.code },
                { key: 'name', label: 'Nama Divisi', sortable: true, render: (r) => r.name },
                { key: 'is_active', label: 'Status', render: (r) => MasterCommon.statusBadge(!!r.is_active) },
                { key: 'actions', label: 'Aksi', render: (r) => buildActions(r, canManage) },
            ],
            fetchPage: async ({ page, perPage, dir, filters: f }) => {
                const params = { search: f.q, active: f.active, dir };
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                const rows = await InvApi.listDivisions(params);
                const total = rows.length;
                const start = (page - 1) * perPage;
                return {
                    rows: rows.slice(start, start + perPage),
                    pagination: { page, per_page: perPage, total, total_pages: Math.max(1, Math.ceil(total / perPage)) },
                };
            },
            onRowClick: (row) => openDetail(row),
            emptyMessage: 'Tidak ada divisi yang cocok dengan filter ini.',
        });
    }

    function buildActions(row, canManage) {
        return MasterCommon.actionsMenu([
            { label: 'Detail', onClick: () => openDetail(row) },
            { label: 'Lihat Jejak', onClick: () => TraceDrawer.openEntity('division', row.id) },
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
                    ['Kode', row.code],
                    ['Nama Divisi', row.name],
                    ['Status', MasterCommon.statusBadge(!!row.is_active)],
                ])));
            },
        });
    }

    async function openEdit(row) {
        const values = await MasterCommon.formModal({
            title: `✏️ Edit Divisi: ${row.name}`,
            submitLabel: 'Simpan',
            initial: { name: row.name, is_active: row.is_active ? 'ACTIVE' : 'INACTIVE' },
            fields: [
                { key: 'name', label: 'Nama Divisi', type: 'text', required: true },
                { key: 'is_active', label: 'Status', type: 'select', allowEmpty: false, options: [{ value: 'ACTIVE', label: 'Aktif' }, { value: 'INACTIVE', label: 'Tidak Aktif' }] },
            ],
        });
        if (!values) return;
        try {
            await InvApi.updateDivision(row.id, { name: values.name, is_active: values.is_active === 'ACTIVE' });
            UI.toast('Divisi berhasil diperbarui.', 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function toggleActive(row) {
        const confirmed = row.is_active
            ? await MasterCommon.confirmDeactivate(`divisi "${row.name}"`)
            : await MasterCommon.confirmActivate(`divisi "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateDivision(row.id, { is_active: !row.is_active });
            UI.toast(`Divisi berhasil ${row.is_active ? 'dinonaktifkan' : 'diaktifkan'}.`, 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function doDelete(row) {
        const confirmed = await MasterCommon.confirmDeletePermanent(`divisi "${row.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.deleteDivision(row.id);
            UI.toast('Divisi berhasil dihapus permanen.', 'success');
            if (dtHandle) dtHandle.reload();
            Master.loadAll().catch(() => {});
        } catch (err) {
            const handled = await MasterCommon.handleDeleteError(err);
            if (!handled) UI.handleApiError(err);
        }
    }

    return { render };
})();
