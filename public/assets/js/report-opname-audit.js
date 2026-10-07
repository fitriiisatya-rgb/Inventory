/**
 * Laporan > Laporan Stock Opname (reports v3 presentation).
 *
 * One READ-ONLY, compact audit screen: when / where / who (P1, P2, supervisor, finalizer) / system vs physical quantity / Good, Expired, Rusak, Deadstock /
 * HPP and value / variance / notes / evidence photos / the adjustment that was posted.
 *
 *   header (Cetak + Download Excel) -> ONE filter card -> KPI row -> [ Ringkasan Sesi | Rincian Item ] -> session drawer / item drawer / evidence gallery
 *
 * Data: GET /api/reports/opname-audit/{sessions,items,item-detail,export,photo/{id}} (StockOpnameAuditReportService, built on the approved Jejak read model).
 * The Rincian Item columns come from the column catalogue the API returns — the very catalogue the Excel / CSV exports use — so the export can never be less
 * detailed than the screen. Unknown values are shown as "—" (never 0); quantities of different units are never added (footers are grouped by unit).
 * Shared look: the .rp-* report family (app.css "RV3 REPORT FAMILY") + a few SO specific .soa3-* rules (app.css "SOA3 AUDIT UI"). Cetak / Excel: report-tools.js.
 *
 * READ-ONLY: this module only issues GET requests (data, evidence images, exports). It cannot create, change, finalize or post anything.
 */
