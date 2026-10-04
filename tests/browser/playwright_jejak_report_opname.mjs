// "Jejak Stock Opname" — row-click integration + KPI drill-down, tested against
// the REAL ACTIVE production frontend path: public/assets/js/report-opname.js
// (sidebar "Laporan P1/P2 Stock Opname", tab laporan-opname), NOT the
// dev-only stock-opname-report.js.
//
// The real, unmodified frontend files in public/ are served statically and
// run in real Chromium; ONLY the /api backend is mocked (page.route), because
// this container has no MySQL. That is enough here: the Jejak drawer is
// 100% simulated data by design, and the only real endpoints the page uses
// are the session list (GET /api/reports/opname) and the TraceDrawer
// (GET /api/trace/opname/:id) behind "Lihat Detail". Every request is
// recorded so the test can prove no write/posting call is ever made.
//
// Usage:
//   node tests/browser/playwright_jejak_report_opname.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.resolve(__dirname, '..', '..', 'public');
const shotDir = process.env.JEJAK_SHOT_DIR || __dirname;

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

// ---- static server for public/ ----
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const server = http.createServer((req, res) => {
    const urlPath = decodeURIComponent(req.url.split('?')[0]);
    const file = path.join(publicDir, urlPath === '/' ? 'index.html' : urlPath);
    if (!file.startsWith(publicDir) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('nf'); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

// ---- mocked backend ----
const SESSIONS = [
    { id: 12, session_number: 'SO-20260930-0012', session_date: '2026-09-30', warehouse: { id: 1, name: 'Gudang Cibadak' }, status: 'POSTED', p1: 'Dewi', p2: 'Arman', supervisor: 'Andi SPV', match_count: 96, mismatch_count: 18, variance_value: -128500, created_by: 'superadmin', finalized_at: '2026-10-01 10:15:00' },
    { id: 13, session_number: null, session_date: '2026-09-29', warehouse: { id: 1, name: 'Gudang Cibadak' }, status: 'OPEN', p1: null, p2: null, supervisor: null, match_count: 0, mismatch_count: 0, variance_value: 0, created_by: 'superadmin', finalized_at: null },
];
const requests = [];
function ok(data) { return { status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data, message: '' }) }; }
async function mockApi(route) {
    const req = route.request();
    const url = new URL(req.url());
    const p = url.pathname.replace(/^\/api/, '');
    requests.push({ method: req.method(), path: p });
    if (p === '/auth/me') return route.fulfill(ok({ id: 1, username: 'qa-admin', role_code: 'SUPERADMIN', permissions: ['INVENTORY_VIEW', 'STOCK_OPNAME_MANAGE', 'STOCK_OPNAME_SUPERVISE'], csrf_token: 'tok', must_change_password: false }));
    if (p === '/warehouses') return route.fulfill(ok([{ id: 1, code: 'CBD', name: 'Gudang Cibadak', warehouse_type: 'MAIN', is_active: 1 }]));
    if (p === '/reports/opname/export') return route.fulfill({ status: 200, contentType: 'text/csv', body: 'a,b\n1,2\n' });
    if (p === '/reports/opname') return route.fulfill(ok({ rows: SESSIONS, pagination: { page: 1, per_page: 25, total: SESSIONS.length, total_pages: 1 } }));
    if (p === '/trace/opname/12') {
        return route.fulfill(ok({
            session: { warehouse_code: 'CBD', warehouse_name: 'Gudang Cibadak', status: 'POSTED', created_by_username: 'superadmin', finalized_by_username: 'rina', posted_by_username: 'fajar', cancelled_by_username: null },
            lines: [], audit_events: [],
        }));
    }
    return route.fulfill(ok([])); // every other master list: empty
}

const consoleErrors = [];
let browser;
try {
    browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.route('**/api/**', mockApi);
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push(String(e)));

    // ============================================================
    // 1 — active page loads (laporan-opname → report-opname.js)
    // ============================================================
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    const scripts = await page.evaluate(() => Array.from(document.scripts).map((s) => s.getAttribute('src') || ''));
    check('index.html loads the ACTIVE report-opname.js', scripts.some((s) => s.includes('assets/js/report-opname.js')));
    check('index.html loads stock-opname-report-jejak.js', scripts.some((s) => s.includes('assets/js/stock-opname-report-jejak.js')));
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-opname"]').click());
    await page.waitForSelector('#tab-laporan-opname.active', { timeout: 5000 });
    await page.waitForSelector('#tab-laporan-opname tbody tr td:not(.dt-empty)', { timeout: 5000 });
    const rowCount = await page.locator('#tab-laporan-opname tbody tr').count();
    check('Laporan Stock Opname page (report-opname.js) loads with session rows', rowCount === 2, `rows=${rowCount}`);

    const row12 = page.locator('#tab-laporan-opname tbody tr', { hasText: 'SO-20260930-0012' });
    const detailBtn = row12.locator('button', { hasText: 'Lihat Detail' });
    check('"Lihat Detail" action button present on the row', await detailBtn.count() === 1);

    // ============================================================
    // 2 — row click opens Jejak
    // ============================================================
    const before = requests.length;
    await row12.locator('td').first().click();
    await page.waitForSelector('.drawer.open', { timeout: 5000 });
    const title = (await page.locator('.drawer-title').first().textContent() || '').trim();
    check('Row click opens Jejak drawer', title.includes('Jejak Stock Opname #12'), title);
    check('Jejak header carries the PREVIEW badge', await page.locator('[data-testid="jejak-preview-badge"]').isVisible());
    check('Drawer is widened (.drawer-xl)', await page.locator('.drawer.drawer-xl.open').count() === 1);
    check('Row click made no API request (pure preview, no trace fetch)', requests.slice(before).length === 0, JSON.stringify(requests.slice(before)));

    // ============================================================
    // 3 — tabs: order, default, all work
    // ============================================================
    const tabLabels = (await page.locator('.drawer-tab').allTextContents()).map((t) => t.trim());
    check('Tab order is Overview | Per Barang | Rekonsiliasi | Audit', tabLabels.join('|') === 'Overview|Per Barang|Rekonsiliasi|Audit', tabLabels.join('|'));
    check('Per Barang is the default active tab', (await page.locator('.drawer-tab.active').textContent() || '').trim() === 'Per Barang');
    check('Per Barang renders items table', await page.locator('[data-testid="jejak-items-table"] tbody tr').count() === 11);
    check('Compact session info grid renders', await page.locator('.jejak-info-cell').count() === 9);

    // visual screenshot (dark theme) of default view
    await page.screenshot({ path: path.join(shotDir, 'screenshot_jejak_report_opname_drawer.png') });

    // ============================================================
    // 4 — KPI cards: all six clickable, correct breakdown, back to Jejak
    // ============================================================
    const KPIS = [
        { key: 'nilai_stok_sistem', title: 'Rincian Nilai Stok Sistem', cardValue: '125.460.000', cols: ['SKU', 'Nama Barang', 'Satuan', 'Qty Sistem', 'HPP (Rp)', 'Nilai Stok Sistem (Rp)'] },
        { key: 'nilai_final_count', title: 'Rincian Nilai Final Count', cardValue: '123.985.000', cols: ['SKU', 'Nama Barang', 'Satuan', 'Final Count', 'HPP (Rp)', 'Nilai Final Count (Rp)'] },
        { key: 'selisih_nominal', title: 'Rincian Selisih Nominal', cardValue: '1.475.000', cols: ['SKU', 'Nama Barang', 'Qty Sistem', 'Final Count', 'Selisih Qty', 'HPP (Rp)', 'Nilai Selisih (Rp)', 'Lebih/Kurang'] },
        { key: 'dead_stock', title: 'Rincian Dead Stock', cardValue: '640.000', cols: ['SKU', 'Nama Barang', 'Dead Stock Qty', 'HPP (Rp)', 'Nilai Dead Stock (Rp)', 'Catatan'] },
        { key: 'rusak', title: 'Rincian Rusak', cardValue: '225.000', cols: ['SKU', 'Nama Barang', 'Rusak Qty', 'HPP (Rp)', 'Nilai Rusak (Rp)', 'Catatan'] },
        { key: 'adjustment_bersih', title: 'Rincian Adjustment Bersih', cardValue: '610.000', cols: ['SKU', 'Nama Barang', 'Jenis Adjustment', 'Qty Adjustment', 'HPP (Rp)', 'Nilai Adjustment (Rp)', 'Kontribusi'] },
    ];
    check('6 KPI cards render', await page.locator('.drawer-body .jejak-kpi').count() === 6);
    check('KPI cards are keyboard/AT-exposed buttons', await page.locator('.drawer-body .jejak-kpi[role="button"][tabindex="0"]').count() === 6);

    // hover state
    const firstCard = page.locator('[data-testid="jejak-kpi-nilai_stok_sistem"]');
    const bgBefore = await firstCard.evaluate((e) => getComputedStyle(e).backgroundColor);
    await firstCard.hover();
    await page.waitForTimeout(250);
    const bgHover = await firstCard.evaluate((e) => getComputedStyle(e).backgroundColor);
    const cursor = await firstCard.evaluate((e) => getComputedStyle(e).cursor);
    check('KPI card has pointer cursor + distinct hover background', cursor === 'pointer' && bgHover !== bgBefore, `${cursor} ${bgBefore} -> ${bgHover}`);

    let shotTaken = false;
    for (const k of KPIS) {
        await page.locator(`[data-testid="jejak-kpi-${k.key}"]`).click();
        await page.waitForSelector('[data-testid="jejak-drill-modal"]', { timeout: 3000 });
        const t = (await page.locator('[data-testid="jejak-drill-title"]').textContent() || '').trim();
        check(`[${k.key}] opens correct breakdown title`, t.includes(k.title), t);
        const headers = (await page.locator('[data-testid="jejak-drill-table"] thead th').allTextContents()).map((h) => h.trim());
        check(`[${k.key}] table columns match spec`, headers.join('|') === k.cols.join('|'), headers.join('|'));
        const summary = (await page.locator('[data-testid="jejak-drill-summary"]').textContent() || '');
        check(`[${k.key}] summary shows item count + total equal to KPI card`, /\d+ (SKU|baris adjustment)/.test(summary) && summary.replace(/ /g, ' ').includes(k.cardValue), summary.trim());
        check(`[${k.key}] has a TOTAL row`, (await page.locator('[data-testid="jejak-drill-total-row"]').textContent() || '').includes('TOTAL'));
        const totalCell = (await page.locator('[data-testid="jejak-drill-total-row"]').textContent() || '');
        check(`[${k.key}] TOTAL row nominal equals KPI card value`, totalCell.includes(k.cardValue), totalCell.trim());
        check(`[${k.key}] Jejak drawer still open underneath`, await page.locator('.drawer.open').count() === 1);
        const onTop = await page.evaluate(() => {
            const m = document.querySelector('[data-testid="jejak-drill-modal"]').getBoundingClientRect();
            const hit = document.elementFromPoint(m.left + m.width / 2, m.top + 40);
            return !!hit.closest('[data-testid="jejak-drill-modal"]');
        });
        check(`[${k.key}] modal is stacked above the drawer`, onTop);
        if (!shotTaken) { await page.screenshot({ path: path.join(shotDir, 'screenshot_jejak_kpi_drilldown.png') }); shotTaken = true; }
        await page.click('[data-testid="jejak-drill-close"]');
        await page.waitForTimeout(100);
        check(`[${k.key}] ✕ closes modal, back on Jejak`, await page.locator('[data-testid="jejak-drill-modal"]').count() === 0 && await page.locator('.drawer.open').count() === 1);
    }

    // other close paths
    await page.locator('[data-testid="jejak-kpi-rusak"]').click();
    await page.click('[data-testid="jejak-drill-back"]');
    check('"Kembali ke Jejak" closes modal only', await page.locator('[data-testid="jejak-drill-modal"]').count() === 0 && await page.locator('.drawer.open').count() === 1);
    await page.locator('[data-testid="jejak-kpi-rusak"]').click();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    check('Escape closes the modal but NOT the Jejak drawer', await page.locator('[data-testid="jejak-drill-modal"]').count() === 0 && await page.locator('.drawer.open').count() === 1);
    await page.locator('[data-testid="jejak-kpi-rusak"]').click();
    await page.mouse.click(5, 5);
    await page.waitForTimeout(150);
    check('Click on overlay outside the modal closes only the modal', await page.locator('[data-testid="jejak-drill-modal"]').count() === 0 && await page.locator('.drawer.open').count() === 1);
    await page.locator('[data-testid="jejak-kpi-dead_stock"]').focus();
    await page.keyboard.press('Enter');
    check('KPI card opens via keyboard (Enter)', await page.locator('[data-testid="jejak-drill-modal"]').count() === 1);
    await page.keyboard.press('Escape');
    check('Focus returns to the KPI card after closing', await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-testid')) === 'jejak-kpi-dead_stock');

    // drill-down search / filter
    await page.locator('[data-testid="jejak-kpi-selisih_nominal"]').click();
    const rowsAll = await page.locator('[data-testid="jejak-drill-table"] tbody tr').count();
    await page.selectOption('[data-testid="jejak-drill-filter"]', 'Lebih');
    const rowsLebih = await page.locator('[data-testid="jejak-drill-table"] tbody tr').count();
    check('Selisih filter "Lebih" narrows rows (and drops the non-itemised row)', rowsLebih === 3 && rowsAll === 10, `all=${rowsAll} lebih=${rowsLebih}`); // BRD-002, MD-001 + total
    check('Filtered TOTAL row is labelled terfilter', (await page.locator('[data-testid="jejak-drill-total-row"]').textContent() || '').includes('TOTAL (terfilter)'));
    await page.selectOption('[data-testid="jejak-drill-filter"]', '');
    await page.fill('[data-testid="jejak-drill-search"]', 'cookies');
    check('Drill-down search by name works', await page.locator('[data-testid="jejak-drill-table"] tbody tr').count() === 2); // CK-002 + total
    await page.fill('[data-testid="jejak-drill-search"]', 'zzz-none');
    check('Drill-down search with no match shows empty message', (await page.locator('[data-testid="jejak-drill-table"]').textContent() || '').includes('Tidak ada baris yang cocok'));
    await page.click('[data-testid="jejak-drill-close"]');

    await page.locator('[data-testid="jejak-kpi-adjustment_bersih"]').click();
    await page.selectOption('[data-testid="jejak-drill-filter"]', 'Mengurangi');
    const adjText = (await page.locator('[data-testid="jejak-drill-table"] tbody').textContent() || '');
    check('Adjustment filter "Mengurangi" shows only negative contributions', adjText.includes('Mengurangi') && !adjText.includes('Menambah (+)'));
    await page.click('[data-testid="jejak-drill-close"]');

    // KPI cards also work from the other tabs
    await page.click('.drawer-tab:has-text("Overview")');
    await page.locator('.drawer-body [data-testid="jejak-kpi-dead_stock"]').click();
    check('KPI drill-down also opens from the Overview tab', (await page.locator('[data-testid="jejak-drill-title"]').textContent() || '').includes('Dead Stock'));
    await page.click('[data-testid="jejak-drill-close"]');
    await page.click('.drawer-tab:has-text("Rekonsiliasi")');
    check('Rekonsiliasi tab renders', await page.isVisible('.drawer-body >> text=Mockup — tab Rekonsiliasi'));
    await page.click('.drawer-tab:has-text("Audit")');
    check('Audit tab renders', await page.isVisible('.drawer-section-title >> text=Jejak Audit'));
    await page.click('.drawer-tab:has-text("Per Barang")');

    // ============================================================
    // 5 — table columns reachable by horizontal scroll; footer reachable
    // ============================================================
    await page.setViewportSize({ width: 1000, height: 760 });
    await page.waitForTimeout(200);
    const scroll = await page.locator('[data-testid="jejak-items-table-wrap"]').evaluate((w) => ({ sw: w.scrollWidth, cw: w.clientWidth, ox: getComputedStyle(w).overflowX }));
    check('Per Barang table scrolls horizontally when narrower than its columns', scroll.sw > scroll.cw && ['auto', 'scroll'].includes(scroll.ox), JSON.stringify(scroll));
    const wanted = ['Dead Stock Qty', 'Rusak Qty', 'HPP (Rp)', 'Nilai Selisih (Rp)', 'Nilai Dead Stock (Rp)', 'Nilai Rusak (Rp)'];
    const headerTexts = (await page.locator('[data-testid="jejak-items-table"] thead th').allTextContents()).map((h) => h.trim());
    check('All required numeric columns exist', wanted.every((w) => headerTexts.includes(w)), headerTexts.join('|'));
    const reachable = [];
    for (const w of wanted) {
        const th = page.locator('[data-testid="jejak-items-table"] thead th', { hasText: w }).first();
        await th.scrollIntoViewIfNeeded();
        const ok2 = await page.evaluate((txt) => {
            const wrap = document.querySelector('[data-testid="jejak-items-table-wrap"]').getBoundingClientRect();
            const ths = Array.from(document.querySelectorAll('[data-testid="jejak-items-table"] thead th'));
            const r = ths.find((t) => t.textContent.trim() === txt).getBoundingClientRect();
            return r.left >= wrap.left - 1 && r.right <= wrap.right + 1;
        }, w);
        reachable.push(ok2);
    }
    check('Each required column can be scrolled fully into view', reachable.every(Boolean), reachable.join(','));

    const footerVisible = await page.evaluate(() => {
        const a = document.querySelector('[data-testid="jejak-actions"]').getBoundingClientRect();
        return a.top >= 0 && a.bottom <= window.innerHeight + 1;
    });
    check('Action buttons stay in view without scrolling (sticky footer)', footerVisible);
    await page.evaluate(() => { const b = document.querySelector('.drawer-body'); b.scrollTop = b.scrollHeight; });
    await page.waitForTimeout(100);
    const bottomVisible = await page.evaluate(() => {
        const cards = Array.from(document.querySelectorAll('.drawer-body .hpp-kpi-row')).pop().getBoundingClientRect();
        const a = document.querySelector('[data-testid="jejak-actions"]').getBoundingClientRect();
        return cards.bottom <= a.top + 1 && cards.top >= 0;
    });
    check('Bottom summary reachable by scrolling and not hidden behind the footer', bottomVisible);
    await page.setViewportSize({ width: 1440, height: 900 });

    // ============================================================
    // 6 — Posting Adjustment stays disabled; nothing posts
    // ============================================================
    const postBtn = page.locator('.drawer-body button:has-text("Posting Adjustment")');
    check('Posting Adjustment is disabled and marked Preview', await postBtn.isDisabled() && (await postBtn.textContent() || '').includes('(Preview)'));
    const reqBeforePost = requests.length;
    await postBtn.click({ force: true, timeout: 1500 }).catch(() => { /* disabled: expected */ });
    await page.click('.drawer-body button:has-text("Cetak Laporan")');
    await page.click('.drawer-body button:has-text("Export Excel")');
    await page.waitForTimeout(300);
    check('Posting/Cetak/Export in preview fire no network request', requests.length === reqBeforePost, JSON.stringify(requests.slice(reqBeforePost)));
    check('Whole Jejak session made no non-GET request and no posting/adjustment call',
        requests.every((r) => r.method === 'GET') && !requests.some((r) => /post|adjust/i.test(r.path)),
        JSON.stringify(requests.filter((r) => r.method !== 'GET')));

    // ============================================================
    // 7 — close drawer; existing Lihat Detail / Export unaffected
    // ============================================================
    await page.click('.drawer-close');
    await page.waitForTimeout(300);
    check('Drawer close button works', await page.locator('.drawer.open').count() === 0);
    check('Closing the drawer removes the .drawer-xl modifier (shared drawer unaffected)', await page.locator('.drawer.drawer-xl').count() === 0);

    await detailBtn.click();
    await page.waitForSelector('.drawer.open', { timeout: 3000 });
    await page.waitForFunction(() => document.querySelector('.drawer-title') && document.querySelector('.drawer-title').textContent.includes('#12'), null, { timeout: 3000 });
    check('"Lihat Detail" opens the REAL trace drawer (/trace/opname/12)', requests.some((r) => r.path === '/trace/opname/12'));
    check('"Lihat Detail" does NOT open Jejak (no preview badge)', await page.locator('[data-testid="jejak-preview-badge"]').count() === 0 || !(await page.locator('[data-testid="jejak-preview-badge"]').isVisible()));
    check('Real trace drawer keeps normal width (not .drawer-xl)', await page.locator('.drawer.drawer-xl').count() === 0);
    const traceTabs = (await page.locator('.drawer-tab').allTextContents()).map((t) => t.trim());
    check('Real trace drawer shows its own tabs (Overview/Lines/Audit)', traceTabs.length === 3 && traceTabs[1].startsWith('Lines'), traceTabs.join('|'));
    await page.click('.drawer-close');
    await page.waitForTimeout(300);

    // Export CSV (toolbar action) must not open Jejak either
    const popupPromise = context.waitForEvent('page', { timeout: 3000 }).catch(() => null);
    await page.click('#rc-export-btn');
    const popup = await popupPromise;
    if (popup) await popup.close();
    await page.waitForTimeout(200);
    check('Export CSV does not open Jejak', await page.locator('.drawer.open').count() === 0);

    // second row (legacy session without session_number) still opens Jejak
    await page.locator('#tab-laporan-opname tbody tr').nth(1).locator('td').first().click();
    await page.waitForSelector('.drawer.open');
    const t2 = (await page.locator('.drawer-title').first().textContent() || '');
    check('Row for a legacy session (no session_number) opens Jejak with #id fallback and OPN-id number', t2.includes('Jejak Stock Opname #13') && t2.includes('OPN-13'), t2.trim());
    await page.click('.drawer-close');

    // ============================================================
    // 8 — console
    // ============================================================
    const unexpected = consoleErrors.filter((e) => !/favicon/i.test(e));
    check('Zero unexpected console errors', unexpected.length === 0, unexpected.join(' | '));
} finally {
    if (browser) await browser.close();
    server.close();
}

const pass = results.filter(Boolean).length;
console.log(`\n${pass}/${results.length} checks passed.`);
process.exit(results.every(Boolean) ? 0 : 1);
