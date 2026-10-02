// PHASE V2.16.4 — "Laporan Stock Opname" browser smoke, against the real
// backend (reuses tests/browser/seed_v2164.php's fixtures). Covers the
// UI-visible points of the spec's 14-point checklist that the PHP service/
// HTTP test (tests/inventory_v2_16_4_stock_opname_report_test.php) cannot:
//   1. submenu tampil (Proses Stock Opname + Laporan Stock Opname both show)
//   2. Proses Stock Opname tetap bekerja (existing admin screen unaffected)
//   3. Laporan Stock Opname bisa dibuka
//   4. filter bekerja
//   6. detail report tampil
//   7. pagination bekerja (pager controls render)
//  11/12. Print/PDF + Excel Final buttons open without a JS error (SUPERADMIN)
//  13. kolom HPP TIDAK ADA anywhere in the rendered report (session list OR detail)
//  14. permission existing tidak diregresikan (VIEWER sees NO export/print buttons)
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v2164.mjs
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
const seed = JSON.parse(sh(`php tests/browser/seed_v2164.php`));
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, session_number: seed.session_number }));

const port = 8900 + Math.floor(Math.random() * 300) + 5700;
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: repoRoot });
let serverLog = '';
server.stdout.on('data', (d) => { serverLog += d.toString(); });
server.stderr.on('data', (d) => { serverLog += d.toString(); });
{
    let ready = false;
    for (let i = 0; i < 50 && !ready; i++) {
        await new Promise((resolve) => setTimeout(resolve, 200));
        try {
            const res = await fetch(`${base}/api/auth/me`);
            if (res.status) ready = true;
        } catch (e) { /* keep polling */ }
    }
    if (!ready) { console.error('Server did not become ready:\n' + serverLog); process.exit(1); }
}

