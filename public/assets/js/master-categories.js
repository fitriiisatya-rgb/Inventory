/**
 * PHASE V2 — Master Kategori. List + inline create/edit form.
 * items.category (the original free-text field) is never touched from
 * here — this only manages the normalized categories table and each
 * item's category_id, via the item detail drawer / stock report (not
 * this page, which is category-master-only).
 */
const MasterCategories = (() => {
    let editingId = null;
    let filters = { q: '', active: '', sort: 'name' };
    let tableHost = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat kategori...</div>';
        try {
            container.innerHTML = '';
            container.appendChild(buildForm());
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

    function buildForm() {
        const canManage = Auth.hasPermission('MASTER_CATEGORY_MANAGE');
        const card = UI.el('div', { class: 'card', id: 'category-form-card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title', id: 'category-form-title' }, '➕ Kategori Baru')]),
            UI.el('div', { id: 'category-form-alert' }),
            UI.el('div', { class: 'grid-2', html: `
                <div class="form-group"><label>Kode</label><input type="text" id="category-code"></div>
                <div class="form-group"><label>Nama</label><input type="text" id="category-name"></div>
            ` }),
            !canManage ? UI.el('div', { class: 'alert alert-warning' }, 'Anda tidak memiliki izin untuk menambah/mengubah kategori.') : null,
            UI.el('div', { style: 'display:flex; gap:10px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'category-save-btn', ...(canManage ? {} : { disabled: 'disabled' }) }, 'Simpan'),
                UI.el('button', { class: 'btn btn-secondary', id: 'category-cancel-btn', style: 'display:none;' }, 'Batal Edit'),
            ]),
        ]);
        setTimeout(wireForm, 0);
        return card;
    }

    function wireForm() {
        document.getElementById('category-save-btn').addEventListener('click', save);
        document.getElementById('category-cancel-btn').addEventListener('click', () => { editingId = null; resetForm(); });
    }

    function resetForm() {
        document.getElementById('category-code').value = '';
        document.getElementById('category-name').value = '';
        document.getElementById('category-form-title').textContent = '➕ Kategori Baru';
        document.getElementById('category-cancel-btn').style.display = 'none';
        document.getElementById('category-form-alert').innerHTML = '';
    }

    function fillForm(c) {
        document.getElementById('category-code').value = c.code || '';
        document.getElementById('category-name').value = c.name || '';
        document.getElementById('category-form-title').textContent = `✏️ Edit Kategori: ${c.name}`;
        document.getElementById('category-cancel-btn').style.display = '';
    }

    async function save() {
        const alertBox = document.getElementById('category-form-alert');
        alertBox.innerHTML = '';
        const code = document.getElementById('category-code').value.trim();
        const name = document.getElementById('category-name').value.trim();
        if (!code || !name) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Kode dan Nama wajib diisi.'));
            return;
        }
        try {
            if (editingId) {
                await InvApi.updateCategory(editingId, { name });
                UI.toast('Kategori berhasil diperbarui.', 'success');
            } else {
                await InvApi.createCategory({ code, name });
                UI.toast('Kategori berhasil ditambahkan.', 'success');
            }
            editingId = null;
            resetForm();
            await reload();
        } catch (err) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan kategori.'));
        }
    }

    function buildTable(categories) {
        const canManage = Auth.hasPermission('MASTER_CATEGORY_MANAGE');
        const rows = categories.map((c) => UI.el('tr', {}, [
            UI.el('td', {}, c.code),
            UI.el('td', {}, c.name),
            UI.el('td', {}, UI.formatNumber(c.item_count ?? 0, 0)),
            UI.el('td', {}, MasterCommon.statusBadge(!!c.is_active)),
            UI.el('td', {}, canManage ? MasterCommon.actionsMenu([
                { label: 'Edit', onClick: () => { editingId = c.id; fillForm(c); document.getElementById('category-form-card').scrollIntoView({ behavior: 'smooth' }); } },
                { label: c.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(c) },
                { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(c) },
            ]) : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `🏷️ Daftar Kategori (${categories.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'Jumlah Item', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada kategori — buat kategori pertama di atas, atau backfill via scripts/backfill_item_categories.php')])]),
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
