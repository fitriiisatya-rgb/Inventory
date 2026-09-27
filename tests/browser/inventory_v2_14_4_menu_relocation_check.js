// PHASE V2.14.4 — browser-level proof that the Warehouse Cutover sidebar
// link, now relocated into the Audit & Control group, is shown/hidden per
// permission exactly as before, that clicking it actually renders
// WarehouseCutover.render(), and that its neighbors (Audit Log, Trace
// Center) are unaffected.
//
// Invoked ONCE PER ROLE (a fresh `node` process each time — this
// environment's `php -S` single-connection dev server and/or Chromium
// become unreliable past ~4 sequential full page loads inside one
// process, confirmed by reordering which role runs last and watching the
// failure follow the LAST position rather than any particular role) as:
//   node inventory_v2_14_4_menu_relocation_check.js <baseUrl> <credsJsonPath> <role>
// <role> is one of: superadmin, admin, stock, division, viewer.
// Requires `playwright` resolvable on NODE_PATH (see the invoking test's
// own NODE_PATH setup — this file never hardcodes an install location).
'use strict';
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');

const baseUrl = process.argv[2];
const creds = JSON.parse(readFileSync(process.argv[3], 'utf8'));
const role = process.argv[4];

const results = [];
function check(name, pass, detail) {
    results.push(pass);
    console.log((pass ? 'PASS' : 'FAIL') + ' - ' + name + (detail ? ` (${detail})` : ''));
}

async function login(username, password) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined });
    const page = await browser.newPage();
    await page.goto(`${baseUrl}/index.html`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.fill('#login-username', username);
    await page.fill('#login-password', password);
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/auth/me') && r.status() === 200, { timeout: 60000 }),
        page.click('#login-submit'),
    ]);
    await page.waitForFunction((u) => document.getElementById('username-chip')?.textContent === u, username, { timeout: 10000 });
    return { page, browser };
}

function shownState(page) {
    return page.evaluate(() => {
        const el = document.querySelector('[data-tab="warehouse-cutover"]');
        const audit = document.querySelector('[data-tab="audit"]');
        const trace = document.querySelector('[data-tab="trace-center"]');
        return {
            cutoverShown: !!el && el.style.display !== 'none',
            auditShown: !!audit && audit.style.display !== 'none',
            traceShown: !!trace && trace.style.display !== 'none',
            cutoverInAuditGroup: !!el && !!el.closest('[data-group="audit-control"]'),
            oneCutoverLink: document.querySelectorAll('[data-tab="warehouse-cutover"]').length,
            oneTabContent: document.querySelectorAll('#tab-warehouse-cutover').length,
        };
    });
}

(async () => {
    if (role === 'superadmin') {
        const { page, browser } = await login(creds.superadmin.username, creds.superadmin.password);
        try {
            const state = await shownState(page);
            check('5. Warehouse Cutover link VISIBLE for SUPERADMIN', state.cutoverShown === true);
            check('Warehouse Cutover link now lives inside the Audit & Control group', state.cutoverInAuditGroup === true);
            check('Exactly ONE Warehouse Cutover sidebar link in the DOM', state.oneCutoverLink === 1, String(state.oneCutoverLink));
            check('Exactly ONE #tab-warehouse-cutover content div in the DOM', state.oneTabContent === 1, String(state.oneTabContent));
            check('8. Audit Log link still VISIBLE/unaffected for SUPERADMIN', state.auditShown === true);
            check('8. Trace Center link still VISIBLE/unaffected for SUPERADMIN', state.traceShown === true);

            // Programmatically click (bypasses the collapsed-accordion
            // actionability check, a UI affordance unrelated to this
            // feature) and confirm WarehouseCutover.render() actually ran.
            await page.evaluate(() => document.querySelector('[data-tab="warehouse-cutover"]').click());
            await page.waitForSelector('#tab-warehouse-cutover.active', { timeout: 5000 }).catch(() => null);
            const rendered = await page.evaluate(() => {
                const tab = document.getElementById('tab-warehouse-cutover');
                return {
                    active: !!tab && tab.classList.contains('active'),
                    hasBody: !!document.getElementById('wc-body'),
                    titleText: tab ? (tab.querySelector('.card-title')?.textContent || '') : '',
                };
            });
            check('7. Clicking the link activates #tab-warehouse-cutover', rendered.active === true, JSON.stringify(rendered));
            check('7. WarehouseCutover.render() actually populated the pane (#wc-body present, card title rendered)', rendered.hasBody === true && rendered.titleText.includes('Cutover'), JSON.stringify(rendered));
        } finally {
            await browser.close();
        }
    } else if (role === 'admin') {
        const { page, browser } = await login(creds.admin.username, creds.admin.password);
        try {
            const state = await shownState(page);
            check('5. Warehouse Cutover link VISIBLE for ADMIN', state.cutoverShown === true);
        } finally {
            await browser.close();
        }
    } else if (role === 'stock') {
        const { page, browser } = await login(creds.stock.username, creds.stock.password);
        try {
            const state = await shownState(page);
            check('6. Warehouse Cutover link HIDDEN for STOCK', state.cutoverShown === false);
        } finally {
            await browser.close();
        }
    } else if (role === 'division') {
        const { page, browser } = await login(creds.division.username, creds.division.password);
        try {
            const state = await shownState(page);
            check('6. Warehouse Cutover link HIDDEN for DIVISION', state.cutoverShown === false);
        } finally {
            await browser.close();
        }
    } else if (role === 'viewer') {
        const { page, browser } = await login(creds.viewer.username, creds.viewer.password);
        try {
            const state = await shownState(page);
            check('6. Warehouse Cutover link HIDDEN for VIEWER', state.cutoverShown === false);
        } finally {
            await browser.close();
        }
    } else {
        console.log(`FAIL - unknown role argument: ${role}`);
        results.push(false);
    }

    console.log(`=== BROWSER SUMMARY (${role}): ${results.filter(Boolean).length}/${results.length} PASS ===`);
    process.exit(results.includes(false) ? 1 : 0);
})();
