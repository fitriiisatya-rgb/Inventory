/**
 * PHASE V2.6B — Report 1 "Ringkasan Inventory": management overview for
 * a selected warehouse + period. Every number is computed server-side by
 * InventorySummaryReportService; this file only renders and provides
 * click-through navigation into the more detailed reports (never
 * decorative/duplicate dashboard content of its own).
 */
const ReportSummary = (() => {
    let state = null;

    function defaultRange() {
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth(), 1);
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        const fmt = (d) => d.toISOString().slice(0, 10);
        return { start: fmt(start), end: fmt(end) };
    }

    function render(container) {
        const range = defaultRange();
        state = { startDate: range.start, endDate: range.end, warehouseId: '' };

        container.innerHTML = '';
        container.appendChild(UI.el('div', { class: 'hpp-page-header' }, [
            UI.el('div', {}, [
                UI.el('h2', { class: 'hpp-title' }, 'Ringkasan Inventory'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Gambaran umum untuk manajemen — gudang dan periode terpilih'),
            ]),
        ]));
        container.appendChild(buildFilterBar());
        container.appendChild(UI.el('div', { id: 'summary-host' }));

        load();
    }

    function buildFilterBar() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const card = UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid', html: `
                <div class="form-group"><label>Periode Tanggal</label>
                    <div class="hpp-date-range">
                        <input type="date" id="summary-start-date" value="${state.startDate}">
                        <span>—</span>
                        <input type="date" id="summary-end-date" value="${state.endDate}">
                    </div>
                </div>
                <div class="form-group"><label>Gudang</label><select id="summary-warehouse-select"><option value="">Semua Gudang</option>${whOptions}</select></div>
            ` }),
            UI.el('div', { class: 'hpp-filter-actions' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'summary-apply-btn' }, '🔧 Terapkan Filter'),
            ]),
        ]);
        setTimeout(() => {
            document.getElementById('summary-apply-btn').addEventListener('click', () => {
                state.startDate = document.getElementById('summary-start-date').value || state.startDate;
                state.endDate = document.getElementById('summary-end-date').value || state.endDate;
                state.warehouseId = document.getElementById('summary-warehouse-select').value;
                load();
            });
        }, 0);
        return card;
    }

    async function load() {
        const host = document.getElementById('summary-host');
        host.innerHTML = '<div class="alert alert-info">Memuat ringkasan...</div>';
        try {
            const s = await InvApi.summaryInventory({ start_date: state.startDate, end_date: state.endDate, warehouse_id: state.warehouseId || undefined });
            host.innerHTML = '';

            const kpiRow = UI.el('div', { class: 'hpp-kpi-row' }, [
                kpi('📥', 'Nilai Awal', s.beginning_inventory_value),
                kpi('🛒', 'Pembelian Eksternal', s.external_purchase),
                kpi('📦', 'HPP FIFO (OUT)', s.fifo_hpp, 'Biaya aktual FIFO — lihat Nilai Stok & HPP untuk rincian.'),
                kpi('📤', 'Nilai Akhir', s.ending_inventory_value),
            ]);
            host.appendChild(kpiRow);

            const opsRow = UI.el('div', { class: 'hpp-kpi-row' }, [
                kpi('🏷️', 'SKU Aktif', s.active_sku, null, true),
                kpi('📊', 'SKU Ada Stok', s.sku_with_stock, null, true),
                kpi('🚚', 'Transfer Pending', s.pending_transfers, 'Klik untuk lihat di Laporan Transfer', true, () => navigate('laporan-transfer')),
                kpi('📋', 'Opname Aktif', s.active_opname, null, true),
            ]);
            host.appendChild(opsRow);

            const flagRow = UI.el('div', { class: 'hpp-kpi-row' });
            flagRow.appendChild(kpi('⚠️', 'Migration Negative', s.migration_negative_count, null, true));
            if (s.expiry_warning && s.expiry_warning.has_data) {
                flagRow.appendChild(kpi('⏰', 'Expiry ≤ 30 Hari', s.expiry_warning.count_30d, null, true));
            }
            host.appendChild(flagRow);

            const movCard = UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🧮 Pergerakan Periode Ini') ]),
                UI.el('div', { class: 'hpp-bridge-table' }, movementRows(s)),
            ]);
            const clickHint = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-top:10px;' }, 'Lihat Detail Harian →');
            clickHint.addEventListener('click', () => navigate('laporan-pergerakan'));
            movCard.appendChild(clickHint);
            host.appendChild(movCard);
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="alert alert-error">Gagal memuat ringkasan: ${(err && err.message) || ''}</div>`;
        }
    }

    function movementRows(s) {
        const rows = [
            ['Pembelian Eksternal', s.external_purchase],
            ['Lainnya (Masuk)', s.other_in],
        ];
        if (s.transfer_in !== null) rows.push(['Transfer IN', s.transfer_in]);
        rows.push(['Adjustment Positif', s.adjustment_positive]);
        rows.push(['OUT / Pemakaian', -s.out_usage]);
        if (s.transfer_out !== null) rows.push(['Transfer OUT', -s.transfer_out]);
        rows.push(['Adjustment Negatif', -s.adjustment_negative]);
        rows.push(['Lainnya (Keluar)', -s.other_out]);
        if (s.transfer_elimination !== null) rows.push(['Transfer Internal (Eliminasi)', s.transfer_elimination]);
        return rows.map(([label, value]) => UI.el('div', { class: 'hpp-bridge-row' }, [
            UI.el('span', {}, label), UI.el('span', { class: 'mono' }, UI.formatMoney(value)),
        ]));
    }

    function kpi(icon, label, value, note, isCount, onClick) {
        const card = UI.el('div', { class: `card hpp-kpi-card${onClick ? ' hpp-kpi-clickable' : ''}` }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, label),
                UI.el('div', { class: 'hpp-kpi-value' }, isCount ? UI.formatNumber(value) : UI.formatMoney(value)),
                note ? UI.el('div', { class: 'hpp-kpi-subnote' }, note) : null,
            ]),
        ]);
        if (onClick) card.addEventListener('click', onClick);
        return card;
    }

    function navigate(tab) {
        const link = document.querySelector(`.sidebar-link[data-tab="${tab}"]`);
        if (link) link.click();
    }

    return { render };
})();
