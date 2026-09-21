/**
 * PHASE V2.6B — shared compact-report page builder reused by every
 * remaining Reporting Pack page (Laporan Stok/Pembelian/IN-OUT/Transfer/
 * Stock Opname/Adjustment/Expiry/Supplier/Bakery/Slow Movement/Audit).
 * Keeps every page visually and structurally consistent (compact filter
 * toolbar, optional summary cards, wide DataTable, loading/empty/error
 * states already handled by DataTable.render) without duplicating that
 * plumbing 11 times. Each page only supplies its own filter fields,
 * columns, and fetch function — never its own table/pagination logic.
 *
 * PHASE V2.6C fix: every internal lookup is now a direct element
 * reference (captured at build time) or scoped to `container.querySelector`
 * — never a bare `document.getElementById()`. Tab panels in this app are
 * never removed from the DOM (only hidden via the `.active` class), so
 * with 9+ pages all built from this same file, a bare
 * `document.getElementById('rc-reset-btn')` would silently resolve to
 * whichever report the user visited FIRST, not the one currently on
 * screen — the browser smoke test caught this wiring the wrong page's
 * Reset button to the wrong page's state. `id="rc-*"` attributes are
 * kept only for CSS/test-selector convenience; nothing here re-queries
 * them from `document`.
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

        const exportBtn = opts.exportUrl ? UI.el('button', { class: 'btn btn-success', id: 'rc-export-btn' }, '⬇ Export CSV') : null;
        if (exportBtn) exportBtn.addEventListener('click', () => window.open(opts.exportUrl(state), '_blank'));

        container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, opts.title),
                UI.el('div', { class: 'hpp-subtitle' }, opts.subtitle),
            ]),
            exportBtn,
        ]));

        container.appendChild(buildFilterBar(opts.filters, state, () => reload()));

        const summaryHost = UI.el('div', { id: 'rc-summary-host' });
        container.appendChild(summaryHost);

        const noteHost = UI.el('div', { id: 'rc-note-host' });
        container.appendChild(noteHost);
        if (opts.note) noteHost.appendChild(UI.el('div', { class: 'alert alert-info' }, opts.note));

        const tableHostInner = UI.el('div', { id: 'rc-table-host' });
        const tableHost = UI.el('div', { class: 'card' }, [tableHostInner]);
        container.appendChild(tableHost);

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
            tableHostInner.innerHTML = '';
            handle = DataTable.render(tableHostInner, {
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
        const fieldEls = {};
        const fields = (filters || []).map((f) => {
            const fieldId = `rc-f-${f.key}`;
            let input;
            if (f.type === 'date') {
                input = UI.el('input', { type: 'date', id: fieldId, value: state[f.key] || '' });
            } else if (f.type === 'select') {
                const optionEls = [UI.el('option', { value: '' }, f.allLabel || 'Semua')].concat(
                    (f.options || []).map((o) => {
                        const attrs = { value: String(o.value) };
                        if (String(state[f.key] || '') === String(o.value)) attrs.selected = 'selected';
                        return UI.el('option', attrs, o.label);
                    })
                );
                input = UI.el('select', { id: fieldId }, optionEls);
            } else {
                input = UI.el('input', { type: 'text', id: fieldId, value: state[f.key] || '', placeholder: f.placeholder || '' });
            }
            fieldEls[f.key] = input;
            return UI.el('div', { class: 'form-group' }, [UI.el('label', {}, f.label), input]);
        });

        const applyBtn = UI.el('button', { class: 'btn btn-primary', id: 'rc-apply-btn' }, '🔧 Terapkan Filter');
        const resetBtn = UI.el('button', { class: 'btn btn-secondary', id: 'rc-reset-btn' }, '↺ Reset');

        applyBtn.addEventListener('click', () => {
            (filters || []).forEach((f) => { state[f.key] = fieldEls[f.key].value || undefined; });
            onApply();
        });
        resetBtn.addEventListener('click', () => {
            (filters || []).forEach((f) => {
                delete state[f.key];
                fieldEls[f.key].value = '';
            });
            onApply();
        });

        return UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid' }, fields),
            UI.el('div', { class: 'hpp-filter-actions' }, [applyBtn, resetBtn]),
        ]);
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
