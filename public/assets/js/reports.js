/**
 * D4 — Stock Report / Ledger. Every figure comes straight from
 * InventoryService's ledger/currentStock endpoints; this file never
 * recomputes a running balance itself beyond what the server already
 * returns per line.
 */
const Reports = (() => {
    function render(container) {
        container.innerHTML = '';
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📊 Histori Barang / Kartu Stok')]),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang</label><select id="report-item">${itemOptions}</select></div>
                <div class="form-group"><label>Gudang</label><select id="report-warehouse">${whOptions}</select></div>
                <div class="form-group" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" id="report-load-btn">Tampilkan Histori</button></div>
            ` }),
            UI.el('div', { id: 'report-current' }),
            UI.el('div', { id: 'report-ledger' }),
        ]);
        container.appendChild(card);

        document.getElementById('report-load-btn').addEventListener('click', loadReport);
        if (Master.items().length && Master.warehouses().length) {
            loadReport();
        }
    }

    async function loadReport() {
        const itemId = document.getElementById('report-item').value;
        const warehouseId = document.getElementById('report-warehouse').value;
        if (!itemId || !warehouseId) return;

        const currentBox = document.getElementById('report-current');
        const ledgerBox = document.getElementById('report-ledger');
        currentBox.innerHTML = '<div class="alert alert-info">Memuat...</div>';
        ledgerBox.innerHTML = '';

        try {
            const [current, ledger] = await Promise.all([
                InvApi.currentStock(itemId, warehouseId),
                InvApi.ledger(itemId, warehouseId),
            ]);
            currentBox.innerHTML = '';
            currentBox.appendChild(UI.el('div', { class: 'grid-2' }, [
                UI.el('div', { class: 'kpi-card' }, [
                    UI.el('div', { class: 'kpi-label' }, 'Stok Saat Ini'),
                    UI.el('div', { class: 'kpi-value' }, UI.formatNumber(current.qty_base)),
                ]),
                UI.el('div', { class: 'kpi-card' }, [
                    UI.el('div', { class: 'kpi-label' }, 'Nilai Stok Saat Ini'),
                    UI.el('div', { class: 'kpi-value' }, UI.formatMoney(current.value)),
                ]),
            ]));

            const voidableTypes = ['IN', 'OUT', 'ADJUSTMENT'];
            const rows = ledger.map((row) => UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(row.date)),
                UI.el('td', {}, row.transaction_type),
                UI.el('td', {}, row.reference || '-'),
                UI.el('td', {}, row.in_qty ? UI.formatNumber(row.in_qty) : '-'),
                UI.el('td', {}, row.out_qty ? UI.formatNumber(row.out_qty) : '-'),
                UI.el('td', {}, UI.formatNumber(row.balance_qty)),
                UI.el('td', {}, UI.formatMoney(row.unit_cost_base)),
                UI.el('td', {}, UI.formatMoney(row.value)),
                UI.el('td', {}, voidableTypes.includes(row.transaction_type)
                    ? UI.el('button', { class: 'btn btn-danger btn-sm ledger-void-btn', 'data-tx-id': String(row.transaction_id) }, 'Batalkan')
                    : '-'),
            ]));
            ledgerBox.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Jenis', 'Referensi', 'Masuk', 'Keluar', 'Saldo', 'Harga Satuan', 'Nilai', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '9' }, 'Belum ada transaksi untuk kombinasi ini')])]),
                ]),
            ]));
            ledgerBox.querySelectorAll('.ledger-void-btn').forEach((btn) => btn.addEventListener('click', () => voidTransaction(Number(btn.dataset.txId), btn)));

            if (ledger.length) {
                const exportBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-bottom:10px;' }, '⬇️ Export CSV');
                exportBtn.addEventListener('click', () => UI.exportCsv(`ledger-${itemId}-${warehouseId}.csv`, [
                    ['date', 'Tanggal'], ['transaction_type', 'Jenis'], ['reference', 'Referensi'],
                    ['in_qty', 'Masuk'], ['out_qty', 'Keluar'], ['balance_qty', 'Saldo'],
                    ['unit_cost_base', 'Harga Satuan'], ['value', 'Nilai'],
                ], ledger));
                ledgerBox.insertBefore(exportBtn, ledgerBox.firstChild);
            }
        } catch (err) {
            UI.handleApiError(err);
            currentBox.innerHTML = `<div class="alert alert-error">Gagal memuat laporan: ${(err && err.message) || ''}</div>`;
        }
    }

    const voidUuids = new Map();

    async function voidTransaction(transactionId, btn) {
        const reason = prompt(`Alasan pembatalan transaksi #${transactionId} (wajib):`);
        if (!reason || !reason.trim()) return;
        if (!voidUuids.has(transactionId)) voidUuids.set(transactionId, InvApi.newRequestUuid());
        btn.disabled = true;
        try {
            await InvApi.voidTransaction(transactionId, { request_uuid: voidUuids.get(transactionId), reason: reason.trim() });
            UI.toast(`Transaksi #${transactionId} dibatalkan (reversal dibuat).`, 'success');
            voidUuids.delete(transactionId);
            loadReport();
        } catch (err) {
            if (err && err.code === 'PERIOD_LOCKED') {
                const override = confirm(`${err.message}\n\nPeriode ini sudah TERKUNCI. Lanjutkan sebagai SUPERADMIN OVERRIDE (tercatat di audit log)?`);
                if (override) {
                    try {
                        await InvApi.voidTransaction(transactionId, { request_uuid: voidUuids.get(transactionId), reason: reason.trim(), superadmin_override: true });
                        UI.toast(`Transaksi #${transactionId} dibatalkan (override periode terkunci).`, 'success');
                        voidUuids.delete(transactionId);
                        loadReport();
                        return;
                    } catch (err2) {
                        UI.handleApiError(err2);
                    }
                }
            } else {
                UI.handleApiError(err);
            }
            btn.disabled = false;
        }
    }

    return { render };
})();
