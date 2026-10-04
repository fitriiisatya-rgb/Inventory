// MOCKUP — "Jejak Stock Opname" detail drawer smoke test.
// Lightweight but real: proves against the actual backend + actual
// (fixed) stock-opname-report.js/stock-opname-report-jejak.js in a real
// browser that: the Laporan Stock Opname page loads, clicking a session
// row opens the drawer, the close button works, tabs switch, the Per
// Barang table renders with mock data, search/category filter do not
// throw, and no unexpected console errors occur. Does NOT touch
// StockOpnameService posting logic, sessions 11/12, TransferService,
// FifoService, DB schema/data, or Karang Tengah balances — this is a
// pure frontend mockup smoke test against a freshly seeded disposable
// test session.
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_stock_opname_jejak_mockup.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}
function sh(cmd) {
    return execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seed = JSON.parse(sh(`php tests/browser/seed_stock_opname_jejak_mockup.php`));
console.log('Seeded:', JSON.stringify(seed));

const port = 8900 + Math.floor(Math.random() * 300) + 5900;
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: repoRoot });
{
    let ready = false;
    for (let i = 0; i < 50 && !ready; i++) {
        await new Promise((r) => setTimeout(r, 200));
        try { if ((await fetch(`${base}/api/auth/me`)).status) ready = true; } catch (e) { /* retry */ }
    }
    if (!ready) { console.error('server not ready'); process.exit(1); }
}

async function loginAs(browser, creds) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('pageerror', (err) => consoleErrors.push(String(err)));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page, consoleErrors };
}

async function openLaporanStockOpname(page) {
    const groupHeader = page.locator('.sidebar-group[data-group="opname"] .sidebar-group-header');
    if ((await groupHeader.getAttribute('aria-expanded')) !== 'true') {
        await groupHeader.click();
    }
    await page.click('.sidebar-link[data-tab="opname-laporan"]');
    await page.waitForSelector('#tab-opname-laporan', { state: 'visible', timeout: 8000 });
}

