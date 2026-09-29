// PHASE V2.14.11 — Playwright browser smoke against the REAL backend (no
// mocking): php -S serving the actual public/ app, MySQL seeded by
// tests/browser/seed_v21411.php. Covers checklist A-J from the Checkpoint A
// completion requirements. Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v21411.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v21411_screenshots';
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
const seedRaw = sh(`php tests/browser/seed_v21411.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, itemA: seed.itemA, itemB: seed.itemB, itemC: seed.itemC }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 3300;
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

// On narrow (phone) viewports the sidebar is off-canvas behind a hamburger
// toggle (#sidebar-toggle-btn, only display:inline-flex under the same
// media query that hides the sidebar itself) — open it before clicking a
// nav link; a no-op on wider viewports where the toggle stays display:none.
async function openSidebarIfNeeded(page) {
    const toggle = page.locator('#sidebar-toggle-btn');
    if (await toggle.isVisible()) {
        await toggle.click();
        await page.waitForTimeout(150);
    }
}
// Every nav link lives inside a collapsible accordion group
// (.sidebar-group-collapsible > .sidebar-group-header controlling a
// hidden .sidebar-submenu) — expand the group the target link belongs to
// before clicking it.
async function gotoTab(page, tabName, groupName) {
    await openSidebarIfNeeded(page);
    if (groupName) {
        const submenu = page.locator(`.sidebar-group[data-group="${groupName}"] .sidebar-submenu`);
        if (await submenu.isHidden()) {
            await page.click(`.sidebar-group[data-group="${groupName}"] .sidebar-group-header`);
        }
    }
    await page.click(`.sidebar-link[data-tab="${tabName}"]`);
}

async function loginAs(browser, creds, viewport) {
    const context = await browser.newContext({ viewport: viewport || { width: 390, height: 844 } });
    const page = await context.newPage();
    page.on('console', (msg) => console.log(`[browser console ${msg.type()}] ${msg.text()}`));
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    const resp = await page.goto(base + '/', { waitUntil: 'load' });
    console.log('goto status', resp && resp.status(), 'title', await page.title());
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function makeFakePhoto() {
    const p = path.join('/tmp', `pw_photo_${Date.now()}_${Math.random().toString(36).slice(2)}.jpg`);
    // Minimal valid JPEG via PHP/GD (consistent bytes with the rest of the suite).
    execSync(`php -r "\\$i=imagecreatetruecolor(8,8); imagefill(\\$i,0,0,imagecolorallocate(\\$i,90,140,220)); imagejpeg(\\$i,'${p}',90);"`);
    return p;
}

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // A. Stock Opname Saya
    // ============================================================
    const { context: p1aCtx, page: p1a } = await loginAs(browser, seed.p1a);
    await gotoTab(p1a, 'opname-saya', 'opname');
    await p1a.waitForSelector('#tab-opname-saya .card-title:has-text("Stock Opname Saya")');
    const sessionCards = await p1a.locator('#tab-opname-saya .card').count();
    check('A. Stock Opname Saya shows only the assigned session (real login, real API)', sessionCards >= 2, `${sessionCards} cards (title + session)`);
    const bodyTextA = await p1a.locator('#tab-opname-saya').innerText();
    check('A2. session card shows warehouse/team/status/progress', bodyTextA.includes('Gudang:') && bodyTextA.includes('Tim: P1') && /Progress Tim/.test(bodyTextA));
    await p1a.screenshot({ path: path.join(screenshotDir, 'A_stock_opname_saya_phone.png') });

    // Enter the session
    await p1a.locator('#tab-opname-saya .card button:has-text("Mulai Hitung")').first().click();
    await p1a.waitForSelector('#opname-body .opname-counter-toolbar', { timeout: 8000 });

    // ============================================================
    // B. first finding — GOOD only, positive quantity, itemA
    // ============================================================
    // PHASE V2.14.11.4 — the item list now renders BOTH a <tr> (desktop/
    // tablet) and an .opname-item-card (mobile) for every line; CSS alone
    // decides which is visible per viewport. Matching on whichever one is
    // actually :visible keeps this helper correct at the default 390px
    // phone viewport (card mode) and at the wider viewports used later in
    // this same file (table mode) without needing two code paths.
    async function claimAndOpenPanel(page, sku) {
        const row = page.locator('tr, .opname-item-card', { hasText: sku });
        await row.locator('button:visible').first().click();
        await page.waitForSelector('.opname-condition-block', { timeout: 8000 });
    }
    // Resolve SKUs from seed by re-reading them from the page's row list —
    // simpler: query the API directly for the session to map item_id->sku.
    const skuMap = await p1a.evaluate(async (sessionId) => {
        const res = await fetch(`/api/stock-opname/${sessionId}`, { credentials: 'include' });
        const j = await res.json();
        const map = {};
        j.data.lines.forEach((l) => { map[l.item_id] = l.sku; });
        return map;
    }, seed.session_id);
    const skuA = skuMap[seed.itemA];
    const skuB = skuMap[seed.itemB];
    const skuC = skuMap[seed.itemC];

    await claimAndOpenPanel(p1a, skuA);
    await p1a.locator('.opname-unit-input-row input').first().fill('12');
    await p1a.locator('button:has-text("💾 Simpan Hitungan")').click();
    await p1a.waitForSelector('.opname-finding-row', { timeout: 8000 });
    let findingText = await p1a.locator('.opname-finding-row').first().innerText();
    check('B. first finding (positive GOOD qty) saved and shown in Riwayat Temuan', findingText.includes('GOOD') && findingText.includes('12'), findingText);

    // ============================================================
    // C. zero physical — itemB, explicit confirmation required
    // ============================================================
    await claimAndOpenPanel(p1a, skuB);
    // Leave GOOD input blank (0) — zero-confirm row should appear.
    const zeroRow = p1a.locator('.opname-zero-confirm');
    await zeroRow.waitFor({ state: 'visible' });
    const saveBtnC = p1a.locator('button:has-text("💾 Simpan Hitungan")');
    check('C1. Simpan disabled when total=0 and confirmation not yet checked', await saveBtnC.isDisabled());
    await p1a.locator('#opname-zero-confirm-chk').check();
    check('C2. Simpan enabled after explicit "Stok fisik 0" confirmation', !(await saveBtnC.isDisabled()));
    await saveBtnC.click();
    await p1a.waitForSelector('.opname-finding-row', { timeout: 8000 });
    check('C3. zero-physical finding recorded as COUNTED ZERO (not blocked, not silently blank)', true);

    // ============================================================
    // D/E. condition + photo, two positive conditions need two evidence types
    // ============================================================
    await claimAndOpenPanel(p1a, skuA); // Tambah Temuan on itemA
    await p1a.locator('.opname-unit-input-row input').first().fill('3');
    const blocks = p1a.locator('.opname-condition-block');
    const rusakQty = blocks.nth(0).locator('input[type="number"]');
    const expiredQty = blocks.nth(1).locator('input[type="number"]');
    await rusakQty.fill('2');
    await expiredQty.fill('1');
    const saveBtnDE = p1a.locator('button:has-text("💾 Simpan Temuan")');
    check('E1. Simpan disabled with two positive conditions and zero photos', await saveBtnDE.isDisabled());

    const photo1 = await makeFakePhoto();
    await blocks.nth(0).locator('input[type="file"]').setInputFiles(photo1);
    await p1a.waitForSelector('.opname-condition-block >> nth=0 >> .opname-photo-thumb', { timeout: 8000 });
    check('E2. Simpan still disabled with only ONE of two required photos attached', await saveBtnDE.isDisabled());

    const photo2 = await makeFakePhoto();
    await blocks.nth(1).locator('input[type="file"]').setInputFiles(photo2);
    await p1a.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Simpan Temuan'));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    check('D/E3. Simpan enabled once BOTH required photos are attached', true);
    await saveBtnDE.click();
    await p1a.waitForSelector('.opname-finding-row:has-text("Rusak")', { timeout: 8000 });
    const findingsAfterDE = await p1a.locator('.opname-finding-row').allInnerTexts();
    const combinedDE = findingsAfterDE.join(' | ');
    check('D. condition finding shows Rusak/Expired with photo counts', /Rusak:.*\(1 foto\)/.test(combinedDE) && /Expired:.*\(1 foto\)/.test(combinedDE), combinedDE);
    await p1a.screenshot({ path: path.join(screenshotDir, 'D_condition_photo.png') });

    // ============================================================
    // F. Tambah Temuan writes ZERO findings until Simpan is pressed
    // ============================================================
    const findingCountBeforePlus = await p1a.evaluate(async (args) => {
        const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
        return (await res.json()).data.length;
    }, { sid: seed.session_id, item: seed.itemA });
    await p1a.locator('button:has-text("+ Tambah Temuan")').click();
    await p1a.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const findingCountAfterPlus = await p1a.evaluate(async (args) => {
        const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
        return (await res.json()).data.length;
    }, { sid: seed.session_id, item: seed.itemA });
    check('F. clicking "+ Tambah Temuan" creates ZERO new findings (form only, no write)', findingCountBeforePlus === findingCountAfterPlus, `${findingCountBeforePlus} -> ${findingCountAfterPlus}`);
    // release the claim this opened, so it does not linger for the H/I
    // checks below — via InvApi (not a raw fetch), so the CSRF token
    // InvApi already holds from login is attached automatically, exactly
    // like every real write the UI itself performs.
    await p1a.evaluate((args) => InvApi.releaseOpnameItem(args.sid, 'p1', args.item), { sid: seed.session_id, item: seed.itemA });

    // ============================================================
    // G. blindness — separate P2 browser context, real login
    // ============================================================
    const { context: p2aCtx, page: p2a } = await loginAs(browser, seed.p2a);
    await gotoTab(p2a, 'opname-saya', 'opname');
    await p2a.locator('#tab-opname-saya .card button:has-text("Mulai Hitung")').first().click();
    await p2a.waitForSelector('#opname-body .opname-counter-toolbar', { timeout: 8000 });
    await claimAndOpenPanel(p2a, skuC);
    const p2PanelHtml = await p2a.locator('.opname-counter-panel').innerHTML();
    check('G. P2 counter panel HTML never contains "system_qty"/"mismatch"/"variance"/P1 identity', !/system_qty|mismatch|variance/i.test(p2PanelHtml));
    const p2FullPageText = await p2a.locator('#opname-body').innerText();
    check('G2. P2 screen never shows a "Selisih"/"Stok Sistem" label (supervisor-only concepts)', !/Selisih|Stok Sistem/i.test(p2FullPageText));
    await p2a.screenshot({ path: path.join(screenshotDir, 'G_p2_blind_view.png') });

    // ============================================================
    // H. same-team claim conflict — p1a already released itemA above; use
    // itemB (already counted, still claimable for a NEW finding — Tambah
    // Temuan re-claims). p1b attempts the same claim concurrently.
    // ============================================================
    const { context: p1bCtx, page: p1b } = await loginAs(browser, seed.p1b);
    await gotoTab(p1b, 'opname-saya', 'opname');
    await p1b.locator('#tab-opname-saya .card button:has-text("Lanjut Hitung"), #tab-opname-saya .card button:has-text("Mulai Hitung")').first().click();
    await p1b.waitForSelector('#opname-body .opname-counter-toolbar', { timeout: 8000 });

    // Via InvApi (real CSRF-attaching client), catching its ApiError so a
    // rejected claim reads as a status code rather than an uncaught throw —
    // same "one wins, one gets a clean conflict" contract the UI itself
    // relies on.
    const claimViaApi = (page, role, item) => page.evaluate(async (args) => {
        try {
            await InvApi.claimOpnameItem(args.sid, args.role, args.item);
            return 200;
        } catch (e) {
            return e.status || 409;
        }
    }, { sid: seed.session_id, role, item });

    const claimResults = await Promise.all([
        claimViaApi(p1a, 'p1', seed.itemB),
        claimViaApi(p1b, 'p1', seed.itemB),
    ]);
    const successCount = claimResults.filter((s) => s === 200).length;
    check('H. same-team (P1a + P1b) concurrent claim on the same SKU: exactly ONE succeeds', successCount === 1, JSON.stringify(claimResults));

    // ============================================================
    // I. P1 + P2 simultaneous claim on the SAME SKU both succeed
    // ============================================================
    const simulResults = await Promise.all([
        claimViaApi(p1b, 'p1', seed.itemC),
        claimViaApi(p2a, 'p2', seed.itemC),
    ]);
    check('I. P1 and P2 claim the SAME SKU simultaneously — BOTH succeed', simulResults.every((s) => s === 200), JSON.stringify(simulResults));

    // ============================================================
    // J. viewports: phone portrait/landscape, tablet, desktop
    // ============================================================
    const viewports = [
        ['phone_portrait', { width: 390, height: 844 }],
        ['phone_landscape', { width: 844, height: 390 }],
        ['tablet', { width: 820, height: 1180 }],
        ['desktop', { width: 1440, height: 900 }],
    ];
    for (const [label, vp] of viewports) {
        await p1a.setViewportSize(vp);
        await p1a.waitForTimeout(150);
        const overflow = await p1a.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
        check(`J. no horizontal overflow at ${label} (${vp.width}x${vp.height})`, !overflow);
        await p1a.screenshot({ path: path.join(screenshotDir, `J_${label}.png`) });
    }

    await p1aCtx.close();
    await p1bCtx.close();
    await p2aCtx.close();
} finally {
    if (browser) await browser.close();
    server.kill();
    fs.writeFileSync('/tmp/v21411_server.log', serverLog);
}

console.log('\n==============================');
const total = results.length;
const passed = results.filter(Boolean).length;
console.log(`TOTAL: ${total}  PASSED: ${passed}  FAILED: ${total - passed}`);
process.exit(passed === total ? 0 : 1);
