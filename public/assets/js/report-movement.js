/**
 * PHASE V2.6B — Report 2 "Pergerakan Stok Harian". Main table is
 * deliberately exactly 5 columns (Tanggal/Saldo Awal/Barang Masuk/
 * Barang Keluar/Saldo Akhir) per the approved spec — every other number
 * (category breakdown, transaction-level drill-down) lives behind the
 * row-click breakdown drawer, never bloats the main table. Every number
 * is computed server-side by InventoryMovementReportService; this file
 * only renders what the API returns. Transaction-level rows open the
 * EXISTING TraceDrawer — zero trace logic of its own.
 */
const ReportMovement = (() => {
    let state = null;
    let dailyHandle = null;

    function defaultRange() {
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth(), 1);
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        const fmt = (d) => d.toISOString().slice(0, 10);
        return { start: fmt(start), end: fmt(end) };
    }

    function render(container) {
        const range = defaultRange();
        state = { startDate: range.start, endDate: range.end, warehouseId: '', selectedDate: null };

        container.innerHTML = '';
        container.appendChild(buildHeader());
        container.appendChild(buildFilterBar());
        container.appendChild(UI.el('div', { id: 'movement-cutover-banner' }));

        const bodyRow = UI.el('div', { class: 'hpp-body-row' });
        const mainCol = UI.el('div', { class: 'hpp-main-col' });
        mainCol.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📅 Pergerakan Stok Harian')]),
            UI.el('div', { id: 'movement-daily-host' }),
        ]));
        mainCol.appendChild(UI.el('div', { id: 'movement-historical-host' }));
        bodyRow.appendChild(mainCol);

        const drawerHost = UI.el('div', { class: 'hpp-trace-panel', id: 'movement-breakdown-panel' });
        bodyRow.appendChild(drawerHost);
        container.appendChild(bodyRow);

        container.appendChild(UI.el('div', { class: 'hpp-legend' },
            'Klik salah satu baris untuk melihat rincian kategori pergerakan pada tanggal tersebut. ' +
            'Saat "Semua Gudang" dipilih, transfer antar gudang internal (mis. SCM ⇄ Cibadak, SCM ⇄ Karang Tengah) dieliminasi dari Barang Masuk/Keluar — ' +
            'lihat "Transfer Internal (Eliminasi)" pada panel rincian.'));

        renderBreakdownEmpty();
        loadDaily();
    }

    function buildHeader() {
        const exportBtn = UI.el('button', { class: 'btn btn-success', id: 'movement-export-btn' }, '⬇ Export CSV');
        exportBtn.addEventListener('click', () => window.open(InvApi.movementDailyExportUrl(movementParams()), '_blank'));
        return UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, 'Pergerakan Stok Harian'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Saldo Awal, Barang Masuk, Barang Keluar, Saldo Akhir per hari (nominal Rupiah)'),
            ]),
            exportBtn,
        ]);
    }

    function buildFilterBar() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const card = UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid', html: `
                <div class="form-group"><label>Periode Tanggal</label>
                    <div class="hpp-date-range">
                        <input type="date" id="movement-start-date" value="${state.startDate}">
                        <span>—</span>
                        <input type="date" id="movement-end-date" value="${state.endDate}">
                    </div>
                </div>
                <div class="form-group"><label>Gudang</label><select id="movement-warehouse-select"><option value="">Semua Gudang</option>${whOptions}</select></div>
            ` }),
            UI.el('div', { class: 'hpp-filter-actions' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'movement-apply-btn' }, '🔧 Terapkan Filter'),
                UI.el('button', { class: 'btn btn-secondary', id: 'movement-reset-btn' }, '↺ Reset'),
            ]),
        ]);
        setTimeout(() => wireFilterBar(), 0);
        return card;
    }

    function wireFilterBar() {
        document.getElementById('movement-apply-btn').addEventListener('click', () => {
            state.startDate = document.getElementById('movement-start-date').value || state.startDate;
            state.endDate = document.getElementById('movement-end-date').value || state.endDate;
            state.warehouseId = document.getElementById('movement-warehouse-select').value;
            renderBreakdownEmpty();
            loadDaily();
        });
        document.getElementById('movement-reset-btn').addEventListener('click', () => {
            const range = defaultRange();
            document.getElementById('movement-start-date').value = range.start;
            document.getElementById('movement-end-date').value = range.end;
            document.getElementById('movement-warehouse-select').value = '';
            state = { startDate: range.start, endDate: range.end, warehouseId: '', selectedDate: null };
            renderBreakdownEmpty();
            loadDaily();
        });
    }

    function movementParams(extra) {
        return { start_date: state.startDate, end_date: state.endDate, warehouse_id: state.warehouseId || undefined, ...extra };
    }

    async function loadDaily() {
        const host = document.getElementById('movement-daily-host');
        const bannerHost = document.getElementById('movement-cutover-banner');
        host.innerHTML = '';
        dailyHandle = DataTable.render(host, {
            storageKey: 'dt-movement-daily',
            defaultSort: 'date',
            defaultDir: 'asc',
            pageSize: 15,
            columns: [
                {
                    key: 'date', label: 'Tanggal', render: (r) => r.is_pre_go_live
                        ? UI.el('span', {}, [fmtDateOnly(r.date), ' ', UI.el('span', { class: 'hpp-pre-go-live-badge' }, 'Histori Audit')])
                        : fmtDateOnly(r.date),
                },
                { key: 'stok_awal', label: 'Saldo Awal', render: (r) => dailyMoney(r, r.stok_awal) },
                { key: 'barang_masuk', label: 'Barang Masuk', render: (r) => dailyMoney(r, r.barang_masuk) },
                { key: 'barang_keluar', label: 'Barang Keluar', render: (r) => dailyMoney(r, r.barang_keluar) },
                { key: 'stok_akhir', label: 'Saldo Akhir', render: (r) => dailyMoney(r, r.stok_akhir) },
            ],
            fetchPage: async ({ page, perPage }) => {
                const result = await InvApi.movementDaily(movementParams());
                if (bannerHost) {
                    bannerHost.innerHTML = '';
                    const banner = cutoverBanner(result.cutover);
                    if (banner) bannerHost.appendChild(banner);
                }
                renderHistoricalSection(result.historical || []);
                const start = (page - 1) * perPage;
                const totalPages = Math.max(1, Math.ceil(result.rows.length / perPage));
                return {
                    rows: result.rows.slice(start, start + perPage),
                    pagination: { page, total_pages: totalPages, total: result.rows.length },
                };
            },
            onRowClick: (row) => { if (!row.is_pre_go_live) openBreakdown(row.date); },
            emptyMessage: 'Tidak ada data pada periode ini.',
        });
    }

    // MANDATORY CORRECTION A — historical (inventory_effect=0) nominal IN/
    // OUT disclosure, rendered as a visually distinct section: it NEVER
    // shares a Saldo Awal/Akhir with the live table above (historical rows
    // structurally cannot affect live economics — see the service's own
    // docblock), it only discloses raw historical nominal activity for
    // audit purposes.
    function renderHistoricalSection(historicalRows) {
        const host = document.getElementById('movement-historical-host');
        if (!host) return;
        host.innerHTML = '';
        if (!historicalRows.length) return;

        const rows = historicalRows.map((h) => UI.el('tr', { class: 'movement-historical-row' }, [
            UI.el('td', {}, fmtDateOnly(h.date)),
            UI.el('td', {}, UI.formatMoney(h.historical_in)),
            UI.el('td', {}, UI.formatMoney(h.historical_out)),
            UI.el('td', {}, UI.formatNumber(h.transaction_count)),
        ]));
        rows.forEach((tr, i) => tr.addEventListener('click', () => openHistoricalTransactions(historicalRows[i].date)));

        host.appendChild(UI.el('div', { class: 'card', style: 'margin-top:16px;' }, [
            UI.el('div', { class: 'card-header' }, [
                UI.el('div', { class: 'card-title' }, [
                    UI.el('span', { class: 'hpp-pre-go-live-badge' }, 'HISTORICAL'),
                    ' Pergerakan Historis / Reporting Only',
                ]),
            ]),
            UI.el('p', { style: 'color:var(--text3); font-size:0.75rem; margin: 0 0 10px;' },
                'Reporting Only — Tidak memengaruhi stok/HPP live. Data ini adalah aktivitas nominal historis (1–15 Sep atau tanggal historis lain) yang tetap dapat diaudit, namun TIDAK menjadi bagian dari Saldo Awal/Akhir live di atas.'),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Historical IN', 'Historical OUT', 'Jumlah Transaksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows),
                ]),
            ]),
        ]));
    }

    async function openHistoricalTransactions(date) {
        Drawer.open({ title: `Historis — ${fmtDateOnly(date)}`, render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat transaksi historis...</div>'; } });
        try {
            const rows = await InvApi.movementHistoricalTransactions({ date, warehouse_id: state.warehouseId || undefined });
            Drawer.open({
                title: `Historis / Reporting Only — ${fmtDateOnly(date)}`,
                render: (body) => {
                    body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'HISTORICAL — Reporting Only, tidak memengaruhi stok/HPP live.'));
                    if (!rows.length) {
                        body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada transaksi historis pada tanggal ini.'));
                        return;
                    }
                    const trRows = rows.map((r, i) => UI.el('tr', { class: 'hpp-trace-detail-row', 'data-tx-id': String(r.transaction_id) }, [
                        UI.el('td', {}, String(i + 1)),
                        UI.el('td', {}, r.reference_no || '-'),
                        UI.el('td', {}, `${r.sku} — ${r.item_name}`),
                        UI.el('td', {}, r.warehouse_code),
                        UI.el('td', {}, UI.formatNumber(r.qty)),
                        UI.el('td', {}, UI.formatMoney(r.value)),
                    ]));
                    body.appendChild(UI.el('div', { class: 'table-wrapper hpp-trace-detail-table' }, [
                        UI.el('table', {}, [
                            UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Referensi', 'Barang', 'Gudang', 'Qty', 'Nilai'].map((h) => UI.el('th', {}, h)))]),
                            UI.el('tbody', {}, trRows),
                        ]),
                    ]));
                    body.querySelectorAll('.hpp-trace-detail-row').forEach((row) => {
                        row.addEventListener('click', () => TraceDrawer.openTransaction(Number(row.dataset.txId)));
                        row.style.cursor = 'pointer';
                    });
                },
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    function cutoverBanner(cutover) {
        if (!cutover) return null;
        if (cutover.is_pre_go_live_period) {
            return UI.el('div', { class: 'alert alert-info hpp-cutover-banner' },
                `Periode yang dipilih seluruhnya sebelum Opening Go-Live${cutover.live_opening_date ? ` (${fmtDateOnly(cutover.live_opening_date)})` : ''} — tidak ada aktivitas ekonomi untuk periode ini.`);
        }
        if (cutover.live_opening_date && cutover.effective_start_date !== cutover.requested_start_date) {
            return UI.el('div', { class: 'alert alert-info hpp-cutover-banner' },
                `Periode efektif: ${fmtDateOnly(cutover.effective_start_date)} – ${fmtDateOnly(cutover.requested_end_date)} (Opening Go-Live ${fmtDateOnly(cutover.live_opening_date)}). Data sebelum tanggal ini adalah histori audit.`);
        }
        return null;
    }

    function dailyMoney(row, value) {
        const money = UI.formatMoney(value);
        return row.is_pre_go_live ? UI.el('span', { class: 'hpp-pre-go-live-value' }, money) : money;
    }

    function fmtDateOnly(value) {
        if (!value) return '-';
        const d = new Date(value + 'T00:00:00');
        if (isNaN(d.getTime())) return value;
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function renderBreakdownEmpty() {
        const host = document.getElementById('movement-breakdown-panel');
        if (!host) return;
        host.innerHTML = '';
        host.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🔎 Rincian Pergerakan')]),
            UI.el('div', { class: 'alert alert-info' }, 'Klik salah satu baris pada tabel untuk melihat rincian kategori pergerakan pada tanggal tersebut.'),
        ]));
    }

    async function openBreakdown(date) {
        state.selectedDate = date;
        const host = document.getElementById('movement-breakdown-panel');
        host.innerHTML = '<div class="card"><div class="alert alert-info">Memuat rincian...</div></div>';
        try {
            const b = await InvApi.movementDayBreakdown({ date, warehouse_id: state.warehouseId || undefined });
            host.innerHTML = '';

            const inRows = b.categories.filter((c) => c.direction === 'IN').map((c) => categoryRow(c, date));
            const outRows = b.categories.filter((c) => c.direction === 'OUT').map((c) => categoryRow(c, date));

            host.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [
                    UI.el('div', { class: 'card-title' }, '🔎 Rincian Pergerakan'),
                    UI.el('button', { class: 'btn-icon', id: 'movement-breakdown-close' }, '✕'),
                ]),
                UI.el('div', { class: 'hpp-trace-date-chip' }, [UI.el('span', {}, '📅'), ` ${fmtDateOnly(date)}`]),
                b.is_company_consolidated ? UI.el('p', { style: 'color:var(--text3); font-size:0.72rem;' }, 'Level Perusahaan — transfer antar gudang internal dieliminasi.') : null,
                UI.el('div', { class: 'hpp-trace-section-label' }, 'Barang Masuk'),
                UI.el('div', { class: 'hpp-bridge-table' }, inRows),
                UI.el('div', { class: 'hpp-bridge-row hpp-bridge-total' }, [UI.el('span', {}, 'Total Barang Masuk'), UI.el('span', { class: 'mono' }, UI.formatMoney(b.barang_masuk))]),
                UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:14px;' }, 'Barang Keluar'),
                UI.el('div', { class: 'hpp-bridge-table' }, outRows),
                UI.el('div', { class: 'hpp-bridge-row hpp-bridge-total' }, [UI.el('span', {}, 'Total Barang Keluar'), UI.el('span', { class: 'mono' }, UI.formatMoney(b.barang_keluar))]),
            ]));

            document.getElementById('movement-breakdown-close').addEventListener('click', renderBreakdownEmpty);
            document.querySelectorAll('.movement-category-row[data-category]').forEach((row) => {
                row.addEventListener('click', () => openCategoryTransactions(date, row.dataset.category, row.dataset.label));
                row.style.cursor = 'pointer';
            });
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="card"><div class="alert alert-error">Gagal memuat rincian: ${(err && err.message) || ''}</div></div>`;
        }
    }

    function categoryRow(c, date) {
        const clickable = c.key !== 'transfer_elimination';
        const row = UI.el('div', {
            class: `hpp-bridge-row${clickable ? ' movement-category-row' : ''}`,
            'data-category': clickable ? c.key : undefined,
            'data-label': c.label,
        }, [
            UI.el('span', {}, c.label),
            UI.el('span', { class: 'mono' }, UI.formatMoney(c.value)),
        ]);
        if (c.note) row.title = c.note;
        return row;
    }

    async function openCategoryTransactions(date, category, label) {
        Drawer.open({ title: label, render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat transaksi...</div>'; } });
        try {
            const rows = await InvApi.movementDayTransactions({ date, category, warehouse_id: state.warehouseId || undefined });
            Drawer.open({
                title: `${label} — ${fmtDateOnly(date)}`,
                render: (body) => {
                    if (!rows.length) {
                        body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada transaksi pada kategori ini.'));
                        return;
                    }
                    const trRows = rows.map((r, i) => UI.el('tr', { class: 'hpp-trace-detail-row', 'data-tx-id': String(r.transaction_id) }, [
                        UI.el('td', {}, String(i + 1)),
                        UI.el('td', {}, r.reference_no || '-'),
                        UI.el('td', {}, `${r.sku} — ${r.item_name}`),
                        UI.el('td', {}, r.warehouse_code),
                        UI.el('td', {}, UI.formatNumber(r.qty)),
                        UI.el('td', {}, UI.formatMoney(r.value)),
                        UI.el('td', {}, r.status),
                    ]));
                    body.appendChild(UI.el('div', { class: 'table-wrapper hpp-trace-detail-table' }, [
                        UI.el('table', {}, [
                            UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Referensi', 'Barang', 'Gudang', 'Qty', 'Nilai', 'Status'].map((h) => UI.el('th', {}, h)))]),
                            UI.el('tbody', {}, trRows),
                        ]),
                    ]));
                    body.querySelectorAll('.hpp-trace-detail-row').forEach((row) => {
                        row.addEventListener('click', () => TraceDrawer.openTransaction(Number(row.dataset.txId)));
                        row.style.cursor = 'pointer';
                    });
                },
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
