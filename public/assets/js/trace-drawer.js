/**
 * PHASE V2.2 — TraceDrawer: the one reusable "Lihat Jejak" UI, opened from
 * anywhere (Master Data pages, Stock Report, Transaction History, Trace
 * Center). Strictly read-only — this file has no edit/delete/post action
 * anywhere in it, matching TraceService's own read-only contract.
 *
 * Never fabricates history: an empty timeline renders as "Data historis
 * tidak merekam informasi ini." rather than inventing a row.
 */
const TraceDrawer = (() => {
    const ENTITY_LABELS = {
        item: 'Barang', warehouse: 'Gudang', division: 'Divisi', supplier: 'Vendor',
        bakery_destination: 'Bakery Tujuan', category: 'Kategori', stock_policy: 'Stock Policy',
    };

    function fmtVal(v) {
        if (v === null || v === undefined) return '—';
        if (typeof v === 'object') return JSON.stringify(v);
        return String(v);
    }

    function renderKv(obj, excludeKeys) {
        const exclude = new Set(excludeKeys || []);
        const pairs = Object.entries(obj || {}).filter(([k]) => !exclude.has(k)).map(([k, v]) => [k, fmtVal(v)]);
        return Drawer.kv(pairs);
    }

    /** Renders a chronological list of audit_logs-derived events, newest first, diffing before/after per field. */
    function renderTimeline(events) {
        if (!events || events.length === 0) {
            return UI.el('div', { class: 'alert alert-info' }, 'Data historis tidak merekam informasi ini.');
        }
        const rows = events.slice().reverse().map((e) => {
            const changed = [];
            if (e.before || e.after) {
                const keys = new Set([...Object.keys(e.before || {}), ...Object.keys(e.after || {})]);
                keys.forEach((k) => {
                    const b = e.before ? e.before[k] : undefined;
                    const a = e.after ? e.after[k] : undefined;
                    if (JSON.stringify(b) !== JSON.stringify(a)) changed.push(`${k}: ${fmtVal(b)} → ${fmtVal(a)}`);
                });
            }
            return UI.el('div', { class: 'trace-timeline-row' }, [
                UI.el('div', { class: 'trace-timeline-head' }, [
                    UI.el('span', { class: 'trace-timeline-date' }, UI.formatDate(e.created_at)),
                    UI.el('span', { class: 'badge badge-pending' }, e.action_code),
                ]),
                UI.el('div', { class: 'trace-timeline-actor' }, `Actor: ${e.actor}`),
                changed.length ? UI.el('div', { class: 'trace-timeline-changes' }, changed.map((f) => UI.el('div', {}, f))) : null,
                e.reason ? UI.el('div', { class: 'trace-timeline-reason' }, `Alasan: ${e.reason}`) : null,
            ]);
        });
        return UI.el('div', { class: 'trace-timeline' }, rows);
    }

    function movementRow(m) {
        return UI.el('tr', {}, [
            UI.el('td', {}, UI.formatDate(m.transaction_date)),
            UI.el('td', {}, m.transaction_type),
            UI.el('td', {}, UI.formatNumber(m.base_qty)),
            UI.el('td', {}, UI.formatMoney(m.unit_cost_base)),
            UI.el('td', {}, m.reference_no || '—'),
            UI.el('td', {}, m.is_historical_import ? UI.el('span', { class: 'badge badge-void' }, 'Historical / Reporting Only') : UI.el('span', { class: `badge ${UI.badgeClass(m.status)}` }, m.status)),
            UI.el('td', {}, m.created_by_username || '—'),
        ]);
    }

    function batchRow(b) {
        return UI.el('tr', {}, [
            UI.el('td', {}, `#${b.id}`),
            UI.el('td', {}, UI.formatDate(b.received_date)),
            UI.el('td', {}, UI.formatNumber(b.original_qty_base)),
            UI.el('td', {}, UI.formatNumber(b.qty_base)),
            UI.el('td', {}, UI.formatMoney(b.unit_cost_base)),
            UI.el('td', {}, b.is_negative_layer ? UI.el('span', { class: 'badge badge-warning' }, 'Negative Override') : '—'),
        ]);
    }

    async function openEntity(type, id) {
        Drawer.open({ title: 'Memuat jejak...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceEntity(type, id);
            const row = result.overview;
            const title = type === 'stock_policy'
                ? `Jejak Stock Policy — Item #${row.item_id} / Gudang #${row.warehouse_id}`
                : `Jejak ${ENTITY_LABELS[type] || type} — ${row.name || row.code || id}`;
            Drawer.open({
                title,
                tabs: [
                    { key: 'overview', label: 'Overview', render: (body) => body.appendChild(Drawer.section('Ringkasan', renderKv(row))) },
                    { key: 'timeline', label: 'Timeline', render: (body) => body.appendChild(renderTimeline(result.timeline)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openTransaction(id) {
        Drawer.open({ title: 'Memuat jejak transaksi...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceTransaction(id);
            const t = result.transaction;
            Drawer.open({
                title: `Jejak Transaksi #${id} — ${t.transaction_type}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['UUID', t.transaction_uuid],
                                ['Jenis', t.transaction_type],
                                ['Tanggal', UI.formatDate(t.transaction_date)],
                                ['Gudang', `${t.warehouse_code} — ${t.warehouse_name}`],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(t.status)}` }, t.status)],
                                ['Referensi', t.reference_no || '—'],
                                ['Historical Import', t.is_historical_import ? UI.el('span', { class: 'badge badge-void' }, 'Historical / Reporting Only') : 'Tidak'],
                                ['Inventory Effect', t.inventory_effect ? 'Ya' : 'Tidak (tidak mengubah stok)'],
                            ])));
                            if (t.status === 'VOID') {
                                body.appendChild(Drawer.section('Void', Drawer.kv([
                                    ['Alasan', t.void_reason || '—'],
                                    ['Waktu', UI.formatDate(t.voided_at)],
                                ])));
                            }
                        },
                    },
                    {
                        key: 'lines', label: 'Lines', render: (body) => {
                            const rows = result.lines.map((l) => UI.el('tr', {}, [
                                UI.el('td', {}, `${l.sku} — ${l.item_name}`),
                                UI.el('td', {}, `${UI.formatNumber(l.input_qty)} ${l.unit_code}`),
                                UI.el('td', {}, UI.formatNumber(l.base_qty)),
                                UI.el('td', {}, UI.formatMoney(l.unit_cost_base)),
                                UI.el('td', {}, UI.formatMoney(l.subtotal)),
                            ]));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Qty Input', 'Qty Base', 'Cost/Unit', 'Subtotal'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Tidak ada baris')])]),
                                ]),
                            ]));
                        },
                    },
                    {
                        key: 'fifo', label: 'FIFO', render: (body) => {
                            body.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.78rem; margin-bottom:10px;' }, 'Read-only — FIFO batch/allocation trail tidak dapat diubah dari sini.'));
                            if (result.fifo_batches_created.length > 0) {
                                const rows = result.fifo_batches_created.map((b) => UI.el('tr', {}, [
                                    UI.el('td', {}, `#${b.id}`), UI.el('td', {}, UI.formatDate(b.received_date)),
                                    UI.el('td', {}, UI.formatNumber(b.original_qty_base)), UI.el('td', {}, UI.formatMoney(b.unit_cost_base)),
                                ]));
                                body.appendChild(Drawer.section('Batch Dibuat', UI.el('div', { class: 'table-wrapper' }, [
                                    UI.el('table', {}, [
                                        UI.el('thead', {}, [UI.el('tr', {}, ['Batch', 'Tanggal', 'Qty', 'Cost/Unit'].map((h) => UI.el('th', {}, h)))]),
                                        UI.el('tbody', {}, rows),
                                    ]),
                                ])));
                            }
                            if (result.fifo_allocations.length > 0) {
                                const rows = result.fifo_allocations.map((a) => UI.el('tr', {}, [
                                    UI.el('td', {}, `#${a.batch_id}`), UI.el('td', {}, UI.formatDate(a.received_date)),
                                    UI.el('td', {}, UI.formatNumber(a.qty_allocated)), UI.el('td', {}, UI.formatMoney(a.unit_cost_base)),
                                ]));
                                body.appendChild(Drawer.section('Batch Dikonsumsi (Alokasi)', UI.el('div', { class: 'table-wrapper' }, [
                                    UI.el('table', {}, [
                                        UI.el('thead', {}, [UI.el('tr', {}, ['Batch', 'Tanggal Batch', 'Qty Dialokasikan', 'Cost/Unit'].map((h) => UI.el('th', {}, h)))]),
                                        UI.el('tbody', {}, rows),
                                    ]),
                                ])));
                            }
                            if (result.fifo_batches_created.length === 0 && result.fifo_allocations.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Transaksi ini tidak membuat atau mengonsumsi batch FIFO.'));
                            }
                        },
                    },
                    {
                        key: 'relations', label: 'Relations', render: (body) => {
                            const rel = [];
                            if (result.reversal_of) rel.push(['Reversal dari Transaksi', `#${result.reversal_of.id} (${result.reversal_of.transaction_type}, ${result.reversal_of.status})`]);
                            if (result.reversed_by) rel.push(['Dibalik oleh Transaksi', `#${result.reversed_by.id} (${result.reversed_by.transaction_type})`]);
                            if (result.transfer) rel.push(['Transfer', `#${result.transfer.id} (${result.transfer.status})`]);
                            if (result.production) rel.push(['Produksi', `#${result.production.id}`]);
                            if (result.adjustment) rel.push(['Stock Adjustment', `#${result.adjustment.id} — ${result.adjustment.adjustment_type}: ${result.adjustment.reason}`]);
                            if (rel.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada relasi lain (transfer/produksi/reversal/adjustment) untuk transaksi ini.'));
                                return;
                            }
                            body.appendChild(Drawer.section('Relasi', Drawer.kv(rel)));
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openInventory(itemId, warehouseId) {
        Drawer.open({ title: 'Memuat jejak inventory...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceInventory(itemId, warehouseId);
            Drawer.open({
                title: `Jejak Inventory — ${result.item.sku} @ ${result.warehouse.code}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['SKU', result.item.sku],
                                ['Nama Barang', result.item.name],
                                ['Gudang', `${result.warehouse.code} — ${result.warehouse.name}`],
                                ['Qty Stock', UI.formatNumber(result.overview.qty_base)],
                                ['Nilai Stok', UI.formatMoney(result.overview.value)],
                                ['Minimum', result.overview.minimum_stock !== null ? UI.formatNumber(result.overview.minimum_stock) : 'Tidak tersedia'],
                                ['Buffer', result.overview.buffer_stock !== null ? UI.formatNumber(result.overview.buffer_stock) : 'Belum dikonfigurasi'],
                            ])));
                        },
                    },
                    {
                        key: 'movements', label: 'Movement Timeline', render: (body) => {
                            const rows = result.movements.map(movementRow);
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Jenis', 'Qty Base', 'Cost/Unit', 'Referensi', 'Status', 'Actor'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '7' }, 'Belum ada pergerakan')])]),
                                ]),
                            ]));
                        },
                    },
                    {
                        key: 'fifo', label: 'FIFO Batches', render: (body) => {
                            const rows = result.fifo_batches.map(batchRow);
                            body.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.78rem; margin-bottom:10px;' }, 'Read-only — koreksi hanya melalui Stock Opname / Stock Adjustment.'));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Batch', 'Tanggal', 'Qty Awal', 'Qty Sisa', 'Cost/Unit', 'Catatan'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Tidak ada batch aktif')])]),
                                ]),
                            ]));
                        },
                    },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return { openEntity, openTransaction, openInventory, renderTimeline, renderKv };
})();
