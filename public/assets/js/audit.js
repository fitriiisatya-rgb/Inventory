/**
 * D13 — Audit Log. Every row (before/after/reason) comes straight from
 * GET /audit-logs; this file only formats and filters what the server
 * already recorded, never reconstructs history from local state.
 *
 * Filter fields match what audit_logs actually stores server-side (date
 * range, username, action code, entity type) — there is no per-SKU or
 * per-warehouse column on this table, so those aren't offered as filters
 * here rather than faking a client-side filter that only covers the 500
 * most recent rows.
 */
const Audit = (() => {
    function render(container) {
        container.innerHTML = '';
        container.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📜 Audit Log')]),
            UI.el('div', { class: 'grid-4', html: `
                <div class="form-group"><label>Dari Tanggal</label><input type="date" id="audit-date-from"></div>
                <div class="form-group"><label>Sampai Tanggal</label><input type="date" id="audit-date-to"></div>
                <div class="form-group"><label>Username</label><input type="text" id="audit-username" placeholder="cari username..."></div>
                <div class="form-group"><label>Action Code</label><input type="text" id="audit-action" placeholder="mis. STOCK_ADJUSTMENT"></div>
            ` }),
            UI.el('button', { class: 'btn btn-primary btn-sm', id: 'audit-filter-btn' }, 'Filter'),
        ]));
        container.appendChild(UI.el('div', { id: 'audit-list-wrap' }));

        document.getElementById('audit-filter-btn').addEventListener('click', loadLogs);
        loadLogs();
    }

    async function loadLogs() {
        const wrap = document.getElementById('audit-list-wrap');
        wrap.innerHTML = '<div class="alert alert-info">Memuat audit log...</div>';
        const filters = {};
        const dateFrom = document.getElementById('audit-date-from').value;
        const dateTo = document.getElementById('audit-date-to').value;
        const username = document.getElementById('audit-username').value.trim();
        const action = document.getElementById('audit-action').value.trim();
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        if (username) filters.username = username;
        if (action) filters.action_code = action;

        try {
            const logs = await InvApi.auditLogs(filters);
            wrap.innerHTML = '';
            const rows = logs.map((log) => UI.el('tr', {}, [
                UI.el('td', {}, UI.formatDate(log.created_at)),
                UI.el('td', {}, log.username_snapshot),
                UI.el('td', {}, log.action_code),
                UI.el('td', {}, `${log.entity_type}${log.entity_id ? ` #${log.entity_id}` : ''}`),
                UI.el('td', {}, log.reason || '-'),
                UI.el('td', {}, UI.el('button', { class: 'btn btn-secondary btn-sm audit-detail-btn', 'data-id': String(log.id) }, 'Detail')),
            ]));
            const exportBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-bottom:10px;' }, '⬇️ Export CSV');
            exportBtn.addEventListener('click', () => UI.exportCsv('audit-log.csv', [
                ['created_at', 'Waktu'], ['username_snapshot', 'User'], ['action_code', 'Aksi'],
                ['entity_type', 'Entitas'], ['entity_id', 'ID Entitas'], ['reason', 'Alasan'],
            ], logs));
            wrap.appendChild(UI.el('div', { class: 'card' }, [
                exportBtn,
                UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['Waktu', 'User', 'Aksi', 'Entitas', 'Alasan', ''].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Tidak ada log yang cocok')])]),
                    ]),
                ]),
                UI.el('div', { style: 'font-size:0.75rem; color:var(--text3); margin-top:8px;' }, `Menampilkan hingga 500 log terbaru yang cocok dengan filter.`),
            ]));
            wrap.querySelectorAll('.audit-detail-btn').forEach((btn) => {
                const log = logs.find((l) => String(l.id) === btn.dataset.id);
                btn.addEventListener('click', () => showDetail(log));
            });
        } catch (err) {
            UI.handleApiError(err);
            wrap.innerHTML = `<div class="alert alert-error">Gagal memuat audit log: ${(err && err.message) || ''}</div>`;
        }
    }

    function showDetail(log) {
        const before = log.before_data ? JSON.stringify(JSON.parse(log.before_data), null, 2) : '(kosong)';
        const after = log.after_data ? JSON.stringify(JSON.parse(log.after_data), null, 2) : '(kosong)';
        alert(`Aksi: ${log.action_code}\nEntitas: ${log.entity_type} #${log.entity_id || '-'}\nAlasan: ${log.reason || '-'}\n\nSEBELUM:\n${before}\n\nSESUDAH:\n${after}`);
    }

    return { render };
})();
