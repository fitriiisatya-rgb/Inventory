/**
 * D7 — Transfer Antar Gudang. ship -> PENDING -> Konfirmasi Terima ->
 * RECEIVED. The frontend never adds destination stock itself; every number
 * shown after receive/cancel comes straight from TransferService's own
 * response. Cancel is only offered because the backend allows it (PENDING
 * only) — a rejected cancel just surfaces the server's error.
 */
const Transfers = (() => {
    let lineCount = 0;
    let transferWarehouses = [];
    let sourceWarehouseId = null;
    // PHASE V2.14.7/V2.14.8 — idx -> { ctl, qtyInput, unitHost, rowEl,
    // noCell, stockCell, warnCell, stockBase }. Each line gets its own
    // independent ItemSelector instance (unlike Distribution Order's
    // single re-mounted Quick Add bar) because Transfer lets the admin
    // fill in several rows simultaneously, search/select on each
    // independently, and remove any one of them without disturbing the
    // others — ctl.destroy() is always called before a row's DOM is
    // removed. V2.14.8: rows are real <tr>s in a compact, sticky-header,
    // scrollable table (not stacked grid-3 blocks) — ItemSelector mounts
    // with showLabel:false/compact:true/externalUnitHost so "Barang" and
    // "Satuan" land in their own table cells per the required header
    // (No | Barang/SKU | Satuan | Qty Transfer | Stok Tersedia | Aksi).
    let lineSelectors = new Map();

    const receiveUuids = new Map();
    const cancelUuids = new Map();
    const reverseUuids = new Map();

    async function render(container) {
        container.innerHTML =
            '<div class="alert alert-info">Memuat modul transfer...</div>';

        try {
            const options = await InvApi.transferDestinations();

            transferWarehouses = options.warehouses || [];
            sourceWarehouseId = options.source_warehouse_id
                ? Number(options.source_warehouse_id)
                : null;

            container.innerHTML = '';
            container.appendChild(buildCreateForm());
            container.appendChild(
                UI.el('div', { id: 'transfer-list-wrap' })
            );

            loadList();
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML =
                `<div class="alert alert-error">Gagal memuat modul transfer: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildCreateForm() {
        const allWarehouseOptions = transferWarehouses
            .map((w) => `<option value="${w.id}">${w.name}</option>`)
            .join('');

        const fromWarehouseOptions = sourceWarehouseId !== null
            ? transferWarehouses
                .filter((w) => Number(w.id) === sourceWarehouseId)
                .map((w) => `<option value="${w.id}">${w.name}</option>`)
                .join('')
            : allWarehouseOptions;

        const toWarehouseOptions = sourceWarehouseId !== null
            ? transferWarehouses
                .filter((w) => Number(w.id) !== sourceWarehouseId)
                .map((w) => `<option value="${w.id}">${w.name}</option>`)
                .join('')
            : allWarehouseOptions;

        const sourceDisabled =
            sourceWarehouseId !== null ? ' disabled' : '';

        lineCount = 0;
        lineSelectors.forEach((l) => l.ctl.destroy());
        lineSelectors = new Map();
        const tbody = UI.el('tbody', { id: 'transfer-lines-tbody' });
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🚚 Buat Transfer Baru')]),
            UI.el('div', { id: 'transfer-create-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Gudang Asal</label><select id="transfer-from-wh"${sourceDisabled}>${fromWarehouseOptions}</select></div>
                <div class="form-group"><label>Gudang Tujuan</label><select id="transfer-to-wh">${toWarehouseOptions}</select></div>
                <div class="form-group"><label>Tanggal Kirim</label><input type="date" id="transfer-ship-date" value="${new Date().toISOString().slice(0, 10)}"></div>
            ` }),
            UI.el('div', { class: 'compact-table-toolbar' }, [
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'transfer-add-line' }, '+ Tambah Barang'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'transfer-add-5' }, '+ 5 Baris'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'transfer-add-10' }, '+ 10 Baris'),
            ]),
            UI.el('div', { class: 'compact-table-wrap' }, [
                UI.el('table', { class: 'compact-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Barang / SKU', 'Satuan', 'Qty Transfer', 'Stok Tersedia', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    tbody,
                ]),
            ]),
            UI.el('div', { class: 'compact-summary', id: 'transfer-summary' }),
            UI.el('div', { style: 'margin-top:14px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'transfer-submit-btn' }, 'Kirim Transfer'),
            ]),
        ]);
        setTimeout(() => {
            addLine();
            document.getElementById('transfer-add-line').addEventListener('click', () => addLine());
            document.getElementById('transfer-add-5').addEventListener('click', () => { for (let i = 0; i < 5; i++) addLine(); });
            document.getElementById('transfer-add-10').addEventListener('click', () => { for (let i = 0; i < 10; i++) addLine(); });
            document.getElementById('transfer-submit-btn').addEventListener('click', submitTransfer);
            document.getElementById('transfer-from-wh')?.addEventListener('change', refreshAllStock);
        }, 0);
        return card;
    }

    function currentSourceWarehouseId() {
        const el = document.getElementById('transfer-from-wh');
        return el && el.value ? Number(el.value) : null;
    }

    function addLine() {
        const idx = lineCount++;
        const itemSelectorHost = UI.el('div');
        const unitHost = UI.el('td', {});
        const qtyInput = UI.el('input', { type: 'number', class: 'transfer-line-qty', min: '0', step: 'any' });
        const qtyWarn = UI.el('div', { class: 'compact-inline-warning', style: 'display:none;' });
        const stockCell = UI.el('td', { class: 'compact-col-stock' }, '-');
        const noCell = UI.el('td', { class: 'compact-col-no' }, String(lineSelectors.size + 1));
        const removeBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm compact-row-btn', title: 'Hapus baris ini' }, '✕');
        const itemCell = UI.el('td', { class: 'compact-col-item' }, [itemSelectorHost]);
        const qtyCell = UI.el('td', { class: 'compact-col-qty' }, [qtyInput, qtyWarn]);
        const row = UI.el('tr', { id: `transfer-line-${idx}` }, [
            noCell, itemCell, unitHost, qtyCell, stockCell,
            UI.el('td', { class: 'compact-col-action' }, [removeBtn]),
        ]);
        document.getElementById('transfer-lines-tbody').appendChild(row);

        const entry = { qtyInput, unitHost, rowEl: row, noCell, stockCell, qtyWarn, stockBase: null, itemId: null };
        const ctl = ItemSelector.mount(itemSelectorHost, {
            showLabel: false, compact: true, externalUnitHost: unitHost,
            onChange: (state) => {
                entry.itemId = state.valid ? state.itemId : null;
                refreshStockFor(idx);
                checkDuplicates();
            },
        });
        entry.ctl = ctl;
        lineSelectors.set(idx, entry);
        qtyInput.addEventListener('input', () => checkQtyWarning(idx));

        removeBtn.addEventListener('click', () => removeLine(idx));
        renumberRows();
        updateSummary();
    }

    // PHASE V2.14.7 — destroys this line's ItemSelector instance BEFORE
    // removing its DOM (never after), then removes the row. Other lines'
    // selectors are untouched (each is fully independent — see the
    // lineSelectors Map). If this was the last remaining line, a fresh
    // blank line is added automatically rather than letting the form sit
    // at zero lines (Transfer always requires at least one line to submit;
    // this is simpler and more consistent with the existing UX than a
    // conditionally-disabled remove button).
    function removeLine(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        entry.ctl.destroy();
        entry.rowEl.remove();
        lineSelectors.delete(idx);
        if (lineSelectors.size === 0) addLine();
        renumberRows();
        checkDuplicates();
        updateSummary();
    }

    function renumberRows() {
        let n = 1;
        lineSelectors.forEach((entry) => { entry.noCell.textContent = String(n++); });
    }

    // PHASE V2.14.8, requirement D — "Stok Tersedia" always reflects
    // GUDANG ASAL, reusing the exact same InvApi.currentStock() endpoint
    // Distribution Order's own quick-add table already uses (no new
    // endpoint). entry.stockBase itself is always the raw BASE quantity
    // the API returns — never touch that value, never touch inventory.
    // STABILIZATION — the DISPLAYED figure (what's actually rendered
    // into stockCell) now follows the currently SELECTED input unit via
    // renderStockDisplay()/currentUnitFactor() below, since "Stok
    // Tersedia" showing a raw base number regardless of the unit someone
    // just picked was confusing UX (base stock 192 KG shown even after
    // switching to KARTON, where the real answer is 192/32 = 6 KARTON).
    async function refreshStockFor(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        const whId = currentSourceWarehouseId();
        if (!entry.itemId || !whId) { entry.stockBase = null; entry.stockCell.textContent = '-'; return; }
        entry.stockCell.textContent = '...';
        try {
            const stock = await InvApi.currentStock(entry.itemId, whId);
            const current = lineSelectors.get(idx);
            if (!current || current.itemId !== entry.itemId) return; // selection changed while awaiting
            current.stockBase = Number(stock.qty_base);
            renderStockDisplay(idx);
            checkQtyWarning(idx);
        } catch (err) {
            entry.stockCell.textContent = '-';
        }
    }

    function refreshAllStock() {
        lineSelectors.forEach((entry, idx) => { if (entry.itemId) refreshStockFor(idx); });
    }

    // The conversion_to_base of whichever unit is CURRENTLY selected in
    // this line's ItemSelector — reads straight from its own already-
    // loaded state (never a new fetch), exactly as checkQtyWarning()
    // already did for its base-equivalent comparison below. Base unit
    // itself always carries conversion_to_base = 1 (see item-selector.js's
    // own base-unit backfill), so this is correct for every unit in the
    // dropdown, not just conversions.
    function currentUnitFactor(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return 1;
        const state = entry.ctl.getState();
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));
        const factor = unit ? Number(unit.conversion_to_base) : 1;
        return factor > 0 ? factor : 1;
    }

    // STABILIZATION — renders entry.stockBase (the real, unconverted base
    // quantity — never mutated) divided by the selected unit's factor:
    // available_in_selected_unit = available_base_qty / conversion_to_base.
    // Called after every fresh currentStock() fetch AND whenever the
    // selected unit alone changes (ItemSelector's onChange fires on a
    // pure unit change too — see addLine()'s onChange below) — never a
    // new network call for a unit-only change, since base stock doesn't
    // depend on which unit is selected. UI.formatNumber() already caps
    // at 2 decimals (id-ID locale) — the same convention every other
    // quantity on this screen uses — so this naturally preserves
    // fractional results (e.g. 192/500 KARTON = "0,38") without any
    // extra rounding logic here.
    function renderStockDisplay(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        if (entry.stockBase === null) { entry.stockCell.textContent = '-'; return; }
        entry.stockCell.textContent = UI.formatNumber(entry.stockBase / currentUnitFactor(idx));
    }

    // Requirement E — inline-only warning; TransferService's own backend
    // validation is completely unchanged and remains the real gate. The
    // comparison itself is or has ever been in BASE terms on both sides
    // (entered qty, in the selected unit, converted UP via *factor,
    // against the real base stock) — correct direction, never changed
    // here. Only the NUMBER SHOWN in the warning text now matches what
    // "Stok Tersedia" itself displays (the selected-unit-converted
    // figure), so the two never contradict each other on screen.
    function checkQtyWarning(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        const qty = Number(entry.qtyInput.value);
        if (!qty || entry.stockBase === null) { entry.qtyWarn.style.display = 'none'; return; }
        const factor = currentUnitFactor(idx);
        const qtyBaseEquivalent = qty * factor;
        if (qtyBaseEquivalent > entry.stockBase) {
            entry.qtyWarn.textContent = `⚠ Melebihi stok tersedia (${UI.formatNumber(entry.stockBase / factor)})`;
            entry.qtyWarn.style.display = 'block';
        } else {
            entry.qtyWarn.style.display = 'none';
        }
        updateSummary();
    }

    // Requirement H — warn (and block submit) rather than silently merge;
    // TransferService::create() has no documented same-item-line merge
    // behavior, so two lines picking the same item stay two separate
    // lines and must be corrected by the admin, not auto-combined.
    function checkDuplicates() {
        const seen = new Map(); // itemId -> [idx,...]
        lineSelectors.forEach((entry, idx) => {
            if (!entry.itemId) return;
            if (!seen.has(entry.itemId)) seen.set(entry.itemId, []);
            seen.get(entry.itemId).push(idx);
        });
        const duplicateIdxs = new Set();
        seen.forEach((idxs) => { if (idxs.length > 1) idxs.forEach((i) => duplicateIdxs.add(i)); });
        lineSelectors.forEach((entry, idx) => {
            entry.rowEl.classList.toggle('compact-row-duplicate', duplicateIdxs.has(idx));
        });
        return duplicateIdxs.size > 0;
    }

    function updateSummary() {
        const box = document.getElementById('transfer-summary');
        if (!box) return;
        let totalItem = 0;
        let totalQty = 0;
        lineSelectors.forEach((entry) => {
            const qty = Number(entry.qtyInput.value);
            if (entry.itemId && qty > 0) { totalItem++; totalQty += qty; }
        });
        box.innerHTML = '';
        box.appendChild(UI.el('div', {}, ['Total Item: ', UI.el('b', {}, String(totalItem))]));
        box.appendChild(UI.el('div', {}, ['Total Qty: ', UI.el('b', {}, UI.formatNumber(totalQty))]));
    }

    let transferUuid = null;

    async function submitTransfer() {
        const btn = document.getElementById('transfer-submit-btn');
        const alertBox = document.getElementById('transfer-create-alert');
        alertBox.innerHTML = '';
        const fromWh = document.getElementById('transfer-from-wh').value;
        const toWh = document.getElementById('transfer-to-wh').value;
        if (!fromWh || !toWh) {
            alertBox.appendChild(
                UI.el(
                    'div',
                    { class: 'alert alert-error' },
                    'Gudang asal dan tujuan wajib dipilih.'
                )
            );
            return;
        }

        if (fromWh === toWh) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Gudang asal dan tujuan harus berbeda.'));
            return;
        }
        // Requirement H — block rather than silently merge duplicate item
        // lines (rows are already visually flagged via checkDuplicates()
        // on every selection change; this is the submit-time gate).
        if (checkDuplicates()) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Barang yang sama dipilih di lebih dari satu baris (ditandai merah) — gabungkan menjadi satu baris atau ganti barangnya sebelum mengirim.'));
            return;
        }
        const lines = [];
        lineSelectors.forEach((entry) => {
            const state = entry.ctl.getState();
            const qty = entry.qtyInput.value;
            if (state.valid && state.itemId && state.unitId && qty) {
                lines.push({ item_id: Number(state.itemId), input_qty: Number(qty), input_unit_id: Number(state.unitId) });
            }
        });
        if (!lines.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Tambahkan minimal satu barang dengan jumlah dan satuan.'));
            return;
        }

        if (!transferUuid) transferUuid = InvApi.newRequestUuid();
        btn.disabled = true;
        try {
            await InvApi.createTransfer({
                transfer_uuid: transferUuid,
                from_warehouse_id: Number(fromWh),
                to_warehouse_id: Number(toWh),
                ship_date: document.getElementById('transfer-ship-date').value,
                lines,
            });
            alertBox.appendChild(UI.el('div', { class: 'alert alert-success' }, 'Transfer berhasil dikirim (status PENDING).'));
            transferUuid = null;
            lineSelectors.forEach((l) => l.ctl.destroy());
            lineSelectors = new Map();
            document.getElementById('transfer-lines-tbody').innerHTML = '';
            lineCount = 0;
            addLine();
            loadList();
        } catch (err) {
            if (err && err.code === 'NETWORK_ERROR') {
                UI.handleApiError(err);
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Koneksi terputus — coba kirim ulang, aman (tidak akan tercatat dobel).'));
            } else {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal membuat transfer.'));
            }
        } finally {
            btn.disabled = false;
        }
    }

    async function loadList() {
        const wrap = document.getElementById('transfer-list-wrap');
        wrap.innerHTML = '<div class="alert alert-info">Memuat daftar transfer...</div>';
        try {
            const [all, pending] = await Promise.all([InvApi.listTransfers(), InvApi.listPendingTransfers()]);
            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [
                    UI.el('div', { class: 'card-title' }, '📦 Daftar Transfer'),
                    UI.el('span', { class: 'badge badge-pending' }, `${pending.length} PENDING`),
                ]),
                buildTable(all),
            ]));
            wireRowActions();
        } catch (err) {
            UI.handleApiError(err);
            wrap.innerHTML = `<div class="alert alert-error">Gagal memuat daftar transfer: ${(err && err.message) || ''}</div>`;
        }
    }

    function whName(id) {
        const wh = transferWarehouses.find(
            (row) => Number(row.id) === Number(id)
        ) || Master.warehouseById(id);

        return wh ? wh.name : `#${id}`;
    }

    function buildTable(rows) {
        const canTrace = Auth.hasPermission('AUDIT_LOG_VIEW');
        const body = rows.map((t) => {
            const ta = transferActions(t);
            const actionsCell = Array.isArray(ta) ? ta.slice() : (ta === '-' ? [] : [ta]);
            if (canTrace) {
                actionsCell.push(UI.el('button', { class: 'btn btn-secondary btn-sm transfer-trace-btn', 'data-id': String(t.id), style: 'margin-left:6px;' }, '🔍 Lihat Jejak'));
            }
            return UI.el('tr', {}, [
                UI.el('td', {}, `#${t.id}`),
                UI.el('td', {}, whName(t.from_warehouse_id)),
                UI.el('td', {}, whName(t.to_warehouse_id)),
                UI.el('td', {}, UI.formatDate(t.ship_date)),
                UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(t.status)}` }, t.status)),
                UI.el('td', {}, actionsCell.length ? actionsCell : '-'),
            ]);
        });
        return UI.el('div', { class: 'table-wrapper' }, [
            UI.el('table', {}, [
                UI.el('thead', {}, [UI.el('tr', {}, ['ID', 'Dari', 'Ke', 'Tgl Kirim', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, body.length ? body : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Belum ada transfer')])]),
            ]),
        ]);
    }

    function transferActions(t) {
        // PHASE V2.5 — RECEIVED transfer correction: privileged-only
        // (TRANSFER_REVERSE, never granted to STOCK — see the migration),
        // so the permission check alone decides visibility here, same as
        // the backend route (no per-warehouse gating on top of it).
        if (t.status === 'RECEIVED') {
            if (!Auth.hasPermission('TRANSFER_REVERSE')) {
                return '-';
            }
            return [
                UI.el('button', {
                    class: 'btn btn-danger btn-sm transfer-reverse-btn',
                    'data-id': String(t.id),
                }, 'Reverse Transfer'),
            ];
        }

        if (t.status !== 'PENDING') {
            return '-';
        }

        // ADMIN/SUPERADMIN: backend permission remains authoritative.
        if (sourceWarehouseId === null) {
            return [
                UI.el('button', {
                    class: 'btn btn-success btn-sm transfer-receive-btn',
                    'data-id': String(t.id)
                }, 'Konfirmasi Terima'),
                UI.el('button', {
                    class: 'btn btn-danger btn-sm transfer-cancel-btn',
                    'data-id': String(t.id),
                    style: 'margin-left:6px;'
                }, 'Batalkan'),
            ];
        }

        const actions = [];

        if (Number(t.to_warehouse_id) === sourceWarehouseId) {
            actions.push(
                UI.el('button', {
                    class: 'btn btn-success btn-sm transfer-receive-btn',
                    'data-id': String(t.id)
                }, 'Konfirmasi Terima')
            );
        }

        if (Number(t.from_warehouse_id) === sourceWarehouseId) {
            actions.push(
                UI.el('button', {
                    class: 'btn btn-danger btn-sm transfer-cancel-btn',
                    'data-id': String(t.id)
                }, 'Batalkan')
            );
        }

        return actions.length ? actions : '-';
    }

    function wireRowActions() {
        document.querySelectorAll('.transfer-receive-btn').forEach((btn) => btn.addEventListener('click', () => receiveTransfer(Number(btn.dataset.id), btn)));
        document.querySelectorAll('.transfer-cancel-btn').forEach((btn) => btn.addEventListener('click', () => cancelTransfer(Number(btn.dataset.id), btn)));
        document.querySelectorAll('.transfer-reverse-btn').forEach((btn) => btn.addEventListener('click', () => reverseTransfer(Number(btn.dataset.id), btn)));
        document.querySelectorAll('.transfer-trace-btn').forEach((btn) => btn.addEventListener('click', () => TraceDrawer.openTransfer(Number(btn.dataset.id))));
    }

    async function receiveTransfer(id, btn) {
        if (!receiveUuids.has(id)) receiveUuids.set(id, InvApi.newRequestUuid());
        btn.disabled = true;
        try {
            await InvApi.receiveTransfer(id, { request_uuid: receiveUuids.get(id) });
            UI.toast(`Transfer #${id} diterima.`, 'success');
            receiveUuids.delete(id);
            loadList();
        } catch (err) {
            UI.handleApiError(err);
            btn.disabled = false;
        }
    }

    async function cancelTransfer(id, btn) {
        const reason = prompt('Alasan pembatalan transfer ini:');
        if (!reason || !reason.trim()) return;
        if (!cancelUuids.has(id)) cancelUuids.set(id, InvApi.newRequestUuid());
        btn.disabled = true;
        try {
            await InvApi.cancelTransfer(id, { request_uuid: cancelUuids.get(id), reason: reason.trim() });
            UI.toast(`Transfer #${id} dibatalkan.`, 'success');
            cancelUuids.delete(id);
            loadList();
        } catch (err) {
            UI.handleApiError(err);
            btn.disabled = false;
        }
    }

    // PHASE V2.5 — Reverse Transfer (RECEIVED only). Fetches the full
    // transfer (with lines) for the confirmation modal's Total Item/Total
    // Nilai — the list endpoint only returns the header.
    async function reverseTransfer(id, btn) {
        btn.disabled = true;
        let full;
        try {
            full = await InvApi.getTransfer(id);
        } catch (err) {
            UI.handleApiError(err);
            btn.disabled = false;
            return;
        }
        const totalItem = (full.lines || []).length;
        const totalNilai = (full.lines || []).reduce((sum, l) => sum + Number(l.qty_base) * Number(l.unit_cost_base), 0);

        const reason = await Modal.form({
            title: 'REVERSE TRANSFER',
            infoRows: [
                ['Transfer', `#${full.id}`],
                ['Gudang Asal', whName(full.from_warehouse_id)],
                ['Gudang Tujuan', whName(full.to_warehouse_id)],
                ['Tanggal', UI.formatDate(full.ship_date)],
                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(full.status)}` }, full.status)],
                ['Total Item', String(totalItem)],
                ['Total Nilai', UI.formatMoney(totalNilai)],
            ],
            warning: 'Reversal akan membalik seluruh rantai transfer dan FIFO terkait.',
            reasonLabel: 'Alasan Reversal',
            reasonPlaceholder: 'Jelaskan alasan reversal transfer ini (minimal 5 karakter)',
            confirmLabel: 'Reverse Transfer',
            cancelLabel: 'Batal',
            danger: true,
        });
        if (reason === null) {
            btn.disabled = false;
            return;
        }

        if (!reverseUuids.has(id)) reverseUuids.set(id, InvApi.newRequestUuid());
        try {
            await InvApi.reverseTransfer(id, { request_uuid: reverseUuids.get(id), reason });
            UI.toast(`Transfer #${id} berhasil direversal.`, 'success');
            reverseUuids.delete(id);
            loadList();
        } catch (err) {
            btn.disabled = false;
            if (err && err.dependencies && err.dependencies.length) {
                const list = err.dependencies.map((dep) => `#${dep.id} — ${dep.transaction_type} (${UI.formatDate(dep.transaction_date)})`).join('\n');
                await Modal.alert({
                    title: 'Transfer Tidak Dapat Direversal Otomatis',
                    message: `Transfer tidak dapat direversal otomatis karena stok hasil transfer sudah digunakan oleh transaksi berikutnya:\n\n${list}`,
                });
                return;
            }
            UI.handleApiError(err);
            await Modal.alert({ title: 'Gagal Reverse Transfer', message: (err && err.message) || 'Terjadi kesalahan.' });
        }
    }

    return { render };
})();
