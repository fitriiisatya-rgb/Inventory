/**
 * PHASE V2.6D — Kartu Stok (Stock Card), opened by clicking "Stok
 * Tersedia" on Laporan Stok. Read-only: reuses GET /reports/stock/card
 * (itself a thin pagination wrapper over the EXISTING
 * InventoryService::ledger()/currentStock() — the same engine the
 * "Mutasi Stok / Ledger" tab already uses) — never a second ledger
 * computation. A row with a real transaction_id opens the EXISTING
 * TraceDrawer.openTransaction(), never a second transaction-detail view.
 *
 * Also hosts the "Semua Gudang" warehouse-breakdown view (GET
 * /inventory/current/{sku}, already existing and already permission/
 * warehouse-scope safe) — each warehouse's figure opens that
 * warehouse's own Kartu Stok.
 */
const StockCard = (() => {
    function reportStatus(qty, minimum) {
        if (qty <= 0) return 'HABIS';
        if (qty <= minimum) return 'WARNING';
        return 'AMAN';
    }

    function statusBadge(status) {
        const cls = { AMAN: 'badge-status-safe', WARNING: 'badge-status-low', HABIS: 'badge-status-critical' }[status] || 'badge-status-safe';
        const label = { AMAN: 'Aman', WARNING: 'Warning', HABIS: 'Habis' }[status] || status;
        return UI.el('span', { class: `badge ${cls}` }, label);
    }

    /**
     * @param {{item_id:number, sku:string, name:string, category?:string,
     *   unit:string, warehouse_id:number, warehouse_name:string, minimum_stock:number}} ctx
     */
    async function open(ctx) {
        Drawer.open({ title: `Kartu Stok — ${ctx.sku}`, render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        await renderPage(ctx, 1);
    }

    async function renderPage(ctx, page) {
        let result;
        try {
            result = await InvApi.stockCard({ item_id: ctx.item_id, warehouse_id: ctx.warehouse_id, page, per_page: 50 });
        } catch (err) {
            UI.handleApiError(err);
            Drawer.open({ title: `Kartu Stok — ${ctx.sku}`, render: (body) => {
                body.innerHTML = '';
                body.appendChild(UI.el('div', { class: 'alert alert-error' }, `Gagal memuat kartu stok: ${(err && err.message) || ''}`));
            } });
            return;
        }

        const qty = result.current.qty_base;
        const status = reportStatus(qty, ctx.minimum_stock);

        Drawer.open({
            title: `Kartu Stok — ${ctx.sku} @ ${ctx.warehouse_name}`,
            render: (body) => {
                body.innerHTML = '';
                body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                    ['SKU', ctx.sku],
                    ['Nama Barang', ctx.name],
                    ['Kategori', ctx.category || '-'],
                    ['Gudang', ctx.warehouse_name],
                    ['Satuan', ctx.unit],
                    ['Stok Saat Ini', UI.formatNumber(qty)],
                    ['Stok Minimal', UI.formatNumber(ctx.minimum_stock)],
                ])));
                const statusRow = UI.el('div', { style: 'margin:-6px 0 14px; display:flex; align-items:center; gap:8px;' }, ['Status:', statusBadge(status)]);
                body.appendChild(statusRow);

                // PHASE V2.9 — edit the EXISTING per-item-per-warehouse
                // policy (StockPolicyService, PUT /stock-policy). STOCK
                // role holds INVENTORY_VIEW (sees the figures above) but
                // not STOCK_POLICY_MANAGE by default (schema.sql
                // role_permissions), so it stays view-only here unless
                // that grant is explicitly given — never a frontend-only
                // check, the PUT route re-enforces this permission itself.
                if (Auth.hasPermission('STOCK_POLICY_MANAGE')) {
                    body.appendChild(editMinimumControl(ctx, page));
                }

                body.appendChild(UI.el('h4', { style: 'margin:14px 0 8px; font-size:0.85rem; color:var(--text2);' }, '📈 LIVE STOCK CARD (16 Sep onward)'));
                body.appendChild(movementTable(result.movements, ctx));

                if (result.pagination.total_pages > 1) {
                    body.appendChild(paginationBar(result.pagination, (p) => renderPage(ctx, p)));
                }

                if (result.historical && result.historical.length) {
                    body.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:16px;' }, [
                        UI.el('span', { class: 'hpp-pre-go-live-badge' }, 'HISTORICAL'),
                        ' Reporting Only — 1–15 Sep 2026, tidak memengaruhi saldo stok live.',
                    ]));
                    body.appendChild(movementTable(result.historical, ctx, true));
                }
            },
        });
    }

    /**
     * PHASE V2.9 — inline "Ubah Stok Minimal" control for one item+gudang.
     * Fetches the current policy fresh (never trusts ctx.minimum_stock's
     * possibly-stale origin, e.g. a value carried over from the "Semua
     * Gudang" breakdown before it too was fixed to resolve per-warehouse)
     * so the edit form always starts from THIS warehouse's real current
     * minimum/buffer.
     */
    function editMinimumControl(ctx, page) {
        const host = UI.el('div', { style: 'margin:-4px 0 14px;' });
        const toggleBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '✏️ Ubah Stok Minimal');
        host.appendChild(toggleBtn);

        toggleBtn.addEventListener('click', async () => {
            toggleBtn.disabled = true;
            let policy;
            try {
                policy = await InvApi.getStockPolicy(ctx.item_id, ctx.warehouse_id);
            } catch (err) {
                UI.handleApiError(err);
                toggleBtn.disabled = false;
                return;
            }
            host.innerHTML = '';
            const minInput = UI.el('input', { type: 'number', step: 'any', min: '0', value: String(policy.minimum_stock) });
            const bufInput = UI.el('input', { type: 'number', step: 'any', min: '0', value: policy.buffer_configured ? String(policy.buffer_stock) : '' });
            const alertBox = UI.el('div', { id: 'stock-policy-edit-alert' });
            const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Simpan');
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Batal');
            saveBtn.addEventListener('click', async () => {
                alertBox.innerHTML = '';
                const minVal = parseFloat(minInput.value);
                if (!(minVal >= 0)) {
                    alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Stok Minimal harus angka >= 0.'));
                    return;
                }
                const bufVal = bufInput.value.trim() === '' ? null : parseFloat(bufInput.value);
                if (bufVal !== null && !(bufVal >= 0)) {
                    alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Buffer Stock harus angka >= 0 (atau kosongkan).'));
                    return;
                }
                saveBtn.disabled = true;
                try {
                    await InvApi.saveStockPolicy({ item_id: ctx.item_id, warehouse_id: ctx.warehouse_id, minimum_stock: minVal, buffer_stock: bufVal });
                } catch (err) {
                    UI.handleApiError(err);
                    alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan.'));
                    saveBtn.disabled = false;
                    return;
                }
                // Refresh THIS warehouse's own ctx.minimum_stock and
                // re-render the whole drawer so the Stok Minimal figure
                // and status badge above update immediately.
                ctx.minimum_stock = minVal;
                renderPage(ctx, page);
            });
            cancelBtn.addEventListener('click', () => renderPage(ctx, page));
            host.appendChild(UI.el('div', { class: 'grid-2', style: 'max-width:420px; gap:8px; align-items:end;' }, [
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Stok Minimal (gudang ini)'), minInput]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Buffer Stock (opsional)'), bufInput]),
            ]));
            host.appendChild(UI.el('div', { style: 'display:flex; gap:8px; margin-top:6px;' }, [saveBtn, cancelBtn]));
            host.appendChild(alertBox);
            toggleBtn.disabled = false;
        });

        return host;
    }

    function movementTable(rows, ctx, isHistorical) {
        const body = rows.map((r) => {
            const clickable = !!r.transaction_id;
            const tr = UI.el('tr', { class: clickable ? 'stock-card-row-clickable' : '' }, [
                UI.el('td', {}, UI.formatDate(r.date)),
                UI.el('td', {}, r.reference || `#${r.transaction_id}`),
                UI.el('td', {}, r.transaction_type),
                UI.el('td', {}, r.in_qty ? UI.formatNumber(r.in_qty) : '-'),
                UI.el('td', {}, r.out_qty ? UI.formatNumber(r.out_qty) : '-'),
                UI.el('td', {}, UI.formatNumber(isHistorical ? r.historical_running_balance : r.balance_qty)),
            ]);
            if (clickable) {
                tr.addEventListener('click', () => TraceDrawer.openTransaction(r.transaction_id));
            }
            return tr;
        });
        return UI.el('div', { class: 'table-wrapper' }, [
            UI.el('table', {}, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Referensi', 'Jenis', 'Masuk', 'Keluar', 'Saldo'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, body.length ? body : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Belum ada pergerakan')])]),
            ]),
        ]);
    }

    function paginationBar(pagination, onPage) {
        const bar = UI.el('div', { style: 'display:flex; justify-content:center; align-items:center; gap:10px; margin-top:10px;' });
        const prev = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '‹ Sebelumnya');
        const next = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Berikutnya ›');
        prev.disabled = pagination.page <= 1;
        next.disabled = pagination.page >= pagination.total_pages;
        prev.addEventListener('click', () => onPage(pagination.page - 1));
        next.addEventListener('click', () => onPage(pagination.page + 1));
        bar.appendChild(prev);
        bar.appendChild(UI.el('span', { style: 'color:var(--text3); font-size:0.78rem;' }, `Halaman ${pagination.page} / ${pagination.total_pages}`));
        bar.appendChild(next);
        return bar;
    }

    /**
     * "Semua Gudang" -> per-warehouse breakdown, each row opening that
     * warehouse's own Kartu Stok. Only ACTIVE warehouses are ever shown
     * (Karang Tengah / any PENDING_CUTOVER warehouse never appears here,
     * both because it is filtered out below and because — having never
     * been transacted — it has no inventory_batches rows to aggregate in
     * the first place).
     */
    async function openWarehouseBreakdown(ctx) {
        Drawer.open({ title: `Breakdown Gudang — ${ctx.sku}`, render: (body) => { body.innerHTML = '<div class="alert alert-info">Memuat...</div>'; } });
        let result;
        try {
            result = await InvApi.currentStockBySku(ctx.sku);
        } catch (err) {
            UI.handleApiError(err);
            return;
        }
        const activeWarehouseIds = new Set(Master.warehouses().filter((w) => Number(w.is_active) === 1).map((w) => Number(w.id)));
        const rows = (result.by_warehouse || []).filter((r) => activeWarehouseIds.has(Number(r.warehouse_id)));

        Drawer.open({
            title: `Breakdown Gudang — ${ctx.sku}`,
            render: (body) => {
                body.innerHTML = '';
                body.appendChild(Drawer.section('Ringkasan', Drawer.kv([
                    ['SKU', ctx.sku],
                    ['Nama Barang', ctx.name],
                    ['Satuan', ctx.unit],
                    ['Total Stok (semua gudang aktif)', UI.formatNumber(result.total.qty_base)],
                ])));
                // PHASE V2.9 — each row's minimum_stock/report_status now
                // comes straight from the backend (InventoryService::
                // currentStockAllWarehouses(), resolved via
                // StockPolicyService PER warehouse) — never a single
                // company-wide minimum reused across every row, which
                // would let one warehouse's healthy stock visually mask
                // another warehouse's real shortage.
                const trs = rows.map((r) => {
                    const wh = Master.warehouseById(r.warehouse_id);
                    const whName = (wh && wh.name) || `#${r.warehouse_id}`;
                    const tr = UI.el('tr', { class: 'stock-card-row-clickable' }, [
                        UI.el('td', {}, whName),
                        UI.el('td', {}, UI.formatNumber(r.qty_base)),
                        UI.el('td', {}, UI.formatNumber(r.minimum_stock)),
                        UI.el('td', {}, statusBadge(r.report_status)),
                        UI.el('td', {}, UI.formatMoney(r.value)),
                    ]);
                    tr.addEventListener('click', () => open({
                        item_id: ctx.item_id, sku: ctx.sku, name: ctx.name, category: ctx.category, unit: ctx.unit,
                        warehouse_id: r.warehouse_id, warehouse_name: whName,
                        // THIS row's own resolved minimum — NOT ctx.minimum_stock
                        // (which may be the "Semua Gudang" global fallback the
                        // calling Laporan Stok row used, a different
                        // warehouse's own Kartu Stok's value, or stale from a
                        // previous navigation — never assumed to apply here).
                        minimum_stock: r.minimum_stock,
                    }));
                    return tr;
                });
                trs.push(UI.el('tr', { style: 'font-weight:700; border-top:2px solid var(--border);' }, [
                    UI.el('td', {}, 'TOTAL'),
                    UI.el('td', {}, UI.formatNumber(result.total.qty_base)),
                    UI.el('td', {}, '-'),
                    UI.el('td', {}, '-'),
                    UI.el('td', {}, UI.formatMoney(result.total.value)),
                ]));
                body.appendChild(UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['Gudang', 'Stok Tersedia', 'Stok Minimal', 'Status', 'Nilai Stok'].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, trs),
                    ]),
                ]));
                body.appendChild(UI.el('p', { style: 'color:var(--text3); font-size:0.75rem; margin-top:10px;' }, 'Klik salah satu gudang untuk membuka Kartu Stok gudang tersebut. Angka total ini bersifat presentasi — FIFO tidak digabung antar gudang.'));
            },
        });
    }

    return { open, openWarehouseBreakdown, reportStatus, statusBadge };
})();
