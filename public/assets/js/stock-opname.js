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
                body.appendChild(await buildAssignCountersCard(session));
                if (!session.p1_user_id || !session.p2_user_id) {
                    body.appendChild(buildCountForm(session));
                    setTimeout(() => {
                        const saveBtn = document.getElementById('opname-save-count-btn');
                        const finBtn = document.getElementById('opname-finalize-btn');
                        if (saveBtn) saveBtn.addEventListener('click', saveCounts);
                        if (finBtn) finBtn.addEventListener('click', finalizeOpname);
                    }, 0);
                } else if (canSupervise()) {
                    body.appendChild(await buildSupervisorReviewCard(session));
                } else {
                    body.appendChild(UI.el('div', { class: 'card' }, [
                        UI.el('div', { class: 'alert alert-info' }, 'P1 dan P2 sedang menghitung fisik. Hubungi supervisor untuk melihat perbandingan hasil hitung.'),
                    ]));
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
    // Blind counting screen (P1 or P2) — mobile-friendly
    // ============================================================
    function buildBlindCountScreen(view) {
        const wrap = UI.el('div');
        wrap.appendChild(UI.el('div', { class: 'banner-lock' },
            `🔒 STOCK OPNAME — Sesi ${view.session_number || view.session_id} — Anda login sebagai ${view.role.toUpperCase()} (Hitung Fisik Independen/Blind).`));

        const progressPct = view.progress.total > 0 ? Math.round((view.progress.counted / view.progress.total) * 100) : 0;
        wrap.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, `Progress ${view.role.toUpperCase()}: ${view.progress.counted} / ${view.progress.total}`),
            UI.el('div', { style: `height:10px; background:var(--border); border-radius:6px; overflow:hidden; margin-top:6px;` }, [
                UI.el('div', { style: `height:100%; width:${progressPct}%; background:var(--primary, #2563eb);` }),
            ]),
        ]));

        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU/nama, atau scan barcode...', class: 'opname-blind-search' });
        const scanBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '📷 Scan');
        wrap.appendChild(UI.el('div', { class: 'card', style: 'display:flex; gap:8px; align-items:center;' }, [searchInput, scanBtn]));

        const listHost = UI.el('div', { id: 'opname-blind-list' });
        wrap.appendChild(listHost);

        function renderList(filter) {
            listHost.innerHTML = '';
            const q = String(filter || '').trim().toLowerCase();
            const lines = view.lines.filter((l) => !q || l.sku.toLowerCase().includes(q) || l.name.toLowerCase().includes(q));
            lines.forEach((line) => listHost.appendChild(buildBlindCountRow(view, line)));
            if (lines.length === 0) {
                listHost.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Tidak ada barang yang cocok.'));
            }
        }
        searchInput.addEventListener('input', () => renderList(searchInput.value));
        scanBtn.addEventListener('click', () => openScanModal(view));
        renderList('');

        return wrap;
    }

    function buildBlindCountRow(view, line) {
        const isCounted = line.is_counted_by_me || line.is_excluded;
        const card = UI.el('div', { class: 'card', id: `opname-line-${line.item_id}`, style: 'padding:12px 16px;' });
        card.appendChild(UI.el('div', { style: 'font-weight:700; font-size:1.05rem;' }, `${line.sku} — ${line.name}`));

        if (line.is_excluded) {
            card.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:8px;' }, '⏭️ Dikecualikan oleh supervisor.'));
            return card;
        }
        if (isCounted) {
            card.appendChild(UI.el('div', { class: 'alert alert-success', style: 'margin-top:8px;' }, `✅ Sudah dihitung: ${UI.formatNumber(line.my_qty_base)}`));
            return card;
        }

        const qtyInput = UI.el('input', {
            type: 'number', step: 'any', inputmode: 'decimal', placeholder: 'Qty fisik...',
            class: 'opname-blind-qty-input', style: 'font-size:1.4rem; padding:12px; width:100%; margin-top:8px;',
        });
        const saveBtn = UI.el('button', { class: 'btn btn-primary', style: 'width:100%; margin-top:8px; padding:12px; font-size:1.05rem;' }, 'Simpan Hitungan');
        const alertBox = UI.el('div');
        saveBtn.addEventListener('click', async () => {
            if (qtyInput.value === '') { UI.toast('Isi jumlah fisik terlebih dahulu.', 'error'); return; }
            saveBtn.disabled = true;
            alertBox.innerHTML = '';
            try {
                await InvApi.submitOpnameCount(view.session_id, view.role, line.item_id, Number(qtyInput.value));
                UI.toast(`Tersimpan: ${line.sku} = ${qtyInput.value}`, 'success');
                await renderSession(view.session_id);
            } catch (err) {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan.'));
                saveBtn.disabled = false;
            }
        });
        card.appendChild(qtyInput);
        card.appendChild(saveBtn);
        card.appendChild(alertBox);
        return card;
    }

    function openScanModal(view) {
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
            const line = view.lines.find((l) => l.item_id === resolution.item.id);
            if (!line) {
                statusLine.textContent = 'Barang ini tidak termasuk dalam cakupan sesi opname ini.';
                return;
            }
            overlay.remove();
            const row = document.getElementById(`opname-line-${line.item_id}`);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const qtyInput = row.querySelector('.opname-blind-qty-input');
                if (qtyInput) qtyInput.focus();
            }
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
            const cells = [
                UI.el('td', {}, `${l.sku} — ${l.name}`),
                UI.el('td', {}, UI.formatNumber(l.system_qty_base)),
                UI.el('td', {}, l.p1_qty_base !== null ? UI.formatNumber(l.p1_qty_base) : '-'),
                UI.el('td', {}, l.p2_qty_base !== null ? UI.formatNumber(l.p2_qty_base) : '-'),
                UI.el('td', {}, [UI.el('span', { class: `badge ${badgeClassFor(l.match_status)}` }, l.match_status)]),
                UI.el('td', {}, l.final_physical_qty_base !== null ? UI.formatNumber(l.final_physical_qty_base) : '-'),
            ];
            const actionCell = UI.el('td', {});
            if (l.match_status === 'MISMATCH') {
                const recountBtn = UI.el('button', { class: 'btn btn-warning btn-sm' }, 'Recount');
                recountBtn.addEventListener('click', () => recountItem(session.id, l));
                actionCell.appendChild(recountBtn);
            }
            if (l.match_status === 'PENDING' || l.match_status === 'MISMATCH') {
                const excludeBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-left:6px;' }, 'Kecualikan');
                excludeBtn.addEventListener('click', () => excludeItem(session.id, l));
                actionCell.appendChild(excludeBtn);
            }
            cells.push(actionCell);
            return UI.el('tr', {}, cells);
        });

        wrap.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, 'Perbandingan P1 vs P2'),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Sistem', 'P1', 'P2', 'Status', 'Final', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '7' }, '-')])]),
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

    function badgeClassFor(status) {
        if (status === 'MATCH' || status === 'RECOUNTED') return 'badge-pass';
        if (status === 'MISMATCH') return 'badge-void';
        if (status === 'EXCLUDED') return 'badge-cancelled';
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
