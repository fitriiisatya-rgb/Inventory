/**
 * D5/D6 — Transaction IN (Transaksi Masuk) and OUT (Transaksi Keluar).
 * The frontend only collects qty/unit/price/warehouse/etc — the server is
 * the sole authority on unit conversion, base_qty, cost, and stock
 * validation. This file never computes a final cost or balance itself; it
 * only renders whatever InvApi.postTransactionIn/Out returns.
 *
 * Idempotency: one request_uuid is generated per user action and reused
 * across every retry of that SAME action (network failure, PRICE_ANOMALY
 * override, negative-stock override) — a fresh uuid is only drawn after a
 * transaction actually posts (or the user resets the form).
 */
const Transactions = (() => {
    let inUuid = null;
    let outUuid = null;

    function render(container) {
        container.innerHTML = '';
        container.appendChild(buildForm('in'));
        container.appendChild(buildForm('out'));
        inUuid = null;
        outUuid = null;
    }

    function buildForm(kind) {
        const isIn = kind === 'in';
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const supplierOptions = Master.suppliers().map((s) => `<option value="${s.id}">${s.name}</option>`).join('');
        const divisionOptions = Master.divisions().map((d) => `<option value="${d.id}">${d.name}</option>`).join('');

        const card = UI.el('div', { class: 'card', id: `tx-${kind}-card` }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, isIn ? '📥 Transaksi Masuk' : '📤 Transaksi Keluar')]),
            UI.el('div', { id: `tx-${kind}-alert` }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang</label><select id="tx-${kind}-item">${itemOptions}</select></div>
                <div class="form-group"><label>Gudang</label><select id="tx-${kind}-warehouse">${whOptions}</select></div>
                <div class="form-group"><label>Satuan</label><select id="tx-${kind}-unit"></select></div>
                <div class="form-group"><label>Jumlah</label><input type="number" id="tx-${kind}-qty" min="0" step="any"></div>
                ${isIn ? `<div class="form-group"><label>Harga Satuan (Rp)</label><input type="number" id="tx-${kind}-price" min="0" step="any"></div>` : ''}
                ${isIn ? `<div class="form-group"><label>Supplier</label><select id="tx-${kind}-supplier"><option value="">-</option>${supplierOptions}</select></div>` : `<div class="form-group"><label>Divisi Tujuan</label><select id="tx-${kind}-division"><option value="">-</option>${divisionOptions}</select></div>`}
                <div class="form-group"><label>Tanggal Transaksi</label><input type="date" id="tx-${kind}-date" value="${new Date().toISOString().slice(0, 10)}"></div>
                <div class="form-group"><label>Referensi (opsional)</label><input type="text" id="tx-${kind}-ref"></div>
            ` }),
            !isIn ? UI.el('div', { class: 'form-group', id: 'tx-out-negative-wrap', html: `
                <label style="display:flex; align-items:center; gap:8px; text-transform:none;"><input type="checkbox" id="tx-out-allow-negative" style="width:auto;"> Izinkan stok negatif</label>
                <input type="text" id="tx-out-negative-reason" placeholder="Alasan stok negatif (wajib jika dicentang)" style="margin-top:6px;">
            ` }) : null,
            UI.el('button', { class: 'btn btn-primary', id: `tx-${kind}-submit` }, isIn ? 'Simpan Transaksi Masuk' : 'Simpan Transaksi Keluar'),
        ]);

        setTimeout(() => wire(kind), 0);
        return card;
    }

    function wire(kind) {
        const itemSel = document.getElementById(`tx-${kind}-item`);
        const unitSel = document.getElementById(`tx-${kind}-unit`);
        const submitBtn = document.getElementById(`tx-${kind}-submit`);

        const loadUnits = async () => {
            unitSel.innerHTML = '<option>Memuat...</option>';
            try {
                const units = await InvApi.itemUnits(itemSel.value);
                unitSel.innerHTML = units.map((u) => `<option value="${u.id}">${u.code} (${u.name})</option>`).join('')
                    || '<option value="">(belum ada satuan terdaftar untuk barang ini)</option>';
            } catch (err) {
                UI.handleApiError(err);
                unitSel.innerHTML = '<option value="">Gagal memuat satuan</option>';
            }
        };
        itemSel.addEventListener('change', loadUnits);
        if (itemSel.value) loadUnits();

        submitBtn.addEventListener('click', () => (kind === 'in' ? submitIn() : submitOut()));
    }

    function alertBox(kind) {
        return document.getElementById(`tx-${kind}-alert`);
    }

    function showAlert(kind, type, message) {
        const box = alertBox(kind);
        box.innerHTML = '';
        box.appendChild(UI.el('div', { class: `alert alert-${type}` }, message));
    }

    function clearAlert(kind) {
        alertBox(kind).innerHTML = '';
    }

    function basePayload(kind) {
        return {
            item_id: Number(document.getElementById(`tx-${kind}-item`).value),
            warehouse_id: Number(document.getElementById(`tx-${kind}-warehouse`).value),
            input_unit_id: Number(document.getElementById(`tx-${kind}-unit`).value),
            input_qty: Number(document.getElementById(`tx-${kind}-qty`).value),
            transaction_date: document.getElementById(`tx-${kind}-date`).value,
            reference_no: document.getElementById(`tx-${kind}-ref`).value || null,
        };
    }

    async function submitIn(extra = {}) {
        if (!inUuid) inUuid = InvApi.newRequestUuid();
        const submitBtn = document.getElementById('tx-in-submit');
        submitBtn.disabled = true;
        clearAlert('in');
        try {
            const payload = {
                ...basePayload('in'),
                transaction_uuid: inUuid,
                unit_price_input: Number(document.getElementById('tx-in-price').value),
                supplier_id: document.getElementById('tx-in-supplier').value || null,
                ...extra,
            };
            const result = await InvApi.postTransactionIn(payload);
            showAlert('in', 'success', `Transaksi Masuk tersimpan. Qty base: ${UI.formatNumber(result.base_qty)}, Harga pokok/unit: ${UI.formatMoney(result.unit_cost_base)}.`);
            inUuid = null;
            document.getElementById('tx-in-qty').value = '';
            document.getElementById('tx-in-price').value = '';
        } catch (err) {
            await handleTxError('in', err, extra, submitIn);
        } finally {
            submitBtn.disabled = false;
        }
    }

    async function submitOut(extra = {}) {
        if (!outUuid) outUuid = InvApi.newRequestUuid();
        const submitBtn = document.getElementById('tx-out-submit');
        submitBtn.disabled = true;
        clearAlert('out');
        try {
            const allowNegative = document.getElementById('tx-out-allow-negative').checked;
            const negReason = document.getElementById('tx-out-negative-reason').value;
            const payload = {
                ...basePayload('out'),
                transaction_uuid: outUuid,
                division_id: document.getElementById('tx-out-division').value || null,
                ...(allowNegative ? { allow_negative_stock: true, negative_stock_reason: negReason } : {}),
                ...extra,
            };
            const result = await InvApi.postTransactionOut(payload);
            showAlert('out', 'success', `Transaksi Keluar tersimpan. Qty base: ${UI.formatNumber(result.base_qty)}.`);
            outUuid = null;
            document.getElementById('tx-out-qty').value = '';
            document.getElementById('tx-out-allow-negative').checked = false;
            document.getElementById('tx-out-negative-reason').value = '';
        } catch (err) {
            await handleTxError('out', err, extra, submitOut);
        } finally {
            submitBtn.disabled = false;
        }
    }

    async function handleTxError(kind, err, extra, retryFn) {
        if (err && err.code === 'NETWORK_ERROR') {
            UI.handleApiError(err);
            showAlert(kind, 'error', 'Koneksi ke server terputus. Transaksi BELUM tentu tersimpan — silakan coba kirim ulang (aman, tidak akan tercatat dobel).');
            return;
        }
        if (err && err.code === 'PRICE_ANOMALY') {
            const proceed = confirm(`${err.message}\n\nLanjutkan menyimpan dengan harga ini?`);
            if (proceed) {
                await retryFn({ ...extra, anomaly_approved_by: Auth.user().id });
                return;
            }
            showAlert(kind, 'warning', 'Transaksi dibatalkan karena anomali harga tidak disetujui.');
            return;
        }
        if (err && err.code === 'INSUFFICIENT_STOCK') {
            showAlert(kind, 'error', `${err.message} — centang "Izinkan stok negatif" dan isi alasan jika ingin tetap melanjutkan.`);
            return;
        }
        if (err && (err.code === 'PERIOD_LOCKED' || err.code === 'OPNAME_ACTIVE')) {
            showAlert(kind, 'error', err.message);
            return;
        }
        showAlert(kind, 'error', (err && err.message) || 'Gagal menyimpan transaksi.');
    }

    return { render };
})();
