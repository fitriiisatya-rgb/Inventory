/**
 * PHASE V2.6B — Report 8 "Laporan Stock Opname". Read-only over
 * StockOpnameReportService (existing session model, unchanged
 * start/count/finalize/post workflow). No P1/P2 dual-count — single
 * blind count only, matching the current opname implementation.
 */
const ReportOpname = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const statusOptions = [
            { value: 'OPEN', label: 'Open' }, { value: 'FINALIZED', label: 'Finalized' },
            { value: 'POSTED', label: 'Posted' }, { value: 'CANCELLED', label: 'Cancelled' },
        ];

        ReportCommon.render(container, {
            title: 'Laporan Stock Opname',
            subtitle: 'Riwayat sesi stock opname — sistem model saat ini (single count).',
            storageKey: 'dt-report-opname',
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'status', label: 'Status', type: 'select', options: statusOptions },
            ],
            columns: [
                { key: 'session_date', label: 'Tanggal Sesi', render: (r) => r.session_date },
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'status', label: 'Status', render: (r) => r.status },
                { key: 'system_qty', label: 'Qty Sistem', render: (r) => UI.formatNumber(r.system_qty) },
                { key: 'counted_qty', label: 'Qty Dihitung', render: (r) => UI.formatNumber(r.counted_qty) },
                { key: 'variance_qty', label: 'Selisih Qty', render: (r) => varianceCell(r.variance_qty) },
                { key: 'variance_value', label: 'Selisih Nilai', render: (r) => varianceCell(r.variance_value, true) },
                { key: 'created_by', label: 'Dibuat Oleh', render: (r) => r.created_by },
                { key: 'finalized_at', label: 'Finalisasi', render: (r) => r.finalized_at || '-' },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') clean[k] = state[k]; });
                return InvApi.opnameReport(clean);
            },
            onRowClick: (row) => TraceDrawer.openOpname(row.id),
            emptyMessage: 'Tidak ada sesi stock opname untuk filter ini.',
        });
    }

    function varianceCell(value, isMoney) {
        const text = isMoney ? UI.formatMoney(value) : UI.formatNumber(value);
        const color = Math.abs(value) < 0.5 ? 'var(--text2)' : (value < 0 ? 'var(--orange)' : 'var(--green)');
        return UI.el('span', { style: `color:${color};` }, text);
    }

    return { render };
})();
