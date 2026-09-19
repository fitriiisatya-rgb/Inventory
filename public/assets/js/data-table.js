/**
 * PHASE V2 — generic server-side paginated/sortable/filterable table with a
 * column picker. Used by Stok Barang, History Transaksi, and the master-
 * data list pages. Never fetches/filters/sorts a full dataset client-side
 * — every page turn, sort, and filter change calls fetchPage() again,
 * matching the "server-side pagination for 1000+ rows" performance
 * requirement.
 *
 * Column visibility choice is persisted to localStorage purely as a
 * per-viewer convenience (never re-fetched from it for data) — if it's
 * unavailable (private browsing, blocked storage) the table still renders
 * correctly with every column visible.
 *
 * Usage:
 *   DataTable.render(containerEl, {
 *     storageKey: 'dt-stock-report',      // localStorage key for column visibility
 *     columns: [{ key, label, sortable, defaultVisible, render(row) }],
 *     filters: [{ key, label, type: 'text'|'select', options: [{value,label}], placeholder }],
 *     fetchPage: async ({ page, perPage, sort, dir, filters }) => ({ rows, pagination }),
 *     onRowClick: (row) => {},
 *     defaultSort: 'name', defaultDir: 'asc', pageSize: 50,
 *     exportHref: (filters) => 'url' | null,
 *     emptyMessage: 'Tidak ada data',
 *     toolbarExtra: HTMLElement | null,   // e.g. summary totals
 *   });
 */
