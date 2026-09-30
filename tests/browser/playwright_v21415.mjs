// PHASE V2.14.11.5 — Playwright browser smoke for the direct-count +
// autocomplete-search UX hotfix on the Stock Opname counter screen, against
// the REAL backend (php -S serving the actual public/ app, MySQL seeded by
// tests/browser/seed_v21415.php). Covers the 16 required scenarios:
//   1. exactly one active session -> direct entry, no intermediate card
//   2. Petugas Stock Opname / admin page never rendered for OPNAME_COUNTER
//   3. zero active sessions -> no-assignment message
//   4. two+ active sessions -> a simple picker
//   5/15. typeahead suggestions for "coklat" (ACTIVE + INACTIVE, never the
//      unrelated item)
//   6. suggestions never carry system/opponent quantities
//   7. tap a suggestion -> count form opens directly, first GOOD input
//      focused
//   8. Enter with no arrow navigation selects the FIRST suggestion
//   9. ArrowDown + Enter selects the HIGHLIGHTED suggestion
//   10. exact SKU + Enter opens that item directly
//   11. no-match shows the compact "Barang tidak ditemukan." text
//   12. after a successful Save, focus returns to the search box
//   13. a same-team claim conflict shows the fixed message and never opens
//      an editable form
//   14. an already-counted item opens SUMMARY first (SUDAH DIHITUNG + non-
//      destructive "+ Tambah Temuan"), never overwriting the prior finding
//   16. zero horizontal scroll at 375/390/430px with the dropdown open
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v21415.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v21415_screenshots';
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
const seedRaw = sh(`php tests/browser/seed_v21415.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, session_id2: seed.session_id2 }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 4100;
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
    const context = await browser.newContext({ viewport: viewport || { width: 390, height: 844 } });
    const page = await context.newPage();
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function fetchSkuMap(page, sessionId) {
    return page.evaluate(async (sid) => {
        const res = await fetch(`/api/stock-opname/${sid}`, { credentials: 'include' });
        const j = await res.json();
        const map = {};
        j.data.lines.forEach((l) => { map[l.item_id] = l.sku; });
        return map;
    }, sessionId);
}

// Releases whatever claim the count panel currently holds (if any) and
// returns to the search-first screen — mirrors a real counter tapping
// "‹ Kembali ke Daftar" between items.
async function backToList(page) {
    const backBtn = page.locator('button:has-text("Kembali ke Daftar")');
    if (await backBtn.count()) await backBtn.first().click();
}

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // 1/2 — counterOne: exactly ONE active session -> direct entry;
    // the admin Stock Opname / Petugas page is never reachable.
    // ============================================================
    const { context: c1Ctx, page: c1 } = await loginAs(browser, seed.counterOne);
    await c1.waitForSelector('#tab-opname-saya .opname-counter-toolbar', { timeout: 8000 });
    const introCardCount = await c1.locator('#tab-opname-saya .card-title:has-text("Stock Opname Saya")').count();
    check('1. exactly one active session opens the counter screen directly (no intermediate card)', introCardCount === 0, `${introCardCount} intermediate cards`);
    const bodyText1 = await c1.locator('#tab-opname-saya').innerText();
    check('1b. Progress Tim is visible with zero extra taps', /Progress Tim/.test(bodyText1));
    await c1.screenshot({ path: path.join(screenshotDir, '1_direct_entry.png') });

    const sidebarOpnameLink = c1.locator('.sidebar-link[data-tab="opname"]');
    check('2. the admin Stock Opname sidebar link is hidden for OPNAME_COUNTER', !(await sidebarOpnameLink.isVisible()));
    const adminTabHtml = (await c1.locator('#tab-opname').innerHTML()).trim();
    check('2b. the admin Stock Opname tab content is NEVER populated for OPNAME_COUNTER', adminTabHtml === '', `len=${adminTabHtml.length}`);
    const petugasTextCount = await c1.locator('text=Petugas Stock Opname').count();
    check('2c. "Petugas Stock Opname" never appears anywhere on the page', petugasTextCount === 0);

    const skuMap = await fetchSkuMap(c1, seed.session_id);
    const skuCoklat1 = skuMap[seed.itemCoklat1];
    const skuCoklat2 = skuMap[seed.itemCoklat2];
    const skuCoklatInactive = skuMap[seed.itemCoklatInactive];
    const skuPlain = skuMap[seed.itemPlain];
    const skuConflict = skuMap[seed.itemConflict];
    const skuAlready = skuMap[seed.itemAlready];

    const search = c1.locator('.opname-blind-search');
    const suggestRows = c1.locator('.opname-suggest-row');

    // ============================================================
    // 5/6/15 — typeahead suggestions
    // ============================================================
    await search.fill('coklat');
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    const suggestTexts = (await suggestRows.allInnerTexts()).join(' | ');
    check('5. typing "coklat" shows the matching items as suggestions', suggestTexts.includes(skuCoklat1) && suggestTexts.includes(skuCoklat2));
    check('15. an INACTIVE item (Dark Coklat Chips) is searchable alongside ACTIVE ones', suggestTexts.includes(skuCoklatInactive));
    check('5b. an unrelated item never appears in "coklat" suggestions', !suggestTexts.includes(skuPlain));
    check('6. suggestions never carry system/opponent quantities', !/system_qty|mismatch|variance|stok sistem/i.test(suggestTexts));

    // ============================================================
    // 7 — tap a suggestion opens the count form directly
    // ============================================================
    await c1.locator('.opname-suggest-row', { hasText: skuCoklat1 }).first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('7. tapping a suggestion opens the count form directly (no extra Hitung tap)', true);
    const focusedIsGoodInput = await c1.evaluate(() => document.activeElement === document.querySelector('.opname-unit-input-row input'));
    check('7b. the first GOOD quantity input is auto-focused after selection', focusedIsGoodInput);
    await backToList(c1);

    // ============================================================
    // 8 — Enter with no arrow navigation selects the FIRST suggestion
    // ============================================================
    await search.fill('coklat');
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    const firstSuggestSku = await suggestRows.first().locator('.opname-suggest-sku').innerText();
    await search.press('Enter');
    await c1.waitForSelector('.opname-condition-block, .opname-history-toggle', { timeout: 8000 });
    const panelTitle8 = await c1.locator('.opname-counter-panel .card-title').innerText();
    check('8. Enter with no arrow navigation selects the FIRST suggestion', panelTitle8.startsWith(firstSuggestSku), `title=${panelTitle8} first=${firstSuggestSku}`);
    await backToList(c1);

    // ============================================================
    // 9 — ArrowDown + Enter selects the HIGHLIGHTED suggestion
    // ============================================================
    await search.fill('coklat');
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    const secondSuggestSku = await suggestRows.nth(1).locator('.opname-suggest-sku').innerText();
    await search.press('ArrowDown');
    await search.press('ArrowDown');
    check('9a. the second suggestion is visually highlighted after two ArrowDown presses', await suggestRows.nth(1).evaluate((el) => el.classList.contains('active')));
    await search.press('Enter');
    await c1.waitForSelector('.opname-condition-block, .opname-history-toggle', { timeout: 8000 });
    const panelTitle9 = await c1.locator('.opname-counter-panel .card-title').innerText();
    check('9b. ArrowDown + Enter selects the HIGHLIGHTED suggestion, not necessarily the first', panelTitle9.startsWith(secondSuggestSku), `title=${panelTitle9} second=${secondSuggestSku}`);
    await backToList(c1);

    // ============================================================
    // 10 — exact SKU + Enter opens that item directly
    // ============================================================
    await search.fill(skuPlain);
    await search.press('Enter');
    await c1.waitForSelector('.opname-condition-block, .opname-history-toggle', { timeout: 8000 });
    const panelTitle10 = await c1.locator('.opname-counter-panel .card-title').innerText();
    check('10. exact SKU + Enter opens that item directly', panelTitle10.startsWith(skuPlain));
    await backToList(c1);

    // ============================================================
    // 11 — no-match state
    // ============================================================
    await search.fill('zzz-tidak-ada-barang-seperti-ini-xyz');
    await c1.waitForSelector('.opname-suggest-empty', { timeout: 8000 });
    const emptyText = await c1.locator('.opname-suggest-empty').innerText();
    check('11. no-match shows the compact "Barang tidak ditemukan." text', emptyText.includes('Barang tidak ditemukan'));
    await search.fill('');
    await c1.waitForTimeout(250);

    // ============================================================
    // 12 — after a successful Save, focus returns to the search box
    // ============================================================
    await search.fill(skuCoklat2);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    await c1.locator('.opname-unit-input-row input').first().fill('9');
    await c1.locator('button:has-text("💾 Simpan Hitungan"), button:has-text("💾 Simpan Temuan")').click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    await c1.waitForTimeout(150);
    const focusedAfterSave = await c1.evaluate(() => document.activeElement === document.querySelector('.opname-blind-search'));
    check('12. after a successful Save, focus returns to the search box automatically', focusedAfterSave);

    // ============================================================
    // 14 — already-counted item opens SUMMARY first, offers
    // "+ Tambah Temuan", never overwrites the prior finding.
    // ============================================================
    await search.fill(skuAlready);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    const suggestRow14Text = await c1.locator('.opname-suggest-row', { hasText: skuAlready }).innerText();
    check('14a. the suggestion badge shows SUDAH DIHITUNG for an already-counted item', suggestRow14Text.includes('SUDAH DIHITUNG'));
    await c1.locator('.opname-suggest-row', { hasText: skuAlready }).first().click();
    await c1.waitForSelector('.opname-counter-panel .card-title', { timeout: 8000 });
    const conditionBlockCount14 = await c1.locator('.opname-condition-block').count();
    check('14b. reopening an already-counted item does NOT jump straight into a blank form', conditionBlockCount14 === 0);
    const panelText14 = await c1.locator('.opname-counter-panel').innerText();
    check('14c. the panel clearly shows SUDAH DIHITUNG and offers "+ Tambah Temuan"', panelText14.includes('SUDAH DIHITUNG') && panelText14.includes('Tambah Temuan'));
    await c1.locator('button:has-text("+ Tambah Temuan")').click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const findingCount14 = await c1.evaluate(async (args) => {
        const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
        return (await res.json()).data.length;
    }, { sid: seed.session_id, item: seed.itemAlready });
    check('14d. "+ Tambah Temuan" opens a blank form WITHOUT overwriting the seeded prior finding', findingCount14 === 1, `${findingCount14} findings on record`);
    await backToList(c1);

    // ============================================================
    // 16 — zero horizontal scroll at 375/390/430px with the
    // suggestion dropdown open (the highest-overflow-risk moment).
    // ============================================================
    for (const w of [375, 390, 430]) {
        await c1.setViewportSize({ width: w, height: 844 });
        await c1.waitForTimeout(100);
        await search.fill('coklat');
        await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
        const overflow = await c1.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
        check(`16. no horizontal scroll at ${w}px with the autocomplete dropdown open`, !overflow);
        await c1.screenshot({ path: path.join(screenshotDir, `16_${w}_dropdown_open.png`) });
        await search.fill('');
        await c1.waitForTimeout(150);
    }

    // ============================================================
    // 13 — same-team claim conflict. counterOne claims itemConflict
    // and deliberately does NOT release it; counterOneB (same P1 team,
    // same session) then tries to select the SAME item via search.
    // ============================================================
    await c1.evaluate((args) => InvApi.claimOpnameItem(args.sid, 'p1', args.item), { sid: seed.session_id, item: seed.itemConflict });

    const { context: c1bCtx, page: c1b } = await loginAs(browser, seed.counterOneB);
    await c1b.waitForSelector('#tab-opname-saya .opname-counter-toolbar', { timeout: 8000 });
    const search1b = c1b.locator('.opname-blind-search');
    await search1b.fill(skuConflict);
    await c1b.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await c1b.locator('.opname-suggest-row').first().click();
    await c1b.waitForTimeout(600);
    check('13a. a same-team claim conflict never opens an editable count form', (await c1b.locator('.opname-condition-block').count()) === 0);
    const toastTexts13 = await c1b.locator('.toast').allInnerTexts();
    check('13b. shows the fixed "Barang sedang dihitung oleh anggota Tim P1/P2 lain." message', toastTexts13.some((t) => t.includes('Barang sedang dihitung oleh anggota Tim P1/P2 lain')), JSON.stringify(toastTexts13));
    const focused13 = await c1b.evaluate(() => document.activeElement === document.querySelector('.opname-blind-search'));
    check('13c. focus returns to the search box after a claim conflict', focused13);
    await c1.evaluate((args) => InvApi.releaseOpnameItem(args.sid, 'p1', args.item), { sid: seed.session_id, item: seed.itemConflict });
    await c1bCtx.close();
    await c1Ctx.close();

    // ============================================================
    // 3 — zero active assigned sessions
    // ============================================================
    const { context: c0Ctx, page: c0 } = await loginAs(browser, seed.counterZero);
    await c0.waitForSelector('#tab-opname-saya', { timeout: 8000 });
    const zeroText = await c0.locator('#tab-opname-saya').innerText();
    check('3. zero active assigned sessions shows the exact no-assignment message', zeroText.includes('Anda belum ditugaskan ke sesi Stock Opname aktif.'), zeroText);
    await c0.screenshot({ path: path.join(screenshotDir, '3_zero_sessions.png') });
    await c0Ctx.close();

    // ============================================================
    // 4 — two or more active assigned sessions -> a simple picker
    // ============================================================
    const { context: cmCtx, page: cm } = await loginAs(browser, seed.counterMulti);
    await cm.waitForSelector('#tab-opname-saya', { timeout: 8000 });
    const pickerBtnLocator = cm.locator('#tab-opname-saya .card button:has-text("Mulai Hitung"), #tab-opname-saya .card button:has-text("Lanjut Hitung")');
    check('4. two active sessions shows a simple picker instead of auto-opening', (await pickerBtnLocator.count()) === 2, `${await pickerBtnLocator.count()} picker buttons`);
    await cm.screenshot({ path: path.join(screenshotDir, '4_multi_session_picker.png') });
    await pickerBtnLocator.first().click();
    await cm.waitForSelector('#opname-saya-session .opname-counter-toolbar', { timeout: 8000 });
    check('4b. picking a session from the picker opens its counter screen', true);
    await cmCtx.close();
} finally {
    if (browser) await browser.close();
    server.kill();
    fs.writeFileSync('/tmp/v21415_server.log', serverLog);
}

console.log('\n==============================');
const total = results.length;
const passed = results.filter(Boolean).length;
console.log(`TOTAL: ${total}  PASSED: ${passed}  FAILED: ${total - passed}`);
process.exit(passed === total ? 0 : 1);
