/**
 * PHASE V2 — Master Kategori. List + "Tambah Kategori" / Edit in a compact modal (MasterCommon.recordModal).
 * items.category (the original free-text field) is never touched from
 * here — this only manages the normalized categories table and each
 * item's category_id, via the item detail drawer / stock report (not
 * this page, which is category-master-only).
 */
const MasterCategories = (() => {
    let filters = { q: '', active: '', sort: 'name' };
    let tableHost = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat kategori...</div>';
        try {
            container.innerHTML = '';
            container.appendChild(MasterCommon.pageHeader({
                title: 'Kategori', description: 'Kelola data kategori barang.', buttonLabel: 'Tambah Kategori',
                canCreate: Auth.hasPermission('MASTER_CATEGORY_MANAGE'), onCreate: () => openForm(null),
            }));
            container.appendChild(buildToolbar());
            tableHost = UI.el('div');
            container.appendChild(tableHost);
            await reload();
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat kategori: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildToolbar() {
        const qInput = UI.el('input', { type: 'text', placeholder: 'Cari kode / nama kategori' });
        qInput.value = filters.q;
        let debounce;
        qInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => { filters.q = qInput.value.trim(); reload(); }, 300);
        });
        const activeSelect = UI.el('select', { html: `
            <option value="">Semua Status</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Tidak Aktif</option>
        ` });
        activeSelect.value = filters.active;
        activeSelect.addEventListener('change', () => { filters.active = activeSelect.value; reload(); });
        const sortSelect = UI.el('select', { html: `
            <option value="name">Nama A-Z</option>
            <option value="name_desc">Nama Z-A</option>
            <option value="item_count">Jumlah Item Tertinggi</option>
        ` });
        sortSelect.value = filters.sort;
        sortSelect.addEventListener('change', () => { filters.sort = sortSelect.value; reload(); });
        const resetBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Reset Filter');
        resetBtn.addEventListener('click', () => {
            filters = { q: '', active: '', sort: 'name' };
            qInput.value = ''; activeSelect.value = ''; sortSelect.value = 'name';
            reload();
        });
        return UI.el('div', { class: 'dt-toolbar' }, [
            UI.el('div', { class: 'dt-filters' }, [qInput, activeSelect, sortSelect, resetBtn]),
        ]);
    }

    async function reload() {
        if (!tableHost) return;
        try {
            const dir = filters.sort === 'name_desc' ? 'desc' : (filters.sort === 'item_count' ? 'desc' : 'asc');
            const sort = filters.sort === 'name_desc' ? 'name' : filters.sort;
            const categories = await InvApi.listCategories({ search: filters.q, active: filters.active, sort, dir });
            tableHost.innerHTML = '';
            tableHost.appendChild(buildTable(categories));
        } catch (err) {
            UI.handleApiError(err);
            tableHost.innerHTML = `<div class="alert alert-error">Gagal memuat kategori: ${(err && err.message) || ''}</div>`;
        }
    }

    // Create + Edit share one compact modal. The category code is fixed once created (the API only
    // updates the name / status), so it is read-only when editing.
    async function openForm(c) {
        const res = await MasterCommon.recordModal({
            title: c ? `Edit Kategori: ${c.name}` : 'Tambah Kategori',
            testid: 'mdm-modal-category',
            initial: c ? { code: c.code, name: c.name, is_active: !!c.is_active } : { is_active: true },
            fields: [
                { key: 'code', label: 'Kode Kategori', type: 'text', required: true, placeholder: 'Contoh: KTG005', maxLength: 60, ...(c ? { readonly: true } : {}) },
                { key: 'name', label: 'Nama Kategori', type: 'text', required: true, placeholder: 'Contoh: Cake', maxLength: 100 },
                { key: 'is_active', label: 'Status', type: 'toggle' },
            ],
            onSubmit: async (vals) => {
                if (c) await InvApi.updateCategory(c.id, { name: vals.name, is_active: !!vals.is_active });
                else await InvApi.createCategory({ code: vals.code, name: vals.name, is_active: !!vals.is_active });
                return true;
            },
        });
        if (!res) return;
        UI.toast(c ? 'Kategori berhasil diperbarui.' : 'Kategori berhasil ditambahkan.', 'success');
        Master.loadAll().catch(() => {});
        await reload();
    }

    function buildTable(categories) {
        const canManage = Auth.hasPermission('MASTER_CATEGORY_MANAGE');
        const canTrace = Auth.hasPermission('AUDIT_LOG_VIEW');
        const rows = categories.map((c) => UI.el('tr', {}, [
            UI.el('td', {}, c.code),
            UI.el('td', {}, c.name),
            UI.el('td', {}, UI.formatNumber(c.item_count ?? 0, 0)),
            UI.el('td', {}, MasterCommon.statusBadge(!!c.is_active)),
            UI.el('td', {}, (canManage || canTrace) ? MasterCommon.actionsMenu([
                canTrace ? { label: 'Lihat Jejak', onClick: () => TraceDrawer.openEntity('category', c.id) } : null,
                canManage ? { label: 'Edit', onClick: () => openForm(c) } : null,
                canManage ? { label: c.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(c) } : null,
                canManage ? { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(c) } : null,
            ]) : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `🏷️ Daftar Kategori (${categories.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'Jumlah Item', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada kategori — klik "+ Tambah Kategori" untuk membuat yang pertama.')])]),
                ]),
            ]),
        ]);
    }

    async function toggleActive(c) {
        const confirmed = c.is_active
            ? await MasterCommon.confirmDeactivate(`kategori "${c.name}"`)
            : await MasterCommon.confirmActivate(`kategori "${c.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateCategory(c.id, { is_active: !c.is_active });
            UI.toast('Status kategori diperbarui.', 'success');
            Master.loadAll().catch(() => {});
            await reload();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function doDelete(c) {
        const confirmed = await MasterCommon.confirmDeletePermanent(`kategori "${c.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.deleteCategory(c.id);
            UI.toast('Kategori berhasil dihapus permanen.', 'success');
            await reload();
        } catch (err) {
            const handled = await MasterCommon.handleDeleteError(err);
            if (!handled) UI.handleApiError(err);
        }
    }

    return { render };
})();
