/**
 * App bootstrap: session resume, login form, tab navigation. sessionStorage
 * is used ONLY to remember which tab was open (cosmetic UI state) — never
 * for inventory/master/session data, which always comes fresh from the API.
 */
(() => {
    const loginScreen = document.getElementById('login-screen');
    const appShell = document.getElementById('app-shell');
    const changePasswordScreen = document.getElementById('change-password-screen');
    const loginForm = document.getElementById('login-form');
    const loginAlert = document.getElementById('login-alert');
    const loginSubmit = document.getElementById('login-submit');
    const changePasswordForm = document.getElementById('change-password-form');
    const changePasswordAlert = document.getElementById('change-password-alert');
    const changePasswordSubmit = document.getElementById('change-password-submit');
    let pendingStockReportFilters = null;

    function showLogin() {
        loginScreen.style.display = 'block';
        changePasswordScreen.style.display = 'none';
        appShell.style.display = 'none';
    }

    function showChangePassword() {
        loginScreen.style.display = 'none';
        changePasswordScreen.style.display = 'block';
        appShell.style.display = 'none';
    }

    async function showApp() {
        // PHASE V2.2 hardening: Auth.user() can legitimately be null here if
        // session resume raced or failed silently — never assume it's set.
        // (Auth.login() itself now throws before this is reached if the
        // session truly never got established; this guard covers the
        // separate init()/tryResumeSession() path.)
        const user = Auth.user();
        if (!user) {
            loginAlert.innerHTML = '';
            loginAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Sesi login tidak berhasil dibuat. Silakan coba kembali.'));
            showLogin();
            return;
        }
        if (user.must_change_password) {
            showChangePassword();
            return;
        }
        loginScreen.style.display = 'none';
        changePasswordScreen.style.display = 'none';
        appShell.style.display = 'block';
        document.getElementById('username-chip').textContent = user.username;
        document.getElementById('role-badge').textContent = user.role_code;
        Auth.applyRoleVisibility();
        // PHASE V2.16.3 — PRODUCTION SAFETY FIX: OPNAME_COUNTER intentionally
        // holds zero global permissions (its authority comes entirely from
        // its own session-scoped team assignment — see StockOpnameService's
        // claim/finding methods), so loading the shared Master cache for
        // this role hits at least one INVENTORY_VIEW-gated route (GET
        // /item-barcodes) and fails with "Missing permission:
        // INVENTORY_VIEW". The counter's own screens (Stock Opname Saya /
        // buildBlindCountScreen) get their SKU/name/category/status
        // directly from getForCounter()'s own per-line payload, never from
        // Master — so this role never needs the shared cache at all.
        if (user.role_code !== 'OPNAME_COUNTER') {
            try {
                await Master.loadAll();
            } catch (err) {
                UI.handleApiError(err);
            }
        }
        // PHASE V2.14.11.3 — URGENT HOTFIX: a Petugas Stock Opname
        // (OPNAME_COUNTER) must land DIRECTLY on "Stock Opname Saya"
        // every time, never Dashboard — even if a stale sessionStorage
        // tab from a previous session (or a different role's login on
        // this same browser) would otherwise reopen something this role
        // cannot see. The sidebar itself already hides every other tab
        // for this role (see index.html's data-require-permission
        // attributes + Auth.applyRoleVisibility() above), but the
        // landing tab is decided here explicitly rather than trusted to
        // "whatever was last open".
        if (user.role_code === 'OPNAME_COUNTER') {
            activateTab('opname-saya');
            return;
        }
        const savedTab = sessionStorage.getItem('inv_active_tab') || 'dashboard';
        activateTab(savedTab);
    }

    const TAB_LABELS = {
        dashboard: 'Dashboard', 'stok-barang': 'Stok Barang',
        'master-item': 'Master Barang', 'master-warehouse': 'Master Gudang', 'warehouse-cutover': 'Karang Tengah Cutover', 'master-division': 'Master Divisi',
        'master-vendor': 'Vendor / Supplier', 'master-bakery': 'Bakery Tujuan', 'master-category': 'Kategori',
        'trace-center': 'Trace Center',
        laporan: 'Mutasi Stok / Ledger', 'history-transaksi': 'History Transaksi', transaksi: 'Stock IN / OUT',
        transfer: 'Transfer', 'distribusi-do': 'Delivery Order', 'distribusi-invoice': 'Invoice', 'distribusi-pricing': 'Pricing Distribusi', 'distribusi-laporan': 'Laporan Distribusi', produksi: 'Produksi', opname: 'Proses Stock Opname', 'opname-laporan': 'Laporan Stock Opname', import: 'Import',
        audit: 'Audit Log', closing: 'Tutup Buku', 'laporan-hpp': 'Laporan Nilai Stok & HPP',
        'laporan-ringkasan': 'Ringkasan Inventory',
        'laporan-pergerakan': 'Pergerakan Stok Harian', 'laporan-rekonsiliasi': 'Rekonsiliasi Arus Stok',
        'laporan-stok': 'Laporan Stok', 'laporan-pembelian': 'Laporan Pembelian', 'laporan-inout': 'Laporan IN / OUT',
        'laporan-transfer': 'Laporan Transfer', 'laporan-opname': 'Laporan P1/P2 Stock Opname',
        'laporan-adjustment': 'Adjustment / Selisih', 'laporan-expiry': 'Expired / Near Expired',
        'laporan-supplier': 'Pembelian per Supplier', 'laporan-bakery': 'Distribusi per Bakery',
        'laporan-slow-movement': 'Slow / No Movement', 'laporan-audit': 'Audit Transaksi',
    };

    function activateTab(name) {
        document.querySelectorAll('.sidebar-link').forEach((link) => link.classList.toggle('active', link.dataset.tab === name));
        document.querySelectorAll('.tab-content').forEach((el) => el.classList.toggle('active', el.id === `tab-${name}`));
        const breadcrumbCurrent = document.getElementById('breadcrumb-current');
        if (breadcrumbCurrent) breadcrumbCurrent.textContent = TAB_LABELS[name] || name;
        try { sessionStorage.setItem('inv_active_tab', name); } catch (e) { /* ignore, cosmetic only */ }

        if (name === 'dashboard') {
            Dashboard.render(document.getElementById('tab-dashboard'));
        } else if (name === 'stok-barang') {
            StockReport.render(document.getElementById('tab-stok-barang'), pendingStockReportFilters || undefined);
            pendingStockReportFilters = null;
        } else if (name === 'master-item') {
            MasterItems.render(document.getElementById('tab-master-item'));
        } else if (name === 'master-warehouse') {
            MasterWarehouses.render(document.getElementById('tab-master-warehouse'));
        } else if (name === 'warehouse-cutover') {
            WarehouseCutover.render(document.getElementById('tab-warehouse-cutover'));
        } else if (name === 'master-division') {
            MasterDivisions.render(document.getElementById('tab-master-division'));
        } else if (name === 'master-vendor') {
            MasterVendors.render(document.getElementById('tab-master-vendor'));
        } else if (name === 'master-bakery') {
            MasterBakeryDestinations.render(document.getElementById('tab-master-bakery'));
        } else if (name === 'master-category') {
            MasterCategories.render(document.getElementById('tab-master-category'));
        } else if (name === 'laporan') {
            Reports.render(document.getElementById('tab-laporan'));
        } else if (name === 'history-transaksi') {
            TransactionHistory.render(document.getElementById('tab-history-transaksi'));
        } else if (name === 'transaksi') {
            const tabEl = document.getElementById('tab-transaksi');
            Transactions.render(tabEl);
            Adjustments.render(tabEl);
        } else if (name === 'transfer') {
            Transfers.render(document.getElementById('tab-transfer'));
        } else if (name === 'distribusi-do') {
            DistributionOrders.render(document.getElementById('tab-distribusi-do'));
        } else if (name === 'distribusi-invoice') {
            DistributionInvoices.render(document.getElementById('tab-distribusi-invoice'));
        } else if (name === 'distribusi-pricing') {
            DistributionPricing.render(document.getElementById('tab-distribusi-pricing'));
        } else if (name === 'distribusi-laporan') {
            DistributionReports.render(document.getElementById('tab-distribusi-laporan'));
        } else if (name === 'produksi') {
            Production.render(document.getElementById('tab-produksi'));
        } else if (name === 'opname') {
            // PHASE V2.14.11.5 — a Petugas Stock Opname (OPNAME_COUNTER)
            // must never render the Stock Opname ADMIN tab (Petugas
            // Stock Opname management, warehouse session selector, P1/P2
            // assignment, supervisor comparison, cancel/finalize) even if
            // something ever calls activateTab('opname') for this role —
            // the sidebar link is already hidden and login already lands
            // on 'opname-saya', but this is a second, independent guard at
            // the one place that actually renders that admin UI. Backend
            // 403s on every admin route regardless; this is cosmetic
            // defense-in-depth only.
            const activeUser = Auth.user();
            if (activeUser && activeUser.role_code === 'OPNAME_COUNTER') {
                activateTab('opname-saya');
                return;
            }
            StockOpname.render(document.getElementById('tab-opname'));
        } else if (name === 'opname-laporan') {
            StockOpnameReport.render(document.getElementById('tab-opname-laporan'));
        } else if (name === 'opname-saya') {
            StockOpname.renderMySessions(document.getElementById('tab-opname-saya'));
        } else if (name === 'import') {
            Imports.render(document.getElementById('tab-import'));
        } else if (name === 'audit') {
            Audit.render(document.getElementById('tab-audit'));
        } else if (name === 'trace-center') {
            TraceCenter.render(document.getElementById('tab-trace-center'));
        } else if (name === 'closing') {
            Closing.render(document.getElementById('tab-closing'));
        } else if (name === 'laporan-hpp') {
            ReportHpp.render(document.getElementById('tab-laporan-hpp'));
        } else if (name === 'laporan-ringkasan') {
            ReportSummary.render(document.getElementById('tab-laporan-ringkasan'));
        } else if (name === 'laporan-pergerakan') {
            ReportMovement.render(document.getElementById('tab-laporan-pergerakan'));
        } else if (name === 'laporan-rekonsiliasi') {
            ReportReconciliation.render(document.getElementById('tab-laporan-rekonsiliasi'));
        } else if (name === 'laporan-stok') {
            ReportStock.render(document.getElementById('tab-laporan-stok'));
        } else if (name === 'laporan-pembelian') {
            ReportPurchase.render(document.getElementById('tab-laporan-pembelian'));
        } else if (name === 'laporan-inout') {
            ReportInOut.render(document.getElementById('tab-laporan-inout'));
        } else if (name === 'laporan-transfer') {
            ReportTransferList.render(document.getElementById('tab-laporan-transfer'));
        } else if (name === 'laporan-opname') {
            ReportOpname.render(document.getElementById('tab-laporan-opname'));
        } else if (name === 'laporan-adjustment') {
            ReportAdjustment.render(document.getElementById('tab-laporan-adjustment'));
        } else if (name === 'laporan-expiry') {
            ReportExpiry.render(document.getElementById('tab-laporan-expiry'));
        } else if (name === 'laporan-supplier') {
            ReportSupplier.render(document.getElementById('tab-laporan-supplier'));
        } else if (name === 'laporan-bakery') {
            ReportBakery.render(document.getElementById('tab-laporan-bakery'));
        } else if (name === 'laporan-slow-movement') {
            ReportSlowMovement.render(document.getElementById('tab-laporan-slow-movement'));
        } else if (name === 'laporan-audit') {
            ReportAudit.render(document.getElementById('tab-laporan-audit'));
        }
    }

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => activateTab(link.dataset.tab));
    });

    // PHASE V2.2 — used by Dashboard's clickable Need Attention cards to
    // drill into Stok Barang pre-filtered exactly as the backend already
    // computes status (never a frontend-recomputed guess), and by
    // TraceDrawer's contextual navigation links (Full Ledger / History
    // Transaksi / Detail Barang). Deliberately a small, explicit surface —
    // not a general router — since only these two callers need it.
    window.InvNav = {
        goToStockReport(filters) {
            pendingStockReportFilters = filters || null;
            document.querySelector('[data-tab="stok-barang"]')?.click();
        },
        goToTab(tabName) {
            document.querySelector(`[data-tab="${tabName}"]`)?.click();
        },
    };

    document.getElementById('logout-btn').addEventListener('click', async () => {
        await Auth.logout();
        showLogin();
    });

    // PHASE V2.2 — never show a raw JS/PHP error to the user (e.g. "null is
    // not an object", a stack trace, or a bare technical API message).
    // Known cases get an exact friendly Indonesian message; anything else
    // falls back to a generic one. Technical detail can still be logged to
    // the console for support/debugging, just never rendered into the DOM.
    function friendlyLoginError(err) {
        const code = err && err.code;
        if (code === 'UNAUTHENTICATED') return 'Username atau password tidak sesuai.';
        if (code === 'SESSION_NOT_ESTABLISHED') return 'Sesi login tidak berhasil dibuat.\nSilakan coba kembali.';
        if (code === 'NETWORK_ERROR') return 'Tidak dapat terhubung ke server.\nSilakan coba kembali.';
        if (code === 'RATE_LIMITED' || code === 'TOO_MANY_ATTEMPTS') return 'Terlalu banyak percobaan login. Silakan coba lagi beberapa saat lagi.';
        if (err && typeof err.message === 'string' && err.message.trim() !== '') return err.message;
        return 'Login gagal. Silakan coba kembali.';
    }

    loginForm.addEventListener('submit', async (evt) => {
        evt.preventDefault();
        loginAlert.innerHTML = '';
        loginSubmit.disabled = true;
        try {
            const username = document.getElementById('login-username').value.trim();
            const password = document.getElementById('login-password').value;
            await Auth.login(username, password);
            await showApp();
        } catch (err) {
            if (err && err.code !== 'NETWORK_ERROR') console.error('Login failed:', err);
            loginAlert.innerHTML = '';
            loginAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, friendlyLoginError(err)));
        } finally {
            loginSubmit.disabled = false;
        }
    });

    // Show/hide password toggle on the redesigned login card.
    const loginPasswordInput = document.getElementById('login-password');
    const loginPasswordToggle = document.getElementById('login-password-toggle');
    if (loginPasswordInput && loginPasswordToggle) {
        loginPasswordToggle.addEventListener('click', () => {
            const showing = loginPasswordInput.type === 'text';
            loginPasswordInput.type = showing ? 'password' : 'text';
            loginPasswordToggle.textContent = showing ? '👁' : '🙈';
            loginPasswordToggle.setAttribute('aria-label', showing ? 'Tampilkan password' : 'Sembunyikan password');
        });
    }
    const loginYearEl = document.getElementById('login-year');
    if (loginYearEl) loginYearEl.textContent = String(new Date().getFullYear());

    changePasswordForm.addEventListener('submit', async (evt) => {
        evt.preventDefault();
        changePasswordAlert.innerHTML = '';
        changePasswordSubmit.disabled = true;
        try {
            const current = document.getElementById('change-password-current').value;
            const next = document.getElementById('change-password-new').value;
            await InvApi.changePassword(current, next);
            UI.toast('Password berhasil diganti. Silakan login ulang dengan password baru.', 'success');
            await Auth.logout();
            document.getElementById('change-password-current').value = '';
            document.getElementById('change-password-new').value = '';
            showLogin();
        } catch (err) {
            changePasswordAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal mengganti password'));
        } finally {
            changePasswordSubmit.disabled = false;
        }
    });

    (async function init() {
        const resumed = await Auth.tryResumeSession();
        if (resumed) {
            await showApp();
        } else {
            showLogin();
        }
    })();
})();
