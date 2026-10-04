/**
 * "Jejak Stock Opname" — per-session trace drawer, REAL DATA.
 *
 * Opened by a row click in report-opname.js (the active production Stock
 * Opname report; stock-opname-report.js uses it too on the dev branch).
 * Everything shown comes from ONE read-only call,
 *   GET /api/reports/opname/{id}/jejak   (StockOpnameJejakService)
 * — session identity, every line (system / Count 01 / Count 02 / final /
 * variance / dead stock / rusak / HPP and their Rupiah values) and the
 * posted adjustments. There is NO mock or fallback data: while loading a
 * spinner text is shown, and on any failure the real error is shown with a
 * retry button.
 *
 * READ-ONLY: this module only ever issues that one GET. It cannot post,
 * finalize or adjust anything; "Posting Adjustment" is a disabled button
 * with no click handler, and "Export Excel" is disabled until an export is
 * built on this data.
 *
 * KPI cards are computed here from the SAME rows the drill-down tables list
 * (computeKpis / drilldownRows below), so a card and its breakdown can
 * never disagree; the server returns its own kpi block purely so tests can
 * cross-check the two implementations.
 *
 * Self-contained styling: .jejak-* rules ship in the same app.css patch as
 * .drawer-xl (no dependency on .so-report-*, absent from production).
 */