const DataTable = (() => {
    function loadVisibility(storageKey, columns) {
        try {
            const raw = localStorage.getItem(storageKey);
            if (raw) {
                const saved = JSON.parse(raw);
                return columns.map((c) => (Object.prototype.hasOwnProperty.call(saved, c.key) ? saved[c.key] : c.defaultVisible !== false));
            }
        } catch (e) { /* private browsing / blocked storage — fall through to defaults */ }
        return columns.map((c) => c.defaultVisible !== false);
    }
    function saveVisibility(storageKey, columns, visible) {
        try {
            const obj = {};
            columns.forEach((c, i) => { obj[c.key] = visible[i]; });
            localStorage.setItem(storageKey, JSON.stringify(obj));
        } catch (e) { /* cosmetic only — ignore */ }
    }

    function render(container, opts) {
        const state = {
            page: 1,
            perPage: opts.pageSize || 50,
            sort: opts.defaultSort || (opts.columns[0] && opts.columns[0].key) || '',
            dir: opts.defaultDir || 'asc',
            // PHASE V2.2: an optional preset (e.g. a Dashboard "Need Attention"
            // drill-down landing on Stok Barang pre-filtered to status=CRITICAL)
            // seeds both the query state AND the visible control below, so the
            // toolbar never lies about what's actually filtering the table.
            filters: { ...(opts.initialFilters || {}) },
            visible: loadVisibility(opts.storageKey || 'dt-default', opts.columns),
            loading: false,
            debounceTimer: null,
        };

        container.innerHTML = '';
        const toolbar = UI.el('div', { class: 'dt-toolbar' });
        const filtersBox = UI.el('div', { class: 'dt-filters' });
        const rightBox = UI.el('div', { style: 'display:flex; align-items:center; gap:8px;' });
        toolbar.appendChild(filtersBox);
        toolbar.appendChild(rightBox);
        if (opts.toolbarExtra) container.appendChild(opts.toolbarExtra);
        container.appendChild(toolbar);

        (opts.filters || []).forEach((f) => {
            let input;
            if (f.type === 'select') {
                const optionsHtml = `<option value="">${f.placeholder || f.label}</option>` + (f.options || []).map((o) => `<option value="${o.value}">${o.label}</option>`).join('');
                input = UI.el('select', { html: optionsHtml });
            } else {
                input = UI.el('input', { type: 'text', placeholder: f.placeholder || f.label });
            }
            if (state.filters[f.key] !== undefined) input.value = state.filters[f.key];
            input.addEventListener(f.type === 'select' ? 'change' : 'input', () => {
                clearTimeout(state.debounceTimer);
                state.debounceTimer = setTimeout(() => {
                    state.filters[f.key] = input.value || undefined;
                    state.page = 1;
                    load();
                }, f.type === 'select' ? 0 : 300);
            });
            filtersBox.appendChild(input);
        });

        // column picker
        const picker = UI.el('div', { class: 'dt-column-picker' });
        const pickerBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '⚙ Kolom');
        const pickerPanel = UI.el('div', { class: 'dt-column-picker-panel' });
        opts.columns.forEach((c, i) => {
            const cb = UI.el('input', { type: 'checkbox' });
            cb.checked = state.visible[i];
            cb.addEventListener('change', () => {
                state.visible[i] = cb.checked;
                saveVisibility(opts.storageKey || 'dt-default', opts.columns, state.visible);
                renderTable();
            });
            pickerPanel.appendChild(UI.el('label', {}, [cb, document.createTextNode(c.label)]));
        });
        pickerBtn.addEventListener('click', (e) => { e.stopPropagation(); pickerPanel.classList.toggle('open'); });
        document.addEventListener('click', () => pickerPanel.classList.remove('open'));
        picker.appendChild(pickerBtn);
        picker.appendChild(pickerPanel);
        rightBox.appendChild(picker);

        if (opts.exportHref) {
            const exportBtn = UI.el('a', { class: 'btn btn-secondary btn-sm', href: '#' }, '⬇ Export CSV');
            exportBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const href = opts.exportHref(state.filters);
                if (href) window.location.href = href;
            });
            rightBox.appendChild(exportBtn);
        }

        const wrapper = UI.el('div', { class: 'dt-wrapper' });
        const table = UI.el('table');
        const thead = UI.el('thead');
        const tbody = UI.el('tbody');
        table.appendChild(thead);
        table.appendChild(tbody);
        wrapper.appendChild(table);
        container.appendChild(wrapper);

        const pagination = UI.el('div', { class: 'dt-pagination' });
        container.appendChild(pagination);

        function renderHead() {
            thead.innerHTML = '';
            const tr = UI.el('tr');
            opts.columns.forEach((c, i) => {
                if (!state.visible[i]) return;
                const th = UI.el('th', c.sortable ? { style: 'cursor:pointer; user-select:none;' } : {});
                th.textContent = c.label;
                if (c.sortable) {
                    if (state.sort === c.key) {
                        th.appendChild(UI.el('span', { class: 'dt-sort-icon' }, state.dir === 'asc' ? '▲' : '▼'));
                    }
                    th.addEventListener('click', () => {
                        if (state.sort === c.key) {
                            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
                        } else {
                            state.sort = c.key;
                            state.dir = 'asc';
                        }
                        load();
                    });
                }
                tr.appendChild(th);
            });
            thead.appendChild(tr);
        }

        let lastRows = [];
        function renderTable() {
            renderHead();
            tbody.innerHTML = '';
            if (state.loading) {
                for (let i = 0; i < 6; i++) {
                    const tr = UI.el('tr', { class: 'dt-skeleton-row' });
                    opts.columns.forEach((c, ci) => { if (state.visible[ci]) tr.appendChild(UI.el('td', {}, '')); });
                    tbody.appendChild(tr);
                }
                return;
            }
            if (lastRows.length === 0) {
                const visibleCount = state.visible.filter(Boolean).length || 1;
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: String(visibleCount), class: 'dt-empty' }, opts.emptyMessage || 'Tidak ada data')]));
                return;
            }
            lastRows.forEach((row) => {
                const tr = UI.el('tr');
                opts.columns.forEach((c, i) => {
                    if (!state.visible[i]) return;
                    const cell = typeof c.render === 'function' ? c.render(row) : (row[c.key] ?? '-');
                    const td = UI.el('td');
                    if (cell instanceof Node) td.appendChild(cell); else td.textContent = cell;
                    tr.appendChild(td);
                });
                if (opts.onRowClick) tr.addEventListener('click', () => opts.onRowClick(row));
                tbody.appendChild(tr);
            });
        }

        function renderPagination(p) {
            pagination.innerHTML = '';
            if (!p) return;
            const info = UI.el('div', {}, `Menampilkan halaman ${p.page} dari ${p.total_pages || 1} (${p.total} total baris)`);
            const prevBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '‹ Sebelumnya');
            const nextBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Berikutnya ›');
            prevBtn.disabled = p.page <= 1;
            nextBtn.disabled = p.page >= (p.total_pages || 1);
            prevBtn.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); load(); });
            nextBtn.addEventListener('click', () => { state.page = state.page + 1; load(); });
            pagination.appendChild(info);
            pagination.appendChild(UI.el('div', { style: 'display:flex; gap:8px;' }, [prevBtn, nextBtn]));
        }

        async function load() {
            state.loading = true;
            renderTable();
            try {
                const result = await opts.fetchPage({ page: state.page, perPage: state.perPage, sort: state.sort, dir: state.dir, filters: state.filters });
                lastRows = result.rows || [];
                state.loading = false;
                renderTable();
                renderPagination(result.pagination);
                if (opts.onLoaded) opts.onLoaded(result);
            } catch (err) {
                state.loading = false;
                lastRows = [];
                UI.handleApiError(err);
                tbody.innerHTML = '';
                const visibleCount = state.visible.filter(Boolean).length || 1;
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: String(visibleCount), class: 'dt-error' }, `Gagal memuat data: ${(err && err.message) || ''}`)]));
                renderPagination(null);
            }
        }

        renderHead();
        load();
        return { reload: load, getFilters: () => state.filters };
    }

    return { render };
})();
