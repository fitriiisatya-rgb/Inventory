// STABILIZATION — TRANSFER STOCK DISPLAY FOLLOWS SELECTED UNIT.
// Proves, against the REAL backend and the REAL (fixed) transfers.js in
// an actual browser, that "Stok Tersedia" now shows
// available_base_qty / conversion_to_base for whichever unit is
// currently selected — never the raw base quantity regardless of unit —
// and that the submitted payload still sends the user's raw entered
// qty + the selected unit id untouched (backend remains responsible for
// normalization; this is a display-only fix).
//
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_transfer_stock_display.mjs
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
const seed = JSON.parse(sh(`php tests/browser/seed_transfer_stock_display.php`));
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

async function pickItem(page, sku) {
    const row = page.locator('#transfer-lines-tbody tr').first();
    const input = row.locator('.item-selector-input');
    await input.click();
    await input.fill(sku);
    await page.waitForSelector('.item-selector-option', { state: 'visible', timeout: 8000 });
    await page.locator('.item-selector-option', { hasText: sku }).first().click();
    await page.waitForTimeout(400);
    return row;
}

async function selectUnitByCode(row, code) {
    const unitSelect = row.locator('select.item-selector-unit');
    const optionTexts = await unitSelect.locator('option').allTextContents();
    const idx = optionTexts.findIndex((t) => t.startsWith(code + ' '));
    if (idx < 0) throw new Error(`unit ${code} not found among: ${JSON.stringify(optionTexts)}`);
    await unitSelect.selectOption({ label: optionTexts[idx] });
    await row.page().waitForTimeout(500); // onChange -> refreshStockFor() -> render
}

async function stockText(row) {
    return (await row.locator('.compact-col-stock').first().textContent() || '').trim();
}

let browser;
try {
    browser = await chromium.launch();
    const { page } = await loginAs(browser, seed.admin);
    await openTransferTab(page);
    await page.selectOption('#transfer-from-wh', String(seed.src_warehouse_id));

    // ============================================================
    // A/B/C/D — base 192 KG, KARTON factor 32 (=6), PACK factor 4 (=48).
    // ============================================================
    {
        const row = await pickItem(page, seed.sku_bcd);

        await selectUnitByCode(row, 'KG');
        check('A. base unit (KG) displays the raw base stock: 192', (await stockText(row)) === '192', await stockText(row));

        await selectUnitByCode(row, 'KARTON');
        check('B. largest unit (KARTON, factor 32) displays 192/32 = 6', (await stockText(row)) === '6', await stockText(row));

        await selectUnitByCode(row, 'PACK');
        check('C. middle unit (PACK, factor 4) displays 192/4 = 48', (await stockText(row)) === '48', await stockText(row));

        // D. switching repeatedly — back to KARTON, then KG, then PACK again.
        await selectUnitByCode(row, 'KARTON');
        const d1 = await stockText(row);
        await selectUnitByCode(row, 'KG');
        const d2 = await stockText(row);
        await selectUnitByCode(row, 'PACK');
        const d3 = await stockText(row);
        check('D. switching units repeatedly: KARTON->6 each time, never stuck on a stale value', d1 === '6', d1);
        check('D. switching units repeatedly: KG->192 each time', d2 === '192', d2);
        check('D. switching units repeatedly: PACK->48 each time', d3 === '48', d3);
    }

    // ============================================================
    // E — base 100 KG, ROLL factor 7 -> 100/7 = 14.2857... -> "14,29"
    // (id-ID locale, 2 decimals, same UI.formatNumber() convention used
    // everywhere else on this screen).
    // ============================================================
    {
        const row = await pickItem(page, seed.sku_e_decimal);
        // ROLL is the only conversion and purchase-default, so it's the
        // default-selected option already.
        check('E. decimal result: ROLL (factor 7) displays 100/7 rounded to 2 decimals (14,29)', (await stockText(row)) === '14,29', await stockText(row));
        await selectUnitByCode(row, 'KG');
        check('E2. switching that same item to its base unit (KG) displays the exact integer 100', (await stockText(row)) === '100', await stockText(row));
    }

    // ============================================================
    // F — zero stock, base KG + KARTON factor 10 -> 0 in both units.
    // ============================================================
    {
        const row = await pickItem(page, seed.sku_f_zero);
        await selectUnitByCode(row, 'KARTON');
        check('F. zero stock displays 0 in a non-base unit (KARTON), never blank/NaN/negative', (await stockText(row)) === '0', await stockText(row));
        await selectUnitByCode(row, 'KG');
        check('F2. zero stock displays 0 in the base unit too', (await stockText(row)) === '0', await stockText(row));
    }

    // ============================================================
    // G — 192 KG on hand, BOX factor 500 (larger than what's on hand) ->
    // available in BOX is a fraction < 1, never floored to 0.
    // ============================================================
    {
        const row = await pickItem(page, seed.sku_g_largefactor);
        check('G. unit factor larger than available base stock displays the correct fraction (192/500 = 0,38), never 0 or negative', (await stockText(row)) === '0,38', await stockText(row));
        await selectUnitByCode(row, 'KG');
        check('G2. switching back to base unit (KG) shows the full 192', (await stockText(row)) === '192', await stockText(row));
    }

    // ============================================================
    // H — submission payload unchanged: select KARTON (displayed "6"),
    // enter qty "2" (2 KARTON), intercept the real POST /transfers
    // request and verify the body still carries the RAW entered qty and
    // the SELECTED unit id — never a base-converted number. Backend
    // remains solely responsible for normalization.
    // ============================================================
    {
        let capturedBody = null;
        await page.route('**/api/transfers', async (route, request) => {
            if (request.method() === 'POST') {
                capturedBody = JSON.parse(request.postData());
            }
            await route.continue();
        });

        const row = await pickItem(page, seed.sku_bcd);
        await selectUnitByCode(row, 'KARTON');
        check('H1. (setup) KARTON displays 6 before submit', (await stockText(row)) === '6', await stockText(row));

        // Capture the KARTON option's real value BEFORE submitting — the
        // row's own ctl (and its <select>'s options) is destroyed by
        // submitTransfer()'s post-success form reset, so this must be
        // read while the row is still live.
        const unitSelect = row.locator('select.item-selector-unit');
        const kartonOptionText = (await unitSelect.locator('option').allTextContents()).find((t) => t.startsWith('KARTON '));
        const kartonValue = await unitSelect.locator('option', { hasText: kartonOptionText }).first().getAttribute('value');

        await row.locator('.transfer-line-qty').fill('2');
        await page.selectOption('#transfer-to-wh', String(seed.dst_warehouse_id));
        await page.click('#transfer-submit-btn');
        await page.waitForSelector('#transfer-create-alert .alert-success', { timeout: 8000 });

        check('H2. submitted payload has exactly ONE line', Array.isArray(capturedBody?.lines) && capturedBody.lines.length === 1, JSON.stringify(capturedBody));
        check('H3. submitted input_qty is the RAW entered value (2), never converted to a base-equivalent (200)', capturedBody?.lines?.[0]?.input_qty === 2, JSON.stringify(capturedBody?.lines));
        check('H4. submitted input_unit_id is the SELECTED unit (KARTON), never silently swapped to the base unit', String(capturedBody?.lines?.[0]?.input_unit_id) === String(kartonValue), JSON.stringify(capturedBody?.lines));
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
