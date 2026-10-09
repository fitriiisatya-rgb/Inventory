/**
 * STOCK IN V2 — the table-first purchase sheet (see transactions.js for the shell).
 *
 * Commercial formula (identical to PurchaseInvoiceService::quote(), which the
 * Review step and the POST use — this file only previews it live). PPN is NOT per item:
 * it is chosen ONCE (in the summary at the bottom) and applied to the total at the end.
 *   base = qty x Harga Beli ; item discount (% or Rp, <= base) ; row Total = DPP = base - discount
 *   Subtotal = SUM(row Total) ;  Invoice discount (% or Rp, <= Subtotal, taken BEFORE PPN)
 *   DPP after discount = Subtotal - invoice discount ;  PPN = DPP after discount x rate
 *   Grand Total = DPP after discount + PPN + Biaya Kirim
 * Default "Harga Beli" = the reference price of the selected unit (ItemPriceService via
 * GET /items/{id}/units); editing it affects this transaction only — master data is
 * never written. SKU and Batch are not shown; the item id is kept internally.
 */
const StockInSheet = (() => {
    const K = TxKit;
    const today = () => new Date().toISOString().slice(0, 10);
    let rowSeq = 0;
    let S = fresh();

    function isStockUser() { const u = Auth.user(); return !!(u && u.role_code === 'STOCK' && u.warehouse_id); }

    function blankRow() {
        return { id: ++rowSeq, item: null, units: [], unitId: '', qty: '', price: '', refPrice: null, discMode: 'PERCENT', discValue: '', el: null };
    }
    function fresh() {
        const u = Auth.user();
        return {
            phase: 'edit', warehouseId: isStockUser() ? String(u.warehouse_id) : '', supplierId: '', reference: '', date: today(), notes: '',
            rows: [blankRow(), blankRow(), blankRow(), blankRow(), blankRow()], ppnMode: '11', ppnCustom: '', ppnAdj: '',
            invMode: 'PERCENT', invValue: '', freight: '', freightCap: false, ppnTreatment: 'CREDITABLE', ppnPct: '',
            uuid: null, quote: null, result: null, anomalyApproved: false,
        };
    }

    // ---------------------------------------------------------------- calculation (live preview)
    /** ONE PPN rate for the whole invoice, applied at the end */
    const ppnRate = () => (S.ppnMode === 'custom' ? K.nz(S.ppnCustom) : Number(S.ppnMode));
    const rowFilled = (r) => !!(r.item || K.nz(r.qty) > 0 || K.nz(r.price) > 0);
    function calc() {
        const rows = S.rows.map((r) => {
            const out = { r, filled: rowFilled(r), errors: [], base: 0, disc: 0, dpp: 0, total: 0 };
            if (!out.filled) return out;
            const qty = K.nz(r.qty);
            const price = K.nz(r.price);
            if (!r.item) out.errors.push('Pilih barang dari hasil pencarian');
            else if (!r.unitId) out.errors.push('Pilih satuan');
            if (!(qty > 0)) out.errors.push('Qty harus lebih dari 0');
            if (r.price === '' || Number.isNaN(Number(r.price)) || price < 0) out.errors.push('Harga beli wajib diisi (≥ 0)');
            const dv = K.nz(r.discValue);
            if (dv < 0 || (r.discMode === 'PERCENT' && dv > 100)) out.errors.push('Diskon tidak valid');
            out.base = K.round4(Math.max(0, qty) * Math.max(0, price));
            out.disc = r.discMode === 'PERCENT' ? K.round4(out.base * dv / 100) : K.round4(dv);
            if (out.disc > out.base + 0.0001) { out.errors.push('Diskon melebihi nilai barang'); out.disc = out.base; }
            out.dpp = K.round4(out.base - out.disc);
            out.total = out.dpp;   // no PPN per item: the row Total is its DPP
            return out;
        });
        const filled = rows.filter((x) => x.filled);
        const subtotal = K.round4(filled.reduce((a, x) => a + x.total, 0));
        const iv = K.nz(S.invValue);
        let invDisc = S.invMode === 'PERCENT' ? K.round4(subtotal * iv / 100) : K.round4(iv);
        const problems = [];
        const rate = ppnRate();
        if (rate < 0 || rate > 100 || Number.isNaN(rate)) problems.push('PPN harus 0–100');
        if (iv < 0 || (S.invMode === 'PERCENT' && iv > 100)) problems.push('Diskon invoice tidak valid');
        if (invDisc > subtotal + 0.0001) { problems.push('Diskon invoice melebihi subtotal'); invDisc = subtotal; }
        const freight = K.nz(S.freight);
        if (freight < 0) problems.push('Biaya kirim tidak boleh negatif');
        if (!S.warehouseId) problems.push('Gudang wajib dipilih');
        if (filled.length === 0) problems.push('Tambahkan minimal satu barang');
        filled.forEach((x) => x.errors.forEach((e) => problems.push(`Baris ${S.rows.indexOf(x.r) + 1}: ${e}`)));
        const dupKeys = new Set();
        let dup = false;
        filled.forEach((x) => { if (x.r.item) { const k = `${x.r.item.id}:${x.r.unitId}`; if (dupKeys.has(k)) dup = true; dupKeys.add(k); } });
        const dppAfter = K.round4(subtotal - invDisc);
        const ppnCalc = K.round4(dppAfter * (Number.isNaN(rate) ? 0 : rate) / 100);
        // PPN follows the rate until the user types the exact amount printed on the supplier's invoice
        const adjusted = S.ppnAdj !== '' && S.ppnAdj !== null && !Number.isNaN(Number(S.ppnAdj));
        const ppn = adjusted ? K.round4(Number(S.ppnAdj)) : ppnCalc;
        if (adjusted && (ppn < 0 || ppn > dppAfter + 0.0001)) problems.push('Nominal PPN tidak valid (0 s/d subtotal setelah diskon)');
        return { rows, filled, subtotal, invDisc, dppAfter, ppn, ppnCalc, adjusted, freight, grand: K.round4(dppAfter + ppn + Math.max(0, freight)), problems, duplicate: dup };
    }

    // ---------------------------------------------------------------- mount / layout
    let ctx = null;
    let ui = {};

    function mount(c) {
        ctx = c;
        if (isStockUser() && !S.warehouseId) S.warehouseId = String(Auth.user().warehouse_id);
        draw();
    }

    function setStep() {
        const step = S.phase === 'done' ? 4 : (S.phase === 'review' ? 3 : (S.warehouseId && S.date ? 2 : 1));
        ctx.stepSlot.innerHTML = '';
        ctx.stepSlot.appendChild(K.stepper(step));
    }

    function draw() {
        ctx.host.innerHTML = '';
        ui = {};
        setStep();
        if (S.phase === 'review') return drawReview();
        if (S.phase === 'done') return drawDone();
        ctx.host.appendChild(buildHeader());
        ctx.host.appendChild(buildItemsCard());
        ctx.host.appendChild(buildBottom());
        ctx.host.appendChild(buildBar());
        refresh();
    }

    function buildHeader() {
        const whOpts = [['', 'Pilih gudang']].concat(Master.warehouses().map((w) => [w.id, w.name]));
        const wh = K.select(whOpts, S.warehouseId, (v) => { S.warehouseId = v; refresh(); setStep(); }, { disabled: isStockUser(), testid: 'in-warehouse' });
        const sup = K.select([['', 'Pilih supplier (opsional)']].concat(Master.suppliers().filter((v) => v.is_active).map((v) => [v.id, v.name])), S.supplierId, (v) => { S.supplierId = v; }, { testid: 'in-supplier' });
        const ref = UI.el('input', { type: 'text', class: 'tx2-in', placeholder: 'No. faktur / DO / dll', 'data-testid': 'in-reference', maxlength: '100' });
        ref.value = S.reference;
        ref.addEventListener('input', () => { S.reference = ref.value; });
        const date = UI.el('input', { type: 'date', class: 'tx2-in', 'data-testid': 'in-date' });
        date.value = S.date;
        date.addEventListener('change', () => { S.date = date.value; refresh(); setStep(); });
        const type = UI.el('div', { class: 'tx2-static' }, [UI.el('span', { class: 'tx2-static-ico' }, '⭳'), 'Stock IN']);
        const notes = UI.el('input', { type: 'text', class: 'tx2-in', placeholder: 'Tambahkan keterangan transaksi...', 'data-testid': 'in-notes', maxlength: '255' });
        notes.value = S.notes;
        notes.addEventListener('input', () => { S.notes = notes.value; });
        return UI.el('div', { class: 'tx2-card tx2-head', 'data-testid': 'in-header' }, [
            K.field('Gudang', wh, { required: true }), K.field('Vendor / Supplier', sup), K.field('Referensi', ref), K.field('Tanggal Transaksi', date, { required: true }),
            K.field('Tipe Transaksi', type), K.field('Catatan (Opsional)', notes, { cls: 'tx2-span3' }),
        ]);
    }

    function buildItemsCard() {
        const quick = UI.el('div', { class: 'tx2-quick' });
        const mountQuick = () => {
            quick.innerHTML = '';
            const p = K.itemPicker({ item: null, onPick: (item) => { addItem(item); mountQuick(); }, onClear: () => {}, onHint: (t) => p.setHint(t) });
            quick.appendChild(p);
        };
        mountQuick();
        const addBtn = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-add', 'data-testid': 'in-add-row' }, '+ Tambah Barang');
        addBtn.addEventListener('click', () => { const r = blankRow(); S.rows.push(r); buildRows(); r.el.pick.focus(); });

        ui.count = UI.el('span', { class: 'tx2-chip', 'data-testid': 'in-count' }, '0 item');
        ui.tbody = UI.el('tbody', { 'data-testid': 'in-rows' });
        const table = UI.el('table', { class: 'tx2-table', 'data-testid': 'in-table' }, [
            UI.el('thead', {}, [UI.el('tr', {}, [['No', 'c w-no'], ['Nama Barang', 'w-name'], ['Satuan', 'w-unit'], ['Qty', 'w-qty'], ['Harga Beli', 'w-price'], ['Diskon', 'w-disc'], ['Total', 'r w-total'], ['Aksi', 'c w-act']].map(([h, c]) => UI.el('th', { class: c }, h)))]),
            ui.tbody,
        ]);
        const card = UI.el('div', { class: 'tx2-card' }, [
            UI.el('div', { class: 'tx2-cardhead' }, [UI.el('div', { class: 'tx2-cardtitle' }, ['Daftar Barang ', ui.count]), UI.el('div', { class: 'tx2-cardtools' }, [quick, addBtn])]),
            UI.el('div', { class: 'tx2-tablewrap' }, [table]),
        ]);
        buildRows();
        return card;
    }

    // ---------------------------------------------------------------- rows
    function buildRows() {
        ui.tbody.innerHTML = '';
        S.rows.forEach((r, i) => ui.tbody.appendChild(buildRow(r, i)));
        refresh();
    }

    function addItem(item) {
        let r = S.rows.find((x) => !rowFilled(x));
        if (!r) { r = blankRow(); S.rows.push(r); }
        buildRows();
        pickItem(r, item, true);
    }

    async function pickItem(r, item, redraw) {
        r.item = item;
        r.units = [];
        r.unitId = '';
        r.price = '';
        r.refPrice = null;
        if (redraw && r.el) r.el.pick.querySelector('input').value = item.name;
        try {
            r.units = await K.unitsFor(item.id);
        } catch (err) {
            UI.handleApiError(err);
            r.item = null;
            refresh();
            return;
        }
        if (r.item !== item) return; // changed meanwhile
        const u = r.units[0];
        r.unitId = u ? String(u.id) : '';
        applyUnitPrice(r);
        if (S.rows[S.rows.length - 1] === r) { S.rows.push(blankRow()); buildRows(); } else { syncRowControls(r); refresh(); }
    }

    /** a price is only ever valid for the unit it was resolved for → replace on every unit change */
    function applyUnitPrice(r) {
        const u = r.units.find((x) => String(x.id) === String(r.unitId));
        r.refPrice = u && u.reference_price !== null && u.reference_price !== undefined ? Number(u.reference_price) : null;
        r.price = r.refPrice !== null ? String(r.refPrice) : '';
    }

    function buildRow(r, i) {
        const tr = UI.el('tr', { 'data-testid': 'in-row' });
        const no = UI.el('td', { class: 'c' }, String(i + 1));
        const pick = K.itemPicker({
            item: r.item,
            onPick: (item) => pickItem(r, item, false),
            onClear: () => { r.item = null; r.units = []; r.unitId = ''; r.price = ''; r.refPrice = null; syncRowControls(r); refresh(); },
            onHint: (t) => pick.setHint(t),
        });
        const err = UI.el('div', { class: 'tx2-rowerr', 'data-testid': 'in-rowerr' });
        const unit = UI.el('select', { class: 'tx2-sel', 'data-testid': 'in-unit' });
        unit.addEventListener('change', () => { r.unitId = unit.value; applyUnitPrice(r); syncRowControls(r); refresh(); });
        const qty = K.numInput({ value: r.qty, onValue: (n) => { r.qty = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-qty' });
        const price = K.numInput({ value: r.price, prefix: 'Rp', onValue: (n) => { r.price = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-price' });
        const refHint = UI.el('div', { class: 'tx2-refhint', 'data-testid': 'in-refhint' });
        const disc = K.numInput({ value: r.discValue, onValue: (n) => { r.discValue = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-disc' });
        const discMode = K.select([['PERCENT', '%'], ['AMOUNT', 'Rp']], r.discMode, (v) => { r.discMode = v; refresh(); }, { testid: 'in-disc-mode', cls: 'tx2-mini' });
        const total = UI.el('td', { class: 'r tx2-total', 'data-testid': 'in-rowtotal' }, 'Rp 0');
        const dup = UI.el('button', { type: 'button', class: 'tx2-iconbtn', title: 'Duplikat baris', 'data-testid': 'in-dup' }, '⧉');
        const del = UI.el('button', { type: 'button', class: 'tx2-iconbtn danger', title: 'Hapus baris', 'data-testid': 'in-del' }, '🗑');
        dup.addEventListener('click', () => {
            const c = Object.assign(blankRow(), { item: r.item, units: r.units, unitId: r.unitId, qty: r.qty, price: r.price, refPrice: r.refPrice, discMode: r.discMode, discValue: r.discValue });
            S.rows.splice(S.rows.indexOf(r) + 1, 0, c);
            buildRows();
        });
        del.addEventListener('click', () => {
            S.rows.splice(S.rows.indexOf(r), 1);
            if (S.rows.length === 0 || rowFilled(S.rows[S.rows.length - 1])) S.rows.push(blankRow());
            buildRows();
        });
        tr.appendChild(no);
        tr.appendChild(UI.el('td', {}, [pick, err]));
        tr.appendChild(UI.el('td', {}, [unit]));
        tr.appendChild(UI.el('td', {}, [qty]));
        tr.appendChild(UI.el('td', {}, [price, refHint]));
        tr.appendChild(UI.el('td', {}, [UI.el('div', { class: 'tx2-inline' }, [disc, discMode])]));
        tr.appendChild(total);
        tr.appendChild(UI.el('td', { class: 'c' }, [UI.el('div', { class: 'tx2-inline c' }, [dup, del])]));
        r.el = { tr, no, pick, err, unit, qty, price, refHint, disc, discMode, total };
        syncRowControls(r);
        return tr;
    }

    function syncRowControls(r) {
        const e = r.el;
        if (!e) return;
        e.unit.innerHTML = '';
        if (!r.item) e.unit.appendChild(UI.el('option', { value: '' }, 'Pilih'));
        r.units.forEach((u) => e.unit.appendChild(UI.el('option', { value: String(u.id) }, u.code)));
        e.unit.value = r.unitId;
        if (!r.item) e.unit.setAttribute('disabled', 'disabled'); else e.unit.removeAttribute('disabled');
        e.price.setValue(r.price);
    }

    // ---------------------------------------------------------------- live refresh (cells only — never rebuilds inputs)
    function refresh() {
        const c = calc();
        if (!ui.tbody) return;
        c.rows.forEach((x, i) => {
            const e = x.r.el;
            if (!e) return;
            e.no.textContent = String(i + 1);
            e.total.textContent = x.filled ? K.money(x.total) : 'Rp 0';
            e.tr.classList.toggle('filled', x.filled);
            e.tr.classList.toggle('bad', x.errors.length > 0);
            const showErr = x.filled && x.errors.length && (x.r.item || K.nz(x.r.qty) > 0 || K.nz(x.r.price) > 0);
            e.err.textContent = showErr ? x.errors[0] : '';
            const ref = x.r.refPrice;
            const priceNow = x.r.price === '' ? null : Number(x.r.price);
            if (x.r.item && ref === null) e.refHint.textContent = 'Harga default belum tersedia.';
            else if (x.r.item && priceNow !== null && Math.abs(priceNow - ref) > 0.0049) e.refHint.textContent = `Ref. ${K.money(ref)}`;
            else e.refHint.textContent = '';
            e.refHint.className = `tx2-refhint${x.r.item && ref !== null && priceNow !== null && Math.abs(priceNow - ref) > 0.0049 ? ' diff' : ''}`;
        });
        ui.count.textContent = `${c.filled.length} item`;
        if (ui.sum) {
            ui.sum.subtotal.textContent = K.money(c.subtotal);
            ui.sum.invLabel.textContent = `Diskon Invoice (${S.invMode === 'PERCENT' ? `${K.fmtNum(K.nz(S.invValue), 2)}%` : 'Rp'})`;
            ui.sum.inv.textContent = c.invDisc > 0 ? `- ${K.money(c.invDisc)}` : K.money(0);
            const diff = K.round4(c.ppn - c.ppnCalc);
            ui.sum.ppnLabel.textContent = c.adjusted
                ? `PPN disesuaikan sesuai invoice supplier · hitungan otomatis ${K.money(c.ppnCalc)} (${K.fmtNum(Number.isNaN(ppnRate()) ? 0 : ppnRate(), 2)}% × ${K.money(c.dppAfter)}) · selisih ${diff > 0 ? '+' : (diff < 0 ? '-' : '')}${K.money(Math.abs(diff))}`
                : `PPN dihitung sekali dari total: ${K.fmtNum(Number.isNaN(ppnRate()) ? 0 : ppnRate(), 2)}% × ${K.money(c.dppAfter)} (subtotal setelah diskon) · ketik nominal untuk menyesuaikan dengan invoice supplier`;
            ui.sum.ppnLabel.style.color = c.adjusted ? '#b45309' : '';
            ui.sum.ppn.setValue(c.ppn);
            ui.sum.ppnReset.style.display = c.adjusted ? '' : 'none';
            ui.sum.freight.textContent = K.money(Math.max(0, c.freight));
            ui.sum.grand.textContent = K.money(c.grand);
        }
        if (ui.bar) {
            const ok = c.problems.length === 0;
            ui.bar.status.className = `tx2-status ${ok ? 'ok' : 'bad'}`;
            ui.bar.statusTitle.textContent = ok ? 'Data siap disimpan' : `${c.problems.length} hal perlu diperbaiki`;
            ui.bar.statusSub.textContent = ok ? (c.duplicate ? 'Perhatian: barang & satuan yang sama muncul lebih dari satu baris.' : 'Tidak ada error pada data transaksi.') : c.problems[0];
            ui.bar.items.textContent = String(c.filled.length);
            ui.bar.grand.textContent = K.money(c.grand);
            ui.bar.next.disabled = !ok;
        }
        S._calc = c;
        K.updateBars();
        return c;
    }

    // ---------------------------------------------------------------- invoice discount / shipping / summary
    function buildBottom() {
        const invVal = K.numInput({ value: S.invValue, onValue: (n) => { S.invValue = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-inv-value', suffix: S.invMode === 'PERCENT' ? '%' : null, prefix: S.invMode === 'AMOUNT' ? 'Rp' : null });
        const seg = K.segmented([['PERCENT', '% Persentase'], ['AMOUNT', 'Rp Nominal']], S.invMode, (v) => {
            S.invMode = v;
            const wrap = invVal;
            wrap.querySelectorAll('.tx2-pre,.tx2-suf').forEach((n) => n.remove());
            if (v === 'AMOUNT') wrap.insertBefore(UI.el('span', { class: 'tx2-pre' }, 'Rp'), wrap.input); else wrap.appendChild(UI.el('span', { class: 'tx2-suf' }, '%'));
            refresh();
        }, 'in-inv-mode');
        const freight = K.numInput({ value: S.freight, prefix: 'Rp', onValue: (n) => { S.freight = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-freight' });
        const cap = UI.el('input', { type: 'checkbox', 'data-testid': 'in-freight-cap', id: 'in-freight-cap' });
        cap.checked = S.freightCap;
        cap.addEventListener('change', () => { S.freightCap = cap.checked; });
        const treat = K.select([['CREDITABLE', 'Ya, dapat dikreditkan (tidak masuk HPP)'], ['NON_CREDITABLE', 'Tidak (PPN masuk HPP)'], ['PARTIALLY_CREDITABLE', 'Sebagian dikreditkan']], S.ppnTreatment, (v) => { S.ppnTreatment = v; pctWrap.style.display = v === 'PARTIALLY_CREDITABLE' ? '' : 'none'; }, { testid: 'in-ppn-treatment' });
        const pct = K.numInput({ value: S.ppnPct, suffix: '%', onValue: (n) => { S.ppnPct = Number.isNaN(n) ? '' : n; }, testid: 'in-ppn-pct' });
        const pctWrap = UI.el('div', { class: 'tx2-field' }, [UI.el('label', {}, '% dikreditkan'), pct]);
        pctWrap.style.display = S.ppnTreatment === 'PARTIALLY_CREDITABLE' ? '' : 'none';

        const ppnCustom = K.numInput({ value: S.ppnCustom, suffix: '%', onValue: (n) => { S.ppnCustom = Number.isNaN(n) ? '' : n; S.ppnAdj = ''; refresh(); }, testid: 'in-ppn-custom', cls: 'tx2-ppncustom' });
        const ppnSel = K.select([['0', '0% (tanpa PPN)'], ['11', '11%'], ['custom', 'Custom']], S.ppnMode, (v) => { S.ppnMode = v; S.ppnAdj = ''; ppnCustom.style.display = v === 'custom' ? '' : 'none'; refresh(); }, { testid: 'in-ppn' });
        ppnCustom.style.display = S.ppnMode === 'custom' ? '' : 'none';
        ppnSel.style.width = '112px';
        ppnCustom.style.width = '84px';
        ui.sum = {
            subtotal: UI.el('span', { 'data-testid': 'in-sum-subtotal' }, 'Rp 0'), invLabel: UI.el('span', {}, 'Diskon Invoice'), inv: UI.el('span', { 'data-testid': 'in-sum-inv' }, 'Rp 0'),
            ppnLabel: UI.el('span', {}, ''),
            ppn: K.numInput({ value: '', prefix: 'Rp', onValue: (n) => { S.ppnAdj = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'in-sum-ppn', cls: 'tx2-ppnamt' }),
            ppnReset: UI.el('button', { type: 'button', class: 'btn btn-sm', 'data-testid': 'in-ppn-reset', title: 'Kembali ke hitungan otomatis', style: 'display:none;margin-top:6px;padding:2px 10px;background:transparent;color:#93c5fd;border:1px solid currentColor;border-radius:6px;font-size:12px;cursor:pointer' }, '↺ Otomatis'),
            freight: UI.el('span', { 'data-testid': 'in-sum-freight' }, 'Rp 0'), grand: UI.el('span', { 'data-testid': 'in-sum-grand' }, 'Rp 0'),
        };
        ui.sum.ppn.style.width = '150px';
        ui.sum.ppnReset.addEventListener('click', () => { S.ppnAdj = ''; refresh(); });
        ui.sum.ppn.input.addEventListener('blur', () => refresh());   // an emptied field falls back to the automatic amount
        return UI.el('div', { class: 'tx2-card tx2-bottom' }, [
            UI.el('div', { class: 'tx2-bcol' }, [
                UI.el('div', { class: 'tx2-btitle' }, ['Diskon Invoice ', UI.el('span', { class: 'tx2-opt' }, '(Opsional)')]),
                UI.el('div', { class: 'tx2-inline wrap' }, [seg, invVal]),
                UI.el('div', { class: 'tx2-hintline' }, 'Diskon diterapkan ke subtotal setelah diskon per item, sebelum PPN.'),
            ]),
            UI.el('div', { class: 'tx2-bcol' }, [
                UI.el('div', { class: 'tx2-btitle' }, ['Biaya Kirim ', UI.el('span', { class: 'tx2-opt' }, '(Opsional)')]),
                freight,
                UI.el('label', { class: 'tx2-check', for: 'in-freight-cap' }, [cap, ' Biaya kirim masuk HPP (landed cost, dialokasikan proporsional)']),
                UI.el('div', { class: 'tx2-btitle sm' }, 'PPN dapat dikreditkan?'),
                UI.el('div', { class: 'tx2-inline wrap' }, [treat, pctWrap]),
            ]),
            UI.el('div', { class: 'tx2-sumbox', 'data-testid': 'in-summary' }, [
                UI.el('div', { class: 'tx2-sumrow' }, [UI.el('span', {}, 'Subtotal'), ui.sum.subtotal]),
                UI.el('div', { class: 'tx2-sumrow' }, [ui.sum.invLabel, ui.sum.inv]),
                UI.el('div', { class: 'tx2-sumrow tx2-ppnrow', style: 'align-items:center' }, [UI.el('span', { class: 'tx2-ppnctl', style: 'display:inline-flex;align-items:center;gap:8px' }, ['PPN ', ppnSel, ppnCustom]), ui.sum.ppn]),
                UI.el('div', { class: 'tx2-hintline tx2-ppnnote' }, [ui.sum.ppnLabel, UI.el('div', {}, [ui.sum.ppnReset])]),
                UI.el('div', { class: 'tx2-sumrow' }, [UI.el('span', {}, 'Biaya Kirim'), ui.sum.freight]),
                UI.el('div', { class: 'tx2-grand' }, [UI.el('span', {}, 'Grand Total'), ui.sum.grand]),
            ]),
        ]);
    }

    function buildBar() {
        const reset = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'in-reset' }, '↺ Reset');
        reset.addEventListener('click', async () => {
            const ok = await Modal.confirm({ title: 'Reset transaksi?', message: 'Semua isian pada form ini akan dikosongkan.', confirmLabel: 'Reset', danger: true });
            if (ok) { S = fresh(); draw(); }
        });
        const next = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-next', 'data-testid': 'in-next' }, 'Lanjut ke Review →');
        next.addEventListener('click', goReview);
        ui.bar = {
            status: UI.el('div', { class: 'tx2-status ok', 'data-testid': 'in-status' }), statusTitle: UI.el('div', { class: 'tx2-status-t', 'data-testid': 'in-status-title' }), statusSub: UI.el('div', { class: 'tx2-status-s', 'data-testid': 'in-status-sub' }),
            items: UI.el('div', { class: 'tx2-bar-v' }, '0'), grand: UI.el('div', { class: 'tx2-bar-g' }, 'Rp 0'), next,
        };
        ui.bar.status.appendChild(UI.el('div', { class: 'tx2-status-ico' }, '✓'));
        ui.bar.status.appendChild(UI.el('div', {}, [ui.bar.statusTitle, ui.bar.statusSub]));
        return K.stickyBar(UI.el('div', { class: 'tx2-bar' }, [
            reset, ui.bar.status,
            UI.el('div', { class: 'tx2-bar-kv' }, [UI.el('div', { class: 'tx2-bar-k' }, 'Total Item'), ui.bar.items]),
            UI.el('div', { class: 'tx2-bar-kv' }, [UI.el('div', { class: 'tx2-bar-k' }, 'Grand Total'), ui.bar.grand]),
            next,
        ]));
    }

    // ---------------------------------------------------------------- payload / review / post
    function payload() {
        const lines = S.rows.filter(rowFilled).map((r) => ({
            item_id: r.item ? r.item.id : 0, input_unit_id: Number(r.unitId) || 0, input_qty: K.nz(r.qty), unit_price_input: r.price === '' ? -1 : K.nz(r.price),
            discount_type: K.nz(r.discValue) > 0 ? r.discMode : 'NONE', discount_value: K.nz(r.discValue),
        }));
        return {
            warehouse_id: Number(S.warehouseId), supplier_id: S.supplierId ? Number(S.supplierId) : null, reference_no: S.reference || null, transaction_date: S.date, notes: S.notes || null,
            invoice_discount_type: K.nz(S.invValue) > 0 ? S.invMode : 'NONE', invoice_discount_value: K.nz(S.invValue),
            freight_amount: Math.max(0, K.nz(S.freight)), freight_capitalize: !!S.freightCap,
            ppn_rate: ppnRate() || 0, ...(calc().adjusted ? { ppn_amount: calc().ppn } : {}), ppn_treatment: S.ppnTreatment, ppn_creditable_pct: K.nz(S.ppnPct), lines,
        };
    }

    async function goReview() {
        const c = refresh();
        if (c.problems.length) { UI.toast(c.problems[0], 'error'); return; }
        S.phase = 'review';
        S.quote = null;
        draw();
    }

    function drawReview() {
        const host = ctx.host;
        const wrap = UI.el('div', { class: 'tx2-card tx2-review', 'data-testid': 'in-review' }, [UI.el('div', { class: 'alert alert-info' }, 'Memuat review...')]);
        host.appendChild(wrap);
        (async () => {
            let q;
            try { q = await K.api('POST', '/stock-in/quote', payload()); } catch (err) { wrap.innerHTML = ''; wrap.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'in-review-error' }, `Gagal memuat review: ${err.message}`)); wrap.appendChild(backBtn()); return; }
            S.quote = q;
            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { id: 'in-review-alert', 'data-testid': 'in-review-alert' }));
            if (!q.valid) {
                wrap.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'in-review-invalid' }, ['Data belum valid:', UI.el('ul', {}, q.errors.map((e) => UI.el('li', {}, e)))]));
                wrap.appendChild(backBtn());
                return;
            }
            const wh = Master.warehouseById(S.warehouseId);
            const sup = S.supplierId ? Master.supplierById(S.supplierId) : null;
            const kv = (k, v) => UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, k), UI.el('div', { class: 'tx2-kv-v' }, v)]);
            wrap.appendChild(UI.el('div', { class: 'tx2-reviewhead' }, [kv('Gudang', wh ? wh.name : '-'), kv('Supplier', sup ? sup.name : '-'), kv('Referensi', S.reference || '-'), kv('Tanggal', S.date), ...(S.notes ? [kv('Catatan', S.notes)] : [])]));
            const unitCode = (r) => { const u = r.units.find((x) => String(x.id) === String(r.unitId)); return u ? u.code : ''; };
            const filledRows = S.rows.filter(rowFilled);
            const body = q.lines.map((l, i) => {
                const r = filledRows[i];
                const disc = l.discount_type === 'PERCENT' ? `${K.fmtNum(l.discount_value, 2)}% (${K.money(l.item_discount)})` : (l.item_discount > 0 ? K.money(l.item_discount) : '-');
                return UI.el('tr', { 'data-testid': 'in-review-row' }, [
                    UI.el('td', { class: 'c' }, String(l.line_no)), UI.el('td', {}, l.item_name || '-'), UI.el('td', { class: 'r' }, `${K.fmtNum(l.input_qty)} ${unitCode(r)}`),
                    UI.el('td', { class: 'r' }, K.money(l.unit_price_input)), UI.el('td', { class: 'r' }, disc), UI.el('td', { class: 'r strong' }, K.money(l.total)),
                ]);
            });
            wrap.appendChild(UI.el('div', { class: 'tx2-tablewrap' }, [UI.el('table', { class: 'tx2-table tx2-reviewtable' }, [
                UI.el('thead', {}, [UI.el('tr', {}, [['No', 'c'], ['Nama Barang', ''], ['Qty + Satuan', 'r'], ['Harga Beli', 'r'], ['Diskon Item', 'r'], ['Total', 'r']].map(([h, c]) => UI.el('th', { class: c }, h)))]),
                UI.el('tbody', {}, body),
            ])]));
            const t = q.totals;
            const row = (k, v, strong) => UI.el('div', { class: `tx2-sumrow${strong ? ' strong' : ''}` }, [UI.el('span', {}, k), UI.el('span', { 'data-testid': strong ? 'in-review-grand' : null }, v)]);
            wrap.appendChild(UI.el('div', { class: 'tx2-reviewsum' }, [
                UI.el('div', { class: 'tx2-hintline' }, `Nilai masuk persediaan (HPP): ${K.money(t.inventory_cost_total)} · PPN ${t.ppn_treatment === 'CREDITABLE' ? 'dikreditkan (tidak masuk HPP)' : (t.ppn_treatment === 'NON_CREDITABLE' ? 'masuk HPP' : 'sebagian masuk HPP')} · Biaya kirim ${t.freight_treatment === 'CAPITALIZE' ? 'masuk HPP' : 'tidak masuk HPP'}`),
                UI.el('div', { class: 'tx2-sumbox' }, [
                    row('Subtotal', K.money(t.subtotal)), row(`Diskon Invoice${t.invoice_discount_type === 'PERCENT' ? ` (${K.fmtNum(t.invoice_discount_value, 2)}%)` : ''}`, t.invoice_discount > 0 ? `- ${K.money(t.invoice_discount)}` : K.money(0)),
                    row(t.ppn_adjusted ? `PPN (disesuaikan sesuai invoice supplier; hitungan ${K.fmtNum(t.ppn_rate || 0, 2)}% = ${K.money(t.ppn_calculated)})` : `PPN (${K.fmtNum(t.ppn_rate || 0, 2)}% × ${K.money(t.dpp_after_invoice_discount)})`, K.money(t.ppn_total)),
                    row('Biaya Kirim', K.money(t.freight_amount)), UI.el('div', { class: 'tx2-grand' }, [UI.el('span', {}, 'Grand Total'), UI.el('span', { 'data-testid': 'in-review-grand' }, K.money(t.grand_total))]),
                ]),
            ]));
            const save = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-next', id: 'in-post-btn', 'data-testid': 'in-post' }, '✓ Simpan Transaksi Masuk');
            save.addEventListener('click', () => post());
            wrap.appendChild(UI.el('div', { class: 'tx2-actions' }, [backBtn(), save]));
        })();
    }

    function backBtn() {
        const b = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'in-back' }, '‹ Kembali ke Barang');
        b.addEventListener('click', () => { S.phase = 'edit'; draw(); });
        return b;
    }

    async function post(extra = {}) {
        if (!S.uuid) S.uuid = K.uuid();
        const btn = document.getElementById('in-post-btn');
        if (btn) btn.disabled = true;
        const box = document.getElementById('in-review-alert');
        if (box) box.innerHTML = '';
        const show = (type, msg) => { if (box) { box.innerHTML = ''; box.appendChild(UI.el('div', { class: `alert alert-${type}`, 'data-testid': 'in-post-alert' }, msg)); } };
        try {
            const res = await K.api('POST', '/stock-in', Object.assign(payload(), { transaction_uuid: S.uuid }, extra));
            S.result = res;
            S.uuid = null;
            S.phase = 'done';
            K.clearUnitCache(); // reference prices just changed
            draw();
        } catch (err) {
            if (err.code === 'NETWORK_ERROR') { show('error', 'Koneksi terputus. Transaksi BELUM tentu tersimpan — kirim ulang aman (tidak akan tercatat dobel).'); }
            else if (err.code === 'PRICE_ANOMALY') {
                const ok = await Modal.confirm({ title: 'Anomali Harga Terdeteksi', message: `${err.message}\n\nLanjutkan menyimpan dengan harga ini?`, confirmLabel: 'Lanjutkan', danger: true });
                if (ok) { await post({ ...extra, anomaly_approved_by: Auth.user().id }); return; }
                show('warning', 'Transaksi dibatalkan karena anomali harga tidak disetujui.');
            } else show('error', err.errors && err.errors.length ? err.errors.join('; ') : err.message);
            if (btn) btn.disabled = false;
        }
    }

    function drawDone() {
        const r = S.result || {};
        const t = r.totals || {};
        const wrap = UI.el('div', { class: 'tx2-card tx2-done', 'data-testid': 'in-done' }, [
            UI.el('div', { class: 'alert alert-success' }, `Transaksi Masuk berhasil disimpan (${(r.transactions || []).length} barang).`),
            UI.el('div', { class: 'tx2-reviewhead' }, [
                UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, 'Grand Total'), UI.el('div', { class: 'tx2-kv-v', 'data-testid': 'in-done-grand' }, K.money(t.grand_total))]),
                UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, 'Nilai masuk persediaan'), UI.el('div', { class: 'tx2-kv-v' }, K.money(t.inventory_cost_total))]),
                UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, 'ID Transaksi'), UI.el('div', { class: 'tx2-kv-v', 'data-testid': 'in-done-ids' }, (r.transactions || []).map((x) => `#${x.transaction_id}`).join(', '))]),
            ]),
        ]);
        const again = UI.el('button', { type: 'button', class: 'btn btn-primary', 'data-testid': 'in-new' }, '＋ Transaksi Masuk Baru');
        again.addEventListener('click', () => { S = fresh(); draw(); });
        const hist = UI.el('button', { type: 'button', class: 'btn btn-secondary' }, 'Lihat History Transaksi');
        hist.addEventListener('click', () => { if (window.InvNav) window.InvNav.goToTab('history-transaksi'); });
        wrap.appendChild(UI.el('div', { class: 'tx2-actions' }, [again, hist]));
        ctx.host.appendChild(wrap);
    }

    return { mount, _state: () => S, _calc: calc };
})();
