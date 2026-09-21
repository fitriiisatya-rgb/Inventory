/**
 * PHASE V2.6B — Report 15 "Audit Transaksi". Pure reuse: calls the
 * EXISTING GET /audit-logs (same audit_logs table the "Audit Log" tab
 * under Audit & Control already reads) — no new audit table, no second
 * audit source. This is a report-style filtered view of the exact same
 * data, under the Laporan menu as the approved menu structure requires.
 * Never exposes password_hash/session data — audit_logs itself never
 * stores those (see schema: before_data/after_data are scoped per
 * action_code by each service's own AuditService::log() call).
 */
const ReportAudit = (() => {
    function render(container) {
        ReportCommon.render(container, {
            title: 'Audit Transaksi',
            subtitle: 'Log audit sistem — sama dengan data Audit Log, ditampilkan sebagai laporan yang dapat difilter.',
            storageKey: 'dt-report-audit',
            note: 'Data ini sama persis dengan menu Audit & Control → Audit Log — tidak ada sumber audit kedua.',
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'username', label: 'User', type: 'text', placeholder: 'Cari username...' },
                { key: 'action_code', label: 'Action', type: 'text', placeholder: 'e.g. TRANSACTION_VOID' },
                { key: 'entity_type', label: 'Entity', type: 'text', placeholder: 'e.g. inventory_transactions' },
            ],
            columns: [
                { key: 'created_at', label: 'Timestamp', render: (r) => r.created_at },
                { key: 'username_snapshot', label: 'User', render: (r) => r.username_snapshot },
                { key: 'action_code', label: 'Action', render: (r) => r.action_code },
                { key: 'entity_type', label: 'Entity Type', render: (r) => r.entity_type },
                { key: 'entity_id', label: 'Referensi', render: (r) => r.entity_id ?? '-' },
                { key: 'reason', label: 'Ringkasan', render: (r) => r.reason || '-' },
            ],
            fetchPage: async (state) => {
                const clean = {};
                ['date_from', 'date_to', 'username', 'action_code', 'entity_type'].forEach((k) => { if (state[k]) clean[k] = state[k]; });
                const rows = await InvApi.auditLogs(clean);
                const page = state.page || 1;
                const perPage = state.per_page || 25;
                const start = (page - 1) * perPage;
                return {
                    rows: rows.slice(start, start + perPage),
                    pagination: { page, total_pages: Math.max(1, Math.ceil(rows.length / perPage)), total: rows.length },
                };
            },
            onRowClick: (row) => openDetail(row),
            emptyMessage: 'Tidak ada log audit untuk filter ini. (Menampilkan maksimal 500 baris terbaru.)',
        });
    }

    function openDetail(row) {
        Drawer.open({
            title: `${row.action_code} — ${row.entity_type} #${row.entity_id ?? '-'}`,
            render: (body) => {
                body.appendChild(UI.el('div', { class: 'hpp-trace-date-chip' }, [UI.el('span', {}, '📅'), ` ${row.created_at} — ${row.username_snapshot}`]));
                if (row.reason) body.appendChild(UI.el('p', {}, row.reason));
                body.appendChild(UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:12px;' }, 'Before'));
                body.appendChild(UI.el('pre', { style: 'white-space:pre-wrap; font-size:0.72rem; background:var(--bg2); padding:8px; border-radius:6px;' }, formatJson(row.before_data)));
                body.appendChild(UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:12px;' }, 'After'));
                body.appendChild(UI.el('pre', { style: 'white-space:pre-wrap; font-size:0.72rem; background:var(--bg2); padding:8px; border-radius:6px;' }, formatJson(row.after_data)));
            },
        });
    }

    function formatJson(raw) {
        if (!raw) return '(kosong)';
        try {
            return JSON.stringify(typeof raw === 'string' ? JSON.parse(raw) : raw, null, 2);
        } catch (e) {
            return String(raw);
        }
    }

    return { render };
})();
