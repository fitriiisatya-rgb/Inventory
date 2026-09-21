/**
 * PHASE V2.6B — shared compact-report page builder reused by every
 * remaining Reporting Pack page (Laporan Stok/Pembelian/IN-OUT/Transfer/
 * Stock Opname/Adjustment/Expiry/Supplier/Bakery/Slow Movement/Audit).
 * Keeps every page visually and structurally consistent (compact filter
 * toolbar, optional summary cards, wide DataTable, loading/empty/error
 * states already handled by DataTable.render) without duplicating that
 * plumbing 11 times. Each page only supplies its own filter fields,
 * columns, and fetch function — never its own table/pagination logic.
 */
const ReportCommon = (() => {
    /**
     * @param {HTMLElement} container
     * @param {{
     *   title: string, subtitle: string,
     *   filters: Array<{key:string, label:string, type:'date'|'select'|'text', options?:Array<{value,label}>}>,
     *   columns: Array<object>,
     *   fetchPage: (state) => Promise<{rows, pagination}>,
     *   loadSummary?: (state) => Promise<HTMLElement|HTMLElement[]|null>,
     *   onRowClick?: (row) => void,
     *   exportUrl?: (state) => string,
     *   storageKey: string,
     *   defaultFilters?: object,
     * }} opts
     */
    function render(container, opts) {
        const state = Object.assign({}, opts.defaultFilters || {});
        container.innerHTML = '';

        container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, opts.title),
                UI.el('div', { class: 'hpp-subtitle' }, opts.subtitle),
            ]),
            opts.exportUrl ? UI.el('button', { class: 'btn btn-success', id: 'rc-export-btn' }, '⬇ Export CSV') : null,
        ]));

        container.appendChild(buildFilterBar(opts.filters, state, () => reload()));

        const summaryHost = UI.el('div', { id: 'rc-summary-host' });
        container.appendChild(summaryHost);

        const noteHost = UI.el('div', { id: 'rc-note-host' });
        container.appendChild(noteHost);
        if (opts.note) noteHost.appendChild(UI.el('div', { class: 'alert alert-info' }, opts.note));

        const tableHost = UI.el('div', { class: 'card' }, [
            UI.el('div', { id: 'rc-table-host' }),
        ]);
        container.appendChild(tableHost);

        if (opts.exportUrl) {
            setTimeout(() => {
                const btn = document.getElementById('rc-export-btn');
                if (btn) btn.addEventListener('click', () => window.open(opts.exportUrl(state), '_blank'));
            }, 0);
        }

        let handle = null;
        async function reload() {
            if (opts.loadSummary) {
                summaryHost.innerHTML = '<div class="alert alert-info">Memuat ringkasan...</div>';
                try {
                    const els = await opts.loadSummary(state);
                    summaryHost.innerHTML = '';
                    if (els) (Array.isArray(els) ? els : [els]).forEach((el) => summaryHost.appendChild(el));
                } catch (err) {
                    UI.handleApiError(err);
                    summaryHost.innerHTML = `<div class="alert alert-error">Gagal memuat ringkasan: ${(err && err.message) || ''}</div>`;
                }
            }
            const tableHostEl = document.getElementById('rc-table-host');
            tableHostEl.innerHTML = '';
            handle = DataTable.render(tableHostEl, {
                storageKey: opts.storageKey,
                pageSize: 25,
                columns: opts.columns,
                fetchPage: async ({ page, perPage }) => opts.fetchPage(Object.assign({}, state, { page, per_page: perPage })),
                onRowClick: opts.onRowClick,
                emptyMessage: opts.emptyMessage || 'Tidak ada data untuk filter ini.',
            });
        }

        reload();
        return { reload: () => reload(), getState: () => state };
    }

    function buildFilterBar(filters, state, onApply) {
        const fieldsHtml = (filters || []).map((f) => {
            if (f.type === 'date') {
                return `<div class="form-group"><label>${f.label}</label><input type="date" id="rc-f-${f.key}" value="${state[f.key] || ''}"></div>`;
            }
            if (f.type === 'select') {
                const opts = (f.options || []).map((o) => `<option value="${o.value}" ${String(state[f.key] || '') === String(o.value) ? 'selected' : ''}>${o.label}</option>`).join('');
                return `<div class="form-group"><label>${f.label}</label><select id="rc-f-${f.key}"><option value="">${f.allLabel || 'Semua'}</option>${opts}</select></div>`;
            }
            return `<div class="form-group"><label>${f.label}</label><input type="text" id="rc-f-${f.key}" value="${state[f.key] || ''}" placeholder="${f.placeholder || ''}"></div>`;
        }).join('');

        const card = UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid', html: fieldsHtml }),
            UI.el('div', { class: 'hpp-filter-actions' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'rc-apply-btn' }, '🔧 Terapkan Filter'),
                UI.el('button', { class: 'btn btn-secondary', id: 'rc-reset-btn' }, '↺ Reset'),
            ]),
        ]);

        setTimeout(() => {
            document.getElementById('rc-apply-btn').addEventListener('click', () => {
                (filters || []).forEach((f) => {
                    const el = document.getElementById(`rc-f-${f.key}`);
                    if (el) state[f.key] = el.value || undefined;
                });
                onApply();
            });
            document.getElementById('rc-reset-btn').addEventListener('click', () => {
                (filters || []).forEach((f) => {
                    delete state[f.key];
                    const el = document.getElementById(`rc-f-${f.key}`);
                    if (el) el.value = '';
                });
                onApply();
            });
        }, 0);

        return card;
    }

    function kpiCard(icon, label, value, isCount) {
        return UI.el('div', { class: 'card hpp-kpi-card' }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, label),
                UI.el('div', { class: 'hpp-kpi-value' }, isCount ? UI.formatNumber(value) : UI.formatMoney(value)),
            ]),
        ]);
    }

    function kpiRow(cards) {
        return UI.el('div', { class: 'hpp-kpi-row' }, cards);
    }

    function historicalBadge() {
        return UI.el('span', { class: 'hpp-pre-go-live-badge' }, 'HISTORICAL');
    }

    return { render, kpiCard, kpiRow, historicalBadge };
})();
