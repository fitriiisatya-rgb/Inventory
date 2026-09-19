/**
 * PHASE V2.3 — "Laporan Nilai Stok & HPP" (Inventory Value & HPP
 * Reconciliation). Every number here is computed server-side by
 * InventoryHppReportService — this file only renders what the API
 * returns and never recomputes a total itself.
 *
 * Deep trace integration (per the owner's explicit instruction): every
 * clickable number opens the EXISTING TraceDrawer — this file contains
 * zero trace logic of its own. The right-side "Trace Detail HPP" panel
 * is populated from GET /reports/inventory-hpp/day-detail, whose rows
 * carry real transaction_id values that go straight into
 * TraceDrawer.openTransaction().
 */
const ReportHpp = (() => {
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
        state = {
            startDate: range.start, endDate: range.end,
            warehouseId: '', categoryId: '', q: '',
            selectedDate: null,
        };

        container.innerHTML = '';
        container.appendChild(buildHeader());
        container.appendChild(buildFilterBar());
        const kpiHost = UI.el('div', { class: 'hpp-kpi-row', id: 'hpp-kpi-row' });
        container.appendChild(kpiHost);
        const formulaHost = UI.el('div', { id: 'hpp-formula-strip' });
        container.appendChild(formulaHost);

        const bodyRow = UI.el('div', { class: 'hpp-body-row' });
        const mainCol = UI.el('div', { class: 'hpp-main-col' });
        const whHost = UI.el('div', { id: 'hpp-warehouse-panels' });
        const dailyCard = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📅 Rekap Harian Nilai Stok & HPP')]),
            UI.el('div', { id: 'hpp-daily-host' }),
        ]);
        mainCol.appendChild(whHost);
        mainCol.appendChild(dailyCard);
        bodyRow.appendChild(mainCol);

        const traceHost = UI.el('div', { class: 'hpp-trace-panel', id: 'hpp-trace-panel' });
        bodyRow.appendChild(traceHost);
        container.appendChild(bodyRow);

        container.appendChild(UI.el('div', { class: 'hpp-legend' },
            'Keterangan: HPP FIFO = nilai barang keluar berdasarkan FIFO allocation. ' +
            'HPP Rekonsiliasi = Stok Awal + Pembelian Eksternal − Stok Akhir. ' +
            'Transfer antar gudang tidak dihitung sebagai pembelian/penjualan perusahaan. ' +
            'Adjustment/Opname/Reversal ditampilkan terpisah agar tidak tercampur dengan HPP.'));

        renderTracePanelEmpty();
        loadAll();
    }

    function buildHeader() {
        return UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, 'Laporan Nilai Stok & HPP'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Inventory Value & HPP Reconciliation'),
            ]),
            UI.el('button', { class: 'btn btn-success', id: 'hpp-export-btn' }, '⬇ Export Excel'),
        ]);
    }

    function buildFilterBar() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const catOptions = Master.categories().map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
        const card = UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid', html: `
                <div class="form-group"><label>Periode Tanggal</label>
                    <div class="hpp-date-range">
                        <input type="date" id="hpp-start-date" value="${state.startDate}">
                        <span>—</span>
                        <input type="date" id="hpp-end-date" value="${state.endDate}">
                    </div>
                </div>
                <div class="form-group"><label>Periode</label>
                    <div class="hpp-period-buttons">
                        <button type="button" class="btn btn-secondary btn-sm" data-period="day">Harian</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-period="week">Mingguan</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-period="month">Bulanan</button>
                    </div>
                </div>
                <div class="form-group"><label>Gudang</label><select id="hpp-warehouse-select"><option value="">Semua Gudang</option>${whOptions}</select></div>
                <div class="form-group"><label>Kategori</label><select id="hpp-category-select"><option value="">Semua Kategori</option>${catOptions}</select></div>
                <div class="form-group"><label>Barang / SKU</label><input type="text" id="hpp-sku-search" placeholder="Cari nama barang / SKU..."></div>
            ` }),
            UI.el('div', { class: 'hpp-filter-actions' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'hpp-apply-btn' }, '🔧 Terapkan Filter'),
                UI.el('button', { class: 'btn btn-secondary', id: 'hpp-reset-btn' }, '↺ Reset'),
            ]),
        ]);
        setTimeout(() => wireFilterBar(), 0);
        return card;
    }

    function wireFilterBar() {
        document.getElementById('hpp-apply-btn').addEventListener('click', () => {
            state.startDate = document.getElementById('hpp-start-date').value || state.startDate;
            state.endDate = document.getElementById('hpp-end-date').value || state.endDate;
            state.warehouseId = document.getElementById('hpp-warehouse-select').value;
            state.categoryId = document.getElementById('hpp-category-select').value;
            state.q = document.getElementById('hpp-sku-search').value.trim();
            loadAll();
        });
        document.getElementById('hpp-reset-btn').addEventListener('click', () => {
            const range = defaultRange();
            document.getElementById('hpp-start-date').value = range.start;
            document.getElementById('hpp-end-date').value = range.end;
            document.getElementById('hpp-warehouse-select').value = '';
            document.getElementById('hpp-category-select').value = '';
            document.getElementById('hpp-sku-search').value = '';
            state = { startDate: range.start, endDate: range.end, warehouseId: '', categoryId: '', q: '', selectedDate: null };
            renderTracePanelEmpty();
            loadAll();
        });
        document.querySelectorAll('.hpp-period-buttons [data-period]').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.hpp-period-buttons [data-period]').forEach((b) => b.classList.remove('btn-primary'));
                btn.classList.add('btn-primary');
                const now = new Date();
                const fmt = (d) => d.toISOString().slice(0, 10);
                let start;
                let end;
                if (btn.dataset.period === 'day') {
                    start = end = fmt(now);
                } else if (btn.dataset.period === 'week') {
                    const day = now.getDay() || 7;
                    start = fmt(new Date(now.getFullYear(), now.getMonth(), now.getDate() - day + 1));
                    end = fmt(new Date(now.getFullYear(), now.getMonth(), now.getDate() - day + 7));
                } else {
                    start = fmt(new Date(now.getFullYear(), now.getMonth(), 1));
                    end = fmt(new Date(now.getFullYear(), now.getMonth() + 1, 0));
                }
                document.getElementById('hpp-start-date').value = start;
                document.getElementById('hpp-end-date').value = end;
            });
        });
        document.getElementById('hpp-export-btn').addEventListener('click', () => {
            const url = InvApi.hppExportUrl(hppParams());
            window.open(url, '_blank');
        });
    }

    function hppParams(extra) {
        return {
            start_date: state.startDate, end_date: state.endDate,
            warehouse_id: state.warehouseId || undefined,
            category_id: state.categoryId || undefined,
            q: state.q || undefined,
            ...extra,
        };
    }

    async function loadAll() {
        await Promise.all([loadKpis(), loadWarehousePanels(), loadDailyTable()]);
    }

    async function loadKpis() {
        const host = document.getElementById('hpp-kpi-row');
        const formulaHost = document.getElementById('hpp-formula-strip');
        host.innerHTML = '<div class="alert alert-info">Memuat ringkasan...</div>';
        try {
            const summary = await InvApi.hppSummary(hppParams());
            host.innerHTML = '';
            host.appendChild(kpiCard('📥', 'Nilai Stok Awal', summary.opening_value, summary.deltas_vs_previous_period.opening_value_pct));
            host.appendChild(kpiCard('🛒', 'Pembelian Eksternal', summary.external_purchase, summary.deltas_vs_previous_period.external_purchase_pct));
            host.appendChild(kpiCard('📦', 'Barang Keluar FIFO / HPP Aktual', summary.fifo_hpp, summary.deltas_vs_previous_period.fifo_hpp_pct, 'Biaya aktual dari FIFO allocation — angka HPP yang sesungguhnya.'));
            host.appendChild(kpiCard('📤', 'Nilai Stok Akhir', summary.ending_value, summary.deltas_vs_previous_period.ending_value_pct));
            host.appendChild(kpiCard('🧮', 'HPP Rekonsiliasi (Nilai Kontrol)', summary.hpp_reconciliation, summary.deltas_vs_previous_period.hpp_reconciliation_pct, 'Formula kontrol (Stok Awal + Pembelian − Stok Akhir) — BUKAN otomatis sama dengan HPP FIFO aktual.'));
            host.appendChild(varianceCard(summary));

            formulaHost.innerHTML = '';
            formulaHost.appendChild(buildFormulaStrip(summary));
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="alert alert-error">Gagal memuat ringkasan: ${(err && err.message) || ''}</div>`;
        }
    }

    function kpiCard(icon, label, value, deltaPct, note) {
        const deltaEl = deltaPct === null || deltaPct === undefined
            ? UI.el('span', { class: 'hpp-kpi-delta neutral' }, '—')
            : UI.el('span', { class: `hpp-kpi-delta ${deltaPct >= 0 ? 'up' : 'down'}` }, `${deltaPct >= 0 ? '↑' : '↓'} ${Math.abs(deltaPct)}%`);
        return UI.el('div', { class: 'card hpp-kpi-card' }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, label),
                UI.el('div', { class: 'hpp-kpi-value' }, UI.formatMoney(value)),
                UI.el('div', {}, [deltaEl, UI.el('span', { class: 'hpp-kpi-delta-note' }, ' vs periode lalu')]),
                note ? UI.el('div', { class: 'hpp-kpi-subnote' }, note) : null,
            ]),
        ]);
    }

    // Clicking Variance opens the exact bridge (spec section 8) — never a
    // silent "trust me it balances." A nonzero `unexplained` is shown as a
    // visible warning, not hidden or rounded away.
    function varianceCard(summary) {
        const balanced = Math.abs(summary.variance) < 0.5;
        const card = UI.el('div', { class: `card hpp-kpi-card hpp-kpi-clickable ${balanced ? 'hpp-variance-ok' : 'hpp-variance-warn'}` }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, balanced ? '✅' : '⚠️'),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, 'Variance FIFO vs Rekonsiliasi'),
                UI.el('div', { class: 'hpp-kpi-value' }, UI.formatMoney(summary.variance)),
                UI.el('div', { class: 'hpp-kpi-delta-note' }, balanced ? '0.00% — Seimbang' : 'Klik untuk lihat rincian penjelas ↓'),
            ]),
        ]);
        card.addEventListener('click', openVarianceBridge);
        return card;
    }

    function buildFormulaStrip(summary) {
        return UI.el('div', { class: 'card hpp-formula-strip' }, [
            UI.el('div', { class: 'hpp-formula-icon' }, '🧮'),
            UI.el('div', { class: 'hpp-formula-label' }, 'Formula Rekonsiliasi HPP (Level Perusahaan)'),
            UI.el('div', { class: 'hpp-formula-chip' }, [UI.el('div', { class: 'hpp-formula-chip-label' }, 'Stok Awal Total'), UI.el('div', { class: 'hpp-formula-chip-value' }, UI.formatMoney(summary.opening_value))]),
            UI.el('div', { class: 'hpp-formula-op' }, '+'),
            UI.el('div', { class: 'hpp-formula-chip' }, [UI.el('div', { class: 'hpp-formula-chip-label' }, 'Pembelian Eksternal'), UI.el('div', { class: 'hpp-formula-chip-value' }, UI.formatMoney(summary.external_purchase))]),
            UI.el('div', { class: 'hpp-formula-op' }, '−'),
            UI.el('div', { class: 'hpp-formula-chip' }, [UI.el('div', { class: 'hpp-formula-chip-label' }, 'Stok Akhir Total'), UI.el('div', { class: 'hpp-formula-chip-value' }, UI.formatMoney(summary.ending_value))]),
            UI.el('div', { class: 'hpp-formula-op' }, '='),
            UI.el('div', { class: 'hpp-formula-chip hpp-formula-result' }, [UI.el('div', { class: 'hpp-formula-chip-label' }, 'HPP Rekonsiliasi (Nilai Kontrol)'), UI.el('div', { class: 'hpp-formula-chip-value' }, UI.formatMoney(summary.hpp_reconciliation))]),
            UI.el('div', { class: 'hpp-formula-note' }, 'ℹ️ Nilai kontrol, BUKAN otomatis sama dengan HPP FIFO aktual. Transfer antar gudang tidak dihitung sebagai pembelian perusahaan.'),
        ]);
    }

    async function openVarianceBridge() {
        Drawer.open({ title: 'Variance FIFO vs Rekonsiliasi', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat rincian...</div>'; } });
        try {
            const b = await InvApi.hppVarianceBridge({ start_date: state.startDate, end_date: state.endDate, warehouse_id: state.warehouseId || undefined });
            const componentRows = b.components.filter((c) => Math.abs(c.explains) >= 0.5);
            Drawer.open({
                title: 'Variance FIFO vs Rekonsiliasi',
                render: (body) => {
                    body.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.78rem; margin-bottom:14px;' },
                        'HPP Rekonsiliasi adalah nilai KONTROL (Stok Awal + Pembelian − Stok Akhir), bukan otomatis sama dengan HPP FIFO aktual. Selisihnya (Variance) dijelaskan oleh pergerakan non-HPP di bawah — tidak pernah dipaksa menjadi nol.'));
                    body.appendChild(UI.el('div', { class: 'hpp-bridge-table' }, [
                        UI.el('div', { class: 'hpp-bridge-row' }, [UI.el('span', {}, 'HPP Rekonsiliasi (Nilai Kontrol)'), UI.el('span', { class: 'mono' }, UI.formatMoney(b.hpp_reconciliation))]),
                        UI.el('div', { class: 'hpp-bridge-row' }, [UI.el('span', {}, 'FIFO HPP (Aktual)'), UI.el('span', { class: 'mono' }, UI.formatMoney(b.fifo_hpp))]),
                        UI.el('div', { class: 'hpp-bridge-divider' }),
                        UI.el('div', { class: 'hpp-bridge-row hpp-bridge-total' }, [UI.el('span', {}, 'Variance'), UI.el('span', { class: 'mono' }, UI.formatMoney(b.variance))]),
                    ]));
                    body.appendChild(UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:16px;' }, 'Dijelaskan oleh:'));
                    body.appendChild(UI.el('div', { class: 'hpp-bridge-table' }, componentRows.length ? componentRows.map((c) => UI.el('div', { class: 'hpp-bridge-row' }, [
                        UI.el('span', {}, c.label),
                        UI.el('span', { class: 'mono' }, UI.formatMoney(c.explains)),
                    ])) : [UI.el('div', { class: 'hpp-bridge-row' }, [UI.el('span', {}, '(tidak ada pergerakan non-HPP pada periode ini)'), UI.el('span', {}, '')])]));
                    body.appendChild(UI.el('div', { class: 'hpp-bridge-divider' }));
                    body.appendChild(UI.el('div', { class: `hpp-bridge-row hpp-bridge-total ${b.is_fully_explained ? '' : 'hpp-bridge-warn'}` }, [
                        UI.el('span', {}, 'Unexplained'),
                        UI.el('span', { class: 'mono' }, UI.formatMoney(b.unexplained)),
                    ]));
                    if (!b.is_fully_explained) {
                        body.appendChild(UI.el('div', { class: 'alert alert-error', style: 'margin-top:12px;' },
                            '⚠️ Ada selisih yang belum terjelaskan. Variance TIDAK dipaksa menjadi nol — periksa pergerakan non-HPP lain (mis. jenis transaksi baru) sebelum melaporkan angka ini.'));
                    }
                },
            });
        } catch (err) {
            UI.handleApiError(err);
            Drawer.open({ title: 'Variance FIFO vs Rekonsiliasi', render: (body) => { body.innerHTML = `<div class="alert alert-error">Gagal memuat rincian: ${(err && err.message) || ''}</div>`; } });
        }
    }

    async function loadWarehousePanels() {
        const host = document.getElementById('hpp-warehouse-panels');
        if (state.warehouseId) {
            host.innerHTML = '';
            return;
        }
        host.innerHTML = '<div class="alert alert-info">Memuat breakdown gudang...</div>';
        try {
            const result = await InvApi.hppWarehouses({ start_date: state.startDate, end_date: state.endDate });
            host.innerHTML = '';
            const row = UI.el('div', { class: 'hpp-warehouse-row' });
            result.panels.forEach((p) => row.appendChild(warehousePanel(p)));
            host.appendChild(row);
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="alert alert-error">Gagal memuat breakdown gudang: ${(err && err.message) || ''}</div>`;
        }
    }

    function warehousePanel(p) {
        const w = p.warehouse;
        const trend = p.daily_trend || [];
        const max = Math.max(1, ...trend.map((t) => Number(t.value) || 0));
        const bars = UI.el('div', { class: 'hpp-sparkline' }, trend.map((t) => UI.el('div', {
            class: 'hpp-sparkline-bar',
            style: `height:${Math.max(4, Math.round((Number(t.value) / max) * 40))}px;`,
            title: `${t.date}: ${UI.formatMoney(t.value)}`,
        })));
        const detailBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Lihat Detail →');
        // Drills the whole report into this single warehouse — reuses the
        // exact same filter state the filter bar's own Gudang select drives,
        // never a separate code path.
        detailBtn.addEventListener('click', () => {
            state.warehouseId = String(w.id);
            const sel = document.getElementById('hpp-warehouse-select');
            if (sel) sel.value = state.warehouseId;
            loadAll();
        });
        return UI.el('div', { class: 'card hpp-warehouse-panel' }, [
            UI.el('div', { class: 'hpp-warehouse-panel-header' }, [
                UI.el('div', {}, [
                    UI.el('div', { class: 'hpp-warehouse-panel-title' }, `🏢 ${w.name}`),
                    UI.el('div', { class: 'hpp-warehouse-panel-subtitle' }, w.warehouse_type === 'TRANSIT' ? 'Stok transit & distribusi' : 'Stok utama bahan baku & material'),
                ]),
                detailBtn,
            ]),
            UI.el('div', { class: 'hpp-warehouse-panel-grid' }, [
                miniStat('Stok Awal', p.opening_value),
                miniStat('Pembelian', p.external_purchase),
                miniStat('FIFO OUT', p.fifo_hpp),
                miniStat('Transfer IN', p.transfer_in),
                miniStat('Transfer OUT', p.transfer_out),
                miniStat('Stok Akhir', p.ending_value),
            ]),
            bars,
        ]);
    }

    function miniStat(label, value) {
        return UI.el('div', { class: 'hpp-mini-stat' }, [
            UI.el('div', { class: 'hpp-mini-stat-label' }, label),
            UI.el('div', { class: 'hpp-mini-stat-value' }, UI.formatMoney(value)),
        ]);
    }

    async function loadDailyTable() {
        const host = document.getElementById('hpp-daily-host');
        host.innerHTML = '';
        dailyHandle = DataTable.render(host, {
            storageKey: 'dt-hpp-daily',
            defaultSort: 'date',
            defaultDir: 'asc',
            pageSize: 10,
            columns: [
                { key: 'date', label: 'Tanggal', render: (r) => fmtDateOnly(r.date) },
                { key: 'stok_awal', label: 'Stok Awal', render: (r) => UI.formatMoney(r.stok_awal) },
                { key: 'pembelian', label: 'Pembelian', render: (r) => UI.formatMoney(r.pembelian) },
                { key: 'fifo_out', label: 'FIFO OUT / HPP', render: (r) => UI.formatMoney(r.fifo_out) },
                { key: 'transfer_in', label: 'Transfer IN', render: (r) => UI.formatMoney(r.transfer_in) },
                { key: 'transfer_out', label: 'Transfer OUT', render: (r) => UI.formatMoney(r.transfer_out) },
                { key: 'adjustment', label: 'Adjustment', render: (r) => UI.formatMoney(r.adjustment) },
                { key: 'stok_akhir', label: 'Stok Akhir', render: (r) => UI.formatMoney(r.stok_akhir) },
                { key: 'hpp_reconciliation', label: 'HPP Rekonsiliasi', render: (r) => UI.formatMoney(r.hpp_reconciliation) },
                { key: 'variance', label: 'Variance', render: (r) => UI.el('span', { style: Math.abs(r.variance) < 0.5 ? 'color:var(--green);' : 'color:var(--orange);' }, UI.formatMoney(r.variance)) },
                {
                    key: 'aksi', label: 'Aksi', sortable: false, render: (r) => UI.el('button', { class: 'btn btn-secondary btn-sm hpp-row-trace-btn' }, '👁'),
                },
            ],
            fetchPage: async ({ page, perPage }) => InvApi.hppDaily(hppParams({ page, per_page: perPage })),
            onRowClick: (row) => openTracePanel(row.date),
            emptyMessage: 'Tidak ada data pada periode ini.',
        });
    }

    function fmtDateOnly(value) {
        if (!value) return '-';
        const d = new Date(value + 'T00:00:00');
        if (isNaN(d.getTime())) return value;
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function renderTracePanelEmpty() {
        const host = document.getElementById('hpp-trace-panel');
        if (!host) return;
        host.innerHTML = '';
        host.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🔎 Trace Detail HPP')]),
            UI.el('div', { class: 'alert alert-info' }, 'Klik salah satu baris pada Rekap Harian untuk melihat rincian transaksi barang keluar (FIFO) pada tanggal tersebut.'),
        ]));
    }

    async function openTracePanel(date) {
        state.selectedDate = date;
        const host = document.getElementById('hpp-trace-panel');
        host.innerHTML = '<div class="card"><div class="alert alert-info">Memuat detail...</div></div>';
        try {
            const detail = await InvApi.hppDayDetail({ date, warehouse_id: state.warehouseId || undefined });
            host.innerHTML = '';

            const breakdownRows = detail.breakdown_by_warehouse.map((b) => UI.el('div', { class: 'hpp-trace-breakdown-row' }, [
                UI.el('div', { class: 'hpp-trace-breakdown-label' }, [UI.el('span', { class: 'hpp-trace-dot' }), ` ${b.warehouse_code}`]),
                UI.el('div', { class: 'hpp-trace-breakdown-bar-wrap' }, [
                    UI.el('div', { class: 'hpp-trace-breakdown-bar', style: `width:${b.pct}%;` }),
                ]),
                UI.el('div', { class: 'hpp-trace-breakdown-value' }, `${UI.formatMoney(b.value)} (${b.pct}%)`),
            ]));

            const detailRows = detail.lines.slice(0, 20).map((l, i) => UI.el('tr', { class: 'hpp-trace-detail-row', 'data-tx-id': String(l.transaction_id) }, [
                UI.el('td', {}, String(i + 1)),
                UI.el('td', {}, l.reference_no || l.transaction_uuid),
                UI.el('td', {}, `${l.sku} — ${l.item_name}`),
                UI.el('td', {}, UI.formatNumber(l.qty_allocated)),
                UI.el('td', {}, UI.formatMoney(l.unit_cost_base)),
                UI.el('td', {}, UI.formatMoney(l.hpp_value)),
            ]));

            host.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [
                    UI.el('div', { class: 'card-title' }, '🔎 Trace Detail HPP'),
                    UI.el('button', { class: 'btn-icon', id: 'hpp-trace-close' }, '✕'),
                ]),
                UI.el('p', { style: 'color:var(--text3); font-size:0.78rem;' }, 'Rincian transaksi barang keluar (FIFO) untuk tanggal yang dipilih.'),
                UI.el('div', { class: 'hpp-trace-date-chip' }, [UI.el('span', {}, '📅'), ` ${fmtDateOnly(date)}`]),
                UI.el('div', { class: 'hpp-trace-total-chip' }, [
                    UI.el('div', { class: 'hpp-trace-total-label' }, 'Total HPP FIFO'),
                    UI.el('div', { class: 'hpp-trace-total-value' }, UI.formatMoney(detail.total_fifo_hpp)),
                ]),
                detail.breakdown_by_warehouse.length ? UI.el('div', { class: 'hpp-trace-breakdown' }, [
                    UI.el('div', { class: 'hpp-trace-section-label' }, 'Breakdown per Gudang'),
                    ...breakdownRows,
                ]) : null,
                UI.el('div', { class: 'hpp-trace-section-label' }, 'Detail Transaksi'),
                UI.el('div', { class: 'table-wrapper hpp-trace-detail-table' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Referensi', 'Barang', 'Qty', 'Harga', 'Total HPP'].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, detailRows.length ? detailRows : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Tidak ada transaksi barang keluar pada tanggal ini')])]),
                    ]),
                ]),
                detail.lines.length > 0 ? UI.el('button', { class: 'btn btn-primary hpp-trace-viewall-btn', style: 'width:100%; margin-top:10px;' }, `Lihat Semua Transaksi Tanggal Ini →`) : null,
            ]));

            document.getElementById('hpp-trace-close').addEventListener('click', renderTracePanelEmpty);
            // Deep trace integration: every row opens the EXISTING TraceDrawer —
            // no separate detail view is built here.
            document.querySelectorAll('.hpp-trace-detail-row').forEach((row) => {
                row.addEventListener('click', () => TraceDrawer.openTransaction(Number(row.dataset.txId)));
                row.style.cursor = 'pointer';
            });
            const viewAllBtn = host.querySelector('.hpp-trace-viewall-btn');
            if (viewAllBtn) {
                viewAllBtn.addEventListener('click', () => TraceDrawer.openTransaction(Number(detail.lines[0].transaction_id)));
            }
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="card"><div class="alert alert-error">Gagal memuat detail: ${(err && err.message) || ''}</div></div>`;
        }
    }

    return { render };
})();
