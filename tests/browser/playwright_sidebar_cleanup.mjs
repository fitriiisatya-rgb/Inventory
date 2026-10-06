// Sidebar "Laporan" cleanup — real application, real browser. The Laporan group must list exactly the five approved reports, in order;
// every other report page stays reachable through internal navigation (nothing deleted); active state, role visibility, the collapsed rail
// (flyout), the iPad layouts and the mobile off-canvas menu keep working; navigation never writes. Screenshots -> $SBC_SHOT_DIR.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw SBC_SHOT_DIR=/some/dir node tests/browser/playwright_sidebar_cleanup.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.SBC_SHOT_DIR || __dirname;
fs.mkdirSync(shotDir, { recursive: true });
const dbName = process.env.DB_DATABASE || 'inventory_test';

const results = [];
function check(name, pass, detail = '') { results.push(pass); console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`); }
const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env }).toString();
const sql = (q) => sh(`mysql -uroot ${dbName} -N -e ${JSON.stringify(q)}`).trim();
const near = (a, b, e = 0.01) => Math.abs(Number(a) - Number(b)) <= e;
const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot ${dbName} < database/schema.sql`);
const seed = JSON.parse(sh('php tests/browser/seed_movement_report.php'));
console.log('Seeded', JSON.stringify(seed.wh));

