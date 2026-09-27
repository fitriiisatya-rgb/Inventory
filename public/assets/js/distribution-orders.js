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
    // PHASE V2.14.9 — compact bulk table (was the post-274dc78 Quick Add
    // bar: one item added at a time, then appended to a read-only table
    // below). A 20-50+ item DO is now filled the same way Transfer/
    // Production/DO-Receive already are (V2.14.8): N independent,
    // pre-rendered rows, each with its own ItemSelector (compact,
    // externalUnitHost) so Barang/Satuan land in their own columns, live
    // "Stok Tersedia" at SCM, +5/+10 fast-entry, and a duplicate-item
    // guard that WARNS AND BLOCKS submit — never silently merges, unlike
    // the old Quick Add bar's merge-on-duplicate behavior; nothing in
    // DistributionOrderService documents a merge guarantee, so this
    // matches the same "never invent a merge" rule already applied to
    // Transfer/Production. DistributionOrderService::create() and its
    // payload shape ({item_id, input_qty, input_unit_id} per line, plus
    // the unchanged header fields) are completely untouched — only how
    // the frontend collects `lines` before calling create() changed.
    let lineCount = 0;
    let lineSelectors = new Map(); // idx -> { ctl, qtyInput, unitHost, rowEl, noCell, stockCell, qtyWarn, stockBase, itemId }
    let scmWarehouseId = null;

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
    // Create form — compact bulk table (V2.14.9)
    // ============================================================
    function buildCreateForm() {
        lineCount = 0;
        lineSelectors.forEach((l) => l.ctl.destroy());
        lineSelectors = new Map();
        const scm = Master.warehouses().find((w) => w.code === 'SCM');
        scmWarehouseId = scm ? Number(scm.id) : null;

        const bakeryOptions = Master.bakeryDestinations().filter((b) => b.is_active)
            .map((b) => `<option value="${b.id}">${b.name}</option>`).join('');

        const tbody = UI.el('tbody', { id: 'do-create-lines-tbody' });
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
            UI.el('div', { class: 'compact-table-toolbar' }, [
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'do-create-add-line' }, '+ Tambah Barang'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'do-create-add-5' }, '+ 5 Baris'),
                UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'do-create-add-10' }, '+ 10 Baris'),
            ]),
            UI.el('div', { class: 'compact-table-wrap' }, [
                UI.el('table', { class: 'compact-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['No', 'Barang / SKU', 'Satuan', 'Qty Kirim', 'Stok Tersedia', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    tbody,
                ]),
            ]),
            UI.el('div', { class: 'compact-summary', id: 'do-create-summary' }),
            UI.el('div', { style: 'margin-top:14px;' }, [
                UI.el('button', { class: 'btn btn-primary', id: 'do-submit-btn' }, 'Simpan Delivery Order (Draft)'),
            ]),
        ]);
        setTimeout(() => {
            addCreateLine();
            document.getElementById('do-create-add-line').addEventListener('click', () => addCreateLine());
            document.getElementById('do-create-add-5').addEventListener('click', () => { for (let i = 0; i < 5; i++) addCreateLine(); });
            document.getElementById('do-create-add-10').addEventListener('click', () => { for (let i = 0; i < 10; i++) addCreateLine(); });
            document.getElementById('do-submit-btn').addEventListener('click', () => submitCreate(card));
        }, 0);
        return card;
    }

    function addCreateLine() {
        const idx = lineCount++;
        const itemSelectorHost = UI.el('div');
        const unitHost = UI.el('td', {});
        const qtyInput = UI.el('input', { type: 'number', class: 'do-create-line-qty', min: '0', step: 'any' });
        const qtyWarn = UI.el('div', { class: 'compact-inline-warning', style: 'display:none;' });
        const stockCell = UI.el('td', { class: 'compact-col-stock' }, '-');
        const noCell = UI.el('td', { class: 'compact-col-no' }, String(lineSelectors.size + 1));
        const removeBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm compact-row-btn', title: 'Hapus baris ini' }, '✕');
        const itemCell = UI.el('td', { class: 'compact-col-item' }, [itemSelectorHost]);
        const qtyCell = UI.el('td', { class: 'compact-col-qty' }, [qtyInput, qtyWarn]);
        const row = UI.el('tr', { id: `do-create-line-${idx}` }, [
            noCell, itemCell, unitHost, qtyCell, stockCell,
            UI.el('td', { class: 'compact-col-action' }, [removeBtn]),
        ]);
        document.getElementById('do-create-lines-tbody').appendChild(row);

        const entry = { qtyInput, unitHost, rowEl: row, noCell, stockCell, qtyWarn, stockBase: null, itemId: null };
        const ctl = ItemSelector.mount(itemSelectorHost, {
            showLabel: false, compact: true, externalUnitHost: unitHost,
            onChange: (state) => {
                entry.itemId = state.valid ? state.itemId : null;
                refreshCreateStockFor(idx);
                checkCreateDuplicates();
            },
        });
        entry.ctl = ctl;
        lineSelectors.set(idx, entry);
        qtyInput.addEventListener('input', () => checkCreateQtyWarning(idx));

        removeBtn.addEventListener('click', () => removeCreateLine(idx));
        renumberCreateRows();
        updateCreateSummary();
    }

    // Same "never leave the form at zero lines" convention as Transfer/
    // Production: destroys this row's ItemSelector before removing its
    // DOM (others untouched), auto-refilling one blank row if it was the
    // last one.
    function removeCreateLine(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        entry.ctl.destroy();
        entry.rowEl.remove();
        lineSelectors.delete(idx);
        if (lineSelectors.size === 0) addCreateLine();
        renumberCreateRows();
        checkCreateDuplicates();
        updateCreateSummary();
    }

    function renumberCreateRows() {
        let n = 1;
        lineSelectors.forEach((entry) => { entry.noCell.textContent = String(n++); });
    }

    // "Stok Tersedia" is always SCM stock (the only source warehouse a DO
    // can ever ship from — assertIsScmWarehouse() enforces this
    // server-side too), reusing the same InvApi.currentStock() endpoint
    // Transfer/Production already use — no new endpoint.
    async function refreshCreateStockFor(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        if (!entry.itemId || !scmWarehouseId) { entry.stockBase = null; entry.stockCell.textContent = '-'; return; }
        entry.stockCell.textContent = '...';
        try {
            const stock = await InvApi.currentStock(entry.itemId, scmWarehouseId);
            const current = lineSelectors.get(idx);
            if (!current || current.itemId !== entry.itemId) return; // selection changed while awaiting
            current.stockBase = Number(stock.qty_base);
            current.stockCell.textContent = UI.formatNumber(current.stockBase);
            checkCreateQtyWarning(idx);
        } catch (err) {
            entry.stockCell.textContent = '-';
        }
    }

    // Inline-only warning — DistributionOrderService::create() has no
    // stock-availability check at create time (only dispatch() actually
    // consumes FIFO stock), so this never blocks submit, only informs.
    function checkCreateQtyWarning(idx) {
        const entry = lineSelectors.get(idx);
        if (!entry) return;
        const qty = Number(entry.qtyInput.value);
        if (!qty || entry.stockBase === null) { entry.qtyWarn.style.display = 'none'; return; }
        const state = entry.ctl.getState();
        const unit = (state.units || []).find((u) => String(u.id) === String(state.unitId));
        const factor = unit ? Number(unit.conversion_to_base) : 1;
        const qtyBaseEquivalent = qty * factor;
        if (qtyBaseEquivalent > entry.stockBase) {
            entry.qtyWarn.textContent = `⚠ Melebihi stok tersedia (${UI.formatNumber(entry.stockBase)})`;
            entry.qtyWarn.style.display = 'block';
        } else {
            entry.qtyWarn.style.display = 'none';
        }
        updateCreateSummary();
    }

    // Warn AND block submit rather than silently merge — matches the
    // Transfer/Production bulk-table convention; DistributionOrderService
    // ::create() documents no same-item-line merge guarantee, so two rows
    // picking the same item must be corrected by the admin, not
    // auto-combined (a change from the old Quick Add bar's merge-on-
    // duplicate behavior, deliberate per this redesign).
    function checkCreateDuplicates() {
        const seen = new Map();
        lineSelectors.forEach((entry, idx) => {
            if (!entry.itemId) return;
            if (!seen.has(entry.itemId)) seen.set(entry.itemId, []);
            seen.get(entry.itemId).push(idx);
        });
        const duplicateIdxs = new Set();
        seen.forEach((idxs) => { if (idxs.length > 1) idxs.forEach((i) => duplicateIdxs.add(i)); });
        lineSelectors.forEach((entry, idx) => {
            entry.rowEl.classList.toggle('compact-row-duplicate', duplicateIdxs.has(idx));
        });
        return duplicateIdxs.size > 0;
    }

    function updateCreateSummary() {
        const box = document.getElementById('do-create-summary');
        if (!box) return;
        let totalItem = 0;
        let totalQty = 0;
        lineSelectors.forEach((entry) => {
            const qty = Number(entry.qtyInput.value);
            if (entry.itemId && qty > 0) { totalItem++; totalQty += qty; }
        });
        box.innerHTML = '';
        box.appendChild(UI.el('div', {}, ['Total Item: ', UI.el('b', {}, String(totalItem))]));
        box.appendChild(UI.el('div', {}, ['Total Qty: ', UI.el('b', {}, UI.formatNumber(totalQty))]));
    }

    async function submitCreate(card) {
        const alertBox = document.getElementById('do-create-alert');
        alertBox.innerHTML = '';
        const doDate = document.getElementById('do-date').value;
        const bakeryId = document.getElementById('do-bakery').value;
        if (!bakeryId) { UI.toast('Bakery tujuan wajib dipilih.', 'error'); return; }

        if (checkCreateDuplicates()) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Barang yang sama dipilih di lebih dari satu baris (ditandai merah) — gabungkan menjadi satu baris atau ganti barangnya sebelum menyimpan.'));
            return;
        }

        const scm = Master.warehouses().find((w) => w.code === 'SCM');
        if (!scm) { UI.toast('Gudang SCM tidak ditemukan pada master data.', 'error'); return; }

        // Payload shape is byte-identical to the prior Quick Add
        // implementation and to DistributionOrderService::create()'s
        // expectations — only how these rows were collected changed.
        const lines = [];
        lineSelectors.forEach((entry) => {
            const state = entry.ctl.getState();
            const qty = entry.qtyInput.value;
            if (state.valid && state.itemId && state.unitId && qty) {
                lines.push({ item_id: Number(state.itemId), input_qty: Number(qty), input_unit_id: Number(state.unitId) });
            }
        });
        if (!lines.length) { UI.toast('Tambahkan minimal satu barang dengan jumlah dan satuan.', 'error'); return; }

        const btn = document.getElementById('do-submit-btn');
        btn.disabled = true;
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
        } finally {
            btn.disabled = false;
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

    // PHASE V2.14.8 — compact table (was one grid-3 block per line; a
    // 20-50 item DO produced an extremely long page). Qty Diterima still
    // defaults to qty_sent_base (preserving the existing prefill exactly
    // — never invented), Selisih is a LIVE display-only calculation
    // mirroring DistributionOrderService::receive()'s own
    // `$diff = round($received - $sent, 6)` sign convention, and the
    // discrepancy reason enum/values and submit payload are unchanged
    // from V2.14.7 ({do_line_id, qty_received, discrepancy_reason} only).
    function buildReceiveForm(detail, onDone) {
        const wrap = UI.el('div', { class: 'card', style: 'padding:12px;' }, [
            UI.el('div', { style: 'font-weight:700; margin-bottom:8px;' }, 'Konfirmasi Penerimaan Bakery'),
        ]);
        const tbody = UI.el('tbody', {});
        const summaryBox = UI.el('div', { class: 'compact-summary' });
        const rowInputs = [];

        function updateSummary() {
            let sesuai = 0;
            let selisih = 0;
            rowInputs.forEach((r) => {
                const diff = Number(r.qtyInput.value || 0) - r.sentQty;
                if (Math.abs(diff) > 0.000001) selisih++; else sesuai++;
            });
            summaryBox.innerHTML = '';
            summaryBox.appendChild(UI.el('div', {}, ['Total Item: ', UI.el('b', {}, String(rowInputs.length))]));
            summaryBox.appendChild(UI.el('div', {}, ['Item Sesuai: ', UI.el('b', {}, String(sesuai))]));
            summaryBox.appendChild(UI.el('div', {}, ['Item Selisih: ', UI.el('b', {}, String(selisih))]));
        }

        detail.lines.forEach((l, idx) => {
            const sentQty = Number(l.qty_sent_base);
            const qtyInput = UI.el('input', { type: 'number', min: '0', step: 'any', value: String(sentQty), class: 'do-receive-qty' });
            const selisihCell = UI.el('td', { class: 'compact-col-qty', style: 'text-align:right;' }, '0');
            const reasonSelect = UI.el('select', { html: `
                <option value="">- tidak ada selisih -</option>
                <option value="KURANG">Kurang</option>
                <option value="RUSAK">Rusak</option>
                <option value="REJECT">Reject</option>
                <option value="SALAH_BARANG">Salah Barang</option>
                <option value="LAINNYA">Lainnya</option>
            ` });
            const row = UI.el('tr', {}, [
                UI.el('td', { class: 'compact-col-no' }, String(idx + 1)),
                UI.el('td', { class: 'compact-col-item' }, `${l.sku_snapshot} — ${l.item_name_snapshot}`),
                UI.el('td', { class: 'compact-col-qty', style: 'text-align:right;' }, UI.formatNumber(sentQty)),
                UI.el('td', { class: 'compact-col-qty' }, [qtyInput]),
                selisihCell,
                UI.el('td', {}, [reasonSelect]),
            ]);

            function recompute() {
                const diff = Number(qtyInput.value || 0) - sentQty;
                const hasDiscrepancy = Math.abs(diff) > 0.000001;
                selisihCell.textContent = (diff > 0 ? '+' : '') + UI.formatNumber(diff);
                row.classList.toggle('compact-row-discrepancy', hasDiscrepancy);
                // Requirement E — visually emphasize the reason as required
                // when there's a discrepancy (mirrors, never weakens, the
                // backend's own requirement that discrepancy_reason be set
                // whenever qty_received differs from qty_sent).
                reasonSelect.style.borderColor = (hasDiscrepancy && !reasonSelect.value) ? 'var(--red)' : '';
                updateSummary();
            }
            qtyInput.addEventListener('input', recompute);
            reasonSelect.addEventListener('change', recompute);
            // Fast-entry: Enter on Qty Diterima jumps straight to the next
            // row's Qty Diterima (never submits — submit stays a separate,
            // explicit button click).
            qtyInput.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const next = rowInputs[idx + 1];
                if (next) next.qtyInput.focus();
            });
            recompute();
            tbody.appendChild(row);
            rowInputs.push({ doLineId: l.id, qtyInput, reasonSelect, sentQty });
        });

        wrap.appendChild(UI.el('div', { class: 'compact-table-wrap' }, [
            UI.el('table', { class: 'compact-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['No', 'SKU / Barang', 'Qty Kirim', 'Qty Diterima', 'Selisih', 'Alasan'].map((h) => UI.el('th', {}, h)))]),
                tbody,
            ]),
        ]));
        wrap.appendChild(summaryBox);
        updateSummary();

        const alertBox = UI.el('div', { style: 'margin-top:8px;' });
        const submitBtn = UI.el('button', { class: 'btn btn-primary btn-sm', style: 'margin-top:10px;' }, 'Simpan Penerimaan');
        submitBtn.addEventListener('click', async () => {
            alertBox.innerHTML = '';
            // Client-side mirror of receive()'s own requirement (never a
            // new rule): a discrepancy without a reason is rejected
            // server-side anyway — checking here just saves a round trip.
            const missingReason = rowInputs.some((r) => Math.abs(Number(r.qtyInput.value || 0) - r.sentQty) > 0.000001 && !r.reasonSelect.value);
            if (missingReason) {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Ada baris dengan selisih qty tapi Alasan Selisih belum diisi (ditandai merah) — lengkapi terlebih dahulu.'));
                return;
            }
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
        wrap.appendChild(alertBox);
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
