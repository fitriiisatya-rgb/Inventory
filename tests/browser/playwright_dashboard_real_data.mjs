// Main Dashboard redesign — REAL data, end to end, through the real UI.
//
// Real MariaDB + real schema + real PHP API (php -S public/router.php, behind a
// tiny node static server so Chromium's parallel connections don't choke php -S)
// + real login form + real Chromium. The ledger history is built by the
// application's own services (tests/lib/dashboard_fixture.php); the backend's
// numbers are hand-verified by tests/inventory_dashboard_test.php. This script
// proves the UI shows exactly those server numbers, that every card opens the
// right drawer whose GRAND TOTAL / Σ rows equal the card, filters/pagination
// work, nothing but GET leaves the browser, there are no console errors and the
// layout holds on desktop / iPad landscape / iPad portrait / mobile.
//
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw \
//     DASH_SHOT_DIR=/some/dir node tests/browser/playwright_dashboard_real_data.mjs
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

const CARDS = [
    ['opening_stock', 'Rincian Stok Awal', 'GRAND TOTAL STOK AWAL'],
    ['purchase_in', 'Rincian Pembelian / Stock IN', 'GRAND TOTAL PEMBELIAN / STOCK IN'],
    ['stock_out', 'Rincian Barang Keluar / Stock OUT', 'GRAND TOTAL BARANG KELUAR / STOCK OUT'],
    ['closing_stock', 'Rincian Stok Akhir', 'GRAND TOTAL STOK AKHIR'],
];
const baseP = (extra = {}) => ({ warehouse_id: '', period: 'month', ...extra });

