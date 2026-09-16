/**
 * D10 — Koreksi Stok (Stock Adjustment). Deliberately a SEPARATE flow from
 * Transaksi Masuk/Keluar and from Pembatalan Transaksi (void, wired into
 * reports.js's ledger view) — this is the only path allowed to correct a
 * quantity outside of a normal transaction, always with a mandatory reason
 * and a full audit trail (before_qty_base/after_qty_base), never a direct
 * edit of an existing transaction's qty/price.
 */
const Adjustments = (() => {
    let adjustmentUuid = null;

    const TYPES = [
        ['CORRECTION', 'Koreksi Hitung'],
        ['DAMAGE', 'Rusak'],
        ['EXPIRED', 'Kadaluarsa'],
        ['LOSS', 'Hilang'],
        ['OTHER', 'Lainnya'],
    ];

    function render(container) {
        adjustmentUuid = null;
        const itemOptions = Master.items().map((i) => `<option value="${i.id}">${i.sku} — ${i.name}</option>`).join('');
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const typeOptions = TYPES.map(([v, l]) => `<option value="${v}">${l}</option>`).join('');

        container.appendChild(UI.el('div', { class: 'card', style: 'border-left: 3px solid var(--orange);' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🛠️ Koreksi Stok (Stock Adjustment)')]),
            UI.el('div', { class: 'alert alert-warning' }, 'Gunakan menu ini HANYA untuk koreksi stok fisik (bukan untuk membatalkan transaksi yang salah — gunakan tombol "Batalkan" pada Histori Barang untuk itu). Alasan wajib diisi.'),
            UI.el('div', { id: 'adjustment-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang</label><select id="adjustment-item">${itemOptions}</select></div>
                <div class="form-group"><label>Gudang</label><select id="adjustment-warehouse">${whOptions}</select></div>
                <div class="form-group"><label>Jenis Koreksi</label><select id="adjustment-type">${typeOptions}</select></div>
                <div class="form-group"><label>Selisih Qty (base unit, +/-)</label><input type="number" id="adjustment-delta" step="any" placeholder="mis. -5 atau 3"></div>
                <div class="form-group"><label>Harga Pokok (jika menambah &amp; tidak ada histori harga)</label><input type="number" id="adjustment-cost" min="0" step="any"></div>
            ` }),
            UI.el('div', { class: 'form-group', html: '<label>Alasan (wajib)</label><textarea id="adjustment-reason" rows="2"></textarea>' }),
            UI.el('button', { class: 'btn btn-warning', id: 'adjustment-submit-btn' }, 'Simpan Koreksi Stok'),
        ]));

        document.getElementById('adjustment-submit-btn').addEventListener('click', () => submit());
    }

    async function submit(extra = {}) {
        const btn = document.getElementById('adjustment-submit-btn');
        const alertBox = document.getElementById('adjustment-alert');
        alertBox.innerHTML = '';

        const delta = Number(document.getElementById('adjustment-delta').value);
        const reason = document.getElementById('adjustment-reason').value.trim();
        if (!delta) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Selisih qty tidak boleh 0.'));
            return;
        }
        if (!reason) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Alasan wajib diisi.'));
            return;
        }

        if (!adjustmentUuid) adjustmentUuid = InvApi.newRequestUuid();
        btn.disabled = true;
        try {
            const cost = document.getElementById('adjustment-cost').value;
            const result = await InvApi.postAdjustment({
                transaction_uuid: adjustmentUuid,
                item_id: Number(document.getElementById('adjustment-item').value),
                warehouse_id: Number(document.getElementById('adjustment-warehouse').value),
                qty_base_delta: delta,
                adjustment_type: document.getElementById('adjustment-type').value,
                reason,
                override_cost_base: cost ? Number(cost) : undefined,
                ...extra,
            });
            alertBox.appendChild(UI.el('div', { class: 'alert alert-success' }, `Koreksi tersimpan. Stok sebelum: ${UI.formatNumber(result.before_qty_base)}, sesudah: ${UI.formatNumber(result.after_qty_base)}.`));
            adjustmentUuid = null;
            document.getElementById('adjustment-delta').value = '';
            document.getElementById('adjustment-reason').value = '';
            document.getElementById('adjustment-cost').value = '';
        } catch (err) {
            if (err && err.code === 'NETWORK_ERROR') {
                UI.handleApiError(err);
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Koneksi terputus — coba simpan ulang, aman (tidak akan tercatat dobel).'));
            } else if (err && err.code === 'COST_REQUIRED') {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, `${err.message} — isi Harga Pokok di atas lalu simpan ulang.`));
            } else if (err && err.code === 'NEGATIVE_STOCK') {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, err.message));
            } else {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan koreksi.'));
            }
        } finally {
            btn.disabled = false;
        }
    }

    return { render };
})();