async function login(browser, creds, viewport) {
    const context = await browser.newContext({ viewport: viewport || { width: 1366, height: 900 } });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    await page.waitForTimeout(400);
    return { context, page, pageErrors };
}

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // SUPERADMIN: submenu, Proses Stock Opname, Laporan Stock Opname,
    // filters, detail, pagination, HPP absence, export/print buttons.
    // ============================================================
    {
        const { context, page, pageErrors } = await login(browser, seed.admin);

        // 1. submenu tampil — both links present under the Stock Opname group
        // (the submenu is a collapsible accordion, hidden until its header
        // is clicked — same convention tests/browser/playwright_v2161.mjs uses).
        await page.click('.sidebar-group[data-group="opname"] .sidebar-group-header');
        await page.waitForTimeout(200);
        const prosesLink = page.locator('.sidebar-link[data-tab="opname"]');
        const laporanLink = page.locator('.sidebar-link[data-tab="opname-laporan"]');
        check('1. "Proses Stock Opname" sidebar link is present', await prosesLink.count() > 0);
        check('1b. "Laporan Stock Opname" sidebar link is present', await laporanLink.count() > 0);
        check('1c. "Proses Stock Opname" link text is correctly relabeled', (await prosesLink.textContent()).includes('Proses Stock Opname'));

        // 2. Proses Stock Opname tetap bekerja — existing admin screen renders.
        await prosesLink.click();
        await page.waitForTimeout(500);
        const prosesTabVisible = await page.locator('#tab-opname.active').count() > 0;
        check('2. Proses Stock Opname tab activates and renders (existing admin screen unaffected)', prosesTabVisible);
        const prosesHasContent = (await page.locator('#tab-opname').innerText()).trim().length > 0;
        check('2b. Proses Stock Opname tab has real rendered content (not blank)', prosesHasContent);

        // 3. Laporan Stock Opname bisa dibuka.
        await laporanLink.click();
        await page.waitForTimeout(600);
        const laporanTabVisible = await page.locator('#tab-opname-laporan.active').count() > 0;
        check('3. Laporan Stock Opname tab activates and renders', laporanTabVisible);
        const sessionRow = page.locator('.so-report-table tbody tr', { hasText: seed.session_number });
        check('3b. the fixture POSTED session appears in the session list', await sessionRow.count() > 0);

        // 4. filter bekerja — search by the fixture's own SO number narrows the list to 1 row.
        await page.fill('.hpp-filter-card input[placeholder="No. SO"]', seed.session_number);
        await page.click('.hpp-filter-card button:has-text("Terapkan Filter")');
        await page.waitForTimeout(500);
        const rowsAfterFilter = await page.locator('.so-report-table tbody tr').count();
        check('4. filter (session search) narrows the session list to exactly 1 matching row', rowsAfterFilter === 1, String(rowsAfterFilter));

        // 6. detail report tampil.
        await page.click('.so-report-table tbody tr button:has-text("Lihat Detail")');
        await page.waitForTimeout(600);
        const detailHeaderVisible = await page.locator(`.hpp-title:has-text("${seed.session_number}")`).count() > 0;
        check('6. detail report opens and shows the session header', detailHeaderVisible);
        const financeSummaryVisible = await page.locator('.hpp-kpi-row').count() > 0;
        check('6b. finance summary KPI cards render', financeSummaryVisible);
        const categorySummaryVisible = await page.locator('h3:has-text("Ringkasan Kategori")').count() > 0;
        check('6c. category summary table renders', categorySummaryVisible);
        const itemRows = await page.locator('.so-report-table tbody tr').count();
        check('6d. item detail table renders with both fixture items', itemRows >= 2, String(itemRows));

        // 7. pagination bekerja — pager controls (per-page select + prev/next) render.
        const pagerVisible = await page.locator('.so-report-pager').count() > 0;
        check('7. pagination controls render on the detail item table', pagerVisible);
        const perPageOptions = await page.locator('.so-report-pager select option').allTextContents();
        check('7b. pagination offers 25/50/100 per-page options', perPageOptions.some((t) => t.includes('25')) && perPageOptions.some((t) => t.includes('50')) && perPageOptions.some((t) => t.includes('100')));

        // 13. kolom HPP TIDAK ADA — scan the ENTIRE rendered detail page text.
        const detailPageText = await page.locator('#tab-opname-laporan').innerText();
        const hppLeak = /\bHPP\b|Unit Cost|Harga Pokok/i.test(detailPageText);
        check('13. NO "HPP" / "Unit Cost" / "Harga Pokok" text anywhere in the rendered report', !hppLeak, hppLeak ? 'LEAK DETECTED' : 'clean');

        // 11/12. Print/PDF + Excel Final buttons are visible for a privileged
        // user and open a new tab without crashing the page (no JS error).
        const printBtn = page.locator('button:has-text("Print / PDF")').first();
        check('11. Print/PDF button is visible for SUPERADMIN', await printBtn.count() > 0);
        const [printPopup] = await Promise.all([context.waitForEvent('page'), printBtn.click()]);
        await printPopup.waitForLoadState('load').catch(() => {});
        check('11b. Print/PDF opens a new tab (window.open to the reused print route)', printPopup !== undefined);
        await printPopup.close();

        const excelBtn = page.locator('button:has-text("Excel Final")').first();
        check('12. Excel Final export button is visible for SUPERADMIN', await excelBtn.count() > 0);

        check('no uncaught JS errors on this entire flow (SUPERADMIN)', pageErrors.length === 0, JSON.stringify(pageErrors));
        await context.close();
    }

    // ============================================================
    // VIEWER (INVENTORY_VIEW only): report still reachable, export/print
    // buttons are hidden (cosmetic — backend already 403s them too, see
    // the PHP HTTP test), and still zero HPP leakage.
    // ============================================================
    {
        const { context, page, pageErrors } = await login(browser, seed.viewer);

        await page.click('.sidebar-group[data-group="opname"] .sidebar-group-header');
        await page.waitForTimeout(200);
        const laporanLink = page.locator('.sidebar-link[data-tab="opname-laporan"]');
        check('14. VIEWER (INVENTORY_VIEW only) still sees the "Laporan Stock Opname" link', await laporanLink.count() > 0);
        await laporanLink.click();
        await page.waitForTimeout(600);
        const sessionRow = page.locator('.so-report-table tbody tr', { hasText: seed.session_number });
        check('14b. VIEWER can see the POSTED session in the list', await sessionRow.count() > 0);

        await page.click('.so-report-table tbody tr button:has-text("Lihat Detail")');
        await page.waitForTimeout(600);
        const viewerDetailVisible = await page.locator(`.hpp-title:has-text("${seed.session_number}")`).count() > 0;
        check('14c. VIEWER can open the detail report (INVENTORY_VIEW is sufficient)', viewerDetailVisible);

        const viewerExportButtons = await page.locator('button:has-text("Excel Final"), button:has-text("Rekonsiliasi Final"), button:has-text("Print / PDF")').count();
        check('14d. VIEWER does NOT see the Excel Final/Rekonsiliasi Final/Print buttons (no STOCK_OPNAME_MANAGE/SUPERVISE)', viewerExportButtons === 0, String(viewerExportButtons));

        const viewerPageText = await page.locator('#tab-opname-laporan').innerText();
        const viewerHppLeak = /\bHPP\b|Unit Cost|Harga Pokok/i.test(viewerPageText);
        check('13b. NO HPP text leak for VIEWER either', !viewerHppLeak);

        check('no uncaught JS errors on this entire flow (VIEWER)', pageErrors.length === 0, JSON.stringify(pageErrors));
        await context.close();
    }
} finally {
    if (browser) await browser.close();
    server.kill();
}

const passed = results.filter(Boolean).length;
console.log(`\n${passed} / ${results.length} PASSED`);
process.exit(passed === results.length ? 0 : 1);
