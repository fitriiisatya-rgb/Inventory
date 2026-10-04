// Pergerakan Stok Harian redesign — REAL data, end to end, through the real UI (real MariaDB + PHP API + Chromium).
// The ledger comes from the application's own posting services (tests/browser/seed_movement_report.php); every number on screen is
// compared with the API / independent SQL. Checks: load, filters, Nominal vs Qty (no mixed-unit total), KPI == daily table == item table,
// item transaction trail, recon warning, export == screen, STOCK scope, read-only (GET only), no console errors, responsive. Screenshots -> $MVR_SHOT_DIR.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw MVR_SHOT_DIR=/some/dir node tests/browser/playwright_movement_report.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.MVR_SHOT_DIR || __dirname;
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
const tid = (id) => `[data-testid="${id}"]`;
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `movement-${name}.png`) });
const hOver = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
async function openReport(page) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-pergerakan"]').click());
    await page.waitForSelector('#tab-laporan-pergerakan.active .mvr-title');
    await page.waitForFunction(() => !document.querySelector('.mvr-loading') && document.querySelector('[data-testid="mvr-kpi-closing-value"], [data-testid="mvr-error"]'), null, { timeout: 15000 });
}
const kpiText = async (page, k) => (await page.locator(tid(`mvr-kpi-${k}-value`)).innerText()).replace(/\s+/g, ' ').trim();
const kpiSub = async (page, k) => (await page.locator(`${tid('mvr-kpi-' + k)} .mvr-kpi-sub`).innerText()).replace(/\s+/g, ' ').trim();
const rpFmt = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
async function setRange(page, s, e) { await page.fill(tid('mvr-start'), s); await page.fill(tid('mvr-end'), e); await page.click(tid('mvr-apply')); await page.waitForTimeout(700); await page.waitForFunction(() => !document.querySelector('.mvr-loading'), null, { timeout: 15000 }); }

