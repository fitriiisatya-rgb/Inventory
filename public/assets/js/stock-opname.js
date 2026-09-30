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
        // PHASE V2.14.11.3 — "Petugas Stock Opname" account management is
        // warehouse-independent (it manages LOGIN identities, not a
        // session), so it renders once here, above the per-warehouse
        // session card, and only for SUPERADMIN (also enforced
        // server-side on every counter-accounts route — this is cosmetic
        // convenience only). Deliberately a SEPARATE card from "Penugasan
        // Tim P1 & Tim P2" (buildAssignCountersCard): create the account
        // here first, assign it to a session's team there second — never
        // mixed into one form.
        if (Auth.hasRole('SUPERADMIN')) {
            container.appendChild(buildCounterAccountsCard());
        }
        const whOptions = Master.warehouses().map((w) => `<option value="${w.id}">${w.name}</option>`).join('');
        container.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📋 Stock Opname')]),
            UI.el('div', { class: 'form-group', html: `<label>Gudang</label><select id="opname-wh">${whOptions}</select>` }),
        ]));
        container.appendChild(UI.el('div', { id: 'opname-body' }));

        document.getElementById('opname-wh').addEventListener('change', loadForWarehouse);
        if (Master.warehouses().length) loadForWarehouse();
    }

    // PHASE V2.14.11.3 — URGENT HOTFIX: independent login accounts for
    // physical Stock Opname counters ("Petugas Stock Opname"). Account
    // creation/reset-password/activate/deactivate only — never touches
    // stock_opname_team_members (P1/P2 session assignment stays in
    // buildAssignCountersCard above). Role is never client-selectable —
    // the server always assigns OPNAME_COUNTER; no role/warehouse/
    // division/permission dropdown is ever rendered here.
    function buildCounterAccountsCard() {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🪪 Petugas Stock Opname')]),
        ]);
        const addBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '+ Tambah Petugas');
        const formHost = UI.el('div', { style: 'display:none; margin-top:10px;' });
        const alertBox = UI.el('div');
        const listHost = UI.el('div', { style: 'margin-top:10px;' });
        card.appendChild(UI.el('div', {}, [addBtn]));
        card.appendChild(formHost);
        card.appendChild(alertBox);
        card.appendChild(listHost);

        async function refresh() {
            listHost.innerHTML = '<div class="alert alert-info">Memuat daftar petugas...</div>';
            try {
                renderTable(await InvApi.listOpnameCounterAccounts());
            } catch (err) {
                UI.handleApiError(err);
                listHost.innerHTML = `<div class="alert alert-error">Gagal memuat daftar petugas: ${(err && err.message) || ''}</div>`;
            }
        }

        function renderTable(rows) {
            listHost.innerHTML = '';
            if (!rows.length) {
                listHost.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Belum ada Petugas Stock Opname. Klik "+ Tambah Petugas" untuk membuat akun baru.'));
                return;
            }
            const table = UI.el('table', { class: 'table' });
            table.appendChild(UI.el('thead', {}, [UI.el('tr', {}, ['Nama', 'Username', 'Status', 'Login Terakhir', 'Dibuat', 'Aksi'].map((h) => UI.el('th', {}, h)))]));
            const tbody = UI.el('tbody');
            rows.forEach((r) => {
                const statusBadge = UI.el('span', { class: `badge ${r.is_active ? 'badge-received' : 'badge-cancelled'}` }, r.is_active ? 'Aktif' : 'Nonaktif');
                const resetBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Reset Password');
                const toggleBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, r.is_active ? 'Nonaktifkan' : 'Aktifkan');
                resetBtn.addEventListener('click', () => showResetPasswordForm(r));
                toggleBtn.addEventListener('click', async () => {
                    alertBox.innerHTML = '';
                    try {
                        if (r.is_active) await InvApi.deactivateOpnameCounterAccount(r.id);
                        else await InvApi.activateOpnameCounterAccount(r.id);
                        UI.toast(`Petugas ${r.full_name} berhasil ${r.is_active ? 'dinonaktifkan' : 'diaktifkan'}.`, 'success');
                        await refresh();
                    } catch (err) {
                        alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal mengubah status petugas.'));
                    }
                });
                tbody.appendChild(UI.el('tr', {}, [
                    UI.el('td', {}, r.full_name),
                    UI.el('td', {}, r.username),
                    UI.el('td', {}, [statusBadge]),
                    UI.el('td', {}, r.last_login_at ? UI.formatDate(r.last_login_at) : '-'),
                    UI.el('td', {}, r.created_at ? UI.formatDate(r.created_at) : '-'),
                    UI.el('td', { style: 'display:flex; gap:6px; flex-wrap:wrap;' }, [resetBtn, toggleBtn]),
                ]));
            });
            table.appendChild(tbody);
            listHost.appendChild(table);
        }

        function showCreateForm() {
            formHost.innerHTML = '';
            formHost.style.display = 'block';
            const nameInput = UI.el('input', { type: 'text', placeholder: 'Nama Lengkap', autocomplete: 'off' });
            const usernameInput = UI.el('input', { type: 'text', placeholder: 'Username', autocomplete: 'off' });
            const passwordInput = UI.el('input', { type: 'password', placeholder: 'Password (min. 8 karakter)', autocomplete: 'new-password' });
            const confirmInput = UI.el('input', { type: 'password', placeholder: 'Konfirmasi Password', autocomplete: 'new-password' });
            const activeCheckbox = UI.el('input', { type: 'checkbox', checked: true });
            const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Simpan Petugas');
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Batal');
            const formAlert = UI.el('div');

            saveBtn.addEventListener('click', async () => {
                formAlert.innerHTML = '';
                if (passwordInput.value !== confirmInput.value) {
                    formAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Password dan konfirmasi tidak sama.'));
                    return;
                }
                saveBtn.disabled = true;
                try {
                    await InvApi.createOpnameCounterAccount(nameInput.value, usernameInput.value, passwordInput.value, activeCheckbox.checked);
                    UI.toast('Petugas Stock Opname berhasil dibuat.', 'success');
                    formHost.style.display = 'none';
                    formHost.innerHTML = '';
                    await refresh();
                } catch (err) {
                    formAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal membuat akun petugas.'));
                } finally {
                    saveBtn.disabled = false;
                }
            });
            cancelBtn.addEventListener('click', () => { formHost.style.display = 'none'; formHost.innerHTML = ''; });

            formHost.appendChild(UI.el('div', { class: 'form-group', style: 'border:1px solid var(--border); border-radius:8px; padding:10px;' }, [
                UI.el('label', {}, 'Nama Lengkap *'), nameInput,
                UI.el('label', { style: 'margin-top:8px; display:block;' }, 'Username *'), usernameInput,
                UI.el('label', { style: 'margin-top:8px; display:block;' }, 'Password *'), passwordInput,
                UI.el('label', { style: 'margin-top:8px; display:block;' }, 'Konfirmasi Password *'), confirmInput,
                UI.el('label', { style: 'margin-top:8px; display:flex; align-items:center; gap:6px;' }, [activeCheckbox, document.createTextNode('Status Aktif')]),
                formAlert,
                UI.el('div', { style: 'margin-top:8px; display:flex; gap:8px;' }, [saveBtn, cancelBtn]),
            ]));
        }

        function showResetPasswordForm(row) {
            formHost.innerHTML = '';
            formHost.style.display = 'block';
            const passwordInput = UI.el('input', { type: 'password', placeholder: 'Password Baru (min. 8 karakter)', autocomplete: 'new-password' });
            const confirmInput = UI.el('input', { type: 'password', placeholder: 'Konfirmasi Password', autocomplete: 'new-password' });
            const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Simpan Password Baru');
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Batal');
            const formAlert = UI.el('div');

            saveBtn.addEventListener('click', async () => {
                formAlert.innerHTML = '';
                if (passwordInput.value !== confirmInput.value) {
                    formAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Password dan konfirmasi tidak sama.'));
                    return;
                }
                saveBtn.disabled = true;
                try {
                    await InvApi.resetOpnameCounterPassword(row.id, passwordInput.value);
                    UI.toast(`Password ${row.full_name} berhasil direset.`, 'success');
                    formHost.style.display = 'none';
                    formHost.innerHTML = '';
                } catch (err) {
                    formAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal reset password.'));
                } finally {
                    saveBtn.disabled = false;
                }
            });
            cancelBtn.addEventListener('click', () => { formHost.style.display = 'none'; formHost.innerHTML = ''; });

            formHost.appendChild(UI.el('div', { class: 'form-group', style: 'border:1px solid var(--border); border-radius:8px; padding:10px;' }, [
                UI.el('label', {}, `Reset Password — ${row.full_name} (${row.username})`),
                UI.el('label', { style: 'margin-top:8px; display:block;' }, 'Password Baru *'), passwordInput,
                UI.el('label', { style: 'margin-top:8px; display:block;' }, 'Konfirmasi Password *'), confirmInput,
                formAlert,
                UI.el('div', { style: 'margin-top:8px; display:flex; gap:8px;' }, [saveBtn, cancelBtn]),
            ]));
        }

        addBtn.addEventListener('click', showCreateForm);
        refresh();
        return card;
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
                    // PHASE V2.14.10.1 Gate 2 — a LEGACY_DUAL_COUNT session
                    // is NEVER auto-converted; a supervisor must explicitly
                    // upgrade it, and only while it still has zero
                    // submitted P1/P2 counts (server-enforced — this button
                    // is shown unconditionally whenever the mode is
                    // eligible and the server is the final word).
                    if (canSupervise() && (session.counting_model || 'LEGACY_DUAL_COUNT') === 'LEGACY_DUAL_COUNT') {
                        const upgradeCard = UI.el('div', { class: 'card' }, [
                            UI.el('div', { class: 'card-title' }, 'Mode Hitung: Legacy (Single Count per Role)'),
                            UI.el('div', { style: 'color:var(--text3); font-size:0.85rem; margin-bottom:8px;' },
                                'Upgrade ke Mode Tim/Findings mengizinkan banyak anggota per tim, satuan dinamis dari master barang, dan Tambah Temuan berulang. Hanya bisa dilakukan selama BELUM ADA hasil hitung P1/P2 yang tersimpan.'),
                        ]);
                        const upgradeBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Upgrade ke Mode Tim/Findings');
                        upgradeBtn.addEventListener('click', async () => {
                            try {
                                await InvApi.upgradeOpnameToFindings(session.id);
                                UI.toast('Sesi berhasil di-upgrade ke Mode Tim/Findings.', 'success');
                                await renderSession(session.id);
                            } catch (err) { UI.handleApiError(err); }
                        });
                        upgradeCard.appendChild(upgradeBtn);
                        body.appendChild(upgradeCard);
                    }
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
    // PHASE V2.14.10 — TEAM assignment: P1/P2 are now teams of one or
    // more users (any active user — see StockOpnameService::
    // assertCanCount()'s corrected eligibility rule), replacing the old
    // single-select dropdown with a searchable multi-select + chip list
    // per role. Backed by assignOpnameTeam() (full-membership
    // reconciliation — see StockOpnameService::assignTeamMembers()).
    // ============================================================
    async function buildAssignCountersCard(session) {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, '👥 Penugasan Tim P1 & Tim P2'),
        ]);
        let eligible = [];
        let currentTeams = { p1: [], p2: [] };
        try {
            eligible = await InvApi.opnameEligibleCounters(session.warehouse_id);
        } catch (err) { UI.handleApiError(err); }
        try {
            const review = await InvApi.opnameReview(session.id);
            currentTeams.p1 = (review.team_progress && review.team_progress.p1 && review.team_progress.p1.members) || [];
            currentTeams.p2 = (review.team_progress && review.team_progress.p2 && review.team_progress.p2.members) || [];
        } catch (err) { /* team assignment still usable even if review() is briefly unavailable */ }

        const alertBox = UI.el('div');
        const teamsHost = UI.el('div', { class: 'grid-2' });
        card.appendChild(teamsHost);
        card.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem; margin-top:4px;' },
            'ANY user aktif dapat ditugaskan — kewenangan menghitung berasal dari penugasan sesi ini, bukan dari peran global akun. Satu orang tidak bisa berada di Tim P1 dan Tim P2 sekaligus.'));
        card.appendChild(alertBox);

        function buildTeamEditor(role, label) {
            const selected = new Set((currentTeams[role] || []).map((m) => String(m.user_id)));
            const chipHost = UI.el('div', { style: 'display:flex; flex-wrap:wrap; gap:6px; margin:8px 0;' });
            const addSelect = UI.el('select', { html: '<option value="">+ Tambah Petugas...</option>' });

            function usernameOf(userId) {
                const u = eligible.find((e) => String(e.id) === String(userId));
                if (u) return u.username;
                const cur = (currentTeams[role] || []).find((m) => String(m.user_id) === String(userId));
                return cur ? cur.username : `user #${userId}`;
            }

            function renderChips() {
                chipHost.innerHTML = '';
                selected.forEach((uid) => {
                    const chip = UI.el('span', { class: 'badge badge-received', style: 'display:inline-flex; align-items:center; gap:6px;' }, [
                        document.createTextNode(usernameOf(uid)),
                        UI.el('button', { type: 'button', style: 'border:none; background:none; color:inherit; cursor:pointer; font-weight:700; padding:0;' }, '×'),
                    ]);
                    chip.lastChild.addEventListener('click', () => { selected.delete(uid); renderChips(); renderOptions(); });
                    chipHost.appendChild(chip);
                });
            }
            function renderOptions() {
                const opts = eligible.filter((u) => !selected.has(String(u.id)));
                addSelect.innerHTML = '<option value="">+ Tambah Petugas...</option>' + opts.map((u) =>
                    `<option value="${u.id}">${u.username} (${u.role_code}${u.same_warehouse ? '' : ' — gudang lain'})</option>`).join('');
            }
            addSelect.addEventListener('change', () => {
                if (addSelect.value) { selected.add(addSelect.value); renderChips(); renderOptions(); addSelect.value = ''; }
            });
            renderChips();
            renderOptions();

            const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, `Simpan Tim ${label}`);
            saveBtn.addEventListener('click', async () => {
                alertBox.innerHTML = '';
                try {
                    await InvApi.assignOpnameTeam(session.id, role, Array.from(selected).map(Number));
                    UI.toast(`Tim ${label} berhasil disimpan.`, 'success');
                    await renderSession(session.id);
                } catch (err) {
                    alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || `Gagal menyimpan Tim ${label}.`));
                }
            });

            return UI.el('div', { class: 'form-group', style: 'border:1px solid var(--border); border-radius:8px; padding:10px;' }, [
                UI.el('label', {}, `Tim ${label} (${selected.size} petugas)`),
                chipHost, addSelect,
                UI.el('div', { style: 'margin-top:8px;' }, [saveBtn]),
            ]);
        }

        teamsHost.appendChild(buildTeamEditor('p1', 'P1'));
        teamsHost.appendChild(buildTeamEditor('p2', 'P2'));
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
    // PHASE V2.14.9 — item Master-status ("Aktif"/"Tidak Aktif" on the
    // ITEM, never opname/session/counted status) and barcode, joined
    // client-side from the already-loaded Master cache (Master.itemById);
    // category_id/item_status themselves come straight from
    // getForCounter()'s own response (added in this phase) so no extra
    // per-row API call is ever made, at any session size.
    function itemBarcodeOf(itemId) {
        const item = Master.itemById(itemId);
        if (item && item.barcode) return String(item.barcode).toLowerCase();
        const extra = (Master.itemBarcodes() || []).find((b) => Number(b.item_id) === Number(itemId));
        return extra ? String(extra.barcode || '').toLowerCase() : '';
    }

    function categoryNameOf(categoryId) {
        if (categoryId === null || categoryId === undefined) return '-';
        const cat = Master.categoryById(categoryId);
        return cat ? cat.name : '-';
    }

    function categoryFilterOptionsHtml() {
        const cats = (Master.categories() || []).slice().sort((a, b) => a.name.localeCompare(b.name));
        return '<option value="ALL">Semua Kategori</option>' + cats.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
    }

    // PHASE V2.14.11 — one finding's full condition-typed breakdown
    // (GOOD/DAMAGED/EXPIRED/DEADSTOCK, each its own multi-unit raw input),
    // shared by the counter's own "Riwayat Temuan" panel and the
    // supervisor's both-teams drilldown. `baseUnitCode` may be null (the
    // supervisor drilldown does not carry the item's base unit code in
    // its response) — the per-condition unit breakdown text is always
    // self-describing (each row already carries its own unit code) so
    // this degrades gracefully either way.
    function buildFindingSummaryEl(f, idx, baseUnitCode) {
        const goodBreakdown = (f.quantities.GOOD || []).map((u) => `${UI.formatNumber(u.input_qty)} ${u.unit_code}`).join(' + ') || '0';
        const goodSuffix = baseUnitCode ? ` ${baseUnitCode}` : '';
        const lines = [UI.el('div', {}, `Temuan ${idx + 1}: GOOD ${goodBreakdown} = ${UI.formatNumber(f.good_qty)}${goodSuffix}`)];
        [['DAMAGED', 'Rusak', f.damaged_qty], ['EXPIRED', 'Expired', f.expired_qty], ['DEADSTOCK', 'Deadstock', f.deadstock_qty]].forEach(([key, label, qty]) => {
            const photos = f.photos && f.photos[key] ? f.photos[key] : [];
            if (Number(qty) === 0 && photos.length === 0) return;
            const unitBreakdown = (f.quantities[key] || []).map((u) => `${UI.formatNumber(u.input_qty)} ${u.unit_code}`).join(' + ');
            const row = UI.el('div', { style: 'font-size:0.85rem; color:var(--text2);' }, `${label}: ${unitBreakdown} (${photos.length} foto)`);
            lines.push(row);
        });
        if (f.notes) lines.push(UI.el('div', { style: 'font-size:0.8rem; color:var(--text3); font-style:italic;' }, f.notes));
        return UI.el('div', {}, lines);
    }

    // PHASE V2.14.10 — REWRITTEN for append-only multi-unit findings.
    // Two-pane layout (list left, count panel right — collapses to a
    // single stacked column on phones via .opname-counter-split's own
    // media query): the list is now read-only status display (search/
    // filter/pagination unchanged from V2.14.9), the count panel is
    // rendered ONCE for whichever single item is currently claimed/
    // selected — never one input row per SKU in the table itself, so
    // 1000+ rows still render as a plain compact list.
    function buildBlindCountScreen(initialView) {
        const PAGE_SIZE = 50;
        const state = {
            view: initialView, filterText: '', filterStatus: 'ALL', filterItemStatus: 'ALL', filterCategory: 'ALL', page: 0,
            activeItemId: null, unitsCache: {},
            // PHASE V2.14.10.1 Gate 4 — the claim_token from the most
            // recent claimOpnameItem() call for the active item; required
            // by submitOpnameFinding(). Gate 6 — panelMode is 'form' (a
            // blank entry the counter is filling in) or 'summary' (history
            // + aggregate shown after a save, offering only a non-writing
            // "+Tambah Temuan" button).
            activeClaimToken: null, panelMode: 'form',
            // PHASE V2.14.11.5 — autocomplete dropdown state: the current
            // (max 10) suggestion set for whatever is typed in searchInput,
            // and which one (if any) arrow-key navigation has highlighted.
            suggestItems: [], suggestIndex: -1,
        };
        let suggestDebounceTimer = null;

        const wrap = UI.el('div', { class: 'opname-counter-screen' });
        const bannerHost = UI.el('div');
        const progressHost = UI.el('div', { class: 'card' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU / nama barang, atau scan...', class: 'opname-blind-search' });
        const scanBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '📷 Scan');
        const nextItemBtn = UI.el('button', { type: 'button', class: 'btn btn-primary opname-next-item-btn' }, '➡️ Ambil Item Berikutnya');
        const filterSelect = UI.el('select', { id: 'opname-blind-filter', html: `
            <option value="ALL">Semua</option>
            <option value="PENDING">Belum Dihitung</option>
            <option value="COUNTED">Sudah Ada Temuan</option>
        ` });
        const itemStatusSelect = UI.el('select', { id: 'opname-blind-item-status', html: `
            <option value="ALL">Semua Status Item</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Tidak Aktif</option>
        ` });
        const categorySelect = UI.el('select', { id: 'opname-blind-category', html: categoryFilterOptionsHtml() });

        // Mobile-only segmented control mirroring itemStatusSelect exactly
        // (the select stays the single source of truth — these buttons
        // only set its value and re-dispatch its own change event). CSS
        // shows exactly one of the two per viewport width, never both.
        const itemStatusSegLabels = { ALL: 'Semua', ACTIVE: 'Aktif', INACTIVE: 'Tidak Aktif' };
        const itemStatusSegButtons = Object.keys(itemStatusSegLabels).map((key) => UI.el('button', {
            type: 'button', class: 'btn btn-secondary btn-sm opname-status-seg-btn', 'data-value': key,
        }, itemStatusSegLabels[key]));
        function syncItemStatusSeg() {
            itemStatusSegButtons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-value') === itemStatusSelect.value));
        }
        itemStatusSegButtons.forEach((btn) => btn.addEventListener('click', () => {
            itemStatusSelect.value = btn.getAttribute('data-value');
            itemStatusSelect.dispatchEvent(new Event('change'));
        }));
        const itemStatusSegHost = UI.el('div', { class: 'opname-status-segmented' }, itemStatusSegButtons);

        const tbody = UI.el('tbody', {});
        const cardListHost = UI.el('div', { class: 'opname-mobile-cards' });
        const pagerHost = UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center; margin-top:10px; flex-wrap:wrap; gap:8px;' });

        // PHASE V2.14.11.5 — autocomplete/typeahead dropdown attached to
        // searchInput. Suggestions are computed from state.view.lines only
        // (already-loaded, blind-safe data — see filteredLines()'s own
        // blindness note above) — never a new endpoint, never system/
        // opponent quantities.
        const suggestHost = UI.el('div', { class: 'opname-suggest-list' });
        const searchWrap = UI.el('div', { class: 'opname-search-wrap' }, [searchInput, suggestHost]);

        // Requirement 9/11 — the full browse list (table/cards + its
        // status/category/progress filters) is SECONDARY on mobile,
        // collapsed by default behind two independent toggles: the list
        // itself, and — nested one level further — the filter controls.
        const browseToggleBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary opname-browse-toggle' }, '▸ Lihat Daftar Barang');
        const filterToggleBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm opname-filter-toggle' }, '▸ Filter');
        const filterSectionHost = UI.el('div', { class: 'opname-filter-section', style: 'display:none;' }, [
            UI.el('div', { class: 'opname-toolbar-item-status' }, [itemStatusSelect, itemStatusSegHost]),
            UI.el('div', { class: 'opname-toolbar-category' }, [categorySelect]),
            UI.el('div', { class: 'opname-toolbar-progress-filter' }, [filterSelect]),
        ]);
        const browseSectionHost = UI.el('div', { class: 'opname-browse-section', style: 'display:none;' }, [
            filterToggleBtn,
            filterSectionHost,
            UI.el('div', { class: 'compact-table-wrap' }, [
                UI.el('table', { class: 'compact-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['No', 'SKU / Barang', 'Kategori', 'Status', 'Terakhir Diinput', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    tbody,
                ]),
            ]),
            cardListHost,
            pagerHost,
        ]);
        filterToggleBtn.addEventListener('click', () => {
            const opening = filterSectionHost.style.display === 'none';
            filterSectionHost.style.display = opening ? 'block' : 'none';
            filterToggleBtn.textContent = opening ? '▾ Filter' : '▸ Filter';
        });
        browseToggleBtn.addEventListener('click', () => {
            const opening = browseSectionHost.style.display === 'none';
            browseSectionHost.style.display = opening ? 'block' : 'none';
            browseToggleBtn.textContent = opening ? '▾ Lihat Daftar Barang' : '▸ Lihat Daftar Barang';
        });

        const listHost = UI.el('div', { class: 'opname-counter-list' }, [
            UI.el('div', { class: 'card opname-counter-toolbar' }, [
                searchWrap,
                UI.el('div', { class: 'opname-toolbar-next' }, [nextItemBtn]),
                UI.el('div', { class: 'opname-toolbar-scan' }, [scanBtn]),
                browseToggleBtn,
            ]),
            browseSectionHost,
        ]);
        const panelHost = UI.el('div', { class: 'opname-counter-panel card' });

        wrap.appendChild(bannerHost);
        wrap.appendChild(progressHost);
        wrap.appendChild(UI.el('div', { class: 'opname-counter-split' }, [listHost, panelHost]));

        // Blindness note: every field this filters/searches on
        // (sku/name/item_status/category_id, plus barcode joined from
        // Master's own already-loaded item cache) is either already public
        // master data or this counter's OWN TEAM'S prior findings — nothing
        // here reads system_qty_base or the other team's values.
        function filteredLines() {
            const q = state.filterText.trim().toLowerCase();
            return state.view.lines.filter((l) => {
                if (q && !l.sku.toLowerCase().includes(q) && !l.name.toLowerCase().includes(q) && !itemBarcodeOf(l.item_id).includes(q)) return false;
                const isDone = l.is_counted_by_me || l.is_excluded;
                if (state.filterStatus === 'PENDING' && isDone) return false;
                if (state.filterStatus === 'COUNTED' && !isDone) return false;
                if (state.filterItemStatus !== 'ALL' && l.item_status !== state.filterItemStatus) return false;
                if (state.filterCategory !== 'ALL' && String(l.category_id) !== state.filterCategory) return false;
                return true;
            });
        }

        // PHASE V2.14.11.5 — autocomplete suggestions: pure SKU/name/
        // barcode text search over state.view.lines, deliberately NEVER
        // narrowed by the secondary browse list's own Aktif/Tidak
        // Aktif/Kategori/progress filters (requirement 11 — both ACTIVE
        // and INACTIVE items must always be searchable here, since the
        // current session already covers every master SKU). Same
        // blindness guarantee as filteredLines() — sku/name/item_status/
        // barcode/is_counted_by_me/claimed_by_teammate_username are all
        // that is ever read, never system_qty_base or the other team's
        // values. Capped at 10 results (requirement 4).
        function searchSuggestions(query) {
            const q = query.trim().toLowerCase();
            if (!q) return [];
            return state.view.lines
                .filter((l) => !l.is_excluded && (l.sku.toLowerCase().includes(q) || l.name.toLowerCase().includes(q) || itemBarcodeOf(l.item_id).includes(q)))
                .slice(0, 10);
        }

        function suggestBadgeCountText(line) {
            return line.is_counted_by_me ? 'SUDAH DIHITUNG' : 'BELUM DIHITUNG';
        }

        function buildSuggestionRowEl(line, idx) {
            const row = UI.el('div', { class: 'opname-suggest-row', 'data-idx': String(idx) });
            if (idx === state.suggestIndex) row.classList.add('active');
            row.appendChild(UI.el('div', { class: 'opname-suggest-identity' }, [
                UI.el('div', { class: 'opname-suggest-sku' }, line.sku),
                UI.el('div', { class: 'opname-suggest-name' }, line.name),
            ]));
            const statusBadge = UI.el('span', { class: `badge ${line.item_status === 'INACTIVE' ? 'badge-cancelled' : 'badge-received'}` }, line.item_status === 'INACTIVE' ? 'TIDAK AKTIF' : 'AKTIF');
            const countBadge = UI.el('span', { class: `badge ${line.is_counted_by_me ? 'badge-received' : 'badge-pending'}` }, suggestBadgeCountText(line));
            row.appendChild(UI.el('div', { class: 'opname-suggest-badges' }, [statusBadge, countBadge]));
            // mousedown (not click) fires before the input's blur, so a tap
            // never loses the selection to a blur-triggered dropdown close.
            row.addEventListener('mousedown', (e) => e.preventDefault());
            row.addEventListener('click', () => chooseSuggestion(line.item_id));
            return row;
        }

        function renderSuggestions() {
            suggestHost.innerHTML = '';
            if (state.suggestItems.length === 0) {
                if (state.filterText.trim()) {
                    suggestHost.style.display = 'block';
                    suggestHost.appendChild(UI.el('div', { class: 'opname-suggest-empty' }, 'Barang tidak ditemukan.'));
                } else {
                    suggestHost.style.display = 'none';
                }
                return;
            }
            suggestHost.style.display = 'block';
            state.suggestItems.forEach((line, idx) => suggestHost.appendChild(buildSuggestionRowEl(line, idx)));
        }

        function closeSuggestions() {
            state.suggestItems = [];
            state.suggestIndex = -1;
            suggestHost.style.display = 'none';
            suggestHost.innerHTML = '';
        }

        // Requirement 4 — debounce ~150-250ms so fast typing never fires a
        // search per keystroke.
        function scheduleSuggest() {
            if (suggestDebounceTimer) clearTimeout(suggestDebounceTimer);
            suggestDebounceTimer = setTimeout(() => {
                state.suggestItems = searchSuggestions(state.filterText);
                state.suggestIndex = -1;
                renderSuggestions();
            }, 200);
        }

        function chooseSuggestion(itemId) {
            closeSuggestions();
            jumpToItem(itemId);
        }

        // Requirement 6/7 — Enter's priority is: (1) an exact SKU match
        // always wins outright regardless of arrow-key navigation, since a
        // deliberately typed/scanned exact SKU is unambiguous; (2) a
        // uniquely-resolving exact barcode opens directly, an ambiguous
        // one shows an error and never guesses; (3) otherwise the
        // arrow-highlighted suggestion, or the FIRST suggestion if the
        // user never navigated.
        function handleSearchEnter() {
            const q = state.filterText.trim();
            if (!q) return;
            const qLower = q.toLowerCase();
            const exactSku = state.view.lines.find((l) => !l.is_excluded && l.sku.toLowerCase() === qLower);
            if (exactSku) { chooseSuggestion(exactSku.item_id); return; }

            const barcodeMatches = state.view.lines.filter((l) => !l.is_excluded && itemBarcodeOf(l.item_id) === qLower);
            if (barcodeMatches.length === 1) { chooseSuggestion(barcodeMatches[0].item_id); return; }
            if (barcodeMatches.length > 1) {
                UI.toast('Barcode tidak unik — beberapa barang cocok. Gunakan pencarian SKU/nama.', 'error');
                return;
            }

            if (state.suggestItems.length === 0) return;
            const idx = state.suggestIndex >= 0 ? state.suggestIndex : 0;
            chooseSuggestion(state.suggestItems[idx].item_id);
        }

        function renderChrome() {
            bannerHost.innerHTML = '';
            bannerHost.appendChild(UI.el('div', { class: 'banner-lock' },
                `🔒 STOCK OPNAME — Sesi ${state.view.session_number || state.view.session_id} — Anda login sebagai Tim ${state.view.role.toUpperCase()} (Hitung Fisik Independen/Blind).`));

            const progressPct = state.view.progress.total > 0 ? Math.round((state.view.progress.counted / state.view.progress.total) * 100) : 0;
            progressHost.innerHTML = '';
            progressHost.appendChild(UI.el('div', { class: 'card-title' }, `Progress Tim ${state.view.role.toUpperCase()}: ${state.view.progress.counted} / ${state.view.progress.total} (${progressPct}%)`));
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
            cardListHost.innerHTML = '';
            if (pageLines.length === 0) {
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Tidak ada barang yang cocok.')]));
                cardListHost.appendChild(UI.el('div', { class: 'opname-empty-state' }, 'Tidak ada barang yang cocok.'));
            } else {
                pageLines.forEach((line, i) => {
                    tbody.appendChild(buildBlindCountRowEl(state, line, start + i + 1, selectItem));
                    cardListHost.appendChild(buildBlindCountCardEl(line, selectItem));
                });
            }

            pagerHost.innerHTML = '';
            const prevBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', ...(state.page === 0 ? { disabled: 'disabled' } : {}) }, '‹ Sebelumnya');
            const nextBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', ...(state.page >= totalPages - 1 ? { disabled: 'disabled' } : {}) }, 'Berikutnya ›');
            prevBtn.addEventListener('click', () => { state.page--; renderTable(); });
            nextBtn.addEventListener('click', () => { state.page++; renderTable(); });
            pagerHost.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, `Halaman ${state.page + 1} dari ${totalPages} (${filtered.length} item)`));
            pagerHost.appendChild(UI.el('div', { style: 'display:flex; gap:8px;' }, [prevBtn, nextBtn]));
        }

        searchInput.addEventListener('input', () => {
            state.filterText = searchInput.value;
            state.page = 0;
            renderTable();
            scheduleSuggest();
        });
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (state.suggestItems.length === 0) return;
                state.suggestIndex = Math.min(state.suggestItems.length - 1, state.suggestIndex + 1);
                renderSuggestions();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (state.suggestItems.length === 0) return;
                state.suggestIndex = Math.max(0, state.suggestIndex - 1);
                renderSuggestions();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                handleSearchEnter();
            } else if (e.key === 'Escape') {
                closeSuggestions();
            }
        });
        filterSelect.addEventListener('change', () => { state.filterStatus = filterSelect.value; state.page = 0; renderTable(); });
        itemStatusSelect.addEventListener('change', () => { state.filterItemStatus = itemStatusSelect.value; state.page = 0; syncItemStatusSeg(); renderTable(); });
        categorySelect.addEventListener('change', () => { state.filterCategory = categorySelect.value; state.page = 0; renderTable(); });
        syncItemStatusSeg();
        scanBtn.addEventListener('click', () => openScanModal(state, jumpToItem));
        nextItemBtn.addEventListener('click', claimNextItem);

        // Re-fetches the SAME getOpname(sessionId) call renderSession()
        // itself uses, but patches state.view / re-renders THIS screen in
        // place — preserving the operator's current search/filter/page.
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
            state.filterItemStatus = 'ALL';
            state.filterCategory = 'ALL';
            searchInput.value = '';
            filterSelect.value = 'ALL';
            itemStatusSelect.value = 'ALL';
            categorySelect.value = 'ALL';
            syncItemStatusSeg();
            state.page = Math.floor(globalIdx / PAGE_SIZE);
            renderTable();
            selectItem(itemId);
            return true;
        }

        // Claims $itemId (or, if omitted, whatever the server picks as
        // "next available" — see StockOpnameService::claimItem()) for THIS
        // team, then opens the count panel on it.
        //
        // PHASE V2.14.11.5 — a claim conflict (someone on the SAME team
        // currently holds a live claim on this exact item) shows the
        // fixed, friendly message rather than the raw server text, and
        // returns focus to the search box for an immediate retry/next
        // search. Reopening an item that already has team findings opens
        // in SUMMARY mode (history + "SUDAH DIHITUNG" + a non-writing
        // "+ Tambah Temuan" button) rather than straight into a blank
        // form — "Ambil Item Berikutnya" (itemId === null) never hits
        // this branch, since its own candidate selection only ever offers
        // genuinely uncounted lines server-side.
        async function selectItem(itemId) {
            const line = itemId === null ? null : state.view.lines.find((l) => l.item_id === itemId);
            try {
                const claim = await InvApi.claimOpnameItem(state.view.session_id, state.view.role, itemId);
                state.activeItemId = claim.item_id;
                state.activeClaimToken = claim.claim_token;
                state.panelMode = (line && line.is_counted_by_me) ? 'summary' : 'form';
                await refetch();
                await renderPanel();
                panelHost.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (err) {
                UI.handleApiError(err);
                const msg = (err && err.message) || '';
                if ((line && line.claimed_by_teammate_username) || /currently claimed by/i.test(msg)) {
                    UI.toast('Barang sedang dihitung oleh anggota Tim P1/P2 lain.', 'error');
                } else {
                    UI.toast(msg || 'Barang tidak tersedia untuk diklaim.', 'error');
                }
                searchInput.focus();
            }
        }

        async function claimNextItem() {
            await selectItem(null);
        }

        async function closePanel() {
            if (state.activeItemId !== null) {
                try { await InvApi.releaseOpnameItem(state.view.session_id, state.view.role, state.activeItemId); } catch (err) { /* best-effort */ }
            }
            state.activeItemId = null;
            state.activeClaimToken = null;
            state.panelMode = 'form';
            await refetch();
            renderPanelEmpty();
        }

        function renderPanelEmpty() {
            panelHost.innerHTML = '';
            panelHost.appendChild(UI.el('div', { class: 'alert alert-info' }, 'Pilih barang dari daftar ("Hitung"), scan barcode, atau klik "Ambil Item Berikutnya" untuk mulai menghitung.'));
        }

        // PHASE V2.14.10.1 Gate 5 — units and the team's finding history
        // for this ONE item are fetched here, on demand, when the panel
        // actually opens — never bundled into the bulk list request.
        // Gate 1 — units come from the session's FROZEN snapshot
        // (opnameItemUnits), never live GET /items/{id}/units.
        async function renderPanel() {
            const line = state.view.lines.find((l) => l.item_id === state.activeItemId);
            if (!line) { renderPanelEmpty(); return; }

            let units = state.unitsCache[state.activeItemId];
            if (!units) {
                try {
                    units = await InvApi.opnameItemUnits(state.view.session_id, state.activeItemId);
                    state.unitsCache[state.activeItemId] = units;
                } catch (err) {
                    UI.handleApiError(err);
                    units = [];
                }
            }
            const baseUnitCode = line.base_unit_code;

            let findings = [];
            try {
                findings = await InvApi.opnameMyFindingsForItem(state.view.session_id, state.activeItemId);
            } catch (err) {
                UI.handleApiError(err);
            }
            const totalAkumulasi = findings.reduce((sum, f) => sum + f.good_qty, 0);
            const isAdditional = findings.length > 0;

            panelHost.innerHTML = '';
            panelHost.appendChild(UI.el('div', { class: 'card-title' }, `${line.sku} — ${line.name}`));
            const identityMetaEl = UI.el('div', { style: 'color:var(--text3); font-size:0.85rem; margin-bottom:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                document.createTextNode(`Kategori: ${categoryNameOf(line.category_id)}`),
            ]);
            // Requirement 14 — reopening an item that already has team
            // findings always shows this at a glance, in BOTH panel modes
            // (the collapsed-by-default summary AND the "Temuan Baru"
            // form), never silently.
            if (isAdditional) identityMetaEl.appendChild(UI.el('span', { class: 'badge badge-received' }, 'SUDAH DIHITUNG'));
            panelHost.appendChild(identityMetaEl);

            const closeBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '‹ Kembali ke Daftar');
            closeBtn.addEventListener('click', closePanel);
            panelHost.appendChild(closeBtn);

            // Riwayat Temuan — THIS TEAM's own history only (already
            // structurally guaranteed by getMyFindingsForItem() never
            // returning the other team's data at all). Shown in BOTH panel
            // modes (form and summary). PHASE V2.14.11.4 — the itemized
            // list is collapsed by default (keeps the counting screen
            // short on mobile) behind a toggle; the accumulated total
            // stays visible either way so the counter always sees it at a
            // glance without expanding anything.
            const findingsHost = UI.el('div', { style: 'margin-top:10px; border:1px solid var(--border); border-radius:8px;' });
            const findingsListHost = UI.el('div', { class: 'opname-history-list', style: 'display:none;' });
            const historyToggleBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm opname-history-toggle' }, `Lihat Riwayat Temuan (${findings.length})`);
            historyToggleBtn.addEventListener('click', () => {
                const opening = findingsListHost.style.display === 'none';
                findingsListHost.style.display = opening ? 'block' : 'none';
                historyToggleBtn.textContent = opening ? 'Sembunyikan Riwayat Temuan' : `Lihat Riwayat Temuan (${findings.length})`;
            });
            findingsHost.appendChild(UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center; padding:6px 10px; font-weight:600; flex-wrap:wrap; gap:6px;' }, [
                document.createTextNode('Riwayat Temuan (Tim)'),
                historyToggleBtn,
            ]));
            if (findings.length === 0) {
                findingsListHost.appendChild(UI.el('div', { style: 'padding:8px 10px; color:var(--text3); font-size:0.85rem;' }, 'Belum ada temuan untuk barang ini.'));
            } else {
                findings.forEach((f, idx) => {
                    findingsListHost.appendChild(UI.el('div', { class: 'opname-finding-row' }, [
                        buildFindingSummaryEl(f, idx, baseUnitCode),
                        UI.el('div', { style: 'color:var(--text3);' }, f.created_at),
                    ]));
                });
            }
            findingsHost.appendChild(findingsListHost);
            findingsHost.appendChild(UI.el('div', { class: 'compact-summary', style: 'padding:8px 10px;' }, [
                UI.el('div', {}, [document.createTextNode('Total Akumulasi Tim: '), UI.el('b', {}, `${UI.formatNumber(totalAkumulasi)} ${baseUnitCode}`)]),
            ]));
            panelHost.appendChild(findingsHost);

            // PHASE V2.14.10.1 Gate 6 — SUMMARY mode: after any successful
            // save, the panel shows ONLY the history/aggregate above and a
            // "+Tambah Temuan" button that NEVER writes anything by
            // itself — it only re-claims (a fresh claim_token) and swaps
            // to a blank FORM mode. This is verified by an automated test
            // asserting the finding row count is unchanged immediately
            // after clicking it, before "Simpan Temuan" is ever pressed.
            if (state.panelMode === 'summary') {
                const addFindingBtn = UI.el('button', { class: 'btn btn-secondary' }, '+ Tambah Temuan');
                addFindingBtn.addEventListener('click', async () => {
                    try {
                        const claim = await InvApi.claimOpnameItem(state.view.session_id, state.view.role, state.activeItemId);
                        state.activeClaimToken = claim.claim_token;
                        state.panelMode = 'form';
                        await renderPanel();
                    } catch (err) {
                        UI.handleApiError(err);
                        const msg = (err && err.message) || '';
                        UI.toast(/currently claimed by/i.test(msg) ? 'Barang sedang dihitung oleh anggota Tim P1/P2 lain.' : (msg || 'Tidak dapat mengklaim ulang barang ini.'), 'error');
                    }
                });
                panelHost.appendChild(UI.el('div', { class: 'opname-sticky-actions' }, [addFindingBtn]));
                return;
            }

            // FORM mode — a fresh, blank entry. Labeled "Temuan Baru" once
            // at least one finding already exists (the Tambah Temuan case).
            if (isAdditional) {
                panelHost.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:8px;' }, 'Temuan Baru'));
            }

            const unitInputs = {};
            const unitInputsHost = UI.el('div', { style: 'margin-top:12px;' });
            units.forEach((u) => {
                const input = UI.el('input', { type: 'number', step: 'any', min: '0', inputmode: 'decimal', placeholder: '0' });
                unitInputs[u.unit_id] = input;
                unitInputsHost.appendChild(UI.el('div', { class: 'opname-unit-input-row' }, [
                    UI.el('label', {}, `${u.code} (${u.name})`), input,
                ]));
            });
            panelHost.appendChild(unitInputsHost);
            // Requirement 6 — selecting an item (search/tap/Enter) opens
            // straight into an editable form with the first GOOD quantity
            // input already focused, no extra "Hitung" tap needed.
            if (units.length > 0) unitInputs[units[0].unit_id].focus();

            const totalHost = UI.el('div', { class: 'opname-total-otomatis' });
            // PHASE V2.14.11 — item 6 correction: a blank/untouched form is
            // NEVER silently treated as "stok fisik 0". Whenever the GOOD
            // total preview is exactly 0, an explicit confirmation checkbox
            // appears and must be checked before Simpan is enabled — the
            // server independently re-validates the actual zero-count rule
            // (first finding only) regardless of this client-side gate.
            const zeroConfirmRow = UI.el('div', { class: 'opname-zero-confirm', style: 'display:none;' });
            const zeroConfirmCheckbox = UI.el('input', { type: 'checkbox', id: 'opname-zero-confirm-chk' });
            zeroConfirmRow.appendChild(UI.el('label', { style: 'display:flex; align-items:center; gap:8px;' }, [
                zeroConfirmCheckbox, document.createTextNode('Saya konfirmasi: Stok fisik GOOD = 0 (bukan form kosong)'),
            ]));
            function recomputeTotalPreview() {
                let total = 0;
                units.forEach((u) => { total += (Number(unitInputs[u.unit_id].value) || 0) * Number(u.conversion_to_base); });
                totalHost.textContent = `Total Otomatis (dalam satuan dasar: ${baseUnitCode}): ${UI.formatNumber(total)}`;
                zeroConfirmRow.style.display = total === 0 ? 'block' : 'none';
                if (total !== 0) zeroConfirmCheckbox.checked = false;
                updateSaveEnabled();
            }
            Object.values(unitInputs).forEach((inp) => inp.addEventListener('input', recomputeTotalPreview));
            zeroConfirmCheckbox.addEventListener('change', updateSaveEnabled);
            panelHost.appendChild(totalHost);
            panelHost.appendChild(zeroConfirmRow);

            // PHASE V2.14.11 — every condition (GOOD already above; DAMAGED/
            // EXPIRED/DEADSTOCK here) now has its OWN unit + qty, never a
            // single base-unit-assumed number — same dynamic-unit source
            // (the session's frozen snapshot) as GOOD. Photo evidence is
            // captured HERE, immediately on file selection (uploaded right
            // away, scoped to the current claim), never deferred until
            // Simpan Temuan — but the FINDING itself is only ever created by
            // Simpan Temuan; selecting a photo alone writes nothing to the
            // findings/quantities tables.
            // PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 1):
            // a photo no longer auto-attaches just by matching session/
            // item/role/condition/uploader — Simpan Temuan must explicitly
            // name each photo's upload_token. This block now tracks
            // {photo_id, token} per uploaded photo and sends the tokens for
            // THIS condition; each thumbnail also gets a × button that
            // calls removeOpnamePhoto() (Blocker 1D) so an abandoned/
            // reconsidered photo can be dropped before Simpan Temuan and
            // can never later be swept into a finding.
            function buildConditionBlock(label, conditionKey) {
                const unitOptions = units.map((u) => `<option value="${u.unit_id}"${u.is_base_unit ? ' selected' : ''}>${u.code}</option>`).join('');
                const unitSelect = UI.el('select', { html: unitOptions });
                const qtyInput = UI.el('input', { type: 'number', step: 'any', min: '0', inputmode: 'decimal', value: '0' });
                const photoInput = UI.el('input', { type: 'file', accept: 'image/*', capture: 'environment', style: 'display:none;' });
                const photoBtn = UI.el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, `📷 Foto ${label}`);
                const thumbHost = UI.el('div', { class: 'opname-photo-thumbs' });
                const photoHint = UI.el('div', { class: 'opname-photo-hint' }, `Foto wajib jika ${label} > 0.`);
                const uploaded = [];

                function renderThumb(entry) {
                    const wrap = UI.el('div', { class: 'opname-photo-thumb-wrap', style: 'position:relative; display:inline-block;' });
                    const thumb = UI.el('img', { src: entry.url, class: 'opname-photo-thumb', alt: label });
                    const removeBtn = UI.el('button', {
                        type: 'button', class: 'opname-photo-remove',
                        style: 'position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; border:none; background:#c0392b; color:#fff; line-height:1; cursor:pointer;',
                        title: `Hapus foto ${label}`,
                    }, '×');
                    removeBtn.addEventListener('click', async () => {
                        removeBtn.disabled = true;
                        try {
                            await InvApi.removeOpnamePhoto(state.view.session_id, entry.photo_id, entry.token);
                            const idx = uploaded.indexOf(entry);
                            if (idx !== -1) uploaded.splice(idx, 1);
                            wrap.remove();
                            updateSaveEnabled();
                        } catch (err) {
                            UI.handleApiError(err);
                            UI.toast((err && err.message) || `Gagal menghapus foto ${label}.`, 'error');
                            removeBtn.disabled = false;
                        }
                    });
                    wrap.appendChild(thumb);
                    wrap.appendChild(removeBtn);
                    thumbHost.appendChild(wrap);
                }

                photoBtn.addEventListener('click', () => photoInput.click());
                photoInput.addEventListener('change', async () => {
                    const file = photoInput.files && photoInput.files[0];
                    photoInput.value = '';
                    if (!file) return;
                    if (!state.activeClaimToken) {
                        UI.toast('Klaim sudah tidak aktif — buka ulang barang ini.', 'error');
                        return;
                    }
                    photoBtn.disabled = true;
                    try {
                        const compressed = await compressImageFile(file);
                        const result = await InvApi.uploadOpnamePhoto(
                            state.view.session_id, state.view.role, state.activeItemId, conditionKey, state.activeClaimToken, compressed
                        );
                        const entry = { photo_id: result.photo_id, token: result.token, url: URL.createObjectURL(compressed) };
                        uploaded.push(entry);
                        renderThumb(entry);
                        updateSaveEnabled();
                    } catch (err) {
                        UI.handleApiError(err);
                        UI.toast((err && err.message) || `Gagal mengunggah foto ${label}.`, 'error');
                    } finally {
                        photoBtn.disabled = false;
                    }
                });
                qtyInput.addEventListener('input', updateSaveEnabled);

                const block = UI.el('div', { class: 'form-group opname-condition-block' }, [
                    UI.el('label', {}, label),
                    UI.el('div', { style: 'display:flex; gap:6px; align-items:center;' }, [qtyInput, unitSelect]),
                    UI.el('div', { style: 'margin-top:6px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;' }, [photoBtn, photoInput]),
                    thumbHost, photoHint,
                ]);
                return {
                    el: block,
                    qty: () => Number(qtyInput.value || 0),
                    payload: () => [{ unit_id: Number(unitSelect.value), qty: Number(qtyInput.value || 0) }],
                    photoCount: () => uploaded.length,
                    photoTokens: () => uploaded.map((entry) => entry.token),
                };
            }

            const damagedBlock = buildConditionBlock('Rusak', 'DAMAGED');
            const expiredBlock = buildConditionBlock('Expired', 'EXPIRED');
            const deadstockBlock = buildConditionBlock('Deadstock', 'DEADSTOCK');
            panelHost.appendChild(UI.el('div', { class: 'grid-3', style: 'margin-top:8px;' }, [damagedBlock.el, expiredBlock.el, deadstockBlock.el]));

            const notesInput = UI.el('input', { type: 'text', placeholder: 'Keterangan (opsional)' });
            panelHost.appendChild(UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Keterangan'), notesInput]));

            const alertBox = UI.el('div');
            panelHost.appendChild(alertBox);

            const saveBtn = UI.el('button', { class: 'btn btn-primary' }, isAdditional ? '💾 Simpan Temuan' : '💾 Simpan Hitungan');
            panelHost.appendChild(UI.el('div', { class: 'opname-sticky-actions' }, [saveBtn]));

            // Client-side pre-check only (mirrors the server's own gate —
            // see StockOpnameService::submitFinding()'s photo-requirement
            // check) so a counter never taps Simpan only to be told a photo
            // is missing after the round trip; the server remains the sole
            // authority and re-validates everything from scratch.
            function updateSaveEnabled() {
                let ok = true;
                let totalGood = 0;
                units.forEach((u) => { totalGood += (Number(unitInputs[u.unit_id].value) || 0) * Number(u.conversion_to_base); });
                if (totalGood === 0 && !zeroConfirmCheckbox.checked) ok = false;
                [damagedBlock, expiredBlock, deadstockBlock].forEach((b) => {
                    if (b.qty() > 0 && b.photoCount() === 0) ok = false;
                });
                saveBtn.disabled = !ok;
            }
            recomputeTotalPreview();
            updateSaveEnabled();

            // PHASE V2.14.10.1 Gate 3 — every rendered GOOD unit row is
            // sent, including a zero value: an untouched/blank input is a
            // real "checked this unit, found none" answer, never silently
            // dropped. The server enforces the actual zero-result rule
            // (valid only for the very first finding on this line/role).
            // Gate 4 — retries ONCE, transparently, on CLAIM_LOST: the
            // claim is refreshed and the exact same payload resubmitted, so
            // an expired-but-uncontested lease never forces the counter to
            // re-type their entry. PHASE V2.14.11.1 — already-uploaded
            // photos are named by upload_token (never re-matched by
            // session/item/role/condition/uploader alone), so the retry's
            // token list stays valid across the claim refresh.
            async function saveFinding(retryOnClaimLost) {
                const conditions = {
                    GOOD: units.map((u) => ({ unit_id: u.unit_id, qty: Number(unitInputs[u.unit_id].value || 0) })),
                    DAMAGED: damagedBlock.payload(),
                    EXPIRED: expiredBlock.payload(),
                    DEADSTOCK: deadstockBlock.payload(),
                };
                const photos = {
                    DAMAGED: damagedBlock.photoTokens(),
                    EXPIRED: expiredBlock.photoTokens(),
                    DEADSTOCK: deadstockBlock.photoTokens(),
                };
                alertBox.innerHTML = '';
                try {
                    await InvApi.submitOpnameFinding(state.view.session_id, state.view.role, state.activeItemId, conditions, notesInput.value || null, state.activeClaimToken, photos);
                    UI.toast('Temuan tersimpan.', 'success');
                    state.activeClaimToken = null;
                    state.panelMode = 'summary';
                    await refetch();
                    await renderPanel();
                    // Requirement 12 — fast warehouse counting loop: after a
                    // successful save, focus returns to the search box so
                    // the next SKU can be typed immediately, with zero
                    // extra taps (the summary view stays visible; only
                    // keyboard focus moves).
                    searchInput.focus();
                } catch (err) {
                    if (err && err.code === 'CLAIM_LOST' && retryOnClaimLost) {
                        try {
                            const claim = await InvApi.claimOpnameItem(state.view.session_id, state.view.role, state.activeItemId);
                            state.activeClaimToken = claim.claim_token;
                            await saveFinding(false);
                            return;
                        } catch (retryErr) {
                            // fall through to the generic error display below
                        }
                    }
                    alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menyimpan temuan.'));
                }
            }
            saveBtn.addEventListener('click', () => saveFinding(true));
        }

        // PHASE V2.14.11 — client-side compression: draws the source image
        // onto a canvas capped at 1600px on the longest side and re-encodes
        // as JPEG. Purely a bandwidth/UX convenience for the mobile upload
        // — the server (StockOpnamePhotoService) never trusts this and
        // independently MIME-sniffs, decodes, and re-encodes every upload
        // itself regardless of what the client sent.
        function compressImageFile(file) {
            return new Promise((resolve) => {
                const img = new Image();
                const reader = new FileReader();
                reader.onload = () => { img.src = reader.result; };
                img.onload = () => {
                    const maxDim = 1600;
                    let { width, height } = img;
                    if (width > maxDim || height > maxDim) {
                        const scale = Math.min(maxDim / width, maxDim / height);
                        width = Math.round(width * scale);
                        height = Math.round(height * scale);
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                    canvas.toBlob((blob) => resolve(blob || file), 'image/jpeg', 0.82);
                };
                img.onerror = () => resolve(file);
                reader.onerror = () => resolve(file);
                reader.readAsDataURL(file);
            });
        }

        renderChrome();
        renderTable();
        renderPanelEmpty();
        return wrap;
    }

    // One compact <tr> per session line — no inline inputs at all
    // (V2.14.10: input happens exclusively in the count panel, opened via
    // this row's "Hitung" button). Status is derived ONLY from
    // is_counted_by_me/is_excluded/claimed_by_* (fields already on the
    // blind view; never a system/theoretical quantity, which this shape
    // never carries in the first place).
    function buildBlindCountRowEl(state, line, rowNo, selectItem) {
        const row = UI.el('tr', { 'data-item-id': String(line.item_id) });
        row.appendChild(UI.el('td', { class: 'compact-col-no' }, String(rowNo)));
        row.appendChild(UI.el('td', { class: 'compact-col-item' }, `${line.sku} — ${line.name}`));
        row.appendChild(UI.el('td', {}, categoryNameOf(line.category_id)));

        if (line.is_excluded) {
            row.appendChild(UI.el('td', {}, [UI.el('span', { class: 'badge badge-cancelled' }, 'Dikecualikan')]));
            row.appendChild(UI.el('td', {}, '-'));
            row.appendChild(UI.el('td', {}, '-'));
            return row;
        }

        let statusBadge;
        if (line.is_counted_by_me) {
            statusBadge = UI.el('span', { class: 'badge badge-received' }, `Ada Temuan (${UI.formatNumber(line.my_qty_base)} ${line.base_unit_code})`);
        } else if (line.claimed_by_teammate_username) {
            statusBadge = UI.el('span', { class: 'badge badge-pending' }, `Sedang dihitung oleh ${line.claimed_by_teammate_username}`);
        } else {
            statusBadge = UI.el('span', { class: 'badge badge-pending' }, 'Belum Dihitung');
        }
        row.appendChild(UI.el('td', {}, [statusBadge]));
        row.appendChild(UI.el('td', {}, line.my_submitted_at || '-'));

        const hitungBtn = UI.el('button', { class: 'btn btn-primary btn-sm compact-row-btn' }, line.is_counted_by_me ? 'Tambah Temuan' : 'Hitung');
        hitungBtn.addEventListener('click', () => selectItem(line.item_id));
        row.appendChild(UI.el('td', {}, [hitungBtn]));
        return row;
    }

    // PHASE V2.14.11.4 — mobile card rendering of the SAME session line
    // buildBlindCountRowEl already renders as a <tr>: same fields, same
    // is_excluded/is_counted_by_me/claimed_by_teammate_username status
    // derivation, same selectItem() action, never a second read of
    // anything the table row doesn't already read. Rendered in parallel
    // with the table (never instead of it) — CSS alone decides which one
    // is visible per viewport, so nothing here duplicates business logic,
    // only presentation.
    function buildBlindCountCardEl(line, selectItem) {
        const card = UI.el('div', { class: 'opname-item-card', 'data-item-id': String(line.item_id) });
        card.appendChild(UI.el('div', { class: 'opname-item-card-title' }, [
            UI.el('div', { class: 'opname-item-card-sku' }, line.sku),
            UI.el('div', { class: 'opname-item-card-name' }, line.name),
        ]));

        const itemStatusBadge = UI.el('span', { class: `badge ${line.item_status === 'INACTIVE' ? 'badge-cancelled' : 'badge-received'}` }, line.item_status === 'INACTIVE' ? 'TIDAK AKTIF' : 'AKTIF');
        const metaRow = UI.el('div', { class: 'opname-item-card-meta' }, [itemStatusBadge]);
        const catName = categoryNameOf(line.category_id);
        if (catName && catName !== '-') metaRow.appendChild(UI.el('span', { class: 'opname-item-card-category' }, catName));
        card.appendChild(metaRow);

        if (line.is_excluded) {
            card.appendChild(UI.el('div', { class: 'opname-item-card-count-status' }, [UI.el('span', { class: 'badge badge-cancelled' }, 'Dikecualikan')]));
            return card;
        }

        let statusBadge;
        if (line.is_counted_by_me) {
            statusBadge = UI.el('span', { class: 'badge badge-received' }, `Ada Temuan (${UI.formatNumber(line.my_qty_base)} ${line.base_unit_code})`);
        } else if (line.claimed_by_teammate_username) {
            statusBadge = UI.el('span', { class: 'badge badge-pending' }, `Sedang dihitung oleh ${line.claimed_by_teammate_username}`);
        } else {
            statusBadge = UI.el('span', { class: 'badge badge-pending' }, 'Belum Dihitung');
        }
        card.appendChild(UI.el('div', { class: 'opname-item-card-count-status' }, [statusBadge]));

        const hitungBtn = UI.el('button', { class: 'btn btn-primary opname-item-card-btn' }, line.is_counted_by_me ? 'Tambah Temuan' : 'HITUNG');
        hitungBtn.addEventListener('click', () => selectItem(line.item_id));
        card.appendChild(hitungBtn);
        return card;
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
    // PHASE V2.14.9 — search/filter toolbar for the supervisor comparison
    // table, entirely client-side over review.lines (already-loaded bulk
    // data from opnameReview() — no per-row API calls at any session
    // size). Status Hitung here covers the full supervisor vocabulary
    // (match_status as-is, plus PENDING split visually as "Belum
    // Dihitung" by keteranganFor()); Item Aktif/Tidak Aktif is the ITEM
    // MASTER status (item_status), never opname/count status.
    function reviewFilterOptionsHtml() {
        return `
            <option value="ALL">Semua</option>
            <option value="PENDING">Belum Dihitung</option>
            <option value="MATCH">Cocok</option>
            <option value="MISMATCH">Selisih</option>
            <option value="RECOUNTED">Recount</option>
            <option value="EXCLUDED">Dikecualikan</option>
        `;
    }

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
        if (s.condition_disagreement) kpis.push(['Perlu Resolusi Kondisi', s.condition_disagreement]);
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

        // PHASE V2.14.10 — Team Progress panel: members / total / counted /
        // remaining / % per role, plus an optional per-member breakdown
        // (operational visibility only — never surfaced as a performance
        // judgment).
        if (review.team_progress) {
            const teamBox = UI.el('div', { class: 'grid-2', style: 'margin-bottom:12px;' });
            ['p1', 'p2'].forEach((role) => {
                const tp = review.team_progress[role];
                if (!tp) return;
                const memberNames = tp.members.map((m) => m.username).join(', ') || '(belum ditugaskan)';
                const perMemberList = (tp.per_member || []).map((pm) => UI.el('div', { style: 'font-size:0.8rem; color:var(--text3);' }, `${pm.username}: ${pm.counted} SKU`));
                teamBox.appendChild(UI.el('div', { class: 'card', style: 'margin-bottom:0;' }, [
                    UI.el('div', { class: 'card-title', style: 'font-size:0.9rem;' }, `Tim ${role.toUpperCase()} (${tp.members.length} petugas)`),
                    UI.el('div', { style: 'font-size:0.85rem; color:var(--text2); margin-bottom:6px;' }, memberNames),
                    UI.el('div', {}, `${tp.counted} / ${tp.total} dihitung (${tp.progress_pct}%) — sisa ${tp.remaining}`),
                    ...perMemberList,
                ]));
            });
            wrap.appendChild(teamBox);
        }

        const reviewState = { text: '', itemStatus: 'ALL', category: 'ALL', status: 'ALL' };
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU/nama/barcode...', id: 'opname-review-search' });
        const itemStatusSelect = UI.el('select', { id: 'opname-review-item-status', html: `
            <option value="ALL">Semua Status Item</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Tidak Aktif</option>
        ` });
        const categorySelect = UI.el('select', { id: 'opname-review-category', html: categoryFilterOptionsHtml() });
        const statusSelect = UI.el('select', { id: 'opname-review-status', html: reviewFilterOptionsHtml() });
        const tbody = UI.el('tbody', {});

        function matchesFilters(l) {
            const q = reviewState.text.trim().toLowerCase();
            if (q && !l.sku.toLowerCase().includes(q) && !l.name.toLowerCase().includes(q) && !itemBarcodeOf(l.item_id).includes(q)) return false;
            if (reviewState.itemStatus !== 'ALL' && l.item_status !== reviewState.itemStatus) return false;
            if (reviewState.category !== 'ALL' && String(l.category_id) !== reviewState.category) return false;
            if (reviewState.status !== 'ALL') {
                const effectiveStatus = l.is_excluded ? 'EXCLUDED' : l.match_status;
                if (effectiveStatus !== reviewState.status) return false;
            }
            return true;
        }

        function renderRows() {
            tbody.innerHTML = '';
            const filtered = review.lines.filter(matchesFilters);
            if (!filtered.length) {
                tbody.appendChild(UI.el('tr', {}, [UI.el('td', { colspan: '13' }, 'Tidak ada barang yang cocok.')]));
                return;
            }
            filtered.forEach((l) => tbody.appendChild(buildReviewRowEl(session, l)));
        }

        searchInput.addEventListener('input', () => { reviewState.text = searchInput.value; renderRows(); });
        itemStatusSelect.addEventListener('change', () => { reviewState.itemStatus = itemStatusSelect.value; renderRows(); });
        categorySelect.addEventListener('change', () => { reviewState.category = categorySelect.value; renderRows(); });
        statusSelect.addEventListener('change', () => { reviewState.status = statusSelect.value; renderRows(); });

        const cancelBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Batalkan Sesi');
        cancelBtn.addEventListener('click', cancelOpname);

        wrap.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { style: 'display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;' }, [
                UI.el('div', { class: 'card-title' }, 'Perbandingan P1 vs P2'),
                cancelBtn,
            ]),
            UI.el('div', { style: 'display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;' }, [
                UI.el('div', { style: 'flex:1 1 220px;' }, [searchInput]),
                UI.el('div', { style: 'width:160px;' }, [itemStatusSelect]),
                UI.el('div', { style: 'width:180px;' }, [categorySelect]),
                UI.el('div', { style: 'width:160px;' }, [statusSelect]),
            ]),
            UI.el('div', { class: 'compact-table-wrap' }, [
                UI.el('table', { class: 'compact-table' }, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Barang', 'Kategori', 'Stok Sistem', 'P1', 'P2', 'Selisih', 'Rusak P1/P2', 'Expired P1/P2', 'Deadstock P1/P2', 'Keterangan', 'Hasil Final/Recount', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    tbody,
                ]),
            ]),
        ]));
        renderRows();

        // PHASE V2.14.9.1 — finalize is also blocked while any line has an
        // unresolved condition disagreement (mirrors the server-side
        // check in StockOpnameService::finalize()); the button label
        // surfaces both blockers together so the supervisor knows exactly
        // what's outstanding.
        const readyToFinalize = s.not_counted === 0 && s.mismatch === 0 && !s.condition_disagreement;
        const pendingCount = s.not_counted + s.mismatch + (s.condition_disagreement || 0);
        const finalizeBtn = UI.el('button', { class: 'btn btn-danger', style: 'margin-top:12px;' },
            readyToFinalize ? '🔐 Finalisasi Sesi' : `🔐 Finalisasi Sesi (${pendingCount} item belum selesai)`);
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

    // PHASE V2.14.9 — one compact <tr> for the supervisor comparison
    // table. Rusak/Expired/Deadstock are shown as "P1 / P2" side-by-side
    // — NEVER merged/averaged/hidden — so a disagreement between counters
    // stays visible exactly like the pre-existing P1/P2 qty columns
    // already do. Keterangan shows both counters' own notes distinctly
    // (never combined into one un-attributable note).
    function buildReviewRowEl(session, l) {
        const diffP1P2 = (l.p1_qty_base !== null && l.p2_qty_base !== null)
            ? UI.formatNumber(Number(l.p1_qty_base) - Number(l.p2_qty_base))
            : '-';
        const pairText = (p1, p2) => `${p1 !== null && p1 !== undefined ? UI.formatNumber(p1) : '-'} / ${p2 !== null && p2 !== undefined ? UI.formatNumber(p2) : '-'}`;
        const notesText = (l.p1_notes || l.p2_notes)
            ? `P1: ${l.p1_notes || '-'} | P2: ${l.p2_notes || '-'}`
            : '-';
        const cells = [
            UI.el('td', {}, `${l.sku} — ${l.name}`),
            UI.el('td', {}, categoryNameOf(l.category_id)),
            UI.el('td', {}, UI.formatNumber(l.system_qty_base)),
            // PHASE V2.14.10 — "who counted P1 / who counted P2" caption,
            // essential once a team can hold more than one member.
            UI.el('td', {}, l.p1_qty_base !== null ? `${UI.formatNumber(l.p1_qty_base)}${l.p1_counter_username ? ` (${l.p1_counter_username})` : ''}` : '-'),
            UI.el('td', {}, l.p2_qty_base !== null ? `${UI.formatNumber(l.p2_qty_base)}${l.p2_counter_username ? ` (${l.p2_counter_username})` : ''}` : '-'),
            UI.el('td', {}, diffP1P2),
            UI.el('td', {}, pairText(l.p1_rusak_qty, l.p2_rusak_qty)),
            UI.el('td', {}, pairText(l.p1_expired_qty, l.p2_expired_qty)),
            UI.el('td', {}, pairText(l.p1_deadstock_qty, l.p2_deadstock_qty)),
            UI.el('td', {}, notesText),
            UI.el('td', {}, l.final_physical_qty_base !== null ? UI.formatNumber(l.final_physical_qty_base) : '-'),
            UI.el('td', {}, [
                UI.el('span', { class: `badge ${badgeClassFor(l.match_status, l.is_excluded)}` }, keteranganFor(l)),
                // PHASE V2.14.9.1 — a condition-only disagreement can exist
                // even on a qty MATCH line, so this is a SEPARATE badge,
                // never folded into match_status's own badge.
                l.requires_condition_resolution ? UI.el('span', { class: 'badge badge-void', style: 'margin-left:4px;' }, 'Perlu Resolusi') : null,
            ].filter(Boolean)),
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
        if (l.requires_condition_resolution) {
            const resolveBtn = UI.el('button', { class: 'btn btn-danger btn-sm', style: 'margin-left:6px;' }, 'Resolusi Kondisi');
            resolveBtn.addEventListener('click', () => resolveConditionsItem(session.id, l));
            actionCell.appendChild(resolveBtn);
        }
        // PHASE V2.14.10.1 Gate 5 — review() now only carries a lightweight
        // per-role finding_count (never the full arrays); the button shows
        // whenever either count is non-zero, and the full BOTH-teams
        // history (including voided) is fetched on demand only when a
        // supervisor actually opens the drilldown.
        if ((l.p1_finding_count || 0) > 0 || (l.p2_finding_count || 0) > 0) {
            const findingsBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', style: 'margin-left:6px;' }, 'Riwayat Temuan');
            findingsBtn.addEventListener('click', () => showFindingsDrilldown(session.id, l));
            actionCell.appendChild(findingsBtn);
        }
        cells.push(actionCell);
        const row = UI.el('tr', {}, cells);
        if (l.requires_condition_resolution) row.classList.add('compact-row-discrepancy');
        return row;
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

    // PHASE V2.14.9.1 — explicit supervisor resolution of a Rusak/Expired/
    // Deadstock disagreement (never auto-picks P1 or P2 — the supervisor
    // types the actual final value for each disagreeing field; a field
    // already agreed/resolved is shown as a hint but left editable in case
    // the supervisor wants to override it too).
    async function resolveConditionsItem(sessionId, line) {
        const values = await MasterCommon.formModal({
            title: `Resolusi Kondisi — ${line.sku}`,
            submitLabel: 'Simpan Resolusi',
            initial: {
                final_rusak_qty: line.final_rusak_qty ?? '',
                final_expired_qty: line.final_expired_qty ?? '',
                final_deadstock_qty: line.final_deadstock_qty ?? '',
                final_notes: line.final_notes ?? '',
            },
            fields: [
                { key: 'final_rusak_qty', label: `Final Rusak (P1: ${line.p1_rusak_qty ?? '-'} / P2: ${line.p2_rusak_qty ?? '-'})`, type: 'text' },
                { key: 'final_expired_qty', label: `Final Expired (P1: ${line.p1_expired_qty ?? '-'} / P2: ${line.p2_expired_qty ?? '-'})`, type: 'text' },
                { key: 'final_deadstock_qty', label: `Final Deadstock (P1: ${line.p1_deadstock_qty ?? '-'} / P2: ${line.p2_deadstock_qty ?? '-'})`, type: 'text' },
                { key: 'final_notes', label: 'Keterangan Final (opsional)', type: 'text' },
            ],
        });
        if (!values) return;
        try {
            await InvApi.resolveOpnameConditions(sessionId, line.item_id, {
                final_rusak_qty: values.final_rusak_qty === '' ? null : Number(values.final_rusak_qty),
                final_expired_qty: values.final_expired_qty === '' ? null : Number(values.final_expired_qty),
                final_deadstock_qty: values.final_deadstock_qty === '' ? null : Number(values.final_deadstock_qty),
                final_notes: values.final_notes || null,
            });
            UI.toast('Resolusi kondisi tersimpan.', 'success');
            await renderSession(sessionId);
        } catch (err) { UI.handleApiError(err); }
    }

    // PHASE V2.14.10 — supervisor-only full finding drilldown, BOTH teams,
    // including voided entries — the audit trail (Section "Supervisor
    // View": "P1 finding details / P2 finding details ... who counted each
    // finding / timestamps"). Voiding here is the ONLY correction path —
    // a finding's own quantities are never editable.
    async function showFindingsDrilldown(sessionId, line) {
        let both;
        try {
            both = await InvApi.opnameLineFindingsForSupervisor(sessionId, line.item_id);
        } catch (err) {
            UI.handleApiError(err);
            return;
        }
        function renderTeamFindings(role, findings, overlayRef) {
            if (!findings.length) return UI.el('div', { style: 'color:var(--text3); padding:6px 0;' }, `Belum ada temuan Tim ${role}.`);
            const host = UI.el('div');
            findings.forEach((f, idx) => {
                const rowEl = UI.el('div', { class: `opname-finding-row${f.is_voided ? ' is-voided' : ''}` }, [
                    buildFindingSummaryEl(f, idx, null),
                    UI.el('div', { style: 'color:var(--text3);' }, `oleh ${f.counter_username} — ${f.created_at}${f.is_voided ? ` (VOID: ${f.void_reason})` : ''}`),
                ]);
                if (!f.is_voided) {
                    const voidBtn = UI.el('button', { class: 'btn btn-danger btn-sm' }, 'Void');
                    voidBtn.addEventListener('click', async () => {
                        const reason = await Modal.prompt({ title: `Void Temuan #${f.id}`, label: 'Alasan void (wajib)', required: true });
                        if (reason === null) return;
                        try {
                            await InvApi.voidOpnameFinding(sessionId, f.id, reason);
                            UI.toast('Temuan di-void.', 'success');
                            overlayRef.remove();
                            await renderSession(sessionId);
                        } catch (err) { UI.handleApiError(err); }
                    });
                    rowEl.appendChild(voidBtn);
                }
                host.appendChild(rowEl);
            });
            return host;
        }

        const closeBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Tutup');
        const overlay = UI.el('div', { class: 'modal open' });
        const bodyHost = UI.el('div');
        const content = UI.el('div', { class: 'modal-content' }, [
            UI.el('h3', {}, `Riwayat Temuan — ${line.sku}`),
            bodyHost,
            UI.el('div', { style: 'display:flex; justify-content:flex-end; margin-top:14px;' }, [closeBtn]),
        ]);
        overlay.appendChild(content);
        document.body.appendChild(overlay);
        bodyHost.appendChild(UI.el('div', { class: 'card-title', style: 'font-size:0.9rem;' }, 'Tim P1'));
        bodyHost.appendChild(renderTeamFindings('P1', both.p1 || [], overlay));
        bodyHost.appendChild(UI.el('div', { class: 'card-title', style: 'font-size:0.9rem; margin-top:12px;' }, 'Tim P2'));
        bodyHost.appendChild(renderTeamFindings('P2', both.p2 || [], overlay));
        closeBtn.addEventListener('click', () => overlay.remove());
        overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) overlay.remove(); });
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

    // PHASE V2.14.11.5 — renders a counter's session directly into its OWN
    // dedicated element, entirely separate from the admin "Stock Opname"
    // tab's #opname-body. This is the actual fix for "OPNAME_COUNTER must
    // never visually reach the admin page": the previous implementation
    // reused #tab-opname/#opname-body as the mount point for "Stock Opname
    // Saya", which — on a shared/kiosk browser where a SUPERADMIN had
    // earlier opened the real admin tab in that same page session — left
    // the admin skeleton (including the "Petugas Stock Opname" card)
    // sitting in the DOM and visible once the counter's session got
    // rendered inside it. This function never touches #tab-opname at all,
    // so that DOM simply cannot leak into a counter's view regardless of
    // browser history.
    async function renderCounterSession(mountEl, sessionId) {
        mountEl.innerHTML = '<div class="alert alert-info">Memuat sesi opname...</div>';
        try {
            const session = await InvApi.getOpname(sessionId);
            mountEl.innerHTML = '';
            if (session.role) {
                mountEl.appendChild(buildBlindCountScreen(session));
            } else {
                // Defensive only — renderMySessions() only ever lists
                // sessions where the caller IS that session's assigned P1/P2
                // counter (StockOpnameService::mySessions()), so this
                // should never actually happen; it exists purely so a
                // future mismatch can never fall through to rendering
                // supervisor/admin content in a counter's own tab.
                mountEl.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Sesi ini tidak dapat dibuka sebagai Tim P1/P2.'));
            }
        } catch (err) {
            UI.handleApiError(err);
            mountEl.innerHTML = `<div class="alert alert-error">Gagal memuat sesi: ${(err && err.message) || ''}</div>`;
        }
    }

    // ============================================================
    // PHASE V2.14.10 — "STOCK OPNAME SAYA": deliberately reachable by ANY
    // active user regardless of global role/permission (see index.html's
    // sidebar link — no data-require-permission attribute at all). Lists
    // only sessions where the logged-in user personally has an active
    // team membership, and renders straight into buildBlindCountScreen()
    // via renderCounterSession() above — the SAME counter-screen
    // implementation the admin "Stock Opname" tab uses for its own
    // assigned-counter callers, so there is exactly one implementation,
    // not two.
    //
    // PHASE V2.14.11.5 — "Login -> Progress Tim -> Search Barang ->
    // Counting" with zero extra taps for the common case: mySessions()
    // already scopes to OPEN/FINALIZED sessions only (never POSTED/
    // CANCELLED — see StockOpnameService::mySessions()), so `sessions`
    // here already IS "active assigned sessions", no extra filtering
    // needed. Exactly one -> open it immediately, no intermediate card.
    // Zero -> a plain message. Two or more -> a minimal picker (this
    // should be rare — one physical counter assigned to two concurrent
    // open sessions).
    // ============================================================
    async function renderMySessions(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat sesi Anda...</div>';
        let sessions = [];
        try {
            sessions = await InvApi.myOpnameSessions();
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = '<div class="alert alert-error">Gagal memuat sesi Stock Opname Anda.</div>';
            return;
        }

        container.innerHTML = '';

        if (sessions.length === 0) {
            container.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-title' }, '🙋 Stock Opname Saya'),
                UI.el('div', { class: 'alert alert-info' }, 'Anda belum ditugaskan ke sesi Stock Opname aktif.'),
            ]));
            return;
        }

        const mountEl = UI.el('div', { id: 'opname-saya-session' });

        if (sessions.length === 1) {
            container.appendChild(mountEl);
            await renderCounterSession(mountEl, sessions[0].session_id);
            return;
        }

        container.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-title' }, '🙋 Stock Opname Saya'),
            UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, 'Anda ditugaskan ke lebih dari satu sesi aktif — pilih salah satu untuk mulai menghitung.'),
        ]));
        sessions.forEach((s) => {
            const pct = s.progress.total > 0 ? Math.round((s.progress.counted / s.progress.total) * 100) : 0;
            const card = UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-title' }, s.session_number || `Sesi #${s.session_id}`),
                UI.el('div', {}, `Gudang: ${s.warehouse_name}`),
                UI.el('div', {}, `Tim: ${s.role.toUpperCase()}`),
                UI.el('div', { style: 'margin:6px 0;' }, `Progress Tim: ${s.progress.counted} / ${s.progress.total} (${pct}%)`),
            ]);
            const goBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, s.progress.counted > 0 ? 'Lanjut Hitung' : 'Mulai Hitung');
            goBtn.addEventListener('click', () => renderCounterSession(mountEl, s.session_id));
            card.appendChild(goBtn);
            container.appendChild(card);
        });
        container.appendChild(mountEl);
    }

    return { render, renderMySessions };
})();
