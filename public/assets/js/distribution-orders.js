/**
 * PHASE V2.11A — SCM -> Bakery Delivery Order (Distribusi Bakery). A
 * genuinely different concept from Transfers (transfers.js, warehouse-to-
 * warehouse only) — every DO always names a Bakery Tujuan, never a
 * destination warehouse, and real stock only leaves inventory once the
 * server actually dispatches it (DistributionOrderService::dispatch()).
 * This module never computes/mutates inventory itself — every number
 * shown after an action comes straight from what the API returned.
 *
 * Create form (HOTFIX, post-274dc78): a compact header + a single Quick
 * Add bar (one ItemSelector instance — searchable item + barcode, same
 * component as Stock IN/OUT — re-mounted fresh after every add) feeding
 * an Order Lines table, so adding dozens of real bakery-delivery items
 * never renders one full form block per line.
 *
 * Pricing/Invoice (Phase V2.11B) and the polished receiving UI/discrepancy
 * workflow/print documents (Phase V2.11C) are NOT part of this module yet
 * — this phase ships DO create/approve/picking/dispatch plus a functional
 * (not yet visually polished) receive form, per the phased delivery plan.
 */
const DistributionOrders = (() => {
    let dtHandle = null;
    // HOTFIX (post-274dc78) — Quick Add + Order Lines Table (Section C):
    // one single ItemSelector instance backs the Quick Add bar (re-mounted
    // fresh after every add), never one ItemSelector per already-added
    // line. `quickAddLines` holds the collected rows; DistributionOrderService
    // itself is untouched — this is purely how the frontend collects lines
    // before calling create().
    let quickAddSelectorCtl = null;
    let quickAddSelectorHost = null;
    let quickAddQtyInput = null;
    let quickAddWasValid = false;
    let quickAddLines = []; // [{ itemId, unitId, sku, name, unitCode, qty, stockBaseQty }]
    let quickAddLinesHost = null;
    let quickAddSummaryHost = null;
    let scmWarehouseId = null;
    const stockCache = new Map(); // itemId -> qty_base at SCM (best-effort, display only)

    function canManage(permission) {
        return Auth.hasPermission(permission);
    }

    async function render(container) {
        container.innerHTML = '';
        const createHost = UI.el('div');
        container.appendChild(createHost);
        const listHost = UI.el('div');
        container.appendChild(listHost);

        if (canManage('DISTRIBUTION_CREATE')) {
            createHost.appendChild(buildCreateForm());
        }
        renderList(listHost);
    }

    // ============================================================
    // Create form
    // ============================================================
    function buildCreateForm() {
        quickAddLines = [];
        quickAddWasValid = false;
        stockCache.clear();
        const scm = Master.warehouses().find((w) => w.code === 'SCM');
        scmWarehouseId = scm ? Number(scm.id) : null;

        const bakeryOptions = Master.bakeryDestinations().filter((b) => b.is_active)
            .map((b) => `<option value="${b.id}">${b.name}</option>`).join('');

        quickAddSelectorHost = UI.el('div', { style: 'flex:1 1 320px; min-width:220px;' });
        quickAddQtyInput = UI.el('input', { type: 'number', min: '0', step: 'any', placeholder: 'Qty', style: 'width:120px;' });
        const addBtn = UI.el('button', { class: 'btn btn-primary btn-sm', type: 'button' }, 'Tambah');
        quickAddLinesHost = UI.el('div');
        quickAddSummaryHost = UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center; margin-top:12px;' });

        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🚛 Buat Delivery Order Baru (SCM → Bakery)')]),
            UI.el('div', { id: 'do-create-alert' }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Tanggal DO</label><input type="date" id="do-date" value="${new Date().toISOString().slice(0, 10)}"></div>
                <div class="form-group"><label>Bakery Tujuan</label><select id="do-bakery"><option value="">- pilih bakery -</option>${bakeryOptions}</select></div>
                <div class="form-group"><label>Referensi (opsional)</label><input type="text" id="do-ref"></div>
            ` }),
            UI.el('div', { class: 'grid-3', html: `
                <div class="form-group"><label>Driver (opsional)</label><input type="text" id="do-driver"></div>
                <div class="form-group"><label>No. Kendaraan (opsional)</label><input type="text" id="do-vehicle"></div>
                <div class="form-group"><label>Catatan Pengiriman (opsional)</label><input type="text" id="do-delivery-notes"></div>
            ` }),
            UI.el('div', { class: 'card', style: 'padding:10px; margin-top:10px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;' }, [
                quickAddSelectorHost,
                UI.el('div', { class: 'form-group', style: 'margin:0;' }, [UI.el('label', {}, 'Qty'), quickAddQtyInput]),
                addBtn,
            ]),
            quickAddLinesHost,
            quickAddSummaryHost,
        ]);

        addBtn.addEventListener('click', () => addFromQuickAdd());
        quickAddQtyInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addFromQuickAdd(); }
        });

        remountQuickAddSelector();
        renderQuickAddLines();
        return card;
    }

    // Re-mounts a fresh ItemSelector into the Quick Add bar's host — this
    // is the ONE selector instance the whole create form ever has, reset
    // after every successful add so the search box is empty and ready for
    // the next scan/type, with focus back on it (Section C's fast-entry
    // loop: scan -> Enter selects -> unit auto-fills -> focus jumps to Qty
    // -> Enter on Qty adds the row and resets focus back to search).
    function remountQuickAddSelector() {
        if (quickAddSelectorCtl) quickAddSelectorCtl.destroy();
        quickAddWasValid = false;
        quickAddSelectorCtl = ItemSelector.mount(quickAddSelectorHost, {
            onChange: (state) => {
                if (!quickAddWasValid && state.valid && state.itemId) {
                    quickAddQtyInput.value = '';
                    quickAddQtyInput.focus();
                }
                quickAddWasValid = state.valid;
            },
        });
    }

    function addFromQuickAdd() {
        const state = quickAddSelectorCtl.getState();
        if (!state.valid || !state.itemId) { UI.toast(ItemSelector.MESSAGES.PICK_FROM_RESULTS, 'error'); quickAddSelectorCtl.focus(); return; }
        if (!state.unitId) { UI.toast('Satuan wajib dipilih.', 'error'); return; }
        const qty = Number(quickAddQtyInput.value);
        if (!(qty > 0)) { UI.toast('Jumlah harus lebih dari 0.', 'error'); quickAddQtyInput.focus(); return; }

        const item = state.item || Master.itemById(state.itemId);
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));

        // Duplicate item+unit: merge into the existing row instead of
        // silently adding a second row for the same thing.
        const existing = quickAddLines.find((l) => l.itemId === state.itemId && l.unitId === state.unitId);
        if (existing) {
            existing.qty += qty;
            UI.toast(`Qty digabung ke baris ${existing.sku} yang sudah ada (total ${UI.formatNumber(existing.qty)}).`, 'info');
        } else {
            quickAddLines.push({
                itemId: state.itemId, unitId: state.unitId,
                sku: item ? item.sku : '', name: item ? item.name : '',
                unitCode: unit ? unit.code : '', qty,
                stockBaseQty: stockCache.has(state.itemId) ? stockCache.get(state.itemId) : undefined,
            });
            loadStockFor(state.itemId);
        }

        renderQuickAddLines();
        remountQuickAddSelector();
        quickAddSelectorCtl.focus();
    }

    async function loadStockFor(itemId) {
        if (stockCache.has(itemId) || !scmWarehouseId) return;
        try {
            const stock = await InvApi.currentStock(itemId, scmWarehouseId);
            stockCache.set(itemId, Number(stock.qty_base));
        } catch (err) {
            stockCache.set(itemId, null); // fetch failed — show '-' rather than retrying forever
        }
        quickAddLines.forEach((l) => { if (l.itemId === itemId) l.stockBaseQty = stockCache.get(itemId); });
        renderQuickAddLines();
    }

    function renderQuickAddLines() {
        quickAddLinesHost.innerHTML = '';
        quickAddSummaryHost.innerHTML = '';
        if (quickAddLines.length === 0) {
            quickAddLinesHost.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:10px;' }, 'Belum ada barang. Cari/scan barang di atas untuk menambahkan.'));
        } else {
            const rows = quickAddLines.map((l, idx) => {
                const qtyInput = UI.el('input', { type: 'number', min: '0', step: 'any', value: String(l.qty), style: 'width:100px;' });
                qtyInput.addEventListener('change', () => {
                    const v = Number(qtyInput.value);
                    l.qty = v > 0 ? v : l.qty;
                    qtyInput.value = String(l.qty);
                    renderQuickAddLines();
                });
                const removeBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '✕');
                removeBtn.addEventListener('click', () => {
                    quickAddLines.splice(idx, 1);
                    renderQuickAddLines();
                });
                return UI.el('tr', {}, [
                    UI.el('td', {}, String(idx + 1)),
                    UI.el('td', {}, l.sku),
                    UI.el('td', {}, l.name),
                    UI.el('td', {}, l.unitCode),
                    UI.el('td', {}, qtyInput),
                    UI.el('td', {}, l.stockBaseQty === undefined ? 'Memuat...' : (l.stockBaseQty === null ? '-' : UI.formatNumber(l.stockBaseQty))),
                    UI.el('td', {}, removeBtn),
                ]);
            });
            quickAddLinesHost.appendChild(UI.el('div', { class: 'table-wrapper', style: 'margin-top:10px;' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['No.', 'SKU', 'Nama Barang', 'Satuan', 'Qty', 'Stok SCM', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows),
                ]),
            ]));
        }

        quickAddSummaryHost.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.9rem;' }, `${quickAddLines.length} jenis barang`));
        const saveBtn = UI.el('button', { class: 'btn btn-primary', id: 'do-submit-btn' }, 'Simpan Delivery Order (Draft)');
        saveBtn.addEventListener('click', () => submitCreate(saveBtn.closest('.card')));
        quickAddSummaryHost.appendChild(saveBtn);
    }

    async function submitCreate(card) {
        const alertBox = document.getElementById('do-create-alert');
        alertBox.innerHTML = '';
        const doDate = document.getElementById('do-date').value;
        const bakeryId = document.getElementById('do-bakery').value;
        if (!bakeryId) { UI.toast('Bakery tujuan wajib dipilih.', 'error'); return; }
        if (quickAddLines.length === 0) { UI.toast('Minimal satu baris barang wajib diisi.', 'error'); return; }
        for (const l of quickAddLines) {
            if (!(l.qty > 0)) { UI.toast(`Jumlah untuk ${l.sku} harus lebih dari 0.`, 'error'); return; }
        }

        const scm = Master.warehouses().find((w) => w.code === 'SCM');
        if (!scm) { UI.toast('Gudang SCM tidak ditemukan pada master data.', 'error'); return; }

        const lines = quickAddLines.map((l) => ({ item_id: l.itemId, input_qty: l.qty, input_unit_id: l.unitId }));

        try {
            const result = await InvApi.createDistributionOrder({
                do_date: doDate, bakery_destination_id: Number(bakeryId), from_warehouse_id: Number(scm.id),
                reference_no: document.getElementById('do-ref').value || null,
                driver_name: document.getElementById('do-driver').value || null,
                vehicle_no: document.getElementById('do-vehicle').value || null,
                delivery_notes: document.getElementById('do-delivery-notes').value || null,
                lines,
            });
            UI.toast(`Delivery Order ${result.do_number} berhasil dibuat (Draft).`, 'success');
            const fresh = buildCreateForm();
            card.replaceWith(fresh);
            if (dtHandle) dtHandle.reload();
        } catch (err) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal membuat Delivery Order.'));
        }
    }

    // ============================================================
    // List
    // ============================================================
    function renderList(container) {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📋 Daftar Delivery Order')]),
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-distribution-orders',
            filters: [
                { key: 'status', label: 'Status', type: 'select', placeholder: 'Semua Status', options: [
                    'DRAFT', 'APPROVED', 'PICKING', 'DISPATCHED', 'RECEIVED', 'RECEIVED_WITH_DISCREPANCY', 'COMPLETED', 'CANCELLED',
                ].map((s) => ({ value: s, label: s })) },
            ],
            defaultSort: 'do_date', defaultDir: 'desc', pageSize: 25,
            columns: [
                { key: 'do_number', label: 'No. DO', render: (r) => r.do_number },
                { key: 'do_date', label: 'Tanggal', render: (r) => r.do_date },
                { key: 'bakery_name', label: 'Bakery', render: (r) => r.bakery_name },
                { key: 'status', label: 'Status', render: (r) => UI.el('span', { class: `badge ${UI.badgeClass(r.status)}` }, r.status) },
                { key: 'reference_no', label: 'Referensi', render: (r) => r.reference_no || '-' },
                { key: 'created_at', label: 'Dibuat', render: (r) => UI.formatDate(r.created_at) },
            ],
            // No server-side pagination yet (Section 30's filters are
            // server-applied; page-size slicing is not, since DO volume in
            // this phase is modest) — a single "page 1 of 1" is always
            // reported, matching DataTable's expected {rows, pagination} shape.
            fetchPage: async ({ filters: f }) => {
                const rows = await InvApi.listDistributionOrders(f);
                return { rows, pagination: { page: 1, total_pages: 1, total: rows.length } };
            },
            onRowClick: (row) => openDetail(row.id),
            emptyMessage: 'Belum ada Delivery Order.',
        });
    }

    // ============================================================
    // Detail drawer + lifecycle actions
    // ============================================================
    async function openDetail(doId) {
        let detail;
        try {
            detail = await InvApi.getDistributionOrder(doId);
        } catch (err) {
            UI.handleApiError(err);
            return;
        }

        Drawer.open({
            title: `${detail.do_number} — ${detail.status}`,
            render: (body) => {
                body.appendChild(Drawer.section('Informasi', Drawer.kv([
                    ['Gudang Asal', detail.from_warehouse_name],
                    ['Bakery Tujuan', detail.bakery_name],
                    ['Alamat', detail.delivery_address_snapshot || '-'],
                    ['Tanggal', detail.do_date],
                    ['Referensi', detail.reference_no || '-'],
                    ['Driver', detail.driver_name || '-'],
                    ['Kendaraan', detail.vehicle_no || '-'],
                    ['Status', UI.el('span', { class: `badge ${UI.badgeClass(detail.status)}` }, detail.status)],
                ])));

                const rows = detail.lines.map((l) => UI.el('tr', {}, [
                    UI.el('td', {}, l.sku_snapshot),
                    UI.el('td', {}, l.item_name_snapshot),
                    UI.el('td', {}, UI.formatNumber(l.input_qty)),
                    UI.el('td', {}, l.qty_sent_base !== null ? UI.formatNumber(l.qty_sent_base) : '-'),
                    UI.el('td', {}, l.qty_received_base !== null ? UI.formatNumber(l.qty_received_base) : '-'),
                    UI.el('td', {}, l.discrepancy_reason || (l.difference_qty_base !== null ? '-' : '')),
                ]));
                body.appendChild(Drawer.section('Barang', UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['SKU', 'Nama', 'Jumlah', 'Qty Terkirim', 'Qty Diterima', 'Selisih/Alasan'].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, '-')])]),
                    ]),
                ])));

                body.appendChild(Drawer.section('Aksi', buildActionPanel(detail)));
            },
        });
    }

    function buildActionPanel(detail) {
        const wrap = UI.el('div', { style: 'display:flex; flex-direction:column; gap:10px;' });
        const actionBtn = (label, permission, onClick, danger = false) => {
            if (!canManage(permission)) return;
            const btn = UI.el('button', { class: danger ? 'btn btn-danger' : 'btn btn-primary' }, label);
            btn.addEventListener('click', onClick);
            wrap.appendChild(btn);
        };

        const reload = () => openDetail(detail.id);

        const printBtn = UI.el('button', { class: 'btn btn-secondary' }, '🖨️ Print DO');
        printBtn.addEventListener('click', () => window.open(InvApi.distributionOrderPrintUrl(detail.id), '_blank'));
        wrap.appendChild(printBtn);

        if (detail.status === 'DRAFT') {
            actionBtn('Approve', 'DISTRIBUTION_APPROVE', () => runAction(() => InvApi.approveDistributionOrder(detail.id), 'DO disetujui.', reload));
            actionBtn('Batalkan', 'DISTRIBUTION_CREATE', () => runCancel(detail.id, reload), true);
        } else if (detail.status === 'APPROVED') {
            actionBtn('Mulai Picking', 'DISTRIBUTION_APPROVE', () => runAction(() => InvApi.startPickingDistributionOrder(detail.id), 'DO mulai picking.', reload));
            actionBtn('Batalkan', 'DISTRIBUTION_CREATE', () => runCancel(detail.id, reload), true);
        } else if (detail.status === 'PICKING') {
            actionBtn('Dispatch (Kirim — Stock OUT nyata)', 'DISTRIBUTION_DISPATCH', () => runAction(
                () => InvApi.dispatchDistributionOrder(detail.id, { request_uuid: InvApi.newRequestUuid() }),
                'DO berhasil di-dispatch. Stok SCM telah berkurang.', reload
            ));
            actionBtn('Batalkan', 'DISTRIBUTION_CREATE', () => runCancel(detail.id, reload), true);
        } else if (detail.status === 'DISPATCHED') {
            if (canManage('DISTRIBUTION_RECEIVE')) {
                wrap.appendChild(buildReceiveForm(detail, reload));
            }
            actionBtn('Reverse (Batalkan setelah dispatch)', 'DISTRIBUTION_REVERSE', () => runReverse(detail.id, reload), true);
        } else if (detail.status === 'RECEIVED' || detail.status === 'RECEIVED_WITH_DISCREPANCY') {
            actionBtn('Selesaikan (Complete)', 'DISTRIBUTION_RECEIVE', () => runAction(() => InvApi.completeDistributionOrder(detail.id), 'DO diselesaikan.', reload));
            actionBtn('Reverse (Batalkan setelah dispatch)', 'DISTRIBUTION_REVERSE', () => runReverse(detail.id, reload), true);
        }
        if (wrap.children.length === 0) {
            wrap.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, 'Tidak ada aksi tersedia untuk status ini / peran Anda.'));
        }
        return wrap;
    }

    function buildReceiveForm(detail, onDone) {
        const wrap = UI.el('div', { class: 'card', style: 'padding:12px;' }, [
            UI.el('div', { style: 'font-weight:700; margin-bottom:8px;' }, 'Konfirmasi Penerimaan Bakery'),
        ]);
        const rowInputs = detail.lines.map((l) => {
            const qtyInput = UI.el('input', { type: 'number', min: '0', step: 'any', value: String(l.qty_sent_base) });
            const reasonSelect = UI.el('select', { html: `
                <option value="">- tidak ada selisih -</option>
                <option value="KURANG">Kurang</option>
                <option value="RUSAK">Rusak</option>
                <option value="REJECT">Reject</option>
                <option value="SALAH_BARANG">Salah Barang</option>
                <option value="LAINNYA">Lainnya</option>
            ` });
            wrap.appendChild(UI.el('div', { class: 'grid-3', style: 'margin-bottom:6px;' }, [
                UI.el('div', {}, `${l.sku_snapshot} — ${l.item_name_snapshot} (terkirim ${UI.formatNumber(l.qty_sent_base)})`),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Qty Diterima'), qtyInput]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Alasan Selisih (jika ada)'), reasonSelect]),
            ]));
            return { doLineId: l.id, qtyInput, reasonSelect };
        });
        const submitBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Simpan Penerimaan');
        submitBtn.addEventListener('click', async () => {
            const lines = rowInputs.map((r) => ({
                do_line_id: r.doLineId, qty_received: Number(r.qtyInput.value),
                discrepancy_reason: r.reasonSelect.value || null,
            }));
            try {
                const result = await InvApi.receiveDistributionOrder(detail.id, { lines });
                UI.toast(`Penerimaan tersimpan (${result.status}).`, 'success');
                onDone();
            } catch (err) {
                UI.handleApiError(err);
            }
        });
        wrap.appendChild(submitBtn);
        return wrap;
    }

    async function runAction(fn, successMessage, onDone) {
        try {
            await fn();
            UI.toast(successMessage, 'success');
            onDone();
            if (dtHandle) dtHandle.reload();
        } catch (err) {
            UI.handleApiError(err);
        }
    }

    async function runCancel(doId, onDone) {
        const reason = await Modal.prompt({ title: 'Batalkan Delivery Order', label: 'Alasan pembatalan', required: true });
        if (reason === null) return;
        runAction(() => InvApi.cancelDistributionOrder(doId, { reason }), 'Delivery Order dibatalkan.', onDone);
    }

    async function runReverse(doId, onDone) {
        const confirmed = await Modal.confirm({
            title: 'Reverse Delivery Order',
            message: 'Ini akan mengembalikan stok yang sudah keluar (FIFO) dan membalik status transaksi. Tindakan ini hanya untuk peran yang berwenang.',
            danger: true,
        });
        if (!confirmed) return;
        const reason = await Modal.prompt({ title: 'Alasan Reverse', label: 'Alasan (wajib, minimal 5 karakter)', required: true });
        if (reason === null) return;
        runAction(() => InvApi.reverseDistributionOrder(doId, { reason, request_uuid: InvApi.newRequestUuid() }), 'Delivery Order berhasil di-reverse.', onDone);
    }

    return { render };
})();
