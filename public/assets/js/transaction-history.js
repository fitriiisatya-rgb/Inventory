/**
 * PHASE V2 — "History Transaksi". GET /reports/transactions via DataTable,
 * row click opens the transaction detail drawer (header, lines, FIFO
 * allocation summary for OUT, audit metadata if the viewer holds
 * AUDIT_LOG_VIEW).
 */
const TransactionHistory = (() => {
    const voidUuids = new Map();
    let activeDataTableReload = null;

    function render(container) {
        container.innerHTML = '';

        const isStock = Auth.user().role_code === 'STOCK';
        const warehouseOptions = Master.warehouses().map((w) => ({ value: w.id, label: w.name }));
        const categoryOptions = Master.categories().filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }));
        const typeOptions = ['IN', 'OUT', 'TRANSFER_IN', 'TRANSFER_OUT', 'ADJUSTMENT', 'OPNAME', 'PRODUCTION_IN', 'PRODUCTION_OUT', 'OPENING'].map((t) => ({ value: t, label: t }));
        const supplierOptions = Master.suppliers().map((s) => ({ value: s.id, label: s.name }));
        const bakeryOptions = Master.bakeryDestinations().map((b) => ({ value: b.id, label: b.name }));

        const filters = [
            { key: 'q', label: 'Cari', type: 'text', placeholder: 'SKU / Nama / No. Referensi' },
            { key: 'transaction_type', label: 'Jenis', type: 'select', options: typeOptions, placeholder: 'Semua Jenis' },
            { key: 'category_id', label: 'Kategori', type: 'select', options: categoryOptions, placeholder: 'Semua Kategori' },
            { key: 'supplier_id', label: 'Vendor', type: 'select', options: supplierOptions, placeholder: 'Semua Vendor' },
            { key: 'bakery_destination_id', label: 'Bakery Tujuan', type: 'select', options: bakeryOptions, placeholder: 'Semua Bakery Tujuan' },
            { key: 'date_from', label: 'Dari Tanggal', type: 'date-like' },
            { key: 'date_to', label: 'Sampai Tanggal', type: 'date-like' },
        ];
        if (!isStock) filters.splice(1, 0, { key: 'warehouse_id', label: 'Gudang', type: 'select', options: warehouseOptions, placeholder: 'Semua Gudang' });

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🧾 History Transaksi')]),
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        const dt = DataTable.render(tableHost, {
            storageKey: 'dt-transaction-history',
            filters: filters.map((f) => (f.type === 'date-like' ? { ...f, type: 'text', placeholder: `${f.label} (YYYY-MM-DD)` } : f)),
            defaultSort: 'date', defaultDir: 'desc',
            columns: [
                { key: 'date', label: 'Tanggal', sortable: true, render: (r) => UI.formatDate(r.transaction_date) },
                { key: 'reference_no', label: 'Referensi', render: (r) => r.reference_no || '—' },
                { key: 'type', label: 'Jenis', sortable: true, render: (r) => UI.el('span', { class: `badge ${UI.badgeClass(r.transaction_type)}` }, r.transaction_type) },
                { key: 'sku', label: 'SKU', render: (r) => r.item.sku },
                { key: 'item', label: 'Nama Barang', sortable: true, render: (r) => r.item.name },
                { key: 'qty', label: 'Qty', render: (r) => `${UI.formatNumber(r.input_qty)} ${r.input_unit.code}` },
                { key: 'warehouse', label: 'Gudang', defaultVisible: true, render: (r) => r.warehouse.name },
                { key: 'vendor', label: 'Vendor', defaultVisible: true, render: (r) => (r.supplier ? r.supplier.name : '—') },
                { key: 'bakery', label: 'Bakery Tujuan', defaultVisible: true, render: (r) => (r.bakery_destination ? r.bakery_destination.name : '—') },
                { key: 'created_by', label: 'Dibuat Oleh', defaultVisible: false, render: (r) => r.created_by.username },
            ],
            fetchPage: async ({ page, perPage, sort, dir, filters: f }) => {
                const sortMap = { date: 'date', type: 'type', item: 'item' };
                const params = { page, per_page: perPage, sort: sortMap[sort] || 'date', dir, ...f };
                if (isStock) delete params.warehouse_id;
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                return InvApi.transactionReport(params);
            },
            onRowClick: (row) => openDetail(row.transaction_id),
            emptyMessage: 'Tidak ada transaksi yang cocok dengan filter ini.',
        });
        activeDataTableReload = dt.reload;
    }

    // PHASE V2.5 — transaction correction. IN/OUT/ADJUSTMENT while POSTED
    // (never OPENING, never an already-VOID/REVERSED row, never a
    // historical-import audit-only row) can be voided by a holder of
    // TRANSACTION_VOID. The backend is the authoritative gate (STOCK never
    // has this permission) — this check only decides whether the button is
    // shown at all.
    const VOIDABLE_TYPES = ['IN', 'OUT', 'ADJUSTMENT'];
    function canVoid(d) {
        return Auth.hasPermission('TRANSACTION_VOID')
            && VOIDABLE_TYPES.includes(d.transaction_type)
            && d.status === 'POSTED'
            && !d.is_historical;
    }

    async function openDetail(transactionId) {
        Drawer.open({
            title: `Transaksi #${transactionId}`,
            render: async (body) => {
                body.innerHTML = '<div class="alert alert-info">Memuat detail transaksi...</div>';
                try {
                    const d = await InvApi.transactionDetail(transactionId);
                    body.innerHTML = '';
                    body.appendChild(Drawer.section('Header', Drawer.kv([
                        ['Jenis', d.transaction_type],
                        ['Tanggal', UI.formatDate(d.transaction_date)],
                        ['Referensi', d.reference_no || '—'],
                        ['Status', UI.el('span', { class: `badge ${UI.badgeClass(d.status)}` }, d.status)],
                        ['Gudang', d.warehouse.name],
                        ['Vendor', d.supplier ? d.supplier.name : '—'],
                        ['Bakery Tujuan', d.bakery_destination ? d.bakery_destination.name : '—'],
                        ['Divisi', d.division ? d.division.name : '—'],
                        ['Dibuat Oleh', d.created_by.username],
                    ])));

                    const actionsRow = UI.el('div', { style: 'margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap;' });
                    if (Auth.hasPermission('AUDIT_LOG_VIEW')) {
                        const jejakBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '🔍 Lihat Jejak Lengkap');
                        jejakBtn.addEventListener('click', () => TraceDrawer.openTransaction(transactionId));
                        actionsRow.appendChild(jejakBtn);
                    }
                    if (canVoid(d)) {
                        const voidBtn = UI.el('button', { class: 'btn btn-danger btn-sm' }, '⛔ Void Transaksi');
                        voidBtn.addEventListener('click', () => voidTransaction(d, voidBtn));
                        actionsRow.appendChild(voidBtn);
                    }
                    if (actionsRow.children.length) body.appendChild(actionsRow);

                    d.lines.forEach((line, idx) => {
                        const lineRows = [
                            ['Barang', `${line.item.sku} — ${line.item.name}`],
                            ['Qty Input', `${UI.formatNumber(line.input_qty)} ${line.input_unit.code}`],
                            ['Konversi ke Base', UI.formatNumber(line.conversion_factor_snapshot, 6)],
                            ['Qty Base', UI.formatNumber(line.base_qty)],
                            ['Harga Satuan', UI.formatMoney(line.unit_price_input)],
                            ['Harga Pokok/Unit', UI.formatMoney(line.unit_cost_base)],
                            ['Subtotal', UI.formatMoney(line.subtotal)],
                        ];
                        body.appendChild(Drawer.section(`Baris ${idx + 1}`, Drawer.kv(lineRows)));

                        if (line.fifo_allocations.length > 0) {
                            const allocRows = line.fifo_allocations.map((a) => UI.el('tr', {}, [
                                UI.el('td', {}, UI.formatDate(a.received_date)),
                                UI.el('td', {}, UI.formatNumber(a.qty_allocated)),
                                UI.el('td', {}, UI.formatMoney(a.unit_cost_base)),
                                UI.el('td', {}, UI.formatMoney(a.subtotal)),
                            ]));
                            body.appendChild(Drawer.section('FIFO Allocation', UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Batch Tanggal', 'Qty', 'Unit Cost', 'Subtotal'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, allocRows),
                                ]),
                            ])));
                        }
                    });

                    if (d.audit_log) {
                        const auditRows = d.audit_log.map((a) => UI.el('tr', {}, [
                            UI.el('td', {}, UI.formatDate(a.created_at)),
                            UI.el('td', {}, a.action_code),
                            UI.el('td', {}, a.username_snapshot),
                        ]));
                        body.appendChild(Drawer.section('Audit Metadata', UI.el('div', { class: 'table-wrapper' }, [
                            UI.el('table', {}, [
                                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Aksi', 'User'].map((h) => UI.el('th', {}, h)))]),
                                UI.el('tbody', {}, auditRows.length ? auditRows : [UI.el('tr', {}, [UI.el('td', { colspan: '3' }, 'Tidak ada catatan audit')])]),
                            ]),
                        ])));
                    }
                } catch (err) {
                    UI.handleApiError(err);
                    body.innerHTML = `<div class="alert alert-error">Gagal memuat detail: ${(err && err.message) || ''}</div>`;
                }
            },
        });
    }

    // PHASE V2.5 — Void Transaksi confirmation + submit. `d` is the full
    // detail payload already loaded in the drawer (header fields present
    // directly on it — note TransactionHistoryService::detail() exposes the
    // primary key as `transaction_id`, not `id`).
    async function voidTransaction(d, btn) {
        const nilai = (d.lines || []).reduce((sum, l) => sum + Math.abs(Number(l.subtotal) || 0), 0);
        const reason = await Modal.form({
            title: 'VOID TRANSAKSI',
            infoRows: [
                ['Transaksi', `#${d.transaction_id}`],
                ['Jenis', d.transaction_type],
                ['Tanggal', UI.formatDate(d.transaction_date)],
                ['Gudang', d.warehouse.name],
                ['Reference', d.reference_no || '—'],
                ['Dibuat Oleh', d.created_by.username],
                ['Nilai', UI.formatMoney(nilai)],
            ],
            warning: 'Tindakan ini akan membuat transaksi REVERSAL yang membalik dampak transaksi asli. Transaksi asli tidak akan dihapus, hanya ditandai VOID.',
            reasonLabel: 'Alasan Void',
            reasonPlaceholder: 'Jelaskan alasan pembatalan transaksi ini (minimal 5 karakter)',
            confirmLabel: 'Konfirmasi Void',
            cancelLabel: 'Batal',
            danger: true,
        });
        if (reason === null) return;

        if (!voidUuids.has(d.transaction_id)) voidUuids.set(d.transaction_id, InvApi.newRequestUuid());
        btn.disabled = true;
        try {
            await InvApi.voidTransaction(d.transaction_id, { request_uuid: voidUuids.get(d.transaction_id), reason });
            UI.toast(`Transaksi #${d.transaction_id} berhasil di-void.`, 'success');
            voidUuids.delete(d.transaction_id);
            openDetail(d.transaction_id); // refresh the drawer in place — status/lines now reflect VOID + REVERSAL
            if (activeDataTableReload) activeDataTableReload();
        } catch (err) {
            btn.disabled = false;
            if (err && err.dependencies && err.dependencies.length) {
                const list = err.dependencies.map((dep) => `#${dep.id} — ${dep.transaction_type} (${UI.formatDate(dep.transaction_date)})`).join('\n');
                await Modal.alert({
                    title: 'Tidak Dapat Di-void',
                    message: `Stok dari transaksi ini sudah digunakan oleh transaksi berikut — void transaksi tersebut terlebih dahulu:\n\n${list}`,
                });
                return;
            }
            UI.handleApiError(err);
            await Modal.alert({ title: 'Gagal Void Transaksi', message: (err && err.message) || 'Terjadi kesalahan.' });
        }
    }

    return { render };
})();
