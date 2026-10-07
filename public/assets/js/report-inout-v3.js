/**
 * Laporan > Laporan IN / OUT — one page, three tabs: Barang Masuk (IN) · Barang Keluar (OUT) · Transfer Antar Gudang.
 *
 * Every number comes from GET /api/reports/io/* (InOutReportService); this file only renders them — it never recalculates a value.
 *   IN       Stock IN V2 invoice arithmetic (Subtotal Barang − … + PPN − Diskon Invoice + Ongkir = Grand Total); legacy rows only know their recorded value.
 *   OUT      HPP = the stored FIFO cost; Nilai Jual = the invoice line stored at posting; Margin = Nilai Jual − HPP; shipping per line is a labelled DERIVED allocation.
 *   TRANSFER cost layers are preserved (value out = value in); Qty Diterima / lead time are unknown ("—") until the transfer is received.
 * READ-ONLY: only GET requests are issued. Quantities of different units are never added. Unknown values show "—", never a fabricated 0.
 * Self-contained: the page issues its own same-origin GETs (session cookie) instead of going through InvApi, so it works with whichever api-client file a deployment executes.
 */
const ReportInOutV3 = (() => {
    let S = null;

    // ------------------------------------------------------------------ API (read-only GET)
    const qsOf = (params) => {
        const clean = {};
        Object.keys(params || {}).forEach((k) => { if (params[k] !== undefined && params[k] !== null && params[k] !== '') clean[k] = params[k]; });
        const q = new URLSearchParams(clean).toString();
        return q ? `?${q}` : '';
    };
    async function apiGet(path, params) {
        let res;
        try {
            res = await fetch(`/api${path}${qsOf(params)}`, { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } });
        } catch (e) {
            const err = new Error('Sistem sedang tidak dapat terhubung ke server.');
            err.code = 'NETWORK_ERROR';
            throw err;
        }
        let payload;
        try { payload = await res.json(); } catch (e) { payload = null; }
        if (!payload || !payload.success) {
            const err = new Error((payload && payload.error && payload.error.message) || `Request failed (${res.status})`);
            err.code = (payload && payload.error && payload.error.code) || 'UNKNOWN_ERROR';
            err.status = res.status;
            throw err;
        }
        return payload.data;
    }
    const IoApi = {
        options: () => apiGet('/reports/io/options'),
        overview: (p) => apiGet('/reports/io/overview', p),
        list: (p) => apiGet('/reports/io/list', p),
        detail: (p) => apiGet('/reports/io/detail', p),
        exportUrl: (p) => `/api/reports/io/export${qsOf(p)}`,
    };

    // ------------------------------------------------------------------ formatting
    const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nil = (v) => v === null || v === undefined || v === '';
    const rp = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const qn = (v) => (nil(v) ? '—' : UI.formatNumber(v, 3));
    const int = (v) => (nil(v) ? '—' : UI.formatNumber(v, 0));
    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
        return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    const fmtTs = (v) => (v ? `${fmtDate(v)} ${String(v).slice(11, 16)}` : '—');
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (d, n) => { const x = new Date(d.getTime()); x.setDate(x.getDate() + n); return x; };
    function quickRange(kind) {
        const n = new Date();
        if (kind === 'today') return { start: iso(n), end: iso(n) };
        if (kind === '7') return { start: iso(addDays(n, -6)), end: iso(n) };
        if (kind === '30') return { start: iso(addDays(n, -29)), end: iso(n) };
        return { start: iso(new Date(n.getFullYear(), n.getMonth(), 1)), end: iso(new Date(n.getFullYear(), n.getMonth() + 1, 0)) };
    }
    const delta = (d) => {
        if (!d || nil(d.pct)) return { text: '—', cls: 'flat', title: 'Periode sebelumnya tidak memiliki nilai pembanding' };
        const up = d.pct > 0; const down = d.pct < 0;
        return { text: `${up ? '▲ +' : down ? '▼ ' : ''}${UI.formatNumber(d.pct, 1)}%`, cls: up ? 'up' : down ? 'down' : 'flat', title: 'Dibanding periode sebelumnya dengan panjang yang sama' };
    };
    const unitsText = (list) => (list && list.length ? list.map((u) => `${u.unit} ${UI.formatNumber(u.qty, 3)}`).join(' · ') : '—');
    const TABS = [['in', 'Barang Masuk (IN)', '📥'], ['out', 'Barang Keluar (OUT)', '📤'], ['transfer', 'Transfer Antar Gudang', '🚚']];
    const TITLES = {
        in: ['Daftar Transaksi Barang Masuk', 'Rincian Item', 'Cari no. invoice, supplier, atau barang…'],
        out: ['Daftar Transaksi Barang Keluar', 'Rincian Item', 'Cari no. DO, invoice, bakery, atau barang…'],
        transfer: ['Daftar Transfer Antar Gudang', 'Rincian Item Transfer', 'Cari no. transfer, gudang, atau barang…'],
    };
    const SORTABLE = {
        in: { date: 'date', reference: 'reference', supplier: 'supplier', total: 'total' },
        out: { date: 'date', do_number: 'do_number', bakery: 'bakery', hpp: 'hpp', sell: 'sell' },
        transfer: { created_date: 'date', number: 'number', from: 'from', to: 'to', value: 'value' },
    };

    function freshState(tab) {
        const r = quickRange('month');
        return { root: null, tab: tab || 'in', start: r.start, end: r.end, quick: 'month', warehouse: '', category: '', q: '', supplier: '', bakery: '', division: '', from: '', to: '', status: '', source: '',
            gq: '', view: 'tx', bucket: 'day', page: 1, perPage: 10, sort: '', dir: 'desc', options: null, overview: null, list: null, selected: null, detail: null, loadTicket: 0, detailTicket: 0, listTicket: 0 };
    }
    function params(extra) {
        const p = { tab: S.tab, start_date: S.start, end_date: S.end, warehouse_id: S.warehouse || undefined, category_id: S.category || undefined, q: S.q || undefined, status: S.status || undefined, bucket: S.bucket };
        if (S.tab === 'in') { p.supplier_id = S.supplier || undefined; p.in_source = S.source || undefined; }
        if (S.tab === 'out') { p.bakery_destination_id = S.bakery || undefined; p.division_id = S.division || undefined; p.out_source = S.source || undefined; }
        if (S.tab === 'transfer') { p.from_warehouse_id = S.from || undefined; p.to_warehouse_id = S.to || undefined; }
        return { ...p, ...extra };
    }
    const q$ = (sel) => S.root.querySelector(sel);

    // ------------------------------------------------------------------ render
    function render(container, opts) {
        // a sibling mount of this page (laporan-inout / laporan-transfer share the component) must not keep stale, same-id DOM
        ['tab-laporan-inout', 'tab-laporan-transfer'].forEach((id) => { const el = document.getElementById(id); if (el && el !== container) el.innerHTML = ''; });
        S = freshState((opts && opts.tab) || 'in');
        S.root = container;
        container.innerHTML = '';
        container.classList.add('io');
        ReportTools.onResize('io-chart', () => { if (S && S.overview && q$('#io-chart') && q$('#io-chart').offsetParent) renderChart(); });
        const exportBtn = ReportTools.actions({ id: 'io', print: doPrint, excel: () => doExcel(S.tab), extras: [{ label: 'Download Semua Tab (3 sheet)', testid: 'io-excel-all', run: () => doExcel('all') }] });
        container.appendChild(UI.el('div', { class: 'io-head' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'io-title', 'data-testid': 'io-title' }, 'Laporan IN / OUT'),
                UI.el('p', { class: 'io-desc' }, 'Analisis barang masuk, barang keluar, dan transfer antar gudang.'),
            ]),
            exportBtn,
        ]));
        container.appendChild(UI.el('div', { class: 'io-tabs', role: 'tablist', 'data-testid': 'io-tabs' }, TABS.map(([key, label, icon]) => {
            const b = UI.el('button', { type: 'button', role: 'tab', class: `io-tab io-tab-${key}`, 'data-tab': key, 'data-testid': `io-tab-${key}`, 'aria-selected': 'false' }, [UI.el('span', { class: 'io-tab-ic' }, icon), UI.el('span', { class: 'io-tab-tx' }, label)]);
            b.addEventListener('click', () => switchTab(key));
            return b;
        })));
        container.appendChild(UI.el('div', { class: 'io-card io-filters', id: 'io-filters', 'data-testid': 'io-filters' }));
        container.appendChild(UI.el('div', { id: 'io-banner', 'data-testid': 'io-banner' }));
        container.appendChild(UI.el('div', { id: 'io-kpis', class: 'io-kpis', 'data-testid': 'io-kpis' }));
        container.appendChild(UI.el('div', { id: 'io-chart', class: 'io-card', 'data-testid': 'io-chart-card' }));
        container.appendChild(UI.el('div', { id: 'io-list', class: 'io-card', 'data-testid': 'io-list-card' }));
        container.appendChild(UI.el('div', { id: 'io-detail', class: 'io-card', 'data-testid': 'io-detail-card', hidden: 'hidden' }));
        paintTabs();
        buildFilters();
        IoApi.options().then((o) => { S.options = o; buildFilters(); }).catch(() => {}).finally(() => loadAll());
    }

    function paintTabs() {
        S.root.querySelectorAll('.io-tab').forEach((b) => { const on = b.dataset.tab === S.tab; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    }
    function switchTab(key) {
        if (key === S.tab) return;
        S.tab = key; S.status = ''; S.source = ''; S.gq = ''; S.page = 1; S.sort = ''; S.dir = 'desc'; S.selected = null; S.detail = null;
        S.supplier = ''; S.bakery = ''; S.division = ''; S.from = ''; S.to = '';
        S.overview = null; S.list = null;
        paintTabs(); buildFilters(); loadAll();
    }

    // ------------------------------------------------------------------ filters
    function buildFilters() {
        const o = S.options || { warehouses: [], categories: [], suppliers: [], bakeries: [], divisions: [], all_warehouses: [] };
        const opts = (list, sel, all) => `<option value="">${esc(all)}</option>` + (list || []).map((x) => `<option value="${esc(x.id !== undefined ? x.id : x.value)}"${String(x.id !== undefined ? x.id : x.value) === String(sel) ? ' selected' : ''}>${esc(x.name || x.label)}</option>`).join('');
        const scoped = !!(Auth.user() && Auth.user().role_code === 'STOCK' && Auth.user().warehouse_id);
        const tab = S.tab;
        const chips = [['today', 'Hari Ini'], ['7', '7 Hari'], ['30', '30 Hari'], ['month', 'Bulan Ini']].map(([k, l]) => `<button type="button" class="io-chip${S.quick === k ? ' on' : ''}" data-quick="${k}" data-testid="io-quick-${k}">${l}</button>`).join('')
            + `<button type="button" class="io-chip${S.quick === 'custom' ? ' on' : ''}" data-quick="custom" data-testid="io-quick-custom">Custom</button>`;
        const sources = tab === 'in' ? o.in_sources : tab === 'out' ? o.out_sources : [];
        const statuses = tab === 'in' ? o.in_status : tab === 'out' ? o.out_status : o.trf_status;
        const field = (label, inner, cls) => `<div class="io-f ${cls || ''}"><label>${label}</label>${inner}</div>`;
        const parts = [
            field('Periode Tanggal', `<div class="io-dates"><input type="date" id="io-start" data-testid="io-start" value="${esc(S.start)}"><span>—</span><input type="date" id="io-end" data-testid="io-end" value="${esc(S.end)}"></div><div class="io-chips">${chips}</div>`, 'io-f-period s4'),
            field('Gudang', `<select id="io-wh" data-testid="io-wh" ${scoped ? 'disabled' : ''}>${opts(o.warehouses, S.warehouse, 'Semua Gudang')}</select>`, 's2'),
            field('Kategori', `<select id="io-cat" data-testid="io-cat">${opts(o.categories, S.category, 'Semua Kategori')}</select>`, 's2'),
            field('Barang / SKU', `<input type="text" id="io-q" data-testid="io-q" placeholder="Cari nama barang / SKU…" autocomplete="off" value="${esc(S.q)}">`, 's4'),
        ];
        if (tab === 'in') parts.push(field('Supplier', `<select id="io-sup" data-testid="io-sup">${opts(o.suppliers, S.supplier, 'Semua Supplier')}</select>`, 's4'));
        if (tab === 'out') {
            parts.push(field('Bakery Tujuan', `<select id="io-bak" data-testid="io-bak">${opts(o.bakeries, S.bakery, 'Semua Bakery')}</select>`, 's3'));
            parts.push(field('Divisi', `<select id="io-div" data-testid="io-div">${opts(o.divisions, S.division, 'Semua Divisi')}</select>`, 's2'));
        }
        if (tab === 'transfer') {
            parts.push(field('Gudang Asal', `<select id="io-from" data-testid="io-from">${opts(o.all_warehouses, S.from, 'Semua Gudang Asal')}</select>`, 's3'));
            parts.push(field('Gudang Tujuan', `<select id="io-to" data-testid="io-to">${opts(o.all_warehouses, S.to, 'Semua Gudang Tujuan')}</select>`, 's3'));
        }
        if (tab !== 'transfer') parts.push(field(tab === 'in' ? 'Jenis Transaksi IN' : 'Jenis Transaksi OUT', `<select id="io-src" data-testid="io-src">${opts(sources, S.source, 'Semua Jenis')}</select>`, 's2'));
        parts.push(field('Status', `<select id="io-status" data-testid="io-status">${opts(statuses, S.status, 'Semua Status')}</select>`, 's2'));
        parts.push(`<div class="io-f io-f-actions ${tab === 'in' ? 's4' : tab === 'out' ? 's3' : 's4'}"><div class="io-filter-actions"><button type="button" class="btn btn-primary" id="io-apply" data-testid="io-apply">Terapkan</button><button type="button" class="btn btn-secondary" id="io-reset" data-testid="io-reset">Reset</button></div></div>`);
        const host = q$('#io-filters');
        host.innerHTML = `<div class="io-filter-grid io-grid-${tab}">${parts.join('')}</div>`;
        host.querySelectorAll('.io-chip').forEach((b) => b.addEventListener('click', () => {
            const k = b.dataset.quick;
            S.quick = k;
            if (k !== 'custom') { const r = quickRange(k); S.start = r.start; S.end = r.end; q$('#io-start').value = r.start; q$('#io-end').value = r.end; applyFilters(); } else { host.querySelectorAll('.io-chip').forEach((x) => x.classList.toggle('on', x === b)); }
        }));
        ['io-start', 'io-end'].forEach((id) => q$(`#${id}`).addEventListener('change', () => { S.quick = 'custom'; host.querySelectorAll('.io-chip').forEach((x) => x.classList.toggle('on', x.dataset.quick === 'custom')); }));
        host.querySelector('#io-apply').addEventListener('click', applyFilters);
        host.querySelector('#io-reset').addEventListener('click', () => { const t = S.tab; const o2 = S.options; const root = S.root; S = freshState(t); S.options = o2; S.root = root; buildFilters(); loadAll(); });
        host.querySelector('#io-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
    }
    function readFilters() {
        const v = (id) => { const e = q$(`#${id}`); return e ? e.value : ''; };
        S.start = v('io-start'); S.end = v('io-end'); S.warehouse = v('io-wh'); S.category = v('io-cat'); S.q = v('io-q').trim(); S.status = v('io-status');
        if (S.tab === 'in') { S.supplier = v('io-sup'); S.source = v('io-src'); }
        if (S.tab === 'out') { S.bakery = v('io-bak'); S.division = v('io-div'); S.source = v('io-src'); }
        if (S.tab === 'transfer') { S.from = v('io-from'); S.to = v('io-to'); }
    }
    function applyFilters() { readFilters(); S.page = 1; S.selected = null; S.detail = null; loadAll(); }

    // ------------------------------------------------------------------ loading (a newer load supersedes an older one: never paint a stale response)
    async function loadAll() {
        const state = S;
        const ticket = (state.loadTicket += 1);
        state.listTicket += 1;
        q$('#io-banner').innerHTML = '';
        const detail = q$('#io-detail'); detail.hidden = true; detail.innerHTML = '';
        q$('#io-kpis').innerHTML = '<div class="io-loading">Memuat laporan…</div>';
        try {
            const [ov, ls] = await Promise.all([IoApi.overview(params()), IoApi.list(params({ gq: S.gq || undefined, page: S.page, per_page: S.perPage, sort: S.sort || undefined, dir: S.dir, view: S.view === 'lines' ? 'lines' : undefined }))]);
            if (state !== S || ticket !== state.loadTicket) return;
            S.overview = ov; S.list = ls;
            renderBanner(); renderKpis(); renderChart(); renderList();
        } catch (err) {
            if (state !== S || ticket !== state.loadTicket) return;
            q$('#io-kpis').innerHTML = '';
            q$('#io-banner').appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'io-error' }, `Gagal memuat laporan: ${err.message}`));
            q$('#io-chart').innerHTML = ''; q$('#io-list').innerHTML = '';
        }
    }
    async function reloadList() {
        const state = S;
        const ticket = (state.listTicket += 1);
        try {
            const ls = await IoApi.list(params({ gq: S.gq || undefined, page: S.page, per_page: S.perPage, sort: S.sort || undefined, dir: S.dir, view: S.view === 'lines' ? 'lines' : undefined }));
            if (state !== S || ticket !== state.listTicket) return;
            S.list = ls; renderList();
        } catch (err) {
            if (state !== S || ticket !== state.listTicket) return;
            q$('#io-list').innerHTML = '';
            q$('#io-list').appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'io-error' }, `Gagal memuat tabel: ${err.message}`));
        }
    }
    async function reloadChart() {
        const state = S;
        const ticket = (state.loadTicket += 1);
        try {
            const ov = await IoApi.overview(params());
            if (state !== S || ticket !== state.loadTicket) return;
            S.overview = ov; renderBanner(); renderKpis(); renderChart();
        } catch (err) { /* keep the previous chart; the next full load reports the error */ }
    }

    // ------------------------------------------------------------------ banner + KPI
    function renderBanner() {
        const host = q$('#io-banner');
        host.innerHTML = '';
        const d = S.overview.disclosures;
        const notes = [];
        if (S.tab === 'in' && d.void.invoices > 0) notes.push(`${d.void.invoices} invoice VOID (${rp(d.void.amount)}) ditampilkan tercoret dan tidak dihitung pada total.`);
        if (S.tab === 'out' && d.void.documents > 0) notes.push(`${d.void.documents} dokumen OUT dibatalkan / di-reverse (HPP ${rp(d.void.hpp)}) ditampilkan tercoret dan tidak dihitung pada total.`);
        if (S.tab === 'transfer' && d.inactive.transfers > 0) notes.push(`${d.inactive.transfers} transfer dibatalkan / di-reverse (nilai ${rp(d.inactive.value)}) ditampilkan tercoret dan tidak dihitung.`);
        notes.forEach((t) => host.appendChild(UI.el('div', { class: 'io-note', 'data-testid': 'io-disclosure' }, `ℹ ${t}`)));
    }
    function renderKpis() {
        const ov = S.overview; const n = ov.kpi.nominal; const dl = ov.delta || {};
        const card = (key, tone, ic, label, value, sub, d) => {
            const dd = d !== undefined ? delta(d) : null;
            return UI.el('div', { class: `io-kpi tone-${tone}`, 'data-testid': `io-kpi-${key}` }, [
                UI.el('div', { class: 'io-kpi-ic' }, ic),
                UI.el('div', { class: 'io-kpi-body' }, [
                    UI.el('div', { class: 'io-kpi-label' }, label),
                    UI.el('div', { class: 'io-kpi-value', 'data-testid': `io-kpi-${key}-value` }, value),
                    UI.el('div', { class: 'io-kpi-sub' }, sub),
                    ...(dd ? [UI.el('div', { class: `io-kpi-delta ${dd.cls}`, title: dd.title, 'data-testid': `io-kpi-${key}-delta` }, [UI.el('b', {}, dd.text), ' vs periode lalu'])] : []),
                ]),
            ]);
        };
        const host = q$('#io-kpis');
        host.innerHTML = '';
        const unitList = unitsText(ov.kpi.qty_by_unit);
        let cards = [];
        if (S.tab === 'in') {
            cards = [
                card('total', 'green', '⬆', 'Total Nilai Masuk', rp(n.total), `Diskon ${rp(n.discount)}`, dl.total),
                card('invoices', 'blue', '🧾', 'Transaksi', `${int(n.invoices)} transaksi`, `Rata-rata ${rp(n.avg_invoice)} / transaksi`, dl.invoices),
                card('skus', 'teal', '📦', 'SKU Masuk', `${int(n.skus)} SKU`, unitList, dl.skus),
                card('suppliers', 'amber', '🛒', 'Supplier', `${int(n.suppliers)} supplier`, `${int(n.invoices)} transaksi`, dl.suppliers),
                card('ppn', 'purple', '％', 'PPN', rp(n.ppn), 'Dari invoice Stock IN V2', dl.ppn),
                card('freight', 'indigo', '🚚', 'Ongkos Kirim', rp(n.freight), 'Ongkir pada invoice', dl.freight),
            ];
        } else if (S.tab === 'out') {
            cards = [
                card('hpp', 'red', '⬇', 'HPP Keluar (FIFO)', rp(n.hpp), n.hpp_without_sell > 0 ? `${rp(n.hpp_without_sell)} tanpa nilai jual (legacy)` : 'Biaya FIFO tersimpan', dl.hpp),
                card('sell', 'green', '💰', 'Nilai Jual (Invoice)', rp(n.sell), `+ Ongkir ${rp(n.shipping)} = ${rp(n.grand_total)}`, dl.sell),
                card('margin', 'teal', '📈', 'Margin', rp(n.margin), nil(n.margin_pct) ? 'Margin % tidak diketahui' : `${UI.formatNumber(n.margin_pct, 2)}% dari nilai jual`, dl.margin),
                card('documents', 'blue', '🧾', 'Transaksi', `${int(n.documents)} transaksi`, `${int(n.skus)} SKU · ${unitList}`, dl.documents),
                card('bakeries', 'amber', '🏪', 'Bakery Tujuan', `${int(n.bakeries)} bakery`, 'Bakery berbeda', dl.bakeries),
                card('shipping', 'indigo', '🚚', 'Ongkos Kirim', rp(n.shipping), 'Ongkir pada invoice', dl.shipping),
            ];
        } else {
            cards = [
                card('transfers', 'blue', '🔁', 'Total Transfer', int(n.transfers), 'Transfer aktif (Pending + Diterima)', dl.transfers),
                card('pending', 'amber', '⏳', 'Pending', int(n.pending), `Dalam perjalanan ${rp(n.in_transit_value)}`, dl.pending),
                card('received', 'green', '✔', 'Diterima', int(n.received), `Nilai ${rp(n.received_value)}`, dl.received),
                card('skus', 'teal', '📦', 'SKU Dipindah', `${int(n.skus)} SKU`, unitList, dl.skus),
                card('value', 'purple', '💲', 'Nilai Cost', rp(n.value), 'Biaya layer asli (tidak di-reprice)', dl.value),
                card('lead', 'indigo', '⏱', 'Lead Time (rata-rata)', nil(n.avg_lead_hours) ? '—' : (n.avg_lead_hours >= 24 ? `${UI.formatNumber(n.avg_lead_hours / 24, 1)} hari` : `${UI.formatNumber(n.avg_lead_hours, 1)} jam`), n.lead_samples > 0 ? `Dari ${int(n.lead_samples)} transfer diterima` : 'Belum ada transfer diterima'),
            ];
        }
        cards.forEach((c) => host.appendChild(c));
    }

    // ------------------------------------------------------------------ chart
    function niceMax(v) { if (v <= 0) return 1; const p = 10 ** Math.floor(Math.log10(v)); const m = v / p; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p; }
    function axisLabel(v) {
        const a = Math.abs(v);
        if (a >= 1e9) return `Rp ${UI.formatNumber(v / 1e9, 1)} M`;
        if (a >= 1e6) return `Rp ${UI.formatNumber(v / 1e6, 1)} jt`;
        if (a >= 1e3) return `Rp ${UI.formatNumber(v / 1e3, 0)} rb`;
        return `Rp ${UI.formatNumber(v, 0)}`;
    }
    function bucketLabel(b) {
        if (S.bucket === 'month') { const [y, m] = b.split('-'); return `${['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][Number(m) - 1]} ${y}`; }
        const d = new Date(`${b}T00:00:00`);
        return S.bucket === 'week' ? `Mgg ${d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}` : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
    }
    function renderChart() {
        const host = q$('#io-chart');
        host.innerHTML = '';
        const titles = { in: ['Grafik Barang Masuk', '(Nominal Rp)', 'Nilai Masuk (Rp)', 'Jumlah Transaksi'], out: ['Grafik Barang Keluar', '(HPP & Nilai Jual Rp)', 'HPP Keluar (Rp)', 'Jumlah Transaksi'], transfer: ['Grafik Transfer Antar Gudang', '(Nilai Cost Rp)', 'Nilai Cost Transfer (Rp)', 'Jumlah Transfer'] }[S.tab];
        const seg = UI.el('div', { class: 'io-seg', role: 'group' }, [['day', 'Harian'], ['week', 'Mingguan'], ['month', 'Bulanan']].map(([k, label]) => {
            const b = UI.el('button', { type: 'button', class: `io-seg-btn${S.bucket === k ? ' on' : ''}`, 'data-testid': `io-bucket-${k}` }, label);
            b.addEventListener('click', () => { if (S.bucket === k) return; S.bucket = k; reloadChart(); });
            return b;
        }));
        host.appendChild(UI.el('div', { class: 'io-card-head' }, [UI.el('div', { class: 'io-card-title' }, [`📊 ${titles[0]} `, UI.el('span', { class: 'io-card-sub' }, titles[1])]), seg]));
        const rows = S.overview.trend;
        if (!rows.length) { host.appendChild(UI.el('div', { class: 'io-empty' }, 'Tidak ada transaksi pada periode ini.')); return; }
        const bar = (r) => (S.tab === 'in' ? r.total : S.tab === 'out' ? r.hpp : r.value);
        const bar2 = S.tab === 'out' ? (r) => r.sell : null;
        const NS = 'http://www.w3.org/2000/svg';
        const W = ReportTools.chartWidth(q$('#io-chart'), 1100); const H = 200; const L = 66; const R = 40; const T = 10; const B = 28;
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('class', 'io-svg'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', titles[0]); svg.setAttribute('data-testid', 'io-chart');
        const max = niceMax(Math.max(...rows.map(bar), ...(bar2 ? rows.map(bar2) : [0]), 0));
        const lmax = niceMax(Math.max(...rows.map((r) => r.count), 0));
        const y = (v) => T + (H - T - B) * (1 - v / max);
        const y2 = (v) => T + (H - T - B) * (1 - v / lmax);
        const el = (name, attrs, text) => { const e = document.createElementNS(NS, name); Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, String(v))); if (text !== undefined) e.textContent = text; return e; };
        for (let i = 0; i <= 4; i++) {
            const v = (max * i) / 4; const yy = y(v);
            svg.appendChild(el('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'io-grid' }));
            svg.appendChild(el('text', { x: L - 8, y: yy + 4, class: 'io-axis', 'text-anchor': 'end' }, axisLabel(v)));
            svg.appendChild(el('text', { x: W - R + 6, y: yy + 4, class: 'io-axis', 'text-anchor': 'start' }, UI.formatNumber((lmax * i) / 4, 0)));
        }
        const step = (W - L - R) / rows.length;
        const bw = Math.max(3, Math.min(bar2 ? 14 : 24, step * (bar2 ? 0.36 : 0.5)));
        const tip = UI.el('div', { class: 'io-tip', hidden: 'hidden', 'data-testid': 'io-tip' });
        const pts = [];
        const every = Math.max(1, Math.ceil(rows.length / 12));
        rows.forEach((r, i) => {
            const cx = L + step * i + step / 2;
            const v = bar(r);
            const x1 = bar2 ? cx - bw - 1 : cx - bw / 2;
            if (v > 0) svg.appendChild(el('rect', { x: x1, y: y(v), width: bw, height: Math.max(1, y(0) - y(v)), rx: 2, class: `io-bar io-bar-${S.tab}` }));
            if (bar2 && bar2(r) > 0) svg.appendChild(el('rect', { x: cx + 1, y: y(bar2(r)), width: bw, height: Math.max(1, y(0) - y(bar2(r))), rx: 2, class: 'io-bar io-bar-sell' }));
            if (r.count > 0) pts.push([cx, y2(r.count)]);   // the count line joins only the buckets that had activity (a zero day is not a dip to join)
            if (i % every === 0) svg.appendChild(el('text', { x: cx, y: H - 11, class: 'io-axis', 'text-anchor': 'middle' }, bucketLabel(r.bucket)));
            const hit = el('rect', { x: L + step * i, y: T, width: step, height: H - T - B, fill: 'transparent', 'data-testid': 'io-hit' });
            hit.addEventListener('mouseenter', () => {
                tip.innerHTML = '';
                const lines = [['Periode', bucketLabel(r.bucket)]];
                if (S.tab === 'in') lines.push(['Nilai Masuk', rp(r.total)], ['Transaksi', int(r.count)]);
                if (S.tab === 'out') lines.push(['HPP Keluar', rp(r.hpp)], ['Nilai Jual', rp(r.sell)], ['Transaksi', int(r.count)]);
                if (S.tab === 'transfer') lines.push(['Nilai Cost', rp(r.value)], ['Transfer', int(r.count)]);
                lines.forEach(([k2, v2]) => tip.appendChild(UI.el('div', {}, [UI.el('b', {}, `${k2}: `), v2])));
                tip.style.left = `${(cx / W) * 100}%`;
                tip.hidden = false;
            });
            hit.addEventListener('mouseleave', () => { tip.hidden = true; });
            svg.appendChild(hit);
        });
        svg.appendChild(el('polyline', { points: pts.map((p) => p.join(',')).join(' '), class: 'io-line', fill: 'none' }));
        pts.forEach((p) => svg.appendChild(el('circle', { cx: p[0], cy: p[1], r: 2.5, class: 'io-dot' })));
        const wrap = UI.el('div', { class: 'io-chart-wrap' });
        wrap.appendChild(svg); wrap.appendChild(tip);
        host.appendChild(wrap);
        const legend = [[S.tab === 'in' ? '#22c55e' : S.tab === 'out' ? '#ef4444' : '#8b5cf6', titles[2]], ...(bar2 ? [['#38bdf8', 'Nilai Jual (Rp)']] : []), ['#2f7bff', titles[3]]];
        host.appendChild(UI.el('div', { class: 'io-chart-legend' }, legend.map(([c, l]) => UI.el('span', {}, [UI.el('i', { style: `background:${c}` }), ` ${l}`]))));
    }

    // ------------------------------------------------------------------ table
    const NUMERIC = new Set(['money', 'int', 'qty', 'pct', 'num', 'rate']);
    const STATUS_CLS = { POSTED: 'success', VOID: 'danger', 'SEBAGIAN VOID': 'warning', DISPATCHED: 'info', RECEIVED: 'success', RECEIVED_WITH_DISCREPANCY: 'warning', COMPLETED: 'success', CANCELLED: 'danger', PENDING: 'warning', REVERSED: 'danger' };
    const STATUS_TEXT = { RECEIVED_WITH_DISCREPANCY: 'RECEIVED (SELISIH)' };
    const statusBadge = (v) => UI.el('span', { class: `io-badge io-badge-${STATUS_CLS[v] || 'muted'}`, 'data-status': v }, STATUS_TEXT[v] || v);
    function hiddenCols() { try { return JSON.parse(localStorage.getItem('io-cols') || '{}'); } catch (e) { return {}; } }
    function saveHidden(h) { try { localStorage.setItem('io-cols', JSON.stringify(h)); } catch (e) { /* storage unavailable: the choice just is not remembered */ } }
    const visible = (table, col) => { const h = hiddenCols(); const k = `${table}:${col.key}`; return Object.prototype.hasOwnProperty.call(h, k) ? !h[k] : !!col.default; };
    function cell(col, row) {
        const v = row[col.key];
        switch (col.type) {
            case 'date': return fmtDate(v);
            case 'time': return nil(v) ? '—' : String(v);
            case 'ts': return fmtTs(v);
            case 'money': return rp(v);
            case 'qty': return qn(v);
            case 'int': return int(v);
            case 'num': return nil(v) ? '—' : UI.formatNumber(v, 2);
            case 'pct': return nil(v) ? '—' : `${UI.formatNumber(v, 2)}%`;
            case 'rate': return nil(v) ? '—' : `${UI.formatNumber(v, 2)}%`;
            case 'status': {
                if (nil(v)) return '—';
                const noRef = (S.tab === 'out' ? row.do_number : row.reference) === '—';
                return noRef && row.source && row.source !== 'V2' ? UI.el('span', {}, [statusBadge(v), ' ', UI.el('span', { class: 'io-src', title: row.source === 'Legacy' ? 'Transaksi legacy: tanpa rincian komponen / DO' : 'Data historis (hanya pelaporan)' }, row.source)]) : statusBadge(v);
            }
            default: {
                if (nil(v)) return '—';
                const kids = [String(v)];
                if (['reference', 'do_number'].includes(col.key) && v !== '—' && row.source && row.source !== 'V2') kids.push(' ', UI.el('span', { class: 'io-src', title: row.source === 'Legacy' ? 'Transaksi legacy: tanpa rincian komponen / DO' : row.source === 'Historis' ? 'Data historis (hanya pelaporan)' : 'Campuran' }, row.source));
                if (['reference', 'do_number'].includes(col.key) && row.partial) kids.push(' ', UI.el('span', { class: 'io-src partial', title: `Hanya ${row.lines_matched} dari ${row.lines_total} baris yang cocok dengan filter barang` }, 'sebagian'));
                return UI.el('span', {}, kids);
            }
        }
    }
    function columnMenu(table, columns, onChange) {
        const wrap = UI.el('div', { class: 'io-colmenu-wrap' });
        const btn = UI.el('button', { type: 'button', class: 'btn btn-secondary io-col-btn', 'data-testid': 'io-cols' }, '▦ Kolom');
        const menu = UI.el('div', { class: 'io-colmenu', hidden: 'hidden' });
        columns.forEach((c) => {
            const cb = UI.el('input', { type: 'checkbox', 'data-col': c.key, ...(visible(table, c) ? { checked: 'checked' } : {}) });
            cb.addEventListener('change', () => { const h = hiddenCols(); h[`${table}:${c.key}`] = !cb.checked; saveHidden(h); onChange(); });
            menu.appendChild(UI.el('label', { class: 'io-colopt' }, [cb, ` ${c.label}`]));
        });
        btn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
        menu.addEventListener('click', (e) => e.stopPropagation());
        document.addEventListener('click', () => { menu.hidden = true; });
        wrap.appendChild(btn); wrap.appendChild(menu);
        return wrap;
    }
    function pager(p, onPage, label, onPer) {
        const bar = UI.el('div', { class: 'io-pager' }, [UI.el('span', { 'data-testid': 'io-pager-info' }, p.total === 0 ? `0 ${label}` : `Menampilkan ${int((p.page - 1) * p.per_page + 1)} - ${int(Math.min(p.page * p.per_page, p.total))} dari ${int(p.total)} ${label}`)]);
        const nav = UI.el('div', { class: 'io-pager-nav' });
        const btn = (text, page, disabled, cur) => { const b = UI.el('button', { type: 'button', class: `io-pg${cur ? ' on' : ''}`, ...(disabled ? { disabled: 'disabled' } : {}) }, text); b.addEventListener('click', () => onPage(page)); return b; };
        nav.appendChild(btn('‹', p.page - 1, p.page <= 1));
        const shown = new Set([1, p.total_pages, p.page, p.page - 1, p.page + 1].filter((n) => n >= 1 && n <= p.total_pages));
        let prev = 0;
        [...shown].sort((a, b) => a - b).forEach((n) => { if (n - prev > 1) nav.appendChild(UI.el('span', { class: 'io-pg-gap' }, '…')); nav.appendChild(btn(String(n), n, false, n === p.page)); prev = n; });
        nav.appendChild(btn('›', p.page + 1, p.page >= p.total_pages));
        bar.appendChild(nav);
        const sel = UI.el('select', { class: 'io-per', 'data-testid': 'io-per' }, [5, 10, 25, 50].map((n) => UI.el('option', { value: String(n), ...(n === p.per_page ? { selected: 'selected' } : {}) }, `${n} / halaman`)));
        sel.addEventListener('change', () => onPer(Number(sel.value)));
        bar.appendChild(sel);
        return bar;
    }
    function totalCell(col, i, totals, label) {
        if (i === 0) return label;
        if (Object.prototype.hasOwnProperty.call(totals, col.key) && ['money', 'int', 'qty'].includes(col.type)) return col.type === 'money' ? rp(totals[col.key]) : UI.formatNumber(totals[col.key], col.type === 'qty' ? 3 : 0);
        return '';
    }
    const rowKey = (r) => (S.tab === 'in' ? r.tx_ids.join(',') : S.tab === 'out' ? (r.do_id ? `d${r.do_id}` : `t${r.tx_ids[0]}`) : String(r.id));
    const isVoid = (r) => (S.view === 'lines' ? r.counted === false : S.tab === 'in' ? r.status !== 'POSTED' : S.tab === 'out' ? r.items === 0 : !r.active);

    function renderList() {
        const res = S.list;
        const host = q$('#io-list');
        host.innerHTML = '';
        const tableKey = `${S.tab}${S.view === 'lines' ? ':lines' : ''}`;
        const cols = res.columns.filter((c) => visible(tableKey, c));
        const search = UI.el('input', { type: 'text', class: 'io-search', 'data-testid': 'io-gq', placeholder: TITLES[S.tab][2], value: S.gq });
        let timer = null;
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { S.gq = search.value.trim(); S.page = 1; reloadList(); }, 350); });
        const viewSeg = UI.el('div', { class: 'io-seg', role: 'group', 'aria-label': 'Tampilan tabel', 'data-testid': 'io-view' }, [['tx', 'Per Transaksi'], ['lines', 'Per Baris Barang']].map(([k, label]) => {
            const b = UI.el('button', { type: 'button', class: `io-seg-btn${S.view === k ? ' on' : ''}`, 'data-testid': `io-view-${k}`, 'aria-pressed': S.view === k ? 'true' : 'false' }, label);
            b.addEventListener('click', () => { if (S.view === k) return; S.view = k; S.page = 1; S.sort = ''; S.dir = 'desc'; S.selected = null; reloadList(); });
            return b;
        }));
        host.appendChild(UI.el('div', { class: 'io-card-head' }, [
            UI.el('div', { class: 'io-card-title' }, [`🧾 ${TITLES[S.tab][0]} `, UI.el('span', { class: 'io-card-sub' }, '(klik baris atau 👁 untuk rincian item)')]),
            UI.el('div', { class: 'io-card-tools' }, [viewSeg, search, columnMenu(`${S.tab}${S.view === 'lines' ? ':lines' : ''}`, res.columns, renderList)]),
        ]));
        if (!res.rows.length) { host.appendChild(UI.el('div', { class: 'io-empty', 'data-testid': 'io-empty' }, 'Tidak ada transaksi pada periode ini.')); return; }
        const sortable = SORTABLE[S.tab];
        const head = UI.el('tr', {}, cols.map((c) => {
            const k = S.view === 'lines' ? null : sortable[c.key];
            const th = UI.el('th', { class: `${NUMERIC.has(c.type) ? 'num' : ''}${k ? ' sortable' : ''}`, ...(k ? { 'data-sort': k } : {}) }, c.label + (k && S.sort === k ? (S.dir === 'asc' ? ' ▲' : ' ▼') : ''));
            if (k) th.addEventListener('click', () => { if (S.sort === k) S.dir = S.dir === 'asc' ? 'desc' : 'asc'; else { S.sort = k; S.dir = 'desc'; } S.page = 1; reloadList(); });
            return th;
        }).concat([UI.el('th', {}, 'Aksi')]));
        const body = res.rows.map((r) => {
            const key = rowKey(r);
            const eye = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary io-eye', title: 'Lihat rincian item', 'data-testid': 'io-eye' }, '👁');
            const tr = UI.el('tr', { class: `click${isVoid(r) ? ' void' : ''}${S.selected === key ? ' sel' : ''}`, 'data-testid': 'io-row', 'data-key': key },
                cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [cell(c, r)])).concat([UI.el('td', {}, [eye])]));
            const open = () => { S.selected = key; host.querySelectorAll('tr[data-testid="io-row"]').forEach((x) => x.classList.toggle('sel', x === tr)); openDetail(r); };
            eye.addEventListener('click', (e) => { e.stopPropagation(); open(); });
            tr.addEventListener('click', open);
            return tr;
        });
        const f = res.footer;
        const foot = UI.el('tr', { 'data-testid': 'io-total' }, cols.map((c, i) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [totalCell(c, i, f.totals, 'GRAND TOTAL')])).concat([UI.el('td', {}, '')]));
        host.appendChild(UI.el('div', { class: 'io-scroll', 'data-testid': 'io-scroll' }, [UI.el('table', { class: 'io-table', 'data-testid': 'io-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])]));
        if (S.tab === 'in' && f.incomplete_components > 0) host.appendChild(UI.el('div', { class: 'io-hint' }, `${f.incomplete_components} transaksi legacy/historis hanya memiliki nilai Total — kolom komponen "—" dan tidak ikut dijumlahkan.`));
        if (S.tab === 'out' && f.unpriced > 0) host.appendChild(UI.el('div', { class: 'io-hint' }, `${f.unpriced} transaksi tanpa invoice (legacy): Nilai Jual / Margin tidak diketahui ("—") dan tidak dijumlahkan; HPP tetap dihitung.`));
        if (S.tab === 'transfer') host.appendChild(UI.el('div', { class: 'io-hint' }, 'GRAND TOTAL hanya menjumlahkan transfer aktif (PENDING + RECEIVED); transfer dibatalkan / di-reverse tercoret.'));
        host.appendChild(pager(res.pagination, (p) => { S.page = p; reloadList(); }, S.view === 'lines' ? 'baris barang' : S.tab === 'transfer' ? 'transfer' : 'transaksi', (n) => { S.perPage = n; S.page = 1; reloadList(); }));
    }

    // ------------------------------------------------------------------ detail (inline, under the table)
    async function openDetail(row) {
        const state = S;
        const ticket = (state.detailTicket += 1);
        const host = q$('#io-detail');
        host.hidden = false;
        host.innerHTML = '<div class="io-loading" data-testid="io-detail-loading">Memuat rincian…</div>';
        const p = S.tab === 'in' ? { tab: 'in', tx_ids: row.tx_ids.join(',') } : S.tab === 'out' ? (row.do_id ? { tab: 'out', do_id: row.do_id } : { tab: 'out', tx_id: row.tx_ids[0] }) : { tab: 'transfer', id: row.id };
        let d;
        try { d = await IoApi.detail(p); } catch (err) {
            if (state !== S || ticket !== state.detailTicket) return;
            host.innerHTML = '';
            host.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'io-detail-error' }, `Gagal memuat rincian: ${err.message}`));
            return;
        }
        if (state !== S || ticket !== state.detailTicket) return;
        S.detail = d;
        renderDetail(d, row);
        try { host.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) { /* older browsers */ }
    }
    const kv = (rows) => UI.el('div', { class: 'io-kv' }, rows.map(([k, v]) => UI.el('div', { class: 'io-kv-row' }, [UI.el('span', { class: 'io-kv-k' }, k), UI.el('span', { class: 'io-kv-v' }, [nil(v) ? '—' : (typeof v === 'string' || typeof v === 'object' ? v : String(v))])])));
    function downloadCsv(name, cols, rows) {
        const cell2 = (c, r) => { const v = r[c.key]; if (nil(v)) return ''; return typeof v === 'number' ? String(v) : String(v); };
        const lines = [cols.map((c) => `"${c.label.replace(/"/g, '""')}"`).join(',')].concat(rows.map((r) => cols.map((c) => `"${cell2(c, r).replace(/"/g, '""')}"`).join(',')));
        const blob = new Blob([`﻿${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }
    function lineTable(cols, lines, extra) {
        const head = UI.el('tr', {}, [UI.el('th', {}, 'No.')].concat(cols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) ? 'num' : '' }, c.label))).concat(extra ? [UI.el('th', {}, extra.head)] : []));
        const body = [];
        lines.forEach((l, i) => {
            const counted = l.counted !== false;
            const tr = UI.el('tr', { class: `${counted ? '' : 'void'}${l.diff && Math.abs(l.diff) > 0 ? ' diff' : ''}`, 'data-testid': 'io-detail-line', 'data-sku': l.sku },
                [UI.el('td', {}, String(i + 1))].concat(cols.map((c) => UI.el('td', { class: `${NUMERIC.has(c.type) ? 'num' : ''}${c.key === 'diff' && l.diff && Math.abs(l.diff) > 0 ? ' io-diff' : ''}` }, [cell(c, l)]))));
            if (extra) tr.appendChild(UI.el('td', {}, [extra.cell(l, tr)]));
            body.push(tr);
        });
        return { head, body };
    }
    function layersRow(l, colspan, layerCols) {
        const rows = l.layer_rows || [];
        const t = UI.el('table', { class: 'io-table io-sub', 'data-testid': 'io-layers' }, [
            UI.el('thead', {}, [UI.el('tr', {}, layerCols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) ? 'num' : '' }, c.label)))]),
            UI.el('tbody', {}, rows.map((a) => UI.el('tr', { 'data-testid': 'io-layer-row' }, layerCols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [cell(c, a)]))))),
            UI.el('tfoot', {}, [UI.el('tr', {}, layerCols.map((c, i) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [i === 0 ? 'Σ layer' : (c.key === 'qty' ? qn(rows.reduce((s, a) => s + a.qty, 0)) : c.key === 'value' ? rp(rows.reduce((s, a) => s + a.value, 0)) : '')])))]),
        ]);
        return UI.el('tr', { class: 'io-layer-tr', 'data-testid': 'io-layer-tr' }, [UI.el('td', { colspan: String(colspan) }, [UI.el('div', { class: 'io-layer-wrap' }, [t])])]);
    }
    function renderDetail(d, row) {
        const host = q$('#io-detail');
        host.innerHTML = '';
        const tab = S.tab;
        let title; let headKv; let cols; let csvName;
        if (tab === 'in') {
            const inv = d.invoice;
            title = `${fmtDate(inv.date)} - ${inv.reference}`;
            headKv = [['No. Invoice / Referensi', inv.reference], ['Supplier', inv.supplier], ['Gudang', inv.warehouse], ['Tanggal transaksi', fmtDate(inv.date)], ['Dibuat oleh', inv.created_by], ['Timestamp dibuat', fmtTs(inv.created_at)], ['Diposting pada', fmtTs(inv.posted_at)], ['Status', statusBadge(inv.status)]];
            cols = d.columns.filter((c) => c.default);
            csvName = `rincian-in-${inv.reference}.csv`;
        } else if (tab === 'out') {
            const doc = d.document;
            title = `${fmtDate(doc.date)} - ${doc.do_number}${doc.invoice_number !== '—' ? ` / ${doc.invoice_number}` : ''}`;
            headKv = [['No. DO', doc.do_number], ['No. Invoice', doc.invoice_number], ['Bakery Tujuan', doc.bakery], ['Gudang Asal', doc.warehouse], ['Dibuat oleh', doc.created_by], ['Dispatched oleh', doc.dispatched_by], ['Dispatched pada', fmtTs(doc.dispatched_at)], ['Status', statusBadge(doc.status)]];
            cols = d.columns.filter((c) => c.default && c.key !== 'layers');
            csvName = `rincian-out-${doc.do_number}.csv`;
        } else {
            const t = d.transfer;
            title = `${fmtDate(t.created_at)} - ${t.number}`;
            headKv = [['No. Transfer', t.number], ['Gudang Asal', t.from], ['Gudang Tujuan', t.to], ['Dibuat oleh', t.created_by], ['Dispatched pada', fmtTs(t.dispatched_at)], ['Diterima oleh', t.received_by], ['Diterima pada', fmtTs(t.received_at)], ['Lead time', t.lead_time], ['Status', statusBadge(t.status)]];
            if (t.cancel) headKv.push(['Dibatalkan', `${t.cancel.by || '—'} · ${fmtTs(t.cancel.at)} · ${t.cancel.reason || '—'}`]);
            if (t.reverse) headKv.push(['Di-reverse', `${t.reverse.by || '—'} · ${fmtTs(t.reverse.at)} · ${t.reverse.reason || '—'}`]);
            cols = d.columns.filter((c) => c.default && c.key !== 'layers' && c.key !== 'status');
            csvName = `rincian-transfer-${t.number}.csv`;
        }
        const dl = UI.el('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'io-download-rincian' }, '⬇ Download Rincian');
        dl.addEventListener('click', () => downloadCsv(csvName, cols, d.lines));
        host.appendChild(UI.el('div', { class: 'io-card-head' }, [
            UI.el('div', {}, [UI.el('div', { class: 'io-card-title', 'data-testid': 'io-detail-title' }, `📦 ${TITLES[tab][1]} (${title})`), UI.el('div', { class: 'io-card-sub' }, tab === 'in' ? 'Nilai mengikuti perhitungan pembelian (diskon, PPN, ongkos kirim).' : tab === 'out' ? 'HPP = biaya FIFO tersimpan; Nilai Jual = invoice saat posting; klik "Layer" untuk alokasi FIFO.' : 'Biaya layer asli dipertahankan; klik "Layer" untuk sumber cost.')]),
            UI.el('div', { class: 'io-card-tools' }, [dl]),
        ]));
        host.appendChild(UI.el('div', { 'data-testid': 'io-detail-header' }, [kv(headKv)]));
        const layerCols = (d.layer_columns || []).filter((c) => !['do_number', 'number', 'sku', 'name', 'received', 'base_unit'].includes(c.key));
        const extra = tab === 'in' ? null : { head: 'Layer', cell: (l, tr) => {
            const n = (l.layer_rows || []).length;
            const b = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary io-layer-btn', 'data-testid': 'io-layer-toggle' }, `▸ ${n} layer`);
            let open = null;
            b.addEventListener('click', () => {
                if (open) { open.remove(); open = null; b.textContent = `▸ ${n} layer`; return; }
                open = layersRow(l, cols.length + 2, layerCols);
                tr.parentNode.insertBefore(open, tr.nextSibling);
                b.textContent = `▾ ${n} layer`;
            });
            return b;
        } };
        const { head, body } = lineTable(cols, d.lines, extra);
        let foot = null;
        if (tab === 'in' && d.arithmetic) {
            const a = d.arithmetic;
            const m = { dpp: a.subtotal, item_discount: a.item_discount, ppn: a.ppn, invoice_discount: a.invoice_discount, freight: a.freight, total: a.grand_total, inventory_cost: a.inventory_cost, gross: a.gross };
            foot = UI.el('tr', { 'data-testid': 'io-detail-total' }, [UI.el('td', {}, 'TOTAL')].concat(cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [Object.prototype.hasOwnProperty.call(m, c.key) ? rp(m[c.key]) : '']))));
        } else if (tab === 'out') {
            const posted = d.lines.filter((l) => l.counted);
            const sum = (k) => posted.reduce((s, l) => s + (l[k] || 0), 0);
            const known = posted.every((l) => l.sell !== null);
            const m = { hpp: sum('hpp'), sell: known ? sum('sell') : null, shipping: known ? sum('shipping') : null, line_total: known ? sum('line_total') : null, margin: known ? sum('margin') : null };
            foot = UI.el('tr', { 'data-testid': 'io-detail-total' }, [UI.el('td', {}, 'TOTAL')].concat(cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [Object.prototype.hasOwnProperty.call(m, c.key) ? rp(m[c.key]) : '']))).concat([UI.el('td', {}, '')]));
        } else if (tab === 'transfer') {
            const act = d.transfer.active;
            foot = UI.el('tr', { 'data-testid': 'io-detail-total' }, [UI.el('td', {}, act ? 'TOTAL' : 'TOTAL (tidak dihitung)')].concat(cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [c.key === 'value' ? rp(d.lines.reduce((s, l) => s + l.value, 0)) : '']))).concat([UI.el('td', {}, '')]));
        }
        host.appendChild(UI.el('div', { class: 'io-scroll' }, [UI.el('table', { class: 'io-table', 'data-testid': 'io-detail-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), ...(foot ? [UI.el('tfoot', {}, [foot])] : [])])]));
        if (tab === 'in' && d.arithmetic) {
            const a = d.arithmetic;
            const eq = (label, value, cls) => UI.el('div', { class: `io-eq ${cls || ''}` }, [UI.el('span', {}, label), UI.el('b', {}, value)]);
            host.appendChild(UI.el('div', { class: 'io-arith', 'data-testid': 'io-arith' }, [UI.el('div', { class: 'io-arith-title' }, 'Perhitungan invoice (Stock IN V2)'),
                eq('Gross Barang', rp(a.gross)), eq('− Diskon Barang', rp(a.item_discount)), eq('= Subtotal Barang', rp(a.subtotal), 'sub'), eq('+ PPN', rp(a.ppn)), eq('− Diskon Invoice', rp(a.invoice_discount)), eq('+ Ongkos Kirim', rp(a.freight)), eq('= GRAND TOTAL', rp(a.grand_total), 'grand')]));
        }
        if (tab === 'out') {
            const ck = d.check;
            host.appendChild(UI.el('div', { class: 'io-checks', 'data-testid': 'io-checks' }, [
                UI.el('span', { class: `io-check ${ck.fifo_qty_equals_out_qty ? 'ok' : 'bad'}` }, `${ck.fifo_qty_equals_out_qty ? '✔' : '✖'} Σ Qty FIFO = Qty OUT`),
                UI.el('span', { class: `io-check ${ck.fifo_value_equals_hpp ? 'ok' : 'bad'}` }, `${ck.fifo_value_equals_hpp ? '✔' : '✖'} Σ Nilai FIFO = HPP tersimpan`),
            ]));
        }
        Object.values(d.notes || {}).forEach((t) => host.appendChild(UI.el('div', { class: 'io-hint' }, t)));
    }

    // ------------------------------------------------------------------ Cetak + Download Excel (follow the active tab, filters, view and the visible columns)
    const TAB_TITLE = { in: 'Barang Masuk (IN)', out: 'Barang Keluar (OUT)', transfer: 'Transfer Antar Gudang' };
    async function doExcel(tab) {
        await ReportTools.download(IoApi.exportUrl(params({ tab, gq: S.gq || undefined })), 'Laporan_IN_OUT.xlsx');
    }
    const selText = (id, all) => { const e = q$(`#${id}`); const t = e && e.selectedOptions && e.selectedOptions[0] ? e.selectedOptions[0].textContent.trim() : ''; return e && e.value ? t : all; };
    const PRINT_TYPE = { date: 'date', ts: 'ts', money: 'money', qty: 'qty', int: 'int', pct: 'pct', rate: 'pct', num: 'qty' };
    async function doPrint() {
        const tableKey = `${S.tab}${S.view === 'lines' ? ':lines' : ''}`;
        const base = params({ gq: S.gq || undefined, per_page: 100, sort: S.sort || undefined, dir: S.dir, view: S.view === 'lines' ? 'lines' : undefined });
        let res = await IoApi.list({ ...base, page: 1 });
        const rows = res.rows.slice();
        const first = res;
        for (let p = 2; p <= Math.min(first.pagination.total_pages, 40); p += 1) {
            res = await IoApi.list({ ...base, page: p });
            rows.push(...res.rows);
        }
        const cols = first.columns.filter((c) => visible(tableKey, c));
        const f = first.footer || { totals: {} };
        const totalRow = cols.map((c, i) => (i === 0 ? 'GRAND TOTAL' : (Object.prototype.hasOwnProperty.call(f.totals, c.key) && ['money', 'int', 'qty'].includes(c.type) ? f.totals[c.key] : null)));
        const meta = [['Periode', `${fmtDate(S.start)} s/d ${fmtDate(S.end)}`], ['Gudang', selText('io-wh', 'Semua Gudang')], ['Kategori', selText('io-cat', 'Semua Kategori')], ['Barang / SKU', S.q || ''], ['Pencarian tabel', S.gq || ''],
            ['Tab', TAB_TITLE[S.tab]], ['Tampilan', S.view === 'lines' ? 'Per Baris Barang' : 'Per Transaksi']];
        if (S.tab === 'in') meta.push(['Supplier', selText('io-sup', 'Semua Supplier')], ['Jenis IN', selText('io-src', 'Semua Jenis')]);
        if (S.tab === 'out') meta.push(['Bakery', selText('io-bak', 'Semua Bakery')], ['Divisi', selText('io-div', 'Semua Divisi')], ['Jenis OUT', selText('io-src', 'Semua Jenis')]);
        if (S.tab === 'transfer') meta.push(['Gudang Asal', selText('io-from', 'Semua Gudang Asal')], ['Gudang Tujuan', selText('io-to', 'Semua Gudang Tujuan')]);
        meta.push(['Status', selText('io-status', 'Semua Status')]);
        const n = S.overview.kpi.nominal;
        const kpis = S.tab === 'in' ? [{ label: 'Total Nilai Barang Masuk', value: rp(n.total) }, { label: 'Transaksi', value: int(n.invoices) }, { label: 'SKU Masuk', value: int(n.skus) }, { label: 'PPN', value: rp(n.ppn) }, { label: 'Ongkos Kirim', value: rp(n.freight) }]
            : S.tab === 'out' ? [{ label: 'HPP Keluar (FIFO)', value: rp(n.hpp) }, { label: 'Nilai Jual', value: rp(n.sell) }, { label: 'Margin', value: rp(n.margin) }, { label: 'Transaksi', value: int(n.documents) }, { label: 'Ongkos Kirim', value: rp(n.shipping) }]
                : [{ label: 'Total Transfer', value: int(n.transfers) }, { label: 'Pending', value: int(n.pending) }, { label: 'Diterima', value: int(n.received) }, { label: 'Nilai Cost Transfer', value: rp(n.value) }];
        const table = (r) => cols.map((c) => { const v = r[c.key]; return c.type === 'status' && !nil(v) ? (STATUS_TEXT[v] || v) : (nil(v) ? null : v); });
        ReportTools.printDocument({
            title: `Laporan IN / OUT — ${TAB_TITLE[S.tab]}`, subtitle: `${S.view === 'lines' ? 'Rincian per baris barang' : 'Daftar transaksi'} · ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`, meta, kpis, orientation: 'landscape',
            sections: [{ title: S.view === 'lines' ? 'Rincian per Baris Barang' : TITLES[S.tab][0], note: first.pagination.total_pages > 40 ? 'Dibatasi 4.000 baris pertama — daftar lengkap ada di Download Excel.' : (S.tab === 'transfer' ? 'GRAND TOTAL hanya menjumlahkan transfer aktif (Pending + Diterima).' : 'Baris VOID / dibatalkan ditampilkan tetapi tidak dihitung pada total.'),
                columns: cols.map((c) => ({ label: c.label, type: PRINT_TYPE[c.type] || 'text' })), rows: rows.map(table), totalRow }],
        });
    }

    return { render };
})();
