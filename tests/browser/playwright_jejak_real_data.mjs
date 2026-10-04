// "Jejak Stock Opname" — REAL DATA, end to end, through the ACTIVE production
// report (report-opname.js, sidebar "Laporan P1/P2 Stock Opname").
//
// Real everything except the machine: real MariaDB with the real schema, the
// real PHP API (php -S public/router.php), two sessions built through the
// application's own services (tests/lib/jejak_real_fixture.php — one
// LEGACY_DUAL_COUNT "CIBADAK-like", one FINDINGS_V1 "SCM-like") plus a 130-line
// session for pagination/scroll, real login through the login form, real
// Chromium. Expectations are hand-computed in the fixture, not read back from
// the code under test.
//
// Usage (DB_* must point at a disposable database the user can DROP/CREATE via
// `mysql -uroot`):
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw \
//     node tests/browser/playwright_jejak_real_data.mjs
import { chromium, devices } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const shotDir = process.env.JEJAK_SHOT_DIR || __dirname;
const dbName = process.env.DB_DATABASE || 'inventory_test';

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}
const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env }).toString();
const rp = (n) => `Rp ${Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;
const nq = (n) => (n === null ? '—' : Number(n).toLocaleString('id-ID', { maximumFractionDigits: 4 }));

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot ${dbName} < database/schema.sql`);
const seed = JSON.parse(sh('php tests/browser/seed_jejak_real_sessions.php'));
console.log('Seeded sessions:', seed.legacy.id, seed.legacy.number, '|', seed.findings.id, seed.findings.number, '|', seed.big.id);

// php -S serves one connection per worker and chokes on Chromium's many parallel
// keep-alive connections for the ~70 static assets, so: a tiny node server serves
// public/ itself and proxies ONLY /api/* to php -S (one short-lived connection each).
let phpServer;
let proxy;
let base;
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
            const up = http.request({ host: '127.0.0.1', port: phpPort, path: req.url, method: req.method, headers: { ...req.headers, connection: 'close' }, agent: false }, (r) => {
                res.writeHead(r.statusCode, r.headers);
                r.pipe(res);
            });
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

