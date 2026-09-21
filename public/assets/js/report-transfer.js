/**
 * PHASE V2.6B — Report 6 "Laporan Transfer". Read-only over
 * TransferReportService (existing warehouse_transfers/lines data,
 * unchanged create/receive/cancel/reverse business logic). Partial
 * receiving is not implemented — full-receive-only stays as-is.
 */
const ReportTransferList = (() => {
    function render(container) {
        const whOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const statusOptions = [
            { value: 'PENDING', label: 'Pending' }, { value: 'RECEIVED', label: 'Received' },
            { value: 'CANCELLED', label: 'Cancelled' }, { value: 'REVERSED', label: 'Reversed' },
        ];

        ReportCommon.render(container, {
            title: 'Laporan Transfer',
            subtitle: 'Riwayat transfer antar gudang — status, nilai, dan lead time.',
            storageKey: 'dt-report-transfer',
            exportUrl: (state) => InvApi.transferReportExportUrl(state),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal Kirim', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal Kirim', type: 'date' },
                { key: 'from_warehouse_id', label: 'Dari Gudang', type: 'select', options: whOptions },
                { key: 'to_warehouse_id', label: 'Ke Gudang', type: 'select', options: whOptions },
                { key: 'status', label: 'Status', type: 'select', options: statusOptions },
            ],
            columns: [
                { key: 'id', label: 'No. Transfer', render: (r) => `TRF-${r.id}` },
                { key: 'ship_date', label: 'Tanggal Kirim', render: (r) => fmtDate(r.ship_date) },
                { key: 'from_warehouse', label: 'Dari', render: (r) => r.from_warehouse.name },
                { key: 'to_warehouse', label: 'Ke', render: (r) => r.to_warehouse.name },
                { key: 'status', label: 'Status', render: (r) => statusBadge(r) },
                { key: 'item_count', label: 'Jumlah SKU', render: (r) => UI.formatNumber(r.item_count) },
                { key: 'transfer_value', label: 'Nilai Transfer', render: (r) => UI.formatMoney(r.transfer_value) },
                { key: 'lead_time_days', label: 'Lead Time', render: (r) => r.lead_time_days !== null ? `${r.lead_time_days} hari` : '-' },
                { key: 'created_by', label: 'Dibuat Oleh', render: (r) => r.created_by },
                { key: 'received_by', label: 'Diterima Oleh', render: (r) => r.received_by || '-' },
            ],
            fetchPage: async (state) => {
                const clean = {};
                Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') clean[k] = state[k]; });
                return InvApi.transferReport(clean);
            },
            onRowClick: (row) => TraceDrawer.openTransfer(row.id),
            emptyMessage: 'Tidak ada transfer untuk filter ini.',
        });
    }

    function statusBadge(r) {
        const labels = { PENDING: 'Pending', RECEIVED: 'Received', CANCELLED: 'Cancelled', REVERSED: 'Reversed' };
        let extra = '';
        if (r.status === 'CANCELLED' && r.cancel) extra = ` — ${r.cancel.reason}`;
        if (r.status === 'REVERSED' && r.reverse) extra = ` — ${r.reverse.reason}`;
        return `${labels[r.status] || r.status}${extra}`;
    }
    function fmtDate(value) {
        if (!value) return '-';
        return new Date(value.replace(' ', 'T')).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    return { render };
})();
