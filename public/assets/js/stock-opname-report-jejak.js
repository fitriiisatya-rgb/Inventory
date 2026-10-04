/**
 * MOCKUP — "Jejak Stock Opname" detail drawer, per the supplied reference
 * design. Fast-path visual mockup only (explicitly NOT a backend/data-
 * model task this round): opens a wide Drawer (reusing the existing
 * Drawer component — drawer.js — untouched) when a session row is clicked
 * in report-opname.js (the ACTIVE production Stock Opname report) or in
 * stock-opname-report.js (dev-branch-only monthly report).
 *
 * SELF-CONTAINED STYLING: every class this file needs beyond the
 * long-standing .card/.badge/.btn/.modal, .hpp-kpi and .drawer rules is a
 * .jejak-* rule shipped in the same app.css patch as .drawer-xl. It must
 * NOT depend on .so-report-* (V2.16.4 — absent from production's app.css).
 *
 * ALL DATA IN THIS FILE IS MOCK, clearly isolated in buildMockDetail()
 * below. Nothing here calls InvApi, posts anything, or touches
 * StockOpnameService/TransferService/FifoService/the DB. Cetak Laporan /
 * Export Excel are safe no-op toasts only; Posting Adjustment is
 * hard-disabled (see open()/buildPerBarangTab()) and can never fire a
 * click handler, let alone a real posting call. The drawer header always
 * carries a "PREVIEW UI — DATA SIMULASI" badge + explanatory note so this
 * can never be mistaken for a live report even once linked from a real
 * menu.
 *
 * Shaped loosely after stock-opname-report.js's own REAL detail response
 * (detail.session / detail.finance_summary / detail.items) so swapping
 * in a real endpoint later is a data-source change, not a UI rewrite.
 */
