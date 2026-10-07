// UI2 — dashboard typography/density refinement + collapsible sidebar, through the real UI.
//
// Same harness as playwright_dashboard_real_data.mjs (real MariaDB + PHP API + Chromium). A "big"
// session rewrites the dashboard payload inside the browser to 10-digit Rupiah figures purely to
// stress the layout (nothing is sent to / stored on the server). Checks: type scale per viewport,
// density, 4-up / 2x2 grids, no overflow/overlap, compact Rupiah fallback (exact value kept),
// sidebar expanded/rail/drawer, persistence, tooltips, flyouts, keyboard, other pages unharmed,
// drill-down + warehouse selector still work, no console errors. Screenshots -> $DASH_SHOT_DIR.
//
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw DASH_SHOT_DIR=/some/dir \
//     node tests/browser/playwright_ui2_dashboard_sidebar.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.DASH_SHOT_DIR || __dirname;
fs.mkdirSync(shotDir, { recursive: true });
const dbName = process.env.DB_DATABASE || 'inventory_test';

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}
const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env }).toString();
const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const close2 = (a, b) => Math.abs(Number(a) - Number(b)) < 0.011;

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot ${dbName} < database/schema.sql`);
const seed = JSON.parse(sh('php tests/browser/seed_dashboard_real.php'));
console.log('Seeded warehouses', JSON.stringify(seed.wh), 'today', seed.today);

let phpServer; let proxy; let base;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
async function startServer() {
    const phpPort = 9000 + Math.floor(Math.random() * 400);
    phpServer = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', 'public', 'public/router.php'], { cwd: repoRoot, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } });
    let ready = false;
    for (let i = 0; i < 60 && !ready; i++) {
        await new Promise((r) => setTimeout(r, 200));
        try { if ((await fetch(`http://127.0.0.1:${phpPort}/api/auth/me`)).status) ready = true; } catch (e) { /* retry */ }
    }
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
async function stopServer() {
    if (proxy) { proxy.closeAllConnections(); proxy.close(); }
    if (phpServer) { phpServer.kill('SIGKILL'); await new Promise((r) => setTimeout(r, 200)); }
}
await startServer();