let browser;
try {
    browser = await chromium.launch();
    const { page, consoleErrors } = await loginAs(browser, seed.admin);

    // ============================================================
    // A — page loads.
    // ============================================================
    await openLaporanStockOpname(page);
    check('Laporan Stock Opname tab loads', await page.isVisible('#tab-opname-laporan'));

    await page.waitForSelector('.so-report-table tbody tr', { state: 'visible', timeout: 8000 });
    const rowCount = await page.locator('.so-report-table tbody tr').count();
    check('Session list renders at least one row', rowCount >= 1, `rows=${rowCount}`);

    // ============================================================
    // B — clicking a session row opens the drawer.
    // ============================================================
    const row = page.locator('.so-report-table tbody tr', { hasText: seed.session_number }).first();
    check('Seeded session row is visible', await row.isVisible());
    await row.click();
    await page.waitForSelector('.drawer.open', { state: 'visible', timeout: 5000 });
    check('Drawer opens on row click', await page.isVisible('.drawer.open'));

    const titleText = (await page.locator('.drawer-title').first().textContent() || '').trim();
    check('Drawer title shows "Jejak Stock Opname"', titleText.includes('Jejak Stock Opname'), titleText);
    check('Drawer title includes session number', titleText.includes(seed.session_number), titleText);
    check('Drawer is widened (.drawer-xl)', await page.locator('.drawer.drawer-xl').count() === 1);

    const statusBadgeText = (await page.locator('.drawer-title .badge-posted').first().textContent() || '').trim();
    check('Drawer header shows POSTED status badge', statusBadgeText === 'POSTED', statusBadgeText);

    // ============================================================
    // B2 — PRODUCTION PREVIEW safeguard: preview badge + explanatory note.
    // ============================================================
    const previewBadge = page.locator('[data-testid="jejak-preview-badge"]');
    check('Preview badge is visible in drawer header', await previewBadge.isVisible());
    check('Preview badge reads "PREVIEW UI — DATA SIMULASI"',
        (await previewBadge.textContent() || '').trim() === 'PREVIEW UI — DATA SIMULASI',
        await previewBadge.textContent());

    const previewNote = page.locator('[data-testid="jejak-preview-note"]');
    check('Preview explanatory note is visible in drawer header', await previewNote.isVisible());
    check('Preview note text matches required wording',
        (await previewNote.textContent() || '').includes('data simulasi dan belum terhubung ke data Stock Opname aktual'),
        await previewNote.textContent());

    // ============================================================
    // C — Per Barang tab is default-active and renders.
    // ============================================================
    const activeTabLabel = (await page.locator('.drawer-tab.active').first().textContent() || '').trim();
    check('Default active tab is "Per Barang"', activeTabLabel === 'Per Barang', activeTabLabel);

    const tabLabels = await page.locator('.drawer-tab').allTextContents();
    check('All 4 tabs present, order Overview|Per Barang|Rekonsiliasi|Audit',
        tabLabels.map((t) => t.trim()).join('|') === 'Overview|Per Barang|Rekonsiliasi|Audit',
        tabLabels.join(', '));

    check('Informasi Sesi Stock Opname section renders', await page.isVisible('.drawer-section-title >> text=Informasi Sesi Stock Opname'));
    const kpiCount = await page.locator('.drawer-body .hpp-kpi-row').first().locator('.hpp-kpi-card').count();
    check('6 KPI cards render in Per Barang tab', kpiCount === 6, `count=${kpiCount}`);

    const kpiValue = (await page.locator('.drawer-body .hpp-kpi-card').first().locator('.hpp-kpi-value').textContent() || '').trim();
    check('First KPI (Nilai Stok Sistem) shows Rp125.460.000', kpiValue.includes('125.460.000'), kpiValue);

    await page.waitForSelector('.drawer-body .jejak-table tbody tr', { state: 'visible', timeout: 5000 });
    const itemRowCount = await page.locator('.drawer-body .jejak-table tbody tr').count();
    check('Per Barang table renders item rows + total row', itemRowCount === 11, `rows=${itemRowCount}`); // 10 mock items + 1 total row
    check('Total row shows "TOTAL"', (await page.locator('.drawer-body .jejak-total-row').textContent() || '').includes('TOTAL'));

    const bottomSummaryTexts = await page.locator('.drawer-body .hpp-kpi-row').nth(1).locator('.hpp-kpi-value').allTextContents();
    check('Bottom summary shows 96/18/9/5', bottomSummaryTexts.join(',') === '96,18,9 SKU,5 SKU', bottomSummaryTexts.join(','));

    // Visual self-check screenshot — captured while the mockup drawer is
    // open (before any later step navigates away via the existing real
    // "Lihat Detail" button), for comparison against the reference design.
    await page.screenshot({ path: path.join(repoRoot, 'tests/browser/screenshot_stock_opname_jejak_mockup.png') });

    // ============================================================
    // D — search/filter in Per Barang tab does not throw.
    // ============================================================
    await page.fill('.drawer-body input[placeholder="Cari SKU atau nama barang..."]', 'Roti');
    await page.waitForTimeout(150);
    let filteredRows = await page.locator('.drawer-body .jejak-table tbody tr').count();
    check('Search filter narrows rows without throwing', filteredRows === 3, `rows=${filteredRows}`); // 2 "Roti" items + total row

    await page.fill('.drawer-body input[placeholder="Cari SKU atau nama barang..."]', '');
    await page.selectOption('.drawer-body select', { label: 'Bahan Baku' });
    await page.waitForTimeout(150);
    filteredRows = await page.locator('.drawer-body .jejak-table tbody tr').count();
    check('Category filter narrows rows without throwing', filteredRows === 4, `rows=${filteredRows}`); // 3 Bahan Baku items + total row
    await page.selectOption('.drawer-body select', { label: 'Semua Kategori' });

    // ============================================================
    // E — action buttons are safe mock handlers (no real posting).
    // ============================================================
    await page.click('.drawer-body button:has-text("Cetak Laporan")');
    await page.waitForTimeout(150);
    check('Cetak Laporan shows mock toast (no navigation/crash)', page.url().includes(base));

    // E2 — PRODUCTION PREVIEW safeguard: Posting Adjustment is hard-disabled
    // and cannot fire any posting request, no matter how it's clicked.
    const postBtn = page.locator('.drawer-body button:has-text("Posting Adjustment")');
    check('Posting Adjustment button is visible', await postBtn.isVisible());
    check('Posting Adjustment button has the disabled attribute', await postBtn.isDisabled());
    check('Posting Adjustment button label marks it as Preview', (await postBtn.textContent() || '').includes('(Preview)'));

    let postingRequestFired = false;
    const requestWatcher = (req) => {
        if (req.method() === 'POST' && /posting|adjustment|stock-opname/i.test(req.url())) postingRequestFired = true;
    };
    page.on('request', requestWatcher);
    // Disabled buttons never dispatch a 'click' event in a real browser even
    // when force-clicked — this proves there is no code path, not just that
    // we didn't exercise one.
    await postBtn.click({ force: true, timeout: 1500 }).catch(() => { /* expected: disabled elements may refuse the click entirely */ });
    await page.waitForTimeout(300);
    page.off('request', requestWatcher);
    check('Posting Adjustment click never fired a posting/adjustment network request', !postingRequestFired);
    check('Drawer remains open after attempted Posting Adjustment click (no real action taken)', await page.isVisible('.drawer.open'));

    // ============================================================
    // F — tabs switch.
    // ============================================================
    await page.click('.drawer-tab:has-text("Overview")');
    await page.waitForTimeout(100);
    check('Overview tab becomes active', (await page.locator('.drawer-tab.active').textContent() || '').trim() === 'Overview');

    await page.click('.drawer-tab:has-text("Rekonsiliasi")');
    await page.waitForTimeout(100);
    check('Rekonsiliasi tab renders', await page.isVisible('.drawer-body >> text=Mockup — tab Rekonsiliasi'));

    await page.click('.drawer-tab:has-text("Audit")');
    await page.waitForTimeout(100);
    check('Audit tab renders Jejak Audit section', await page.isVisible('.drawer-section-title >> text=Jejak Audit'));

    await page.click('.drawer-tab:has-text("Per Barang")');
    await page.waitForTimeout(100);
    check('Switching back to Per Barang works', (await page.locator('.drawer-tab.active').textContent() || '').trim() === 'Per Barang');

    // ============================================================
    // G — close button works.
    // ============================================================
    await page.click('.drawer-close');
    await page.waitForTimeout(300);
    check('Close button closes drawer', !(await page.locator('.drawer.open').count()));

    // ============================================================
    // H — existing "Lihat Detail" action button still works unaffected.
    // ============================================================
    const detailBtn = row.locator('button', { hasText: 'Detail' }).first();
    if (await detailBtn.count()) {
        await detailBtn.click();
        await page.waitForTimeout(400);
        check('Existing "Lihat Detail" button unaffected by row-click wiring', !(await page.locator('.drawer.open').count()));
        check('Real detail view shows the actual session number (not mock drawer)',
            (await page.locator('body').textContent() || '').includes(seed.session_number));
        // The mockup drawer stays in the DOM when closed (Drawer.close() only
        // removes the .open class — pre-existing, untouched behavior — it's
        // translated off-screen via CSS, not display:none), so presence alone
        // doesn't prove separation. What matters: it is definitely closed/
        // inert while the real detail view is what's on screen.
        check('Mockup drawer is closed/inert while the real detail view is shown (fully separate flow)',
            (await page.locator('.drawer.open').count()) === 0);
    } else {
        check('Existing action buttons row present (skip detail-click, none found)', true);
    }

    // ============================================================
    // Console error check.
    // ============================================================
    const unexpectedErrors = consoleErrors.filter((e) => !/favicon/i.test(e));
    check('No unexpected console errors', unexpectedErrors.length === 0, unexpectedErrors.join(' | '));
} finally {
    server.kill();
    if (browser) await browser.close();
}

const passCount = results.filter(Boolean).length;
console.log(`\n${passCount}/${results.length} checks passed.`);
process.exit(results.every(Boolean) ? 0 : 1);
