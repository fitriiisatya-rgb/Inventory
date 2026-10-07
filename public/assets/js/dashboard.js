/**
 * Main Dashboard — compact, operational, warehouse-filterable, REAL data.
 *
 * Everything on the page comes from ONE read-only call,
 *     GET /api/dashboard/inventory?warehouse_id=&period=&date_from=&date_to=
 * (DashboardInventoryService) and every card drills into the rows behind it with
 *     GET /api/dashboard/inventory/detail?type=...   (paginated, searchable)
 * This file never recomputes a total: each number shown is a server figure, and
 * the drill-down's GRAND TOTAL is the server's sum of the very rows listed.
 * Only GET requests are ever issued here (no write, no posting).
 *
 * The stock-movement cards (Stok Awal / Stock IN / Stock OUT / Adjustment / Stok
 * Akhir) are nominal Rupiah — mixed-unit quantities are deliberately never summed.
 * Every figure is the one Laporan Pergerakan Stok (Reports v3) reports for the same
 * filter: Stok Awal + Stock IN − Stock OUT + Transfer IN − Transfer OUT + Adjustment
 * = Stok Akhir. Transfers are supporting detail (company-wide they net to zero once
 * received); Adjustment is NAMED by ledger transaction type — there is no generic
 * "Pergerakan lain" (see DashboardInventoryService).
 *
 * Self-contained styling: .dash-* rules ship with this file's app.css patch.
 * A warehouse-scoped STOCK user only ever sees their own warehouse (the server
 * forces it; the selector is also locked here).
 */
