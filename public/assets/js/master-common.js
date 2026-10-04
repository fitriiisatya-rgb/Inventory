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
     * PHASE V2.14.7 — added type:'itemSelector': mounts a searchable
     * ItemSelector (SKU/name/barcode) instead of a plain text/numeric-id
     * input — first use is Warehouse Cutover's "Map to Item ID" field,
     * which used to require typing a raw numeric item_id. Never submits a
     * unit (showUnit:false) since none of this form's current callers need
     * one; set showBaseUnit:true on the field spec to also display the
     * selected item's base unit as read-only text (resolved via the
     * existing GET /items/{id}/units, never a new endpoint).
     *
     * @param {{title:string, submitLabel?:string, fields:{key:string,label:string,type?:'text'|'select'|'itemSelector',options?:{value,label}[],emptyLabel?:string,allowEmpty?:boolean,required?:boolean,showBaseUnit?:boolean}[], initial?:object}} opts
     * @returns {Promise<object|null>} field values keyed by field.key, or null if cancelled
     */
    function formModal({ title, submitLabel = 'Simpan', fields, initial = {} }) {
        return new Promise((resolve) => {
            const inputs = {};
            const itemSelectors = {}; // f.key -> { ctl, getItemId: () => number|null }
            const rows = fields.map((f) => {
                if (f.type === 'itemSelector') {
                    const host = UI.el('div');
                    const baseUnitNode = f.showBaseUnit ? UI.el('div', { style: 'font-size:0.8rem; color:var(--text3); margin-top:4px;' }, '') : null;
                    const initialItemId = initial[f.key] !== undefined && initial[f.key] !== null && initial[f.key] !== ''
                        ? Number(initial[f.key]) : null;
                    let currentItemId = initialItemId;
                    const ctl = ItemSelector.mount(host, {
                        initialItemId,
                        showUnit: false,
                        onChange: (state) => {
                            currentItemId = state.valid ? state.itemId : null;
                            if (baseUnitNode) updateBaseUnitNode(baseUnitNode, state);
                        },
                    });
                    if (baseUnitNode && initialItemId) {
                        updateBaseUnitNode(baseUnitNode, { valid: true, itemId: initialItemId, item: Master.itemById(initialItemId) });
                    }
                    itemSelectors[f.key] = { ctl, getItemId: () => currentItemId };
                    return UI.el('div', { class: 'form-group' }, [UI.el('label', {}, f.label), host, baseUnitNode].filter(Boolean));
                }
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

            // Every mounted ItemSelector must be destroyed exactly once,
            // whichever way the modal closes (cancel or submit) — never
            // left as a stale document-level listener after the overlay
            // itself is removed.
            const close = () => {
                Object.values(itemSelectors).forEach((s) => s.ctl.destroy());
                overlay.remove();
            };
            cancelBtn.addEventListener('click', () => { close(); resolve(null); });
            submitBtn.addEventListener('click', () => {
                const values = {};
                for (const f of fields) {
                    if (f.type === 'itemSelector') {
                        const itemId = itemSelectors[f.key].getItemId();
                        if (f.required && itemId === null) {
                            errorNode.textContent = `${f.label} wajib diisi.`;
                            errorNode.style.display = 'block';
                            return;
                        }
                        values[f.key] = itemId !== null ? String(itemId) : '';
                        continue;
                    }
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

    async function updateBaseUnitNode(node, state) {
        if (!state.valid || !state.itemId || !state.item) { node.textContent = ''; return; }
        node.textContent = 'Memuat satuan dasar...';
        try {
            const units = await InvApi.itemUnits(state.itemId);
            const baseUnit = units.find((u) => Number(u.id) === Number(state.item.base_unit_id));
            node.textContent = `Satuan Dasar: ${baseUnit ? baseUnit.code : '-'}`;
        } catch (err) {
            node.textContent = 'Satuan Dasar: -';
        }
    }

    // ============================================================
    // Master Data "Tambah ..." pattern (every Master page): a page header with a compact primary
    // button in the upper-right, and a compact centered modal for Create/Edit — no permanent inline
    // form above the table. Presentation + client-side checks only: every rule that matters
    // (permission, duplicates, existence of category/supplier/unit, price) is enforced by the API,
    // whose error is shown under the matching field with the entered values preserved.
    // ============================================================

    /** Page header: title + short description on the left, primary "+ Tambah ..." button on the right
     *  (omitted entirely when the user lacks the create permission — the API still rejects a direct POST). */
    function pageHeader({ title, description, buttonLabel, canCreate, onCreate, testid }) {
        const head = UI.el('div', { class: 'mdm-head', ...(testid ? { 'data-testid': testid } : {}) }, [
            UI.el('div', { class: 'mdm-head-text' }, [
                UI.el('h2', { class: 'mdm-title' }, title),
                description ? UI.el('p', { class: 'mdm-desc' }, description) : null,
            ].filter(Boolean)),
        ]);
        if (canCreate && buttonLabel) {
            const btn = UI.el('button', { type: 'button', class: 'btn btn-primary mdm-add', 'data-testid': 'mdm-add' }, `+ ${buttonLabel}`);
            btn.addEventListener('click', onCreate);
            head.appendChild(btn);
        }
        return head;
    }

    /** Indonesian input -> number (NaN if not a number): "250.000" = 250000 (dot = thousands),
     *  "1.250,50" = 1250.5 (comma = decimal), "1250" / "2,5" / "0.25" as typed. */
    function parseDecimal(raw) {
        let t = String(raw === null || raw === undefined ? '' : raw).trim().replace(/\s/g, '');
        if (t === '') return NaN;
        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
        else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
        else if ((t.match(/\./g) || []).length > 1) t = t.replace(/\./g, '');
        return /^-?\d+(\.\d+)?$/.test(t) ? Number(t) : NaN;
    }

    function stripServerPrefix(message) {
        return String(message || '').replace(/^VALIDATION_FAILED:\s*/i, '').replace(/^IMPORT_VALIDATION_FAILED:\s*/i, '');
    }

    /**
     * Compact create/edit modal.
     * fields[]: {key,label,type:'text'|'email'|'textarea'|'select'|'decimal'|'toggle', required, placeholder, maxLength,
     *            options:[{value,label}], emptyLabel (select: label of the empty choice; omit = no empty choice),
     *            half:true (half-width cell on wide screens), hint, readonly, match:RegExp (server-error routing)}
     * opts.initial: values by key. opts.validate(values) -> {key: message}|null (extra client checks).
     * opts.onChange(key, values, ctl) -> optional dependent-field logic; ctl = {setLabel, setHint, setHidden, setValue}.
     * opts.onSubmit(values) -> Promise; throw to keep the modal open (API error -> field errors / form alert).
     * Resolves with onSubmit's return value, or null when closed via X / Batal / Esc.
     */
    function recordModal({ title, submitLabel = 'Simpan', fields, initial = {}, wide = false, validate, onChange, onSubmit, testid = 'mdm-modal' }) {
        return new Promise((resolve) => {
            const ctls = {};
            const errNodes = {};
            const cells = {};
            const labelNodes = {};
            const hintNodes = {};

            const readValue = (f) => {
                const c = ctls[f.key];
                if (f.type === 'toggle') return !!c.checked;
                let v = c.value;
                if (f.type !== 'textarea') v = v.trim();
                else v = v.trim();
                if (f.type === 'select' && v === '') return null;
                return v;
            };
            const values = () => { const o = {}; fields.forEach((f) => { o[f.key] = readValue(f); }); return o; };

            const setError = (key, message) => {
                const node = errNodes[key];
                const c = ctls[key];
                if (!node || !c) return;
                node.textContent = message || '';
                node.style.display = message ? 'block' : 'none';
                c.classList.toggle('invalid', !!message);
                if (message) c.setAttribute('aria-invalid', 'true'); else c.removeAttribute('aria-invalid');
            };
            const clearErrors = () => { fields.forEach((f) => setError(f.key, '')); formError.style.display = 'none'; formError.textContent = ''; };

            const cell = (f) => {
                let control;
                const id = `mdm-f-${f.key}`;
                if (f.type === 'select') {
                    control = UI.el('select', { id, 'data-testid': id });
                    if (f.emptyLabel !== undefined) control.appendChild(UI.el('option', { value: '' }, f.emptyLabel));
                    (f.options || []).forEach((o) => control.appendChild(UI.el('option', { value: String(o.value) }, o.label)));
                    const init = initial[f.key];
                    control.value = init !== undefined && init !== null ? String(init) : (f.emptyLabel !== undefined ? '' : (f.options && f.options[0] ? String(f.options[0].value) : ''));
                } else if (f.type === 'textarea') {
                    control = UI.el('textarea', { id, 'data-testid': id, rows: '3', ...(f.placeholder ? { placeholder: f.placeholder } : {}), ...(f.maxLength ? { maxlength: String(f.maxLength) } : {}) });
                    control.value = initial[f.key] !== undefined && initial[f.key] !== null ? String(initial[f.key]) : '';
                } else if (f.type === 'toggle') {
                    control = UI.el('input', { id, 'data-testid': id, type: 'checkbox', class: 'mdm-switch-input', role: 'switch' });
                    control.checked = initial[f.key] === undefined ? true : !!initial[f.key];
                } else {
                    control = UI.el('input', {
                        id, 'data-testid': id, type: f.type === 'email' ? 'email' : 'text', autocomplete: 'off',
                        ...(f.type === 'decimal' ? { inputmode: 'decimal' } : {}),
                        ...(f.placeholder ? { placeholder: f.placeholder } : {}),
                        ...(f.maxLength ? { maxlength: String(f.maxLength) } : {}),
                        ...(f.readonly ? { readonly: 'readonly' } : {}),
                    });
                    control.value = initial[f.key] !== undefined && initial[f.key] !== null ? String(initial[f.key]) : '';
                }
                ctls[f.key] = control;
                const label = UI.el('label', { for: id, class: 'mdm-label' }, f.label);
                if (f.required) label.appendChild(UI.el('span', { class: 'mdm-req', 'aria-hidden': 'true' }, ' *'));
                labelNodes[f.key] = { node: label, base: f.label, required: !!f.required };
                const err = UI.el('div', { class: 'mdm-err', id: `mdm-e-${f.key}`, 'data-testid': `mdm-e-${f.key}`, role: 'alert', style: 'display:none;' });
                errNodes[f.key] = err;
                const hint = UI.el('div', { class: 'mdm-hint', style: f.hint ? '' : 'display:none;' }, f.hint || '');
                hintNodes[f.key] = hint;
                let body;
                if (f.type === 'toggle') {
                    const text = UI.el('span', { class: 'mdm-switch-text' }, control.checked ? 'Aktif' : 'Nonaktif');
                    control.addEventListener('change', () => { text.textContent = control.checked ? 'Aktif' : 'Nonaktif'; });
                    body = UI.el('div', { class: 'mdm-switch' }, [control, UI.el('span', { class: 'mdm-switch-track', 'aria-hidden': 'true' }), text]);
                } else {
                    body = control;
                }
                const wrapper = UI.el('div', { class: `mdm-field${f.half === false ? ' mdm-full' : ''}`, 'data-field': f.key }, [label, body, hint, err]);
                cells[f.key] = wrapper;
                const onAny = () => {
                    setError(f.key, '');
                    if (onChange) onChange(f.key, values(), ctl);
                };
                control.addEventListener(f.type === 'select' || f.type === 'toggle' ? 'change' : 'input', onAny);
                return wrapper;
            };

            const ctl = {
                setLabel(key, text) { const l = labelNodes[key]; if (!l) return; l.node.firstChild.textContent = text; },
                setHint(key, text) { const h = hintNodes[key]; if (!h) return; h.textContent = text || ''; h.style.display = text ? '' : 'none'; },
                setHidden(key, hidden) { if (cells[key]) cells[key].style.display = hidden ? 'none' : ''; },
                setValue(key, v) { if (ctls[key]) ctls[key].value = v; },
            };

            const formError = UI.el('div', { class: 'alert alert-error mdm-form-error', 'data-testid': 'mdm-form-error', role: 'alert', style: 'display:none;' });
            const cancelBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary mdm-btn', 'data-testid': 'mdm-cancel' }, 'Batal');
            const saveBtn = UI.el('button', { type: 'submit', class: 'btn btn-primary mdm-btn', 'data-testid': 'mdm-save' }, submitLabel);
            const closeBtn = UI.el('button', { type: 'button', class: 'mdm-x', 'aria-label': 'Tutup', title: 'Tutup', 'data-testid': 'mdm-close' }, '×');
            const titleId = `mdm-title-${Math.random().toString(36).slice(2, 8)}`;
            const form = UI.el('form', { class: 'mdm-form', novalidate: 'novalidate' }, [
                UI.el('div', { class: 'mdm-body' }, [UI.el('div', { class: 'mdm-grid' }, fields.map(cell)), formError]),
                UI.el('div', { class: 'mdm-foot' }, [cancelBtn, saveBtn]),
            ]);
            const content = UI.el('div', { class: `modal-content mdm-modal${wide ? ' mdm-wide' : ''}`, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, 'data-testid': testid }, [
                UI.el('div', { class: 'mdm-modal-head' }, [UI.el('span', { class: 'mdm-modal-ico', 'aria-hidden': 'true' }, '▣'), UI.el('h3', { id: titleId }, title), closeBtn]),
                form,
            ]);
            const overlay = UI.el('div', { class: 'modal open mdm-overlay' }, [content]);
            document.body.appendChild(overlay);
            document.body.classList.add('mdm-lock');
            if (onChange) onChange('__init', values(), ctl);

            let busy = false;
            const finish = (result) => {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                if (!document.querySelector('.mdm-overlay')) document.body.classList.remove('mdm-lock');
                resolve(result);
            };
            const onKey = (e) => { if (e.key === 'Escape' && !busy && overlay.isConnected && overlay === document.querySelectorAll('.mdm-overlay')[document.querySelectorAll('.mdm-overlay').length - 1]) { e.stopPropagation(); finish(null); } };
            document.addEventListener('keydown', onKey, true);
            closeBtn.addEventListener('click', () => { if (!busy) finish(null); });
            cancelBtn.addEventListener('click', () => { if (!busy) finish(null); });

            const routeServerError = (err) => {
                const raw = stripServerPrefix(err && err.message);
                const parts = raw.split(/;\s+/).filter(Boolean);
                let routed = 0;
                const leftovers = [];
                parts.forEach((part) => {
                    const low = part.toLowerCase();
                    const f = fields.find((x) => (x.match ? x.match.test(part) : new RegExp(`(^|[^a-z])${x.key.replace(/_id$/, '')}(_id)?([^a-z]|$)`, 'i').test(low)));
                    if (!f) { leftovers.push(part); return; }
                    let text = part;
                    if (/already exists/i.test(part)) text = `${f.label} sudah digunakan.`;
                    else if (/required|cannot be blank|must not be blank/i.test(part)) text = `${f.label} wajib diisi.`;
                    else if (/does not exist|not a recognized/i.test(part)) text = `${f.label} tidak ditemukan di master data.`;
                    else if (/inactive/i.test(part)) text = `${f.label} tidak aktif.`;
                    setError(f.key, text);
                    routed += 1;
                });
                if (leftovers.length || (!routed && !parts.length)) {
                    formError.textContent = leftovers.join('; ') || (err && err.message) || 'Gagal menyimpan.';
                    formError.style.display = 'block';
                }
                const firstBad = fields.find((f) => ctls[f.key].classList.contains('invalid'));
                if (firstBad) ctls[firstBad.key].focus();
            };

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (busy) return;
                clearErrors();
                const v = values();
                const problems = {};
                fields.forEach((f) => {
                    if (cells[f.key].style.display === 'none') return;
                    const val = v[f.key];
                    if (f.required && f.type !== 'toggle' && (val === null || val === '')) problems[f.key] = `${f.label} wajib diisi.`;
                    else if (f.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) problems[f.key] = 'Format email tidak valid.';
                    else if (f.type === 'decimal' && val && Number.isNaN(parseDecimal(val))) problems[f.key] = `${f.label} harus berupa angka.`;
                    else if (f.maxLength && typeof val === 'string' && val.length > f.maxLength) problems[f.key] = `${f.label} maksimal ${f.maxLength} karakter.`;
                });
                const extra = validate ? (validate(v) || {}) : {};
                Object.keys(extra).forEach((k) => { if (extra[k] && !problems[k]) problems[k] = extra[k]; });
                const keys = Object.keys(problems);
                if (keys.length) {
                    keys.forEach((k) => setError(k, problems[k]));
                    const firstField = fields.find((f) => problems[f.key]);
                    if (firstField) ctls[firstField.key].focus();
                    return;
                }
                busy = true;
                saveBtn.disabled = true;
                cancelBtn.disabled = true;
                const savedLabel = saveBtn.textContent;
                saveBtn.textContent = 'Menyimpan…';
                try {
                    const result = await onSubmit(v);
                    busy = false;
                    finish(result === undefined ? true : result);
                } catch (err) {
                    busy = false;
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    saveBtn.textContent = savedLabel;
                    if (err && err.code === 'NETWORK_ERROR') UI.handleApiError(err);
                    routeServerError(err);
                }
            });

            const first = fields.find((f) => f.type !== 'toggle' && !f.readonly);
            if (first) setTimeout(() => ctls[first.key].focus(), 0);
        });
    }

    return {
        statusBadge, actionsMenu,
        confirmDeletePermanent, confirmDeactivate, confirmActivate,
        handleDeleteError, formModal,
        pageHeader, recordModal, parseDecimal,
    };
})();
