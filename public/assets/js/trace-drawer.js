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

    // ============================================================
    // PHASE V2.2B — the 7 previously dead-end / partial trace types.
    // Same "Memuat..." placeholder -> tabs pattern as above.
    // ============================================================

    async function openTransfer(id) {
        Drawer.open({ title: 'Memuat jejak transfer...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceTransfer(id);
            const t = result.transfer;
            Drawer.open({
                title: `Jejak Transfer #${id}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Dari Gudang', `${t.from_warehouse_code} — ${t.from_warehouse_name}`],
                                ['Ke Gudang', `${t.to_warehouse_code} — ${t.to_warehouse_name}`],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(t.status)}` }, t.status)],
                                ['Dibuat Oleh', t.created_by_username || '—'],
                                ['Dibuat Pada', UI.formatDate(t.created_at)],
                                ['Tanggal Kirim', UI.formatDate(t.ship_date)],
                                ['Diterima Oleh', t.received_by_username || '—'],
                                ['Diterima Pada', t.receive_date ? UI.formatDate(t.receive_date) : '—'],
                                ['Dibatalkan Oleh', t.cancelled_by_username || '—'],
                                ['Alasan Batal', t.cancel_reason || '—'],
                                ['Referensi Transaksi', result.transactions.map((tx) => `#${tx.id} (${tx.transaction_type})`).join(', ') || '—'],
                            ])));
                        },
                    },
                    {
                        key: 'lines', label: 'Lines & FIFO', render: (body) => {
                            if (result.lines.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada baris.'));
                                return;
                            }
                            result.lines.forEach((ld) => {
                                const l = ld.line;
                                body.appendChild(Drawer.section(`${l.sku} — ${l.item_name}`, UI.el('div', {}, [
                                    Drawer.kv([['Qty', UI.formatNumber(l.qty_base)], ['Cost/Unit', UI.formatMoney(l.unit_cost_base)]]),
                                    ld.out_fifo_allocations.length
                                        ? UI.el('div', {}, [UI.el('div', { style: 'font-weight:600;margin-top:6px;' }, 'Batch Sumber (dikonsumsi):'),
                                            ...ld.out_fifo_allocations.map((a) => UI.el('div', {}, `Batch #${a.batch_id} — ${UI.formatNumber(a.qty_allocated)} @ ${UI.formatMoney(a.unit_cost_base)}`))])
                                        : UI.el('div', { class: 'alert alert-info' }, 'Belum ada alokasi FIFO (belum dikirim).'),
                                    ld.destination_batch
                                        ? UI.el('div', {}, `Batch Tujuan Dibuat: #${ld.destination_batch.id} — ${UI.formatNumber(ld.destination_batch.qty_base)} @ ${UI.formatMoney(ld.destination_batch.unit_cost_base)}`)
                                        : UI.el('div', { class: 'alert alert-info' }, 'Belum diterima — belum ada batch tujuan.'),
                                ])));
                            });
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openOpname(id) {
        Drawer.open({ title: 'Memuat jejak opname...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceOpname(id);
            const s = result.session;
            Drawer.open({
                title: `Jejak Stock Opname #${id}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Gudang', `${s.warehouse_code} — ${s.warehouse_name}`],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status)],
                                ['Dibuat Oleh', s.created_by_username || '—'],
                                ['Difinalisasi Oleh', s.finalized_by_username || '—'],
                                ['Diposting Oleh', s.posted_by_username || '—'],
                                ['Dibatalkan Oleh', s.cancelled_by_username || '—'],
                            ])));
                        },
                    },
                    {
                        key: 'lines', label: 'Lines & Adjustment', render: (body) => {
                            const rows = result.lines.map((ld) => UI.el('tr', {}, [
                                UI.el('td', {}, `${ld.line.sku} — ${ld.line.item_name}`),
                                UI.el('td', {}, UI.formatNumber(ld.line.system_qty_base)),
                                UI.el('td', {}, ld.line.counted_qty_base !== null ? UI.formatNumber(ld.line.counted_qty_base) : '—'),
                                UI.el('td', {}, ld.line.variance_qty_base !== null ? UI.formatNumber(ld.line.variance_qty_base) : '—'),
                                UI.el('td', {}, ld.resulting_adjustment ? `Adj #${ld.resulting_adjustment.id} (${ld.resulting_adjustment.transaction_status})` : '—'),
                            ]));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Qty Sistem', 'Qty Fisik', 'Selisih', 'Adjustment'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Tidak ada baris')])]),
                                ]),
                            ]));
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openProduction(id) {
        Drawer.open({ title: 'Memuat jejak produksi...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceProduction(id);
            const p = result.production;
            Drawer.open({
                title: `Jejak Produksi #${id}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Gudang', `${p.warehouse_code} — ${p.warehouse_name}`],
                                ['Divisi', p.division_code ? `${p.division_code} — ${p.division_name}` : '—'],
                                ['Tanggal', UI.formatDate(p.production_date)],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(p.status)}` }, p.status)],
                                ['Dibuat Oleh', p.created_by_username || '—'],
                            ])));
                        },
                    },
                    {
                        key: 'consumption', label: 'Konsumsi (Bahan Baku)', render: (body) => {
                            if (result.inputs.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada bahan baku.'));
                                return;
                            }
                            result.inputs.forEach((idet) => {
                                const i = idet.input;
                                body.appendChild(Drawer.section(`${i.sku} — ${i.item_name}`, UI.el('div', {}, [
                                    Drawer.kv([['Qty Dikonsumsi', UI.formatNumber(i.qty_base)], ['Cost/Unit (FIFO)', UI.formatMoney(i.unit_cost_base)]]),
                                    idet.fifo_allocations.length
                                        ? UI.el('div', {}, [UI.el('div', { style: 'font-weight:600;margin-top:6px;' }, 'Batch Sumber:'),
                                            ...idet.fifo_allocations.map((a) => UI.el('div', {}, `Batch #${a.batch_id} — ${UI.formatNumber(a.qty_allocated)} @ ${UI.formatMoney(a.unit_cost_base)}`))])
                                        : null,
                                ])));
                            });
                        },
                    },
                    {
                        key: 'output', label: 'Hasil (Finished Goods)', render: (body) => {
                            if (result.outputs.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada hasil produksi.'));
                                return;
                            }
                            result.outputs.forEach((odet) => {
                                const o = odet.output;
                                body.appendChild(Drawer.section(`${o.sku} — ${o.item_name}`, UI.el('div', {}, [
                                    Drawer.kv([['Qty Dihasilkan', UI.formatNumber(o.qty_base)], ['Cost/Unit', UI.formatMoney(o.unit_cost_base)]]),
                                    odet.created_batch
                                        ? UI.el('div', {}, `Batch Dibuat: #${odet.created_batch.id} — ${UI.formatNumber(odet.created_batch.qty_base)} tersisa`)
                                        : UI.el('div', { class: 'alert alert-info' }, 'Batch tidak ditemukan.'),
                                ])));
                            });
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openOpening(id) {
        Drawer.open({ title: 'Memuat jejak opening stock...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceOpening(id);
            const o = result.opening;
            Drawer.open({
                title: `Jejak Opening Stock #${id}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan (Staging)', Drawer.kv([
                                ['Cutoff Date', UI.formatDate(o.cutoff_date)],
                                ['Deskripsi', o.description || '—'],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(o.status)}` }, o.status)],
                                ['Dibuat Oleh', o.created_by_username || '—'],
                                ['Dikomit Oleh', o.committed_by_username || '—'],
                                ['Total Nilai Kontrol', o.control_total_value !== null ? UI.formatMoney(o.control_total_value) : '—'],
                            ])));
                        },
                    },
                    {
                        key: 'lines', label: 'Lines: Staging vs Live', render: (body) => {
                            body.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.78rem; margin-bottom:10px;' }, 'Kolom kiri = catatan staging/laporan (histori impor). Kolom kanan = baseline FIFO yang benar-benar berlaku hari ini. Tidak pernah mengubah ekonomi opening.'));
                            if (result.lines.length === 0) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada baris di gudang Anda.'));
                                return;
                            }
                            result.lines.forEach((ld) => {
                                const sl = ld.staging_line;
                                body.appendChild(Drawer.section(`${sl.sku || '—'} — ${sl.item_name || '(item tidak dikenal)'}`, UI.el('div', {}, [
                                    Drawer.kv([
                                        ['Gudang', sl.warehouse_code ? `${sl.warehouse_code} — ${sl.warehouse_name}` : '—'],
                                        ['Qty (Staging)', UI.formatNumber(sl.qty_base)],
                                        ['Cost/Unit (Staging)', UI.formatMoney(sl.unit_cost_base)],
                                        ['Sumber', sl.source || '—'],
                                        ['Status Baris', sl.row_status],
                                    ]),
                                    ld.live_fifo_batch
                                        ? UI.el('div', {}, `Batch FIFO Live: #${ld.live_fifo_batch.id} — ${UI.formatNumber(ld.live_fifo_batch.qty_base)} tersisa dari ${UI.formatNumber(ld.live_fifo_batch.original_qty_base)}`)
                                        : UI.el('div', { class: 'alert alert-info' }, 'Belum ter-link ke batch live.'),
                                    ld.live_opening_transaction
                                        ? UI.el('div', {}, `Transaksi OPENING Live: #${ld.live_opening_transaction.id} (${ld.live_opening_transaction.status})`)
                                        : null,
                                ])));
                            });
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openImport(id) {
        Drawer.open({ title: 'Memuat jejak import...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceImport(id);
            const b = result.import_batch;
            Drawer.open({
                title: `Jejak Import #${id} — ${b.file_name}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Jenis Import', b.import_type],
                                ['File', b.file_name],
                                ['Status', UI.el('span', { class: `badge ${UI.badgeClass(b.status)}` }, b.status)],
                                ['Total Baris', UI.formatNumber(b.total_rows)],
                                ['Valid', UI.formatNumber(b.valid_rows)],
                                ['Warning', UI.formatNumber(b.warning_rows)],
                                ['Error', UI.formatNumber(b.error_rows)],
                                ['Diunggah Oleh', b.uploaded_by_username || '—'],
                                ['Diunggah Pada', UI.formatDate(b.uploaded_at)],
                                ['Dikomit Oleh', b.committed_by_username || '—'],
                            ])));
                        },
                    },
                    {
                        key: 'rows', label: `Baris (${result.pagination.total})`, render: (body) => {
                            const rows = result.rows.map((r) => UI.el('tr', {}, [
                                UI.el('td', {}, String(r.row_no)),
                                UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(r.row_status)}` }, r.row_status)),
                                UI.el('td', {}, r.created_entity_id !== null ? `#${r.created_entity_id}` : '—'),
                                UI.el('td', {}, r.messages ? JSON.stringify(r.messages) : '—'),
                            ]));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Baris #', 'Status', 'Entity Dibuat', 'Pesan'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Tidak ada baris')])]),
                                ]),
                            ]));
                        },
                    },
                    { key: 'audit', label: 'Audit', render: (body) => body.appendChild(renderTimeline(result.audit_events)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openUser(id) {
        Drawer.open({ title: 'Memuat jejak user...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceUser(id);
            const u = result.overview;
            Drawer.open({
                title: `Jejak User — ${u.username}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Username', u.username],
                                ['Nama Lengkap', u.full_name],
                                ['Role', `${u.role_code} — ${u.role_name}`],
                                ['Divisi', u.division_code ? `${u.division_code} — ${u.division_name}` : '—'],
                                ['Gudang', u.warehouse_code ? `${u.warehouse_code} — ${u.warehouse_name}` : 'Semua Gudang'],
                                ['Status', MasterCommon.statusBadge(!!u.is_active)],
                                ['Harus Ganti Password', u.must_change_password ? 'Ya' : 'Tidak'],
                                ['Login Terakhir', u.last_login_at ? UI.formatDate(u.last_login_at) : '—'],
                                ['Dibuat Pada', UI.formatDate(u.created_at)],
                            ])));
                            if (result.historical_data_limited) {
                                body.appendChild(UI.el('div', { class: 'alert alert-info' }, result.historical_note));
                            }
                        },
                    },
                    {
                        key: 'login', label: 'Riwayat Login', render: (body) => {
                            const rows = result.login_history.map((l) => UI.el('tr', {}, [
                                UI.el('td', {}, UI.formatDate(l.created_at)),
                                UI.el('td', {}, l.ip_address || '—'),
                                UI.el('td', {}, l.success ? UI.el('span', { class: 'badge badge-pass' }, 'Berhasil') : UI.el('span', { class: 'badge badge-error' }, 'Gagal')),
                            ]));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Waktu', 'IP', 'Hasil'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '3' }, 'Belum ada riwayat login')])]),
                                ]),
                            ]));
                        },
                    },
                    { key: 'timeline', label: 'Timeline Akun', render: (body) => body.appendChild(renderTimeline(result.timeline)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function openRole(id) {
        Drawer.open({ title: 'Memuat jejak role...', render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        try {
            const result = await InvApi.traceRole(id);
            const r = result.role;
            Drawer.open({
                title: `Jejak Role — ${r.name}`,
                tabs: [
                    {
                        key: 'overview', label: 'Overview', render: (body) => {
                            body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                                ['Kode', r.code],
                                ['Nama', r.name],
                                ['Deskripsi', r.description || '—'],
                                ['Jumlah User Terpasang', String(result.assigned_user_count)],
                            ])));
                            body.appendChild(UI.el('div', { class: 'alert alert-info' }, result.historical_note));
                        },
                    },
                    {
                        key: 'permissions', label: `Permissions (${result.permissions.length})`, render: (body) => {
                            const rows = result.permissions.map((p) => UI.el('tr', {}, [
                                UI.el('td', {}, p.code),
                                UI.el('td', {}, p.description || '—'),
                            ]));
                            body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                                UI.el('table', {}, [
                                    UI.el('thead', {}, [UI.el('tr', {}, ['Kode Permission', 'Deskripsi'].map((h) => UI.el('th', {}, h)))]),
                                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '2' }, 'Tidak ada permission')])]),
                                ]),
                            ]));
                        },
                    },
                    { key: 'timeline', label: 'Timeline', render: (body) => body.appendChild(renderTimeline(result.timeline)) },
                ],
            });
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    return {
        openEntity, openTransaction, openInventory, renderTimeline, renderKv,
        openTransfer, openOpname, openProduction, openOpening, openImport, openUser, openRole,
    };
})();
