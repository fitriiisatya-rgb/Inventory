/**
 * PHASE V2 — Master Vendor/Supplier. List + inline create/edit form.
 * Soft-delete only (toggle Aktif/Nonaktif) — never a hard delete, matching
 * the backend's own rule for anything referenced by transactions/items.
 */
const MasterVendors = (() => {
    let editingId = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat vendor...</div>';
        try {
            const suppliers = await InvApi.listSuppliers();
            container.innerHTML = '';
            container.appendChild(buildForm());
            container.appendChild(buildTable(suppliers));
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat vendor: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildForm() {
        const canManage = Auth.hasPermission('MASTER_SUPPLIER_MANAGE');
        const card = UI.el('div', { class: 'card', id: 'vendor-form-card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title', id: 'vendor-form-title' }, '➕ Vendor Baru')]),
            UI.el('div', { id: 'vendor-form-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Kode</label><input type="text" id="vendor-code"></div>
                <div class="form-group"><label>Nama</label><input type="text" id="vendor-name"></div>
                <div class="form-group"><label>PIC / Contact Person</label><input type="text" id="vendor-contact-name"></div>
                <div class="form-group"><label>Telepon</label><input type="text" id="vendor-phone"></div>
                <div class="form-group"><label>Email</label><input type="email" id="vendor-email"></div>
                <div class="form-group"><label>Alamat</label><input type="text" id="vendor-address"></div>
                <div class="form-group"><label>Catatan</label><input type="text" id="vendor-notes"></div>
            ` }),
            !canManage ? UI.el('div', { class: 'alert alert-warning' }, 'Anda tidak memiliki izin untuk menambah/mengubah vendor.') : null,
            UI.el('div', { style: 'display:flex; gap:10px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'vendor-save-btn', ...(canManage ? {} : { disabled: 'disabled' }) }, 'Simpan'),
                UI.el('button', { class: 'btn btn-secondary', id: 'vendor-cancel-btn', style: 'display:none;' }, 'Batal Edit'),
            ]),
        ]);
        setTimeout(wireForm, 0);
        return card;
    }

    function wireForm() {
        document.getElementById('vendor-save-btn').addEventListener('click', save);
        document.getElementById('vendor-cancel-btn').addEventListener('click', () => { editingId = null; resetForm(); });
    }

    function resetForm() {
        ['code', 'name', 'contact-name', 'phone', 'email', 'address', 'notes'].forEach((f) => {
            const el = document.getElementById(`vendor-${f}`);
            if (el) el.value = '';
        });
        document.getElementById('vendor-form-title').textContent = '➕ Vendor Baru';
        document.getElementById('vendor-cancel-btn').style.display = 'none';
        document.getElementById('vendor-form-alert').innerHTML = '';
    }

    function fillForm(v) {
        document.getElementById('vendor-code').value = v.code || '';
        document.getElementById('vendor-name').value = v.name || '';
        document.getElementById('vendor-contact-name').value = v.contact_name || '';
        document.getElementById('vendor-phone').value = v.phone || '';
        document.getElementById('vendor-email').value = v.email || '';
        document.getElementById('vendor-address').value = v.address || '';
        document.getElementById('vendor-notes').value = v.notes || '';
        document.getElementById('vendor-form-title').textContent = `✏️ Edit Vendor: ${v.name}`;
        document.getElementById('vendor-cancel-btn').style.display = '';
    }

    async function save() {
        const alertBox = document.getElementById('vendor-form-alert');
        alertBox.innerHTML = '';
        const payload = {
            code: document.getElementById('vendor-code').value.trim(),
            name: document.getElementById('vendor-name').value.trim(),
            contact_name: document.getElementById('vendor-contact-name').value.trim() || null,
            phone: document.getElementById('vendor-phone').value.trim() || null,
            email: document.getElementById('vendor-email').value.trim() || null,
            address: document.getElementById('vendor-address').value.trim() || null,
            notes: document.getElementById('vendor-notes').value.trim() || null,
        };
        if (!payload.code || !payload.name) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Kode dan Nama wajib diisi.'));
            return;
        }
        try {
            if (editingId) {
                await InvApi.updateSupplier(editingId, payload);
                UI.toast('Vendor berhasil diperbarui.', 'success');
            } else {
                await InvApi.createSupplier(payload);
                UI.toast('Vendor berhasil ditambahkan.', 'success');
            }
            editingId = null;
            const tab = document.getElementById('tab-master-vendor');
            if (tab) render(tab);
        } catch (err) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan vendor.'));
        }
    }

    function buildTable(suppliers) {
        const canManage = Auth.hasPermission('MASTER_SUPPLIER_MANAGE');
        const rows = suppliers.map((v) => UI.el('tr', {}, [
            UI.el('td', {}, v.code),
            UI.el('td', {}, v.name),
            UI.el('td', {}, v.contact_name || '—'),
            UI.el('td', {}, v.phone || '—'),
            UI.el('td', {}, v.email || '—'),
            UI.el('td', {}, UI.el('span', { class: `badge ${v.is_active ? 'badge-received' : 'badge-cancelled'}` }, v.is_active ? 'Aktif' : 'Nonaktif')),
            UI.el('td', {}, canManage ? [
                actionBtn('Edit', () => { editingId = v.id; fillForm(v); document.getElementById('vendor-form-card').scrollIntoView({ behavior: 'smooth' }); }),
                actionBtn(v.is_active ? 'Nonaktifkan' : 'Aktifkan', () => toggleActive(v)),
            ] : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `📇 Daftar Vendor (${suppliers.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'PIC', 'Telepon', 'Email', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '7' }, 'Belum ada vendor')])]),
                ]),
            ]),
        ]);
    }

    function actionBtn(label, onClick) {
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-right:6px;' }, label);
        btn.addEventListener('click', onClick);
        return btn;
    }

    async function toggleActive(v) {
        const confirmed = await Modal.confirm({
            title: v.is_active ? 'Nonaktifkan Vendor' : 'Aktifkan Vendor',
            message: `${v.is_active ? 'Nonaktifkan' : 'Aktifkan'} vendor "${v.name}"? Data vendor tidak akan dihapus.`,
        });
        if (!confirmed) return;
        try {
            await InvApi.updateSupplier(v.id, { is_active: !v.is_active });
            UI.toast('Status vendor diperbarui.', 'success');
            const tab = document.getElementById('tab-master-vendor');
            if (tab) render(tab);
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