const StockOpnameJejak = (() => {
    const PAGE_SIZE = 50;

    // ------------------------------------------------------------ formatting
    const isNil = (v) => v === null || v === undefined;
    const money = (v) => (isNil(v) ? '—' : UI.formatMoney(v));
    const num = (v) => (isNil(v) ? '—' : UI.formatNumber(v, 4));
    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

    // ------------------------------------------------------------ data access
    async function fetchDetail(sessionId) {
        let res;
        try {
            res = await fetch(`/api/reports/opname/${encodeURIComponent(sessionId)}/jejak`, {
                method: 'GET', credentials: 'include', headers: { Accept: 'application/json' },
            });
        } catch (e) {
            throw new Error('Sistem sedang tidak dapat terhubung ke server.');
        }
        let payload;
        try {
            payload = await res.json();
        } catch (e) {
            throw new Error(`Respons server tidak valid (HTTP ${res.status}).`);
        }
        if (!payload || !payload.success) {
            throw new Error((payload && payload.error && payload.error.message) || `Gagal memuat Jejak (HTTP ${res.status}).`);
        }
        return payload.data;
    }

    // ------------------------------------------------------- KPI / drill-down
    // DRILLDOWNS is the static presentation spec; drilldownRows() turns the
    // real payload into rows; computeKpis() reduces the very same rows.
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
            title: 'Rincian Nilai Stok Sistem', countLabel: 'SKU', value: 'system_value',
            columns: [COL_SKU, COL_NAME, { key: 'unit', label: 'Satuan', fmt: 'text' },
                { key: 'system_qty', label: 'Qty Sistem', fmt: 'qty' }, COL_HPP,
                { key: 'system_value', label: 'Nilai Stok Sistem (Rp)', fmt: 'money', total: true }],
        },
        nilai_final_count: {
            title: 'Rincian Nilai Final Count', countLabel: 'SKU', value: 'final_value',
            columns: [COL_SKU, COL_NAME, { key: 'unit', label: 'Satuan', fmt: 'text' },
                { key: 'final_qty', label: 'Final Count', fmt: 'qty' }, COL_HPP,
                { key: 'final_value', label: 'Nilai Final Count (Rp)', fmt: 'money', total: true }],
        },
        selisih_nominal: {
            title: 'Rincian Selisih Nominal', countLabel: 'SKU', value: 'variance_value',
            filter: { key: 'arah', label: 'Semua (Lebih/Kurang)', options: ['Lebih', 'Kurang'] },
            columns: [COL_SKU, COL_NAME,
                { key: 'system_qty', label: 'Qty Sistem', fmt: 'qty' }, { key: 'final_qty', label: 'Final Count', fmt: 'qty' },
                { key: 'variance_qty', label: 'Selisih Qty', fmt: 'sqty' }, COL_HPP,
                { key: 'variance_value', label: 'Nilai Selisih (Rp)', fmt: 'smoney', total: true },
                { key: 'arah', label: 'Lebih/Kurang', fmt: 'arah' }],
        },
        dead_stock: {
            title: 'Rincian Dead Stock', countLabel: 'SKU', value: 'dead_value',
            columns: [COL_SKU, COL_NAME, { key: 'dead_qty', label: 'Dead Stock Qty', fmt: 'qty' }, COL_HPP,
                { key: 'dead_value', label: 'Nilai Dead Stock (Rp)', fmt: 'money', total: true },
                { key: 'note', label: 'Catatan', fmt: 'text' }],
        },
        rusak: {
            title: 'Rincian Rusak', countLabel: 'SKU', value: 'rusak_value',
            columns: [COL_SKU, COL_NAME, { key: 'rusak_qty', label: 'Rusak Qty', fmt: 'qty' }, COL_HPP,
                { key: 'rusak_value', label: 'Nilai Rusak (Rp)', fmt: 'money', total: true },
                { key: 'note', label: 'Catatan', fmt: 'text' }],
        },
        adjustment_bersih: {
            title: 'Rincian Adjustment Bersih', countLabel: 'baris adjustment', value: 'value',
            filter: { key: 'kontribusi', label: 'Semua Kontribusi', options: ['Menambah', 'Mengurangi'] },
            columns: [COL_SKU, COL_NAME, { key: 'jenis', label: 'Jenis Adjustment', fmt: 'text' },
                { key: 'qty', label: 'Qty Adjustment', fmt: 'sqty' }, COL_HPP,
                { key: 'value', label: 'Nilai Adjustment (Rp)', fmt: 'smoney', total: true },
                { key: 'kontribusi', label: 'Kontribusi', fmt: 'kontribusi' }],
        },
    };

    const hasVariance = (it) => !isNil(it.variance_qty) && Math.abs(it.variance_qty) >= 0.0000001;

    /** Real rows behind one KPI. `unvalued` = SKUs listed but not in the total (qty unknown). */
    function drilldownRows(key, data) {
        const items = data.items;
        if (key === 'nilai_stok_sistem') {
            return { rows: items, valued: items.filter((i) => !isNil(i.system_value)).length };
        }
        if (key === 'nilai_final_count') {
            return { rows: items, valued: items.filter((i) => !isNil(i.final_value)).length };
        }
        if (key === 'selisih_nominal') {
            const rows = items.filter(hasVariance).map((i) => Object.assign({}, i, { arah: i.variance_qty > 0 ? 'Lebih' : 'Kurang' }));
            return { rows, valued: rows.length };
        }
        if (key === 'dead_stock') {
            const rows = items.filter((i) => !isNil(i.dead_qty) && i.dead_qty > 0);
            return { rows, valued: rows.length };
        }
        if (key === 'rusak') {
            const rows = items.filter((i) => !isNil(i.rusak_qty) && i.rusak_qty > 0);
            return { rows, valued: rows.length };
        }
        const rows = data.adjustments.map((a) => Object.assign({}, a, { kontribusi: a.value >= 0 ? 'Menambah' : 'Mengurangi' }));
        return { rows, valued: rows.length };
    }

    function sumOf(rows, field) {
        return r2(rows.reduce((acc, r) => acc + (isNil(r[field]) ? 0 : r[field]), 0));
    }

    function computeKpis(data) {
        const out = {};
        KPI_DEFS.forEach((def) => {
            const spec = DRILLDOWNS[def.key];
            const { rows } = drilldownRows(def.key, data);
            const counted = rows.filter((r) => !isNil(r[spec.value]));
            out[def.key] = { value: sumOf(counted, spec.value), count: counted.length };
        });
        return out;
    }

    // ------------------------------------------------------------- UI pieces
    function pill(text, color) {
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, text);
    }

    function signed(value, isMoney) {
        if (isNil(value)) return UI.el('span', { style: 'color:var(--text3);' }, '—');
        if (!value) return UI.el('span', { style: 'color:var(--text3);' }, isMoney ? money(0) : '0');
        const color = value > 0 ? 'var(--green)' : 'var(--red)';
        return pill(isMoney ? money(value) : (value > 0 ? `+${num(value)}` : num(value)), color);
    }

    const RIGHT = ['qty', 'sqty', 'money', 'smoney'];
    const isRight = (col) => RIGHT.includes(col.fmt);

    function cellNode(col, row) {
        const v = row[col.key];
        if (isNil(v) || v === '') return document.createTextNode('—');
        if (col.fmt === 'qty') {
            return document.createTextNode(num(v) + (row.unit ? ` ${row.unit}` : ''));
        }
        if (col.fmt === 'sqty') return signed(v, false);
        if (col.fmt === 'money') return document.createTextNode(money(v));
        if (col.fmt === 'smoney') return signed(v, true);
        if (col.fmt === 'arah') return pill(v, v === 'Lebih' ? 'var(--green)' : 'var(--red)');
        if (col.fmt === 'kontribusi') return pill(v === 'Menambah' ? '▲ Menambah (+)' : '▼ Mengurangi (−)', v === 'Menambah' ? 'var(--green)' : 'var(--red)');
        return document.createTextNode(String(v));
    }

    /** Prev/next pager shared by the Per Barang table and the drill-down tables. */
    function pager(total, state, onChange, testid) {
        const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        state.page = Math.min(Math.max(1, state.page), pages);
        const from = total === 0 ? 0 : (state.page - 1) * PAGE_SIZE + 1;
        const to = Math.min(total, state.page * PAGE_SIZE);
        const prev = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': `${testid}-prev` }, '‹ Sebelumnya');
        const next = UI.el('button', { class: 'btn btn-secondary btn-sm', type: 'button', 'data-testid': `${testid}-next` }, 'Berikutnya ›');
        if (state.page <= 1) prev.setAttribute('disabled', 'disabled');
        if (state.page >= pages) next.setAttribute('disabled', 'disabled');
        prev.addEventListener('click', () => { state.page -= 1; onChange(); });
        next.addEventListener('click', () => { state.page += 1; onChange(); });
        return UI.el('div', { class: 'jejak-pager', 'data-testid': `${testid}-pager` }, [
            UI.el('span', {}, total === 0 ? 'Tidak ada baris' : `Baris ${num(from)}–${num(to)} dari ${num(total)} · halaman ${state.page}/${pages}`),
            UI.el('span', { style: 'display:flex; gap:8px;' }, [prev, next]),
        ]);
    }

    function openDrilldown(key, data, returnFocusTo) {
        const spec = DRILLDOWNS[key];
        const kpiDef = KPI_DEFS.find((k) => k.key === key);
        const { rows: allRows, valued } = drilldownRows(key, data);
        const headline = sumOf(allRows.filter((r) => !isNil(r[spec.value])), spec.value);
        const unvalued = allRows.length - valued;
        const dq = data.data_quality || {};

        const summaryParts = [
            UI.el('span', { 'data-testid': 'jejak-drill-count' }, `${num(valued)} ${spec.countLabel}`),
            UI.el('span', {}, ' · Total '),
            UI.el('strong', { style: `color:${kpiDef.color};`, 'data-testid': 'jejak-drill-total' }, money(headline)),
        ];
        if (unvalued > 0) summaryParts.push(UI.el('span', {}, ` · ${num(unvalued)} SKU belum ada qty (tidak dihitung)`));
        if (key === 'selisih_nominal' && dq.variance_missing > 0) summaryParts.push(UI.el('span', {}, ` · ${num(dq.variance_missing)} SKU belum dapat dihitung selisihnya`));

        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU atau nama barang...', style: 'flex:1 1 260px; width:auto;', 'data-testid': 'jejak-drill-search' });
        const filterSelect = spec.filter
            ? UI.el('select', { style: 'flex:0 0 220px; width:220px;', 'data-testid': 'jejak-drill-filter' },
                [UI.el('option', { value: '' }, spec.filter.label)].concat(spec.filter.options.map((o) => UI.el('option', { value: o }, o))))
            : null;
        const tableHost = UI.el('div');
        const closeBtn = UI.el('button', { class: 'drawer-close', type: 'button', 'aria-label': 'Tutup rincian', 'data-testid': 'jejak-drill-close' }, '✕');
        const backBtn = UI.el('button', { class: 'btn btn-secondary', type: 'button', 'data-testid': 'jejak-drill-back' }, '← Kembali ke Jejak');

        const content = UI.el('div', { class: 'modal-content jejak-drill', role: 'dialog', 'aria-modal': 'true', 'aria-label': spec.title, 'data-testid': 'jejak-drill-modal' }, [
            UI.el('div', { class: 'jejak-drill-head' }, [
                UI.el('div', {}, [
                    UI.el('h3', { 'data-testid': 'jejak-drill-title', style: 'margin-bottom:4px;' }, `${kpiDef.icon} ${spec.title}`),
                    UI.el('div', { class: 'hpp-subtitle', 'data-testid': 'jejak-drill-summary' }, summaryParts),
                ]),
                closeBtn,
            ]),
            UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin:12px 0;' }, [searchInput].concat(filterSelect ? [filterSelect] : [])),
            tableHost,
            UI.el('div', { style: 'display:flex; justify-content:flex-end; margin-top:14px;' }, [backBtn]),
        ]);
        const overlay = UI.el('div', { class: 'modal open jejak-drill-overlay' }, [content]);
        const state = { page: 1 };

        function renderTable() {
            tableHost.innerHTML = '';
            const q = searchInput.value.trim().toLowerCase();
            const f = filterSelect ? filterSelect.value : '';
            const filtered = !!(q || f);
            const rows = allRows.filter((r) => {
                if (f && r[spec.filter.key] !== f) return false;
                if (q && !(String(r.sku).toLowerCase().includes(q) || String(r.name).toLowerCase().includes(q))) return false;
                return true;
            });
            const pageRows = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

            const thead = UI.el('thead', {}, UI.el('tr', {}, spec.columns.map((c) => UI.el('th', isRight(c) ? { class: 'text-right' } : {}, c.label))));
            const tbody = UI.el('tbody', {}, pageRows.map((r) => UI.el('tr', {}, spec.columns.map((c) => UI.el('td', isRight(c) ? { class: 'text-right' } : {}, [cellNode(c, r)])))));
            if (!rows.length) {
                const empty = key === 'adjustment_bersih' && !filtered
                    ? ((dq.notes && dq.notes.length) ? dq.notes.join(' ') : 'Tidak ada adjustment ter-posting untuk sesi ini.')
                    : 'Tidak ada baris yang cocok.';
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: String(spec.columns.length), style: 'text-align:center; color:var(--text3); white-space:normal;' }, empty)]));
            }
            // TOTAL covers ALL filtered rows (every page), nominal columns only
            // — summing quantities across different units is meaningless.
            const totalRow = UI.el('tr', { class: 'jejak-total-row', 'data-testid': 'jejak-drill-total-row' }, spec.columns.map((c, i) => {
                if (i === 0) return UI.el('td', {}, filtered ? 'TOTAL (terfilter)' : 'TOTAL');
                if (!c.total) return UI.el('td', {}, '');
                const sum = sumOf(rows.filter((r) => !isNil(r[c.key])), c.key);
                return UI.el('td', { class: 'text-right' }, [c.fmt === 'smoney' ? signed(sum, true) : document.createTextNode(money(sum))]);
            }));
            tbody.appendChild(totalRow);
            tableHost.appendChild(UI.el('div', { class: 'jejak-table-wrap', 'data-testid': 'jejak-drill-table-wrap' }, [
                UI.el('table', { class: 'jejak-table', 'data-testid': 'jejak-drill-table' }, [thead, tbody]),
            ]));
            if (rows.length > PAGE_SIZE) tableHost.appendChild(pager(rows.length, state, renderTable, 'jejak-drill'));
        }

        // Escape closes ONLY this modal: a capture-phase document listener runs
        // before Drawer's own (bubble-phase) Escape handler and stops it.
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
        searchInput.addEventListener('input', () => { state.page = 1; renderTable(); });
        if (filterSelect) filterSelect.addEventListener('change', () => { state.page = 1; renderTable(); });

        renderTable();
        document.body.appendChild(overlay);
        backBtn.focus();
    }

    function kpiTile(def, kpi, data) {
        const sub = def.key === 'adjustment_bersih' ? `${num(kpi.count)} baris` : `${num(kpi.count)} SKU`;
        const card = UI.el('div', {
            class: 'card hpp-kpi-card jejak-kpi', role: 'button', tabindex: '0',
            title: 'Klik untuk lihat rincian', 'data-testid': `jejak-kpi-${def.key}`,
            style: `border-left:3px solid ${def.color};`,
        }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, def.icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, def.label),
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${def.color};`, 'data-testid': `jejak-kpi-value-${def.key}` }, money(kpi.value)),
                UI.el('div', { style: 'font-size:0.66rem; color:var(--text3);' }, sub),
            ]),
        ]);
        const activate = () => openDrilldown(def.key, data, card);
        card.addEventListener('click', activate);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
        });
        return card;
    }

    function miniStat(icon, label, value, color, testid) {
        return UI.el('div', { class: 'card hpp-kpi-card', style: `border-left:3px solid ${color};`, 'data-testid': testid }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${color};` }, String(value)),
                UI.el('div', { class: 'hpp-kpi-label' }, label),
            ]),
        ]);
    }

    const dash = (v) => (isNil(v) || v === '' ? '—' : v);
    const who = (name, at) => (name ? (at ? `${name} · ${at}` : name) : '—');

    function buildInfoCard(s) {
        const model = s.counting_model === 'FINDINGS_V1' ? 'Findings — tim P1/P2' : 'Dual count P1/P2 (legacy)';
        const rows = [
            ['Gudang', `${dash(s.warehouse_code)} — ${dash(s.warehouse_name)}`],
            ['Status', UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status)],
            ['Tanggal Sesi', dash(s.session_date)],
            ['Model Hitung', model],
            ['Sumber Stok Sistem', dash(s.stock_source_label)],
            ['Dibuat Oleh', who(s.created_by, s.created_at)],
            ['Difinalisasi Oleh', who(s.finalized_by, s.finalized_at)],
            ['Diposting Oleh', who(s.posted_by, s.posted_at)],
            ['Supervisor', dash(s.supervisor)],
            ['Tim Count 01 (P1)', (s.team && s.team.P1 && s.team.P1.length) ? s.team.P1.join(', ') : dash(s.p1_user)],
            ['Tim Count 02 (P2)', (s.team && s.team.P2 && s.team.P2.length) ? s.team.P2.join(', ') : dash(s.p2_user)],
        ];
        const grid = UI.el('div', { class: 'jejak-info-grid' }, rows.map(([k, v]) => UI.el('div', { class: 'jejak-info-cell' }, [
            UI.el('div', { class: 'k' }, k),
            UI.el('div', { class: 'v' }, [typeof v === 'string' ? document.createTextNode(v) : v]),
        ])));
        return Drawer.section('Informasi Sesi Stock Opname', grid);
    }

    function buildKpiRow(data) {
        const kpis = computeKpis(data);
        return UI.el('div', { class: 'hpp-kpi-row jejak-kpi-row' }, KPI_DEFS.map((def) => kpiTile(def, kpis[def.key], data)));
    }

    function buildNotes(data) {
        const notes = (data.data_quality && data.data_quality.notes) || [];
        if (!notes.length) return null;
        return UI.el('div', { class: 'alert alert-info', style: 'margin-bottom:12px;', 'data-testid': 'jejak-data-notes' },
            notes.map((n) => UI.el('div', {}, n)));
    }

    function buildBottomSummary(sum) {
        return UI.el('div', { class: 'hpp-kpi-row jejak-kpi-row', style: 'margin-top:16px;', 'data-testid': 'jejak-bottom-summary' }, [
            miniStat('✅', 'Total SKU cocok', sum.sku_cocok, 'var(--green)', 'jejak-sum-cocok'),
            miniStat('⚠️', 'Perlu review', sum.perlu_review, 'var(--red)', 'jejak-sum-review'),
            miniStat('📦', 'Dead stock SKU', `${sum.dead_stock_sku} SKU`, 'var(--orange)', 'jejak-sum-dead'),
            miniStat('🛠', 'Rusak SKU', `${sum.rusak_sku} SKU`, '#f97316', 'jejak-sum-rusak'),
        ]);
    }

    // ---- printing: a plain HTML copy of the REAL data in a new window ----
    function esc(v) {
        return String(isNil(v) ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function printReport(data) {
        const s = data.session;
        const kpis = computeKpis(data);
        const win = window.open('', '_blank');
        if (!win) { UI.toast('Pop-up diblokir browser — izinkan pop-up untuk mencetak.', 'error'); return; }
        const kpiHtml = KPI_DEFS.map((d) => `<tr><td>${esc(d.label)}</td><td style="text-align:right">${esc(money(kpis[d.key].value))}</td><td style="text-align:right">${esc(kpis[d.key].count)}</td></tr>`).join('');
        const head = ['SKU', 'Nama Barang', 'Satuan', 'Qty Sistem', 'Count 01', 'Petugas 01', 'Count 02', 'Petugas 02', 'Final', 'Selisih', 'Dead', 'Rusak', 'HPP', 'Nilai Selisih', 'Nilai Dead', 'Nilai Rusak'];
        const body = data.items.map((i) => `<tr>${[i.sku, i.name, i.unit, num(i.system_qty), num(i.count1_qty), (i.count1_users || []).join(', ') || '—', num(i.count2_qty), (i.count2_users || []).join(', ') || '—', num(i.final_qty), num(i.variance_qty), num(i.dead_qty), num(i.rusak_qty), money(i.hpp), money(i.variance_value), money(i.dead_value), money(i.rusak_value)].map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('');
        win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Jejak Stock Opname ${esc(s.session_number)}</title>
<style>body{font:12px Arial,sans-serif;margin:16px}table{border-collapse:collapse;width:100%;margin-bottom:14px}td,th{border:1px solid #999;padding:3px 5px;text-align:left}th{background:#eee}h1{font-size:16px;margin:0 0 4px}</style></head><body>
<h1>Jejak Stock Opname ${esc(s.session_number)}</h1>
<div>${esc(s.warehouse_code)} — ${esc(s.warehouse_name)} · ${esc(s.session_date)} · ${esc(s.status)} · ${esc(s.stock_source_label)}</div><br>
<table><tr><th>KPI</th><th>Nilai</th><th>Jumlah</th></tr>${kpiHtml}</table>
<table><tr>${head.map((h) => `<th>${esc(h)}</th>`).join('')}</tr>${body}</table></body></html>`);
        win.document.close();
        win.focus();
        win.print();
    }

    function buildPerBarangTab(data) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(data.session));
        wrap.appendChild(buildKpiRow(data));
        const notes = buildNotes(data);
        if (notes) wrap.appendChild(notes);

        const toolbar = UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU atau nama barang...', style: 'flex:1 1 260px; width:auto;', 'data-testid': 'jejak-items-search' });
        const cats = Array.from(new Set(data.items.map((it) => it.category))).sort();
        const catSelect = UI.el('select', { style: 'flex:0 0 220px; width:220px;', 'data-testid': 'jejak-items-category' },
            [UI.el('option', { value: '' }, 'Semua Kategori')].concat(cats.map((c) => UI.el('option', { value: c }, c))));
        toolbar.appendChild(searchInput);
        toolbar.appendChild(catSelect);
        wrap.appendChild(toolbar);

        const tableHost = UI.el('div', { class: 'card' });
        wrap.appendChild(tableHost);
        wrap.appendChild(buildBottomSummary(data.summary));

        // Sticky footer inside the scrolling drawer body: at the very end of
        // the content it simply sits after it (never over rows).
        const actionsRow = UI.el('div', { class: 'jejak-actions', 'data-testid': 'jejak-actions' });
        const printBtn = UI.el('button', { class: 'btn btn-secondary', type: 'button', 'data-testid': 'jejak-print' }, '🖨 Cetak Laporan');
        const excelBtn = UI.el('button', {
            class: 'btn btn-secondary', type: 'button', disabled: 'disabled', 'data-testid': 'jejak-export',
            title: 'Export Excel belum tersedia untuk data Jejak.',
        }, '📊 Export Excel (Belum tersedia)');
        // Posting Adjustment: reporting-only — disabled, NO click handler, and
        // nothing in this module can call a posting endpoint.
        const postBtn = UI.el('button', {
            class: 'btn btn-primary', type: 'button', disabled: 'disabled', 'data-testid': 'jejak-post',
            title: 'Posting Adjustment dinonaktifkan — Jejak hanya untuk pelaporan.',
        }, '✅ Posting Adjustment (Disabled)');
        printBtn.addEventListener('click', () => printReport(data));
        [printBtn, excelBtn, postBtn].forEach((b) => actionsRow.appendChild(b));
        wrap.appendChild(actionsRow);

        const state = { page: 1 };
        function renderTable() {
            tableHost.innerHTML = '';
            const q = searchInput.value.trim().toLowerCase();
            const cat = catSelect.value;
            const rows = data.items.filter((it) => {
                if (cat && it.category !== cat) return false;
                if (q && !(it.sku.toLowerCase().includes(q) || it.name.toLowerCase().includes(q))) return false;
                return true;
            });
            const pageRows = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

            const heads = ['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty Sistem', 'Hasil Count 01', 'Petugas 01',
                'Hasil Count 02', 'Petugas 02', 'Final Count', 'Selisih Qty', 'Dead Stock Qty', 'Rusak Qty', 'Expired Qty',
                'HPP (Rp)', 'Nilai Selisih (Rp)', 'Nilai Dead Stock (Rp)', 'Nilai Rusak (Rp)'];
            const textCols = new Set(['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Petugas 01', 'Petugas 02']);
            const thead = UI.el('thead', {}, UI.el('tr', {}, heads.map((h) => UI.el('th', textCols.has(h) ? {} : { class: 'text-right' }, h))));
            const R = (node) => UI.el('td', { class: 'text-right' }, [node]);
            const T = (v) => document.createTextNode(v);
            const qtyColored = (v, color) => (isNil(v) ? T('—') : (v ? pill(num(v), color) : UI.el('span', { style: 'color:var(--text3);' }, '0')));
            const moneyColored = (v, color) => (isNil(v) ? T('—') : (v ? pill(money(v), color) : UI.el('span', { style: 'color:var(--text3);' }, money(0))));
            const people = (list) => (list && list.length ? list.join(', ') : '—');

            const tbody = UI.el('tbody', {}, pageRows.map((it) => UI.el('tr', {}, [
                UI.el('td', {}, it.sku),
                UI.el('td', {}, it.name + (it.is_excluded ? ' (dikecualikan)' : '') + (it.condition_unresolved ? ' ⚠' : '')),
                UI.el('td', {}, it.category),
                UI.el('td', {}, it.unit),
                R(T(num(it.system_qty))),
                R(T(num(it.count1_qty))),
                UI.el('td', {}, people(it.count1_users)),
                R(T(num(it.count2_qty))),
                UI.el('td', {}, people(it.count2_users)),
                R(T(num(it.final_qty))),
                R(signed(it.variance_qty, false)),
                R(qtyColored(it.dead_qty, 'var(--orange)')),
                R(qtyColored(it.rusak_qty, '#f97316')),
                R(T(num(it.expired_qty))),
                R(T(money(it.hpp))),
                R(signed(it.variance_value, true)),
                R(moneyColored(it.dead_value, 'var(--orange)')),
                R(moneyColored(it.rusak_value, '#f97316')),
            ])));
            if (!rows.length) {
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length), style: 'text-align:center; color:var(--text3);' }, 'Tidak ada baris yang cocok.')]));
            }

            // TOTAL over ALL filtered rows (every page); nominal columns only.
            const tot = (field) => sumOf(rows.filter((r) => !isNil(r[field])), field);
            const totalRow = UI.el('tr', { class: 'jejak-total-row', 'data-testid': 'jejak-items-total-row' },
                heads.map((h, i) => {
                    if (i === 0) return UI.el('td', {}, `TOTAL (${num(rows.length)} SKU)`);
                    if (h === 'Nilai Selisih (Rp)') return R(signed(tot('variance_value'), true));
                    if (h === 'Nilai Dead Stock (Rp)') return R(T(money(tot('dead_value'))));
                    if (h === 'Nilai Rusak (Rp)') return R(T(money(tot('rusak_value'))));
                    return UI.el('td', {}, '');
                }));
            tbody.appendChild(totalRow);

            tableHost.appendChild(UI.el('div', { class: 'jejak-table-wrap', 'data-testid': 'jejak-items-table-wrap' }, [
                UI.el('table', { class: 'jejak-table', 'data-testid': 'jejak-items-table' }, [thead, tbody]),
            ]));
            if (rows.length > PAGE_SIZE) tableHost.appendChild(pager(rows.length, state, renderTable, 'jejak-items'));
        }
        searchInput.addEventListener('input', () => { state.page = 1; renderTable(); });
        catSelect.addEventListener('change', () => { state.page = 1; renderTable(); });
        renderTable();
        return wrap;
    }

    function buildOverviewTab(data) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(data.session));
        wrap.appendChild(buildKpiRow(data));
        const notes = buildNotes(data);
        if (notes) wrap.appendChild(notes);
        wrap.appendChild(buildBottomSummary(data.summary));
        return wrap;
    }

    function buildRekonsiliasiTab(data) {
        const wrap = UI.el('div');
        const src = data.sources || {};
        wrap.appendChild(Drawer.section('Sumber Data', Drawer.kv([
            ['HPP', dash(src.hpp)],
            ['Qty Sistem', dash(src.system_qty)],
            ['Final Count', dash(src.final_qty)],
            ['Selisih', dash(src.variance)],
            ['Dead Stock / Rusak', dash(src.dead_rusak)],
            ['Adjustment', dash(src.adjustment)],
        ])));
        wrap.appendChild(buildKpiRow(data));
        const notes = buildNotes(data);
        if (notes) wrap.appendChild(notes);
        return wrap;
    }

    function buildAuditTab(data) {
        const s = data.session;
        const rows = [
            ['Dibuat', who(s.created_by, s.created_at)],
            ['Difinalisasi', who(s.finalized_by, s.finalized_at)],
            ['Diposting', who(s.posted_by, s.posted_at)],
            ['Dibatalkan', who(s.cancelled_by, s.cancelled_at)],
            ['Supervisor', dash(s.supervisor)],
        ];
        const wrap = UI.el('div');
        wrap.appendChild(Drawer.section('Jejak Sesi', Drawer.kv(rows)));
        const adj = data.adjustments || [];
        if (adj.length) {
            wrap.appendChild(Drawer.section('Adjustment Ter-posting', Drawer.kv(adj.map((a) => [
                `#${a.adjustment_id} · ${a.sku}`, `${a.jenis} · ${num(a.qty)} · ${money(a.value)} · ${dash(a.created_by)} · ${dash(a.created_at)}`,
            ]))));
        }
        return wrap;
    }

    // ----------------------------------------------------------- drawer shell
    function titleNode(session, data, loadingLabel) {
        const s = data ? data.session : null;
        const idLabel = s ? s.id : session.id;
        const number = s ? s.session_number : (session.session_number || `OPN-${session.id}`);
        const status = s ? s.status : session.status;
        const sub = s
            ? `${s.session_number} · ${s.session_date} · ${dash(s.warehouse_code)} · ${dash(s.stock_source_label)}`
            : `${number}${loadingLabel ? ` · ${loadingLabel}` : ''}`;
        return UI.el('div', { 'data-jejak-title': '1', 'data-jejak-session': String(idLabel) }, [
            UI.el('div', { style: 'font-size:1.1rem; font-weight:800; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                `Jejak Stock Opname #${idLabel}`,
                status ? UI.el('span', { class: `badge ${UI.badgeClass(status)}` }, status) : null,
            ]),
            UI.el('div', { style: 'font-size:0.78rem; color:var(--text3); font-weight:500; margin-top:2px;', 'data-testid': 'jejak-subtitle' }, sub),
        ]);
    }

    // app.css gives <html> its own overflow (overflow-x:hidden), so
    // `body{overflow:hidden}` alone is NOT propagated to the viewport and the
    // page behind the drawer would still scroll — both elements are locked.
    function lockPageScroll(on) {
        document.documentElement.classList.toggle('jejak-scroll-lock', on);
        document.body.classList.toggle('jejak-scroll-lock', on);
    }

    function isStillOpenFor(sessionId) {
        const panel = document.querySelector('.drawer');
        const marker = panel && panel.querySelector('[data-jejak-title]');
        return !!(panel && panel.classList.contains('open') && marker && marker.getAttribute('data-jejak-session') === String(sessionId));
    }

    function open(session) {
        const sessionId = session.id;

        Drawer.open({
            title: titleNode(session, null, 'memuat…'),
            render: (body) => body.appendChild(UI.el('div', { class: 'alert alert-info', 'data-testid': 'jejak-loading' }, 'Memuat data Stock Opname…')),
        });

        // Widen this drawer instance (modifier class — never a change to
        // .drawer itself) and keep the page behind from scrolling. The Drawer
        // panel is a shared singleton, so both are dropped again as soon as it
        // closes or another screen's drawer replaces our content.
        const panel = document.querySelector('.drawer');
        if (panel) {
            panel.classList.add('drawer-xl');
            lockPageScroll(true);
            const observer = new MutationObserver(() => {
                if (!panel.classList.contains('open') || !panel.querySelector('[data-jejak-title]')) {
                    panel.classList.remove('drawer-xl');
                    lockPageScroll(false);
                    observer.disconnect();
                }
            });
            observer.observe(panel, { attributes: true, attributeFilter: ['class'], childList: true });
        }

        const load = () => {
            fetchDetail(sessionId).then((data) => {
                if (!isStillOpenFor(sessionId)) return; // closed or replaced while loading
                render(data);
            }).catch((err) => {
                if (!isStillOpenFor(sessionId)) return;
                const retry = UI.el('button', { class: 'btn btn-secondary', type: 'button', 'data-testid': 'jejak-retry' }, 'Coba lagi');
                retry.addEventListener('click', () => {
                    const body = document.querySelector('.drawer .drawer-body');
                    if (body) body.innerHTML = '';
                    if (body) body.appendChild(UI.el('div', { class: 'alert alert-info', 'data-testid': 'jejak-loading' }, 'Memuat data Stock Opname…'));
                    load();
                });
                const body = document.querySelector('.drawer .drawer-body');
                if (!body) return;
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'jejak-error' }, `Gagal memuat Jejak Stock Opname: ${err.message}`));
                body.appendChild(retry);
            });
        };

        function render(data) {
            Drawer.open({
                title: titleNode(session, data),
                tabs: [
                    { key: 'overview', label: 'Overview', render: (body) => body.appendChild(buildOverviewTab(data)) },
                    { key: 'per-barang', label: 'Per Barang', render: (body) => body.appendChild(buildPerBarangTab(data)) },
                    { key: 'rekonsiliasi', label: 'Rekonsiliasi', render: (body) => body.appendChild(buildRekonsiliasiTab(data)) },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(buildAuditTab(data)) },
                ],
            });
            // Tab order is Overview | Per Barang | ..., with Per Barang default.
            // Drawer.open() always activates tabs[0] and shared drawer.js is
            // deliberately not modified, so select it as a user would.
            const panelNow = document.querySelector('.drawer');
            const perBarang = panelNow && Array.from(panelNow.querySelectorAll('.drawer-tab')).find((b) => b.textContent === 'Per Barang');
            if (perBarang) perBarang.click();
        }

        load();
    }

    // _computeKpis/_drilldownRows are exposed only so the reconciliation test can
    // compare them against the server's own totals.
    return { open, _computeKpis: computeKpis, _drilldownRows: drilldownRows };
})();
