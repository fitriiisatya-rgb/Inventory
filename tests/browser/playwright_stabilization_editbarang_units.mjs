// STABILIZATION — Edit Barang "No Options" unit-dropdown regression.
// Reproduces, deterministically against the REAL backend, the exact bug
// class reported in production: GET /units failing/returning empty
// INDEPENDENTLY of the CORE master lists that already let the screen
// open (Master.loadAll()'s own CORE/OPTIONAL split makes that possible
// by design — see master.js), and GET /items/{id}/units legitimately
// returning nothing beyond nothing at all for an item with no
// item_unit_conversions row.
//
// Covers:
//   1. GET /units fails on the page's initial Master.loadAll() (so
//      Master.units() starts empty) but succeeds on retry -> Edit Barang's
//      self-heal re-fetch recovers and Base Unit/Unit Conversion show
//      real options (not "No Options").
//   2. GET /units keeps failing even on retry -> Edit Barang shows a
//      VISIBLE error and never silently renders empty selects.
//   3. An item with literally zero item_unit_conversions rows (a data
//      gap, not a code bug) still shows its base unit in "Satuan untuk
//      Harga" (never zero options there either).
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_stabilization_editbarang_units.mjs
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
const seed = JSON.parse(sh(`php tests/browser/seed_editbarang_units.php`));
console.log('Seeded:', JSON.stringify(seed));

const port = 8900 + Math.floor(Math.random() * 300) + 5200;
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
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function openMasterBarangAndEdit(page, sku) {
    const groupHeader = page.locator('.sidebar-group[data-group="master-data"] .sidebar-group-header');
    if ((await groupHeader.getAttribute('aria-expanded')) !== 'true') {
        await groupHeader.click();
    }
    await page.click('.sidebar-link[data-tab="master-item"]');
    await page.waitForSelector('#tab-master-item', { state: 'visible', timeout: 8000 });
    const search = page.locator('#tab-master-item input[type="text"], #tab-master-item input[type="search"]').first();
    if (await search.count()) {
        await search.fill(sku);
        await page.waitForTimeout(600);
    }
    await page.locator(`#tab-master-item tr:has-text("${sku}")`).first().waitFor({ timeout: 8000 });
    await page.locator(`#tab-master-item tr:has-text("${sku}")`).first().click();
}

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // Scenario 1+2 — GET /units intercepted.
    // ============================================================
    {
        const { page } = await loginAs(browser, seed.admin);
        let unitsCallCount = 0;
        let failUnitsAlways = false;
        await page.route('**/api/units', async (route) => {
            unitsCallCount++;
            if (failUnitsAlways || unitsCallCount === 1) {
                return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'INTERNAL_ERROR', message: 'simulated GET /units failure' } }) });
            }
            return route.continue();
        });

        await page.reload();
        await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
        await page.waitForTimeout(500);

        const unitsEmptyAfterLoad = await page.evaluate(() => Master.units().length);
        check('1. initial Master.loadAll() with GET /units failing once leaves Master.units() empty (CORE/OPTIONAL split working as designed)', unitsEmptyAfterLoad === 0, `len=${unitsEmptyAfterLoad}`);

        await openMasterBarangAndEdit(page, seed.sku_normal);
        await page.waitForSelector('text=Edit Barang', { timeout: 8000 }).catch(() => {});
        const editBtn = page.locator('button:has-text("Edit Barang")').first();
        if (await editBtn.count()) await editBtn.click();
        await page.waitForTimeout(800);

        const baseUnitOptionCount = await page.locator('.modal select').first().locator('option').count().catch(() => 0);
        check('2. Edit Barang self-heals after GET /units\' one-time failure — Base Unit dropdown has real options, not "No Options"', baseUnitOptionCount > 1, `count=${baseUnitOptionCount}`);
        const unitsAfterRetry = await page.evaluate(() => Master.units().length);
        check('2b. Master.units() is populated after the self-heal retry', unitsAfterRetry > 0, `len=${unitsAfterRetry}`);

        // Close whatever modal is open, then force a PERMANENT failure and retry the scenario.
        const cancelBtn = page.locator('.modal.open button:has-text("Batal")');
        if (await cancelBtn.count()) await cancelBtn.first().click();
        await page.waitForSelector('.modal.open', { state: 'detached', timeout: 5000 }).catch(() => {});
        await page.keyboard.press('Escape').catch(() => {}); // closes the Detail drawer left open underneath
        await page.waitForSelector('.drawer-backdrop.open', { state: 'detached', timeout: 5000 }).catch(() => {});
        failUnitsAlways = true;
        await page.evaluate(async () => { await Master.loadAll(); }); // force Master.units() back to empty, GET /units now always fails
        const unitsForcedEmpty = await page.evaluate(() => Master.units().length);
        check('3. (setup) Master.units() forced back to empty for the permanent-failure scenario', unitsForcedEmpty === 0, `len=${unitsForcedEmpty}`);

        await openMasterBarangAndEdit(page, seed.sku_normal);
        const editBtn2 = page.locator('button:has-text("Edit Barang")').first();
        if (await editBtn2.count()) await editBtn2.click();
        await page.waitForTimeout(800);
        const toastText = await page.locator('.toast').first().innerText().catch(() => '');
        check('4. a PERMANENT GET /units failure shows a visible error, never a silently empty modal', /satuan|units|gagal/i.test(toastText), toastText);
        const modalOpenCount = await page.locator('.modal.open').count();
        check('4b. the Edit Barang modal never opens when units truly cannot be loaded (no empty-select dead end)', modalOpenCount === 0, `open modals=${modalOpenCount}`);

        await page.context().close();
    }

    // ============================================================
    // Scenario 3 — an item with zero item_unit_conversions rows still
    // shows its base unit in "Satuan untuk Harga".
    // ============================================================
    {
        const { page } = await loginAs(browser, seed.admin);
        await openMasterBarangAndEdit(page, seed.sku_no_conversions);
        const editBtn = page.locator('button:has-text("Edit Barang")').first();
        if (await editBtn.count()) await editBtn.click();
        await page.waitForTimeout(800);
        const selects = page.locator('.modal select');
        const selectCount = await selects.count();
        let priceUnitOptionCount = 0;
        if (selectCount > 0) {
            priceUnitOptionCount = await selects.last().locator('option').count();
        }
        check('5. an item with ZERO item_unit_conversions rows still shows its base unit under "Satuan untuk Harga" (never zero options)', priceUnitOptionCount >= 1, `count=${priceUnitOptionCount}`);
        await page.context().close();
    }

    await browser.close();
} catch (err) {
    console.error('FATAL:', err);
    if (browser) await browser.close().catch(() => {});
    server.kill();
    process.exit(1);
}

server.kill();
const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed > 0 ? 1 : 0);
