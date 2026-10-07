/**
 * Laporan > Laporan Nilai Stok & HPP — DUAL VALUATION (FIFO + Average).
 *
 * Every number comes from GET /api/reports/inventory-valuation* (InventoryValuationService); this file only renders them — it never recalculates a value.
 *   FIFO    = the system's OPERATIONAL method: real FIFO layers / allocations ("Layer Terpakai", "Layer Aktif").
 *   Average = ANALYTICAL moving weighted average per item per warehouse (read-only); items whose history cannot support it show
 *             "Average tidak dapat direkonstruksi" and are never summed or invented.
 * The method toggle keeps period / warehouse / category / search / selected item and refetches the report with the explicit `method` parameter.
 * READ-ONLY: only GET requests are issued. Quantities of different items/units are never added (quantity cells of the totals row are "—").
 */
const ReportNilaiHppV3 = (() => {
    let S = null;
    const $ = (id) => document.getElementById(id);

    // ------------------------------------------------------------------ API (self-contained, read-only GET)
    // The page issues its own same-origin GETs (session cookie; no CSRF needed for GET) instead of going through InvApi, so it works whichever api-client file a deployment
    // actually executes (some installations load a hotfixed api-client-v2163eod.js next to api-client.js).
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
    const ValApi = {
        overview: (p) => apiGet('/reports/inventory-valuation', p),
        item: (p) => apiGet('/reports/inventory-valuation/item', p),
        exportUrl: (p) => `/api/reports/inventory-valuation/export${qsOf(p)}`,
    };

    // ------------------------------------------------------------------ formatting
    const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nil = (v) => v === null || v === undefined || v === '';
    const rp = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const qn = (v) => (nil(v) ? '—' : UI.formatNumber(v, 3));
    const unitCost = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
        return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    const fmtTime = (v) => (v ? String(v).slice(11, 19) : '');
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    function monthRange() { const n = new Date(); return { start: iso(new Date(n.getFullYear(), n.getMonth(), 1)), end: iso(new Date(n.getFullYear(), n.getMonth() + 1, 0)) }; }
    const METHOD_LABEL = { fifo: 'FIFO', average: 'Average' };
    const pct = (v) => (nil(v) ? null : `${v > 0 ? '▲ +' : v < 0 ? '▼ ' : ''}${UI.formatNumber(v, 1)}%`.replace('.', ','));

    function freshState() {
        const r = monthRange();
        return { start: r.start, end: r.end, warehouse: '', category: '', q: '', method: 'fifo', view: 'item', bucket: 'day', page: 1, perPage: 50, itemId: null, dayOnlyItem: false, overview: null, detail: null };
    }
    function params(extra) {
        return { start_date: S.start, end_date: S.end, warehouse_id: S.warehouse || undefined, category_id: S.category || undefined, q: S.q || undefined, method: S.method, ...extra };
    }

    // ------------------------------------------------------------------ render
    function render(container) {
        S = freshState();
        container.innerHTML = '';
        container.classList.add('val');
        const exportBtn = ReportTools.actions({ id: 'val', print: doPrint, excel: doExcel });
        container.appendChild(UI.el('div', { class: 'val-head' }, [
            UI.el('div', {}, [
                UI.el('div', { class: 'val-titlerow' }, [
                    UI.el('h2', { class: 'val-title', 'data-testid': 'val-title', id: 'val-title' }, ''),
                    UI.el('div', { class: 'val-badges' }, [
                        UI.el('span', { class: 'val-badge val-badge-success', 'data-testid': 'val-system-badge' }, 'Metode operasional sistem: FIFO'),
                        UI.el('span', { class: 'val-badge val-badge-muted', id: 'val-method-badge', 'data-testid': 'val-method-badge' }, ''),
                    ]),
                ]),
                UI.el('p', { class: 'val-desc', id: 'val-desc' }, ''),
            ]),
            exportBtn,
        ]));
        container.appendChild(buildFilters());
        container.appendChild(UI.el('div', { id: 'val-banner' }));
        container.appendChild(UI.el('div', { id: 'val-kpis', class: 'val-kpis', 'data-testid': 'val-kpis' }));
        container.appendChild(UI.el('div', { id: 'val-compare' }));
        container.appendChild(UI.el('div', { id: 'val-main' }));
        paintHeader();
        loadAll();
    }

    function paintHeader() {
        const m = S.method;
        $('val-title').textContent = 'Laporan Nilai HPP';
        $('val-desc').textContent = m === 'fifo'
            ? 'Nilai persediaan dan HPP dari layer FIFO nyata — metode yang dipakai sistem.'
            : 'Nilai persediaan dan HPP dengan moving weighted average — pembanding analitis, read-only.';
        $('val-method-badge').textContent = m === 'fifo' ? 'Dilihat: FIFO' : 'Analytical Average — tidak mengubah FIFO operasional';
        document.querySelectorAll('.val-seg-btn[data-method]').forEach((b) => { const on = b.dataset.method === m; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        document.querySelectorAll('.val-seg-btn[data-view]').forEach((b) => { const on = b.dataset.view === S.view; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        document.querySelectorAll('.val-seg-btn[data-bucket]').forEach((b) => { const on = b.dataset.bucket === S.bucket; b.classList.toggle('on', on); });
        const bw = $('val-bucket-wrap');
        if (bw) bw.hidden = S.view !== 'day';
    }

    function seg(attr, items, testPrefix) {
        return UI.el('div', { class: 'val-seg', role: 'group' }, items.map(([key, label]) => UI.el('button', { type: 'button', class: 'val-seg-btn', [`data-${attr}`]: key, 'data-testid': `${testPrefix}-${key}`, 'aria-pressed': 'false' }, label)));
    }

    function buildFilters() {
        const opts = (list, name) => list.map((x) => `<option value="${esc(x.id)}">${esc(x[name || 'name'])}</option>`).join('');
        const u = Auth.user();
        const isStock = !!(u && u.role_code === 'STOCK' && u.warehouse_id);
        const methodSeg = seg('method', [['fifo', 'FIFO'], ['average', 'Average']], 'val-method');
        const viewSeg = seg('view', [['item', 'Per Barang'], ['day', 'Per Hari']], 'val-view');
        const bucketSeg = seg('bucket', [['day', 'Harian'], ['week', 'Minggu'], ['month', 'Bulanan']], 'val-bucket');
        const card = UI.el('div', { class: 'val-card val-filters' }, [
            UI.el('div', { class: 'val-filter-grid', html: `
                <div class="val-f"><label>Periode Tanggal</label><div class="val-dates"><input type="date" id="val-start" data-testid="val-start" value="${esc(S.start)}"><span>—</span><input type="date" id="val-end" data-testid="val-end" value="${esc(S.end)}"></div></div>
                <div class="val-f"><label>Gudang</label><select id="val-wh" data-testid="val-wh" ${isStock ? 'disabled' : ''}><option value="">Semua Gudang</option>${opts(Master.warehouses().filter((w) => w.is_active !== false))}</select></div>
                <div class="val-f"><label>Kategori</label><select id="val-cat" data-testid="val-cat"><option value="">Semua Kategori</option>${opts(Master.categories().filter((c) => c.is_active))}</select></div>
                <div class="val-f"><label>Cari Barang / SKU</label><input type="text" id="val-q" data-testid="val-q" placeholder="Cari nama barang / SKU…" autocomplete="off"></div>
            ` }),
            UI.el('div', { class: 'val-filter-row2' }, [
                UI.el('div', { class: 'val-toggle-wrap' }, [
                    UI.el('span', { class: 'val-toggle-label' }, 'Metode Penilaian'), methodSeg,
                    UI.el('span', { class: 'val-toggle-label' }, 'Tampilan'), viewSeg,
                    UI.el('span', { id: 'val-bucket-wrap', class: 'val-toggle-wrap' }, [UI.el('span', { class: 'val-toggle-label' }, 'Periode'), bucketSeg]),
                ]),
                UI.el('div', { class: 'val-filter-actions' }, [
                    UI.el('button', { type: 'button', class: 'btn btn-primary', id: 'val-apply', 'data-testid': 'val-apply' }, '⏷ Terapkan Filter'),
                    UI.el('button', { type: 'button', class: 'btn btn-secondary', id: 'val-reset', 'data-testid': 'val-reset' }, '↺ Reset'),
                ]),
            ]),
        ]);
        setTimeout(() => {
            if (isStock) { $('val-wh').value = String(u.warehouse_id); S.warehouse = String(u.warehouse_id); }
            $('val-apply').addEventListener('click', applyFilters);
            $('val-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
            $('val-reset').addEventListener('click', () => {
                const keep = isStock ? S.warehouse : '';
                S = Object.assign(freshState(), { method: S.method, view: S.view, warehouse: keep });
                $('val-start').value = S.start; $('val-end').value = S.end; $('val-wh').value = keep; $('val-cat').value = ''; $('val-q').value = '';
                paintHeader(); loadAll();
            });
            card.querySelectorAll('.val-seg-btn[data-method]').forEach((b) => b.addEventListener('click', () => setMethod(b.dataset.method)));
            card.querySelectorAll('.val-seg-btn[data-view]').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
            card.querySelectorAll('.val-seg-btn[data-bucket]').forEach((b) => b.addEventListener('click', () => { S.bucket = b.dataset.bucket; paintHeader(); loadAll(); }));
            paintHeader();
        }, 0);
        return card;
    }

    // ------------------------------------------------------------------ Cetak + Download Excel (follow the filters, the method AND the view)
    async function doExcel() {
        await ReportTools.download(ValApi.exportUrl(params({ view: S.view })), 'Laporan_Nilai_HPP.xlsx');
    }
    const selText = (id, all) => { const e = $(id); return e && e.value ? e.selectedOptions[0].textContent.trim() : all; };
    async function doPrint() {
        const payload = await apiGet('/reports/inventory-valuation/export', params({ view: S.view, format: 'json' }));
        const ov = S.overview;
        const k = ov.kpi || {};
        const f = k[S.method] || {};
        const main = payload.sheets[1];
        const kpis = S.method === 'fifo'
            ? [{ label: 'Nilai Stok Awal FIFO', value: rp(f.opening) }, { label: 'Inventory Cost Masuk', value: rp(f.cost_in) }, { label: 'HPP Keluar FIFO', value: rp(f.hpp) }, { label: 'Nilai Stok Akhir FIFO', value: rp(f.closing) }, { label: 'Nilai Layer Aktif', value: rp(f.layer_value) }]
            : [{ label: 'Nilai Stok Awal Average', value: rp(f.opening) }, { label: 'Pembelian / Cost In', value: rp(f.cost_in) }, { label: 'HPP Average', value: rp(f.hpp) }, { label: 'Nilai Stok Akhir Average', value: rp(f.closing) }];
        const meta = [['Periode', `${fmtDate(S.start)} s/d ${fmtDate(S.end)}`], ['Gudang', selText('val-wh', 'Semua Gudang')], ['Kategori', selText('val-cat', 'Semua Kategori')], ['Barang / SKU', S.q || ''],
            ['Metode Penilaian', METHOD_LABEL[S.method]], ['Tampilan', S.view === 'day' ? 'Per Hari' : 'Per Barang'], ['Metode operasional sistem', 'FIFO']];
        if (S.method === 'average') meta.push(['Catatan metode', 'Analytical Average — tidak mengubah FIFO operasional']);
        const spec = ReportTools.specFromPayload(payload, [{ sheet: main.name, title: `${main.name} — ${METHOD_LABEL[S.method]} · ${S.view === 'day' ? 'Per Hari' : 'Per Barang'}`, maxRows: 3000 }], {
            subtitle: `Metode Penilaian: ${METHOD_LABEL[S.method]} · Tampilan: ${S.view === 'day' ? 'Per Hari' : 'Per Barang'} · ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`, orientation: 'landscape', meta, kpis,
            footnote: S.method === 'average' ? 'Analytical Average — tidak mengubah FIFO operasional. Nilai FIFO tetap sumber kebenaran operasional. "—" = tidak tersedia (bukan nol).' : undefined,
        });
        ReportTools.printDocument(spec);
    }

    function applyFilters() {
        S.start = $('val-start').value; S.end = $('val-end').value; S.warehouse = $('val-wh').value; S.category = $('val-cat').value; S.q = $('val-q').value.trim(); S.page = 1;
        loadAll();
    }
    function setMethod(m) { if (m === S.method) return; S.method = m; paintHeader(); loadAll(); }
    function setView(v) { if (v === S.view) return; S.view = v; S.page = 1; paintHeader(); loadAll(); }

    // ------------------------------------------------------------------ data
    async function loadAll() {
        const main = $('val-main');
        main.innerHTML = '<div class="val-loading" data-testid="val-loading">Memuat nilai stok & HPP…</div>';
        $('val-kpis').innerHTML = ''; $('val-compare').innerHTML = ''; $('val-banner').innerHTML = '';
        const state = S;
        const ticket = (state.loadTicket = (state.loadTicket || 0) + 1);   // a newer load / a reset supersedes this one: never paint a stale or half-initialised response
        try {
            const view = S.view;
            const extra = view === 'day' ? { view: 'day', bucket: S.bucket, item_id: S.dayOnlyItem && S.itemId ? S.itemId : undefined } : { view: 'item', page: S.page, per_page: S.perPage };
            const data = await ValApi.overview(params(extra));
            if (S !== state || state.loadTicket !== ticket) return;
            S.overview = data;
        } catch (err) {
            if (S !== state || state.loadTicket !== ticket) return;
            main.innerHTML = '';
            main.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'val-error' }, `Gagal memuat laporan: ${err.message}`));
            return;
        }
        renderBanner(); renderKpis(); renderCompare();
        if (S.view === 'day') { S.detail = null; renderDays(); return; }
        const rows = S.overview.items;
        if (!rows.some((r) => r.item_id === S.itemId)) S.itemId = rows.length ? rows[0].item_id : null;
        renderItems();
        await loadDetail();
    }
    async function loadDetail() {
        const host = $('val-detail-host');
        if (!host) return;
        if (S.itemId === null) { host.innerHTML = ''; host.appendChild(UI.el('div', { class: 'val-empty' }, 'Tidak ada barang dengan stok / pergerakan pada filter ini.')); return; }
        host.innerHTML = '<div class="val-loading" data-testid="val-loading">Memuat detail barang…</div>';
        const state = S;
        const ticket = (state.detailTicket = (state.detailTicket || 0) + 1);
        try {
            const data = await ValApi.item(params({ item_id: S.itemId }));
            if (S !== state || state.detailTicket !== ticket) return;
            S.detail = data;
        } catch (err) {
            if (S !== state || state.detailTicket !== ticket) return;
            host.innerHTML = '';
            host.appendChild(UI.el('div', { class: 'alert alert-error' }, `Gagal memuat detail: ${err.message}`));
            return;
        }
        renderDetail();
    }

    function renderBanner() {
        const host = $('val-banner');
        host.innerHTML = '';
        const ov = S.overview;
        if (S.method === 'average' && ov.unreconstructable.length) {
            const names = ov.unreconstructable.slice(0, 3).map((u) => `${u.sku}`).join(', ');
            host.appendChild(UI.el('div', { class: 'val-note', 'data-testid': 'val-unknown-banner' }, `${ov.unreconstructable.length} barang: Average tidak dapat direkonstruksi (${names}${ov.unreconstructable.length > 3 ? ', …' : ''}) — riwayat stok tidak mencukupi (mis. stok negatif). Nilainya ditampilkan "—" dan TIDAK dihitung dalam total Average; FIFO tetap lengkap.`));
        }
        host.appendChild(UI.el('div', { class: 'val-hint', 'data-testid': 'val-note' }, S.method === 'fifo'
            ? 'FIFO = metode HPP operasional berdasarkan layer stok paling awal. Nilai di sini adalah nilai posting nyata; tidak dihitung ulang dari harga terakhir.'
            : 'Average = moving weighted average untuk analisis nilai persediaan (per barang per gudang; "Semua Gudang" = penjumlahan hasil per gudang). Metode operasional sistem tetap FIFO — laporan ini read-only.'));
    }

    // ------------------------------------------------------------------ KPI
    function renderKpis() {
        const k = S.overview.kpi;
        const host = $('val-kpis');
        host.innerHTML = '';
        const card = (key, tone, label, value, sub, raw) => UI.el('div', { class: `val-kpi ${tone}`, 'data-testid': `val-kpi-${key}` }, [
            UI.el('div', { class: 'val-kpi-label' }, label), UI.el('div', { class: 'val-kpi-value', 'data-testid': `val-kpi-${key}-value`, 'data-raw': raw === undefined ? '' : String(raw) }, value), UI.el('div', { class: 'val-kpi-sub' }, sub),
        ]);
        if (S.method === 'fifo') {
            const f = k.fifo;
            host.className = 'val-kpis five';
            host.appendChild(card('closing', 'blue', 'Nilai Stok Akhir FIFO', rp(f.closing), pct(f.closing_delta_pct) ? `${pct(f.closing_delta_pct)} vs awal periode` : 'awal periode Rp 0', f.closing));
            host.appendChild(card('hpp', 'green', 'HPP Keluar FIFO', rp(f.hpp), pct(f.hpp_delta_pct) ? `${pct(f.hpp_delta_pct)} vs periode lalu` : 'tidak ada HPP periode lalu', f.hpp));
            host.appendChild(card('layers', 'purple', 'Jumlah Layer Aktif', UI.formatNumber(f.layers, 0), `${f.layers_delta >= 0 ? '▲ +' : '▼ '}${f.layers_delta} layer vs awal periode`, f.layers));
            host.appendChild(card('skus', 'teal', 'SKU Memiliki Stok', UI.formatNumber(f.skus_with_stock, 0), `dari ${UI.formatNumber(k.items, 0)} barang dengan stok / pergerakan`, f.skus_with_stock));
            const ok = Math.abs(f.variance) <= 0.05;
            host.appendChild(card('variance', ok ? 'green' : 'red', 'Variance / Rekonsiliasi FIFO', rp(f.variance), ok ? 'Sesuai dengan metode FIFO (ledger = layer)' : 'ledger ≠ layer — lihat Rekonsiliasi', f.variance));
        } else {
            const a = k.average;
            host.className = 'val-kpis six';
            host.appendChild(card('opening', 'blue', 'Nilai Stok Awal', rp(a.opening), 'Average, awal periode', a.opening));
            host.appendChild(card('cost-in', 'teal', 'Pembelian / Cost In', rp(a.cost_in), 'pembelian, opening, hasil produksi', a.cost_in));
            host.appendChild(card('usage', 'amber', 'Pemakaian / Barang Keluar', rp(a.usage), `HPP ${rp(a.hpp)} · lainnya ${rp(a.usage - a.hpp)}`, a.usage));
            host.appendChild(card('closing', 'indigo', 'Nilai Stok Akhir Average', rp(a.closing), a.unknown_items ? `${a.unknown_items} barang tidak dihitung` : (pct(a.closing_delta_pct) ? `${pct(a.closing_delta_pct)} vs awal periode` : '—'), a.closing));
            host.appendChild(card('hpp', 'green', 'HPP Average', rp(a.hpp), pct(a.hpp_delta_pct) ? `${pct(a.hpp_delta_pct)} vs periode lalu` : 'tidak ada HPP periode lalu', a.hpp));
            const ok = Math.abs(a.variance) <= 0.05;
            host.appendChild(card('variance', ok ? 'green' : 'red', 'Selisih / Rekonsiliasi', rp(a.variance), `Awal + masuk − pemakaian ± lainnya (${rp(a.other_net)}) = akhir`, a.variance));
        }
        ReportTools.decorateKpis(host);
    }

    function renderCompare() {
        const c = S.overview.comparison;
        const host = $('val-compare');
        host.innerHTML = '';
        const diffCell = (v) => (v === 0 ? 'Rp 0' : `${v > 0 ? '+' : '−'}${rp(Math.abs(v))}`);
        host.appendChild(UI.el('div', { class: 'val-card val-compare', 'data-testid': 'val-compare' }, [
            UI.el('div', { class: 'val-card-head' }, [UI.el('div', { class: 'val-card-title' }, 'Selisih HPP — FIFO vs Average'), UI.el('div', { class: 'val-card-sub' }, `${UI.formatNumber(c.items, 0)} barang${c.excluded_items ? ` · ${c.excluded_items} barang tidak dapat direkonstruksi (Average) dikeluarkan dari kedua sisi` : ''}`)]),
            UI.el('div', { class: 'val-cmp-grid', html: `
                <div class="val-cmp"><span>HPP FIFO</span><b data-testid="val-cmp-fifo-hpp">${rp(c.fifo.hpp)}</b></div>
                <div class="val-cmp"><span>HPP Average</span><b data-testid="val-cmp-avg-hpp">${rp(c.average.hpp)}</b></div>
                <div class="val-cmp hl"><span>Selisih HPP</span><b data-testid="val-cmp-diff-hpp">${diffCell(c.diff.hpp)}</b></div>
                <div class="val-cmp"><span>Nilai Akhir FIFO</span><b>${rp(c.fifo.closing)}</b></div>
                <div class="val-cmp"><span>Nilai Akhir Average</span><b>${rp(c.average.closing)}</b></div>
                <div class="val-cmp hl"><span>Selisih Nilai Akhir</span><b data-testid="val-cmp-diff-closing">${diffCell(c.diff.closing)}</b></div>` }),
            UI.el('div', { class: 'val-hint' }, `${c.note} Selisih = Average − FIFO.`),
        ]));
    }

    // ------------------------------------------------------------------ Per Barang: item table
    function itemColumns() {
        const fifo = S.method === 'fifo';
        const pick = (r, k) => (fifo ? r.fifo[k] : r.avg[k]);
        const known = (r) => fifo || r.avg.known;
        const money = (k) => (r) => (known(r) ? rp(pick(r, k)) : '—');
        const base = [
            { key: 'sku', label: 'SKU', cls: 'sticky1', f: (r) => esc(r.sku), t: () => 'TOTAL' },
            { key: 'name', label: 'Barang', cls: 'sticky2', f: (r) => esc(r.name), t: () => '' },
            { key: 'unit', label: 'Satuan', f: (r) => esc(r.unit), t: () => '' },
            { key: 'qty_open', label: 'Qty Awal', num: true, f: (r) => qn(r.qty_open), t: () => '—' },
            { key: 'qty_in', label: 'Qty Masuk', num: true, f: (r) => qn(r.qty_in), t: () => '—' },
            { key: 'qty_out', label: 'Qty Keluar', num: true, f: (r) => qn(r.qty_out), t: () => '—' },
            { key: 'qty_close', label: 'Qty Akhir', num: true, f: (r) => qn(r.qty_close), t: () => '—' },
        ];
        const F = (o, k) => (o ? o[k] : null);
        if (fifo) {
            return base.concat([
                { key: 'opening', label: 'Nilai Awal FIFO', num: true, f: money('opening'), t: (T) => rp(T.f.opening) },
                { key: 'cost_in', label: 'Inventory Cost Masuk', num: true, f: money('cost_in'), t: (T) => rp(T.f.cost_in) },
                { key: 'hpp', label: 'HPP Keluar FIFO', num: true, f: money('hpp'), t: (T) => rp(T.f.hpp) },
                { key: 'trfadj', label: 'Transfer & Adjustment', num: true, f: (r) => rp(r.fifo.transfer + r.fifo.adjustment), t: (T) => rp(T.f.transfer + T.f.adjustment) },
                { key: 'closing', label: 'Nilai Akhir FIFO', num: true, f: money('closing'), t: (T) => rp(T.f.closing) },
                { key: 'unit_cost', label: 'Unit Cost Akhir', num: true, f: (r) => unitCost(r.fifo.unit_cost), t: () => '—' },
                { key: 'layers', label: 'Layer Aktif', num: true, f: (r) => UI.formatNumber(r.fifo.layers, 0), t: (T) => UI.formatNumber(T.f.layers, 0) },
                { key: 'variance', label: 'Variance', num: true, f: (r) => rp(r.fifo.variance), t: (T) => rp(T.f.variance) },
            ]);
        }
        return base.concat([
            { key: 'opening', label: 'Nilai Awal Average', num: true, f: money('opening'), t: (T) => rp(T.a.opening) },
            { key: 'cost_in', label: 'Pembelian / Cost In', num: true, f: money('cost_in'), t: (T) => rp(T.a.cost_in) },
            { key: 'usage', label: 'Pemakaian / Barang Keluar', num: true, f: money('usage'), t: (T) => rp(T.a.usage) },
            { key: 'hpp', label: 'HPP Average', num: true, f: money('hpp'), t: (T) => rp(T.a.hpp) },
            { key: 'trfadj', label: 'Transfer & Adjustment', num: true, f: (r) => (r.avg.known ? rp(r.avg.transfer + r.avg.adjustment) : '—'), t: (T) => rp(T.a.transfer + T.a.adjustment) },
            { key: 'closing', label: 'Nilai Akhir Average', num: true, f: money('closing'), t: (T) => rp(T.a.closing) },
            { key: 'unit_cost', label: 'Average Cost Akhir', num: true, f: (r) => (r.avg.known ? unitCost(F(r.avg, 'unit_cost')) : '—'), t: () => '—' },
            { key: 'variance', label: 'Variance', num: true, f: (r) => (r.avg.known ? rp(r.avg.variance) : '—'), t: (T) => rp(T.a.variance) },
        ]);
    }

    function renderItems() {
        const main = $('val-main');
        main.innerHTML = '';
        const ov = S.overview;
        const cols = itemColumns();
        const head = UI.el('tr', {}, cols.map((c) => UI.el('th', { class: `${c.cls || ''} ${c.num ? 'num' : ''}`.trim() }, c.label)));
        const body = ov.items.map((r) => {
            const tr = UI.el('tr', { class: `click ${r.item_id === S.itemId ? 'sel' : ''}`, 'data-testid': 'val-item-row', 'data-item-id': String(r.item_id), 'data-sku': r.sku, tabindex: '0' }, cols.map((c) => {
                const td = UI.el('td', { class: `${c.cls || ''} ${c.num ? 'num' : ''}`.trim(), html: c.f(r) });
                return td;
            }));
            if (S.method === 'average' && !r.avg.known) {
                tr.title = `Average tidak dapat direkonstruksi — ${r.avg.reason}`;
                tr.querySelectorAll('td.num').forEach((td, i) => { if (td.textContent === '—' && i >= 4) td.classList.add('val-unknown'); });
            }
            const pick = () => { if (S.itemId === r.item_id) return; S.itemId = r.item_id; main.querySelectorAll('tr[data-testid="val-item-row"]').forEach((x) => x.classList.toggle('sel', x === tr)); loadDetail(); };
            tr.addEventListener('click', pick);
            tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') pick(); });
            return tr;
        });
        const T = ov.items_footer;
        const foot = UI.el('tr', { 'data-testid': 'val-items-total' }, cols.map((c) => UI.el('td', { class: `${c.cls || ''} ${c.num ? 'num' : ''}`.trim() }, c.t(T))));
        const card = UI.el('div', { class: 'val-card', id: 'val-items-card' }, [
            UI.el('div', { class: 'val-card-head' }, [
                UI.el('div', { class: 'val-card-title' }, [`Nilai Stok per Barang `, UI.el('span', { class: 'val-card-sub' }, `— ${METHOD_LABEL[S.method]} · klik baris untuk melihat perhitungannya`)]),
                UI.el('div', { class: 'val-card-sub' }, `${UI.formatNumber(ov.page.total, 0)} barang${S.method === 'average' && T.unknown_items ? ` · ${T.unknown_items} tidak dapat direkonstruksi` : ''}`),
            ]),
            ov.items.length
                ? UI.el('div', { class: 'val-scroll val-wide', 'data-testid': 'val-items-scroll' }, [UI.el('table', { class: 'val-table val-items', 'data-testid': 'val-items-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])])
                : UI.el('div', { class: 'val-empty' }, 'Tidak ada barang dengan stok / pergerakan pada periode dan filter ini.'),
            UI.el('div', { class: 'val-hint' }, 'Kuantitas barang berbeda satuan tidak dijumlahkan (baris TOTAL hanya menjumlahkan nilai Rupiah). Average dihitung per barang per gudang.'),
        ]);
        main.appendChild(card);
        main.appendChild(pager(ov.page));
        main.appendChild(UI.el('div', { id: 'val-detail-host' }));
    }

    function pager(p) {
        const pages = Math.max(1, Math.ceil(p.total / p.per_page));
        if (pages <= 1) return UI.el('div', {});
        const mk = (label, page, dis, on) => { const b = UI.el('button', { type: 'button', class: `val-pg ${on ? 'on' : ''}` }, label); if (dis) b.disabled = true; b.addEventListener('click', () => { S.page = page; loadAll(); }); return b; };
        return UI.el('div', { class: 'val-pager' }, [UI.el('span', {}, `Halaman ${p.page} dari ${pages}`), UI.el('div', { class: 'val-pager-nav' }, [mk('‹', p.page - 1, p.page <= 1), mk(String(p.page), p.page, false, true), mk('›', p.page + 1, p.page >= pages)])]);
    }

    // ------------------------------------------------------------------ Per Barang: detail
    function renderDetail() {
        const host = $('val-detail-host');
        host.innerHTML = '';
        const d = S.detail;
        const fifo = S.method === 'fifo';
        const sel = UI.el('select', { id: 'val-item-select', 'data-testid': 'val-item-select', class: 'val-input' });
        S.overview.items.forEach((r) => sel.appendChild(UI.el('option', { value: String(r.item_id) }, `${r.name} (${r.sku})`)));
        sel.value = String(S.itemId);
        sel.addEventListener('change', () => { S.itemId = Number(sel.value); document.querySelectorAll('tr[data-testid="val-item-row"]').forEach((x) => x.classList.toggle('sel', x.dataset.itemId === sel.value)); loadDetail(); });
        const info = fifo
            ? UI.el('div', { class: 'val-info', 'data-testid': 'val-howto' }, [UI.el('b', {}, 'Cara kerja FIFO'), UI.el('div', {}, 'Stok keluar menggunakan layer stok masuk paling awal yang masih tersedia, lalu berlanjut ke layer berikutnya jika kuantitas tidak mencukupi. FIFO = metode HPP operasional sistem.')])
            : UI.el('div', { class: 'val-info', 'data-testid': 'val-howto' }, [UI.el('b', {}, 'Average = moving weighted average'), UI.el('div', {}, 'Average cost berubah setiap ada barang masuk bernilai; barang keluar memakai average saat itu dan tidak mengubahnya. Dihitung per barang per gudang.')]);
        const title = fifo ? `Simulasi FIFO per Barang` : `Perhitungan Average Cost (Per Barang)`;
        host.appendChild(UI.el('div', { class: 'val-card', 'data-testid': 'val-detail' }, [
            UI.el('div', { class: 'val-card-head' }, [UI.el('div', {}, [UI.el('div', { class: 'val-card-title' }, title), UI.el('div', { class: 'val-card-sub' }, `${d.item.name} (${d.item.sku}) · satuan ${d.item.unit} · ${fmtDate(S.start)} – ${fmtDate(S.end)}`)])]),
            UI.el('div', { class: 'val-pick' }, [UI.el('div', { class: 'val-f' }, [UI.el('label', {}, 'Pilih Barang'), sel]), info]),
            UI.el('div', { class: 'val-detail-grid' }, [
                UI.el('div', { class: 'val-detail-left' }, [historyCard(d, fifo), fifo ? queueCard(d) : stepsCard(d)]),
                UI.el('div', { class: 'val-detail-right' }, fifo ? layerCards(d) : [formulaCard(d)]),
            ]),
        ]));
        host.appendChild(itemCompareCard(d));
    }

    function layersText(layers) {
        if (!layers || !layers.length) return '—';
        return layers.map((l) => `${l.restore ? '↩ ' : ''}${UI.formatNumber(l.qty, 3)} @ ${UI.formatMoney(l.cost)}`).join('\n');
    }

    function historyCard(d, fifo) {
        const op = d.opening;
        const unit = d.item.unit;
        let heads;
        let rowCells;
        let openRow;
        if (fifo) {
            heads = ['Tanggal & Timestamp', 'Jenis Transaksi', 'Referensi', 'Gudang', `Qty Masuk (${unit})`, 'Unit Cost FIFO Masuk', `Qty Keluar (${unit})`, 'Layer Terpakai (FIFO)', 'HPP Keluar FIFO', 'Saldo Qty', 'Saldo Nilai FIFO', 'Petugas', 'Keterangan'];
            rowCells = (r) => [
                `<b>${esc(fmtDate(r.date))}</b><div class="val-sub">${esc(fmtTime(r.timestamp))}</div>`, esc(r.type_label), esc(r.reference || '—'), esc(r.warehouse),
                nil(r.qty_in) ? '—' : `<span class="in">${qn(r.qty_in)}</span>`, nil(r.unit_cost_in) ? '—' : unitCost(r.unit_cost_in), nil(r.qty_out) ? '—' : `<span class="out">${qn(r.qty_out)}</span>`,
                `<span class="val-layers">${esc(layersText(r.layers))}</span>`, nil(r.hpp) ? '—' : `<span class="out">${rp(r.hpp)}</span>`, qn(r.bal_qty), rp(r.bal_value), esc(r.by || '—'), esc(r.notes || ''),
            ];
            openRow = ['<b>Saldo Awal</b>', '', '', '', '—', '—', '—', '—', '—', qn(op.qty), rp(op.fifo_value), '', ''];
        } else {
            heads = ['Tanggal & Timestamp', 'Jenis Transaksi', 'Referensi', 'Gudang', `Qty Masuk (${unit})`, `Qty Keluar (${unit})`, 'Harga / Unit Cost Masuk', 'Nilai Masuk', 'Saldo Qty Sebelum', 'Saldo Nilai Sebelum', 'Average Cost Sebelum', 'Average Cost Sesudah', 'HPP Keluar Average', 'Saldo Qty Akhir', 'Saldo Nilai Akhir', 'Petugas', 'Keterangan'];
            rowCells = (r) => [
                `<b>${esc(fmtDate(r.date))}</b><div class="val-sub">${esc(fmtTime(r.timestamp))}</div>`, esc(r.type_label), esc(r.reference || '—'), esc(r.warehouse),
                nil(r.qty_in) ? '—' : `<span class="in">${qn(r.qty_in)}</span>`, nil(r.qty_out) ? '—' : `<span class="out">${qn(r.qty_out)}</span>`, nil(r.unit_cost_in) ? '—' : unitCost(r.unit_cost_in), nil(r.value_in) ? '—' : rp(r.value_in),
                qn(r.qty_before), nil(r.value_before) ? '—' : rp(r.value_before), nil(r.avg_before) ? '—' : unitCost(r.avg_before),
                `<span class="${r.avg_changed ? 'val-avgchg' : ''}">${nil(r.avg_after) ? '—' : unitCost(r.avg_after)}</span>`, nil(r.hpp) ? '—' : `<span class="out">${rp(r.hpp)}</span>`,
                qn(r.bal_qty), nil(r.bal_value) ? '—' : rp(r.bal_value), esc(r.by || '—'), r.unknown && r.unknown_reason ? `<span class="val-unknown">Average tidak dapat direkonstruksi</span> ${esc(r.unknown_reason)}` : esc(r.notes || ''),
            ];
            openRow = ['<b>Saldo Awal</b>', '', '', '', '—', '—', '—', '—', qn(op.qty), nil(op.avg_value) ? '—' : rp(op.avg_value), nil(op.avg_cost) ? '—' : unitCost(op.avg_cost), nil(op.avg_cost) ? '—' : unitCost(op.avg_cost), '—', qn(op.qty), nil(op.avg_value) ? '—' : rp(op.avg_value), '', ''];
        }
        const numFrom = fifo ? [4, 5, 6, 8, 9, 10] : [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];
        const head = UI.el('tr', {}, heads.map((h, i) => UI.el('th', { class: numFrom.includes(i) ? 'num' : '' }, h)));
        const mk = (cells, cls, tid) => UI.el('tr', { class: cls || '', 'data-testid': tid }, cells.map((c, i) => UI.el('td', { class: numFrom.includes(i) ? 'num' : '', html: c })));
        const body = [mk(openRow, 'pre', 'val-history-open')].concat(d.rows.map((r) => {
            const tr = mk(rowCells(r), `${r.status === 'VOID' ? 'void' : ''} ${r.avg_changed ? 'chg' : ''}`, 'val-history-row');
            return tr;
        }));
        const unknownBanner = !fifo && d.average_unknown ? UI.el('div', { class: 'val-note', 'data-testid': 'val-item-unknown' }, `Average tidak dapat direkonstruksi untuk barang ini — ${d.average_unknown}. Kolom Average ditampilkan "—"; tidak ada angka yang dikarang.`) : null;
        return UI.el('div', { class: 'val-subcard' }, [
            UI.el('div', { class: 'val-card-title sm' }, fifo ? `Riwayat Transaksi & Perhitungan FIFO — ${d.item.name}` : `Riwayat & Perhitungan Average — ${d.item.name}`),
            unknownBanner,
            d.rows.length ? UI.el('div', { class: 'val-scroll val-wide', 'data-testid': 'val-history-scroll' }, [UI.el('table', { class: 'val-table val-history', 'data-testid': 'val-history-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body)])])
                : UI.el('div', { class: 'val-empty' }, 'Tidak ada pergerakan barang ini pada periode (hanya saldo awal).'),
        ]);
    }

    // ---- FIFO side: layers + visual queue
    function layerCards(d) {
        const L = d.layers;
        const active = UI.el('div', { class: 'val-subcard', 'data-testid': 'val-layers-active' }, [
            UI.el('div', { class: 'val-card-head' }, [UI.el('div', { class: 'val-card-title sm' }, `Layer Aktif (Sisa Stok)`), UI.el('span', { class: 'val-pill' }, `${L.active.length} layer`)]),
            L.active.length ? UI.el('table', { class: 'val-table val-layer-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Tgl Masuk', 'Gudang', 'Qty Sisa', 'Harga Beli', 'Nilai Sisa', 'Status'].map((h, i) => UI.el('th', { class: i >= 3 && i <= 5 ? 'num' : '' }, h)))]),
                UI.el('tbody', {}, L.active.map((r) => UI.el('tr', { 'data-testid': 'val-layer-active-row' }, [
                    UI.el('td', {}, String(r.no)), UI.el('td', {}, fmtDate(r.received)), UI.el('td', {}, esc(r.warehouse)), UI.el('td', { class: 'num' }, qn(r.remaining)), UI.el('td', { class: 'num' }, rp(r.cost)), UI.el('td', { class: 'num' }, rp(r.value)),
                    UI.el('td', { html: `<span class="val-st ok">${esc(r.status)}</span>` }),
                ]))),
                UI.el('tfoot', {}, [UI.el('tr', { 'data-testid': 'val-layers-total' }, [UI.el('td', { colspan: '3' }, 'Total Sisa Stok'), UI.el('td', { class: 'num' }, qn(L.total_qty)), UI.el('td', {}, ''), UI.el('td', { class: 'num' }, rp(L.total_value)), UI.el('td', {}, '')])]),
            ]) : UI.el('div', { class: 'val-empty' }, 'Tidak ada layer aktif (stok habis).'),
            L.deficit_layers.length ? UI.el('div', { class: 'val-hint' }, `Layer negatif (override stok negatif): ${L.deficit_layers.map((x) => `${qn(x.qty)} @ ${rp(x.cost)}`).join('; ')}.`) : null,
        ]);
        const used = UI.el('div', { class: 'val-subcard', 'data-testid': 'val-layers-used' }, [
            UI.el('div', { class: 'val-card-head' }, [UI.el('div', { class: 'val-card-title sm' }, 'Layer yang Sudah Terpakai'), UI.el('span', { class: 'val-pill red' }, `${L.used.length} layer`)]),
            L.used.length ? UI.el('table', { class: 'val-table val-layer-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Tgl Masuk', 'Gudang', 'Qty Awal', 'Harga Beli', 'Status'].map((h, i) => UI.el('th', { class: i === 3 || i === 4 ? 'num' : '' }, h)))]),
                UI.el('tbody', {}, L.used.map((r) => UI.el('tr', {}, [UI.el('td', {}, String(r.no)), UI.el('td', {}, fmtDate(r.received)), UI.el('td', {}, esc(r.warehouse)), UI.el('td', { class: 'num' }, qn(r.used)), UI.el('td', { class: 'num' }, rp(r.cost)), UI.el('td', { html: `<span class="val-st gone">${esc(r.status)}</span>` })]))),
            ]) : UI.el('div', { class: 'val-empty' }, 'Belum ada layer yang habis terpakai pada periode ini.'),
        ]);
        return [active, used];
    }

    function queueCard(d) {
        const all = d.layers.used.concat(d.layers.active).sort((a, b) => String(a.received + a.batch_id).localeCompare(String(b.received + b.batch_id))).slice(-10);
        return UI.el('div', { class: 'val-subcard', 'data-testid': 'val-fifo-queue' }, [
            UI.el('div', { class: 'val-card-title sm' }, `Visual Alur FIFO — ${d.item.name}`),
            UI.el('div', { class: 'val-card-sub' }, 'Urutan layer pembelian dan pemakaian stok (FIFO Queue)'),
            all.length ? UI.el('div', { class: 'val-queue' }, all.map((l, i) => UI.el('div', { class: 'val-q-wrap' }, [
                UI.el('div', { class: `val-q ${l.status === 'Habis' ? 'gone' : 'ok'}`, 'data-testid': 'val-queue-layer' }, [
                    UI.el('div', { class: 'val-q-date' }, fmtDate(l.received)), UI.el('div', { class: 'val-q-qty' }, `${qn(l.remaining ?? l.used)} ${d.item.unit}`),
                    UI.el('div', { class: 'val-q-cost' }, `@ ${rp(l.cost)}`), UI.el('div', { class: 'val-q-st' }, l.status === 'Habis' ? 'Habis' : `Tersisa ${qn(l.remaining)}`),
                ]),
                i < all.length - 1 ? UI.el('span', { class: 'val-q-arrow' }, '→') : null,
            ]))) : UI.el('div', { class: 'val-empty' }, 'Tidak ada layer untuk ditampilkan.'),
        ]);
    }

    // ---- Average side: formula + steps (real numbers only)
    function formulaCard(d) {
        const f = d.formula;
        const unit = d.item.unit;
        const ex = [];
        if (f && f.inbound) {
            const i = f.inbound;
            ex.push(UI.el('div', {}, `Setelah pembelian ${fmtDate(i.date)}${i.reference ? ` (${i.reference})` : ''}:`));
            ex.push(UI.el('div', { class: 'val-math', 'data-testid': 'val-avg-example-in' }, `Average cost = (${rp(i.value_before)} + ${rp(i.value_in)}) / (${qn(i.qty_before)} + ${qn(i.qty_in)}) = ${rp(i.value_after)} / ${qn(i.qty_after)} = ${rp(i.avg_after)} per ${unit}`));
        }
        if (f && f.outbound) {
            const o = f.outbound;
            ex.push(UI.el('div', { class: 'val-math', 'data-testid': 'val-avg-example-out' }, `Keluar ${fmtDate(o.date)}: ${qn(o.qty)} ${unit} × ${rp(o.avg)} = ${rp(o.hpp)} → sisa ${qn(o.qty_after)} ${unit} bernilai ${rp(o.value_after)}`));
        }
        return UI.el('div', { class: 'val-subcard', 'data-testid': 'val-formula' }, [
            UI.el('div', { class: 'val-card-title sm' }, '💡 Rumus Average Cost'),
            UI.el('div', { class: 'val-formula' }, [UI.el('span', {}, 'Average Cost ='), UI.el('div', { class: 'val-frac' }, [UI.el('div', {}, 'Total Nilai Persediaan'), UI.el('div', {}, 'Total Qty Tersedia')])]),
            UI.el('div', { class: 'val-card-sub' }, 'Keterangan:'),
            UI.el('ul', { class: 'val-ul' }, [
                UI.el('li', {}, 'Average cost (harga rata-rata) dihitung setiap kali ada barang masuk bernilai (pembelian, opening, adjustment +).'),
                UI.el('li', {}, 'Saat ada barang keluar, HPP menggunakan average cost pada saat itu; barang keluar sendiri tidak mengubah average.'),
                UI.el('li', {}, 'Nilai persediaan = saldo qty × average cost berjalan.'),
            ]),
            UI.el('div', { class: 'val-example', 'data-testid': 'val-avg-example' }, [UI.el('b', {}, `Contoh Perhitungan — ${d.item.name}`)].concat(ex.length ? ex : [UI.el('div', {}, d.average_unknown ? 'Average tidak dapat direkonstruksi untuk barang ini.' : 'Belum ada pembelian / pemakaian pada periode ini untuk dicontohkan.')])),
        ]);
    }

    function stepsCard(d) {
        const unit = d.item.unit;
        const steps = [];
        d.rows.filter((r) => !r.unknown).slice(0, 8).forEach((r) => {
            const day = fmtDate(r.date);
            if (!nil(r.qty_in) && !nil(r.value_in) && r.type !== 'REVERSAL') {
                steps.push(`${day}: Masuk ${qn(r.qty_in)} ${unit} @ ${unitCost(r.unit_cost_in)} → Average cost = (${rp(r.value_before || 0)} + ${rp(r.value_in)}) / (${qn(r.qty_before)} + ${qn(r.qty_in)}) = ${nil(r.avg_after) ? '—' : rp(r.avg_after)}`);
            } else if (!nil(r.qty_out) && !nil(r.hpp)) {
                steps.push(`${day}: ${r.type_label} ${qn(r.qty_out)} ${unit} → HPP memakai average cost saat ini (${nil(r.avg_before) ? '—' : rp(r.avg_before)}). Nilai keluar = ${qn(r.qty_out)} × ${nil(r.avg_before) ? '—' : rp(r.avg_before)} = ${rp(r.hpp)}. Sisa saldo ${qn(r.bal_qty)} ${unit} bernilai ${nil(r.bal_value) ? '—' : rp(r.bal_value)}.`);
            } else {
                steps.push(`${day}: ${r.type_label}${r.reference ? ` (${r.reference})` : ''} — saldo ${qn(r.bal_qty)} ${unit}, nilai ${nil(r.bal_value) ? '—' : rp(r.bal_value)}.`);
            }
        });
        return UI.el('div', { class: 'val-subcard val-steps', 'data-testid': 'val-avg-steps' }, [
            UI.el('div', { class: 'val-card-title sm' }, 'ⓘ Penjelasan Proses Perhitungan'),
            steps.length ? UI.el('ol', { class: 'val-ol' }, steps.map((s) => UI.el('li', {}, s))) : UI.el('div', { class: 'val-empty' }, d.average_unknown ? 'Average tidak dapat direkonstruksi untuk barang ini.' : 'Tidak ada pergerakan pada periode ini.'),
        ]);
    }

    function itemCompareCard(d) {
        const c = d.comparison;
        const diff = (v) => (v === null ? '—' : v === 0 ? 'Rp 0' : `${v > 0 ? '+' : '−'}${rp(Math.abs(v))}`);
        const known = c.available && c.known;
        const rows = d.compare_rows;
        return UI.el('div', { class: 'val-card', 'data-testid': 'val-item-compare' }, [
            UI.el('div', { class: 'val-card-head' }, [UI.el('div', { class: 'val-card-title' }, `FIFO vs Average — ${d.item.name}`), UI.el('div', { class: 'val-card-sub' }, 'Tidak ada metode yang "salah": FIFO = operasional sistem, Average = analitis.')]),
            !known ? UI.el('div', { class: 'val-note' }, `Average tidak dapat direkonstruksi untuk barang ini${c.reason ? ` — ${c.reason}` : ''}. Hanya FIFO yang ditampilkan: HPP ${rp(c.fifo.hpp)}, nilai akhir ${rp(c.fifo.closing)}.`)
                : UI.el('table', { class: 'val-table val-cmp-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['', 'FIFO (operasional)', 'Average (analitis)', 'Selisih (Average − FIFO)'].map((h, i) => UI.el('th', { class: i ? 'num' : '' }, h)))]),
                    UI.el('tbody', {}, [
                        UI.el('tr', { 'data-testid': 'val-itemcmp-hpp' }, [UI.el('td', {}, 'HPP Keluar'), UI.el('td', { class: 'num' }, rp(c.fifo.hpp)), UI.el('td', { class: 'num' }, rp(c.average.hpp)), UI.el('td', { class: 'num' }, diff(c.diff.hpp))]),
                        UI.el('tr', { 'data-testid': 'val-itemcmp-closing' }, [UI.el('td', {}, `Nilai Akhir (${qn(c.qty_close)} ${d.item.unit})`), UI.el('td', { class: 'num' }, rp(c.fifo.closing)), UI.el('td', { class: 'num' }, rp(c.average.closing)), UI.el('td', { class: 'num' }, diff(c.diff.closing))]),
                    ]),
                ]),
            known && rows.length ? UI.el('div', { class: 'val-scroll' }, [UI.el('table', { class: 'val-table val-cmp-table', 'data-testid': 'val-itemcmp-rows' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Referensi', 'Gudang', 'Qty Keluar', 'Layer FIFO', 'HPP FIFO', 'HPP Average', 'Selisih'].map((h, i) => UI.el('th', { class: i >= 3 && i !== 4 ? 'num' : '' }, h)))]),
                UI.el('tbody', {}, rows.map((r) => UI.el('tr', {}, [UI.el('td', {}, fmtDate(r.date)), UI.el('td', {}, esc(r.reference || '—')), UI.el('td', {}, esc(r.warehouse)), UI.el('td', { class: 'num' }, qn(r.qty)),
                    UI.el('td', {}, UI.el('span', { class: 'val-layers' }, layersText(r.layers))), UI.el('td', { class: 'num' }, rp(r.hpp_fifo)), UI.el('td', { class: 'num' }, nil(r.hpp_avg) ? '—' : rp(r.hpp_avg)), UI.el('td', { class: 'num' }, diff(r.diff))]))),
            ])]) : null,
        ]);
    }

    // ------------------------------------------------------------------ Per Hari
    function renderDays() {
        const main = $('val-main');
        main.innerHTML = '';
        const ov = S.overview;
        const fifo = S.method === 'fifo';
        const labelOf = (r) => (S.bucket === 'day' ? fmtDate(r.date) : S.bucket === 'week' ? `${fmtDate(r.date)} – ${fmtDate(r.to)}` : `${new Date(`${r.date}T00:00:00`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}`);
        const cols = fifo
            ? [['Tanggal', (r) => esc(labelOf(r)), () => 'TOTAL'], ['Nilai Stok Awal FIFO', (r) => rp(r.fifo.opening), (T) => rp(T.fifo.opening)], ['Inventory Cost Masuk', (r) => rp(r.fifo.cost_in), (T) => rp(T.fifo.cost_in)], ['HPP Keluar FIFO', (r) => rp(r.fifo.hpp), (T) => rp(T.fifo.hpp)],
               ['Transfer Bersih', (r) => rp(r.fifo.transfer), (T) => rp(T.fifo.transfer)], ['Adjustment & Koreksi', (r) => rp(r.fifo.adjustment), (T) => rp(T.fifo.adjustment)], ['Nilai Stok Akhir FIFO', (r) => rp(r.fifo.closing), (T) => rp(T.fifo.closing)],
               ['Layer Aktif', (r) => UI.formatNumber(r.fifo.layers, 0), () => '—'], ['Variance', (r) => rp(r.fifo.variance), () => '—']]
            : [['Tanggal', (r) => esc(labelOf(r)), () => 'TOTAL'], ['Nilai Stok Awal Average', (r) => rp(r.avg.opening), (T) => rp(T.avg.opening)], ['Nilai Masuk', (r) => rp(r.avg.cost_in), (T) => rp(T.avg.cost_in)], ['HPP Keluar Average', (r) => rp(r.avg.hpp), (T) => rp(T.avg.hpp)],
               ['Transfer Bersih', (r) => rp(r.avg.transfer), (T) => rp(T.avg.transfer)], ['Adjustment & Koreksi', (r) => rp(r.avg.adjustment), (T) => rp(T.avg.adjustment)], ['Nilai Stok Akhir Average', (r) => rp(r.avg.closing), (T) => rp(T.avg.closing)],
               ['Average Cost End-of-Day', (r) => (nil(r.avg.eod_cost) ? '—' : unitCost(r.avg.eod_cost)), () => '—'], ['Variance', (r) => rp(r.avg.variance), () => '—']];
        const head = UI.el('tr', {}, cols.map(([h], i) => UI.el('th', { class: i ? 'num' : '' }, h)));
        const body = ov.days.map((r) => UI.el('tr', { 'data-testid': 'val-day-row', 'data-date': r.date }, cols.map(([, f], i) => UI.el('td', { class: i ? 'num' : '', html: f(r) }))));
        const foot = UI.el('tr', { 'data-testid': 'val-days-total' }, cols.map(([, , t], i) => UI.el('td', { class: i ? 'num' : '' }, t(ov.days_footer))));
        const pick = S.itemId ? UI.el('label', { class: 'val-check' }, [UI.el('input', { type: 'checkbox', id: 'val-day-item', 'data-testid': 'val-day-item' }), ' Batasi ke barang terpilih']) : null;
        const eodHint = !fifo ? UI.el('div', { class: 'val-hint' }, 'Average Cost End-of-Day hanya dihitung untuk SATU barang (centang "Batasi ke barang terpilih"); untuk semua barang tidak ada satu average gabungan antar SKU — nilai total adalah penjumlahan hasil per barang.') : null;
        const card = UI.el('div', { class: 'val-card', id: 'val-days-card' }, [
            UI.el('div', { class: 'val-card-head' }, [
                UI.el('div', { class: 'val-card-title' }, [`Rekap Per Hari — ${METHOD_LABEL[S.method]} `, UI.el('span', { class: 'val-card-sub' }, fifo ? 'Opening + Cost In − HPP FIFO ± Transfer ± Adjustment = Closing' : 'Opening + Nilai Masuk − HPP Average ± Transfer ± Adjustment = Closing')]),
                pick,
            ]),
            UI.el('div', { class: 'val-scroll val-wide', 'data-testid': 'val-days-scroll' }, [UI.el('table', { class: 'val-table val-days', 'data-testid': 'val-days-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])]),
            eodHint,
            UI.el('div', { class: 'val-hint' }, 'Adjustment & Koreksi mencakup adjustment, reversal (void), produksi (bahan keluar) dan setiap sisi transaksi VOID (transaksi VOID dan reversal-nya saling meniadakan).'),
        ]);
        main.appendChild(card);
        const cb = $('val-day-item');
        if (cb) { cb.checked = !!S.dayOnlyItem; cb.addEventListener('change', () => { S.dayOnlyItem = cb.checked; loadAll(); }); }
    }

    return { render };
})();