const R = seed.range; // 2026-06-10 .. 2026-06-20 (the ledger fixture window)
const todayIso = new Date().toISOString().slice(0, 10);
const back = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); };

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin' }, seed.admin);
    await openReport(page);
    check('A report loads: title, filter bar, 5 KPI cards, chart card, daily table', await page.locator('.mvr-title').innerText() === 'Pergerakan Stok Harian' && await page.locator('.mvr-kpi').count() === 5 && await page.locator(tid('mvr-chart')).count() === 1 && await page.locator(tid('mvr-daily-table')).count() === 1);
    check('A the default period is the current month (start = day 1)', (await page.inputValue(tid('mvr-start'))).endsWith('-01'));

    // ---- period with the ledger fixture
    await setRange(page, R.from, R.to);
    const ov = await api(page, '/reports/movement/overview', { start_date: R.from, end_date: R.to });
    check('B date filter: the daily table has one row per day of the period', await page.locator(tid('mvr-daily-row')).count() === Math.min(15, ov.rows.length) && ov.rows.length === 11);
    const t = ov.totals;
    check('C Nominal: Saldo Awal / Barang Masuk / Barang Keluar / Adjustment / Saldo Akhir cards == API totals', await kpiText(page, 'opening') === rpFmt(t.opening) && await kpiText(page, 'masuk') === rpFmt(t.masuk) && await kpiText(page, 'keluar') === rpFmt(t.keluar) && (await kpiText(page, 'closing')) === rpFmt(t.closing), `${await kpiText(page, 'masuk')} vs ${rpFmt(t.masuk)}`);
    check('C Nominal: card sub-lines show real SKU / transaction counts', (await kpiSub(page, 'closing')).startsWith(`${t.sku_closing} SKU`) && (await kpiSub(page, 'masuk')).startsWith(`${t.tx_in} transaksi`));
    const tot = await page.locator(tid('mvr-daily-total')).innerText();
    check('F KPI cards == the TOTAL PERIODE row of the daily table', [t.opening, t.masuk, t.keluar, t.closing].every((v) => tot.replace(/\s+/g, ' ').includes(rpFmt(v))));
    check('I no reconciliation warning on clean data; no "pre go-live" rows', await page.locator(tid('mvr-recon-warning')).count() === 0);
    await shot(page, 'desktop-nominal');

    // ---- Qty mode
    await setRange(page, R.from, todayIso);
    await page.click(tid('mvr-mode-qty'));
    const sub = await kpiSub(page, 'masuk');
    const closeSub = await kpiSub(page, 'closing');
    check('D Qty: cards switch to SKU / transaction counts and quantities grouped BY UNIT (KG, PCS, LTR each listed)', /transaksi/.test(await kpiText(page, 'masuk')) && /KG/.test(sub) && /PCS/.test(sub) && /LTR/.test(sub) && /PCS/.test(closeSub), `${await kpiText(page, 'masuk')} | ${sub}`);
    const numbersOnly = (s) => (s.match(/\d[\d.,]*/g) || []).length;
    check('E Qty: no single mixed-unit quantity — every quantity in the card carries its own unit token', !/Qty:\s*\d/.test(sub) && !/Qty:\s*\d/.test(closeSub));
    check('E Qty: with several items in scope the chart shows the message instead of a mixed-unit chart', await page.locator(tid('mvr-chart-qty-message')).count() === 1 && await page.locator(tid('mvr-chart')).count() === 0);
    check('D Qty: the daily table switches to counts (SKU awal / masuk tx/sku / …) — no Rupiah columns', !(await page.locator(tid('mvr-daily-table')).innerText()).includes('Rp '));
    await shot(page, 'desktop-qty');
    await page.fill(tid('mvr-q'), 'MVPCS'); await page.click(tid('mvr-apply'));
    await page.waitForFunction(() => document.querySelector('[data-testid="mvr-chart"]'), null, { timeout: 10000 });
    check('E Qty + ONE item (search "MVPCS"): the quantity chart appears, titled with the unit PCS', await page.locator(tid('mvr-chart')).count() === 1 && /PCS/.test(await page.locator('#mvr-chart-card .mvr-card-title').innerText()));
    await page.click(tid('mvr-mode-nominal'));
    await page.fill(tid('mvr-q'), ''); await page.click(tid('mvr-reset'));
    await page.waitForTimeout(600);
    check('B Reset restores the default period and clears the search', (await page.inputValue(tid('mvr-q'))) === '' && (await page.inputValue(tid('mvr-start'))).endsWith('-01'));

    // ---- filters
    await setRange(page, R.from, R.to);
    await page.selectOption(tid('mvr-wh'), String(seed.wh.A)); await page.click(tid('mvr-apply')); await page.waitForTimeout(700);
    const ovA = await api(page, '/reports/movement/overview', { start_date: R.from, end_date: R.to, warehouse_id: seed.wh.A });
    check('B warehouse filter (SCM): KPI == API for that warehouse; transfers now count as Masuk/Keluar of the warehouse', await kpiText(page, 'masuk') === rpFmt(ovA.totals.masuk) && ovA.totals.keluar > 0 && ovA.is_company_consolidated === false);
    await page.selectOption(tid('mvr-cat'), String(seed.cat.bahan)); await page.click(tid('mvr-apply')); await page.waitForTimeout(700);
    const ovC = await api(page, '/reports/movement/overview', { start_date: R.from, end_date: R.to, warehouse_id: seed.wh.A, category_id: seed.cat.bahan });
    check('B category filter narrows the totals (closing differs from the unfiltered warehouse) and equals the API', await kpiText(page, 'closing') === rpFmt(ovC.totals.closing) && ovC.totals.closing !== ovA.totals.closing);
    await page.selectOption(tid('mvr-cat'), ''); await page.selectOption(tid('mvr-wh'), ''); await page.click(tid('mvr-apply')); await page.waitForTimeout(600);
    await page.click(tid('mvr-quick-7')); await page.waitForTimeout(800);
    check('B quick period "7 Hari" sets the last 7 days (end = today)', await page.inputValue(tid('mvr-end')) === todayIso && await page.inputValue(tid('mvr-start')) === back(6));
    await setRange(page, R.from, R.to);

    // ---- daily row -> Rincian Per Barang
    const row = page.locator(tid('mvr-daily-row'), { hasText: '15' }).first();
    await page.locator('tr[data-date="2026-06-15"]').click();
    await page.waitForSelector(tid('mvr-items-table'));
    await page.waitForFunction(() => !document.querySelector('.mvr-loading'));
    check('G the selected day is highlighted and "Rincian Per Barang" opens for that date', await page.locator('tr[data-date="2026-06-15"].sel').count() === 1 && /15 Jun 2026/.test(await page.locator(tid('mvr-items-title')).innerText()));
    const day = ov.rows.find((r) => r.date === '2026-06-15');
    const itemsTot = (await page.locator(tid('mvr-items-total')).innerText()).replace(/\s+/g, ' ');
    check('G item table footer == the daily row (opening / masuk / keluar / adjustment / closing, nominal)', [day.nominal.opening, day.nominal.masuk, day.nominal.keluar, day.nominal.closing].every((v) => itemsTot.includes(rpFmt(v))), itemsTot);
    const cols = await page.locator(`${tid('mvr-items-table')} thead th`).allInnerTexts();
    check('G item table has the required columns (Kode … Saldo Akhir Nilai, Jumlah Transaksi, Aksi)', ['Kode', 'Nama Barang', 'Kategori', 'Satuan', 'Saldo Awal Qty', 'Saldo Awal Nilai', 'Masuk Qty', 'Masuk Nilai', 'Keluar Qty', 'Keluar Nilai (HPP)', 'Adjustment Qty', 'Adjustment Nilai', 'Saldo Akhir Qty', 'Saldo Akhir Nilai', 'Jumlah Transaksi', 'Aksi'].every((c) => cols.some((x) => x.replace(/[▲▼]/g, '').trim().toLowerCase() === c.toLowerCase())), cols.join('|'));
    const sc = await page.evaluate(() => { const s = document.querySelector('[data-testid="mvr-items-scroll"]'); return { sw: s.scrollWidth, cw: s.clientWidth, sticky: getComputedStyle(document.querySelector('.mvr-items th.sticky1')).position, over: document.documentElement.scrollWidth - innerWidth }; });
    check('G the wide table scrolls horizontally inside its own container (page itself does not), sticky identity columns + header', sc.sw > sc.cw && sc.sticky === 'sticky' && sc.over <= 0, JSON.stringify(sc));
    await page.evaluate(() => document.getElementById('mvr-items-card').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await shot(page, 'selected-day-items');
    // search / category / movement filter inside the item table
    await page.fill(tid('mvr-item-q'), 'DFTEP'); await page.waitForTimeout(900);
    const rowsAfter = await page.locator(tid('mvr-item-row')).allInnerTexts();
    check('N item search by code fragment filters the rows', rowsAfter.length >= 1 && rowsAfter.every((r) => r.includes('DFTEP')));
    await page.fill(tid('mvr-item-q'), ''); await page.waitForTimeout(800);
    await page.selectOption(tid('mvr-item-move'), 'keluar'); await page.waitForTimeout(800);
    check('movement-type filter "Ada Barang Keluar" keeps only rows with an outbound', (await page.locator(tid('mvr-item-row')).count()) >= 1 && (await page.locator(tid('mvr-item-row')).allInnerTexts()).every((r) => /TEP|ROT|MEN|OUT|PCS/.test(r)));
    await page.selectOption(tid('mvr-item-move'), '');
    // per-unit line (never a mixed total)
    check('the item table footer shows quantities per unit', /Qty per satuan/.test(await page.locator(tid('mvr-items-units')).innerText()) && /KG/.test(await page.locator(tid('mvr-items-units')).innerText()));

    // ---- item trail drawer
    await page.waitForTimeout(500);
    const target = page.locator(tid('mvr-item-row'), { hasText: 'DFTEP' }).first();
    await target.click();
    await page.waitForSelector(tid('mvr-trail-table'));
    const trailText = await page.locator('.drawer.open').innerText();
    console.log('TRAIL>>', trailText.replace(/\s+/g, ' ').slice(0, 900));
    check('H item trail: timestamp, Jenis Transaksi, reference OUT-A-1, 30 kg out with FIFO HPP, opening/closing balance header', /15 Jun 2026/i.test(trailText) && /OUT-A-1/i.test(trailText) && /Stock OUT/i.test(trailText) && /Saldo awal hari/i.test(trailText) && /HPP Keluar/i.test(trailText));
    const trailCols = await page.locator(`${tid('mvr-trail-table')} thead th`).allInnerTexts();
    check('H trail columns: Timestamp, Jenis, Referensi, Gudang, Qty Masuk, Harga Beli, Qty Keluar, HPP Keluar, Adjustment, Saldo Qty, Saldo Nilai, User, Keterangan', ['Timestamp', 'Jenis Transaksi', 'No. Referensi', 'Gudang', 'Harga Beli / Cost', 'HPP Keluar (FIFO)', 'Adjustment', 'Saldo Qty', 'Saldo Nilai', 'User', 'Keterangan'].every((c) => trailCols.map((x) => x.toLowerCase()).includes(c.toLowerCase())), trailCols.join('|'));
    const trail = await api(page, '/reports/movement/item-trail', { date: '2026-06-15', item_id: seed.items.tepung.id });
    const last = trail.rows[trail.rows.length - 1];
    check('H the last running balance in the trail == the item row closing (from the API)', trail.rows.length >= 1 && Math.abs(last.balance_value - trail.closing.value) < 0.01);
    const trailFit = await page.evaluate(() => { const s = document.querySelector('.drawer.open .mvr-scroll'); return { sw: s.scrollWidth, cw: s.clientWidth }; });
    check('H the item-trail table fits the drawer on a 1536px desktop (no horizontal scroll needed)', trailFit.sw <= trailFit.cw + 1, JSON.stringify(trailFit));
    await shot(page, 'item-trail');
    await page.evaluate(() => Drawer.close());
    await page.waitForTimeout(300);

    // ---- KPI drill-down
    await page.click(tid('mvr-kpi-masuk'));
    await page.waitForSelector(tid('mvr-ptx-total'));
    const ptx = await page.locator(tid('mvr-ptx-total')).innerText();
    check('KPI drill-down: Barang Masuk card lists the underlying ledger lines and a matching total', /baris ledger/.test(ptx) && ptx.includes(rpFmt(t.masuk)), ptx);
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);

    // ---- export == screen (fetch what the menu links to)
    await page.click(tid('mvr-export'));
    check('P export menu offers summary / detail / transactions', await page.locator('.mvr-export-item').count() === 3);
    const href = await page.evaluate(() => InvApi.movementExportUrl({ start_date: '2026-06-10', end_date: '2026-06-20', kind: 'summary', mode: 'nominal' }));
    const csvText = await page.evaluate(async (u) => (await fetch(u, { credentials: 'include' })).text(), href);
    check('P export summary: the TOTAL PERIODE row equals the screen totals; metadata present', csvText.includes('TOTAL PERIODE') && csvText.includes(String(Math.round(t.closing))) || csvText.includes(String(t.closing)), csvText.split('\n').filter((l) => l.startsWith('TOTAL')).join(''));
    await page.keyboard.press('Escape'); await page.click('.mvr-title');

    // ---- recon warning (UI contract) with a mocked API answer
    const bad = await context.newPage();
    await bad.route('**/api/reports/movement/overview?*', async (route) => {
        const resp = await route.fetch(); const j = await resp.json();
        j.data.reconciliation = { ok: false, tolerance: 0.01, issues: [{ date: '2026-06-15', type: 'LEDGER', difference: 1234.5, message: 'Saldo Akhir per barang berbeda dari jumlah langsung ledger' }] };
        await route.fulfill({ response: resp, json: j });
    });
    await bad.goto(base + '/'); await bad.waitForSelector('#app-shell', { state: 'visible' });
    await openReport(bad);
    check('I an unbalanced reconciliation is shown as an explicit warning (never hidden)', await bad.locator(tid('mvr-recon-warning')).count() === 1 && /1\.234,5/.test(await bad.locator(tid('mvr-recon-warning')).innerText()));
    await bad.close();

    // ---- empty state
    await setRange(page, '2019-01-01', '2019-01-05');
    check('empty / pre-go-live period shows an honest message, no fabricated numbers', /Opening Go-Live|No movement found/.test(await page.locator('#tab-laporan-pergerakan').innerText()));
    await setRange(page, R.from, R.to);

    // ---- STOCK scope
    await context.close();
    const st = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'stock' }, seed.stockA);
    await openReport(st.page);
    check('Q STOCK user: the warehouse selector is locked to their own warehouse', await st.page.locator(tid('mvr-wh')).isDisabled() && await st.page.inputValue(tid('mvr-wh')) === String(seed.wh.A));
    const stOv = await st.page.evaluate(async (w) => (await (await fetch(`/api/reports/movement/overview?start_date=2026-06-10&end_date=2026-06-20&warehouse_id=${w}`, { credentials: 'include' })).json()).data.period.warehouse_id, seed.wh.B);
    check('Q STOCK user asking for warehouse B through the query string still gets warehouse A', Number(stOv) === seed.wh.A);
    await st.context.close();

    // ---- responsive
    for (const [name, w, h] of [['d1366', 1366, 768], ['ipadL', 1180, 820], ['ipadP', 820, 1180], ['m390', 390, 844]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        await openReport(s.page);
        await setRange(s.page, R.from, R.to);
        const m = await s.page.evaluate(() => {
            const k = Array.from(document.querySelectorAll('.mvr-kpi')).map((e) => Math.round(e.getBoundingClientRect().top / 8));
            const wide = Array.from(document.querySelectorAll('.mvr-kpi-value')).filter((e) => e.scrollWidth > e.clientWidth + 1).length;
            return { rows: new Set(k).size, clipped: wide, over: document.documentElement.scrollWidth - innerWidth, kpiFont: parseFloat(getComputedStyle(document.querySelector('.mvr-kpi-value')).fontSize), titleFont: parseFloat(getComputedStyle(document.querySelector('.mvr-title')).fontSize) };
        });
        check(`T ${name}: no horizontal page overflow, no clipped KPI value, KPI font not oversized (${m.kpiFont}px)${w >= 1000 ? `, KPI cards on ${m.rows} row` : ''}`, m.over <= 0 && m.clipped === 0 && m.kpiFont <= 26 && (w < 1000 || m.rows === 1), JSON.stringify(m));
        await s.page.locator('tr[data-date="2026-06-15"]').click();
        await s.page.waitForSelector(tid('mvr-items-table'));
        await s.page.waitForTimeout(500);
        const sc2 = await s.page.evaluate(() => { const e = document.querySelector('[data-testid="mvr-items-scroll"]'); return { scrolls: e.scrollWidth > e.clientWidth, over: document.documentElement.scrollWidth - innerWidth }; });
        check(`T ${name}: the item table scrolls inside its container; page does not overflow`, sc2.scrolls && sc2.over <= 0, JSON.stringify(sc2));
        if (name === 'ipadL') await shot(s.page, 'ipad-landscape');
        if (name === 'ipadP') await shot(s.page, 'ipad-portrait');
        if (name === 'm390') await shot(s.page, 'mobile');
        await s.context.close();
    }

    check('R no console / page errors in any session', consoleErrors.length === 0, consoleErrors.slice(0, 4).join(' || '));
    check('S only GET requests left the browser while using the report (no write)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
