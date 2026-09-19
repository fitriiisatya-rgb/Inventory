/**
 * PHASE V2.2 — Trace Center: global search across entities + a paginated,
 * filterable browser over audit_logs (the "Audit Event" entity type in
 * the spec). Read-only, same as every other trace surface.
 *
 * Known limitation (documented, not silently worked around): audit_logs
 * has no warehouse_id column, so the Events browser below can't filter by
 * warehouse — see docs/PHASE_V2_2_TRACE_ARCHITECTURE.md. Search results
 * for warehouse-scoped entities (items, transactions) still open through
 * TraceDrawer, whose own endpoints DO enforce warehouse isolation.
 */
const TraceCenter = (() => {
    const ENTITY_LABELS = {
        item: 'Barang', transaction: 'Transaksi', supplier: 'Vendor', bakery_destination: 'Bakery Tujuan',
        category: 'Kategori', warehouse: 'Gudang', transfer: 'Transfer', user: 'User',
    };

    function render(container) {
        container.innerHTML = '';

        const searchCard = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🔎 Trace Center — Pencarian Global')]),
            UI.el('p', { style: 'color:var(--text3); font-size:0.8rem; margin-bottom:10px;' }, 'Cari SKU, nama barang, nomor transaksi/referensi, transfer, vendor, bakery tujuan, kategori, gudang, atau user.'),
        ]);
        const searchToolbar = UI.el('div', { class: 'dt-toolbar' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari...', style: 'min-width:260px;' });
        const typeSelect = UI.el('select', { html: `<option value="">Semua Jenis</option>` + Object.entries(ENTITY_LABELS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('') });
        searchToolbar.appendChild(UI.el('div', { class: 'dt-filters' }, [searchInput, typeSelect]));
        searchCard.appendChild(searchToolbar);
        const resultsBox = UI.el('div', {});
        searchCard.appendChild(resultsBox);
        container.appendChild(searchCard);

        let debounce;
        async function runSearch() {
            const q = searchInput.value.trim();
            if (q === '') {
                resultsBox.innerHTML = '';
                return;
            }
            resultsBox.innerHTML = '<div class="alert alert-info">Mencari...</div>';
            try {
                const results = await InvApi.traceSearch(q, typeSelect.value || undefined);
                resultsBox.innerHTML = '';
                if (results.length === 0) {
                    resultsBox.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada hasil.'));
                    return;
                }
                results.forEach((r) => {
                    const row = UI.el('div', { class: 'trace-search-result', role: 'button', tabindex: '0' }, [
                        UI.el('span', {}, [
                            UI.el('span', { class: 'type-badge' }, ENTITY_LABELS[r.type] || r.type),
                            UI.el('span', {}, `${r.code ? r.code + ' — ' : ''}${r.label}`),
                        ]),
                        r.status ? UI.el('span', { class: `badge ${UI.badgeClass(r.status)}` }, r.status) : null,
                    ]);
                    const open = () => (r.type === 'transaction' ? TraceDrawer.openTransaction(r.id) : (r.type === 'transfer' ? TraceCenter.openTransfer(r.id) : (r.type === 'user' ? null : TraceDrawer.openEntity(r.type, r.id))));
                    row.addEventListener('click', open);
                    row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
                    resultsBox.appendChild(row);
                });
            } catch (err) {
                UI.handleApiError(err);
                resultsBox.innerHTML = `<div class="alert alert-error">Gagal mencari: ${(err && err.message) || ''}</div>`;
            }
        }
        searchInput.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(runSearch, 300); });
        typeSelect.addEventListener('change', runSearch);

        const eventsCard = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📜 Audit Events') ]),
        ]);
        const eventsHost = UI.el('div');
        eventsCard.appendChild(eventsHost);
        container.appendChild(eventsCard);

        const entityTypeOptions = ['items', 'warehouses', 'divisions', 'suppliers', 'bakery_destinations', 'categories', 'item_warehouse_stock_policy', 'inventory_transactions'].map((t) => ({ value: t, label: t }));

        DataTable.render(eventsHost, {
            storageKey: 'dt-trace-events',
            filters: [
                { key: 'entity_type', label: 'Entity Type', type: 'select', options: entityTypeOptions, placeholder: 'Semua Entity Type' },
                { key: 'username', label: 'User', type: 'text', placeholder: 'Cari User (actor)' },
                { key: 'date_from', label: 'Dari', type: 'text', placeholder: 'YYYY-MM-DD' },
                { key: 'date_to', label: 'Sampai', type: 'text', placeholder: 'YYYY-MM-DD' },
            ],
            defaultSort: 'id',
            defaultDir: 'desc',
            pageSize: 25,
            columns: [
                { key: 'created_at', label: 'Tanggal', render: (r) => UI.formatDate(r.created_at) },
                { key: 'action_code', label: 'Event Type', render: (r) => UI.el('span', { class: 'badge badge-pending' }, r.action_code) },
                { key: 'entity_type', label: 'Entity', render: (r) => `${r.entity_type} #${r.entity_id ?? '—'}` },
                { key: 'actor', label: 'Actor', render: (r) => r.actor },
                { key: 'reason', label: 'Alasan', render: (r) => r.reason || '—' },
            ],
            fetchPage: async ({ page, perPage, dir, filters: f }) => {
                const params = { ...f, dir, page, per_page: perPage };
                Object.keys(params).forEach((k) => { if (params[k] === undefined || params[k] === '') delete params[k]; });
                return InvApi.traceEvents(params);
            },
            onRowClick: (row) => {
                const type = TraceCenter.entityTypeToTraceType(row.entity_type);
                if (type && row.entity_id) TraceDrawer.openEntity(type, row.entity_id);
                else if (row.entity_type === 'inventory_transactions' && row.entity_id) TraceDrawer.openTransaction(row.entity_id);
            },
            emptyMessage: 'Tidak ada audit event yang cocok dengan filter ini.',
        });
    }

    function entityTypeToTraceType(auditEntityType) {
        const map = {
            items: 'item', warehouses: 'warehouse', divisions: 'division', suppliers: 'supplier',
            bakery_destinations: 'bakery_destination', categories: 'category', item_warehouse_stock_policy: 'stock_policy',
        };
        return map[auditEntityType] || null;
    }

    async function openTransfer(transferId) {
        // No dedicated transfer-trace endpoint yet (see known limitations) —
        // trace via its TRANSFER_OUT transaction instead of leaving the
        // click a dead end. Looked up via the reference_no convention every
        // TransferService transaction already carries.
        try {
            const results = await InvApi.transactionReport({ q: `TRANSFER-${transferId}`, per_page: 1 });
            const row = (results.rows || [])[0];
            if (row) TraceDrawer.openTransaction(row.transaction_id);
            else UI.toast('Transaksi untuk transfer ini tidak ditemukan.', 'error');
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { render, entityTypeToTraceType, openTransfer };
})();
