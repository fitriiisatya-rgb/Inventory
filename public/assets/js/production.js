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
    // PHASE V2.14.7 — idx -> { ctl, qtyInput, rowEl }, one independent
    // ItemSelector per input-material line (same rationale as Transfer).
    // outputSelectorCtl is a single separate instance for "Barang Hasil" —
    // fully independent of every input line's own selector/state.
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

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🏭 Produksi / Racik (Barang Setengah Jadi)')]),
            UI.el('div', { id: 'production-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Gudang</label><select id="production-wh">${whOptions}</select></div>
                <div class="form-group"><label>Divisi Pelaksana</label><select id="production-division"><option value="">-</option>${divisionOptions}</select></div>
                <div class="form-group"><label>Tanggal Produksi</label><input type="date" id="production-date" value="${new Date().toISOString().slice(0, 10)}"></div>
            ` }),
            UI.el('div', { class: 'card-title', style: 'font-size:0.85rem; margin-top:10px;' }, 'Bahan Baku (Input)'),
            UI.el('div', { id: 'production-inputs' }),
            UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'production-add-input' }, '+ Tambah Bahan'),
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
        document.getElementById('production-add-input').addEventListener('click', addInputRow);
        document.getElementById('production-submit-btn').addEventListener('click', submitProduction);
    }

    function addInputRow() {
        const idx = inputCount++;
        const itemSelectorHost = UI.el('div');
        const qtyInput = UI.el('input', { type: 'number', class: 'production-input-qty', min: '0', step: 'any' });
        const removeBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm', title: 'Hapus bahan ini' }, '✕ Hapus');
        const row = UI.el('div', { class: 'grid-3', id: `production-input-${idx}` }, [
            itemSelectorHost,
            UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Jumlah'), qtyInput]),
            UI.el('div', { class: 'form-group', style: 'align-self:flex-end;' }, [removeBtn]),
        ]);
        document.getElementById('production-inputs').appendChild(row);

        const ctl = ItemSelector.mount(itemSelectorHost, { onChange: () => {} });
        inputSelectors.set(idx, { ctl, qtyInput, rowEl: row });

        removeBtn.addEventListener('click', () => removeInputRow(idx));
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
            document.getElementById('production-inputs').innerHTML = '';
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
