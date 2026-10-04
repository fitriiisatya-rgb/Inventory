// Master Data "Tambah ..." + compact modals — REAL data, end to end, through the real UI.
// Real MariaDB + real PHP API + real login + real Chromium. Disposable fixture records only (tests/browser/seed_tx_v2.php).
// Checks A-O of the brief: every page loads, permitted admin sees the primary button, modal opens/closes (Batal, X, Esc),
// required + duplicate validation (modal stays open, values preserved), successful create refreshes the table without a page
// reload and is findable by search, edit still works, VIEWER/STOCK cannot create (UI hidden + API 403), no console errors,
// Master Barang detail/edit intact, Stock OUT sees a new active Bakery, Master Barang/Stock IN see a new Category/Supplier,
// and the modal fits 1536 / 1366 / iPad landscape / iPad portrait / mobile. Screenshots -> $MDM_SHOT_DIR.
//
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw MDM_SHOT_DIR=/some/dir node tests/browser/playwright_master_create.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.MDM_SHOT_DIR || __dirname;
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
async function pickUnit(page, sel, code) { const v = await page.locator(`${sel} option`).evaluateAll((os, c) => (os.find((o) => o.textContent.startsWith(c)) || {}).value, code); await page.selectOption(sel, v); }
const MODAL = '.mdm-overlay .mdm-modal';
// modal gone, then give the follow-up table refresh (a normal API call, no page reload) time to land
async function closed(page) { await page.waitForFunction(() => !document.querySelector('.mdm-overlay'), null, { timeout: 8000 }); await page.waitForTimeout(900); }
async function openMaster(page, tab) {
    await page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), tab);
    await page.waitForSelector(`#tab-${tab}.active .mdm-head`, { timeout: 10000 });
    await page.waitForFunction((t) => { const el = document.querySelector(`#tab-${t}`); return el && !/Memuat/.test(el.innerText) && el.querySelector('table'); }, tab, { timeout: 10000 });
    // a success toast (top-right, a few seconds) sits over the header button — let it go before the next click
    await page.waitForFunction(() => !document.querySelector('.toast'), null, { timeout: 10000 });
}
const searchBox = (page, tab) => page.locator(`#tab-${tab} input[type="text"]`).first();
async function search(page, tab, text) {
    const box = searchBox(page, tab);
    await box.fill(text);
    await page.waitForTimeout(700);
}
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `master-${name}.png`) });
const modalOpen = async (page) => (await page.locator(MODAL).count()) === 1;
const err = async (page, key) => (await page.locator(tid(`mdm-e-${key}`)).innerText()).trim();
const val = (page, key) => page.locator(`#mdm-f-${key}`).inputValue();
const hOver = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
async function rowActions(page, tab, text, label) {
    const row = page.locator(`#tab-${tab} tbody tr`, { hasText: text }).first();
    await row.locator('.master-actions-menu button').first().click();
    await row.locator('.master-action-item', { hasText: label }).first().click();
}

