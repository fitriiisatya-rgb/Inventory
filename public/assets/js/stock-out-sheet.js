/**
 * STOCK OUT V2 — table-first issue to a bakery destination that produces a
 * Delivery Order and an Invoice (see transactions.js for the shell,
 * services/StockOutService.php for the server-side truth).
 *
 *  - Harga Modal   = reference purchase price of the selected unit (ItemPriceService,
 *                    delivered with GET /items/{id}/units) — NOT the FIFO cost.
 *  - Markup        = per CATEGORY, per transaction: % (Harga Modal x (1+%)) or Rp
 *                    (Harga Modal + Rp, per selected unit). Never per item, never assumed:
 *                    a category present in the sheet must have a value (0 is allowed).
 *  - Harga Jual    = Harga Modal + markup ;  row Total = Qty x Harga Jual
 *  - Grand Total   = SUM(row Total) + Biaya Kirim   (no PPN, no discount)
 *  - Stok Tersedia = base stock / the selected unit's approved conversion factor
 *                    (display only — stock figures themselves are never altered).
 * Inventory is consumed by the real FIFO engine on the server; the selling price never
 * reaches it. The Invoice document never contains cost or markup (server-rendered).
 */
const StockOutSheet = (() => {
    const K = TxKit;
    const today = () => new Date().toISOString().slice(0, 10);
    let rowSeq = 0;
    let S = fresh();
    const TONES = ['blue', 'purple', 'orange', 'green', 'pink'];

    function isStockUser() { const u = Auth.user(); return !!(u && u.role_code === 'STOCK' && u.warehouse_id); }
    function blankRow() { return { id: ++rowSeq, item: null, units: [], unitId: '', qty: '', el: null }; }
    function fresh() {
        const u = Auth.user();
        return {
            phase: 'edit', warehouseId: isStockUser() ? String(u.warehouse_id) : '', bakeryId: '', reference: '', date: today(), notes: '', shipping: '',
            rows: [blankRow(), blankRow(), blankRow(), blankRow(), blankRow()], markups: {}, stock: {}, uuid: null, quote: null, result: null,
        };
    }

    const catKey = (item) => String(item && item.category_id ? item.category_id : 0);
    const catName = (key) => (key === '0' ? 'Tanpa Kategori' : ((Master.categoryById(Number(key)) || {}).name || `Kategori ${key}`));
    const toneFor = (name, idx) => ({ packaging: 'blue', aksesoris: 'purple', bahan: 'orange' }[String(name).toLowerCase()] || TONES[idx % TONES.length]);
    const rowFilled = (r) => !!(r.item || K.nz(r.qty) > 0);
    const unitOf = (r) => r.units.find((u) => String(u.id) === String(r.unitId)) || null;
    const factorOf = (r) => { const u = unitOf(r); return u ? Number(u.conversion_to_base) : null; };

    // ---------------------------------------------------------------- calculation (live preview)
    function categoriesInUse() {
        const keys = [];
        S.rows.forEach((r) => { if (r.item) { const k = catKey(r.item); if (!keys.includes(k)) keys.push(k); } });
        return keys;
    }
    const markupOk = (m) => !!(m && m.value !== '' && m.value !== null && !Number.isNaN(Number(m.value)) && Number(m.value) >= 0 && (m.mode !== 'PERCENT' || Number(m.value) <= 10000));
    const stockKey = (item) => `${item.id}:${S.warehouseId}`;
    const fmtQty = (v) => K.fmtNum(v, 4);

    function calc() {
        // aggregated need per item (the same item may sit on several rows)
        const need = {};
        S.rows.forEach((r) => { const f = factorOf(r); if (r.item && f && K.nz(r.qty) > 0) need[r.item.id] = (need[r.item.id] || 0) + K.nz(r.qty) * f; });
        let subtotal = 0;
        let qtySum = 0;
        const units = new Set();
        const rows = S.rows.map((r) => {
            const out = { r, filled: rowFilled(r), errors: [], ref: null, markup: null, sell: null, total: 0, stockUnit: null, stockBase: null };
            if (!out.filled) return out;
            const qty = K.nz(r.qty);
            if (!r.item) { out.errors.push('Pilih barang dari hasil pencarian'); return out; }
            const u = unitOf(r);
            if (!u) out.errors.push('Pilih satuan');
            if (!(qty > 0)) out.errors.push('Qty harus lebih dari 0');
            const st = S.stock[stockKey(r.item)];
            if (st !== undefined && u) {
                out.stockBase = st.qty;
                out.stockUnit = st.qty / Number(u.conversion_to_base);
                if (st.review) out.errors.push('Barang berstatus MIGRATION_NEGATIVE_REVIEW — selesaikan lewat Stock Opname / Adjustment');
                else if (need[r.item.id] > st.qty + 0.0000005) out.errors.push(`Stok tidak cukup: diminta ${fmtQty(qty)} ${u.code}, tersedia ${fmtQty(out.stockUnit)} ${u.code}`);
            }
            if (u) {
                out.ref = u.reference_price !== null && u.reference_price !== undefined ? Number(u.reference_price) : null;
                if (out.ref === null) out.errors.push('Belum ada Harga Modal (harga beli referensi) — catat pembelian lewat Stock IN dahulu');
                const m = S.markups[catKey(r.item)];
                if (!markupOk(m)) { if (out.ref !== null) out.errors.push(`Markup kategori ${catName(catKey(r.item))} belum diisi (isi 0 jika tanpa markup)`); }
                else if (out.ref !== null) {
                    out.markup = m;
                    out.sell = K.round4(m.mode === 'PERCENT' ? out.ref * (1 + Number(m.value) / 100) : out.ref + Number(m.value));
                    out.total = K.round4(Math.max(0, qty) * out.sell);
                    subtotal += out.total;
                }
                units.add(u.code);
            }
            qtySum += Math.max(0, qty);
            return out;
        });
        const filled = rows.filter((x) => x.filled);
        const problems = [];
        if (!S.warehouseId) problems.push('Gudang Asal wajib dipilih');
        if (!S.bakeryId) problems.push('Bakery Tujuan wajib dipilih');
        if (filled.length === 0) problems.push('Tambahkan minimal satu barang');
        if (K.nz(S.shipping) < 0) problems.push('Biaya Kirim tidak boleh negatif');
        filled.forEach((x) => x.errors.forEach((e) => problems.push(`Baris ${S.rows.indexOf(x.r) + 1}: ${e}`)));
        const shipping = Math.max(0, K.nz(S.shipping));
        subtotal = K.round4(subtotal);
        return { rows, filled, subtotal, shipping, grand: K.round4(subtotal + shipping), qtySum, qtyUnit: units.size === 1 ? Array.from(units)[0] : null, problems };
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
        const step = S.phase === 'done' ? 4 : (S.phase === 'review' ? 3 : (S.warehouseId && S.bakeryId && S.date ? 2 : 1));
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
        ctx.host.appendChild(buildMarkupCard());
        ctx.host.appendChild(buildItemsCard());
        ctx.host.appendChild(buildSummary());
        ctx.host.appendChild(buildBar());
        renderMarkups();
        refresh();
        S.rows.forEach((r) => { if (r.item) ensureStock(r.item); });
    }

    function buildHeader() {
        const wh = K.select([['', 'Pilih gudang']].concat(Master.warehouses().map((w) => [w.id, w.name])), S.warehouseId, (v) => { S.warehouseId = v; S.stock = {}; S.rows.forEach((r) => { if (r.item) ensureStock(r.item); }); refresh(); setStep(); }, { disabled: isStockUser(), testid: 'out-warehouse' });
        const bk = K.select([['', 'Pilih bakery tujuan']].concat(Master.bakeryDestinations().filter((b) => b.is_active).map((b) => [b.id, b.name])), S.bakeryId, (v) => { S.bakeryId = v; refresh(); setStep(); }, { testid: 'out-bakery' });
        const ref = UI.el('input', { type: 'text', class: 'tx2-in', placeholder: 'Opsional', 'data-testid': 'out-reference', maxlength: '100' });
        ref.value = S.reference;
        ref.addEventListener('input', () => { S.reference = ref.value; });
        const date = UI.el('input', { type: 'date', class: 'tx2-in', 'data-testid': 'out-date' });
        date.value = S.date;
        date.addEventListener('change', () => { S.date = date.value; refresh(); setStep(); });
        const notes = UI.el('input', { type: 'text', class: 'tx2-in', placeholder: 'Catatan opsional', 'data-testid': 'out-notes', maxlength: '255' });
        notes.value = S.notes;
        notes.addEventListener('input', () => { S.notes = notes.value; });
        const hist = UI.el('button', { type: 'button', class: 'btn btn-secondary tx2-histbtn', 'data-testid': 'out-history' }, '🧾 Riwayat');
        hist.addEventListener('click', openHistory);
        return UI.el('div', { class: 'tx2-card tx2-head tx2-head-out', 'data-testid': 'out-header' }, [
            K.field('Gudang Asal', wh, { required: true }), K.field('Bakery Tujuan', bk, { required: true }), K.field('Referensi (Opsional)', ref), K.field('Tanggal Transaksi', date, { required: true }), K.field('Catatan', notes), hist,
        ]);
    }

    // ---------------------------------------------------------------- markup per category
    function buildMarkupCard() {
        ui.markupHost = UI.el('div', { class: 'tx2-markups', 'data-testid': 'out-markups' });
        return UI.el('div', { class: 'tx2-card tx2-markupcard' }, [
            UI.el('div', { class: 'tx2-cardhead' }, [UI.el('div', {}, [UI.el('div', { class: 'tx2-cardtitle' }, ['Markup Kategori ', UI.el('span', { class: 'tx2-opt' }, '(Untuk perhitungan harga jual)')]), UI.el('div', { class: 'tx2-hintline' }, 'Markup otomatis diterapkan pada semua barang di masing-masing kategori.')])]),
            ui.markupHost,
        ]);
    }

    function renderMarkups() {
        if (!ui.markupHost) return;
        const keys = categoriesInUse();
        ui.markupHost.innerHTML = '';
        if (!keys.length) { ui.markupHost.appendChild(UI.el('div', { class: 'tx2-markup-empty', 'data-testid': 'out-markup-empty' }, 'Pilih barang terlebih dahulu — markup muncul untuk kategori barang yang dipilih.')); return; }
        keys.forEach((k, idx) => {
            if (!S.markups[k]) S.markups[k] = { mode: 'PERCENT', value: '', source: null };
            const m = S.markups[k];
            const name = catName(k);
            const tone = toneFor(name, idx);
            const input = K.numInput({ value: m.value, placeholder: '0', suffix: m.mode === 'PERCENT' ? '%' : null, prefix: m.mode === 'AMOUNT' ? 'Rp' : null, onValue: (n) => { m.value = Number.isNaN(n) ? '' : n; m.source = null; refresh(); }, testid: `out-markup-value-${k}` });
            const seg = K.segmented([['PERCENT', '%'], ['AMOUNT', 'Nominal (Rp)']], m.mode, (v) => {
                m.mode = v;
                input.querySelectorAll('.tx2-pre,.tx2-suf').forEach((n) => n.remove());
                if (v === 'AMOUNT') input.insertBefore(UI.el('span', { class: 'tx2-pre' }, 'Rp'), input.input); else input.appendChild(UI.el('span', { class: 'tx2-suf' }, '%'));
                refresh();
            }, `out-markup-mode-${k}`);
            ui.markupHost.appendChild(UI.el('div', { class: `tx2-mk tone-${tone}`, 'data-testid': `out-markup-${k}`, 'data-category': name }, [
                UI.el('div', { class: 'tx2-mk-head' }, [UI.el('span', { class: 'tx2-mk-ico' }, '▣'), UI.el('span', { class: 'tx2-mk-name' }, name), m.source ? UI.el('span', { class: 'tx2-mk-src', title: 'Diisi dari kebijakan harga Distribusi (dapat diubah)' }, 'kebijakan') : null]),
                seg, input,
            ]));
        });
        fillDefaults(keys);
    }

    let defaultsAsked = new Set();
    async function fillDefaults(keys) {
        const ask = keys.filter((k) => !defaultsAsked.has(k) && !markupOk(S.markups[k]));
        if (!ask.length) return;
        ask.forEach((k) => defaultsAsked.add(k));
        try {
            const d = await K.api('GET', `/stock-out/markup-defaults?category_ids=${ask.join(',')}`);
            let changed = false;
            Object.keys(d || {}).forEach((k) => {
                const m = S.markups[k];
                if (m && !markupOk(m)) { m.mode = d[k].mode; m.value = d[k].value; m.source = d[k].source; changed = true; }
            });
            if (changed && S.phase === 'edit') { renderMarkups(); refresh(); }
        } catch (err) { /* defaults are a convenience only — the operator can always type the markup */ }
    }

    // ---------------------------------------------------------------- items table
    function buildItemsCard() {
        const quick = UI.el('div', { class: 'tx2-quick' });
        const mountQuick = () => {
            quick.innerHTML = '';
            const p = K.itemPicker({ item: null, onPick: (item) => { addItem(item); mountQuick(); }, onClear: () => {}, onHint: (t) => p.setHint(t) });
            quick.appendChild(p);
        };
        mountQuick();
        const addBtn = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-add', 'data-testid': 'out-add-row' }, '+ Tambah Barang');
        addBtn.addEventListener('click', () => { const r = blankRow(); S.rows.push(r); buildRows(); r.el.pick.focus(); });
        ui.count = UI.el('span', { class: 'tx2-chip', 'data-testid': 'out-count' }, '0 item');
        ui.tbody = UI.el('tbody', { 'data-testid': 'out-rows' });
        const cols = [['No', 'c w-no'], ['Nama Barang', 'w-name'], ['Kategori', 'w-cat'], ['Stok Tersedia', 'r w-stock'], ['Qty', 'w-qty'], ['Satuan', 'w-unit'], ['Harga Modal', 'r w-money'], ['Markup Kategori', 'c w-mk'], ['Harga Jual', 'r w-money'], ['Total', 'r w-total'], ['Aksi', 'c w-act']];
        const card = UI.el('div', { class: 'tx2-card' }, [
            UI.el('div', { class: 'tx2-cardhead' }, [UI.el('div', {}, [UI.el('div', { class: 'tx2-cardtitle' }, ['Daftar Barang ', ui.count]), UI.el('div', { class: 'tx2-hintline' }, 'Tambah barang ke transaksi. Gunakan pencarian atau pilih dari daftar.')]), UI.el('div', { class: 'tx2-cardtools' }, [quick, addBtn])]),
            UI.el('div', { class: 'tx2-tablewrap' }, [UI.el('table', { class: 'tx2-table tx2-table-out', 'data-testid': 'out-table' }, [UI.el('thead', {}, [UI.el('tr', {}, cols.map(([h, c]) => UI.el('th', { class: c }, h)))]), ui.tbody])]),
        ]);
        buildRows();
        return card;
    }

    function buildRows() {
        ui.tbody.innerHTML = '';
        S.rows.forEach((r, i) => ui.tbody.appendChild(buildRow(r, i)));
        renderMarkups();
        refresh();
    }

    function addItem(item) {
        let r = S.rows.find((x) => !rowFilled(x));
        if (!r) { r = blankRow(); S.rows.push(r); }
        buildRows();
        pickItem(r, item, true);
    }

    async function ensureStock(item) {
        if (!S.warehouseId) return;
        const key = stockKey(item);
        if (S.stock[key] !== undefined) return;
        S.stock[key] = undefined;
        try {
            const cs = await InvApi.currentStock(item.id, S.warehouseId);
            S.stock[key] = { qty: Number(cs.qty_base), review: !!cs.migration_negative_review };
        } catch (err) { return; }
        if (S.phase === 'edit') refresh();
    }

    async function pickItem(r, item, redraw) {
        r.item = item;
        r.units = [];
        r.unitId = '';
        if (redraw && r.el) r.el.pick.querySelector('input').value = item.name;
        try { r.units = await K.unitsFor(item.id); } catch (err) { UI.handleApiError(err); r.item = null; refresh(); return; }
        if (r.item !== item) return;
        r.unitId = r.units[0] ? String(r.units[0].id) : '';
        ensureStock(item);
        if (S.rows[S.rows.length - 1] === r) S.rows.push(blankRow());
        buildRows();
    }

    function buildRow(r, i) {
        const tr = UI.el('tr', { 'data-testid': 'out-row' });
        const no = UI.el('td', { class: 'c' }, String(i + 1));
        const pick = K.itemPicker({
            item: r.item,
            onPick: (item) => pickItem(r, item, false),
            onClear: () => { r.item = null; r.units = []; r.unitId = ''; buildRows(); },
            onHint: (t) => pick.setHint(t),
        });
        const err = UI.el('div', { class: 'tx2-rowerr', 'data-testid': 'out-rowerr' });
        const cat = UI.el('td', {}, []);
        const stock = UI.el('td', { class: 'r tx2-stock', 'data-testid': 'out-stock' }, '—');
        const qty = K.numInput({ value: r.qty, onValue: (n) => { r.qty = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'out-qty' });
        const unit = UI.el('select', { class: 'tx2-sel', 'data-testid': 'out-unit' });
        r.units.forEach((u) => unit.appendChild(UI.el('option', { value: String(u.id) }, u.code)));
        if (!r.item) unit.appendChild(UI.el('option', { value: '' }, 'Pilih'));
        unit.value = r.unitId;
        if (!r.item) unit.setAttribute('disabled', 'disabled');
        unit.addEventListener('change', () => { r.unitId = unit.value; refresh(); });
        const modal = UI.el('td', { class: 'r', 'data-testid': 'out-modal' }, '—');
        const mk = UI.el('td', { class: 'c', 'data-testid': 'out-mk' }, '—');
        const sell = UI.el('td', { class: 'r strong', 'data-testid': 'out-sell' }, '—');
        const total = UI.el('td', { class: 'r tx2-total', 'data-testid': 'out-rowtotal' }, 'Rp 0');
        const del = UI.el('button', { type: 'button', class: 'tx2-iconbtn danger', title: 'Hapus baris', 'data-testid': 'out-del' }, '🗑');
        del.addEventListener('click', () => { S.rows.splice(S.rows.indexOf(r), 1); if (S.rows.length === 0 || rowFilled(S.rows[S.rows.length - 1])) S.rows.push(blankRow()); buildRows(); });
        tr.appendChild(no);
        tr.appendChild(UI.el('td', {}, [pick, err]));
        tr.appendChild(cat);
        tr.appendChild(stock);
        tr.appendChild(UI.el('td', {}, [qty]));
        tr.appendChild(UI.el('td', {}, [unit]));
        tr.appendChild(modal);
        tr.appendChild(mk);
        tr.appendChild(sell);
        tr.appendChild(total);
        tr.appendChild(UI.el('td', { class: 'c' }, [UI.el('div', { class: 'tx2-inline c' }, [del])]));
        r.el = { tr, no, pick, err, cat, stock, qty, unit, modal, mk, sell, total };
        return tr;
    }

    // ---------------------------------------------------------------- live refresh (cells only)
    function refresh() {
        const c = calc();
        if (!ui.tbody) return c;
        const keys = categoriesInUse();
        c.rows.forEach((x, i) => {
            const e = x.r.el;
            if (!e) return;
            e.no.textContent = String(i + 1);
            const key = x.r.item ? catKey(x.r.item) : null;
            e.cat.innerHTML = '';
            if (key !== null) {
                const nm = catName(key);
                e.cat.appendChild(UI.el('span', { class: `tx2-badge tone-${toneFor(nm, keys.indexOf(key))}`, 'data-testid': 'out-cat' }, nm));
            }
            const u = unitOf(x.r);
            e.stock.textContent = x.stockUnit !== null && u ? `${fmtQty(x.stockUnit)} ${u.code}` : (x.r.item ? (S.stock[stockKey(x.r.item)] === undefined ? '…' : '—') : '—');
            e.stock.classList.toggle('low', x.stockUnit !== null && K.nz(x.r.qty) > x.stockUnit + 0.0000005);
            e.modal.textContent = x.ref !== null ? K.money(x.ref) : '—';
            e.mk.textContent = x.markup ? (x.markup.mode === 'PERCENT' ? `${K.fmtNum(Number(x.markup.value), 2)}%` : K.money(Number(x.markup.value))) : '—';
            e.sell.textContent = x.sell !== null ? K.money(x.sell) : '—';
            e.total.textContent = x.filled && x.sell !== null ? K.money(x.total) : 'Rp 0';
            e.tr.classList.toggle('filled', x.filled);
            e.tr.classList.toggle('bad', x.errors.length > 0);
            e.err.textContent = x.filled && x.errors.length ? x.errors[0] : '';
        });
        ui.count.textContent = `${c.filled.length} item`;
        if (ui.sum) {
            ui.sum.items.textContent = `${c.filled.length} jenis`;
            ui.sum.qty.textContent = c.qtySum ? `${K.fmtNum(c.qtySum, 4)}${c.qtyUnit ? ` ${c.qtyUnit}` : ''}` : '0';
            ui.sum.qtyNote.textContent = c.qtyUnit || !c.qtySum ? '' : 'satuan campur';
            ui.sum.subtotal.textContent = K.money(c.subtotal);
            ui.sum.grand.textContent = K.money(c.grand);
        }
        if (ui.bar) {
            const ok = c.problems.length === 0;
            ui.bar.status.textContent = ok ? 'Data siap disimpan' : c.problems[0];
            ui.bar.status.className = `tx2-status-line ${ok ? 'ok' : 'bad'}`;
            ui.bar.next.disabled = !ok;
            ui.bar.pdo.disabled = !ok;
            ui.bar.pinv.disabled = !ok;
        }
        S._calc = c;
        K.updateBars();
        return c;
    }

    function buildSummary() {
        const shipping = K.numInput({ value: S.shipping, prefix: 'Rp', onValue: (n) => { S.shipping = Number.isNaN(n) ? '' : n; refresh(); }, testid: 'out-shipping' });
        ui.sum = {
            items: UI.el('div', { class: 'tx2-tile-v', 'data-testid': 'out-sum-items' }, '0 jenis'), qty: UI.el('div', { class: 'tx2-tile-v', 'data-testid': 'out-sum-qty' }, '0'), qtyNote: UI.el('div', { class: 'tx2-tile-s' }),
            subtotal: UI.el('div', { class: 'tx2-tile-v', 'data-testid': 'out-sum-subtotal' }, 'Rp 0'), grand: UI.el('div', { class: 'tx2-grand-v', 'data-testid': 'out-sum-grand' }, 'Rp 0'),
        };
        const tile = (label, ...v) => UI.el('div', { class: 'tx2-tile' }, [UI.el('div', { class: 'tx2-tile-k' }, label), ...v]);
        return UI.el('div', { class: 'tx2-card tx2-summary', 'data-testid': 'out-summary' }, [
            UI.el('div', { class: 'tx2-cardtitle' }, 'Ringkasan Transaksi'),
            UI.el('div', { class: 'tx2-tiles' }, [
                tile('Total Item', ui.sum.items), tile('Total Qty', ui.sum.qty, ui.sum.qtyNote), tile('Subtotal Harga Jual', ui.sum.subtotal),
                tile('Biaya Kirim', shipping),
                UI.el('div', { class: 'tx2-grandtile' }, [UI.el('div', { class: 'tx2-tile-k' }, 'Grand Total'), ui.sum.grand]),
            ]),
        ]);
    }

    function buildBar() {
        const reset = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-reset' }, '↺ Reset');
        reset.addEventListener('click', async () => {
            const ok = await Modal.confirm({ title: 'Reset transaksi?', message: 'Semua isian pada form ini akan dikosongkan.', confirmLabel: 'Reset', danger: true });
            if (ok) { S = fresh(); defaultsAsked = new Set(); draw(); }
        });
        const pdo = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-preview-do' }, '📄 Preview DO');
        const pinv = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-preview-invoice' }, '👁 Preview Invoice');
        pdo.addEventListener('click', () => previewDrawer(1));
        pinv.addEventListener('click', () => previewDrawer(0));
        const next = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-next', 'data-testid': 'out-next' }, '✓ Lanjut / Simpan');
        next.addEventListener('click', goReview);
        ui.bar = { status: UI.el('div', { class: 'tx2-status-line ok', 'data-testid': 'out-status' }, 'Data siap disimpan'), pdo, pinv, next };
        return K.stickyBar(UI.el('div', { class: 'tx2-bar tx2-bar-out' }, [reset, ui.bar.status, UI.el('div', { class: 'tx2-bar-sp' }), pdo, pinv, next]));
    }

    // ---------------------------------------------------------------- payload, preview, review, post
    function payload() {
        const lines = S.rows.filter((r) => rowFilled(r)).map((r) => ({ item_id: r.item ? r.item.id : 0, input_unit_id: Number(r.unitId) || 0, input_qty: K.nz(r.qty) }));
        const markups = {};
        categoriesInUse().forEach((k) => { const m = S.markups[k]; if (markupOk(m)) markups[k] = { mode: m.mode, value: Number(m.value) }; });
        return {
            warehouse_id: Number(S.warehouseId), bakery_destination_id: Number(S.bakeryId), transaction_date: S.date, reference_no: S.reference || null, notes: S.notes || null,
            shipping_amount: Math.max(0, K.nz(S.shipping)), markups, lines,
        };
    }

    function previewDrawer(start) {
        const c = refresh();
        if (c.problems.length) { UI.toast(c.problems[0], 'error'); return; }
        const p = payload();
        TxKit.openDocDrawer('Preview Dokumen', [
            { label: 'Tampilan Invoice', load: () => K.api('POST', '/stock-out/preview/invoice', p, { raw: true }) },
            { label: 'Tampilan DO', load: () => K.api('POST', '/stock-out/preview/do', p, { raw: true }) },
        ], start);
    }

    function savedDrawer(doId, start, numbers = {}) {
        K.openDocDrawer(`${numbers.invoice_number || ''} ${numbers.do_number ? '· ' + numbers.do_number : ''}`.trim() || 'Dokumen', [
            { label: 'Tampilan Invoice', load: () => K.api('GET', `/stock-out/${doId}/print/invoice`, undefined, { raw: true }) },
            { label: 'Tampilan DO', load: () => K.api('GET', `/stock-out/${doId}/print/do`, undefined, { raw: true }) },
        ], start);
    }

    function goReview() {
        const c = refresh();
        if (c.problems.length) { UI.toast(c.problems[0], 'error'); return; }
        S.phase = 'review';
        draw();
    }

    function backBtn() {
        const b = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-back' }, '‹ Kembali ke Barang');
        b.addEventListener('click', () => { S.phase = 'edit'; draw(); });
        return b;
    }

    function drawReview() {
        const wrap = UI.el('div', { class: 'tx2-card tx2-review', 'data-testid': 'out-review' }, [UI.el('div', { class: 'alert alert-info' }, 'Memuat review...')]);
        ctx.host.appendChild(wrap);
        (async () => {
            let q;
            try { q = await K.api('POST', '/stock-out/quote', payload()); } catch (err) { wrap.innerHTML = ''; wrap.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'out-review-error' }, `Gagal memuat review: ${err.message}`)); wrap.appendChild(backBtn()); return; }
            S.quote = q;
            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { id: 'out-review-alert', 'data-testid': 'out-review-alert' }));
            if (!q.valid) {
                wrap.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'out-review-invalid' }, ['Data belum valid:', UI.el('ul', {}, q.errors.map((e) => UI.el('li', {}, e)))]));
                wrap.appendChild(backBtn());
                return;
            }
            const kv = (k, v) => UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, k), UI.el('div', { class: 'tx2-kv-v' }, v)]);
            wrap.appendChild(UI.el('div', { class: 'tx2-reviewhead' }, [
                kv('Gudang Asal', q.warehouse.name), kv('Bakery Tujuan', q.bakery.name), kv('Tanggal', S.date), kv('Referensi', S.reference || '-'),
                ...(q.bakery.address ? [kv('Alamat', q.bakery.address)] : []), ...(S.notes ? [kv('Catatan', S.notes)] : []),
            ]));
            // markup rules actually applied
            const rules = [];
            const seen = new Set();
            q.lines.forEach((l) => { if (!seen.has(l.category_id)) { seen.add(l.category_id); rules.push(UI.el('span', { class: 'tx2-rule', 'data-testid': 'out-review-rule' }, `${l.category_name || 'Tanpa Kategori'}: ${l.markup_mode === 'PERCENT' ? `${K.fmtNum(l.markup_value, 2)}%` : K.money(l.markup_value)}`)); } });
            wrap.appendChild(UI.el('div', { class: 'tx2-rules' }, [UI.el('span', { class: 'tx2-rules-k' }, 'Markup:'), ...rules]));
            wrap.appendChild(UI.el('div', { class: 'tx2-tablewrap' }, [UI.el('table', { class: 'tx2-table tx2-reviewtable' }, [
                UI.el('thead', {}, [UI.el('tr', {}, [['No', 'c'], ['Nama Barang', ''], ['Kategori', ''], ['Qty + Satuan', 'r'], ['Harga Jual', 'r'], ['Total', 'r']].map(([h, cl]) => UI.el('th', { class: cl }, h)))]),
                UI.el('tbody', {}, q.lines.map((l) => UI.el('tr', { 'data-testid': 'out-review-row' }, [
                    UI.el('td', { class: 'c' }, String(l.line_no)), UI.el('td', {}, l.item_name), UI.el('td', {}, l.category_name || '-'), UI.el('td', { class: 'r' }, `${K.fmtNum(l.input_qty)} ${l.unit_code}`),
                    UI.el('td', { class: 'r' }, K.money(l.selling_price)), UI.el('td', { class: 'r strong' }, K.money(l.total)),
                ]))),
            ])]));
            const t = q.totals;
            wrap.appendChild(UI.el('div', { class: 'tx2-reviewsum' }, [UI.el('div', { class: 'tx2-sumbox' }, [
                UI.el('div', { class: 'tx2-sumrow' }, [UI.el('span', {}, 'Subtotal Harga Jual'), UI.el('span', { 'data-testid': 'out-review-subtotal' }, K.money(t.subtotal))]),
                UI.el('div', { class: 'tx2-sumrow' }, [UI.el('span', {}, 'Biaya Kirim'), UI.el('span', { 'data-testid': 'out-review-shipping' }, K.money(t.shipping_amount))]),
                UI.el('div', { class: 'tx2-grand' }, [UI.el('span', {}, 'Grand Total'), UI.el('span', { 'data-testid': 'out-review-grand' }, K.money(t.grand_total))]),
            ])]));
            const pdo = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-review-preview-do' }, '📄 Preview DO');
            const pinv = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-review-preview-invoice' }, '👁 Preview Invoice');
            pdo.addEventListener('click', () => previewDrawer(1));
            pinv.addEventListener('click', () => previewDrawer(0));
            const save = UI.el('button', { type: 'button', class: 'btn btn-primary tx2-next', id: 'out-post-btn', 'data-testid': 'out-post' }, '✓ Simpan & Buat DO + Invoice');
            save.addEventListener('click', () => post());
            wrap.appendChild(UI.el('div', { class: 'tx2-actions' }, [backBtn(), UI.el('div', { class: 'tx2-bar-sp' }), pdo, pinv, save]));
        })();
    }

    async function post() {
        if (!S.uuid) S.uuid = K.uuid();
        const btn = document.getElementById('out-post-btn');
        if (btn) btn.disabled = true;
        const box = document.getElementById('out-review-alert');
        if (box) box.innerHTML = '';
        const show = (type, msg) => { if (box) { box.innerHTML = ''; box.appendChild(UI.el('div', { class: `alert alert-${type}`, 'data-testid': 'out-post-alert' }, msg)); } };
        try {
            const res = await K.api('POST', '/stock-out', Object.assign(payload(), { transaction_uuid: S.uuid }));
            S.result = res;
            S.uuid = null;
            S.phase = 'done';
            S.stock = {};
            draw();
        } catch (err) {
            if (err.code === 'NETWORK_ERROR') show('error', 'Koneksi terputus. Transaksi BELUM tentu tersimpan — kirim ulang aman (tidak akan tercatat dobel).');
            else show('error', err.errors && err.errors.length ? err.errors.join('; ') : err.message);
            if (btn) btn.disabled = false;
        }
    }

    function drawDone() {
        const r = S.result || {};
        const kv = (k, v, tid) => UI.el('div', { class: 'tx2-kv' }, [UI.el('div', { class: 'tx2-kv-k' }, k), UI.el('div', { class: 'tx2-kv-v', ...(tid ? { 'data-testid': tid } : {}) }, v)]);
        const wrap = UI.el('div', { class: 'tx2-card tx2-done', 'data-testid': 'out-done' }, [
            UI.el('div', { class: 'alert alert-success' }, 'Transaksi Keluar berhasil disimpan. DO dan Invoice sudah dibuat.'),
            UI.el('div', { class: 'tx2-reviewhead' }, [
                kv('No. DO', r.do_number || '-', 'out-done-do'), kv('No. Invoice', r.invoice_number || '-', 'out-done-invoice'), kv('Bakery Tujuan', r.bakery_name || '-'), kv('Gudang Asal', r.warehouse_name || '-'),
                kv('Grand Total', K.money(r.grand_total), 'out-done-grand'),
            ]),
        ]);
        const pdo = UI.el('button', { type: 'button', class: 'btn btn-primary', 'data-testid': 'out-done-print-do' }, '🖨 Cetak DO');
        const pinv = UI.el('button', { type: 'button', class: 'btn btn-primary', 'data-testid': 'out-done-print-invoice' }, '🖨 Cetak Invoice');
        pdo.addEventListener('click', () => savedDrawer(r.do_id, 1, r));
        pinv.addEventListener('click', () => savedDrawer(r.do_id, 0, r));
        const again = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'out-new' }, '＋ Transaksi Keluar Baru');
        again.addEventListener('click', () => { S = fresh(); defaultsAsked = new Set(); draw(); });
        const hist = UI.el('button', { type: 'button', class: 'btn btn-secondary' }, '🧾 Riwayat');
        hist.addEventListener('click', openHistory);
        wrap.appendChild(UI.el('div', { class: 'tx2-actions' }, [pinv, pdo, again, hist]));
        ctx.host.appendChild(wrap);
    }

    // ---------------------------------------------------------------- reprint list
    async function openHistory() {
        const body = UI.el('div', { 'data-testid': 'out-history-body' }, [UI.el('div', { class: 'alert alert-info' }, 'Memuat riwayat...')]);
        K.wideDrawer('Riwayat Stock OUT (DO & Invoice)', (b) => b.appendChild(body));
        try {
            const rows = await K.api('GET', '/stock-out/recent?limit=40');
            body.innerHTML = '';
            if (!rows.length) { body.appendChild(UI.el('div', { class: 'tx2-markup-empty' }, 'Belum ada Stock OUT dengan DO/Invoice.')); return; }
            body.appendChild(UI.el('div', { class: 'tx2-tablewrap' }, [UI.el('table', { class: 'tx2-table', 'data-testid': 'out-history-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'No. DO', 'No. Invoice', 'Bakery', 'Gudang', 'Item', 'Grand Total', 'Cetak'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, rows.map((x) => {
                    const pdo = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'out-history-do' }, 'DO');
                    const pinv = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'out-history-invoice' }, 'Invoice');
                    pdo.addEventListener('click', () => savedDrawer(x.do_id, 1, x));
                    pinv.addEventListener('click', () => savedDrawer(x.do_id, 0, x));
                    return UI.el('tr', { 'data-testid': 'out-history-row' }, [UI.el('td', {}, x.do_date), UI.el('td', {}, x.do_number), UI.el('td', {}, x.invoice_number || '-'), UI.el('td', {}, x.bakery_name), UI.el('td', {}, x.warehouse_name), UI.el('td', { class: 'r' }, String(x.item_count)), UI.el('td', { class: 'r' }, K.money(x.grand_total || 0)), UI.el('td', {}, [UI.el('div', { class: 'tx2-inline' }, [pdo, pinv])])]);
                })),
            ])]));
        } catch (err) { body.innerHTML = ''; body.appendChild(UI.el('div', { class: 'alert alert-error' }, `Gagal memuat riwayat: ${err.message}`)); }
    }

    return { mount, openDocuments: savedDrawer, _state: () => S, _calc: calc };
})();
