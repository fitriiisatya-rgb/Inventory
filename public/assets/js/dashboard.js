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
 * The four stock-movement cards (Stok Awal / Pembelian-Stock IN / Barang Keluar-
 * Stock OUT / Stok Akhir) are nominal Rupiah — mixed-unit quantities are
 * deliberately never summed. "Pergerakan lain" discloses every remaining ledger
 * movement so the formula reconciles (see DashboardInventoryService).
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

        const refresh = UI.el('button', { class: 'btn btn-primary btn-sm', type: 'button', 'data-testid': 'dash-refresh' }, '⟳ Refresh');
        refresh.addEventListener('click', () => load());

        return UI.el('div', { class: 'dash-top' }, [
            UI.el('div', { class: 'dash-filter' }, [UI.el('label', { for: 'dash-wh' }, 'Gudang:'), sel]),
            UI.el('div', { class: 'dash-updated' }, [
                UI.el('span', { id: 'dash-updated', 'data-testid': 'dash-updated' }, 'Data diperbarui: —'),
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
        const grid = UI.el('div', { class: 'dash-cols' }, [buildAttention(d), buildActivity(d), buildTopLow(d), buildTopValue(d)]);
        wrap.appendChild(grid);
        wrap.appendChild(buildQuickActions());
        return wrap;
    }

    // ----------------------------------------------------------------- KPI row
    function kpi(testid, icon, label, valueNode, sub, onClick) {
        const card = UI.el('div', { class: 'dash-card dash-kpi dash-click', role: 'button', tabindex: '0', 'data-testid': testid, title: 'Klik untuk lihat rincian' }, [
            UI.el('div', { class: 'dash-kpi-icon' }, icon),
            UI.el('div', { class: 'dash-kpi-main' }, [
                UI.el('div', { class: 'dash-label' }, label),
                UI.el('div', { class: 'dash-value', 'data-testid': `${testid}-value` }, [valueNode]),
                sub ? UI.el('div', { class: 'dash-sub' }, sub) : null,
            ]),
        ]);
        activate(card, onClick);
        return card;
    }

    // Rupiah figures are never wrapped or clipped: shrink the font stepwise until
    // the figure fits its card (min 0.62rem); the full value stays in the title.
    function fitValues(root) {
        const scope = root || document;
        // size each figure to its own card first, then give every card of the same
        // row the SMALLEST size so the row reads as one consistent set
        [['.dash-kpis'], ['.dash-mv-cards']].forEach(([sel]) => {
            scope.querySelectorAll(sel).forEach((group) => {
                const vals = Array.from(group.querySelectorAll('.dash-value'));
                let smallest = Infinity;
                vals.forEach((v) => {
                    v.style.fontSize = '';
                    v.title = v.textContent;
                    let px = parseFloat(getComputedStyle(v).fontSize);
                    const base = px;
                    let guard = 60;
                    while (v.scrollWidth > v.clientWidth + 0.5 && px > 10 && guard-- > 0) {
                        px -= 0.5;
                        v.style.fontSize = `${px}px`;
                    }
                    smallest = Math.min(smallest, px);
                    v.dataset.base = String(base);
                });
                vals.forEach((v) => { if (smallest < parseFloat(v.dataset.base)) v.style.fontSize = `${smallest}px`; });
            });
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
            kpi('dash-kpi-sku', '📦', 'Total SKU Aktif', document.createTextNode(num(s.total_sku)), `${num(s.sku_with_stock)} SKU memiliki stok`,
                () => goStockReport({})),
            kpi('dash-kpi-value', '💰', 'Nilai Stok', document.createTextNode(money(sv.total)), stockSub,
                () => openDetail({ type: 'current_stock', title: 'Nilai Stok Saat Ini', totalLabel: 'GRAND TOTAL NILAI STOK (ON-HAND)' })),
            kpi('dash-kpi-transfer', '🚚', 'Transfer Pending', document.createTextNode(num(s.pending_transfers)), 'Dalam proses',
                () => openDetail({ type: 'pending_transfers', title: 'Transfer Pending', totalLabel: 'TOTAL NILAI DALAM PERJALANAN' })),
            kpi('dash-kpi-opname', '📋', 'Stock Opname Aktif', document.createTextNode(num(s.active_opname)), 'Sedang berjalan',
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
        { key: 'opening_stock', type: 'opening_stock', label: 'Stok Awal', cls: 'is-open', icon: '📦', total: 'GRAND TOTAL STOK AWAL', title: 'Rincian Stok Awal', sub: (m) => `${num(m.sku_count)} SKU` },
        { key: 'purchase_in', type: 'purchase_in', label: 'Pembelian / Stock IN', cls: 'is-in', icon: '🛒', total: 'GRAND TOTAL PEMBELIAN / STOCK IN', title: 'Rincian Pembelian / Stock IN', sub: (m) => `${num(m.tx_count)} transaksi` },
        { key: 'stock_out', type: 'stock_out', label: 'Barang Keluar / Stock OUT', cls: 'is-out', icon: '📤', total: 'GRAND TOTAL BARANG KELUAR / STOCK OUT', title: 'Rincian Barang Keluar / Stock OUT', sub: (m) => `${num(m.tx_count)} transaksi` },
        { key: 'closing_stock', type: 'closing_stock', label: 'Stok Akhir', cls: 'is-close', icon: '🧊', total: 'GRAND TOTAL STOK AKHIR', title: 'Rincian Stok Akhir', sub: (m) => `${num(m.sku_count)} SKU` },
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
            UI.el('span', {}, '🔁 RINGKASAN PERGERAKAN STOK'),
            UI.el('span', { class: 'dash-period-label', 'data-testid': 'dash-period-label' }, `(PERIODE: ${periodLabel(d)})`),
        ]);
        section.appendChild(UI.el('div', { class: 'dash-section-head' }, [title, buildPeriodControls(d)]));

        if (m.note) section.appendChild(UI.el('div', { class: 'dash-note', 'data-testid': 'dash-movement-note' }, m.note));

        const cards = UI.el('div', { class: 'dash-mv-cards' }, MOVEMENT_CARDS.map((c) => {
            const node = m[c.key];
            const el = UI.el('div', { class: `dash-card dash-mv ${c.cls} dash-click`, role: 'button', tabindex: '0', 'data-testid': `dash-mv-${c.key}`, title: 'Klik untuk lihat rincian' }, [
                UI.el('div', { class: 'dash-mv-icon' }, c.icon),
                UI.el('div', { class: 'dash-mv-main' }, [
                    UI.el('div', { class: 'dash-label' }, c.label),
                    UI.el('div', { class: 'dash-value dash-mv-value', 'data-testid': `dash-mv-${c.key}-value` }, money(node.value)),
                    UI.el('div', { class: 'dash-sub' }, c.sub(node)),
                ]),
            ]);
            activate(el, () => openDetail({ type: c.type, title: c.title, totalLabel: c.total, columnsFor: c.key }));
            return el;
        }));
        section.appendChild(cards);

        section.appendChild(buildOtherMovements(d));
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

    function buildOtherMovements(d) {
        const o = d.movement.other_movements;
        const wrap = UI.el('div', { class: 'dash-other', 'data-testid': 'dash-other' });
        const toggle = UI.el('button', { class: 'dash-other-toggle', type: 'button', 'aria-expanded': 'false', 'data-testid': 'dash-other-toggle' }, [
            UI.el('span', {}, 'Pergerakan lain: '),
            UI.el('strong', { class: o.net < 0 ? 'neg' : (o.net > 0 ? 'pos' : ''), 'data-testid': 'dash-other-net' }, money(o.net)),
            UI.el('span', { class: 'dash-other-hint' }, ' · Stok Awal + Pembelian − Barang Keluar ± Pergerakan lain = Stok Akhir  ▾'),
        ]);
        const list = UI.el('div', { class: 'dash-other-list', 'data-testid': 'dash-other-list', hidden: 'hidden' });
        o.items.forEach((it) => {
            const clickable = it.tx_count > 0;
            const row = UI.el('div', { class: `dash-other-row${clickable ? ' dash-click' : ' is-zero'}`, role: clickable ? 'button' : null, tabindex: clickable ? '0' : null, 'data-testid': `dash-other-${it.key}` }, [
                UI.el('span', {}, it.label),
                UI.el('span', { class: 'dash-other-tx' }, `${num(it.tx_count)} transaksi`),
                UI.el('strong', { class: it.value < 0 ? 'neg' : (it.value > 0 ? 'pos' : '') }, money(it.value)),
            ]);
            if (clickable) {
                activate(row, () => openDetail({ type: `move_${it.key}`, title: `Pergerakan Lain — ${it.label}`, totalLabel: `GRAND TOTAL ${it.label.toUpperCase()}` }));
            }
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
                `Peringatan rekonsiliasi: Stok Awal + Pembelian − Keluar ± Lain berbeda ${money(r.identity_diff)} dari Stok Akhir. Mohon dilaporkan.`));
        }
        if (r.batch_ok === false) {
            frag.appendChild(UI.el('div', { class: 'alert alert-warning', 'data-testid': 'dash-batch-warning' },
                `Stok Akhir (buku besar) berbeda ${money(r.batch_diff)} dari valuasi batch saat ini (${money(r.batch_on_hand)}).`));
        }
        return frag;
    }

    // ------------------------------------------------------- attention + lists
    function buildAttention(d) {
        const rows = d.attention.map((a) => {
            const tr = UI.el('tr', { class: 'dash-click', role: 'button', tabindex: '0', 'data-testid': `dash-attn-${a.key}`, title: a.hint }, [
                UI.el('td', { class: 'wrap' }, [UI.el('div', { class: 'dash-attn-label' }, a.label), UI.el('div', { class: 'dash-sub' }, a.hint)]),
                UI.el('td', { class: 'text-right' }, num(a.sku_count)),
                UI.el('td', { class: 'text-right' }, a.value ? money(a.value) : (a.key === 'out_of_stock' ? '—' : money(0))),
                UI.el('td', { class: 'text-right dash-chev' }, '›'),
            ]);
            activate(tr, () => runAction(a));
            return tr;
        });
        return UI.el('div', { class: 'dash-section', 'data-testid': 'dash-attention' }, [
            UI.el('div', { class: 'dash-section-title' }, '⚠️ PERLU PERHATIAN'),
            UI.el('div', { class: 'dash-table-wrap' }, [UI.el('table', { class: 'dash-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, [UI.el('th', {}, 'Status'), UI.el('th', { class: 'text-right' }, 'Jumlah SKU'), UI.el('th', { class: 'text-right' }, 'Estimasi Nilai'), UI.el('th', { class: 'text-right' }, 'Aksi')])]),
                UI.el('tbody', {}, rows),
            ])]),
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
        const mini = (testid, icon, label, node, cls) => {
            const el = UI.el('div', { class: `dash-card dash-act ${cls} dash-click`, role: 'button', tabindex: '0', 'data-testid': testid, title: 'Buka History Transaksi' }, [
                UI.el('div', { class: 'dash-act-icon' }, icon),
                UI.el('div', {}, [
                    UI.el('div', { class: 'dash-label' }, label),
                    UI.el('div', { class: 'dash-act-count' }, `${num(node.count)} transaksi`),
                    UI.el('div', { class: 'dash-sub' }, node.count > 0 ? money(node.value) : '—'),
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
            UI.el('div', { class: 'dash-section-title' }, [UI.el('span', {}, '📈 AKTIVITAS HARI INI'), UI.el('span', { class: 'dash-period-label' }, `(${d.today})`)]),
            UI.el('div', { class: 'dash-act-grid' }, [
                mini('dash-act-in', '📥', 'Stock IN', a.stock_in, 'is-in'),
                mini('dash-act-out', '📤', 'Stock OUT', a.stock_out, 'is-out'),
                mini('dash-act-trf-out', '🚚', 'Transfer Keluar', a.transfer_out, 'is-trf'),
                mini('dash-act-trf-in', '🚛', 'Transfer Diterima', a.transfer_in, 'is-trfin'),
            ]),
            UI.el('div', { class: 'dash-table-wrap' }, [UI.el('table', { class: 'dash-table', 'data-testid': 'dash-recent' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Waktu', 'Jenis', 'No. Referensi', 'Gudang'].map((h) => UI.el('th', {}, h)).concat(['Jumlah Item', 'Nilai'].map((h) => UI.el('th', { class: 'text-right' }, h))).concat([UI.el('th', {}, 'Status')]))]),
                UI.el('tbody', {}, recent.length ? recent : [UI.el('tr', {}, [UI.el('td', { colspan: '7', class: 'dash-empty' }, 'Belum ada aktivitas')])]),
            ])]),
        ]);
    }

    function topTable(testid, title, linkLabel, onLink, headers, rows) {
        const head = UI.el('div', { class: 'dash-section-title' }, [UI.el('span', {}, title)]);
        if (onLink) {
            const link = UI.el('button', { class: 'dash-link', type: 'button', 'data-testid': `${testid}-link` }, `${linkLabel} →`);
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
            UI.el('td', {}, String(i + 1)), UI.el('td', {}, r.sku), UI.el('td', {}, r.name),
            UI.el('td', { class: 'text-right' }, qty(r.qty)), UI.el('td', { class: 'text-right' }, qty(r.minimum)),
            UI.el('td', { class: 'text-right' }, [UI.el('span', { class: 'dash-gap' }, qty(r.gap))]),
        ]));
        return topTable('dash-top-low', '🔻 TOP 5 STOK MENIPIS', 'Lihat Semua', () => goStockReport({ status: 'CRITICAL' }),
            [['#'], ['SKU'], ['Nama Barang'], ['Stok', true], ['Min', true], ['Selisih', true]], rows);
    }

    function buildTopValue(d) {
        const rows = d.top_inventory_value.map((r, i) => UI.el('tr', { 'data-testid': 'dash-value-row' }, [
            UI.el('td', {}, String(i + 1)), UI.el('td', {}, r.sku), UI.el('td', {}, r.name),
            UI.el('td', { class: 'text-right' }, qty(r.qty)), UI.el('td', { class: 'text-right' }, money(r.hpp)), UI.el('td', { class: 'text-right' }, money(r.value)),
        ]));
        return topTable('dash-top-value', '💎 TOP 5 NILAI STOK', 'Lihat Semua', () => goStockReport({}),
            [['#'], ['SKU'], ['Nama Barang'], ['Qty (base)', true], ['HPP', true], ['Nilai Stok', true]], rows);
    }

    function buildQuickActions() {
        const actions = [
            { label: '📥 Stock IN', permission: 'TRANSACTION_IN_CREATE', tab: 'transaksi' },
            { label: '📤 Stock OUT', permission: 'TRANSACTION_OUT_CREATE', tab: 'transaksi' },
            { label: '🚚 Buat Transfer', permission: 'WAREHOUSE_TRANSFER_MANAGE', tab: 'transfer' },
            { label: '📋 Mulai Stock Opname', permission: 'STOCK_OPNAME_MANAGE', tab: 'opname' },
        ].filter((a) => Auth.hasPermission(a.permission));
        if (!actions.length) return UI.el('div', {});
        return UI.el('div', { class: 'dash-section dash-quick-row', 'data-testid': 'dash-quick' }, [
            UI.el('div', { class: 'dash-section-title' }, '⚡ Quick Actions'),
            UI.el('div', { class: 'quick-actions' }, actions.map((a) => {
                const b = UI.el('button', { class: 'btn btn-primary btn-sm', type: 'button' }, a.label);
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
        purchase_in: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Supplier', 'supplier'], ['Gudang', 'warehouse'], ['Qty', 'qty', 'qty'], ['Satuan', 'unit'], ['Qty Base', 'qty_base', 'qty'], ['Harga / HPP', 'hpp', 'money'], ['Nilai', 'value', 'money']],
        stock_out: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Tujuan / Keterangan', 'destination'], ['Qty', 'qty', 'qty'], ['Satuan', 'unit'], ['Qty Base', 'qty_base', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money']],
        other: [['Tanggal', 'date', 'date'], ['No Referensi', 'reference_no'], ['Jenis', 'type_label'], ['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Qty Base', 'qty_base', 'qty'], ['Nilai', 'value', 'smoney']],
        pending_transfers: [['No', 'reference_no'], ['Tanggal Kirim', 'date', 'date'], ['Dari', 'from'], ['Ke', 'to'], ['Jumlah Item', 'item_count', 'int'], ['Nilai', 'value', 'money']],
        active_opname: [['No. Sesi', 'reference_no'], ['Tanggal', 'date'], ['Gudang', 'warehouse'], ['Status', 'status'], ['Model', 'counting_model'], ['Jumlah Item', 'item_count', 'int']],
        rusak: [['SKU', 'sku'], ['Nama Barang', 'name'], ['Gudang', 'warehouse'], ['Sesi SO', 'session'], ['Satuan', 'unit'], ['Qty Rusak', 'qty', 'qty'], ['HPP', 'hpp', 'money'], ['Nilai', 'value', 'money'], ['Catatan', 'note']],
    };

    function columnsFor(type, kind) {
        if (kind === 'balance') return COLUMNS.balance(type === 'opening_stock' ? 'Qty Awal' : (type === 'closing_stock' ? 'Qty Akhir' : 'Qty'));
        if (type === 'purchase_in') return COLUMNS.purchase_in;
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

        const needsFilters = ['opening_stock', 'closing_stock', 'current_stock', 'purchase_in', 'stock_out', 'rusak'].includes(spec.type) || spec.type.startsWith('move_');
        if (needsFilters && spec.type !== 'rusak') {
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
