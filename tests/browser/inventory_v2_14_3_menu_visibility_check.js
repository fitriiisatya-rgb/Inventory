// PHASE V2.14.3 — browser-level proof that Auth.applyRoleVisibility()
// actually shows/hides the Warehouse Cutover sidebar link based on the
// backend-hydrated permissions array (not a hard-coded frontend map).
//
// Invoked as: node inventory_v2_14_3_menu_visibility_check.js <baseUrl> <credsJsonPath>
// Requires `playwright` resolvable on NODE_PATH (a global npm install is
// used in this environment; see the invoking test's own NODE_PATH setup —
// this file intentionally never hardcodes an install location).
'use strict';
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');

const baseUrl = process.argv[2];
const creds = JSON.parse(readFileSync(process.argv[3], 'utf8'));

const results = [];
function check(name, pass, detail) {
    results.push(pass);
    console.log((pass ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ` (${detail})` : ''));
}

async function loginAndCheckMenu(browser, username, password) {
    const page = await browser.newPage();
    await page.goto(`${baseUrl}/index.html`, { waitUntil: 'domcontentloaded' });
    await page.fill('#login-username', username);
    await page.fill('#login-password', password);
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/auth/me') && r.status() === 200),
        page.click('#login-submit'),
    ]);
    // showApp() writes #username-chip synchronously, then immediately calls
    // Auth.applyRoleVisibility() in the same tick (before any further
    // await) — waiting for the chip to populate is a deterministic proxy
    // for "visibility has already been applied", no fixed sleep needed.
    await page.waitForFunction((u) => document.getElementById('username-chip')?.textContent === u, username, { timeout: 5000 });
    // The link's parent .sidebar-submenu is a collapsible accordion section
    // with its own native `hidden` attribute, unrelated to this feature —
    // Auth.applyRoleVisibility() only ever sets the LINK's own inline
    // style.display, so check that directly rather than Playwright's
    // isVisible() (which would report false while the accordion is simply
    // collapsed, regardless of permission).
    const shown = await page.evaluate(() => {
        const el = document.querySelector('[data-tab="warehouse-cutover"]');
        return !!el && el.style.display !== 'none';
    });
    await page.close();
    return shown;
}

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined });
    try {
        const superVisible = await loginAndCheckMenu(browser, creds.superadmin.username, creds.superadmin.password);
        check('10. Warehouse Cutover menu link is VISIBLE for SUPERADMIN in a real browser', superVisible === true);

        const adminVisible = await loginAndCheckMenu(browser, creds.admin.username, creds.admin.password);
        check('10. Warehouse Cutover menu link is VISIBLE for ADMIN in a real browser', adminVisible === true);

        const stockVisible = await loginAndCheckMenu(browser, creds.stock.username, creds.stock.password);
        check('11. Warehouse Cutover menu link is HIDDEN for STOCK in a real browser', stockVisible === false);

        const viewerVisible = await loginAndCheckMenu(browser, creds.viewer.username, creds.viewer.password);
        check('11. Warehouse Cutover menu link is HIDDEN for VIEWER in a real browser', viewerVisible === false);
    } finally {
        await browser.close();
    }

    console.log(`=== BROWSER SUMMARY: ${results.filter(Boolean).length}/${results.length} PASS ===`);
    process.exit(results.includes(false) ? 1 : 0);
})();
