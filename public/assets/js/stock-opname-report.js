/**
 * Laporan > Laporan Stock Opname (audit redesign).
 *
 * One read-only screen that answers: when / where / who (P1, P2, supervisor, finalizer) / when each side counted / system vs final
 * quantity / Good, Expired, Rusak, Deadstock / HPP and value / variance / notes / evidence photos / the adjustment that was posted.
 *
 *   filter bar -> KPI cards -> [ Ringkasan Sesi | Rincian Item ] -> item drawer + evidence gallery
 *
 * Every number and every column comes from GET /api/reports/opname-audit/* (StockOpnameAuditReportService, built on the approved
 * Jejak read model): the table columns are RENDERED FROM the column catalogue the API returns — the very same catalogue the
 * exports use — so the export can never be less detailed than the screen. Unknown values are shown as "—" (never 0); quantities of
 * different units are never added (footers are grouped by unit).
 *
 * READ-ONLY: this module only issues GET requests (data + evidence images + exports). It cannot create, change, finalize or post
 * anything. Row actions reuse existing READ screens: "Lihat Jejak" (StockOpnameJejak), the finance print page and — for supervisors —
 * the existing final Excel export.
 */
const StockOpnameReport = (() => {
    let S = null;
    const $ = (id) => document.getElementById(id);
    const COL_KEY = 'soa_hidden_cols_v1';

    // ------------------------------------------------------------------ formatting
    const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nil = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);
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
    const qn = (v) => (nil(v) ? '—' : UI.formatNumber(v, 3));
    const rp = (v) => (nil(v) ? '—' : UI.formatMoney(v));
    const sgnQty = (v) => (nil(v) ? '—' : `${v > 0 ? '+' : ''}${UI.formatNumber(v, 3)}`);
    const sgnRp = (v) => (nil(v) ? '—' : `${v > 0 ? '+' : ''}${UI.formatMoney(v)}`);
    const tone = (v) => (nil(v) || Number(v) === 0 ? '' : Number(v) > 0 ? 'pos' : 'neg');
    const isoShift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };

    function freshState() {
        return {
            from: isoShift(-90), to: isoShift(0), warehouse: '', status: '', q: '',
            view: 'sessions', page: 1, perPage: 25, sessions: null, selected: null,
            itemQ: '', itemCond: '', itemMatch: '', itemPage: 1, itemPerPage: 50, items: null,
        };
    }
    function hiddenCols() {
        try { return JSON.parse(localStorage.getItem(COL_KEY) || '{}'); } catch (e) { return {}; }
    }
    function saveHidden(h) { try { localStorage.setItem(COL_KEY, JSON.stringify(h)); } catch (e) { /* cosmetic only */ } }
    const visible = (table, col) => {
        const h = hiddenCols()[table];
        return h && Object.prototype.hasOwnProperty.call(h, col.key) ? !h[col.key] : col.default;
    };

    function baseParams() {
        return { date_from: S.from || undefined, date_to: S.to || undefined, warehouse_id: S.warehouse || undefined, status: S.status || undefined, q: S.q || undefined };
    }
    function itemParams(extra) {
        const p = { ...baseParams(), item_q: undefined, condition: S.itemCond || undefined, match_status: S.itemMatch || undefined, ...extra };
        if (S.itemQ) p.item_q = S.itemQ;
        else if (S.q) p.item_q = S.q;
        if (S.selected) p.session_ids = String(S.selected.id);
        return p;
    }

    // ------------------------------------------------------------------ render
    function render(container) {
        S = freshState();
        container.innerHTML = '';
        container.classList.add('soa');
        const exportWrap = UI.el('div', { class: 'soa-export' });
        const exportBtn = UI.el('button', { class: 'btn btn-success soa-export-btn', 'data-testid': 'soa-export', type: 'button', 'aria-haspopup': 'true' }, '⬇ Export Excel ▾');
        const menu = UI.el('div', { class: 'soa-export-menu', hidden: 'hidden' }, [
            ['workbook', 'Excel — semua sheet (Ringkasan, Rincian, Evidence, Riwayat Hitung, Adjustment, Audit)'], ['sessions', 'CSV — Ringkasan Sesi'], ['items', 'CSV — Rincian Item'],
            ['evidence', 'CSV — Evidence'], ['history', 'CSV — Riwayat Hitung'], ['adjustments', 'CSV — Adjustment'], ['audit', 'CSV — Audit Log'],
        ].map(([kind, label]) => {
            const b = UI.el('button', { type: 'button', class: 'soa-export-item', 'data-testid': `soa-export-${kind}` }, label);
            b.addEventListener('click', () => { menu.hidden = true; window.open(exportUrl(kind), '_blank'); });
            return b;
        }));
        exportBtn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
        document.addEventListener('click', () => { menu.hidden = true; });
        exportWrap.appendChild(exportBtn);
        exportWrap.appendChild(menu);
        container.appendChild(UI.el('div', { class: 'soa-head' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'soa-title' }, 'Laporan Stock Opname'),
                UI.el('p', { class: 'soa-desc' }, 'Audit hasil Stock Opname dari data sesi nyata: petugas, waktu, qty sistem vs fisik, kondisi barang, nilai, evidence, dan adjustment.'),
            ]),
            exportWrap,
        ]));
        container.appendChild(buildFilterCard());
        container.appendChild(UI.el('div', { id: 'soa-banner' }));
        container.appendChild(UI.el('div', { id: 'soa-kpis', class: 'soa-kpis', 'data-testid': 'soa-kpis' }));
        container.appendChild(buildViewToggle());
        container.appendChild(UI.el('div', { id: 'soa-sessions-card', class: 'soa-card' }));
        container.appendChild(UI.el('div', { id: 'soa-items-card', class: 'soa-card', style: 'display:none;' }));
        container.appendChild(UI.el('div', { class: 'soa-legend' },
            'Match / Mismatch = kecocokan hitung P1 vs P2 per item (status baris produksi); Recount = diselesaikan supervisor. Qty Fisik Final (Total) = Good + Expired + Rusak + Deadstock. '
            + 'Sesi legacy: Rusak/Expired/Deadstock adalah bagian dari total fisik dan tidak mengubah stok; sesi FINDINGS_V1: stok Good dihitung terpisah dari kondisi lainnya. '
            + 'Nilai memakai HPP snapshot awal sesi (bukan harga hari ini). "—" = data tidak tersedia / tidak dicatat (bukan nol). Export selalu memuat semua kolom bisnis dan mengikuti filter + sesi terpilih.'));
        loadSessions();
    }

    function exportUrl(kind) {
        const p = S.selected || S.view === 'items' ? itemParams({ kind }) : { ...baseParams(), kind };
        return InvApi.opnameAuditExportUrl({ ...p, page: undefined, per_page: undefined });
    }

    function buildFilterCard() {
        const whOptions = Master.warehouses().filter((w) => w.is_active !== false).map((w) => `<option value="${esc(w.id)}">${esc(w.name)}</option>`).join('');
        const u = Auth.user();
        const locked = !!(u && u.warehouse_id && ['STOCK', 'ADMIN'].includes(u.role_code));
        const card = UI.el('div', { class: 'soa-card soa-filters' }, [
            UI.el('div', { class: 'soa-filter-grid', html: `
                <div class="soa-f"><label>Dari Tanggal</label><input type="date" id="soa-from" data-testid="soa-from" value="${esc(S.from)}"></div>
                <div class="soa-f"><label>Sampai Tanggal</label><input type="date" id="soa-to" data-testid="soa-to" value="${esc(S.to)}"></div>
                <div class="soa-f"><label>Gudang</label><select id="soa-wh" data-testid="soa-wh" ${locked ? 'disabled' : ''}><option value="">Semua Gudang</option>${whOptions}</select></div>
                <div class="soa-f"><label>Status</label><select id="soa-status" data-testid="soa-status"><option value="">Semua</option>
                    <option value="OPEN">Open / Counting</option><option value="FINALIZED">Finalized</option><option value="POSTED">Posted</option><option value="CANCELLED">Cancelled</option></select></div>
                <div class="soa-f soa-f-wide"><label>Pencarian</label><input type="text" id="soa-q" data-testid="soa-q" placeholder="No. sesi, SKU, nama barang, nama petugas, catatan…" autocomplete="off"></div>
            ` }),
            UI.el('div', { class: 'soa-filter-actions' }, [
                UI.el('button', { type: 'button', class: 'btn btn-primary', id: 'soa-apply', 'data-testid': 'soa-apply' }, '⏷ Terapkan Filter'),
                UI.el('button', { type: 'button', class: 'btn btn-secondary', id: 'soa-reset', 'data-testid': 'soa-reset' }, '↺ Reset'),
            ]),
        ]);
        setTimeout(() => {
            if (locked && u.warehouse_id) { $('soa-wh').value = String(u.warehouse_id); S.warehouse = String(u.warehouse_id); }
            $('soa-apply').addEventListener('click', applyFilters);
            $('soa-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
            $('soa-reset').addEventListener('click', () => {
                const keep = locked ? S.warehouse : '';
                const view = S.view;
                S = Object.assign(freshState(), { warehouse: keep, view });
                $('soa-from').value = S.from; $('soa-to').value = S.to; $('soa-wh').value = keep; $('soa-status').value = ''; $('soa-q').value = '';
                loadSessions();
            });
        }, 0);
        return card;
    }

    function applyFilters() {
        S.from = $('soa-from').value; S.to = $('soa-to').value; S.warehouse = $('soa-wh').value; S.status = $('soa-status').value; S.q = $('soa-q').value.trim();
        S.page = 1; S.itemPage = 1; S.selected = null; S.itemQ = ''; S.itemCond = ''; S.itemMatch = '';
        loadSessions();
    }

    function buildViewToggle() {
        const wrap = UI.el('div', { class: 'soa-viewbar' }, [
            UI.el('div', { class: 'soa-seg', role: 'tablist' }, [
                UI.el('button', { type: 'button', class: 'soa-seg-btn on', 'data-view': 'sessions', 'data-testid': 'soa-view-sessions', role: 'tab' }, 'Ringkasan Sesi'),
                UI.el('button', { type: 'button', class: 'soa-seg-btn', 'data-view': 'items', 'data-testid': 'soa-view-items', role: 'tab' }, 'Rincian Item'),
            ]),
            UI.el('div', { id: 'soa-scope', class: 'soa-scope' }),
        ]);
        wrap.querySelectorAll('.soa-seg-btn').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
        return wrap;
    }
    function setView(view) {
        S.view = view;
        document.querySelectorAll('.soa-seg-btn').forEach((b) => { const on = b.dataset.view === view; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
        if (view === 'items') { S.itemPage = 1; loadItems(); } else { renderLayout(); if (S.selected) loadItems(); }
    }
    function renderLayout() {
        $('soa-sessions-card').style.display = S.view === 'sessions' ? '' : 'none';
        $('soa-items-card').style.display = S.view === 'items' || S.selected ? '' : 'none';
        const scope = $('soa-scope');
        scope.innerHTML = '';
        if (S.selected) {
            const chip = UI.el('span', { class: 'soa-chip', 'data-testid': 'soa-scope-chip' }, [`Sesi terpilih: ${S.selected.session_number} · ${S.selected.warehouse} `]);
            const x = UI.el('button', { type: 'button', class: 'soa-chip-x', 'data-testid': 'soa-scope-clear', title: 'Tampilkan semua sesi' }, '✕');
            x.addEventListener('click', () => { S.selected = null; S.itemPage = 1; document.querySelectorAll('#soa-sessions-card tr.sel').forEach((r) => r.classList.remove('sel')); renderLayout(); if (S.view === 'items') loadItems(); });
            chip.appendChild(x);
            scope.appendChild(chip);
        } else if (S.view === 'items') {
            scope.appendChild(UI.el('span', { class: 'soa-hint' }, 'Menampilkan item dari semua sesi sesuai filter.'));
        }
    }

    // ------------------------------------------------------------------ data
    async function loadSessions() {
        const card = $('soa-sessions-card');
        card.innerHTML = '<div class="soa-loading" data-testid="soa-loading">Memuat sesi Stock Opname…</div>';
        $('soa-kpis').innerHTML = '';
        $('soa-banner').innerHTML = '';
        renderLayout();
        try {
            S.sessions = await InvApi.opnameAuditSessions({ ...baseParams(), page: S.page, per_page: S.perPage });
        } catch (err) {
            card.innerHTML = '';
            card.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'soa-error' }, `Gagal memuat laporan: ${err.message}`));
            return;
        }
        renderBanner();
        renderKpis();
        renderSessions();
        if (S.view === 'items' || S.selected) loadItems();
    }

    async function loadItems() {
        const card = $('soa-items-card');
        card.style.display = '';
        card.innerHTML = '<div class="soa-loading" data-testid="soa-items-loading">Memuat rincian item…</div>';
        renderLayout();
        try {
            S.items = await InvApi.opnameAuditItems(itemParams({ page: S.itemPage, per_page: S.itemPerPage }));
        } catch (err) {
            card.innerHTML = '';
            card.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'soa-items-error' }, `Gagal memuat rincian item: ${err.message}`));
            return;
        }
        renderItems();
    }

    function renderBanner() {
        const host = $('soa-banner');
        host.innerHTML = '';
        if (S.sessions && S.sessions.truncated) {
            host.appendChild(UI.el('div', { class: 'alert alert-warning', 'data-testid': 'soa-truncated' }, 'Terdapat lebih dari 200 sesi pada filter ini — hanya 200 sesi terbaru yang dihitung. Persempit periode agar KPI dan export lengkap.'));
        }
    }

    // ------------------------------------------------------------------ KPI
    function byUnit(map) {
        const e = Object.entries(map || {});
        return e.length ? e.map(([u, q]) => `${u} ${UI.formatNumber(q, 3)}`).join(' · ') : '—';
    }
    function renderKpis() {
        const k = S.sessions.kpi;
        const card = (key, cls, label, value, sub) => UI.el('div', { class: `soa-kpi ${cls}`, 'data-testid': `soa-kpi-${key}` }, [
            UI.el('div', { class: 'soa-kpi-label' }, label),
            UI.el('div', { class: 'soa-kpi-value', 'data-testid': `soa-kpi-${key}-value` }, value),
            UI.el('div', { class: 'soa-kpi-sub' }, sub),
        ]);
        const host = $('soa-kpis');
        host.innerHTML = '';
        const c = k.conditions;
        [
            card('sessions', 'blue', 'TOTAL SESI', UI.formatNumber(k.sessions, 0), `${UI.formatNumber(k.items, 0)} item total`),
            card('verified', 'green', 'TOTAL ITEM DIVERIFIKASI', UI.formatNumber(k.verified, 0), `Match ${k.match} · Mismatch ${k.mismatch} · Recount ${k.recounted}${k.match_pct === null ? '' : ` · ${UI.formatNumber(k.match_pct, 1)}% match`}`),
            card('variance', k.variance_value < 0 ? 'red' : 'amber', 'TOTAL SELISIH NILAI', sgnRp(k.variance_value), `Kurang ${rp(k.variance_negative)} · Lebih ${sgnRp(k.variance_positive)}`),
            card('good', 'teal', 'GOOD', `${UI.formatNumber(c.good.sku, 0)} SKU`, byUnit(c.good.by_unit)),
            card('expired', 'amber', 'EXPIRED', `${UI.formatNumber(c.expired.sku, 0)} SKU`, byUnit(c.expired.by_unit)),
            card('rusak', 'red', 'RUSAK', `${UI.formatNumber(c.rusak.sku, 0)} SKU`, byUnit(c.rusak.by_unit)),
            card('deadstock', 'purple', 'DEADSTOCK', `${UI.formatNumber(c.deadstock.sku, 0)} SKU`, byUnit(c.deadstock.by_unit)),
        ].forEach((n) => host.appendChild(n));
    }

    // ------------------------------------------------------------------ cell rendering (driven by the API column catalogue)
    function statusBadge(v) {
        const map = { POSTED: 'success', FINALIZED: 'info', OPEN: 'warning', CANCELLED: 'danger', Match: 'success', Mismatch: 'danger', Recount: 'info', Dikecualikan: 'muted', 'Belum dihitung': 'warning' };
        return UI.el('span', { class: `badge soa-badge-${map[v] || 'muted'}` }, v);
    }
    function cell(col, row) {
        const v = row[col.key];
        switch (col.type) {
            case 'date': return fmtDate(v);
            case 'ts': return fmtTs(v);
            case 'qty': return qn(v);
            case 'money': return rp(v);
            case 'int': return nil(v) ? '—' : UI.formatNumber(v, 0);
            case 'variance': return UI.el('span', { class: tone(v) }, sgnQty(v));
            case 'variance_money': return UI.el('span', { class: tone(v) }, sgnRp(v));
            case 'people': return nil(v) ? '—' : UI.el('span', { class: 'soa-people' }, v.map((n) => UI.el('span', { class: 'soa-person' }, n)));
            case 'status': return nil(v) ? '—' : statusBadge(v);
            case 'evidence': return evidenceCell(row);
            default: return nil(v) ? '—' : String(v);
        }
    }
    const NUMERIC = new Set(['qty', 'money', 'int', 'variance', 'variance_money']);

    function evidenceCell(row) {
        const ev = row.evidence || [];
        if (!ev.length) return UI.el('span', { class: 'soa-muted', 'data-testid': 'soa-no-evidence' }, row.model === 'Legacy' ? '—' : 'Tidak ada evidence');
        const wrap = UI.el('span', { class: 'soa-thumbs', 'data-testid': 'soa-evidence' });
        ev.slice(0, 3).forEach((e, i) => {
            const img = UI.el('img', { class: `soa-thumb${e.file_ok ? '' : ' broken'}`, src: e.url, alt: `${e.condition} ${row.sku}`, loading: 'lazy', 'data-testid': 'soa-thumb', title: `${e.condition} · ${e.uploaded_by}` });
            img.addEventListener('click', (ev2) => { ev2.stopPropagation(); openGallery(row, i); });
            wrap.appendChild(img);
        });
        if (ev.length > 3) {
            const more = UI.el('button', { type: 'button', class: 'soa-more', 'data-testid': 'soa-thumb-more' }, `+${ev.length - 3}`);
            more.addEventListener('click', (e) => { e.stopPropagation(); openGallery(row, 3); });
            wrap.appendChild(more);
        }
        return wrap;
    }

    // ------------------------------------------------------------------ column visibility
    function columnMenu(table, columns, onChange) {
        const wrap = UI.el('div', { class: 'soa-colmenu-wrap' });
        const btn = UI.el('button', { type: 'button', class: 'btn btn-secondary soa-col-btn', 'data-testid': `soa-cols-${table}` }, '⚙ Kolom');
        const menu = UI.el('div', { class: 'soa-colmenu', hidden: 'hidden' });
        columns.filter((c) => c.key !== 'no').forEach((c) => {
            const cb = UI.el('input', { type: 'checkbox', 'data-col': c.key, ...(visible(table, c) ? { checked: 'checked' } : {}) });
            cb.addEventListener('change', () => {
                const h = hiddenCols();
                h[table] = h[table] || {};
                h[table][c.key] = !cb.checked;
                saveHidden(h);
                onChange();
            });
            menu.appendChild(UI.el('label', { class: 'soa-colopt' }, [cb, ` ${c.label}`]));
        });
        btn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
        menu.addEventListener('click', (e) => e.stopPropagation());
        document.addEventListener('click', () => { menu.hidden = true; });
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    }

    // ------------------------------------------------------------------ Ringkasan Sesi
    function renderSessions() {
        const res = S.sessions;
        const card = $('soa-sessions-card');
        card.innerHTML = '';
        const cols = res.columns.filter((c) => visible('sessions', c));
        card.appendChild(UI.el('div', { class: 'soa-card-head' }, [
            UI.el('div', { class: 'soa-card-title' }, ['Ringkasan Sesi ', UI.el('span', { class: 'soa-sub' }, '(klik baris untuk melihat Rincian Item sesi tersebut)')]),
            columnMenu('sessions', res.columns, renderSessions),
        ]));
        if (!res.rows.length) {
            card.appendChild(UI.el('div', { class: 'soa-empty', 'data-testid': 'soa-sessions-empty' }, 'Tidak ada sesi Stock Opname untuk filter ini.'));
            return;
        }
        const head = UI.el('tr', {}, cols.map((c) => UI.el('th', { class: NUMERIC.has(c.type) ? 'num' : '' }, c.label)).concat([UI.el('th', {}, 'Aksi')]));
        const body = res.rows.map((r) => {
            const tr = UI.el('tr', { class: `click${S.selected && S.selected.id === r.id ? ' sel' : ''}`, 'data-testid': 'soa-session-row', 'data-session-id': String(r.id) },
                cols.map((c) => UI.el('td', { class: NUMERIC.has(c.type) ? 'num' : '' }, [cell(c, r)])).concat([UI.el('td', { class: 'soa-actions' }, sessionActions(r))]));
            tr.addEventListener('click', (e) => { if (e.target.closest('button, a')) return; selectSession(r); });
            return tr;
        });
        card.appendChild(UI.el('div', { class: 'soa-scroll', 'data-testid': 'soa-sessions-scroll' }, [UI.el('table', { class: 'soa-table', 'data-testid': 'soa-sessions-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body)])]));
        card.appendChild(pager(res.pagination, (p) => { S.page = p; loadSessions(); }, 'sesi'));
    }

    function canUseExports() {
        return Auth.hasPermission('STOCK_OPNAME_MANAGE') || Auth.hasPermission('STOCK_OPNAME_SUPERVISE');
    }
    function sessionActions(r) {
        const jej = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary', 'data-testid': 'soa-jejak' }, 'Lihat Jejak');
        jej.addEventListener('click', () => { if (typeof StockOpnameJejak !== 'undefined') StockOpnameJejak.open({ id: r.id, session_number: r.session_number, status: r.status }); });
        const det = UI.el('button', { type: 'button', class: 'btn btn-sm btn-primary', 'data-testid': 'soa-rincian' }, 'Rincian');
        det.addEventListener('click', () => { selectSession(r); setView('items'); });
        const out = [det, ' ', jej];
        const print = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary', 'data-testid': 'soa-print' }, 'Cetak');
        print.addEventListener('click', () => window.open(InvApi.stockOpnameReportPrintUrl(r.id), '_blank'));
        out.push(' ', print);
        if (canUseExports() && r.status === 'POSTED') {
            const xl = UI.el('button', { type: 'button', class: 'btn btn-sm btn-secondary', 'data-testid': 'soa-excel-final' }, 'Excel Final');
            xl.addEventListener('click', () => window.open(InvApi.opnameExportFinalUrl(r.id), '_blank'));
            out.push(' ', xl);
        }
        return out;
    }

    function selectSession(r) {
        S.selected = { id: r.id, session_number: r.session_number, warehouse: r.warehouse };
        S.itemPage = 1;
        document.querySelectorAll('#soa-sessions-card tr[data-session-id]').forEach((tr) => tr.classList.toggle('sel', tr.dataset.sessionId === String(r.id)));
        loadItems();
    }

    // ------------------------------------------------------------------ Rincian Item
    function renderItems() {
        const res = S.items;
        const card = $('soa-items-card');
        card.innerHTML = '';
        const cols = res.columns.filter((c) => visible('items', c));
        card.appendChild(UI.el('div', { class: 'soa-card-head' }, [
            UI.el('div', { class: 'soa-card-title', 'data-testid': 'soa-items-title' }, [
                `Rincian Item Opname${S.selected ? ` — ${S.selected.session_number}` : ''} `,
                UI.el('span', { class: 'soa-sub' }, `(${UI.formatNumber(res.pagination.total, 0)} item · geser ke samping untuk semua kolom)`),
            ]),
            columnMenu('items', res.columns, renderItems),
        ]));
        card.appendChild(UI.el('div', { class: 'soa-itemfilters', html: `
            <input type="text" id="soa-item-q" data-testid="soa-item-q" placeholder="Cari SKU / barang / petugas / catatan…" value="${esc(S.itemQ)}">
            <select id="soa-item-cond" data-testid="soa-item-cond">
                <option value="">Semua kondisi</option><option value="good">Ada Good</option><option value="expired">Ada Expired</option><option value="rusak">Ada Rusak</option>
                <option value="deadstock">Ada Deadstock</option><option value="variance">Ada selisih</option><option value="evidence">Ada evidence</option><option value="no_evidence">Tanpa evidence</option></select>
            <select id="soa-item-match" data-testid="soa-item-match">
                <option value="">Semua status baris</option><option value="MATCH">Match</option><option value="MISMATCH">Mismatch</option><option value="RECOUNTED">Recount</option>
                <option value="PENDING">Belum dihitung</option><option value="EXCLUDED">Dikecualikan</option></select>
            <select id="soa-item-pp" data-testid="soa-item-pp">${[25, 50, 100, 200].map((n) => `<option value="${n}">${n} / halaman</option>`).join('')}</select>
        ` }));
        $('soa-item-cond').value = S.itemCond; $('soa-item-match').value = S.itemMatch; $('soa-item-pp').value = String(S.itemPerPage);
        const reload = () => { S.itemPage = 1; loadItems(); };
        $('soa-item-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') { S.itemQ = e.target.value.trim(); reload(); } });
        $('soa-item-q').addEventListener('blur', (e) => { if (e.target.value.trim() !== S.itemQ) { S.itemQ = e.target.value.trim(); reload(); } });
        $('soa-item-cond').addEventListener('change', (e) => { S.itemCond = e.target.value; reload(); });
        $('soa-item-match').addEventListener('change', (e) => { S.itemMatch = e.target.value; reload(); });
        $('soa-item-pp').addEventListener('change', (e) => { S.itemPerPage = Number(e.target.value); reload(); });

        if (!res.rows.length) {
            card.appendChild(UI.el('div', { class: 'soa-empty', 'data-testid': 'soa-items-empty' }, 'Tidak ada item untuk filter ini.'));
            return;
        }
        const stickyIdx = (key) => (key === 'no' ? 1 : key === 'sku' ? 2 : key === 'name' ? 3 : 0);
        const th = (c) => UI.el('th', { class: `${NUMERIC.has(c.type) ? 'num' : ''}${stickyIdx(c.key) ? ` sticky${stickyIdx(c.key)}` : ''}`, 'data-col': c.key }, c.label);
        const head = UI.el('tr', {}, cols.map(th).concat([UI.el('th', { class: 'soa-aksi-col' }, 'Aksi')]));
        const body = res.rows.map((r) => {
            const tr = UI.el('tr', { class: 'click', 'data-testid': 'soa-item-row', 'data-line-id': String(r.line_id), 'data-sku': r.sku },
                cols.map((c) => UI.el('td', { class: `${NUMERIC.has(c.type) ? 'num' : ''}${stickyIdx(c.key) ? ` sticky${stickyIdx(c.key)}` : ''}`, 'data-col': c.key }, [cell(c, r)]))
                    .concat([UI.el('td', { class: 'soa-aksi-col' }, [itemAction(r)])]));
            tr.addEventListener('click', (e) => { if (e.target.closest('button, img')) return; openItemDrawer(r); });
            return tr;
        });
        const foot = footerRow(cols, res);
        card.appendChild(UI.el('div', { class: 'soa-scroll soa-items', 'data-testid': 'soa-items-scroll' }, [UI.el('table', { class: 'soa-table soa-wide', 'data-testid': 'soa-items-table' }, [UI.el('thead', {}, [head]), UI.el('tbody', {}, body), UI.el('tfoot', {}, [foot])])]));
        card.appendChild(UI.el('div', { class: 'soa-unitline', 'data-testid': 'soa-items-units' }, unitLine(res.footer)));
        card.appendChild(pager(res.pagination, (p) => { S.itemPage = p; loadItems(); }, 'item'));
    }

    function itemAction(r) {
        const b = UI.el('button', { type: 'button', class: 'btn btn-sm btn-primary', 'data-testid': 'soa-item-detail' }, 'Detail');
        b.addEventListener('click', (e) => { e.stopPropagation(); openItemDrawer(r); });
        return b;
    }
    function footerRow(cols, res) {
        const m = res.footer.money;
        const sums = { system_value: m.system_value, final_value: m.final_value, variance_value: m.variance_value, adj_value: m.adj_value };
        const cells = cols.map((c, i) => {
            const stick = c.key === 'no' ? ' sticky1' : c.key === 'sku' ? ' sticky2' : c.key === 'name' ? ' sticky3' : '';
            let content = '';
            if (i === 0) content = `TOTAL ${UI.formatNumber(res.footer.count, 0)} item`; // first visible column (SKU / No. may be hidden)
            if (c.key === 'no') content = '';
            if (c.key === 'sku') content = `TOTAL ${UI.formatNumber(res.footer.count, 0)} item`;
            if (Object.prototype.hasOwnProperty.call(sums, c.key)) content = c.type === 'variance_money' ? UI.el('span', { class: tone(sums[c.key]) }, sgnRp(sums[c.key])) : rp(sums[c.key]);
            return UI.el('td', { class: `${NUMERIC.has(c.type) ? 'num' : ''}${stick}`, 'data-col': c.key }, [content]);
        });
        return UI.el('tr', { 'data-testid': 'soa-items-total' }, cells.concat([UI.el('td', {}, '')]));
    }
    function unitLine(f) {
        const parts = Object.entries(f.qty_by_unit).map(([u, q]) => `${u}: sistem ${UI.formatNumber(q.system_qty, 3)} · fisik ${UI.formatNumber(q.final_qty, 3)} · good ${UI.formatNumber(q.good_qty, 3)} · expired ${UI.formatNumber(q.expired_qty, 3)} · rusak ${UI.formatNumber(q.rusak_qty, 3)} · deadstock ${UI.formatNumber(q.deadstock_qty, 3)} · selisih ${sgnQty(q.variance_qty)}`);
        return parts.length ? `Qty per satuan (tidak dijumlahkan lintas satuan; nilai "—" tidak dihitung) — ${parts.join('  |  ')}` : '';
    }

    function pager(p, onPage, label) {
        const bar = UI.el('div', { class: 'soa-pager' }, [UI.el('span', {}, `Total ${UI.formatNumber(p.total, 0)} ${label}`)]);
        const nav = UI.el('div', { class: 'soa-pager-nav' });
        const btn = (text, page, disabled, cur) => { const b = UI.el('button', { type: 'button', class: `soa-pg${cur ? ' on' : ''}`, ...(disabled ? { disabled: 'disabled' } : {}) }, text); b.addEventListener('click', () => onPage(page)); return b; };
        nav.appendChild(btn('‹', p.page - 1, p.page <= 1));
        const shown = new Set([1, p.total_pages, p.page, p.page - 1, p.page + 1].filter((n) => n >= 1 && n <= p.total_pages));
        let prev = 0;
        [...shown].sort((a, b) => a - b).forEach((n) => { if (n - prev > 1) nav.appendChild(UI.el('span', { class: 'soa-pg-gap' }, '…')); nav.appendChild(btn(String(n), n, false, n === p.page)); prev = n; });
        nav.appendChild(btn('›', p.page + 1, p.page >= p.total_pages));
        bar.appendChild(nav);
        return bar;
    }

    // ------------------------------------------------------------------ evidence gallery (lightbox)
    function openGallery(row, startIndex) {
        const list = row.evidence || [];
        if (!list.length) return;
        let idx = Math.min(startIndex || 0, list.length - 1);
        const overlay = UI.el('div', { class: 'soa-lightbox', 'data-testid': 'soa-gallery', role: 'dialog', 'aria-modal': 'true' });
        const img = UI.el('img', { class: 'soa-lb-img', 'data-testid': 'soa-gallery-img', alt: '' });
        const info = UI.el('div', { class: 'soa-lb-info', 'data-testid': 'soa-gallery-info' });
        const strip = UI.el('div', { class: 'soa-lb-strip' });
        const close = () => { overlay.remove(); window.removeEventListener('keydown', onKey, true); };
        // capture on window + stopPropagation: Esc closes ONLY the lightbox, not the item drawer underneath it.
        const onKey = (e) => {
            if (e.key === 'Escape') { e.stopPropagation(); close(); } else if (e.key === 'ArrowRight') { e.stopPropagation(); show(idx + 1); } else if (e.key === 'ArrowLeft') { e.stopPropagation(); show(idx - 1); }
        };
        function show(i) {
            idx = (i + list.length) % list.length;
            const e = list[idx];
            img.src = e.url;
            info.innerHTML = '';
            [
                ['Barang', `${row.sku} — ${row.name}`], ['Sesi', `${row.session_number} · ${row.warehouse}`], ['Kondisi', `${e.condition} (tim ${e.team})`],
                ['Diunggah oleh', e.uploaded_by], ['Timestamp upload', fmtTs(e.uploaded_at)], ['Catatan / caption', e.caption || '—'],
                ['Status', e.voided ? 'Temuan di-VOID' : 'Aktif'], ['Foto', `${idx + 1} / ${list.length}${e.file_ok ? '' : ' — file tidak ditemukan'}`],
            ].forEach(([k, v]) => info.appendChild(UI.el('div', { class: 'soa-lb-row' }, [UI.el('b', {}, `${k}: `), v])));
            strip.querySelectorAll('img').forEach((t, n) => t.classList.toggle('on', n === idx));
        }
        list.forEach((e, n) => { const t = UI.el('img', { src: e.url, class: 'soa-lb-th', alt: '' }); t.addEventListener('click', () => show(n)); strip.appendChild(t); });
        const closeBtn = UI.el('button', { type: 'button', class: 'soa-lb-close', 'data-testid': 'soa-gallery-close', 'aria-label': 'Tutup' }, '✕');
        closeBtn.addEventListener('click', close);
        const prevBtn = UI.el('button', { type: 'button', class: 'soa-lb-nav prev', 'aria-label': 'Sebelumnya' }, '‹');
        const nextBtn = UI.el('button', { type: 'button', class: 'soa-lb-nav next', 'aria-label': 'Berikutnya' }, '›');
        prevBtn.addEventListener('click', () => show(idx - 1));
        nextBtn.addEventListener('click', () => show(idx + 1));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        overlay.appendChild(closeBtn);
        overlay.appendChild(UI.el('div', { class: 'soa-lb-main' }, [prevBtn, UI.el('div', { class: 'soa-lb-stage' }, [img]), nextBtn]));
        overlay.appendChild(strip);
        overlay.appendChild(info);
        document.body.appendChild(overlay);
        window.addEventListener('keydown', onKey, true);
        show(idx);
    }

    // ------------------------------------------------------------------ item drawer
    function wideDrawer(title, opts) {
        Drawer.open({ title, ...opts });
        const panel = document.querySelector('.drawer');
        if (!panel) return;
        panel.classList.add('soa-drawer');
        const mo = new MutationObserver(() => { if (!panel.classList.contains('open')) { panel.classList.remove('soa-drawer'); mo.disconnect(); } });
        mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    function kv(rows) {
        return UI.el('div', { class: 'soa-kv' }, rows.map(([k, v]) => UI.el('div', { class: 'soa-kv-row' }, [UI.el('span', { class: 'soa-kv-k' }, k), UI.el('span', { class: 'soa-kv-v' }, [nil(v) ? '—' : v])])));
    }
    function simpleTable(heads, rows, testid) {
        return UI.el('div', { class: 'soa-scroll' }, [UI.el('table', { class: 'soa-table', 'data-testid': testid }, [
            UI.el('thead', {}, [UI.el('tr', {}, heads.map((h) => UI.el('th', {}, h)))]),
            UI.el('tbody', {}, rows.length ? rows.map((r) => UI.el('tr', {}, r.map((v) => UI.el('td', {}, [nil(v) ? '—' : v])))) : [UI.el('tr', {}, [UI.el('td', { colspan: String(heads.length) }, 'Tidak ada data.')])]),
        ])]);
    }

    async function openItemDrawer(row) {
        wideDrawer(`${row.sku} — ${row.name}`, { render: (body) => { body.innerHTML = '<div class="alert alert-info" data-testid="soa-drawer-loading">Memuat detail item…</div>'; } });
        let d;
        try {
            d = await InvApi.opnameAuditItemDetail({ session_id: row.session_id, line_id: row.line_id });
        } catch (err) {
            const body = document.querySelector('.drawer .drawer-body');
            if (body) { body.innerHTML = ''; body.appendChild(UI.el('div', { class: 'alert alert-error', 'data-testid': 'soa-drawer-error' }, `Gagal memuat detail: ${err.message}`)); }
            return;
        }
        const it = d.item;
        const sub = UI.el('div', { class: 'soa-drawer-sub' }, `${it.session_number} · ${it.warehouse} · ${fmtDate(it.session_date)} · ${it.model} · ${it.line_status}`);
        wideDrawer(`${it.sku} — ${it.name}`, {
            tabs: [
                { key: 'ringkasan', label: 'Ringkasan', render: (body) => { body.appendChild(sub); body.appendChild(summaryTab(d)); } },
                { key: 'riwayat', label: 'Riwayat Hitung', render: (body) => body.appendChild(historyTab(d)) },
                { key: 'evidence', label: `Evidence (${d.evidence.length})`, render: (body) => body.appendChild(evidenceTab(d)) },
                { key: 'rekon', label: 'Rekonsiliasi', render: (body) => body.appendChild(reconTab(d)) },
                { key: 'adjustment', label: 'Adjustment', render: (body) => body.appendChild(adjustmentTab(d)) },
                { key: 'audit', label: `Audit (${d.audit.length})`, render: (body) => body.appendChild(auditTab(d)) },
            ],
        });
    }
    function summaryTab(d) {
        const s = d.item;
        return UI.el('div', { 'data-testid': 'soa-tab-ringkasan' }, [
            kv([['Satuan', s.unit], ['Qty Sistem', qn(s.system_qty)], ['Qty Fisik Final (Total)', qn(s.final_qty)], ['Good / Stok Layak', qn(s.good_qty)], ['Expired', qn(s.expired_qty)], ['Rusak', qn(s.rusak_qty)],
                ['Deadstock', qn(s.deadstock_qty)], ['Selisih Qty', UI.el('span', { class: tone(s.variance_qty) }, sgnQty(s.variance_qty))], ['HPP / Unit Cost (snapshot sesi)', rp(s.hpp)],
                ['Nilai Sistem', rp(s.system_value)], ['Nilai Fisik', rp(s.final_value)], ['Selisih Nilai', UI.el('span', { class: tone(s.variance_value) }, sgnRp(s.variance_value))],
                ['Petugas Hitung (P1)', nil(s.p_hitung) ? null : s.p_hitung.join(', ')], ['Petugas Verifikasi (P2)', nil(s.p_verifikasi) ? null : s.p_verifikasi.join(', ')],
                ['Timestamp Hitung', fmtTs(s.ts_hitung)], ['Timestamp Verifikasi', fmtTs(s.ts_verifikasi)], ['Timestamp Final Input', fmtTs(s.ts_final)],
                ['Catatan Petugas', s.note_petugas], ['Catatan Supervisor', s.note_supervisor], ['Catatan Lain', s.note_general]]),
        ]);
    }
    function historyTab(d) {
        const rows = d.count_history.map((h) => [
            h.role + (h.round > 1 && h.role !== 'RECOUNT' ? ` r${h.round}` : ''), h.actor, h.action, fmtTs(h.counted_at),
            h.source === 'LEGACY' ? qn(h.total_qty) : `Good ${qn(h.good_qty)}`, qn(h.expired_qty), qn(h.rusak_qty), qn(h.deadstock_qty),
            h.quantities && h.quantities.length ? h.quantities.map((q) => `${q.condition} ${UI.formatNumber(q.input_qty, 3)} ${q.unit}`).join(', ') : '—',
            h.voided ? `VOID oleh ${h.voided.by} (${fmtTs(h.voided.at)}): ${h.voided.reason || '—'}` : (h.backfilled ? 'Waktu di-backfill' : ''), h.notes,
        ]);
        return UI.el('div', { 'data-testid': 'soa-tab-riwayat' }, [simpleTable(['Tim', 'Petugas', 'Aksi', 'Waktu hitung', 'Qty', 'Expired', 'Rusak', 'Deadstock', 'Rincian satuan input', 'Status', 'Catatan'], rows, 'soa-history-table')]);
    }
    function evidenceTab(d) {
        if (!d.evidence.length) return UI.el('div', { class: 'soa-empty', 'data-testid': 'soa-tab-evidence' }, d.item.model === 'Legacy' ? '— (sesi legacy tidak menyimpan evidence)' : 'Tidak ada evidence');
        const grid = UI.el('div', { class: 'soa-evgrid', 'data-testid': 'soa-tab-evidence' });
        d.evidence.forEach((e, i) => {
            const fig = UI.el('figure', { class: 'soa-fig' }, [
                UI.el('img', { src: e.url, class: 'soa-fig-img', alt: e.condition, loading: 'lazy' }),
                UI.el('figcaption', {}, `${e.condition} · tim ${e.team} · ${e.uploaded_by} · ${fmtTs(e.uploaded_at)}${e.caption ? ` · ${e.caption}` : ''}${e.voided ? ' · VOID' : ''}`),
            ]);
            fig.addEventListener('click', () => openGallery(d.item, i));
            grid.appendChild(fig);
        });
        return grid;
    }
    function reconTab(d) {
        const r = d.reconciliation;
        const book = r.book_stock;
        const rec = r.recount;
        return UI.el('div', { 'data-testid': 'soa-tab-rekon' }, [
            kv([['Model', r.model], ['Status baris', r.line_status], ['Sumber Qty Sistem', r.system_source], ['Sumber Qty Final', r.final_source], ['Sumber Selisih', r.variance_source],
                ['Rumus', r.formula], ['Supervisor', r.supervisor], ['Keputusan final oleh', r.final_decision_by], ['Waktu final', fmtTs(r.final_decision_at)], ['Catatan Supervisor', r.note_supervisor],
                ['Recount', rec ? `${qn(rec.qty)} oleh ${rec.by || '—'} (${fmtTs(rec.at)}) — ${rec.reason || '—'}` : null],
                ['Stok buku EOD (V1)', book ? qn(book.book_stock_eod) : null], ['Fisik EOD total (V1)', book ? qn(book.final_physical_eod) : null]]),
        ]);
    }
    function adjustmentTab(d) {
        const rows = d.adjustment.map((a) => [a.reference_no || `ADJ-${a.adjustment_id}`, UI.el('span', { class: tone(a.qty) }, sgnQty(a.qty)), rp(a.hpp), UI.el('span', { class: tone(a.value) }, sgnRp(a.value)), a.reason, a.created_by, fmtTs(a.created_at)]);
        return UI.el('div', { 'data-testid': 'soa-tab-adjustment' }, [simpleTable(['Adjustment Ref', 'Qty', 'HPP Adjustment', 'Nilai', 'Alasan', 'Dibuat oleh', 'Timestamp'], rows, 'soa-adjustment-table'),
            UI.el('div', { class: 'soa-hint' }, 'Nilai adjustment = qty × HPP adjustment yang benar-benar diposting (FIFO); dapat berbeda dari Selisih Nilai (qty × HPP snapshot sesi).')]);
    }
    function auditTab(d) {
        const rows = d.audit.map((a) => [fmtTs(a.at), a.actor, a.action, `${a.entity} #${a.entity_id}`, a.reason, a.after ? JSON.stringify(a.after) : null]);
        return UI.el('div', { 'data-testid': 'soa-tab-audit' }, [simpleTable(['Waktu', 'Pelaku', 'Aksi', 'Entitas', 'Alasan', 'Detail'], rows, 'soa-audit-table')]);
    }

    return { render };
})();