let browser;
try {
    browser = await chromium.launch();
    const { page } = await newSession(browser, { viewport: { width: 1440, height: 900 }, __name: 'desktop' }, seed.admin);

    // ============================================ 1. page, scripts, top bar
    const scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.getAttribute('src') || ''));
    check('dashboard.js + app.css carry the new cache token', scripts.some((s) => s.includes('dashboard.js?v=20261009-dash1')) && (await page.evaluate(() => Array.from(document.querySelectorAll('link[rel=stylesheet]')).some((l) => l.href.includes('20261009-dash1')))));
    const whOptions = (await page.locator('[data-testid="dash-warehouse"] option').allTextContents()).map((s) => s.trim());
    check('Gudang filter lists Semua Gudang + the three warehouses', whOptions[0] === 'Semua Gudang' && ['Gudang SCM / Gudang Besar', 'Gudang Cibadak', 'Gudang Karang Tengah'].every((n) => whOptions.includes(n)), whOptions.join('|'));
    check('Refresh button + "Data diperbarui" timestamp present', (await txt(page, 'dash-updated')).startsWith('Data diperbarui:') && !(await txt(page, 'dash-updated')).endsWith('—') && await page.locator('[data-testid="dash-refresh"]').count() === 1);
    const before = requests.length;
    await page.click('[data-testid="dash-refresh"]'); await waitDash(page);
    check('Refresh issues a fresh GET /dashboard/inventory', requests.slice(before).some((r) => r.path === '/dashboard/inventory' && r.method === 'GET'));

    // ============================================ 2. top KPI cards
    let ov = await api(page, '/dashboard/inventory', baseP());
    const fm = (v) => page.evaluate((x) => UI.formatMoney(x), v);
    check('KPI Total SKU Aktif == API', (await txt(page, 'dash-kpi-sku-value')) === String(ov.summary.total_sku).replace(/\B(?=(\d{3})+(?!\d))/g, '.') || (await txt(page, 'dash-kpi-sku-value')).replace(/\D/g, '') === String(ov.summary.total_sku));
    check('KPI Nilai Stok == API total (on-hand + in-transit)', (await txt(page, 'dash-kpi-value-value')) === await fm(ov.summary.stock_value.total));
    check('KPI Transfer Pending / Stock Opname Aktif == API', Number((await txt(page, 'dash-kpi-transfer-value')).replace(/\D/g, '')) === ov.summary.pending_transfers && Number((await txt(page, 'dash-kpi-opname-value')).replace(/\D/g, '')) === ov.summary.active_opname && ov.summary.pending_transfers >= 1 && ov.summary.active_opname >= 1, JSON.stringify(ov.summary));
    // click each KPI
    await page.click('[data-testid="dash-kpi-sku"]'); await page.waitForSelector('#tab-stok-barang.active', { timeout: 5000 });
    check('KPI Total SKU click opens Stok Barang', true);
    await page.evaluate(() => document.querySelector('[data-tab="dashboard"]').click()); await waitDash(page);
    for (const [tid, title, type] of [['dash-kpi-value', 'Nilai Stok Saat Ini', 'current_stock'], ['dash-kpi-transfer', 'Transfer Pending', 'pending_transfers'], ['dash-kpi-opname', 'Stock Opname Aktif', 'active_opname']]) {
        await page.click(`[data-testid="${tid}"]`);
        await page.waitForSelector('.drawer.open [data-dash-title]', { timeout: 5000 });
        await page.waitForSelector('[data-testid="dash-drawer-table"]', { timeout: 15000 });
        const t = await txt(page, 'dash-drawer-title');
        const rows = await page.locator('[data-testid="dash-drawer-row"]').count();
        const det = await api(page, '/dashboard/inventory/detail', baseP({ type, per_page: '50' }));
        check(`KPI ${tid} opens drawer "${title}" with ${det.pagination.total} real rows`, t === title && rows === Math.min(50, det.pagination.total) && det.pagination.total >= 1, `${t} rows=${rows}/${det.pagination.total}`);
        if (type === 'current_stock') {
            const g = await txt(page, 'dash-drawer-grand-value');
            check('Nilai Stok drawer GRAND TOTAL == on-hand batch valuation (Σ of the same rows)', g === await fm(det.grand_total.value) && close2(det.grand_total.value, ov.summary.stock_value.on_hand), `${g} vs on_hand ${ov.summary.stock_value.on_hand}`);
        }
        await closeDrawer(page);
    }

    // ============================================ 3. movement cards + reconciliation
    const m = ov.movement;
    for (const [key] of CARDS) check(`month: card ${key} shows server value ${rp(m[key].value)}`, (await mvText(page, key)) === await fm(m[key].value), await mvText(page, key));
    check('month: Awal + Pembelian − Keluar ± Lain = Akhir (ledger identity, no forced formula)', close2(m.opening_stock.value + m.purchase_in.value - m.stock_out.value + m.other_movements.net, m.closing_stock.value) && m.reconciliation.ok, JSON.stringify(m.reconciliation));
    check('month: "Pergerakan lain" nominal shown and equals server net', (await txt(page, 'dash-other-net')) === await fm(m.other_movements.net));
    await page.click('[data-testid="dash-other-toggle"]');
    check('month: Pergerakan lain breakdown lists items incl. transfer / adjustment rows', await page.locator('[data-testid="dash-other-list"] .dash-other-row').count() === m.other_movements.items.length && m.other_movements.items.length >= 3);
    check('month: Stok Akhir reconciles with current batch valuation', m.reconciliation.batch_ok === true && close2(m.closing_stock.value, m.reconciliation.batch_on_hand), JSON.stringify(m.reconciliation));
    check('no reconciliation warning shown on clean data', await page.locator('[data-testid="dash-recon-warning"], [data-testid="dash-batch-warning"]').count() === 0);
    check('period label reads BULAN INI', (await txt(page, 'dash-period-label')).includes('BULAN INI'));
    await page.screenshot({ path: path.join(shotDir, 'dashboard-desktop-1440.png'), fullPage: true });

    // ============================================ 4. four drawers: title, Σ rows == card
    for (const [key, title, totalLabel] of CARDS) {
        const reqBefore = requests.length;
        await openCard(page, key);
        check(`[${key}] drawer title "${title}"`, (await txt(page, 'dash-drawer-title')) === title);
        const grandLabel = await txt(page, 'dash-drawer-grand');
        check(`[${key}] GRAND TOTAL label "${totalLabel}"`, grandLabel.includes(totalLabel), grandLabel);
        const grandUi = await txt(page, 'dash-drawer-grand-value');
        check(`[${key}] drawer GRAND TOTAL == card (${await mvText(page, key)})`, grandUi === (await mvText(page, key)), grandUi);
        const s = await sumAllPages(page, baseP(), key);
        check(`[${key}] Σ of every drill-down row (${s.rows} rows, all pages) == card total`, close2(s.sum, m[key].value) && close2(s.grand, m[key].value), `Σ=${s.sum} card=${m[key].value}`);
        const heads = (await page.locator('[data-testid="dash-drawer-table"] thead th').allTextContents()).map((h) => h.trim());
        const want = {
            opening_stock: ['SKU', 'Nama Barang', 'Kategori', 'Gudang', 'Satuan Base', 'Qty Awal', 'HPP', 'Nilai'],
            purchase_in: ['Tanggal', 'No Referensi', 'SKU', 'Nama Barang', 'Supplier', 'Gudang', 'Qty', 'Satuan', 'Qty Base', 'Harga / HPP', 'Nilai'],
            stock_out: ['Tanggal', 'No Referensi', 'SKU', 'Nama Barang', 'Gudang', 'Tujuan / Keterangan', 'Qty', 'Satuan', 'Qty Base', 'HPP', 'Nilai'],
            closing_stock: ['SKU', 'Nama Barang', 'Kategori', 'Gudang', 'Satuan Base', 'Qty Akhir', 'HPP', 'Nilai'],
        }[key];
        check(`[${key}] columns = ${want.join(' | ')}`, heads.join('|') === want.join('|'), heads.join('|'));
        check(`[${key}] only GET requests were made opening the drawer`, requests.slice(reqBefore).every((r) => r.method === 'GET'));
        check(`[${key}] drawer has search + category + warehouse + per-page controls`, await page.locator('[data-testid="dash-drawer-search"]').count() === 1 && await page.locator('[data-testid="dash-drawer-category"]').count() === 1 && await page.locator('[data-testid="dash-drawer-warehouse"]').count() === 1 && await page.locator('[data-testid="dash-drawer-perpage"]').count() === 1);
        if (key === 'purchase_in') {
            const refs = await page.locator('[data-testid="dash-drawer-row"]').allInnerTexts();
            check('[purchase_in] contains no transfer-receipt reference (true purchases only)', !refs.some((r) => /TRF|TRANSFER/i.test(r)), '');
        }
        if (key === 'closing_stock') await page.screenshot({ path: path.join(shotDir, 'dashboard-drawer-closing-1440.png') });
        await closeDrawer(page);
        check(`[${key}] drawer closed: scroll-lock + drawer-dash class removed`, await page.evaluate(() => !document.documentElement.classList.contains('dash-scroll-lock') && !document.body.classList.contains('dash-scroll-lock') && !document.querySelector('.drawer.drawer-dash')));
    }

    // ============================================ 5. pagination / search / filters (Stok Akhir company-wide, >50 rows)
    await openCard(page, 'closing_stock');
    const total = (await api(page, '/dashboard/inventory/detail', baseP({ type: 'closing_stock', per_page: '50' }))).pagination.total;
    check(`Stok Akhir has ${total} rows (> 50) so pagination is exercised`, total > 50);
    check('page 1 shows exactly 50 rows; Sebelumnya disabled', await page.locator('[data-testid="dash-drawer-row"]').count() === 50 && await page.locator('[data-testid="dash-drawer-prev"]').isDisabled());
    const p1first = await page.locator('[data-testid="dash-drawer-row"]').first().innerText();
    await page.click('[data-testid="dash-drawer-next"]');
    await page.waitForFunction((f) => document.querySelector('[data-testid="dash-drawer-row"]') && document.querySelector('[data-testid="dash-drawer-row"]').innerText !== f, p1first);
    check('Berikutnya loads page 2 with the remaining rows', await page.locator('[data-testid="dash-drawer-row"]').count() === total - 50 && (await txt(page, 'dash-drawer-pager')).includes('halaman 2/'));
    check('GRAND TOTAL stays the all-pages total on page 2', (await txt(page, 'dash-drawer-grand-value')) === await fm(m.closing_stock.value));
    await page.selectOption('[data-testid="dash-drawer-perpage"]', '25');
    await page.waitForFunction(() => document.querySelectorAll('[data-testid="dash-drawer-row"]').length === 25);
    check('rows-per-page 25 resets to page 1 with 25 rows', (await txt(page, 'dash-drawer-pager')).includes('halaman 1/'));
    await page.selectOption('[data-testid="dash-drawer-perpage"]', '100');
    await page.waitForFunction((n) => document.querySelectorAll('[data-testid="dash-drawer-row"]').length === n, total);
    check('rows-per-page 100 shows all 80 rows on one page (1/1)', (await txt(page, 'dash-drawer-pager')).includes('halaman 1/1'));
    // search
    await page.fill('[data-testid="dash-drawer-search"]', seed.tepungSku);
    await page.waitForFunction(() => /TERFILTER/.test(document.querySelector('[data-testid="dash-drawer-grand"]')?.innerText || '') && document.querySelectorAll('[data-testid="dash-drawer-row"]').length >= 1, null, { timeout: 8000 });
    const sRows = await page.locator('[data-testid="dash-drawer-row"]').allInnerTexts();
    const filtered = await api(page, '/dashboard/inventory/detail', baseP({ type: 'closing_stock', q: seed.tepungSku }));
    check('search by SKU narrows rows to that SKU only', sRows.length === filtered.pagination.total && sRows.length >= 1 && sRows.every((r) => r.includes(seed.tepungSku)), `${sRows.length}`);
    const gl = await txt(page, 'dash-drawer-grand');
    check('filtered GRAND TOTAL = Σ filtered rows, labelled TERFILTER, unfiltered card total still shown', gl.includes('TERFILTER') && (await txt(page, 'dash-drawer-grand-value')) === await fm(filtered.grand_total.value) && (await txt(page, 'dash-drawer-card-total')).includes(await fm(filtered.card_total.value)));
    await page.fill('[data-testid="dash-drawer-search"]', '');
    await page.waitForFunction(() => !/TERFILTER/.test(document.querySelector('[data-testid="dash-drawer-grand"]')?.innerText || '') && document.querySelectorAll('[data-testid="dash-drawer-row"]').length > 3, null, { timeout: 8000 });
    // category + row-warehouse
    await page.selectOption('[data-testid="dash-drawer-category"]', String(seed.cat.roti));
    await page.waitForTimeout(600); await page.waitForSelector('[data-testid="dash-drawer-table"]');
    const catApi = await api(page, '/dashboard/inventory/detail', baseP({ type: 'closing_stock', category_id: String(seed.cat.roti), per_page: '100' }));
    check('category filter returns only that category (server-side)', await page.locator('[data-testid="dash-drawer-row"]').count() === Math.min(100, catApi.pagination.total) && (await page.locator('[data-testid="dash-drawer-row"]').allInnerTexts()).every((r) => r.includes('DF Roti')));
    await page.selectOption('[data-testid="dash-drawer-warehouse"]', String(seed.wh.B));
    await page.waitForTimeout(600); await page.waitForSelector('[data-testid="dash-drawer-table"]');
    const bothApi = await api(page, '/dashboard/inventory/detail', baseP({ type: 'closing_stock', category_id: String(seed.cat.roti), row_warehouse_id: String(seed.wh.B), per_page: '100' }));
    check('warehouse row filter combines with category', await page.locator('[data-testid="dash-drawer-row"]').count() === bothApi.pagination.total && (await page.locator('[data-testid="dash-drawer-row"]').allInnerTexts()).every((r) => r.includes('Gudang Cibadak')) && bothApi.pagination.total >= 1);
    await page.fill('[data-testid="dash-drawer-search"]', 'ZZZ-NO-SUCH-ITEM');
    await page.waitForSelector('[data-testid="dash-drawer-empty"]', { timeout: 5000 });
    check('no matching rows → friendly empty state, no console error', (await txt(page, 'dash-drawer-empty')).includes('Tidak ada baris yang cocok'));
    await closeDrawer(page);

    // ============================================ 6. warehouse filter refreshes every card
    const whs = [['SCM', seed.wh.A], ['Cibadak', seed.wh.B], ['Karang Tengah', seed.wh.C]];
    const wApi = {};
    for (const [label, id] of whs) {
        const before2 = requests.length;
        await selectWh(page, String(id));
        const o = await api(page, '/dashboard/inventory', baseP({ warehouse_id: String(id) }));
        wApi[label] = o;
        const reqs = requests.slice(before2);
        check(`[${label}] selecting the warehouse sends warehouse_id=${id} (GET only)`, reqs.some((r) => r.url.includes(`warehouse_id=${id}`) && r.path === '/dashboard/inventory') && reqs.every((r) => r.method === 'GET'));
        let ok = true;
        for (const [key] of CARDS) ok = ok && (await mvText(page, key)) === await fm(o.movement[key].value);
        check(`[${label}] all 4 movement cards == API for this warehouse`, ok);
        check(`[${label}] KPI Nilai Stok == API`, (await txt(page, 'dash-kpi-value-value')) === await fm(o.summary.stock_value.total));
        check(`[${label}] Perlu Perhatian + Top 5 + recent activity re-rendered`, await page.locator('[data-testid^="dash-attn-"]').count() === o.attention.length && await page.locator('[data-testid="dash-value-row"]').count() === o.top_inventory_value.length && await page.locator('[data-testid="dash-low-row"]').count() === o.top_low_stock.length && await page.locator('[data-testid="dash-recent-row"]').count() === o.recent_activity.length);
        // drawers follow the filter
        await openCard(page, 'closing_stock');
        const s = await sumAllPages(page, baseP({ warehouse_id: String(id) }), 'closing_stock');
        const whCol = await page.locator('[data-testid="dash-drawer-row"]').first().innerText();
        check(`[${label}] Stok Akhir drawer Σ == card and rows belong to this warehouse only`, close2(s.sum, o.movement.closing_stock.value) && (await txt(page, 'dash-drawer-grand-value')) === await fm(o.movement.closing_stock.value) && await page.locator('[data-testid="dash-drawer-warehouse"]').count() === 0, whCol.slice(0, 60));
        await closeDrawer(page);
    }
    const sumWh = (k) => whs.reduce((a, [l]) => a + wApi[l].movement[k].value, 0);
    check('Σ per-warehouse Stok Awal == company-wide (no double count)', close2(sumWh('opening_stock'), m.opening_stock.value), `${sumWh('opening_stock')} vs ${m.opening_stock.value}`);
    check('Σ per-warehouse Pembelian == company-wide', close2(sumWh('purchase_in'), m.purchase_in.value));
    check('Σ per-warehouse Barang Keluar == company-wide', close2(sumWh('stock_out'), m.stock_out.value));
    check('Σ per-warehouse Stok Akhir == company-wide', close2(sumWh('closing_stock'), m.closing_stock.value));
    check('warehouses differ from each other (filter really changes data)', new Set(whs.map(([l]) => wApi[l].movement.closing_stock.value)).size === 3);
    await selectWh(page, '');

    // ============================================ 7. periods: Hari Ini, Custom (hand-verified purchase = 262.000)
    await setPeriod(page, 'today');
    const td = await api(page, '/dashboard/inventory', baseP({ period: 'today' }));
    let okT = true;
    for (const [key] of CARDS) okT = okT && (await mvText(page, key)) === await fm(td.movement[key].value);
    check('Hari Ini: 4 cards == API; period label HARI INI', okT && (await txt(page, 'dash-period-label')).includes('HARI INI'));
    check('Hari Ini: Pembelian today = PO-TODAY 25 × 2.200 = Rp 55.000', close2(td.movement.purchase_in.value, 55000), String(td.movement.purchase_in.value));
    await openCard(page, 'purchase_in');
    const ts = await sumAllPages(page, baseP({ period: 'today' }), 'purchase_in');
    check('Hari Ini: Pembelian drawer Σ == card', close2(ts.sum, td.movement.purchase_in.value) && (await txt(page, 'dash-drawer-grand-value')) === await fm(td.movement.purchase_in.value));
    await closeDrawer(page);
    await page.click('[data-testid="dash-period-custom"]');
    await page.fill('[data-testid="dash-from"]', seed.range.from);
    await page.fill('[data-testid="dash-to"]', seed.range.to);
    await page.click('[data-testid="dash-apply"]');
    await waitDash(page);
    check('Custom range: label shows the range; purchase_in = 110.000+126.000+26.000 = Rp 262.000 (hand-computed)', (await txt(page, 'dash-period-label')).includes(seed.range.from) && (await mvText(page, 'purchase_in')) === rp(262000), await mvText(page, 'purchase_in'));
    const cu = await api(page, '/dashboard/inventory', { warehouse_id: '', period: 'custom', date_from: seed.range.from, date_to: seed.range.to });
    check('Custom range: identity holds and cards == API', cu.movement.reconciliation.ok && (await mvText(page, 'closing_stock')) === await fm(cu.movement.closing_stock.value) && (await mvText(page, 'opening_stock')) === await fm(cu.movement.opening_stock.value));
    for (const [key] of CARDS) {
        await openCard(page, key);
        const s = await sumAllPages(page, { warehouse_id: '', period: 'custom', date_from: seed.range.from, date_to: seed.range.to }, key);
        check(`Custom: [${key}] Σ rows == card`, close2(s.sum, cu.movement[key].value) && (await txt(page, 'dash-drawer-grand-value')) === await fm(cu.movement[key].value));
        await closeDrawer(page);
    }
    // invalid custom range is refused client-side
    await page.fill('[data-testid="dash-from"]', '2026-07-01'); await page.fill('[data-testid="dash-to"]', '2026-06-01');
    const b3 = requests.length; await page.click('[data-testid="dash-apply"]'); await page.waitForTimeout(300);
    check('from > to is rejected with a toast and no request', requests.length === b3 && await page.locator('.toast-error').count() >= 1);
    await setPeriod(page, 'month');

    // ============================================ 8. other cards, Perlu Perhatian, activity, quick actions
    const ov2 = await api(page, '/dashboard/inventory', baseP());
    const attnKeys = ov2.attention.map((a) => a.key);
    check('Perlu Perhatian rows = server items (Stok Habis / Di Bawah Minimum / Dead Stock / Rusak …)', (await page.locator('[data-testid^="dash-attn-"]').count()) === attnKeys.length && attnKeys.includes('below_minimum') && attnKeys.includes('rusak'), attnKeys.join(','));
    const lowAttn = ov2.attention.find((a) => a.key === 'below_minimum');
    check('Di Bawah Minimum shows server SKU count and estimated value', (await txt(page, 'dash-attn-below_minimum')).includes(String(lowAttn.sku_count)) && (await txt(page, 'dash-attn-below_minimum')).includes(await fm(lowAttn.value)));
    await page.click('[data-testid="dash-attn-below_minimum"]');
    await page.waitForSelector('#tab-stok-barang.active', { timeout: 5000 });
    check('Di Bawah Minimum opens Stok Barang with its filter', true);
    await page.evaluate(() => document.querySelector('[data-tab="dashboard"]').click()); await waitDash(page);
    const rusak = ov2.attention.find((a) => a.key === 'rusak');
    await page.click('[data-testid="dash-attn-rusak"]');
    await page.waitForSelector('.drawer.open [data-dash-title]'); await page.waitForSelector('[data-testid="dash-drawer-table"]');
    const rs = await sumAllPages(page, baseP(), 'rusak');
    check('Rusak drawer lists the latest POSTED opname findings; Σ == Perlu Perhatian value (old session ignored)', close2(rs.sum, rusak.value) && close2(rusak.value, 7000) && await page.locator('[data-testid="dash-drawer-row"]').count() === 2, `${rs.sum}/${rusak.value}`);
    await closeDrawer(page);
    const ta = ov2.today_activity;
    check('Aktivitas Hari Ini counts == API (Stock IN / OUT / Transfer Keluar / Transfer Diterima)', (await txt(page, 'dash-act-in')).includes(`${ta.stock_in.count} transaksi`) && (await txt(page, 'dash-act-out')).includes(`${ta.stock_out.count} transaksi`) && (await txt(page, 'dash-act-trf-out')).includes(`${ta.transfer_out.count} transaksi`) && (await txt(page, 'dash-act-trf-in')).includes(`${ta.transfer_in.count} transaksi`) && ta.stock_in.count >= 1 && ta.transfer_in.count >= 1, JSON.stringify(ta));
    const rh = (await page.locator('[data-testid="dash-recent"] thead th').allTextContents()).map((s) => s.trim());
    check('recent activity columns Waktu|Jenis|No. Referensi|Gudang|Jumlah Item|Nilai|Status', rh.join('|') === 'Waktu|Jenis|No. Referensi|Gudang|Jumlah Item|Nilai|Status', rh.join('|'));
    check('Top 5 Stok Menipis / Top 5 Nilai Stok rendered ≤ 5 rows each', await page.locator('[data-testid="dash-low-row"]').count() <= 5 && await page.locator('[data-testid="dash-value-row"]').count() === 5);
    check('Quick Actions shown for SUPERADMIN', await page.locator('[data-testid="dash-quick"] button').count() >= 3);

    // ============================================ 9. stress Rupiah + layouts (desktop, iPad L/P, mobile)
    const stressValue = 900000 * 95000000;
    const layouts = [['desktop-1440', 1440, 900], ['ipad-landscape-1180', 1180, 820], ['ipad-portrait-820', 820, 1180], ['mobile-390', 390, 844]];
    for (const [name, w, h] of layouts) {
        const { context, page: lp } = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        const geo = await lp.evaluate(() => {
            const docOverflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
            const vals = Array.from(document.querySelectorAll('.dash-value')).map((v) => {
                const r = v.getBoundingClientRect(); const c = v.closest('.dash-card').getBoundingClientRect();
                return { t: v.innerText, over: v.scrollWidth - v.clientWidth, inside: r.right <= c.right + 0.5 && r.left >= c.left - 0.5 };
            });
            const mv = Array.from(document.querySelectorAll('[data-testid^="dash-mv-"][role=button]')).map((e) => { const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, vis: r.width > 80 && r.height > 30 }; });
            return { docOverflow, vals, mv };
        });
        check(`[${name}] no horizontal page overflow`, geo.docOverflow <= 1, `overflow=${geo.docOverflow}`);
        check(`[${name}] every Rupiah KPI/movement figure fits inside its card (no overflow/clipping)`, geo.vals.every((v) => v.over <= 1 && v.inside), JSON.stringify(geo.vals.filter((v) => v.over > 1 || !v.inside)));
        check(`[${name}] the 4 movement cards are rendered and readable`, geo.mv.length === 4 && geo.mv.every((c) => c.vis));
        const stressSeen = await lp.evaluate((sv) => Array.from(document.querySelectorAll('.dash-value')).some((v) => v.innerText.replace(/\D/g, '').length >= 14), stressValue);
        check(`[${name}] 14-digit Rupiah value present in the page (stress case)`, stressSeen);
        await lp.screenshot({ path: path.join(shotDir, `dashboard-${name}.png`), fullPage: true });
        // vertical page scroll works
        const pageScrolls = await lp.evaluate(async () => { window.scrollTo(0, 400); await new Promise((r) => setTimeout(r, 100)); return window.scrollY > 0 || document.documentElement.scrollHeight <= window.innerHeight; });
        check(`[${name}] main page scrolls vertically`, pageScrolls);
        // drawer: layout, vertical scroll inside the drawer, horizontal table scroll, lock + cleanup
        await openCard(lp, 'purchase_in').catch(() => {});
        await lp.click('.drawer-close'); await lp.waitForTimeout(200);
        await openCard(lp, 'closing_stock');
        await lp.waitForTimeout(450); // slide-in transition
        const dg = await lp.evaluate(async () => {
            const dr = document.querySelector('.drawer'); const body = dr.querySelector('.drawer-body'); const wrap = dr.querySelector('.dash-table-wrap');
            const rect = dr.getBoundingClientRect();
            body.scrollTop = 0; body.scrollTop = 300; await new Promise((r) => setTimeout(r, 100));
            return {
                fits: rect.top >= 0 && rect.bottom <= window.innerHeight + 1 && rect.right <= window.innerWidth + 1,
                bodyScrollable: body.scrollHeight > body.clientHeight, scrolled: body.scrollTop > 0,
                tableScrollsX: wrap.scrollWidth > wrap.clientWidth, drawerOverflowX: body.scrollWidth - body.clientWidth,
                locked: getComputedStyle(document.documentElement).overflow === 'hidden' && getComputedStyle(document.body).overflow === 'hidden',
                grandVisible: (() => { const g = document.querySelector('[data-testid="dash-drawer-grand"]').getBoundingClientRect(); return g.bottom <= window.innerHeight + 1 && g.top >= 0; })(),
            };
        });
        check(`[${name}] drawer fits the viewport`, dg.fits);
        check(`[${name}] drawer body scrolls vertically (50 rows)`, dg.bodyScrollable && dg.scrolled);
        check(`[${name}] GRAND TOTAL bar stays visible while scrolling`, dg.grandVisible);
        check(`[${name}] page behind the drawer is scroll-locked`, dg.locked);
        check(`[${name}] wide table scrolls horizontally inside its own wrapper (drawer itself has no sideways overflow)`, (w > 520 ? true : dg.tableScrollsX) && dg.drawerOverflowX <= 1, JSON.stringify(dg));
        if (name === 'ipad-portrait-820' || name === 'ipad-landscape-1180') await lp.screenshot({ path: path.join(shotDir, `dashboard-drawer-${name}.png`) });
        await lp.click('.drawer-close'); await lp.waitForTimeout(250);
        const unlocked = await lp.evaluate(() => getComputedStyle(document.documentElement).overflow !== 'hidden' || document.documentElement.scrollHeight <= window.innerHeight);
        check(`[${name}] closing the drawer restores page scrolling`, unlocked);
        await context.close();
    }

    // ============================================ 10. empty state + error state
    await selectWh(page, String(seed.wh.D));
    await setPeriod(page, 'today');
    await openCard(page, 'purchase_in');
    const e = await txt(page, 'dash-drawer-empty').catch(() => '');
    check('Gudang kosong / Hari Ini / Pembelian: empty-state message, GRAND TOTAL Rp 0', e.includes('Tidak ada data untuk periode') && (await txt(page, 'dash-drawer-grand-value')) === 'Rp 0', e);
    await closeDrawer(page);
    check('recent activity empty-state for a warehouse without transactions', (await page.locator('[data-testid="dash-recent-row"]').count()) === 0 && (await page.locator('[data-testid="dash-recent"]').innerText()).includes('Belum ada aktivitas'));
    await page.route('**/api/dashboard/inventory?*', (r) => r.abort());
    await page.click('[data-testid="dash-refresh"]');
    await page.waitForSelector('[data-testid="dash-error"]', { timeout: 10000 });
    check('server unreachable → clear error + retry button (page does not break)', await page.locator('[data-testid="dash-retry"]').count() === 1);
    await page.unroute('**/api/dashboard/inventory?*');
    await page.click('[data-testid="dash-retry"]'); await waitDash(page);
    check('Coba lagi recovers the dashboard', await page.locator('[data-testid="dash-mv-closing_stock"]').count() === 1);

    // ============================================ 11. auth / warehouse scope
    const stock = await newSession(browser, { viewport: { width: 1280, height: 900 }, __name: 'stockA' }, seed.stockA);
    const sel = stock.page.locator('[data-testid="dash-warehouse"]');
    check('STOCK user: Gudang selector locked to own warehouse, no "Semua Gudang"', await sel.isDisabled() && (await sel.locator('option').allTextContents()).every((t) => t.trim() !== 'Semua Gudang') && await sel.inputValue() === String(seed.wh.A));
    const so = await api(stock.page, '/dashboard/inventory', baseP());
    const stockApiA = wApi.SCM;
    check('STOCK user without warehouse_id param still sees ONLY its warehouse (server forced), == SCM numbers', so.scope.warehouse_id === seed.wh.A && close2(so.movement.closing_stock.value, stockApiA.movement.closing_stock.value) && close2(so.movement.opening_stock.value, stockApiA.movement.opening_stock.value));
    const forged = await api(stock.page, '/dashboard/inventory', baseP({ warehouse_id: String(seed.wh.B) }));
    check('STOCK user asking for Cibadak gets its own warehouse, never Cibadak data', forged.scope.warehouse_id === seed.wh.A && !close2(forged.movement.closing_stock.value, wApi.Cibadak.movement.closing_stock.value));
    check('STOCK user: 4 cards render and equal its API', (await mvText(stock.page, 'closing_stock')) === await fm(so.movement.closing_stock.value));
    await stock.context.close();
    const viewer = await newSession(browser, { viewport: { width: 1280, height: 900 }, __name: 'viewer' }, seed.viewer);
    check('VIEWER: dashboard readable, Quick Actions (write shortcuts) hidden', await viewer.page.locator('[data-testid="dash-mv-closing_stock"]').count() === 1 && await viewer.page.locator('[data-testid="dash-quick"]').count() === 0);
    await viewer.context.close();
    const anon = await browser.newContext(); const ap = await anon.newPage();
    await ap.goto(base + '/', { waitUntil: 'load' });
    const unauth = await ap.evaluate(async () => (await fetch('/api/dashboard/inventory')).status);
    const unauth2 = await ap.evaluate(async () => (await fetch('/api/dashboard/inventory/detail?type=closing_stock')).status);
    check('unauthenticated GET /dashboard/inventory[/detail] → 401', unauth === 401 && unauth2 === 401, `${unauth}/${unauth2}`);
    const post = await ap.evaluate(async () => (await fetch('/api/dashboard/inventory', { method: 'POST' })).status);
    check('POST to the dashboard endpoint is not routed (404/405)', post === 404 || post === 405, String(post));
    await anon.close();

    // ============================================ 12. global invariants
    const dashReqs = requests.filter((r) => r.path.startsWith('/dashboard'));
    check(`all ${dashReqs.length} dashboard API calls were GET`, dashReqs.length > 20 && dashReqs.every((r) => r.method === 'GET'));
    check('NO write request (POST/PUT/PATCH/DELETE) left the browser during the whole run', requests.every((r) => r.method === 'GET' || r.path.startsWith('/auth')), JSON.stringify(requests.filter((r) => r.method !== 'GET').slice(0, 5)));
    const realErrors = consoleErrors.filter((c) => !/Failed to load resource.*(401|404|405)|net::ERR_FAILED|aborted/i.test(c));
    check('no console / page errors', realErrors.length === 0, realErrors.slice(0, 5).join(' || '));
} catch (err) {
    console.error('TEST CRASH', err);
    results.push(false);
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
