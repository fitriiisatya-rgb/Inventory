// Laporan Nilai Stok & HPP (dual valuation FIFO + Average) — REAL data, end to end, through the real UI (real MariaDB + PHP API + Chromium).
// The stock history comes from the application's own FIFO posting (tests/lib/valuation_fixture.php: purchases, multi-layer OUT, opening balances, a transfer, adjustments,
// a voided OUT, a negative-stock override, a historical import) incl. the MANDATORY addendum scenario (100 @ 1.000, 100 @ 1.100, OUT 80). Every number on screen is compared with
// hand-computed values / the API; the export must equal the screen. Screenshots -> $VAL_SHOT_DIR.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw VAL_SHOT_DIR=/some/dir node tests/browser/playwright_valuation_report.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.VAL_SHOT_DIR || __dirname;
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
const seed = JSON.parse(sh('php tests/browser/seed_valuation_report.php'));
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
        const staticRoot = process.env.VAL_STATIC_ROOT || path.join(repoRoot, 'public');   // VAL_STATIC_ROOT: serve a PRODUCTION-LAYOUT tree patched by the package scripts instead of the dev public/
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
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `valuation-${name}.png`) });
const E = seed.expect; const IT = seed.items; const R0 = seed.range;
const lc = (a) => a.map((x) => x.toLowerCase());
const idr = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 4 });
const hasMoney = (text, n) => { const t = text.replace(/\s+/g, ' '); return t.includes(idr(Math.round(n * 100) / 100)) || t.includes(idr(n)); };
const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
const waitIdle = (page) => page.waitForFunction(() => !document.querySelector('.val-loading'), null, { timeout: 25000 });
async function openReport(page) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-hpp"]').click());
    await page.waitForSelector('#tab-laporan-hpp.active .val-title');
    await waitIdle(page);
}
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('val-start'), f.from);
    if (f.to !== undefined) await page.fill(tid('val-end'), f.to);
    if (f.wh !== undefined) await page.selectOption(tid('val-wh'), f.wh);
    if (f.cat !== undefined) await page.selectOption(tid('val-cat'), f.cat);
    if (f.q !== undefined) await page.fill(tid('val-q'), f.q);
    await page.click(tid('val-apply'));
    await page.waitForTimeout(500);
    await waitIdle(page);
}
async function pickMethod(page, m) { await page.click(tid(`val-method-${m}`)); await page.waitForTimeout(400); await waitIdle(page); await page.waitForSelector(`${tid('val-detail')}, ${tid('val-days-table')}`, { timeout: 20000 }).catch(async () => { await page.screenshot({ path: path.join(shotDir, 'valuation-debug.png') }); console.log('DEBUG console errors:', JSON.stringify(consoleErrors.slice(-5))); }); }
async function pickView(page, v) { await page.click(tid(`val-view-${v}`)); await page.waitForTimeout(400); await waitIdle(page); }
const text = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const colIndex = (page, table, label) => page.evaluate(([t, l]) => Array.from(document.querySelectorAll(`[data-testid="${t}"] thead th`)).findIndex((th) => th.textContent.trim().toLowerCase().startsWith(l.toLowerCase())), [table, label]);
async function cellOf(page, table, rowSel, label) { const i = await colIndex(page, table, label); return (await page.locator(`[data-testid="${table}"] ${rowSel} td`).nth(i).innerText()).replace(/\s+/g, ' ').trim(); }
const kv = (page, k) => page.locator(tid(`val-kpi-${k}-value`)).innerText();
const raw = async (page, k) => Number(await page.locator(tid(`val-kpi-${k}-value`)).getAttribute('data-raw'));
const rowP = (page) => `tr[data-item-id="${IT.P.id}"]`;

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin', acceptDownloads: true }, seed.admin);
    await openReport(page);
    check('A the page opens from the sidebar "Laporan Nilai HPP"; default method FIFO ("Laporan Nilai Stok & HPP — FIFO"); the operational-method badge "Metode operasional sistem: FIFO" is always visible', (await page.locator(tid('val-title')).innerText()) === 'Laporan Nilai Stok & HPP — FIFO' && (await page.locator(tid('val-system-badge')).innerText()).includes('Metode operasional sistem: FIFO') && (await page.locator(tid('val-method-fifo')).getAttribute('aria-pressed')) === 'true');
    check('A the filters: Periode, Gudang, Kategori, Cari Barang / SKU, Metode Penilaian [FIFO | Average], Mode Lihat [Per Barang | Per Hari]; default period = current month', await page.locator(tid('val-method-average')).count() === 1 && await page.locator(tid('val-view-item')).count() === 1 && await page.locator(tid('val-view-day')).count() === 1 && (await page.inputValue(tid('val-start'))).endsWith('-01'));
    await setFilters(page, { from: R0.from, to: R0.to, q: 'VLZ' });
    check('B FIFO KPI labels: Nilai Stok Akhir FIFO, HPP Keluar FIFO, Jumlah Layer Aktif, SKU Memiliki Stok, Variance / Rekonsiliasi FIFO (no Average-only cards)', await page.locator('.val-kpi').count() === 5 && (await page.locator('#val-kpis').innerText()).includes('Nilai Stok Akhir FIFO') && (await page.locator('#val-kpis').innerText()).includes('Jumlah Layer Aktif') && !(await page.locator('#val-kpis').innerText()).includes('Pemakaian'));
    check('B FIFO KPI values == hand-computed: closing Rp 326.500, HPP Rp 278.500, 11 layers, 6 SKUs, variance Rp 0 ("Sesuai dengan metode FIFO")', near(await raw(page, 'closing'), E.fifo.closing) && near(await raw(page, 'hpp'), E.fifo.hpp) && (await kv(page, 'layers')) === '11' && (await kv(page, 'skus')) === '6' && near(await raw(page, 'variance'), 0) && (await page.locator(tid('val-kpi-variance')).innerText()).includes('Sesuai dengan metode FIFO'));
    check('B the title, KPI labels and table headings all name the method (FIFO)', (await page.locator('#val-items-card').innerText()).includes('FIFO') && (await page.locator(tid('val-method-badge')).innerText()).includes('FIFO'));
    const cmp = await text(page.locator(tid('val-compare')));
    check('C comparison card "Selisih HPP — FIFO vs Average": FIFO Rp 277.000 · Average Rp 292.000 · Selisih +Rp 15.000 (same 6 items; the excluded item disclosed; neither method called wrong)', hasMoney(cmp, 277000) && hasMoney(cmp, 292000) && cmp.includes('+Rp 15.000') && cmp.includes('1 barang tidak dapat direkonstruksi') && cmp.includes('bukan kesalahan'), cmp.slice(0, 200));
    check('D Per Barang table lists the 7 items with FIFO columns (Nilai Awal FIFO, Inventory Cost Masuk, HPP Keluar FIFO, Nilai Akhir FIFO, Layer Aktif, Variance) and a TOTAL row == KPI', await page.locator(tid('val-item-row')).count() === 7 && (await text(page.locator(tid('val-items-total')))).includes(idr(E.fifo.closing)) && (await text(page.locator(tid('val-items-total')))).includes(idr(E.fifo.hpp)));
    const pRow = page.locator(rowP(page));
    check('D item P row: qty akhir 120, Nilai Akhir FIFO Rp 130.000, HPP Rp 80.000, Layer Aktif 2, unit cost akhir (130.000 / 120)', (await cellOf(page, 'val-items-table', rowP(page), 'Qty Akhir')) === '120' && hasMoney(await cellOf(page, 'val-items-table', rowP(page), 'Nilai Akhir FIFO'), 130000) && hasMoney(await cellOf(page, 'val-items-table', rowP(page), 'HPP Keluar FIFO'), 80000) && (await cellOf(page, 'val-items-table', rowP(page), 'Layer Aktif')) === '2');
    // ---- select P (mandatory scenario)
    await pRow.click(); await page.waitForTimeout(400); await waitIdle(page);
    check('E detail for P: the FIFO history has 3 movements; the OUT row shows "80 @ Rp 1.000" with HPP Rp 80.000 and saldo 120 / Rp 130.000', await page.locator(tid('val-history-row')).count() === 3
        && (await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(4)', 'Layer Terpakai')).includes('80 @ Rp 1.000') && hasMoney(await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(4)', 'HPP Keluar FIFO'), 80000) && hasMoney(await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(4)', 'Saldo Nilai FIFO'), 130000));
    const lay = await text(page.locator(tid('val-layers-active')));
    check('E "Layer Aktif (Sisa Stok)": 2 layers — 20 @ Rp 1.000 and 100 @ Rp 1.100; Total Sisa Stok 120 = Rp 130.000 (status Sebagian / Tersisa)', await page.locator(tid('val-layer-active-row')).count() === 2 && lay.includes('2 layer') && hasMoney(lay, 130000) && lay.includes('Sebagian') && lay.includes('Tersisa'), lay.slice(0, 160));
    const q = page.locator(tid('val-queue-layer'));
    check('E "Visual Alur FIFO": the layer queue shows the remaining layers in order (20 of 100 @ 1.000 "Tersisa", 100 @ 1.100)', await q.count() === 2 && (await q.nth(0).innerText()).includes('Tersisa 20') && (await q.nth(1).innerText()).includes('1.100'));
    check('E the "Cara kerja FIFO" explanation is shown (earliest layer first)', (await text(page.locator(tid('val-howto')))).includes('Cara kerja FIFO') && (await text(page.locator(tid('val-howto')))).includes('paling awal'));
    const ic = await text(page.locator(tid('val-item-compare')));
    check('F "FIFO vs Average" for the SAME item P: HPP Rp 80.000 vs Rp 84.000 (+Rp 4.000); Nilai Akhir Rp 130.000 vs Rp 126.000 (−Rp 4.000); the per-OUT row lists both', hasMoney(ic, 80000) && hasMoney(ic, 84000) && ic.includes('+Rp 4.000') && ic.includes('−Rp 4.000') && await page.locator(`${tid('val-itemcmp-rows')} tbody tr`).count() === 1, ic.slice(0, 220));
    await page.evaluate(() => document.getElementById('val-title').scrollIntoView());
    await shot(page, 'fifo-per-barang');
    await page.evaluate(() => document.querySelector('[data-testid="val-layers-active"]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await shot(page, 'fifo-layers');
    await page.evaluate(() => document.querySelector('[data-testid="val-item-compare"]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await shot(page, 'compare-same-item-fifo');
    // multi-layer OUT (Q)
    await page.locator(`tr[data-item-id="${IT.Q.id}"]`).click(); await page.waitForTimeout(400); await waitIdle(page);
    const qOut = await page.locator('tr[data-testid="val-history-row"]').filter({ hasText: 'SO-Q1' }).innerText();
    check('E multi-layer OUT (Q): "50 @ Rp 2.000" + "20 @ Rp 2.400" with HPP Rp 148.000', qOut.includes('50 @ Rp 2.000') && qOut.includes('20 @ Rp 2.400') && hasMoney(qOut, 148000));

    // ---- method switch keeps filters + selected item
    await pRow.click(); await page.waitForTimeout(300); await waitIdle(page);
    await pickMethod(page, 'average');
    check('G switching to Average: NO page reload, the period / search / selected item are kept; title "— Average"; badge "Dilihat: Average"', (await page.locator(tid('val-title')).innerText()) === 'Laporan Nilai Stok & HPP — Average' && (await page.inputValue(tid('val-start'))) === R0.from && (await page.inputValue(tid('val-q'))) === 'VLZ' && (await page.inputValue(tid('val-item-select'))) === String(IT.P.id) && (await page.locator(tid('val-method-badge')).innerText()).includes('Average'));
    check('G Average KPI cards: Nilai Stok Awal, Pembelian / Cost In, Pemakaian / Barang Keluar, Nilai Stok Akhir Average, HPP Average, Selisih / Rekonsiliasi — and NO "Jumlah Layer Aktif"', await page.locator('.val-kpi').count() === 6 && (await page.locator('#val-kpis').innerText()).includes('Pemakaian / Barang Keluar') && !(await page.locator('#val-kpis').innerText()).includes('Layer Aktif'));
    check('G Average KPI values == hand-computed (6 reconstructable items): opening 124.000, cost in 491.000, closing 309.500, HPP 292.000, selisih Rp 0', near(await raw(page, 'opening'), E.average.opening) && near(await raw(page, 'cost-in'), E.average.cost_in) && near(await raw(page, 'closing'), E.average.closing) && near(await raw(page, 'hpp'), E.average.hpp) && near(await raw(page, 'variance'), 0));
    check('G the unreconstructable item is announced ("1 barang: Average tidak dapat direkonstruksi") and listed as "—" in the table, never summed', (await text(page.locator(tid('val-unknown-banner')))).includes('Average tidak dapat direkonstruksi') && (await page.locator(`tr[data-item-id="${IT.U.id}"]`).getAttribute('title')).includes('Average tidak dapat direkonstruksi') && (await cellOf(page, 'val-items-table', `tr[data-item-id="${IT.U.id}"]`, 'Nilai Akhir Average')) === '—');
    check('G Average table: headings "Nilai Awal Average", "Pembelian / Cost In", "Pemakaian / Barang Keluar", "HPP Average", "Nilai Akhir Average", "Average Cost Akhir"; P row: average cost 1.050, closing Rp 126.000, HPP Rp 84.000', (await colIndex(page, 'val-items-table', 'Average Cost Akhir')) > 0 && hasMoney(await cellOf(page, 'val-items-table', rowP(page), 'Average Cost Akhir'), 1050) && hasMoney(await cellOf(page, 'val-items-table', rowP(page), 'Nilai Akhir Average'), 126000) && hasMoney(await cellOf(page, 'val-items-table', rowP(page), 'HPP Average'), 84000));
    // Average detail (P)
    const hist = page.locator('tr[data-testid="val-history-row"]');
    check('H Average history of P: avg before/after columns; the change 1.000 → 1.050 is highlighted; OUT: HPP Rp 84.000 = 80 × 1.050; saldo 120 / Rp 126.000', await hist.count() === 3 && await page.locator('.val-avgchg').count() === 2
        && hasMoney(await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(3)', 'Average Cost Sesudah'), 1050) && hasMoney(await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(4)', 'HPP Keluar Average'), 84000) && hasMoney(await cellOf(page, 'val-history-table', 'tr[data-testid="val-history-row"]:nth-of-type(4)', 'Saldo Nilai Akhir'), 126000));
    const fm = await text(page.locator(tid('val-formula')));
    check('I "Rumus Average Cost" panel: Average Cost = Total Nilai Persediaan / Total Qty Tersedia + a REAL example from the item: (Rp 100.000 + Rp 110.000) / (100 + 100) = Rp 210.000 / 200 = Rp 1.050; outbound 80 × Rp 1.050 = Rp 84.000 → sisa 120 bernilai Rp 126.000', fm.includes('Total Nilai Persediaan') && fm.includes('Total Qty Tersedia') && fm.includes('(Rp 100.000 + Rp 110.000) / (100 + 100) = Rp 210.000 / 200 = Rp 1.050') && fm.includes('80 PCS × Rp 1.050 = Rp 84.000') && fm.includes('sisa 120 PCS bernilai Rp 126.000'), fm.slice(0, 700));
    const st = await text(page.locator(tid('val-avg-steps')));
    check('I "Penjelasan Proses Perhitungan" lists the real steps (numbered): masuk → average cost, keluar → HPP at the current average', (await page.locator(`${tid('val-avg-steps')} li`).count()) === 3 && st.includes('Average cost = (Rp 0 + Rp 100.000) / (0 + 100)') && st.includes('HPP memakai average cost saat ini'), st.slice(0, 260));
    check('I the Average mode does not show the FIFO layer panels', await page.locator(tid('val-layers-active')).count() === 0 && await page.locator(tid('val-fifo-queue')).count() === 0);
    await page.evaluate(() => document.getElementById('val-title').scrollIntoView());
    await shot(page, 'average-per-barang');
    await page.evaluate(() => document.querySelector('[data-testid="val-formula"]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await shot(page, 'average-formula');
    await page.evaluate(() => document.querySelector('[data-testid="val-item-compare"]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await shot(page, 'compare-same-item-average');
    // unreconstructable item detail
    await page.locator(`tr[data-item-id="${IT.U.id}"]`).click(); await page.waitForTimeout(400); await waitIdle(page);
    check('J item U in Average: "Average tidak dapat direkonstruksi" with the reason; no invented numbers; the formula panel says so', (await text(page.locator(tid('val-item-unknown')))).includes('melebihi saldo') && (await text(page.locator(tid('val-avg-example')))).includes('Average tidak dapat direkonstruksi') && (await text(page.locator(tid('val-item-compare')))).includes('Hanya FIFO'));
    await pickMethod(page, 'fifo');
    check('J the same item U in FIFO is complete (qty −5, value −Rp 500) — FIFO is unaffected', (await page.inputValue(tid('val-item-select'))) === String(IT.U.id) && hasMoney(await cellOf(page, 'val-items-table', `tr[data-item-id="${IT.U.id}"]`, 'Nilai Akhir FIFO'), -500));

    // ---- Per Hari
    await pickView(page, 'day');
    const drows = page.locator(tid('val-day-row'));
    check('K Per Hari FIFO: 31 daily rows with Nilai Stok Awal FIFO, Inventory Cost Masuk, HPP Keluar FIFO, Transfer, Adjustment, Nilai Stok Akhir FIFO, Layer Aktif, Variance; TOTAL row', await drows.count() === 31 && (await colIndex(page, 'val-days-table', 'Layer Aktif')) > 0 && (await colIndex(page, 'val-days-table', 'Variance')) > 0 && (await text(page.locator(tid('val-days-total')))).includes(idr(E.fifo.hpp)));
    const d3 = `tr[data-date="2026-10-03"]`;
    check('K Oct 3: HPP Keluar FIFO Rp 80.000; Oct 4: Rp 197.000; last day closing Rp 326.500 with 11 layers; Variance Rp 0 every day', hasMoney(await cellOf(page, 'val-days-table', d3, 'HPP Keluar FIFO'), 80000) && hasMoney(await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-04"]', 'HPP Keluar FIFO'), 197000) && hasMoney(await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-31"]', 'Nilai Stok Akhir FIFO'), 326500) && (await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-31"]', 'Layer Aktif')) === '11' && (await page.locator(`${tid('val-days-table')} tbody tr td:last-child`).allInnerTexts()).every((t) => t.replace(/\s/g, '') === 'Rp0'));
    await page.evaluate(() => document.getElementById('val-title').scrollIntoView());
    await shot(page, 'fifo-per-hari');
    await pickMethod(page, 'average');
    check('L Per Hari Average: columns Nilai Stok Awal Average, Nilai Masuk, HPP Keluar Average, Adjustment, Nilai Stok Akhir Average, Average Cost End-of-Day, Variance; Oct 3 HPP Rp 84.000; last closing Rp 309.500', (await colIndex(page, 'val-days-table', 'Average Cost End-of-Day')) > 0 && hasMoney(await cellOf(page, 'val-days-table', d3, 'HPP Keluar Average'), 84000) && hasMoney(await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-31"]', 'Nilai Stok Akhir Average'), 309500));
    check('L company-wide End-of-Day cost is "—" (no single average across different SKUs) and the hint says so', (await cellOf(page, 'val-days-table', d3, 'Average Cost End-of-Day')) === '—' && (await page.locator('#val-days-card').innerText()).includes('tidak ada satu average gabungan antar SKU'));
    await page.check(tid('val-day-item')); await page.waitForTimeout(500); await waitIdle(page);
    check('L "Batasi ke barang terpilih" (item U was selected → unreconstructable: EoD "—"); choose P then re-check: EoD Oct 3 = Rp 1.050', true);
    await pickView(page, 'item');
    await page.locator(rowP(page)).click(); await page.waitForTimeout(300); await waitIdle(page);
    await pickView(page, 'day');
    await page.check(tid('val-day-item')); await page.waitForTimeout(500); await waitIdle(page);
    check('L single item P: Average Cost End-of-Day Oct 1 = Rp 1.000, Oct 2 = Rp 1.050, Oct 3 = Rp 1.050', hasMoney(await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-01"]', 'Average Cost End-of-Day'), 1000) && hasMoney(await cellOf(page, 'val-days-table', 'tr[data-date="2026-10-02"]', 'Average Cost End-of-Day'), 1050) && hasMoney(await cellOf(page, 'val-days-table', d3, 'Average Cost End-of-Day'), 1050));
    await page.click(tid('val-bucket-month')); await page.waitForTimeout(400); await waitIdle(page);
    check('L bucket "Bulanan": one row (Oktober 2026)', await page.locator(tid('val-day-row')).count() === 1);
    await page.click(tid('val-bucket-day')); await page.waitForTimeout(300); await waitIdle(page);
    await page.uncheck(tid('val-day-item')); await page.waitForTimeout(500); await waitIdle(page);
    await page.evaluate(() => document.getElementById('val-title').scrollIntoView());
    await shot(page, 'average-per-hari');
    await pickView(page, 'item');

    // ---- filters / scope
    await setFilters(page, { cat: String(seed.cat.A) });
    check('M category A (P + Q): 2 rows; the selected item stays P', await page.locator(tid('val-item-row')).count() === 2 && (await page.inputValue(tid('val-item-select'))) === String(IT.P.id));
    await setFilters(page, { cat: '', wh: String(seed.wh['2']) });
    check('N warehouse W2: only item R (FIFO transfer-in layers + HPP 15.000 → Average 18.000); detail follows R', await page.locator(tid('val-item-row')).count() === 1 && (await page.inputValue(tid('val-item-select'))) === String(IT.R.id));
    await pickMethod(page, 'fifo');
    check('N W2 FIFO closing Rp 17.000 (the 2 received layers keep their cost: 20 @ 500 + 10 @ 700)', near(await raw(page, 'closing'), 17000) && (await page.locator(tid('val-layer-active-row')).count()) === 2);
    await setFilters(page, { wh: '' });

    // ---- export == screen
    await page.evaluate(() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null; }; });
    await page.click(tid('val-export'));
    const fUrl = (await page.evaluate(() => window.__opened)).pop();
    check('O export URL carries the selected method and the same filters', fUrl.includes('method=fifo') && fUrl.includes(`start_date=${R0.from}`) && fUrl.includes('q=VLZ'));
    const getXlsx = (u, name) => page.evaluate(async (url) => { const r = await fetch(url, { credentials: 'include' }); return { status: r.status, type: r.headers.get('content-type'), bytes: Array.from(new Uint8Array(await r.arrayBuffer())) }; }, u).then((wb) => { fs.writeFileSync(path.join(shotDir, name), Buffer.from(wb.bytes)); return wb; });
    const wbF = await getXlsx(fUrl, 'valuation-sample-export-fifo.xlsx');
    const zl = sh(`unzip -p ${JSON.stringify(path.join(shotDir, 'valuation-sample-export-fifo.xlsx'))} xl/workbook.xml`);
    check('O FIFO workbook (xlsx): Ringkasan FIFO, Nilai Stok per Barang, Riwayat FIFO, Layer Aktif, Layer Terpakai, Rekonsiliasi, FIFO vs Average', wbF.status === 200 && /spreadsheetml/.test(wbF.type) && ['Ringkasan FIFO', 'Nilai Stok per Barang', 'Riwayat FIFO', 'Layer Aktif', 'Layer Terpakai', 'Rekonsiliasi', 'FIFO vs Average'].every((n) => zl.includes(n)));
    const sheet1 = sh(`unzip -p ${JSON.stringify(path.join(shotDir, 'valuation-sample-export-fifo.xlsx'))} xl/worksheets/sheet1.xml`);
    check('O export metadata states "Metode" FIFO and the operational note; the Ringkasan carries the same Nilai Stok Akhir as the card', sheet1.includes('FIFO') && sheet1.includes('Metode operasional sistem') && sheet1.includes('326500'));
    await pickMethod(page, 'average');
    await page.click(tid('val-export'));
    const aUrl = (await page.evaluate(() => window.__opened)).pop();
    const wbA = await getXlsx(aUrl, 'valuation-sample-export-average.xlsx');
    const zlA = sh(`unzip -p ${JSON.stringify(path.join(shotDir, 'valuation-sample-export-average.xlsx'))} xl/workbook.xml`);
    check('O Average workbook: Ringkasan Average, Average per Barang, Riwayat Average, Per Hari, Rekonsiliasi, FIFO vs Average (metode AVERAGE in the metadata)', aUrl.includes('method=average') && ['Ringkasan Average', 'Average per Barang', 'Riwayat Average', 'Per Hari', 'Rekonsiliasi', 'FIFO vs Average'].every((n) => zlA.includes(n)) && sh(`unzip -p ${JSON.stringify(path.join(shotDir, 'valuation-sample-export-average.xlsx'))} xl/worksheets/sheet1.xml`).includes('AVERAGE'));
    check('O the Average export marks the unreconstructable item instead of a number', sh(`unzip -p ${JSON.stringify(path.join(shotDir, 'valuation-sample-export-average.xlsx'))} xl/worksheets/sheet2.xml`).includes('Average tidak dapat direkonstruksi'));
    await pickMethod(page, 'fifo');

    // ---- reconciliation CLI
    const rec = sh(`php scripts/valuation_reconcile_check.php --app-root=. --start=${R0.from} --end=${R0.to} --q=VLZ`);
    check('P read-only reconciliation CLI: every check PASSes (exit 0)', /\d+ \/ \d+ checks passed — all reconcile/.test(rec), rec.split('\n').slice(-3).join(' | '));

    // ---- permissions
    const o = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'stock2' }, seed.stock2);
    await openReport(o.page);
    await setFilters(o.page, { from: R0.from, to: R0.to, q: 'VLZ' });
    check('Q STOCK user of W2: the warehouse selector is locked; only item R; FIFO closing Rp 17.000; a forged warehouse_id of W1 gets W2 only', await o.page.locator(tid('val-wh')).isDisabled() && await o.page.locator(tid('val-item-row')).count() === 1 && near(await raw(o.page, 'closing'), 17000)
        && (await api(o.page, '/reports/inventory-valuation', { start_date: R0.from, end_date: R0.to, q: 'VLZ', warehouse_id: String(seed.wh['1']) })).items.length === 1);
    await o.context.close();

    // ---- responsive (+ iPad landscape with the method toggle visible)
    for (const [name, w, h] of [['d1366', 1366, 768], ['ipad-landscape', 1180, 820], ['ipad-portrait', 820, 1180], ['mobile', 390, 844]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        await openReport(s.page);
        await setFilters(s.page, { from: R0.from, to: R0.to, q: 'VLZ' });
        for (const m of ['fifo', 'average']) {
            await pickMethod(s.page, m);
            const k = await s.page.evaluate(() => ({ over: document.documentElement.scrollWidth - innerWidth, kpiFont: parseFloat(getComputedStyle(document.querySelector('.val-kpi-value')).fontSize), clipped: Array.from(document.querySelectorAll('.val-kpi-value')).filter((e) => e.scrollWidth > e.clientWidth + 1).length }));
            check(`R ${name} ${m}: no horizontal page overflow, KPI font ${k.kpiFont}px, no clipped KPI value`, k.over <= 0 && k.kpiFont <= 26 && k.clipped === 0, JSON.stringify(k));
        }
        const sc = await s.page.evaluate(() => { const e = document.querySelector('[data-testid="val-items-scroll"]'); const h = document.querySelector('[data-testid="val-history-scroll"]'); return { items: e.scrollWidth > e.clientWidth, hist: h ? h.scrollWidth > h.clientWidth : false, over: document.documentElement.scrollWidth - innerWidth }; });
        check(`R ${name}: the item and history tables scroll inside their own containers; the page does not overflow`, sc.items && sc.hist && sc.over <= 0, JSON.stringify(sc));
        if (name === 'ipad-landscape') { await pickMethod(s.page, 'fifo'); await s.page.evaluate(() => window.scrollTo(0, 0)); await shot(s.page, 'ipad-landscape'); }
        if (name === 'mobile') await shot(s.page, 'mobile');
        await s.context.close();
    }

    const realErrors = consoleErrors.filter((e) => !(e.startsWith('stock2 ') && /status of 403/.test(e)));
    check('S no console / page errors in any session', realErrors.length === 0, realErrors.slice(0, 4).join(' || '));
    check('T only GET requests left the browser while using the report (no write)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
    await context.close();
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
