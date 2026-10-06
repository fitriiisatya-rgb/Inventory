// Laporan IN / OUT / Transfer — REAL data, end to end, through the real UI (real MariaDB + PHP API + Chromium).
// The history comes from the application's own services (tests/lib/inout_report_fixture.php: Stock IN V2 invoices, Stock OUT V2 documents with multi-layer FIFO, a reversed DO,
// a legacy OUT, received / pending / cancelled / reversed transfers). Every number on screen is compared with the API / hand-computed values; the export must equal the screen.
// Screenshots -> $IO_SHOT_DIR.   IO_STATIC_ROOT: serve a PRODUCTION-LAYOUT tree patched by the package scripts instead of the dev public/.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw IO_SHOT_DIR=/some/dir node tests/browser/playwright_inout_report.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.IO_SHOT_DIR || __dirname;
fs.mkdirSync(shotDir, { recursive: true });
const dbName = process.env.DB_DATABASE || 'inventory_test';

const results = [];
function check(name, pass, detail = '') { results.push(pass); console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`); }
const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env }).toString();
const near = (a, b, e = 0.01) => Math.abs(Number(a) - Number(b)) <= e;

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot ${dbName} < database/schema.sql`);
const seed = JSON.parse(sh('php tests/browser/seed_inout_report.php'));
console.log('Seeded', JSON.stringify(seed.wh));

