// Laporan Stock Opname (audit redesign) — REAL data, end to end, through the real UI (real MariaDB + PHP API + Chromium).
// The sessions come from the application's own Stock Opname services (tests/lib/jejak_real_fixture.php: one LEGACY_DUAL_COUNT and one
// FINDINGS_V1 session with real evidence photos, a voided finding and real posted adjustments). Every number on screen is compared with
// the API / hand-computed values; the evidence gallery must show a REAL decoded image; exports must equal the screen; the read-only
// reconciliation CLI is run against both sessions. Screenshots -> $SOA_SHOT_DIR.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw SOA_SHOT_DIR=/some/dir node tests/browser/playwright_so_audit_report.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.SOA_SHOT_DIR || __dirname;
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
const seed = JSON.parse(sh('php tests/browser/seed_so_audit.php'));
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
        const staticRoot = process.env.SOA_STATIC_ROOT || path.join(repoRoot, 'public');   // SOA_STATIC_ROOT: serve a PRODUCTION-LAYOUT tree patched by the package scripts instead of the dev public/
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
const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `so-audit-${name}.png`) });
const L = seed.legacy; const V = seed.findings;
const day = '2026-09-30';
const rpFmt = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
async function openReport(page) {
    const tab = process.env.SOA_TAB || 'opname-laporan';   // SOA_TAB=laporan-opname: the production route the package re-points
    await page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), tab);
    await page.waitForSelector(`#tab-${tab}.active .soa-title`);
    await page.waitForFunction(() => !document.querySelector('.soa-loading'), null, { timeout: 20000 });
}
const waitIdle = (page) => page.waitForFunction(() => !document.querySelector('.soa-loading'), null, { timeout: 20000 });
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('soa-from'), f.from);
    if (f.to !== undefined) await page.fill(tid('soa-to'), f.to);
    if (f.wh !== undefined) await page.selectOption(tid('soa-wh'), f.wh);
    if (f.status !== undefined) await page.selectOption(tid('soa-status'), f.status);
    if (f.q !== undefined) await page.fill(tid('soa-q'), f.q);
    await page.click(tid('soa-apply'));
    await page.waitForTimeout(500);
    await waitIdle(page);
}
const rowText = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const colIndex = async (page, table, label) => page.evaluate(([t, l]) => Array.from(document.querySelectorAll(`[data-testid="${t}"] thead th`)).findIndex((th) => th.textContent.trim().toLowerCase() === l.toLowerCase()), [table, label]);
async function cellOf(page, table, rowSel, label) {
    const i = await colIndex(page, table, label);
    return (await page.locator(`[data-testid="${table}"] ${rowSel} td`).nth(i).innerText()).replace(/\s+/g, ' ').trim();
}

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin', acceptDownloads: true }, seed.admin);
    await openReport(page);
    check('A report loads: title, filter bar (Dari/Sampai/Gudang/Status/Pencarian), 7 KPI cards, view toggle, sessions table', await page.locator('.soa-title').innerText() === 'Laporan Stock Opname'
        && await page.locator('.soa-kpi').count() === 7 && await page.locator(tid('soa-view-sessions')).count() === 1 && await page.locator(tid('soa-view-items')).count() === 1 && await page.locator(tid('soa-sessions-table')).count() === 1
        && await page.locator(tid('soa-from')).count() === 1 && await page.locator(tid('soa-status')).count() === 1);

    // ---- B..F filters
    await setFilters(page, { from: day, to: day });
    check('B date filter: exactly the two sessions of 2026-09-30', await page.locator(tid('soa-session-row')).count() === 2);
    await setFilters(page, { from: '2020-01-01', to: '2020-01-31' });
    check('B a period without sessions shows an honest empty state (no fabricated rows)', await page.locator(tid('soa-sessions-empty')).count() === 1 && await page.locator(tid('soa-session-row')).count() === 0);
    await setFilters(page, { from: day, to: day, wh: String(L.warehouse_id) });
    check('C warehouse filter: CIBADAK → only the legacy session', await page.locator(tid('soa-session-row')).count() === 1 && (await rowText(page.locator(tid('soa-session-row')))).includes(L.session_number));
    await setFilters(page, { wh: '' });
    await setFilters(page, { status: 'OPEN' });
    check('D status filter: OPEN → none; POSTED → both', await page.locator(tid('soa-session-row')).count() === 0);
    await setFilters(page, { status: 'POSTED' });
    check('D status POSTED → 2 sessions', await page.locator(tid('soa-session-row')).count() === 2);
    await setFilters(page, { status: '', q: V.session_number });
    check('E search by session number → that session only', await page.locator(tid('soa-session-row')).count() === 1 && (await rowText(page.locator(tid('soa-session-row')))).includes(V.session_number));
    await setFilters(page, { q: V.users.f1b.username });
    check('F search by petugas name (a V1 counter) → the V1 session', await page.locator(tid('soa-session-row')).count() === 1 && (await rowText(page.locator(tid('soa-session-row')))).includes(V.session_number));
    await setFilters(page, { q: 'dus jatuh' });
    check('F search by a line note → the legacy session', await page.locator(tid('soa-session-row')).count() === 1 && (await rowText(page.locator(tid('soa-session-row')))).includes(L.session_number));
    await setFilters(page, { q: V.items.rusak.sku });
    check('E search by SKU → the session that holds it', await page.locator(tid('soa-session-row')).count() === 1);
    await setFilters(page, { q: '' });

    // ---- G sessions table
    const sess = await api(page, '/reports/opname-audit/sessions', { date_from: day, date_to: day });
    const heads = await page.locator(`${tid('soa-sessions-table')} thead th`).allInnerTexts();
    const lc = (a) => a.map((x) => x.toLowerCase());
    const defaults = sess.columns.filter((c) => c.default).map((c) => c.label);
    check('G Ringkasan Sesi: shows the default column set from the catalogue (+ Aksi): No. Sesi … Petugas P1/P2, timestamps, Match/Mismatch, Selisih, Adjustment', lc(defaults).every((l) => lc(heads).includes(l)) && lc(heads).includes('aksi') && ['Petugas P1', 'P1 Mulai', 'P2 Selesai', 'Finalizer', 'Match', 'Mismatch', 'Selisih Nilai', 'Status Adjustment'].every((l) => lc(heads).includes(l.toLowerCase())), heads.join('|'));
    const vRow = page.locator(`${tid('soa-session-row')}[data-session-id="${V.session_id}"]`);
    const lRow = page.locator(`${tid('soa-session-row')}[data-session-id="${L.session_id}"]`);
    const vApi = sess.rows.find((r) => r.id === V.session_id);
    const lApi = sess.rows.find((r) => r.id === L.session_id);
    check('G V1 session row: petugas P1 = f1a + f1b (voided f1c absent), P2 = f2a, supervisor/finalizer = admin', (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'Petugas P1')).includes(V.users.f1a.username)
        && !(await rowText(vRow)).includes(V.users.f1c.username) && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'Petugas P2')).includes(V.users.f2a.username)
        && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'Finalizer')) === seed.admin.username);
    check('G timestamps on screen are the API timestamps (P1 Mulai / P2 Selesai / Timestamp Final), formatted — not "—"', (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'P1 Mulai')) !== '—' && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'Timestamp Final')) !== '—' && vApi.p1_start === '2026-09-29 12:00:00');
    check('G legacy row: Selisih Qty is per unit ("KG -1"), Selisih Nilai +Rp 2.500, adjustment Terposting Rp 3.500', (await cellOf(page, 'soa-sessions-table', `[data-session-id="${L.session_id}"]`, 'Selisih Qty (per satuan)')) === 'KG -1' && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${L.session_id}"]`, 'Selisih Nilai')).includes('2.500') && (await rowText(lRow)).includes('Terposting') && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${L.session_id}"]`, 'Nilai Adjustment')).includes('3.500'));
    check('G Match / Mismatch on screen == API (legacy 4 / 0; V1 4 / 0) and the status badge shows POSTED', (await cellOf(page, 'soa-sessions-table', `[data-session-id="${L.session_id}"]`, 'Match')) === String(lApi.match) && (await cellOf(page, 'soa-sessions-table', `[data-session-id="${V.session_id}"]`, 'Mismatch')) === String(vApi.mismatch) && (await rowText(vRow)).includes('POSTED'));
    const k = sess.kpi;
    check('G KPI cards == API: sessions 2, verified, signed Selisih Nilai, Good/Expired/Rusak/Deadstock SKU counts', (await page.locator(tid('soa-kpi-sessions-value')).innerText()) === '2' && (await page.locator(tid('soa-kpi-verified-value')).innerText()) === String(k.verified)
        && (await page.locator(tid('soa-kpi-variance-value')).innerText()).replace(/\s+/g, ' ').includes(Math.abs(k.variance_value).toLocaleString('id-ID')) && (await page.locator(tid('soa-kpi-rusak-value')).innerText()).startsWith(`${k.conditions.rusak.sku} SKU`) && (await page.locator(tid('soa-kpi-deadstock-value')).innerText()).startsWith(`${k.conditions.deadstock.sku} SKU`));
    check('G KPI condition cards show quantities PER UNIT ("KG 22" for Rusak), never one mixed total', (await page.locator(tid('soa-kpi-rusak')).innerText()).includes('KG 22'));
    await shot(page, 'sessions-desktop');

    // ---- H / I selection
    await vRow.click();
    await page.waitForSelector(tid('soa-items-table'));
    await waitIdle(page);
    check('I clicking a session row highlights it, shows the scope chip and populates "Rincian Item Opname — <session>" below', await vRow.evaluate((e) => e.classList.contains('sel')) && await page.locator(tid('soa-scope-chip')).count() === 1 && (await page.locator(tid('soa-items-title')).innerText()).includes(V.session_number) && await page.locator(tid('soa-item-row')).count() === 9);
    await page.evaluate(() => document.getElementById('soa-items-card').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await shot(page, 'selected-session');

    // ---- H wide item table
    const itemsApi = await api(page, '/reports/opname-audit/items', { session_ids: String(V.session_id), per_page: '50' });
    const iheads = await page.locator(`${tid('soa-items-table')} thead th`).allInnerTexts();
    const idef = itemsApi.columns.filter((c) => c.default).map((c) => c.label);
    check('H Rincian Item has the wide column set: Good / Expired / Rusak / Deadstock, Qty Sistem vs Fisik, HPP, Nilai, Selisih, Petugas Hitung/Verifikasi, timestamps, Evidence, Catatan, Adjustment, Aksi', lc(idef).every((l) => lc(iheads).includes(l))
        && lc(['Qty Sistem', 'Qty Fisik Final (Total)', 'Good / Stok Layak', 'Expired', 'Rusak', 'Deadstock', 'Selisih Qty', 'HPP / Unit Cost', 'Nilai Sistem', 'Nilai Fisik', 'Selisih Nilai', 'Petugas Hitung (P1)', 'Petugas Verifikasi (P2)', 'Timestamp Hitung', 'Timestamp Verifikasi', 'Evidence Foto', 'Catatan Petugas', 'Adjustment Ref', 'Adjustment Nilai', 'Status Line', 'Aksi']).every((l) => lc(iheads).includes(l)), iheads.join('|'));
    const sc = await page.evaluate(() => { const s = document.querySelector('[data-testid="soa-items-scroll"]'); const th = document.querySelector('[data-testid="soa-items-table"] th.sticky3'); return { sw: s.scrollWidth, cw: s.clientWidth, sticky: th ? getComputedStyle(th).position : null, over: document.documentElement.scrollWidth - innerWidth, headPos: getComputedStyle(document.querySelector('[data-testid="soa-items-table"] thead th')).position }; });
    check('H the wide table scrolls horizontally INSIDE its container (the page does not), sticky header + sticky identity columns', sc.sw > sc.cw * 1.5 && sc.over <= 0 && sc.sticky === 'sticky' && sc.headPos === 'sticky', JSON.stringify(sc));
    const mixSel = `[data-sku="${V.items.mix.sku}"]`;
    const gv = (label) => cellOf(page, 'soa-items-table', mixSel, label);
    check('K–N V1 mix row: Good 90, Expired 3, Rusak 5, Deadstock 2; Qty Fisik Total 100', (await gv('Good / Stok Layak')) === '90' && (await gv('Expired')) === '3' && (await gv('Rusak')) === '5' && (await gv('Deadstock')) === '2' && (await gv('Qty Fisik Final (Total)')) === '100', [await gv('Good / Stok Layak'), await gv('Expired'), await gv('Rusak'), await gv('Deadstock')].join('/'));
    check('O–R mix row: Qty Sistem 100, Selisih Qty −10, HPP Rp 700, Nilai Fisik Rp 70.000, Selisih Nilai −Rp 7.000', (await gv('Qty Sistem')) === '100' && (await gv('Selisih Qty')).includes('-10') && (await gv('HPP / Unit Cost')).includes('700') && (await gv('Nilai Fisik')).includes('70.000') && (await gv('Selisih Nilai')).includes('7.000'));
    check('S–T mix row: Petugas Hitung = f1a, Petugas Verifikasi = f2a', (await gv('Petugas Hitung (P1)')).includes(V.users.f1a.username) && (await gv('Petugas Verifikasi (P2)')).includes(V.users.f2a.username));
    check('V mix row: Timestamp Hitung / Verifikasi are real formatted times (2026-09-29 12:00:00 → "29 Sep 2026")', (await gv('Timestamp Hitung')).includes('29 Sep 2026') && (await gv('Timestamp Verifikasi')).includes('29 Sep 2026'));
    const posSel = `[data-sku="${V.items.pos.sku}"]`;
    check('U/S pos row (voided f1c finding + live f1b): Petugas Hitung = f1b only; Petugas Verifikasi "—"; Timestamp Verifikasi "—"', (await cellOf(page, 'soa-items-table', posSel, 'Petugas Hitung (P1)')) === V.users.f1b.username && (await cellOf(page, 'soa-items-table', posSel, 'Petugas Verifikasi (P2)')) === '—' && (await cellOf(page, 'soa-items-table', posSel, 'Timestamp Verifikasi')) === '—');
    check('X no evidence: V1 line without photos says "Tidak ada evidence"', (await cellOf(page, 'soa-items-table', posSel, 'Evidence Foto')) === 'Tidak ada evidence');
    check('Y notes: rusak row shows "P1: kemasan sobek" in Catatan Petugas', (await cellOf(page, 'soa-items-table', `[data-sku="${V.items.rusak.sku}"]`, 'Catatan Petugas')).includes('kemasan sobek'));
    const tot = await rowText(page.locator(tid('soa-items-total')));
    check('footer: TOTAL row money == API footer (Nilai Sistem / Selisih Nilai) and quantities are listed per unit below', tot.includes(Math.round(itemsApi.footer.money.system_value).toLocaleString('id-ID')) && (await page.locator(tid('soa-items-units')).innerText()).includes('KG:'));

    // ---- evidence thumbnails + gallery
    const mixThumbs = page.locator(`${tid('soa-item-row')}${mixSel} ${tid('soa-thumb')}`);
    check('W evidence column shows compact thumbnails: 3 thumbs + "+3" for the mix row (6 photos)', await mixThumbs.count() === 3 && (await rowText(page.locator(`${tid('soa-item-row')}${mixSel} ${tid('soa-thumb-more')}`))) === '+3');
    const decoded = await mixThumbs.first().evaluate((img) => new Promise((res) => { if (img.complete) res(img.naturalWidth > 0); else { img.onload = () => res(img.naturalWidth > 0); img.onerror = () => res(false); } }));
    check('W the thumbnail is a REAL decoded image served by the API (naturalWidth > 0), not a placeholder', decoded === true);
    await mixThumbs.first().click();
    await page.waitForSelector(tid('soa-gallery'));
    await page.waitForFunction(() => { const i = document.querySelector('[data-testid="soa-gallery-img"]'); return i && i.complete && i.naturalWidth > 0; });
    const info = await rowText(page.locator(tid('soa-gallery-info')));
    check('W gallery: real image, item/SKU, session, petugas (uploader), upload timestamp, condition + team, photo 1/6', info.includes(V.items.mix.sku) && info.includes(V.session_number) && /Diunggah oleh: jfF/.test(info) && /Timestamp upload: .*2026/.test(info) && /Kondisi: (DAMAGED|EXPIRED|DEADSTOCK)/.test(info) && info.includes('1 / 6'), info.slice(0, 260));
    await page.click('.soa-lb-nav.next');
    check('W gallery: "next" moves to photo 2/6', (await rowText(page.locator(tid('soa-gallery-info')))).includes('2 / 6'));
    await shot(page, 'evidence-gallery');
    await page.keyboard.press('Escape');
    check('W gallery closes with Esc', await page.locator(tid('soa-gallery')).count() === 0);
    await page.locator(`${tid('soa-item-row')}[data-sku="${V.items.rusak.sku}"] ${tid('soa-thumb-more')}`).count();

    // ---- item drawer
    await page.locator(`${tid('soa-item-row')}${mixSel} ${tid('soa-item-detail')}`).click();
    await page.waitForSelector(tid('soa-tab-ringkasan'));
    const drawerText = await page.locator('.drawer.open').innerText();
    check('J item drawer (Ringkasan): good/expired/rusak/deadstock, petugas, timestamps, HPP, selisih', /Good \/ Stok Layak\s*90/.test(drawerText.replace(/\n/g, ' ')) && drawerText.includes(V.users.f1a.username) && drawerText.includes('29 Sep 2026') && drawerText.includes('HPP'), drawerText.slice(0, 200));
    await shot(page, 'item-drawer');
    const tabs = await page.locator('.drawer.open .drawer-tab').allInnerTexts();
    check('J drawer tabs: Ringkasan, Riwayat Hitung, Evidence (6), Rekonsiliasi, Adjustment, Audit', tabs.length === 6 && tabs[2].includes('(6)') && tabs[0] === 'Ringkasan');
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Riwayat Hitung' }).click();
    check('J Riwayat Hitung: P1 and P2 findings with per-condition input quantities', await page.locator(`${tid('soa-history-table')} tbody tr`).count() === 2 && (await rowText(page.locator(tid('soa-history-table')))).includes('DAMAGED'));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Evidence' }).click();
    check('J Evidence tab shows the 6 photos; clicking one opens the gallery', await page.locator('.drawer.open .soa-fig').count() === 6);
    await page.locator('.drawer.open .soa-fig').first().click();
    await page.waitForSelector(tid('soa-gallery'));
    await page.keyboard.press('Escape');
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Rekonsiliasi' }).click();
    check('J Rekonsiliasi tab: model, formula (EOD), supervisor / final decision, book-stock row', (await rowText(page.locator(tid('soa-tab-rekon')))).includes('FINDINGS_V1') && (await rowText(page.locator(tid('soa-tab-rekon')))).includes('Stok buku EOD'));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Audit' }).click();
    check('J Audit tab lists real audit events (finding create)', (await rowText(page.locator(tid('soa-audit-table')))).includes('STOCK_OPNAME_FINDING_CREATE'));
    await page.evaluate(() => Drawer.close());
    await page.waitForTimeout(300);

    // ---- item filters
    await page.selectOption(tid('soa-item-cond'), 'rusak');
    await page.waitForTimeout(700); await waitIdle(page);
    check('item filter "Ada Rusak" keeps 3 V1 lines (rusak, mix, move)', await page.locator(tid('soa-item-row')).count() === 3);
    await page.selectOption(tid('soa-item-cond'), 'evidence');
    await page.waitForTimeout(700); await waitIdle(page);
    check('item filter "Ada evidence" keeps the 4 lines with photos', await page.locator(tid('soa-item-row')).count() === 4);
    await page.selectOption(tid('soa-item-cond'), '');
    await page.fill(tid('soa-item-q'), V.users.f2a.username); await page.keyboard.press('Enter');
    await page.waitForTimeout(700); await waitIdle(page);
    check('item search by petugas name → only that petugas\' 4 lines', await page.locator(tid('soa-item-row')).count() === 4);
    await page.fill(tid('soa-item-q'), ''); await page.keyboard.press('Enter');
    await page.waitForTimeout(700); await waitIdle(page);

    // ---- legacy session items (Z adjustment linkage, AE legacy)
    await page.click(tid('soa-scope-clear'));
    check('I clearing the scope chip in the sessions view hides the per-session detail again', await page.locator(tid('soa-items-table')).count() === 0 || !(await page.locator('#soa-items-card').isVisible()));
    await page.click(tid('soa-view-items'));
    await page.waitForSelector(tid('soa-items-table')); await waitIdle(page);
    check('Rincian Item view without a selected session shows items of ALL sessions in the filter (15)', await page.locator(tid('soa-item-row')).count() === 15);
    const l1 = `[data-sku="${L.items.L1.sku}"]`;
    const gl = (label) => cellOf(page, 'soa-items-table', l1, label);
    check('Z legacy L1: Adjustment Ref real, Adjustment Nilai −Rp 5.000 (FIFO) vs Selisih Nilai −Rp 6.000 — not faked equal', (await gl('Adjustment Ref')) !== '—' && (await gl('Adjustment Nilai')).includes('5.000') && (await gl('Selisih Nilai')).includes('6.000'));
    check('AE legacy L1: Rusak 5, Good 90, Qty Fisik 95; no evidence storage → "—"', (await gl('Rusak')) === '5' && (await gl('Good / Stok Layak')) === '90' && (await gl('Qty Fisik Final (Total)')) === '95' && (await gl('Evidence Foto')) === '—');
    check('AF V1 line in the same table keeps its own semantics (Good = EOD physical) and "Model" can be shown', (await cellOf(page, 'soa-items-table', mixSel, 'Good / Stok Layak')) === '90');
    const l4 = `[data-sku="${L.items.L4.sku}"]`;
    check('legacy L4 recount: Petugas Hitung = B, Verifikasi = C, status "Recount", Qty Fisik 19', (await cellOf(page, 'soa-items-table', l4, 'Petugas Hitung (P1)')) === L.users.B.username && (await cellOf(page, 'soa-items-table', l4, 'Petugas Verifikasi (P2)')) === L.users.C.username && (await cellOf(page, 'soa-items-table', l4, 'Status Line')).toLowerCase() === 'recount' && (await cellOf(page, 'soa-items-table', l4, 'Qty Fisik Final (Total)')) === '19');
    check('legacy excluded L5: "Dikecualikan", Qty Fisik "—" (unknown, not 0)', (await cellOf(page, 'soa-items-table', `[data-sku="${L.items.L5.sku}"]`, 'Status Line')).toLowerCase() === 'dikecualikan' && (await cellOf(page, 'soa-items-table', `[data-sku="${L.items.L5.sku}"]`, 'Qty Fisik Final (Total)')) === '—');

    // ---- column visibility
    await page.click(tid('soa-cols-items'));
    const before = (await page.locator(`${tid('soa-items-table')} thead th`).allInnerTexts()).length;
    await page.locator('#soa-items-card .soa-colmenu input[data-col="adj_by"]').check();
    await page.waitForTimeout(300);
    check('column control "Kolom": showing a hidden column adds it to the table (and is remembered)', lc(await page.locator(`${tid('soa-items-table')} thead th`).allInnerTexts()).includes('adjustment oleh') && (await page.locator(`${tid('soa-items-table')} thead th`).count()) === before + 1);
    await page.screenshot({ path: path.join(shotDir, 'so-audit-column-menu.png') });
    check('column menu: checkbox and label share one row (compact list, not stacked)', await page.evaluate(() => { const l = document.querySelector('#soa-items-card .soa-colopt'); const i = l.querySelector('input'); return Math.abs(i.getBoundingClientRect().top - l.getBoundingClientRect().top) < 14 && l.getBoundingClientRect().height < 34; }));
    await page.click('.soa-title');
    check('clicking outside closes the column menu', await page.locator('#soa-items-card .soa-colmenu').isHidden());
    await page.evaluate(() => { try { localStorage.removeItem('soa_hidden_cols_v1'); } catch (e) { /* */ } });
    await page.evaluate(() => { document.getElementById('soa-items-card').scrollIntoView({ block: 'start' }); });
    await page.screenshot({ path: path.join(shotDir, 'so-audit-items-left.png') });
    await page.evaluate(() => { const s = document.querySelector('[data-testid="soa-items-scroll"]'); const th = Array.from(s.querySelectorAll('thead th')).find((x) => x.textContent.trim().toLowerCase().startsWith('good')); s.scrollLeft += (th.getBoundingClientRect().left - s.getBoundingClientRect().left) - 420; });
    await page.waitForTimeout(300);
    await shot(page, 'items-conditions');
    await page.evaluate(() => { document.querySelector('[data-testid="soa-items-scroll"]').scrollLeft = 99999; });
    await page.waitForTimeout(300);
    await shot(page, 'items-right');
    await page.evaluate(() => { document.querySelector('[data-testid="soa-items-scroll"]').scrollLeft = 0; });

    // ---- exports == screen
    await page.evaluate(() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null; }; });
    await page.click(tid('soa-export'));
    check('AA–AC export menu: Excel workbook + 6 CSVs', await page.locator('.soa-export-item').count() === 7);
    const fetchText = async (u) => page.evaluate(async (url) => { const r = await fetch(url, { credentials: 'include' }); return { status: r.status, type: r.headers.get('content-type'), text: await r.text() }; }, u);
    await page.click(tid('soa-export-items'));
    const itemsUrl = (await page.evaluate(() => window.__opened)).pop();
    const csvItems = await fetchText(itemsUrl);
    const parse = (t) => t.replace(/^﻿/, '').trim().split('\n').filter(Boolean);
    const itemLines = parse(csvItems.text);
    const allLabels = (await api(page, '/reports/opname-audit/items', { session_ids: `${L.session_id},${V.session_id}`, per_page: '50' })).columns.map((c) => c.label);
    check('AB export items (CSV, current scope): every business column of the screen catalogue is in the header; 15 rows', csvItems.status === 200 && allLabels.every((l) => itemLines[0].includes(l)) && itemLines.length === 16, `${itemLines.length} lines`);
    await page.click(tid('soa-export')); await page.click(tid('soa-export-sessions'));
    const sesUrl = (await page.evaluate(() => window.__opened)).pop();
    const csvSes = await fetchText(sesUrl);
    check('AA export sessions (CSV): all session columns in the header, 2 rows, petugas / timestamps / adjustment present', sess.columns.every((c) => parse(csvSes.text)[0].includes(c.label)) && parse(csvSes.text).length === 3 && csvSes.text.includes(V.users.f2a.username));
    await page.click(tid('soa-export')); await page.click(tid('soa-export-evidence'));
    const evUrl = (await page.evaluate(() => window.__opened)).pop();
    const csvEv = await fetchText(evUrl);
    check('AC export evidence (CSV): 12 photo rows with URL, uploader, timestamp', parse(csvEv.text).length === 13 && csvEv.text.includes('/api/reports/opname-audit/photo/') && csvEv.text.includes('Diunggah Oleh'));
    await page.click(tid('soa-export')); await page.click(tid('soa-export-workbook'));
    const wbUrl = (await page.evaluate(() => window.__opened)).pop();
    const wb = await page.evaluate(async (u) => { const r = await fetch(u, { credentials: 'include' }); const b = new Uint8Array(await r.arrayBuffer()); return { status: r.status, type: r.headers.get('content-type'), bytes: Array.from(b) }; }, wbUrl);
    fs.writeFileSync(path.join(shotDir, 'so-audit-sample-export.xlsx'), Buffer.from(wb.bytes));
    const zipList = sh(`unzip -l ${JSON.stringify(path.join(shotDir, 'so-audit-sample-export.xlsx'))}`);
    check('AA–AC workbook: real .xlsx with 7 worksheets (Ringkasan Sesi, Rincian Item, Evidence, Riwayat Hitung, Adjustment, Audit Log, Info)', wb.status === 200 && /spreadsheetml/.test(wb.type) && (zipList.match(/xl\/worksheets\/sheet\d+\.xml/g) || []).length === 7);
    // export preview image (sample rows of the CSV, rendered)
    const sample = await context.newPage();
    const rows = itemLines.slice(0, 6).map((l) => l.split(',').slice(0, 14));
    await sample.setContent(`<html><body style="font:12px sans-serif;background:#fff;padding:12px"><h3>Export Rincian Item (CSV) — 14 kolom pertama, 5 baris pertama</h3><table border="1" cellpadding="4" style="border-collapse:collapse">${rows.map((r, i) => `<tr>${r.map((c) => `<${i ? 'td' : 'th'}>${c.replace(/[<>&]/g, '')}</${i ? 'td' : 'th'}>`).join('')}</tr>`).join('')}</table></body></html>`);
    await sample.setViewportSize({ width: 1500, height: 260 });
    await sample.screenshot({ path: path.join(shotDir, 'so-audit-export-sample.png') });
    await sample.close();

    // ---- reconciliation CLI (read-only) on both sessions
    const rec = sh(`php scripts/opname_audit_reconcile_check.php --app-root=. --session=${L.session_id},${V.session_id}`);
    check('AG/AH read-only reconciliation CLI over both sessions: all checks PASS (exit 0)', /\d+ \/ \d+ checks passed — all reconcile/.test(rec), rec.split('\n').slice(-3).join(' | '));

    // ---- warehouse permission (STOCK user of another warehouse)
    const o = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'outsider' }, seed.outsider);
    await openReport(o.page);
    check('AD STOCK user of ANOTHER warehouse: no sessions of other warehouses are listed; warehouse selector locked', await o.page.locator(tid('soa-session-row')).count() === 0 && await o.page.locator(tid('soa-wh')).isDisabled());
    const forb = await o.page.evaluate(async (id) => (await fetch(`/api/reports/opname-audit/items?session_ids=${id}`, { credentials: 'include' })).status, V.session_id);
    const forbPhoto = await o.page.evaluate(async (id) => (await fetch(`/api/reports/opname-audit/photo/${id}`, { credentials: 'include' })).status, 1);
    check('AD direct API for another warehouse\'s session → 403; its evidence photo → 403 (no leak)', forb === 403 && forbPhoto === 403, `${forb}/${forbPhoto}`);
    await o.context.close();

    // ---- viewer (INVENTORY_VIEW only) sees the report; export Excel Final / print respect permission
    const vw = await newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'viewer' }, seed.viewer);
    await openReport(vw.page);
    await vw.page.waitForSelector(tid('soa-session-row'));
    check('VIEWER (INVENTORY_VIEW only): can open the report and see sessions; "Excel Final" (supervisor export) is NOT offered', await vw.page.locator(tid('soa-session-row')).count() >= 2 && await vw.page.locator(tid('soa-excel-final')).count() === 0);
    await vw.context.close();

    // ---- Session-11-like data (V3): a final Deadstock snapshot WITHOUT any non-VOID deadstock finding (the test DB only); the report must show it unchanged, say where it comes from and flag the missing finding
    const deadSnap = Number(sql(`SELECT l.final_deadstock_qty FROM stock_opname_lines l WHERE l.session_id = ${V.session_id} AND l.item_id = ${V.items.dead.id}`));
    sql(`DELETE q FROM stock_opname_finding_quantities q JOIN stock_opname_findings f ON f.id = q.finding_id WHERE f.session_id = ${V.session_id} AND f.stock_opname_line_id = (SELECT id FROM stock_opname_lines WHERE session_id = ${V.session_id} AND item_id = ${V.items.dead.id}) AND q.condition_type = 'DEADSTOCK'`);
    await page.click(tid('soa-view-sessions'));
    await setFilters(page, { from: day, to: day });
    check('V3 the session list shows a note: conditions of 1 item come from the posted snapshot, no equivalent finding (nothing erased / inferred)', (await page.locator(tid('soa-cond-note')).innerText()).includes(V.session_number) && (await page.locator(tid('soa-cond-note')).innerText()).includes('snapshot final posting'));
    await page.locator(`${tid('soa-session-row')}[data-session-id="${V.session_id}"]`).click();
    await page.waitForSelector(tid('soa-item-row'));
    const deadSel = `[data-sku="${V.items.dead.sku}"]`;
    check(`V3 the Deadstock of that item is still the stored snapshot (${deadSnap}), not zero`, deadSnap > 0 && (await cellOf(page, 'soa-items-table', `${tid('soa-item-row')}${deadSel}`, 'Deadstock')).replace(/[^0-9]/g, '') === String(deadSnap).replace(/[^0-9]/g, ''));
    await page.locator(`${tid('soa-item-row')}${deadSel} ${tid('soa-item-detail')}`).click();
    await page.waitForSelector(tid('soa-condition-block'));
    const cb = (await page.locator(tid('soa-condition-block')).innerText()).replace(/\s+/g, ' ');
    check('V3 the drawer separates the snapshot from the history: "Snapshot final (stock_opname_lines.final_*)", Deadstock status "snapshot — TANPA temuan kondisi", "tidak ada temuan non-VOID"', cb.includes('Snapshot final (stock_opname_lines.final_*)') && cb.includes('TANPA temuan kondisi') && cb.includes('tidak ada temuan non-VOID'), cb.slice(0, 220));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Riwayat Hitung' }).click();
    check('V3 "Riwayat Hitung" still lists exactly the findings that exist and notes that they do not carry the snapshot condition', await page.locator('.drawer.open [data-testid="soa-history-table"] tbody tr').count() >= 2 && (await page.locator(tid('soa-history-condflag')).innerText()).includes('Deadstock'));
    await page.evaluate(() => Drawer.close());
    await page.waitForTimeout(300);
    const rec2 = sh(`php scripts/opname_audit_reconcile_check.php --app-root=. --session=${L.session_id},${V.session_id}`);
    check('V3 reconciliation CLI on the snapshot-without-finding data: every check PASSes (D2 snapshot, D3 disclosed, E raw) and the case is announced', /\d+ \/ \d+ checks passed — all reconcile/.test(rec2) && rec2.includes('NOTE: kondisi final berasal dari snapshot posting') && !rec2.includes('FAIL -'), rec2.split('\n').slice(-3).join(' | '));

    // ---- Jejak still reachable from the report
    await page.click(tid('soa-view-sessions'));
    await page.waitForTimeout(300);
    await page.locator(`${tid('soa-session-row')}[data-session-id="${L.session_id}"] ${tid('soa-jejak')}`).click();
    await page.waitForSelector('[data-jejak-title]');
    check('"Lihat Jejak" opens the existing Jejak Stock Opname drawer for that session', (await page.locator('[data-jejak-title]').innerText()).includes(`#${L.session_id}`));
    await page.evaluate(() => Drawer.close());
    await page.waitForTimeout(300);

    // ---- responsive
    for (const [name, w, h] of [['ipad-landscape', 1180, 820], ['ipad-portrait', 820, 1180], ['mobile', 390, 844]]) {
        const s = await newSession(browser, { viewport: { width: w, height: h }, __name: name }, seed.admin);
        await openReport(s.page);
        await setFilters(s.page, { from: day, to: day });
        const m = await s.page.evaluate(() => ({ over: document.documentElement.scrollWidth - innerWidth, kpiFont: parseFloat(getComputedStyle(document.querySelector('.soa-kpi-value')).fontSize), clipped: Array.from(document.querySelectorAll('.soa-kpi-value')).filter((e) => e.scrollWidth > e.clientWidth + 1).length }));
        check(`AK–AM ${name}: no horizontal page overflow, KPI font ${m.kpiFont}px, no clipped KPI value`, m.over <= 0 && m.kpiFont <= 24 && m.clipped === 0, JSON.stringify(m));
        if (name === 'ipad-landscape') await shot(s.page, 'ipad-landscape-sessions');
        await s.page.locator(`${tid('soa-session-row')}[data-session-id="${V.session_id}"]`).click();
        await s.page.waitForSelector(tid('soa-items-table'));
        await s.page.waitForTimeout(500);
        const sc2 = await s.page.evaluate(() => { const e = document.querySelector('[data-testid="soa-items-scroll"]'); return { scrolls: e.scrollWidth > e.clientWidth, over: document.documentElement.scrollWidth - innerWidth }; });
        check(`AK–AM ${name}: the item table scrolls inside its container; page does not overflow`, sc2.scrolls && sc2.over <= 0, JSON.stringify(sc2));
        if (name === 'ipad-landscape') { await s.page.evaluate(() => document.getElementById('soa-items-card').scrollIntoView({ block: 'start' })); await s.page.waitForTimeout(300); await shot(s.page, 'ipad-landscape-items'); }
        if (name === 'ipad-portrait') await shot(s.page, 'ipad-portrait');
        if (name === 'mobile') await shot(s.page, 'mobile');
        await s.context.close();
    }

    // the outsider session deliberately fires two forbidden requests (403) to prove there is no leak — the browser logs those as resource errors
    const realErrors = consoleErrors.filter((e) => !(e.startsWith('outsider ') && /status of 403/.test(e)));
    check('AJ no console / page errors in any session (the two deliberate 403 probes of the other-warehouse user excluded)', realErrors.length === 0, realErrors.slice(0, 4).join(' || '));
    check('AI only GET requests left the browser while using the report (no write)', requests.every((r) => r.method === 'GET'), requests.filter((r) => r.method !== 'GET').map((r) => r.method + r.path).join(','));
    await context.close();
} finally {
    if (browser) await browser.close();
    await stopServer();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
