// PHASE V2.16.3 — PRODUCTION SAFETY FIX regression: OPNAME_COUNTER must
// never trigger Master.loadAll() (and therefore never hit the
// INVENTORY_VIEW-gated GET /item-barcodes route), must land directly on
// "Stock Opname Saya", and counting must remain fully usable. ADMIN/
// SUPERADMIN must still load Master normally. Real browser against the
// real backend (reuses tests/browser/seed_v2161.php's admin+counter
// fixtures unchanged).
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v2163.mjs
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

// ---- fresh DB + seed (reuses the existing V2.16.1 admin+counter fixtures) ----
sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seed = JSON.parse(sh(`php tests/browser/seed_v2161.php`));
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, sku: seed.sku }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 5400;
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

// Master.loadAll()'s own exact endpoint set (services/api-client.js's
// listItems/listWarehouses/listSuppliers/listDivisions/listCategories/
// listBakeryDestinations/listItemBarcodes) — tracked by request URL
// path, ignoring query string.
const MASTER_LOAD_PATHS = ['/api/items', '/api/warehouses', '/api/suppliers', '/api/divisions', '/api/categories', '/api/bakery-destinations', '/api/item-barcodes'];

async function loginAndTrack(browser, creds, viewport) {
    const context = await browser.newContext({ viewport: viewport || { width: 1280, height: 900 } });
    const page = await context.newPage();
    const requestedPaths = [];
    const consoleErrors = [];
    page.on('request', (req) => {
        const url = new URL(req.url());
        if (MASTER_LOAD_PATHS.includes(url.pathname)) requestedPaths.push(url.pathname);
    });
    page.on('response', async (res) => {
        if (res.status() === 403) {
            try {
                const body = await res.json();
                consoleErrors.push(JSON.stringify(body));
            } catch (e) { /* non-JSON error body, ignore */ }
        }
    });
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    await page.waitForTimeout(600);
    return { context, page, requestedPaths, consoleErrors };
}

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // OPNAME_COUNTER: Master.loadAll() must NEVER run.
    // ============================================================
    {
        const { context, page, requestedPaths, consoleErrors } = await loginAndTrack(browser, seed.counter);

        check('1. OPNAME_COUNTER login never requests ANY Master.loadAll() endpoint (items/warehouses/suppliers/divisions/categories/bakery-destinations/item-barcodes)', requestedPaths.length === 0, JSON.stringify(requestedPaths));
        check('2. OPNAME_COUNTER login triggers ZERO 403 responses (never "Missing permission: INVENTORY_VIEW")', consoleErrors.length === 0, JSON.stringify(consoleErrors));
        const landedOnCounterScreen = (await page.locator('button.opname-next-item-btn').count() > 0)
            || (await page.locator('.opname-counter-panel').count() > 0)
            || (await page.locator('.card-title:has-text("Stock Opname Saya")').count() > 0);
        check('3. OPNAME_COUNTER lands directly on "Stock Opname Saya" (no manual navigation)', landedOnCounterScreen);

        // Counting must remain fully usable: claim -> qty input -> save.
        const nextItemBtn = page.locator('button.opname-next-item-btn');
        if (await nextItemBtn.count() > 0) {
            await nextItemBtn.click();
            await page.waitForTimeout(400);
            const qtyInput = page.locator('.opname-unit-input-row input').first();
            check('4. counter can still claim an item and see a GOOD qty input (counting remains usable)', await qtyInput.count() > 0);
            if (await qtyInput.count() > 0) {
                await qtyInput.fill('50');
                await page.click('button:has-text("Simpan Hitungan")');
                await page.waitForTimeout(800);
                check('5. counting save still succeeds with Master.loadAll() skipped (no error alert)', await page.locator('.alert-error').count() === 0);
            }
        } else {
            check('4. counter can still claim an item and see a GOOD qty input (counting remains usable)', false, 'next-item button not found');
        }

        // Re-confirm, after the full counting round trip, that no Master
        // endpoint was EVER requested during this entire session.
        check('6. still zero Master.loadAll() requests after a full claim/save round trip', requestedPaths.length === 0, JSON.stringify(requestedPaths));

        await context.close();
    }

    // ============================================================
    // ADMIN/SUPERADMIN: Master.loadAll() must still run normally.
    // ============================================================
    {
        const { context, requestedPaths } = await loginAndTrack(browser, seed.admin);
        const uniquePaths = [...new Set(requestedPaths)];
        check('7. ADMIN/SUPERADMIN login still requests Master.loadAll()\'s endpoints normally', uniquePaths.length >= 5, JSON.stringify(uniquePaths));
        check('7b. ADMIN/SUPERADMIN login specifically requests GET /items (core Master data)', requestedPaths.includes('/api/items'));
        await context.close();
    }
} finally {
    if (browser) await browser.close();
    server.kill();
}

const passed = results.filter(Boolean).length;
console.log(`\n${passed} / ${results.length} PASSED`);
process.exit(passed === results.length ? 0 : 1);
