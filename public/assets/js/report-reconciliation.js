/**
 * PHASE V2.6B — Report 14 "Rekonsiliasi Arus Stok", the control report.
 * Per warehouse (plus a consolidated Perusahaan row):
 *   SALDO AWAL + IN - OUT = THEORETICAL ENDING, compared against ACTUAL
 *   ENDING (live inventory_batches state). Difference is ALWAYS shown —
 *   never forced to zero, never hidden. Server-computed only; this file
 *   just renders InventoryReconciliationReportService's response.
 */
const ReportReconciliation = (() => {
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
                UI.el('h2', { class: 'hpp-title' }, 'Rekonsiliasi Arus Stok'),
                UI.el('div', { class: 'hpp-subtitle' }, 'Saldo Awal + Pergerakan = Saldo Akhir Teoritis, dibandingkan dengan Saldo Akhir Aktual'),
            ]),
        ]));
        container.appendChild(buildFilterBar());
        container.appendChild(UI.el('div', { id: 'recon-host' }));
        container.appendChild(UI.el('div', { class: 'hpp-legend' },
            'Saldo Akhir Aktual dibaca langsung dari batch stok saat ini (inventory_batches). Perbandingan hanya sepenuhnya presisi bila Tanggal Akhir = hari ini; ' +
            'bila periode berakhir di masa lalu, Selisih dapat mencerminkan transaksi yang terjadi setelah Tanggal Akhir — tetap ditampilkan, tidak pernah disembunyikan.'));

        load();
    }

    function buildFilterBar() {
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        const card = UI.el('div', { class: 'card hpp-filter-card' }, [
            UI.el('div', { class: 'hpp-filter-grid', html: `
                <div class="form-group"><label>Periode Tanggal</label>
                    <div class="hpp-date-range">
                        <input type="date" id="recon-start-date" value="${state.startDate}">
                        <span>—</span>
                        <input type="date" id="recon-end-date" value="${state.endDate}">
                    </div>
                </div>
                <div class="form-group"><label>Gudang</label><select id="recon-warehouse-select"><option value="">Semua Gudang</option>${whOptions}</select></div>
            ` }),
            UI.el('div', { class: 'hpp-filter-actions' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'recon-apply-btn' }, '🔧 Terapkan Filter'),
            ]),
        ]);
        setTimeout(() => {
            document.getElementById('recon-apply-btn').addEventListener('click', () => {
                state.startDate = document.getElementById('recon-start-date').value || state.startDate;
                state.endDate = document.getElementById('recon-end-date').value || state.endDate;
                state.warehouseId = document.getElementById('recon-warehouse-select').value;
                load();
            });
        }, 0);
        return card;
    }

    async function load() {
        const host = document.getElementById('recon-host');
        host.innerHTML = '<div class="alert alert-info">Memuat rekonsiliasi...</div>';
        try {
            const result = await InvApi.reconciliationMovement({
                start_date: state.startDate, end_date: state.endDate, warehouse_id: state.warehouseId || undefined,
            });
            host.innerHTML = '';
            result.scopes.forEach((scope) => host.appendChild(scopeCard(scope)));
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="alert alert-error">Gagal memuat rekonsiliasi: ${(err && err.message) || ''}</div>`;
        }
    }

    function scopeCard(s) {
        const balanced = s.status === 'BALANCE';
        const notComparable = s.status === 'NOT_COMPARABLE';
        const statusLabel = { BALANCE: '✅ BALANCE', REVIEW: '⚠️ REVIEW', NOT_COMPARABLE: 'ℹ️ NOT COMPARABLE' }[s.status] || s.status;
        const rows = [
            ['Saldo Awal', s.saldo_awal],
            ['+ Pembelian Eksternal', s.external_purchase],
            ['+ Lainnya (Masuk)', s.other_in],
        ];
        if (s.warehouse_id !== null) rows.push(['+ Transfer IN', s.transfer_in]);
        rows.push(['+ Adjustment Positif', s.adjustment_positive]);
        rows.push(['− OUT / Pemakaian', -s.out_usage]);
        if (s.warehouse_id !== null) rows.push(['− Transfer OUT', -s.transfer_out]);
        rows.push(['− Adjustment Negatif', -s.adjustment_negative]);
        rows.push(['− Lainnya (Keluar)', -s.other_out]);
        if (s.warehouse_id === null && s.transfer_elimination !== undefined) {
            rows.push(['Transfer Internal (Eliminasi)', s.transfer_elimination]);
        }

        return UI.el('div', { class: `card hpp-warehouse-panel ${balanced ? '' : (notComparable ? '' : 'hpp-variance-warn')}` }, [
            UI.el('div', { class: 'hpp-warehouse-panel-header' }, [
                UI.el('div', {}, [
                    UI.el('div', { class: 'hpp-warehouse-panel-title' }, `🏢 ${s.warehouse_name}`),
                    s.is_same_day_check === false ? UI.el('div', { class: 'hpp-warehouse-panel-subtitle' }, 'Perbandingan Saldo Akhir Aktual hanya berlaku untuk Tanggal Akhir = hari ini.') : null,
                ]),
                UI.el('div', { class: `hpp-kpi-delta ${balanced ? 'up' : (notComparable ? 'neutral' : 'down')}` }, statusLabel),
            ]),
            s.note ? UI.el('div', { class: 'alert alert-info' }, s.note) : null,
            notComparable ? UI.el('div', { class: 'alert alert-info' }, s.reason || 'Historical physical inventory snapshot is not available.') : null,
            UI.el('div', { class: 'hpp-bridge-table' }, rows.map(([label, value]) => UI.el('div', { class: 'hpp-bridge-row' }, [
                UI.el('span', {}, label), UI.el('span', { class: 'mono' }, UI.formatMoney(value)),
            ]))),
            UI.el('div', { class: 'hpp-bridge-divider' }),
            UI.el('div', { class: 'hpp-bridge-row hpp-bridge-total' }, [UI.el('span', {}, 'Saldo Akhir Teoritis'), UI.el('span', { class: 'mono' }, UI.formatMoney(s.theoretical_ending))]),
            UI.el('div', { class: 'hpp-bridge-row hpp-bridge-total' }, [UI.el('span', {}, 'Saldo Akhir Aktual'), UI.el('span', { class: 'mono' }, s.actual_available ? UI.formatMoney(s.actual_value) : 'NOT COMPARABLE')]),
            UI.el('div', { class: `hpp-bridge-row hpp-bridge-total ${balanced ? '' : (notComparable ? '' : 'hpp-bridge-warn')}` }, [UI.el('span', {}, 'Selisih'), UI.el('span', { class: 'mono' }, s.difference === null ? '—' : UI.formatMoney(s.difference))]),
        ]);
    }

    return { render };
})();
