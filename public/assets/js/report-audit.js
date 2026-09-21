/**
 * PHASE V2.6B/V2.6C — Report 15 "Audit Transaksi". Reuse, not a second
 * audit source: calls the EXISTING TraceService::browseEvents() (the
 * same read path the Trace Center already relies on) via the new
 * GET /reports/audit route — neither TraceService nor the audit_logs
 * table is touched or replaced. V2.6C swaps this off the old flat
 * GET /audit-logs (500-row cap, no real pagination) onto proper
 * server-side page/per_page/total/total_pages, capped at 100/page.
 * browseEvents() only ever projects {id, action_code, entity_type,
 * entity_id, actor, before, after, reason, created_at} — password/
 * session/CSRF fields are never part of that shape.
 */
const ReportAudit = (() => {
    function render(container) {
        ReportCommon.render(container, {
            title: 'Audit Transaksi',
            subtitle: 'Log audit sistem dengan pagination server-side — sumber data sama dengan Trace Center.',
            storageKey: 'dt-report-audit',
            note: 'Sumber data sama dengan Audit & Control → Audit Log / Trace Center — tidak ada audit_logs kedua.',
            exportUrl: (state) => InvApi.auditReportExportUrl(clean(state)),
            filters: [
                { key: 'date_from', label: 'Dari Tanggal', type: 'date' },
                { key: 'date_to', label: 'Sampai Tanggal', type: 'date' },
                { key: 'username', label: 'User', type: 'text', placeholder: 'Cari username...' },
                { key: 'action_code', label: 'Action', type: 'text', placeholder: 'e.g. TRANSACTION_VOID' },
                { key: 'entity_type', label: 'Entity', type: 'text', placeholder: 'e.g. inventory_transactions' },
            ],
            columns: [
                { key: 'created_at', label: 'Timestamp', render: (r) => r.created_at },
                { key: 'actor', label: 'User', render: (r) => r.actor },
                { key: 'action_code', label: 'Action', render: (r) => r.action_code },
                { key: 'entity_type', label: 'Entity Type', render: (r) => r.entity_type },
                { key: 'entity_id', label: 'Referensi', render: (r) => r.entity_id ?? '-' },
                { key: 'reason', label: 'Ringkasan', render: (r) => r.reason || '-' },
            ],
            fetchPage: async (state) => {
                const result = await InvApi.auditReport(clean(state));
                return { rows: result.rows, pagination: result.pagination };
            },
            onRowClick: (row) => openDetail(row),
            emptyMessage: 'Tidak ada log audit untuk filter ini.',
        });
    }

    function clean(state) {
        const c = {};
        Object.keys(state).forEach((k) => { if (state[k] !== undefined && state[k] !== '') c[k] = state[k]; });
        return c;
    }

    function openDetail(row) {
        Drawer.open({
            title: `${row.action_code} — ${row.entity_type} #${row.entity_id ?? '-'}`,
            render: (body) => {
                body.appendChild(UI.el('div', { class: 'hpp-trace-date-chip' }, [UI.el('span', {}, '📅'), ` ${row.created_at} — ${row.actor}`]));
                if (row.reason) body.appendChild(UI.el('p', {}, row.reason));
                body.appendChild(UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:12px;' }, 'Before'));
                body.appendChild(UI.el('pre', { style: 'white-space:pre-wrap; font-size:0.72rem; background:var(--bg2); padding:8px; border-radius:6px;' }, formatJson(row.before)));
                body.appendChild(UI.el('div', { class: 'hpp-trace-section-label', style: 'margin-top:12px;' }, 'After'));
                body.appendChild(UI.el('pre', { style: 'white-space:pre-wrap; font-size:0.72rem; background:var(--bg2); padding:8px; border-radius:6px;' }, formatJson(row.after)));
            },
        });
    }

    function formatJson(value) {
        if (value === null || value === undefined) return '(kosong)';
        try {
            return JSON.stringify(value, null, 2);
        } catch (e) {
            return String(value);
        }
    }

    return { render };
})();
