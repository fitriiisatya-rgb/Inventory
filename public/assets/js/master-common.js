/**
 * PHASE V2.1 — shared helpers for the 6 master-data pages (Barang, Gudang,
 * Divisi, Vendor, Bakery Tujuan, Kategori): the ⋮ actions-menu dropdown,
 * the exact-text confirmation dialogs the spec requires for delete/
 * deactivate, and surfacing a server-side delete-blocked reason verbatim.
 * No page-specific logic lives here — each page still owns its own
 * columns/filters/API calls.
 */
const MasterCommon = (() => {
    function statusBadge(isActive) {
        return UI.el('span', { class: `badge ${isActive ? 'badge-received' : 'badge-cancelled'}` }, isActive ? 'Aktif' : 'Nonaktif');
    }

    /** @param {{label:string, onClick:()=>void, danger?:boolean, disabled?:boolean}[]} items */
    function actionsMenu(items) {
        const wrap = UI.el('div', { class: 'master-actions-menu dt-column-picker' });
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, '⋮');
        const panel = UI.el('div', { class: 'dt-column-picker-panel' });
        items.filter(Boolean).forEach((it) => {
            const link = UI.el('button', {
                class: `master-action-item${it.danger ? ' danger' : ''}`,
                type: 'button',
                ...(it.disabled ? { disabled: 'disabled' } : {}),
            }, it.label);
            if (!it.disabled) {
                link.addEventListener('click', (e) => {
                    e.stopPropagation();
                    panel.classList.remove('open');
                    it.onClick();
                });
            }
            panel.appendChild(link);
        });
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.dt-column-picker-panel.open').forEach((p) => { if (p !== panel) p.classList.remove('open'); });
            panel.classList.toggle('open');
        });
        document.addEventListener('click', () => panel.classList.remove('open'));
        wrap.appendChild(btn);
        wrap.appendChild(panel);
        return wrap;
    }

    // Exact Indonesian confirmation text per the owner's spec — never reword.
    function confirmDeletePermanent(name) {
        return Modal.confirm({
            title: 'Hapus Permanen',
            message: `Hapus permanen ${name}? Tindakan ini tidak dapat dibatalkan.`,
            confirmLabel: 'Hapus Permanen',
            danger: true,
        });
    }

    function confirmDeactivate(name) {
        return Modal.confirm({
            title: 'Nonaktifkan',
            message: `Nonaktifkan ${name}? Data historis tetap dipertahankan.`,
            confirmLabel: 'Nonaktifkan',
        });
    }

    function confirmActivate(name) {
        return Modal.confirm({
            title: 'Aktifkan',
            message: `Aktifkan ${name}? Record ini akan tampil kembali sebagai aktif.`,
            confirmLabel: 'Aktifkan',
        });
    }

    /**
     * Surfaces a DELETE_BLOCKED_HAS_REFERENCES error's server message
     * verbatim (it already names the record and tells the user to
     * deactivate instead). Returns true if it was that error (handled),
     * false so the caller falls back to UI.handleApiError for anything else.
     */
    async function handleDeleteError(err) {
        if (err && err.code === 'DELETE_BLOCKED_HAS_REFERENCES') {
            await Modal.alert({ title: 'Tidak Dapat Dihapus', message: err.message });
            return true;
        }
        return false;
    }

    /**
     * Small multi-field edit form in a Modal-styled overlay — used for the
     * "Edit" action on list pages where a per-row inline form doesn't scale
     * (Master Barang has 1000+ rows). Reuses the same .modal/.modal-content
     * CSS as modal.js rather than introducing another dialog convention.
     *
     * @param {{title:string, submitLabel?:string, fields:{key:string,label:string,type?:'text'|'select',options?:{value,label}[],emptyLabel?:string,allowEmpty?:boolean,required?:boolean}[], initial?:object}} opts
     * @returns {Promise<object|null>} field values keyed by field.key, or null if cancelled
     */
    function formModal({ title, submitLabel = 'Simpan', fields, initial = {} }) {
        return new Promise((resolve) => {
            const inputs = {};
            const rows = fields.map((f) => {
                let input;
                if (f.type === 'select') {
                    const optionsHtml = (f.allowEmpty !== false ? `<option value="">${f.emptyLabel || '—'}</option>` : '')
                        + (f.options || []).map((o) => `<option value="${o.value}">${o.label}</option>`).join('');
                    input = UI.el('select', { html: optionsHtml });
                    if (initial[f.key] !== undefined && initial[f.key] !== null) input.value = String(initial[f.key]);
                } else {
                    input = UI.el('input', { type: 'text' });
                    input.value = initial[f.key] !== undefined && initial[f.key] !== null ? String(initial[f.key]) : '';
                }
                inputs[f.key] = input;
                return UI.el('div', { class: 'form-group' }, [UI.el('label', {}, f.label), input]);
            });
            const errorNode = UI.el('div', { class: 'alert alert-error', style: 'display:none; margin-top:8px;' });
            const overlay = UI.el('div', { class: 'modal open' });
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Batal');
            const submitBtn = UI.el('button', { class: 'btn btn-primary' }, submitLabel);
            const content = UI.el('div', { class: 'modal-content' }, [
                UI.el('h3', {}, title),
                UI.el('div', {}, [...rows, errorNode]),
                UI.el('div', { style: 'display:flex; gap:10px; justify-content:flex-end; margin-top:18px;' }, [cancelBtn, submitBtn]),
            ]);
            overlay.appendChild(content);
            document.body.appendChild(overlay);

            const close = () => overlay.remove();
            cancelBtn.addEventListener('click', () => { close(); resolve(null); });
            submitBtn.addEventListener('click', () => {
                const values = {};
                for (const f of fields) {
                    const input = inputs[f.key];
                    let v = input.value.trim();
                    if (f.type === 'select' && v === '') v = null;
                    if (f.required && (v === '' || v === null)) {
                        errorNode.textContent = `${f.label} wajib diisi.`;
                        errorNode.style.display = 'block';
                        return;
                    }
                    values[f.key] = v;
                }
                close();
                resolve(values);
            });
        });
    }

    return {
        statusBadge, actionsMenu,
        confirmDeletePermanent, confirmDeactivate, confirmActivate,
        handleDeleteError, formModal,
    };
})();
