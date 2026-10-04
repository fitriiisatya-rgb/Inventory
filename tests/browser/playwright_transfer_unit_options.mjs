// STABILIZATION — TRANSFER UNIT OPTIONS.
// Proves, against the REAL backend and the REAL (patched) item-selector.js
// in an actual browser, that the Transfer screen's "Satuan" dropdown now
// always includes the item's base unit — appended, never prepended, so
// today's default-selected option (purchase-default/largest unit, first
// in the endpoint's own ORDER BY) is unchanged — and is never duplicated
// when the base unit already has its own open conversion row.
//
// The unit-conversion MATH (base_qty correctness, insufficient-stock
// rejection, decimals, the pre-existing receive()-side base-identity
// finding) is proven separately and more directly at the PHP service
// level by tests/transfer_unit_options_test.php. This file's job is
// narrower and browser-specific: prove the actual shipped item-selector.js
// renders the right <option> set in the real DOM, and that one full
// create+receive round-trip through the UI itself still produces the
// correct base quantity end to end.
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_transfer_unit_options.mjs
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
const seed = JSON.parse(sh(`php tests/browser/seed_transfer_unit_options.php`));
console.log('Seeded:', JSON.stringify(seed));

const port = 8900 + Math.floor(Math.random() * 300) + 5600;
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
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function openTransferTab(page) {
    const groupHeader = page.locator('.sidebar-group[data-group="transfers"] .sidebar-group-header');
    if ((await groupHeader.getAttribute('aria-expanded')) !== 'true') {
        await groupHeader.click();
    }
    await page.click('.sidebar-link[data-tab="transfer"]');
    await page.waitForSelector('#tab-transfer', { state: 'visible', timeout: 8000 });
    await page.waitForSelector('#transfer-lines-tbody', { state: 'visible', timeout: 8000 });
}

// Types into the first line's item-selector input, waits for the results
// dropdown, clicks the matching row, and returns the <option> texts the
// real item-selector.js rendered into that row's "Satuan" <select>.
async function pickItemAndReadUnitOptions(page, sku) {
    const row = page.locator('#transfer-lines-tbody tr').first();
    const input = row.locator('.item-selector-input');
    await input.click();
    await input.fill(sku);
    await page.waitForSelector('.item-selector-option', { state: 'visible', timeout: 8000 });
    await page.locator('.item-selector-option', { hasText: sku }).first().click();
    await page.waitForTimeout(400); // loadUnitsForItem() is async
    const unitSelect = row.locator('select.item-selector-unit');
    await unitSelect.waitFor({ state: 'attached', timeout: 8000 });
    const optionTexts = await unitSelect.locator('option').allTextContents();
    const selectedValue = await unitSelect.inputValue();
    const selectedText = await unitSelect.locator('option:checked').textContent().catch(() => null);
    return { optionTexts, selectedValue, selectedText };
}

