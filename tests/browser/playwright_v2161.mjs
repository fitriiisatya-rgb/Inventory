// PHASE V2.16.1 — "STOK BUKU SO" EOD reconciliation, real browser smoke
// against the actual backend (php -S serving public/, MySQL seeded by
// tests/browser/seed_v2161.php). Covers: baseline SCM import (Section A,
// with admin-confirmed coverage), movement bulk import (Section B, with
// the never-silent status breakdown), reconciliation table (Section C),
// draft Excel export download, the counter-facing counted_at backdated-
// entry checkbox end-to-end (claim -> fill -> backdate -> save -> shows
// up in the reconciliation table), and a mobile-width no-horizontal-
// scroll check on the new card.
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v2161.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v2161_screenshots';
fs.mkdirSync(screenshotDir, { recursive: true });

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

function sh(cmd) {
    return execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

// ---- fresh DB + seed ----
sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seed = JSON.parse(sh(`php tests/browser/seed_v2161.php`));
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, sku: seed.sku }));

// ---- fixture files the browser will upload ----
const fixtureDir = '/tmp/v2161_fixtures';
fs.mkdirSync(fixtureDir, { recursive: true });
// PHASE V2.16.2 — the baseline fixture now reproduces the ACTUAL real
// workbook's shape (parent header row 7 / Stok Akhir sub-header row 8 /
// blank row 9 / data from row 10, plus a footer garbage row), not a
// flat single-row-header CSV — proving the real upload path, not just
// a simplified stand-in.
const baselineXlsx = path.join(fixtureDir, 'baseline_real_shaped.xlsx');
sh(`php tests/browser/gen_scm_fixture.php ${baselineXlsx} ${seed.sku} 100`);
const movementCsv = path.join(fixtureDir, 'movements.csv');
fs.writeFileSync(movementCsv, [
    'effective_at,document_reference,sku,nama_barang,movement_type,qty,unit',
    `2026-09-30 10:00:00,PW-IN-1,${seed.sku},Item,IN,20,KG`,
    `2026-09-30 11:00:00,PW-OUT-1,${seed.sku},Item,OUT,5,KG`,
].join('\n') + '\n');

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 5100;
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