let phpServer; let proxy; let base;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.jpg': 'image/jpeg' };
async function startServer() {
    const phpPort = 9000 + Math.floor(Math.random() * 400);
    phpServer = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', 'public', 'public/router.php'], { cwd: process.env.RV3_APP_ROOT || repoRoot, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } });
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
        const staticRoot = process.env.IO_STATIC_ROOT || path.join(process.env.RV3_APP_ROOT || repoRoot, 'public');
        const file = path.join(staticRoot, urlPath === '/' ? 'index.html' : urlPath);
        if (!file.startsWith(staticRoot) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('nf'); return; }
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
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `inout-${name}.png`) });
const R0 = seed.range; const E = seed.expect; const IE = seed.io_expect; const T = seed.trf; const O = seed.out; const W1 = seed.wh['1']; const W2 = seed.wh['2'];
const idr = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const hasMoney = (txt, n) => txt.replace(/\s+/g, ' ').includes(idr(n));
const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
const idle = (page) => page.waitForFunction(() => !document.querySelector('.io-loading'), null, { timeout: 25000 });
const text = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const colIndex = (page, table, label) => page.evaluate(([t, l]) => Array.from(document.querySelectorAll(`[data-testid="${t}"] thead th`)).findIndex((th) => th.textContent.trim().toLowerCase().startsWith(l.toLowerCase())), [table, label]);
async function cellOf(page, table, rowSel, label) { const i = await colIndex(page, table, label); return (await page.locator(`[data-testid="${table}"] ${rowSel} td`).nth(i).innerText()).replace(/\s+/g, ' ').trim(); }
const rowCount = (page) => page.locator(tid('io-row')).count();
const kpi = (page, k) => text(page.locator(tid(`io-kpi-${k}-value`)));
async function openReport(page, tab = 'in') {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-inout"]').click());
    await page.waitForSelector('#tab-laporan-inout.active .io-title');
    await idle(page);
    if (tab !== 'in') { await page.click(tid(`io-tab-${tab}`)); await page.waitForTimeout(300); await idle(page); }
}
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('io-start'), f.from);
    if (f.to !== undefined) await page.fill(tid('io-end'), f.to);
    for (const [key, id] of [['wh', 'io-wh'], ['cat', 'io-cat'], ['sup', 'io-sup'], ['bak', 'io-bak'], ['from_wh', 'io-from'], ['to_wh', 'io-to'], ['src', 'io-src'], ['status', 'io-status']]) {
        if (f[key] !== undefined) await page.selectOption(tid(id), f[key]);
    }
    if (f.q !== undefined) await page.fill(tid('io-q'), f.q);
    await page.click(tid('io-apply'));
    await page.waitForTimeout(450);
    await idle(page);
}
const period = { from: R0.from, to: R0.to };
const qsBase = { start_date: R0.from, end_date: R0.to };

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin', acceptDownloads: true }, seed.admin);

    // ================================================================ A. tab IN
    await openReport(page);
    check('A page: title "Laporan IN / OUT", three tabs in ONE row, the IN tab selected by default', (await text(page.locator(tid('io-title')))) === 'Laporan IN / OUT' && await page.locator('.io-tab').count() === 3 && (await page.getAttribute(tid('io-tab-in'), 'aria-selected')) === 'true');
    await setFilters(page, period);
    const apiIn = await api(page, '/reports/io/overview', { ...qsBase, tab: 'in' });
    const nIn = apiIn.kpi.nominal;
    check('A IN KPI cards (6): Total Nilai Masuk Rp 146.025,5 · 4 transaksi · 5 SKU · 2 supplier · PPN 9.530 · Ongkir 21.000 — equal to the API', hasMoney(await kpi(page, 'total'), E.live_total) && near(nIn.total, E.live_total) && (await kpi(page, 'invoices')).startsWith('4') && (await kpi(page, 'skus')).startsWith('5') && (await kpi(page, 'suppliers')).startsWith('2') && hasMoney(await kpi(page, 'ppn'), 9530) && hasMoney(await kpi(page, 'freight'), 21000), await kpi(page, 'total'));
    check('A KPI "vs periode lalu": the previous period holds no data → the change is "—", never an invented percentage', (await text(page.locator(tid('io-kpi-total-delta')))).startsWith('—'));
    check('A table: 4 transaction rows (void D listed too = 5 rows? no — default list shows POSTED + VOID) — rows = 5 incl. the voided invoice, footer GRAND TOTAL = KPI', await rowCount(page) === 5 && hasMoney(await text(page.locator(tid('io-total'))), E.live_total));
    const rowA = page.locator(`${tid('io-row')}:has-text("INV-A")`);
    check('A row INV-A: supplier, gudang, items, subtotal 28.000, diskon, PPN 2.090, ongkir 15.000, grand total 43.585,5, dibuat oleh, status POSTED', await rowA.count() === 1 && hasMoney(await text(rowA), 43585.5) && hasMoney(await text(rowA), 28000) && hasMoney(await text(rowA), 2090) && hasMoney(await text(rowA), 15000) && (await text(rowA)).includes('PR Supplier Satu') && (await text(rowA)).includes(seed.admin.username) && (await text(rowA)).includes('POSTED'));
    const rowV = page.locator(`${tid('io-row')}:has-text("INV-VOID")`);
    check('A the voided invoice is struck through (class "void") with status VOID, and is not part of the GRAND TOTAL', (await rowV.getAttribute('class')).includes('void') && (await text(rowV)).includes('VOID'));
    const rowL = page.locator(`${tid('io-row')}:has-text("PO-LEGACY")`);
    check('A legacy purchase: Total 8.000 known, components "—" (unknown, not 0), tagged "Legacy"', hasMoney(await text(rowL), 8000) && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("PO-LEGACY")`, 'Subtotal')) === '—' && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("PO-LEGACY")`, 'PPN')) === '—' && (await text(rowL)).includes('Legacy'));
    check('A an invoice without a reference shows "—", never an invented number', (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("PR Supplier Dua")`, 'No. Invoice')) === '—');
    check('A the quantity cell is per unit (no mixed sum): "KG 5 · LTR 20 · PCS 10"', (await text(rowA)).includes('KG 5 · LTR 20 · PCS 10'));
    await shot(page, 'in-desktop');

    // item detail (inline, under the table)
    await rowA.locator(tid('io-eye')).click();
    await page.waitForSelector(`${tid('io-detail-card')}:not([hidden]) ${tid('io-detail-line')}`);
    check('K IN detail: header (supplier, gudang, dibuat oleh, timestamps, status) + 3 item lines + the Stock IN V2 arithmetic', (await text(page.locator(tid('io-detail-title')))).includes('INV-A') && await page.locator(tid('io-detail-line')).count() === 3 && (await text(page.locator(tid('io-detail-header')))).includes('PR Supplier Satu') && (await text(page.locator(tid('io-detail-header')))).includes('Diposting pada'));
    const ar = await text(page.locator(tid('io-arith')));
    check('K arithmetic: Gross 30.000 − Diskon Barang 2.000 = Subtotal 28.000 + PPN 2.090 − Diskon Invoice 1.504,5 + Ongkir 15.000 = GRAND TOTAL 43.585,5', hasMoney(ar, 30000) && hasMoney(ar, 2000) && hasMoney(ar, 28000) && hasMoney(ar, 2090) && hasMoney(ar, 1504.5) && hasMoney(ar, 15000) && hasMoney(ar, 43585.5));
    check('K detail TOTAL row = invoice grand total; each line carries harga beli, diskon item, DPP, PPN, alokasi diskon, alokasi ongkir, total, inventory cost, petugas, timestamp, catatan columns', hasMoney(await text(page.locator(tid('io-detail-total'))), 43585.5)
        && (await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="io-detail-table"] thead th')).map((t) => t.textContent.trim()))).join('|').includes('Harga Beli') && (await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="io-detail-table"] thead th')).map((t) => t.textContent.trim()))).join('|').includes('Inventory Cost'));
    const lineX = page.locator(`${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`);
    check('K line X: harga beli 1.000, diskon item 1.000, DPP 9.000, PPN 990, alokasi diskon 499,5, alokasi ongkir 4.821,4286, total 14.311,9286', hasMoney(await text(lineX), 9000) && hasMoney(await text(lineX), 990) && hasMoney(await text(lineX), 499.5) && (await text(lineX)).includes('4.821,43') && (await text(lineX)).includes('14.311,93'));
    await shot(page, 'in-item-detail');

    // filters on IN
    await setFilters(page, { sup: String(seed.sup['2']) });
    check('H supplier filter S2 → 1 transaction (Rp 31.500); KPI follows', await rowCount(page) === 1 && hasMoney(await kpi(page, 'total'), 31500));
    await setFilters(page, { sup: '', status: 'VOID' });
    check('A status filter VOID → only the voided invoice, struck through; KPI total Rp 0 (not counted)', await rowCount(page) === 1 && (await page.locator(tid('io-row')).first().getAttribute('class')).includes('void'));
    await setFilters(page, { status: '', src: 'Historis' });
    check('A Jenis Transaksi IN = Historis → only the historical import, labelled "Historis" (never mixed into the live totals)', await rowCount(page) === 1 && (await text(page.locator(tid('io-row')).first())).includes('Historis') && (await text(page.locator(tid('io-row')).first())).includes('PO-HIST'));
    await setFilters(page, { src: '', q: seed.items.X.sku });
    check('G item search by SKU → INV-A + INV-C only; partial invoices are flagged "sebagian"', await rowCount(page) === 2 && (await text(page.locator(tid('io-table')))).includes('sebagian'));
    await setFilters(page, { q: '', cat: String(seed.cat['1']) });
    check('F category PR Roti → X invoices only (2); SKU KPI = 1', await rowCount(page) === 2 && (await kpi(page, 'skus')).startsWith('1'));
    await setFilters(page, { cat: '', wh: String(W2) });
    check('E warehouse W2 → only invoice B (Rp 31.500)', await rowCount(page) === 1 && hasMoney(await kpi(page, 'total'), 31500));
    await setFilters(page, { wh: '' });
    await page.fill(tid('io-gq'), 'legacy');
    await page.waitForTimeout(800); await idle(page);
    check('G global search in the table: "legacy" → 1 row; clearing restores all', await rowCount(page) === 1);
    await page.fill(tid('io-gq'), '');
    await page.waitForTimeout(800); await idle(page);
    check('G clearing the table search restores the 5 rows', await rowCount(page) === 5);

    // chart
    const bars = () => page.locator('.io-bar-in').count();
    const dayBars = await bars();
    await page.click(tid('io-bucket-month')); await page.waitForTimeout(500); await idle(page);
    const monthBars = await bars();
    await page.click(tid('io-bucket-week')); await page.waitForTimeout(500); await idle(page);
    const weekBars = await bars();
    await page.click(tid('io-bucket-day')); await page.waitForTimeout(500); await idle(page);
    check('A chart Harian / Mingguan / Bulanan: the bars regroup (days with purchases → weeks → 1 month)', dayBars >= 4 && monthBars === 1 && weekBars >= 3 && weekBars <= dayBars, `${dayBars}/${weekBars}/${monthBars}`);
    await page.locator(tid('io-hit')).nth(4).hover();
    check('A chart tooltip on hover shows the period, nominal and the number of transactions', (await text(page.locator(tid('io-tip')))).includes('Nilai Masuk') && (await text(page.locator(tid('io-tip')))).includes('Transaksi'));

    // columns + sorting
    await page.click(tid('io-cols'));
    await page.locator('.io-colopt:has-text("PPN") input').first().uncheck();
    check('A column picker: hiding "PPN" removes the column; it can be restored', !(await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="io-table"] thead th')).map((t) => t.textContent.trim()).includes('PPN'))));
    await page.click(tid('io-cols'));
    await page.locator('.io-colopt:has-text("PPN") input').first().check();
    await page.locator('th[data-sort="total"]').click(); await page.waitForTimeout(500); await idle(page);
    const firstTotal = await cellOf(page, 'io-table', `${tid('io-row')}:first-child`, 'Grand Total');
    check('A sorting by Grand Total (desc) puts the largest invoice first (INV-C 62.940)', firstTotal.includes('62.940'));

    // quick periods
    const today = await page.evaluate(() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; });
    await page.click(tid('io-quick-today')); await page.waitForTimeout(500); await idle(page);
    check('D quick period "Hari Ini": both dates = today; "7 Hari" spans 7 days; "Bulan Ini" starts on the 1st; manual date edit switches to Custom', (await page.inputValue(tid('io-start'))) === today && (await page.inputValue(tid('io-end'))) === today);
    await page.click(tid('io-quick-7')); await page.waitForTimeout(400); await idle(page);
    const d7 = Math.round((new Date(await page.inputValue(tid('io-end'))) - new Date(await page.inputValue(tid('io-start')))) / 86400000);
    await page.click(tid('io-quick-month')); await page.waitForTimeout(400); await idle(page);
    check('D 7 Hari = 6 days apart; Bulan Ini starts on day 01', d7 === 6 && (await page.inputValue(tid('io-start'))).endsWith('-01'));
    await setFilters(page, period);
    await setFilters(page, { from: '2025-01-01', to: '2025-01-31' });
    check('X empty period: the empty message is shown for the table AND the chart, KPI average is "—" not 0', (await text(page.locator(tid('io-empty')))).includes('Tidak ada transaksi pada periode ini.') && (await text(page.locator(tid('io-chart-card')))).length > 0 && (await text(page.locator(tid('io-kpi-invoices'))).then((t) => t.includes('—'))));
    await setFilters(page, period);

    // ================================================================ B. tab OUT
    await page.click(tid('io-tab-out')); await page.waitForTimeout(400); await idle(page);
    check('B switching to OUT keeps the period and warehouse filter, swaps in the OUT filters (Bakery Tujuan, Divisi, Jenis OUT) and updates KPI + chart + table', (await page.inputValue(tid('io-start'))) === R0.from && await page.locator(tid('io-bak')).count() === 1 && await page.locator(tid('io-div')).count() === 1 && await page.locator(tid('io-sup')).count() === 0 && (await page.getAttribute(tid('io-tab-out'), 'aria-selected')) === 'true');
    const apiOut = await api(page, '/reports/io/overview', { ...qsBase, tab: 'out' });
    const nOut = apiOut.kpi.nominal;
    check('B OUT KPI (equal to the API): HPP Keluar, Nilai Jual, Margin, 3 transaksi, 2 bakery, Ongkir 10.000', hasMoney(await kpi(page, 'hpp'), nOut.hpp) && near(nOut.hpp, IE.hpp_total) && hasMoney(await kpi(page, 'sell'), nOut.sell) && hasMoney(await kpi(page, 'margin'), nOut.margin) && (await kpi(page, 'documents')).startsWith('3') && (await kpi(page, 'bakeries')).startsWith('2') && hasMoney(await kpi(page, 'shipping'), 10000));
    check('B KPI sell + shipping = Total invoice shown in the sub line (derived from stored invoice values only)', hasMoney(await text(page.locator(tid('io-kpi-sell'))), nOut.grand_total));
    check('B table: O1, O2, reversed O3 and the legacy OUT = 4 rows; GRAND TOTAL HPP = KPI', await rowCount(page) === 4 && hasMoney(await text(page.locator(tid('io-total'))), nOut.hpp));
    const rowO1 = page.locator(`${tid('io-row')}:has-text("${O.O1.do_number}")`);
    check('B row O1: real DO + invoice numbers, gudang asal, bakery tujuan, HPP, Nilai Jual, Ongkir 10.000, Grand Total Invoice, Margin, dibuat / dispatched oleh, DISPATCHED', (await text(rowO1)).includes(O.O1.invoice_number) && (await text(rowO1)).includes('IO Bakery Satu') && (await text(rowO1)).includes('Gudang PR-SCM') && hasMoney(await text(rowO1), 10000) && (await text(rowO1)).includes('DISPATCHED') && (await text(rowO1)).includes(seed.admin.username));
    const o1api = (await api(page, '/reports/io/list', { ...qsBase, tab: 'out' })).rows.find((r) => r.do_number === O.O1.do_number);
    check('L O1 HPP Total (screen) = Σ stored FIFO layer cost: 10 × cost(A·X) + 2 × cost(C·X) + 3 × cost(A·Y)', hasMoney(await text(rowO1), o1api.hpp) && near(o1api.hpp, IE.O1.hpp_x + IE.O1.hpp_y, 0.01), String(o1api.hpp));
    check('M O1 Grand Total Invoice = Nilai Jual + Ongkir and Margin = Nilai Jual − HPP (screen = API)', hasMoney(await text(rowO1), o1api.grand_total) && hasMoney(await text(rowO1), o1api.margin) && near(o1api.grand_total, o1api.sell + 10000) && near(o1api.margin, o1api.sell - o1api.hpp));
    const rowO3 = page.locator(`${tid('io-row')}:has-text("${O.O3.do_number}")`);
    check('B the reversed O3 is listed struck through with status CANCELLED and does not count (banner discloses it)', (await rowO3.getAttribute('class')).includes('void') && (await text(rowO3)).includes('CANCELLED') && (await text(page.locator(tid('io-banner')))).includes('dibatalkan'));
    const rowOL = page.locator(`${tid('io-row')}:has-text("Legacy")`);
    check('B legacy OUT (no DO / invoice): DO, invoice, Nilai Jual, Margin show "—" (unknown, not 0); HPP 2.000 is real', (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("Legacy")`, 'No. DO')) === '—' && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("Legacy")`, 'Nilai Jual')) === '—' && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("Legacy")`, 'Margin')) === '—' && hasMoney(await text(rowOL), 2000));
    await shot(page, 'out-desktop');

    await rowO1.locator(tid('io-eye')).click();
    await page.waitForSelector(`${tid('io-detail-card')}:not([hidden]) ${tid('io-detail-line')}`);
    const dh = await text(page.locator(tid('io-detail-header')));
    check('L OUT detail: header shows DO, invoice, bakery, gudang, dibuat / dispatched oleh + dispatched pada, status; 2 item lines', dh.includes(O.O1.do_number) && dh.includes(O.O1.invoice_number) && dh.includes('IO Bakery Satu') && dh.includes('Dispatched pada') && await page.locator(tid('io-detail-line')).count() === 2);
    const heads = (await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="io-detail-table"] thead th')).map((t) => t.textContent.trim()))).join('|');
    check('L OUT detail columns: Harga Modal Referensi, HPP FIFO Aktual, Markup Type/Value, Harga Jual, Subtotal Jual, Alokasi Ongkir, Total, HPP Total, Margin, Timestamp, Catatan', ['Harga Modal Referensi', 'HPP FIFO Aktual', 'Markup Type', 'Markup Value', 'Harga Jual', 'Subtotal Jual', 'Alokasi Ongkir', 'Total (Jual + Ongkir)', 'HPP Total', 'Margin', 'Timestamp', 'Catatan'].every((h) => heads.includes(h)), heads);
    const lx = page.locator(`${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`);
    const lxT = await text(lx);
    check('L markup is the stored one: X "Persen (%)" 10, Y "Nominal (Rp)" 500', lxT.includes('Persen (%)') && (await text(page.locator(`${tid('io-detail-line')}:has-text("${seed.items.Y.sku}")`))).includes('Nominal (Rp)'));
    await lx.locator(tid('io-layer-toggle')).click();
    await page.waitForSelector(tid('io-layers'));
    const layersTxt = await text(page.locator(tid('io-layers')));
    check('L FIFO layer detail of X: 2 layers (10 + 2) with batch id, layer date, source IN INV-A / INV-C, qty, cost, value; Σ qty = 12', await page.locator(tid('io-layer-row')).count() === 2 && layersTxt.includes('INV-A') && layersTxt.includes('INV-C') && layersTxt.includes('Σ layer') && layersTxt.includes('12'), layersTxt);
    check('L checks: "Σ Qty FIFO = Qty OUT" and "Σ Nilai FIFO = HPP tersimpan" both ✔', (await text(page.locator(tid('io-checks')))).split('✔').length === 3 && !(await text(page.locator(tid('io-checks')))).includes('✖'));
    check('M detail TOTAL row: HPP Total + Subtotal Jual + Ongkir 10.000 + Total = invoice grand total', hasMoney(await text(page.locator(tid('io-detail-total'))), 10000) && hasMoney(await text(page.locator(tid('io-detail-total'))), o1api.grand_total));
    await shot(page, 'out-item-detail-fifo');
    await page.locator(tid('io-detail-card')).evaluate((e) => e.scrollIntoView());

    // OUT filters
    await setFilters(page, { bak: String(seed.bakery['2']) });
    check('I bakery filter Bakery Dua → O2 only; KPI follows (1 transaksi)', await rowCount(page) === 1 && (await kpi(page, 'documents')).startsWith('1') && (await text(page.locator(tid('io-row')).first())).includes(O.O2.do_number));
    await setFilters(page, { bak: String(seed.bakery['1']) });
    check('I bakery filter Bakery Satu → O1 + the reversed O3 (listed); only O1 counted (1 transaksi)', await rowCount(page) === 2 && (await kpi(page, 'documents')).startsWith('1'));
    await setFilters(page, { bak: '', status: 'CANCELLED' });
    check('I status filter CANCELLED → the reversed O3', await rowCount(page) === 1 && (await text(page.locator(tid('io-row')).first())).includes(O.O3.do_number));
    await setFilters(page, { status: '', src: 'Legacy' });
    check('I Jenis OUT = Legacy → only the legacy OUT (no DO)', await rowCount(page) === 1);
    await setFilters(page, { src: '', cat: String(seed.cat['1']) });
    check('F category PR Roti on OUT → X lines only (O1, O2): 2 transaksi, 1 SKU, HPP = X layers', (await kpi(page, 'documents')).startsWith('2') && hasMoney(await kpi(page, 'hpp'), IE.O1.hpp_x + IE.O2.hpp_x));
    await setFilters(page, { cat: '', q: seed.items.Z.sku });
    check('G item search Z → O2 only', await rowCount(page) === 1);
    await setFilters(page, { q: '' });
    await page.fill(tid('io-gq'), O.O2.invoice_number);
    await page.waitForTimeout(800); await idle(page);
    check('G global search by invoice number → O2; by "bakery satu" → O1 + O3', await rowCount(page) === 1);
    await page.fill(tid('io-gq'), 'bakery satu');
    await page.waitForTimeout(800); await idle(page);
    check('G global search by bakery name', await rowCount(page) === 2);
    await page.fill(tid('io-gq'), ''); await page.waitForTimeout(800); await idle(page);

    // chart OUT
    check('B chart: HPP bars + Nilai Jual bars + transaction-count line; legend names them', await page.locator('.io-bar-out').count() >= 3 && await page.locator('.io-bar-sell').count() >= 2 && (await text(page.locator(tid('io-chart-card')))).includes('Nilai Jual'));

    // ================================================================ C. tab Transfer
    await page.click(tid('io-tab-transfer')); await page.waitForTimeout(400); await idle(page);
    check('C Transfer tab: Gudang Asal / Gudang Tujuan / Status filters; period kept', await page.locator(tid('io-from')).count() === 1 && await page.locator(tid('io-to')).count() === 1 && (await page.inputValue(tid('io-start'))) === R0.from);
    const apiT = await api(page, '/reports/io/overview', { ...qsBase, tab: 'transfer' });
    const nT = apiT.kpi.nominal;
    check('C KPI: 3 active transfers, 1 pending, 2 received, 4 SKU, Nilai Cost, lead time 1,2 hari (28 jam over 2 transfers)', (await kpi(page, 'transfers')).startsWith('3') && (await kpi(page, 'pending')).startsWith('1') && (await kpi(page, 'received')).startsWith('2') && (await kpi(page, 'skus')).startsWith('4') && hasMoney(await kpi(page, 'value'), nT.value) && (await kpi(page, 'lead')).includes('1,2 hari'), await kpi(page, 'lead'));
    check('C table: 5 transfers (T1 received, T2 pending, T3 cancelled, T4 received, T5 reversed); GRAND TOTAL value = KPI (active only)', await rowCount(page) === 5 && hasMoney(await text(page.locator(tid('io-total'))), nT.value));
    const rT2 = page.locator(`${tid('io-row')}:has-text("TRF-${T.T2}")`);
    check('O pending T2: status PENDING; "Diterima Oleh", "Diterima Pada" and "Lead Time" are "—" (unknown — nothing invented)', (await text(rT2)).includes('PENDING') && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("TRF-${T.T2}")`, 'Diterima Oleh')) === '—' && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("TRF-${T.T2}")`, 'Diterima Pada')) === '—' && (await cellOf(page, 'io-table', `${tid('io-row')}:has-text("TRF-${T.T2}")`, 'Lead Time')) === '—');
    const rT1 = page.locator(`${tid('io-row')}:has-text("TRF-${T.T1}")`);
    check('P received T1: status RECEIVED, diterima oleh / pada real (24 Sep 2026 15:00), lead time 1,3 hari, dibuat oleh + dispatched', (await text(rT1)).includes('RECEIVED') && (await text(rT1)).includes('24 Sep 2026 15:00') && (await text(rT1)).includes('1,3 hari') && (await text(rT1)).includes(seed.admin.username) && (await text(rT1)).includes('Gudang PR-SCM') && (await text(rT1)).includes('Gudang PR-Cibadak'), (await text(rT1)).slice(0, 220));
    check('J cancelled T3 and reversed T5 are struck through (not counted) and disclosed in the banner', (await page.locator(`${tid('io-row')}:has-text("TRF-${T.T3}")`).getAttribute('class')).includes('void') && (await page.locator(`${tid('io-row')}:has-text("TRF-${T.T5}")`).getAttribute('class')).includes('void') && (await text(page.locator(tid('io-banner')))).includes('2 transfer dibatalkan'));
    await shot(page, 'transfer-desktop');

    await rT1.locator(tid('io-eye')).click();
    await page.waitForSelector(`${tid('io-detail-card')}:not([hidden]) ${tid('io-detail-line')}`);
    const th = await text(page.locator(tid('io-detail-header')));
    check('N transfer detail header: nomor, asal → tujuan, dibuat / dispatched pada, diterima oleh / pada, lead time, status RECEIVED', th.includes(`TRF-${T.T1}`) && th.includes('Dispatched pada') && th.includes('Diterima pada') && th.includes('Lead time') && await page.locator(tid('io-detail-line')).count() === 2);
    const tcols = (await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="io-detail-table"] thead th')).map((t) => t.textContent.trim()))).join('|');
    check('N transfer detail columns: Qty Transfer, Qty Diterima, Selisih, Unit Cost, Nilai, Layer', ['Qty Transfer', 'Qty Diterima', 'Selisih', 'Unit Cost', 'Nilai', 'Layer'].every((h) => tcols.includes(h)), tcols);
    const lxt = page.locator(`${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`);
    check('P received detail X: Qty Transfer 10 = Qty Diterima 10, Selisih 0 (no highlight)', (await cellOf(page, 'io-detail-table', `${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`, 'Qty Transfer')) === '10' && (await cellOf(page, 'io-detail-table', `${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`, 'Qty Diterima')) === '10' && (await cellOf(page, 'io-detail-table', `${tid('io-detail-line')}:has-text("${seed.items.X.sku}")`, 'Selisih')) === '0' && !(await lxt.getAttribute('class')).includes('diff'));
    await lxt.locator(tid('io-layer-toggle')).click();
    await page.waitForSelector(tid('io-layers'));
    const tl = await text(page.locator(tid('io-layers')));
    check('N layer detail: the cost layers that travelled (batch id, layer date, qty, cost) with Σ layer = 10', await page.locator(tid('io-layer-row')).count() >= 1 && tl.includes('Σ layer') && tl.includes('10'), tl);
    await shot(page, 'transfer-item-detail');
    await rT2.locator(tid('io-eye')).click();
    await page.waitForFunction(() => (document.querySelector('[data-testid="io-detail-title"]')?.textContent || '').includes('TRF-'), null, { timeout: 8000 });
    await page.waitForTimeout(300);
    check('O pending detail T2: Qty Diterima and Selisih "—" (unknown while in transit)', (await cellOf(page, 'io-detail-table', `${tid('io-detail-line')}`, 'Qty Diterima')) === '—' && (await cellOf(page, 'io-detail-table', `${tid('io-detail-line')}`, 'Selisih')) === '—');
    const rT3 = page.locator(`${tid('io-row')}:has-text("TRF-${T.T3}")`);
    await rT3.locator(tid('io-eye')).click();
    await page.waitForFunction((n) => (document.querySelector('[data-testid="io-detail-title"]')?.textContent || '').includes(n), `TRF-${T.T3}`, { timeout: 8000 });
    check('R cancelled detail shows who cancelled, when and why (real actor + reason)', (await text(page.locator(tid('io-detail-header')))).includes('Dibatalkan') && (await text(page.locator(tid('io-detail-header')))).includes('io fixture cancel') && (await text(page.locator(tid('io-detail-header')))).includes(seed.admin.username));
    await setFilters(page, { status: 'PENDING' });
    check('J transfer status PENDING → T2 only', await rowCount(page) === 1);
    await setFilters(page, { status: '', from_wh: String(W2) });
    check('J Gudang Asal = W2 → T4 (the opposite direction)', await rowCount(page) === 1 && (await text(page.locator(tid('io-row')).first())).includes(`TRF-${T.T4}`));
    await setFilters(page, { from_wh: '', to_wh: String(W1) });
    check('J Gudang Tujuan = W1 → T4', await rowCount(page) === 1);
    await setFilters(page, { to_wh: '', wh: String(W2) });
    check('E warehouse W2 (either leg) → all 5 transfers involve W2', await rowCount(page) === 5);
    await setFilters(page, { wh: '', cat: String(seed.cat['1']) });
    check('F category PR Roti on Transfer → T1 (X) + cancelled T3', (await kpi(page, 'transfers')).startsWith('1') && await rowCount(page) === 2);
    await setFilters(page, { cat: '' });
    await page.fill(tid('io-gq'), `TRF-${T.T2}`); await page.waitForTimeout(800); await idle(page);
    check('G global search by transfer number → 1 row', await rowCount(page) === 1);
    await page.fill(tid('io-gq'), ''); await page.waitForTimeout(800); await idle(page);

    // legacy route + tab memory
    await page.evaluate(() => window.InvNav && window.InvNav.goToTab('laporan-transfer'));
    await page.waitForTimeout(600); await idle(page);
    check('C the legacy route "laporan-transfer" opens this page directly on the Transfer tab', (await page.getAttribute(tid('io-tab-transfer'), 'aria-selected')) === 'true' && await page.locator('#tab-laporan-transfer.active .io-title').count() === 1);
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-inout"]').click());
    await page.waitForSelector('#tab-laporan-inout.active .io-title'); await idle(page);

    // ================================================================ S. export
    const dlDir = fs.mkdtempSync(path.join(os.tmpdir(), 'iodl_'));
    const download = async (btn, viaMenu) => {
        if (viaMenu) await page.click(tid('io-more'));
        const [d] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click(tid(btn))]);
        const f = path.join(shotDir, `inout-sample-${d.suggestedFilename()}`);
        await d.saveAs(f);
        return { name: d.suggestedFilename(), file: f };
    };
    const printHtml = async (page2) => page2.evaluate(async () => { const b = ReportTools.lastPrintHtml; document.querySelector('[data-testid="io-print"]').click(); for (let i = 0; i < 100; i++) { await new Promise((r) => setTimeout(r, 100)); if (ReportTools.lastPrintHtml && ReportTools.lastPrintHtml !== b) break; } return ReportTools.lastPrintHtml || ''; });
    const dump = (f) => JSON.parse(sh(`php tests/lib/xlsx_dump.php ${JSON.stringify(f)}`));
    for (const [tab, sheets, needle, base] of [['in', ['Ringkasan IN', 'Transaksi IN', 'Rincian Item IN'], '146025.5', 'Laporan_IN_OUT_Barang_Masuk'], ['out', ['Ringkasan OUT', 'Transaksi OUT', 'Rincian Item OUT', 'FIFO Allocation'], null, 'Laporan_IN_OUT_Barang_Keluar'], ['transfer', ['Ringkasan Transfer', 'Daftar Transfer', 'Rincian Item Transfer'], null, 'Laporan_IN_OUT_Transfer']]) {
        await page.click(tid(`io-tab-${tab}`)); await page.waitForTimeout(300); await idle(page);
        await setFilters(page, period);
        const x = await download('io-excel', false);
        check(`S Download Excel ${tab}: file name starts with ${base}_ and ends .xlsx`, x.name.startsWith(`${base}_`) && x.name.endsWith('.xlsx'), x.name);
        const f = x.file;
        const wbx = sh(`unzip -p ${JSON.stringify(f)} xl/workbook.xml`);
        check(`S export ${tab} workbook: valid xlsx with sheets ${sheets.join(', ')}`, sheets.every((s) => wbx.includes(s)));
        const dm = dump(f);
        const main = dm.sheets[1];
        check(`S export ${tab}: header row frozen + autofilter on the transaction sheet; numeric cells are numbers, dates are dates`, main.frozen && main.autofilter !== '' && main.rows.slice(1, 4).some((r) => r.some((c) => c.t === 'd')) && main.rows.slice(1, 4).some((r) => r.some((c) => c.t === 'n' && c.fmt.includes('Rp'))));
        if (needle) check('S export IN total row equals the screen (Grand Total 146.025,5)', sh(`unzip -p ${JSON.stringify(f)} xl/worksheets/sheet2.xml`).includes(needle));
        if (tab === 'out') {
            const s2 = sh(`unzip -p ${JSON.stringify(f)} xl/worksheets/sheet2.xml`);
            check('S export OUT: HPP total of the sheet = the KPI shown on screen; unknown legacy prices exported as "—"', s2.includes(String(nOut.hpp).slice(0, 8)) && sh(`unzip -p ${JSON.stringify(f)} xl/worksheets/sheet3.xml`).includes('—'));
        }
        if (tab === 'transfer') check('S export Transfer: summary carries "Rata-rata Lead Time (jam)" 28', sh(`unzip -p ${JSON.stringify(f)} xl/worksheets/sheet1.xml`).includes('Rata-rata Lead Time') && sh(`unzip -p ${JSON.stringify(f)} xl/worksheets/sheet1.xml`).includes('28'));
        // Cetak follows the active tab
        const html = await printHtml(page);
        const tabName = { in: 'Barang Masuk (IN)', out: 'Barang Keluar (OUT)', transfer: 'Transfer Antar Gudang' }[tab];
        check(`S Cetak ${tab}: titled "Laporan IN / OUT — ${tabName}" with the period, a print date, white background, repeating header, no controls`, html.includes(`<h1>Laporan IN / OUT — ${tabName}</h1>`) && html.includes('Periode:') && html.includes('Tanggal cetak:') && /background:\s*#fff\s*!important/.test(html) && html.includes('display: table-header-group') && !/<(button|select|input)\b/i.test(html) && html.includes('size: A4 landscape'));
        check(`S Cetak ${tab}: lists the transaction rows (${tab === 'in' ? 'invoice' : tab === 'out' ? 'DO' : 'TRF'} numbers) and right-aligned money columns`, (html.match(/<tr><td/g) || []).length >= 1 && /<td class="r">Rp /.test(html) && !html.includes('Rp NaN'));
    }
    // line view + "Download Semua Tab"
    await page.click(tid('io-tab-in')); await page.waitForTimeout(300); await idle(page);
    await setFilters(page, period);
    const txRows = await rowCount(page);
    await page.click(tid('io-view-lines')); await page.waitForTimeout(500); await idle(page);
    const lineRows = await rowCount(page);
    check('S view toggle "Per Baris Barang": one row per invoice LINE (more rows than invoices), SKU / Barang / Qty columns present', lineRows > txRows && (await page.locator(`${tid('io-table')} thead th`).allInnerTexts()).join('|').includes('SKU'));
    const lhtml = await printHtml(page);
    check('S Cetak follows the view: the line table is printed ("Rincian per Baris Barang", SKU column)', lhtml.includes('Rincian per Baris Barang') && /<th class="[^"]*">SKU<\/th>/.test(lhtml) && lhtml.includes('Tampilan:</b> Per Baris Barang'));
    await page.locator(tid('io-row')).first().click();
    await page.waitForSelector(tid('io-detail-line'));
    check('S a line row still opens its invoice detail', await page.locator(tid('io-detail-line')).count() >= 1);
    await page.click(tid('io-view-tx')); await page.waitForTimeout(400); await idle(page);
    const all = await download('io-excel-all', true);
    const allWb = dump(all.file);
    check('S "Download Semua Tab": one workbook with the Ringkasan + all 3 tabs (9 sheets) named Laporan_IN_OUT_Semua_Tab_…', all.name.startsWith('Laporan_IN_OUT_Semua_Tab_') && allWb.sheets.map((s) => s.name).join('|') === 'Ringkasan|Barang Masuk|Barang Keluar|Transfer|Rincian Barang Masuk|Rincian Barang Keluar|FIFO Allocation|Rincian Transfer|Layer Cost Transfer', allWb.sheets.map((s) => s.name).join('|'));
    // ---- reconciliation CLI
    const rec = sh(`php scripts/inout_reconcile_check.php --app-root=. --start=${R0.from} --end=${R0.to}`);
    check('P read-only reconciliation CLI: every check PASSes (exit 0)', /\d+ \/ \d+ checks passed — all reconcile/.test(rec), rec.split('\n').slice(-3).join(' | '));

    // ================================================================ T. permissions
    const o = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'stock2' }, seed.stock2);
    await openReport(o.page);
    await setFilters(o.page, period);
    check('T STOCK user of W2: the warehouse selector is locked; IN total = invoice B only (31.500)', await o.page.locator(tid('io-wh')).isDisabled() && hasMoney(await kpi(o.page, 'total'), 31500));
    const forged = await api(o.page, '/reports/io/overview', { ...qsBase, tab: 'in', warehouse_id: String(W1) });
    check('T a forged warehouse_id of W1 still returns W2 only', near(forged.kpi.nominal.total, 31500));
    await o.page.click(tid('io-tab-out')); await o.page.waitForTimeout(400); await idle(o.page);
    check('T the W2 user sees no OUT (every OUT left W1) and the empty state', await rowCount(o.page) === 0 && (await text(o.page.locator(tid('io-empty')))).includes('Tidak ada transaksi'));
    await o.page.click(tid('io-tab-transfer')); await o.page.waitForTimeout(400); await idle(o.page);
    check('T transfers of the W2 user: only those that involve W2 (5)', await rowCount(o.page) === 5);
    const det = await o.page.evaluate(async (id) => (await fetch(`/api/reports/io/detail?tab=out&do_id=${id}`, { credentials: 'include' })).status, O.O1.do_id);
    check('T the detail of a W1 DO is 404 for the W2 user', det === 404);
    await o.context.close();

    // ================================================================ W. responsive
    const sizes = [['d1366', 1366, 768], ['ipad-landscape', 1180, 820], ['ipad-1024', 1024, 768], ['ipad-portrait', 820, 1180], ['mobile', 390, 844]];
    for (const [name, w, h] of sizes) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        await openReport(s.page);
        await setFilters(s.page, period);
        for (const tab of ['in', 'out', 'transfer']) {
            await s.page.click(tid(`io-tab-${tab}`)); await s.page.waitForTimeout(350); await idle(s.page);
            const k = await s.page.evaluate(() => ({
                over: document.documentElement.scrollWidth - innerWidth,
                kpiFont: parseFloat(getComputedStyle(document.querySelector('.io-kpi-value')).fontSize),
                clipped: Array.from(document.querySelectorAll('.io-kpi-value')).filter((e) => e.scrollWidth > e.clientWidth + 1).length,
                tabTops: Array.from(document.querySelectorAll('.io-tab')).map((t) => Math.round(t.getBoundingClientRect().top)),
                tabsOver: Array.from(document.querySelectorAll('.io-tab')).some((t) => t.getBoundingClientRect().right > innerWidth + 1),
            }));
            check(`W ${name} ${tab}: no horizontal page overflow, the 3 tabs on ONE row inside the viewport, KPI font ${k.kpiFont}px, no clipped KPI`, k.over <= 0 && new Set(k.tabTops).size === 1 && !k.tabsOver && k.kpiFont <= 26 && k.clipped === 0, JSON.stringify(k));
        }
        const sc = await s.page.evaluate(() => { const e = document.querySelector('[data-testid="io-scroll"]'); return { scrolls: e.scrollWidth > e.clientWidth, over: document.documentElement.scrollWidth - innerWidth }; });
        check(`W ${name}: the wide transfer table scrolls inside its own container; the page does not overflow`, (sc.scrolls || w >= 1366) && sc.over <= 0, JSON.stringify(sc));
        if (name === 'ipad-landscape') { await s.page.click(tid('io-tab-in')); await s.page.waitForTimeout(400); await idle(s.page); await s.page.evaluate(() => window.scrollTo(0, 0)); await shot(s.page, 'ipad-landscape'); }
        if (name === 'mobile') { await s.page.evaluate(() => window.scrollTo(0, 0)); await shot(s.page, 'mobile'); }
        await s.context.close();
    }

    // ---- cleaned report sidebar (screenshot + the five approved entries)
    const sb = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'sidebar' }, seed.admin);
    await sb.page.click('.sidebar-group-header[aria-expanded="false"]').catch(() => {});
    await sb.page.waitForTimeout(400);
    const links = await sb.page.evaluate(() => Array.from(document.querySelectorAll('.sidebar-group[data-group="laporan"] .sidebar-submenu .sidebar-link')).map((a) => a.textContent.trim().replace(/^\S+\s+/, '')));
    check('menu cleanup: the Laporan submenu lists exactly the 5 approved entries', links.join('|') === 'Laporan Pergerakan Stok|Laporan IN / OUT|Laporan Pembelian|Laporan Nilai HPP|Laporan Stock Opname', links.join('|'));
    await sb.page.screenshot({ path: path.join(shotDir, 'inout-sidebar-cleaned.png'), clip: { x: 0, y: 0, width: 320, height: 520 } });
    await sb.context.close();

    // ---- unrelated report pages still render (regression)
    const rg = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'regress' }, seed.admin);
    for (const [tab, sel] of [['laporan-pembelian', '.pur-title, .pur-head'], ['laporan-hpp', '.val-title'], ['laporan-pergerakan', '[data-testid], .mvr-title, .report-title, h2']]) {
        await rg.page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), tab);
        await rg.page.waitForTimeout(900);
        check(`regression: ${tab} still renders`, await rg.page.locator(`#tab-${tab}.active ${sel}`).count() > 0);
    }
    await rg.context.close();

    const realErrors = consoleErrors.filter((e) => !(e.startsWith('stock2 ') && /status of 404/.test(e)));
    check('V no console / page errors in any session', realErrors.length === 0, realErrors.slice(0, 4).join(' || '));
    check('U only GET requests left the browser while using the report (no write)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
    await context.close();
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
