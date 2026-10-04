/**
 * Laporan > Pergerakan Stok Harian (redesign).
 *
 * Every number comes from the API (MovementDailyReportService, built on the real ledger); this file only renders it:
 *   filter bar (+ Nominal / Kuantitas toggle) -> KPI cards -> daily chart -> daily table -> "Rincian Per Barang" for the selected
 *   date -> per-item transaction trail (drawer). Reads only (GET); no write request is ever issued from this screen.
 *
 * Qty mode never adds quantities of different units: cards show SKU / transaction counts and quantities grouped BY UNIT, the chart
 * plots quantity only when the filters resolve to ONE item, and the item table keeps each item's own unit.
 * The previous report's historical-import disclosure (inventory_effect = 0, reporting only) is kept below the daily table.
 */
const ReportMovement = (() => {
    let S = null; // screen state
    const $ = (id) => document.getElementById(id);

    function today() { return new Date().toISOString().slice(0, 10); }
    function isoShift(days) { const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); }
    function monthRange() {
        const now = new Date();
        const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        return { start: fmt(new Date(now.getFullYear(), now.getMonth(), 1)), end: fmt(new Date(now.getFullYear(), now.getMonth() + 1, 0)) };
    }
    function freshState() {
        const r = monthRange();
        return { start: r.start, end: r.end, warehouse: '', category: '', q: '', mode: 'nominal', overview: null, selected: null, dayPage: 1, dayPerPage: 25, dailyPage: 1, dailyPerPage: 15, itemQ: '', itemCat: '', itemMove: '', movedOnly: false, sort: 'sku', dir: 'asc', items: null };
    }

    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(`${v}T00:00:00`);
        return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    const fmtTs = (v) => {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? v : d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };
    const rp = (v) => (v === null || v === undefined ? '—' : UI.formatMoney(v));
    const qn = (v) => (v === null || v === undefined ? '—' : UI.formatNumber(v, 3));
    const signed = (v) => { const n = Number(v) || 0; return (n > 0 ? '+' : '') + UI.formatMoney(n); };
    const unitsText = (list) => (list && list.length ? list.map((u) => `${u.unit} ${UI.formatNumber(u.qty, 3)}`).join(' · ') : '—');

    function params(extra) {
        return { start_date: S.start, end_date: S.end, warehouse_id: S.warehouse || undefined, category_id: S.category || undefined, q: S.q || undefined, ...extra };
    }

    // ------------------------------------------------------------------ render
    function render(container) {
        S = freshState();
        container.innerHTML = '';
        container.classList.add('mvr');
        const exportWrap = UI.el('div', { class: 'mvr-export' });
        const exportBtn = UI.el('button', { class: 'btn btn-success mvr-export-btn', 'data-testid': 'mvr-export', type: 'button', 'aria-haspopup': 'true' }, '⬇ Export CSV ▾');
        const menu = UI.el('div', { class: 'mvr-export-menu', hidden: 'hidden' }, [
            ['summary', 'Ringkasan harian (1 baris per tanggal)'], ['detail', 'Detail per barang (1 baris per barang/tanggal)'], ['transactions', 'Transaksi (1 baris per mutasi ledger)'],
        ].map(([kind, label]) => {
            const b = UI.el('button', { type: 'button', class: 'mvr-export-item', 'data-testid': `mvr-export-${kind}` }, label);
            b.addEventListener('click', () => { menu.hidden = true; window.open(exportUrl(kind), '_blank'); });
            return b;
        }));
        exportBtn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
        document.addEventListener('click', () => { menu.hidden = true; });
        exportWrap.appendChild(exportBtn);
        exportWrap.appendChild(menu);
        container.appendChild(UI.el('div', { class: 'mvr-head' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'mvr-title' }, 'Pergerakan Stok Harian'),
                UI.el('p', { class: 'mvr-desc' }, 'Saldo Awal, Barang Masuk, Barang Keluar, Adjustment / Pergerakan Lain, dan Saldo Akhir per hari — dari ledger inventory.'),
            ]),
            exportWrap,
        ]));
        container.appendChild(buildFilterCard());
        container.appendChild(UI.el('div', { id: 'mvr-banner' }));
        container.appendChild(UI.el('div', { id: 'mvr-kpis', class: 'mvr-kpis', 'data-testid': 'mvr-kpis' }));
        container.appendChild(UI.el('div', { id: 'mvr-chart-card', class: 'mvr-card' }));
        container.appendChild(UI.el('div', { id: 'mvr-daily-card', class: 'mvr-card' }));
        container.appendChild(UI.el('div', { id: 'mvr-items-card', class: 'mvr-card', style: 'display:none;' }));
        container.appendChild(UI.el('div', { id: 'mvr-historical' }));
        container.appendChild(UI.el('div', { class: 'mvr-legend' },
            'Barang Masuk = pembelian (Stock IN) [+ Transfer IN bila satu gudang dipilih]. Barang Keluar = Stock OUT [+ Transfer OUT bila satu gudang dipilih]. '
            + 'Adjustment / Pergerakan Lain = koreksi (termasuk Stock Opname), saldo awal baru, pembalikan void, produksi, dan — pada "Semua Gudang" — selisih transfer internal yang masih di perjalanan. '
            + 'Transfer antar gudang internal tidak dihitung sebagai pembelian/pemakaian pada "Semua Gudang". Nilai memakai biaya yang tercatat di ledger (FIFO), bukan harga hari ini.'));
        load();
    }

    function exportUrl(kind) {
        return InvApi.movementExportUrl(params({ kind, mode: S.mode }));
    }

    function buildFilterCard() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const catOptions = Master.categories().filter((c) => c.is_active).map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
        const isStock = Auth.user() && Auth.user().role_code === 'STOCK';
        const card = UI.el('div', { class: 'mvr-card mvr-filters' }, [
            UI.el('div', { class: 'mvr-filter-grid', html: `
                <div class="mvr-f"><label>Periode Tanggal</label><div class="mvr-dates"><input type="date" id="mvr-start" data-testid="mvr-start" value="${S.start}"><span>—</span><input type="date" id="mvr-end" data-testid="mvr-end" value="${S.end}"></div></div>
                <div class="mvr-f"><label>Gudang</label><select id="mvr-wh" data-testid="mvr-wh" ${isStock ? 'disabled' : ''}><option value="">Semua Gudang</option>${whOptions}</select></div>
                <div class="mvr-f"><label>Kategori</label><select id="mvr-cat" data-testid="mvr-cat"><option value="">Semua Kategori</option>${catOptions}</select></div>
                <div class="mvr-f"><label>Barang</label><input type="text" id="mvr-q" data-testid="mvr-q" placeholder="Cari kode atau nama barang…" autocomplete="off"></div>
            ` }),
            UI.el('div', { class: 'mvr-filter-row2' }, [
                UI.el('div', { class: 'mvr-toggle-wrap' }, [
                    UI.el('span', { class: 'mvr-toggle-label' }, 'Tampilan'),
                    UI.el('div', { class: 'mvr-seg', role: 'group', 'aria-label': 'Tampilan data' }, [
                        UI.el('button', { type: 'button', class: 'mvr-seg-btn on', 'data-mode': 'nominal', 'data-testid': 'mvr-mode-nominal', 'aria-pressed': 'true' }, 'Nominal (Rp)'),
                        UI.el('button', { type: 'button', class: 'mvr-seg-btn', 'data-mode': 'qty', 'data-testid': 'mvr-mode-qty', 'aria-pressed': 'false' }, 'Kuantitas (Qty)'),
                    ]),
                    UI.el('div', { class: 'mvr-quick' }, [['7 Hari', 7], ['30 Hari', 30], ['Bulan Ini', 0]].map(([label, n]) => {
                        const b = UI.el('button', { type: 'button', class: 'mvr-quick-btn', 'data-testid': `mvr-quick-${n}` }, label);
                        b.addEventListener('click', () => {
                            const r = n === 0 ? monthRange() : { start: isoShift(-(n - 1)), end: today() };
                            $('mvr-start').value = r.start; $('mvr-end').value = r.end;
                            applyFilters();
                        });
                        return b;
                    })),
                ]),
                UI.el('div', { class: 'mvr-filter-actions' }, [
                    UI.el('button', { type: 'button', class: 'btn btn-primary', id: 'mvr-apply', 'data-testid': 'mvr-apply' }, '⏷ Terapkan Filter'),
                    UI.el('button', { type: 'button', class: 'btn btn-secondary', id: 'mvr-reset', 'data-testid': 'mvr-reset' }, '↺ Reset'),
                ]),
            ]),
        ]);
        setTimeout(() => {
            if (isStock && Auth.user().warehouse_id) { $('mvr-wh').value = String(Auth.user().warehouse_id); S.warehouse = String(Auth.user().warehouse_id); }
            $('mvr-apply').addEventListener('click', applyFilters);
            $('mvr-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
            $('mvr-reset').addEventListener('click', () => {
                const keepWh = isStock ? S.warehouse : '';
                S = Object.assign(freshState(), { mode: S.mode, warehouse: keepWh });
                $('mvr-start').value = S.start; $('mvr-end').value = S.end; $('mvr-wh').value = keepWh; $('mvr-cat').value = ''; $('mvr-q').value = '';
                $('mvr-items-card').style.display = 'none';
                load();
            });
            card.querySelectorAll('.mvr-seg-btn').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
        }, 0);
        return card;
    }

    function applyFilters() {
        S.start = $('mvr-start').value || S.start;
        S.end = $('mvr-end').value || S.end;
        S.warehouse = $('mvr-wh').value;
        S.category = $('mvr-cat').value;
        S.q = $('mvr-q').value.trim();
        S.selected = null; S.dailyPage = 1; S.dayPage = 1;
        $('mvr-items-card').style.display = 'none';
        load();
    }

    function setMode(mode) {
        S.mode = mode;
        document.querySelectorAll('.mvr-seg-btn').forEach((b) => { const on = b.dataset.mode === mode; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        if (S.overview) { renderKpis(); renderChart(); renderDaily(); }
        if (S.selected) renderItems();
    }

    async function load() {
        $('mvr-kpis').innerHTML = '<div class="mvr-loading">Memuat laporan…</div>';
        $('mvr-chart-card').innerHTML = '';
        $('mvr-daily-card').innerHTML = '';
        $('mvr-banner').innerHTML = '';
        try {
            S.overview = await InvApi.movementOverview(params());
        } catch (err) {
            S.overview = null;
            $('mvr-kpis').innerHTML = '';
            const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'mvr-retry' }, 'Coba lagi');
            retry.addEventListener('click', load);
            $('mvr-daily-card').appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'mvr-error' }, [`Gagal memuat laporan: ${(err && err.message) || ''} `, retry]));
            UI.handleApiError(err);
            return;
        }
        renderBanner();
        renderKpis();
        renderChart();
        renderDaily();
        renderHistorical();
    }

    function renderBanner() {
        const host = $('mvr-banner');
        host.innerHTML = '';
        const ov = S.overview;
        const c = ov.cutover;
        if (c.is_pre_go_live_period) {
            host.appendChild(UI.el('div', { class: 'alert alert-info' }, `Periode yang dipilih seluruhnya sebelum Opening Go-Live${c.live_opening_date ? ` (${fmtDate(c.live_opening_date)})` : ''} — tidak ada aktivitas ekonomi.`));
        } else if (c.live_opening_date && c.effective_start_date !== c.requested_start_date) {
            host.appendChild(UI.el('div', { class: 'alert alert-info' }, `Periode efektif: ${fmtDate(c.effective_start_date)} – ${fmtDate(c.requested_end_date)} (Opening Go-Live ${fmtDate(c.live_opening_date)}).`));
        }
        if (!ov.reconciliation.ok) {
            const items = ov.reconciliation.issues.slice(0, 8).map((i) => UI.el('li', {}, `${i.date ? fmtDate(i.date) : 'Periode'}: ${i.message} (selisih ${UI.formatMoney(i.difference)})`));
            host.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'mvr-recon-warning' }, [
                UI.el('strong', {}, '⚠ Rekonsiliasi tidak seimbang. '), 'Saldo Awal + Masuk − Keluar ± Adjustment tidak sama dengan Saldo Akhir pada: ', UI.el('ul', { style: 'margin:6px 0 0 18px;' }, items),
            ]));
        }
    }

    // ------------------------------------------------------------------ KPI cards
    function kpi(key, tone, label, value, sub, onClick) {
        const el = UI.el('div', { class: `mvr-kpi tone-${tone}${onClick ? ' click' : ''}`, 'data-testid': `mvr-kpi-${key}`, ...(onClick ? { role: 'button', tabindex: '0' } : {}) }, [
            UI.el('div', { class: 'mvr-kpi-label' }, label),
            UI.el('div', { class: 'mvr-kpi-value', 'data-testid': `mvr-kpi-${key}-value`, title: typeof value === 'string' ? value : '' }, value),
            UI.el('div', { class: 'mvr-kpi-sub' }, sub),
        ]);
        if (onClick) {
            el.addEventListener('click', onClick);
            el.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } });
        }
        return el;
    }

    function renderKpis() {
        const host = $('mvr-kpis');
        host.innerHTML = '';
        const t = S.overview.totals;
        const nominal = S.mode === 'nominal';
        const lastLive = [...S.overview.rows].reverse().find((r) => !r.is_pre_go_live);
        const firstLive = S.overview.rows.find((r) => !r.is_pre_go_live);
        const units = t.qty_by_unit;
        if (nominal) {
            host.appendChild(kpi('opening', 'blue', 'Saldo Awal', rp(t.opening), `${UI.formatNumber(t.sku_opening, 0)} SKU memiliki stok`, firstLive ? () => selectDate(firstLive.date) : null));
            host.appendChild(kpi('masuk', 'green', 'Barang Masuk', rp(t.masuk), `${UI.formatNumber(t.tx_in, 0)} transaksi · ${UI.formatNumber(t.sku_in, 0)} SKU`, () => openPeriodTx('masuk', 'Barang Masuk')));
            host.appendChild(kpi('keluar', 'red', 'Barang Keluar', rp(t.keluar), `${UI.formatNumber(t.tx_out, 0)} transaksi · ${UI.formatNumber(t.sku_out, 0)} SKU`, () => openPeriodTx('keluar', 'Barang Keluar')));
            host.appendChild(kpi('adjustment', 'amber', 'Adjustment / Lain', signed(t.lain), `${UI.formatNumber(t.tx_other, 0)} transaksi · ${UI.formatNumber(t.sku_adjustment, 0)} SKU`, () => openPeriodTx('other', 'Adjustment / Pergerakan Lain')));
            host.appendChild(kpi('closing', 'purple', 'Saldo Akhir', rp(t.closing), `${UI.formatNumber(t.sku_closing, 0)} SKU memiliki stok`, lastLive ? () => selectDate(lastLive.date) : null));
        } else {
            host.appendChild(kpi('opening', 'blue', 'Saldo Awal', `${UI.formatNumber(t.sku_opening, 0)} SKU`, `Qty: ${unitsText(units.opening)}`, firstLive ? () => selectDate(firstLive.date) : null));
            host.appendChild(kpi('masuk', 'green', 'Barang Masuk', `${UI.formatNumber(t.tx_in, 0)} transaksi`, `${UI.formatNumber(t.sku_in, 0)} SKU · Qty: ${unitsText(units.masuk)}`, () => openPeriodTx('masuk', 'Barang Masuk')));
            host.appendChild(kpi('keluar', 'red', 'Barang Keluar', `${UI.formatNumber(t.tx_out, 0)} transaksi`, `${UI.formatNumber(t.sku_out, 0)} SKU · Qty: ${unitsText(units.keluar)}`, () => openPeriodTx('keluar', 'Barang Keluar')));
            host.appendChild(kpi('adjustment', 'amber', 'Adjustment / Lain', `${UI.formatNumber(t.tx_other, 0)} transaksi`, `${UI.formatNumber(t.sku_adjustment, 0)} SKU · Qty: ${unitsText(units.lain)}`, () => openPeriodTx('other', 'Adjustment / Pergerakan Lain')));
            host.appendChild(kpi('closing', 'purple', 'Saldo Akhir', `${UI.formatNumber(t.sku_closing, 0)} SKU`, `Qty: ${unitsText(units.closing)}`, lastLive ? () => selectDate(lastLive.date) : null));
        }
    }

    // ------------------------------------------------------------------ chart (inline SVG, no library)
    function renderChart() {
        const host = $('mvr-chart-card');
        host.innerHTML = '';
        const ov = S.overview;
        const qty = S.mode === 'qty';
        host.appendChild(UI.el('div', { class: 'mvr-card-head' }, [UI.el('div', { class: 'mvr-card-title' }, [
            '📈 Grafik Pergerakan Stok Harian ', UI.el('span', { class: 'mvr-card-sub' }, qty ? (ov.single_item ? `(Qty ${ov.single_item.unit})` : '(Qty)') : '(Nominal Rp)'),
        ])]));
        const rows = ov.rows.filter((r) => !r.is_pre_go_live);
        if (!rows.length) { host.appendChild(UI.el('div', { class: 'mvr-empty', 'data-testid': 'mvr-chart-empty' }, 'No movement found for this period.')); return; }
        if (qty && !ov.single_item) {
            host.appendChild(UI.el('div', { class: 'mvr-empty', 'data-testid': 'mvr-chart-qty-message' }, 'Pilih barang atau satuan untuk melihat grafik kuantitas. (Kuantitas satuan berbeda tidak dijumlahkan.)'));
            return;
        }
        const pick = (r, k) => (qty ? (r.qty ? r.qty[k] : 0) : r.nominal[k]);
        const series = rows.map((r) => ({ date: r.date, masuk: pick(r, 'masuk'), keluar: pick(r, 'keluar'), opening: pick(r, 'opening'), closing: pick(r, 'closing') }));
        host.appendChild(buildSvg(series, qty ? ov.single_item.unit : null));
        host.appendChild(UI.el('div', { class: 'mvr-chart-legend' }, [
            ['#2f7bff', 'Saldo Awal'], ['#22c55e', 'Barang Masuk'], ['#ef4444', 'Barang Keluar'], ['#8b5cf6', 'Saldo Akhir'],
        ].map(([c, l]) => UI.el('span', {}, [UI.el('i', { style: `background:${c}` }), ` ${l}`]))));
    }

    function niceMax(v) {
        if (v <= 0) return 1;
        const p = 10 ** Math.floor(Math.log10(v));
        const n = v / p;
        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
    }
    function axisLabel(v, unit) {
        const a = Math.abs(v);
        if (unit) return UI.formatNumber(v, 1);
        if (a >= 1e9) return `Rp ${UI.formatNumber(v / 1e9, 1)} M`;
        if (a >= 1e6) return `Rp ${UI.formatNumber(v / 1e6, 1)} jt`;
        if (a >= 1e3) return `Rp ${UI.formatNumber(v / 1e3, 0)} rb`;
        return `Rp ${UI.formatNumber(v, 0)}`;
    }
    function buildSvg(series, unit) {
        const NS = 'http://www.w3.org/2000/svg';
        const W = 900; const H = 260; const L = 64; const R = 14; const T = 14; const B = 34;
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        svg.setAttribute('class', 'mvr-svg');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Grafik pergerakan stok harian');
        svg.setAttribute('data-testid', 'mvr-chart');
        const max = niceMax(Math.max(...series.flatMap((s) => [s.masuk, s.keluar, s.opening, s.closing, 0])));
        const min = Math.min(0, ...series.flatMap((s) => [s.opening, s.closing]));
        const span = max - min || 1;
        const y = (v) => T + (H - T - B) * (1 - (v - min) / span);
        const el = (name, attrs, text) => { const e = document.createElementNS(NS, name); Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, String(v))); if (text !== undefined) e.textContent = text; return e; };
        for (let i = 0; i <= 4; i++) {
            const v = min + (span * i) / 4; const yy = y(v);
            svg.appendChild(el('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'mvr-grid' }));
            svg.appendChild(el('text', { x: L - 8, y: yy + 4, class: 'mvr-axis', 'text-anchor': 'end' }, axisLabel(v, unit)));
        }
        const step = (W - L - R) / series.length;
        const bw = Math.max(3, Math.min(18, step * 0.32));
        const tip = UI.el('div', { class: 'mvr-tip', hidden: 'hidden' });
        const fmtV = (v) => (unit ? `${UI.formatNumber(v, 3)} ${unit}` : UI.formatMoney(v));
        const pts = { open: [], close: [] };
        series.forEach((s, i) => {
            const cx = L + step * i + step / 2;
            const zero = y(0);
            svg.appendChild(el('rect', { x: cx - bw - 1, y: Math.min(y(s.masuk), zero), width: bw, height: Math.abs(zero - y(s.masuk)), class: 'mvr-bar-in', rx: 2 }));
            svg.appendChild(el('rect', { x: cx + 1, y: Math.min(y(s.keluar), zero), width: bw, height: Math.abs(zero - y(s.keluar)), class: 'mvr-bar-out', rx: 2 }));
            pts.open.push([cx, y(s.opening)]); pts.close.push([cx, y(s.closing)]);
            if (series.length <= 16 || i % Math.ceil(series.length / 12) === 0) svg.appendChild(el('text', { x: cx, y: H - 12, class: 'mvr-axis', 'text-anchor': 'middle' }, new Date(`${s.date}T00:00:00`).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })));
            const hit = el('rect', { x: cx - step / 2, y: T, width: step, height: H - T - B, fill: 'transparent', 'data-date': s.date, class: 'mvr-hit' });
            hit.addEventListener('mouseenter', () => { tip.hidden = false; tip.innerHTML = ''; [['Tanggal', fmtDate(s.date)], ['Saldo Awal', fmtV(s.opening)], ['Barang Masuk', fmtV(s.masuk)], ['Barang Keluar', fmtV(s.keluar)], ['Saldo Akhir', fmtV(s.closing)]].forEach(([k, v]) => tip.appendChild(UI.el('div', {}, [UI.el('b', {}, `${k}: `), v]))); tip.style.left = `${Math.min(88, Math.max(2, (cx / W) * 100))}%`; });
            hit.addEventListener('mouseleave', () => { tip.hidden = true; });
            hit.addEventListener('click', () => selectDate(s.date));
            svg.appendChild(hit);
        });
        const line = (arr, cls) => el('polyline', { points: arr.map((p) => p.join(',')).join(' '), class: cls, fill: 'none' });
        svg.appendChild(line(pts.open, 'mvr-line-open'));
        svg.appendChild(line(pts.close, 'mvr-line-close'));
        pts.close.forEach(([cx, cy]) => svg.appendChild(el('circle', { cx, cy, r: 2.6, class: 'mvr-dot-close' })));
        const wrap = UI.el('div', { class: 'mvr-chart-wrap' }, [svg, tip]);
        return wrap;
    }

    // ------------------------------------------------------------------ daily table
    function renderDaily() {
        const host = $('mvr-daily-card');
        host.innerHTML = '';
        const ov = S.overview;
        const nominal = S.mode === 'nominal';
        host.appendChild(UI.el('div', { class: 'mvr-card-head' }, [
            UI.el('div', { class: 'mvr-card-title' }, ['📅 Ringkasan Harian ', UI.el('span', { class: 'mvr-card-sub' }, nominal ? '(Nominal Rp)' : '(Kuantitas — jumlah SKU / transaksi)')]),
            UI.el('div', { class: 'mvr-hint' }, 'Klik baris atau ikon mata untuk melihat Rincian Per Barang pada tanggal tersebut.'),
        ]));
        const rows = ov.rows;
        if (!rows.length || rows.every((r) => r.is_pre_go_live) && !ov.historical.length) {
            host.appendChild(UI.el('div', { class: 'mvr-empty', 'data-testid': 'mvr-empty' }, 'No movement found for this period.'));
            return;
        }
        const totalPages = Math.max(1, Math.ceil(rows.length / S.dailyPerPage));
        S.dailyPage = Math.min(S.dailyPage, totalPages);
        const slice = rows.slice((S.dailyPage - 1) * S.dailyPerPage, S.dailyPage * S.dailyPerPage);
        const heads = nominal
            ? ['Tanggal', 'Saldo Awal', 'Barang Masuk', 'Barang Keluar', 'Adjustment / Lain', 'Saldo Akhir', 'Jumlah SKU', 'Aksi']
            : ['Tanggal', 'SKU Awal', 'Masuk (Transaksi / SKU)', 'Keluar (Transaksi / SKU)', 'Adjustment / Lain (Transaksi)', 'SKU Akhir', 'SKU Bergerak', 'Aksi'];
        const body = slice.map((r) => {
            const n = r.nominal; const c = r.counts;
            const cells = r.is_pre_go_live
                ? [fmtDate(r.date), '—', '—', '—', '—', '—', '—']
                : nominal
                    ? [fmtDate(r.date), rp(n.opening), rp(n.masuk), rp(n.keluar), signed(n.lain), rp(n.closing), UI.formatNumber(c.sku_closing, 0)]
                    : [fmtDate(r.date), UI.formatNumber(c.sku_opening, 0), `${c.tx_in} / ${c.sku_in}`, `${c.tx_out} / ${c.sku_out}`, UI.formatNumber(c.tx_other, 0), UI.formatNumber(c.sku_closing, 0), UI.formatNumber(c.sku_moved, 0)];
            const tr = UI.el('tr', { 'data-date': r.date, 'data-testid': 'mvr-daily-row', class: `${r.date === S.selected ? 'sel ' : ''}${r.is_pre_go_live ? 'pre' : 'click'}${!r.reconciliation.ok ? ' bad' : ''}` });
            cells.forEach((v, i) => tr.appendChild(UI.el('td', { class: i === 0 ? '' : 'num' }, v)));
            const eye = UI.el('button', { type: 'button', class: 'mvr-eye', 'aria-label': `Rincian ${r.date}`, 'data-testid': 'mvr-eye', title: 'Lihat rincian per barang' }, '👁');
            if (r.is_pre_go_live) eye.disabled = true;
            tr.appendChild(UI.el('td', { class: 'num' }, [eye, !r.reconciliation.ok ? UI.el('span', { class: 'mvr-warn', title: 'Rekonsiliasi tidak seimbang' }, ' ⚠') : null]));
            if (!r.is_pre_go_live) tr.addEventListener('click', () => selectDate(r.date));
            return tr;
        });
        const t = ov.totals;
        const live = rows.some((r) => !r.is_pre_go_live);
        const foot = !live ? null : UI.el('tr', { class: 'mvr-total', 'data-testid': 'mvr-daily-total' }, (nominal
            ? ['TOTAL PERIODE', rp(t.opening), rp(t.masuk), rp(t.keluar), signed(t.lain), rp(t.closing), UI.formatNumber(t.sku_closing, 0)]
            : ['TOTAL PERIODE', UI.formatNumber(t.sku_opening, 0), `${t.tx_in} / ${t.sku_in}`, `${t.tx_out} / ${t.sku_out}`, UI.formatNumber(t.tx_other, 0), UI.formatNumber(t.sku_closing, 0), '—']).map((v, i) => UI.el('td', { class: i === 0 ? '' : 'num' }, v)).concat([UI.el('td', {}, '')]));
        host.appendChild(UI.el('div', { class: 'mvr-scroll' }, [UI.el('table', { class: 'mvr-table', 'data-testid': 'mvr-daily-table' }, [
            UI.el('thead', {}, [UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: i === 0 ? '' : 'num' }, h)))]),
            UI.el('tbody', {}, body),
            foot ? UI.el('tfoot', {}, [foot]) : null,
        ].filter(Boolean))]));
        host.appendChild(pager(S.dailyPage, totalPages, rows.length, (p) => { S.dailyPage = p; renderDaily(); }, 'hari'));
    }

    function pager(page, totalPages, total, onPage, unitLabel) {
        const bar = UI.el('div', { class: 'mvr-pager' }, [UI.el('span', {}, `Total ${UI.formatNumber(total, 0)} ${unitLabel}`)]);
        const nav = UI.el('div', { class: 'mvr-pager-nav' });
        const btn = (label, p, disabled, cur) => { const b = UI.el('button', { type: 'button', class: `mvr-pg${cur ? ' on' : ''}`, ...(disabled ? { disabled: 'disabled' } : {}) }, label); b.addEventListener('click', () => onPage(p)); return b; };
        nav.appendChild(btn('‹', page - 1, page <= 1));
        const shown = new Set([1, totalPages, page, page - 1, page + 1].filter((p) => p >= 1 && p <= totalPages));
        let prev = 0;
        [...shown].sort((a, b) => a - b).forEach((p) => { if (p - prev > 1) nav.appendChild(UI.el('span', { class: 'mvr-pg-gap' }, '…')); nav.appendChild(btn(String(p), p, false, p === page)); prev = p; });
        nav.appendChild(btn('›', page + 1, page >= totalPages));
        bar.appendChild(nav);
        return bar;
    }

    // ------------------------------------------------------------------ historical disclosure (unchanged semantics)
    function renderHistorical() {
        const host = $('mvr-historical');
        host.innerHTML = '';
        const hist = S.overview.historical || [];
        if (!hist.length) return;
        const rows = hist.map((h) => {
            const tr = UI.el('tr', { class: 'click' }, [h.date, h.historical_in, h.historical_out, h.transaction_count].map((v, i) => UI.el('td', { class: i ? 'num' : '' }, i === 0 ? fmtDate(v) : i === 3 ? UI.formatNumber(v, 0) : UI.formatMoney(v))));
            tr.addEventListener('click', () => openHistorical(h.date));
            return tr;
        });
        host.appendChild(UI.el('div', { class: 'mvr-card' }, [
            UI.el('div', { class: 'mvr-card-head' }, [UI.el('div', { class: 'mvr-card-title' }, [UI.el('span', { class: 'hpp-pre-go-live-badge' }, 'HISTORICAL'), ' Pergerakan Historis / Reporting Only'])]),
            UI.el('p', { class: 'mvr-hint' }, 'Reporting Only — tidak memengaruhi stok/HPP live dan tidak menjadi bagian dari Saldo Awal/Akhir di atas.'),
            UI.el('div', { class: 'mvr-scroll' }, [UI.el('table', { class: 'mvr-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Historical IN', 'Historical OUT', 'Jumlah Transaksi'].map((h, i) => UI.el('th', { class: i ? 'num' : '' }, h)))]),
                UI.el('tbody', {}, rows),
            ])]),
        ]));
    }

    async function openHistorical(date) {
        wideDrawer(`Historis — ${fmtDate(date)}`, async (body) => {
            body.innerHTML = '<div class="alert alert-info">Memuat transaksi historis…</div>';
            try {
                const rows = await InvApi.movementHistoricalTransactions({ date, warehouse_id: S.warehouse || undefined });
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'HISTORICAL — Reporting Only, tidak memengaruhi stok/HPP live.'));
                body.appendChild(simpleTable(['No', 'Referensi', 'Barang', 'Gudang', 'Qty', 'Nilai'], rows.map((r, i) => [i + 1, r.reference_no || '—', `${r.sku} — ${r.item_name}`, r.warehouse_code, UI.formatNumber(r.qty, 3), UI.formatMoney(r.value)]), rows.map((r) => () => TraceDrawer.openTransaction(r.transaction_id))));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    // ------------------------------------------------------------------ drawers
    function wideDrawer(title, fill) {
        Drawer.open({ title, render: (body) => { fill(body); } });
        const panel = document.querySelector('.drawer');
        if (!panel) return;
        panel.classList.add('mvr-drawer');
        const mo = new MutationObserver(() => { if (!panel.classList.contains('open')) { panel.classList.remove('mvr-drawer'); mo.disconnect(); } });
        mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }

    function simpleTable(heads, rows, onRowClicks) {
        const trs = rows.map((r, i) => {
            const tr = UI.el('tr', { class: onRowClicks ? 'click' : '' }, r.map((v, j) => UI.el('td', { class: j >= heads.length - 2 ? 'num' : '' }, v === null || v === undefined ? '—' : v)));
            if (onRowClicks) tr.addEventListener('click', onRowClicks[i]);
            return tr;
        });
        return UI.el('div', { class: 'mvr-scroll' }, [UI.el('table', { class: 'mvr-table' }, [UI.el('thead', {}, [UI.el('tr', {}, heads.map((h) => UI.el('th', {}, h)))]), UI.el('tbody', {}, trs.length ? trs : [UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length) }, 'No movement found.')])])])]);
    }

    async function openPeriodTx(bucket, label) {
        wideDrawer(`${label} — ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`, async (body) => {
            body.innerHTML = '<div class="alert alert-info">Memuat transaksi…</div>';
            try {
                const res = await InvApi.movementPeriodTransactions(params({ bucket, per_page: 100 }));
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'mvr-drawer-sum', 'data-testid': 'mvr-ptx-total' }, `${UI.formatNumber(res.pagination.total, 0)} baris ledger · total ${bucket === 'other' ? signed(res.totals.value) : UI.formatMoney(res.totals.abs_value)}${res.pagination.total > 100 ? ' (100 baris pertama; gunakan Export Transaksi untuk semuanya)' : ''}`));
                body.appendChild(simpleTable(['Timestamp', 'Jenis', 'No. Referensi', 'Gudang', 'Kode', 'Nama Barang', 'Qty (satuan dasar)', 'Nilai'],
                    res.rows.map((r) => [fmtTs(r.timestamp), r.type_label, r.reference_no || '—', r.warehouse_code, r.sku, r.item_name, UI.formatNumber(r.qty, 3), bucket === 'other' ? signed(r.signed_value) : UI.formatMoney(r.value)]),
                    res.rows.map((r) => () => TraceDrawer.openTransaction(r.transaction_id))));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    // ------------------------------------------------------------------ Rincian Per Barang
    function selectDate(date) {
        S.selected = date; S.dayPage = 1;
        renderDaily();
        loadItems(true);
    }

    async function loadItems(scroll) {
        const host = $('mvr-items-card');
        host.style.display = '';
        host.innerHTML = '<div class="mvr-loading">Memuat rincian per barang…</div>';
        try {
            S.items = await InvApi.movementDayItems(params({ date: S.selected, category_id: S.itemCat || S.category || undefined, q: S.itemQ || S.q || undefined, movement: S.itemMove || undefined, moved_only: S.movedOnly ? '1' : undefined, sort: S.sort, dir: S.dir, page: S.dayPage, per_page: S.dayPerPage }));
        } catch (err) {
            host.innerHTML = '';
            const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, 'Coba lagi');
            retry.addEventListener('click', () => loadItems(false));
            host.appendChild(UI.el('div', { class: 'alert alert-error' }, [`Gagal memuat rincian: ${(err && err.message) || ''} `, retry]));
            return;
        }
        renderItems();
        if (scroll) host.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const ITEM_COLS = [
        ['sku', 'Kode', (r) => r.sku, true, 'sticky1'], ['name', 'Nama Barang', (r) => r.name, true, 'sticky2'], ['category', 'Kategori', (r) => r.category || '—', true],
        ['unit', 'Satuan', (r) => r.unit, false],
        ['opening_qty', 'Saldo Awal Qty', (r) => qn(r.opening_qty), true, 'num'], ['opening_value', 'Saldo Awal Nilai', (r) => rp(r.opening_value), true, 'num'],
        ['masuk_qty', 'Masuk Qty', (r) => qn(r.masuk_qty), true, 'num in'], ['masuk_value', 'Masuk Nilai', (r) => rp(r.masuk_value), true, 'num in'],
        ['keluar_qty', 'Keluar Qty', (r) => qn(r.keluar_qty), true, 'num out'], ['hpp', 'HPP Keluar / Unit', (r) => (r.hpp_keluar_per_unit === null ? '—' : rp(r.hpp_keluar_per_unit)), false, 'num out'], ['keluar_value', 'Keluar Nilai (HPP)', (r) => rp(r.keluar_value), true, 'num out'],
        ['adjustment_qty', 'Adjustment Qty', (r) => (r.adjustment_qty > 0 ? '+' : '') + qn(r.adjustment_qty), true, 'num adj'], ['adjustment_value', 'Adjustment Nilai', (r) => signed(r.adjustment_value), true, 'num adj'],
        ['closing_qty', 'Saldo Akhir Qty', (r) => qn(r.closing_qty), true, 'num'], ['closing_value', 'Saldo Akhir Nilai', (r) => rp(r.closing_value), true, 'num'],
        ['tx_count', 'Jumlah Transaksi', (r) => UI.formatNumber(r.tx_count, 0), true, 'num'],
    ];

    function renderItems() {
        const host = $('mvr-items-card');
        host.innerHTML = '';
        const d = S.items;
        const nominalTitle = S.mode === 'nominal' ? 'Nominal' : 'Kuantitas';
        const filters = UI.el('div', { class: 'mvr-item-filters' });
        const search = UI.el('input', { type: 'text', placeholder: 'Cari kode / nama barang…', value: S.itemQ, 'data-testid': 'mvr-item-q', class: 'mvr-input' });
        const cat = UI.el('select', { 'data-testid': 'mvr-item-cat', class: 'mvr-input' }, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(Master.categories().filter((c) => c.is_active).map((c) => UI.el('option', { value: String(c.id) }, c.name))));
        cat.value = S.itemCat;
        const mv = UI.el('select', { 'data-testid': 'mvr-item-move', class: 'mvr-input' }, [['', 'Semua Jenis'], ['masuk', 'Ada Barang Masuk'], ['keluar', 'Ada Barang Keluar'], ['adjustment', 'Ada Adjustment / Lain'], ['transfer', 'Ada Transfer']].map(([v, l]) => UI.el('option', { value: v }, l)));
        mv.value = S.itemMove;
        const moved = UI.el('label', { class: 'mvr-check' }, [UI.el('input', { type: 'checkbox', 'data-testid': 'mvr-item-moved', ...(S.movedOnly ? { checked: 'checked' } : {}) }), ' Hanya yang bergerak']);
        let t;
        search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { S.itemQ = search.value.trim(); S.dayPage = 1; loadItems(false); }, 350); });
        cat.addEventListener('change', () => { S.itemCat = cat.value; S.dayPage = 1; loadItems(false); });
        mv.addEventListener('change', () => { S.itemMove = mv.value; S.dayPage = 1; loadItems(false); });
        moved.querySelector('input').addEventListener('change', (e) => { S.movedOnly = e.target.checked; S.dayPage = 1; loadItems(false); });
        [search, cat, mv, moved].forEach((n) => filters.appendChild(n));
        host.appendChild(UI.el('div', { class: 'mvr-card-head' }, [
            UI.el('div', {}, [
                UI.el('div', { class: 'mvr-card-title', 'data-testid': 'mvr-items-title' }, `📦 Rincian Per Barang — ${fmtDate(S.selected)}`),
                UI.el('div', { class: 'mvr-hint' }, `Qty memakai satuan dasar masing-masing barang (tidak dijumlahkan lintas satuan). Mode ${nominalTitle}. Scroll horizontal untuk semua kolom.`),
            ]),
            UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'mvr-items-close' }, 'Tutup'),
        ]));
        host.querySelector('[data-testid="mvr-items-close"]').addEventListener('click', () => { S.selected = null; host.style.display = 'none'; renderDaily(); });
        host.appendChild(filters);
        const head = UI.el('tr', {}, ITEM_COLS.map(([key, label, , sortable, cls]) => {
            const th = UI.el('th', { class: cls || '', ...(sortable ? { 'data-sort': key, tabindex: '0' } : {}) }, [label, S.sort === key ? (S.dir === 'asc' ? ' ▲' : ' ▼') : '']);
            if (sortable) th.addEventListener('click', () => { if (S.sort === key) S.dir = S.dir === 'asc' ? 'desc' : 'asc'; else { S.sort = key; S.dir = 'asc'; } S.dayPage = 1; loadItems(false); });
            return th;
        }).concat([UI.el('th', {}, 'Aksi')]));
        const body = d.rows.map((r) => {
            const tr = UI.el('tr', { 'data-testid': 'mvr-item-row', 'data-item': String(r.item_id), class: 'click' }, ITEM_COLS.map(([, , get, , cls]) => UI.el('td', { class: cls || '', title: cls && cls.includes('sticky') ? '' : undefined }, get(r))));
            const open = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'mvr-item-trail' }, 'Transaksi');
            tr.appendChild(UI.el('td', {}, [open]));
            tr.addEventListener('click', () => openTrail(r));
            return tr;
        });
        const T = d.totals;
        const blank = (n) => Array.from({ length: n }, () => UI.el('td', {}, ''));
        const totalRow = UI.el('tr', { class: 'mvr-total', 'data-testid': 'mvr-items-total' }, [
            UI.el('td', { class: 'sticky1' }, 'TOTAL'), UI.el('td', { class: 'sticky2' }, `${UI.formatNumber(T.sku_count, 0)} barang`), ...blank(2),
            UI.el('td', { class: 'num' }, '—'), UI.el('td', { class: 'num' }, rp(T.opening_value)),
            UI.el('td', { class: 'num in' }, '—'), UI.el('td', { class: 'num in' }, rp(T.masuk_value)),
            UI.el('td', { class: 'num out' }, '—'), UI.el('td', { class: 'num out' }, ''), UI.el('td', { class: 'num out' }, rp(T.keluar_value)),
            UI.el('td', { class: 'num adj' }, '—'), UI.el('td', { class: 'num adj' }, signed(T.adjustment_value)),
            UI.el('td', { class: 'num' }, '—'), UI.el('td', { class: 'num' }, rp(T.closing_value)), UI.el('td', { class: 'num' }, UI.formatNumber(T.tx_count, 0)), UI.el('td', {}, ''),
        ]);
        host.appendChild(UI.el('div', { class: 'mvr-scroll mvr-wide', 'data-testid': 'mvr-items-scroll' }, [UI.el('table', { class: 'mvr-table mvr-items', 'data-testid': 'mvr-items-table' }, [
            UI.el('thead', {}, [head]),
            UI.el('tbody', {}, body.length ? body : [UI.el('tr', {}, [UI.el('td', { colspan: String(ITEM_COLS.length + 1) }, 'No movement found for this period.')])]),
            UI.el('tfoot', {}, [totalRow]),
        ])]));
        const unitLine = ['opening', 'masuk', 'keluar', 'adjustment', 'closing'].map((k) => `${{ opening: 'Awal', masuk: 'Masuk', keluar: 'Keluar', adjustment: 'Adj', closing: 'Akhir' }[k]}: ${unitsText(T.qty_by_unit[k])}`).join('  |  ');
        host.appendChild(UI.el('div', { class: 'mvr-unitline', 'data-testid': 'mvr-items-units' }, `Qty per satuan — ${unitLine}`));
        host.appendChild(pager(d.pagination.page, d.pagination.total_pages, d.pagination.total, (p) => { S.dayPage = p; loadItems(false); }, 'barang'));
    }

    async function openTrail(row) {
        wideDrawer(`${row.sku} — ${row.name} · ${fmtDate(S.selected)}`, async (body) => {
            body.innerHTML = '<div class="alert alert-info">Memuat transaksi…</div>';
            try {
                const tr = await InvApi.movementItemTrail({ date: S.selected, item_id: row.item_id, warehouse_id: S.warehouse || undefined });
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'mvr-trail-head', 'data-testid': 'mvr-trail-head' }, [
                    UI.el('div', {}, [UI.el('b', {}, 'Saldo awal hari: '), `${qn(tr.opening.qty)} ${tr.item.unit} · ${rp(tr.opening.value)}`]),
                    UI.el('div', {}, [UI.el('b', {}, 'Saldo akhir hari: '), `${qn(tr.closing.qty)} ${tr.item.unit} · ${rp(tr.closing.value)}`]),
                    tr.is_company_consolidated ? UI.el('div', { class: 'mvr-hint' }, 'Semua Gudang: transfer antar gudang tampil di sini tetapi tidak dihitung sebagai Masuk/Keluar.') : null,
                ].filter(Boolean)));
                const heads = ['Timestamp', 'Jenis Transaksi', 'No. Referensi', 'Gudang', `Qty Masuk (${tr.item.unit})`, 'Harga Beli / Cost', `Qty Keluar (${tr.item.unit})`, 'HPP Keluar (FIFO)', 'Adjustment', 'Saldo Qty', 'Saldo Nilai', 'User', 'Keterangan'];
                const trs = tr.rows.map((r) => {
                    const adj = r.adjustment_qty !== null && r.adjustment_qty !== undefined ? `${r.adjustment_qty > 0 ? '+' : ''}${qn(r.adjustment_qty)} / ${signed(r.adjustment_value)}` : '—';
                    const tre = UI.el('tr', { class: `click${r.internal_transfer ? ' internal' : ''}`, 'data-testid': 'mvr-trail-row', 'data-bucket': r.bucket }, [
                        fmtTs(r.timestamp), r.type_label + (r.internal_transfer ? ' (internal)' : ''), r.reference_no || '—', r.warehouse_code,
                        r.qty_in === null ? '—' : qn(r.qty_in), r.cost_in === null ? '—' : rp(r.cost_in),
                        r.qty_out === null ? '—' : qn(r.qty_out), r.hpp_out === null ? '—' : `${rp(r.hpp_out)} (@ ${rp(r.hpp_out_per_unit)})`,
                        adj, qn(r.balance_qty), rp(r.balance_value), r.user || '—', r.note || '—',
                    ].map((v, i) => UI.el('td', { class: i >= 4 && i <= 10 ? 'num' : '' }, v)));
                    tre.addEventListener('click', () => TraceDrawer.openTransaction(r.transaction_id));
                    return tre;
                });
                body.appendChild(UI.el('div', { class: 'mvr-scroll' }, [UI.el('table', { class: 'mvr-table mvr-trail-table', 'data-testid': 'mvr-trail-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: i >= 4 && i <= 10 ? 'num' : '' }, h)))]),
                    UI.el('tbody', {}, trs.length ? trs : [UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length) }, 'Tidak ada transaksi pada tanggal ini (hanya saldo yang dibawa).')])]),
                ])]));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    return { render };
})();
