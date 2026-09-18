/**
 * PHASE V2 — Master Bakery Tujuan. List + inline create/edit form.
 * A bakery destination is a distribution endpoint for OUT transactions —
 * never a warehouse, never a division. Soft-delete only.
 */
const MasterBakeryDestinations = (() => {
    let editingId = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat bakery tujuan...</div>';
        try {
            const destinations = await InvApi.listBakeryDestinations();
            container.innerHTML = '';
            container.appendChild(buildForm());
            container.appendChild(buildTable(destinations));
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat bakery tujuan: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildForm() {
        const canManage = Auth.hasPermission('MASTER_BAKERY_DESTINATION_MANAGE');
        const card = UI.el('div', { class: 'card', id: 'bakery-form-card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title', id: 'bakery-form-title' }, '➕ Bakery Tujuan Baru')]),
            UI.el('div', { id: 'bakery-form-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Kode</label><input type="text" id="bakery-code"></div>
                <div class="form-group"><label>Nama</label><input type="text" id="bakery-name"></div>
                <div class="form-group"><label>PIC</label><input type="text" id="bakery-pic-name"></div>
                <div class="form-group"><label>Telepon</label><input type="text" id="bakery-phone"></div>
                <div class="form-group"><label>Kota / Area</label><input type="text" id="bakery-city-area"></div>
                <div class="form-group"><label>Rute / Cluster</label><input type="text" id="bakery-route-cluster"></div>
                <div class="form-group"><label>Alamat</label><input type="text" id="bakery-address"></div>
                <div class="form-group"><label>Catatan</label><input type="text" id="bakery-notes"></div>
            ` }),
            !canManage ? UI.el('div', { class: 'alert alert-warning' }, 'Anda tidak memiliki izin untuk menambah/mengubah bakery tujuan.') : null,
            UI.el('div', { style: 'display:flex; gap:10px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'bakery-save-btn', ...(canManage ? {} : { disabled: 'disabled' }) }, 'Simpan'),
                UI.el('button', { class: 'btn btn-secondary', id: 'bakery-cancel-btn', style: 'display:none;' }, 'Batal Edit'),
            ]),
        ]);
        setTimeout(wireForm, 0);
        return card;
    }

    function wireForm() {
        document.getElementById('bakery-save-btn').addEventListener('click', save);
        document.getElementById('bakery-cancel-btn').addEventListener('click', () => { editingId = null; resetForm(); });
    }

    function resetForm() {
        ['code', 'name', 'pic-name', 'phone', 'city-area', 'route-cluster', 'address', 'notes'].forEach((f) => {
            const el = document.getElementById(`bakery-${f}`);
            if (el) el.value = '';
        });
        document.getElementById('bakery-form-title').textContent = '➕ Bakery Tujuan Baru';
        document.getElementById('bakery-cancel-btn').style.display = 'none';
        document.getElementById('bakery-form-alert').innerHTML = '';
    }

    function fillForm(b) {
        document.getElementById('bakery-code').value = b.code || '';
        document.getElementById('bakery-name').value = b.name || '';
        document.getElementById('bakery-pic-name').value = b.pic_name || '';
        document.getElementById('bakery-phone').value = b.phone || '';
        document.getElementById('bakery-city-area').value = b.city_area || '';
        document.getElementById('bakery-route-cluster').value = b.route_cluster || '';
        document.getElementById('bakery-address').value = b.address || '';
        document.getElementById('bakery-notes').value = b.notes || '';
        document.getElementById('bakery-form-title').textContent = `✏️ Edit Bakery Tujuan: ${b.name}`;
        document.getElementById('bakery-cancel-btn').style.display = '';
    }

    async function save() {
        const alertBox = document.getElementById('bakery-form-alert');
        alertBox.innerHTML = '';
        const payload = {
            code: document.getElementById('bakery-code').value.trim(),
            name: document.getElementById('bakery-name').value.trim(),
            pic_name: document.getElementById('bakery-pic-name').value.trim() || null,
            phone: document.getElementById('bakery-phone').value.trim() || null,
            city_area: document.getElementById('bakery-city-area').value.trim() || null,
            route_cluster: document.getElementById('bakery-route-cluster').value.trim() || null,
            address: document.getElementById('bakery-address').value.trim() || null,
            notes: document.getElementById('bakery-notes').value.trim() || null,
        };
        if (!payload.code || !payload.name) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Kode dan Nama wajib diisi.'));
            return;
        }
        try {
            if (editingId) {
                await InvApi.updateBakeryDestination(editingId, payload);
                UI.toast('Bakery tujuan berhasil diperbarui.', 'success');
            } else {
                await InvApi.createBakeryDestination(payload);
                UI.toast('Bakery tujuan berhasil ditambahkan.', 'success');
            }
            editingId = null;
            const tab = document.getElementById('tab-master-bakery');
            if (tab) render(tab);
        } catch (err) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan bakery tujuan.'));
        }
    }

    function buildTable(destinations) {
        const canManage = Auth.hasPermission('MASTER_BAKERY_DESTINATION_MANAGE');
        const rows = destinations.map((b) => UI.el('tr', {}, [
            UI.el('td', {}, b.code),
            UI.el('td', {}, b.name),
            UI.el('td', {}, b.city_area || '—'),
            UI.el('td', {}, b.pic_name || '—'),
            UI.el('td', {}, b.phone || '—'),
            UI.el('td', {}, UI.el('span', { class: `badge ${b.is_active ? 'badge-received' : 'badge-cancelled'}` }, b.is_active ? 'Aktif' : 'Nonaktif')),
            UI.el('td', {}, canManage ? [
                actionBtn('Edit', () => { editingId = b.id; fillForm(b); document.getElementById('bakery-form-card').scrollIntoView({ behavior: 'smooth' }); }),
                actionBtn(b.is_active ? 'Nonaktifkan' : 'Aktifkan', () => toggleActive(b)),
            ] : '—'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, `🥖 Daftar Bakery Tujuan (${destinations.length})`)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode', 'Nama', 'Kota/Area', 'PIC', 'Telepon', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '7' }, 'Belum ada bakery tujuan')])]),
                ]),
            ]),
        ]);
    }

    function actionBtn(label, onClick) {
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-right:6px;' }, label);
        btn.addEventListener('click', onClick);
        return btn;
    }

    async function toggleActive(b) {
        const confirmed = await Modal.confirm({
            title: b.is_active ? 'Nonaktifkan Bakery Tujuan' : 'Aktifkan Bakery Tujuan',
            message: `${b.is_active ? 'Nonaktifkan' : 'Aktifkan'} bakery tujuan "${b.name}"? Data tidak akan dihapus.`,
        });
        if (!confirmed) return;
        try {
            await InvApi.updateBakeryDestination(b.id, { is_active: !b.is_active });
            UI.toast('Status bakery tujuan diperbarui.', 'success');
            const tab = document.getElementById('tab-master-bakery');
            if (tab) render(tab);
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
