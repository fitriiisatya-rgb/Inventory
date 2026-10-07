/**
 * Laporan > Laporan Pembelian (redesign).
 *
 * Real Stock IN V2 invoice financials (GET /api/reports/purchase-v2/*, PurchaseReportService) — this file only renders them:
 *   filter bar (+ Nominal / Kuantitas toggle) -> KPI cards -> purchase chart (Harian / Mingguan / Bulanan) -> "Detail Pembelian" one row per
 *   invoice -> "Rincian per Barang" -> invoice drawer with the exact Stock IN V2 arithmetic.
 * The tables are rendered from the column catalogue the API returns (the exports use the same catalogue, so export == screen).
 *
 * Unknown stays unknown: legacy / historical purchases have no PPN / discount / freight breakdown and show "—" (never 0). Quantities of different
 * units are never added (cards and footers list them BY UNIT; the quantity chart plots one item only). READ-ONLY: only GET requests are issued.
 */
const ReportPembelianV3 = (() => {
    let S = null;
    const $ = (id) => document.getElementById(id);
    const COL_KEY = 'pur_hidden_cols_v1';

    // own read-only GETs (same-origin, session cookie) — independent of whichever api-client file a deployment executes
    const PurApi = {
        overview: (p) => ReportTools.apiGet('/reports/purchase-v2/overview', p),
        invoices: (p) => ReportTools.apiGet('/reports/purchase-v2/invoices', p),
        items: (p) => ReportTools.apiGet('/reports/purchase-v2/items', p),
        invoiceDetail: (p) => ReportTools.apiGet('/reports/purchase-v2/invoice-detail', p),
        exportUrl: (p) => `/api/reports/purchase-v2/export${ReportTools.qsOf(p)}`,
    };

    // ------------------------------------------------------------------ formatting
    const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nil = (v) => v === null || v === undefined || v === '';
    const rp = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const qn = (v) => (nil(v) ? '—' : UI.formatNumber(v, 3));
    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
        return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    const fmtTs = (v) => {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? v : d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const isoShift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d); };
    function monthRange() { const n = new Date(); return { start: iso(new Date(n.getFullYear(), n.getMonth(), 1)), end: iso(new Date(n.getFullYear(), n.getMonth() + 1, 0)) }; }
    const unitsText = (list) => (list && list.length ? list.map((u) => `${u.unit} ${UI.formatNumber(u.qty, 3)}`).join(' · ') : '—');

    function freshState() {
        const r = monthRange();
        return { start: r.start, end: r.end, warehouse: '', supplier: '', category: '', q: '', hist: '', mode: 'nominal', bucket: 'day', invQ: '', page: 1, perPage: 25, itemPage: 1, itemPerPage: 25, overview: null, invoices: null, items: null };
    }
    const hiddenCols = () => { try { return JSON.parse(localStorage.getItem(COL_KEY) || '{}'); } catch (e) { return {}; } };
    const saveHidden = (h) => { try { localStorage.setItem(COL_KEY, JSON.stringify(h)); } catch (e) { /* cosmetic only */ } };
    const QTY_INVOICE = new Set(['date', 'reference', 'supplier', 'warehouse', 'items', 'skus', 'qty_text', 'created_by', 'status']);
    const QTY_ITEMS = new Set(['sku', 'name', 'category', 'unit', 'qty', 'frequency', 'price_min', 'price_max', 'price_avg', 'base_qty', 'base_unit']);
    function visible(table, col) {
        if (S.mode === 'qty') return (table === 'invoices' ? QTY_INVOICE : QTY_ITEMS).has(col.key);
        if (col.key === 'qty_text' && table === 'invoices') return false;
        const h = hiddenCols()[table];
        return h && Object.prototype.hasOwnProperty.call(h, col.key) ? !h[col.key] : col.default;
    }

    function params(extra) {
        return { start_date: S.start, end_date: S.end, warehouse_id: S.warehouse || undefined, supplier_id: S.supplier || undefined, category_id: S.category || undefined, q: S.q || undefined, historical: S.hist || undefined, ...extra };
    }

    // ------------------------------------------------------------------ render
    function render(container) {
        S = freshState();
        container.innerHTML = '';
        container.classList.add('pur');
        ReportTools.onResize('pur-chart', () => { if (S.overview && $('pur-chart-card') && $('pur-chart-card').offsetParent) renderChart(); });
        const actions = ReportTools.actions({ id: 'pur', print: doPrint, excel: () => doExcel('workbook'), extras: [
            { label: 'CSV — Detail Invoice', testid: 'pur-export-invoices', run: () => doExcel('invoices') },
            { label: 'CSV — Detail Barang', testid: 'pur-export-items', run: () => doExcel('items') },
            { label: 'CSV — Baris Invoice-Barang', testid: 'pur-export-lines', run: () => doExcel('lines') },
        ] });
        container.appendChild(UI.el('div', { class: 'pur-head' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'pur-title' }, 'Laporan Pembelian'),
                UI.el('p', { class: 'pur-desc' }, 'Analisis pembelian eksternal (Stock IN) dari invoice nyata: nilai barang, diskon, PPN, ongkos kirim, supplier, dan harga beli aktual.'),
            ]),
            actions,
        ]));
        container.appendChild(buildFilterCard());
        container.appendChild(UI.el('div', { id: 'pur-banner' }));
        container.appendChild(UI.el('div', { id: 'pur-kpis', class: 'pur-kpis', 'data-testid': 'pur-kpis' }));
        container.appendChild(UI.el('div', { id: 'pur-chart-card', class: 'pur-card' }));
        container.appendChild(UI.el('div', { id: 'pur-invoices-card', class: 'pur-card' }));
        container.appendChild(UI.el('div', { id: 'pur-items-card', class: 'pur-card' }));
        container.appendChild(UI.el('div', { class: 'pur-legend' },
            'Total Pembelian = nilai invoice (pembayaran) dari transaksi Stock IN POSTED: Subtotal Barang + PPN − Diskon Invoice + Ongkos Kirim. Subtotal Barang = Gross − Diskon Barang (DPP). '
            + 'Diskon Invoice dialokasikan proporsional terhadap total baris, ongkos kirim proporsional terhadap DPP bersih — aturan yang sama dengan Stock IN V2. PPN memakai tarif transaksi (0% / 11% / custom). '
            + 'Angka ini BUKAN nilai persediaan FIFO. Invoice VOID ditampilkan tetapi tidak dihitung. Pembelian legacy/historis tidak punya rincian komponen: tampil "—" (bukan 0) dan hanya masuk ke Total sebagai nilai tercatat. '
            + 'Harga Beli = harga pada transaksi (bukan harga master terbaru).'));
        loadAll();
    }

    function exportUrl(kind) {
        return PurApi.exportUrl(params({ kind, inv_q: S.invQ || undefined }));
    }
    async function doExcel(kind) {
        await ReportTools.download(exportUrl(kind), kind === 'workbook' ? 'Laporan_Pembelian.xlsx' : `Laporan_Pembelian_${kind}.csv`);
    }
    const selText = (id, all) => { const e = $(id); return e && e.value ? e.selectedOptions[0].textContent.trim() : all; };
    /** Cetak: the SAME workbook tables the Excel file is made of (?format=json), narrowed to the columns currently visible on screen. */
    async function doPrint() {
        const payload = await ReportTools.apiGet('/reports/purchase-v2/export', params({ kind: 'workbook', format: 'json', inv_q: S.invQ || undefined }));
        const labelsOf = (res, table) => (res ? res.columns.filter((c) => visible(table, c)).map((c) => c.label) : undefined);
        const n = S.overview.kpi.nominal;
        const k = S.overview.kpi;
        const qty = S.mode === 'qty';
        const meta = [['Periode', `${fmtDate(S.start)} s/d ${fmtDate(S.end)}`], ['Gudang', selText('pur-wh', 'Semua Gudang')], ['Supplier', selText('pur-sup', 'Semua Supplier')], ['Kategori', selText('pur-cat', 'Semua Kategori')],
            ['Barang', S.q || ''], ['Data', selText('pur-hist', 'Live saja')], ['Tampilan', qty ? 'Kuantitas (Qty) — per satuan, tidak dijumlahkan lintas satuan' : 'Nominal (Rp)']];
        const spec = ReportTools.specFromPayload(payload, [
            { sheet: 'Detail Invoice', title: 'Detail Pembelian (per Invoice)', columns: labelsOf(S.invoices, 'invoices'), maxRows: 2500 },
            { sheet: 'Detail Barang', title: 'Rincian per Barang', columns: labelsOf(S.items, 'items'), maxRows: 2500 },
        ], {
            subtitle: `Periode ${fmtDate(S.start)} s/d ${fmtDate(S.end)}`, orientation: 'landscape',
            meta,
            kpis: qty ? [{ label: 'Transaksi', value: UI.formatNumber(n.invoices, 0) }, { label: 'SKU Dibeli', value: UI.formatNumber(n.skus, 0) }, { label: 'Supplier', value: UI.formatNumber(n.suppliers, 0) }, { label: 'Qty per Satuan', value: unitsText(k.qty_by_unit) }]
                : [{ label: 'Total Nilai Pembelian', value: rp(n.total), sub: `${UI.formatNumber(n.invoices, 0)} invoice` }, { label: 'Subtotal Barang (DPP)', value: rp(n.subtotal) }, { label: 'Diskon', value: rp(n.discount) },
                    { label: 'PPN', value: rp(n.ppn) }, { label: 'Ongkos Kirim', value: rp(n.freight) }, { label: 'Supplier', value: UI.formatNumber(n.suppliers, 0) }],
        });
        ReportTools.printDocument(spec);
    }

    function buildFilterCard() {
        const opts = (list, name) => list.map((x) => `<option value="${esc(x.id)}">${esc(x[name || 'name'])}</option>`).join('');
        const u = Auth.user();
        const isStock = !!(u && u.role_code === 'STOCK' && u.warehouse_id);
        const card = UI.el('div', { class: 'pur-card pur-filters' }, [
            UI.el('div', { class: 'pur-filter-grid', html: `
                <div class="pur-f"><label>Periode Tanggal</label><div class="pur-dates"><input type="date" id="pur-start" data-testid="pur-start" value="${esc(S.start)}"><span>—</span><input type="date" id="pur-end" data-testid="pur-end" value="${esc(S.end)}"></div></div>
                <div class="pur-f"><label>Gudang</label><select id="pur-wh" data-testid="pur-wh" ${isStock ? 'disabled' : ''}><option value="">Semua Gudang</option>${opts(Master.warehouses().filter((w) => w.is_active !== false))}</select></div>
                <div class="pur-f"><label>Supplier</label><select id="pur-sup" data-testid="pur-sup"><option value="">Semua Supplier</option>${opts(Master.suppliers())}</select></div>
                <div class="pur-f"><label>Kategori</label><select id="pur-cat" data-testid="pur-cat"><option value="">Semua Kategori</option>${opts(Master.categories().filter((c) => c.is_active))}</select></div>
                <div class="pur-f"><label>Barang</label><input type="text" id="pur-q" data-testid="pur-q" placeholder="Cari kode atau nama barang…" autocomplete="off"></div>
                <div class="pur-f"><label>Data</label><select id="pur-hist" data-testid="pur-hist"><option value="">Live saja</option><option value="1">Historis saja</option><option value="all">Live + Historis</option></select></div>
            ` }),
            UI.el('div', { class: 'pur-filter-row2' }, [
                UI.el('div', { class: 'pur-toggle-wrap' }, [
                    UI.el('span', { class: 'pur-toggle-label' }, 'Tampilan Data'),
                    UI.el('div', { class: 'pur-seg', role: 'group', 'aria-label': 'Tampilan data' }, [
                        UI.el('button', { type: 'button', class: 'pur-seg-btn on', 'data-mode': 'nominal', 'data-testid': 'pur-mode-nominal', 'aria-pressed': 'true' }, 'Nominal (Rp)'),
                        UI.el('button', { type: 'button', class: 'pur-seg-btn', 'data-mode': 'qty', 'data-testid': 'pur-mode-qty', 'aria-pressed': 'false' }, 'Kuantitas (Qty)'),
                    ]),
                    UI.el('div', { class: 'pur-quick' }, [['7 Hari', 7], ['30 Hari', 30], ['Bulan Ini', 0]].map(([label, n]) => {
                        const b = UI.el('button', { type: 'button', class: 'pur-quick-btn', 'data-testid': `pur-quick-${n}` }, label);
                        b.addEventListener('click', () => { const r = n === 0 ? monthRange() : { start: isoShift(-(n - 1)), end: isoShift(0) }; $('pur-start').value = r.start; $('pur-end').value = r.end; applyFilters(); });
                        return b;
                    })),
                ]),
                UI.el('div', { class: 'pur-filter-actions' }, [
                    UI.el('button', { type: 'button', class: 'btn btn-primary', id: 'pur-apply', 'data-testid': 'pur-apply' }, '⏷ Terapkan Filter'),
                    UI.el('button', { type: 'button', class: 'btn btn-secondary', id: 'pur-reset', 'data-testid': 'pur-reset' }, '↺ Reset'),
                ]),
            ]),
        ]);
        setTimeout(() => {
            if (isStock) { $('pur-wh').value = String(u.warehouse_id); S.warehouse = String(u.warehouse_id); }
            $('pur-apply').addEventListener('click', applyFilters);
            $('pur-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
            $('pur-reset').addEventListener('click', () => {
                const keep = isStock ? S.warehouse : '';
                S = Object.assign(freshState(), { mode: S.mode, warehouse: keep });
                $('pur-start').value = S.start; $('pur-end').value = S.end; $('pur-wh').value = keep; $('pur-sup').value = ''; $('pur-cat').value = ''; $('pur-q').value = ''; $('pur-hist').value = '';
                loadAll();
            });
            card.querySelectorAll('.pur-seg-btn').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
        }, 0);
        return card;
    }

    function applyFilters() {
        S.start = $('pur-start').value; S.end = $('pur-end').value; S.warehouse = $('pur-wh').value; S.supplier = $('pur-sup').value; S.category = $('pur-cat').value;
        S.q = $('pur-q').value.trim(); S.hist = $('pur-hist').value; S.page = 1; S.itemPage = 1; S.invQ = '';
        loadAll();
    }
    function setMode(mode) {
        S.mode = mode;
        document.querySelectorAll('.pur-seg-btn').forEach((b) => { const on = b.dataset.mode === mode; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        if (S.overview) { renderKpis(); renderChart(); renderInvoices(); renderItems(); }
    }

    // ------------------------------------------------------------------ data
    async function loadAll() {
        ['pur-chart-card', 'pur-invoices-card', 'pur-items-card'].forEach((id) => { $(id).innerHTML = '<div class="pur-loading" data-testid="pur-loading">Memuat data pembelian…</div>'; });
        $('pur-kpis').innerHTML = '';
        $('pur-banner').innerHTML = '';
        try {
            const [ov, inv, it] = await Promise.all([
                PurApi.overview(params({ bucket: S.bucket })),
                PurApi.invoices(params({ page: S.page, per_page: S.perPage, inv_q: S.invQ || undefined })),
                PurApi.items(params({ page: S.itemPage, per_page: S.itemPerPage })),
            ]);
            S.overview = ov; S.invoices = inv; S.items = it;
        } catch (err) {
            ['pur-chart-card', 'pur-invoices-card', 'pur-items-card'].forEach((id) => { $(id).innerHTML = ''; });
            $('pur-invoices-card').appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'pur-error' }, `Gagal memuat laporan: ${err.message}`));
            return;
        }
        renderBanner(); renderKpis(); renderChart(); renderInvoices(); renderItems();
    }
    async function reloadInvoices() {
        try { S.invoices = await PurApi.invoices(params({ page: S.page, per_page: S.perPage, inv_q: S.invQ || undefined })); } catch (err) { UI.handleApiError(err); return; }
        renderInvoices();
    }
    async function reloadItems() {
        try { S.items = await PurApi.items(params({ page: S.itemPage, per_page: S.itemPerPage })); } catch (err) { UI.handleApiError(err); return; }
        renderItems();
    }
    async function reloadChart() {
        try { S.overview = await PurApi.overview(params({ bucket: S.bucket })); } catch (err) { UI.handleApiError(err); return; }
        renderChart();
    }

    function renderBanner() {
        const host = $('pur-banner');
        host.innerHTML = '';
        const ov = S.overview;
        const notes = [];
        if (ov.partial_by_item_filter) notes.push('Filter barang / kategori aktif: invoice yang hanya sebagian barangnya cocok dihitung dari baris yang cocok saja (ditandai "sebagian").');
        const n = ov.kpi.nominal;
        if (n.sources.Legacy.invoices > 0) notes.push(`${n.sources.Legacy.invoices} invoice legacy (tanpa rincian komponen) masuk Total sebagai nilai tercatat ${rp(n.sources.Legacy.total)}; komponen tampil "—".`);
        if (n.sources.Historis.invoices > 0) notes.push(`${n.sources.Historis.invoices} pembelian historis (data impor, hanya pelaporan) ikut dihitung karena filter "Data" memilihnya.`);
        if (ov.disclosures.void.invoices > 0) notes.push(`${ov.disclosures.void.invoices} invoice VOID (${rp(ov.disclosures.void.amount)}) ditampilkan tetapi tidak dihitung dalam total.`);
        notes.forEach((t) => host.appendChild(UI.el('div', { class: 'pur-note', 'data-testid': 'pur-note' }, t)));
    }

    // ------------------------------------------------------------------ KPI
    function renderKpis() {
        const k = S.overview.kpi;
        const n = k.nominal;
        const card = (key, cls, label, value, sub) => UI.el('div', { class: `pur-kpi ${cls}`, 'data-testid': `pur-kpi-${key}` }, [
            UI.el('div', { class: 'pur-kpi-label' }, label), UI.el('div', { class: 'pur-kpi-value', 'data-testid': `pur-kpi-${key}-value` }, value), UI.el('div', { class: 'pur-kpi-sub' }, sub),
        ]);
        const host = $('pur-kpis');
        host.innerHTML = '';
        if (S.mode === 'qty') {
            [
                card('invoices', 'blue', 'Jumlah Transaksi', UI.formatNumber(n.invoices, 0), `${UI.formatNumber(n.lines, 0)} baris barang`),
                card('skus', 'teal', 'Jumlah SKU Dibeli', UI.formatNumber(n.skus, 0), 'SKU berbeda'),
                card('suppliers', 'purple', 'Jumlah Supplier', UI.formatNumber(n.suppliers, 0), 'supplier berbeda'),
                card('qty', 'green', 'Qty per Satuan', k.qty_by_unit.length ? `${k.qty_by_unit.length} satuan` : '—', unitsText(k.qty_by_unit)),
            ].forEach((c) => host.appendChild(c));
            host.classList.add('four');
            ReportTools.decorateKpis(host);
            return;
        }
        host.classList.remove('four');
        const rates = n.ppn_rates.length ? n.ppn_rates.join(' / ') : '—';
        [
            card('total', 'blue', 'Total Nilai Pembelian', rp(n.total), `${UI.formatNumber(n.invoices, 0)} invoice · ${UI.formatNumber(n.skus, 0)} SKU`),
            card('subtotal', 'teal', 'Subtotal Barang', rp(n.subtotal), `DPP · diskon barang ${rp(n.item_discount)} · invoice ${rp(n.invoice_discount)}`),
            card('ppn', 'purple', 'PPN', rp(n.ppn), `Tarif transaksi: ${rates}`),
            card('freight', 'green', 'Ongkos Kirim', rp(n.freight), 'Ongkir pada invoice'),
            card('suppliers', 'indigo', 'Supplier', UI.formatNumber(n.suppliers, 0), `${UI.formatNumber(n.invoices, 0)} transaksi`),
        ].forEach((c) => host.appendChild(c));
        ReportTools.decorateKpis(host);
    }

    // ------------------------------------------------------------------ chart
    function niceMax(v) { if (v <= 0) return 1; const p = 10 ** Math.floor(Math.log10(v)); const m = v / p; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p; }
    function axisLabel(v, unit) {
        if (unit) return UI.formatNumber(v, 1);
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
        const host = $('pur-chart-card');
        host.innerHTML = '';
        const ov = S.overview;
        const qty = S.mode === 'qty';
        const single = ov.single_item;
        const seg = UI.el('div', { class: 'pur-seg pur-seg-sm', role: 'group' }, [['day', 'Harian'], ['week', 'Mingguan'], ['month', 'Bulanan']].map(([k, label]) => {
            const b = UI.el('button', { type: 'button', class: `pur-seg-btn${S.bucket === k ? ' on' : ''}`, 'data-testid': `pur-bucket-${k}` }, label);
            b.addEventListener('click', () => { S.bucket = k; reloadChart(); });
            return b;
        }));
        host.appendChild(UI.el('div', { class: 'pur-card-head' }, [
            UI.el('div', { class: 'pur-card-title' }, ['📈 Grafik Pembelian ', UI.el('span', { class: 'pur-card-sub' }, qty ? (single ? `(Qty ${single.base_unit})` : '(Jumlah invoice & SKU)') : '(Nominal Rp)')]), seg,
        ]));
        const rows = ov.trend;
        if (!rows.length) { host.appendChild(UI.el('div', { class: 'pur-empty' }, 'Tidak ada data.')); return; }
        if (qty && !single) {
            host.appendChild(UI.el('div', { class: 'pur-note', 'data-testid': 'pur-chart-qty-message' }, 'Pilih barang untuk melihat grafik kuantitas. Kuantitas satuan berbeda (PCS / KG / LTR) tidak dijumlahkan — grafik di bawah menampilkan jumlah invoice dan SKU.'));
        }
        const mode = qty ? (single ? 'qty' : 'count') : 'nominal';
        host.appendChild(buildSvg(rows, mode, single ? single.base_unit : null));
        host.appendChild(UI.el('div', { class: 'pur-chart-legend' }, (mode === 'nominal' ? [['#2f7bff', 'Total Pembelian'], ['#f59e0b', 'Jumlah Invoice']] : mode === 'qty' ? [['#22c55e', `Qty (${single.base_unit})`]] : [['#2f7bff', 'Jumlah Invoice'], ['#f59e0b', 'Jumlah SKU']])
            .map(([c, l]) => UI.el('span', {}, [UI.el('i', { style: `background:${c}` }), ` ${l}`]))));
    }
    function buildSvg(rows, mode, unit) {
        const NS = 'http://www.w3.org/2000/svg';
        const W = ReportTools.chartWidth($('pur-chart-card'), 900); const H = 210; const L = 66; const R = 40; const T = 14; const B = 32;
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('class', 'pur-svg'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Grafik pembelian'); svg.setAttribute('data-testid', 'pur-chart');
        const bar = (r) => (mode === 'nominal' ? r.total : mode === 'qty' ? (r.qty || 0) : r.invoices);
        const line = (r) => (mode === 'nominal' ? r.invoices : mode === 'count' ? r.skus : null);
        const max = niceMax(Math.max(...rows.map(bar), 0));
        const lmax = mode === 'qty' ? 1 : niceMax(Math.max(...rows.map((r) => line(r) || 0), 0));
        const y = (v) => T + (H - T - B) * (1 - v / max);
        const y2 = (v) => T + (H - T - B) * (1 - v / lmax);
        const el = (name, attrs, text) => { const e = document.createElementNS(NS, name); Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, String(v))); if (text !== undefined) e.textContent = text; return e; };
        for (let i = 0; i <= 4; i++) {
            const v = (max * i) / 4; const yy = y(v);
            svg.appendChild(el('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'pur-grid' }));
            svg.appendChild(el('text', { x: L - 8, y: yy + 4, class: 'pur-axis', 'text-anchor': 'end' }, axisLabel(v, mode !== 'nominal' ? (unit || '') || null : null)));
            if (mode !== 'qty') svg.appendChild(el('text', { x: W - R + 6, y: yy + 4, class: 'pur-axis', 'text-anchor': 'start' }, UI.formatNumber((lmax * i) / 4, 0)));
        }
        const step = (W - L - R) / rows.length;
        const bw = Math.max(3, Math.min(26, step * 0.5));
        const tip = UI.el('div', { class: 'pur-tip', hidden: 'hidden', 'data-testid': 'pur-tip' });
        const pts = [];
        const every = Math.max(1, Math.ceil(rows.length / 12));
        rows.forEach((r, i) => {
            const cx = L + step * i + step / 2;
            const v = bar(r);
            if (v > 0) svg.appendChild(el('rect', { x: cx - bw / 2, y: y(v), width: bw, height: Math.max(1, y(0) - y(v)), rx: 2, class: mode === 'qty' ? 'pur-bar-qty' : 'pur-bar' }));
            if (mode !== 'qty') pts.push([cx, y2(line(r) || 0)]);
            if (i % every === 0) svg.appendChild(el('text', { x: cx, y: H - 12, class: 'pur-axis', 'text-anchor': 'middle' }, bucketLabel(r.bucket)));
            const hit = el('rect', { x: L + step * i, y: T, width: step, height: H - T - B, class: 'pur-hit', 'data-testid': 'pur-hit', fill: 'transparent' });
            hit.addEventListener('mouseenter', () => {
                tip.innerHTML = '';
                const lines = mode === 'nominal'
                    ? [['Periode', bucketLabel(r.bucket)], ['Subtotal Barang', rp(r.subtotal)], ['Diskon', rp(r.discount)], ['PPN', rp(r.ppn)], ['Ongkos Kirim', rp(r.freight)], ['Total Pembelian', rp(r.total)], ['Transaksi', UI.formatNumber(r.invoices, 0)]]
                    : mode === 'qty' ? [['Periode', bucketLabel(r.bucket)], ['Qty', `${UI.formatNumber(r.qty || 0, 3)} ${unit}`], ['Transaksi', UI.formatNumber(r.invoices, 0)]]
                        : [['Periode', bucketLabel(r.bucket)], ['Invoice', UI.formatNumber(r.invoices, 0)], ['SKU', UI.formatNumber(r.skus, 0)]];
                lines.forEach(([k2, v2]) => tip.appendChild(UI.el('div', {}, [UI.el('b', {}, `${k2}: `), v2])));
                tip.style.left = `${(cx / W) * 100}%`;
                tip.hidden = false;
            });
            hit.addEventListener('mouseleave', () => { tip.hidden = true; });
            svg.appendChild(hit);
        });
        if (pts.length) {
            svg.appendChild(el('polyline', { points: pts.map((p) => p.join(',')).join(' '), class: 'pur-line', fill: 'none' }));
            pts.forEach((p) => svg.appendChild(el('circle', { cx: p[0], cy: p[1], r: 2.5, class: 'pur-dot' })));
        }
        const wrap = UI.el('div', { class: 'pur-chart-wrap' });
        wrap.appendChild(svg);
        wrap.appendChild(tip);
        return wrap;
    }

    // ------------------------------------------------------------------ tables
    const NUMERIC = new Set(['money', 'int', 'qty', 'pct']);
    function statusBadge(v) {
        const cls = { POSTED: 'success', VOID: 'danger', 'SEBAGIAN VOID': 'warning' }[v] || 'muted';
        return UI.el('span', { class: `badge pur-badge-${cls}` }, v);
    }
    function cell(col, row) {
        const v = row[col.key];
        switch (col.type) {
            case 'date': return fmtDate(v);
            case 'ts': return fmtTs(v);
            case 'money': return rp(v);
            case 'qty': return qn(v);
            case 'int': return nil(v) ? '—' : UI.formatNumber(v, 0);
            case 'pct': return nil(v) ? '—' : `${UI.formatNumber(v, 2)}%`;
            case 'rate': return nil(v) ? '—' : `${UI.formatNumber(v, 2)}%`;
            case 'status': return nil(v) ? '—' : statusBadge(v);
            default:
                if (col.key === 'reference') {
                    const kids = [String(v)];
                    if (row.source && row.source !== 'V2') kids.push(' ', UI.el('span', { class: 'pur-src', title: row.source === 'Legacy' ? 'Pembelian legacy: tanpa rincian komponen' : row.source === 'Historis' ? 'Data historis (hanya pelaporan)' : 'Campuran' }, row.source));
                    if (row.partial) kids.push(' ', UI.el('span', { class: 'pur-src partial', title: `Hanya ${row.lines_in_filter} dari ${row.lines_total} baris yang cocok dengan filter barang` }, 'sebagian'));
                    return UI.el('span', {}, kids);
                }
                return nil(v) ? '—' : String(v);
        }
    }
    function columnMenu(table, columns, onChange) {
        const wrap = UI.el('div', { class: 'pur-colmenu-wrap' });
        const btn = UI.el('button', { type: 'button', class: 'btn btn-secondary pur-col-btn', 'data-testid': `pur-cols-${table}` }, '⚙ Kolom');
        const menu = UI.el('div', { class: 'pur-colmenu', hidden: 'hidden' });
        columns.forEach((c) => {
            const cb = UI.el('input', { type: 'checkbox', 'data-col': c.key, ...(visible(table, c) ? { checked: 'checked' } : {}), ...(S.mode === 'qty' ? { disabled: 'disabled' } : {}) });
            cb.addEventListener('change', () => { const h = hiddenCols(); h[table] = h[table] || {}; h[table][c.key] = !cb.checked; saveHidden(h); onChange(); });
            menu.appendChild(UI.el('label', { class: 'pur-colopt' }, [cb, ` ${c.label}`]));
        });
        btn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
        menu.addEventListener('click', (e) => e.stopPropagation());
        document.addEventListener('click', () => { menu.hidden = true; });
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    }
    function pager(p, onPage, label) {
        const bar = UI.el('div', { class: 'pur-pager' }, [UI.el('span', {}, `Total ${UI.formatNumber(p.total, 0)} ${label}`)]);
        const nav = UI.el('div', { class: 'pur-pager-nav' });
        const btn = (text, page, disabled, cur) => { const b = UI.el('button', { type: 'button', class: `pur-pg${cur ? ' on' : ''}`, ...(disabled ? { disabled: 'disabled' } : {}) }, text); b.addEventListener('click', () => onPage(page)); return b; };
        nav.appendChild(btn('‹', p.page - 1, p.page <= 1));
        const shown = new Set([1, p.total_pages, p.page, p.page - 1, p.page + 1].filter((n) => n >= 1 && n <= p.total_pages));
        let prev = 0;
        [...shown].sort((a, b) => a - b).forEach((n) => { if (n - prev > 1) nav.appendChild(UI.el('span', { class: 'pur-pg-gap' }, '…')); nav.appendChild(btn(String(n), n, false, n === p.page)); prev = n; });
        nav.appendChild(btn('›', p.page + 1, p.page >= p.total_pages));
        bar.appendChild(nav);
        return bar;
    }
    function totalCell(col, i, totals, count, label) {
        if (i === 0) return label;
        if (Object.prototype.hasOwnProperty.call(totals, col.key) && ['money', 'int', 'qty'].includes(col.type)) return col.type === 'money' ? rp(totals[col.key]) : UI.formatNumber(totals[col.key], col.type === 'qty' ? 3 : 0);
        return '';
    }

    function renderInvoices() {
        const res = S.invoices;
        const card = $('pur-invoices-card');
        card.innerHTML = '';
        const cols = res.columns.filter((c) => visible('invoices', c));
        const search = UI.el('input', { type: 'text', class: 'pur-search', 'data-testid': 'pur-inv-q', placeholder: 'Cari tanggal, invoice, supplier, barang…', value: S.invQ });
        search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { S.invQ = search.value.trim(); S.page = 1; reloadInvoices(); } });
        search.addEventListener('blur', () => { if (search.value.trim() !== S.invQ) { S.invQ = search.value.trim(); S.page = 1; reloadInvoices(); } });
        card.appendChild(UI.el('div', { class: 'pur-card-head' }, [
            UI.el('div', { class: 'pur-card-title' }, ['🧾 Detail Pembelian ', UI.el('span', { class: 'pur-card-sub' }, '(satu baris per invoice — klik 👁 untuk rincian)')]),
            UI.el('div', { class: 'pur-card-tools' }, [search, columnMenu('invoices', res.columns, renderInvoices)]),
        ]));
        if (!res.rows.length) { card.appendChild(UI.el('div', { class: 'pur-empty', 'data-testid': 'pur-invoices-empty' }, 'Tidak ada pembelian untuk filter ini.')); return; }
        const head = UI.el('tr', {}, cols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) ? 'num' : '' }, c.label)).concat([UI.el('th', {}, 'Aksi')]));
        const body = res.rows.map((r) => {
            const eye = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary pur-eye', title: 'Lihat rincian invoice', 'data-testid': 'pur-eye' }, '👁');
            eye.addEventListener('click', (e) => { e.stopPropagation(); openInvoice(r); });
            const tr = UI.el('tr', { class: `click${r.status === 'VOID' ? ' void' : ''}`, 'data-testid': 'pur-invoice-row', 'data-ref': r.reference },
                cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [cell(c, r)])).concat([UI.el('td', {}, [eye])]));
            tr.addEventListener('click', () => openInvoice(r));
            return tr;
        });
        const f = res.footer;
        const foot = UI.el('tr', { 'data-testid': 'pur-invoices-total' }, cols.map((c, i) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [totalCell(c, i, f.totals, f.count, 'GRAND TOTAL')])).concat([UI.el('td', {}, '')]));
        card.appendChild(UI.el('div', { class: 'pur-scroll pur-wide', 'data-testid': 'pur-invoices-scroll' }, [UI.el('table', { class: 'pur-table', 'data-testid': 'pur-invoices-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])]));
        if (f.incomplete_components > 0) card.appendChild(UI.el('div', { class: 'pur-hint' }, `${f.incomplete_components} invoice tanpa rincian komponen (legacy/historis) hanya memiliki nilai Total — kolom lain "—" dan tidak ikut dijumlahkan.`));
        if (f.voided_total > 0) card.appendChild(UI.el('div', { class: 'pur-hint' }, `Invoice VOID (${rp(f.voided_total)}) ditampilkan tercoret dan tidak dihitung dalam GRAND TOTAL.`));
        card.appendChild(pager(res.pagination, (p) => { S.page = p; reloadInvoices(); }, 'invoice'));
    }

    function renderItems() {
        const res = S.items;
        const card = $('pur-items-card');
        card.innerHTML = '';
        const cols = res.columns.filter((c) => visible('items', c));
        card.appendChild(UI.el('div', { class: 'pur-card-head' }, [
            UI.el('div', { class: 'pur-card-title' }, ['📦 Rincian per Barang ', UI.el('span', { class: 'pur-card-sub', title: 'Ongkos kirim dan diskon invoice pada rincian per barang dialokasikan secara proporsional sesuai metode costing transaksi.' }, '(alokasi diskon invoice & ongkir proporsional sesuai Stock IN V2)')]),
            columnMenu('items', res.columns, renderItems),
        ]));
        if (!res.rows.length) { card.appendChild(UI.el('div', { class: 'pur-empty', 'data-testid': 'pur-items-empty' }, 'Tidak ada barang untuk filter ini.')); return; }
        const head = UI.el('tr', {}, cols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) ? 'num' : '', title: c.key === 'price_avg' ? 'Σ(qty × harga beli) / Σqty' : '' }, c.label)));
        const body = res.rows.map((r) => UI.el('tr', { 'data-testid': 'pur-item-row', 'data-sku': r.sku }, cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [cell(c, r)]))));
        const f = res.footer;
        const foot = UI.el('tr', { 'data-testid': 'pur-items-total' }, cols.map((c, i) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [totalCell(c, i, f.totals, f.count, 'GRAND TOTAL')])));
        card.appendChild(UI.el('div', { class: 'pur-scroll pur-wide', 'data-testid': 'pur-items-scroll' }, [UI.el('table', { class: 'pur-table', 'data-testid': 'pur-items-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])]));
        card.appendChild(UI.el('div', { class: 'pur-hint', 'data-testid': 'pur-items-units' }, `Qty beli per satuan (tidak dijumlahkan lintas satuan): ${unitsText(f.qty_by_unit)}. Barang dengan satuan beli berbeda ditampilkan terpisah.`));
        card.appendChild(pager(res.pagination, (p) => { S.itemPage = p; reloadItems(); }, 'baris barang'));
    }

    // ------------------------------------------------------------------ invoice drawer
    function wideDrawer(title, fill) {
        Drawer.open({ title, render: (body) => { fill(body); } });
        const panel = document.querySelector('.drawer');
        if (!panel) return;
        panel.classList.add('pur-drawer');
        const mo = new MutationObserver(() => { if (!panel.classList.contains('open')) { panel.classList.remove('pur-drawer'); mo.disconnect(); } });
        mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    async function openInvoice(row) {
        wideDrawer(`${row.reference} — ${row.supplier}`, async (body) => {
            body.innerHTML = '<div class="alert alert-info" data-testid="pur-drawer-loading">Memuat rincian invoice…</div>';
            let d;
            try { d = await PurApi.invoiceDetail({ tx_ids: row.tx_ids.join(',') }); } catch (err) { body.innerHTML = ''; body.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'pur-drawer-error' }, `Gagal memuat: ${err.message}`)); return; }
            body.innerHTML = '';
            const inv = d.invoice;
            const kv = (rows) => UI.el('div', { class: 'pur-kv' }, rows.map(([k, v]) => UI.el('div', { class: 'pur-kv-row' }, [UI.el('span', { class: 'pur-kv-k' }, k), UI.el('span', { class: 'pur-kv-v' }, [nil(v) ? '—' : v])])));
            body.appendChild(UI.el('div', { 'data-testid': 'pur-drawer-header' }, [kv([
                ['No. Invoice / Referensi', inv.reference], ['Supplier', inv.supplier], ['Gudang', inv.warehouse], ['Tanggal transaksi', fmtDate(inv.date)], ['Timestamp dibuat', fmtTs(inv.created_at)],
                ['Dibuat oleh', inv.created_by], ['Status', statusBadge(inv.status)], ['Sumber data', inv.source === 'V2' ? 'Stock IN V2 (rincian lengkap)' : inv.source === 'Legacy' ? 'Legacy (tanpa rincian komponen)' : inv.source],
            ])]));
            const cols = d.columns.filter((c) => ['sku', 'name', 'category', 'unit', 'qty', 'price', 'gross', 'item_discount', 'dpp', 'ppn_rate', 'ppn', 'invoice_discount', 'freight', 'total'].includes(c.key));
            const head = UI.el('tr', {}, cols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) || c.type === 'rate' ? 'num' : '' }, c.label)));
            const trs = d.lines.map((l) => UI.el('tr', { class: l.counted ? '' : 'void', 'data-testid': 'pur-drawer-line' }, cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) || c.type === 'rate' ? 'num' : '' }, [cell(c, l)]))));
            const sums = d.arithmetic;
            const foot = sums ? UI.el('tr', { 'data-testid': 'pur-drawer-total' }, cols.map((c, i) => UI.el('td', { class: NUMERIC.has(c.type) || c.type === 'rate' ? 'num' : '' }, [i === 0 ? 'TOTAL' : ({ gross: rp(sums.gross), item_discount: rp(sums.item_discount), dpp: rp(sums.subtotal), ppn: rp(sums.ppn), invoice_discount: rp(sums.invoice_discount), freight: rp(sums.freight), total: rp(sums.grand_total) }[c.key] ?? '')]))) : null;
            body.appendChild(UI.el('div', { class: 'pur-scroll' }, [UI.el('table', { class: 'pur-table', 'data-testid': 'pur-drawer-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, trs), ...(foot ? [UI.el('tfoot', {}, [foot])] : [])])]));
            if (sums) {
                const eq = (label, value, cls) => UI.el('div', { class: `pur-eq ${cls || ''}` }, [UI.el('span', {}, label), UI.el('b', {}, value)]);
                body.appendChild(UI.el('div', { class: 'pur-arith', 'data-testid': 'pur-arith' }, [
                    UI.el('div', { class: 'pur-arith-title' }, 'Perhitungan invoice (Stock IN V2)'),
                    eq('Gross Barang', rp(sums.gross)), eq('− Diskon Barang', rp(sums.item_discount)), eq('= Subtotal Barang', rp(sums.subtotal), 'sub'),
                    eq('+ PPN', rp(sums.ppn)), eq('− Diskon Invoice', rp(sums.invoice_discount)), eq('+ Ongkos Kirim', rp(sums.freight)), eq('= GRAND TOTAL', rp(sums.grand_total), 'grand'),
                    UI.el('div', { class: 'pur-arith-note' }, `Setara pada sheet Stock IN: Subtotal (termasuk PPN) ${rp(sums.subtotal_incl_ppn)} − Diskon Invoice ${rp(sums.invoice_discount)} + Ongkos Kirim ${rp(sums.freight)} = ${rp(sums.grand_total)}. `
                        + `Basis DPP: diskon invoice ${rp(sums.invoice_discount_dpp)}, PPN setelah diskon ${rp(sums.ppn_net)}.`),
                    UI.el('div', { class: 'pur-arith-note', 'data-testid': 'pur-treatments' }, `Perlakuan PPN: ${sums.ppn_treatments.join(', ') || '—'} · Perlakuan ongkos kirim: ${sums.freight_treatments.join(', ') || '—'} · Nilai persediaan FIFO (bukan nilai invoice): ${rp(sums.inventory_cost)}.`),
                ]));
            } else {
                body.appendChild(UI.el('div', { class: 'pur-note', 'data-testid': 'pur-drawer-nobreakdown' }, inv.status === 'VOID' ? 'Invoice ini VOID — tidak dihitung dalam laporan.' : 'Pembelian legacy / historis: rincian PPN, diskon dan ongkos kirim tidak tersimpan sehingga tidak ditampilkan (—). Nilai tercatat saja yang diketahui.'));
            }
            d.notes && Object.values(d.notes).forEach((t) => body.appendChild(UI.el('div', { class: 'pur-hint' }, t)));
        });
    }

    return { render };
})();