const PAGES = [
    { tab: 'master-item', title: 'Master Barang', btn: '+ Tambah Barang', modal: 'mdm-modal-item' },
    { tab: 'master-warehouse', title: 'Master Gudang', btn: '+ Tambah Gudang', modal: 'mdm-modal-warehouse' },
    { tab: 'master-division', title: 'Master Divisi', btn: '+ Tambah Divisi', modal: 'mdm-modal-division' },
    { tab: 'master-vendor', title: 'Vendor / Supplier', btn: '+ Tambah Supplier', modal: 'mdm-modal-supplier' },
    { tab: 'master-bakery', title: 'Bakery Tujuan', btn: '+ Tambah Bakery', modal: 'mdm-modal-bakery' },
    { tab: 'master-category', title: 'Kategori', btn: '+ Tambah Kategori', modal: 'mdm-modal-category' },
];

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1440, height: 900 }, __name: 'admin' }, seed.admin);
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) navCount += 1; });
    var navCount = 0;
    const navBase = navCount;

    // ===== A-E: load, button, open, close (Batal / X / Esc)
    for (const p of PAGES) {
        await openMaster(page, p.tab);
        const head = (await page.locator(`#tab-${p.tab} .mdm-title`).innerText()).trim();
        check(`A [${p.tab}] page loads with header "${p.title}", a description and a table`, head === p.title && (await page.locator(`#tab-${p.tab} .mdm-desc`).innerText()).length > 10 && await page.locator(`#tab-${p.tab} table`).count() >= 1, head);
        const btn = page.locator(`#tab-${p.tab} ${tid('mdm-add')}`);
        check(`B [${p.tab}] primary button "${p.btn}" visible, upper right`, await btn.count() === 1 && (await btn.innerText()).trim() === p.btn && await btn.evaluate((b) => { const r = b.getBoundingClientRect(); const h = b.closest('.mdm-head').getBoundingClientRect(); return r.right > h.right - 40 && r.top < h.top + 20; }));
        check(`B [${p.tab}] no permanent inline create form above the table (no <form>/ #…-form-card outside the modal)`, await page.locator(`#tab-${p.tab} form, #tab-${p.tab} [id$="-form-card"]`).count() === 0);
        await btn.click();
        await page.waitForSelector(MODAL);
        check(`C [${p.tab}] clicking opens the right modal`, await page.locator(tid(p.modal)).count() === 1);
        await page.click(tid('mdm-cancel'));
        check(`D [${p.tab}] Batal closes the modal`, !(await modalOpen(page)));
        await btn.click(); await page.waitForSelector(MODAL);
        await page.click(tid('mdm-close'));
        check(`E [${p.tab}] X closes the modal`, !(await modalOpen(page)));
        await btn.click(); await page.waitForSelector(MODAL);
        await page.keyboard.press('Escape');
        check(`E [${p.tab}] Esc closes the modal`, !(await modalOpen(page)));
    }

    // ===== F: required validation (modal stays open, errors under fields)
    for (const [tab, keys] of [['master-item', ['sku', 'name', 'category_id', 'base_unit_id']], ['master-warehouse', ['code', 'name']], ['master-division', ['code', 'name']], ['master-vendor', ['code', 'name']], ['master-bakery', ['code', 'name']], ['master-category', ['code', 'name']]]) {
        await openMaster(page, tab);
        await page.click(`#tab-${tab} ${tid('mdm-add')}`);
        await page.waitForSelector(MODAL);
        await page.click(tid('mdm-save'));
        const msgs = [];
        for (const k of keys) msgs.push(await err(page, k));
        check(`F [${tab}] empty Simpan: modal stays open and every required field shows its error`, await modalOpen(page) && msgs.every((m) => /wajib diisi/.test(m)), msgs.join(' | '));
        await page.keyboard.press('Escape');
    }

    // ===== H/I/G: create every record, table refreshes without a page reload, search finds it, duplicate rejected with values preserved
    await page.evaluate(() => { window.__mdmMarker = 'same-document'; });
    const created = {};
    // Kategori
    await openMaster(page, 'master-category');
    await page.click(`#tab-master-category ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'KTG-NEW'); await page.fill('#mdm-f-name', 'Cake Baru');
    await page.click(tid('mdm-save'));
    await closed(page);
    check('H [category] saved: modal closed, success toast shown', /Kategori berhasil ditambahkan/.test(await page.locator('.toast-container').innerText()));
    check('H [category] the new record is visible in the refreshed table (no reload)', await page.locator('#tab-master-category tbody tr', { hasText: 'Cake Baru' }).count() === 1);
    await search(page, 'master-category', 'KTG-NEW');
    check('I [category] searching by code finds it', await page.locator('#tab-master-category tbody tr', { hasText: 'KTG-NEW' }).count() === 1);
    await page.click(`#tab-master-category ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'KTG-OTHER'); await page.fill('#mdm-f-name', 'cake baru');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-name')}:not([style*="none"])`, { timeout: 5000 });
    check('G [category] duplicate NAME rejected: error under Nama, modal stays open, entered values preserved', await modalOpen(page) && /sudah digunakan/.test(await err(page, 'name')) && await val(page, 'code') === 'KTG-OTHER' && await val(page, 'name') === 'cake baru', await err(page, 'name'));
    await page.fill('#mdm-f-code', 'KTG-NEW'); await page.fill('#mdm-f-name', 'Nama Lain');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-code')}:not([style*="none"])`, { timeout: 5000 });
    check('G [category] duplicate CODE rejected under Kode, values preserved', /sudah digunakan/.test(await err(page, 'code')) && await val(page, 'name') === 'Nama Lain', await err(page, 'code'));
    await page.keyboard.press('Escape');

    // Supplier
    await openMaster(page, 'master-vendor');
    await page.click(`#tab-master-vendor ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'SUP-NEW'); await page.fill('#mdm-f-name', 'PT Baru Abadi'); await page.fill('#mdm-f-contact_name', 'Rudi'); await page.fill('#mdm-f-phone', '0812-3333-4444');
    await page.fill('#mdm-f-email', 'salah-format');
    await page.click(tid('mdm-save'));
    check('F [supplier] invalid e-mail format is flagged under Email, modal stays open', /Format email/.test(await err(page, 'email')) && await modalOpen(page));
    await page.fill('#mdm-f-email', 'rudi@baru.co.id'); await page.fill('#mdm-f-address', 'Jl. Baru 1');
    await page.click(tid('mdm-save'));
    await closed(page);
    check('H [supplier] saved + visible in the refreshed table', await page.locator('#tab-master-vendor tbody tr', { hasText: 'PT Baru Abadi' }).count() === 1);
    await search(page, 'master-vendor', 'SUP-NEW');
    check('I [supplier] searching by code finds it', await page.locator('#tab-master-vendor tbody tr', { hasText: 'SUP-NEW' }).count() === 1);
    await page.click(`#tab-master-vendor ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'sup-new'); await page.fill('#mdm-f-name', 'Duplikat');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-code')}:not([style*="none"])`, { timeout: 5000 });
    check('G [supplier] duplicate code rejected under Kode (case-insensitive), values preserved', /sudah digunakan/.test(await err(page, 'code')) && await val(page, 'name') === 'Duplikat' && await modalOpen(page));
    await page.keyboard.press('Escape');

    // Bakery (the exact example of the brief)
    await openMaster(page, 'master-bakery');
    await page.click(`#tab-master-bakery ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'SUDIRMAN'); await page.fill('#mdm-f-name', 'Bakery Sudirman');
    await page.fill('#mdm-f-address', 'Jl. Jend Sudirman No. 36 Sukabumi.'); await page.fill('#mdm-f-city_area', 'Sukabumi');
    check('C [bakery] modal offers Alamat, Kota/Area, PIC, Telepon, Route/Cluster, Catatan and an Aktif switch (default on)', await page.locator('#mdm-f-pic_name, #mdm-f-phone, #mdm-f-route_cluster, #mdm-f-notes').count() === 4 && await page.locator('#mdm-f-is_active').isChecked());
    await shot(page, 'bakery-modal-1440');
    await page.click(tid('mdm-save'));
    await closed(page);
    check('H [bakery] saved + visible', await page.locator('#tab-master-bakery tbody tr', { hasText: 'Bakery Sudirman' }).count() === 1);
    const bk = sql("SELECT CONCAT(code,'|',name,'|',address,'|',city_area,'|',is_active) FROM bakery_destinations WHERE code='SUDIRMAN'");
    check('H [bakery] stored exactly as typed (nothing hard-coded)', bk === 'SUDIRMAN|Bakery Sudirman|Jl. Jend Sudirman No. 36 Sukabumi.|Sukabumi|1', bk);
    await search(page, 'master-bakery', 'SUDIRMAN');
    check('I [bakery] searching by code finds it', await page.locator('#tab-master-bakery tbody tr', { hasText: 'SUDIRMAN' }).count() === 1);
    await page.click(`#tab-master-bakery ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'SUDIRMAN'); await page.fill('#mdm-f-name', 'Dup');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-code')}:not([style*="none"])`, { timeout: 5000 });
    check('G [bakery] duplicate code rejected under Kode, values preserved', /sudah digunakan/.test(await err(page, 'code')) && await val(page, 'name') === 'Dup');
    await page.keyboard.press('Escape');
    // inactive bakery must NOT be offered by Stock OUT
    await page.click(`#tab-master-bakery ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'BK-OFF'); await page.fill('#mdm-f-name', 'Bakery Nonaktif'); await page.locator('#mdm-f-is_active').uncheck();
    await page.click(tid('mdm-save')); await closed(page);
    await search(page, 'master-bakery', 'Bakery Nonaktif');
    check('H [bakery] created with the status switch OFF -> shows Nonaktif', /Nonaktif/.test(await page.locator('#tab-master-bakery tbody tr', { hasText: 'Bakery Nonaktif' }).innerText()));

    // Gudang
    await openMaster(page, 'master-warehouse');
    await page.click(`#tab-master-warehouse ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    check('C [warehouse] type select offers exactly MAIN / TRANSIT (real schema), status on by default', (await page.locator('#mdm-f-warehouse_type option').allTextContents()).length === 2 && await page.locator('#mdm-f-is_active').isChecked());
    await page.fill('#mdm-f-code', 'GD-NEW'); await page.fill('#mdm-f-name', 'Gudang Baru');
    await shot(page, 'gudang-modal-1440');
    await page.click(tid('mdm-save')); await closed(page);
    check('H [warehouse] saved + visible', await page.locator('#tab-master-warehouse tbody tr', { hasText: 'Gudang Baru' }).count() === 1);
    await search(page, 'master-warehouse', 'GD-NEW');
    check('I [warehouse] searching by code finds it', await page.locator('#tab-master-warehouse tbody tr', { hasText: 'GD-NEW' }).count() === 1);
    check('H [warehouse] activation_locked stays 0; new warehouse is in the shared cache (Master.warehouses)', sql("SELECT activation_locked FROM warehouses WHERE code='GD-NEW'") === '0' && await page.evaluate(() => Master.warehouses().some((w) => w.code === 'GD-NEW')));
    await page.click(`#tab-master-warehouse ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'gd-new'); await page.fill('#mdm-f-name', 'Dup');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-code')}:not([style*="none"])`, { timeout: 5000 });
    check('G [warehouse] duplicate code rejected under Kode, values preserved', /sudah digunakan/.test(await err(page, 'code')) && await val(page, 'name') === 'Dup');
    await page.keyboard.press('Escape');

    // Divisi
    await openMaster(page, 'master-division');
    await page.click(`#tab-master-division ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    check('C [division] modal shows only Kode, Nama, Status (real schema)', await page.locator(`${MODAL} .mdm-field`).count() === 3);
    await page.fill('#mdm-f-code', 'DIV-NEW'); await page.fill('#mdm-f-name', 'Divisi Baru');
    await page.click(tid('mdm-save')); await closed(page);
    check('H [division] saved + visible', await page.locator('#tab-master-division tbody tr', { hasText: 'Divisi Baru' }).count() === 1);
    await search(page, 'master-division', 'Divisi Baru');
    check('I [division] searching by name finds it (this page searches names)', await page.locator('#tab-master-division tbody tr', { hasText: 'DIV-NEW' }).count() === 1);
    await page.click(`#tab-master-division ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-code', 'DIV-NEW'); await page.fill('#mdm-f-name', 'Dup');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-code')}:not([style*="none"])`, { timeout: 5000 });
    check('G [division] duplicate code rejected under Kode, values preserved', /sudah digunakan/.test(await err(page, 'code')) && await val(page, 'name') === 'Dup');
    await page.keyboard.press('Escape');

    // Barang — also O: new category + new supplier are offered; purchase conversion behaviour
    await openMaster(page, 'master-item');
    await page.click(`#tab-master-item ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    const catOpts = await page.locator('#mdm-f-category_id option').allTextContents();
    const supOpts = await page.locator('#mdm-f-default_supplier_id option').allTextContents();
    check('O Master Barang create offers the NEW category and the NEW supplier (shared cache refreshed), not the nonexistent ones', catOpts.includes('Cake Baru') && supOpts.includes('PT Baru Abadi') && !catOpts.includes('Kategori Off'), `${catOpts.join('|')} // ${supOpts.join('|')}`);
    check('C [item] Konversi is hidden until a Satuan Beli is chosen; price label follows the unit', !(await page.locator('[data-field="purchase_conversion"]').isVisible()) && /Harga Beli \(Rp\)/.test(await page.locator('[data-field="price"] label').innerText()));
    await page.fill('#mdm-f-sku', 'MDB-001'); await page.fill('#mdm-f-name', 'Tepung Baru Test');
    await page.selectOption('#mdm-f-category_id', { label: 'Cake Baru' });
    await page.selectOption('#mdm-f-default_supplier_id', { label: 'PT Baru Abadi' });
    await pickUnit(page, '#mdm-f-base_unit_id', 'KG');
    await pickUnit(page, '#mdm-f-purchase_unit_id', 'KARTON');
    check('C [item] choosing a Satuan Beli reveals Konversi and relabels the price "Harga Beli (Rp / KARTON)"', await page.locator('[data-field="purchase_conversion"]').isVisible() && /KARTON/.test(await page.locator('[data-field="price"] label').innerText()));
    await page.click(tid('mdm-save'));
    check('F [item] Satuan Beli without Konversi is flagged under Konversi, modal stays open', /Konversi wajib/.test(await err(page, 'purchase_conversion')) && await modalOpen(page));
    await page.fill('#mdm-f-purchase_conversion', '10'); await page.fill('#mdm-f-price', '250.000');
    await shot(page, 'barang-modal-1440');
    await page.click(tid('mdm-save')); await closed(page);
    check('H [item] saved + toast', /Barang berhasil ditambahkan/.test(await page.locator('.toast-container').innerText()));
    await search(page, 'master-item', 'MDB-001');
    check('I [item] searching by SKU finds it in the refreshed table (category + supplier columns filled)', await page.locator('#tab-master-item tbody tr', { hasText: 'MDB-001' }).count() === 1 && /Cake Baru/.test(await page.locator('#tab-master-item tbody tr', { hasText: 'MDB-001' }).innerText()) && /PT Baru Abadi/.test(await page.locator('#tab-master-item tbody tr', { hasText: 'MDB-001' }).innerText()));
    const iid = sql("SELECT id FROM items WHERE sku='MDB-001'");
    check('H [item] stored: base KG, KARTON x10 purchase default, Harga Beli 250.000/karton -> 25.000 per kg in item_price_history', sql(`SELECT COUNT(*) FROM item_unit_conversions WHERE item_id=${iid}`) === '2' && sql(`SELECT conversion_to_base FROM item_unit_conversions WHERE item_id=${iid} AND is_purchase_default=1`).startsWith('10') && sql(`SELECT CONCAT(ROUND(price_per_unit),'/',ROUND(unit_cost_base)) FROM item_price_history WHERE item_id=${iid}`) === '250000/25000', sql(`SELECT CONCAT(price_per_unit,'/',unit_cost_base) FROM item_price_history WHERE item_id=${iid}`));
    await page.click(`#tab-master-item ${tid('mdm-add')}`); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-sku', 'mdb-001'); await page.fill('#mdm-f-name', 'Duplikat Barang');
    await page.selectOption('#mdm-f-category_id', { label: 'Cake Baru' }); await pickUnit(page, '#mdm-f-base_unit_id', 'KG');
    await page.click(tid('mdm-save'));
    await page.waitForSelector(`${tid('mdm-e-sku')}:not([style*="none"])`, { timeout: 5000 });
    check('G [item] duplicate SKU rejected under SKU / Kode, all entered values preserved', /sudah digunakan/.test(await err(page, 'sku')) && await val(page, 'name') === 'Duplikat Barang' && await modalOpen(page));
    await page.keyboard.press('Escape');

    // ===== N: Stock OUT sees the new ACTIVE bakery (and not the inactive one); Stock IN sees the new supplier
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="transaksi"]').click());
    await page.waitForSelector(`#tab-transaksi.active ${tid('tx-root')}`);
    await page.click(tid('tx-tab-out')); await page.waitForSelector(tid('out-header'));
    const bkOpts = await page.locator(tid('out-bakery') + ' option').allTextContents();
    check('N Stock OUT V2 offers the newly created active "Bakery Sudirman" and hides the inactive one', bkOpts.includes('Bakery Sudirman') && !bkOpts.includes('Bakery Nonaktif'), bkOpts.join('|'));
    await page.selectOption(tid('out-bakery'), { label: 'Bakery Sudirman' });
    const bkId = sql("SELECT id FROM bakery_destinations WHERE code='SUDIRMAN'");
    check('N …and it can be selected as Bakery Tujuan', await page.locator(tid('out-bakery')).inputValue() === bkId);
    const q = await page.evaluate(async (id) => {
        const r = await fetch('/api/stock-out/quote', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': Auth.user().csrf_token }, body: JSON.stringify({ warehouse_id: Master.warehouses()[0].id, bakery_destination_id: Number(id), transaction_date: new Date().toISOString().slice(0, 10), lines: [] }) });
        return r.json();
    }, bkId);
    check('N the Stock OUT V2 backend resolves the new bakery (name + address) with no extra step', q.success === true && q.data.bakery && q.data.bakery.name === 'Bakery Sudirman' && /Sudirman No\. 36/.test(q.data.bakery.address || ''), JSON.stringify(q).slice(0, 200));
    await page.click(tid('tx-tab-in')); await page.waitForSelector(tid('in-header'));
    check('O Stock IN supplier list contains the new supplier', (await page.locator(tid('in-supplier') + ' option').allTextContents()).includes('PT Baru Abadi'));

    // ===== J / M: edit still works; Master Barang detail + edit
    await openMaster(page, 'master-vendor');
    await search(page, 'master-vendor', 'SUP-NEW');
    await rowActions(page, 'master-vendor', 'SUP-NEW', 'Edit');
    await page.waitForSelector(MODAL);
    check('J [supplier] Edit opens the same compact modal pre-filled', await val(page, 'code') === 'SUP-NEW' && await val(page, 'name') === 'PT Baru Abadi' && await val(page, 'email') === 'rudi@baru.co.id');
    await page.fill('#mdm-f-name', 'PT Baru Abadi Jaya'); await page.click(tid('mdm-save'));
    await closed(page);
    check('J [supplier] edit saved (DB + table)', sql("SELECT name FROM suppliers WHERE code='SUP-NEW'") === 'PT Baru Abadi Jaya' && await page.locator('#tab-master-vendor tbody tr', { hasText: 'PT Baru Abadi Jaya' }).count() === 1);
    await openMaster(page, 'master-bakery'); await search(page, 'master-bakery', 'SUDIRMAN');
    await rowActions(page, 'master-bakery', 'SUDIRMAN', 'Edit'); await page.waitForSelector(MODAL);
    await page.fill('#mdm-f-pic_name', 'Pak Andi'); await page.click(tid('mdm-save'));
    await closed(page);
    check('J [bakery] edit saved', sql("SELECT pic_name FROM bakery_destinations WHERE code='SUDIRMAN'") === 'Pak Andi');
    await openMaster(page, 'master-category'); await search(page, 'master-category', 'KTG-NEW');
    await rowActions(page, 'master-category', 'KTG-NEW', 'Edit'); await page.waitForSelector(MODAL);
    check('J [category] code is read-only while editing', await page.locator('#mdm-f-code').getAttribute('readonly') !== null);
    await page.fill('#mdm-f-name', 'Cake Baru 2'); await page.click(tid('mdm-save'));
    await closed(page);
    check('J [category] edit saved', sql("SELECT name FROM categories WHERE code='KTG-NEW'") === 'Cake Baru 2');
    await openMaster(page, 'master-warehouse'); await search(page, 'master-warehouse', 'GD-NEW');
    await rowActions(page, 'master-warehouse', 'GD-NEW', 'Edit'); await page.waitForSelector('.modal.open .modal-content');
    await page.locator('.modal.open .modal-content input[type="text"]').first().fill('Gudang Baru 2');
    await page.locator('.modal.open .modal-content button', { hasText: 'Simpan' }).click();
    await page.waitForFunction(() => !document.querySelector('.modal.open'), null, { timeout: 8000 });
    check('J [warehouse] existing edit flow still works', sql("SELECT name FROM warehouses WHERE code='GD-NEW'") === 'Gudang Baru 2');
    await openMaster(page, 'master-division'); await search(page, 'master-division', 'Divisi Baru');
    await rowActions(page, 'master-division', 'DIV-NEW', 'Edit'); await page.waitForSelector('.modal.open .modal-content');
    await page.locator('.modal.open .modal-content input[type="text"]').first().fill('Divisi Baru 2');
    await page.locator('.modal.open .modal-content button', { hasText: 'Simpan' }).click();
    await page.waitForFunction(() => !document.querySelector('.modal.open'), null, { timeout: 8000 });
    check('J [division] existing edit flow still works', sql("SELECT name FROM divisions WHERE code='DIV-NEW'") === 'Divisi Baru 2');
    await openMaster(page, 'master-item'); await search(page, 'master-item', 'MDB-001');
    await page.locator('#tab-master-item tbody tr', { hasText: 'MDB-001' }).first().locator('td').nth(1).click();
    await page.waitForSelector('.drawer.open');
    check('M [item] row click still opens the detail drawer with unit conversion + price section', /Tepung Baru Test/.test(await page.locator('.drawer.open').innerText()) && /Satuan & Konversi/i.test(await page.locator('.drawer.open').innerText()));
    await page.waitForFunction(() => /Internal Unit Cost/i.test(document.querySelector('.drawer.open').innerText) && /250\.000|25\.000/.test(document.querySelector('.drawer.open').innerText), null, { timeout: 8000 });
    check('M [item] the drawer shows the Harga Beli created by "Tambah Barang" (25.000/kg and 250.000/karton — same price system)', true);
    await page.locator('.drawer.open button', { hasText: 'Edit Barang' }).first().evaluate((b) => b.click());
    await page.waitForSelector('.modal.open .modal-content');
    await page.locator('.modal.open .modal-content input[type="text"]').first().fill('Tepung Baru Test 2');
    await page.locator('.modal.open .modal-content button', { hasText: 'Simpan' }).click();
    await page.waitForFunction(() => !document.querySelector('.modal.open'), null, { timeout: 8000 });
    check('M [item] Edit Barang still saves', sql("SELECT name FROM items WHERE sku='MDB-001'") === 'Tepung Baru Test 2');
    await page.waitForTimeout(1200);
    await page.evaluate(() => Drawer.close());
    await page.waitForFunction(() => !document.querySelector('.drawer.open'), null, { timeout: 5000 });
    // whole run: no full page reload happened
    check('H no full-browser reload happened during all creates/edits (single document, same page object)', await page.evaluate(() => window.__mdmMarker === 'same-document' && typeof Master !== 'undefined'));

    // ===== screenshots at the mockup size (list + modal for 5 menus)
    for (const [tab, name, nameShot] of [['master-item', 'barang', 'Master Barang'], ['master-vendor', 'supplier', 'Supplier'], ['master-bakery', 'bakery', 'Bakery'], ['master-warehouse', 'gudang', 'Gudang'], ['master-category', 'kategori', 'Kategori']]) {
        await openMaster(page, tab);
        await page.waitForTimeout(300);
        await shot(page, `${name}-list-1440`);
        await page.click(`#tab-${tab} ${tid('mdm-add')}`); await page.waitForSelector(MODAL); await page.waitForTimeout(250);
        await shot(page, `${name}-modal-1440`);
        await page.keyboard.press('Escape');
    }

    // ===== K: unauthorized roles
    await context.close();
    for (const [who, user] of [['VIEWER', seed.viewer], ['STOCK', seed.stockA]]) {
        const s = await newSession(browser, { viewport: { width: 1440, height: 900 }, __name: who }, user);
        const visibleTabs = await s.page.evaluate(() => ['master-item','master-warehouse','master-division','master-vendor','master-bakery','master-category'].filter((t) => { const a = document.querySelector(`.sidebar-link[data-tab="${t}"]`); return a && a.offsetParent !== null; }));
        let loaded = 0; let noButton = 0;
        for (const p of PAGES) {
            if (!visibleTabs.includes(p.tab)) continue;
            await s.page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), p.tab);
            await s.page.waitForSelector(`#tab-${p.tab}.active .mdm-head`, { timeout: 10000 });
            loaded += 1;
            if (await s.page.locator(`#tab-${p.tab} ${tid('mdm-add')}`).count() === 0) noButton += 1;
        }
        check(`K ${who}: every Master page it can open hides the "+ Tambah" button (${noButton}/${loaded})`, loaded === noButton);
        const posts = await s.page.evaluate(async () => {
            const csrf = Auth.user().csrf_token;
            const out = {};
            for (const [p, b] of [['/items', { sku: 'NOPE', name: 'x', category_id: 1, base_unit_id: 1 }], ['/warehouses', { code: 'NOPE', name: 'x' }], ['/divisions', { code: 'NOPE', name: 'x' }], ['/suppliers', { code: 'NOPE', name: 'x' }], ['/bakery-destinations', { code: 'NOPE', name: 'x' }], ['/categories', { code: 'NOPE', name: 'x' }]]) {
                const r = await fetch(`/api${p}`, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b) });
                out[p] = r.status;
            }
            return out;
        });
        check(`K ${who}: a direct POST to every create endpoint is rejected by the backend (403)`, Object.values(posts).every((v) => v === 403), JSON.stringify(posts));
        await s.context.close();
    }

    // ===== responsive: modals fit 1536 / 1366 / iPad landscape / iPad portrait / mobile
    for (const [name, w, h] of [['d1536', 1536, 864], ['d1366', 1366, 768], ['ipadL', 1180, 820], ['ipadP', 820, 1180], ['m390', 390, 844]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        for (const [tab, label] of [['master-item', 'item'], ['master-bakery', 'bakery'], ['master-vendor', 'supplier']]) {
            await s.page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), tab);
            await s.page.waitForSelector(`#tab-${tab}.active .mdm-head`, { timeout: 10000 });
            await s.page.waitForTimeout(400);
            const over0 = await hOver(s.page);
            await s.page.click(`#tab-${tab} ${tid('mdm-add')}`); await s.page.waitForSelector(MODAL); await s.page.waitForTimeout(250);
            const m = await s.page.evaluate(() => {
                const r = document.querySelector('.mdm-overlay .mdm-modal').getBoundingClientRect();
                const save = document.querySelector('[data-testid="mdm-save"]').getBoundingClientRect();
                const cancel = document.querySelector('[data-testid="mdm-cancel"]').getBoundingClientRect();
                const x = document.querySelector('[data-testid="mdm-close"]').getBoundingClientRect();
                const body = document.querySelector('.mdm-body');
                return { l: r.left, r: r.right, t: r.top, b: r.bottom, vw: innerWidth, vh: innerHeight, save: [save.left, save.right, save.top, save.bottom], cancel: [cancel.top, cancel.bottom], x: [x.top, x.bottom], scrolls: body.scrollHeight > body.clientHeight + 1, over: document.documentElement.scrollWidth - innerWidth };
            });
            const inView = (a, b, lo, hi) => a >= lo - 0.5 && b <= hi + 0.5;
            check(`R ${name} ${label}: modal fully inside the viewport, Simpan/Batal/X reachable, no page overflow${m.scrolls ? ' (body scrolls internally)' : ''}`, inView(m.l, m.r, 0, m.vw) && inView(m.t, m.b, 0, m.vh) && inView(m.save[2], m.save[3], 0, m.vh) && inView(m.cancel[0], m.cancel[1], 0, m.vh) && inView(m.x[0], m.x[1], 0, m.vh) && m.over <= 0 && over0 <= 0, JSON.stringify(m));
            if (label === 'item') {
                await s.page.fill('#mdm-f-sku', 'X');
                const long = await s.page.evaluate(() => { const b = document.querySelector('.mdm-body'); b.scrollTop = b.scrollHeight; return b.scrollTop; });
                const saveStill = await s.page.evaluate(() => { const r = document.querySelector('[data-testid="mdm-save"]').getBoundingClientRect(); return r.bottom <= innerHeight + 0.5 && r.top >= 0; });
                check(`R ${name} item: after scrolling the long form to its end the footer actions stay pinned and reachable`, saveStill, String(long));
            }
            if (['d1536', 'ipadL', 'ipadP', 'm390'].includes(name) && label !== 'supplier') await s.page.screenshot({ path: path.join(shotDir, `master-${label}-modal-${name}.png`) });
            await s.page.keyboard.press('Escape');
        }
        await s.context.close();
    }
    // Chrome logs every 4xx fetch as a console error; the duplicate / forbidden attempts above are deliberate 422 / 403s — anything else is a real error
    const unexpected = consoleErrors.filter((m) => !/status of (422|403)/.test(m));
    check('L no console / page errors in any session (only the deliberate 422 duplicate / 403 unauthorized responses are logged by the browser)', unexpected.length === 0, unexpected.slice(0, 5).join(' || '));
    check('only expected API traffic: every non-GET request was a master create/update (plus one read-only quote)', requests.filter((r) => r.method !== 'GET').every((r) => /^\/(items|warehouses|divisions|suppliers|bakery-destinations|categories)(\/\d+)?$|^\/stock-out\/quote$/.test(r.path)), requests.filter((r) => r.method !== 'GET' && !/^\/(items|warehouses|divisions|suppliers|bakery-destinations|categories)(\/\d+)?$|^\/stock-out\/quote$/.test(r.path)).map((r) => r.method + r.path).join(','));
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
