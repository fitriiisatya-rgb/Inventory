/**
 * PHASE V2.16.4 — "Laporan Stock Opname": monthly/session reporting over
 * POSTED Stock Opname sessions for finance/accounting/audit. Separate,
 * additive module — does NOT touch stock-opname.js (now "Proses Stock
 * Opname") or report-opname.js (the older P1/P2 dual-count report still
 * reachable from the Laporan mega-menu).
 *
 * READ-ONLY: every action here is a GET against
 * StockOpnameMonthlyReportService, or a window.open() to an EXISTING
 * print/export endpoint (opnamePrintUrl/opnameExportFinalUrl/
 * opnameExportEodFinalUrl) — this module never creates a Stock
 * Adjustment, never writes a finding/condition, and can never
 * finalize/post/reopen a session.
 *
 * PENTING: HPP / Unit Cost is NEVER rendered anywhere in this file — only
 * Rupiah VALUE fields (nilai sistem/fisik/selisih) the backend already
 * computed from unit_cost_base internally. See
 * StockOpnameMonthlyReportService's own docblock for the same rule on the
 * backend side.
 */
const StockOpnameReport = (() => {
    const MONTHS = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    function canUseExports() {
        // PHASE V2.16.4 — the reused export/print endpoints
        // (opnamePrintUrl/opnameExportFinalUrl/opnameExportEodFinalUrl)
        // are STOCK_OPNAME_SUPERVISE/STOCK_OPNAME_MANAGE-gated on the
        // backend, unchanged (never weakened to INVENTORY_VIEW — see
        // PRODUCTION_MAPPING.md / final report ISSUES for why). A
        // finance/audit user with only INVENTORY_VIEW can still open
        // this whole report (list + detail + summaries), just without
        // these three buttons, which would 403 anyway.
        return Auth.hasPermission('STOCK_OPNAME_MANAGE') || Auth.hasPermission('STOCK_OPNAME_SUPERVISE');
    }

    function render(container) {
        container.innerHTML = '';
        renderList(container);
    }

    function renderList(container) {
        container.innerHTML = '';

        const now = new Date();
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const catOptions = Master.categories().map((c) => ({ value: c.id, label: c.name }));
        const statusOptions = [
            { value: 'POSTED', label: 'Posted' }, { value: 'FINALIZED', label: 'Finalized' },
            { value: 'OPEN', label: 'Open' }, { value: 'CANCELLED', label: 'Cancelled' },
        ];

        const state = {
            month: '', year: String(now.getFullYear()), warehouse_id: '', category_id: '',
            session: '', status: 'POSTED', search: '', page: 1, per_page: 25,
        };

        container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, 'Laporan Stock Opname'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Laporan bulanan hasil Stock Opname untuk finance/accounting, audit, dan rekonsiliasi — hanya sesi POSTED yang resmi/final.'),
            ]),
        ]));

        const filterHost = UI.el('div');
        const tableHost = UI.el('div', { class: 'card' });
        container.appendChild(filterHost);
        container.appendChild(tableHost);

        function buildFilterBar() {
            const monthSelect = UI.el('select', {}, [UI.el('option', { value: '' }, 'Semua Bulan')].concat(
                MONTHS.map((m, i) => {
                    const attrs = { value: String(i + 1) };
                    if (state.month === String(i + 1)) attrs.selected = 'selected';
                    return UI.el('option', attrs, m);
                })
            ));
            const yearInput = UI.el('input', { type: 'number', value: state.year, placeholder: 'Tahun', style: 'width:100px;' });
            const whSelect = UI.el('select', {}, [UI.el('option', { value: '' }, 'Semua Gudang')].concat(
                whOptions.map((o) => {
                    const attrs = { value: String(o.value) };
                    if (String(state.warehouse_id) === String(o.value)) attrs.selected = 'selected';
                    return UI.el('option', attrs, o.label);
                })
            ));
            const catSelect = UI.el('select', {}, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(
                catOptions.map((o) => {
                    const attrs = { value: String(o.value) };
                    if (String(state.category_id) === String(o.value)) attrs.selected = 'selected';
                    return UI.el('option', attrs, o.label);
                })
            ));
            const statusSelect = UI.el('select', {}, statusOptions.map((o) => {
                const attrs = { value: o.value };
                if (state.status === o.value) attrs.selected = 'selected';
                return UI.el('option', attrs, o.label);
            }));
            const sessionInput = UI.el('input', { type: 'text', value: state.session, placeholder: 'No. SO' });
            const searchInput = UI.el('input', { type: 'text', value: state.search, placeholder: 'SKU / Nama / Barcode' });

            const applyBtn = UI.el('button', { class: 'btn btn-primary' }, '🔧 Terapkan Filter');
            const resetBtn = UI.el('button', { class: 'btn btn-secondary' }, '↺ Reset');

            applyBtn.addEventListener('click', () => {
                state.month = monthSelect.value;
                state.year = yearInput.value;
                state.warehouse_id = whSelect.value;
                state.category_id = catSelect.value;
                state.status = statusSelect.value;
                state.session = sessionInput.value;
                state.search = searchInput.value;
                state.page = 1;
                loadSessions();
            });
            resetBtn.addEventListener('click', () => {
                Object.assign(state, {
                    month: '', year: String(now.getFullYear()), warehouse_id: '', category_id: '',
                    session: '', status: 'POSTED', search: '', page: 1,
                });
                buildFilterBar();
                loadSessions();
            });

            filterHost.innerHTML = '';
            filterHost.appendChild(UI.el('div', { class: 'card hpp-filter-card' }, [
                UI.el('div', { class: 'hpp-filter-grid' }, [
                    labeledField('Bulan', monthSelect), labeledField('Tahun', yearInput),
                    labeledField('Gudang', whSelect), labeledField('Session Stock Opname', sessionInput),
                    labeledField('Kategori', catSelect), labeledField('Status', statusSelect),
                    labeledField('Search', searchInput),
                ]),
                UI.el('div', { class: 'hpp-filter-actions' }, [applyBtn, resetBtn]),
            ]));
        }

        function labeledField(label, input) {
            return UI.el('div', { class: 'form-group' }, [UI.el('label', {}, label), input]);
        }

        async function loadSessions() {
            tableHost.innerHTML = '<div class="alert alert-info">Memuat sesi...</div>';
            const params = {};
            if (state.month) params.month = state.month;
            if (state.year) params.year = state.year;
            if (state.warehouse_id) params.warehouse_id = state.warehouse_id;
            if (state.category_id) params.category_id = state.category_id;
            if (state.session) params.session = state.session;
            if (state.status) params.status = state.status;
            if (state.search) params.search = state.search;
            params.page = state.page;
            params.per_page = state.per_page;

            try {
                const result = await InvApi.stockOpnameReports(params);
                renderSessionTable(result);
            } catch (err) {
                UI.handleApiError(err);
                tableHost.innerHTML = `<div class="alert alert-error">Gagal memuat laporan: ${(err && err.message) || ''}</div>`;
            }
        }

        function renderSessionTable(result) {
            tableHost.innerHTML = '';
            const sessions = result.sessions || [];

            if (sessions.length === 0) {
                tableHost.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada sesi Stock Opname untuk filter ini.'));
                tableHost.appendChild(buildPager(result, (p) => { state.page = p; loadSessions(); }, state.per_page, (pp) => { state.per_page = pp; state.page = 1; loadSessions(); }));
                return;
            }

            const table = UI.el('table', { class: 'so-report-table' });
            const thead = UI.el('thead', {}, UI.el('tr', {}, [
                'No', 'No. SO', 'Tanggal SO', 'Gudang', 'Total Item', 'Status', 'Finalized', 'Posted', 'Aksi',
            ].map((h) => UI.el('th', {}, h))));
            const tbody = UI.el('tbody');

            sessions.forEach((s, idx) => {
                const tr = UI.el('tr', {}, [
                    UI.el('td', {}, String((result.page - 1) * result.per_page + idx + 1)),
                    UI.el('td', {}, s.session_number),
                    UI.el('td', {}, s.session_date),
                    UI.el('td', {}, s.warehouse_name),
                    UI.el('td', {}, UI.formatNumber(s.total_item, 0)),
                    UI.el('td', {}, statusBadge(s.status)),
                    UI.el('td', {}, s.finalized_at || '-'),
                    UI.el('td', {}, s.posted_at || '-'),
                    UI.el('td', { class: 'so-report-actions' }, buildActionButtons(s, container)),
                ]);
                tbody.appendChild(tr);
            });

            table.appendChild(thead);
            table.appendChild(tbody);
            tableHost.appendChild(UI.el('div', { class: 'so-report-table-wrap' }, [table]));
            tableHost.appendChild(buildPager(result, (p) => { state.page = p; loadSessions(); }, state.per_page, (pp) => { state.per_page = pp; state.page = 1; loadSessions(); }));
        }

        buildFilterBar();
        loadSessions();
    }

    function buildActionButtons(session, container) {
        const detailBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Lihat Detail');
        detailBtn.addEventListener('click', () => renderDetail(container, session.id));

        const buttons = [detailBtn];
        if (canUseExports()) {
            const excelBtn = UI.el('button', { class: 'btn btn-success btn-sm' }, 'Excel Final');
            excelBtn.addEventListener('click', () => window.open(InvApi.opnameExportFinalUrl(session.id), '_blank'));
            const reconBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Rekonsiliasi Final');
            reconBtn.addEventListener('click', () => window.open(InvApi.opnameExportEodFinalUrl(session.id), '_blank'));
            const printBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Print / PDF');
            printBtn.addEventListener('click', () => window.open(InvApi.opnamePrintUrl(session.id), '_blank'));
            buttons.push(excelBtn, reconBtn, printBtn);
        }
        return UI.el('div', { class: 'so-report-action-group' }, buttons);
    }

    function statusBadge(status) {
        const colors = { POSTED: 'var(--green)', FINALIZED: 'var(--blue, #3b82f6)', OPEN: 'var(--orange)', CANCELLED: 'var(--text2)' };
        return UI.el('span', { style: `color:${colors[status] || 'var(--text2)'}; font-weight:600;` }, status);
    }

    function buildPager(result, onPage, perPage, onPerPage) {
        const info = UI.el('div', {}, `Halaman ${result.page} dari ${result.total_pages || 1} (${result.total} total)`);
        const prevBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '‹ Sebelumnya');
        const nextBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Berikutnya ›');
        prevBtn.disabled = result.page <= 1;
        nextBtn.disabled = result.page >= (result.total_pages || 1);
        prevBtn.addEventListener('click', () => onPage(Math.max(1, result.page - 1)));
        nextBtn.addEventListener('click', () => onPage(result.page + 1));

        const perPageSelect = UI.el('select', {}, [25, 50, 100].map((n) => {
            const attrs = { value: String(n) };
            if (perPage === n) attrs.selected = 'selected';
            return UI.el('option', attrs, `${n} / halaman`);
        }));
        perPageSelect.addEventListener('change', () => onPerPage(parseInt(perPageSelect.value, 10)));

        return UI.el('div', { class: 'so-report-pager' }, [info, UI.el('div', { style: 'display:flex; gap:8px; align-items:center;' }, [perPageSelect, prevBtn, nextBtn])]);
    }

    async function renderDetail(container, sessionId) {
        container.innerHTML = '<div class="alert alert-info">Memuat detail laporan...</div>';

        const state = { category_id: '', search: '', page: 1, per_page: 25 };

        async function load() {
            const params = { page: state.page, per_page: state.per_page };
            if (state.category_id) params.category_id = state.category_id;
            if (state.search) params.search = state.search;
            try {
                const detail = await InvApi.stockOpnameReportDetail(sessionId, params);
                renderDetailBody(detail);
            } catch (err) {
                UI.handleApiError(err);
                container.innerHTML = `<div class="alert alert-error">Gagal memuat detail laporan: ${(err && err.message) || ''}</div>`;
            }
        }

        function renderDetailBody(detail) {
            container.innerHTML = '';
            const session = detail.session;

            const backBtn = UI.el('button', { class: 'btn btn-secondary' }, '‹ Kembali ke Daftar Sesi');
            backBtn.addEventListener('click', () => renderList(container));

            const headerActions = [backBtn];
            if (canUseExports()) {
                const excelBtn = UI.el('button', { class: 'btn btn-success' }, 'Excel Final');
                excelBtn.addEventListener('click', () => window.open(InvApi.opnameExportFinalUrl(sessionId), '_blank'));
                const reconBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Rekonsiliasi Final');
                reconBtn.addEventListener('click', () => window.open(InvApi.opnameExportEodFinalUrl(sessionId), '_blank'));
                const printBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Print / PDF');
                printBtn.addEventListener('click', () => window.open(InvApi.opnamePrintUrl(sessionId), '_blank'));
                headerActions.push(excelBtn, reconBtn, printBtn);
            }

            container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
                UI.el('div', {}, [
                    UI.el('h2', { class: 'hpp-title' }, `Laporan Stock Opname — ${session.session_number}`),
                    UI.el('div', { class: 'hpp-subtitle' }, `${session.warehouse_name} · ${session.session_date} · ${session.status}`),
                ]),
                UI.el('div', { style: 'display:flex; gap:8px; flex-wrap:wrap;' }, headerActions),
            ]));

            container.appendChild(buildSessionInfoCard(session));
            container.appendChild(buildFinanceSummary(detail.finance_summary));
            container.appendChild(buildCategorySummary(detail.category_summary));

            const filterCard = UI.el('div', { class: 'card hpp-filter-card' });
            const catSelect = UI.el('select', {}, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(
                Master.categories().map((c) => {
                    const attrs = { value: String(c.id) };
                    if (String(state.category_id) === String(c.id)) attrs.selected = 'selected';
                    return UI.el('option', attrs, c.name);
                })
            ));
            const searchInput = UI.el('input', { type: 'text', value: state.search, placeholder: 'SKU / Nama / Barcode' });
            const applyBtn = UI.el('button', { class: 'btn btn-primary' }, '🔧 Terapkan Filter');
            applyBtn.addEventListener('click', () => {
                state.category_id = catSelect.value;
                state.search = searchInput.value;
                state.page = 1;
                load();
            });
            filterCard.appendChild(UI.el('div', { class: 'hpp-filter-grid' }, [
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Kategori'), catSelect]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Search'), searchInput]),
            ]));
            filterCard.appendChild(UI.el('div', { class: 'hpp-filter-actions' }, [applyBtn]));
            container.appendChild(filterCard);

            const tableHost = UI.el('div', { class: 'card' });
            container.appendChild(tableHost);
            renderItemTable(tableHost, detail);
        }

        function renderItemTable(tableHost, detail) {
            tableHost.innerHTML = '';
            const items = detail.items || [];
            if (items.length === 0) {
                tableHost.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada item untuk filter ini.'));
                tableHost.appendChild(buildPager(detail, (p) => { state.page = p; load(); }, state.per_page, (pp) => { state.per_page = pp; state.page = 1; load(); }));
                return;
            }

            const table = UI.el('table', { class: 'so-report-table' });
            const thead = UI.el('thead', {}, UI.el('tr', {}, [
                'No', 'Kode Barang', 'Nama Barang', 'Kategori', 'Satuan',
                'Stok Sistem (Qty)', 'Stok Sistem (Nilai)', 'Stok Fisik Final (Qty)', 'Stok Fisik Final (Nilai)',
                'Selisih (Qty)', 'Selisih (Nilai)', 'Rusak', 'Expired', 'Deadstock', 'Kondisi', 'Keterangan',
            ].map((h) => UI.el('th', {}, h))));
            const tbody = UI.el('tbody');

            items.forEach((it, idx) => {
                tbody.appendChild(UI.el('tr', {}, [
                    UI.el('td', {}, String((detail.page - 1) * detail.per_page + idx + 1)),
                    UI.el('td', {}, it.sku),
                    UI.el('td', {}, it.name),
                    UI.el('td', {}, it.category),
                    UI.el('td', {}, it.unit),
                    UI.el('td', { class: 'text-right' }, UI.formatNumber(it.system_qty)),
                    UI.el('td', { class: 'text-right' }, UI.formatMoney(it.system_value)),
                    UI.el('td', { class: 'text-right' }, it.physical_qty !== null ? UI.formatNumber(it.physical_qty) : '-'),
                    UI.el('td', { class: 'text-right' }, it.physical_value !== null ? UI.formatMoney(it.physical_value) : '-'),
                    UI.el('td', { class: 'text-right' }, varianceCell(it.variance_qty, false)),
                    UI.el('td', { class: 'text-right' }, varianceCell(it.variance_value, true)),
                    UI.el('td', { class: 'text-right' }, UI.formatNumber(it.rusak_qty)),
                    UI.el('td', { class: 'text-right' }, UI.formatNumber(it.expired_qty)),
                    UI.el('td', { class: 'text-right' }, UI.formatNumber(it.deadstock_qty)),
                    UI.el('td', {}, kondisiBadge(it.kondisi)),
                    UI.el('td', {}, it.keterangan || '-'),
                ]));
            });

            table.appendChild(thead);
            table.appendChild(tbody);
            tableHost.appendChild(UI.el('div', { class: 'so-report-table-wrap' }, [table]));
            tableHost.appendChild(buildPager(detail, (p) => { state.page = p; load(); }, state.per_page, (pp) => { state.per_page = pp; state.page = 1; load(); }));
        }

        load();
    }

    function varianceCell(value, isMoney) {
        if (value === null || value === undefined) return '-';
        const text = isMoney ? UI.formatMoney(value) : UI.formatNumber(value);
        const color = Math.abs(value) < 0.0005 ? 'var(--text2)' : (value < 0 ? 'var(--red, #dc2626)' : 'var(--green)');
        return UI.el('span', { style: `color:${color}; font-weight:600;` }, text);
    }

    function kondisiBadge(kondisi) {
        const map = {
            'Sesuai': 'var(--green)', 'Lebih (+)': 'var(--green)', 'Kurang (-)': 'var(--red, #dc2626)',
            'Rusak': 'var(--orange)', 'Expired': 'var(--orange)', 'Deadstock': 'var(--red, #dc2626)',
            'Dikecualikan': 'var(--text2)', 'Belum Dihitung': 'var(--text2)',
        };
        return UI.el('span', { style: `color:${map[kondisi] || 'var(--text2)'}; font-weight:600;` }, kondisi);
    }

    function buildSessionInfoCard(session) {
        const rows = [
            ['No. SO', session.session_number], ['Gudang', session.warehouse_name],
            ['Tanggal SO', session.session_date], ['Status', session.status],
            ['Finalized', session.finalized_at ? `${session.finalized_at} oleh ${session.finalized_by || '-'}` : '-'],
            ['Posted', session.posted_at ? `${session.posted_at} oleh ${session.posted_by || '-'}` : '-'],
            ['Counting Model', session.counting_model],
        ];
        return UI.el('div', { class: 'card so-report-info-card' }, rows.map(([label, value]) => UI.el('div', { class: 'so-report-info-row' }, [
            UI.el('span', { class: 'so-report-info-label' }, label), UI.el('span', {}, String(value ?? '-')),
        ])));
    }

    function buildFinanceSummary(fs) {
        const kpis = [
            ReportCommon.kpiCard('📦', 'Total Item Scope', fs.total_item_scope, true),
            ReportCommon.kpiCard('✅', 'Sesuai', fs.sesuai, true),
            ReportCommon.kpiCard('📈', 'Selisih (+)', fs.selisih_plus, true),
            ReportCommon.kpiCard('📉', 'Selisih (-)', fs.selisih_minus, true),
            ReportCommon.kpiCard('🛠', 'Rusak', fs.rusak, true),
            ReportCommon.kpiCard('⏳', 'Expired', fs.expired, true),
            ReportCommon.kpiCard('🚫', 'Deadstock', fs.deadstock, true),
        ];
        const financial = [
            ReportCommon.kpiCard('💰', 'Nilai Stok Sistem', fs.nilai_stok_sistem, false),
            ReportCommon.kpiCard('💵', 'Nilai Stok Fisik Final', fs.nilai_stok_fisik_final, false),
            ReportCommon.kpiCard('⚖️', 'Selisih Nilai', fs.selisih_nilai, false),
            ReportCommon.kpiCard('🔢', 'Qty Sistem', fs.qty_sistem, true),
            ReportCommon.kpiCard('🔢', 'Qty Fisik Final', fs.qty_fisik_final, true),
            ReportCommon.kpiCard('🔢', 'Selisih Qty', fs.selisih_qty, true),
        ];
        return UI.el('div', {}, [ReportCommon.kpiRow(kpis), ReportCommon.kpiRow(financial)]);
    }

    function buildCategorySummary(categories) {
        const table = UI.el('table', { class: 'so-report-table' });
        const thead = UI.el('thead', {}, UI.el('tr', {}, [
            'Kategori', 'Total Item', 'Qty Sistem', 'Nilai Sistem', 'Qty Fisik', 'Nilai Fisik', 'Selisih Qty', 'Selisih Nilai',
        ].map((h) => UI.el('th', {}, h))));
        const tbody = UI.el('tbody', {}, (categories || []).map((c) => UI.el('tr', { class: c.is_total_row ? 'so-report-total-row' : '' }, [
            UI.el('td', {}, c.category),
            UI.el('td', { class: 'text-right' }, UI.formatNumber(c.total_item, 0)),
            UI.el('td', { class: 'text-right' }, UI.formatNumber(c.qty_sistem)),
            UI.el('td', { class: 'text-right' }, UI.formatMoney(c.nilai_sistem)),
            UI.el('td', { class: 'text-right' }, UI.formatNumber(c.qty_fisik)),
            UI.el('td', { class: 'text-right' }, UI.formatMoney(c.nilai_fisik)),
            UI.el('td', { class: 'text-right' }, varianceCell(c.selisih_qty, false)),
            UI.el('td', { class: 'text-right' }, varianceCell(c.selisih_nilai, true)),
        ])));
        table.appendChild(thead);
        table.appendChild(tbody);
        return UI.el('div', { class: 'card' }, [UI.el('h3', { class: 'so-report-section-title' }, 'Ringkasan Kategori'), UI.el('div', { class: 'so-report-table-wrap' }, [table])]);
    }

    return { render };
})();
