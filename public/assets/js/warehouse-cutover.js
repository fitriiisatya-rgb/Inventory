/**
 * PHASE V2.14 — Karang Tengah Cutover admin page. The underlying tables/
 * service (WarehouseCutoverService) are GENERIC — this page just always
 * targets the warehouse whose code is KARANG_TENGAH, since that is the
 * only cutover in scope for this phase. No action here ever unlocks or
 * activates the warehouse (see Load Opening's own confirm dialog) — that
 * remains a separate, later, explicitly-approved step via Master Gudang.
 */
const WarehouseCutover = (() => {
    let container = null;
    let karangTengah = null;
    let cutover = null;
    let dtHandle = null;

    async function render(el) {
        container = el;
        container.innerHTML = '';
        const wrap = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🗂️ Karang Tengah Cutover')]),
        ]);
        container.appendChild(wrap);
        const body = UI.el('div', { id: 'wc-body' });
        wrap.appendChild(body);
        await loadKarangTengah();
        await refresh();
    }

    async function loadKarangTengah() {
        const result = await InvApi.warehousesReport({ q: 'KARANG_TENGAH' });
        karangTengah = (result.rows || []).find((r) => r.code === 'KARANG_TENGAH') || null;
    }

    async function refresh() {
        const body = document.getElementById('wc-body');
        if (!body) return;
        body.innerHTML = '';

        if (!karangTengah) {
            body.appendChild(UI.el('div', { class: 'alert alert-warning' }, 'Gudang Karang Tengah tidak ditemukan.'));
            return;
        }

        if (!cutover) {
            // Look for any existing cutover on this warehouse we don't yet
            // know the id of — a real page would list them; this phase's
            // scope is a single cutover, so a create form is offered here
            // and the resulting id is kept in memory only.
            body.appendChild(buildCreateForm());
            return;
        }

        try {
            const detail = await InvApi.getWarehouseCutover(cutover.id);
            cutover = detail.cutover;
            body.appendChild(buildHeader(detail));
            body.appendChild(buildSummaryCards(detail.summary));
            body.appendChild(await buildLinesTable());
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    function buildCreateForm() {
        const nameInput = UI.el('input', { type: 'text', class: 'form-control', placeholder: 'Karang_Tengah_Reconciliation_Report_V2.xlsx', value: 'Karang_Tengah_Reconciliation_Report_V2.xlsx' });
        const dateInput = UI.el('input', { type: 'date', class: 'form-control' });
        const btn = UI.el('button', { class: 'btn btn-primary' }, 'Buat Cutover Baru');
        btn.addEventListener('click', async () => {
            if (!dateInput.value) {
                UI.toast('Isi tanggal Opening As Of.', 'error');
                return;
            }
            try {
                const result = await InvApi.createWarehouseCutover({
                    warehouse_id: karangTengah.id,
                    source_name: nameInput.value,
                    opening_as_of: dateInput.value,
                });
                cutover = { id: result.cutover_id };
                UI.toast('Cutover dibuat. Silakan unggah workbook rekonsiliasi.', 'success');
                await refresh();
            } catch (err) {
                UI.handleApiError(err);
            }
        });
        return UI.el('div', { class: 'form-group', style: 'max-width:480px;' }, [
            UI.el('p', {}, 'Belum ada cutover aktif untuk Gudang Karang Tengah pada sesi ini.'),
            UI.el('label', {}, 'Nama Sumber'), nameInput,
            UI.el('label', { style: 'margin-top:10px;' }, 'Opening As Of'), dateInput,
            UI.el('div', { style: 'margin-top:14px;' }, [btn]),
        ]);
    }

    function statusBadgeClass(status) {
        return { DRAFT: 'badge-cancelled', VALIDATED: 'badge-pending', REVIEW_REQUIRED: 'badge-pending', RECONCILED: 'badge-pending', APPROVED: 'badge-received', LOADED: 'badge-received', ACTIVATED: 'badge-received' }[status] || 'badge-cancelled';
    }

    function buildHeader(detail) {
        const canManage = Auth.hasPermission('WAREHOUSE_CUTOVER_MANAGE');
        const isSuperadmin = Auth.hasRole('SUPERADMIN');
        const fileInput = UI.el('input', { type: 'file', accept: '.xlsx', style: 'display:none;' });
        const uploadBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '📥 Unggah Workbook');
        uploadBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', async () => {
            const file = fileInput.files[0];
            if (!file) return;
            try {
                const uploaded = await InvApi.uploadImportFile(file);
                const summary = await InvApi.importWarehouseCutover(cutover.id, uploaded.file_path);
                UI.toast(`Import selesai: ${summary.total_rows} baris (PASS ${summary.PASS}, REVIEW ${summary.REVIEW}, CRITICAL ${summary.CRITICAL}, NO_ACTIVITY ${summary.NO_ACTIVITY}).`, 'success');
                await refresh();
            } catch (err) {
                UI.handleApiError(err);
            }
        });

        const matchBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '🔗 Match Items');
        matchBtn.addEventListener('click', async () => {
            try {
                const counts = await InvApi.matchWarehouseCutoverItems(cutover.id);
                UI.toast(`Matching selesai: ${counts.MATCHED} MATCHED, ${counts.NOT_FOUND} NOT_FOUND, ${counts.NAME_MISMATCH} NAME_MISMATCH, ${counts.UNIT_MISMATCH} UNIT_MISMATCH.`, 'success');
                await refresh();
            } catch (err) {
                UI.handleApiError(err);
            }
        });

        const approveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, '✅ Approve');
        approveBtn.addEventListener('click', async () => {
            if (!(await Modal.confirm({ title: 'Approve Cutover', message: 'Setujui cutover ini? Tidak dapat disetujui jika masih ada baris CRITICAL/REVIEW yang belum diresolusi.' }))) return;
            try {
                await InvApi.approveWarehouseCutover(cutover.id);
                UI.toast('Cutover disetujui.', 'success');
                await refresh();
            } catch (err) {
                UI.handleApiError(err);
            }
        });

        const previewBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '👁️ Preview Opening');
        previewBtn.addEventListener('click', () => openPreview());

        const loadBtn = UI.el('button', { class: 'btn btn-primary btn-sm', style: 'background:#a33;' }, '⚠️ Load Opening (Test DB Only)');
        loadBtn.addEventListener('click', async () => {
            if (!(await Modal.confirm({ title: 'Load Opening (Test DB Only)', message: 'PERINGATAN: ini akan memposting saldo opening FIFO nyata untuk Karang Tengah. HANYA jalankan di database TEST/STAGING, TIDAK PERNAH di production. Lanjutkan?', danger: true }))) return;
            try {
                const result = await InvApi.loadWarehouseCutoverOpening(cutover.id);
                UI.toast(`Opening dimuat: ${result.line_count} baris, qty ${UI.formatNumber(result.total_qty)}, nilai ${UI.formatMoney(result.total_value)}.`, 'success');
                await refresh();
            } catch (err) {
                UI.handleApiError(err);
            }
        });

        const actions = [];
        if (canManage && cutover.status === 'DRAFT') actions.push(uploadBtn, fileInput);
        if (canManage && !['LOADED', 'ACTIVATED'].includes(cutover.status)) actions.push(matchBtn);
        if (canManage && cutover.status === 'RECONCILED') actions.push(approveBtn);
        if (canManage) actions.push(previewBtn);
        if (canManage && isSuperadmin && cutover.status === 'APPROVED') actions.push(loadBtn);

        return UI.el('div', { style: 'margin-bottom:16px;' }, [
            UI.el('div', { style: 'display:flex; align-items:center; gap:12px; flex-wrap:wrap;' }, [
                UI.el('span', { class: `badge ${statusBadgeClass(cutover.status)}` }, cutover.status),
                UI.el('span', {}, `Sumber: ${cutover.source_name}`),
                UI.el('span', {}, `Opening As Of: ${cutover.opening_as_of}`),
                UI.el('span', {}, `Total Baris: ${cutover.total_rows}`),
                ...actions,
            ]),
        ]);
    }

    function statCard(label, value, danger) {
        return UI.el('div', { class: 'stat-card', style: `padding:10px 14px; border-radius:8px; background:${danger && value > 0 ? '#3a1a1a' : '#1a2a3a'}; min-width:120px;` }, [
            UI.el('div', { style: 'font-size:12px; opacity:.75;' }, label),
            UI.el('div', { style: 'font-size:20px; font-weight:600;' }, String(value)),
        ]);
    }

    function buildSummaryCards(s) {
        return UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;' }, [
            statCard('PASS', s.pass_count),
            statCard('REVIEW', s.review_count, true),
            statCard('CRITICAL', s.critical_count, true),
            statCard('NO_ACTIVITY', s.no_activity_count),
            statCard('Unresolved Mapping', s.unresolved_mapping_count, true),
            statCard('Negative Closing', s.negative_closing_count, true),
            statCard('Unit Conflict', s.unit_conflict_count, true),
            statCard('Missing Price', s.missing_price_count, true),
            statCard('Duplicate Impact', s.duplicate_impact_count, true),
            statCard('Approved Rows', s.approved_rows_count),
        ]);
    }

    async function buildLinesTable() {
        const host = UI.el('div');
        dtHandle = DataTable.render(host, {
            storageKey: 'dt-warehouse-cutover-lines',
            filters: [
                { key: 'q', label: 'Cari', type: 'text', placeholder: 'SKU / Nama' },
                { key: 'reconciliation_status', label: 'Status', type: 'select', placeholder: 'Semua Status', options: [{ value: 'PASS', label: 'PASS' }, { value: 'REVIEW', label: 'REVIEW' }, { value: 'CRITICAL', label: 'CRITICAL' }, { value: 'NO_ACTIVITY', label: 'NO_ACTIVITY' }] },
                { key: 'decision', label: 'Decision', type: 'select', placeholder: 'Semua Decision', options: [{ value: 'PENDING', label: 'PENDING' }, { value: 'ACCEPT_SOURCE', label: 'ACCEPT_SOURCE' }, { value: 'BUSINESS_OVERRIDE', label: 'BUSINESS_OVERRIDE' }, { value: 'EXCLUDE', label: 'EXCLUDE' }] },
                { key: 'mapping_status', label: 'Master Match', type: 'select', placeholder: 'Semua', options: [{ value: 'MATCHED', label: 'MATCHED' }, { value: 'NOT_FOUND', label: 'NOT_FOUND' }, { value: 'NAME_MISMATCH', label: 'NAME_MISMATCH' }, { value: 'UNIT_MISMATCH', label: 'UNIT_MISMATCH' }] },
            ],
            defaultSort: 'source_row_reference', defaultDir: 'asc', pageSize: 25,
            columns: [
                { key: 'source_sku', label: 'SKU', render: (r) => r.source_sku },
                { key: 'source_name', label: 'Name', render: (r) => r.source_name },
                { key: 'source_unit', label: 'Unit', render: (r) => r.source_unit },
                { key: 'opening_qty', label: 'Opening', render: (r) => UI.formatNumber(r.opening_qty) },
                { key: 'in_qty', label: 'IN', render: (r) => UI.formatNumber(r.in_qty) },
                { key: 'out_qty', label: 'OUT', render: (r) => UI.formatNumber(r.out_qty) },
                { key: 'theoretical_closing_qty', label: 'Theoretical Closing', render: (r) => UI.formatNumber(r.theoretical_closing_qty) },
                { key: 'reconciliation_status', label: 'Status', render: (r) => UI.el('span', { class: `badge ${r.reconciliation_status === 'CRITICAL' ? 'badge-cancelled' : r.reconciliation_status === 'REVIEW' ? 'badge-pending' : 'badge-received'}` }, r.reconciliation_status) },
                { key: 'exception_codes', label: 'Exceptions', render: (r) => r.exception_codes || '-' },
                { key: 'mapping_status', label: 'Master Match', render: (r) => r.mapping_status },
                { key: 'decision', label: 'Decision', render: (r) => r.decision },
                { key: 'approved_qty', label: 'Approved Qty', render: (r) => (r.approved_qty !== null ? UI.formatNumber(r.approved_qty) : '-') },
                { key: 'approved_unit_cost', label: 'Approved Unit Cost', render: (r) => (r.approved_unit_cost !== null ? UI.formatMoney(r.approved_unit_cost) : '-') },
                { key: 'notes', label: 'Notes', render: (r) => UI.el('span', { title: r.notes || '' }, (r.notes || '').slice(0, 40)) },
                { key: 'actions', label: 'Aksi', render: (r) => buildLineActions(r) },
            ],
            fetchPage: async ({ page, perPage, filters: f }) => {
                const result = await InvApi.warehouseCutoverLines(cutover.id, f);
                const total = result.rows.length;
                const start = (page - 1) * perPage;
                return { rows: result.rows.slice(start, start + perPage), pagination: { page, per_page: perPage, total, total_pages: Math.max(1, Math.ceil(total / perPage)) } };
            },
            emptyMessage: 'Belum ada baris — unggah workbook rekonsiliasi terlebih dahulu.',
        });
        return host;
    }

    function buildLineActions(row) {
        const btn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Resolusi');
        btn.addEventListener('click', () => openResolveModal(row));
        return btn;
    }

    async function openResolveModal(row) {
        const values = await MasterCommon.formModal({
            title: `Resolusi Baris: ${row.source_sku} — ${row.source_name}`,
            submitLabel: 'Simpan',
            initial: {
                decision: row.decision,
                item_id: row.item_id || '',
                approved_qty: row.approved_qty ?? '',
                approved_unit_cost: row.approved_unit_cost ?? '',
                notes: row.notes || '',
            },
            fields: [
                { key: 'decision', label: 'Decision', type: 'select', allowEmpty: false, options: [{ value: 'PENDING', label: 'PENDING' }, { value: 'ACCEPT_SOURCE', label: 'Accept Source' }, { value: 'BUSINESS_OVERRIDE', label: 'Business Decision (Correct Qty/Cost)' }, { value: 'EXCLUDE', label: 'Exclude from Opening' }] },
                { key: 'item_id', label: 'Map to Item ID (kosongkan jika tidak berubah)', type: 'text' },
                { key: 'approved_qty', label: 'Approved Qty (wajib untuk Business Decision)', type: 'text' },
                { key: 'approved_unit_cost', label: 'Approved Unit Cost', type: 'text' },
                { key: 'notes', label: 'Catatan (alasan keputusan)', type: 'text' },
            ],
        });
        if (!values) return;
        try {
            const payload = { decision: values.decision, notes: values.notes || null };
            if (values.item_id !== '') payload.item_id = Number(values.item_id);
            if (values.decision === 'BUSINESS_OVERRIDE') {
                payload.approved_qty = values.approved_qty !== '' ? Number(values.approved_qty) : null;
                if (values.approved_unit_cost !== '') payload.approved_unit_cost = Number(values.approved_unit_cost);
            }
            await InvApi.resolveWarehouseCutoverLine(cutover.id, row.id, payload);
            UI.toast('Baris berhasil diresolusi.', 'success');
            if (dtHandle) dtHandle.reload();
            await refresh();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openPreview() {
        try {
            const preview = await InvApi.previewWarehouseCutoverOpening(cutover.id);
            const s = preview.summary;
            Drawer.open({
                title: 'Approved Opening Preview',
                render: (body) => {
                    body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                        ['Approved SKU Count', s.approved_sku_count],
                        ['Total Qty', UI.formatNumber(s.total_qty)],
                        ['Total Value', UI.formatMoney(s.total_value)],
                        ['Excluded SKU Count', s.excluded_sku_count],
                        ['Unresolved Rows', s.unresolved_rows],
                        ['Zero/Negative Qty Rows', s.zero_or_negative_qty_rows],
                        ['Unit Conflicts', s.unit_conflicts],
                        ['Unmapped Items', s.unmapped_items],
                        ['Theoretical Closing Qty Sum', UI.formatNumber(s.theoretical_closing_qty_sum)],
                        ['Theoretical Closing Value Sum', UI.formatMoney(s.theoretical_closing_value_sum)],
                        ['Approved vs Theoretical Qty Delta', UI.formatNumber(s.approved_vs_theoretical_qty_delta)],
                        ['Approved vs Theoretical Value Delta', UI.formatMoney(s.approved_vs_theoretical_value_delta)],
                    ])));
                },
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render };
})();
