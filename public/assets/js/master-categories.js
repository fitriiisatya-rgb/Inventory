/**
 * PHASE V2 — Master Kategori. List + inline create/edit form.
 * items.category (the original free-text field) is never touched from
 * here — this only manages the normalized categories table and each
 * item's category_id, via the item detail drawer / stock report (not
 * this page, which is category-master-only).
 */
const MasterCategories = (() => {
    let editingId = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat kategori...</div>';
        try {
            const categories = await InvApi.listCategories();
            container.innerHTML = '';
            container.appendChild(buildForm());
            container.appendChild(buildTable(categories));
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat kategori: ${(err && err.message) || ''}</div>`;
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
            const tab = document.getElementById('tab-master-category');
            if (tab) render(tab);
        } catch (err) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan kategori.'));
        }
    }

    function buildTable(categories) {
        const canManage = Auth.hasPermission('MASTER_CATEGORY_MANAGE');
        const rows = categories.map((c) => UI.el('tr', {}, [
            UI.el('td', {}, c.code),
            UI.el('td', {}, c.name),
            UI.el('td', {}, UI.el('span', { class: `badge ${c.is_active ? 'badge-received' : 'badge-cancelled'}` }, c.is_active ? 'Aktif' : 'Nonaktif')),
            UI.el('td', {}, canManage ? [
                actionBtn('Edit', () => { editingId = c.id; fillForm(c); document.getElementById('category-form-card').scrollIntoView({ behavior: 'smooth' }); }),
                actionBtn(c.is_active ? 'Nonaktifkan' : 'Aktifkan', () => toggleActive(c)),
            ] : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `🏷️ Daftar Kategori (${categories.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Belum ada kategori — buat kategori pertama di atas, atau backfill via scripts/backfill_item_categories.php')])]),
                ]),
            ]),
        ]);
    }

    function actionBtn(label, onClick) {
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-right:6px;' }, label);
        btn.addEventListener('click', onClick);
        return btn;
    }

    async function toggleActive(c) {
        const confirmed = await Modal.confirm({
            title: c.is_active ? 'Nonaktifkan Kategori' : 'Aktifkan Kategori',
            message: `${c.is_active ? 'Nonaktifkan' : 'Aktifkan'} kategori "${c.name}"? Item yang sudah memakai kategori ini tidak berubah.`,
        });
        if (!confirmed) return;
        try {
            await InvApi.updateCategory(c.id, { is_active: !c.is_active });
            UI.toast('Status kategori diperbarui.', 'success');
            const tab = document.getElementById('tab-master-category');
            if (tab) render(tab);
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
