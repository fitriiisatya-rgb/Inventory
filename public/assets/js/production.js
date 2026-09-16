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

    function render(container) {
        container.innerHTML = '';
        inputCount = 0;
        productionUuid = null;
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const divisionOptions = Master.divisions().map((d) => `<option value="${d.id}">${d.name}</option>`).join('');
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');

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
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang Hasil</label><select id="production-output-item">${itemOptions}</select></div>
                <div class="form-group"><label>Satuan</label><select id="production-output-unit"></select></div>
                <div class="form-group"><label>Jumlah Hasil</label><input type="number" id="production-output-qty" min="0" step="any"></div>
            ` }),
            UI.el('div', { class: 'form-group', html: '<label>Catatan (opsional)</label><input type="text" id="production-notes">' }),
            UI.el('button', { class: 'btn btn-success', id: 'production-submit-btn' }, '✅ Proses Produksi'),
            UI.el('div', { id: 'production-result' }),
        ]);
        container.appendChild(card);

        addInputRow();
        document.getElementById('production-add-input').addEventListener('click', addInputRow);
        document.getElementById('production-submit-btn').addEventListener('click', submitProduction);
        wireUnitLoader(document.getElementById('production-output-item'), document.getElementById('production-output-unit'));
    }

    function wireUnitLoader(itemSel, unitSel) {
        const load = async () => {
            unitSel.innerHTML = '<option>Memuat...</option>';
            try {
                const units = await InvApi.itemUnits(itemSel.value);
                unitSel.innerHTML = units.map((u) => `<option value="${u.id}">${u.code}</option>`).join('') || '<option value="">-</option>';
            } catch (e) {
                unitSel.innerHTML = '<option value="">Gagal memuat</option>';
            }
        };
        itemSel.addEventListener('change', load);
        if (itemSel.value) load();
    }

    function addInputRow() {
        const idx = inputCount++;
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');
        const row = UI.el('div', { class: 'grid-3', id: `production-input-${idx}` }, [
            UI.el('div', { class: 'form-group', html: `<label>Bahan</label><select class="production-input-item" data-idx="${idx}">${itemOptions}</select>` }),
            UI.el('div', { class: 'form-group', html: `<label>Satuan</label><select class="production-input-unit" data-idx="${idx}"></select>` }),
            UI.el('div', { class: 'form-group', html: `<label>Jumlah</label><input type="number" class="production-input-qty" data-idx="${idx}" min="0" step="any">` }),
        ]);
        document.getElementById('production-inputs').appendChild(row);
        wireUnitLoader(row.querySelector('.production-input-item'), row.querySelector('.production-input-unit'));
    }

    async function submitProduction() {
        const btn = document.getElementById('production-submit-btn');
        const alertBox = document.getElementById('production-alert');
        const resultBox = document.getElementById('production-result');
        alertBox.innerHTML = '';
        resultBox.innerHTML = '';

        const inputs = [];
        document.querySelectorAll('.production-input-item').forEach((sel) => {
            const idx = sel.dataset.idx;
            const qty = document.querySelector(`.production-input-qty[data-idx="${idx}"]`).value;
            const unit = document.querySelector(`.production-input-unit[data-idx="${idx}"]`).value;
            if (sel.value && qty && unit) {
                inputs.push({ item_id: Number(sel.value), input_qty: Number(qty), input_unit_id: Number(unit) });
            }
        });
        const outputItem = document.getElementById('production-output-item').value;
        const outputUnit = document.getElementById('production-output-unit').value;
        const outputQty = document.getElementById('production-output-qty').value;

        if (!inputs.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Tambahkan minimal satu bahan baku.'));
            return;
        }
        if (!outputItem || !outputUnit || !outputQty) {
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
                output: { item_id: Number(outputItem), output_unit_id: Number(outputUnit), output_qty: Number(outputQty) },
            });
            resultBox.appendChild(UI.el('div', { class: 'alert alert-success' }, [
                `Produksi #${result.production_id} berhasil diproses. `,
                UI.el('br'),
                `Total Biaya Bahan: ${UI.formatMoney(result.total_input_cost)} — `,
                `Jumlah Hasil: ${UI.formatNumber(result.output_qty_base)} — `,
                `HPP per Unit Hasil: ${UI.formatMoney(result.output_unit_cost_base)}`,
            ]));
            productionUuid = null;
            document.getElementById('production-inputs').innerHTML = '';
            inputCount = 0;
            addInputRow();
            document.getElementById('production-output-qty').value = '';
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