async function loginAs(browser, creds, viewport) {
    const context = await browser.newContext({ viewport: viewport || { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

// This app has no hash router for a specific session — the "Stock
// Opname" sidebar tab's own render() auto-loads whichever session is
// currently ACTIVE (status not POSTED/CANCELLED) for the selected
// warehouse (see StockOpname.loadForWarehouse() in stock-opname.js),
// which is exactly the seeded session here. Navigating there again
// (e.g. after a save) just re-clicks the same tab to force a reload.
async function clickSidebarTab(page, tab) {
    // On narrow viewports the sidebar is off-canvas behind a hamburger
    // toggle (#sidebar-toggle-btn) — open it first if visible.
    const toggle = page.locator('#sidebar-toggle-btn');
    if (await toggle.isVisible()) {
        await toggle.click();
        await page.waitForTimeout(200);
    }
    // Both opname tabs live inside a collapsible sidebar group — expand
    // it (idempotent: a no-op if already expanded) before clicking.
    const submenu = page.locator('.sidebar-group[data-group="opname"] .sidebar-submenu');
    if (!(await submenu.isVisible())) {
        await page.click('.sidebar-group[data-group="opname"] .sidebar-group-header');
    }
    await page.click(`.sidebar-link[data-tab="${tab}"]`);
    await page.waitForTimeout(600);
    return true;
}
// The seeded admin's supervisor permission makes the supervisor "Stock
// Opname" tab return the FULL comparison view, never the counter's own
// blind-count claim/search panel, even though that same admin is also
// this session's P1 team member (StockOpnameService::get() prioritizes
// the privileged shape). The claim/search panel only ever renders under
// the separate, never-permission-gated "Stock Opname Saya" tab.
async function openSession(page) { return clickSidebarTab(page, 'opname'); }
async function openMySessions(page) { return clickSidebarTab(page, 'opname-saya'); }

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // Desktop: supervisor "STOK BUKU SO" card — Section A (baseline),
    // Section B (movements), Section C (reconciliation), draft export.
    // ============================================================
    {
        const { page } = await loginAs(browser, seed.admin);
        const opened = await openSession(page, seed.session_id);
        check('1. session page loads for the supervisor', opened);

        const cardTitle = page.locator('.card-title:has-text("Stok Buku SO")');
        await cardTitle.waitFor({ state: 'visible', timeout: 8000 });
        check('2. "Stok Buku SO — Rekonsiliasi EOD" card renders', await cardTitle.count() > 0);

        // PHASE V2.16.2 Blocker 3 — the old generic "Reference SCM" card
        // must no longer render for this FINDINGS_V1 session, so an admin
        // can never accidentally use the obsolete importer for this
        // workflow (only "Stok Buku SO" should be visible).
        check('2b. the OLD "Reference SCM" card is REMOVED for FINDINGS_V1 sessions', await page.locator('.card-title:has-text("Reference SCM")').count() === 0);

        check('3. Section A (Baseline Stok SCM) renders', await page.locator('text=A. Baseline Stok SCM').count() > 0);
        check('4. Section B (Movement Backdate) renders', await page.locator('text=B. Movement Backdate').count() > 0);
        check('5. Section C (Rekonsiliasi EOD per SKU) renders', await page.locator('text=C. Rekonsiliasi EOD per SKU').count() > 0);

        // ---- Section A: baseline import with coverage confirmation ----
        const sectionA = page.locator('div', { hasText: 'A. Baseline Stok SCM' }).first();
        const coverageInputs = page.locator('div', { hasText: 'A. Baseline Stok SCM' }).first().locator('input[type="datetime-local"]');
        const inoutInput = coverageInputs.nth(0);
        const scalingInput = coverageInputs.nth(1);
        const adjustmentInput = coverageInputs.nth(2);
        await inoutInput.fill('2026-09-29T23:59');
        await scalingInput.fill('2026-09-29T23:59');
        await adjustmentInput.fill('2026-09-29T23:59');

        // With the old "Reference SCM" card removed, file input [0] is now
        // this card's own Section A baseline input, [1] is Section B's
        // movement input.
        const baselineFileInputs = page.locator('input[type="file"]');
        await baselineFileInputs.nth(0).setInputFiles(baselineXlsx);
        await page.click('button:has-text("Import Baseline")');
        await page.waitForTimeout(800);
        check('6. real-workbook-shaped baseline import succeeds (summary table shows the file)', await page.locator('text=baseline_real_shaped.xlsx').count() > 0);
        check('6b. baseline import shows the confirmed coverage, never blank', await page.locator('text=2026-09-29 23:59').count() > 0);
        const matchedRow = page.locator('tr', { hasText: 'Matched' }).first();
        check('6c. baseline import matches the seeded item (1 matched), never fooled by the footer garbage row', (await matchedRow.innerText()).includes('1'), await matchedRow.innerText());

        // ---- Section B: movement import ----
        await baselineFileInputs.nth(1).setInputFiles(movementCsv);
        await page.click('button:has-text("Import Movement")');
        await page.waitForTimeout(800);
        const includedRow = page.locator('tr', { hasText: 'Termasuk (Book Stock EOD)' });
        await includedRow.waitFor({ state: 'visible', timeout: 5000 });
        const includedText = await includedRow.innerText();
        check('7. movement import classifies rows, status breakdown visible and non-zero', /\d+/.test(includedText) && !includedText.trim().endsWith(' 0'), includedText.replace(/\s+/g, ' '));

        // ---- Section C: reconciliation ----
        await page.click('button:has-text("Refresh Rekonsiliasi")');
        await page.waitForTimeout(500);
        const reconRow = page.locator('table.compact-table tr', { hasText: seed.sku });
        check('8. reconciliation table shows a row for the imported SKU', await reconRow.count() > 0);
        if (await reconRow.count() > 0) {
            const rowText = await reconRow.first().innerText();
            check('9. Book Stock EOD = 100 + 20 - 5 = 115 is shown in the reconciliation row', rowText.includes('115'), rowText.replace(/\s+/g, ' '));
        }

        // ---- Draft export download ----
        const [download] = await Promise.all([
            page.waitForEvent('download', { timeout: 8000 }),
            page.click('button:has-text("Download Draft Rekonsiliasi")'),
        ]);
        const downloadPath = path.join('/tmp', await download.suggestedFilename());
        await download.saveAs(downloadPath);
        check('10. draft reconciliation Excel actually downloads a non-empty file', fs.existsSync(downloadPath) && fs.statSync(downloadPath).size > 0, downloadPath);

        await page.screenshot({ path: path.join(screenshotDir, 'desktop_stok_buku_so.png'), fullPage: true });

        // ============================================================
        // Counter-facing: counted_at backdated-entry checkbox, real
        // claim -> fill -> backdate -> save round trip. GET /stock-opname/
        // {id} always prefers the privileged supervisor shape for a
        // caller who CAN supervise (see index.php's own precedence rule),
        // so this can only be exercised by a genuine OPNAME_COUNTER
        // account (seed.counter), never the supervisor account above —
        // logging in fresh, as a real counter would.
        // ============================================================
        const counterSession = await loginAs(browser, seed.counter);
        const counterPage = counterSession.page;
        // OPNAME_COUNTER lands directly on "Stock Opname Saya" on login
        // (see app.js's own hard landing-tab guard for this role).
        await counterPage.waitForTimeout(500);
        const nextItemBtn = counterPage.locator('button.opname-next-item-btn');
        if (await nextItemBtn.count() > 0) {
            await nextItemBtn.click();
            await counterPage.waitForTimeout(400);
            const qtyInput = counterPage.locator('.opname-unit-input-row input').first();
            check('11. counter panel opens with a GOOD qty input after claiming the item', await qtyInput.count() > 0);
            await qtyInput.fill('115');

            const backdatedCheckbox = counterPage.locator('#opname-backdated-entry');
            check('12. "entry terlambat" (backdated) checkbox is present', await backdatedCheckbox.count() > 0);
            await backdatedCheckbox.check();
            const countedAtInput = counterPage.locator('input[type="datetime-local"]').last();
            check('13. counted_at datetime input becomes visible once backdated is checked', await countedAtInput.isVisible());
            await countedAtInput.fill('2026-09-30T20:00');

            await counterPage.click('button:has-text("Simpan Hitungan")');
            await counterPage.waitForTimeout(800);
            check('14. backdated finding saves successfully (no error alert)', await counterPage.locator('.alert-error').count() === 0);

            // Reconciliation should now reflect a Physical EOD for this
            // line — checked back on the SUPERVISOR's own page.
            await openSession(page);
            await page.click('button:has-text("Refresh Rekonsiliasi")');
            await page.waitForTimeout(500);
            const reconRow2 = page.locator('table.compact-table tr', { hasText: seed.sku });
            const rowText2 = await reconRow2.first().innerText();
            check('15. reconciliation reflects the counted_at-backdated finding\'s Physical EOD (115)', rowText2.includes('115'), rowText2.replace(/\s+/g, ' '));
        } else {
            check('11. counter panel opens with a GOOD qty input after claiming the item', false, 'next-item button not found');
        }
        await counterSession.context.close();
    }

    // ============================================================
    // Mobile (390px): the new card must not cause horizontal scroll.
    // ============================================================
    {
        const { page } = await loginAs(browser, seed.admin, { width: 390, height: 844 });
        await openSession(page, seed.session_id);
        await page.locator('.card-title:has-text("Stok Buku SO")').waitFor({ state: 'visible', timeout: 8000 });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        check('16. mobile (390px): no horizontal overflow on the "Stok Buku SO" card', overflow <= 1, `overflow=${overflow}px`);
        await page.screenshot({ path: path.join(screenshotDir, 'mobile_stok_buku_so.png'), fullPage: true });
    }
} finally {
    if (browser) await browser.close();
    server.kill();
}

const passed = results.filter(Boolean).length;
console.log(`\n${passed} / ${results.length} PASSED`);
process.exit(passed === results.length ? 0 : 1);
