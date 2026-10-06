/**
 * Reports v3 — shared tools for the five reports: "Cetak" (print) and "Download Excel".
 *
 * READ-ONLY. Both actions fetch the SAME server-built tables the Excel file is made of (GET …?format=json / GET …?format=xlsx), so Cetak, Excel and the screen filters can never disagree.
 *   - download(url): fetches the workbook as a blob and saves it under the file name the server chose (Content-Disposition), e.g. Laporan_Pembelian_2026-10.xlsx.
 *   - printDocument(spec): builds a dedicated, print-friendly document (white background, dark text, bordered tables, repeating header row, landscape for wide tables, no sidebar / buttons /
 *     filters) in a hidden iframe and opens the browser print dialog. The application chrome is never printed because only this document is.
 *   - actions(opts): the two header buttons every report shows at the top right (+ optional extra downloads in a small menu).
 * Unknown values are printed as "—", never 0; quantities of different units are never added (that is decided by the report, not here).
 */
const ReportTools = (() => {
    const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nil = (v) => v === null || v === undefined || v === '';

    // ------------------------------------------------------------------ GET helper (self-contained, same-origin, session cookie)
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

    // ------------------------------------------------------------------ download
    function fileNameFrom(res, fallback) {
        const cd = res.headers.get('Content-Disposition') || '';
        const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
        return m ? decodeURIComponent(m[1]) : fallback;
    }
    /** Fetches a GET export as a blob and saves it (named by the server). Throws a readable Error on failure. */
    async function download(url, fallbackName) {
        let res;
        try {
            res = await fetch(url, { method: 'GET', credentials: 'include' });
        } catch (e) {
            throw new Error('Sistem sedang tidak dapat terhubung ke server.');
        }
        if (!res.ok) {
            let msg = `Download gagal (${res.status})`;
            try { const j = await res.json(); if (j && j.error && j.error.message) msg = j.error.message; } catch (e) { /* not json */ }
            throw new Error(msg);
        }
        const blob = await res.blob();
        const name = fileNameFrom(res, fallbackName || 'laporan.xlsx');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 800);
        return { name, size: blob.size, type: blob.type };
    }

    // ------------------------------------------------------------------ print document
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const num = (v, max) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: max });
    function fmtDate(v) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v));
        return m ? `${m[3]} ${MONTHS[Number(m[2]) - 1]} ${m[1]}` : String(v);
    }
    function fmtCell(v, type) {
        if (nil(v)) return '—';
        if (typeof v === 'number') {
            if (type === 'money') return `Rp ${num(v, 2)}`;
            if (type === 'pct') return `${num(v, 2)}%`;
            if (type === 'int') return num(v, 0);
            return num(v, 3);
        }
        const s = String(v);
        if (type === 'date' && /^\d{4}-\d{2}-\d{2}/.test(s)) return fmtDate(s);
        if (type === 'ts' && /^\d{4}-\d{2}-\d{2}/.test(s)) return `${fmtDate(s)} ${s.slice(11, 16)}`;
        return s;
    }
    const RIGHT = new Set(['money', 'qty', 'int', 'pct']);

    /**
     * spec = { title, subtitle, meta:[[label,value]], kpis:[{label,value,sub}], orientation:'landscape'|'portrait'|undefined,
     *          sections:[{ title, note, columns:[{label,type}], rows:[[…]], totalRow?:[…], maxRows? }], footnote }
     */
    function buildPrintHtml(spec) {
        const printedAt = new Date().toLocaleString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        const widest = Math.max(1, ...(spec.sections || []).map((s) => s.columns.length));
        const orientation = spec.orientation || (widest <= 6 ? 'portrait' : 'landscape');
        const dense = widest > 14 ? 'dense2' : widest > 9 ? 'dense1' : '';
        const kpis = (spec.kpis || []).length
            ? `<div class="kpis">${spec.kpis.map((k) => `<div class="kpi"><div class="kl">${esc(k.label)}</div><div class="kv">${esc(k.value)}</div>${k.sub ? `<div class="ks">${esc(k.sub)}</div>` : ''}</div>`).join('')}</div>` : '';
        const meta = (spec.meta || []).filter((m) => !nil(m[1]) && String(m[1]).trim() !== '')
            .map(([k, v]) => `<span class="mi"><b>${esc(k)}:</b> ${esc(v)}</span>`).join('');
        const sections = (spec.sections || []).map((s) => {
            const cap = s.maxRows && s.rows.length > s.maxRows ? s.maxRows : null;
            const rows = cap ? s.rows.slice(0, cap) : s.rows;
            const head = `<tr>${s.columns.map((c) => `<th class="${RIGHT.has(c.type) ? 'r' : ''}">${esc(c.label)}</th>`).join('')}</tr>`;
            const body = rows.map((r) => `<tr>${s.columns.map((c, i) => `<td class="${RIGHT.has(c.type) ? 'r' : ''}">${esc(fmtCell(r[i], c.type))}</td>`).join('')}</tr>`).join('');
            const total = s.totalRow ? `<tr class="tot">${s.columns.map((c, i) => `<td class="${RIGHT.has(c.type) ? 'r' : ''}">${esc(nil(s.totalRow[i]) ? '' : fmtCell(s.totalRow[i], c.type))}</td>`).join('')}</tr>` : '';
            return `<section><h2>${esc(s.title)}</h2>${s.note ? `<p class="note">${esc(s.note)}</p>` : ''}${rows.length
                ? `<table class="${dense}"><thead>${head}</thead><tbody>${body}${total}</tbody></table>` : '<p class="empty">Tidak ada data pada filter ini.</p>'}${cap ? `<p class="note">Menampilkan ${num(cap, 0)} dari ${num(s.rows.length, 0)} baris — daftar lengkap ada di Download Excel.</p>` : ''}</section>`;
        }).join('');
        return `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${esc(spec.title)}</title><style>
@page { size: A4 ${orientation}; margin: 10mm 9mm 12mm; }
* { box-sizing: border-box; }
html, body { background: #fff !important; color: #111 !important; margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
h1 { font-size: 16pt; margin: 0 0 2px; } .sub { font-size: 10pt; color: #333; margin: 0 0 6px; }
.meta { display: flex; flex-wrap: wrap; gap: 2px 18px; font-size: 9pt; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 4px 0; margin: 6px 0 8px; } .meta .mi b { color: #000; }
.kpis { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; } .kpi { border: 1px solid #444; padding: 4px 8px; min-width: 120px; flex: 1 1 120px; }
.kl { font-size: 8pt; color: #333; text-transform: uppercase; letter-spacing: .02em; } .kv { font-size: 12pt; font-weight: 700; } .ks { font-size: 8pt; color: #333; }
section { margin: 0 0 12px; } h2 { font-size: 11.5pt; margin: 10px 0 4px; } .note { font-size: 8.5pt; color: #333; margin: 2px 0 4px; } .empty { font-size: 9pt; font-style: italic; }
table { width: 100%; border-collapse: collapse; table-layout: auto; } th, td { border: 1px solid #555; padding: 2.5px 5px; vertical-align: top; font-size: 8.5pt; word-break: break-word; }
th { background: #e6e9ef !important; font-weight: 700; text-align: left; } td.r, th.r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
thead { display: table-header-group; } tfoot { display: table-footer-group; } tr { page-break-inside: avoid; break-inside: avoid; }
tr.tot td { font-weight: 700; background: #f1f3f7 !important; border-top: 2px solid #222; }
table.dense1 th, table.dense1 td { font-size: 7.5pt; padding: 2px 3px; } table.dense2 th, table.dense2 td { font-size: 6.5pt; padding: 1.5px 2px; }
.foot { margin-top: 10px; font-size: 8pt; color: #444; border-top: 1px solid #999; padding-top: 4px; }
</style></head><body>
<h1>${esc(spec.title)}</h1>${spec.subtitle ? `<div class="sub">${esc(spec.subtitle)}</div>` : ''}
<div class="meta">${meta}<span class="mi"><b>Tanggal cetak:</b> ${esc(printedAt)}</span></div>${kpis}${sections}
<div class="foot">${esc(spec.footnote || 'Inventory Pro — laporan read-only. Angka bersumber dari data sistem; "—" = tidak tersedia (bukan nol). Kuantitas berbeda satuan tidak dijumlahkan.')}</div>
</body></html>`;
    }

    let lastPrintHtml = null;
    /** Opens the browser print dialog for the spec (a hidden iframe: nothing of the application page is printed). */
    function printDocument(spec) {
        const html = buildPrintHtml(spec);
        lastPrintHtml = html;
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('data-testid', 'rp-print-frame');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
        document.body.appendChild(frame);
        const cleanup = () => setTimeout(() => frame.remove(), 400);
        const doc = frame.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
        const go = () => {
            try { frame.contentWindow.focus(); frame.contentWindow.addEventListener('afterprint', cleanup); frame.contentWindow.print(); } catch (e) { cleanup(); throw e; }
            setTimeout(cleanup, 60000);
        };
        if (doc.readyState === 'complete') setTimeout(go, 50); else frame.addEventListener('load', () => setTimeout(go, 50));
        return html;
    }

    /**
     * Build a print spec from a server payload ({ title, meta, sheets:[{name,headers,types,rows}] }).
     * pick = [{ sheet:'Harian', columns?:['Tanggal',…] (labels to keep, in this order), title?, note?, maxRows?, total?:true }]
     * The sheet's own TOTAL row (first cell "TOTAL…") is lifted into the table footer.
     */
    function specFromPayload(payload, pick, extra) {
        const sections = [];
        (pick || []).forEach((p) => {
            const sh = (payload.sheets || []).find((s) => s.name === p.sheet);
            if (!sh) return;
            const idx = p.columns ? p.columns.map((l) => sh.headers.indexOf(l)).filter((i) => i >= 0) : sh.headers.map((_, i) => i);
            const cols = idx.map((i) => ({ label: sh.headers[i], type: sh.types[i] }));
            let rows = sh.rows.map((r) => idx.map((i) => r[i]));
            let totalRow = null;
            if (rows.length && /^(GRAND\s+)?TOTAL\b/i.test(String(sh.rows[sh.rows.length - 1][0] ?? ''))) {
                totalRow = rows[rows.length - 1];
                rows = rows.slice(0, -1);
                if (p.columns && idx[0] !== 0) totalRow = totalRow.map((v, i) => (i === 0 && nil(v) ? 'TOTAL' : v));
            }
            sections.push({ title: p.title || sh.name, note: p.note, columns: cols, rows, totalRow, maxRows: p.maxRows });
        });
        return { title: payload.title, meta: payload.meta || [], sections, ...(extra || {}) };
    }

    // ------------------------------------------------------------------ the two header buttons
    /**
     * opts = { id:'prefix for data-testid', print: async () => void, excel: async () => void, extras:[{label, testid, run: async () => void}] }
     * Each action shows "Menyiapkan…" while it runs and reports errors with a toast; a button never silently does nothing.
     */
    function actions(opts) {
        const id = opts.id || 'rp';
        const wrap = document.createElement('div');
        wrap.className = 'rp-actions';
        wrap.setAttribute('data-testid', `${id}-actions`);
        const mk = (cls, testid, label) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = cls;
            b.setAttribute('data-testid', testid);
            b.textContent = label;
            return b;
        };
        const run = async (btn, label, fn) => {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.textContent = 'Menyiapkan…';
            try { await fn(); } catch (e) { UI.toast(e.message || 'Aksi gagal', 'error', 6000); } finally { btn.disabled = false; btn.textContent = label; }
        };
        const printBtn = mk('btn btn-secondary rp-btn', `${id}-print`, '🖨 Cetak');
        printBtn.addEventListener('click', () => run(printBtn, '🖨 Cetak', opts.print));
        const excelBtn = mk('btn btn-success rp-btn', `${id}-excel`, '⬇ Download Excel');
        excelBtn.addEventListener('click', () => run(excelBtn, '⬇ Download Excel', opts.excel));
        wrap.appendChild(printBtn);
        if (opts.extras && opts.extras.length) {
            const group = document.createElement('div');
            group.className = 'rp-split';
            const caret = mk('btn btn-success rp-btn rp-caret', `${id}-more`, '▾');
            caret.setAttribute('aria-haspopup', 'true');
            caret.setAttribute('title', 'Unduhan lain');
            const menu = document.createElement('div');
            menu.className = 'rp-menu';
            menu.hidden = true;
            opts.extras.forEach((x) => {
                const item = mk('rp-menu-item', x.testid || `${id}-extra`, x.label);
                item.addEventListener('click', () => { menu.hidden = true; run(item, x.label, x.run); });
                menu.appendChild(item);
            });
            caret.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; });
            document.addEventListener('click', () => { menu.hidden = true; });
            group.appendChild(excelBtn);
            group.appendChild(caret);
            group.appendChild(menu);
            wrap.appendChild(group);
        } else {
            wrap.appendChild(excelBtn);
        }
        return wrap;
    }

    return { apiGet, qsOf, download, printDocument, buildPrintHtml, specFromPayload, fmtCell, actions, get lastPrintHtml() { return lastPrintHtml; } };
})();
