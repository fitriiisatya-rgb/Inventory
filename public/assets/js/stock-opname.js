/**
 * D9 — Stock Opname. Start -> Count -> Finalize (variance) -> Post
 * (creates real stock adjustments, unlocks the warehouse). The
 * "STOCK OPNAME ACTIVE" banner is cosmetic — the backend's own
 * WarehouseLockService is what actually blocks other postings against a
 * warehouse mid-opname, regardless of what this UI shows.
 */
const StockOpname = (() => {
    let currentWarehouseId = null;
    let currentSessionId = null;

    function render(container) {
        container.innerHTML = '';
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        container.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📋 Stock Opname')]),
            UI.el('div', { class: 'form-group', html: `<label>Gudang</label><select id="opname-wh">${whOptions}</select>` }),
        ]));
        container.appendChild(UI.el('div', { id: 'opname-body' }));

        document.getElementById('opname-wh').addEventListener('change', loadForWarehouse);
        if (Master.warehouses().length) loadForWarehouse();
    }

    async function loadForWarehouse() {
        currentWarehouseId = Number(document.getElementById('opname-wh').value);
        const body = document.getElementById('opname-body');
        body.innerHTML = '<div class="alert alert-info">Memeriksa status opname gudang ini...</div>';
        try {
            const sessions = await InvApi.listOpnameSessions({ warehouse_id: currentWarehouseId });
            const active = sessions.find((s) => s.status !== 'POSTED');
            if (active) {
                await renderSession(active.id);
            } else {
                renderStartButton();
            }
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat status opname: ${(err && err.message) || ''}</div>`;
        }
    }

    function renderStartButton() {
        const body = document.getElementById('opname-body');
        body.innerHTML = '';
        body.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'alert alert-info' }, 'Tidak ada sesi opname aktif untuk gudang ini.'),
            UI.el('button', { class: 'btn btn-primary', id: 'opname-start-btn' }, 'Mulai Stock Opname'),
        ]));
        document.getElementById('opname-start-btn').addEventListener('click', startOpname);
    }

    async function startOpname() {
        const btn = document.getElementById('opname-start-btn');
        btn.disabled = true;
        try {
            const result = await InvApi.startOpname({ warehouse_id: currentWarehouseId });
            UI.toast('Sesi opname dimulai.', 'success');
            await renderSession(result.session_id);
        } catch (err) {
            UI.handleApiError(err);
            btn.disabled = false;
        }
    }

    async function renderSession(sessionId) {
        currentSessionId = sessionId;
        const body = document.getElementById('opname-body');
        body.innerHTML = '<div class="alert alert-info">Memuat sesi opname...</div>';
        try {
            const session = await InvApi.getOpname(sessionId);
            body.innerHTML = '';
            body.appendChild(UI.el('div', { class: 'banner-lock' }, `🔒 STOCK OPNAME ACTIVE — Sesi #${session.id} (status: ${session.status}). Transaksi Masuk/Keluar di gudang ini ditolak oleh server selama opname berlangsung.`));

            if (session.status === 'OPEN') {
                body.appendChild(buildCountForm(session));
                document.getElementById('opname-save-count-btn').addEventListener('click', saveCounts);
                document.getElementById('opname-finalize-btn').addEventListener('click', finalizeOpname);
            } else if (session.status === 'FINALIZED') {
                body.appendChild(buildFinalizedView(session));
                document.getElementById('opname-post-btn').addEventListener('click', postOpname);
            } else {
                body.appendChild(UI.el('div', { class: 'alert alert-success' }, 'Sesi opname sudah diposting.'));
            }
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat sesi: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildCountForm(session) {
        const rows = session.lines.map((line) => UI.el('tr', {}, [
            UI.el('td', {}, `${line.sku} — ${line.name}`),
            UI.el('td', {}, UI.formatNumber(line.system_qty_base)),
            UI.el('td', {}, UI.el('input', { type: 'number', class: 'opname-count-input', 'data-item-id': String(line.item_id), step: 'any', value: line.counted_qty_base ?? '' })),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, 'Hitung Fisik'),
            UI.el('div', { id: 'opname-count-alert' }),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Stok Sistem', 'Hasil Hitung Fisik'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows),
                ]),
            ]),
            UI.el('div', { style: 'margin-top:12px; display:flex; gap:8px;' }, [
                UI.el('button', { class: 'btn btn-secondary', id: 'opname-save-count-btn' }, 'Simpan Hitungan'),
                UI.el('button', { class: 'btn btn-warning', id: 'opname-finalize-btn' }, 'Finalisasi (Hitung Selisih)'),
            ]),
        ]);
    }

    function buildFinalizedView(session) {
        const rows = session.lines.filter((l) => Number(l.variance_qty_base) !== 0).map((line) => UI.el('tr', {}, [
            UI.el('td', {}, `${line.sku} — ${line.name}`),
            UI.el('td', {}, UI.formatNumber(line.system_qty_base)),
            UI.el('td', {}, UI.formatNumber(line.counted_qty_base)),
            UI.el('td', { class: 'mono' }, UI.formatNumber(line.variance_qty_base)),
            UI.el('td', {}, Number(line.cost_required) === 1
                ? UI.el('input', { type: 'number', class: 'opname-cost-override', 'data-item-id': String(line.item_id), step: 'any', placeholder: 'wajib isi harga' })
                : '-'),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, 'Review Selisih (Variance)'),
            UI.el('div', { id: 'opname-post-alert' }),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Stok Sistem', 'Hasil Hitung', 'Selisih', 'Harga (jika wajib)'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Tidak ada selisih — stok sesuai')])]),
                ]),
            ]),
            UI.el('button', { class: 'btn btn-danger', id: 'opname-post-btn', style: 'margin-top:12px;' }, '🔐 Post Hasil Opname'),
        ]);
    }

    async function saveCounts() {
        const btn = document.getElementById('opname-save-count-btn');
        const alertBox = document.getElementById('opname-count-alert');
        alertBox.innerHTML = '';
        const counts = [];
        document.querySelectorAll('.opname-count-input').forEach((input) => {
            if (input.value !== '') {
                counts.push({ item_id: Number(input.dataset.itemId), counted_qty_base: Number(input.value) });
            }
        });
        if (!counts.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Isi minimal satu hasil hitung fisik.'));
            return;
        }
        btn.disabled = true;
        try {
            await InvApi.countOpname(currentSessionId, counts);
            UI.toast('Hitungan tersimpan.', 'success');
            await renderSession(currentSessionId);
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan hitungan.'));
        } finally {
            btn.disabled = false;
        }
    }

    async function finalizeOpname() {
        const btn = document.getElementById('opname-finalize-btn');
        const alertBox = document.getElementById('opname-count-alert');
        alertBox.innerHTML = '';
        btn.disabled = true;
        try {
            await InvApi.finalizeOpname(currentSessionId);
            UI.toast('Opname difinalisasi.', 'success');
            await renderSession(currentSessionId);
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal finalisasi — pastikan semua barang sudah dihitung.'));
        } finally {
            btn.disabled = false;
        }
    }

    async function postOpname() {
        const btn = document.getElementById('opname-post-btn');
        const alertBox = document.getElementById('opname-post-alert');
        alertBox.innerHTML = '';
        const overrides = {};
        document.querySelectorAll('.opname-cost-override').forEach((input) => {
            if (input.value !== '') overrides[input.dataset.itemId] = Number(input.value);
        });
        btn.disabled = true;
        try {
            await InvApi.postOpname(currentSessionId, overrides);
            UI.toast('Opname berhasil diposting — stok gudang sudah disesuaikan.', 'success');
            await loadForWarehouse();
        } catch (err) {
            if (err && err.code === 'COST_REQUIRED') {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, `${err.message} — isi kolom harga untuk barang tersebut sebelum posting.`));
            } else {
                UI.handleApiError(err);
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal posting opname.'));
            }
        } finally {
            btn.disabled = false;
        }
    }

    return { render };
})();
