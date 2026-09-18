/**
 * D5/D6, rebuilt PHASE 4-A as the approved V2 stepper UI. The frontend
 * only collects qty/unit/price/warehouse/etc — the server remains the
 * sole authority on unit conversion, base_qty, cost, FIFO consumption,
 * and stock validation. This file never computes a final cost or
 * balance itself; every number shown before POST is explicitly a
 * PREVIEW (computed client-side from already-loaded master data, for
 * the operator's benefit only), and every number shown after POST comes
 * straight from what InvApi.postTransactionIn/Out actually returned.
 *
 * PHASE 4-A note: this is a frontend workflow/orchestration change only.
 * It calls the exact same POST /transactions/in and POST /transactions/out
 * endpoints, with the exact same payload shape, as the pre-stepper form.
 * FIFO, transaction semantics, warehouse scoping, and idempotency are
 * unchanged — see the idempotency comment on requestUuid below, which
 * preserves the original "one uuid per action, reused across every retry
 * of that SAME action, only a fresh uuid after an actual post or an
 * explicit reset" rule byte-for-byte from the pre-stepper implementation.
 *
 * Operator never chooses a FIFO layer — the "FIFO layer preview" in the
 * OUT stepper's review step is read-only, informational, computed by
 * walking the already-loaded batch list in the same (received_date ASC,
 * id ASC) order FifoService itself consumes in — never sent to the
 * server, never influences the actual server-side consumption.
 */