const StockOpnameJejak = (() => {
    function money(n) { return UI.formatMoney(n); }
    function num(n) { return UI.formatNumber(n, 0); }

    // PRODUCTION PREVIEW — derives the drawer header's "#N" from the real
    // session_number's trailing digit group (e.g. "SO-20260930-0012" -> 12)
    // instead of the raw DB id, when that's safely parseable. Falls back to
    // the raw id unchanged if session_number is missing/unparseable.
    function deriveSessionDisplayNumber(session) {
        const raw = session && session.session_number;
        if (typeof raw === 'string') {
            const match = raw.match(/(\d+)(?!.*\d)/);
            if (match) {
                const parsed = parseInt(match[1], 10);
                if (!Number.isNaN(parsed)) return String(parsed);
            }
        }
        return String((session && session.id) ?? '');
    }

    // ---- MOCK DATA — replace with a real API call in a future round. ----
    function buildMockDetail(session) {
        const items = [
            { sku: 'BRD-001', name: 'Roti Tawar Kupas', category: 'Roti', unit: 'Pack', qty_sistem: 100, count1: 98, petugas1: 'Dewi', count2: 97, petugas2: 'Arman', final: 97, selisih_qty: -3, dead_qty: 0, rusak_qty: 0, hpp: 8500, nilai_selisih: -25500, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'BRD-002', name: 'Roti Manis Coklat', category: 'Roti', unit: 'Pcs', qty_sistem: 250, count1: 252, petugas1: 'Dewi', count2: 251, petugas2: 'Arman', final: 251, selisih_qty: 1, dead_qty: 0, rusak_qty: 0, hpp: 3000, nilai_selisih: 3000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'CK-001', name: 'Cookies Choco Chips', category: 'Kue Kering', unit: 'Pack', qty_sistem: 75, count1: 75, petugas1: 'Dewi', count2: 75, petugas2: 'Arman', final: 75, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, hpp: 12000, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'CK-002', name: 'Cookies Kastengel', category: 'Kue Kering', unit: 'Pack', qty_sistem: 40, count1: 38, petugas1: 'Dewi', count2: 38, petugas2: 'Arman', final: 38, selisih_qty: -2, dead_qty: 0, rusak_qty: 0, hpp: 15000, nilai_selisih: -30000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'TR-001', name: 'Brownies Coklat', category: 'Kue Basah', unit: 'Pcs', qty_sistem: 60, count1: 58, petugas1: 'Dewi', count2: 59, petugas2: 'Arman', final: 59, selisih_qty: -1, dead_qty: 0, rusak_qty: 2, hpp: 10000, nilai_selisih: -10000, nilai_dead: 0, nilai_rusak: 20000 },
            { sku: 'TR-002', name: 'Lapis Legit', category: 'Kue Basah', unit: 'Pcs', qty_sistem: 30, count1: 30, petugas1: 'Dewi', count2: 28, petugas2: 'Arman', final: 28, selisih_qty: -2, dead_qty: 3, rusak_qty: 0, hpp: 25000, nilai_selisih: -50000, nilai_dead: 75000, nilai_rusak: 0 },
            { sku: 'MD-001', name: 'Tepung Terigu', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 500, count1: 505, petugas1: 'Dewi', count2: 505, petugas2: 'Arman', final: 505, selisih_qty: 5, dead_qty: 0, rusak_qty: 0, hpp: 12000, nilai_selisih: 60000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'MD-002', name: 'Gula Pasir', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 300, count1: 300, petugas1: 'Dewi', count2: 300, petugas2: 'Arman', final: 300, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, hpp: 13000, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'MD-003', name: 'Mentega', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 120, count1: 118, petugas1: 'Dewi', count2: 118, petugas2: 'Arman', final: 118, selisih_qty: -2, dead_qty: 0, rusak_qty: 0, hpp: 28000, nilai_selisih: -56000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'PKG-001', name: 'Box Kue 26x26', category: 'Kemasan', unit: 'Pcs', qty_sistem: 200, count1: 195, petugas1: 'Dewi', count2: 195, petugas2: 'Arman', final: 195, selisih_qty: -5, dead_qty: 0, rusak_qty: 0, hpp: 4000, nilai_selisih: -20000, nilai_dead: 0, nilai_rusak: 0 },
        ];
        return {
            session: {
                number: session.session_number || (session.id != null ? `OPN-${session.id}` : 'SO-20260930-0012'),
                date: session.session_date || '30 September 2026',
                warehouse: session.warehouse_name || (session.warehouse && session.warehouse.name) || 'Gudang Cibadak',
                warehouse_code: 'CIBADAK',
                status: session.status || 'POSTED',
                created_by: 'superadmin',
                finalized_by: 'Rina', finalized_at: '01 Oktober 2026 10:15',
                posted_by: 'Fajar', posted_at: '01 Oktober 2026 11:30',
                petugas1: 'Dewi', petugas1_date: '30 September 2026',
                petugas2: 'Arman', petugas2_date: '30 September 2026',
                supervisor: 'Andi SPV', supervisor_date: '01 Oktober 2026 09:45',
            },
            kpi: {
                nilai_stok_sistem: 125460000, nilai_final_count: 123985000, selisih_nominal: 1475000,
                dead_stock: 640000, rusak: 225000, adjustment_bersih: 610000,
            },
            bottom: { sku_cocok: 96, perlu_review: 18, dead_stock_sku: 9, rusak_sku: 5 },
            items,
        };
    }

    // ---- KPI DRILL-DOWN ---------------------------------------------------
    // Each KPI card opens a secondary modal (above the still-open drawer)
    // listing the rows behind that number. Two clearly separated parts:
    //   DRILLDOWNS            — static presentation spec (title, columns,
    //                           filters). Does not change when real data
    //                           arrives.
    //   getDrilldownData()    — THE ONLY place that produces rows. Today it
    //                           derives MOCK rows from detail.items; later
    //                           replace its body with an API call returning
    //                           the same shape:
    //                             { rows: [{ ...column keys, sku_count?, rest? }],
    //                               total_count: <SKU/row count behind the KPI> }
    // Column formats: text | qty | sqty (signed qty) | money | smoney
    // (signed money) | arah (Lebih/Kurang pill) | kontribusi (+/- pill).
    // `total: true` columns are summed into the TOTAL row; a column whose
    // rows include a null (the non-itemised "Lainnya" row) totals as "—"
    // rather than a misleading partial sum.
    const KPI_DEFS = [
        { key: 'nilai_stok_sistem', icon: '🗄️', label: 'Nilai Stok Sistem', color: 'var(--accent)' },
        { key: 'nilai_final_count', icon: '🛒', label: 'Nilai Final Count', color: 'var(--green)' },
        { key: 'selisih_nominal', icon: '⚖️', label: 'Selisih Nominal', color: 'var(--red)' },
        { key: 'dead_stock', icon: '📦', label: 'Dead Stock', color: 'var(--orange)' },
        { key: 'rusak', icon: '⚠️', label: 'Rusak', color: '#f97316' },
        { key: 'adjustment_bersih', icon: '🧮', label: 'Adjustment Bersih', color: 'var(--accent2)' },
    ];

    const COL_SKU = { key: 'sku', label: 'SKU', fmt: 'text' };
    const COL_NAME = { key: 'name', label: 'Nama Barang', fmt: 'text' };
    const COL_HPP = { key: 'hpp', label: 'HPP (Rp)', fmt: 'money' };

    const DRILLDOWNS = {
        nilai_stok_sistem: {
            title: 'Rincian Nilai Stok Sistem', countLabel: 'SKU', headline: 'nilai',
            columns: [COL_SKU, COL_NAME, { key: 'unit', label: 'Satuan', fmt: 'text' },
                { key: 'qty_sistem', label: 'Qty Sistem', fmt: 'qty', total: true }, COL_HPP,
                { key: 'nilai', label: 'Nilai Stok Sistem (Rp)', fmt: 'money', total: true }],
        },
        nilai_final_count: {
            title: 'Rincian Nilai Final Count', countLabel: 'SKU', headline: 'nilai',
            columns: [COL_SKU, COL_NAME, { key: 'unit', label: 'Satuan', fmt: 'text' },
                { key: 'final', label: 'Final Count', fmt: 'qty', total: true }, COL_HPP,
                { key: 'nilai', label: 'Nilai Final Count (Rp)', fmt: 'money', total: true }],
        },
        selisih_nominal: {
            title: 'Rincian Selisih Nominal', countLabel: 'SKU', headline: 'nilai_selisih',
            filter: { key: 'arah', label: 'Semua (Lebih/Kurang)', options: ['Lebih', 'Kurang'] },
            columns: [COL_SKU, COL_NAME,
                { key: 'qty_sistem', label: 'Qty Sistem', fmt: 'qty' }, { key: 'final', label: 'Final Count', fmt: 'qty' },
                { key: 'selisih_qty', label: 'Selisih Qty', fmt: 'sqty' }, COL_HPP,
                { key: 'nilai_selisih', label: 'Nilai Selisih (Rp)', fmt: 'smoney', total: true },
                { key: 'arah', label: 'Lebih/Kurang', fmt: 'arah' }],
        },
        dead_stock: {
            title: 'Rincian Dead Stock', countLabel: 'SKU', headline: 'nilai_dead',
            columns: [COL_SKU, COL_NAME, { key: 'dead_qty', label: 'Dead Stock Qty', fmt: 'qty', total: true }, COL_HPP,
                { key: 'nilai_dead', label: 'Nilai Dead Stock (Rp)', fmt: 'money', total: true },
                { key: 'note', label: 'Catatan', fmt: 'text' }],
        },
        rusak: {
            title: 'Rincian Rusak', countLabel: 'SKU', headline: 'nilai_rusak',
            columns: [COL_SKU, COL_NAME, { key: 'rusak_qty', label: 'Rusak Qty', fmt: 'qty', total: true }, COL_HPP,
                { key: 'nilai_rusak', label: 'Nilai Rusak (Rp)', fmt: 'money', total: true },
                { key: 'note', label: 'Catatan', fmt: 'text' }],
        },
        adjustment_bersih: {
            title: 'Rincian Adjustment Bersih', countLabel: 'baris adjustment', headline: 'nilai_adj',
            filter: { key: 'kontribusi', label: 'Semua Kontribusi', options: ['Menambah', 'Mengurangi'] },
            columns: [COL_SKU, COL_NAME, { key: 'jenis', label: 'Jenis Adjustment', fmt: 'text' },
                { key: 'qty_adj', label: 'Qty Adjustment', fmt: 'sqty' }, COL_HPP,
                { key: 'nilai_adj', label: 'Nilai Adjustment (Rp)', fmt: 'smoney', total: true },
                { key: 'kontribusi', label: 'Kontribusi', fmt: 'kontribusi' }],
        },
    };

    // MOCK — swap point (see block comment above). The drawer shows a sample
    // of SKUs while the KPI cards describe the whole session, so every
    // drill-down ends with ONE non-itemised "Lainnya" row that carries the
    // remainder; that keeps the modal's TOTAL equal to the KPI card.
    function getDrilldownData(key, detail) {
        const items = detail.items;
        const kpiValue = detail.kpi[key];
        let rows;
        let totalCount;
        if (key === 'nilai_stok_sistem') {
            rows = items.map((it) => ({ sku: it.sku, name: it.name, unit: it.unit, qty_sistem: it.qty_sistem, hpp: it.hpp, nilai: it.qty_sistem * it.hpp }));
            totalCount = detail.bottom.sku_cocok + detail.bottom.perlu_review;
        } else if (key === 'nilai_final_count') {
            rows = items.map((it) => ({ sku: it.sku, name: it.name, unit: it.unit, final: it.final, hpp: it.hpp, nilai: it.final * it.hpp }));
            totalCount = detail.bottom.sku_cocok + detail.bottom.perlu_review;
        } else if (key === 'selisih_nominal') {
            rows = items.filter((it) => it.selisih_qty !== 0).map((it) => ({
                sku: it.sku, name: it.name, qty_sistem: it.qty_sistem, final: it.final, selisih_qty: it.selisih_qty,
                hpp: it.hpp, nilai_selisih: it.nilai_selisih, arah: it.selisih_qty > 0 ? 'Lebih' : 'Kurang',
            }));
            totalCount = detail.bottom.perlu_review;
        } else if (key === 'dead_stock') {
            rows = items.filter((it) => it.dead_qty > 0).map((it) => ({
                sku: it.sku, name: it.name, dead_qty: it.dead_qty, hpp: it.hpp, nilai_dead: it.nilai_dead,
                note: 'Tidak ada pergerakan > 90 hari (simulasi)',
            }));
            totalCount = detail.bottom.dead_stock_sku;
        } else if (key === 'rusak') {
            rows = items.filter((it) => it.rusak_qty > 0).map((it) => ({
                sku: it.sku, name: it.name, rusak_qty: it.rusak_qty, hpp: it.hpp, nilai_rusak: it.nilai_rusak,
                note: 'Rusak saat penyimpanan (simulasi)',
            }));
            totalCount = detail.bottom.rusak_sku;
        } else {
            rows = [];
            items.forEach((it) => {
                if (it.selisih_qty !== 0) {
                    rows.push({ sku: it.sku, name: it.name, jenis: it.selisih_qty > 0 ? 'Selisih Lebih' : 'Selisih Kurang', qty_adj: it.selisih_qty, hpp: it.hpp, nilai_adj: it.nilai_selisih });
                }
                if (it.dead_qty > 0) {
                    rows.push({ sku: it.sku, name: it.name, jenis: 'Write-off Dead Stock', qty_adj: -it.dead_qty, hpp: it.hpp, nilai_adj: -it.nilai_dead });
                }
                if (it.rusak_qty > 0) {
                    rows.push({ sku: it.sku, name: it.name, jenis: 'Write-off Rusak', qty_adj: -it.rusak_qty, hpp: it.hpp, nilai_adj: -it.nilai_rusak });
                }
            });
            rows.forEach((r) => { r.kontribusi = r.nilai_adj >= 0 ? 'Menambah' : 'Mengurangi'; });
            totalCount = rows.length + (detail.bottom.perlu_review - items.filter((it) => it.selisih_qty !== 0).length);
        }

        const spec = DRILLDOWNS[key];
        const itemised = rows.reduce((sum, r) => sum + r[spec.headline], 0);
        const restCount = totalCount - rows.length;
        if (restCount > 0) {
            const rest = { rest: true, sku_count: restCount, sku: '—', name: `Lainnya — ${restCount} ${spec.countLabel} lain (tidak dirinci, simulasi)` };
            rest[spec.headline] = kpiValue - itemised;
            if (key === 'adjustment_bersih') { rest.jenis = 'Penyesuaian lainnya'; rest.kontribusi = rest[spec.headline] >= 0 ? 'Menambah' : 'Mengurangi'; }
            if (key === 'selisih_nominal') { rest.arah = rest[spec.headline] >= 0 ? 'Lebih' : 'Kurang'; }
            rows.push(rest);
        }
        return { rows, total_count: totalCount };
    }

    function rightAligned(col) { return ['qty', 'sqty', 'money', 'smoney'].includes(col.fmt); }

    function pill(text, color) {
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, text);
    }

    function drillCell(col, row) {
        const v = row[col.key];
        let node;
        if (v === undefined || v === null || v === '') node = document.createTextNode('—');
        else if (col.fmt === 'qty') node = document.createTextNode(num(v));
        else if (col.fmt === 'sqty') node = variancePill(v, false);
        else if (col.fmt === 'money') node = document.createTextNode(money(v));
        else if (col.fmt === 'smoney') node = variancePill(v, true);
        else if (col.fmt === 'arah') node = pill(v, v === 'Lebih' ? 'var(--green)' : 'var(--red)');
        else if (col.fmt === 'kontribusi') node = pill(v === 'Menambah' ? '▲ Menambah (+)' : '▼ Mengurangi (−)', v === 'Menambah' ? 'var(--green)' : 'var(--red)');
        else node = document.createTextNode(String(v));
        return UI.el('td', rightAligned(col) ? { class: 'text-right' } : {}, [node]);
    }

    function openDrilldown(key, detail, returnFocusTo) {
        const spec = DRILLDOWNS[key];
        const kpi = KPI_DEFS.find((k) => k.key === key);
        const data = getDrilldownData(key, detail);
        const headlineTotal = data.rows.reduce((sum, r) => sum + (r[spec.headline] || 0), 0);

        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU atau nama barang...', style: 'flex:1 1 260px; width:auto;', 'data-testid': 'jejak-drill-search' });
        const filterSelect = spec.filter
            ? UI.el('select', { style: 'flex:0 0 220px; width:220px;', 'data-testid': 'jejak-drill-filter' },
                [UI.el('option', { value: '' }, spec.filter.label)].concat(spec.filter.options.map((o) => UI.el('option', { value: o }, o))))
            : null;
        const tableHost = UI.el('div');
        const closeBtn = UI.el('button', { class: 'drawer-close', 'aria-label': 'Tutup rincian', 'data-testid': 'jejak-drill-close' }, '✕');
        const backBtn = UI.el('button', { class: 'btn btn-secondary', 'data-testid': 'jejak-drill-back' }, '← Kembali ke Jejak');

        const content = UI.el('div', { class: 'modal-content jejak-drill', role: 'dialog', 'aria-modal': 'true', 'aria-label': spec.title, 'data-testid': 'jejak-drill-modal' }, [
            UI.el('div', { class: 'jejak-drill-head' }, [
                UI.el('div', {}, [
                    UI.el('h3', { 'data-testid': 'jejak-drill-title', style: 'margin-bottom:4px;' }, `${kpi.icon} ${spec.title}`),
                    UI.el('div', { class: 'hpp-subtitle', 'data-testid': 'jejak-drill-summary' }, [
                        UI.el('span', {}, `${num(data.total_count)} ${spec.countLabel}`),
                        UI.el('span', {}, ' · Total '),
                        UI.el('strong', { style: `color:${kpi.color};` }, money(headlineTotal)),
                        UI.el('span', {}, ' · data simulasi'),
                    ]),
                ]),
                closeBtn,
            ]),
            UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin:12px 0;' }, [searchInput].concat(filterSelect ? [filterSelect] : [])),
            tableHost,
            UI.el('div', { style: 'display:flex; justify-content:flex-end; margin-top:14px;' }, [backBtn]),
        ]);
        const overlay = UI.el('div', { class: 'modal open jejak-drill-overlay' }, [content]);

        function renderTable() {
            tableHost.innerHTML = '';
            const q = searchInput.value.trim().toLowerCase();
            const f = filterSelect ? filterSelect.value : '';
            const filtered = !!(q || f);
            const rows = data.rows.filter((r) => {
                if (filtered && r.rest) return false;
                if (f && r[spec.filter.key] !== f) return false;
                if (q && !(String(r.sku).toLowerCase().includes(q) || String(r.name).toLowerCase().includes(q))) return false;
                return true;
            });
            const thead = UI.el('thead', {}, UI.el('tr', {}, spec.columns.map((c) => UI.el('th', rightAligned(c) ? { class: 'text-right' } : {}, c.label))));
            const tbody = UI.el('tbody', {}, rows.map((r) => UI.el('tr', r.rest ? { class: 'jejak-rest-row' } : {}, spec.columns.map((c) => drillCell(c, r)))));
            if (!rows.length) {
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: String(spec.columns.length), style: 'text-align:center; color:var(--text3);' }, 'Tidak ada baris yang cocok.')]));
            }
            const totalRow = UI.el('tr', { class: 'jejak-total-row', 'data-testid': 'jejak-drill-total-row' }, spec.columns.map((c, i) => {
                if (i === 0) return UI.el('td', {}, filtered ? 'TOTAL (terfilter)' : 'TOTAL');
                if (!c.total) return UI.el('td', {}, '');
                if (rows.some((r) => r[c.key] === undefined || r[c.key] === null)) return UI.el('td', { class: 'text-right' }, '—');
                const sum = rows.reduce((acc, r) => acc + r[c.key], 0);
                return UI.el('td', { class: 'text-right' }, [c.fmt === 'smoney' ? variancePill(sum, true) : document.createTextNode(c.fmt === 'money' ? money(sum) : num(sum))]);
            }));
            tbody.appendChild(totalRow);
            tableHost.appendChild(UI.el('div', { class: 'jejak-table-wrap', 'data-testid': 'jejak-drill-table-wrap' }, [
                UI.el('table', { class: 'jejak-table', 'data-testid': 'jejak-drill-table' }, [thead, tbody]),
            ]));
        }

        // Escape closes ONLY this modal: a capture-phase listener on document
        // runs before Drawer's own (bubble-phase) Escape handler and stops it,
        // so the Jejak drawer underneath stays open.
        function onKeydown(e) {
            if (e.key !== 'Escape') return;
            e.stopImmediatePropagation();
            e.preventDefault();
            closeDrilldown();
        }
        function closeDrilldown() {
            document.removeEventListener('keydown', onKeydown, true);
            overlay.remove();
            if (returnFocusTo && returnFocusTo.focus) returnFocusTo.focus();
        }
        document.addEventListener('keydown', onKeydown, true);
        closeBtn.addEventListener('click', closeDrilldown);
        backBtn.addEventListener('click', closeDrilldown);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) closeDrilldown(); });
        searchInput.addEventListener('input', renderTable);
        if (filterSelect) filterSelect.addEventListener('change', renderTable);

        renderTable();
        document.body.appendChild(overlay);
        backBtn.focus();
    }

    // ---- small UI pieces (self-contained .jejak-* styles + existing .hpp-kpi-*) ----
    function kpiTile(def, value, detail) {
        const card = UI.el('div', {
            class: 'card hpp-kpi-card jejak-kpi', role: 'button', tabindex: '0',
            title: 'Klik untuk lihat rincian', 'data-testid': `jejak-kpi-${def.key}`,
            style: `border-left:3px solid ${def.color};`,
        }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, def.icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, def.label),
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${def.color};` }, money(value)),
            ]),
        ]);
        const activate = () => openDrilldown(def.key, detail, card);
        card.addEventListener('click', activate);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
        });
        return card;
    }

    function miniStat(icon, label, value, color) {
        return UI.el('div', { class: 'card hpp-kpi-card', style: `border-left:3px solid ${color};` }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${color};` }, String(value)),
                UI.el('div', { class: 'hpp-kpi-label' }, label),
            ]),
        ]);
    }

    function variancePill(value, isMoney) {
        if (!value) return UI.el('span', { style: 'color:var(--text3);' }, isMoney ? money(0) : '0');
        const color = value > 0 ? 'var(--green)' : 'var(--red)';
        const text = isMoney ? money(value) : (value > 0 ? `+${num(value)}` : num(value));
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, text);
    }

    function qtyCell(value, color) {
        if (!value) return UI.el('span', { style: 'color:var(--text3);' }, '0');
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, num(value));
    }

    // Compact session info: label-over-value cells in an auto-fill grid
    // (3-4 columns in the wide drawer) instead of the 2-column .drawer-kv.
    function buildInfoCard(s) {
        const rows = [
            ['Gudang', `${s.warehouse_code} — ${s.warehouse}`],
            ['Status', UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status)],
            ['Tanggal Sesi', s.date],
            ['Dibuat Oleh', s.created_by],
            ['Difinalisasi Oleh', `${s.finalized_by} · ${s.finalized_at}`],
            ['Diposting Oleh', `${s.posted_by} · ${s.posted_at}`],
            ['Petugas Count 01', `${s.petugas1} · ${s.petugas1_date}`],
            ['Petugas Count 02', `${s.petugas2} · ${s.petugas2_date}`],
            ['Supervisor Verifikasi', `${s.supervisor} · ${s.supervisor_date}`],
        ];
        const grid = UI.el('div', { class: 'jejak-info-grid' }, rows.map(([k, v]) => UI.el('div', { class: 'jejak-info-cell' }, [
            UI.el('div', { class: 'k' }, k),
            UI.el('div', { class: 'v' }, [typeof v === 'string' ? document.createTextNode(v) : v]),
        ])));
        return Drawer.section('Informasi Sesi Stock Opname', grid);
    }

    function buildKpiRow(detail) {
        return UI.el('div', { class: 'hpp-kpi-row jejak-kpi-row' },
            KPI_DEFS.map((def) => kpiTile(def, detail.kpi[def.key], detail)));
    }

    function buildBottomSummary(bottom) {
        return UI.el('div', { class: 'hpp-kpi-row jejak-kpi-row', style: 'margin-top:16px;' }, [
            miniStat('✅', 'Total SKU cocok', bottom.sku_cocok, 'var(--green)'),
            miniStat('⚠️', 'Perlu review', bottom.perlu_review, 'var(--red)'),
            miniStat('📦', 'Dead stock SKU', `${bottom.dead_stock_sku} SKU`, 'var(--orange)'),
            miniStat('🛠', 'Rusak SKU', `${bottom.rusak_sku} SKU`, '#f97316'),
        ]);
    }

    function buildPerBarangTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(detail.session));
        wrap.appendChild(buildKpiRow(detail));

        const toolbar = UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU atau nama barang...', style: 'flex:1 1 260px; width:auto;', 'data-testid': 'jejak-items-search' });
        const catOptions = Array.from(new Set(detail.items.map((it) => it.category)));
        const catSelect = UI.el('select', { style: 'flex:0 0 220px; width:220px;', 'data-testid': 'jejak-items-category' }, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(
            catOptions.map((c) => UI.el('option', { value: c }, c))
        ));
        toolbar.appendChild(searchInput);
        toolbar.appendChild(catSelect);
        wrap.appendChild(toolbar);

        const tableHost = UI.el('div', { class: 'card' });
        wrap.appendChild(tableHost);
        wrap.appendChild(buildBottomSummary(detail.bottom));

        // Sticky footer inside the scrolling drawer body, so the actions stay
        // reachable however long the tab content gets.
        const actionsRow = UI.el('div', { class: 'jejak-actions', 'data-testid': 'jejak-actions' });
        const printBtn = UI.el('button', { class: 'btn btn-secondary' }, '🖨 Cetak Laporan');
        const excelBtn = UI.el('button', { class: 'btn btn-secondary' }, '📊 Export Excel');
        // PRODUCTION PREVIEW — Posting Adjustment is hard-disabled (the
        // `disabled` attribute itself blocks the browser from ever firing a
        // click on it, so there is no code path here that can perform a
        // real posting) and visually marked as preview-only. Never wire
        // this to StockOpnameService or any posting endpoint until a real
        // backend/data-model round explicitly authorizes it.
        const postBtn = UI.el('button', {
            class: 'btn btn-primary',
            disabled: 'disabled',
            title: 'Preview — Posting Adjustment belum tersedia di tahap ini (data simulasi).',
        }, '✅ Posting Adjustment (Preview)');
        printBtn.addEventListener('click', () => UI.toast('Mockup — Cetak Laporan belum terhubung ke data nyata.', 'info'));
        excelBtn.addEventListener('click', () => UI.toast('Mockup — Export Excel belum terhubung ke data nyata.', 'info'));
        actionsRow.appendChild(printBtn);
        actionsRow.appendChild(excelBtn);
        actionsRow.appendChild(postBtn);
        wrap.appendChild(actionsRow);

        function renderTable() {
            tableHost.innerHTML = '';
            const q = searchInput.value.trim().toLowerCase();
            const cat = catSelect.value;
            const rows = detail.items.filter((it) => {
                if (cat && it.category !== cat) return false;
                if (q && !(it.sku.toLowerCase().includes(q) || it.name.toLowerCase().includes(q))) return false;
                return true;
            });

            const table = UI.el('table', { class: 'jejak-table', 'data-testid': 'jejak-items-table' });
            const thead = UI.el('thead', {}, UI.el('tr', {}, [
                'SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty Sistem', 'Hasil Count 01', 'Petugas 01',
                'Hasil Count 02', 'Petugas 02', 'Final Count', 'Selisih Qty', 'Dead Stock Qty', 'Rusak Qty',
                'HPP (Rp)', 'Nilai Selisih (Rp)', 'Nilai Dead Stock (Rp)', 'Nilai Rusak (Rp)',
            ].map((h) => UI.el('th', {}, h))));
            const tbody = UI.el('tbody', {}, rows.map((it) => UI.el('tr', {}, [
                UI.el('td', {}, it.sku),
                UI.el('td', {}, it.name),
                UI.el('td', {}, it.category),
                UI.el('td', {}, it.unit),
                UI.el('td', { class: 'text-right' }, num(it.qty_sistem)),
                UI.el('td', { class: 'text-right' }, num(it.count1)),
                UI.el('td', {}, it.petugas1),
                UI.el('td', { class: 'text-right' }, num(it.count2)),
                UI.el('td', {}, it.petugas2),
                UI.el('td', { class: 'text-right' }, num(it.final)),
                UI.el('td', { class: 'text-right' }, variancePill(it.selisih_qty, false)),
                UI.el('td', { class: 'text-right' }, qtyCell(it.dead_qty, 'var(--orange)')),
                UI.el('td', { class: 'text-right' }, qtyCell(it.rusak_qty, '#f97316')),
                UI.el('td', { class: 'text-right' }, money(it.hpp)),
                UI.el('td', { class: 'text-right' }, variancePill(it.nilai_selisih, true)),
                UI.el('td', { class: 'text-right' }, it.nilai_dead ? UI.el('span', { style: 'color:var(--orange); font-weight:700;' }, money(it.nilai_dead)) : money(0)),
                UI.el('td', { class: 'text-right' }, it.nilai_rusak ? UI.el('span', { style: 'color:#f97316; font-weight:700;' }, money(it.nilai_rusak)) : money(0)),
            ])));

            const totals = rows.reduce((acc, it) => {
                acc.qty_sistem += it.qty_sistem; acc.count1 += it.count1; acc.count2 += it.count2; acc.final += it.final;
                acc.selisih_qty += it.selisih_qty; acc.dead_qty += it.dead_qty; acc.rusak_qty += it.rusak_qty;
                acc.nilai_selisih += it.nilai_selisih; acc.nilai_dead += it.nilai_dead; acc.nilai_rusak += it.nilai_rusak;
                return acc;
            }, { qty_sistem: 0, count1: 0, count2: 0, final: 0, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 });
            const totalRow = UI.el('tr', { class: 'jejak-total-row' }, [
                UI.el('td', { colspan: '4' }, 'TOTAL'),
                UI.el('td', { class: 'text-right' }, num(totals.qty_sistem)),
                UI.el('td', { class: 'text-right' }, num(totals.count1)),
                UI.el('td', {}, ''),
                UI.el('td', { class: 'text-right' }, num(totals.count2)),
                UI.el('td', {}, ''),
                UI.el('td', { class: 'text-right' }, num(totals.final)),
                UI.el('td', { class: 'text-right' }, variancePill(totals.selisih_qty, false)),
                UI.el('td', { class: 'text-right' }, num(totals.dead_qty)),
                UI.el('td', { class: 'text-right' }, num(totals.rusak_qty)),
                UI.el('td', {}, '—'),
                UI.el('td', { class: 'text-right' }, variancePill(totals.nilai_selisih, true)),
                UI.el('td', { class: 'text-right' }, money(totals.nilai_dead)),
                UI.el('td', { class: 'text-right' }, money(totals.nilai_rusak)),
            ]);
            tbody.appendChild(totalRow);

            table.appendChild(thead);
            table.appendChild(tbody);
            tableHost.appendChild(UI.el('div', { class: 'jejak-table-wrap', 'data-testid': 'jejak-items-table-wrap' }, [table]));
        }

        searchInput.addEventListener('input', renderTable);
        catSelect.addEventListener('change', renderTable);
        renderTable();

        return wrap;
    }

    function buildOverviewTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(detail.session));
        wrap.appendChild(buildKpiRow(detail));
        wrap.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:12px;' },
            'Ringkasan Overview — mockup visual. Klik kartu KPI untuk rincian; data lengkap ada di tab Per Barang.'));
        return wrap;
    }

    function buildRekonsiliasiTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(UI.el('div', { class: 'alert alert-info' },
            'Mockup — tab Rekonsiliasi akan menampilkan pencocokan Stok Buku SO vs hasil opname di tahap berikutnya.'));
        wrap.appendChild(buildKpiRow(detail));
        return wrap;
    }

    function buildAuditTab(detail) {
        const s = detail.session;
        const rows = [
            ['Dibuat', `${s.created_by}`],
            ['Difinalisasi', `${s.finalized_by} · ${s.finalized_at}`],
            ['Diposting', `${s.posted_by} · ${s.posted_at}`],
            ['Supervisor Verifikasi', `${s.supervisor} · ${s.supervisor_date}`],
        ];
        const wrap = UI.el('div');
        wrap.appendChild(Drawer.section('Jejak Audit (mockup)', Drawer.kv(rows)));
        wrap.appendChild(UI.el('div', { class: 'alert alert-info' },
            'Mockup — timeline audit lengkap (per-field before/after, mengikuti pola TraceDrawer) menyusul di tahap berikutnya.'));
        return wrap;
    }

    function open(session) {
        const detail = buildMockDetail(session);
        const s = detail.session;

        const titleNode = UI.el('div', {}, [
            UI.el('div', { style: 'font-size:1.1rem; font-weight:800; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                `Jejak Stock Opname #${deriveSessionDisplayNumber(session)}`,
                // PRODUCTION PREVIEW — reuses the existing .badge/.badge-warning
                // pair (app.css:84/95) as-is; no new badge CSS introduced.
                UI.el('span', { class: 'badge badge-warning', 'data-testid': 'jejak-preview-badge' }, 'PREVIEW UI — DATA SIMULASI'),
            ]),
            UI.el('div', { style: 'font-size:0.78rem; color:var(--text3); font-weight:500; margin-top:2px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                UI.el('span', {}, `${s.number} · ${s.date} · ${s.warehouse_code}`),
                UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status),
            ]),
            // PRODUCTION PREVIEW — reuses the existing .hpp-subtitle muted-text
            // style (app.css:776) as-is; no new text-style CSS introduced.
            UI.el('div', { class: 'hpp-subtitle', style: 'margin-top:4px;', 'data-testid': 'jejak-preview-note' },
                'Tampilan ini masih menggunakan data simulasi dan belum terhubung ke data Stock Opname aktual.'),
        ]);

        Drawer.open({
            title: titleNode,
            tabs: [
                { key: 'overview', label: 'Overview', render: (body) => body.appendChild(buildOverviewTab(detail)) },
                { key: 'per-barang', label: 'Per Barang', render: (body) => body.appendChild(buildPerBarangTab(detail)) },
                { key: 'rekonsiliasi', label: 'Rekonsiliasi', render: (body) => body.appendChild(buildRekonsiliasiTab(detail)) },
                { key: 'audit', label: 'Audit', render: (body) => body.appendChild(buildAuditTab(detail)) },
            ],
        });

        // Widen this specific drawer instance beyond the shared 560px
        // default — a modifier class, never a change to .drawer itself
        // (every other screen's drawer stays untouched).
        const panel = document.querySelector('.drawer');
        if (panel) {
            panel.classList.add('drawer-xl');
            // The Drawer panel is a shared singleton, so the modifier must not
            // outlive this view: drop it as soon as the drawer closes or its
            // content is replaced by another screen's drawer (e.g. the real
            // "Lihat Detail" trace), which then gets its normal 560px width.
            const observer = new MutationObserver(() => {
                if (!panel.classList.contains('open') || !panel.contains(titleNode)) {
                    panel.classList.remove('drawer-xl');
                    observer.disconnect();
                }
            });
            observer.observe(panel, { attributes: true, attributeFilter: ['class'], childList: true });
        }

        // Tab order is Overview | Per Barang | ..., but Per Barang is the
        // default. Drawer.open() always activates tabs[0] and shared
        // drawer.js is deliberately not modified, so select it the same way
        // a user would — by clicking its tab button.
        if (panel) {
            const perBarangTab = Array.from(panel.querySelectorAll('.drawer-tab')).find((b) => b.textContent === 'Per Barang');
            if (perBarangTab) perBarangTab.click();
        }
    }

    return { open };
})();
