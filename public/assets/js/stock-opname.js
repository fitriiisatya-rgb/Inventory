/**
 * D9 — Stock Opname. Legacy: Start -> Count -> Finalize (variance) -> Post.
 * PHASE V2.12 — Dual Count: Start -> Assign P1/P2 -> P1 blind count / P2
 * blind count (independent, mobile-friendly) -> automatic compare
 * (MATCH/MISMATCH) -> Recount for MISMATCH (supervisor) -> Finalize
 * (supervisor) -> Post (supervisor).
 *
 * GET /stock-opname/{id} itself decides which shape to hand back: a caller
 * who IS that session's assigned P1 or P2 counter gets the BLIND view
 * (getForCounter — never the other side's qty, never system_qty_base); any
 * other authorized caller gets the full session. This module renders
 * whichever shape arrives — it never tries to infer or bypass that.
 *
 * The "STOCK OPNAME ACTIVE" banner is cosmetic — the backend's own
 * WarehouseLockService is what actually blocks other postings against a
 * warehouse mid-opname, regardless of what this UI shows.
 */
const StockOpname = (() => {
    let currentWarehouseId = null;
    let currentSessionId = null;

    function canSupervise() { return Auth.hasPermission('STOCK_OPNAME_SUPERVISE'); }

    function render(container) {
        container.innerHTML = '';
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        container.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📋 Stock Opname')]),
            UI.el('div', { class: 'form-group', html: `<label>Gudang</label><select id="opname-wh">${whOptions}</select>` }),
        ]));
        container.appendChild(UI.el('div', { id: 'opname-body' }));

        document.getElementById('opname-wh').addEventListener('change', loadForWarehouse);
        if (Master.warehouses().length) loadForWarehouse();
    }

    async function loadForWarehouse() {
        currentWarehouseId = Number(document.getElementById('opname-wh').value);
        const body = document.getElementById('opname-body');
        body.innerHTML = '<div class="alert alert-info">Memeriksa status opname gudang ini...</div>';
        try {
            const sessions = await InvApi.listOpnameSessions({ warehouse_id: currentWarehouseId });
            const active = sessions.find((s) => s.status !== 'POSTED' && s.status !== 'CANCELLED');
            if (active) {
                await renderSession(active.id);
            } else {
                renderStartButton();
            }
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat status opname: ${(err && err.message) || ''}</div>`;
        }
    }

    function renderStartButton() {
        const body = document.getElementById('opname-body');
        body.innerHTML = '';
        body.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'alert alert-info' }, 'Tidak ada sesi opname aktif untuk gudang ini.'),
            UI.el('button', { class: 'btn btn-primary', id: 'opname-start-btn' }, 'Mulai Stock Opname'),
        ]));
        document.getElementById('opname-start-btn').addEventListener('click', startOpname);
    }

    async function startOpname() {
        const btn = document.getElementById('opname-start-btn');
        btn.disabled = true;
        try {
            const result = await InvApi.startOpname({ warehouse_id: currentWarehouseId });
            UI.toast('Sesi opname dimulai.', 'success');
            await renderSession(result.session_id);
        } catch (err) {
            UI.handleApiError(err);
            btn.disabled = false;
        }
    }

    async function renderSession(sessionId) {
        currentSessionId = sessionId;
        const body = document.getElementById('opname-body');
        body.innerHTML = '<div class="alert alert-info">Memuat sesi opname...</div>';
        try {
            const session = await InvApi.getOpname(sessionId);
            body.innerHTML = '';

            if (session.role) {
                body.appendChild(buildBlindCountScreen(session));
                return;
            }

            body.appendChild(UI.el('div', { class: 'banner-lock' }, `🔒 STOCK OPNAME ACTIVE — Sesi #${session.id}${session.session_number ? ` (${session.session_number})` : ''} (status: ${session.status}). Transaksi Masuk/Keluar di gudang ini ditolak oleh server selama opname berlangsung.`));

            if (Auth.hasPermission('AUDIT_LOG_VIEW')) {
                body.appendChild(UI.el('div', { style: 'margin-bottom:12px;' }, [
                    UI.el('button', { class: 'btn btn-secondary btn-sm', id: 'opname-trace-btn' }, `🔍 Lihat Jejak Sesi #${session.id}`),
                ]));
                document.getElementById('opname-trace-btn').addEventListener('click', () => TraceDrawer.openOpname(session.id));
            }

            if (session.status === 'OPEN') {
                // HOTFIX (post-274dc78): workflow_mode is an explicit,
                // server-derived field (session_number !== null => a real
                // V2.12+ session, ALWAYS dual-count) — never inferred from
                // whether P1/P2 happen to be assigned yet. A brand-new
                // dual-count session starts with both null, which used to
                // be misread as "legacy" and rendered the single-count
                // form by mistake.
                if (session.workflow_mode === 'DUAL_COUNT') {
                    body.appendChild(await buildAssignCountersCard(session));
                    if (canSupervise()) {
                        body.appendChild(await buildSupervisorReviewCard(session));
                    } else {
                        body.appendChild(UI.el('div', { class: 'card' }, [
                            UI.el('div', { class: 'alert alert-info' }, 'P1 dan P2 sedang menghitung fisik. Hubungi supervisor untuk melihat perbandingan hasil hitung.'),
                        ]));
                    }
                } else {
                    body.appendChild(buildCountForm(session));
                    setTimeout(() => {
                        const saveBtn = document.getElementById('opname-save-count-btn');
                        const finBtn = document.getElementById('opname-finalize-btn');
                        if (saveBtn) saveBtn.addEventListener('click', saveCounts);
                        if (finBtn) finBtn.addEventListener('click', finalizeOpname);
                    }, 0);
                }
            } else if (session.status === 'FINALIZED') {
                body.appendChild(buildFinalizedView(session));
                setTimeout(() => {
                    const postBtn = document.getElementById('opname-post-btn');
                    const cancelBtn = document.getElementById('opname-cancel-btn');
                    if (postBtn) postBtn.addEventListener('click', postOpname);
                    if (cancelBtn) cancelBtn.addEventListener('click', cancelOpname);
                }, 0);
            } else if (session.status === 'CANCELLED') {
                body.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Sesi opname ini telah dibatalkan.'));
            } else {
                body.appendChild(UI.el('div', { class: 'alert alert-success' }, 'Sesi opname sudah diposting.'));
                body.appendChild(buildPostedActions(session));
            }
        } catch (err) {
            UI.handleApiError(err);
            body.innerHTML = `<div class="alert alert-error">Gagal memuat sesi: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildPostedActions(session) {
        const wrap = UI.el('div', { style: 'display:flex; gap:10px;' });
        const printBtn = UI.el('button', { class: 'btn btn-secondary' }, '🖨️ Print Hasil Opname');
        printBtn.addEventListener('click', () => window.open(InvApi.opnamePrintUrl(session.id), '_blank'));
        wrap.appendChild(printBtn);
        return wrap;
    }

    // ============================================================
    // Dual-count: assign P1/P2
    // ============================================================
    async function buildAssignCountersCard(session) {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, '👥 Dual Count — Tugaskan Petugas 1 (P1) & Petugas 2 (P2)'),
        ]);
        let eligible = [];
        try {
            eligible = await InvApi.opnameEligibleCounters(session.warehouse_id);
        } catch (err) { UI.handleApiError(err); }

        const options = (excludeId) => eligible
            .filter((u) => String(u.id) !== String(excludeId))
            .map((u) => `<option value="${u.id}">${u.username} (${u.role_code})</option>`).join('');

        const p1Select = UI.el('select', { id: 'opname-assign-p1', html: `<option value="">- pilih P1 -</option>${options(session.p2_user_id)}` });
        const p2Select = UI.el('select', { id: 'opname-assign-p2', html: `<option value="">- pilih P2 -</option>${options(session.p1_user_id)}` });
        if (session.p1_user_id) p1Select.value = String(session.p1_user_id);
        if (session.p2_user_id) p2Select.value = String(session.p2_user_id);

        // Reassigning a role once that person has already submitted a
        // blind count is refused server-side (assertRoleNotYetSubmitted) —
        // this form doesn't try to predict that, it just surfaces whatever
        // error the server returns.
        const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm', id: 'opname-assign-save-btn' }, 'Simpan Penugasan');
        const alertBox = UI.el('div');
        saveBtn.addEventListener('click', async () => {
            const assignments = {};
            if (p1Select.value) assignments.p1_user_id = Number(p1Select.value);
            if (p2Select.value) assignments.p2_user_id = Number(p2Select.value);
            alertBox.innerHTML = '';
            try {
                await InvApi.assignOpnameCounters(session.id, assignments);
                UI.toast('Petugas P1/P2 berhasil ditugaskan.', 'success');
                await renderSession(session.id);
            } catch (err) {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menugaskan petugas.'));
            }
        });

        card.appendChild(UI.el('div', { class: 'grid-3' }, [
            UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Petugas 1 (P1)'), p1Select]),
            UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Petugas 2 (P2)'), p2Select]),
            UI.el('div', { style: 'align-self:flex-end;' }, [saveBtn]),
        ]));
        card.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem; margin-top:4px;' },
            'P1 dan P2 harus orang berbeda — server menolak jika sama. Setelah seseorang mulai menghitung, perannya tidak bisa dipindah ke orang lain.'));
        card.appendChild(alertBox);
        return card;
    }

    // ============================================================
    // Blind counting screen (P1 or P2) — PHASE V2.14.8: compact,
    // paginated high-volume table (was one full-width <div class="card">
    // per line — hundreds/1000+ session lines rendered as cards was both
    // the giant-page-length problem this phase targets AND a real iPad
    // scroll-performance risk). Item identity is read-only by design (no
    // ItemSelector anywhere on this screen — session lines are already
    // predetermined; nothing here is "searched and picked").
    //
    // BLINDNESS SAFETY: this screen only ever renders fields already
    // present on `view.lines` (sku, name, item_id, my_qty_base,
    // is_counted_by_me, is_excluded) — the exact same shape
    // StockOpnameService::getForCounter() has always returned, which
    // structurally never includes system_qty_base, the other counter's
    // qty, or match/variance data (see that method's own docblock). This
    // rewrite adds no new field to the request or response and reads
    // nothing beyond what was already reachable in the pre-V2.14.8 code —
    // pagination/filtering only ever slices the SAME array differently.
    //
    // Pagination (not a virtual-scroll/windowed-scroll mechanism) is the
    // "simple safe mechanism" chosen here: at most PAGE_SIZE real DOM rows
    // exist at once regardless of session size, Prev/Next is trivial to
    // test and reason about, and it works identically on desktop/iPad
    // without any scroll-position/resize-observer bookkeeping.
    function buildBlindCountScreen(initialView) {
        const PAGE_SIZE = 50;
        const state = { view: initialView, filterText: '', filterStatus: 'ALL', page: 0 };

        const wrap = UI.el('div');
        const bannerHost = UI.el('div');
        const progressHost = UI.el('div', { class: 'card' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU/nama, atau scan barcode...', class: 'opname-blind-search' });
        const scanBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '📷 Scan');
        const filterSelect = UI.el('select', { id: 'opname-blind-filter', html: `
            <option value="ALL">Semua</option>
            <option value="PENDING">Belum Dihitung</option>
            <option value="COUNTED">Sudah Dihitung</option>
        ` });
        const tbody = UI.el('tbody', {});
        const pagerHost = UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center; margin-top:10px; flex-wrap:wrap; gap:8px;' });

        wrap.appendChild(bannerHost);
        wrap.appendChild(progressHost);
        wrap.appendChild(UI.el('div', { class: 'card', style: 'display:flex; gap:8px; align-items:center; flex-wrap:wrap;' }, [
            UI.el('div', { style: 'flex:1 1 240px;' }, [searchInput]),
            UI.el('div', { style: 'width:180px;' }, [filterSelect]),
            scanBtn,
        ]));
        wrap.appendChild(UI.el('div', { class: 'compact-table-wrap' }, [
            UI.el('table', { class: 'compact-table' }, [
                UI.el('thead', {}, [UI.el('tr', {}, ['No', 'SKU / Barang', 'Qty Hitung', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                tbody,
            ]),
        ]));
        wrap.appendChild(pagerHost);

        function filteredLines() {
            const q = state.filterText.trim().toLowerCase();
            return state.view.lines.filter((l) => {
                if (q && !l.sku.toLowerCase().includes(q) && !l.name.toLowerCase().includes(q)) return false;
                const isDone = l.is_counted_by_me || l.is_excluded;
                if (state.filterStatus === 'PENDING' && isDone) return false;
                if (state.filterStatus === 'COUNTED' && !isDone) return false;
                return true;
            });
        }

        function renderChrome() {
            bannerHost.innerHTML = '';
            bannerHost.appendChild(UI.el('div', { class: 'banner-lock' },
                `🔒 STOCK OPNAME — Sesi ${state.view.session_number || state.view.session_id} — Anda login sebagai ${state.view.role.toUpperCase()} (Hitung Fisik Independen/Blind).`));

            const progressPct = state.view.progress.total > 0 ? Math.round((state.view.progress.counted / state.view.progress.total) * 100) : 0;
            progressHost.innerHTML = '';
            progressHost.appendChild(UI.el('div', { class: 'card-title' }, `Progress ${state.view.role.toUpperCase()}: ${state.view.progress.counted} / ${state.view.progress.total}`));
            progressHost.appendChild(UI.el('div', { style: 'height:10px; background:var(--border); border-radius:6px; overflow:hidden; margin-top:6px;' }, [
                UI.el('div', { style: `height:100%; width:${progressPct}%; background:var(--primary, #2563eb);` }),
            ]));
        }

        function renderTable() {
            const filtered = filteredLines();
            const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
            state.page = Math.min(state.page, totalPages - 1);
            const start = state.page * PAGE_SIZE;
            const pageLines = filtered.slice(start, start + PAGE_SIZE);

            tbody.innerHTML = '';
            if (pageLines.length === 0) {
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Tidak ada barang yang cocok.')]));
            } else {
                pageLines.forEach((line, i) => tbody.appendChild(buildBlindCountRowEl(state, line, start + i + 1)));
            }

            pagerHost.innerHTML = '';
            const prevBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', ...(state.page === 0 ? { disabled: 'disabled' } : {}) }, '‹ Sebelumnya');
            const nextBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', ...(state.page >= totalPages - 1 ? { disabled: 'disabled' } : {}) }, 'Berikutnya ›');
            prevBtn.addEventListener('click', () => { state.page--; renderTable(); });
            nextBtn.addEventListener('click', () => { state.page++; renderTable(); });
            pagerHost.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, `Halaman ${state.page + 1} dari ${totalPages} (${filtered.length} item)`));
            pagerHost.appendChild(UI.el('div', { style: 'display:flex; gap:8px;' }, [prevBtn, nextBtn]));
        }

        searchInput.addEventListener('input', () => { state.filterText = searchInput.value; state.page = 0; renderTable(); });
        filterSelect.addEventListener('change', () => { state.filterStatus = filterSelect.value; state.page = 0; renderTable(); });
        scanBtn.addEventListener('click', () => openScanModal(state, jumpToItem));

        // Re-fetches the SAME getOpname(sessionId) call renderSession()
        // itself uses, but patches state.view / re-renders THIS screen in
        // place — preserving the operator's current search/filter/page
        // (a full renderSession() call would reset all three on every
        // single save, breaking the "next pending item" fast-entry flow).
        async function refetch() {
            const fresh = await InvApi.getOpname(state.view.session_id);
            state.view = fresh;
            renderChrome();
            renderTable();
        }

        function jumpToItem(itemId) {
            const globalIdx = state.view.lines.findIndex((l) => l.item_id === itemId);
            if (globalIdx === -1) return false;
            state.filterText = '';
            state.filterStatus = 'ALL';
            searchInput.value = '';
            filterSelect.value = 'ALL';
            state.page = Math.floor(globalIdx / PAGE_SIZE);
            renderTable();
            const row = tbody.querySelector(`tr[data-item-id="${itemId}"]`);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const qtyInput = row.querySelector('.opname-blind-qty-input');
                if (qtyInput) qtyInput.focus();
            }
            return true;
        }

        // After a successful save, focus the workflow forward: the next
        // PENDING item under the CURRENT filter/search (so a counter
        // working through "Belum Dihitung" stays in that flow), or if the
        // current filter excludes it, just re-render the same page.
        function focusNextPending(afterItemId) {
            const filtered = filteredLines();
            const idx = filtered.findIndex((l) => l.item_id === afterItemId);
            const next = filtered.slice(idx + 1).find((l) => !l.is_counted_by_me && !l.is_excluded)
                || filtered.find((l) => !l.is_counted_by_me && !l.is_excluded);
            if (!next) return;
            const globalIdxInFiltered = filtered.indexOf(next);
            state.page = Math.floor(globalIdxInFiltered / PAGE_SIZE);
            renderTable();
            const row = tbody.querySelector(`tr[data-item-id="${next.item_id}"]`);
            const qtyInput = row && row.querySelector('.opname-blind-qty-input');
            if (qtyInput) qtyInput.focus();
        }

        async function saveCount(line, qtyInput, saveBtn, alertBox) {
            if (qtyInput.value === '') { UI.toast('Isi jumlah fisik terlebih dahulu.', 'error'); return; }
            saveBtn.disabled = true;
            alertBox.innerHTML = '';
            try {
                await InvApi.submitOpnameCount(state.view.session_id, state.view.role, line.item_id, Number(qtyInput.value));
                UI.toast(`Tersimpan: ${line.sku} = ${qtyInput.value}`, 'success');
                await refetch();
                focusNextPending(line.item_id);
            } catch (err) {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan.'));
                saveBtn.disabled = false;
            }
        }
        // Exposed on state so buildBlindCountRowEl (a sibling function,
        // not a closure over this one) can call back into the save flow.
        state.saveCount = saveCount;

        renderChrome();
        renderTable();
        return wrap;
    }

    // One compact <tr> per session line — status is derived ONLY from
    // is_counted_by_me/is_excluded (fields already on the blind view;
    // never a system/theoretical quantity, which this shape never
    // carries in the first place).
    function buildBlindCountRowEl(state, line, rowNo) {
        const row = UI.el('tr', { 'data-item-id': String(line.item_id) });
        row.appendChild(UI.el('td', { class: 'compact-col-no' }, String(rowNo)));
        row.appendChild(UI.el('td', { class: 'compact-col-item' }, `${line.sku} — ${line.name}`));

        if (line.is_excluded) {
            row.appendChild(UI.el('td', {}, '-'));
            row.appendChild(UI.el('td', {}, [UI.el('span', { class: 'badge badge-cancelled' }, 'Dikecualikan')]));
            row.appendChild(UI.el('td', {}, '-'));
            return row;
        }
        if (line.is_counted_by_me) {
            row.appendChild(UI.el('td', {}, UI.formatNumber(line.my_qty_base)));
            row.appendChild(UI.el('td', {}, [UI.el('span', { class: 'badge badge-received' }, 'Tersimpan')]));
            row.appendChild(UI.el('td', {}, '-'));
            return row;
        }

        const qtyInput = UI.el('input', {
            type: 'number', step: 'any', inputmode: 'decimal', placeholder: 'Qty fisik...',
            class: 'opname-blind-qty-input',
        });
        const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm compact-row-btn' }, 'Simpan');
        const alertBox = UI.el('div');
        saveBtn.addEventListener('click', () => state.saveCount(line, qtyInput, saveBtn, alertBox));
        // Fast-entry: Enter on the qty field saves immediately (never the
        // whole-transaction submit — there is no such single action on
        // this per-line-saved screen to accidentally trigger).
        qtyInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            state.saveCount(line, qtyInput, saveBtn, alertBox);
        });

        row.appendChild(UI.el('td', { class: 'compact-col-qty' }, [qtyInput, alertBox]));
        row.appendChild(UI.el('td', {}, [UI.el('span', { class: 'badge badge-pending' }, 'Belum')]));
        row.appendChild(UI.el('td', {}, [saveBtn]));
        return row;
    }

    function openScanModal(state, jumpToItem) {
        if (typeof ItemSelector === 'undefined' || !ItemSelector.resolveBarcode) {
            Modal.alert({ title: 'Scan Barcode', message: 'Fitur scan tidak tersedia.' });
            return;
        }
        const input = UI.el('input', { type: 'text', autocomplete: 'off', placeholder: 'Scan atau ketik barcode, lalu Enter...' });
        const statusLine = UI.el('div', { style: 'margin-top:8px; color:var(--text3);' }, '');
        const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Tutup');
        const overlay = UI.el('div', { class: 'modal open' });
        const content = UI.el('div', { class: 'modal-content' }, [
            UI.el('h3', {}, 'Scan Barcode'),
            input, statusLine,
            UI.el('div', { style: 'display:flex; justify-content:flex-end; margin-top:14px;' }, [cancelBtn]),
        ]);
        overlay.appendChild(content);
        document.body.appendChild(overlay);
        cancelBtn.addEventListener('click', () => overlay.remove());
        overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) overlay.remove(); });
        input.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const resolution = ItemSelector.resolveBarcode(input.value);
            if (resolution.status !== 'OK') {
                statusLine.textContent = 'Barcode tidak dikenali / barang tidak aktif.';
                return;
            }
            const found = jumpToItem(resolution.item.id);
            if (!found) {
                statusLine.textContent = 'Barang ini tidak termasuk dalam cakupan sesi opname ini.';
                return;
            }
            overlay.remove();
        });
        setTimeout(() => input.focus(), 0);
    }

    // ============================================================
    // Supervisor comparison/review
    // ============================================================
    async function buildSupervisorReviewCard(session) {
        const wrap = UI.el('div');
        let review;
        try {
            review = await InvApi.opnameReview(session.id);
        } catch (err) {
            UI.handleApiError(err);
            return UI.el('div', { class: 'alert alert-error' }, 'Gagal memuat perbandingan hasil hitung.');
        }
        const s = review.summary;
        const kpis = [
            ['Total Item', s.total_items], ['Match', s.match], ['Mismatch', s.mismatch],
            ['Recounted', s.recounted], ['Belum Dihitung', s.not_counted], ['Dikecualikan', s.excluded],
        ];
        // A legacy single-count session mixed into this same warehouse's
        // history can carry lines counted via the old (pre-dual-count)
        // flow — real data, just never compared, so it gets its own tile
        // rather than silently vanishing from the total.
        if (s.legacy_counted) kpis.push(['Hitung Lama (Single Count)', s.legacy_counted]);
        const kpiBox = UI.el('div', { class: 'grid-4', style: 'margin-bottom:12px;' });
        kpis.forEach(([label, value]) => {
            kpiBox.appendChild(UI.el('div', { class: 'kpi-card' }, [
                UI.el('div', { class: 'kpi-label' }, label),
                UI.el('div', { class: 'kpi-value' }, String(value)),
            ]));
        });
        wrap.appendChild(kpiBox);

        const rows = review.lines.map((l) => {
            const diffP1P2 = (l.p1_qty_base !== null && l.p2_qty_base !== null)
                ? UI.formatNumber(Number(l.p1_qty_base) - Number(l.p2_qty_base))
                : '-';
            const cells = [
                UI.el('td', {}, `${l.sku} — ${l.name}`),
                UI.el('td', {}, UI.formatNumber(l.system_qty_base)),
                UI.el('td', {}, l.p1_qty_base !== null ? UI.formatNumber(l.p1_qty_base) : '-'),
                UI.el('td', {}, l.p2_qty_base !== null ? UI.formatNumber(l.p2_qty_base) : '-'),
                UI.el('td', {}, diffP1P2),
                UI.el('td', {}, [UI.el('span', { class: `badge ${badgeClassFor(l.match_status, l.is_excluded)}` }, keteranganFor(l))]),
                UI.el('td', {}, l.final_physical_qty_base !== null ? UI.formatNumber(l.final_physical_qty_base) : '-'),
            ];
            const actionCell = UI.el('td', {});
            if (!l.is_excluded && l.match_status === 'MISMATCH') {
                const recountBtn = UI.el('button', { class: 'btn btn-warning btn-sm' }, 'Recount');
                recountBtn.addEventListener('click', () => recountItem(session.id, l));
                actionCell.appendChild(recountBtn);
            }
            if (!l.is_excluded && (l.match_status === 'PENDING' || l.match_status === 'MISMATCH')) {
                const excludeBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-left:6px;' }, 'Kecualikan');
                excludeBtn.addEventListener('click', () => excludeItem(session.id, l));
                actionCell.appendChild(excludeBtn);
            }
            cells.push(actionCell);
            return UI.el('tr', {}, cells);
        });

        const cancelBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Batalkan Sesi');
        cancelBtn.addEventListener('click', cancelOpname);

        wrap.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center;' }, [
                UI.el('div', { class: 'card-title' }, 'Perbandingan P1 vs P2'),
                cancelBtn,
            ]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Stok Sistem', 'Hasil P1', 'Hasil P2', 'Selisih P1-P2', 'Keterangan', 'Hasil Final/Recount', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '8' }, '-')])]),
                ]),
            ]),
        ]));

        const readyToFinalize = s.not_counted === 0 && s.mismatch === 0;
        const finalizeBtn = UI.el('button', { class: 'btn btn-danger', style: 'margin-top:12px;' },
            readyToFinalize ? '🔐 Finalisasi Sesi' : `🔐 Finalisasi Sesi (${s.not_counted + s.mismatch} item belum selesai)`);
        finalizeBtn.addEventListener('click', async () => {
            finalizeBtn.disabled = true;
            try {
                await InvApi.finalizeOpname(session.id);
                UI.toast('Sesi opname difinalisasi.', 'success');
                await renderSession(session.id);
            } catch (err) {
                UI.handleApiError(err);
                finalizeBtn.disabled = false;
            }
        });
        wrap.appendChild(finalizeBtn);
        return wrap;
    }

    /**
     * HOTFIX (post-274dc78) — exact per-state Keterangan text for the
     * DUAL_COUNT supervisor comparison table, shown from OPEN status
     * onward (even before P1/P2 are assigned or have counted anything —
     * every session line already exists from start()). match_status stays
     * PENDING both when NEITHER counter has submitted yet and when only
     * ONE has (StockOpnameService::resolveMatchStatus never distinguishes
     * those two at the DB level), so this reads p1_qty_base/p2_qty_base
     * directly to tell them apart for display only — never touches
     * server-side match resolution.
     */
    function keteranganFor(l) {
        if (l.is_excluded) return 'Dikecualikan oleh supervisor';
        switch (l.match_status) {
            case 'MATCH': return 'Sesuai (Hasil P1 = Hasil P2)';
            case 'MISMATCH': return 'Selisih P1 ≠ P2 — perlu recount';
            case 'RECOUNTED': return 'Sudah direcount oleh supervisor';
            case 'EXCLUDED': return 'Dikecualikan oleh supervisor';
            default:
                if (l.p1_qty_base === null && l.p2_qty_base === null) return 'Belum dihitung';
                if (l.p1_qty_base === null) return 'Menunggu hasil P1';
                if (l.p2_qty_base === null) return 'Menunggu hasil P2';
                return 'Belum dihitung';
        }
    }

    function badgeClassFor(status, isExcluded) {
        if (isExcluded || status === 'EXCLUDED') return 'badge-cancelled';
        if (status === 'MATCH' || status === 'RECOUNTED') return 'badge-pass';
        if (status === 'MISMATCH') return 'badge-void';
        return 'badge-warning';
    }

    async function recountItem(sessionId, line) {
        const values = await MasterCommon.formModal({
            title: `Recount — ${line.sku}`,
            submitLabel: 'Simpan Recount',
            initial: { counted_qty_base: '', reason: '' },
            fields: [
                { key: 'counted_qty_base', label: 'Qty Hasil Recount', type: 'text', required: true },
                { key: 'reason', label: 'Alasan Recount', type: 'text', required: true },
            ],
        });
        if (!values) return;
        try {
            await InvApi.recountOpname(sessionId, line.item_id, Number(values.counted_qty_base), values.reason);
            UI.toast('Recount tersimpan.', 'success');
            await renderSession(sessionId);
        } catch (err) { UI.handleApiError(err); }
    }

    async function excludeItem(sessionId, line) {
        const reason = await Modal.prompt({ title: `Kecualikan — ${line.sku}`, label: 'Alasan pengecualian (wajib)', required: true });
        if (reason === null) return;
        try {
            await InvApi.excludeOpnameItem(sessionId, line.item_id, reason);
            UI.toast('Barang dikecualikan dari sesi ini.', 'success');
            await renderSession(sessionId);
        } catch (err) { UI.handleApiError(err); }
    }

    // ============================================================
    // Legacy single-count form (unchanged behavior)
    // ============================================================
    function buildCountForm(session) {
        const rows = session.lines.map((line) => UI.el('tr', {}, [
            UI.el('td', {}, `${line.sku} — ${line.name}`),
            UI.el('td', {}, UI.formatNumber(line.system_qty_base)),
            UI.el('td', {}, UI.el('input', { type: 'number', class: 'opname-count-input', 'data-item-id': String(line.item_id), step: 'any', value: line.counted_qty_base ?? '' })),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, 'Hitung Fisik (Single Count)'),
            UI.el('div', { id: 'opname-count-alert' }),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Stok Sistem', 'Hasil Hitung Fisik'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows),
                ]),
            ]),
            UI.el('div', { style: 'margin-top:12px; display:flex; gap:8px;' }, [
                UI.el('button', { class: 'btn btn-secondary', id: 'opname-save-count-btn' }, 'Simpan Hitungan'),
                UI.el('button', { class: 'btn btn-warning', id: 'opname-finalize-btn' }, 'Finalisasi (Hitung Selisih)'),
            ]),
        ]);
    }

    function buildFinalizedView(session) {
        const rows = session.lines.filter((l) => Number(l.variance_qty_base) !== 0).map((line) => UI.el('tr', {}, [
            UI.el('td', {}, `${line.sku} — ${line.name}`),
            UI.el('td', {}, UI.formatNumber(line.system_qty_base)),
            UI.el('td', {}, UI.formatNumber(line.counted_qty_base)),
            UI.el('td', { class: 'mono' }, UI.formatNumber(line.variance_qty_base)),
            UI.el('td', {}, Number(line.cost_required) === 1
                ? UI.el('input', { type: 'number', class: 'opname-cost-override', 'data-item-id': String(line.item_id), step: 'any', placeholder: 'wajib isi harga' })
                : '-'),
        ]));
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, 'Review Selisih (Variance)'),
            UI.el('div', { id: 'opname-post-alert' }),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Stok Sistem', 'Hasil Hitung', 'Selisih', 'Harga (jika wajib)'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Tidak ada selisih — stok sesuai')])]),
                ]),
            ]),
        ]);
        const actions = UI.el('div', { style: 'margin-top:12px; display:flex; gap:8px;' });
        if (canSupervise()) {
            actions.appendChild(UI.el('button', { class: 'btn btn-danger', id: 'opname-post-btn' }, '🔐 Post Hasil Opname'));
            actions.appendChild(UI.el('button', { class: 'btn btn-secondary', id: 'opname-cancel-btn' }, 'Batalkan Sesi'));
        } else {
            actions.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, 'Hanya supervisor yang dapat memposting atau membatalkan sesi ini.'));
        }
        card.appendChild(actions);
        return card;
    }

    async function saveCounts() {
        const btn = document.getElementById('opname-save-count-btn');
        const alertBox = document.getElementById('opname-count-alert');
        alertBox.innerHTML = '';
        const counts = [];
        document.querySelectorAll('.opname-count-input').forEach((input) => {
            if (input.value !== '') {
                counts.push({ item_id: Number(input.dataset.itemId), counted_qty_base: Number(input.value) });
            }
        });
        if (!counts.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Isi minimal satu hasil hitung fisik.'));
            return;
        }
        btn.disabled = true;
        try {
            await InvApi.countOpname(currentSessionId, counts);
            UI.toast('Hitungan tersimpan.', 'success');
            await renderSession(currentSessionId);
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan hitungan.'));
        } finally {
            btn.disabled = false;
        }
    }

    async function finalizeOpname() {
        const btn = document.getElementById('opname-finalize-btn');
        const alertBox = document.getElementById('opname-count-alert');
        alertBox.innerHTML = '';
        btn.disabled = true;
        try {
            await InvApi.finalizeOpname(currentSessionId);
            UI.toast('Opname difinalisasi.', 'success');
            await renderSession(currentSessionId);
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal finalisasi — pastikan semua barang sudah dihitung.'));
        } finally {
            btn.disabled = false;
        }
    }

    async function postOpname() {
        const btn = document.getElementById('opname-post-btn');
        const alertBox = document.getElementById('opname-post-alert');
        alertBox.innerHTML = '';
        const overrides = {};
        document.querySelectorAll('.opname-cost-override').forEach((input) => {
            if (input.value !== '') overrides[input.dataset.itemId] = Number(input.value);
        });
        btn.disabled = true;
        try {
            await InvApi.postOpname(currentSessionId, overrides);
            UI.toast('Opname berhasil diposting — stok gudang sudah disesuaikan.', 'success');
            await renderSession(currentSessionId);
        } catch (err) {
            if (err && err.code === 'COST_REQUIRED') {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, `${err.message} — isi kolom harga untuk barang tersebut sebelum posting.`));
            } else {
                UI.handleApiError(err);
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal posting opname.'));
            }
        } finally {
            btn.disabled = false;
        }
    }

    async function cancelOpname() {
        const reason = await Modal.prompt({ title: 'Batalkan Sesi Opname', label: 'Alasan pembatalan (wajib)', required: true });
        if (reason === null) return;
        try {
            await InvApi.cancelOpname(currentSessionId, reason);
            UI.toast('Sesi opname dibatalkan.', 'success');
            await loadForWarehouse();
        } catch (err) { UI.handleApiError(err); }
    }

    return { render };
})();