async function newSession(browser, contextOptions) {
    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${contextOptions.__name || ''} ${m.text()}`); });
    page.on('pageerror', (e) => consoleErrors.push(String(e)));
    page.on('request', (r) => { if (r.url().includes('/api/') && !r.url().includes('/auth/')) requests.push({ method: r.method(), path: new URL(r.url()).pathname.replace(/^\/api/, '') }); });
    const pending = new Set();
    page.on('request', (r) => pending.add(r.url()));
    page.on('requestfinished', (r) => pending.delete(r.url()));
    page.on('requestfailed', (r) => pending.delete(r.url()));
    try {
        await page.goto(base + '/', { waitUntil: 'load', timeout: 20000 });
    } catch (e) {
        console.error('goto failed; still-pending requests:', Array.from(pending).slice(0, 10));
        throw e;
    }
    await page.fill('#login-username', seed.admin.username);
    await page.fill('#login-password', seed.admin.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 10000 });
    return { context, page };
}

async function openReport(page) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-opname"]').click());
    await page.waitForSelector('#tab-laporan-opname.active', { timeout: 5000 });
    await page.waitForSelector('#tab-laporan-opname tbody tr:not(.dt-skeleton-row) td:not(.dt-empty)', { timeout: 15000 });
}
async function openJejak(page, number) {
    const row = page.locator('#tab-laporan-opname tbody tr', { hasText: number });
    await row.locator('td').first().click();
    await page.waitForSelector('.drawer.open [data-jejak-title]', { timeout: 5000 });
    await page.waitForSelector('.drawer-tab', { timeout: 15000 }); // real data loaded (tabs only exist after the fetch)
    await page.waitForSelector('[data-testid="jejak-items-table"]', { timeout: 5000 });
}
async function closeJejak(page) {
    await page.click('.drawer-close');
    await page.waitForTimeout(300);
}
const apiJson = (page, id) => page.evaluate(async (sid) => (await (await fetch(`/api/reports/opname/${sid}/jejak`, { credentials: 'include' })).json()).data, id);

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await newSession(browser, { viewport: { width: 1440, height: 900 }, __name: 'desktop' });

    // ================================================== 1. active page
    await openReport(page);
    const scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.getAttribute('src') || ''));
    check('active report-opname.js + jejak module loaded', scripts.some((s) => s.includes('report-opname.js')) && scripts.some((s) => s.includes('stock-opname-report-jejak.js')));
    check('report lists the fixture sessions', await page.locator('#tab-laporan-opname tbody tr', { hasText: seed.legacy.number }).count() === 1 && await page.locator('#tab-laporan-opname tbody tr', { hasText: seed.findings.number }).count() === 1);

    for (const key of ['findings', 'legacy']) {
        const S = seed[key];
        const E = S.expect;
        console.log(`\n===== ${key.toUpperCase()} session #${S.id} ${S.number} (${S.model}) =====`);
        const before = requests.length;
        await openJejak(page, S.number);
        const jejakCalls = requests.slice(before).filter((r) => r.path.includes('/jejak'));
        check(`[${key}] row click made exactly one GET to /reports/opname/${S.id}/jejak`, jejakCalls.length === 1 && jejakCalls[0].method === 'GET' && jejakCalls[0].path === `/reports/opname/${S.id}/jejak`, JSON.stringify(jejakCalls));
        check(`[${key}] no write request from opening the drawer`, requests.slice(before).every((r) => r.method === 'GET'));

        // ---- no preview/simulation remnants
        const bodyText = await page.locator('body').innerText();
        check(`[${key}] no PREVIEW / DATA SIMULASI / Lainnya (simulasi) text anywhere`, !/PREVIEW UI|DATA SIMULASI|data simulasi|simulasi/i.test(bodyText));
        check(`[${key}] no preview badge/note elements`, await page.locator('[data-testid="jejak-preview-badge"], [data-testid="jejak-preview-note"]').count() === 0);

        // ---- identity
        const title = (await page.locator('.drawer-title').first().innerText()).replace(/\s+/g, ' ');
        const sub = (await page.locator('[data-testid="jejak-subtitle"]').innerText());
        check(`[${key}] header shows real id #${S.id}, status POSTED and session number`, title.includes(`#${S.id}`) && title.includes('POSTED') && sub.includes(S.number), `${title} | ${sub}`);
        const info = (await page.locator('.jejak-info-grid').first().innerText()).replace(/\s+/g, ' ');
        check(`[${key}] info card: warehouse ${S.warehouse}, date, model, created by admin`, info.includes(S.warehouse) && info.includes('2026-09-30') && info.includes(S.model === 'FINDINGS_V1' ? 'Findings' : 'Dual count') && info.includes(seed.admin.username), info.slice(0, 200));
        const tabs = (await page.locator('.drawer-tab').allTextContents()).map((t) => t.trim());
        check(`[${key}] tabs Overview|Per Barang|Rekonsiliasi|Audit, Per Barang active`, tabs.join('|') === 'Overview|Per Barang|Rekonsiliasi|Audit' && (await page.locator('.drawer-tab.active').innerText()).trim() === 'Per Barang');

        // ---- server payload (same session) for cross-checks
        const api = await apiJson(page, S.id);
        const itemCount = Object.keys(S.skus).length;
        check(`[${key}] real line count = ${itemCount}`, api.items.length === itemCount && (await page.locator('[data-testid="jejak-items-total-row"]').innerText()).includes(`${itemCount} SKU`));

        // ---- per-line cells
        const heads = (await page.locator('[data-testid="jejak-items-table"] thead th').allTextContents()).map((h) => h.trim());
        const col = (name) => heads.indexOf(name);
        for (const k of Object.keys(E.hpp)) {
            const sku = S.skus[k];
            const cells = (await page.locator('[data-testid="jejak-items-table"] tbody tr', { hasText: sku }).first().locator('td').allInnerTexts()).map((t) => t.trim());
            const want = {
                'Qty Sistem': nq(E.system[k]), 'Final Count': nq(E.final[k]), 'HPP (Rp)': rp(E.hpp[k]),
            };
            const ok = Object.entries(want).every(([h, v]) => cells[col(h)] === v);
            check(`[${key}/${k}] Qty Sistem / Final Count / HPP cells = ${want['Qty Sistem']} / ${want['Final Count']} / ${want['HPP (Rp)']}`, ok, ok ? '' : JSON.stringify(cells));
            const varCell = cells[col('Selisih Qty')].replace('+', '');
            check(`[${key}/${k}] Selisih Qty cell = ${nq(E.variance[k])}`, varCell === (E.variance[k] === null ? '—' : (E.variance[k] === 0 ? '0' : nq(E.variance[k]))), cells[col('Selisih Qty')]);
        }
        if (key === 'legacy') {
            const u = S.users;
            const c = async (k) => (await page.locator('[data-testid="jejak-items-table"] tbody tr', { hasText: S.skus[k] }).first().locator('td').allInnerTexts()).map((t) => t.trim());
            const l1 = await c('L1'); const l4 = await c('L4'); const l6 = await c('L6'); const l5 = await c('L5');
            check('[legacy] L1 Count 01 = 95 by A, Count 02 = 95 by C', l1[col('Hasil Count 01')] === '95' && l1[col('Petugas 01')] === u.A && l1[col('Hasil Count 02')] === '95' && l1[col('Petugas 02')] === u.C, JSON.stringify(l1));
            check('[legacy] L4 Count 01 = 18 by B, Count 02 = 22 by C (recount → final 19)', l4[col('Hasil Count 01')] === '18' && l4[col('Petugas 01')] === u.B && l4[col('Hasil Count 02')] === '22' && l4[col('Petugas 02')] === u.C && l4[col('Final Count')] === '19');
            check('[legacy] L6 P1 by B / P2 by D — real per-line users, not one session-level person', l6[col('Petugas 01')] === u.B && l6[col('Petugas 02')] === u.D);
            check('[legacy] excluded L5 shows — for counts/users/final and is labelled dikecualikan', l5[col('Hasil Count 01')] === '—' && l5[col('Petugas 01')] === '—' && l5[col('Final Count')] === '—' && l5[col('Nama Barang')].includes('dikecualikan'));
            const l1dead = l1[col('Rusak Qty')], l3 = await c('L3');
            check('[legacy] L1 Rusak Qty 5, L3 Dead Stock Qty 30 (recorded quantities)', l1dead === '5' && l3[col('Dead Stock Qty')] === '30');
        } else {
            const u = S.users;
            const c = async (k) => (await page.locator('[data-testid="jejak-items-table"] tbody tr', { hasText: S.skus[k] }).first().locator('td').allInnerTexts()).map((t) => t.trim());
            const pos = await c('pos'); const neg = await c('neg'); const rusak = await c('rusak'); const nobase = await c('nobase'); const mix = await c('mix');
            check('[findings] pos Count 01 = 55 by f1b ONLY (voided finding by f1c not shown)', pos[col('Hasil Count 01')] === '55' && pos[col('Petugas 01')] === u.f1b, JSON.stringify(pos));
            check('[findings] neg Count 01 = 95 by f1a; Count 02 / Petugas 02 = — (P2 never counted)', neg[col('Hasil Count 01')] === '95' && neg[col('Petugas 01')] === u.f1a && neg[col('Hasil Count 02')] === '—' && neg[col('Petugas 02')] === '—');
            check('[findings] rusak P1 f1a / P2 f2a, Rusak Qty 2', rusak[col('Petugas 01')] === u.f1a && rusak[col('Petugas 02')] === u.f2a && rusak[col('Rusak Qty')] === '2');
            check('[findings] SKU without EOD baseline: Qty Sistem —, Selisih — (never 0)', nobase[col('Qty Sistem')] === '—' && nobase[col('Selisih Qty')] === '—' && nobase[col('Nilai Selisih (Rp)')] === '—');
            check('[findings] mix: Final 90, Selisih -10, Dead 2, Rusak 5, Expired 3', mix[col('Final Count')] === '90' && mix[col('Selisih Qty')] === '-10' && mix[col('Dead Stock Qty')] === '2' && mix[col('Rusak Qty')] === '5' && mix[col('Expired Qty')] === '3');
            check('[findings] data note explains the unvalued SKU and the missing adjustment', (await page.locator('[data-testid="jejak-data-notes"]').innerText()).includes('belum memiliki qty sistem') && (await page.locator('[data-testid="jejak-data-notes"]').innerText()).includes('tidak ada adjustment'));
        }

        // ---- KPI cards vs hand-computed vs server vs drill-down
        const KPI = [
            ['nilai_stok_sistem', 'Rincian Nilai Stok Sistem', ['SKU', 'Nama Barang', 'Satuan', 'Qty Sistem', 'HPP (Rp)', 'Nilai Stok Sistem (Rp)']],
            ['nilai_final_count', 'Rincian Nilai Final Count', ['SKU', 'Nama Barang', 'Satuan', 'Final Count', 'HPP (Rp)', 'Nilai Final Count (Rp)']],
            ['selisih_nominal', 'Rincian Selisih Nominal', ['SKU', 'Nama Barang', 'Qty Sistem', 'Final Count', 'Selisih Qty', 'HPP (Rp)', 'Nilai Selisih (Rp)', 'Lebih/Kurang']],
            ['dead_stock', 'Rincian Dead Stock', ['SKU', 'Nama Barang', 'Dead Stock Qty', 'HPP (Rp)', 'Nilai Dead Stock (Rp)', 'Catatan']],
            ['rusak', 'Rincian Rusak', ['SKU', 'Nama Barang', 'Rusak Qty', 'HPP (Rp)', 'Nilai Rusak (Rp)', 'Catatan']],
            ['adjustment_bersih', 'Rincian Adjustment Bersih', ['SKU', 'Nama Barang', 'Jenis Adjustment', 'Qty Adjustment', 'HPP (Rp)', 'Nilai Adjustment (Rp)', 'Kontribusi']],
        ];
        check(`[${key}] 6 KPI cards`, await page.locator('.drawer-body .jejak-kpi').count() === 6);
        const clientKpi = await page.evaluate((a) => StockOpnameJejak._computeKpis(a), api);
        for (const [k, titleText, cols] of KPI) {
            const card = (await page.locator(`[data-testid="jejak-kpi-value-${k}"]`).innerText()).trim();
            check(`[${key}] KPI ${k}: card ${rp(E[k])} = hand-computed`, card === rp(E[k]), card);
            check(`[${key}] KPI ${k}: card = server value = client value`, Math.abs(api.kpi[k].value - E[k]) < 0.005 && Math.abs(clientKpi[k].value - E[k]) < 0.005 && api.kpi[k].count === clientKpi[k].count);

            await page.locator(`[data-testid="jejak-kpi-${k}"]`).click();
            await page.waitForSelector('[data-testid="jejak-drill-modal"]');
            const t = (await page.locator('[data-testid="jejak-drill-title"]').innerText()).trim();
            const heads2 = (await page.locator('[data-testid="jejak-drill-table"] thead th').allTextContents()).map((h) => h.trim());
            const total = (await page.locator('[data-testid="jejak-drill-total"]').innerText()).trim();
            const count = (await page.locator('[data-testid="jejak-drill-count"]').innerText()).trim();
            const totalRow = (await page.locator('[data-testid="jejak-drill-total-row"]').innerText()).replace(/\s+/g, ' ');
            check(`[${key}/${k}] breakdown title + exact columns`, t.includes(titleText) && heads2.join('|') === cols.join('|'), `${t} | ${heads2.join('|')}`);
            check(`[${key}/${k}] breakdown header total = card`, total === card, `${total} vs ${card}`);
            check(`[${key}/${k}] breakdown count = server count (${api.kpi[k].count})`, count.startsWith(`${api.kpi[k].count} `), count);
            check(`[${key}/${k}] TOTAL row nominal = card`, totalRow.includes(card.replace('Rp -', 'Rp -')) || (E[k] === 0 && totalRow.includes('Rp 0')), totalRow);
            const rowsText = await page.locator('[data-testid="jejak-drill-table"] tbody tr:not(.jejak-total-row)').count();
            const expectedRows = { nilai_stok_sistem: itemCount, nilai_final_count: itemCount }[k];
            if (expectedRows !== undefined) check(`[${key}/${k}] lists every real line (${expectedRows})`, rowsText === expectedRows, String(rowsText));
            if (k === 'adjustment_bersih') check(`[${key}/adjustment] row count = real adjustments (${api.adjustments.length})`, key === 'legacy' ? rowsText === 3 : (rowsText === 1 && (await page.locator('[data-testid="jejak-drill-table"] tbody').innerText()).includes('tidak ada adjustment')), String(rowsText));
            check(`[${key}/${k}] Jejak drawer still open underneath`, await page.locator('.drawer.open').count() === 1);
            if (key === 'findings' && k === 'selisih_nominal') await page.screenshot({ path: path.join(shotDir, 'screenshot_jejak_kpi_drilldown_real.png') });
            if (k === 'selisih_nominal') {
                const all = await page.locator('[data-testid="jejak-drill-table"] tbody tr:not(.jejak-total-row)').count();
                await page.selectOption('[data-testid="jejak-drill-filter"]', 'Kurang');
                const shortage = api.items.filter((i) => i.variance_qty !== null && i.variance_qty < 0).length;
                check(`[${key}] selisih filter "Kurang" = ${shortage} real rows; TOTAL = shortage ${rp(api.kpi.selisih_nominal.shortage)}`,
                    await page.locator('[data-testid="jejak-drill-table"] tbody tr:not(.jejak-total-row)').count() === shortage && (await page.locator('[data-testid="jejak-drill-total-row"]').innerText()).includes(rp(api.kpi.selisih_nominal.shortage)), `${all} -> ${shortage}`);
                await page.fill('[data-testid="jejak-drill-search"]', 'zzz-none');
                check(`[${key}] drill-down search with no match → empty message`, (await page.locator('[data-testid="jejak-drill-table"]').innerText()).includes('Tidak ada baris yang cocok'));
            }
            await page.click('[data-testid="jejak-drill-close"]');
            check(`[${key}/${k}] ✕ closes modal, Jejak still open`, await page.locator('[data-testid="jejak-drill-modal"]').count() === 0 && await page.locator('.drawer.open').count() === 1);
        }
        if (key === 'legacy') {
            await page.locator('[data-testid="jejak-kpi-adjustment_bersih"]').click();
            const adjText = (await page.locator('[data-testid="jejak-drill-table"] tbody').innerText()).replace(/\s+/g, ' ');
            check('[legacy] adjustment breakdown shows the REAL FIFO posting: L1 −5 @ Rp 1.000 = Rp -5.000 (not −6.000)', adjText.includes(S.skus.L1) && adjText.includes('Rp -5.000') && !adjText.includes('Rp -6.000'), adjText.slice(0, 160));
            await page.keyboard.press('Escape');
        }

        // ---- Overview / Rekonsiliasi / Audit
        await page.click('.drawer-tab:has-text("Overview")');
        check(`[${key}] Overview tab: KPI cards + bottom summary`, await page.locator('.drawer-body .jejak-kpi').count() === 6 && await page.locator('[data-testid="jejak-bottom-summary"]').count() === 1);
        await page.click('.drawer-tab:has-text("Rekonsiliasi")');
        check(`[${key}] Rekonsiliasi tab lists the authoritative sources`, (await page.locator('.drawer-body').innerText()).includes('unit_cost_base'));
        await page.click('.drawer-tab:has-text("Audit")');
        check(`[${key}] Audit tab shows who posted`, (await page.locator('.drawer-body').innerText()).includes(seed.admin.username));
        await page.click('.drawer-tab:has-text("Per Barang")');

        // ---- bottom summary + actions
        const sumTexts = (await page.locator('[data-testid="jejak-bottom-summary"] .hpp-kpi-value').allInnerTexts()).map((t) => t.trim());
        check(`[${key}] bottom summary = server summary (${api.summary.sku_cocok}/${api.summary.perlu_review}/${api.summary.dead_stock_sku}/${api.summary.rusak_sku})`, sumTexts.join(',') === [api.summary.sku_cocok, api.summary.perlu_review, `${api.summary.dead_stock_sku} SKU`, `${api.summary.rusak_sku} SKU`].join(','), sumTexts.join(','));
        check(`[${key}] Posting Adjustment is disabled (Disabled label)`, await page.locator('[data-testid="jejak-post"]').isDisabled() && (await page.locator('[data-testid="jejak-post"]').innerText()).includes('(Disabled)'));
        check(`[${key}] Export Excel is disabled (not faked)`, await page.locator('[data-testid="jejak-export"]').isDisabled());
        if (key === 'legacy') {
            const popupP = context.waitForEvent('page', { timeout: 4000 }).catch(() => null);
            const rb = requests.length;
            await page.click('[data-testid="jejak-print"]');
            const popup = await popupP;
            const pt = popup ? await popup.content() : '';
            check('[legacy] Cetak Laporan prints the REAL data (session number, SKUs, KPI) in a new window', !!popup && pt.includes(S.number) && pt.includes(S.skus.L1) && pt.includes(rp(E.nilai_stok_sistem)));
            check('[legacy] printing made no API request', requests.length === rb);
            if (popup) await popup.close();
        }
        await page.locator('[data-testid="jejak-post"]').click({ force: true, timeout: 1000 }).catch(() => { /* disabled */ });
        check(`[${key}] no write request so far in this session`, requests.every((r) => r.method === 'GET'));

        await closeJejak(page);
        check(`[${key}] drawer closed; .drawer-xl and page scroll lock removed`, await page.locator('.drawer.open').count() === 0 && await page.locator('.drawer.drawer-xl').count() === 0 && !(await page.evaluate(() => document.body.classList.contains('jejak-scroll-lock') || document.documentElement.classList.contains('jejak-scroll-lock'))));

        // ---- Lihat Detail → the real trace drawer, normal width
        const detailBtn = page.locator('#tab-laporan-opname tbody tr', { hasText: S.number }).locator('button', { hasText: 'Lihat Detail' });
        await detailBtn.click();
        await page.waitForFunction((n) => document.querySelector('.drawer.open .drawer-title') && document.querySelector('.drawer.open .drawer-title').textContent.includes(`#${n}`) && document.querySelectorAll('.drawer-tab').length === 3, S.id, { timeout: 8000 });
        const w = await page.evaluate(() => Math.round(document.querySelector('.drawer').getBoundingClientRect().width));
        check(`[${key}] Lihat Detail opens the REAL trace drawer at normal width (${w}px ≤ 560), not Jejak`, w <= 560 && await page.locator('.drawer.drawer-xl').count() === 0 && requests.some((r) => r.path === `/trace/opname/${S.id}`));
        await closeJejak(page);
    }

    // ================================================== pagination + vertical scroll (130-line session)
    console.log('\n===== SCROLL / PAGINATION (130 lines) =====');
    await openJejak(page, seed.big.number);
    const bigRows = await page.locator('[data-testid="jejak-items-table"] tbody tr:not(.jejak-total-row)').count();
    check('130-line session: table paginates at 50 rows, pager shows "dari 130"', bigRows === 50 && (await page.locator('[data-testid="jejak-items-pager"]').innerText()).includes('dari 130'), String(bigRows));
    const totalLabel = await page.locator('[data-testid="jejak-items-total-row"]').innerText();
    check('130-line session: TOTAL row covers ALL 130 SKU, not just the page', totalLabel.includes('130 SKU'));
    await page.click('[data-testid="jejak-items-next"]');
    check('pager next → rows 51–100', (await page.locator('[data-testid="jejak-items-pager"]').innerText()).includes('51–100'));
    await page.click('[data-testid="jejak-items-prev"]');

    // wheel over the CENTRE of the table: must scroll the drawer body (no nested scroller to get trapped in)
    await page.evaluate(() => { document.querySelector('.drawer-body').scrollTop = 0; });
    await page.waitForTimeout(150);
    const box = await page.locator('[data-testid="jejak-items-table-wrap"]').boundingBox();
    const wx = box.x + box.width / 2; const wy = Math.min(box.y + 150, 800);
    await page.mouse.move(wx, wy);
    const pre = await page.evaluate(() => ({ top: document.querySelector('.drawer-body').scrollTop, scrollY: window.scrollY }));
    for (let i = 0; i < 40; i++) { await page.mouse.wheel(0, 400); await page.waitForTimeout(15); }
    await page.waitForTimeout(250);
    const wheel = await page.evaluate(() => { const b = document.querySelector('.drawer-body'); return { top: b.scrollTop, max: b.scrollHeight - b.clientHeight, scrollY: window.scrollY }; });
    check('wheel over the table scrolls the drawer all the way to the bottom (no scroll trap)', wheel.top > pre.top && Math.abs(wheel.top - wheel.max) <= 1, JSON.stringify({ pre, wheel }));
    check('the page BEHIND the drawer did not scroll at all while the drawer scrolled (html + body locked)', wheel.scrollY === pre.scrollY, JSON.stringify({ pre, wheel }));
    const lock = await page.evaluate(() => ({ html: getComputedStyle(document.documentElement).overflow, body: getComputedStyle(document.body).overflow }));
    check('both <html> and <body> are overflow:hidden while the drawer is open', lock.html === 'hidden' && lock.body === 'hidden', JSON.stringify(lock));
    await page.screenshot({ path: path.join(shotDir, 'screenshot_jejak_scrolled_bottom.png') });
    await closeJejak(page);
    check('page scroll lock released after close (html + body)', await page.evaluate(() => !document.body.classList.contains('jejak-scroll-lock') && !document.documentElement.classList.contains('jejak-scroll-lock') && getComputedStyle(document.documentElement).overflowY !== 'hidden'));

    // ================================================== failure / race handling
    console.log('\n===== ERROR + RACE HANDLING =====');
    let failOnce = true;
    await context.route('**/api/reports/opname/*/jejak', async (route) => {
        if (failOnce) { failOnce = false; await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'FORBIDDEN', message: 'Missing permission: INVENTORY_VIEW' } }) }); } else { await route.continue(); }
    });
    await page.locator('#tab-laporan-opname tbody tr', { hasText: seed.legacy.number }).locator('td').first().click();
    await page.waitForSelector('[data-testid="jejak-error"]', { timeout: 5000 });
    check('API failure shows the real error message + retry, and NO mock/KPI data', (await page.locator('[data-testid="jejak-error"]').innerText()).includes('Missing permission: INVENTORY_VIEW') && await page.locator('.jejak-kpi').count() === 0 && await page.locator('[data-testid="jejak-retry"]').count() === 1);
    await page.click('[data-testid="jejak-retry"]');
    await page.waitForSelector('[data-testid="jejak-items-table"]', { timeout: 8000 });
    check('retry loads the real data', await page.locator('.jejak-kpi').count() === 6);
    await closeJejak(page);
    await context.unroute('**/api/reports/opname/*/jejak');

    await context.route('**/api/reports/opname/*/jejak', async (route) => { await new Promise((r) => setTimeout(r, 700)); await route.continue(); });
    await page.locator('#tab-laporan-opname tbody tr', { hasText: seed.legacy.number }).locator('td').first().click();
    await page.waitForSelector('[data-testid="jejak-loading"]');
    check('while loading: loading text, no data, no preview', (await page.locator('.drawer-body').innerText()).includes('Memuat') && await page.locator('.jejak-kpi').count() === 0);
    await closeJejak(page);
    await page.waitForTimeout(1200);
    check('closing before the response arrives: drawer stays closed (late response ignored)', await page.locator('.drawer.open').count() === 0 && await page.locator('.drawer.drawer-xl').count() === 0);
    await context.unroute('**/api/reports/opname/*/jejak');

    // ================================================== device matrix: vertical scroll reaches the bottom
    console.log('\n===== DEVICE MATRIX =====');
    const DEVICES = [
        ['desktop 1440x900', { viewport: { width: 1440, height: 900 } }],
        ['laptop 1280x600', { viewport: { width: 1280, height: 600 } }],
        ['iPad portrait', { ...devices['iPad (gen 7)'] }],
        ['iPad landscape', { ...devices['iPad (gen 7) landscape'] }],
        ['iPhone 13', { ...devices['iPhone 13'] }],
        ['iPhone 13 landscape', { ...devices['iPhone 13 landscape'] }],
    ];
    for (const [name, opts] of DEVICES) {
        // one browser per device: Chromium pools keep-alive sockets per browser, and php -S serves one connection per worker
        const deviceBrowser = await chromium.launch();
        const { context: dctx, page: dp } = await newSession(deviceBrowser, { ...opts, __name: name });
        await openReport(dp);
        await openJejak(dp, seed.big.number);
        await dp.waitForTimeout(350);
        const m = await dp.evaluate(async () => {
            const d = document.querySelector('.drawer'); const b = d.querySelector('.drawer-body');
            const vv = window.visualViewport;
            const before = b.scrollTop;
            b.scrollTop = b.scrollHeight; await new Promise((r) => setTimeout(r, 120));
            const dr = d.getBoundingClientRect(); const ar = document.querySelector('[data-testid="jejak-actions"]').getBoundingClientRect();
            const hr = d.querySelector('.drawer-header').getBoundingClientRect(); const tr = d.querySelector('.drawer-tabs').getBoundingClientRect();
            const sum = document.querySelector('[data-testid="jejak-bottom-summary"]').getBoundingClientRect();
            const wrap = document.querySelector('[data-testid="jejak-items-table-wrap"]');
            const st = getComputedStyle(d);
            return {
                vh: Math.round(vv ? vv.height : innerHeight), drawerTop: Math.round(dr.top), drawerBottom: Math.round(dr.bottom), position: st.position,
                scrolled: b.scrollTop > before, atEnd: Math.abs(b.scrollTop - (b.scrollHeight - b.clientHeight)) <= 1,
                actionsInside: ar.top >= dr.top && ar.bottom <= dr.bottom + 1 && ar.bottom <= (vv ? vv.height : innerHeight) + 1,
                summaryAboveActions: sum.bottom <= ar.top + 1, headerVisible: hr.top >= 0 && tr.bottom > hr.bottom,
                bodyNoHScroll: b.scrollWidth <= b.clientWidth + 1, wrapScrollsX: wrap.scrollWidth > wrap.clientWidth && ['auto', 'scroll'].includes(getComputedStyle(wrap).overflowX),
                wrapNoVScroll: getComputedStyle(wrap).overflowY !== 'auto' && getComputedStyle(wrap).overflowY !== 'scroll',
            };
        });
        check(`[${name}] drawer pinned to the visible viewport (top 0, bottom = ${m.vh}px)`, m.drawerTop === 0 && Math.abs(m.drawerBottom - m.vh) <= 1 && m.position === 'fixed', JSON.stringify(m));
        check(`[${name}] scrolls from top to the very end`, m.scrolled && m.atEnd);
        check(`[${name}] Cetak/Export/Posting footer fully inside the visible viewport at the end`, m.actionsInside);
        check(`[${name}] bottom summary cards are above the footer, not hidden behind it`, m.summaryAboveActions);
        check(`[${name}] header + tabs stay visible`, m.headerVisible);
        check(`[${name}] no horizontal overflow on the drawer; the TABLE scrolls horizontally and has no vertical scroller`, m.bodyNoHScroll && m.wrapScrollsX && m.wrapNoVScroll);
        // required columns reachable
        const reach = await dp.evaluate(async () => {
            const wrap = document.querySelector('[data-testid="jejak-items-table-wrap"]'); const out = {};
            for (const h of ['Dead Stock Qty', 'Rusak Qty', 'HPP (Rp)', 'Nilai Selisih (Rp)', 'Nilai Dead Stock (Rp)', 'Nilai Rusak (Rp)']) {
                const th = Array.from(wrap.querySelectorAll('thead th')).find((t) => t.textContent.trim() === h);
                wrap.scrollLeft = th.offsetLeft - 10; await new Promise((r) => setTimeout(r, 30));
                const wr = wrap.getBoundingClientRect(); const tr = th.getBoundingClientRect();
                out[h] = tr.left >= wr.left - 1 && tr.right <= wr.right + 1;
            }
            return out;
        });
        check(`[${name}] Dead Stock Qty, Rusak Qty, HPP, Nilai Selisih/Dead/Rusak reachable by horizontal scroll`, Object.values(reach).every(Boolean), JSON.stringify(reach));
        // modal fits the viewport and scrolls itself
        await dp.evaluate(() => { document.querySelector('.drawer-body').scrollTop = 0; });
        await dp.locator('[data-testid="jejak-kpi-nilai_stok_sistem"]').click();
        await dp.waitForSelector('[data-testid="jejak-drill-modal"]');
        const mod = await dp.evaluate(() => { const c = document.querySelector('[data-testid="jejak-drill-modal"]'); const r = c.getBoundingClientRect(); return { fits: r.top >= 0 && r.bottom <= innerHeight + 1 && r.left >= 0 && r.right <= innerWidth + 1, scrolls: c.scrollHeight > c.clientHeight }; });
        check(`[${name}] drill-down modal fits the viewport (scrolls inside if taller)`, mod.fits, JSON.stringify(mod));
        await dp.keyboard.press('Escape');
        if (name === 'desktop 1440x900') {
            await dp.screenshot({ path: path.join(shotDir, 'screenshot_jejak_real_drawer.png') });
        }
        await closeJejak(dp);
        check(`[${name}] close restores normal width + unlocks page scroll`, await dp.locator('.drawer.drawer-xl').count() === 0 && !(await dp.evaluate(() => document.body.classList.contains('jejak-scroll-lock') || document.documentElement.classList.contains('jejak-scroll-lock'))));
        await dp.close();
        await dctx.close();
        await deviceBrowser.close();
    }

    // ================================================== global assertions
    const writes = requests.filter((r) => r.method !== 'GET');
    check('NO POST/PUT/PATCH/DELETE request was made by anything in this whole run (Jejak is read-only)', writes.length === 0, JSON.stringify(writes));
    check('every Jejak request was the single read-only GET endpoint', requests.filter((r) => r.path.includes('/jejak')).every((r) => r.method === 'GET'));
    const unexpected = consoleErrors.filter((e) => !/favicon|403 \(Forbidden\)|Failed to load resource/i.test(e));
    check('zero unexpected console errors', unexpected.length === 0, unexpected.join(' | '));

    // ---- database untouched by the whole run
    const adj = Number(sh(`mysql -uroot ${dbName} -N -e "SELECT COUNT(*) FROM stock_adjustments"`).trim());
    check('database: still exactly the 3 fixture adjustments (nothing posted during the run)', adj === 3, String(adj));
} finally {
    await stopServer();
    if (browser) await browser.close();
}

const pass = results.filter(Boolean).length;
console.log(`\n${pass}/${results.length} checks passed.`);
process.exit(results.every(Boolean) ? 0 : 1);
