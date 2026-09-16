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

            const rows = ledger.map((row) => UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(row.date)),
                UI.el('td', {}, row.transaction_type),
                UI.el('td', {}, row.reference || '-'),
                UI.el('td', {}, row.in_qty ? UI.formatNumber(row.in_qty) : '-'),
                UI.el('td', {}, row.out_qty ? UI.formatNumber(row.out_qty) : '-'),
                UI.el('td', {}, UI.formatNumber(row.balance_qty)),
                UI.el('td', {}, UI.formatMoney(row.unit_cost_base)),
                UI.el('td', {}, UI.formatMoney(row.value)),
            ]));
            ledgerBox.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Jenis', 'Referensi', 'Masuk', 'Keluar', 'Saldo', 'Harga Satuan', 'Nilai'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '8' }, 'Belum ada transaksi untuk kombinasi ini')])]),
                ]),
            ]));
        } catch (err) {
            UI.handleApiError(err);
            currentBox.innerHTML = `<div class="alert alert-error">Gagal memuat laporan: ${(err && err.message) || ''}</div>`;
        }
    }

    return { render };
})();
