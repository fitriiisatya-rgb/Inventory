/**
 * PHASE V2.6B — Report 8 "Laporan Stock Opname". Read-only over
 * StockOpnameReportService.
 *
 * PHASE V2.12C: now also shows the dual-count columns (session number,
 * P1/P2/supervisor, match/mismatch) added in V2.12A/B — a legacy
 * single-count session simply shows "-" for P1/P2/supervisor and 0 for
 * match/mismatch, so nothing about an old session's row changes.
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
            subtitle: 'Riwayat sesi stock opname — termasuk dual-count P1/P2 (PHASE V2.12).',
            storageKey: 'dt-report-opname',
            exportUrl: (state) => InvApi.opnameReportExportUrl(state),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'warehouse_id', label: 'Gudang', type: 'select', options: whOptions },
                { key: 'status', label: 'Status', type: 'select', options: statusOptions },
            ],
            columns: [
                { key: 'session_number', label: 'No. Sesi', render: (r) => r.session_number || `OPN-${r.id}` },
                { key: 'session_date', label: 'Tanggal Sesi', render: (r) => r.session_date },
                { key: 'warehouse', label: 'Gudang', render: (r) => r.warehouse.name },
                { key: 'status', label: 'Status', render: (r) => r.status },
                { key: 'p1', label: 'P1', render: (r) => r.p1 || '-' },
                { key: 'p2', label: 'P2', render: (r) => r.p2 || '-' },
                { key: 'supervisor', label: 'Supervisor', render: (r) => r.supervisor || '-' },
                { key: 'match_count', label: 'Match', render: (r) => UI.formatNumber(r.match_count, 0) },
                { key: 'mismatch_count', label: 'Mismatch', render: (r) => UI.formatNumber(r.mismatch_count, 0) },
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