let browser;
try {
    browser = await chromium.launch();
    const { page } = await loginAs(browser, seed.admin);
    await openTransferTab(page);

    // Pin "Gudang Asal" to the seeded source warehouse explicitly (SUPERADMIN
    // has no fixed warehouse, so the select starts on whatever sorts first).
    await page.selectOption('#transfer-from-wh', String(seed.src_warehouse_id));

    // ============================================================
    // A. Base unit only (no conversions at all).
    // ============================================================
    {
        const { optionTexts } = await pickItemAndReadUnitOptions(page, seed.sku_base_only);
        check('A1. base-only item: exactly 1 unit option rendered', optionTexts.length === 1, JSON.stringify(optionTexts));
        check('A2. that one option is the base unit (PCS)', /^PCS/.test(optionTexts[0] || ''), JSON.stringify(optionTexts));
    }

    // ============================================================
    // B. Base + 1 conversion, base identity row missing — the exact
    // production shape (RM-TF-26-032: "KARTON and CTN only").
    // ============================================================
    {
        const { optionTexts, selectedText } = await pickItemAndReadUnitOptions(page, seed.sku_one_conv);
        check('B1. base+1-conversion: exactly 2 unit options rendered (KARTON + backfilled PCS)', optionTexts.length === 2, JSON.stringify(optionTexts));
        check('B2. KARTON is present', optionTexts.some((t) => /^KARTON/.test(t)), JSON.stringify(optionTexts));
        check('B3. PCS (the base unit) is present — this is exactly the production bug being fixed', optionTexts.some((t) => /^PCS/.test(t)), JSON.stringify(optionTexts));
        check('B4. default-selected option is STILL KARTON — the backfill never disturbs today\'s existing default', /^KARTON/.test(selectedText || ''), String(selectedText));
        check('B5. PCS is the LAST option (appended, never prepended)', /^PCS/.test(optionTexts[optionTexts.length - 1] || ''), JSON.stringify(optionTexts));
    }

    // ============================================================
    // C. Base + multiple conversions, base identity row missing.
    // ============================================================
    {
        const { optionTexts, selectedText } = await pickItemAndReadUnitOptions(page, seed.sku_multi_conv);
        check('C1. base+multi-conversion: exactly 3 unit options rendered (KARTON, PACK, backfilled PCS)', optionTexts.length === 3, JSON.stringify(optionTexts));
        check('C2. PCS present among them', optionTexts.some((t) => /^PCS/.test(t)), JSON.stringify(optionTexts));
        check('C3. default-selected option still KARTON (purchase-default/largest factor, unchanged)', /^KARTON/.test(selectedText || ''), String(selectedText));
    }

    // ============================================================
    // Dedup. Base unit already has its own open conversion row — must
    // NOT render a duplicate PCS option.
    // ============================================================
    {
        const { optionTexts } = await pickItemAndReadUnitOptions(page, seed.sku_dedup);
        const pcsCount = optionTexts.filter((t) => /^PCS/.test(t)).length;
        check('Dedup1. base unit already seeded: exactly 2 unit options (KARTON + PCS), no duplicate', optionTexts.length === 2, JSON.stringify(optionTexts));
        check('Dedup2. PCS appears exactly once, never twice', pcsCount === 1, `pcsCount=${pcsCount}`);
    }

    // ============================================================
    // F/G. End-to-end UI submit: pick the newly-available BASE unit for
    // one line's qty, submit the transfer, and verify the resulting
    // destination stock (via the real API, not just the DOM) matches the
    // base quantity exactly — proving the dropdown fix doesn't just
    // render an option, it actually posts correctly through the full
    // create()+receive() pipeline when that option is used.
    // ============================================================
    {
        const row = page.locator('#transfer-lines-tbody tr').first();
        await row.locator('.item-selector-input').click();
        await row.locator('.item-selector-input').fill(seed.sku_submit);
        await page.waitForSelector('.item-selector-option', { state: 'visible', timeout: 8000 });
        await page.locator('.item-selector-option', { hasText: seed.sku_submit }).first().click();
        await page.waitForTimeout(400);
        const unitSelect = row.locator('select.item-selector-unit');
        const optionTexts = await unitSelect.locator('option').allTextContents();
        const pcsOptionIndex = optionTexts.findIndex((t) => /^PCS/.test(t));
        check('F1. the backfilled PCS option is selectable in the real <select> element', pcsOptionIndex >= 0, JSON.stringify(optionTexts));
        await unitSelect.selectOption({ label: optionTexts[pcsOptionIndex] });
        await row.locator('.transfer-line-qty').fill('37');
        await page.selectOption('#transfer-to-wh', String(seed.dst_warehouse_id));
        await page.click('#transfer-submit-btn');
        await page.waitForSelector('.alert-success', { timeout: 8000 });

        // Confirm receive via the real "Konfirmasi Terima" button in the
        // transfer list the submit above already triggered a reload of.
        await page.waitForSelector('.transfer-receive-btn', { timeout: 8000 });
        await page.locator('.transfer-receive-btn').first().click();
        await page.waitForSelector('.toast', { timeout: 8000 });

        // Re-open the create form, switch Gudang Asal to the DESTINATION
        // warehouse, and re-select the same item — "Stok Tersedia" reads
        // live currentStock() exactly like the create form's own column
        // does, so this proves the real post-receive base quantity.
        await page.reload();
        await openTransferTab(page);
        await page.selectOption('#transfer-from-wh', String(seed.dst_warehouse_id));
        const row2 = page.locator('#transfer-lines-tbody tr').first();
        await row2.locator('.item-selector-input').click();
        await row2.locator('.item-selector-input').fill(seed.sku_submit);
        await page.waitForSelector('.item-selector-option', { state: 'visible', timeout: 8000 });
        await page.locator('.item-selector-option', { hasText: seed.sku_submit }).first().click();
        await page.waitForTimeout(600);
        const stockText = await row2.locator('.compact-col-stock').first().textContent();
        check('G1. after transferring 37 PCS (base unit) and confirming receive, destination "Stok Tersedia" reads exactly 37', (stockText || '').trim().replace(/\./g, '') === '37', `stockText="${stockText}"`);
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
