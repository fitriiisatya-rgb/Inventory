// Laporan Pembelian (redesign) — REAL data, end to end, through the real UI (real MariaDB + PHP API + Chromium).
// The purchases come from the application's own Stock IN V2 posting (tests/lib/purchase_report_fixture.php: item discounts, invoice discount, PPN 11 / 5 / 0 %, freight
// expensed + capitalised, a legacy purchase, a voided invoice, a historical import); every number on screen is compared with hand-computed values / the API; exports must equal
// the screen; the read-only reconciliation CLI is run against the data. Screenshots -> $PUR_SHOT_DIR.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw PUR_SHOT_DIR=/some/dir node tests/browser/playwright_purchase_report.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.PUR_SHOT_DIR || __dirname;
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
const seed = JSON.parse(sh('php tests/browser/seed_purchase_report.php'));
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
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `purchase-${name}.png`) });
const E = seed.expect; const IT = seed.items; const R0 = seed.range;
const lc = (a) => a.map((x) => x.toLowerCase());
const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
const waitIdle = (page) => page.waitForFunction(() => !document.querySelector('.pur-loading'), null, { timeout: 20000 });
async function openReport(page) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-pembelian"]').click());
    await page.waitForSelector('#tab-laporan-pembelian.active .pur-title');
    await waitIdle(page);
}
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('pur-start'), f.from);
    if (f.to !== undefined) await page.fill(tid('pur-end'), f.to);
    if (f.wh !== undefined) await page.selectOption(tid('pur-wh'), f.wh);
    if (f.sup !== undefined) await page.selectOption(tid('pur-sup'), f.sup);
    if (f.cat !== undefined) await page.selectOption(tid('pur-cat'), f.cat);
    if (f.q !== undefined) await page.fill(tid('pur-q'), f.q);
    if (f.hist !== undefined) await page.selectOption(tid('pur-hist'), f.hist);
    await page.click(tid('pur-apply'));
    await page.waitForTimeout(500);
    await waitIdle(page);
}
const rowText = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const colIndex = (page, table, label) => page.evaluate(([t, l]) => Array.from(document.querySelectorAll(`[data-testid="${t}"] thead th`)).findIndex((th) => th.textContent.trim().toLowerCase() === l.toLowerCase()), [table, label]);
async function cellOf(page, table, rowSel, label) { const i = await colIndex(page, table, label); return (await page.locator(`[data-testid="${table}"] ${rowSel} td`).nth(i).innerText()).replace(/\s+/g, ' ').trim(); }
const idr = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 4 });
const hasMoney = (text, n) => text.replace(/\s+/g, ' ').includes(idr(Math.round(n * 100) / 100)) || text.replace(/\s+/g, ' ').includes(idr(n));
const qs = { start_date: R0.from, end_date: R0.to };

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin', acceptDownloads: true }, seed.admin);
    await openReport(page);
    check('A the default period is the current month (start = day 1)', (await page.inputValue(tid('pur-start'))).endsWith('-01'));
    await setFilters(page, { from: R0.from, to: R0.to });
    check('A report loads: title, filter bar (Periode/Gudang/Supplier/Kategori/Barang), Nominal/Kuantitas toggle, 6 KPI cards, chart card, invoice table, Rincian per Barang', await page.locator('.pur-title').innerText() === 'Laporan Pembelian'
        && await page.locator('.pur-kpi').count() === 6 && await page.locator(tid('pur-mode-nominal')).count() === 1 && await page.locator(tid('pur-mode-qty')).count() === 1 && await page.locator(tid('pur-chart')).count() === 1 && await page.locator(tid('pur-invoices-table')).count() === 1 && await page.locator(tid('pur-items-table')).count() === 1);

    const ov = await api(page, '/reports/purchase-v2/overview', qs);
    const n = ov.kpi.nominal;
    const inv = await api(page, '/reports/purchase-v2/invoices', { ...qs, per_page: '100' });
    check('B date filter: 5 invoice rows (A, B, C, legacy PO-LEGACY + the voided INV-VOID); the historical import is not in the default (live) report', await page.locator(tid('pur-invoice-row')).count() === 5 && !(await page.locator(tid('pur-invoices-table')).innerText()).includes('PO-HIST'));
    const kv = (k) => page.locator(tid(`pur-kpi-${k}-value`)).innerText();
    check('G KPI cards == hand-computed: Total Rp 146.025,5, Subtotal Barang Rp 112.000, Diskon Rp 6.504,5, PPN Rp 9.530, Ongkos Kirim Rp 21.000, 2 supplier', hasMoney(await kv('total'), E.live_total) && hasMoney(await kv('subtotal'), 112000) && hasMoney(await kv('discount'), 6504.5) && hasMoney(await kv('ppn'), 9530) && hasMoney(await kv('freight'), 21000) && (await kv('suppliers')).trim() === '2', [await kv('total'), await kv('discount')].join(' | '));
    check('G PPN card shows the ACTUAL rates ("0% / 5% / 11%"), never a hard-coded 11 %; Diskon card breaks down Barang / Invoice', (await page.locator(tid('pur-kpi-ppn')).innerText()).includes('0% / 5% / 11%') && (await page.locator(tid('pur-kpi-discount')).innerText()).includes('Barang') && (await page.locator(tid('pur-kpi-discount')).innerText()).includes('Invoice'));
    check('banner discloses legacy value, voided invoice (not counted)', (await page.locator('#pur-banner').innerText()).includes('legacy') && (await page.locator('#pur-banner').innerText()).includes('VOID'));
    await shot(page, 'nominal-desktop');

    // ---- invoice table
    const heads = await page.locator(`${tid('pur-invoices-table')} thead th`).allInnerTexts();
    check('J invoice table columns: Tanggal, Timestamp, No. Invoice / Referensi, Supplier, Gudang, Jumlah Item, Jumlah SKU, Subtotal Barang, Diskon Barang, Diskon Invoice, PPN, Ongkos Kirim, Total Pembelian, Dibuat Oleh, Status, Aksi', ['Tanggal', 'Timestamp', 'No. Invoice / Referensi', 'Supplier', 'Gudang', 'Jumlah Item', 'Jumlah SKU', 'Subtotal Barang', 'Diskon Barang', 'Diskon Invoice', 'PPN', 'Ongkos Kirim', 'Total Pembelian', 'Dibuat Oleh', 'Status', 'Aksi'].every((l) => lc(heads).includes(l.toLowerCase())), heads.join('|'));
    const rowA = '[data-ref="INV-A"]';
    check('J INV-A row: 3 items, subtotal 28.000, diskon barang 2.000, diskon invoice 1.504,5, PPN 2.090, ongkir 15.000, total 43.585,5, status POSTED, supplier + created_by', (await cellOf(page, 'pur-invoices-table', rowA, 'Jumlah Item')) === '3' && hasMoney(await cellOf(page, 'pur-invoices-table', rowA, 'Subtotal Barang'), 28000) && hasMoney(await cellOf(page, 'pur-invoices-table', rowA, 'Diskon Invoice'), 1504.5) && hasMoney(await cellOf(page, 'pur-invoices-table', rowA, 'PPN'), 2090) && hasMoney(await cellOf(page, 'pur-invoices-table', rowA, 'Ongkos Kirim'), 15000) && hasMoney(await cellOf(page, 'pur-invoices-table', rowA, 'Total Pembelian'), 43585.5) && (await cellOf(page, 'pur-invoices-table', rowA, 'Status')).toLowerCase() === 'posted' && (await cellOf(page, 'pur-invoices-table', rowA, 'Supplier')) === 'PR Supplier Satu' && (await cellOf(page, 'pur-invoices-table', rowA, 'Dibuat Oleh')) === seed.admin.username);
    const tot = await rowText(page.locator(tid('pur-invoices-total')));
    check('J GRAND TOTAL row of the invoice table == KPI (Rp 146.025,5 / PPN / ongkir / diskon) and "GRAND TOTAL" label', tot.startsWith('GRAND TOTAL') && hasMoney(tot, E.live_total) && hasMoney(tot, 9530) && hasMoney(tot, 21000));
    const voidRow = page.locator(`${tid('pur-invoice-row')}[data-ref="INV-VOID"]`);
    check('V voided invoice is listed STRUCK THROUGH with status VOID and contributes nothing to the total (Rp 0)', await voidRow.evaluate((e) => e.classList.contains('void')) && (await cellOf(page, 'pur-invoices-table', '[data-ref="INV-VOID"]', 'Status')).toLowerCase() === 'void' && hasMoney(await cellOf(page, 'pur-invoices-table', '[data-ref="INV-VOID"]', 'Total Pembelian'), 0));
    const leg = '[data-ref="PO-LEGACY"]';
    check('W legacy purchase: badge "Legacy", Total Rp 8.000, Subtotal / Diskon / PPN / Ongkir "—" (never 0)', (await rowText(page.locator(`${tid('pur-invoice-row')}${leg}`))).includes('Legacy') && hasMoney(await cellOf(page, 'pur-invoices-table', leg, 'Total Pembelian'), 8000) && (await cellOf(page, 'pur-invoices-table', leg, 'PPN')) === '—' && (await cellOf(page, 'pur-invoices-table', leg, 'Ongkos Kirim')) === '—' && (await cellOf(page, 'pur-invoices-table', leg, 'Diskon Invoice')) === '—');
    check('no invoice number is fabricated: invoice B shows "—" as reference', (await page.locator(tid('pur-invoice-row')).allInnerTexts()).some((t) => /PR Supplier Dua/.test(t) && /\t—\t/.test(t.replace(/ {2,}/g, '\t'))) || await page.locator(`${tid('pur-invoice-row')}[data-ref="—"]`).count() === 1);
    const sc = await page.evaluate(() => { const s = document.querySelector('[data-testid="pur-invoices-scroll"]'); return { sw: s.scrollWidth, cw: s.clientWidth, over: document.documentElement.scrollWidth - innerWidth, pos: getComputedStyle(document.querySelector('[data-testid="pur-invoices-table"] thead th')).position }; });
    check('J the table scrolls horizontally inside its own container (the page does not), sticky header', sc.over <= 0 && sc.pos === 'sticky', JSON.stringify(sc));
    await page.fill(tid('pur-inv-q'), 'INV-C'); await page.keyboard.press('Enter');
    await page.waitForTimeout(600); await waitIdle(page);
    check('J invoice search ("INV-C") narrows the table to that invoice', await page.locator(tid('pur-invoice-row')).count() === 1);
    await page.fill(tid('pur-inv-q'), ''); await page.keyboard.press('Enter');
    await page.waitForTimeout(600); await waitIdle(page);

    // ---- drawer
    await page.locator(`${tid('pur-invoice-row')}${rowA} ${tid('pur-eye')}`).click();
    await page.waitForSelector(tid('pur-arith'));
    const dh = await rowText(page.locator(tid('pur-drawer-header')));
    check('K drawer header: invoice/reference, supplier, warehouse, transaction date, created timestamp, created by, status', dh.includes('INV-A') && dh.includes('PR Supplier Satu') && dh.includes('Gudang PR-SCM') && dh.includes('05 Sep 2026') && dh.includes(seed.admin.username) && /POSTED/i.test(dh));
    const dcols = await page.locator(`${tid('pur-drawer-table')} thead th`).allInnerTexts();
    check('K drawer item table columns: Kode, Nama, Kategori, Satuan, Qty, Harga Beli, Gross, Diskon Item, DPP, PPN %, PPN Rp, Alokasi Diskon Invoice, Alokasi Ongkos Kirim, Total Baris', ['Kode Barang', 'Nama Barang', 'Kategori', 'Satuan', 'Qty', 'Harga Beli', 'Gross', 'Diskon Item', 'DPP / Net Barang', 'PPN %', 'PPN Rp', 'Alokasi Diskon Invoice', 'Alokasi Ongkos Kirim', 'Total Baris'].every((l) => lc(dcols).includes(l.toLowerCase())), dcols.join('|'));
    check('K drawer has exactly the 3 rows of the invoice (full invoice) with the hand-computed X row: gross 10.000, diskon 1.000, DPP 9.000, PPN 11 % = 990, alokasi diskon 499,5, alokasi ongkir 4.821,4286, total 14.311,9286', await page.locator(tid('pur-drawer-line')).count() === 3 && (async () => { const t = await rowText(page.locator(`${tid('pur-drawer-line')}`, { hasText: IT.X.sku })); return hasMoney(t, 9000) && hasMoney(t, 499.5) && hasMoney(t, 4821.4286) && hasMoney(t, 14311.9286) && t.includes('11'); })().then((v) => v));
    const ar = await rowText(page.locator(tid('pur-arith')));
    check('K arithmetic: Gross 30.000 − Diskon Barang 2.000 = Subtotal Barang 28.000; + PPN 2.090 − Diskon Invoice 1.504,5 + Ongkos Kirim 15.000 = GRAND TOTAL 43.585,5', hasMoney(ar, 30000) && hasMoney(ar, 2000) && hasMoney(ar, 28000) && hasMoney(ar, 2090) && hasMoney(ar, 1504.5) && hasMoney(ar, 15000) && ar.includes('GRAND TOTAL') && hasMoney(ar, 43585.5));
    check('K treatments from stored data: PPN CREDITABLE, ongkos kirim EXPENSE; inventory (FIFO) value Rp 26.600 is labelled as NOT the invoice value', (await page.locator(tid('pur-treatments')).innerText()).includes('CREDITABLE') && (await page.locator(tid('pur-treatments')).innerText()).includes('EXPENSE') && (await page.locator(tid('pur-treatments')).innerText()).includes('bukan nilai invoice'));
    await shot(page, 'invoice-drawer');
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    await page.locator(`${tid('pur-invoice-row')}${leg} ${tid('pur-eye')}`).click();
    await page.waitForSelector(tid('pur-drawer-nobreakdown'));
    check('K legacy invoice drawer: states that PPN / discount / freight breakdown is not stored (no fabricated numbers, no arithmetic block)', await page.locator(tid('pur-arith')).count() === 0);
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);

    // ---- Rincian per Barang
    const xRow = `[data-sku="${IT.X.sku}"]`;
    const xr = (l) => cellOf(page, 'pur-items-table', xRow, l);
    check('T/U item X: frequency 2, Qty 40 PCS, Harga Beli min Rp 1.000 / max Rp 1.100 / weighted avg Rp 1.075 (NOT the simple 1.050) — both historical prices preserved', (await xr('Frekuensi Pembelian')) === '2' && hasMoney(await xr('Qty Beli'), 40) && (await xr('Satuan')) === 'PCS' && hasMoney(await xr('Harga Beli Minimum'), 1000) && hasMoney(await xr('Harga Beli Maksimum'), 1100) && hasMoney(await xr('Harga Beli Rata-rata (tertimbang)'), 1075) && !(await xr('Harga Beli Rata-rata (tertimbang)')).includes('1.050'));
    const it = await api(page, '/reports/purchase-v2/items', { ...qs, per_page: '100' });
    const itTot = await rowText(page.locator(tid('pur-items-total')));
    check('Rincian per Barang GRAND TOTAL row == KPI total / PPN / ongkir / diskon; % column sums to 100', itTot.startsWith('GRAND TOTAL') && hasMoney(itTot, E.live_total) && hasMoney(itTot, 21000) && hasMoney(itTot, 9530) && Math.abs(it.rows.reduce((a, r) => a + r.share_pct, 0) - 100) < 0.06);
    check('Rincian per Barang: quantities listed PER UNIT under the table, never one total', /KG [\d.,]+ · LTR [\d.,]+ · PCS [\d.,]+/.test(await page.locator(tid('pur-items-units')).innerText()));
    await page.evaluate(() => document.getElementById('pur-items-card').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await shot(page, 'items-breakdown');
    await page.evaluate(() => window.scrollTo(0, 0));

    // ---- chart
    check('chart: bars + invoice-count line, hover tooltip shows Subtotal / Diskon / PPN / Ongkir / Total', await page.locator('.pur-bar').count() >= 3 && await (async () => { await page.locator(`${tid('pur-hit')}`).nth(4).hover(); await page.waitForTimeout(150); const t = await rowText(page.locator(tid('pur-tip'))); return /Total Pembelian/.test(t) && /PPN/.test(t) && /Ongkos Kirim/.test(t) && hasMoney(t, 43585.5); })());
    await page.click(tid('pur-bucket-week')); await page.waitForTimeout(500); await waitIdle(page);
    const wk = await api(page, '/reports/purchase-v2/overview', { ...qs, bucket: 'week' });
    check('chart Mingguan: bars = ISO weeks (API trend), Σ == KPI total', await page.locator(tid('pur-hit')).count() === wk.trend.length && Math.abs(wk.trend.reduce((a, r) => a + r.total, 0) - n.total) < 0.01);
    await page.click(tid('pur-bucket-month')); await page.waitForTimeout(500); await waitIdle(page);
    check('chart Bulanan: one bucket (Sep 2026)', await page.locator(tid('pur-hit')).count() === 1);
    await page.click(tid('pur-bucket-day')); await page.waitForTimeout(500); await waitIdle(page);

    // ---- qty mode
    await page.click(tid('pur-mode-qty'));
    const qcards = await page.locator('.pur-kpi').allInnerTexts();
    check('H Qty mode: cards switch to Jumlah Transaksi 4, Jumlah SKU Dibeli 5, Jumlah Supplier 2, Qty per Satuan (KG 135 · LTR 20 · PCS 40)', qcards.length === 4 && (await kv('invoices')).trim() === '4' && (await kv('skus')).trim() === '5' && (await kv('suppliers')).trim() === '2' && (await page.locator(tid('pur-kpi-qty')).innerText()).replace(/\s+/g, ' ').includes('KG 135 · LTR 20 · PCS 40'));
    check('I Qty mode never shows a single mixed-unit quantity: no "total qty" card; the multi-unit units are listed separately', !(await page.locator('#pur-kpis').innerText()).match(/Total Qty/i) && (await page.locator(tid('pur-kpi-qty-value')).innerText()).includes('3 satuan'));
    check('H Qty mode: the chart shows the explicit message (no mixed-unit chart) and plots invoice / SKU counts', await page.locator(tid('pur-chart-qty-message')).count() === 1);
    check('H Qty mode: invoice table switches to quantity columns (Jumlah Item, SKU, Qty per satuan dasar) — no Rupiah columns; items table to Qty / Frekuensi / Harga Beli', !(await page.locator(tid('pur-invoices-table')).innerText()).includes('Rp ') || (await page.locator(`${tid('pur-invoices-table')} thead th`).allInnerTexts()).every((h) => !/Subtotal|PPN|Ongkos/i.test(h)));
    check('H Qty mode invoice row: INV-A shows "KG 5 · LTR 20 · PCS 10" (per base unit)', (await cellOf(page, 'pur-invoices-table', rowA, 'Qty (satuan dasar)')).replace(/\s+/g, ' ') === 'KG 5 · LTR 20 · PCS 10');
    await shot(page, 'qty-desktop');
    await setFilters(page, { q: IT.X.sku });
    check('H Qty + ONE item (X): the quantity chart appears titled with the unit PCS', await page.locator(tid('pur-chart-qty-message')).count() === 0 && /PCS/.test(await page.locator('#pur-chart-card .pur-card-title').innerText()) && await page.locator('.pur-bar-qty').count() === 2);
    await page.click(tid('pur-mode-nominal'));
    await setFilters(page, { q: '' });

    // ---- other filters
    await setFilters(page, { wh: String(seed.wh['2']) });
    check('C warehouse filter (WH2): only invoice B (Rp 31.500)', await page.locator(tid('pur-invoice-row')).count() === 1 && hasMoney(await kv('total'), 31500));
    await setFilters(page, { wh: '', sup: String(seed.sup['2']) });
    check('D supplier filter (S2): only invoice B', await page.locator(tid('pur-invoice-row')).count() === 1);
    await setFilters(page, { sup: '', cat: String(seed.cat['1']) });
    check('E category filter (PR Roti = item X only): invoices flagged "sebagian", totals from the matching rows only, note shown', await page.locator('.pur-src.partial').count() === 2 && (await page.locator('#pur-banner').innerText()).includes('sebagian') && (await page.locator(tid('pur-items-table')).locator('tbody tr').count()) === 1);
    await setFilters(page, { cat: '', q: IT.Z.sku });
    check('F item search by SKU (Z) → only invoice A (1 of its 3 rows)', await page.locator(tid('pur-invoice-row')).count() === 1);
    await setFilters(page, { q: '', hist: '1' });
    check('historical filter "Historis saja": only PO-HIST, badge "Historis", total Rp 500; "Live + Historis" lists 6 rows', await page.locator(tid('pur-invoice-row')).count() === 1 && (await rowText(page.locator(tid('pur-invoice-row')))).includes('Historis'));
    await setFilters(page, { hist: 'all' });
    check('"Live + Historis" lists 6 rows', await page.locator(tid('pur-invoice-row')).count() === 6);
    await setFilters(page, { hist: '' });
    await page.click(tid('pur-reset'));
    await page.waitForTimeout(600); await waitIdle(page);
    check('Reset restores the default period (current month) and clears the filters', (await page.inputValue(tid('pur-start'))).endsWith('-01') && (await page.inputValue(tid('pur-q'))) === '');
    await setFilters(page, { from: R0.from, to: R0.to });

    // ---- export == screen
    await page.evaluate(() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null; }; });
    await page.click(tid('pur-export'));
    check('X export menu: Excel workbook + 3 CSVs', await page.locator('.pur-export-item').count() === 4);
    const fetchText = (u) => page.evaluate(async (url) => { const r = await fetch(url, { credentials: 'include' }); return { status: r.status, type: r.headers.get('content-type'), text: await r.text() }; }, u);
    await page.click(tid('pur-export-invoices'));
    const csv = await fetchText((await page.evaluate(() => window.__opened)).pop());
    const lines = csv.text.replace(/^﻿/, '').trim().split('\n').filter(Boolean);
    const apiCols = (await api(page, '/reports/purchase-v2/invoices', { ...qs, per_page: '10' })).columns.map((c) => c.label);
    check('X export Detail Invoice (CSV): every column of the screen catalogue in the header; rows + a TOTAL row; the TOTAL equals the screen GRAND TOTAL (146025.5)', csv.status === 200 && apiCols.every((l) => lines[0].includes(l)) && lines.length === 1 + 5 + 1 && lines[lines.length - 1].startsWith('TOTAL') && lines[lines.length - 1].includes('146025.5'), lines[lines.length - 1].slice(0, 120));
    await page.click(tid('pur-export')); await page.click(tid('pur-export-items'));
    const csvI = await fetchText((await page.evaluate(() => window.__opened)).pop());
    check('X export Detail Barang (CSV): header + one row per item/unit + TOTAL with Total Pembelian == 146025.5', csvI.status === 200 && csvI.text.includes('Harga Beli Rata-rata') && csvI.text.trim().split('\n').pop().includes('146025.5'));
    await page.click(tid('pur-export')); await page.click(tid('pur-export-workbook'));
    const wbUrl = (await page.evaluate(() => window.__opened)).pop();
    const wb = await page.evaluate(async (u) => { const r = await fetch(u, { credentials: 'include' }); const b = new Uint8Array(await r.arrayBuffer()); return { status: r.status, type: r.headers.get('content-type'), bytes: Array.from(b) }; }, wbUrl);
    fs.writeFileSync(path.join(shotDir, 'purchase-sample-export.xlsx'), Buffer.from(wb.bytes));
    const zl = sh(`unzip -l ${JSON.stringify(path.join(shotDir, 'purchase-sample-export.xlsx'))}`);
    check('X workbook: real .xlsx with 4 worksheets (Ringkasan, Detail Invoice, Detail Barang, Baris Invoice-Barang)', wb.status === 200 && /spreadsheetml/.test(wb.type) && (zl.match(/xl\/worksheets\/sheet\d+\.xml/g) || []).length === 4);

    // ---- reconciliation CLI
    const rec = sh(`php scripts/purchase_reconcile_check.php --app-root=. --start=${R0.from} --end=${R0.to}`);
    check('read-only reconciliation CLI: every check PASSes (exit 0)', /7 \/ 7 checks passed — all reconcile/.test(rec), rec.split('\n').slice(-3).join(' | '));

    // ---- permission
    const o = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'stock2' }, seed.stock2);
    await openReport(o.page);
    await setFilters(o.page, { from: R0.from, to: R0.to });
    check('Y STOCK user of WH2: warehouse selector locked to their warehouse; only invoice B visible (Rp 31.500)', await o.page.locator(tid('pur-wh')).isDisabled() && await o.page.locator(tid('pur-invoice-row')).count() === 1 && hasMoney(await o.page.locator(tid('pur-kpi-total-value')).innerText(), 31500));
    const forb = await o.page.evaluate(async (ids) => (await fetch(`/api/reports/purchase-v2/invoice-detail?tx_ids=${ids}`, { credentials: 'include' })).status, seed.tx.A.join(','));
    check('Y direct API for another warehouse\'s invoice → 403', forb === 403);
    await o.context.close();

    // ---- responsive
    for (const [name, w, h] of [['d1366', 1366, 768], ['ipad-landscape', 1180, 820], ['ipad-portrait', 820, 1180], ['mobile', 390, 844]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        await openReport(s.page);
        await setFilters(s.page, { from: R0.from, to: R0.to });
        const m = await s.page.evaluate(() => ({ over: document.documentElement.scrollWidth - innerWidth, kpiFont: parseFloat(getComputedStyle(document.querySelector('.pur-kpi-value')).fontSize), clipped: Array.from(document.querySelectorAll('.pur-kpi-value')).filter((e) => e.scrollWidth > e.clientWidth + 1).length }));
        check(`AB ${name}: no horizontal page overflow, KPI font ${m.kpiFont}px (not oversized), no clipped KPI value`, m.over <= 0 && m.kpiFont <= 26 && m.clipped === 0, JSON.stringify(m));
        const sc2 = await s.page.evaluate(() => { const e = document.querySelector('[data-testid="pur-invoices-scroll"]'); return { scrolls: e.scrollWidth > e.clientWidth, over: document.documentElement.scrollWidth - innerWidth }; });
        check(`AB ${name}: the invoice table scrolls inside its container; page does not overflow`, sc2.scrolls && sc2.over <= 0, JSON.stringify(sc2));
        if (name === 'ipad-landscape') await shot(s.page, 'ipad-landscape');
        if (name === 'ipad-portrait') await shot(s.page, 'ipad-portrait');
        if (name === 'mobile') await shot(s.page, 'mobile');
        await s.context.close();
    }

    const realErrors = consoleErrors.filter((e) => !(e.startsWith('stock2 ') && /status of 403/.test(e)));
    check('Z no console / page errors in any session (the deliberate 403 probe of the other-warehouse user excluded)', realErrors.length === 0, realErrors.slice(0, 4).join(' || '));
    check('AA only GET requests left the browser while using the report (no write)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
    await context.close();
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