const consoleErrors = [];
const requests = [];
async function newSession(browser, contextOptions, user) {
    const name = contextOptions.__name || '';
    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();
    if (contextOptions.__big) {
        // UI-only stress: the dashboard payload is rewritten IN THE BROWSER to large Rupiah figures; nothing is sent to or stored on the server.
        await page.route('**/api/dashboard/inventory?*', async (route) => {
            const resp = await route.fetch();
            const j = await resp.json();
            const d = j.data;
            d.summary.stock_value.total = 2233226305.92;
            d.movement.opening_stock.value = 2200000000.5; d.movement.stock_in.value = 133226305.92;
            d.movement.stock_out.value = 99999999.99; d.movement.closing_stock.value = 2233226305.92;
            await route.fulfill({ response: resp, json: j });
        });
    }
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${name} ${m.text()}`); });
    page.on('pageerror', (e) => consoleErrors.push(`${name} ${String(e)}`));
    page.on('request', (r) => { if (r.url().includes('/api/') && !r.url().includes('/auth/')) requests.push({ method: r.method(), path: new URL(r.url()).pathname.replace(/^\/api/, ''), url: r.url() }); });
    await page.goto(base + '/', { waitUntil: 'load', timeout: 20000 });
    await page.fill('#login-username', user.username);
    await page.fill('#login-password', user.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 10000 });
    await waitDash(page);
    return { context, page };
}
async function waitDash(page) {
    await page.waitForSelector('[data-testid="dash-movement"]', { timeout: 20000 });
    await page.waitForFunction(() => !document.querySelector('#dash-body.dash-loading') && !/Memuat dashboard/.test(document.querySelector('#dash-body')?.innerText || ''), null, { timeout: 20000 });
}
const api = (page, p, params) => page.evaluate(async ([pp, qq]) => {
    const qs = new URLSearchParams(qq).toString();
    return (await (await fetch(`/api${pp}?${qs}`, { credentials: 'include' })).json()).data;
}, [p, params]);
const txt = async (page, tid) => (await page.locator(`[data-testid="${tid}"]`).first().innerText()).replace(/\s+/g, ' ').trim();
const mvText = async (page, key) => txt(page, `dash-mv-${key}-value`);
async function selectWh(page, value) {
    await page.selectOption('[data-testid="dash-warehouse"]', value);
    await waitDash(page);
}
async function setPeriod(page, key) {
    await page.click(`[data-testid="dash-period-${key}"]`);
    await waitDash(page);
}
async function openCard(page, key) {
    await page.click(`[data-testid="dash-mv-${key}"]`);
    await page.waitForSelector('.drawer.open [data-dash-title]', { timeout: 5000 });
    await page.waitForSelector('[data-testid="dash-drawer-table"], [data-testid="dash-drawer-error"]', { timeout: 15000 });
}
async function closeDrawer(page) {
    await page.click('.drawer-close');
    await page.waitForTimeout(250);
}
async function sumAllPages(page, params, type) {
    return page.evaluate(async ([pp, t]) => {
        let sum = 0; let rows = 0; let pg = 1; let total = 0;
        for (;;) {
            const qs = new URLSearchParams({ ...pp, type: t, page: String(pg), per_page: '100' }).toString();
            const d = (await (await fetch(`/api/dashboard/inventory/detail?${qs}`, { credentials: 'include' })).json()).data;
            d.rows.forEach((r) => { sum += Number(r.value); rows += 1; });
            total = d.grand_total.value;
            if (pg >= d.pagination.total_pages) break;
            pg += 1;
        }
        return { sum, rows, grand: total };
    }, [params, type]);
}

const baseP = (extra = {}) => ({ warehouse_id: '', period: 'month', ...extra });
const shot = (page, n) => page.screenshot({ path: path.join(shotDir, `ui2-${n}.png`) });
const sidebarW = (page) => page.evaluate(() => Math.round(document.getElementById('sidebar').getBoundingClientRect().width));
const contentLeft = (page) => page.evaluate(() => Math.round(document.querySelector('.app-content-v2').getBoundingClientRect().left));
const px = (s) => parseFloat(s);
const hOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const fonts = (page) => page.evaluate(() => {
    const f = (s) => { const e = document.querySelector(s); return e ? px(getComputedStyle(e).fontSize) : null; function px(v) { return parseFloat(v); } };
    return { kpi: f('.dash-kpi [data-testid="dash-kpi-sku-value"]'), mv: f('.dash-mv-value'), label: f('.dash-mv .dash-label'), sub: f('.dash-mv .dash-sub'), section: f('.dash-section-title') };
});
// no clipped figure, no overlap inside a grid, children stay inside their card
const layout = (page) => page.evaluate(() => {
    const out = { clipped: [], overlap: [], outside: [], wrappedVals: [] };
    document.querySelectorAll('.dash-value, .dash-attn-count').forEach((v) => {
        if (v.scrollWidth > v.clientWidth + 1) out.clipped.push(v.textContent);
        const lh = parseFloat(getComputedStyle(v).lineHeight) || parseFloat(getComputedStyle(v).fontSize) * 1.2;
        if (v.getBoundingClientRect().height > lh * 1.6) out.wrappedVals.push(v.textContent);
    });
    ['.dash-kpis', '.dash-mv-cards', '.dash-attn-grid', '.dash-act-grid'].forEach((g) => {
        const cards = Array.from(document.querySelectorAll(`${g} > .dash-card`)).map((c) => c.getBoundingClientRect());
        for (let i = 0; i < cards.length; i++) for (let j = i + 1; j < cards.length; j++) {
            const a = cards[i]; const b = cards[j];
            if (a.left < b.right - 0.5 && b.left < a.right - 0.5 && a.top < b.bottom - 0.5 && b.top < a.bottom - 0.5) out.overlap.push(`${g}:${i}/${j}`);
        }
    });
    document.querySelectorAll('.dash-card').forEach((c) => {
        const r = c.getBoundingClientRect();
        c.querySelectorAll('.dash-label,.dash-value,.dash-sub,.dash-attn-count,.dash-attn-val').forEach((e) => {
            const b = e.getBoundingClientRect();
            if (b.width && (b.right > r.right + 1 || b.left < r.left - 1)) out.outside.push(e.textContent.slice(0, 20));
        });
    });
    // rows = clusters of tops closer than 8px (sub-pixel offsets must not split one row)
    const rows = (sel) => { const t = Array.from(document.querySelectorAll(sel)).map((e) => e.getBoundingClientRect().top).sort((a, b) => a - b); let n = 0; t.forEach((v, i) => { if (i === 0 || v - t[i - 1] > 8) n++; }); return n; };
    out.mvRows = rows('.dash-mv'); out.attnRows = rows('.dash-attn'); out.kpiRows = rows('.dash-kpi');
    out.kpiH = Math.round(document.querySelector('.dash-kpi').getBoundingClientRect().height);
    out.vals = Array.from(document.querySelectorAll('.dash-kpi [data-testid="dash-kpi-value-value"], .dash-mv-value')).map((e) => ({ text: e.textContent, title: e.title, compact: e.dataset.compact === '1' }));
    return out;
});
const toggle = (page) => page.click('#sidebar-toggle-btn');
const settle = (page) => page.waitForTimeout(450);
const clean = async (page) => { await page.evaluate(() => { try { localStorage.removeItem('inv_sidebar_collapsed'); } catch (e) { /* none */ } }); };

let browser;
try {
    browser = await chromium.launch();

    // ===== A. desktop 1536 expanded (clean + big data)
    {
        const { page, context } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'd1536', __big: true }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page); await settle(page);
        const f = await fonts(page); const l = await layout(page);
        check('A1 desktop expanded: sidebar ~232px, toggle aria-expanded=true', await sidebarW(page) === 232 && await page.getAttribute('#sidebar-toggle-btn', 'aria-expanded') === 'true' && (await page.getAttribute('#sidebar-toggle-btn', 'aria-label')).startsWith('Ciutkan'), String(await sidebarW(page)));
        check('A2 desktop type scale: KPI 28-32, movement 20-24, title 15-17, support 12-14, section 17-19', f.kpi >= 28 && f.kpi <= 32 && f.mv >= 20 && f.mv <= 24 && f.label >= 15 && f.label <= 17 && f.sub >= 12 && f.sub <= 14 && f.section >= 17 && f.section <= 19, JSON.stringify(f));
        check('A3 desktop: 4 KPI + 5 movement + 4 attention cards each on ONE row', l.kpiRows === 1 && l.mvRows === 1 && l.attnRows === 1, `${l.kpiRows}/${l.mvRows}/${l.attnRows}`);
        check('A4 desktop big values: nothing clipped / overlapping / outside its card / wrapped', !l.clipped.length && !l.overlap.length && !l.outside.length && !l.wrappedVals.length && await hOverflow(page) <= 0, JSON.stringify(l));
        check('A5 KPI card is compact (<= 100px tall at 1536)', l.kpiH <= 100, String(l.kpiH));
        const nv = l.vals[0];
        check('A6 Nilai Stok: full Rupiah or compact "Rp 2,23 M" with exact value in tooltip', nv.text === 'Rp 2.233.226.305,92' || (nv.text === 'Rp 2,23 M' && nv.title === 'Rp 2.233.226.305,92'), JSON.stringify(nv));
        await shot(page, 'desktop-expanded');
        // B. collapse
        await toggle(page); await settle(page);
        const wC = await sidebarW(page);
        const lc = await layout(page);
        check('B1 desktop collapsed: rail 64-76px, aria-expanded=false, label "Perluas"', wC >= 64 && wC <= 76 && await page.getAttribute('#sidebar-toggle-btn', 'aria-expanded') === 'false' && (await page.getAttribute('#sidebar-toggle-btn', 'aria-label')).startsWith('Perluas'), String(wC));
        check('B2 collapsed: labels hidden, icons visible, active item obvious', await page.evaluate(() => { const a = document.querySelector('#sidebar .sidebar-link.active'); const cs = getComputedStyle(a); return parseFloat(cs.fontSize) === 0 && a.querySelector('.icon').getBoundingClientRect().width > 10 && cs.boxShadow !== 'none'; }));
        check('B3 content area widened with the rail (left edge follows)', await contentLeft(page) <= wC + 2, String(await contentLeft(page)));
        check('B4 collapsed + big values: no clip/overlap, Nilai Stok now full text', !lc.clipped.length && !lc.overlap.length && !lc.outside.length && lc.vals[0].text === 'Rp 2.233.226.305,92' && lc.vals.slice(1).every((v) => !v.compact), JSON.stringify(lc.vals));
        check('B5 sidebar width transition is 150-220ms', await page.evaluate(() => { const d = getComputedStyle(document.getElementById('sidebar')).transitionDuration.split(',').map((x) => parseFloat(x) * 1000); return d.some((x) => x >= 150 && x <= 220); }));
        await shot(page, 'desktop-collapsed');
        // tooltip (hover + keyboard focus) and active highlight
        await page.hover('#sidebar [data-tab="dashboard"]');
        await page.waitForSelector('.sidebar-tip:not([hidden])', { timeout: 2000 });
        check('B6 hover tooltip shows the item name', (await page.textContent('.sidebar-tip')).trim() === 'Dashboard');
        await page.mouse.move(700, 400);
        await page.focus('#sidebar [data-tab="dashboard"]');
        await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');
        check('B7a keyboard: Enter on a focused rail link navigates', await (async () => { await page.focus('#sidebar [data-tab="dashboard"]'); await page.keyboard.press('Enter'); await page.waitForSelector('#tab-dashboard.active', { timeout: 3000 }); return true; })().catch(() => false));
        await page.mouse.move(700, 400); await page.focus('#sidebar [data-tab="dashboard"]'); await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');
        const b7 = await page.evaluate(() => ({ tipHidden: document.querySelector('.sidebar-tip').hidden, active: document.activeElement && (document.activeElement.dataset.tab || document.activeElement.id || document.activeElement.className), unnamed: Array.from(document.querySelectorAll('#sidebar .sidebar-link')).filter((a) => !(a.getAttribute('aria-label') && a.getAttribute('aria-label').length > 1)).map((a) => a.dataset.tab) }));
        check('B7 keyboard focus shows tooltip; every rail link has an accessible name', !b7.tipHidden && b7.unnamed.length === 0, JSON.stringify(b7));
        // flyout for nested menu
        await page.click('#sidebar [data-group="transactions"] > .sidebar-group-header');
        const fly = await page.evaluate(() => { const sm = document.querySelector('#sidebar [data-group="transactions"] .sidebar-submenu'); const r = sm.getBoundingClientRect(); return { display: getComputedStyle(sm).display, left: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height), links: Array.from(sm.querySelectorAll('.sidebar-link')).filter((a) => a.offsetParent).map((a) => a.textContent.trim()) }; });
        check('B8 nested menu opens as a flyout beside the rail with its items', fly.display === 'flex' && fly.left >= 60 && fly.w >= 200 && fly.links.some((l) => l.includes('Stock IN / OUT')), JSON.stringify(fly));
        await shot(page, 'desktop-collapsed-flyout');
        await page.click('#sidebar [data-group="transactions"] .sidebar-link[data-tab="transaksi"]');
        await page.waitForSelector('#tab-transaksi.active', { timeout: 5000 });
        check('B9 flyout choice navigates and the flyout closes; parent shows active state', await page.evaluate(() => { const g = document.querySelector('#sidebar [data-group="transactions"]'); return !g.classList.contains('flyout') && g.classList.contains('has-active') && getComputedStyle(g.querySelector('.sidebar-group-header')).boxShadow !== 'none'; }));
        // other page unharmed in rail, and again expanded; pinned bar follows the content edge
        await page.waitForTimeout(500);
        const pageRail = await page.evaluate(() => ({ over: document.documentElement.scrollWidth - innerWidth, left: Math.round(document.querySelector('.app-content-v2').getBoundingClientRect().left) }));
        check('B10 Stock IN/OUT page in rail: no horizontal overflow', pageRail.over <= 0, JSON.stringify(pageRail));
        await shot(page, 'stockinout-collapsed');
        await toggle(page); await settle(page);
        const bar = await page.evaluate(() => { const b = document.querySelector('.tx2-bar'); if (!b) return null; const r = b.getBoundingClientRect(); const c = document.querySelector('.app-content-v2').getBoundingClientRect(); return { l: Math.round(r.left), r: Math.round(r.right), cl: Math.round(c.left), cr: Math.round(c.right) }; });
        check('B11 Stock IN/OUT expanded: no overflow; pinned bar stays inside the content area after toggling', (await hOverflow(page)) <= 0 && (!bar || (bar.l >= bar.cl - 2 && bar.r <= bar.cr + 2)), JSON.stringify(bar));
        await shot(page, 'stockinout-expanded');
        // C. reload preserves preference
        await toggle(page); await settle(page);
        await page.reload(); await page.waitForSelector('#app-shell', { state: 'visible' }); await settle(page);
        check('C1 reload keeps the collapsed rail (localStorage)', await sidebarW(page) <= 76 && await page.evaluate(() => localStorage.getItem('inv_sidebar_collapsed')) === '1');
        await toggle(page); await settle(page); await page.reload(); await page.waitForSelector('#app-shell', { state: 'visible' }); await settle(page);
        check('C2 reload keeps the expanded sidebar', await sidebarW(page) === 232 && await page.evaluate(() => localStorage.getItem('inv_sidebar_collapsed')) === '0');
        // walk other pages in both states: sidebar shared globally
        const tabs = await page.evaluate(() => ['history-transaksi', 'transfer', 'laporan-ringkasan', 'stok-barang', 'opname', 'master-barang', 'distribusi-do'].filter((t) => document.querySelector(`#sidebar [data-tab="${t}"]`)));
        const bad = [];
        for (const collapsed of [false, true]) {
            const cur = (await sidebarW(page)) <= 76;
            if (cur !== collapsed) { await toggle(page); await settle(page); }
            for (const t of tabs) {
                await page.evaluate((tt) => document.querySelector(`#sidebar [data-tab="${tt}"]`).click(), t);
                await page.waitForSelector(`#tab-${t}.active`, { timeout: 8000 }).catch(() => bad.push(`${t}: not active`));
                await page.waitForTimeout(350);
                const o = await hOverflow(page);
                if (o > 0) bad.push(`${t}${collapsed ? ' (rail)' : ''}: overflow ${o}`);
            }
        }
        check(`C3 ${tabs.length} other pages (${tabs.join(', ')}) render in expanded + rail with no horizontal overflow`, bad.length === 0 && tabs.length >= 4, bad.join('; '));
        await context.close();
    }

    // ===== D. 1366 laptop / iPad Pro landscape (default expanded because >=1024 landscape)
    for (const [name, w, h] of [['d1366', 1366, 768], ['ipadL1180', 1180, 820], ['ipadL1024', 1024, 768]]) {
        const { page, context } = await newSession(browser, { viewport: { width: w, height: h }, __name: name, __big: true }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page); await settle(page);
        const f = await fonts(page); const l = await layout(page);
        const expectedW = w <= 1200 ? 216 : 232;
        check(`D ${name}: default EXPANDED (${expectedW}px), collapsible, aria ok`, await sidebarW(page) === expectedW && await page.getAttribute('#sidebar-toggle-btn', 'aria-expanded') === 'true', String(await sidebarW(page)));
        if (w <= 1200) check(`D ${name}: iPad scale KPI 22-26, movement 17-20, title 13-15, support 11-13, section 15-17`, f.kpi >= 22 && f.kpi <= 26 && f.mv >= 17 && f.mv <= 20 && f.label >= 13 && f.label <= 15 && f.sub >= 11 && f.sub <= 13 && f.section >= 15 && f.section <= 17, JSON.stringify(f));
        check(`D ${name}: movement 5-in-a-row, attention 4-in-a-row, KPI 4-in-a-row`, l.mvRows === 1 && l.attnRows === 1 && l.kpiRows === 1, `${l.mvRows}/${l.attnRows}/${l.kpiRows}`);
        check(`D ${name}: no clipped/overlapping/outside/wrapped figure, no page overflow`, !l.clipped.length && !l.overlap.length && !l.outside.length && !l.wrappedVals.length && await hOverflow(page) <= 0, JSON.stringify({ c: l.clipped, o: l.overlap, x: l.outside, w: l.wrappedVals }));
        const header = await page.evaluate(() => { const t = document.querySelector('.dash-top'); return Math.round(t.getBoundingClientRect().height); });
        check(`D ${name}: filter/header row compact (<= 2 lines)`, header <= 76, String(header));
        if (name === 'ipadL1180') {
            await shot(page, 'ipad-landscape-expanded');
            await toggle(page); await settle(page);
            const lc = await layout(page);
            check('D ipad landscape collapsed: rail 64-76, content reflows, still 4-up, nothing clipped', await sidebarW(page) <= 76 && lc.mvRows === 1 && !lc.clipped.length && !lc.overlap.length && await hOverflow(page) <= 0, JSON.stringify(lc.vals));
            await shot(page, 'ipad-landscape-collapsed');
        }
        if (name === 'ipadL1024') {
            const nv = l.vals[0];
            check('D ipad 1024: compact secondary format used, exact value in tooltip', nv.compact && /^Rp \d+(,\d+)? (M|jt|T)$/.test(nv.text) && nv.title === 'Rp 2.233.226.305,92', JSON.stringify(nv));
        }
        await context.close();
    }

    // ===== E. iPad portrait: drawer (<=900) and rail default (1024 portrait)
    for (const [name, w, h] of [['ipadP820', 820, 1180], ['ipadP768', 768, 1024]]) {
        const { page, context } = await newSession(browser, { viewport: { width: w, height: h }, __name: name, __big: true }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page); await settle(page);
        const l = await layout(page); const f = await fonts(page);
        check(`E ${name}: sidebar is an off-canvas drawer (closed), content full width`, await page.evaluate(() => document.getElementById('sidebar').getBoundingClientRect().right <= 1) && await contentLeft(page) === 0);
        check(`E ${name}: movement 2 columns (5 cards = 3 rows), attention 2x2, no overflow/clipping/overlap`, l.mvRows === 3 && l.attnRows === 2 && !l.clipped.length && !l.overlap.length && !l.outside.length && await hOverflow(page) <= 0, JSON.stringify({ mv: l.mvRows, at: l.attnRows, c: l.clipped, o: l.overlap }));
        check(`E ${name}: portrait scale KPI 20-26, movement 17-20`, f.kpi >= 20 && f.kpi <= 26 && f.mv >= 17 && f.mv <= 20, JSON.stringify(f));
        if (name === 'ipadP820') await shot(page, 'ipad-portrait');
        await toggle(page); await page.waitForTimeout(350);
        check(`E ${name}: ☰ opens the drawer overlay (aria-expanded=true, backdrop shown)`, await page.getAttribute('#sidebar-toggle-btn', 'aria-expanded') === 'true' && await page.evaluate(() => document.getElementById('sidebar').classList.contains('open') && getComputedStyle(document.getElementById('sidebar-backdrop')).display === 'block'));
        if (name === 'ipadP820') await shot(page, 'ipad-portrait-drawer');
        await page.click('#sidebar-backdrop', { position: { x: w - 20, y: 300 } }); await page.waitForTimeout(350);
        check(`E ${name}: backdrop closes the drawer`, !(await page.evaluate(() => document.getElementById('sidebar').classList.contains('open'))));
        await context.close();
    }
    {   // 1024 wide held in portrait: rail by default, 2x2
        const { page, context } = await newSession(browser, { viewport: { width: 1024, height: 1366 }, __name: 'ipadP1024', __big: true }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page); await settle(page);
        const l = await layout(page);
        check('E ipad Pro 1024 portrait: rail by default (<=76px), movement 2 columns (3 rows), no overflow', await sidebarW(page) <= 76 && l.mvRows === 3 && !l.clipped.length && await hOverflow(page) <= 0, `${await sidebarW(page)} mvRows=${l.mvRows}`);
        await toggle(page); await settle(page);
        check('E ipad Pro 1024 portrait: can be expanded manually', await sidebarW(page) === 216);
        await context.close();
    }

    // ===== F. mobile drawer
    {
        const { page, context } = await newSession(browser, { viewport: { width: 390, height: 844 }, __name: 'm390', __big: true }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page); await settle(page);
        const l = await layout(page); const f = await fonts(page);
        check('F mobile: no clipping/overlap/overflow; type scaled but not tiny (KPI >= 18, support >= 11)', !l.clipped.length && !l.overlap.length && !l.outside.length && await hOverflow(page) <= 0 && f.kpi >= 18 && f.sub >= 11, JSON.stringify({ f, c: l.clipped, o: l.overlap }));
        await shot(page, 'mobile');
        await toggle(page); await page.waitForTimeout(350);
        check('F mobile: drawer opens over the content (overlay, not rail)', await page.evaluate(() => { const s = document.getElementById('sidebar'); const r = s.getBoundingClientRect(); return s.classList.contains('open') && r.left === 0 && r.width > 200 && !s.classList.contains('sidebar-collapsed') && getComputedStyle(s).position === 'fixed'; }));
        await shot(page, 'mobile-drawer');
        await page.click('#sidebar [data-group="transactions"] > .sidebar-group-header');
        await page.click('#sidebar .sidebar-link[data-tab="transaksi"]');
        await page.waitForSelector('#tab-transaksi.active');
        await page.waitForTimeout(350);
        check('F mobile: choosing a page closes the drawer; page has no horizontal overflow', !(await page.evaluate(() => document.getElementById('sidebar').classList.contains('open'))) && await hOverflow(page) <= 0);
        await context.close();
    }

    // ===== G. function intact after all of that: drill-down, warehouse selector, keyboard, no mutation
    {
        const { page, context } = await newSession(browser, { viewport: { width: 1366, height: 768 }, __name: 'func' }, seed.admin);
        await clean(page); await page.reload(); await waitDash(page);
        await toggle(page); await settle(page); // rail
        const ov = await api(page, '/dashboard/inventory', baseP());
        const fmv = (v) => page.evaluate((x) => UI.formatMoney(x), v);
        check('G1 rail: movement cards still show the server values', (await mvText(page, 'stock_in')) === await fmv(ov.movement.stock_in.value) && (await mvText(page, 'closing_stock')) === await fmv(ov.movement.closing_stock.value));
        await openCard(page, 'stock_in');
        const grand = await txt(page, 'dash-drawer-grand-value');
        check('G2 drill-down drawer opens from a card and GRAND TOTAL == card', grand === await fmv(ov.movement.stock_in.value), grand);
        await closeDrawer(page);
        await page.focus('[data-testid="dash-mv-stock_out"]'); await page.keyboard.press('Enter');
        await page.waitForSelector('.drawer.open [data-dash-title]', { timeout: 5000 });
        check('G3 cards keep keyboard activation (Enter opens drill-down)', (await txt(page, 'dash-drawer-title')).startsWith('Rincian Stock OUT'));
        await closeDrawer(page);
        const before = requests.length;
        const whs = await page.locator('[data-testid="dash-warehouse"] option').evaluateAll((o) => o.map((x) => x.value));
        await selectWh(page, whs[1]);
        const ov2 = await api(page, '/dashboard/inventory', baseP({ warehouse_id: whs[1] }));
        check('G4 warehouse selector still re-queries with warehouse_id and updates figures', requests.slice(before).some((r) => r.path === '/dashboard/inventory' && r.url.includes(`warehouse_id=${whs[1]}`)) && (await mvText(page, 'closing_stock')) === await fmv(ov2.movement.closing_stock.value));
        await selectWh(page, '');
        const dead = ov.attention.find((a) => a.key === 'dead_stock');
        const deadTxt = await page.locator('[data-testid="dash-attn-dead_stock"]').innerText();
        check('G6 Dead Stock card follows the Stock Opname result (hint, count, value from the API)', dead.hint === 'Hasil Stock Opname terakhir' && deadTxt.includes('Hasil Stock Opname terakhir') && deadTxt.includes(String(dead.sku_count)) && dead.action.detail === 'deadstock', JSON.stringify(dead));
        await page.click('[data-testid="dash-attn-dead_stock"]');
        await page.waitForSelector('.drawer.open [data-dash-title]', { timeout: 5000 });
        await page.waitForSelector('[data-testid="dash-drawer-table"], [data-testid="dash-drawer-error"]', { timeout: 15000 });
        const dg = await api(page, '/dashboard/inventory/detail', baseP({ type: 'deadstock', per_page: '50' }));
        check('G7 Dead Stock drill-down: title, rows and GRAND TOTAL == card', (await txt(page, 'dash-drawer-title')).startsWith('Dead Stock') && dg.kind === 'deadstock' && Math.abs(dg.grand_total.value - dead.value) < 0.011 && await page.locator('[data-testid="dash-drawer-row"]').count() === Math.min(50, dg.pagination.total), `${await txt(page, 'dash-drawer-title')} ${dg.grand_total.value} vs ${dead.value}`);
        await closeDrawer(page);
        check('G5 only GET requests left the browser during the whole run', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
        await context.close();
    }
    check('H no console / page errors in any viewport', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' || '));
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
