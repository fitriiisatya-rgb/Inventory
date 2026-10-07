/**
 * Laporan > Laporan Pergerakan Stok (reports v3).
 *
 * READ-ONLY (GET only). Layout of the report family: header (title + Cetak / Download Excel) -> ONE compact filter card -> KPI grid -> reconciliation strip
 * -> daily chart -> "Harian | Per Barang" tabs (daily table with Transfer IN / OUT split, or the server-paginated per-item table) -> drawers.
 *
 *   Stok Awal + Barang Masuk - Barang Keluar + Transfer IN - Transfer OUT + Adjustment = Stok Akhir
 *   (per warehouse a transfer shows as OUT on the sender and IN on the receiver; company-wide it nets to zero.)
 *
 * Quantities of different units are NEVER added: in "Kuantitas" mode the cards / summary group by unit and the item table keeps each item's own unit.
 * Own GET helper (ReportTools.apiGet) — independent of api-client.js.
 */
const ReportPergerakan = (() => {
    let S = null;
    const $ = (id) => document.getElementById(id);
    const api = (path, params) => ReportTools.apiGet(path, params);

    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    function monthRange() { const n = new Date(); return { start: iso(new Date(n.getFullYear(), n.getMonth(), 1)), end: iso(new Date(n.getFullYear(), n.getMonth() + 1, 0)) }; }
    function lastDays(n) { const e = new Date(); const s = new Date(); s.setDate(s.getDate() - (n - 1)); return { start: iso(s), end: iso(e) }; }
    function fresh() {
        const r = monthRange();
        return { start: r.start, end: r.end, warehouse: '', category: '', q: '', mode: 'nominal', view: 'harian', chartView: 'grafik', dayQ: '', overview: null, dailyPage: 1, dailyPer: 31,
            itemQ: '', itemMove: '', sort: 'sku', dir: 'asc', itemPage: 1, itemPer: 25, items: null, ticket: 0, itemTicket: 0 };
    }

    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const fmtDate = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || '')); return m ? `${m[3]} ${MONTHS[Number(m[2]) - 1]} ${m[1]}` : '—'; };
    const fmtTs = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(v || '')); return m ? `${m[3]} ${MONTHS[Number(m[2]) - 1]} ${m[1]} ${m[4]}:${m[5]}` : '—'; };
    const nil = (v) => v === null || v === undefined;
    const rp = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const qn = (v) => (nil(v) ? '—' : UI.formatNumber(v, 3));
    const signedRp = (v) => { const n = Number(v) || 0; return (n > 0 ? '+' : '') + UI.formatMoney(n); };
    const tone = (v) => (Math.abs(Number(v) || 0) < 0.005 ? '' : Number(v) > 0 ? ' rp-pos' : ' rp-neg');
    const unitsText = (list, key) => {
        const parts = (list || []).filter((u) => Math.abs(Number(u[key]) || 0) > 0.0005).map((u) => `${u.unit} ${UI.formatNumber(u[key], 3)}`);
        return parts.length ? parts.join(' · ') : '—';
    };
    const td = (text, cls, attrs) => UI.el('td', { ...(cls ? { class: cls } : {}), ...(attrs || {}) }, text === null || text === undefined ? '—' : String(text));

    function params(extra) {
        return { start_date: S.start, end_date: S.end, warehouse_id: S.warehouse || undefined, category_id: S.category || undefined, q: S.q || undefined, ...(extra || {}) };
    }
    const modeLabel = () => (S.mode === 'qty' ? 'Kuantitas (Qty)' : 'Nominal (Rp)');

    // ------------------------------------------------------------------ render
    function render(container) {
        S = fresh();
        container.innerHTML = '';
        container.classList.add('rp-page');
        ReportTools.onResize('mv-chart', () => { if (S.overview && $('mv-chart') && $('mv-chart').offsetParent) renderChart(); });
        const actions = ReportTools.actions({ id: 'mv', print: doPrint, excel: doExcel });
        container.appendChild(UI.el('div', { class: 'rp-head' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'rp-title', 'data-testid': 'mv-title' }, 'Laporan Pergerakan Stok'),
                UI.el('p', { class: 'rp-desc' }, 'Stok Awal, Barang Masuk, Barang Keluar, Transfer, Adjustment dan Stok Akhir — dari ledger inventory, harian maupun per barang.'),
            ]),
            actions,
        ]));
        container.appendChild(filterCard());
        container.appendChild(UI.el('div', { id: 'mv-banner' }));
        container.appendChild(UI.el('div', { id: 'mv-kpis', class: 'rp-kpis', 'data-testid': 'mv-kpis' }));
        container.appendChild(UI.el('div', { id: 'mv-recon' }));
        container.appendChild(UI.el('div', { id: 'mv-chart', class: 'rp-card' }));
        container.appendChild(UI.el('div', { id: 'mv-data', class: 'rp-card' }));
        container.appendChild(UI.el('div', { class: 'rp-note', 'data-testid': 'mv-legend' },
            'Barang Masuk = pembelian (Stock IN). Barang Keluar = Stock OUT (HPP FIFO aktual). Transfer: per gudang tampil sebagai Transfer OUT pada gudang asal dan Transfer IN pada gudang tujuan; '
            + 'pada "Semua Gudang" keduanya saling meniadakan (selisih = barang dalam perjalanan). Adjustment = koreksi / Stock Opname / pembalikan void / saldo awal baru. Nilai memakai biaya yang tercatat di ledger (FIFO).'));
        load();
    }

    function filterCard() {
        const wh = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const cat = Master.categories().filter((c) => c.is_active).map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
        const isStock = Auth.user() && Auth.user().role_code === 'STOCK';
        const card = UI.el('div', { class: 'rp-card rp-filter', 'data-testid': 'mv-filters' }, [
            UI.el('div', { class: 'rp-fgrid', html: `
                <div class="rp-f s2"><label for="mv-start">Dari</label><input type="date" id="mv-start" data-testid="mv-start" value="${S.start}"></div>
                <div class="rp-f s2"><label for="mv-end">Sampai</label><input type="date" id="mv-end" data-testid="mv-end" value="${S.end}"></div>
                <div class="rp-f s2"><label for="mv-wh">Gudang</label><select id="mv-wh" data-testid="mv-wh" ${isStock ? 'disabled' : ''}><option value="">Semua Gudang</option>${wh}</select></div>
                <div class="rp-f s2"><label for="mv-cat">Kategori</label><select id="mv-cat" data-testid="mv-cat"><option value="">Semua Kategori</option>${cat}</select></div>
                <div class="rp-f s4"><label for="mv-q">Barang / SKU</label><input type="text" id="mv-q" data-testid="mv-q" placeholder="Cari kode atau nama barang…" autocomplete="off"></div>` }),
            UI.el('div', { class: 'rp-row2' }, [
                UI.el('div', { class: 'rp-inline' }, [
                    UI.el('span', { class: 'rp-lab' }, 'Tampilan'),
                    UI.el('div', { class: 'rp-seg', role: 'group', 'aria-label': 'Tampilan data', id: 'mv-mode' }, [
                        UI.el('button', { type: 'button', class: 'on', 'data-mode': 'nominal', 'data-testid': 'mv-mode-nominal', 'aria-pressed': 'true' }, 'Nominal (Rp)'),
                        UI.el('button', { type: 'button', 'data-mode': 'qty', 'data-testid': 'mv-mode-qty', 'aria-pressed': 'false' }, 'Kuantitas (Qty)'),
                    ]),
                    UI.el('div', { class: 'rp-quick' }, [['7 Hari', 7], ['30 Hari', 30], ['Bulan Ini', 0]].map(([label, n]) => {
                        const b = UI.el('button', { type: 'button', 'data-testid': `mv-quick-${n}` }, label);
                        b.addEventListener('click', () => { const r = n === 0 ? monthRange() : lastDays(n); $('mv-start').value = r.start; $('mv-end').value = r.end; applyFilters(); });
                        return b;
                    })),
                ]),
                UI.el('div', { class: 'rp-factions' }, [
                    UI.el('button', { type: 'button', class: 'btn btn-primary', id: 'mv-apply', 'data-testid': 'mv-apply' }, 'Terapkan'),
                    UI.el('button', { type: 'button', class: 'btn btn-secondary', id: 'mv-reset', 'data-testid': 'mv-reset' }, 'Reset'),
                ]),
            ]),
        ]);
        setTimeout(() => {
            if (isStock && Auth.user().warehouse_id) { $('mv-wh').value = String(Auth.user().warehouse_id); S.warehouse = String(Auth.user().warehouse_id); }
            $('mv-apply').addEventListener('click', applyFilters);
            $('mv-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
            $('mv-reset').addEventListener('click', () => {
                const keep = isStock ? S.warehouse : '';
                const mode = S.mode; const view = S.view;
                S = Object.assign(fresh(), { mode, view, warehouse: keep });
                $('mv-start').value = S.start; $('mv-end').value = S.end; $('mv-wh').value = keep; $('mv-cat').value = ''; $('mv-q').value = '';
                load();
            });
            card.querySelectorAll('#mv-mode button').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
        }, 0);
        return card;
    }

    function applyFilters() {
        S.start = $('mv-start').value || S.start;
        S.end = $('mv-end').value || S.end;
        S.warehouse = $('mv-wh').value; S.category = $('mv-cat').value; S.q = $('mv-q').value.trim();
        S.dailyPage = 1; S.itemPage = 1;
        load();
    }

    function setMode(mode) {
        S.mode = mode;
        document.querySelectorAll('#mv-mode button').forEach((b) => { const on = b.dataset.mode === mode; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        if (S.overview) { renderKpis(); renderChart(); renderData(); }
    }

    async function load() {
        const t = ++S.ticket;
        $('mv-kpis').innerHTML = '<div class="rp-loading">Memuat laporan…</div>';
        $('mv-chart').innerHTML = ''; $('mv-data').innerHTML = ''; $('mv-banner').innerHTML = ''; $('mv-recon').innerHTML = '';
        let ov;
        try {
            ov = await api('/reports/movement/v3/overview', params());
        } catch (err) {
            if (t !== S.ticket) return;
            S.overview = null;
            $('mv-kpis').innerHTML = '';
            const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': 'mv-retry' }, 'Coba lagi');
            retry.addEventListener('click', load);
            $('mv-data').appendChild(UI.el('div', { class: 'rp-banner bad', 'data-testid': 'mv-error' }, [`Gagal memuat laporan: ${(err && err.message) || ''} `, retry]));
            return;
        }
        if (t !== S.ticket) return;
        S.overview = ov; S.items = null;
        renderBanner(); renderKpis(); renderRecon(); renderChart(); renderData();
    }

    // ------------------------------------------------------------------ banner / KPI / reconciliation
    function renderBanner() {
        const host = $('mv-banner');
        const c = S.overview.cutover;
        if (c.is_pre_go_live_period) {
            host.appendChild(UI.el('div', { class: 'rp-banner' }, `Periode yang dipilih seluruhnya sebelum Opening Go-Live${c.live_opening_date ? ` (${fmtDate(c.live_opening_date)})` : ''} — tidak ada aktivitas ekonomi.`));
        } else if (c.live_opening_date && c.effective_start_date !== c.requested_start_date) {
            host.appendChild(UI.el('div', { class: 'rp-banner' }, `Periode efektif: ${fmtDate(c.effective_start_date)} – ${fmtDate(c.requested_end_date)} (Opening Go-Live ${fmtDate(c.live_opening_date)}).`));
        }
    }

    function kpi(key, t, label, value, sub, onClick) {
        const el = UI.el(onClick ? 'button' : 'div', { class: `rp-kpi t-${t}${onClick ? ' click' : ''}`, 'data-testid': `mv-kpi-${key}`, ...(onClick ? { type: 'button' } : {}) }, [
            UI.el('div', { class: 'rp-kpi-l' }, label),
            UI.el('div', { class: 'rp-kpi-v', 'data-testid': `mv-kpi-${key}-value`, title: value }, value),
            UI.el('div', { class: 'rp-kpi-s' }, sub),
        ]);
        if (onClick) el.addEventListener('click', onClick);
        return el;
    }

    function renderKpis() {
        const host = $('mv-kpis');
        host.innerHTML = '';
        const ov = S.overview; const t = ov.totals; const sp = ov.split_totals; const u = ov.qty_units || [];
        const trx = (n, s) => `${UI.formatNumber(n, 0)} transaksi · ${UI.formatNumber(s, 0)} SKU`;
        const nominal = S.mode === 'nominal';
        const val = (k, nk) => (nominal ? rp(sp[nk || k]) : unitsText(u, k));
        const adj = nominal ? signedRp(sp.adjustment) : unitsText(u, 'adjustment');
        host.appendChild(kpi('opening', 'blue', 'Stok Awal', val('opening'), `${UI.formatNumber(t.sku_opening, 0)} SKU memiliki stok`));
        host.appendChild(kpi('masuk', 'green', 'Barang Masuk', val('in'), `IN · ${trx(t.tx_in, t.sku_in)}`, () => openPeriod('masuk', 'Barang Masuk')));
        host.appendChild(kpi('keluar', 'red', 'Barang Keluar', val('out'), `OUT · ${trx(t.tx_out, t.sku_out)}`, () => openPeriod('keluar', 'Barang Keluar')));
        host.appendChild(kpi('tin', 'teal', 'Transfer IN', val('tin'), 'masuk dari gudang lain'));
        host.appendChild(kpi('tout', 'amber', 'Transfer OUT', val('tout'), 'keluar ke gudang lain'));
        const adjEl = kpi('adjustment', 'indigo', 'Adjustment', adj, 'koreksi · opname · void');
        host.appendChild(adjEl);
        host.appendChild(kpi('closing', 'purple', 'Stok Akhir', val('closing'), `${UI.formatNumber(t.sku_closing, 0)} SKU memiliki stok`));
        ReportTools.decorateKpis(host);
    }

    function renderRecon() {
        const host = $('mv-recon');
        host.innerHTML = '';
        const ov = S.overview; const sp = ov.split_totals; const r = ov.reconciliation;
        if (r.ok && r.split_ok) {
            host.appendChild(UI.el('div', { class: 'rp-banner ok', 'data-testid': 'mv-recon-ok' }, [
                UI.el('b', {}, '✓ Rekonsiliasi seimbang. '),
                S.mode === 'nominal'
                    ? `Transfer IN ${rp(sp.tin)} · Transfer OUT ${rp(sp.tout)} · Adjustment ${signedRp(sp.adjustment)} · Selisih ${rp(sp.difference)}${S.warehouse ? '' : ' — transfer antar gudang netto nol pada Semua Gudang'}.`
                    : `Transfer IN ${unitsText(ov.qty_units, 'tin')} · Transfer OUT ${unitsText(ov.qty_units, 'tout')} · Adjustment ${unitsText(ov.qty_units, 'adjustment')}.`,
            ]));
        } else {
            const issues = (r.issues || []).concat(r.split_issues || []).slice(0, 6).map((i) => UI.el('li', {}, `${i.date ? fmtDate(i.date) : 'Periode'}: ${i.message} (selisih ${UI.formatMoney(i.difference)})`));
            host.appendChild(UI.el('div', { class: 'rp-banner bad', 'data-testid': 'mv-recon-warning' }, [UI.el('b', {}, '⚠ Rekonsiliasi tidak seimbang. '), 'Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment ≠ Stok Akhir pada:', UI.el('ul', { style: 'margin:4px 0 0 18px;' }, issues)]));
        }
    }

    // ------------------------------------------------------------------ chart
    function renderChart() {
        const host = $('mv-chart');
        host.innerHTML = '';
        const ov = S.overview; const qty = S.mode === 'qty';
        const tog = UI.el('div', { class: 'rp-seg', role: 'group', 'aria-label': 'Tampilan grafik', 'data-testid': 'mv-chart-toggle' }, [['grafik', 'Grafik'], ['tabel', 'Tabel']].map(([k, label]) => {
            const b = UI.el('button', { type: 'button', class: S.chartView === k ? 'on' : '', 'data-testid': `mv-chartview-${k}`, 'aria-pressed': S.chartView === k ? 'true' : 'false' }, label);
            b.addEventListener('click', () => { S.chartView = k; renderChart(); });
            return b;
        }));
        const legend = UI.el('div', { class: 'rp-legend' }, [['#3b82f6', 'Stok Awal'], ['#22c55e', 'Barang Masuk'], ['#ef4444', 'Barang Keluar'], ['#8b5cf6', 'Stok Akhir']].map(([c, l]) => UI.el('span', {}, [UI.el('i', { style: `background:${c}` }), l])));
        host.appendChild(UI.el('div', { class: 'rp-card-head' }, [
            UI.el('div', { class: 'rp-card-title' }, ['Grafik Pergerakan Stok Harian ', UI.el('span', { class: 'rp-sub' }, qty ? (ov.single_item ? `(Qty ${ov.single_item.unit})` : '(Qty)') : '(Nominal Rp)')]),
            UI.el('div', { class: 'rp-inline' }, [S.chartView === 'grafik' ? legend : null, tog].filter(Boolean)),
        ]));
        const rows = ov.rows.filter((r) => !r.is_pre_go_live);
        if (!rows.length) { host.appendChild(UI.el('div', { class: 'rp-empty', 'data-testid': 'mv-chart-empty' }, 'Tidak ada pergerakan pada periode ini.')); return; }
        if (qty && !ov.single_item) { host.appendChild(UI.el('div', { class: 'rp-empty', 'data-testid': 'mv-chart-qty-message' }, 'Pilih satu barang untuk melihat grafik kuantitas — kuantitas berbeda satuan tidak dijumlahkan.')); return; }
        const pick = (r, k) => (qty ? (r.qty ? r.qty[k] : 0) : r.nominal[k]);
        const series = rows.map((r) => ({ date: r.date, masuk: pick(r, 'masuk'), keluar: pick(r, 'keluar'), opening: pick(r, 'opening'), closing: pick(r, 'closing') }));
        if (S.chartView === 'tabel') {
            const unit = qty ? ov.single_item.unit : null;
            const f = (v) => (unit ? `${UI.formatNumber(v, 3)} ${unit}` : UI.formatMoney(v));
            host.appendChild(UI.el('div', { class: 'rp-scroll mv-chart-table', 'data-testid': 'mv-chart-table' }, [UI.el('table', { class: 'rp-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Stok Awal', 'Barang Masuk', 'Barang Keluar', 'Stok Akhir'].map((h, i) => UI.el('th', { class: i ? 'num' : '' }, h)))]),
                UI.el('tbody', {}, series.map((r) => UI.el('tr', {}, [td(fmtDate(r.date)), td(f(r.opening), 'num'), td(f(r.masuk), 'num'), td(f(r.keluar), 'num'), td(f(r.closing), 'num')])))])]));
            return;
        }
        host.appendChild(buildSvg(series, qty ? ov.single_item.unit : null));
    }

    function niceMax(v) { if (v <= 0) return 1; const p = 10 ** Math.floor(Math.log10(v)); const n = v / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p; }
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
        const W = ReportTools.chartWidth($('mv-chart'), 1000); const H = 200; const L = 66; const R = 12; const T = 10; const B = 26;
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Grafik pergerakan stok harian'); svg.setAttribute('data-testid', 'mv-chart-svg');
        const el = (name, attrs, text) => { const e = document.createElementNS(NS, name); Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, String(v))); if (text !== undefined) e.textContent = text; return e; };
        const max = niceMax(Math.max(...series.flatMap((s) => [s.masuk, s.keluar, s.opening, s.closing, 0])));
        const min = Math.min(0, ...series.flatMap((s) => [s.opening, s.closing]));
        const span = max - min || 1;
        const y = (v) => T + (H - T - B) * (1 - (v - min) / span);
        for (let i = 0; i <= 4; i++) { const v = min + (span * i) / 4; const yy = y(v); svg.appendChild(el('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'g' })); svg.appendChild(el('text', { x: L - 8, y: yy + 3, class: 'ax', 'text-anchor': 'end' }, axisLabel(v, unit))); }
        const step = (W - L - R) / series.length;
        const bw = Math.max(3, Math.min(16, step * 0.32));
        const tip = UI.el('div', { class: 'rp-tip', hidden: 'hidden' });
        const fmtV = (v) => (unit ? `${UI.formatNumber(v, 3)} ${unit}` : UI.formatMoney(v));
        const open = []; const close = [];
        series.forEach((s, i) => {
            const cx = L + step * i + step / 2; const zero = y(0);
            svg.appendChild(el('rect', { x: cx - bw - 1, y: Math.min(y(s.masuk), zero), width: bw, height: Math.abs(zero - y(s.masuk)), class: 'b-in', rx: 2 }));
            svg.appendChild(el('rect', { x: cx + 1, y: Math.min(y(s.keluar), zero), width: bw, height: Math.abs(zero - y(s.keluar)), class: 'b-out', rx: 2 }));
            open.push([cx, y(s.opening)]); close.push([cx, y(s.closing)]);
            if (series.length <= 16 || i % Math.ceil(series.length / 12) === 0) svg.appendChild(el('text', { x: cx, y: H - 8, class: 'ax', 'text-anchor': 'middle' }, fmtDate(s.date).slice(0, 6)));
            const hit = el('rect', { x: cx - step / 2, y: T, width: step, height: H - T - B, fill: 'transparent', 'data-date': s.date, style: 'cursor:pointer' });
            hit.addEventListener('mouseenter', () => {
                tip.hidden = false; tip.innerHTML = '';
                [['Tanggal', fmtDate(s.date)], ['Stok Awal', fmtV(s.opening)], ['Barang Masuk', fmtV(s.masuk)], ['Barang Keluar', fmtV(s.keluar)], ['Stok Akhir', fmtV(s.closing)]].forEach(([k, v]) => tip.appendChild(UI.el('div', {}, [UI.el('b', {}, `${k}: `), v])));
                tip.style.left = `${Math.min(86, Math.max(8, (cx / W) * 100))}%`;
            });
            hit.addEventListener('mouseleave', () => { tip.hidden = true; });
            hit.addEventListener('click', () => openDay(s.date));
            svg.appendChild(hit);
        });
        const line = (pts, cls) => el('polyline', { points: pts.map((p) => p.join(',')).join(' '), class: cls });
        svg.appendChild(line(open, 'l-open')); svg.appendChild(line(close, 'l-close'));
        close.forEach(([cx, cy]) => svg.appendChild(el('circle', { cx, cy, r: 2.4, class: 'dot' })));
        return UI.el('div', { class: 'rp-chart' }, [svg, tip]);
    }

    // ------------------------------------------------------------------ data area (tabs)
    function renderData() {
        const host = $('mv-data');
        host.innerHTML = '';
        const tabs = UI.el('div', { class: 'rp-tabs', role: 'tablist' }, [['harian', 'Harian'], ['barang', 'Per Barang']].map(([k, label]) => {
            const b = UI.el('button', { type: 'button', role: 'tab', class: S.view === k ? 'on' : '', 'data-testid': `mv-tab-${k}`, 'aria-selected': S.view === k ? 'true' : 'false' }, label);
            b.addEventListener('click', () => { S.view = k; renderData(); });
            return b;
        }));
        // Harian: a client-side date filter (the rows are already loaded). Per Barang has its own server-side search / filter toolbar inside the table area.
        const search = S.view === 'harian' ? UI.el('input', { type: 'text', class: 'rp-input mv-search', 'data-testid': 'mv-search', placeholder: 'Cari tanggal…', value: S.dayQ }) : null;
        if (search) {
            let timer = null;
            search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { S.dayQ = search.value.trim(); S.dailyPage = 1; const b = $('mv-data-body'); b.innerHTML = ''; renderDaily(b); }, 250); });
        }
        const dl = UI.el('button', { type: 'button', class: 'btn btn-secondary rp-btn', 'data-testid': 'mv-download-detail' }, '⬇ Download Detail');
        dl.addEventListener('click', async () => { dl.disabled = true; try { await doExcel(); } catch (e) { UI.toast ? UI.toast(e.message, 'error') : alert(e.message); } finally { dl.disabled = false; } });
        host.appendChild(UI.el('div', { class: 'rp-card-head' }, [
            UI.el('div', { class: 'rp-card-title' }, [S.view === 'harian' ? 'Detail Pergerakan Stok Harian ' : 'Detail Pergerakan Per Barang ', UI.el('span', { class: 'rp-sub' }, S.view === 'harian' ? '(klik baris untuk rincian barang pada tanggal itu)' : '(klik baris untuk riwayat transaksi barang)')]),
            UI.el('div', { class: 'rp-tools' }, [tabs, search, dl].filter(Boolean)),
        ]));
        const body = UI.el('div', { id: 'mv-data-body' });
        host.appendChild(body);
        if (S.view === 'harian') renderDaily(body); else loadItems(body);
    }

    function renderDaily(host) {
        const ov = S.overview; const nominal = S.mode === 'nominal';
        const dq = S.dayQ.toLowerCase();
        const rows = dq ? ov.rows.filter((r) => `${fmtDate(r.date)} ${r.date}`.toLowerCase().includes(dq)) : ov.rows;
        if (!ov.rows.length || (ov.rows.every((r) => r.is_pre_go_live))) { host.appendChild(UI.el('div', { class: 'rp-empty', 'data-testid': 'mv-empty' }, 'Tidak ada pergerakan pada periode ini.')); return; }
        if (!rows.length) { host.appendChild(UI.el('div', { class: 'rp-empty', 'data-testid': 'mv-day-empty' }, 'Tidak ada tanggal yang cocok dengan pencarian.')); return; }
        if (!nominal) host.appendChild(unitSummary());
        const total = Math.max(1, Math.ceil(rows.length / S.dailyPer));
        S.dailyPage = Math.min(S.dailyPage, total);
        const slice = rows.slice((S.dailyPage - 1) * S.dailyPer, S.dailyPage * S.dailyPer);
        const heads = nominal
            ? ['Tanggal', 'Stok Awal', 'Barang Masuk', 'Barang Keluar', 'Transfer IN', 'Transfer OUT', 'Adjustment', 'Stok Akhir']
            : ['Tanggal', 'SKU Awal', 'Masuk (Trx / SKU)', 'Keluar (Trx / SKU)', 'Adjustment (Trx)', 'SKU Akhir', 'SKU Bergerak'];
        const trs = slice.map((r) => {
            const sp = r.split; const c = r.counts;
            const tr = UI.el('tr', { 'data-date': r.date, 'data-testid': 'mv-daily-row', class: r.is_pre_go_live ? 'dim' : 'click' });
            tr.appendChild(td(fmtDate(r.date)));
            if (r.is_pre_go_live) { for (let i = 1; i < heads.length; i++) tr.appendChild(td('—', 'num dim')); }
            else if (nominal) {
                [sp.opening, sp.in, sp.out, sp.tin, sp.tout].forEach((v) => tr.appendChild(td(rp(v), 'num')));
                tr.appendChild(td(signedRp(sp.adjustment), `num${tone(sp.adjustment)}`));
                tr.appendChild(td(rp(sp.closing), 'num'));
            } else {
                [UI.formatNumber(c.sku_opening, 0), `${c.tx_in} / ${c.sku_in}`, `${c.tx_out} / ${c.sku_out}`, UI.formatNumber(c.tx_other, 0), UI.formatNumber(c.sku_closing, 0), UI.formatNumber(c.sku_moved, 0)].forEach((v) => tr.appendChild(td(v, 'num')));
            }
            if (!r.is_pre_go_live) tr.addEventListener('click', () => openDay(r.date));
            return tr;
        });
        const t = ov.split_totals; const tt = ov.totals;
        const foot = UI.el('tr', { 'data-testid': 'mv-daily-total' }, (nominal
            ? ['TOTAL PERIODE', rp(t.opening), rp(t.in), rp(t.out), rp(t.tin), rp(t.tout), signedRp(t.adjustment), rp(t.closing)]
            : ['TOTAL PERIODE', UI.formatNumber(tt.sku_opening, 0), `${tt.tx_in} / ${tt.sku_in}`, `${tt.tx_out} / ${tt.sku_out}`, UI.formatNumber(tt.tx_other, 0), UI.formatNumber(tt.sku_closing, 0), '—']).map((v, i) => td(v, i ? 'num' : '')));
        host.appendChild(UI.el('div', { class: 'rp-scroll', 'data-testid': 'mv-daily-scroll' }, [UI.el('table', { class: 'rp-table', 'data-testid': 'mv-daily-table' }, [
            UI.el('thead', {}, [UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: i ? 'num' : '' }, h)))]), UI.el('tbody', {}, trs), UI.el('tfoot', {}, [foot])])]));
        if (rows.length > S.dailyPer) host.appendChild(pager(S.dailyPage, total, rows.length, (p) => { S.dailyPage = p; $('mv-data-body').innerHTML = ''; renderDaily($('mv-data-body')); }, 'hari'));
    }

    function unitSummary() {
        const u = S.overview.qty_units || [];
        const heads = ['Satuan', 'Qty Awal', 'Qty Masuk', 'Qty Keluar', 'Transfer IN', 'Transfer OUT', 'Adjustment', 'Qty Akhir'];
        const rows = u.map((x) => UI.el('tr', { 'data-testid': 'mv-unit-row' }, [td(x.unit), ...['opening', 'in', 'out', 'tin', 'tout', 'adjustment', 'closing'].map((k) => td(qn(x[k]), 'num'))]));
        return UI.el('div', { style: 'margin-bottom:10px;' }, [
            UI.el('div', { class: 'rp-hint', style: 'margin-bottom:4px;' }, 'Ringkasan per satuan — kuantitas berbeda satuan tidak dijumlahkan.'),
            UI.el('div', { class: 'rp-scroll free' }, [UI.el('table', { class: 'rp-table', 'data-testid': 'mv-unit-table' }, [UI.el('thead', {}, [UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: i ? 'num' : '' }, h)))]), UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length) }, '—')])])])]),
        ]);
    }

    // ------------------------------------------------------------------ per-item table (server pagination / sort)
    const ITEM_COLS_NOM = [['sku', 'SKU', (r) => r.sku, ''], ['name', 'Nama Barang', (r) => r.name, 'wrap'], ['unit', 'Satuan', (r) => r.unit, ''],
        ['opening_value', 'Stok Awal', (r) => rp(r.opening_value), 'num'], ['in_value', 'Masuk', (r) => rp(r.in_value), 'num'], ['out_value', 'Keluar (HPP)', (r) => rp(r.out_value), 'num'],
        ['tin_value', 'Transfer IN', (r) => rp(r.tin_value), 'num'], ['tout_value', 'Transfer OUT', (r) => rp(r.tout_value), 'num'], ['adjustment_value', 'Adjustment', (r) => signedRp(r.adjustment_value), 'num'], ['closing_value', 'Stok Akhir', (r) => rp(r.closing_value), 'num']];
    const ITEM_COLS_QTY = [['sku', 'SKU', (r) => r.sku, ''], ['name', 'Nama Barang', (r) => r.name, 'wrap'], ['unit', 'Satuan', (r) => r.unit, ''],
        ['opening_qty', 'Qty Awal', (r) => qn(r.opening_qty), 'num'], ['in_qty', 'Masuk', (r) => qn(r.in_qty), 'num'], ['out_qty', 'Keluar', (r) => qn(r.out_qty), 'num'],
        ['tin_qty', 'Transfer IN', (r) => qn(r.tin_qty), 'num'], ['tout_qty', 'Transfer OUT', (r) => qn(r.tout_qty), 'num'], ['adjustment_qty', 'Adjustment', (r) => (r.adjustment_qty > 0 ? '+' : '') + qn(r.adjustment_qty), 'num'],
        ['closing_qty', 'Qty Akhir', (r) => qn(r.closing_qty), 'num'], ['closing_value', 'Nilai Akhir', (r) => rp(r.closing_value), 'num']];

    async function loadItems(host) {
        const t = ++S.itemTicket;
        host.innerHTML = '<div class="rp-loading">Memuat rincian per barang…</div>';
        let d;
        try {
            d = await api('/reports/movement/v3/items', params({ sort: S.sort, dir: S.dir, page: S.itemPage, per_page: S.itemPer, move: S.itemMove || undefined, q: S.itemQ || S.q || undefined }));
        } catch (err) {
            if (t !== S.itemTicket) return;
            host.innerHTML = '';
            const retry = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, 'Coba lagi');
            retry.addEventListener('click', () => loadItems(host));
            host.appendChild(UI.el('div', { class: 'rp-banner bad' }, [`Gagal memuat rincian: ${(err && err.message) || ''} `, retry]));
            return;
        }
        if (t !== S.itemTicket) return;
        S.items = d;
        renderItems(host);
    }

    function renderItems(host) {
        host.innerHTML = '';
        const d = S.items; const nominal = S.mode === 'nominal';
        const cols = nominal ? ITEM_COLS_NOM : ITEM_COLS_QTY;
        const search = UI.el('input', { type: 'text', class: 'rp-input', style: 'max-width:280px;', placeholder: 'Cari kode / nama barang…', value: S.itemQ, 'data-testid': 'mv-item-q' });
        const mv = UI.el('select', { class: 'rp-input', style: 'max-width:190px;', 'data-testid': 'mv-item-move' }, [['', 'Semua Jenis'], ['in', 'Ada Barang Masuk'], ['out', 'Ada Barang Keluar'], ['transfer', 'Ada Transfer'], ['adjustment', 'Ada Adjustment']].map(([v, l]) => UI.el('option', { value: v }, l)));
        mv.value = S.itemMove;
        const per = UI.el('select', { class: 'rp-input', style: 'max-width:110px;', 'data-testid': 'mv-item-per' }, [25, 50, 100].map((n) => UI.el('option', { value: String(n) }, `${n} / hal`)));
        per.value = String(S.itemPer);
        let timer;
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { S.itemQ = search.value.trim(); S.itemPage = 1; loadItems(host); }, 350); });
        mv.addEventListener('change', () => { S.itemMove = mv.value; S.itemPage = 1; loadItems(host); });
        per.addEventListener('change', () => { S.itemPer = Number(per.value); S.itemPage = 1; loadItems(host); });
        host.appendChild(UI.el('div', { class: 'rp-bar', style: 'margin-bottom:8px;' }, [UI.el('div', { class: 'rp-inline' }, [search, mv]), per]));
        const head = UI.el('tr', {}, cols.map(([key, label, , cls]) => {
            const th = UI.el('th', { class: cls, 'data-sort': key, tabindex: '0' }, `${label}${S.sort === key ? (S.dir === 'asc' ? ' ▲' : ' ▼') : ''}`);
            th.addEventListener('click', () => { if (S.sort === key) S.dir = S.dir === 'asc' ? 'desc' : 'asc'; else { S.sort = key; S.dir = 'asc'; } S.itemPage = 1; loadItems(host); });
            return th;
        }));
        const trs = d.rows.map((r) => {
            const tr = UI.el('tr', { class: 'click', 'data-testid': 'mv-item-row', 'data-item': String(r.item_id) }, cols.map(([, , get, cls], i) => {
                const cell = td(get(r), cls);
                if (cols[i][0] === 'adjustment_value' || cols[i][0] === 'adjustment_qty') cell.className += tone(nominal ? r.adjustment_value : r.adjustment_qty);
                return cell;
            }));
            tr.addEventListener('click', () => openItem(r));
            return tr;
        });
        const T = d.totals;
        const sumKey = { opening_value: T.opening_value, in_value: T.in_value, out_value: T.out_value, tin_value: T.tin_value, tout_value: T.tout_value, adjustment_value: T.adjustment_value, closing_value: T.closing_value };
        const foot = UI.el('tr', { 'data-testid': 'mv-items-total' }, cols.map(([key], i) => {
            if (i === 0) return td('TOTAL');
            if (i === 1) return td(`${UI.formatNumber(T.sku_count, 0)} barang`);
            if (key in sumKey) return td(key === 'adjustment_value' ? signedRp(sumKey[key]) : rp(sumKey[key]), 'num');
            return td('', 'num');
        }));
        host.appendChild(UI.el('div', { class: 'rp-scroll', 'data-testid': 'mv-items-scroll' }, [UI.el('table', { class: 'rp-table', 'data-testid': 'mv-items-table' }, [
            UI.el('thead', {}, [head]), UI.el('tbody', {}, trs.length ? trs : [UI.el('tr', {}, [UI.el('td', { colspan: String(cols.length) }, 'Tidak ada barang pada filter ini.')])]), UI.el('tfoot', {}, [foot])])]));
        if (!nominal) host.appendChild(UI.el('div', { class: 'rp-note', 'data-testid': 'mv-items-units', style: 'margin-top:6px;' }, 'Qty memakai satuan dasar masing-masing barang dan tidak dijumlahkan lintas satuan; baris TOTAL hanya menjumlahkan nilai (Rp).'));
        host.appendChild(pager(d.pagination.page, d.pagination.total_pages, d.pagination.total, (p) => { S.itemPage = p; loadItems(host); }, 'barang'));
    }

    function pager(page, totalPages, total, onPage, label) {
        const bar = UI.el('div', { class: 'rp-pager' }, [UI.el('span', {}, `Total ${UI.formatNumber(total, 0)} ${label}`)]);
        const nav = UI.el('div', { class: 'rp-pager-nav' });
        const btn = (text, p, disabled, cur) => { const b = UI.el('button', { type: 'button', class: `rp-pg${cur ? ' on' : ''}`, ...(disabled ? { disabled: 'disabled' } : {}) }, text); b.addEventListener('click', () => onPage(p)); return b; };
        nav.appendChild(btn('‹', page - 1, page <= 1));
        let prev = 0;
        [...new Set([1, totalPages, page, page - 1, page + 1].filter((p) => p >= 1 && p <= totalPages))].sort((a, b) => a - b).forEach((p) => { if (p - prev > 1) nav.appendChild(UI.el('span', { class: 'rp-dim' }, '…')); nav.appendChild(btn(String(p), p, false, p === page)); prev = p; });
        nav.appendChild(btn('›', page + 1, page >= totalPages));
        bar.appendChild(nav);
        return bar;
    }

    // ------------------------------------------------------------------ drawers
    function wideDrawer(title, fill) {
        Drawer.open({ title, render: (body) => { fill(body); } });
        const panel = document.querySelector('.drawer');
        if (!panel) return;
        panel.classList.add('rp-drawer');
        const mo = new MutationObserver(() => { if (!panel.classList.contains('open')) { panel.classList.remove('rp-drawer'); mo.disconnect(); } });
        mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    function simpleTable(heads, rows, numFrom, clicks) {
        const trs = rows.map((r, i) => {
            const tr = UI.el('tr', { class: clicks ? 'click' : '' }, r.map((v, j) => td(nil(v) ? '—' : v, j >= numFrom ? 'num' : '')));
            if (clicks) tr.addEventListener('click', clicks[i]);
            return tr;
        });
        return UI.el('div', { class: 'rp-scroll' }, [UI.el('table', { class: 'rp-table' }, [UI.el('thead', {}, [UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: i >= numFrom ? 'num' : '' }, h)))]),
            UI.el('tbody', {}, trs.length ? trs : [UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length) }, 'Tidak ada data.')])])])]);
    }
    const openTx = (id) => { if (typeof TraceDrawer !== 'undefined' && TraceDrawer.openTransaction) TraceDrawer.openTransaction(id); };

    function openPeriod(bucket, label) {
        wideDrawer(`${label} — ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`, async (body) => {
            body.innerHTML = '<div class="rp-loading">Memuat transaksi…</div>';
            try {
                const res = await api('/reports/movement/period-transactions', params({ bucket, per_page: 100 }));
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'rp-dsum', 'data-testid': 'mv-ptx-total' }, `${UI.formatNumber(res.pagination.total, 0)} baris ledger · total ${bucket === 'other' ? signedRp(res.totals.value) : UI.formatMoney(res.totals.abs_value)}${res.pagination.total > 100 ? ' (100 baris pertama — daftar lengkap ada di Download Excel)' : ''}`));
                body.appendChild(simpleTable(['Waktu', 'Jenis', 'No. Referensi', 'Gudang', 'Kode', 'Nama Barang', 'Qty (satuan dasar)', 'Nilai'],
                    res.rows.map((r) => [fmtTs(r.transaction_date || r.timestamp), r.type_label || r.transaction_type, r.reference_no || '—', r.warehouse_code, r.sku, r.item_name, qn(r.qty), bucket === 'other' ? signedRp(r.signed_value) : rp(r.value)]), 6, res.rows.map((r) => () => openTx(r.transaction_id))));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    function openDay(date) {
        wideDrawer(`Rincian per barang — ${fmtDate(date)}`, async (body) => {
            body.innerHTML = '<div class="rp-loading">Memuat rincian…</div>';
            try {
                const d = await api('/reports/movement/day-items', params({ date, moved_only: '1', per_page: 100, page: 1 }));
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'rp-dsum', 'data-testid': 'mv-day-sum' }, `${UI.formatNumber(d.pagination.total, 0)} barang bergerak · Saldo akhir hari ${rp(d.totals.closing_value)}${d.pagination.total > 100 ? ' (100 barang pertama)' : ''}`));
                body.appendChild(simpleTable(['Kode', 'Nama Barang', 'Satuan', 'Qty Awal', 'Qty Masuk', 'Qty Keluar', 'Qty Adjustment', 'Qty Akhir', 'Nilai Akhir', 'Transaksi'],
                    d.rows.map((r) => [r.sku, r.name, r.unit, qn(r.opening_qty), qn(r.masuk_qty), qn(r.keluar_qty), (r.adjustment_qty > 0 ? '+' : '') + qn(r.adjustment_qty), qn(r.closing_qty), rp(r.closing_value), UI.formatNumber(r.tx_count, 0)]), 3));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    function openItem(row) {
        wideDrawer(`${row.sku} — ${row.name}`, async (body) => {
            body.innerHTML = '<div class="rp-loading">Memuat riwayat transaksi…</div>';
            try {
                const base = params({ item_id: row.item_id, q: undefined, per_page: 100 });
                const all = await Promise.all(['masuk', 'keluar', 'other'].map((bucket) => api('/reports/movement/period-transactions', { ...base, bucket })));
                const rows = all.flatMap((r) => r.rows).sort((a, b) => String(a.transaction_date || a.timestamp).localeCompare(String(b.transaction_date || b.timestamp)) || a.transaction_id - b.transaction_id);
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'rp-dsum', 'data-testid': 'mv-item-sum' }, [
                    UI.el('span', {}, `Stok awal ${qn(row.opening_qty)} ${row.unit} · ${rp(row.opening_value)}`), UI.el('span', {}, `Stok akhir ${qn(row.closing_qty)} ${row.unit} · ${rp(row.closing_value)}`),
                    UI.el('span', {}, `${UI.formatNumber(rows.length, 0)} baris ledger pada periode`)]));
                body.appendChild(simpleTable(['Waktu', 'Jenis', 'No. Referensi', 'Gudang', `Qty (${row.unit})`, 'Nilai (signed)'],
                    rows.map((r) => [fmtTs(r.transaction_date || r.timestamp), r.type_label || r.transaction_type, r.reference_no || '—', r.warehouse_code, qn(r.qty), signedRp(r.signed_value)]), 4, rows.map((r) => () => openTx(r.transaction_id))));
            } catch (err) { body.innerHTML = ''; UI.handleApiError(err); }
        });
    }

    // ------------------------------------------------------------------ Cetak + Download Excel (the same server tables as the file)
    const exportParams = () => params({ mode: S.mode });
    async function doExcel() {
        await ReportTools.download(`/api/reports/movement/v3/export${ReportTools.qsOf(exportParams())}`, 'Laporan_Pergerakan_Stok.xlsx');
    }
    async function doPrint() {
        const payload = await api('/reports/movement/v3/export', { ...exportParams(), format: 'json' });
        const sp = S.overview.split_totals;
        const nominal = S.mode === 'nominal';
        const picks = [];
        if (!nominal) picks.push({ sheet: 'Per Satuan (Qty)', title: 'Ringkasan per Satuan', note: 'Kuantitas berbeda satuan tidak dijumlahkan.' });
        if (S.view === 'harian') {
            picks.push(nominal
                ? { sheet: 'Harian', title: 'Ringkasan Harian', columns: ['Tanggal', 'Stok Awal', 'Barang Masuk (IN)', 'Barang Keluar (OUT)', 'Transfer IN', 'Transfer OUT', 'Adjustment / Lain', 'Stok Akhir'] }
                : { sheet: 'Harian', title: 'Ringkasan Harian (nominal)', columns: ['Tanggal', 'Stok Awal', 'Barang Masuk (IN)', 'Barang Keluar (OUT)', 'Transfer IN', 'Transfer OUT', 'Adjustment / Lain', 'Stok Akhir'] });
        } else {
            picks.push({ sheet: 'Per Barang', title: 'Rincian per Barang', maxRows: 3000, columns: nominal
                ? ['SKU', 'Nama Barang', 'Satuan', 'Nilai Awal', 'Nilai Masuk (IN)', 'HPP Keluar (OUT)', 'Nilai Transfer IN', 'Nilai Transfer OUT', 'Nilai Adjustment', 'Nilai Akhir']
                : ['SKU', 'Nama Barang', 'Satuan', 'Qty Awal', 'Qty Masuk (IN)', 'Qty Keluar (OUT)', 'Qty Transfer IN', 'Qty Transfer OUT', 'Qty Adjustment', 'Qty Akhir', 'Nilai Akhir'] });
        }
        const spec = ReportTools.specFromPayload(payload, picks, {
            subtitle: `Periode ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`,
            meta: ReportTools.metaPairs(payload.meta).filter((m) => ['Periode', 'Gudang', 'Kategori', 'Pencarian barang', 'Tampilan'].includes(m[0])).concat([['Tab', S.view === 'harian' ? 'Harian' : 'Per Barang']]),
            kpis: (() => {
                const u = S.overview.qty_units || [];
                const val = (k) => (nominal ? rp(sp[k]) : unitsText(u, k));
                return [{ label: 'Stok Awal', value: val('opening') }, { label: 'Barang Masuk (IN)', value: val('in') }, { label: 'Barang Keluar (OUT)', value: val('out') }, { label: 'Transfer IN', value: val('tin') },
                    { label: 'Transfer OUT', value: val('tout') }, { label: 'Adjustment', value: nominal ? signedRp(sp.adjustment) : unitsText(u, 'adjustment') }, { label: 'Stok Akhir', value: val('closing') }];
            })(),
            orientation: 'landscape',
        });
        ReportTools.printDocument(spec);
    }

    return { render };
})();