const Transactions = (() => {
    const IN_STEPS = ['Informasi', 'Barang', 'Review', 'Selesai'];
    const OUT_STEPS = ['Tujuan', 'Barang', 'Review FIFO', 'Selesai'];

    // One state object per kind, so IN and OUT steppers on the same page
    // never interfere with each other's idempotency key or step position.
    function freshState() {
        return {
            step: 1,
            warehouseId: null, itemId: null, unitId: null, qty: '', price: '',
            supplierId: '', divisionId: '', bakeryDestinationId: '',
            reference: '', date: new Date().toISOString().slice(0, 10),
            allowNegative: false, negativeReason: '',
            requestUuid: null,
            lastResult: null,
        };
    }
    const state = { in: freshState(), out: freshState() };

    function render(container) {
        container.innerHTML = '';
        container.appendChild(buildStepper('in'));
        container.appendChild(buildStepper('out'));
    }

    function isStockUser() {
        return Auth.user().role_code === 'STOCK';
    }
    function ownWarehouseId() {
        return Auth.user().warehouse_id || null;
    }

    // ============================================================
    // Shared stepper chrome
    // ============================================================
    function buildStepper(kind) {
        const s = state[kind];
        if (isStockUser() && !s.warehouseId) s.warehouseId = ownWarehouseId();

        const card = UI.el('div', { class: 'card', id: `tx-${kind}-card` }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, kind === 'in' ? '📥 Transaksi Masuk (Stock IN)' : '📤 Transaksi Keluar (Stock OUT)')]),
            renderStepperNav(kind === 'in' ? IN_STEPS : OUT_STEPS, s.step),
            UI.el('div', { id: `tx-${kind}-step-body` }),
        ]);
        setTimeout(() => renderStep(kind), 0);
        return card;
    }

    function renderStepperNav(steps, currentStep) {
        const nodes = [];
        steps.forEach((label, idx) => {
            const num = idx + 1;
            const cls = num === currentStep ? 'stepper-step active' : (num < currentStep ? 'stepper-step done' : 'stepper-step');
            nodes.push(UI.el('div', { class: cls }, `${num < currentStep ? '✓' : num}. ${label}`));
            if (idx < steps.length - 1) nodes.push(UI.el('div', { class: 'stepper-sep' }, '→'));
        });
        return UI.el('div', { class: 'stepper' }, nodes);
    }

    function stepBody(kind) {
        return document.getElementById(`tx-${kind}-step-body`);
    }

    function goToStep(kind, step) {
        state[kind].step = step;
        rerenderCard(kind);
    }

    function rerenderCard(kind) {
        const card = document.getElementById(`tx-${kind}-card`);
        if (!card) return;
        const container = card.parentElement;
        const fresh = buildStepper(kind);
        card.replaceWith(fresh);
    }

    function navButtons(kind, { backStep = null, nextLabel = 'Lanjut', onNext, nextDisabled = false } = {}) {
        const buttons = [];
        if (backStep !== null) {
            const backBtn = UI.el('button', { class: 'btn btn-secondary' }, '‹ Kembali');
            backBtn.addEventListener('click', () => goToStep(kind, backStep));
            buttons.push(backBtn);
        }
        if (onNext) {
            const nextBtn = UI.el('button', { class: 'btn btn-primary', ...(nextDisabled ? { disabled: 'disabled' } : {}) }, nextLabel);
            nextBtn.addEventListener('click', onNext);
            buttons.push(nextBtn);
        }
        return UI.el('div', { style: 'display:flex; gap:10px; margin-top:16px;' }, buttons);
    }

    // ============================================================
    // Step dispatch
    // ============================================================
    function renderStep(kind) {
        const s = state[kind];
        const body = stepBody(kind);
        if (!body) return;
        body.innerHTML = '';
        if (kind === 'in') {
            if (s.step === 1) body.appendChild(inStep1Informasi());
            else if (s.step === 2) body.appendChild(inStep2Barang());
            else if (s.step === 3) body.appendChild(inStep3Review());
            else body.appendChild(step4Selesai('in'));
        } else {
            if (s.step === 1) body.appendChild(outStep1Tujuan());
            else if (s.step === 2) body.appendChild(outStep2Barang());
            else if (s.step === 3) body.appendChild(outStep3ReviewFifo());
            else body.appendChild(step4Selesai('out'));
        }
    }

    // ============================================================
    // STOCK IN
    // ============================================================
    function inStep1Informasi() {
        const s = state.in;
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}" ${String(w.id) === String(s.warehouseId) ? 'selected' : ''}>${w.name}</option>`).join('');
        const supplierOptions = Master.suppliers().filter((v) => v.is_active).map((v) => `<option value="${v.id}" ${String(v.id) === String(s.supplierId) ? 'selected' : ''}>${v.name}</option>`).join('');

        const wrap = UI.el('div', {}, [
            UI.el('div', { class: 'grid-2', html: `
                <div class="form-group"><label>Gudang</label>
                    <select id="in-wh" ${isStockUser() ? 'disabled' : ''}>${whOptions}</select>
                </div>
                <div class="form-group"><label>Vendor / Supplier (opsional)</label>
                    <select id="in-supplier"><option value="">-</option>${supplierOptions}</select>
                </div>
                <div class="form-group"><label>Referensi (opsional)</label><input type="text" id="in-ref" value="${s.reference}"></div>
                <div class="form-group"><label>Tanggal Transaksi</label><input type="date" id="in-date" value="${s.date}"></div>
            ` }),
        ]);
        setTimeout(() => {
            document.getElementById('in-wh')?.addEventListener('change', (e) => { s.warehouseId = e.target.value; });
            document.getElementById('in-supplier').addEventListener('change', (e) => { s.supplierId = e.target.value; });
            document.getElementById('in-ref').addEventListener('input', (e) => { s.reference = e.target.value; });
            document.getElementById('in-date').addEventListener('change', (e) => { s.date = e.target.value; });
        }, 0);
        wrap.appendChild(navButtons('in', {
            onNext: () => {
                if (!s.warehouseId) { UI.toast('Gudang wajib dipilih.', 'error'); return; }
                goToStep('in', 2);
            },
        }));
        return wrap;
    }

    function inStep2Barang() {
        const s = state.in;
        const itemOptions = Master.items().map((i) => `<option value="${i.id}" ${String(i.id) === String(s.itemId) ? 'selected' : ''}>${i.sku} — ${i.name}</option>`).join('');

        const wrap = UI.el('div', {}, [
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang</label><select id="in-item"><option value="">- pilih barang -</option>${itemOptions}</select></div>
                <div class="form-group"><label>Satuan</label><select id="in-unit"></select></div>
                <div class="form-group"><label>Jumlah</label><input type="number" id="in-qty" min="0" step="any" value="${s.qty}"></div>
                <div class="form-group"><label>Harga Satuan (Rp)</label><input type="number" id="in-price" min="0" step="any" value="${s.price}"></div>
            ` }),
        ]);
        setTimeout(() => {
            const itemSel = document.getElementById('in-item');
            const unitSel = document.getElementById('in-unit');
            const loadUnits = async () => {
                if (!itemSel.value) { unitSel.innerHTML = ''; return; }
                unitSel.innerHTML = '<option>Memuat...</option>';
                try {
                    const units = await InvApi.itemUnits(itemSel.value);
                    unitSel.innerHTML = units.map((u) => `<option value="${u.id}" data-factor="${u.conversion_to_base}">${u.code} (${u.name})</option>`).join('')
                        || '<option value="">(belum ada satuan terdaftar)</option>';
                    s.unitId = unitSel.value || null;
                } catch (err) {
                    UI.handleApiError(err);
                    unitSel.innerHTML = '<option value="">Gagal memuat satuan</option>';
                }
            };
            itemSel.addEventListener('change', () => { s.itemId = itemSel.value; loadUnits(); });
            unitSel.addEventListener('change', () => { s.unitId = unitSel.value; });
            document.getElementById('in-qty').addEventListener('input', (e) => { s.qty = e.target.value; });
            document.getElementById('in-price').addEventListener('input', (e) => { s.price = e.target.value; });
            if (itemSel.value) loadUnits();
        }, 0);
        wrap.appendChild(navButtons('in', {
            backStep: 1,
            onNext: () => {
                if (!s.itemId || !s.unitId || !(Number(s.qty) > 0) || !(Number(s.price) >= 0)) {
                    UI.toast('Barang, satuan, jumlah (>0), dan harga wajib diisi dengan benar.', 'error');
                    return;
                }
                goToStep('in', 3);
            },
        }));
        return wrap;
    }

    // Synchronous return (a loading placeholder) + async population — never
    // returns a Promise to the caller, which just does body.appendChild(...).
    function inStep3Review() {
        const s = state.in;
        const wrap = UI.el('div', {}, [UI.el('div', { id: 'in-review-alert' }), UI.el('div', { class: 'alert alert-info' }, 'Memuat review...')]);

        (async () => {
            const item = Master.itemById(s.itemId);
            const wh = Master.warehouseById(s.warehouseId);
            const supplier = s.supplierId ? Master.supplierById(s.supplierId) : null;

            let factor = 1;
            try {
                const units = await InvApi.itemUnits(s.itemId);
                const unit = units.find((u) => String(u.id) === String(s.unitId));
                if (unit) factor = Number(unit.conversion_to_base);
            } catch (err) { /* preview-only; POST will still validate authoritatively */ }
            const baseQtyPreview = Number(s.qty) * factor;
            const valuePreview = Number(s.qty) * Number(s.price);

            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { id: 'in-review-alert' }));
            wrap.appendChild(Drawer.kv([
                ['Gudang', wh ? wh.name : '-'],
                ['Vendor', supplier ? supplier.name : '-'],
                ['Referensi', s.reference || '-'],
                ['Tanggal Transaksi', s.date],
                ['Barang', item ? `${item.sku} — ${item.name}` : '-'],
                ['Jumlah Input', UI.formatNumber(Number(s.qty))],
                ['Konversi ke Base (preview)', UI.formatNumber(factor, 6)],
                ['Qty Base (preview)', UI.formatNumber(baseQtyPreview)],
                ['Harga Satuan', UI.formatMoney(Number(s.price))],
                ['Nilai Transaksi', UI.formatMoney(valuePreview)],
            ]));
            wrap.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.75rem; margin-top:10px;' }, 'Nilai "preview" dihitung di browser untuk membantu peninjauan — nilai final tetap dihitung dan divalidasi oleh server saat disimpan.'));

            const postBtn = UI.el('button', { class: 'btn btn-primary', id: 'in-post-btn' }, 'Simpan Transaksi Masuk');
            postBtn.addEventListener('click', () => submitIn());
            wrap.appendChild(UI.el('div', { style: 'display:flex; gap:10px; margin-top:16px;' }, [
                (() => { const b = UI.el('button', { class: 'btn btn-secondary' }, '‹ Kembali'); b.addEventListener('click', () => goToStep('in', 2)); return b; })(),
                postBtn,
            ]));
        })();
        return wrap;
    }

    // Idempotency: requestUuid is generated once on the FIRST submit
    // attempt and reused across every retry of that SAME action (network
    // failure, PRICE_ANOMALY override) — only cleared on an actual
    // successful post or an explicit "Transaksi Baru" reset. Identical
    // rule to the pre-stepper implementation.
    async function submitIn(extra = {}) {
        const s = state.in;
        if (!s.requestUuid) s.requestUuid = InvApi.newRequestUuid();
        const postBtn = document.getElementById('in-post-btn');
        if (postBtn) postBtn.disabled = true;
        const alertBox = document.getElementById('in-review-alert');
        if (alertBox) alertBox.innerHTML = '';
        try {
            const payload = {
                transaction_uuid: s.requestUuid,
                item_id: Number(s.itemId), warehouse_id: Number(s.warehouseId),
                input_unit_id: Number(s.unitId), input_qty: Number(s.qty),
                unit_price_input: Number(s.price), transaction_date: s.date,
                reference_no: s.reference || null, supplier_id: s.supplierId || null,
                ...extra,
            };
            const result = await InvApi.postTransactionIn(payload);
            s.lastResult = { ...result, transaction_uuid: s.requestUuid };
            s.requestUuid = null;
            goToStep('in', 4);
        } catch (err) {
            await handleTxError('in', err, extra, submitIn);
            if (postBtn) postBtn.disabled = false;
        }
    }

    // ============================================================
    // STOCK OUT
    // ============================================================
    function outStep1Tujuan() {
        const s = state.out;
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}" ${String(w.id) === String(s.warehouseId) ? 'selected' : ''}>${w.name}</option>`).join('');
        const bakeryOptions = Master.bakeryDestinations().filter((b) => b.is_active).map((b) => `<option value="${b.id}" ${String(b.id) === String(s.bakeryDestinationId) ? 'selected' : ''}>${b.name}</option>`).join('');
        const divisionOptions = Master.divisions().map((d) => `<option value="${d.id}" ${String(d.id) === String(s.divisionId) ? 'selected' : ''}>${d.name}</option>`).join('');

        const wrap = UI.el('div', {}, [
            UI.el('div', { class: 'grid-2', html: `
                <div class="form-group"><label>Gudang (Asal)</label>
                    <select id="out-wh" ${isStockUser() ? 'disabled' : ''}>${whOptions}</select>
                </div>
                <div class="form-group"><label>Bakery Tujuan (opsional)</label>
                    <select id="out-bakery"><option value="">-</option>${bakeryOptions}</select>
                </div>
                <div class="form-group"><label>Divisi Tujuan (opsional)</label>
                    <select id="out-division"><option value="">-</option>${divisionOptions}</select>
                </div>
                <div class="form-group"><label>Referensi (opsional)</label><input type="text" id="out-ref" value="${s.reference}"></div>
                <div class="form-group"><label>Tanggal Transaksi</label><input type="date" id="out-date" value="${s.date}"></div>
            ` }),
        ]);
        setTimeout(() => {
            document.getElementById('out-wh')?.addEventListener('change', (e) => { s.warehouseId = e.target.value; });
            document.getElementById('out-bakery').addEventListener('change', (e) => { s.bakeryDestinationId = e.target.value; });
            document.getElementById('out-division').addEventListener('change', (e) => { s.divisionId = e.target.value; });
            document.getElementById('out-ref').addEventListener('input', (e) => { s.reference = e.target.value; });
            document.getElementById('out-date').addEventListener('change', (e) => { s.date = e.target.value; });
        }, 0);
        wrap.appendChild(navButtons('out', {
            onNext: () => {
                if (!s.warehouseId) { UI.toast('Gudang wajib dipilih.', 'error'); return; }
                goToStep('out', 2);
            },
        }));
        return wrap;
    }

    function outStep2Barang() {
        const s = state.out;
        const itemOptions = Master.items().map((i) => `<option value="${i.id}" ${String(i.id) === String(s.itemId) ? 'selected' : ''}>${i.sku} — ${i.name}</option>`).join('');

        const wrap = UI.el('div', {}, [
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Barang</label><select id="out-item"><option value="">- pilih barang -</option>${itemOptions}</select></div>
                <div class="form-group"><label>Satuan</label><select id="out-unit"></select></div>
                <div class="form-group"><label>Jumlah Diminta</label><input type="number" id="out-qty" min="0" step="any" value="${s.qty}"></div>
            ` }),
            UI.el('div', { class: 'form-group', html: `
                <label style="display:flex; align-items:center; gap:8px; text-transform:none;"><input type="checkbox" id="out-allow-negative" ${s.allowNegative ? 'checked' : ''} style="width:auto;"> Izinkan stok negatif</label>
                <input type="text" id="out-negative-reason" placeholder="Alasan stok negatif (wajib jika dicentang)" value="${s.negativeReason}" style="margin-top:6px;">
            ` }),
        ]);
        setTimeout(() => {
            const itemSel = document.getElementById('out-item');
            const unitSel = document.getElementById('out-unit');
            const loadUnits = async () => {
                if (!itemSel.value) { unitSel.innerHTML = ''; return; }
                unitSel.innerHTML = '<option>Memuat...</option>';
                try {
                    const units = await InvApi.itemUnits(itemSel.value);
                    unitSel.innerHTML = units.map((u) => `<option value="${u.id}" data-factor="${u.conversion_to_base}">${u.code} (${u.name})</option>`).join('')
                        || '<option value="">(belum ada satuan terdaftar)</option>';
                    s.unitId = unitSel.value || null;
                } catch (err) {
                    UI.handleApiError(err);
                    unitSel.innerHTML = '<option value="">Gagal memuat satuan</option>';
                }
            };
            itemSel.addEventListener('change', () => { s.itemId = itemSel.value; loadUnits(); });
            unitSel.addEventListener('change', () => { s.unitId = unitSel.value; });
            document.getElementById('out-qty').addEventListener('input', (e) => { s.qty = e.target.value; });
            document.getElementById('out-allow-negative').addEventListener('change', (e) => { s.allowNegative = e.target.checked; });
            document.getElementById('out-negative-reason').addEventListener('input', (e) => { s.negativeReason = e.target.value; });
            if (itemSel.value) loadUnits();
        }, 0);
        wrap.appendChild(navButtons('out', {
            backStep: 1,
            onNext: () => {
                if (!s.itemId || !s.unitId || !(Number(s.qty) > 0)) {
                    UI.toast('Barang, satuan, dan jumlah (>0) wajib diisi dengan benar.', 'error');
                    return;
                }
                goToStep('out', 3);
            },
        }));
        return wrap;
    }

    // Synchronous return (a loading placeholder) + async population — never
    // returns a Promise to the caller, which just does body.appendChild(...).
    function outStep3ReviewFifo() {
        const s = state.out;
        const wrap = UI.el('div', {}, [UI.el('div', { id: 'out-review-alert' }), UI.el('div', { class: 'alert alert-info' }, 'Memuat review...')]);

        (async () => {
            const item = Master.itemById(s.itemId);
            const wh = Master.warehouseById(s.warehouseId);
            const bakery = s.bakeryDestinationId ? Master.bakeryDestinationById(s.bakeryDestinationId) : null;

            let factor = 1;
            let currentStock = null;
            let batches = [];
            try {
                const units = await InvApi.itemUnits(s.itemId);
                const unit = units.find((u) => String(u.id) === String(s.unitId));
                if (unit) factor = Number(unit.conversion_to_base);
            } catch (err) { /* preview-only */ }
            try {
                currentStock = await InvApi.currentStock(s.itemId, s.warehouseId);
            } catch (err) { /* shown as unavailable below */ }
            try {
                batches = await InvApi.batches(s.itemId, s.warehouseId);
            } catch (err) { /* FIFO preview simply won't show */ }

            const baseQtyPreview = Number(s.qty) * factor;
            const currentQty = currentStock ? Number(currentStock.qty_base) : null;
            const estimatedRemaining = currentQty !== null ? currentQty - baseQtyPreview : null;

            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { id: 'out-review-alert' }));
            wrap.appendChild(Drawer.kv([
                ['Gudang', wh ? wh.name : '-'],
                ['Bakery Tujuan', bakery ? bakery.name : '-'],
                ['Referensi', s.reference || '-'],
                ['Tanggal Transaksi', s.date],
                ['Barang', item ? `${item.sku} — ${item.name}` : '-'],
                ['Jumlah Diminta', UI.formatNumber(Number(s.qty))],
                ['Qty Base (preview)', UI.formatNumber(baseQtyPreview)],
                ['Stok Saat Ini', currentQty !== null ? UI.formatNumber(currentQty) : 'Gagal memuat'],
                ['Estimasi Sisa Stok (preview)', estimatedRemaining !== null ? UI.formatNumber(estimatedRemaining) : '-'],
            ]));

            if (currentStock && currentStock.migration_negative_review) {
                wrap.appendChild(UI.el('div', { class: 'alert alert-warning', style: 'margin-top:10px;' }, 'Item ini berstatus MIGRATION_NEGATIVE_REVIEW — transaksi OUT akan DITOLAK oleh server sampai saldo diperbaiki melalui Stock Opname / Stock Adjustment.'));
            }
            if (estimatedRemaining !== null && estimatedRemaining < 0 && !s.allowNegative) {
                wrap.appendChild(UI.el('div', { class: 'alert alert-warning', style: 'margin-top:10px;' }, 'Estimasi sisa stok negatif. Server akan menolak transaksi ini kecuali "Izinkan stok negatif" dicentang (kembali ke langkah Barang).'));
            }

            wrap.appendChild(UI.el('div', { class: 'drawer-section-title', style: 'margin-top:16px;' }, 'FIFO Layer Preview (read-only — operator tidak memilih layer secara manual)'));
            wrap.appendChild(buildFifoPreviewTable(batches, baseQtyPreview));
            wrap.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.75rem; margin-top:6px;' }, 'Preview ini dihitung di browser dari data FIFO layer yang sudah dimuat, mengikuti urutan konsumsi yang sama (tanggal terima terlama dahulu) dengan server — bukan instruksi ke server. Alokasi aktual tetap dihitung ulang secara otomatis oleh server saat transaksi disimpan.'));

            const postBtn = UI.el('button', { class: 'btn btn-primary', id: 'out-post-btn' }, 'Simpan Transaksi Keluar');
            postBtn.addEventListener('click', () => submitOut());
            wrap.appendChild(UI.el('div', { style: 'display:flex; gap:10px; margin-top:16px;' }, [
                (() => { const b = UI.el('button', { class: 'btn btn-secondary' }, '‹ Kembali'); b.addEventListener('click', () => goToStep('out', 2)); return b; })(),
                postBtn,
            ]));
        })();
        return wrap;
    }

    /** Read-only simulation of FIFO consumption order — never sent to the server. */
    function buildFifoPreviewTable(batches, requestedBaseQty) {
        let remaining = requestedBaseQty;
        const rows = batches.map((b) => {
            const available = Number(b.qty_base);
            const consumed = remaining > 0 ? Math.min(available, remaining) : 0;
            remaining = Math.max(0, remaining - consumed);
            return UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(b.received_date)),
                UI.el('td', {}, UI.formatNumber(available)),
                UI.el('td', {}, UI.formatMoney(b.unit_cost_base)),
                UI.el('td', {}, consumed > 0 ? UI.formatNumber(consumed) : '-'),
            ]);
        });
        return UI.el('div', { class: 'table-wrapper' }, [
            UI.el('table', {}, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Diterima', 'Qty Tersedia', 'Unit Cost', 'Estimasi Terpakai'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Tidak ada layer FIFO aktif untuk barang ini di gudang ini')])]),
            ]),
        ]);
    }

    async function submitOut(extra = {}) {
        const s = state.out;
        if (!s.requestUuid) s.requestUuid = InvApi.newRequestUuid();
        const postBtn = document.getElementById('out-post-btn');
        if (postBtn) postBtn.disabled = true;
        const alertBox = document.getElementById('out-review-alert');
        if (alertBox) alertBox.innerHTML = '';
        try {
            const payload = {
                transaction_uuid: s.requestUuid,
                item_id: Number(s.itemId), warehouse_id: Number(s.warehouseId),
                input_unit_id: Number(s.unitId), input_qty: Number(s.qty),
                transaction_date: s.date, reference_no: s.reference || null,
                division_id: s.divisionId || null, bakery_destination_id: s.bakeryDestinationId || null,
                ...(s.allowNegative ? { allow_negative_stock: true, negative_stock_reason: s.negativeReason } : {}),
                ...extra,
            };
            const result = await InvApi.postTransactionOut(payload);
            state.out.lastResult = { ...result, transaction_uuid: s.requestUuid };
            state.out.requestUuid = null;
            goToStep('out', 4);
        } catch (err) {
            await handleTxError('out', err, extra, submitOut);
            if (postBtn) postBtn.disabled = false;
        }
    }

    // ============================================================
    // Step 4 — Selesai (shared shape, per-kind data)
    // ============================================================
    function step4Selesai(kind) {
        const s = state[kind];
        const r = s.lastResult || {};
        const wrap = UI.el('div', {}, [
            UI.el('div', { class: 'alert alert-success' }, kind === 'in' ? 'Transaksi Masuk berhasil disimpan.' : 'Transaksi Keluar berhasil disimpan.'),
            Drawer.kv([
                ['Transaction ID', r.transaction_id !== undefined ? String(r.transaction_id) : '-'],
                ['Referensi (UUID)', r.transaction_uuid || '-'],
                ['Qty Base', r.base_qty !== undefined ? UI.formatNumber(r.base_qty) : '-'],
                ['Harga Pokok / Unit', r.unit_cost_base !== undefined ? UI.formatMoney(r.unit_cost_base) : '-'],
            ]),
        ]);
        const newTxBtn = UI.el('button', { class: 'btn btn-primary', style: 'margin-top:16px;' }, kind === 'in' ? '➕ Transaksi Masuk Baru' : '➕ Transaksi Keluar Baru');
        newTxBtn.addEventListener('click', () => {
            const preservedWarehouse = isStockUser() ? ownWarehouseId() : null;
            state[kind] = freshState();
            if (preservedWarehouse) state[kind].warehouseId = preservedWarehouse;
            rerenderCard(kind);
        });
        wrap.appendChild(newTxBtn);
        return wrap;
    }

    // ============================================================
    // Shared error handling — identical branches/messages to the
    // pre-stepper implementation, only using Modal instead of native
    // confirm(), and re-rendering the current (Review) step instead of
    // a flat form on error.
    // ============================================================
    async function handleTxError(kind, err, extra, retryFn) {
        const alertBox = document.getElementById(`${kind === 'in' ? 'in' : 'out'}-review-alert`);
        const showAlert = (type, message) => {
            if (!alertBox) return;
            alertBox.innerHTML = '';
            alertBox.appendChild(UI.el('div', { class: `alert alert-${type}` }, message));
        };

        if (err && err.code === 'NETWORK_ERROR') {
            UI.handleApiError(err);
            showAlert('error', 'Koneksi ke server terputus. Transaksi BELUM tentu tersimpan — silakan coba kirim ulang (aman, tidak akan tercatat dobel).');
            return;
        }
        if (err && err.code === 'PRICE_ANOMALY') {
            const proceed = await Modal.confirm({
                title: 'Anomali Harga Terdeteksi',
                message: `${err.message}\n\nLanjutkan menyimpan dengan harga ini?`,
                confirmLabel: 'Lanjutkan', danger: true,
            });
            if (proceed) {
                await retryFn({ ...extra, anomaly_approved_by: Auth.user().id });
                return;
            }
            showAlert('warning', 'Transaksi dibatalkan karena anomali harga tidak disetujui.');
            return;
        }
        if (err && err.code === 'INSUFFICIENT_STOCK') {
            showAlert('error', `${err.message} — centang "Izinkan stok negatif" pada langkah Barang dan isi alasan jika ingin tetap melanjutkan.`);
            return;
        }
        if (err && err.code === 'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT') {
            showAlert('error', `${err.message} — selesaikan melalui Stock Opname / Stock Adjustment terlebih dahulu.`);
            return;
        }
        if (err && (err.code === 'PERIOD_LOCKED' || err.code === 'OPNAME_ACTIVE')) {
            showAlert('error', err.message);
            return;
        }
        showAlert('error', (err && err.message) || 'Gagal menyimpan transaksi.');
    }

    return { render };
})();
