/**
 * STOCK IN / OUT V2 — the table-first transaction workspace (shell + shared kit).
 *
 * The page is ONE compact transaction sheet per kind (Stock IN / Stock OUT):
 * header fields, a "Daftar Barang" table with live totals, a summary bar and a
 * simplified Review. The two sheets live in stock-in-sheet.js and
 * stock-out-sheet.js; this file owns the tab shell and TxKit, the helpers both
 * share (number input/format, item picker without SKU column, unit cache,
 * server calls, document preview drawer).
 *
 * Authority: the browser only COLLECTS and PREVIEWS. Every number that is posted
 * or printed is recomputed by the server (POST /stock-in/quote, /stock-out/quote
 * — same code path as the posting) and the Review step shows the server's
 * figures. Master / default prices are never written from here.
 *
 * Only the proven endpoints are used for reads that already existed
 * (GET /items/{id}/units, GET /inventory/current); new endpoints are called
 * through TxKit.api() with the session's CSRF token (api-client.js untouched).
 */
const TxKit = (() => {
    // ------------------------------------------------------------ numbers
    const nz = (v) => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? 0 : Number(v));
    const money = (v) => UI.formatMoney(Math.round((Number(v) || 0) * 100) / 100);
    const fmtNum = (v, d = 4) => UI.formatNumber(Number(v) || 0, d);

    /** "12.500" / "12.500,5" / "12500.5" / "5" → number (id-ID: '.' thousands, ',' decimal) */
    function parseNum(text) {
        let t = String(text ?? '').replace(/[^\d.,-]/g, '');
        if (t === '' || t === '-') return NaN;
        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
        else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
        return Number(t);
    }
    const fmtInput = (v) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? '' : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 4 }));
    const round4 = (v) => Math.round((v + Number.EPSILON) * 10000) / 10000;

    /**
     * Text input that shows grouped digits when idle and raw digits while typing.
     * `onValue(number|NaN)` fires on every keystroke; the caller keeps its own state.
     */
    function numInput({ value = '', placeholder = '0', prefix = null, suffix = null, onValue, testid = null, cls = '', money: isMoney = false }) {
        const input = UI.el('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder, class: 'tx2-num', ...(testid ? { 'data-testid': testid } : {}) });
        input.value = fmtInput(value);
        let focused = false;
        input.addEventListener('focus', () => {
            focused = true;
            const n = parseNum(input.value);
            input.value = Number.isNaN(n) ? '' : String(n).replace('.', ',');
            input.select();
        });
        input.addEventListener('blur', () => {
            focused = false;
            const n = parseNum(input.value);
            input.value = Number.isNaN(n) ? '' : fmtInput(n);
        });
        input.addEventListener('input', () => onValue(parseNum(input.value)));
        const wrap = UI.el('div', { class: `tx2-numwrap ${cls}`.trim() }, [
            prefix ? UI.el('span', { class: 'tx2-pre' }, prefix) : null, input, suffix ? UI.el('span', { class: 'tx2-suf' }, suffix) : null,
        ]);
        wrap.input = input;
        wrap.setValue = (v) => { if (!focused) input.value = fmtInput(v); };
        return wrap;
    }

    // ------------------------------------------------------------ server
    function csrf() {
        const u = Auth.user();
        return (u && u.csrf_token) || '';
    }
    /** JSON (or raw HTML with {raw:true}) call; throws an Error with .code/.status/.errors like InvApi. */
    async function api(method, path, body, { raw = false } = {}) {
        let res;
        try {
            const headers = {};
            if (body !== undefined) headers['Content-Type'] = 'application/json';
            if (method !== 'GET') headers['X-CSRF-Token'] = csrf();
            res = await fetch(`/api${path}`, { method, credentials: 'include', headers, body: body !== undefined ? JSON.stringify(body) : undefined });
        } catch (e) {
            const err = new Error('Sistem sedang tidak dapat terhubung ke server.');
            err.code = 'NETWORK_ERROR';
            throw err;
        }
        if (raw && res.ok) return res.text();
        let payload;
        try { payload = await res.json(); } catch (e) { const err = new Error(`Respons server tidak valid (HTTP ${res.status}).`); err.code = 'INVALID_RESPONSE'; throw err; }
        if (!payload.success) {
            const err = new Error((payload.error && payload.error.message) || `Permintaan gagal (HTTP ${res.status}).`);
            err.code = (payload.error && payload.error.code) || 'UNKNOWN_ERROR';
            err.status = res.status;
            err.errors = (payload.error && (payload.error.errors || payload.error.details)) || null;
            throw err;
        }
        return payload.data;
    }

    // ------------------------------------------------------------ units (one request per item, cached)
    const unitCache = new Map();
    function unitsFor(itemId) {
        const key = String(itemId);
        if (!unitCache.has(key)) {
            unitCache.set(key, (async () => {
                let units = await InvApi.itemUnits(itemId);
                const item = Master.itemById(itemId);
                const baseId = item ? item.base_unit_id : null;
                if (baseId !== null && baseId !== undefined && !units.some((u) => String(u.id) === String(baseId))) {
                    const base = Master.unitById(baseId);
                    if (base) units = units.concat([{ id: base.id, code: base.code, name: base.name, conversion_to_base: 1, is_purchase_default: false, reference_price: null, price_source: null }]);
                }
                return units;
            })().catch((err) => { unitCache.delete(key); throw err; }));
        }
        return unitCache.get(key);
    }
    const clearUnitCache = () => unitCache.clear();

    // ------------------------------------------------------------ item picker (name only — no SKU column)
    /**
     * Autocomplete over the already-loaded master items (ItemSelector.search: name,
     * SKU or barcode match, ACTIVE only). Shows the NAME; the SKU/barcode is only
     * a way to find the item. Keyboard: ↑/↓/Enter/Esc. Exact barcode/SKU + Enter
     * resolves directly. An unknown text is never turned into an item.
     */
    function itemPicker({ item = null, onPick, onClear, onHint }) {
        const input = UI.el('input', { type: 'text', class: 'tx2-pick', placeholder: 'Cari nama barang...', autocomplete: 'off', 'data-testid': 'tx-item-input' });
        const hint = UI.el('div', { class: 'tx2-pick-hint' });
        const wrap = UI.el('div', { class: 'tx2-pickwrap' }, [UI.el('span', { class: 'tx2-pick-ico' }, '⌕'), input]);
        const dropdown = UI.el('div', { class: 'tx2-dd', 'data-testid': 'tx-item-dd' });
        dropdown.style.display = 'none';
        let results = [];
        let hi = -1;
        let current = item;
        if (item) input.value = item.name;

        const close = () => { dropdown.style.display = 'none'; if (dropdown.parentNode) dropdown.parentNode.removeChild(dropdown); hi = -1; };
        function place() {
            const r = input.getBoundingClientRect();
            const w = Math.max(r.width, 300);
            dropdown.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - w - 8))}px`;
            dropdown.style.width = `${w}px`;
            const below = window.innerHeight - r.bottom;
            if (below < 260 && r.top > below) { dropdown.style.top = 'auto'; dropdown.style.bottom = `${window.innerHeight - r.top + 4}px`; } else { dropdown.style.bottom = 'auto'; dropdown.style.top = `${r.bottom + 4}px`; }
        }
        function render() {
            dropdown.innerHTML = '';
            if (!results.length) {
                dropdown.appendChild(UI.el('div', { class: 'tx2-dd-empty' }, 'Barang belum tersedia di Master Barang. Tambahkan melalui Master Barang terlebih dahulu.'));
            } else {
                results.forEach((it, i) => {
                    const cat = it.category_id ? Master.categoryById(it.category_id) : null;
                    const row = UI.el('div', { class: `tx2-dd-opt${i === hi ? ' active' : ''}`, 'data-testid': 'tx-item-opt' }, [
                        UI.el('span', { class: 'tx2-dd-name' }, it.name), UI.el('span', { class: 'tx2-dd-cat' }, cat ? cat.name : ''),
                    ]);
                    row.addEventListener('mousedown', (e) => { e.preventDefault(); pick(it); });
                    dropdown.appendChild(row);
                });
            }
            if (!dropdown.parentNode) document.body.appendChild(dropdown);
            place();
            dropdown.style.display = 'block';
        }
        function pick(it) {
            current = it;
            input.value = it.name;
            hint.textContent = '';
            close();
            onPick(it);
        }
        input.addEventListener('input', () => {
            if (current) { current = null; onClear(); }
            results = ItemSelector.search(input.value);
            hi = -1;
            if (input.value.trim()) render(); else close();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (dropdown.style.display === 'none' && input.value.trim()) { results = ItemSelector.search(input.value); render(); } if (results.length) { hi = Math.min(hi + 1, results.length - 1); render(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (results.length) { hi = Math.max(hi - 1, 0); render(); } }
            else if (e.key === 'Escape') close();
            else if (e.key === 'Enter') {
                e.preventDefault();
                if (hi >= 0 && results[hi]) { pick(results[hi]); return; }
                const raw = input.value.trim();
                if (!raw) return;
                const bc = ItemSelector.resolveBarcode(raw);
                if (bc.status === 'OK') { pick(bc.item); return; }
                const sku = Master.items().find((i) => i.status === 'ACTIVE' && String(i.sku).toLowerCase() === raw.toLowerCase());
                if (sku) { pick(sku); return; }
                if (results.length === 1) pick(results[0]);
                else if (!results.length && onHint) onHint('Barang belum tersedia di Master Barang. Tambahkan melalui Master Barang terlebih dahulu.');
            }
        });
        input.addEventListener('blur', () => setTimeout(close, 120));
        const reposition = () => { if (dropdown.style.display !== 'none') place(); };
        window.addEventListener('resize', reposition);
        const node = UI.el('div', { class: 'tx2-pickcell' }, [wrap, hint]);
        node.focus = () => input.focus();
        node.setHint = (t) => { hint.textContent = t || ''; };
        node.destroy = () => { close(); window.removeEventListener('resize', reposition); };
        return node;
    }

    // ------------------------------------------------------------ small UI helpers
    const icon = (txt, cls = '') => UI.el('span', { class: `tx2-ico ${cls}`.trim(), 'aria-hidden': 'true' }, txt);
    function field(label, control, { required = false, cls = '' } = {}) {
        return UI.el('div', { class: `tx2-field ${cls}`.trim() }, [UI.el('label', {}, required ? [label, UI.el('span', { class: 'tx2-req' }, ' *')] : [label]), control]);
    }
    function select(options, value, onChange, { disabled = false, testid = null, cls = '' } = {}) {
        const s = UI.el('select', { class: `tx2-sel ${cls}`.trim(), ...(testid ? { 'data-testid': testid } : {}) });
        options.forEach(([v, label]) => s.appendChild(UI.el('option', { value: String(v) }, label)));
        s.value = value === null || value === undefined ? '' : String(value);
        if (disabled) s.setAttribute('disabled', 'disabled');
        s.addEventListener('change', () => onChange(s.value));
        return s;
    }
    /** two-way segmented toggle, e.g. [%, Nominal] */
    function segmented(options, value, onChange, testid = null) {
        const box = UI.el('div', { class: 'tx2-seg', ...(testid ? { 'data-testid': testid } : {}) });
        const btns = options.map(([v, label]) => {
            const b = UI.el('button', { type: 'button', class: `tx2-segbtn${String(v) === String(value) ? ' on' : ''}`, 'data-value': String(v) }, label);
            b.addEventListener('click', () => { btns.forEach((x) => x.classList.toggle('on', x === b)); onChange(v); });
            box.appendChild(b);
            return b;
        });
        return box;
    }
    function stepper(step) {
        const labels = ['Informasi', 'Barang', 'Review', 'Selesai'];
        return UI.el('div', { class: 'tx2-steps', 'data-testid': 'tx-steps' }, labels.map((l, i) => UI.el('div', { class: `tx2-step${i + 1 === step ? ' on' : (i + 1 < step ? ' done' : '')}` }, [UI.el('span', { class: 'tx2-stepno' }, i + 1 < step ? '✓' : String(i + 1)), UI.el('span', {}, l)])));
    }
    const uuid = () => InvApi.newRequestUuid();

    // ------------------------------------------------------------ bottom action bar that stays reachable
    // body{overflow-x:hidden} makes <body> a (non-scrolling) scroll container, which silently disables
    // position:sticky for every descendant — so the bar is pinned with position:fixed instead, only while
    // its natural spot is below the visible area, and aligned to the sheet's own left/width.
    const bars = [];
    function updateBars() {
        for (let i = bars.length - 1; i >= 0; i--) {
            const { slot, bar } = bars[i];
            if (!slot.isConnected) { bars.splice(i, 1); continue; }
            bar.classList.remove('pinned');
            bar.style.left = ''; bar.style.width = '';
            slot.style.minHeight = '';
            const h = bar.getBoundingClientRect().height;
            const r = slot.getBoundingClientRect();
            if (r.top + h > window.innerHeight) {
                slot.style.minHeight = `${h}px`;
                bar.style.left = `${r.left}px`;
                bar.style.width = `${r.width}px`;
                bar.classList.add('pinned');
            }
        }
    }
    window.addEventListener('scroll', updateBars, { passive: true });
    window.addEventListener('resize', updateBars);
    function stickyBar(bar) {
        const slot = UI.el('div', { class: 'tx2-barslot' }, [bar]);
        bars.push({ slot, bar });
        setTimeout(updateBars, 0);
        slot.update = updateBars;
        return slot;
    }

    // ------------------------------------------------------------ document preview / print drawer (Stock OUT)
    function lockPage(on) {
        document.documentElement.classList.toggle('tx2-scroll-lock', on);
        document.body.classList.toggle('tx2-scroll-lock', on);
    }
    /** Wide drawer (shared Drawer singleton) with page scroll-lock + cleanup on close. */
    function wideDrawer(title, render) {
        Drawer.open({ title: UI.el('div', { 'data-tx-doc-title': '1' }, title), render });
        const panel = document.querySelector('.drawer');
        if (panel) {
            panel.classList.add('drawer-tx');
            lockPage(true);
            const obs = new MutationObserver(() => {
                if (!panel.classList.contains('open') || !panel.querySelector('[data-tx-doc-title]')) { panel.classList.remove('drawer-tx'); lockPage(false); obs.disconnect(); }
            });
            obs.observe(panel, { attributes: true, attributeFilter: ['class'], childList: true });
        }
    }
    /**
     * Wide drawer with an iframe showing server-rendered A4 HTML (the exact
     * document that is printed). docs = [{label, load: () => Promise<html>}].
     */
    function openDocDrawer(title, docs, startIndex = 0) {
        const frame = UI.el('iframe', { class: 'tx2-docframe', title: 'Pratinjau dokumen', 'data-testid': 'tx-doc-frame' });
        const status = UI.el('div', { class: 'tx2-docstatus' });
        const tabs = UI.el('div', { class: 'tx2-doctabs' });
        let active = startIndex;
        const printBtn = UI.el('button', { class: 'btn btn-secondary tx2-printbtn', type: 'button', 'data-testid': 'tx-doc-print' }, '🖨 Cetak');
        printBtn.addEventListener('click', () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { UI.toast('Cetak tidak tersedia di browser ini.', 'error'); } });
        const seq = { n: 0 };
        async function show(i) {
            active = i;
            const mine = ++seq.n;
            Array.from(tabs.children).forEach((b, bi) => b.classList.toggle('on', bi === i));
            status.textContent = 'Memuat dokumen...';
            status.className = 'tx2-docstatus';
            try {
                const html = await docs[i].load();
                if (mine !== seq.n) return;
                frame.srcdoc = html;
                status.textContent = '';
            } catch (err) {
                if (mine !== seq.n) return;
                frame.srcdoc = '';
                status.textContent = `Gagal memuat dokumen: ${err.message}`;
                status.className = 'tx2-docstatus err';
            }
        }
        docs.forEach((d, i) => {
            const b = UI.el('button', { type: 'button', class: 'tx2-doctab', 'data-testid': `tx-doc-tab-${i}` }, d.label);
            b.addEventListener('click', () => show(i));
            tabs.appendChild(b);
        });
        const head = UI.el('div', { class: 'tx2-dochead' }, [tabs, printBtn]);
        wideDrawer(title, (b) => { b.appendChild(head); b.appendChild(status); b.appendChild(frame); });
        show(active);
    }

    return { nz, money, fmtNum, parseNum, fmtInput, round4, numInput, api, unitsFor, clearUnitCache, itemPicker, icon, field, select, segmented, stepper, uuid, openDocDrawer, wideDrawer, stickyBar, updateBars };
})();

const Transactions = (() => {
    const state = { kind: null };

    function can(code) { return Auth.hasPermission(code); }

    function render(container) {
        container.innerHTML = '';
        const canIn = can('TRANSACTION_IN_CREATE');
        const canOut = can('TRANSACTION_OUT_CREATE');
        if (!canIn && !canOut) {
            container.appendChild(UI.el('div', { class: 'alert alert-warning' }, 'Anda tidak memiliki izin untuk membuat transaksi Stock IN / OUT.'));
            return;
        }
        if (!state.kind || (state.kind === 'in' && !canIn) || (state.kind === 'out' && !canOut)) state.kind = canIn ? 'in' : 'out';

        const root = UI.el('div', { class: 'tx2', 'data-testid': 'tx-root' });
        const host = UI.el('div', { class: 'tx2-host', id: 'tx2-host' });
        const tabs = UI.el('div', { class: 'tx2-tabs', 'data-testid': 'tx-tabs' });
        const mk = (kind, label, glyph) => {
            const b = UI.el('button', { type: 'button', class: `tx2-tab${state.kind === kind ? ' on' : ''}`, 'data-testid': `tx-tab-${kind}` }, [UI.el('span', { class: 'tx2-tab-ico' }, glyph), label]);
            b.addEventListener('click', () => { if (state.kind === kind) return; state.kind = kind; render(container); });
            return b;
        };
        if (canIn) tabs.appendChild(mk('in', 'Stock IN', '⭳'));
        if (canOut) tabs.appendChild(mk('out', 'Stock OUT', '⭱'));

        const title = state.kind === 'in' ? 'Transaksi Stock IN / OUT' : 'Transaksi Keluar (Stock OUT)';
        const sub = state.kind === 'in' ? 'Kelola transaksi pemasukan dan pengeluaran stok barang' : 'Distribusi barang ke cabang bakery (packaging, aksesoris, dan bahan-bahan).';
        root.appendChild(UI.el('div', { class: 'tx2-top' }, [
            UI.el('div', { class: 'tx2-titlebox' }, [UI.el('div', { class: 'tx2-titleico' }, state.kind === 'in' ? '⭳' : '⭱'), UI.el('div', {}, [UI.el('h2', { class: 'tx2-title' }, title), UI.el('div', { class: 'tx2-subtitle' }, sub)])]),
            tabs,
            UI.el('div', { class: 'tx2-stepslot', id: 'tx2-stepslot' }),
        ]));
        root.appendChild(host);
        container.appendChild(root);

        const ctx = { host, stepSlot: root.querySelector('#tx2-stepslot') };
        if (state.kind === 'in') StockInSheet.mount(ctx); else StockOutSheet.mount(ctx);
    }

    return { render };
})();
