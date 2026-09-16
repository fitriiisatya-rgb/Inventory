/**
 * D7 — Transfer Antar Gudang. ship -> PENDING -> Konfirmasi Terima ->
 * RECEIVED. The frontend never adds destination stock itself; every number
 * shown after receive/cancel comes straight from TransferService's own
 * response. Cancel is only offered because the backend allows it (PENDING
 * only) — a rejected cancel just surfaces the server's error.
 */
const Transfers = (() => {
    let lineCount = 0;
    const receiveUuids = new Map();
    const cancelUuids = new Map();

    function render(container) {
        container.innerHTML = '';
        container.appendChild(buildCreateForm());
        container.appendChild(UI.el('div', { id: 'transfer-list-wrap' }));
        loadList();
    }

    function buildCreateForm() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        lineCount = 0;
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🚚 Buat Transfer Baru')]),
            UI.el('div', { id: 'transfer-create-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Gudang Asal</label><select id="transfer-from-wh">${whOptions}</select></div>
                <div class="form-group"><label>Gudang Tujuan</label><select id="transfer-to-wh">${whOptions}</select></div>
                <div class="form-group"><label>Tanggal Kirim</label><input type="date" id="transfer-ship-date" value="${new Date().toISOString().slice(0, 10)}"></div>
            ` }),
            UI.el('div', { id: 'transfer-lines' }),
            UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'transfer-add-line' }, '+ Tambah Barang'),
            UI.el('div', { style: 'margin-top:14px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'transfer-submit-btn' }, 'Kirim Transfer'),
            ]),
        ]);
        setTimeout(() => {
            addLine();
            document.getElementById('transfer-add-line').addEventListener('click', addLine);
            document.getElementById('transfer-submit-btn').addEventListener('click', submitTransfer);
        }, 0);
        return card;
    }

    function addLine() {
        const idx = lineCount++;
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');
        const row = UI.el('div', { class: 'grid-3', id: `transfer-line-${idx}` }, [
            UI.el('div', { class: 'form-group', html: `<label>Barang</label><select class="transfer-line-item" data-idx="${idx}">${itemOptions}</select>` }),
            UI.el('div', { class: 'form-group', html: `<label>Satuan</label><select class="transfer-line-unit" data-idx="${idx}"></select>` }),
            UI.el('div', { class: 'form-group', html: `<label>Jumlah</label><input type="number" class="transfer-line-qty" data-idx="${idx}" min="0" step="any">` }),
        ]);
        document.getElementById('transfer-lines').appendChild(row);
        const itemSel = row.querySelector('.transfer-line-item');
        const unitSel = row.querySelector('.transfer-line-unit');
        const loadUnits = async () => {
            unitSel.innerHTML = '<option>Memuat...</option>';
            try {
                const units = await InvApi.itemUnits(itemSel.value);
                unitSel.innerHTML = units.map((u) => `<option value="${u.id}">${u.code}</option>`).join('') || '<option value="">-</option>';
            } catch (err) {
                unitSel.innerHTML = '<option value="">Gagal memuat</option>';
            }
        };
        itemSel.addEventListener('change', loadUnits);
        if (itemSel.value) loadUnits();
    }

    let transferUuid = null;

    async function submitTransfer() {
        const btn = document.getElementById('transfer-submit-btn');
        const alertBox = document.getElementById('transfer-create-alert');
        alertBox.innerHTML = '';
        const fromWh = document.getElementById('transfer-from-wh').value;
        const toWh = document.getElementById('transfer-to-wh').value;
        if (fromWh === toWh) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Gudang asal dan tujuan harus berbeda.'));
            return;
        }
        const lines = [];
        document.querySelectorAll('.transfer-line-item').forEach((sel) => {
            const idx = sel.dataset.idx;
            const qty = document.querySelector(`.transfer-line-qty[data-idx="${idx}"]`).value;
            const unit = document.querySelector(`.transfer-line-unit[data-idx="${idx}"]`).value;
            if (sel.value && qty && unit) {
                lines.push({ item_id: Number(sel.value), input_qty: Number(qty), input_unit_id: Number(unit) });
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
            document.getElementById('transfer-lines').innerHTML = '';
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
        const wh = Master.warehouseById(id);
        return wh ? wh.name : `#${id}`;
    }

    function buildTable(rows) {
        const body = rows.map((t) => UI.el('tr', {}, [
            UI.el('td', {}, `#${t.id}`),
            UI.el('td', {}, whName(t.from_warehouse_id)),
            UI.el('td', {}, whName(t.to_warehouse_id)),
            UI.el('td', {}, UI.formatDate(t.ship_date)),
            UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(t.status)}` }, t.status)),
            UI.el('td', {}, t.status === 'PENDING' ? [
                UI.el('button', { class: 'btn btn-success btn-sm transfer-receive-btn', 'data-id': String(t.id) }, 'Konfirmasi Terima'),
                UI.el('button', { class: 'btn btn-danger btn-sm transfer-cancel-btn', 'data-id': String(t.id), style: 'margin-left:6px;' }, 'Batalkan'),
            ] : '-'),
        ]));
        return UI.el('div', { class: 'table-wrapper' }, [
            UI.el('table', {}, [
                UI.el('thead', {}, [UI.el('tr', {}, ['ID', 'Dari', 'Ke', 'Tgl Kirim', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, body.length ? body : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Belum ada transfer')])]),
            ]),
        ]);
    }

    function wireRowActions() {
        document.querySelectorAll('.transfer-receive-btn').forEach((btn) => btn.addEventListener('click', () => receiveTransfer(Number(btn.dataset.id), btn)));
        document.querySelectorAll('.transfer-cancel-btn').forEach((btn) => btn.addEventListener('click', () => cancelTransfer(Number(btn.dataset.id), btn)));
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

    return { render };
})();
