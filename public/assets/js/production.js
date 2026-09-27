/**
 * D8 — Produksi / Racik. The frontend only picks warehouse/division, raw
 * material quantities, and the output quantity — FIFO consumption, actual
 * cost, and output unit cost are entirely the backend's (ProductionService)
 * job. This file only ever displays what the response returns; it never
 * computes Total Input Cost / Output Unit Cost itself.
 */
const Production = (() => {
    let inputCount = 0;
    let productionUuid = null;
    // PHASE V2.14.7/V2.14.8 — idx -> { ctl, qtyInput, unitHost, rowEl,
    // noCell, stockCell, warnCell, stockBase, itemId }, one independent
    // ItemSelector per input-material row inside a compact table (same
    // rationale/rendering as Transfer). outputSelectorCtl is a single
    // separate instance for "Barang Hasil" above the table — fully
    // independent of every input row's own selector/state.
    let inputSelectors = new Map();
    let outputSelectorCtl = null;
    let outputSelectorHost = null;
    let outputQtyInput = null;

    function render(container) {
        container.innerHTML = '';
        inputCount = 0;
        productionUuid = null;
        inputSelectors.forEach((l) => l.ctl.destroy());
        inputSelectors = new Map();
        if (outputSelectorCtl) outputSelectorCtl.destroy();
        outputSelectorCtl = null;
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const divisionOptions = Master.divisions().map((d) => `<option value="${d.id}">${d.name}</option>`).join('');

        outputSelectorHost = UI.el('div');
        outputQtyInput = UI.el('input', { type: 'number', id: 'production-output-qty', min: '0', step: 'any' });
        const tbody = UI.el('tbody', { id: 'production-inputs-tbody' });

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🏭 Produksi / Racik (Barang Setengah Jadi)')]),
            UI.el('div', { id: 'production-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Gudang</label><select id="production-wh">${whOptions}</select></div>
                <div class="form-group"><label>Divisi Pelaksana</label><select id="production-division"><option value="">-</option>${divisionOptions}</select></div>
                <div class="form-group"><label>Tanggal Produksi</label><input type="date" id="production-date" value="${new Date().toISOString().slice(0, 10)}"></div>
            ` }),
            UI.el('div', { class: 'card-title', style: 'font-size:0.85rem; margin-top:10px;' }, 'Bahan Baku (Input)'),
            UI.el('div', { class: 'compact-table-toolbar' }, [
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'production-add-input' }, '+ Tambah Bahan'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'production-add-5' }, '+ 5 Baris'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'production-add-10' }, '+ 10 Baris'),
            ]),
            UI.el('div', { class: 'compact-table-wrap' }, [
                UI.el('table', { class: 'compact-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Bahan / SKU', 'Satuan', 'Qty Pemakaian', 'Stok Tersedia', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    tbody,
                ]),
            ]),
            UI.el('div', { class: 'compact-summary', id: 'production-summary' }),
            UI.el('div', { class: 'card-title', style: 'font-size:0.85rem; margin-top:16px;' }, 'Hasil Produksi (Output)'),
            UI.el('div', { class: 'grid-3' }, [
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Barang Hasil'), outputSelectorHost]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Jumlah Hasil'), outputQtyInput]),
            ]),
            UI.el('div', { class: 'form-group', html: '<label>Catatan (opsional)</label><input type="text" id="production-notes">' }),
            UI.el('button', { class: 'btn btn-success', id: 'production-submit-btn' }, '✅ Proses Produksi'),
            UI.el('div', { id: 'production-result' }),
        ]);
        container.appendChild(card);

        outputSelectorCtl = ItemSelector.mount(outputSelectorHost, { onChange: () => {} });
        addInputRow();
        document.getElementById('production-add-input').addEventListener('click', () => addInputRow());
        document.getElementById('production-add-5').addEventListener('click', () => { for (let i = 0; i < 5; i++) addInputRow(); });
        document.getElementById('production-add-10').addEventListener('click', () => { for (let i = 0; i < 10; i++) addInputRow(); });
        document.getElementById('production-submit-btn').addEventListener('click', submitProduction);
        document.getElementById('production-wh')?.addEventListener('change', refreshAllStock);
    }

    function currentProductionWarehouseId() {
        const el = document.getElementById('production-wh');
        return el && el.value ? Number(el.value) : null;
    }

    function addInputRow() {
        const idx = inputCount++;
        const itemSelectorHost = UI.el('div');
        const unitHost = UI.el('td', {});
        const qtyInput = UI.el('input', { type: 'number', class: 'production-input-qty', min: '0', step: 'any' });
        const qtyWarn = UI.el('div', { class: 'compact-inline-warning', style: 'display:none;' });
        const stockCell = UI.el('td', { class: 'compact-col-stock' }, '-');
        const noCell = UI.el('td', { class: 'compact-col-no' }, String(inputSelectors.size + 1));
        const removeBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm compact-row-btn', title: 'Hapus bahan ini' }, '✕');
        const row = UI.el('tr', { id: `production-input-${idx}` }, [
            noCell,
            UI.el('td', { class: 'compact-col-item' }, [itemSelectorHost]),
            unitHost,
            UI.el('td', { class: 'compact-col-qty' }, [qtyInput, qtyWarn]),
            stockCell,
            UI.el('td', { class: 'compact-col-action' }, [removeBtn]),
        ]);
        document.getElementById('production-inputs-tbody').appendChild(row);

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
        inputSelectors.set(idx, entry);
        qtyInput.addEventListener('input', () => checkQtyWarning(idx));

        removeBtn.addEventListener('click', () => removeInputRow(idx));
        renumberRows();
        updateSummary();
    }

    // See Transfers.removeLine() — same destroy-before-remove discipline,
    // same auto-refill-when-empty rule (production always requires at
    // least one input material).
    function removeInputRow(idx) {
        const entry = inputSelectors.get(idx);
        if (!entry) return;
        entry.ctl.destroy();
        entry.rowEl.remove();
        inputSelectors.delete(idx);
        if (inputSelectors.size === 0) addInputRow();
        renumberRows();
        checkDuplicates();
        updateSummary();
    }

    function renumberRows() {
        let n = 1;
        inputSelectors.forEach((entry) => { entry.noCell.textContent = String(n++); });
    }

    // Requirement — "show available stock at selected production
    // warehouse if existing architecture supports it safely": reuses the
    // exact same InvApi.currentStock() endpoint Transfer/Distribution
    // Order already use, no new endpoint, displayed as the plain
    // base-unit quantity (same convention as those screens).
    async function refreshStockFor(idx) {
        const entry = inputSelectors.get(idx);
        if (!entry) return;
        const whId = currentProductionWarehouseId();
        if (!entry.itemId || !whId) { entry.stockBase = null; entry.stockCell.textContent = '-'; return; }
        entry.stockCell.textContent = '...';
        try {
            const stock = await InvApi.currentStock(entry.itemId, whId);
            const current = inputSelectors.get(idx);
            if (!current || current.itemId !== entry.itemId) return;
            current.stockBase = Number(stock.qty_base);
            current.stockCell.textContent = UI.formatNumber(current.stockBase);
            checkQtyWarning(idx);
        } catch (err) {
            entry.stockCell.textContent = '-';
        }
    }

    function refreshAllStock() {
        inputSelectors.forEach((entry, idx) => { if (entry.itemId) refreshStockFor(idx); });
    }

    // Warning only — never blocks submission; ProductionService's own
    // INSUFFICIENT_STOCK check (already surfaced in submitProduction's
    // catch below) remains the real, unchanged gate.
    function checkQtyWarning(idx) {
        const entry = inputSelectors.get(idx);
        if (!entry) return;
        const qty = Number(entry.qtyInput.value);
        if (!qty || entry.stockBase === null) { entry.qtyWarn.style.display = 'none'; return; }
        const state = entry.ctl.getState();
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));
        const factor = unit ? Number(unit.conversion_to_base) : 1;
        const qtyBaseEquivalent = qty * factor;
        if (qtyBaseEquivalent > entry.stockBase) {
            entry.qtyWarn.textContent = `⚠ Melebihi stok tersedia (${UI.formatNumber(entry.stockBase)})`;
            entry.qtyWarn.style.display = 'block';
        } else {
            entry.qtyWarn.style.display = 'none';
        }
        updateSummary();
    }

    // Requirement — warn/block duplicate material rows rather than
    // silently merging; ProductionService has no documented same-item
    // line-merge behavior.
    function checkDuplicates() {
        const seen = new Map();
        inputSelectors.forEach((entry, idx) => {
            if (!entry.itemId) return;
            if (!seen.has(entry.itemId)) seen.set(entry.itemId, []);
            seen.get(entry.itemId).push(idx);
        });
        const duplicateIdxs = new Set();
        seen.forEach((idxs) => { if (idxs.length > 1) idxs.forEach((i) => duplicateIdxs.add(i)); });
        inputSelectors.forEach((entry, idx) => {
            entry.rowEl.classList.toggle('compact-row-duplicate', duplicateIdxs.has(idx));
        });
        return duplicateIdxs.size > 0;
    }

    function updateSummary() {
        const box = document.getElementById('production-summary');
        if (!box) return;
        let totalBahan = 0;
        let totalQty = 0;
        inputSelectors.forEach((entry) => {
            const qty = Number(entry.qtyInput.value);
            if (entry.itemId && qty > 0) { totalBahan++; totalQty += qty; }
        });
        box.innerHTML = '';
        box.appendChild(UI.el('div', {}, ['Total Bahan: ', UI.el('b', {}, String(totalBahan))]));
        box.appendChild(UI.el('div', {}, ['Total Qty: ', UI.el('b', {}, UI.formatNumber(totalQty))]));
    }

    async function submitProduction() {
        const btn = document.getElementById('production-submit-btn');
        const alertBox = document.getElementById('production-alert');
        const resultBox = document.getElementById('production-result');
        alertBox.innerHTML = '';
        resultBox.innerHTML = '';

        const inputs = [];
        inputSelectors.forEach((entry) => {
            const state = entry.ctl.getState();
            const qty = entry.qtyInput.value;
            if (state.valid && state.itemId && state.unitId && qty) {
                inputs.push({ item_id: Number(state.itemId), input_qty: Number(qty), input_unit_id: Number(state.unitId) });
            }
        });
        const outputState = outputSelectorCtl.getState();
        const outputQty = outputQtyInput.value;

        if (!inputs.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Tambahkan minimal satu bahan baku.'));
            return;
        }
        if (checkDuplicates()) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Bahan yang sama dipilih di lebih dari satu baris (ditandai merah) — gabungkan menjadi satu baris atau ganti bahannya sebelum memproses.'));
            return;
        }
        if (!outputState.valid || !outputState.itemId || !outputState.unitId || !outputQty) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Lengkapi barang hasil, satuan, dan jumlah hasil.'));
            return;
        }

        if (!productionUuid) productionUuid = InvApi.newRequestUuid();
        btn.disabled = true;
        try {
            const result = await InvApi.postProduction({
                production_uuid: productionUuid,
                warehouse_id: Number(document.getElementById('production-wh').value),
                division_id: document.getElementById('production-division').value || null,
                production_date: document.getElementById('production-date').value,
                notes: document.getElementById('production-notes').value || null,
                inputs,
                output: { item_id: Number(outputState.itemId), output_unit_id: Number(outputState.unitId), output_qty: Number(outputQty) },
            });
            resultBox.appendChild(UI.el('div', { class: 'alert alert-success' }, [
                `Produksi #${result.production_id} berhasil diproses. `,
                UI.el('br'),
                `Total Biaya Bahan: ${UI.formatMoney(result.total_input_cost)} — `,
                `Jumlah Hasil: ${UI.formatNumber(result.output_qty_base)} — `,
                `HPP per Unit Hasil: ${UI.formatMoney(result.output_unit_cost_base)}`,
                Auth.hasPermission('AUDIT_LOG_VIEW') ? UI.el('div', { style: 'margin-top:8px;' }, [
                    UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'production-trace-btn' }, `🔍 Lihat Jejak Produksi #${result.production_id}`),
                ]) : null,
            ]));
            if (Auth.hasPermission('AUDIT_LOG_VIEW')) {
                document.getElementById('production-trace-btn').addEventListener('click', () => TraceDrawer.openProduction(result.production_id));
            }
            productionUuid = null;
            inputSelectors.forEach((l) => l.ctl.destroy());
            inputSelectors = new Map();
            document.getElementById('production-inputs-tbody').innerHTML = '';
            inputCount = 0;
            addInputRow();
            outputSelectorCtl.destroy();
            outputSelectorCtl = ItemSelector.mount(outputSelectorHost, { onChange: () => {} });
            outputQtyInput.value = '';
        } catch (err) {
            if (err && err.code === 'NETWORK_ERROR') {
                UI.handleApiError(err);
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Koneksi terputus — coba proses ulang, aman (tidak akan tercatat dobel).'));
            } else if (err && err.code === 'INSUFFICIENT_STOCK') {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, `${err.message} — bahan baku tidak cukup untuk produksi ini.`));
            } else {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal memproses produksi.'));
            }
        } finally {
            btn.disabled = false;
        }
    }

    return { render };
})();
