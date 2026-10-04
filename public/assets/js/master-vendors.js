/**
 * PHASE V2 — Master Vendor/Supplier. List + "Tambah Supplier" / Edit in a compact modal
 * (MasterCommon.recordModal; no permanent inline form). Soft-delete only (toggle Aktif/Nonaktif) — never a hard delete, matching
 * the backend's own rule for anything referenced by transactions/items.
 */
const MasterVendors = (() => {
    let filters = { q: '', active: '', sort: 'name' };
    let tableHost = null;

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat vendor...</div>';
        try {
            container.innerHTML = '';
            container.appendChild(MasterCommon.pageHeader({
                title: 'Vendor / Supplier', description: 'Kelola data vendor / supplier.', buttonLabel: 'Tambah Supplier',
                canCreate: Auth.hasPermission('MASTER_SUPPLIER_MANAGE'), onCreate: () => openForm(null),
            }));
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

    // Create + Edit share one compact modal. The API is the authority (duplicate code, required, e-mail);
    // its message is shown under the matching field and the entered values stay in the form.
    async function openForm(v) {
        const res = await MasterCommon.recordModal({
            title: v ? `Edit Supplier: ${v.name}` : 'Tambah Supplier',
            wide: false,
            testid: 'mdm-modal-supplier',
            initial: v ? { code: v.code, name: v.name, contact_name: v.contact_name, phone: v.phone, email: v.email, address: v.address, notes: v.notes, is_active: !!v.is_active } : { is_active: true },
            fields: [
                { key: 'code', label: 'Kode Supplier', type: 'text', required: true, placeholder: 'Contoh: SUP005', maxLength: 30 },
                { key: 'name', label: 'Nama Supplier', type: 'text', required: true, placeholder: 'Contoh: PT Baru', maxLength: 150 },
                { key: 'contact_name', label: 'PIC', type: 'text', placeholder: 'Nama PIC', maxLength: 100 },
                { key: 'phone', label: 'Telepon', type: 'text', placeholder: '0812-xxxx-xxxx', maxLength: 30 },
                { key: 'email', label: 'Email', type: 'email', placeholder: 'nama@perusahaan.com', maxLength: 150 },
                { key: 'is_active', label: 'Status', type: 'toggle', ...(v ? {} : {}) },
                { key: 'address', label: 'Alamat', type: 'textarea', placeholder: 'Alamat lengkap', maxLength: 255 },
                { key: 'notes', label: 'Catatan', type: 'textarea', placeholder: 'Catatan tambahan', maxLength: 255 },
            ],
            onSubmit: async (vals) => {
                const payload = {
                    code: vals.code, name: vals.name,
                    contact_name: vals.contact_name || null, phone: vals.phone || null, email: vals.email || null,
                    address: vals.address || null, notes: vals.notes || null, is_active: !!vals.is_active,
                };
                if (v) await InvApi.updateSupplier(v.id, payload);
                else await InvApi.createSupplier(payload);
                return true;
            },
        });
        if (!res) return;
        UI.toast(v ? 'Supplier berhasil diperbarui.' : 'Supplier berhasil ditambahkan.', 'success');
        Master.loadAll().catch(() => {});
        await reload();
    }

    function buildTable(suppliers) {
        const canManage = Auth.hasPermission('MASTER_SUPPLIER_MANAGE');
        const canTrace = Auth.hasPermission('AUDIT_LOG_VIEW');
        const rows = suppliers.map((v) => UI.el('tr', {}, [
            UI.el('td', {}, v.code),
            UI.el('td', {}, v.name),
            UI.el('td', {}, v.contact_name || '—'),
            UI.el('td', {}, v.phone || '—'),
            UI.el('td', {}, v.email || '—'),
            UI.el('td', {}, UI.formatNumber(v.linked_item_count ?? 0, 0)),
            UI.el('td', {}, MasterCommon.statusBadge(!!v.is_active)),
            UI.el('td', {}, (canManage || canTrace) ? MasterCommon.actionsMenu([
                canTrace ? { label: 'Lihat Jejak', onClick: () => TraceDrawer.openEntity('supplier', v.id) } : null,
                canManage ? { label: 'Edit', onClick: () => openForm(v) } : null,
                canManage ? { label: v.is_active ? 'Nonaktifkan' : 'Aktifkan', onClick: () => toggleActive(v) } : null,
                canManage ? { label: 'Hapus Permanen', danger: true, onClick: () => doDelete(v) } : null,
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
            Master.loadAll().catch(() => {});
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
