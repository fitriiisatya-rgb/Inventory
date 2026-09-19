/**
 * PHASE V2 — "History Transaksi". GET /reports/transactions via DataTable,
 * row click opens the transaction detail drawer (header, lines, FIFO
 * allocation summary for OUT, audit metadata if the viewer holds
 * AUDIT_LOG_VIEW).
 */
const TransactionHistory = (() => {
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

        DataTable.render(tableHost, {
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

                    if (Auth.hasPermission('AUDIT_LOG_VIEW')) {
                        const jejakBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-bottom:14px;' }, '🔍 Lihat Jejak Lengkap');
                        jejakBtn.addEventListener('click', () => TraceDrawer.openTransaction(transactionId));
                        body.appendChild(jejakBtn);
                    }

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

    return { render };
})();