let phpServer; let proxy; let base;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.jpg': 'image/jpeg' };
async function startServer() {
    const phpPort = 9000 + Math.floor(Math.random() * 400);
    phpServer = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', 'public', 'public/router.php'], { cwd: repoRoot, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } });
    let ready = false;
    for (let i = 0; i < 60 && !ready; i++) { await new Promise((r) => setTimeout(r, 200)); try { if ((await fetch(`http://127.0.0.1:${phpPort}/api/auth/me`)).status) ready = true; } catch (e) { /* retry */ } }
    if (!ready) { console.error('php server not ready'); process.exit(1); }
    proxy = http.createServer((req, res) => {
        const urlPath = decodeURIComponent(req.url.split('?')[0]);
        if (urlPath.startsWith('/api/')) {
            const up = http.request({ host: '127.0.0.1', port: phpPort, path: req.url, method: req.method, headers: { ...req.headers, connection: 'close' }, agent: false }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
            up.on('error', () => { res.writeHead(502); res.end(); });
            req.pipe(up);
            return;
        }
        const file = path.join(repoRoot, 'public', urlPath === '/' ? 'index.html' : urlPath);
        if (!file.startsWith(path.join(repoRoot, 'public')) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('nf'); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        fs.createReadStream(file).pipe(res);
    });
    await new Promise((r) => proxy.listen(0, '127.0.0.1', r));
    base = `http://127.0.0.1:${proxy.address().port}`;
}
async function stopServer() { if (proxy) { proxy.closeAllConnections(); proxy.close(); } if (phpServer) { phpServer.kill('SIGKILL'); await new Promise((r) => setTimeout(r, 200)); } }
await startServer();

const consoleErrors = [];
const requests = [];
async function newSession(browser, opts, user) {
    const name = opts.__name || '';
    const context = await browser.newContext(opts);
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${name} ${m.text()}`); });
    page.on('pageerror', (e) => consoleErrors.push(`${name} ${String(e)}`));
    page.on('request', (r) => { if (r.url().includes('/api/') && !r.url().includes('/auth/')) requests.push({ method: r.method(), path: new URL(r.url()).pathname.replace(/^\/api/, '') }); });
    await page.goto(base + '/', { waitUntil: 'load', timeout: 20000 });
    await page.fill('#login-username', user.username);
    await page.fill('#login-password', user.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 10000 });
    return { context, page };
}
const FINAL = [
    ['laporan-pergerakan', 'Laporan Pergerakan Stok'],
    ['laporan-inout', 'Laporan IN / OUT'],
    ['laporan-pembelian', 'Laporan Pembelian'],
    ['laporan-hpp', 'Laporan Nilai HPP'],
    ['laporan-opname', 'Laporan Stock Opname'],
];
const REMOVED = ['Ringkasan Inventory', 'Laporan Stok', 'Adjustment / Selisih', 'Expired / Near Expired', 'Pembelian per Supplier', 'Distribusi per Bakery',
    'Slow / No Movement', 'Rekonsiliasi Arus Stok', 'Audit Transaksi', 'Laporan Transfer', 'Laporan P1/P2 Stock Opname', 'Pergerakan Stok Harian', 'Nilai Stok & HPP'];
const LEGACY = ['laporan-ringkasan', 'laporan-stok', 'laporan-transfer', 'opname-laporan', 'laporan-adjustment', 'laporan-expiry', 'laporan-supplier', 'laporan-bakery', 'laporan-slow-movement', 'laporan-rekonsiliasi', 'laporan-audit'];
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `sidebar-${name}.png`) });
const clean = (s) => s.replace(/\s+/g, ' ').trim().replace(/^[^A-Za-z]+/, '');
const laporanLinks = (page, scope = '[data-group="laporan"] .sidebar-submenu') => page.locator(`${scope} .sidebar-link:visible`);
const labelsOf = async (loc) => (await loc.allInnerTexts()).map(clean);
async function openGroup(page) {
    const open = await page.locator('[data-group="laporan"] .sidebar-group-header').getAttribute('aria-expanded');
    if (open !== 'true') await page.click('[data-group="laporan"] .sidebar-group-header');
    await page.waitForFunction(() => !document.querySelector('[data-group="laporan"] .sidebar-submenu').hidden);
}
const activeState = (page) => page.evaluate(() => {
    const act = Array.from(document.querySelectorAll('#sidebar .sidebar-link.active')).map((a) => a.dataset.tab);
    const g = document.querySelector('[data-group="laporan"]');
    return { act, hasActive: g.classList.contains('has-active'), expanded: g.querySelector('.sidebar-group-header').getAttribute('aria-expanded'), visibleActive: Array.from(document.querySelectorAll('#sidebar .sidebar-link.active')).filter((a) => a.getClientRects().length > 0).length };
});

let browser;
try {
    browser = await chromium.launch();

    // ---- desktop, expanded
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin' }, seed.admin);
    await page.evaluate(() => { try { localStorage.setItem('inv_sidebar_collapsed', '0'); } catch (e) { /* */ } });
    await page.reload(); await page.waitForSelector('#app-shell', { state: 'visible' });
    await openGroup(page);
    const labels = await labelsOf(laporanLinks(page));
    check('A the Laporan group has exactly 5 visible submenu items', labels.length === 5, labels.join(' | '));
    check('B exact labels', FINAL.every(([, l]) => labels.includes(l)));
    check('C order is exactly Pergerakan Stok, IN / OUT, Pembelian, Nilai HPP, Stock Opname', JSON.stringify(labels) === JSON.stringify(FINAL.map((f) => f[1])), labels.join(' | '));
    const sidebarVisible = (await labelsOf(page.locator('#sidebar .sidebar-link:visible'))).concat(await labelsOf(page.locator('#sidebar .sidebar-group-header:visible')));
    check('D none of the removed labels is visible anywhere in the sidebar', REMOVED.every((r) => !sidebarVisible.includes(r)), sidebarVisible.filter((s) => REMOVED.includes(s)).join(','));
    check('D "Laporan Transfer" is not a sidebar item (it lives under Laporan IN / OUT)', !sidebarVisible.includes('Laporan Transfer'));
    check('D the legacy links are kept in the DOM but hidden (container hidden, aria-hidden, no focus stop)', await page.locator('[data-testid="sidebar-legacy-routes"] .sidebar-link').count() === LEGACY.length && !(await page.locator('[data-testid="sidebar-legacy-routes"]').isVisible()) && await page.locator('[data-testid="sidebar-legacy-routes"]').getAttribute('aria-hidden') === 'true');
    const opnameGroup = await labelsOf(page.locator('[data-group="opname"] .sidebar-link'));
    check('Stock Opname group keeps "Proses Stock Opname" + "Stock Opname Saya" (report link moved to Laporan, one link per tab)', opnameGroup.join('|') === 'Proses Stock Opname|Stock Opname Saya', opnameGroup.join('|'));
    check('no duplicate data-tab among the sidebar links', await page.evaluate(() => { const t = Array.from(document.querySelectorAll('#sidebar .sidebar-link')).map((a) => a.dataset.tab); return t.length === new Set(t).size; }));
    await shot(page, 'desktop-expanded');

    // ---- each item opens its page; active state
    for (const [tab, label] of FINAL) {
        await openGroup(page);
        await page.locator(`[data-group="laporan"] .sidebar-link[data-tab="${tab}"]`).click();
        await page.waitForSelector(`#tab-${tab}.active`);
        await page.waitForFunction((t) => document.getElementById(`tab-${t}`).children.length > 0, tab, { timeout: 15000 });
        await page.waitForTimeout(500);
        const st = await activeState(page);
        const hl = await labelsOf(page.locator('#sidebar .sidebar-link.active:visible'));
        check(`E/F/G "${label}" opens #tab-${tab}; parent LAPORAN expanded + has-active; only that item highlighted`, st.act.length === 1 && st.act[0] === tab && st.hasActive && st.expanded === 'true' && st.visibleActive === 1 && hl.join() === label, JSON.stringify(st) + ' ' + hl.join());
        const bc = (await page.locator('#breadcrumb-current').innerText()).trim();
        check(`E breadcrumb for ${tab} is not empty / not a raw key`, bc.length > 0 && bc !== tab, bc);
    }

    // ---- removed pages are NOT gone: internal navigation still works, nothing highlighted
    for (const tab of LEGACY) {
        await page.evaluate((t) => InvNav.goToTab(t), tab);
        await page.waitForSelector(`#tab-${tab}.active`, { timeout: 8000 }).catch(() => {});
        const on = await page.locator(`#tab-${tab}.active`).count();
        await page.waitForTimeout(250);
        const st = await activeState(page);
        check(`L legacy page ${tab} still reachable through InvNav; no visible sidebar item highlighted`, on === 1 && st.visibleActive === 0, JSON.stringify(st));
    }
    await page.evaluate(() => { try { sessionStorage.setItem('inv_active_tab', 'laporan-transfer'); } catch (e) { /* */ } });
    await page.reload(); await page.waitForSelector('#app-shell', { state: 'visible' });
    await page.waitForSelector('#tab-laporan-transfer.active', { timeout: 8000 });
    check('L a restored (sessionStorage) legacy tab still renders after reload', await page.locator('#tab-laporan-transfer.active').count() === 1);
    // dashboard drill-down links keep working (they click data-tab links)
    await page.evaluate(() => InvNav.goToTab('dashboard')); await page.waitForTimeout(800);

    // ---- collapsed rail (desktop)
    await page.click('#sidebar-toggle-btn');
    await page.waitForSelector('#sidebar.sidebar-collapsed');
    await page.click('[data-group="laporan"] .sidebar-group-header');
    await page.waitForSelector('[data-group="laporan"].flyout');
    const flyLabels = await labelsOf(laporanLinks(page));
    check('H collapsed rail: the Laporan flyout lists exactly the 5 reports in order', JSON.stringify(flyLabels) === JSON.stringify(FINAL.map((f) => f[1])), flyLabels.join(' | '));
    await shot(page, 'desktop-collapsed');
    await laporanLinks(page).nth(1).click();
    await page.waitForSelector('#tab-laporan-inout.active');
    check('H collapsed rail: choosing a flyout item navigates and closes the flyout', await page.locator('[data-group="laporan"].flyout').count() === 0 && (await activeState(page)).act.join() === 'laporan-inout');
    await page.click('#sidebar-toggle-btn');
    await page.waitForFunction(() => !document.getElementById('sidebar').classList.contains('sidebar-collapsed'));
    check('H expanding the rail again keeps the 5 items and the active highlight', (await labelsOf(laporanLinks(page))).length === 5 && (await activeState(page)).act.join() === 'laporan-inout');
    check('J/K no console errors, no write request during navigation (so far)', consoleErrors.length === 0 && requests.every((r) => r.method === 'GET'), consoleErrors.slice(0, 3).join(' || ') + requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
    await context.close();

    // ---- role visibility: viewer / stock keep their own permission filtering
    for (const [name, user] of [['viewer', seed.viewer], ['stockA', seed.stockA]]) {
        const s = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: name }, user);
        const grp = s.page.locator('[data-group="laporan"]');
        const shown = await grp.isVisible();
        if (shown) await openGroup(s.page);
        const ls = shown ? await labelsOf(laporanLinks(s.page)) : [];
        const expected = await s.page.evaluate((fin) => fin.filter(([tab]) => { const a = document.querySelector(`#sidebar [data-tab="${tab}"]`); return a && a.style.display !== 'none'; }).map((f) => f[1]), FINAL);
        check(`permissions (${name}): visible Laporan items == the approved reports this role may use (${ls.length}); never a removed one`, JSON.stringify(ls) === JSON.stringify(expected) && ls.every((l) => FINAL.some((f) => f[1] === l)), `${ls.join('|')} vs ${expected.join('|')}`);
        await s.context.close();
    }

    // ---- iPad landscape / portrait
    for (const [name, w, h] of [['ipad-landscape', 1180, 820], ['ipad-portrait', 820, 1180], ['laptop', 1366, 768]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        const offCanvas = w <= 900; // <= 900px is the off-canvas drawer (iPad portrait 820px included)
        if (offCanvas) { await s.page.click('#sidebar-toggle-btn'); await s.page.waitForSelector('#sidebar.open'); }
        const collapsed = await s.page.evaluate(() => document.getElementById('sidebar').classList.contains('sidebar-collapsed'));
        if (collapsed) { await s.page.click('[data-group="laporan"] .sidebar-group-header'); await s.page.waitForSelector('[data-group="laporan"].flyout'); } else { await openGroup(s.page); }
        const l = await labelsOf(laporanLinks(s.page));
        check(`T ${name} (${w}x${h}, ${offCanvas ? 'off-canvas' : collapsed ? 'rail + flyout' : 'expanded'}): Laporan has exactly 5 items in order`, JSON.stringify(l) === JSON.stringify(FINAL.map((f) => f[1])), l.join(' | '));
        check(`T ${name}: no horizontal overflow`, (await s.page.evaluate(() => document.documentElement.scrollWidth - innerWidth)) <= 0);
        if (name === 'ipad-landscape') await shot(s.page, 'ipad-landscape');
        if (name === 'ipad-portrait') await shot(s.page, 'ipad-portrait');
        await s.context.close();
    }

    // ---- mobile off-canvas
    const m = await newSession(browser, { viewport: { width: 390, height: 844 }, __name: 'mobile' }, seed.admin);
    check('I mobile: the sidebar starts closed (off-canvas)', await m.page.evaluate(() => !document.getElementById('sidebar').classList.contains('open')));
    await m.page.click('#sidebar-toggle-btn');
    await m.page.waitForSelector('#sidebar.open');
    await openGroup(m.page);
    const ml = await labelsOf(laporanLinks(m.page));
    check('I mobile: opening the menu + Laporan shows exactly the 5 reports in order', JSON.stringify(ml) === JSON.stringify(FINAL.map((f) => f[1])), ml.join(' | '));
    await shot(m.page, 'mobile');
    await laporanLinks(m.page).nth(2).click();
    await m.page.waitForSelector('#tab-laporan-pembelian.active');
    await m.page.waitForFunction(() => !document.getElementById('sidebar').classList.contains('open'));
    check('I mobile: choosing an item opens the page and closes the drawer; item highlighted', (await activeState(m.page)).act.join() === 'laporan-pembelian');
    await m.context.close();

    check('J no console / page errors in any session', consoleErrors.length === 0, consoleErrors.slice(0, 4).join(' || '));
    check('K only GET requests left the browser (navigation never writes)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