const Dashboard = (() => {
    const state = { warehouseId: '', period: 'month', from: '', to: '', data: null, seq: 0, root: null };

    const isNil = (v) => v === null || v === undefined;
    const money = (v) => (isNil(v) ? '—' : UI.formatMoney(v));
    const num = (v, d = 0) => (isNil(v) ? '—' : UI.formatNumber(v, d));
    const qty = (v) => (isNil(v) ? '—' : UI.formatNumber(v, 4));


    // Line icons (feather-style, stroke = currentColor). Static, trusted constants.
    const ICON_PATHS = {
        box: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        cart: '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        truck: '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        clipboard: '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><polyline points="9 14 11 16 15 12"/>',
        xcircle: '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        alert: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        heart: '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        activity: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        layers: '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        refresh: '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        bars: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        chevron: '<polyline points="9 18 15 12 9 6"/>',
        arrow: '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        repeat: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    };
    function icon(name, extraClass) {
        const span = document.createElement('span');
        span.className = `dash-ico${extraClass ? ` ${extraClass}` : ''}`;
        span.setAttribute('aria-hidden', 'true');
        span.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICON_PATHS[name] || ''}</svg>`;
        return span;
    }
    const tile = (name, tone) => UI.el('div', { class: `dash-tile tone-${tone}` }, [icon(name)]);
    const chev = () => UI.el('span', { class: 'dash-chev-ico' }, [icon('chevron')]);

    // ------------------------------------------------------------ data access
    async function getJson(path, params) {
        const qs = new URLSearchParams();
        Object.entries(params || {}).forEach(([k, v]) => { if (!isNil(v) && v !== '') qs.set(k, String(v)); });
        let res;
        try {
            res = await fetch(`/api${path}?${qs.toString()}`, { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } });
        } catch (e) {
            const ne = new Error('Sistem sedang tidak dapat terhubung ke server.'); ne.code = 'NETWORK_ERROR'; throw ne;
        }
        let payload;
        try { payload = await res.json(); } catch (e) { throw new Error(`Respons server tidak valid (HTTP ${res.status}).`); }
        if (!payload || !payload.success) {
            const err = new Error((payload && payload.error && payload.error.message) || `Gagal memuat dashboard (HTTP ${res.status}).`);
            err.status = res.status;
            throw err;
        }
        return payload.data;
    }

    function baseParams() {
        const p = { warehouse_id: state.warehouseId, period: state.period };
        if (state.period === 'custom') { p.date_from = state.from; p.date_to = state.to; }
        return p;
    }

    const isScopedStock = () => {
        const u = Auth.user();
        return !!(u && u.role_code === 'STOCK' && u.warehouse_id);
    };

    // ------------------------------------------------------------------ render
    async function render(container) {
        state.root = container;
        if (isScopedStock()) state.warehouseId = String(Auth.user().warehouse_id);
        container.innerHTML = '';
        container.appendChild(buildShell());
        await load();
    }

    function buildShell() {
        const shell = UI.el('div', { class: 'dash-page', 'data-testid': 'dash-page' });
        shell.appendChild(buildTopBar());
        shell.appendChild(UI.el('div', { id: 'dash-body', 'data-testid': 'dash-body' }, [UI.el('div', { class: 'alert alert-info' }, 'Memuat dashboard...')]));
        return shell;
    }

    function buildTopBar() {
        const scoped = isScopedStock();
        const warehouses = Master.warehouses();
        const sel = UI.el('select', { id: 'dash-wh', 'data-testid': 'dash-warehouse', 'aria-label': 'Gudang' });
        if (!scoped) sel.appendChild(UI.el('option', { value: '' }, 'Semua Gudang'));
        warehouses.forEach((w) => { sel.appendChild(UI.el('option', { value: String(w.id) }, w.name)); });
        sel.value = state.warehouseId;
        if (scoped) sel.setAttribute('disabled', 'disabled');
        sel.addEventListener('change', () => { state.warehouseId = sel.value; load(); });

        const refresh = UI.el('button', { class: 'btn btn-primary dash-refresh', type: 'button', 'data-testid': 'dash-refresh' }, [icon('refresh'), ' Refresh']);
        refresh.addEventListener('click', () => load());

        return UI.el('div', { class: 'dash-top' }, [
            UI.el('div', { class: 'dash-filter' }, [UI.el('label', { for: 'dash-wh' }, 'Gudang:'), UI.el('div', { class: 'dash-select' }, [icon('home', 'dash-select-ico'), sel])]),
            UI.el('div', { class: 'dash-updated' }, [
                icon('clock'), UI.el('span', { id: 'dash-updated', 'data-testid': 'dash-updated' }, 'Data diperbarui: —'),
                refresh,
            ]),
        ]);
    }

    async function load() {
        const seq = ++state.seq;
        const body = state.root && state.root.querySelector('#dash-body');
        if (!body) return;
        body.classList.add('dash-loading');
        try {
            const data = await getJson('/dashboard/inventory', baseParams());
            if (seq !== state.seq) return; // a newer request superseded this one
            state.data = data;
            body.classList.remove('dash-loading');
            body.innerHTML = '';
            body.appendChild(buildBody(data));
            fitValues(body);
            const stamp = state.root.querySelector('#dash-updated');
            if (stamp) stamp.textContent = `Data diperbarui: ${formatStamp(data.generated_at)}`;
        } catch (err) {
            if (seq !== state.seq) return;
            body.classList.remove('dash-loading');
            body.innerHTML = '';
            const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'dash-retry' }, 'Coba lagi');
            retry.addEventListener('click', () => load());
            body.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'dash-error' }, `Gagal memuat dashboard: ${err.message}`));
            body.appendChild(retry);
            if (err.code === 'NETWORK_ERROR') UI.showConnectionBanner(true);
        }
    }

    function formatStamp(iso) {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return String(iso);
        return d.toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function buildBody(d) {
        const wrap = UI.el('div', { class: 'dash-stack' });
        wrap.appendChild(buildKpis(d));
        wrap.appendChild(buildMovement(d));
        wrap.appendChild(buildAttention(d));
        const grid = UI.el('div', { class: 'dash-cols' }, [
            UI.el('div', { class: 'dash-col' }, [buildActivity(d), buildQuickActions()]),
            UI.el('div', { class: 'dash-col' }, [buildTopLow(d), buildTopValue(d)]),
        ]);
        wrap.appendChild(grid);
        return wrap;
    }

    // ----------------------------------------------------------------- KPI row
    function kpi(testid, iconName, tone, label, valueNode, sub, onClick) {
        const card = UI.el('div', { class: 'dash-card dash-kpi dash-click', role: 'button', tabindex: '0', 'data-testid': testid, title: 'Klik untuk lihat rincian' }, [
            tile(iconName, tone),
            UI.el('div', { class: 'dash-kpi-main' }, [
                UI.el('div', { class: 'dash-label' }, label),
                UI.el('div', { class: 'dash-value', 'data-testid': `${testid}-value` }, [valueNode]),
                sub ? UI.el('div', { class: 'dash-sub' }, sub) : null,
            ]),
            chev(),
        ]);
        activate(card, onClick);
        return card;
    }

    // Rupiah figures are never wrapped or clipped. A figure keeps its full text while it fits
    // (shrinking at most to MIN_SHRINK of its CSS size); if it still does not fit it switches to
    // the compact secondary format ("Rp 2,23 M", "Rp 12,5 jt") at the full CSS size and only as a
    // last resort shrinks that. The exact value stays in `title` / `data-full` and in the
    // drill-down; the underlying number is never changed.
    const MIN_SHRINK = 0.86;
    function compactRupiah(full) {
        const m = /^(-?)\s*Rp\s*(-?)([\d.]+)(?:,(\d+))?\s*$/.exec(String(full).trim());
        if (!m) return null;
        const n = parseFloat(`${m[3].replace(/\./g, '')}.${m[4] || '0'}`);
        const abs = Math.abs(n);
        const sign = (m[1] || m[2]) ? '-' : '';
        const unit = abs >= 1e12 ? [1e12, 'T'] : abs >= 1e9 ? [1e9, 'M'] : abs >= 1e6 ? [1e6, 'jt'] : null;
        if (!unit) return null;
        const body = (abs / unit[0]).toLocaleString('id-ID', { maximumFractionDigits: 2 });
        return `${sign}Rp ${body} ${unit[1]}`;
    }
    function fitValues(root) {
        const scope = root || document;
        const overflows = (v) => v.scrollWidth > v.clientWidth + 0.5;
        const shrink = (v, floor) => {
            let px = parseFloat(getComputedStyle(v).fontSize);
            let guard = 80;
            while (overflows(v) && px > floor && guard-- > 0) {
                px -= 0.5;
                v.style.fontSize = `${px}px`;
            }
            return px;
        };
        const reset = (v) => {
            if (!v.dataset.full) v.dataset.full = v.textContent;
            v.textContent = v.dataset.full;
            v.style.fontSize = '';
            v.title = v.dataset.full;
            v.removeAttribute('data-compact');
        };
        const makeCompact = (v) => {
            const c = compactRupiah(v.dataset.full);
            if (!c) return false;
            v.textContent = c;
            v.dataset.compact = '1';
            v.style.fontSize = '';
            return true;
        };
        // Full text if it fits the floor size, else compact (when the figure can be compacted).
        const needsCompact = (v) => {
            reset(v);
            const base = parseFloat(getComputedStyle(v).fontSize);
            shrink(v, base * MIN_SHRINK);
            const fits = !overflows(v);
            v.style.fontSize = '';
            return !fits && compactRupiah(v.dataset.full) !== null;
        };
        // KPI figures are sized individually (only money figures can go compact).
        scope.querySelectorAll('.dash-kpis .dash-value').forEach((v) => {
            if (needsCompact(v)) makeCompact(v); else reset(v);
            shrink(v, 10);
        });
        // The four movement figures are all Rupiah of the same kind: they share one format
        // (compact for all if any needs it) and the smallest fitted size, so the row reads as a set.
        scope.querySelectorAll('.dash-mv-cards').forEach((group) => {
            const vals = Array.from(group.querySelectorAll('.dash-value'));
            const compact = vals.map(needsCompact).some(Boolean);
            vals.forEach((v) => { reset(v); if (compact) makeCompact(v); });
            const sizes = vals.map((v) => shrink(v, 10));
            const smallest = Math.min(...sizes);
            vals.forEach((v, i) => { if (sizes[i] > smallest) v.style.fontSize = `${smallest}px`; });
        });
    }
    let resizeTimer = null;
    window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { if (state.root) fitValues(state.root); }, 120); });

    function activate(el, fn) {
        el.addEventListener('click', fn);
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); } });
    }

    function buildKpis(d) {
        const s = d.summary;
        const sv = s.stock_value;
        const stockSub = sv.in_transit > 0 ? `Termasuk transit ${money(sv.in_transit)}` : 'HPP (rata-rata)';
        return UI.el('div', { class: 'dash-kpis' }, [
            kpi('dash-kpi-sku', 'box', 'blue', 'Total SKU Aktif', document.createTextNode(num(s.total_sku)), `${num(s.sku_with_stock)} SKU memiliki stok`,
                () => goStockReport({})),
            kpi('dash-kpi-value', 'layers', 'gold', 'Nilai Stok', document.createTextNode(money(sv.total)), stockSub,
                () => openDetail({ type: 'current_stock', title: 'Nilai Stok Saat Ini', totalLabel: 'GRAND TOTAL NILAI STOK (ON-HAND)' })),
            kpi('dash-kpi-transfer', 'truck', 'blue', 'Transfer Pending', document.createTextNode(num(s.pending_transfers)), 'Dalam proses',
                () => openDetail({ type: 'pending_transfers', title: 'Transfer Pending', totalLabel: 'TOTAL NILAI DALAM PERJALANAN' })),
            kpi('dash-kpi-opname', 'clipboard', 'green', 'Stock Opname Aktif', document.createTextNode(num(s.active_opname)), 'Sedang berjalan',
                () => openDetail({ type: 'active_opname', title: 'Stock Opname Aktif', totalLabel: '' })),
        ]);
    }

    function goStockReport(filters) {
        const f = Object.assign({}, filters);
        if (state.warehouseId) f.warehouse_id = Number(state.warehouseId);
        if (window.InvNav) window.InvNav.goToStockReport(f);
    }

    // ------------------------------------------------------- movement summary
    const MOVEMENT_CARDS = [
        { key: 'opening_stock', type: 'opening_stock', label: 'Stok Awal', cls: 'is-open', icon: 'box', total: 'GRAND TOTAL STOK AWAL', title: 'Rincian Stok Awal', sub: (m) => `${num(m.sku_count)} SKU`, op: '+' },
        { key: 'stock_in', type: 'stock_in', label: 'Stock IN', cls: 'is-in', icon: 'cart', total: 'GRAND TOTAL STOCK IN', title: 'Rincian Stock IN', sub: (m) => `${num(m.tx_count)} transaksi`, op: '−' },
        { key: 'stock_out', type: 'stock_out', label: 'Stock OUT', cls: 'is-out', icon: 'out', total: 'GRAND TOTAL STOCK OUT', title: 'Rincian Stock OUT', sub: (m) => `${num(m.tx_count)} transaksi`, op: '+' },
        { key: 'transfer_in', type: 'move_transfer_in', label: 'Transfer IN', cls: 'is-tin', icon: 'truck', total: 'GRAND TOTAL TRANSFER IN', title: 'Rincian Transfer IN', sub: (m) => `${num(m.tx_count)} transaksi`, op: '−', transfer: 'in', optional: true },
        { key: 'transfer_out', type: 'move_transfer_out', label: 'Transfer OUT', cls: 'is-tout', icon: 'truck', total: 'GRAND TOTAL TRANSFER OUT', title: 'Rincian Transfer OUT', sub: (m) => `${num(m.tx_count)} transaksi`, op: '+', transfer: 'out', optional: true },
        { key: 'adjustment', type: 'adjustment', label: 'Adjustment', cls: 'is-adj', icon: 'clipboard', total: 'GRAND TOTAL ADJUSTMENT', title: 'Rincian Adjustment (per jenis ledger)', sub: (m) => `${num(m.tx_count)} transaksi`, op: '=', signed: true },
        { key: 'closing_stock', type: 'closing_stock', label: 'Stok Akhir', cls: 'is-close', icon: 'box', total: 'GRAND TOTAL STOK AKHIR', title: 'Rincian Stok Akhir', sub: (m) => `${num(m.sku_count)} SKU`, op: null },
    ];

    function periodLabel(d) {
        const p = d.period;
        if (p.key === 'today') return `HARI INI — ${p.end_date}`;
        if (p.key === 'month') {
            const dt = new Date(`${p.end_date}T00:00:00`);
            return `BULAN INI — ${dt.toLocaleString('id-ID', { month: 'long', year: 'numeric' }).toUpperCase()}`;
        }
        return `${p.start_date} s/d ${p.end_date}`;
    }

    function buildMovement(d) {
        const m = d.movement;
        const section = UI.el('div', { class: 'dash-section dash-movement', 'data-testid': 'dash-movement' });

        const title = UI.el('div', { class: 'dash-section-title' }, [
            icon('repeat', 'dash-title-ico c-gold'), UI.el('span', {}, 'RINGKASAN PERGERAKAN STOK'),
            UI.el('span', { class: 'dash-period-label', 'data-testid': 'dash-period-label' }, `(PERIODE: ${periodLabel(d)})`),
        ]);
        section.appendChild(UI.el('div', { class: 'dash-section-head' }, [title, buildPeriodControls(d)]));

        if (m.note) section.appendChild(UI.el('div', { class: 'dash-note', 'data-testid': 'dash-movement-note' }, m.note));

        // Transfer joins the card row only when the arithmetic needs it (a warehouse that moved stock to / from another one, or company-wide value still in transit);
        // otherwise it is supporting detail below, because company-wide Transfer IN − Transfer OUT nets to zero.
        const tr = m.transfer;
        const showTransfer = (Math.abs(tr.in.value) > 0.005 || Math.abs(tr.out.value) > 0.005) && (tr.scope === 'warehouse' || Math.abs(tr.net) > 0.005);
        const shown = MOVEMENT_CARDS.filter((c) => !c.optional || showTransfer);
        const cards = UI.el('div', { class: `dash-mv-cards${showTransfer ? ' has-transfer' : ''}`, 'data-testid': 'dash-mv-cards' }, [].concat(...shown.map((c) => {
            const node = c.transfer ? tr[c.transfer] : m[c.key];
            const el = UI.el('div', { class: `dash-card dash-mv ${c.cls} dash-click`, role: 'button', tabindex: '0', 'data-testid': `dash-mv-${c.key}`, title: 'Klik untuk lihat rincian' }, [
                tile(c.icon, c.cls.replace('is-', '')),
                UI.el('div', { class: 'dash-mv-main' }, [
                    UI.el('div', { class: 'dash-label' }, c.label),
                    UI.el('div', { class: `dash-value dash-mv-value${c.signed ? (node.value < 0 ? ' neg' : (node.value > 0 ? ' pos' : '')) : ''}`, 'data-testid': `dash-mv-${c.key}-value` }, money(node.value)),
                    UI.el('div', { class: 'dash-sub' }, c.sub(node)),
                ]),
                chev(),
            ]);
            activate(el, () => openDetail({ type: c.type, title: c.title, totalLabel: c.total, columnsFor: c.key }));
            return c.op ? [el, UI.el('div', { class: 'dash-mv-arrow dash-mv-op', 'aria-hidden': 'true' }, c.op)] : [el];
        })));
        section.appendChild(cards);

        section.appendChild(buildArithmetic(d));
        section.appendChild(buildTransferStrip(d));
        section.appendChild(buildAdjustmentBreakdown(d));
        section.appendChild(buildReconciliationNote(d));
        return section;
    }

    function buildPeriodControls(d) {
        const bar = UI.el('div', { class: 'dash-period', 'data-testid': 'dash-period' });
        [['today', 'Hari Ini'], ['month', 'Bulan Ini'], ['custom', 'Custom']].forEach(([key, label]) => {
            const b = UI.el('button', { class: `dash-seg${state.period === key ? ' active' : ''}`, type: 'button', 'data-testid': `dash-period-${key}` }, label);
            b.addEventListener('click', () => {
                if (key === 'custom') {
                    state.period = 'custom';
                    if (!state.from) state.from = d.period.start_date;
                    if (!state.to) state.to = d.period.end_date;
                    // re-render only the controls; the user applies the range explicitly
                    const head = bar.parentNode;
                    head.replaceChild(buildPeriodControls(d), bar);
                    return;
                }
                state.period = key;
                load();
            });
            bar.appendChild(b);
        });
        if (state.period === 'custom') {
            const from = UI.el('input', { type: 'date', value: state.from, 'data-testid': 'dash-from', 'aria-label': 'Dari tanggal', max: d.today });
            const to = UI.el('input', { type: 'date', value: state.to, 'data-testid': 'dash-to', 'aria-label': 'Sampai tanggal', max: d.today });
            const apply = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'dash-apply' }, 'Terapkan');
            apply.addEventListener('click', () => {
                if (!from.value || !to.value) { UI.toast('Isi tanggal awal dan akhir.', 'error'); return; }
                if (from.value > to.value) { UI.toast('Tanggal awal tidak boleh setelah tanggal akhir.', 'error'); return; }
                state.from = from.value;
                state.to = to.value;
                load();
            });
            bar.appendChild(UI.el('span', { class: 'dash-range' }, [from, UI.el('span', {}, 's/d'), to, apply]));
        }
        return bar;
    }

    // The arithmetic under the cards: exactly the report's identity, every term a server figure.
    function buildArithmetic(d) {
        const m = d.movement;
        const c = m.components;
        const parts = [
            ['Stok Awal', money(c.opening)], ['+ Stock IN', money(c.in)], ['− Stock OUT', money(c.out)],
            ['+ Transfer IN', money(c.transfer_in)], ['− Transfer OUT', money(c.transfer_out)], ['± Adjustment', money(c.adjustment)],
        ];
        const line = UI.el('div', { class: 'dash-arith', 'data-testid': 'dash-arith' });
        parts.forEach(([l, v], i) => line.appendChild(UI.el('span', { class: 'dash-arith-t', 'data-testid': `dash-arith-${['opening', 'in', 'out', 'tin', 'tout', 'adj'][i]}` }, [UI.el('span', { class: 'dash-arith-l' }, l), ' ', UI.el('strong', {}, v)])));
        line.appendChild(UI.el('span', { class: 'dash-arith-t dash-arith-eq', 'data-testid': 'dash-arith-closing' }, [UI.el('span', { class: 'dash-arith-l' }, '= Stok Akhir'), ' ', UI.el('strong', {}, money(c.closing))]));
        return line;
    }

    // Transfer: supporting detail. Per warehouse each side stands alone; company-wide the two sides net to zero (a non-zero net is value still in transit and is labelled so).
    function buildTransferStrip(d) {
        const t = d.movement.transfer;
        const company = t.scope === 'company';
        const wrap = UI.el('div', { class: 'dash-transfer', 'data-testid': 'dash-transfer' });
        const side = (key, label, node, type) => {
            const clickable = node.tx_count > 0;
            const el = UI.el('span', { class: `dash-tr-item${clickable ? ' dash-click' : ' is-zero'}`, role: clickable ? 'button' : null, tabindex: clickable ? '0' : null, 'data-testid': `dash-tr-${key}` }, [
                UI.el('span', { class: 'dash-tr-l' }, label), ' ', UI.el('strong', {}, money(node.value)), UI.el('span', { class: 'dash-tr-n' }, ` · ${num(node.tx_count)} transaksi`),
            ]);
            if (clickable) activate(el, () => openDetail({ type, title: `Rincian ${label}`, totalLabel: `GRAND TOTAL ${label.toUpperCase()}` }));
            return el;
        };
        wrap.appendChild(UI.el('span', { class: 'dash-tr-title' }, 'Transfer antar gudang'));
        wrap.appendChild(side('in', 'Transfer IN', t.in, 'move_transfer_in'));
        wrap.appendChild(side('out', 'Transfer OUT', t.out, 'move_transfer_out'));
        if (company) {
            const zero = Math.abs(t.net) <= 0.01;
            wrap.appendChild(UI.el('span', { class: `dash-tr-net ${zero ? 'ok' : 'warn'}`, 'data-testid': 'dash-tr-net' },
                zero ? `Netto semua gudang ${money(0)} (transfer antar gudang saling meniadakan)` : `Netto ${money(t.net)} = transfer dalam perjalanan (belum diterima)`));
        }
        return wrap;
    }

    // Adjustment named by ledger transaction type (no catch-all). Each row drills into the ledger lines behind it.
    function buildAdjustmentBreakdown(d) {
        const b = d.movement.adjustment_breakdown;
        const wrap = UI.el('div', { class: 'dash-other', 'data-testid': 'dash-adjustment' });
        const toggle = UI.el('button', { class: 'dash-other-toggle', type: 'button', 'aria-expanded': 'false', 'data-testid': 'dash-adjustment-toggle' }, [
            UI.el('span', {}, 'Rincian Adjustment per jenis ledger: '),
            UI.el('strong', { class: b.net < 0 ? 'neg' : (b.net > 0 ? 'pos' : ''), 'data-testid': 'dash-adjustment-net' }, money(b.net)),
            UI.el('span', { class: 'dash-other-hint' }, ` · ${num(b.items.length)} jenis  ▾`),
        ]);
        const list = UI.el('div', { class: 'dash-other-list', 'data-testid': 'dash-adjustment-list', hidden: 'hidden' });
        if (!b.items.length) list.appendChild(UI.el('div', { class: 'dash-other-row is-zero' }, [UI.el('span', {}, 'Tidak ada adjustment / koreksi pada periode ini'), UI.el('span', {}, ''), UI.el('strong', {}, money(0))]));
        b.items.forEach((it) => {
            const clickable = it.tx_count > 0;
            const row = UI.el('div', { class: `dash-other-row${clickable ? ' dash-click' : ' is-zero'}`, role: clickable ? 'button' : null, tabindex: clickable ? '0' : null, 'data-testid': `dash-adj-${it.key}` }, [
                UI.el('span', {}, it.label),
                UI.el('span', { class: 'dash-other-tx' }, `${num(it.tx_count)} transaksi`),
                UI.el('strong', { class: it.value < 0 ? 'neg' : (it.value > 0 ? 'pos' : '') }, money(it.value)),
            ]);
            if (clickable) activate(row, () => openDetail({ type: `move_${it.key}`, title: `Adjustment — ${it.label}`, totalLabel: `GRAND TOTAL ${it.label.toUpperCase()}` }));
            list.appendChild(row);
        });
        toggle.addEventListener('click', () => {
            const open = list.hasAttribute('hidden');
            if (open) list.removeAttribute('hidden'); else list.setAttribute('hidden', 'hidden');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        wrap.appendChild(toggle);
        wrap.appendChild(list);
        return wrap;
    }

    function buildReconciliationNote(d) {
        const r = d.movement.reconciliation;
        const frag = UI.el('div', { class: 'dash-recon' });
        if (!r.ok) {
            frag.appendChild(UI.el('div', { class: 'alert alert-warning', 'data-testid': 'dash-recon-warning' },
                `Peringatan rekonsiliasi: Stok Awal + Stock IN − Stock OUT + Transfer IN − Transfer OUT + Adjustment berbeda ${money(r.identity_diff)} dari Stok Akhir`
                + (r.adjustment_explained === false ? `; Adjustment tidak seluruhnya terjelaskan per jenis ledger (selisih ${money(r.adjustment_explained_diff)})` : '') + '. Mohon dilaporkan.'));
        }
        if (r.batch_ok === false) {
            frag.appendChild(UI.el('div', { class: 'alert alert-warning', 'data-testid': 'dash-batch-warning' },
                `Stok Akhir (buku besar) berbeda ${money(r.batch_diff)} dari valuasi batch saat ini (${money(r.batch_on_hand)}).`));
        }
        return frag;
    }

    // ------------------------------------------------------- attention + lists
    const ATTN_STYLE = { out_of_stock: ['xcircle', 'red'], below_minimum: ['alert', 'orange'], dead_stock: ['clock', 'purple'], rusak: ['heart', 'pink'], expired: ['calendar', 'blue'] };
    function buildAttention(d) {
        const cards = d.attention.map((a) => {
            const [ic, tone] = ATTN_STYLE[a.key] || ['alert', 'orange'];
            const valueLine = a.key === 'out_of_stock' ? null : `Estimasi ${money(a.value || 0)}`;
            const el = UI.el('div', { class: `dash-card dash-attn tone-card-${tone} dash-click`, role: 'button', tabindex: '0', 'data-testid': `dash-attn-${a.key}`, title: a.hint }, [
                tile(ic, tone),
                UI.el('div', { class: 'dash-attn-main' }, [
                    UI.el('div', { class: 'dash-label' }, a.label),
                    UI.el('div', { class: 'dash-attn-count' }, num(a.sku_count)),
                    UI.el('div', { class: 'dash-sub' }, a.hint),
                    valueLine ? UI.el('div', { class: 'dash-attn-val' }, valueLine) : null,
                ]),
                chev(),
            ]);
            activate(el, () => runAction(a));
            return el;
        });
        return UI.el('div', { class: 'dash-section', 'data-testid': 'dash-attention' }, [
            UI.el('div', { class: 'dash-section-title' }, [icon('alert', 'dash-title-ico c-orange'), UI.el('span', {}, 'ITEM PERLU PERHATIAN')]),
            UI.el('div', { class: 'dash-attn-grid' }, cards),
        ]);
    }

    function runAction(a) {
        const act = a.action || {};
        if (act.type === 'stock_report') goStockReport(act.filters || {});
        else if (act.type === 'tab' && window.InvNav) window.InvNav.goToTab(act.tab);
        else if (act.type === 'detail') openDetail({ type: act.detail, title: `${a.label} — ${a.hint}`, totalLabel: `GRAND TOTAL ${a.label.toUpperCase()}` });
    }

    function buildActivity(d) {
        const a = d.today_activity;
        const mini = (testid, iconName, label, node, cls) => {
            const el = UI.el('div', { class: `dash-card dash-act ${cls} dash-click`, role: 'button', tabindex: '0', 'data-testid': testid, title: 'Buka History Transaksi' }, [
                tile(iconName, cls.replace('is-', '')),
                UI.el('div', { class: 'dash-act-main' }, [
                    UI.el('div', { class: 'dash-label' }, label),
                    UI.el('div', { class: 'dash-act-count' }, num(node.count)),
                    UI.el('div', { class: 'dash-sub' }, 'transaksi'),
                    UI.el('div', { class: 'dash-act-val' }, node.count > 0 ? money(node.value) : '—'),
                ]),
            ]);
            activate(el, () => window.InvNav && window.InvNav.goToTab('history-transaksi'));
            return el;
        };
        const recent = d.recent_activity.map((r) => {
            const tr = UI.el('tr', Object.assign({ 'data-testid': 'dash-recent-row' }, typeof TraceDrawer !== 'undefined' ? { class: 'dash-click', role: 'button', tabindex: '0' } : {}), [
                UI.el('td', {}, r.date.slice(0, 10) === d.today ? r.date.slice(11, 16) : `${r.date.slice(5, 10)} ${r.date.slice(11, 16)}`),
                UI.el('td', {}, [UI.el('span', { class: `dash-type t-${r.type.toLowerCase()}` }, r.type_label)]),
                UI.el('td', {}, r.reference_no),
                UI.el('td', { class: 'wrap' }, r.warehouse),
                UI.el('td', { class: 'text-right' }, num(r.item_count)),
                UI.el('td', { class: 'text-right' }, money(r.value)),
                UI.el('td', {}, [UI.el('span', { class: `dash-status ${r.status === 'POSTED' ? 'ok' : 'warn'}` }, r.status === 'POSTED' ? 'Selesai' : r.status)]),
            ]);
            if (typeof TraceDrawer !== 'undefined') activate(tr, () => TraceDrawer.openTransaction(r.id));
            return tr;
        });
        return UI.el('div', { class: 'dash-section', 'data-testid': 'dash-activity' }, [
            UI.el('div', { class: 'dash-section-title' }, [icon('activity', 'dash-title-ico c-green'), UI.el('span', {}, 'AKTIVITAS HARI INI'), UI.el('span', { class: 'dash-period-label' }, `(${d.today})`)]),
            UI.el('div', { class: 'dash-act-grid' }, [
                mini('dash-act-in', 'download', 'Stock IN', a.stock_in, 'is-in'),
                mini('dash-act-out', 'upload', 'Stock OUT', a.stock_out, 'is-out'),
                mini('dash-act-trf-out', 'truck', 'Transfer Keluar', a.transfer_out, 'is-trf'),
                mini('dash-act-trf-in', 'truck', 'Transfer Diterima', a.transfer_in, 'is-trfin'),
            ]),
            UI.el('div', { class: 'dash-table-wrap' }, [UI.el('table', { class: 'dash-table', 'data-testid': 'dash-recent' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Waktu', 'Jenis', 'No. Referensi', 'Gudang'].map((h) => UI.el('th', {}, h)).concat(['Jumlah Item', 'Nilai'].map((h) => UI.el('th', { class: 'text-right' }, h))).concat([UI.el('th', {}, 'Status')]))]),
                UI.el('tbody', {}, recent.length ? recent : [UI.el('tr', {}, [UI.el('td', { colspan: '7', class: 'dash-empty' }, 'Belum ada aktivitas')])]),
            ])]),
            (() => {
                const more = UI.el('button', { class: 'dash-link dash-more', type: 'button', 'data-testid': 'dash-more-activity' }, ['Lihat semua aktivitas ', icon('arrow')]);
                more.addEventListener('click', () => window.InvNav && window.InvNav.goToTab('history-transaksi'));
                return more;
            })(),
        ]);
    }

    function topTable(testid, title, ico, icoClass, linkLabel, onLink, headers, rows) {
        const head = UI.el('div', { class: 'dash-section-title' }, [UI.el('span', { class: 'dash-title-l' }, [icon(ico, `dash-title-ico ${icoClass}`), title])]);
        if (onLink) {
            const link = UI.el('button', { class: 'dash-link', type: 'button', 'data-testid': `${testid}-link` }, [`${linkLabel} `, icon('arrow')]);
            link.addEventListener('click', onLink);
            head.appendChild(link);
        }
        return UI.el('div', { class: 'dash-section', 'data-testid': testid }, [
            head,
            UI.el('div', { class: 'dash-table-wrap' }, [UI.el('table', { class: 'dash-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, headers.map(([h, right]) => UI.el('th', right ? { class: 'text-right' } : {}, h)))]),
                UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: String(headers.length), class: 'dash-empty' }, 'Tidak ada data')])]),
            ])]),
        ]);
    }

    function buildTopLow(d) {
        const rows = d.top_low_stock.map((r, i) => UI.el('tr', { 'data-testid': 'dash-low-row' }, [
            UI.el('td', {}, String(i + 1)), UI.el('td', {}, r.sku), UI.el('td', { class: 'wrap-sm' }, r.name),
            UI.el('td', { class: 'text-right' }, qty(r.qty)), UI.el('td', { class: 'text-right' }, qty(r.minimum)),
            UI.el('td', { class: 'text-right' }, [UI.el('span', { class: 'dash-gap' }, qty(r.gap))]),
        ]));
        return topTable('dash-top-low', 'TOP 5 STOK MENIPIS', 'bars', 'c-red', 'Lihat Semua', () => goStockReport({ status: 'CRITICAL' }),
            [['#'], ['SKU'], ['Nama Barang'], ['Stok', true], ['Min', true], ['Selisih', true]], rows);
    }

    function buildTopValue(d) {
        const rows = d.top_inventory_value.map((r, i) => UI.el('tr', { 'data-testid': 'dash-value-row' }, [
            UI.el('td', {}, String(i + 1)), UI.el('td', {}, r.sku), UI.el('td', { class: 'wrap-sm' }, r.name),
            UI.el('td', { class: 'text-right' }, qty(r.qty)), UI.el('td', { class: 'text-right' }, money(r.hpp)), UI.el('td', { class: 'text-right' }, money(r.value)),
        ]));
        return topTable('dash-top-value', 'TOP 5 NILAI STOK', 'layers', 'c-gold', 'Lihat Semua', () => goStockReport({}),
            [['#'], ['SKU'], ['Nama Barang'], ['Qty (base)', true], ['HPP', true], ['Nilai Stok', true]], rows);
    }

    function buildQuickActions() {
        const actions = [
            { label: 'Stock IN', ic: 'download', permission: 'TRANSACTION_IN_CREATE', tab: 'transaksi' },
            { label: 'Stock OUT', ic: 'upload', permission: 'TRANSACTION_OUT_CREATE', tab: 'transaksi' },
            { label: 'Buat Transfer', ic: 'truck', permission: 'WAREHOUSE_TRANSFER_MANAGE', tab: 'transfer' },
            { label: 'Mulai Stock Opname', ic: 'clipboard', permission: 'STOCK_OPNAME_MANAGE', tab: 'opname' },
        ].filter((a) => Auth.hasPermission(a.permission));
        if (!actions.length) return UI.el('div', {});
        return UI.el('div', { class: 'dash-section dash-quick-row', 'data-testid': 'dash-quick' }, [
            UI.el('div', { class: 'dash-section-title' }, [icon('zap', 'dash-title-ico c-gold'), UI.el('span', {}, 'Quick Actions')]),
            UI.el('div', { class: 'quick-actions' }, actions.map((a) => {
                const b = UI.el('button', { class: 'btn btn-primary dash-qa', type: 'button' }, [icon(a.ic), ` ${a.label}`]);
                b.addEventListener('click', () => document.querySelector(`[data-tab="${a.tab}"]`)?.click());
                return b;
            })),
        ]);
    }

    // ============================================================ detail drawer
    // ONE reusable drawer for every card. Server-side search / category /
    // warehouse / pagination; the footer shows the GRAND TOTAL of all filtered
    // rows (every page) and, when filtered, the unfiltered card total too.
    const COLUMNS = {
        balance: (label) => [['SKU', 'sku'], ['Nama Barang', 'name'], ['Kategori', 'category'], ['Gudang', 'warehouse'], ['Satuan Base', 'unit'], [label, 'qty', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money']],
        stock_in: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Supplier', 'supplier'], ['Gudang', 'warehouse'], ['Qty', 'qty', 'qty'], ['Satuan', 'unit'], ['Qty Base', 'qty_base', 'qty'], ['Harga / HPP', 'hpp', 'money'], ['Nilai', 'value', 'money']],
        stock_out: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Tujuan / Keterangan', 'destination'], ['Qty', 'qty', 'qty'], ['Satuan', 'unit'], ['Qty Base', 'qty_base', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money']],
        other: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['Jenis', 'type_label'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Qty Base', 'qty_base', 'qty'], ['Nilai', 'value', 'smoney']],
        pending_transfers: [['No', 'reference_no'], ['Tanggal Kirim', 'date', 'date'], ['Dari', 'from'], ['Ke', 'to'], ['Jumlah Item', 'item_count', 'int'], ['Nilai', 'value', 'money']],
        active_opname: [['No. Sesi', 'reference_no'], ['Tanggal', 'date'], ['Gudang', 'warehouse'], ['Status', 'status'], ['Model', 'counting_model'], ['Jumlah Item', 'item_count', 'int']],
        rusak: [['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Sesi SO', 'session'], ['Satuan', 'unit'], ['Qty Rusak', 'qty', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money'], ['Catatan', 'note']],
        deadstock: [['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Sesi SO', 'session'], ['Satuan', 'unit'], ['Qty Dead Stock', 'qty', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money'], ['Catatan', 'note']],
    };

    function columnsFor(type, kind) {
        if (kind === 'balance') return COLUMNS.balance(type === 'opening_stock' ? 'Qty Awal' : (type === 'closing_stock' ? 'Qty Akhir' : 'Qty'));
        if (type === 'stock_in' || type === 'purchase_in') return COLUMNS.stock_in;
        if (type === 'adjustment') return COLUMNS.other;
        if (type === 'stock_out') return COLUMNS.stock_out;
        if (type.startsWith('move_')) return COLUMNS.other;
        return COLUMNS[kind] || COLUMNS.other;
    }

    function cell(col, row) {
        const [, key, fmt] = col;
        const v = row[key];
        if (isNil(v) || v === '') return document.createTextNode('—');
        if (fmt === 'qty') return document.createTextNode(qty(v));
        if (fmt === 'int') return document.createTextNode(num(v));
        if (fmt === 'money') return document.createTextNode(money(v));
        if (fmt === 'smoney') return UI.el('span', { class: v < 0 ? 'neg' : (v > 0 ? 'pos' : '') }, money(v));
        if (fmt === 'date') return document.createTextNode(String(v).slice(0, 16));
        if (key === 'reference_no' && row.status === 'VOID') return UI.el('span', {}, [`${v} `, UI.el('span', { class: 'dash-status warn' }, 'VOID')]);
        return document.createTextNode(String(v));
    }
    const isRightCol = (col) => ['qty', 'int', 'money', 'smoney'].includes(col[2]);

    function lockPage(on) {
        document.documentElement.classList.toggle('dash-scroll-lock', on);
        document.body.classList.toggle('dash-scroll-lock', on);
    }

    function openDetail(spec) {
        const ds = { type: spec.type, q: '', category: '', rowWarehouse: '', page: 1, perPage: 50, seq: 0 };
        const title = UI.el('div', { 'data-dash-title': '1' }, [
            UI.el('div', { class: 'dash-drawer-title', 'data-testid': 'dash-drawer-title' }, spec.title),
            UI.el('div', { class: 'dash-sub', 'data-testid': 'dash-drawer-sub' }, `${scopeLabel()} · ${periodShort()}`),
        ]);
        const toolbar = UI.el('div', { class: 'dash-toolbar' });
        const tableHost = UI.el('div', { 'data-testid': 'dash-drawer-table-host' });
        const totalBar = UI.el('div', { class: 'dash-grand', 'data-testid': 'dash-drawer-grand' });
        const body = UI.el('div', { class: 'dash-drawer-wrap' }, [toolbar, tableHost, totalBar]);

        Drawer.open({ title, render: (b) => b.appendChild(body) });
        const panel = document.querySelector('.drawer');
        if (panel) {
            panel.classList.add('drawer-dash');
            lockPage(true);
            const obs = new MutationObserver(() => {
                if (!panel.classList.contains('open') || !panel.querySelector('[data-dash-title]')) {
                    panel.classList.remove('drawer-dash');
                    lockPage(false);
                    obs.disconnect();
                }
            });
            obs.observe(panel, { attributes: true, attributeFilter: ['class'], childList: true });
        }

        const search = UI.el('input', { type: 'text', placeholder: 'Cari SKU / nama barang / no. referensi...', 'data-testid': 'dash-drawer-search', style: 'flex:1 1 240px; width:auto;' });
        let timer = null;
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { ds.q = search.value.trim(); ds.page = 1; fetchPage(); }, 300); });
        toolbar.appendChild(search);

        const needsFilters = ['opening_stock', 'closing_stock', 'current_stock', 'stock_in', 'adjustment', 'stock_out', 'rusak', 'deadstock'].includes(spec.type) || spec.type.startsWith('move_');
        if (needsFilters && spec.type !== 'rusak' && spec.type !== 'deadstock') {
            const cats = (typeof Master !== 'undefined' && Master.categories) ? Master.categories() : [];
            const catSel = UI.el('select', { 'data-testid': 'dash-drawer-category', style: 'flex:0 0 200px; width:200px;' }, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(cats.map((c) => UI.el('option', { value: String(c.id) }, c.name))));
            catSel.addEventListener('change', () => { ds.category = catSel.value; ds.page = 1; fetchPage(); });
            toolbar.appendChild(catSel);
        }
        if (needsFilters && !state.warehouseId) {
            const whs = Master.warehouses();
            const whSel = UI.el('select', { 'data-testid': 'dash-drawer-warehouse', style: 'flex:0 0 200px; width:200px;' }, [UI.el('option', { value: '' }, 'Semua Gudang')].concat(whs.map((w) => UI.el('option', { value: String(w.id) }, w.name))));
            whSel.addEventListener('change', () => { ds.rowWarehouse = whSel.value; ds.page = 1; fetchPage(); });
            toolbar.appendChild(whSel);
        }
        const per = UI.el('select', { 'data-testid': 'dash-drawer-perpage', style: 'flex:0 0 110px; width:110px;', 'aria-label': 'Baris per halaman' }, [25, 50, 100].map((n) => UI.el('option', Object.assign({ value: String(n) }, n === 50 ? { selected: 'selected' } : {}), `${n} / hal`)));
        per.addEventListener('change', () => { ds.perPage = Number(per.value); ds.page = 1; fetchPage(); });
        toolbar.appendChild(per);

        function stillOpen() {
            const p = document.querySelector('.drawer');
            return !!(p && p.classList.contains('open') && p.querySelector('[data-dash-title]') === title);
        }

        async function fetchPage() {
            const seq = ++ds.seq;
            tableHost.innerHTML = '';
            tableHost.appendChild(UI.el('div', { class: 'alert alert-info', 'data-testid': 'dash-drawer-loading' }, 'Memuat rincian...'));
            try {
                const params = Object.assign(baseParams(), { type: ds.type, q: ds.q, category_id: ds.category, row_warehouse_id: ds.rowWarehouse, page: ds.page, per_page: ds.perPage });
                const data = await getJson('/dashboard/inventory/detail', params);
                if (seq !== ds.seq || !stillOpen()) return;
                renderRows(data);
            } catch (err) {
                if (seq !== ds.seq || !stillOpen()) return;
                tableHost.innerHTML = '';
                const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'dash-drawer-retry' }, 'Coba lagi');
                retry.addEventListener('click', fetchPage);
                tableHost.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'dash-drawer-error' }, `Gagal memuat rincian: ${err.message}`));
                tableHost.appendChild(retry);
            }
        }

        function renderRows(data) {
            const cols = columnsFor(ds.type, data.kind);
            tableHost.innerHTML = '';
            const filtered = !!(ds.q || ds.category || ds.rowWarehouse);
            const thead = UI.el('thead', {}, [UI.el('tr', {}, cols.map((c) => UI.el('th', isRightCol(c) ? { class: 'text-right' } : {}, c[0])))]);
            const trs = data.rows.map((r) => UI.el('tr', { 'data-testid': 'dash-drawer-row' }, cols.map((c) => UI.el('td', isRightCol(c) ? { class: 'text-right' } : {}, [cell(c, r)]))));
            if (!trs.length) trs.push(UI.el('tr', {}, [UI.el('td', { colspan: String(cols.length), class: 'dash-empty', 'data-testid': 'dash-drawer-empty' }, filtered ? 'Tidak ada baris yang cocok.' : 'Tidak ada data untuk periode / gudang ini.')]));
            tableHost.appendChild(UI.el('div', { class: 'dash-table-wrap' }, [UI.el('table', { class: 'dash-table', 'data-testid': 'dash-drawer-table' }, [thead, UI.el('tbody', {}, trs)])]));

            const p = data.pagination;
            const from = p.total === 0 ? 0 : (p.page - 1) * p.per_page + 1;
            const to = Math.min(p.total, p.page * p.per_page);
            const prev = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'dash-drawer-prev' }, '‹ Sebelumnya');
            const next = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'dash-drawer-next' }, 'Berikutnya ›');
            if (p.page <= 1) prev.setAttribute('disabled', 'disabled');
            if (p.page >= p.total_pages) next.setAttribute('disabled', 'disabled');
            prev.addEventListener('click', () => { ds.page -= 1; fetchPage(); });
            next.addEventListener('click', () => { ds.page += 1; fetchPage(); });
            tableHost.appendChild(UI.el('div', { class: 'dash-pager', 'data-testid': 'dash-drawer-pager' }, [
                UI.el('span', {}, p.total === 0 ? 'Tidak ada baris' : `Baris ${num(from)}–${num(to)} dari ${num(p.total)} · halaman ${p.page}/${p.total_pages}`),
                UI.el('span', { style: 'display:flex; gap:8px;' }, [prev, next]),
            ]));

            totalBar.innerHTML = '';
            const g = data.grand_total;
            if (!isNil(g.value)) {
                totalBar.appendChild(UI.el('div', { class: 'dash-grand-row' }, [
                    UI.el('span', { class: 'dash-grand-label' }, `${spec.totalLabel}${filtered ? ' (TERFILTER)' : ''}`),
                    UI.el('strong', { class: 'dash-grand-value', 'data-testid': 'dash-drawer-grand-value' }, money(g.value)),
                ]));
                if (filtered) {
                    totalBar.appendChild(UI.el('div', { class: 'dash-sub', 'data-testid': 'dash-drawer-card-total' }, `Total kartu (tanpa filter): ${money(data.card_total.value)}`));
                }
                totalBar.appendChild(UI.el('div', { class: 'dash-sub' }, `${num(g.row_count)} baris${g.sku_count ? ` · ${num(g.sku_count)} SKU` : ''}`));
            } else {
                totalBar.appendChild(UI.el('div', { class: 'dash-sub' }, `${num(g.row_count)} baris`));
            }
        }

        fetchPage();
    }

    function scopeLabel() {
        const sel = state.root && state.root.querySelector('#dash-wh');
        return sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : 'Semua Gudang';
    }
    function periodShort() {
        const d = state.data;
        if (!d) return '';
        const p = d.period;
        return p.start_date === p.end_date ? p.end_date : `${p.start_date} s/d ${p.end_date}`;
    }

    return { render, _state: state };
})();
