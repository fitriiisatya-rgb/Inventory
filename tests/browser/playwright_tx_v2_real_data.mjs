// Stock IN V2 + Stock OUT V2 — REAL data, end to end, through the real UI.
// Real MariaDB + schema, real PHP API behind a node static server, real login, real Chromium.
// Hand-computed expectations; DB assertions via the mysql CLI (never the code under test).
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw TX_SHOT_DIR=/dir node tests/browser/playwright_tx_v2_real_data.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.TX_SHOT_DIR || __dirname;
fs.mkdirSync(shotDir, { recursive: true });
const dbName = process.env.DB_DATABASE || 'inventory_test';
const ONLY = process.env.TX_ONLY || '';

const results = [];
function check(name, pass, detail = '') { results.push(pass); console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`); }
const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env }).toString();
const sql = (q) => sh(`mysql -uroot ${dbName} -N -e ${JSON.stringify(q)}`).trim();
const near = (a, b, e = 0.01) => Math.abs(Number(a) - Number(b)) <= e;
const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot ${dbName} < database/schema.sql`);
const seed = JSON.parse(sh('php tests/browser/seed_tx_v2.php'));
const I = seed.items;
console.log('Seeded', JSON.stringify(seed.wh), 'bakery', seed.bakery);

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
async function openTx(page, kind) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="transaksi"]').click());
    await page.waitForSelector(`#tab-transaksi.active ${tid('tx-root')}`, { timeout: 10000 });
    const cur = await page.locator(`${tid('tx-tab-' + kind)}.on`).count();
    if (!cur) { await page.click(tid('tx-tab-' + kind)); }
    await page.waitForSelector(tid(kind === 'in' ? 'in-header' : 'out-header'), { timeout: 10000 });
}
async function pickItem(page, prefix, rowIdx, name) {
    const row = page.locator(tid(`${prefix}-row`)).nth(rowIdx);
    await row.locator(tid('tx-item-input')).fill(name);
    await page.locator(tid('tx-item-opt'), { hasText: name }).first().click();
    await page.waitForFunction(([p, i]) => { const r = document.querySelectorAll(`[data-testid="${p}-row"]`)[i]; const u = r && r.querySelector('[data-testid$="-unit"]'); return u && !u.disabled && u.value; }, [prefix, rowIdx]);
}
const txt = async (page, id) => (await page.locator(tid(id)).first().innerText()).replace(/\s+/g, ' ').trim();
const rowTxt = async (page, prefix, idx, id) => (await page.locator(tid(`${prefix}-row`)).nth(idx).locator(tid(id)).innerText()).replace(/\s+/g, ' ').trim();
const fill = async (loc, v) => { await loc.fill(String(v)); };
const fmt = (n) => rp(n);
const ppnTxt = async (page) => `Rp ${await page.locator(tid('in-sum-ppn')).inputValue()}`;

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1440, height: 900 }, __name: 'desktop' }, seed.admin);
    const itemCount0 = sql('SELECT COUNT(*) FROM items');

    if (!ONLY || ONLY === 'in') {
    // ================================================================ STOCK IN
    await openTx(page, 'in');
    const scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.getAttribute('src') || ''));
    check('new modules + cache tokens loaded', ['transactions.js', 'stock-in-sheet.js', 'stock-out-sheet.js'].every((n) => scripts.some((s) => s.includes(`${n}?v=${n === 'stock-in-sheet.js' ? '20261010-tx2c' : '20261010-tx2'}`))));
    check('tabs Stock IN / Stock OUT; Stock IN active', await page.locator(tid('tx-tab-in')).count() === 1 && await page.locator(tid('tx-tab-out')).count() === 1 && await page.locator(`${tid('tx-tab-in')}.on`).count() === 1);
    const hdrTxt = (await page.locator(tid('in-header')).innerText()).replace(/\s+/g, ' ');
    check('header fields: Gudang, Vendor / Supplier, Referensi, Tanggal Transaksi, Tipe Transaksi, Catatan', ['Gudang', 'Vendor / Supplier', 'Referensi', 'Tanggal Transaksi', 'Tipe Transaksi', 'Catatan (Opsional)'].every((l) => hdrTxt.includes(l)), hdrTxt);
    const heads = (await page.locator(`${tid('in-table')} thead th`).allTextContents()).map((h) => h.trim());
    check('item table columns = No | Nama Barang | Satuan | Qty | Harga Beli | Diskon | Total | Aksi (NO per-item PPN, no SKU, no Batch)', heads.join('|') === 'No|Nama Barang|Satuan|Qty|Harga Beli|Diskon|Total|Aksi' && !/SKU|Batch/i.test(heads.join(' ')), heads.join('|'));
    check('starts compact: 5 blank rows, status asks for data', await page.locator(tid('in-row')).count() === 5 && (await txt(page, 'in-status-sub')).length > 0 && await page.locator(tid('in-next')).isDisabled());

    await page.selectOption(tid('in-warehouse'), String(seed.wh.B));
    await page.selectOption(tid('in-supplier'), String(seed.supplier));
    await fill(page.locator(tid('in-reference')), 'INV-SM-0042');
    await fill(page.locator(tid('in-notes')), 'Pembelian rutin mingguan');

    // ---- unknown item is never created
    await page.locator(tid('in-row')).nth(0).locator(tid('tx-item-input')).fill('Barang Fiktif Zzz');
    check('unknown name → "Barang belum tersedia di Master Barang…" message, nothing selected', (await page.locator(tid('tx-item-dd')).innerText()).includes('Barang belum tersedia di Master Barang. Tambahkan melalui Master Barang terlebih dahulu.'));
    await page.locator(tid('in-row')).nth(0).locator(tid('tx-item-input')).fill('');
    check('no master item was created by typing', sql('SELECT COUNT(*) FROM items') === itemCount0);

    // ---- A: basic (Gula Pasir 10 kg @ default 14.000, PPN 0%)
    await pickItem(page, 'in', 0, 'Gula Pasir');
    check('A item picked → default Harga Beli = reference price 14.000 of the selected unit; units listed (KG)', (await page.locator(tid('in-row')).nth(0).locator(tid('in-price')).inputValue()).replace(/\D/g, '') === '14000' && (await page.locator(tid('in-row')).nth(0).locator(`${tid('in-unit')} option`).allTextContents()).join() === 'KG');
    await page.click(tid('in-add-row'));
    check('"+ Tambah Barang" adds a row', await page.locator(tid('in-row')).count() === 6);
    await page.locator(tid('in-row')).nth(5).locator(tid('in-del')).click();
    check('delete row removes it', await page.locator(tid('in-row')).count() === 5);
    check('PPN is not a row control: no PPN select inside any row; ONE PPN selector in the summary area', await page.locator(`${tid('in-row')} ${tid('in-ppn')}`).count() === 0 && await page.locator(tid('in-ppn')).count() === 1);
    await page.selectOption(tid('in-ppn'), '0');
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-qty')), 10);
    check('A basic: 10 × 14.000 = Rp 140.000 (row, subtotal, grand)', await rowTxt(page, 'in', 0, 'in-rowtotal') === fmt(140000) && await txt(page, 'in-sum-subtotal') === fmt(140000) && await txt(page, 'in-sum-grand') === fmt(140000));
    check('A status "Data siap disimpan", Lanjut enabled', (await txt(page, 'in-status-title')) === 'Data siap disimpan' && await page.locator(tid('in-next')).isEnabled());
    // ---- B: PPN 11%
    await page.selectOption(tid('in-ppn'), '11');
    check('B PPN 11% at the END: row Total stays the DPP 140.000; PPN 15.400 on the total; Grand Rp 155.400', await rowTxt(page, 'in', 0, 'in-rowtotal') === fmt(140000) && await ppnTxt(page) === fmt(15400) && await txt(page, 'in-sum-grand') === fmt(155400));
    // ---- C: item discount 5%
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-disc')), 5);
    check('C item discount 5%: row Total = DPP 133.000; PPN 14.630 → Grand Rp 147.630', await rowTxt(page, 'in', 0, 'in-rowtotal') === fmt(133000) && await txt(page, 'in-sum-grand') === fmt(147630));
    // ---- D: item discount nominal
    await page.locator(tid('in-row')).nth(0).locator(tid('in-disc-mode')).selectOption('AMOUNT');
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-disc')), 10000);
    check('D item discount Rp 10.000: DPP 130.000 → +11% at the end → Grand Rp 144.300', await rowTxt(page, 'in', 0, 'in-rowtotal') === fmt(130000) && await txt(page, 'in-sum-grand') === fmt(144300));
    // ---- K: invalid discount blocked, inline error
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-disc')), 999999999);
    check('K discount > base amount → inline error, status blocking, Lanjut disabled', (await rowTxt(page, 'in', 0, 'in-rowerr')).includes('Diskon melebihi nilai barang') && await page.locator(tid('in-next')).isDisabled() && (await txt(page, 'in-status-title')).includes('perlu diperbaiki'));
    await page.locator(tid('in-row')).nth(0).locator(tid('in-disc-mode')).selectOption('PERCENT');
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-disc')), 5);
    // ---- E/F/G: invoice discount % / Rp, shipping (single row 147.630)
    await fill(page.locator(tid('in-inv-value')), 10);
    check('E invoice discount 10% BEFORE PPN: Subtotal (DPP) 133.000 − 13.300 = 119.700; PPN 11% = 13.167 → Grand Rp 132.867', await txt(page, 'in-sum-subtotal') === fmt(133000) && await ppnTxt(page) === fmt(13167) && await txt(page, 'in-sum-grand') === fmt(132867));
    await page.locator(`${tid('in-inv-mode')} .tx2-segbtn[data-value="AMOUNT"]`).click();
    await fill(page.locator(tid('in-inv-value')), 10000);
    check('F invoice discount Rp 10.000 on the DPP (mutually exclusive with %): 123.000 + PPN 13.530 = Grand Rp 136.530', await txt(page, 'in-sum-grand') === fmt(136530) && (await txt(page, 'in-sum-inv')).includes(fmt(10000)));
    await fill(page.locator(tid('in-freight')), 5000);
    check('G Biaya Kirim Rp 5.000 added after PPN: Grand Rp 141.530', await txt(page, 'in-sum-freight') === fmt(5000) && await txt(page, 'in-sum-grand') === fmt(141530));
    await fill(page.locator(tid('in-inv-value')), 999999);
    check('K invoice discount > subtotal → blocked', (await txt(page, 'in-status-sub')).includes('melebihi subtotal') && await page.locator(tid('in-next')).isDisabled());

    // ---- H/I: combination, non-base unit, price override
    await fill(page.locator(tid('in-inv-value')), 0);
    await page.locator(`${tid('in-inv-mode')} .tx2-segbtn[data-value="PERCENT"]`).click();
    await fill(page.locator(tid('in-inv-value')), 0);
    await fill(page.locator(tid('in-freight')), 0);
    await pickItem(page, 'in', 1, 'Tepung Terigu Segitiga Biru');
    const unitOpts = (await page.locator(tid('in-row')).nth(1).locator(`${tid('in-unit')} option`).allTextContents());
    check('I units offered = approved ones only (KARTON conversion + base KG)', unitOpts.sort().join() === ['KARTON', 'KG'].join());
    await page.locator(tid('in-row')).nth(1).locator(tid('in-unit')).selectOption({ label: 'KARTON' });
    check('I choosing KARTON re-derives the price for that unit: 12.500/kg × 24 = Rp 300.000', (await page.locator(tid('in-row')).nth(1).locator(tid('in-price')).inputValue()).replace(/\D/g, '') === '300000');
        await fill(page.locator(tid('in-row')).nth(1).locator(tid('in-qty')), 2);
    await pickItem(page, 'in', 2, 'Ragi Instan');
        await fill(page.locator(tid('in-row')).nth(2).locator(tid('in-qty')), 5);
    // price override on Gula (14.000 → 15.000), master must stay untouched
    const itemsSum0 = sql('CHECKSUM TABLE items').split('\t')[1];
    await fill(page.locator(tid('in-row')).nth(0).locator(tid('in-price')), 15000);
    check('J overriding Harga Beli shows the reference hint and drives the row: 10 × 15.000 − 5% → row Total (DPP) Rp 142.500', (await rowTxt(page, 'in', 0, 'in-refhint')).includes('Ref. ' + fmt(14000)) && await rowTxt(page, 'in', 0, 'in-rowtotal') === fmt(142500), await rowTxt(page, 'in', 0, 'in-rowtotal'));
    // expected: row1 = 10*15000=150000 - 5% = 142500 ; row2 = 600000 ; row3 = 40000 ; Subtotal (DPP) 782.500 ; ONE PPN 11% at the end
    check('H three rows: 142.500 + 600.000 + 40.000 → Subtotal (DPP) Rp 782.500', await txt(page, 'in-sum-subtotal') === fmt(782500) && await txt(page, 'in-count') === '3 item');
    await page.locator(`${tid('in-inv-mode')} .tx2-segbtn[data-value="AMOUNT"]`).click();
    await fill(page.locator(tid('in-inv-value')), 12500);
    await fill(page.locator(tid('in-freight')), 20000);
    check('H invoice discount Rp 12.500 → DPP 770.000; PPN 11% = 84.700; + Biaya Kirim 20.000 → Grand Total Rp 874.700', await ppnTxt(page) === fmt(84700) && await txt(page, 'in-sum-grand') === fmt(874700), await txt(page, 'in-sum-grand'));
    // ---- adjustable PPN: type the exact amount printed on the supplier invoice
    await page.screenshot({ path: path.join(shotDir, 'stockin-desktop-1440.png'), fullPage: true });
    await fill(page.locator(tid('in-sum-ppn')), 84750);
    await page.locator(tid('in-sum-ppn')).blur();
    check('PPN adjusted to the supplier invoice: PPN Rp 84.750, Grand Total = 770.000 + 84.750 + 20.000 = Rp 874.750; note shows auto 84.700 and diff +Rp 50', await ppnTxt(page) === fmt(84750) && await txt(page, 'in-sum-grand') === fmt(874750) && (await txt(page, 'in-summary')).includes('disesuaikan sesuai invoice supplier') && (await txt(page, 'in-summary')).includes('selisih +' + fmt(50)), await txt(page, 'in-sum-grand'));
    await page.screenshot({ path: path.join(shotDir, 'stockin-ppn-adjusted.png'), fullPage: true });
    check('reset button appears; clicking it returns to the automatic PPN 84.700 / Grand 874.700', await page.locator(tid('in-ppn-reset')).isVisible());
    await page.click(tid('in-ppn-reset'));
    check('reset → automatic PPN again', await ppnTxt(page) === fmt(84700) && await txt(page, 'in-sum-grand') === fmt(874700));
    await fill(page.locator(tid('in-sum-ppn')), 84750);
    await page.click(tid('in-next'));
    await page.waitForSelector(tid('in-review-row'), { timeout: 15000 });
    check('review (server quote) keeps the adjusted PPN: label "disesuaikan", Grand Total Rp 874.750', (await page.locator(tid('in-review')).innerText()).includes('disesuaikan sesuai invoice supplier') && (await page.locator(tid('in-review-grand')).last().innerText()).replace(/\s+/g, ' ') === fmt(874750));
    await page.click(tid('in-back'));
    await page.waitForSelector(tid('in-table'));
    await page.click(tid('in-ppn-reset'));

    // ---- review (server quote) then save
    const beforeIn = Number(sql("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='IN'"));
    await page.click(tid('in-next'));
    await page.waitForSelector(tid('in-review-row'), { timeout: 15000 });
    check('review: 3 lines, with Harga Beli / Diskon Item / Total columns (no per-item PPN)', await page.locator(tid('in-review-row')).count() === 3 && (await page.locator('.tx2-reviewtable thead th').allTextContents()).join('|') === 'No|Nama Barang|Qty + Satuan|Harga Beli|Diskon Item|Total');
    const revText = (await page.locator(tid('in-review')).innerText()).replace(/\s+/g, ' ');
    check('review shows Gudang, Supplier, Referensi, Tanggal, items, Diskon Invoice, Biaya Kirim, Grand Total', ['Gudang Cibadak'.replace('Cibadak', 'SCM'), 'PT Sinar Makmur', 'INV-SM-0042', 'Tepung Terigu Segitiga Biru', '2 KARTON', 'Diskon Invoice', 'Biaya Kirim', 'Grand Total'].every((t) => revText.includes(t)), revText.slice(0, 200));
    check('review Grand Total (server) = live sheet Grand Total = Rp 874.700', (await page.locator(tid('in-review-grand')).last().innerText()).replace(/\s+/g, ' ') === fmt(874700));
    check('review states the inventory (HPP) effect: PPN recoverable not capitalised, shipping not capitalised', revText.includes('PPN dikreditkan (tidak masuk HPP)') && revText.includes('Biaya kirim tidak masuk HPP'));
    await page.click(tid('in-back'));
    await page.waitForSelector(tid('in-table'));
    check('back to edit keeps every value (3 filled rows, totals identical)', await txt(page, 'in-sum-grand') === fmt(874700) && await txt(page, 'in-count') === '3 item');
    await page.click(tid('in-next')); await page.waitForSelector(tid('in-review-row'));
    await page.click(tid('in-post'));
    await page.waitForSelector(tid('in-done'), { timeout: 20000 });
    check('saved: "Transaksi Masuk berhasil disimpan (3 barang)" with Grand Total Rp 874.700', (await txt(page, 'in-done')).includes('3 barang') && await txt(page, 'in-done-grand') === fmt(874700));
    // ---- DB truth
    const txs = sql("SELECT t.id, t.reference_no, t.supplier_id, t.warehouse_id, l.item_id, l.base_qty, l.unit_cost_base, l.notes FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id=t.id WHERE t.reference_no='INV-SM-0042' ORDER BY t.id").split('\n').map((l) => l.split('\t'));
    check('DB: three IN transactions, one per item, shared reference + supplier + warehouse, catatan on the lines', txs.length === 3 && txs.every((t) => t[2] === String(seed.supplier) && t[3] === String(seed.wh.B) && t[7] === 'Pembelian rutin mingguan') && Number(sql("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='IN'")) === beforeIn + 3);
    const byItem = Object.fromEntries(txs.map((t) => [t[4], t]));
    check('DB: base qty gula 10 kg · terigu 2 karton = 48 kg · ragi 5 pack', near(byItem[I.gula.id][5], 10) && near(byItem[I.terigu.id][5], 48) && near(byItem[I.ragi.id][5], 5));
    const sumInvoice = Number(sql(`SELECT SUM(h.invoice_total) FROM purchase_invoice_headers h JOIN inventory_transactions t ON t.id=h.transaction_id WHERE t.reference_no='INV-SM-0042'`));
    check('DB: Σ invoice_total of the rows = Grand Total shown = 874.700', near(sumInvoice, 874700, 0.05), String(sumInvoice));
    const invCost = Number(sql(`SELECT SUM(c.final_inventory_cost) FROM purchase_line_costs c JOIN inventory_transactions t ON t.id=c.transaction_id WHERE t.reference_no='INV-SM-0042'`));
    // hand computation: DPP 782.500 − invoice discount 12.500 = 770.000 (PPN is recoverable and shipping is expensed → neither is capitalised)
    const expectCost = 770000;
    check('DB: inventory (FIFO) cost = DPP after item + invoice discount; PPN recoverable and shipping are NOT capitalised', near(invCost, expectCost, 0.1), `${invCost} vs ${expectCost.toFixed(4)}`);
    check('J master data untouched by the price override (items checksum unchanged); price history appended', sql('CHECKSUM TABLE items').split('\t')[1] === itemsSum0 && sql(`SELECT COUNT(*) FROM item_price_history WHERE item_id=${I.gula.id}`) === '2');
    check('only the two new endpoints were used for the entry (quote ×≥1, post ×1) — no write elsewhere', requests.filter((r) => r.method !== 'GET').every((r) => ['/stock-in/quote', '/stock-in'].includes(r.path)) && requests.filter((r) => r.path === '/stock-in').length === 1);
    await page.click(tid('in-new'));
    await page.waitForSelector(tid('in-table'));
    check('"Transaksi Masuk Baru" resets the sheet', await txt(page, 'in-count') === '0 item');
    }


    if (!ONLY || ONLY === 'out') {
    // ================================================================ STOCK OUT
    await openTx(page, 'out');
    const oh = (await page.locator(tid('out-header')).innerText()).replace(/\s+/g, ' ');
    check('OUT header: Gudang Asal, Bakery Tujuan, Referensi (Opsional), Tanggal Transaksi, Catatan', ['Gudang Asal', 'Bakery Tujuan', 'Referensi (Opsional)', 'Tanggal Transaksi', 'Catatan'].every((l) => oh.includes(l)), oh);
    const oheads = (await page.locator(`${tid('out-table')} thead th`).allTextContents()).map((h) => h.trim());
    check('OUT item table = No | Nama Barang | Kategori | Stok Tersedia | Qty | Satuan | Harga Modal | Markup Kategori | Harga Jual | Total | Aksi (no SKU, no Batch)', oheads.join('|') === 'No|Nama Barang|Kategori|Stok Tersedia|Qty|Satuan|Harga Modal|Markup Kategori|Harga Jual|Total|Aksi' && !/SKU|Batch/i.test(oheads.join(' ')), oheads.join('|'));
    check('OUT has no PPN / discount fields anywhere', !/PPN|Diskon/i.test(await page.locator(tid('tx-root')).innerText()));
    await page.selectOption(tid('out-warehouse'), String(seed.wh.A));
    await page.selectOption(tid('out-bakery'), String(seed.bakery));
    await fill(page.locator(tid('out-reference')), 'REF-OUT-001');
    await fill(page.locator(tid('out-notes')), 'Pengiriman rutin bahan & packaging');
    check('markup section is empty until items are chosen (only categories actually in the transaction)', await page.locator(tid('out-markup-empty')).count() === 1);
    const six = [['Box Cake 20x20', 50], ['Paper Bag Medium', 100], ['Stiker Logo Amor', 200], ['Lilin Ulang Tahun', 20], ['Cake Topper', 30], ['Tepung Terigu', 10]];
    for (let i = 0; i < six.length; i++) {
        await pickItem(page, 'out', i, six[i][0]);
        await fill(page.locator(tid('out-row')).nth(i).locator(tid('out-qty')), six[i][1]);
    }
    check('category pills + Harga Modal (reference price) per row; Stok Tersedia shows base stock', (await rowTxt(page, 'out', 0, 'out-cat')) === 'Packaging' && (await rowTxt(page, 'out', 3, 'out-cat')) === 'Aksesoris' && (await rowTxt(page, 'out', 5, 'out-cat')) === 'Bahan' && await rowTxt(page, 'out', 0, 'out-modal') === fmt(4000) && (await rowTxt(page, 'out', 0, 'out-stock')) === '500 PCS' && (await rowTxt(page, 'out', 5, 'out-stock')) === '100 KG', `${await rowTxt(page, 'out', 0, 'out-stock')}`);
    const mkCards = await page.locator('[data-testid^="out-markup-"][data-category]').evaluateAll((els) => els.map((e) => e.getAttribute('data-category')));
    check('three markup cards: exactly the categories present (Packaging, Aksesoris, Bahan)', mkCards.join() === 'Packaging,Aksesoris,Bahan', mkCards.join());
    check('a category with no markup yet blocks the sheet ("isi 0 jika tanpa markup"), never assumed', (await txt(page, 'out-status')).includes('belum diisi') && await page.locator(tid('out-next')).isDisabled() && await rowTxt(page, 'out', 0, 'out-sell') === '—');
    const mkIn = (cat) => page.locator(`[data-category="${cat}"] input.tx2-num`);
    await fill(mkIn('Packaging'), 20); await fill(mkIn('Aksesoris'), 25); await fill(mkIn('Bahan'), 30);
    const sells = []; const totals = [];
    for (let i = 0; i < 6; i++) { sells.push(await rowTxt(page, 'out', i, 'out-sell')); totals.push(await rowTxt(page, 'out', i, 'out-rowtotal')); }
    check('C/D percent markup per category, rows inherit: Packaging 4.800 / 1.800 / 360 · Aksesoris 2.500 / 4.375 · Bahan 16.250', sells.join('|') === [4800, 1800, 360, 2500, 4375, 16250].map(fmt).join('|'), sells.join('|'));
    check('row totals 240.000 / 180.000 / 72.000 / 50.000 / 131.250 / 162.500', totals.join('|') === [240000, 180000, 72000, 50000, 131250, 162500].map(fmt).join('|'), totals.join('|'));
    check('markup column shows the category rule (20% / 25% / 30%)', await rowTxt(page, 'out', 0, 'out-mk') === '20%' && await rowTxt(page, 'out', 3, 'out-mk') === '25%' && await rowTxt(page, 'out', 5, 'out-mk') === '30%');
    await fill(page.locator(tid('out-shipping')), 25000);
    check('summary: 6 jenis · Total Qty 410 (mixed units flagged) · Subtotal Rp 835.750 · Biaya Kirim 25.000 · Grand Total Rp 860.750', await txt(page, 'out-sum-items') === '6 jenis' && (await txt(page, 'out-sum-qty')) === '410' && (await page.locator(tid('out-summary')).innerText()).includes('satuan campur') && await txt(page, 'out-sum-subtotal') === fmt(835750) && await txt(page, 'out-sum-grand') === fmt(860750));
    check('status "Data siap disimpan"', (await txt(page, 'out-status')) === 'Data siap disimpan' && await page.locator(tid('out-next')).isEnabled());
    // ---- E: markup change recalculates instantly, only its own category
    await page.locator('[data-category="Packaging"] .tx2-segbtn[data-value="AMOUNT"]').click();
    await fill(mkIn('Packaging'), 500);
    check('E Packaging → Nominal Rp 500: 4.500 / 2.000 / 800 (modal + Rp 500), others unchanged, no reload', await rowTxt(page, 'out', 0, 'out-sell') === fmt(4500) && await rowTxt(page, 'out', 1, 'out-sell') === fmt(2000) && await rowTxt(page, 'out', 2, 'out-sell') === fmt(800) && await rowTxt(page, 'out', 3, 'out-sell') === fmt(2500) && await txt(page, 'out-sum-subtotal') === fmt(225000 + 200000 + 160000 + 50000 + 131250 + 162500), await txt(page, 'out-sum-subtotal'));
    await page.locator('[data-category="Packaging"] .tx2-segbtn[data-value="PERCENT"]').click();
    await fill(mkIn('Packaging'), 20);
    check('markup back to 20% restores Subtotal Rp 835.750', await txt(page, 'out-sum-subtotal') === fmt(835750));
    // ---- non-base unit + stock follows the unit
    await pickItem(page, 'out', 6, 'Gula Pasir Karung');
    const kUnits = await page.locator(tid('out-row')).nth(6).locator(`${tid('out-unit')} option`).allTextContents();
    check('F approved units only for the item: KARTON + base KG', kUnits.sort().join() === 'KARTON,KG');
    await page.locator(tid('out-row')).nth(6).locator(tid('out-unit')).selectOption({ label: 'KARTON' });
    await fill(page.locator(tid('out-row')).nth(6).locator(tid('out-qty')), 2);
    await page.waitForFunction(() => /KARTON/.test(document.querySelectorAll('[data-testid="out-row"]')[6].querySelector('[data-testid="out-stock"]').textContent));
    check('G Stok Tersedia follows the unit: 192 KG base = 8 KARTON', await rowTxt(page, 'out', 6, 'out-stock') === '8 KARTON');
    check('F non-base unit: Harga Modal per KARTON = 24.000 (not 1.000 × 2), +30% = 31.200, 2 karton = Rp 62.400', await rowTxt(page, 'out', 6, 'out-modal') === fmt(24000) && await rowTxt(page, 'out', 6, 'out-sell') === fmt(31200) && await rowTxt(page, 'out', 6, 'out-rowtotal') === fmt(62400));
    await page.locator(tid('out-row')).nth(6).locator(tid('out-unit')).selectOption({ label: 'KG' });
    check('G changing the unit to KG shows 192 KG and re-prices per kg (1.000 → 1.300)', await rowTxt(page, 'out', 6, 'out-stock') === '192 KG' && await rowTxt(page, 'out', 6, 'out-modal') === fmt(1000) && await rowTxt(page, 'out', 6, 'out-sell') === fmt(1300));
    await page.locator(tid('out-row')).nth(6).locator(tid('out-unit')).selectOption({ label: 'KARTON' });
    await fill(page.locator(tid('out-row')).nth(6).locator(tid('out-qty')), 9);
    check('H 9 KARTON > 8 available → blocked, message shows requested AND available in the selected unit', (await rowTxt(page, 'out', 6, 'out-rowerr')).includes('diminta 9 KARTON, tersedia 8 KARTON') && await page.locator(tid('out-next')).isDisabled());
    await fill(page.locator(tid('out-row')).nth(6).locator(tid('out-qty')), 2);
    check('J/I with the carton row: Subtotal = 835.750 + 62.400 = 898.150, Grand Total = 923.150', await txt(page, 'out-sum-subtotal') === fmt(898150) && await txt(page, 'out-sum-grand') === fmt(923150), await txt(page, 'out-sum-grand'));
    await page.screenshot({ path: path.join(shotDir, 'stockout-desktop-1440.png'), fullPage: true });

    // ---- previews (server-rendered, same HTML as the printout)
    await page.click(tid('out-preview-invoice'));
    await page.waitForSelector(`${tid('tx-doc-frame')}`);
    const frame = page.frameLocator(tid('tx-doc-frame'));
    await frame.locator('body').waitFor();
    try { await page.waitForFunction(() => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes('INVOICE'); }, null, { timeout: 15000 }); } catch (e) { console.log('DOC STATUS:', await page.locator('.tx2-docstatus').innerText(), '| frame text:', (await frame.locator('body').innerText()).slice(0, 200)); throw e; }
    const invPrev = (await frame.locator('body').innerText()).replace(/\s+/g, ' ');
    check('preview Invoice: CV AMOR GROUP, Kepada Yth. bakery + address, Harga Jual, Subtotal, Biaya Kirim, Grand Total; number is assigned on save', ['CV AMOR GROUP', 'INVOICE', 'Amor Bakery - Pusat', 'Jl. Sudirman No. 123', 'Harga Jual', 'Rp 4.800', 'Rp 31.200', 'Subtotal Harga Jual', 'Rp 898.150', 'Biaya Kirim', 'Rp 25.000', 'Grand Total', 'Rp 923.150', 'REF-OUT-001', 'Nomor otomatis saat disimpan'].every((s) => invPrev.includes(s)), invPrev.slice(0, 300));
    check('preview Invoice does NOT leak cost: no Harga Modal/Harga Beli/HPP/Markup/%, none of the cost prices', !/Modal|Harga Beli|HPP|Markup|Margin|%/i.test(invPrev) && !/Rp (4\.000|1\.500|300|2\.000|3\.500|12\.500|24\.000)(?![\d.])/.test(invPrev));
    check('preview logo is the supplied Amor logo', await frame.locator('img.logo').getAttribute('src') === '/assets/images/amor-logo.jpg');
    await page.click(tid('tx-doc-tab-1'));
    await page.waitForFunction(() => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes('DELIVERY ORDER'); }, null, { timeout: 15000 });
    const doPrev = (await frame.locator('body').innerText()).replace(/\s+/g, ' ');
    check('preview DO: destination + PIC + contact, origin warehouse, items with Qty and Satuan, signature blocks', ['DELIVERY ORDER', 'Amor Bakery - Pusat', 'Budi Santoso', '0812-3456-7890', 'Gudang Cibadak', 'Box Cake 20x20', 'Gula Pasir Karung', 'Disiapkan oleh', 'Diterima oleh'].every((s) => doPrev.includes(s)));
    check('preview DO shows NO price data (no Rp / Harga / Markup / Total)', !/Rp|Harga|Markup|HPP|Modal|Total|Subtotal/i.test(doPrev));
    await page.screenshot({ path: path.join(shotDir, 'stockout-preview-drawer.png') });
    await page.click('.drawer-close'); await page.waitForTimeout(250);
    check('preview drawer closed: page scroll lock removed', await page.evaluate(() => !document.documentElement.classList.contains('tx2-scroll-lock') && !document.querySelector('.drawer.drawer-tx')));

    // ---- review + save
    const stockBefore = Object.fromEntries(['box', 'bag', 'stiker', 'lilin', 'topper', 'tepung', 'karung'].map((k) => [k, Number(sql(`SELECT SUM(qty_base) FROM inventory_batches WHERE item_id=${I[k].id} AND warehouse_id=${seed.wh.A}`))]));
    const costBefore = Object.fromEntries(['box', 'bag', 'stiker', 'lilin', 'topper', 'tepung', 'karung'].map((k) => [k, Number(sql(`SELECT unit_cost_base FROM inventory_batches WHERE item_id=${I[k].id} AND warehouse_id=${seed.wh.A} ORDER BY id LIMIT 1`))]));
    await page.click(tid('out-next'));
    await page.waitForSelector(tid('out-review-row'), { timeout: 15000 });
    const orev = (await page.locator(tid('out-review')).innerText()).replace(/\s+/g, ' ');
    check('review: warehouse, bakery + address, date, reference, markup rules, items with Qty/unit/Harga Jual/Total, subtotal, shipping, grand total, preview buttons', ['Gudang Cibadak', 'Amor Bakery - Pusat', 'Jl. Sudirman No. 123', 'REF-OUT-001', 'Markup:', 'Packaging: 20%', 'Aksesoris: 25%', 'Bahan: 30%', '2 KARTON', 'Subtotal Harga Jual', 'Biaya Kirim', 'Grand Total'].every((s) => orev.includes(s)) && await page.locator(tid('out-review-row')).count() === 7 && await page.locator(tid('out-review-preview-do')).count() === 1 && await page.locator(tid('out-review-preview-invoice')).count() === 1, orev.slice(0, 200));
    check('review Grand Total (server) = sheet Grand Total = Rp 923.150', (await txt(page, 'out-review-grand')) === fmt(923150) && await txt(page, 'out-review-subtotal') === fmt(898150) && await txt(page, 'out-review-shipping') === fmt(25000));
    check('review shows no cost: no Harga Modal / HPP column', !/Harga Modal|HPP/i.test(orev));
    await page.click(tid('out-post'));
    await page.waitForSelector(tid('out-done'), { timeout: 25000 });
    const doNo = await txt(page, 'out-done-do'); const invNo = await txt(page, 'out-done-invoice');
    check('saved: DO-YYYYMMDD-#### and INV-YYYYMMDD-#### shown with Grand Total Rp 923.150', /^DO-\d{8}-\d{4}$/.test(doNo) && /^INV-\d{8}-\d{4}$/.test(invNo) && await txt(page, 'out-done-grand') === fmt(923150), `${doNo} ${invNo}`);
    // ---- DB truth
    const doRow = sql(`SELECT id, status, from_warehouse_id, bakery_destination_id, reference_no, notes FROM distribution_orders WHERE do_number='${doNo}'`).split('\t');
    check('DB: DO DISPATCHED from the chosen warehouse to the bakery with reference + notes', doRow[1] === 'DISPATCHED' && doRow[2] === String(seed.wh.A) && doRow[3] === String(seed.bakery) && doRow[4] === 'REF-OUT-001' && doRow[5] === 'Pengiriman rutin bahan & packaging');
    const invRow = sql(`SELECT id, status, subtotal, shipping_amount, grand_total, discount_amount, tax_amount FROM distribution_invoices WHERE invoice_number='${invNo}'`).split('\t');
    check('DB: Invoice ISSUED, subtotal 898.150 + shipping 25.000 = 923.150, no discount, no tax', invRow[1] === 'ISSUED' && near(invRow[2], 898150) && near(invRow[3], 25000) && near(invRow[4], 923150) && Number(invRow[5]) === 0 && Number(invRow[6]) === 0);
    const stockAfter = Object.fromEntries(Object.keys(stockBefore).map((k) => [k, Number(sql(`SELECT SUM(qty_base) FROM inventory_batches WHERE item_id=${I[k].id} AND warehouse_id=${seed.wh.A}`))]));
    check('K FIFO stock reduced exactly: box −50, bag −100, stiker −200, lilin −20, topper −30, tepung −10, karung −48 kg (2 karton)', near(stockBefore.box - stockAfter.box, 50) && near(stockBefore.bag - stockAfter.bag, 100) && near(stockBefore.stiker - stockAfter.stiker, 200) && near(stockBefore.lilin - stockAfter.lilin, 20) && near(stockBefore.topper - stockAfter.topper, 30) && near(stockBefore.tepung - stockAfter.tepung, 10) && near(stockBefore.karung - stockAfter.karung, 48));
    const outCosts = sql(`SELECT l.item_id, l.unit_cost_base FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id=t.id WHERE t.reference_no='${doNo}'`).split('\n').map((x) => x.split('\t'));
    check('L OUT lines carry the real FIFO cost (HPP 4.000 / 1.500 / 300 / 2.000 / 3.500 / 12.500 / 1.000), never a selling price', outCosts.length === 7 && outCosts.every(([id, c]) => { const k = Object.keys(I).find((x) => String(I[x].id) === id); return near(c, costBefore[k]); }));
    check('only /stock-out endpoints wrote (quote, preview, post) — no legacy POST /transactions/out', requests.filter((r) => r.method !== 'GET').every((r) => r.path.startsWith('/stock-out') || r.path === '/stock-in' || r.path === '/stock-in/quote') && !requests.some((r) => r.path === '/transactions/out'));
    // ---- print / reprint
    await page.click(tid('out-done-print-invoice'));
    await page.waitForFunction((n) => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes(n); }, invNo, { timeout: 15000 });
    const savedInv = (await page.frameLocator(tid('tx-doc-frame')).locator('body').innerText()).replace(/\s+/g, ' ');
    check('Cetak Invoice opens the saved invoice with its number, selling prices and totals — no cost', savedInv.includes(invNo) && savedInv.includes('Rp 923.150') && savedInv.includes('Rp 4.800') && !/Modal|HPP|Markup|%/i.test(savedInv));
    await page.click(tid('tx-doc-tab-1'));
    await page.waitForFunction((n) => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes(n); }, doNo, { timeout: 15000 });
    check('Cetak DO opens the saved DO with its number', (await page.frameLocator(tid('tx-doc-frame')).locator('body').innerText()).includes(doNo));
    check('Cetak button present in the document drawer', await page.locator(tid('tx-doc-print')).count() === 1);
    await page.click('.drawer-close'); await page.waitForTimeout(250);
    await page.click(tid('out-new'));
    await page.waitForSelector(tid('out-header'));
    await page.click(tid('out-history'));
    await page.waitForSelector(tid('out-history-row'), { timeout: 10000 });
    check('Riwayat lists the saved document (reprint any time)', (await page.locator(tid('out-history-table')).innerText()).includes(doNo) && (await page.locator(tid('out-history-table')).innerText()).includes(invNo));
    await page.locator(tid('out-history-row')).first().locator(tid('out-history-invoice')).click();
    await page.waitForFunction((n) => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes(n); }, invNo, { timeout: 15000 });
    check('reprint from Riwayat shows the same invoice', true);
    await page.click('.drawer-close'); await page.waitForTimeout(250);
    }

    if (!ONLY || ONLY === 'out') {
    // ---- History Transaksi → reprint DO / Invoice for a Stock OUT V2 transaction
    const histDo = sql("SELECT do_number FROM distribution_orders ORDER BY id DESC LIMIT 1");
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="history-transaksi"]').click());
    await page.waitForSelector('#tab-history-transaksi.active', { timeout: 10000 });
    await page.waitForSelector('#tab-history-transaksi tbody tr:not(.dt-skeleton-row) td:not(.dt-empty)', { timeout: 15000 });
    await page.locator('#tab-history-transaksi input[type="text"]').first().fill(histDo);
    await page.waitForFunction((n) => Array.from(document.querySelectorAll('#tab-history-transaksi tbody tr')).some((r) => r.innerText.includes(n)), histDo, { timeout: 15000 });
    await page.locator('#tab-history-transaksi tbody tr', { hasText: histDo }).first().locator('td').first().click();
    await page.waitForSelector(tid('hist-print-do'), { timeout: 10000 });
    check('History Transaksi → OUT detail offers Cetak DO and Cetak Invoice', await page.locator(tid('hist-print-do')).count() === 1 && await page.locator(tid('hist-print-invoice')).count() === 1);
    await page.click(tid('hist-print-invoice'));
    await page.waitForFunction(() => { const f = document.querySelector('[data-testid="tx-doc-frame"]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerText.includes('INVOICE') && f.contentDocument.body.innerText.includes('Grand Total'); }, null, { timeout: 15000 });
    check('…and the invoice reprints from History Transaksi (selling prices only)', !/Modal|HPP|Markup|%/i.test(await page.frameLocator(tid('tx-doc-frame')).locator('body').innerText()));
    await page.click('.drawer-close'); await page.waitForTimeout(250);

    // ---- warehouse scope + permissions
    const stock = await newSession(browser, { viewport: { width: 1280, height: 900 }, __name: 'stockA' }, seed.stockA);
    await openTx(stock.page, 'in');
    check('STOCK user (scoped to Gudang Cibadak): Stock IN warehouse is locked to its own', await stock.page.locator(tid('in-warehouse')).isDisabled() && await stock.page.locator(tid('in-warehouse')).inputValue() === String(seed.wh.A), `${await stock.page.locator(tid('in-warehouse')).isDisabled()} ${await stock.page.locator(tid('in-warehouse')).inputValue()} ${JSON.stringify(await stock.page.evaluate(() => [Auth.user().role_code, Auth.user().warehouse_id, Master.warehouses().map((w) => w.id)]))}`);
    await stock.page.click(tid('tx-tab-out'));
    await stock.page.waitForSelector(tid('out-header'));
    check('STOCK user: Stock OUT Gudang Asal locked to its own warehouse; bakery selectable', await stock.page.locator(tid('out-warehouse')).isDisabled() && await stock.page.locator(tid('out-warehouse')).inputValue() === String(seed.wh.A) && await stock.page.locator(`${tid('out-bakery')} option`).count() >= 2);
    await stock.page.selectOption(tid('out-bakery'), String(seed.bakery));
    await pickItem(stock.page, 'out', 0, 'Box Cake 20x20');
    await page.waitForTimeout(300);
    check('STOCK user sees stock of its own warehouse only', (await rowTxt(stock.page, 'out', 0, 'out-stock')) === '450 PCS' || (await rowTxt(stock.page, 'out', 0, 'out-stock')).endsWith('PCS'));
    const forged = await stock.page.evaluate(async (wh) => {
        const me = Auth.user();
        const r = await fetch('/api/stock-out/quote', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf_token }, body: JSON.stringify({ warehouse_id: wh, bakery_destination_id: 1, transaction_date: '2026-10-04', lines: [] }) });
        return r.status;
    }, seed.wh.B);
    check('STOCK user cannot quote/post for another warehouse even by a forged request (403)', forged === 403, String(forged));
    await stock.context.close();
    const viewer = await newSession(browser, { viewport: { width: 1280, height: 900 }, __name: 'viewer' }, seed.viewer);
    await viewer.page.evaluate(() => document.querySelector('.sidebar-link[data-tab="transaksi"]')?.click());
    await viewer.page.waitForTimeout(500);
    check('VIEWER (no Stock IN/OUT permission) cannot reach the workspace', await viewer.page.locator(tid('tx-root')).count() === 0);
    await viewer.context.close();
    }

    // ---- layouts: desktop / iPad landscape / iPad portrait / mobile
    const layouts = [['ipad-landscape-1180', 1180, 820], ['ipad-portrait-820', 820, 1180], ['mobile-390', 390, 844]];
    for (const [name, w, h] of layouts) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        const p = s.page;
        for (const kind of ['in', 'out']) {
            await openTx(p, kind);
            if (kind === 'in') {
                await p.selectOption(tid('in-warehouse'), String(seed.wh.B));
                await pickItem(p, 'in', 0, 'Gula Pasir'); await fill(p.locator(tid('in-row')).nth(0).locator(tid('in-qty')), 12345);
                await pickItem(p, 'in', 1, 'Tepung Terigu Segitiga Biru'); await fill(p.locator(tid('in-row')).nth(1).locator(tid('in-qty')), 9);
                await fill(p.locator(tid('in-freight')), 987654321);
            } else {
                await p.selectOption(tid('out-warehouse'), String(seed.wh.A));
                await p.selectOption(tid('out-bakery'), String(seed.bakery));
                await pickItem(p, 'out', 0, 'Box Cake 20x20'); await fill(p.locator(tid('out-row')).nth(0).locator(tid('out-qty')), 5);
                await pickItem(p, 'out', 1, 'Lilin Ulang Tahun'); await fill(p.locator(tid('out-row')).nth(1).locator(tid('out-qty')), 3);
                await fill(p.locator('[data-category="Packaging"] input.tx2-num'), 20); await fill(p.locator('[data-category="Aksesoris"] input.tx2-num'), 25);
                await fill(p.locator(tid('out-shipping')), 987654321);
            }
            await p.waitForTimeout(200);
            const geo = await p.evaluate(() => {
                const docOverflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
                const bar = document.querySelector('.tx2-bar').getBoundingClientRect();
                const nums = Array.from(document.querySelectorAll('.tx2-bar-g, .tx2-grand-v, .tx2-grand span:last-child, .tx2-tile-v')).map((e) => ({ t: e.textContent, over: e.scrollWidth - e.clientWidth, inside: (() => { const r = e.getBoundingClientRect(); const c = e.parentElement.getBoundingClientRect(); return r.right <= c.right + 1 && r.left >= c.left - 1; })() }));
                const wrap = document.querySelector('.tx2-tablewrap');
                let sc = document.querySelector('.tx2-bar').parentElement; const chain = []; while (sc && sc !== document.documentElement) { const o = getComputedStyle(sc); if (/(auto|scroll|hidden)/.test(o.overflowY + o.overflowX)) chain.push(`${sc.tagName}.${sc.className.toString().slice(0, 30)}:${o.overflowY}`); sc = sc.parentElement; }
                return { chain, bar: [bar.top, bar.bottom, window.innerHeight], docOverflow, barVisible: bar.bottom <= window.innerHeight + 1 && bar.top >= 0, nums, tableScrolls: wrap.scrollWidth > wrap.clientWidth, canScrollY: document.documentElement.scrollHeight > window.innerHeight };
            });
            check(`[${name}/${kind}] no horizontal page overflow`, geo.docOverflow <= 1, `overflow=${geo.docOverflow}`);
            check(`[${name}/${kind}] sticky summary bar (totals + action) stays reachable inside the viewport`, geo.barVisible, JSON.stringify([geo.bar, geo.chain]));
            check(`[${name}/${kind}] big Rupiah figures fit their boxes (no overflow)`, geo.nums.every((x) => x.over <= 1 && x.inside), JSON.stringify(geo.nums.filter((x) => x.over > 1 || !x.inside)));
            check(`[${name}/${kind}] the item table scrolls sideways inside its own wrapper (page itself does not)`, w >= 1400 ? true : geo.tableScrolls);
            await p.screenshot({ path: path.join(shotDir, `stock${kind}-${name}.png`), fullPage: true });
        }
        await s.context.close();
    }

    await context.close();
} catch (err) {
    console.error('TEST CRASH', err);
    results.push(false);
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const realErrors = consoleErrors.filter((c) => !/Failed to load resource.*(401|403|404)/i.test(c));
check('no console / page errors', realErrors.length === 0, realErrors.slice(0, 5).join(' || '));
const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
