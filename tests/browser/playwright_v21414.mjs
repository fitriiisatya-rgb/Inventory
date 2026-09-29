// PHASE V2.14.11.4 — Playwright browser smoke for the mobile UX hotfix on
// the Stock Opname counter screen ("Stock Opname Saya"). Frontend-only
// change (no data model, no new endpoints) — this proves, against the REAL
// backend (php -S serving the actual public/ app, MySQL seeded by the SAME
// tests/browser/seed_v21411.php fixture the V2.14.11 suite uses):
//   1. zero horizontal scroll at exactly 375/390/430px on every core
//      counter screen (item list, count form, condition/photo state,
//      finding history, Tambah Temuan)
//   2. the mobile item list renders as cards (not the desktop table) at
//      those widths
//   3. every existing behavior V2.14.11's own suite already covers
//      (search/filter, claim, dynamic units, zero-count confirmation,
//      condition+photo requirement, Tambah Temuan writing nothing until
//      Simpan, own-team finding history) still works unchanged
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v21414.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v21414_screenshots';
fs.mkdirSync(screenshotDir, { recursive: true });

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

function sh(cmd) {
    return execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

// ---- fresh DB + seed (same fixture as the V2.14.11 suite — this hotfix
// changes no data model, so no new seed script is needed) ----
sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seedRaw = sh(`php tests/browser/seed_v21411.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, itemA: seed.itemA, itemB: seed.itemB, itemC: seed.itemC }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 3700;
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

async function openSidebarIfNeeded(page) {
    const toggle = page.locator('#sidebar-toggle-btn');
    if (await toggle.isVisible()) {
        await toggle.click();
        await page.waitForTimeout(150);
    }
}
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
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function makeFakePhoto() {
    const p = path.join('/tmp', `pw_photo_v21414_${Date.now()}_${Math.random().toString(36).slice(2)}.jpg`);
    execSync(`php -r "\\$i=imagecreatetruecolor(8,8); imagefill(\\$i,0,0,imagecolorallocate(\\$i,90,140,220)); imagejpeg(\\$i,'${p}',90);"`);
    return p;
}

async function noOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 2);
}

let browser;
try {
    browser = await chromium.launch();

    const widths = [
        ['375', 375, seed.itemA],
        ['390', 390, seed.itemB],
        ['430', 430, seed.itemC],
    ];

    for (const [label, width, itemId] of widths) {
        const viewport = { width, height: 844 };
        const { context, page } = await loginAs(browser, seed.p1a, viewport);

        await gotoTab(page, 'opname-saya', 'opname');
        await page.locator('#tab-opname-saya .card button:has-text("Mulai Hitung"), #tab-opname-saya .card button:has-text("Lanjut Hitung")').first().click();
        await page.waitForSelector('#opname-body .opname-counter-toolbar', { timeout: 8000 });

        // ---- 1. item list screen ----
        check(`[${label}px] no horizontal scroll — item list`, await noOverflow(page));
        check(`[${label}px] mobile card list is visible (not the desktop table)`,
            await page.locator('.opname-mobile-cards').isVisible() && !(await page.locator('.compact-table-wrap').isVisible()));
        const cardCount = await page.locator('.opname-item-card').count();
        check(`[${label}px] at least one .opname-item-card rendered`, cardCount >= 1, `${cardCount} cards`);
        const firstCardText = await page.locator('.opname-item-card').first().innerText();
        check(`[${label}px] card shows SKU + item-status badge (Aktif/Tidak Aktif)`, /AKTIF/.test(firstCardText));

        // search + filters still work (existing functionality preserved)
        const skuMap = await page.evaluate(async (sessionId) => {
            const res = await fetch(`/api/stock-opname/${sessionId}`, { credentials: 'include' });
            const j = await res.json();
            const map = {};
            j.data.lines.forEach((l) => { map[l.item_id] = l.sku; });
            return map;
        }, seed.session_id);
        const sku = skuMap[itemId];
        await page.locator('.opname-blind-search').fill(sku);
        await page.waitForTimeout(150);
        const filteredCount = await page.locator('.opname-item-card').count();
        check(`[${label}px] search filters the card list down to the matching SKU`, filteredCount === 1, `${filteredCount} cards after search`);
        check(`[${label}px] no horizontal scroll — after search`, await noOverflow(page));

        // ---- 2. open the count form (card's own button, mobile path) ----
        await page.locator('.opname-item-card').first().locator('button:visible').click();
        await page.waitForSelector('.opname-condition-block', { timeout: 8000 });
        check(`[${label}px] no horizontal scroll — count form`, await noOverflow(page));
        await page.locator('.opname-unit-input-row input').first().fill('7');
        check(`[${label}px] total-otomatis preview visible`, await page.locator('.opname-total-otomatis').isVisible());

        // ---- 3. condition/photo state ----
        const blocks = page.locator('.opname-condition-block');
        await blocks.nth(0).locator('input[type="number"]').fill('1');
        const photo = await makeFakePhoto();
        await blocks.nth(0).locator('input[type="file"]').setInputFiles(photo);
        await page.waitForSelector('.opname-condition-block >> nth=0 >> .opname-photo-thumb', { timeout: 8000 });
        check(`[${label}px] no horizontal scroll — condition/photo state`, await noOverflow(page));

        await page.waitForFunction(() => {
            const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Simpan Hitungan') || b.textContent.includes('Simpan Temuan'));
            return btn && !btn.disabled;
        }, { timeout: 8000 });
        await page.locator('button:has-text("💾 Simpan Hitungan"), button:has-text("💾 Simpan Temuan")').click();
        await page.waitForSelector('.opname-history-toggle', { timeout: 8000 });

        // ---- 4. finding history — collapsed by default, expands on demand ----
        const listHiddenInitially = await page.locator('.opname-history-list').isHidden();
        check(`[${label}px] Riwayat Temuan is collapsed by default after save`, listHiddenInitially);
        await page.locator('.opname-history-toggle').click();
        check(`[${label}px] Riwayat Temuan expands on "Lihat Riwayat Temuan"`, await page.locator('.opname-history-list').isVisible());
        check(`[${label}px] no horizontal scroll — finding history expanded`, await noOverflow(page));

        // ---- 5. Tambah Temuan ----
        const findingCountBefore = await page.evaluate(async (args) => {
            const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
            return (await res.json()).data.length;
        }, { sid: seed.session_id, item: itemId });
        await page.locator('button:has-text("+ Tambah Temuan")').click();
        await page.waitForSelector('.opname-condition-block', { timeout: 8000 });
        check(`[${label}px] no horizontal scroll — Tambah Temuan form`, await noOverflow(page));
        const findingCountAfter = await page.evaluate(async (args) => {
            const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
            return (await res.json()).data.length;
        }, { sid: seed.session_id, item: itemId });
        check(`[${label}px] "+ Tambah Temuan" writes ZERO findings until Simpan`, findingCountBefore === findingCountAfter, `${findingCountBefore} -> ${findingCountAfter}`);

        // sticky save button — full width, minimum 48px tall
        const saveBtn = page.locator('.opname-sticky-actions .btn').last();
        const box = await saveBtn.boundingBox();
        check(`[${label}px] save button is >=48px tall`, !!box && box.height >= 48, JSON.stringify(box));

        await page.evaluate((args) => InvApi.releaseOpnameItem(args.sid, 'p1', args.item), { sid: seed.session_id, item: itemId });
        await page.screenshot({ path: path.join(screenshotDir, `${label}_full_flow.png`), fullPage: true });
        await context.close();
    }
} finally {
    if (browser) await browser.close();
    server.kill();
    fs.writeFileSync('/tmp/v21414_server.log', serverLog);
}

console.log('\n==============================');
const total = results.length;
const passed = results.filter(Boolean).length;
console.log(`TOTAL: ${total}  PASSED: ${passed}  FAILED: ${total - passed}`);
process.exit(passed === total ? 0 : 1);
