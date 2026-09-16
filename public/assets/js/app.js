/**
 * App bootstrap: session resume, login form, tab navigation. sessionStorage
 * is used ONLY to remember which tab was open (cosmetic UI state) — never
 * for inventory/master/session data, which always comes fresh from the API.
 */
(() => {
    const loginScreen = document.getElementById('login-screen');
    const appShell = document.getElementById('app-shell');
    const loginForm = document.getElementById('login-form');
    const loginAlert = document.getElementById('login-alert');
    const loginSubmit = document.getElementById('login-submit');

    function showLogin() {
        loginScreen.style.display = 'block';
        appShell.style.display = 'none';
    }

    async function showApp() {
        loginScreen.style.display = 'none';
        appShell.style.display = 'block';
        const user = Auth.user();
        document.getElementById('username-chip').textContent = user.username;
        document.getElementById('role-badge').textContent = user.role_code;
        Auth.applyRoleVisibility();
        try {
            await Master.loadAll();
        } catch (err) {
            UI.handleApiError(err);
        }
        const savedTab = sessionStorage.getItem('inv_active_tab') || 'dashboard';
        activateTab(savedTab);
    }

    function activateTab(name) {
        document.querySelectorAll('.tab-btn').forEach((btn) => btn.classList.toggle('active', btn.dataset.tab === name));
        document.querySelectorAll('.tab-content').forEach((el) => el.classList.toggle('active', el.id === `tab-${name}`));
        try { sessionStorage.setItem('inv_active_tab', name); } catch (e) { /* ignore, cosmetic only */ }

        if (name === 'dashboard') {
            Dashboard.render(document.getElementById('tab-dashboard'));
        } else if (name === 'master') {
            renderMasterTab(document.getElementById('tab-master'));
        } else if (name === 'laporan') {
            Reports.render(document.getElementById('tab-laporan'));
        } else if (name === 'transaksi') {
            Transactions.render(document.getElementById('tab-transaksi'));
        }
    }

    function renderMasterTab(container) {
        const section = (title, rows, columns) => UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, title)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, columns.map((c) => UI.el('th', {}, c)))]),
                    UI.el('tbody', {}, rows.length
                        ? rows.map((r) => UI.el('tr', {}, columns.map((c, i) => UI.el('td', {}, String(r[i] ?? '-')))))
                        : [UI.el('tr', {}, [UI.el('td', { colspan: String(columns.length) }, 'Belum ada data')])]),
                ]),
            ]),
        ]);

        container.innerHTML = '';
        container.appendChild(section('Barang (Items)', Master.items().map((i) => [i.sku, i.name, i.category, i.status === 'ACTIVE' ? 'Aktif' : 'Nonaktif']), ['SKU', 'Nama', 'Kategori', 'Status']));
        container.appendChild(section('Gudang (Warehouses)', Master.warehouses().map((w) => [w.code, w.name]), ['Kode', 'Nama']));
        container.appendChild(section('Supplier', Master.suppliers().map((s) => [s.code, s.name]), ['Kode', 'Nama']));
        container.appendChild(section('Divisi', Master.divisions().map((d) => [d.code, d.name]), ['Kode', 'Nama']));
    }

    document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });

    document.getElementById('logout-btn').addEventListener('click', async () => {
        await Auth.logout();
        showLogin();
    });

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
            loginAlert.innerHTML = '';
            loginAlert.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Login gagal'));
        } finally {
            loginSubmit.disabled = false;
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
