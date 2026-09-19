/**
 * PHASE V2 — Master Vendor/Supplier. List + inline create/edit form.
 * Soft-delete only (toggle Aktif/Nonaktif) — never a hard delete, matching
 * the backend's own rule for anything referenced by transactions/items.
 */
const MasterVendors = (() => {
    let editingId = null;
    let filters = { q: '', active: '', sort: 'name' };
    let tableHost = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat vendor...</div>';
        try {
            container.innerHTML = '';
            container.appendChild(buildForm());
            container.appendChild(buildToolbar());
            tableHost = UI.el('div');
            container.appendChild(tableHost);
            await reload();
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat vendor: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildToolbar() {
        const qInput = UI.el('input', { type: 'text', placeholder: 'Cari nama / kode / kontak / email' });
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
            <option value="newest">Terbaru</option>
            <option value="oldest">Terlama</option>
            <option value="linked_items">Jumlah Item Terkait</option>
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
            const dir = filters.sort === 'name_desc' ? 'desc' : 'asc';
            const sort = filters.sort === 'name_desc' ? 'name' : filters.sort;
            const suppliers = await InvApi.listSuppliers({ search: filters.q, active: filters.active, sort, dir });
            tableHost.innerHTML = '';
            tableHost.appendChild(buildTable(suppliers));
        } catch (err) {
            UI.handleApiError(err);
            tableHost.innerHTML = `<div class="alert alert-error">Gagal memuat vendor: ${(err && err.message) || ''}</div>`;
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
            resetForm();
            await reload();
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
            UI.el('td', {}, UI.formatNumber(v.linked_item_count ?? 0, 0)),
            UI.el('td', {}, MasterCommon.statusBadge(!!v.is_active)),
            UI.el('td', {}, canManage ? MasterCommon.actionsMenu([
                { label: 'Edit', onClick: () => { editingId = v.id; fillForm(v); document.getElementById('vendor-form-card').scrollIntoView({ behavior: 'smooth' }); } },
                { label: v.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(v) },
                { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(v) },
            ]) : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `📇 Daftar Vendor (${suppliers.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'PIC', 'Telepon', 'Email', 'Item Terkait', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '8' }, 'Belum ada vendor')])]),
                ]),
            ]),
        ]);
    }

    async function toggleActive(v) {
        const confirmed = v.is_active
            ? await MasterCommon.confirmDeactivate(`vendor "${v.name}"`)
            : await MasterCommon.confirmActivate(`vendor "${v.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.updateSupplier(v.id, { is_active: !v.is_active });
            UI.toast('Status vendor diperbarui.', 'success');
            await reload();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function doDelete(v) {
        const confirmed = await MasterCommon.confirmDeletePermanent(`vendor "${v.name}"`);
        if (!confirmed) return;
        try {
            await InvApi.deleteSupplier(v.id);
            UI.toast('Vendor berhasil dihapus permanen.', 'success');
            await reload();
        } catch (err) {
            const handled = await MasterCommon.handleDeleteError(err);
            if (!handled) UI.handleApiError(err);
        }
    }

    return { render };
})();