const ReportOpnameAudit = (() => {
    const COLS_KEY = 'soa3_item_cols_v2';
    /** Default Rincian Item columns (the chooser offers every column of the service catalogue). */
    const ITEM_DEFAULT = ['sku', 'name', 'unit', 'system_qty', 'final_qty', 'variance_qty', 'good_qty', 'expired_qty', 'rusak_qty', 'deadstock_qty', 'hpp', 'variance_value'];
    const SESSION_PER_PAGE = [10, 25, 50, 100];
    const ITEM_PER_PAGE = [25, 50, 100, 200];
    const STATUS_OPTIONS = [['', 'Semua status'], ['OPEN', 'Open / Counting'], ['FINALIZED', 'Finalized'], ['POSTED', 'Posted'], ['CANCELLED', 'Cancelled']];
    const COND_OPTIONS = [['', 'Semua kondisi'], ['good', 'Ada Good'], ['expired', 'Ada Expired'], ['rusak', 'Ada Rusak'], ['deadstock', 'Ada Deadstock'], ['variance', 'Ada selisih'], ['evidence', 'Ada evidence'], ['no_evidence', 'Tanpa evidence']];
    const MATCH_OPTIONS = [['', 'Semua status baris'], ['MATCH', 'Match'], ['MISMATCH', 'Mismatch'], ['RECOUNTED', 'Recount'], ['PENDING', 'Belum dihitung'], ['EXCLUDED', 'Dikecualikan']];
    const NUMERIC = new Set(['qty', 'money', 'int', 'variance', 'variance_money']);
    /** Compact table headers (the full catalogue label stays in the tooltip and in the column chooser). */
    const SHORT = { name: 'Barang', final_qty: 'Qty Fisik', good_qty: 'Good', p_hitung: 'P1', p_verifikasi: 'P2', line_status: 'Status', hpp: 'HPP', category: 'Kategori', session_number: 'No. Sesi', session_date: 'Tanggal', note_petugas: 'Catatan Petugas', ts_hitung: 'Waktu P1', ts_verifikasi: 'Waktu P2', ts_final: 'Waktu Final', evidence: 'Evidence' };

    // ------------------------------------------------------------------ small helpers
    const nil = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);
    /** Lenient element builder: deep-flattens children, stringifies numbers, skips null / false, never writes a null attribute. */
    function h(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach((k) => {
            const v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'class') node.className = v;
            else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : String(v));
        });
        const add = (c) => {
            if (c === null || c === undefined || c === false) return;
            if (Array.isArray(c)) { c.forEach(add); return; }
            node.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
        };
        add(children);
        return node;
    }
    const isoShift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };
    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
        return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    const fmtTs = (v, seconds) => {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(v);
        const o = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        if (seconds) o.second = '2-digit';
        return d.toLocaleString('id-ID', o);
    };
    const num = (v, d) => UI.formatNumber(v, d === undefined ? 3 : d);
    const qn = (v) => (nil(v) ? '—' : num(v, 3));
    const rp = (v) => (nil(v) ? '—' : `${Number(v) < 0 ? '-' : ''}Rp ${Math.abs(Number(v)).toLocaleString('id-ID', { maximumFractionDigits: 2 })}`);
    const sgnQty = (v) => (nil(v) ? '—' : `${v > 0 ? '+' : ''}${num(v, 3)}`);
    const sgnRp = (v) => (nil(v) ? '—' : `${Number(v) > 0 ? '+' : ''}${rp(v)}`);
    const tone = (v) => (nil(v) || Number(v) === 0 ? '' : Number(v) > 0 ? 'rp-pos' : 'rp-neg');
    const names = (v) => (Array.isArray(v) ? v.join(', ') : '');
    const clearNode = (n) => { while (n.firstChild) n.removeChild(n.firstChild); };

    function loadCols() {
        try { const a = JSON.parse(localStorage.getItem(COLS_KEY)); return Array.isArray(a) ? a : null; } catch (e) { return null; }
    }
    function saveCols(keys) {
        try { localStorage.setItem(COLS_KEY, JSON.stringify(keys)); } catch (e) { /* cosmetic only */ }
    }

    // ------------------------------------------------------------------ cells (driven by the API column catalogue)
    const LINE_PILL = { Match: 'ok', Mismatch: 'bad', Recount: 'info', Dikecualikan: '', 'Belum dihitung': 'warn' };
    const SESSION_PILL = { POSTED: 'ok', FINALIZED: 'info', OPEN: 'warn', CANCELLED: 'bad' };
    const pill = (text, cls) => h('span', { class: `rp-pill${cls ? ` ${cls}` : ''}` }, text);

    /** Value -> node/text by catalogue type. */
    function cellBy(type, v, row) {
        switch (type) {
            case 'date': return fmtDate(v);
            case 'ts': return fmtTs(v);
            case 'qty': return qn(v);
            case 'money': return rp(v);
            case 'int': return nil(v) ? '—' : num(v, 0);
            case 'variance': return h('span', { class: tone(v) }, sgnQty(v));
            case 'variance_money': return h('span', { class: tone(v) }, sgnRp(v));
            case 'people': return nil(v) ? '—' : h('span', { class: 'soa3-ppl', title: names(v) }, names(v));
            case 'status': return nil(v) ? '—' : pill(v, SESSION_PILL[v] !== undefined ? SESSION_PILL[v] : LINE_PILL[v]);
            case 'evidence': return row ? evidenceCell(row) : '—';
            default: return nil(v) ? '—' : String(v);
        }
    }

    // ------------------------------------------------------------------ evidence gallery (lightbox) — shared by the table thumbnails and the drawer
    function openGallery(row, startIndex) {
        const list = row.evidence || [];
        if (!list.length) return;
        let idx = Math.min(startIndex || 0, list.length - 1);
        const overlay = h('div', { class: 'soa3-lb', 'data-testid': 'soa3-gallery', role: 'dialog', 'aria-modal': 'true' });
        const img = h('img', { class: 'soa3-lb-img', 'data-testid': 'soa3-gallery-img', alt: '' });
        const info = h('div', { class: 'soa3-lb-info', 'data-testid': 'soa3-gallery-info' });
        const strip = h('div', { class: 'soa3-lb-strip' });
        const close = () => { overlay.remove(); window.removeEventListener('keydown', onKey, true); };
        // capture on window + stopPropagation: Esc closes ONLY the lightbox, not the drawer underneath it.
        const onKey = (e) => {
            if (e.key === 'Escape') { e.stopPropagation(); close(); } else if (e.key === 'ArrowRight') { e.stopPropagation(); show(idx + 1); } else if (e.key === 'ArrowLeft') { e.stopPropagation(); show(idx - 1); }
        };
        function show(i) {
            idx = (i + list.length) % list.length;
            const e = list[idx];
            img.src = e.url;
            clearNode(info);
            [
                ['Barang', `${row.sku} — ${row.name}`], ['Sesi', `${row.session_number} · ${row.warehouse}`], ['Kondisi', `${e.condition} (tim ${e.team})`],
                ['Diunggah oleh', e.uploaded_by], ['Timestamp upload', fmtTs(e.uploaded_at, true)], ['Catatan / caption', e.caption || '—'],
                ['Status', e.voided ? 'Temuan di-VOID' : 'Aktif'], ['Foto', `${idx + 1} / ${list.length}${e.file_ok ? '' : ' — file tidak ditemukan'}`],
            ].forEach(([k, v]) => info.appendChild(h('div', {}, [h('b', {}, `${k}: `), v])));
            strip.querySelectorAll('img').forEach((t, n) => t.classList.toggle('on', n === idx));
        }
        list.forEach((e, n) => { const t = h('img', { src: e.url, class: 'soa3-lb-th', alt: '', onclick: () => show(n) }); strip.appendChild(t); });
        const closeBtn = h('button', { type: 'button', class: 'soa3-lb-close', 'data-testid': 'soa3-gallery-close', 'aria-label': 'Tutup', onclick: close }, '✕');
        const prevBtn = h('button', { type: 'button', class: 'soa3-lb-nav', 'aria-label': 'Sebelumnya', onclick: () => show(idx - 1) }, '‹');
        const nextBtn = h('button', { type: 'button', class: 'soa3-lb-nav', 'aria-label': 'Berikutnya', onclick: () => show(idx + 1) }, '›');
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        overlay.appendChild(closeBtn);
        overlay.appendChild(h('div', { class: 'soa3-lb-main' }, [prevBtn, h('div', { class: 'soa3-lb-stage' }, img), nextBtn]));
        overlay.appendChild(strip);
        overlay.appendChild(info);
        document.body.appendChild(overlay);
        window.addEventListener('keydown', onKey, true);
        show(idx);
    }

    function evidenceCell(row) {
        const ev = row.evidence || [];
        if (!ev.length) return h('span', { class: 'rp-dim', 'data-testid': 'soa3-no-evidence' }, row.model === 'Legacy' ? '—' : 'Tidak ada');
        const wrap = h('span', { class: 'soa3-thumbs', 'data-testid': 'soa3-evidence' });
        ev.slice(0, 3).forEach((e, i) => {
            wrap.appendChild(h('img', {
                class: `soa3-thumb${e.file_ok ? '' : ' broken'}`, src: e.url, alt: `${e.condition} ${row.sku}`, loading: 'lazy', 'data-testid': 'soa3-thumb', title: `${e.condition} · ${e.uploaded_by}`,
                onclick: (ev) => { ev.stopPropagation(); openGallery(row, i); },
            }));
        });
        if (ev.length > 3) wrap.appendChild(h('button', { type: 'button', class: 'soa3-more', 'data-testid': 'soa3-thumb-more', onclick: (e) => { e.stopPropagation(); openGallery(row, 3); } }, `+${ev.length - 3}`));
        return wrap;
    }

    // ------------------------------------------------------------------ drawers (one shared Drawer instance; widened + iPad-safe by .soa3-drawer)
    function wideDrawer(title, opts) {
        Drawer.open({ title, ...opts });
        const panel = document.querySelector('.drawer');
        if (!panel) return;
        panel.classList.add('rp-drawer', 'soa3-drawer');
        const mo = new MutationObserver(() => { if (!panel.classList.contains('open')) { panel.classList.remove('rp-drawer', 'soa3-drawer'); mo.disconnect(); } });
        mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    const kvGrid = (rows, testid) => h('div', { class: 'soa3-kv', 'data-testid': testid }, rows.map(([k, v]) => h('div', { class: 'soa3-kv-row' }, [h('span', { class: 'soa3-kv-k' }, k), h('span', { class: 'soa3-kv-v' }, nil(v) ? '—' : v)])));
    function simpleTable(heads, rows, testid) {
        return h('div', { class: 'rp-scroll free' }, h('table', { class: 'rp-table', 'data-testid': testid }, [
            h('thead', {}, h('tr', {}, heads.map((x) => h('th', {}, x)))),
            h('tbody', {}, rows.length ? rows.map((r) => h('tr', {}, r.map((v) => h('td', {}, nil(v) ? '—' : v)))) : h('tr', {}, h('td', { colspan: String(heads.length), class: 'dim' }, 'Tidak ada data.'))),
        ]));
    }

    const COND_STATUS = { cocok: 'cocok dengan temuan', tanpa_temuan: 'snapshot — TANPA temuan kondisi', berbeda: 'berbeda dari temuan', tidak_dicatat: 'tidak dicatat', legacy: 'sesi legacy (tanpa temuan)' };
    function conditionBlock(d) {
        const c = d.conditions;
        if (!c) return null;
        const rows = Object.keys(c.rows).map((k) => {
            const r = c.rows[k];
            const f = Object.keys(r.findings).length ? Object.keys(r.findings).map((t) => `${t} ${qn(r.findings[t])}`).join(' · ') : 'tidak ada temuan non-VOID';
            return [r.label, qn(r.snapshot), f, h('span', { class: r.status === 'tanpa_temuan' || r.status === 'berbeda' ? 'rp-warn' : '' }, COND_STATUS[r.status] || r.status)];
        });
        return h('div', { 'data-testid': 'soa3-condition-block' }, [
            h('div', { class: 'rp-note soa3-gap' }, `Sumber kondisi final: ${c.source_label}. Nilai di tabel = snapshot persisten; kolom "Temuan" hanya riwayat (tidak dipakai untuk mengubah snapshot).`),
            simpleTable(['Kondisi', 'Snapshot final (ditampilkan)', 'Temuan non-VOID per tim (riwayat)', 'Status'], rows, 'soa3-condition-table'),
        ]);
    }

    function itemDrawerTabs(d, itemColumns) {
        const it = d.item;
        const summary = () => {
            // EVERY audit field of the catalogue (except the row counter and the photo strip, which has its own tab)
            const fields = itemColumns.filter((c) => !['no', 'evidence'].includes(c.key)).map((c) => [c.label, cellBy(c.type, it[c.key], it)]);
            fields.push(['Evidence Foto', `${d.evidence.length} foto`]);
            return h('div', { 'data-testid': 'soa3-tab-ringkasan' }, [conditionBlock(d), h('div', { class: 'soa3-gap' }), kvGrid(fields, 'soa3-item-fields')]);
        };
        const history = () => {
            const rows = d.count_history.map((x) => [
                x.role + (x.round > 1 && x.role !== 'RECOUNT' ? ` r${x.round}` : ''), x.actor, x.action, fmtTs(x.counted_at, true),
                x.source === 'LEGACY' ? qn(x.total_qty) : `Good ${qn(x.good_qty)}`, qn(x.expired_qty), qn(x.rusak_qty), qn(x.deadstock_qty),
                x.quantities && x.quantities.length ? x.quantities.map((q) => `${q.condition} ${num(q.input_qty, 3)} ${q.unit}`).join(', ') : '—',
                x.voided ? `VOID oleh ${x.voided.by} (${fmtTs(x.voided.at, true)}): ${x.voided.reason || '—'}` : (x.backfilled ? 'Waktu di-backfill' : ''), x.notes,
            ]);
            return h('div', { 'data-testid': 'soa3-tab-riwayat' }, [
                d.conditions && d.conditions.flag ? h('div', { class: 'rp-banner soa3-gap', 'data-testid': 'soa3-history-condflag' }, `Riwayat temuan tidak memuat kondisi yang sama dengan snapshot final: ${d.conditions.flag}.`) : null,
                simpleTable(['Tim', 'Petugas', 'Aksi', 'Waktu hitung', 'Qty', 'Expired', 'Rusak', 'Deadstock', 'Rincian satuan input', 'Status', 'Catatan'], rows, 'soa3-history-table'),
            ]);
        };
        const evidence = () => {
            if (!d.evidence.length) return h('div', { class: 'rp-empty', 'data-testid': 'soa3-tab-evidence' }, it.model === 'Legacy' ? '— (sesi legacy tidak menyimpan evidence)' : 'Tidak ada evidence');
            const grid = h('div', { class: 'soa3-evgrid', 'data-testid': 'soa3-tab-evidence' });
            d.evidence.forEach((e, i) => {
                grid.appendChild(h('figure', { class: 'soa3-fig', 'data-testid': 'soa3-fig', onclick: () => openGallery(it, i) }, [
                    h('img', { src: e.url, class: 'soa3-fig-img', alt: e.condition, loading: 'lazy' }),
                    h('figcaption', {}, `${e.condition} · tim ${e.team} · ${e.uploaded_by} · ${fmtTs(e.uploaded_at, true)}${e.caption ? ` · ${e.caption}` : ''}${e.voided ? ' · VOID' : ''}`),
                ]));
            });
            return grid;
        };
        const recon = () => {
            const r = d.reconciliation;
            const book = r.book_stock;
            const rec = r.recount;
            return h('div', { 'data-testid': 'soa3-tab-rekon' }, kvGrid([
                ['Model', r.model], ['Status baris', r.line_status], ['Sumber Qty Sistem', r.system_source], ['Sumber Qty Final', r.final_source], ['Sumber Selisih', r.variance_source],
                ['Rumus', r.formula], ['Supervisor', r.supervisor], ['Keputusan final oleh', r.final_decision_by], ['Waktu final', fmtTs(r.final_decision_at, true)], ['Catatan Supervisor', r.note_supervisor],
                ['Recount', rec ? `${qn(rec.qty)} oleh ${rec.by || '—'} (${fmtTs(rec.at, true)}) — ${rec.reason || '—'}` : null],
                ['Stok buku EOD (V1)', book ? qn(book.book_stock_eod) : null], ['Fisik EOD total (V1)', book ? qn(book.final_physical_eod) : null],
            ]));
        };
        const adjustment = () => {
            const rows = d.adjustment.map((a) => [a.reference_no || `ADJ-${a.adjustment_id}`, h('span', { class: tone(a.qty) }, sgnQty(a.qty)), rp(a.hpp), h('span', { class: tone(a.value) }, sgnRp(a.value)), a.reason, a.created_by, fmtTs(a.created_at, true)]);
            return h('div', { 'data-testid': 'soa3-tab-adjustment' }, [
                simpleTable(['Adjustment Ref', 'Qty', 'HPP Adjustment', 'Nilai', 'Alasan', 'Dibuat oleh', 'Timestamp'], rows, 'soa3-adjustment-table'),
                h('div', { class: 'rp-note soa3-gap' }, 'Nilai adjustment = qty × HPP adjustment yang benar-benar diposting (FIFO); dapat berbeda dari Selisih Nilai (qty × HPP snapshot sesi).'),
            ]);
        };
        const audit = () => {
            const rows = d.audit.map((a) => [fmtTs(a.at, true), a.actor, a.action, `${a.entity} #${a.entity_id}`, a.reason, a.after ? JSON.stringify(a.after) : null]);
            return h('div', { 'data-testid': 'soa3-tab-audit' }, simpleTable(['Waktu', 'Pelaku', 'Aksi', 'Entitas', 'Alasan', 'Detail'], rows, 'soa3-audit-table'));
        };
        return [
            { key: 'ringkasan', label: 'Ringkasan', render: (body) => body.appendChild(summary()) },
            { key: 'riwayat', label: 'Riwayat Hitung', render: (body) => body.appendChild(history()) },
            { key: 'evidence', label: `Evidence (${d.evidence.length})`, render: (body) => body.appendChild(evidence()) },
            { key: 'rekon', label: 'Rekonsiliasi', render: (body) => body.appendChild(recon()) },
            { key: 'adjustment', label: `Adjustment (${d.adjustment.length})`, render: (body) => body.appendChild(adjustment()) },
            { key: 'audit', label: `Audit (${d.audit.length})`, render: (body) => body.appendChild(audit()) },
        ];
    }

    // ================================================================== the view
    function createView(container) {
        const S = {
            from: isoShift(-90), to: isoShift(0), warehouse: '', status: '', q: '',
            tab: 'sessions', page: 1, perPage: 25, sessions: null, options: [], selected: null, allMode: false,
            itemQ: '', itemCond: '', itemMatch: '', itemPage: 1, itemPerPage: 50, items: null, cols: null,
            sTicket: 0, iTicket: 0, dTicket: 0, itemColumns: [],
        };
        const R = {};
        const user = typeof Auth !== 'undefined' ? Auth.user() : null;
        const locked = !!(user && user.warehouse_id && ['STOCK', 'ADMIN'].includes(user.role_code));

        // ---- api
        const API = {
            sessions: (p) => ReportTools.apiGet('/reports/opname-audit/sessions', p),
            items: (p) => ReportTools.apiGet('/reports/opname-audit/items', p),
            itemDetail: (p) => ReportTools.apiGet('/reports/opname-audit/item-detail', p),
            exportUrl: (p) => `/api/reports/opname-audit/export${ReportTools.qsOf(p)}`,
            finalExportUrl: (id) => `/api/stock-opname/${id}/export/final`,
        };
        const baseParams = () => ({ date_from: S.from || undefined, date_to: S.to || undefined, warehouse_id: S.warehouse || undefined, status: S.status || undefined, q: S.q || undefined });
        const itemFilterParams = () => ({ item_q: S.itemQ || S.q || undefined, condition: S.itemCond || undefined, match_status: S.itemMatch || undefined });
        /** Params of a Rincian Item request: filters + selected session (+ the row filters of the Rincian tab). */
        const itemParams = (extra, withRowFilters = true) => {
            const p = { ...baseParams(), ...(withRowFilters ? itemFilterParams() : { item_q: S.q || undefined }), ...extra };
            if (S.selected) p.session_ids = String(S.selected.id);
            return p;
        };
        const warehouseLabel = () => { const o = R.wh && R.wh.selectedOptions[0]; return o && S.warehouse ? o.textContent : 'Semua Gudang'; };
        const periodLabel = () => (S.from || S.to ? `${S.from ? fmtDate(S.from) : 'awal'} s/d ${S.to ? fmtDate(S.to) : 'sekarang'}` : 'Semua periode');
        const statusLabel = () => (STATUS_OPTIONS.find((o) => o[0] === S.status) || ['', 'Semua status'])[1];

        const busy = (host, on) => host.classList.toggle('soa3-busy', !!on);
        const showError = (host, msg, retry) => {
            clearNode(host);
            host.appendChild(h('div', { class: 'rp-banner bad', 'data-testid': 'soa3-error' }, [`Gagal memuat laporan: ${msg} `, h('button', { type: 'button', class: 'btn btn-secondary btn-sm', onclick: retry }, 'Coba lagi')]));
        };

        // ------------------------------------------------------------------ skeleton
        function field(cls, label, control) { return h('div', { class: `rp-f ${cls}` }, [h('label', {}, label), control]); }
        function buildFilter() {
            const whOptions = (typeof Master !== 'undefined' ? Master.warehouses() : []).filter((w) => w.is_active !== false);
            R.from = h('input', { type: 'date', 'data-testid': 'soa3-from', value: S.from });
            R.to = h('input', { type: 'date', 'data-testid': 'soa3-to', value: S.to });
            R.wh = h('select', { 'data-testid': 'soa3-wh', disabled: locked }, [h('option', { value: '' }, 'Semua Gudang')].concat(whOptions.map((w) => h('option', { value: String(w.id) }, w.name))));
            R.status = h('select', { 'data-testid': 'soa3-status' }, STATUS_OPTIONS.map(([v, l]) => h('option', { value: v }, l)));
            R.q = h('input', { type: 'text', 'data-testid': 'soa3-q', placeholder: 'No. sesi, SKU, nama barang, petugas, catatan…', autocomplete: 'off' });
            if (locked) { R.wh.value = String(user.warehouse_id); S.warehouse = String(user.warehouse_id); }
            R.apply = h('button', { type: 'button', class: 'btn btn-primary', 'data-testid': 'soa3-apply', onclick: applyFilters }, 'Terapkan');
            R.reset = h('button', { type: 'button', class: 'btn btn-secondary', 'data-testid': 'soa3-reset', onclick: resetFilters }, 'Reset');
            let timer = null;
            R.q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(applyFilters, 380); });
            R.q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(timer); applyFilters(); } });
            return h('div', { class: 'rp-card rp-filter', 'data-testid': 'soa3-filter' }, h('div', { class: 'rp-fgrid soa3-fgrid' }, [
                field('c3', 'Dari Tanggal', R.from), field('c3', 'Sampai Tanggal', R.to), field('c3', 'Gudang', R.wh), field('c3', 'Status', R.status),
                field('c8', 'Pencarian', R.q), h('div', { class: 'rp-factions soa3-fa' }, [R.apply, R.reset]),
            ]));
        }

        function buildSessionsHost() {
            R.sessHead = h('div', { class: 'rp-card-head soa3-head' });
            R.sessBody = h('div', { class: 'soa3-body' });
            R.sessPager = h('div', {});
            return h('div', { 'data-testid': 'soa3-sessions-host' }, [R.sessHead, R.sessBody, R.sessPager]);
        }

        function buildItemsHost() {
            R.ctx = h('div', { class: 'soa3-ctx', 'data-testid': 'soa3-ctx' });
            R.itemQ = h('input', { type: 'text', class: 'rp-input', 'data-testid': 'soa3-item-q', placeholder: 'Cari SKU / barang / petugas / catatan…', autocomplete: 'off' });
            R.itemCond = h('select', { class: 'rp-input', 'data-testid': 'soa3-item-cond', 'aria-label': 'Kondisi' }, COND_OPTIONS.map(([v, l]) => h('option', { value: v }, l)));
            R.itemMatch = h('select', { class: 'rp-input', 'data-testid': 'soa3-item-match', 'aria-label': 'Status baris' }, MATCH_OPTIONS.map(([v, l]) => h('option', { value: v }, l)));
            R.itemPP = h('select', { class: 'rp-input', 'data-testid': 'soa3-item-pp', 'aria-label': 'Baris per halaman' }, ITEM_PER_PAGE.map((n) => h('option', { value: String(n) }, `${n} / halaman`)));
            R.itemPP.value = String(S.itemPerPage);
            let timer = null;
            const reload = () => { S.itemPage = 1; loadItems(); };
            R.itemQ.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { S.itemQ = R.itemQ.value.trim(); reload(); }, 380); });
            R.itemQ.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(timer); S.itemQ = R.itemQ.value.trim(); reload(); } });
            R.itemCond.addEventListener('change', () => { S.itemCond = R.itemCond.value; reload(); });
            R.itemMatch.addEventListener('change', () => { S.itemMatch = R.itemMatch.value; reload(); });
            R.itemPP.addEventListener('change', () => { S.itemPerPage = Number(R.itemPP.value); reload(); });
            R.colBtn = h('button', { type: 'button', class: 'btn btn-secondary rp-btn', 'data-testid': 'soa3-cols', 'aria-haspopup': 'true', disabled: true }, 'Kolom ▾');
            R.colPanel = h('div', { class: 'rp-colpanel soa3-colpanel', 'data-testid': 'soa3-colpanel', hidden: true });
            R.colPanel.addEventListener('click', (e) => e.stopPropagation());
            R.colBtn.addEventListener('click', (e) => { e.stopPropagation(); R.colPanel.hidden = !R.colPanel.hidden; });
            document.addEventListener('click', () => { R.colPanel.hidden = true; });
            R.itemsBody = h('div', { class: 'soa3-body' });
            R.itemsPager = h('div', {});
            return h('div', { 'data-testid': 'soa3-items-host' }, [
                R.ctx,
                h('div', { class: 'soa3-ifilter' }, [h('div', { class: 'soa3-ifq' }, R.itemQ), R.itemCond, R.itemMatch, R.itemPP, h('div', { class: 'rp-colpick' }, [R.colBtn, R.colPanel])]),
                R.itemsBody, R.itemsPager,
            ]);
        }

        function build() {
            clearNode(container);
            container.classList.add('soa3');
            const actions = ReportTools.actions({
                id: 'soa3', print: doPrint, excel: () => doExport('workbook'),
                extras: [['sessions', 'CSV — Ringkasan Sesi'], ['items', 'CSV — Rincian Item'], ['evidence', 'CSV — Evidence'], ['history', 'CSV — Riwayat Hitung'], ['adjustments', 'CSV — Adjustment'], ['audit', 'CSV — Audit Log']]
                    .map(([kind, label]) => ({ label, testid: `soa3-csv-${kind}`, run: () => doExport(kind) })),
            });
            R.banner = h('div', { class: 'soa3-banners', 'data-testid': 'soa3-banner' });
            R.kpis = h('div', { class: 'rp-kpis soa3-k3', 'data-testid': 'soa3-kpis' });
            R.condKpis = h('div', { class: 'rp-kpis dense soa3-k4', 'data-testid': 'soa3-cond-kpis' });
            R.tabSessions = h('button', { type: 'button', class: 'on', role: 'tab', 'aria-selected': 'true', 'data-testid': 'soa3-tab-sessions', onclick: () => setTab('sessions') }, 'Ringkasan Sesi');
            R.tabItems = h('button', { type: 'button', role: 'tab', 'aria-selected': 'false', 'data-testid': 'soa3-tab-items', onclick: () => setTab('items') }, 'Rincian Item');
            R.scope = h('div', { class: 'soa3-scope', 'data-testid': 'soa3-scope' });
            R.sessWrap = buildSessionsHost();
            R.itemsWrap = buildItemsHost();
            R.itemsWrap.hidden = true;
            R.panel = h('div', { class: 'rp-card soa3-panel', 'data-testid': 'soa3-panel' }, [R.sessWrap, R.itemsWrap]);
            container.appendChild(h('div', { class: 'rp-page', 'data-testid': 'soa3-root' }, [
                h('div', { class: 'rp-head' }, [
                    h('div', {}, [h('h2', { class: 'rp-title', 'data-testid': 'soa3-title' }, 'Laporan Stock Opname'),
                        h('p', { class: 'rp-desc' }, 'Audit hasil Stock Opname — qty sistem vs fisik, kondisi, petugas, nilai, evidence, adjustment.')]),
                    actions,
                ]),
                buildFilter(), R.banner,
                h('div', { class: 'soa3-kpirow' }, [R.kpis, R.condKpis]),
                h('div', { class: 'rp-bar' }, [h('div', { class: 'rp-tabs', role: 'tablist' }, [R.tabSessions, R.tabItems]), R.scope]),
                R.panel,
                h('p', { class: 'rp-note soa3-legend' },
                    'Match / Mismatch = kecocokan hitung P1 vs P2 per item; Recount = diselesaikan supervisor. Qty Fisik Final (Total) = Good + Expired + Rusak + Deadstock. Nilai memakai HPP snapshot awal sesi (bukan harga hari ini). '
                    + '"—" = data tidak tersedia / tidak dicatat (bukan nol). Cetak dan Excel mengikuti filter dan sesi yang dipilih.'),
            ]));
        }

        // ------------------------------------------------------------------ filters
        function applyFilters() {
            S.from = R.from.value; S.to = R.to.value; S.warehouse = R.wh.value; S.status = R.status.value; S.q = R.q.value.trim();
            S.page = 1; S.itemPage = 1;
            loadSessions();
        }
        function resetFilters() {
            S.from = isoShift(-90); S.to = isoShift(0); S.warehouse = locked ? S.warehouse : ''; S.status = ''; S.q = '';
            S.itemQ = ''; S.itemCond = ''; S.itemMatch = ''; S.page = 1; S.itemPage = 1; S.selected = null; S.allMode = false;
            R.from.value = S.from; R.to.value = S.to; R.wh.value = S.warehouse; R.status.value = ''; R.q.value = '';
            R.itemQ.value = ''; R.itemCond.value = ''; R.itemMatch.value = '';
            loadSessions();
        }
        function setTab(tab) {
            S.tab = tab;
            [[R.tabSessions, 'sessions'], [R.tabItems, 'items']].forEach(([b, k]) => { b.classList.toggle('on', k === tab); b.setAttribute('aria-selected', k === tab ? 'true' : 'false'); });
            R.sessWrap.hidden = tab !== 'sessions';
            R.itemsWrap.hidden = tab !== 'items';
            renderScope();
            if (tab === 'items') { ensureSelection(); S.itemPage = 1; loadItems(); }
        }
        /** The Rincian Item tab always has a context: the selected session, else the newest session of the filter (the user may still pick "Semua sesi"). */
        function ensureSelection() {
            if (!S.selected && !S.allMode && S.options.length) selectSession(S.options[0], true);
        }
        function selectSession(o, quiet) {
            S.selected = { id: o.id, session_number: o.session_number, warehouse: o.warehouse };
            S.allMode = false; S.itemPage = 1;
            if (!quiet) { renderSessionsTable(); renderScope(); }
        }
        function renderScope() {
            clearNode(R.scope);
            if (S.tab !== 'sessions') return;
            if (S.selected) {
                R.scope.appendChild(h('span', { class: 'soa3-chip', 'data-testid': 'soa3-scope-chip' }, [
                    `Sesi dipilih: ${S.selected.session_number} · ${S.selected.warehouse}`,
                    h('button', { type: 'button', class: 'soa3-chip-x', 'data-testid': 'soa3-scope-clear', title: 'Batalkan pilihan sesi', 'aria-label': 'Batalkan pilihan sesi', onclick: () => { S.selected = null; S.allMode = false; renderSessionsTable(); renderScope(); } }, '✕'),
                ]));
            } else {
                R.scope.appendChild(h('span', { class: 'rp-hint' }, 'Klik baris sesi untuk melihat detail dan memilihnya sebagai konteks Rincian Item, Cetak dan Excel.'));
            }
        }

        // ------------------------------------------------------------------ sessions
        async function loadSessions() {
            const t = ++S.sTicket;                      // a newer load supersedes this one: never paint a stale response
            busy(R.sessWrap, true);
            if (!S.sessions) { clearNode(R.sessBody); R.sessBody.appendChild(h('div', { class: 'rp-loading', 'data-testid': 'soa3-loading' }, 'Memuat sesi Stock Opname…')); }
            let data;
            try {
                data = await API.sessions({ ...baseParams(), page: S.page, per_page: S.perPage });
            } catch (err) {
                if (t !== S.sTicket) return;
                busy(R.sessWrap, false);
                showError(R.sessBody, err.message, loadSessions);
                return;
            }
            if (t !== S.sTicket) return;
            busy(R.sessWrap, false);
            S.sessions = data;
            S.options = data.options || [];
            if (S.selected && !S.options.some((o) => o.id === S.selected.id)) S.selected = null;   // the selected session fell out of the filter
            renderBanner(); renderKpis(); renderSessionsTable(); renderScope();
            if (S.tab === 'items') { ensureSelection(); loadItems(); }
        }

        function renderBanner() {
            clearNode(R.banner);
            const res = S.sessions;
            if (res.truncated) R.banner.appendChild(h('div', { class: 'rp-banner', 'data-testid': 'soa3-truncated' }, 'Ada lebih dari 200 sesi pada filter ini — hanya 200 sesi terbaru yang dihitung. Persempit periode agar KPI dan export lengkap.'));
            const flagged = res.rows.filter((r) => r.cond_flagged > 0);
            if (flagged.length) {
                R.banner.appendChild(h('div', { class: 'rp-banner', 'data-testid': 'soa3-cond-note' },
                    `Catatan data — ${flagged.map((r) => `${r.session_number}: ${num(r.cond_flagged, 0)} item`).join(' · ')}: kondisi (Rusak / Expired / Deadstock) berasal dari snapshot final posting dan tidak ada temuan kondisi setara; ditampilkan apa adanya (tidak ditebak). Lihat tab "Riwayat Hitung" pada detail item.`));
            }
        }

        function kpiCard(key, cls, label, value, sub) {
            return h('div', { class: `rp-kpi ${cls}`, 'data-testid': `soa3-kpi-${key}` }, [
                h('div', { class: 'rp-kpi-l' }, label), h('div', { class: 'rp-kpi-v', 'data-testid': `soa3-kpi-${key}-value` }, value), h('div', { class: 'rp-kpi-s', title: sub }, sub),
            ]);
        }
        const byUnit = (map) => { const e = Object.entries(map || {}); return e.length ? e.map(([u, q]) => `${u} ${num(q, 3)}`).join(' · ') : '—'; };
        function renderKpis() {
            const k = S.sessions.kpi;
            const none = k.sessions === 0;
            const noItems = k.items === 0;
            clearNode(R.kpis);
            const vTone = none || k.variance_value === 0 ? 't-gray' : k.variance_value < 0 ? 't-red' : 't-green';
            [
                kpiCard('sessions', 't-blue', 'Total Sesi', none ? '—' : num(k.sessions, 0), none ? 'tidak ada sesi pada filter ini' : `${num(k.items, 0)} item total`),
                kpiCard('verified', 't-blue', 'Item Diverifikasi', noItems ? '—' : num(k.verified, 0), noItems ? '—' : `Match ${num(k.match, 0)} · Mismatch ${num(k.mismatch, 0)} · Recount ${num(k.recounted, 0)}${k.match_pct === null ? '' : ` · ${num(k.match_pct, 1)}% match`}`),
                kpiCard('variance', vTone, 'Total Selisih Nilai', noItems ? '—' : sgnRp(k.variance_value), noItems ? '—' : `Kurang ${rp(Math.abs(k.variance_negative))} · Lebih ${rp(k.variance_positive)}`),
            ].forEach((n) => R.kpis.appendChild(n));
            clearNode(R.condKpis);
            const c = k.conditions;
            [['good', 'Good', 't-cyan'], ['expired', 'Expired', 't-amber'], ['rusak', 'Rusak', 't-purple'], ['deadstock', 'Deadstock', 't-gray']].forEach(([key, label, cls]) => {
                const x = c[key];
                const unknown = noItems || (x.sku === 0 && x.unknown !== undefined && x.unknown >= k.items);
                R.condKpis.appendChild(kpiCard(key, cls, label, unknown ? '—' : `${num(x.sku, 0)} SKU`, unknown ? (noItems ? '—' : 'tidak dicatat') : byUnit(x.by_unit)));
            });
            ReportTools.decorateKpis(R.kpis); ReportTools.decorateKpis(R.condKpis);
        }

        function sessionCols() {
            return [
                { key: 'session_number', label: 'No. Sesi', cls: 'sticky1', cell: (r) => h('b', {}, r.session_number) },
                { key: 'session_date', label: 'Tanggal', cell: (r) => fmtDate(r.session_date) },
                { key: 'warehouse', label: 'Gudang', cell: (r) => r.warehouse },
                { key: 'status', label: 'Status', cell: (r) => cellBy('status', r.status) },
                { key: 'p1', label: 'P1', cell: (r) => cellBy('people', r.p1) },
                { key: 'p2', label: 'P2', cell: (r) => cellBy('people', r.p2) },
                { key: 'supervisor', label: 'Supervisor', cell: (r) => cellBy('text', r.supervisor) },
                { key: 'total_items', label: 'Total Item', num: true, cell: (r) => num(r.total_items, 0) },
                { key: 'match', label: 'Match', num: true, cell: (r) => num(r.match, 0) },
                { key: 'mismatch', label: 'Mismatch', num: true, cell: (r) => num(r.mismatch, 0) },
                { key: 'recounted', label: 'Recount', num: true, cell: (r) => num(r.recounted, 0) },
                { key: 'variance_value', label: 'Selisih Nilai', num: true, cell: (r) => cellBy('variance_money', r.variance_value) },
                { key: 'adj', label: 'Adjustment +/−', num: true, cell: (r) => (r.adj_count > 0 ? h('span', { title: `${r.adj_count} adjustment · net ${sgnRp(r.adj_value)}` }, [h('span', { class: 'rp-pos' }, sgnRp(r.adj_pos || 0)), ' / ', h('span', { class: 'rp-neg' }, rp(r.adj_neg || 0))]) : h('span', { class: 'rp-dim', title: r.adj_status }, '—')) },
                { key: 'evidence_count', label: 'Evidence', num: true, cell: (r) => (r.evidence_count === null || r.evidence_count === undefined ? h('span', { class: 'rp-dim', title: 'Sesi legacy tidak menyimpan evidence' }, '—') : `${num(r.evidence_count, 0)} foto`) },
                { key: 'finalizer', label: 'Finalized By', cell: (r) => cellBy('text', r.finalizer) },
                { key: 'finalized_at', label: 'Finalized At', cell: (r) => fmtTs(r.finalized_at) },
                { key: 'excluded', label: 'Excluded', num: true, cell: (r) => num(r.excluded, 0) },
                { key: 'created_by', label: 'Dibuat Oleh', cell: (r) => cellBy('text', r.created_by) },
                { key: 'detail', label: 'Aksi', cell: (r) => h('button', { type: 'button', class: 'btn btn-secondary btn-sm soa3-detail-btn', 'data-testid': 'soa3-session-detail', title: 'Buka detail sesi' }, '👁 Detail') },
            ];
        }

        function renderSessionsTable() {
            const res = S.sessions;
            if (!res) return;
            clearNode(R.sessHead);
            R.sessHead.appendChild(h('div', { class: 'rp-card-title' }, ['Ringkasan Sesi ', h('span', { class: 'rp-sub' }, `${num(res.pagination.total, 0)} sesi`)]));
            clearNode(R.sessBody);
            clearNode(R.sessPager);
            if (!res.rows.length) {
                R.sessBody.appendChild(h('div', { class: 'rp-empty', 'data-testid': 'soa3-sessions-empty' }, 'Tidak ada sesi Stock Opname untuk filter ini.'));
                return;
            }
            const cols = sessionCols();
            const head = h('tr', {}, cols.map((c) => h('th', { class: `${c.num ? 'num' : ''}${c.cls ? ` ${c.cls}` : ''}` }, c.label)));
            const body = res.rows.map((r) => {
                const tr = h('tr', { class: `click${S.selected && S.selected.id === r.id ? ' sel' : ''}`, tabindex: '0', 'data-testid': 'soa3-session-row', 'data-session-id': String(r.id) },
                    cols.map((c) => h('td', { class: `${c.num ? 'num' : ''}${c.cls ? ` ${c.cls}` : ''}` }, c.cell(r))));
                const open = () => { selectSession(r); openSessionDrawer(r); };
                tr.addEventListener('click', open);
                tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
                return tr;
            });
            R.sessBody.appendChild(h('div', { class: 'rp-scroll', 'data-testid': 'soa3-sessions-scroll' }, h('table', { class: 'rp-table', 'data-testid': 'soa3-sessions-table' }, [h('thead', {}, head), h('tbody', {}, body)])));
            R.sessPager.appendChild(pager(res.pagination, 'sesi', (p) => { S.page = p; loadSessions(); }, SESSION_PER_PAGE, S.perPage, (n) => { S.perPage = n; S.page = 1; loadSessions(); }, 'soa3-sessions'));
        }

        function pager(p, label, onPage, sizes, size, onSize, testid) {
            const btn = (text, page, disabled, cur) => h('button', { type: 'button', class: `rp-pg${cur ? ' on' : ''}`, disabled: disabled, 'data-page': String(page), onclick: () => onPage(page) }, text);
            const nav = h('div', { class: 'rp-pager-nav' });
            nav.appendChild(btn('‹', p.page - 1, p.page <= 1));
            const shown = new Set([1, p.total_pages, p.page, p.page - 1, p.page + 1].filter((n) => n >= 1 && n <= p.total_pages));
            let prev = 0;
            [...shown].sort((a, b) => a - b).forEach((n) => { if (n - prev > 1) nav.appendChild(h('span', { class: 'rp-dim' }, '…')); nav.appendChild(btn(String(n), n, false, n === p.page)); prev = n; });
            nav.appendChild(btn('›', p.page + 1, p.page >= p.total_pages));
            const left = [h('span', { 'data-testid': `${testid}-count` }, `Total ${num(p.total, 0)} ${label} · halaman ${p.page} / ${p.total_pages}`)];
            if (sizes && onSize) {
                const sel = h('select', { class: 'rp-input soa3-pp', 'data-testid': `${testid}-pp`, 'aria-label': 'Baris per halaman' }, sizes.map((n) => h('option', { value: String(n) }, `${n} / halaman`)));
                sel.value = String(size);
                sel.addEventListener('change', () => onSize(Number(sel.value)));
                left.push(sel);
            }
            return h('div', { class: 'rp-pager', 'data-testid': `${testid}-pager` }, [h('div', { class: 'rp-inline' }, left), nav]);
        }

        // ------------------------------------------------------------------ items
        async function loadItems() {
            const t = ++S.iTicket;
            busy(R.itemsWrap, true);
            if (!S.items) { clearNode(R.itemsBody); R.itemsBody.appendChild(h('div', { class: 'rp-loading', 'data-testid': 'soa3-items-loading' }, 'Memuat rincian item…')); }
            let res;
            try {
                res = await API.items(itemParams({ page: S.itemPage, per_page: S.itemPerPage }));
            } catch (err) {
                if (t !== S.iTicket) return;
                busy(R.itemsWrap, false);
                showError(R.itemsBody, err.message, loadItems);
                return;
            }
            if (t !== S.iTicket) return;
            busy(R.itemsWrap, false);
            S.items = res;
            S.itemColumns = res.columns;
            if (!S.cols) {
                const saved = loadCols();
                const valid = new Set(res.columns.map((c) => c.key));
                const keep = saved ? saved.filter((k) => valid.has(k)) : [];
                S.cols = keep.length ? keep : ITEM_DEFAULT.slice();
            }
            buildColPanel();
            renderContext();
            renderItemsTable();
        }

        function buildColPanel() {
            if (!S.itemColumns.length) return;
            R.colBtn.disabled = false;
            clearNode(R.colPanel);
            const setCols = (keys) => { S.cols = keys; saveCols(keys); buildColPanel(); renderItemsTable(); };
            R.colPanel.appendChild(h('div', { class: 'soa3-colhead' }, [
                h('b', { 'data-testid': 'soa3-cols-count' }, `${S.cols.length} / ${S.itemColumns.length} kolom`),
                h('span', {}, [
                    h('button', { type: 'button', class: 'soa3-link', 'data-testid': 'soa3-cols-reset', onclick: () => setCols(ITEM_DEFAULT.slice()) }, 'Bawaan'), ' · ',
                    h('button', { type: 'button', class: 'soa3-link', 'data-testid': 'soa3-cols-all', onclick: () => setCols(S.itemColumns.map((c) => c.key)) }, 'Semua'),
                ]),
            ]));
            const grid = h('div', { class: 'soa3-colgrid' });
            S.itemColumns.forEach((c) => {
                const on = S.cols.includes(c.key);
                const cb = h('input', { type: 'checkbox', 'data-testid': `soa3-col-${c.key}`, checked: on, disabled: on && S.cols.length === 1 });
                cb.addEventListener('change', () => setCols(cb.checked ? S.itemColumns.map((x) => x.key).filter((k) => k === c.key || S.cols.includes(k)) : S.cols.filter((k) => k !== c.key)));
                grid.appendChild(h('label', {}, [cb, ` ${c.label}`]));
            });
            R.colPanel.appendChild(grid);
        }

        function renderContext() {
            clearNode(R.ctx);
            const res = S.items;
            const s = S.selected && res.sessions ? res.sessions.find((x) => x.id === S.selected.id) : null;
            const sel = h('select', { class: 'rp-input soa3-ctxsel', 'data-testid': 'soa3-session-select', 'aria-label': 'Sesi' }, [h('option', { value: '' }, `Semua sesi sesuai filter (${num(S.options.length, 0)})`)]
                .concat(S.options.map((o) => h('option', { value: String(o.id) }, `${o.session_number} · ${fmtDate(o.session_date)} · ${o.warehouse}`))));
            sel.value = S.selected ? String(S.selected.id) : '';
            sel.addEventListener('change', () => {
                const o = S.options.find((x) => String(x.id) === sel.value);
                if (o) selectSession(o, true); else { S.selected = null; S.allMode = true; }
                S.itemPage = 1; renderSessionsTable(); loadItems();
            });
            R.ctx.appendChild(h('label', { class: 'soa3-ctxlab' }, 'Sesi'));
            R.ctx.appendChild(sel);
            if (s) {
                const fact = (k, v) => h('span', { class: 'soa3-fact' }, [h('i', {}, `${k} `), v]);
                R.ctx.appendChild(h('div', { class: 'soa3-facts', 'data-testid': 'soa3-ctx-facts' }, [
                    cellBy('status', s.status), fact('Supervisor', s.supervisor || '—'), fact('P1', names(s.p1) || '—'), fact('P2', names(s.p2) || '—'),
                    fact('Finalizer', s.finalizer || '—'), fact('Item', num(s.total_items, 0)), fact('Selisih', h('span', { class: tone(s.variance_value) }, sgnRp(s.variance_value))),
                ]));
                R.ctx.appendChild(h('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'soa3-ctx-detail', onclick: () => openSessionDrawer(s) }, 'Detail sesi'));
            } else {
                R.ctx.appendChild(h('div', { class: 'soa3-facts rp-hint' }, `Menampilkan item dari ${num(S.options.length, 0)} sesi sesuai filter.${S.q && !S.itemQ ? ` Pencarian aktif: "${S.q}".` : ''}`));
            }
        }

        // on screen the physical count reads left to right: system qty → final physical → difference → condition split → HPP → value difference (the export keeps the service catalogue order)
        const ITEM_ORDER = ['no', 'session_number', 'session_date', 'warehouse', 'sku', 'name', 'category', 'unit', 'system_qty', 'final_qty', 'variance_qty', 'good_qty', 'expired_qty', 'rusak_qty', 'deadstock_qty', 'hpp', 'system_value', 'final_value', 'variance_value'];
        const orderRank = (k) => { const i = ITEM_ORDER.indexOf(k); return i < 0 ? 100 : i; };
        function visibleItemCols() {
            const cols = S.itemColumns.filter((c) => S.cols.includes(c.key)).map((c, i) => [c, i]).sort((a, b) => (orderRank(a[0].key) - orderRank(b[0].key)) || (a[1] - b[1])).map((x) => x[0]);
            if (!S.selected && !cols.some((c) => c.key === 'session_number')) { const sc = S.itemColumns.find((c) => c.key === 'session_number'); if (sc) cols.unshift(sc); }
            return cols;
        }

        function renderItemsTable() {
            const res = S.items;
            clearNode(R.itemsBody);
            clearNode(R.itemsPager);
            if (!res.rows.length) {
                R.itemsBody.appendChild(h('div', { class: 'rp-empty', 'data-testid': 'soa3-items-empty' }, 'Tidak ada item untuk filter ini.'));
                return;
            }
            const cols = visibleItemCols();
            const unitHidden = !cols.some((c) => c.key === 'unit');
            const cellFor = (c, r) => (c.key === 'name' && unitHidden ? h('span', {}, [r.name, h('span', { class: 'rp-dim soa3-nowrap' }, ` · ${r.unit}`)]) : cellBy(c.type, r[c.key], r));
            const tdClass = (c, i) => `${NUMERIC.has(c.type) ? 'num' : ''}${i === 0 ? ' sticky1' : ''}${c.key === 'name' ? ' wrap soa3-name' : ''}`.trim();
            const head = h('tr', {}, cols.map((c, i) => h('th', { class: tdClass(c, i), 'data-col': c.key, title: c.label }, SHORT[c.key] || c.label)));
            const body = res.rows.map((r) => {
                const tr = h('tr', { class: 'click', tabindex: '0', 'data-testid': 'soa3-item-row', 'data-line-id': String(r.line_id), 'data-sku': r.sku },
                    cols.map((c, i) => h('td', { class: tdClass(c, i), 'data-col': c.key }, cellFor(c, r))));
                const open = (e) => { if (e.target.closest('button, img')) return; openItemDrawer(r); };
                tr.addEventListener('click', open);
                tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(e); });
                return tr;
            });
            const m = res.footer.money;
            const sums = { system_value: m.system_value, final_value: m.final_value, variance_value: m.variance_value, adj_value: m.adj_value };
            const foot = h('tr', { 'data-testid': 'soa3-items-total' }, cols.map((c, i) => {
                let content = i === 0 ? `TOTAL ${num(res.footer.count, 0)} item` : '';
                if (Object.prototype.hasOwnProperty.call(sums, c.key)) content = c.type === 'variance_money' ? h('span', { class: tone(sums[c.key]) }, sgnRp(sums[c.key])) : rp(sums[c.key]);
                return h('td', { class: tdClass(c, i), 'data-col': c.key }, content);
            }));
            R.itemsBody.appendChild(h('div', { class: 'rp-scroll', 'data-testid': 'soa3-items-scroll' }, h('table', { class: 'rp-table', 'data-testid': 'soa3-items-table' }, [h('thead', {}, head), h('tbody', {}, body), h('tfoot', {}, foot)])));
            const units = Object.entries(res.footer.qty_by_unit).map(([u, q]) => `${u}: sistem ${num(q.system_qty, 3)} · fisik ${num(q.final_qty, 3)} · good ${num(q.good_qty, 3)} · expired ${num(q.expired_qty, 3)} · rusak ${num(q.rusak_qty, 3)} · deadstock ${num(q.deadstock_qty, 3)} · selisih ${sgnQty(q.variance_qty)}`);
            if (units.length) R.itemsPager.appendChild(h('div', { class: 'rp-note soa3-units', 'data-testid': 'soa3-items-units' }, `Qty per satuan (tidak dijumlahkan lintas satuan; "—" tidak dihitung) — ${units.join('  |  ')}`));
            R.itemsPager.appendChild(pager(res.pagination, 'item', (p) => { S.itemPage = p; loadItems(); }, null, null, null, 'soa3-items'));
        }

        // ------------------------------------------------------------------ drawers
        function openSessionDrawer(r) {
            const cols = (S.sessions && S.sessions.columns) || [];
            const fields = cols.filter((c) => c.key !== 'variance_by_unit').map((c) => [c.label, cellBy(c.type, r[c.key])]);
            fields.push(['Adjustment Positif / Negatif', r.adj_count > 0 ? h('span', {}, [h('span', { class: 'rp-pos' }, sgnRp(r.adj_pos || 0)), ' / ', h('span', { class: 'rp-neg' }, rp(r.adj_neg || 0))]) : null],
                ['Selisih Qty (per satuan)', r.variance_by_unit], ['Evidence (foto)', r.evidence_count === null || r.evidence_count === undefined ? null : `${num(r.evidence_count, 0)} foto`]);
            const actions = [
                h('button', { type: 'button', class: 'btn btn-primary btn-sm', 'data-testid': 'soa3-drawer-rincian', onclick: () => { Drawer.close(); selectSession(r, true); setTab('items'); renderSessionsTable(); } }, 'Buka Rincian Item'),
                h('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'soa3-drawer-jejak', onclick: () => {
                    if (typeof StockOpnameJejak === 'undefined') return;
                    const panel = document.querySelector('.drawer');
                    if (panel) panel.classList.remove('rp-drawer', 'soa3-drawer');
                    StockOpnameJejak.open({ id: r.id, session_number: r.session_number, status: r.status });
                } }, 'Lihat Jejak'),
            ];
            if (r.status === 'POSTED' && typeof Auth !== 'undefined' && (Auth.hasPermission('STOCK_OPNAME_MANAGE') || Auth.hasPermission('STOCK_OPNAME_SUPERVISE'))) {
                actions.push(h('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'data-testid': 'soa3-drawer-final', onclick: () => ReportTools.download(API.finalExportUrl(r.id), 'stock-opname-final.xlsx').catch((e) => UI.toast(e.message, 'error', 6000)) }, 'Excel Final'));
            }
            wideDrawer(`${r.session_number} — ${r.warehouse}`, {
                render: (body) => {
                    body.appendChild(h('div', { class: 'soa3-dhead', 'data-testid': 'soa3-session-drawer' }, [h('div', { class: 'soa3-pills' }, [cellBy('status', r.status), pill(r.model, 'info'), pill(r.adj_status, '')]), h('div', { class: 'soa3-dact' }, actions)]));
                    body.appendChild(kvGrid(fields, 'soa3-session-fields'));
                    if (r.data_quality && r.data_quality.length) body.appendChild(h('div', { class: 'soa3-dq soa3-gap', 'data-testid': 'soa3-session-dq' }, [h('b', {}, 'Catatan kualitas data'), h('ul', {}, r.data_quality.map((x) => h('li', {}, x)))]));
                },
            });
        }

        async function openItemDrawer(row) {
            const t = ++S.dTicket;
            wideDrawer(`${row.sku} — ${row.name}`, { render: (body) => { body.appendChild(h('div', { class: 'rp-loading', 'data-testid': 'soa3-drawer-loading' }, 'Memuat detail item…')); } });
            let d;
            try {
                d = await API.itemDetail({ session_id: row.session_id, line_id: row.line_id });
            } catch (err) {
                if (t !== S.dTicket) return;
                const body = document.querySelector('.drawer .drawer-body');
                if (body) { clearNode(body); body.appendChild(h('div', { class: 'rp-banner bad', 'data-testid': 'soa3-drawer-error' }, `Gagal memuat detail: ${err.message}`)); }
                return;
            }
            if (t !== S.dTicket) return;
            const it = d.item;
            wideDrawer(`${it.sku} — ${it.name}`, { tabs: itemDrawerTabs(d, S.itemColumns.length ? S.itemColumns : (S.items ? S.items.columns : [])) });
            const panel = document.querySelector('.drawer');
            const tabs = panel && panel.querySelector('.drawer-tabs');
            if (tabs) panel.insertBefore(h('div', { class: 'soa3-dsub', 'data-testid': 'soa3-drawer-sub' }, `${it.session_number} · ${it.warehouse} · ${fmtDate(it.session_date)} · ${it.model} · ${it.line_status}`), tabs);
        }

        // ------------------------------------------------------------------ export / print
        function exportParams(kind) {
            // the Rincian tab's row filters apply only while that tab is in front (what you see is what you export); session_ids = the selected session
            return { ...itemParams({}, S.tab === 'items'), kind, page: undefined, per_page: undefined };
        }
        async function doExport(kind) {
            const r = await ReportTools.download(API.exportUrl(exportParams(kind)), kind === 'workbook' ? 'Laporan_Stock_Opname.xlsx' : `laporan-stock-opname-${kind}.csv`);
            UI.toast(`Terunduh: ${r.name}`, 'success', 3000);
        }

        async function fetchAllSessions() {
            const rows = [];
            for (let page = 1; ; page++) {
                const res = await API.sessions({ ...baseParams(), page, per_page: 100 });
                rows.push(...res.rows);
                if (page >= res.pagination.total_pages) return { rows, kpi: res.kpi };
            }
        }
        async function fetchAllItems() {
            const rows = [];
            let first = null;
            for (let page = 1; ; page++) {
                const res = await API.items(itemParams({ page, per_page: 200 }, S.tab === 'items'));
                if (!first) first = res;
                rows.push(...res.rows);
                if (page >= res.pagination.total_pages) return { rows, first };
            }
        }
        const pStr = (v) => (nil(v) ? '—' : names(v));
        async function doPrint() {
            const sessCols = [
                { label: 'No. Sesi', type: 'text', get: (r) => r.session_number }, { label: 'Tanggal', type: 'date', get: (r) => r.session_date }, { label: 'Gudang', type: 'text', get: (r) => r.warehouse },
                { label: 'Status', type: 'text', get: (r) => r.status }, { label: 'P1', type: 'text', get: (r) => pStr(r.p1) }, { label: 'P2', type: 'text', get: (r) => pStr(r.p2) },
                { label: 'Supervisor', type: 'text', get: (r) => r.supervisor }, { label: 'Finalizer', type: 'text', get: (r) => r.finalizer },
                { label: 'Item', type: 'int', get: (r) => r.total_items }, { label: 'Match', type: 'int', get: (r) => r.match }, { label: 'Mismatch', type: 'int', get: (r) => r.mismatch }, { label: 'Recount', type: 'int', get: (r) => r.recounted },
                { label: 'Selisih Nilai', type: 'money', get: (r) => (nil(r.variance_value) ? null : sgnRp(r.variance_value)) }, { label: 'Adj. Nilai', type: 'money', get: (r) => (nil(r.adj_value) ? null : sgnRp(r.adj_value)) },
            ];
            const itemCols = [
                { label: 'SKU', type: 'text', get: (r) => r.sku }, { label: 'Barang', type: 'text', get: (r) => r.name }, { label: 'Sat.', type: 'text', get: (r) => r.unit },
                { label: 'Qty Sistem', type: 'qty', get: (r) => r.system_qty }, { label: 'Qty Fisik', type: 'qty', get: (r) => r.final_qty }, { label: 'Good', type: 'qty', get: (r) => r.good_qty },
                { label: 'Expired', type: 'qty', get: (r) => r.expired_qty }, { label: 'Rusak', type: 'qty', get: (r) => r.rusak_qty }, { label: 'Deadstock', type: 'qty', get: (r) => r.deadstock_qty },
                { label: 'Selisih Qty', type: 'qty', get: (r) => (nil(r.variance_qty) ? null : sgnQty(r.variance_qty)) }, { label: 'Selisih Nilai', type: 'money', get: (r) => (nil(r.variance_value) ? null : sgnRp(r.variance_value)) },
                { label: 'P1', type: 'text', get: (r) => pStr(r.p_hitung) }, { label: 'P2', type: 'text', get: (r) => pStr(r.p_verifikasi) }, { label: 'Status', type: 'text', get: (r) => r.line_status },
            ];
            const toSection = (title, note, cols, rows, totalRow) => ({ title, note, columns: cols.map((c) => ({ label: c.label, type: c.type })), rows: rows.map((r) => cols.map((c) => c.get(r))), totalRow });
            const itemFilters = S.tab === 'items' ? [S.itemQ || S.q ? `cari "${S.itemQ || S.q}"` : '', S.itemCond ? `kondisi: ${(COND_OPTIONS.find((o) => o[0] === S.itemCond) || [])[1]}` : '', S.itemMatch ? `status baris: ${(MATCH_OPTIONS.find((o) => o[0] === S.itemMatch) || [])[1]}` : ''].filter(Boolean).join(', ') : (S.q ? `cari "${S.q}"` : '');
            const meta = [['Periode', periodLabel()], ['Gudang', warehouseLabel()], ['Status sesi', statusLabel()], ['Pencarian', S.q], ['Filter item', itemFilters]];
            let spec;
            if (S.selected) {
                const { rows, first } = await fetchAllItems();
                const sess = (first.sessions || []).find((x) => x.id === S.selected.id) || null;
                meta.push(['Sesi', `${S.selected.session_number} — ${S.selected.warehouse}`]);
                const f = first.footer;
                const totalRow = itemCols.map((c, i) => (i === 0 ? `TOTAL ${num(f.count, 0)} item` : c.label === 'Selisih Nilai' ? sgnRp(f.money.variance_value) : null));
                spec = {
                    title: 'Laporan Stock Opname', subtitle: `Audit sesi ${S.selected.session_number} — ${S.selected.warehouse}`, meta, orientation: 'landscape',
                    kpis: sess ? [
                        { label: 'Total Item', value: num(sess.total_items, 0), sub: `Match ${num(sess.match, 0)} · Mismatch ${num(sess.mismatch, 0)} · Recount ${num(sess.recounted, 0)}` },
                        { label: 'Total Selisih Nilai', value: sgnRp(sess.variance_value), sub: `Qty: ${sess.variance_by_unit || '—'}` },
                        { label: 'Nilai Adjustment', value: nil(sess.adj_value) ? '—' : sgnRp(sess.adj_value), sub: sess.adj_status },
                    ] : [],
                    sections: [
                        toSection('Ringkasan Sesi', null, sessCols, sess ? [sess] : []),
                        toSection(`Rincian Item — ${S.selected.session_number}`, 'Kolom utama; seluruh kolom audit (waktu, catatan, evidence, adjustment, dll.) tersedia di Download Excel.', itemCols, rows, totalRow),
                    ],
                };
            } else {
                const all = await fetchAllSessions();
                const k = all.kpi;
                spec = {
                    title: 'Laporan Stock Opname', subtitle: 'Ringkasan Sesi sesuai filter — tidak ada sesi yang dipilih, sehingga Rincian Item tidak dicetak (pilih satu sesi di layar untuk mencetak juga Rincian Item-nya).', meta, orientation: 'landscape',
                    kpis: [
                        { label: 'Total Sesi', value: k.sessions === 0 ? '—' : num(k.sessions, 0), sub: k.items === 0 ? '' : `${num(k.items, 0)} item total` },
                        { label: 'Item Diverifikasi', value: k.items === 0 ? '—' : num(k.verified, 0), sub: k.items === 0 ? '' : `Match ${num(k.match, 0)} · Mismatch ${num(k.mismatch, 0)} · Recount ${num(k.recounted, 0)}` },
                        { label: 'Total Selisih Nilai', value: k.items === 0 ? '—' : sgnRp(k.variance_value), sub: k.items === 0 ? '' : `Kurang ${rp(Math.abs(k.variance_negative))} · Lebih ${rp(k.variance_positive)}` },
                    ],
                    sections: [toSection('Ringkasan Sesi', 'Sesuai filter di atas.', sessCols, all.rows)],
                };
            }
            ReportTools.printDocument(spec);
        }

        // ------------------------------------------------------------------ go
        build();
        loadSessions();
    }

    function render(container) {
        if (!container) return;
        if (typeof ReportTools === 'undefined') {
            container.innerHTML = '<div class="rp-banner bad">Modul laporan (report-tools.js) belum dimuat. Muat ulang halaman.</div>';
            return;
        }
        createView(container);
    }

    return { render };
})();
