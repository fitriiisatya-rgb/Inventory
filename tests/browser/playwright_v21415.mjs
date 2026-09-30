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
    // 18 — "SISA SATUAN TERKECIL", PHASE V2.14.11.6 base-unit-driven
    // rework: the control is now keyed ENTIRELY off the item's own BASE
    // unit code, never off whether a GR/ML unit happens to already be
    // configured/frozen for that item.
    // ============================================================
    const skuKgGr = skuMap[seed.itemKgGr];
    const skuPcsOnly = skuMap[seed.itemPcsOnly];
    const skuKgOnly = skuMap[seed.itemKgOnly];
    const skuLtrOnly = skuMap[seed.itemLtrOnly];

    // 1/2 — a KG-base item ALWAYS shows the Gram remainder, even with NO
    // GR unit configured/frozen anywhere for it.
    await search.fill(skuKgOnly);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('18.1. a KG-base item ALWAYS shows the SISA SATUAN TERKECIL control', await c1.locator('.opname-remainder-block').isVisible());
    const unitRowLabelsKgOnly = await c1.locator('.opname-unit-input-row label').allInnerTexts();
    check('18.2. GR does not need to exist as a configured/frozen unit for this item (none of its rows is GR)', !unitRowLabelsKgOnly.some((t) => /^GR\b/.test(t)), JSON.stringify(unitRowLabelsKgOnly));
    const remainderLabelKg = await c1.locator('.opname-remainder-unit-label').innerText();
    check('18.8. a KG-base item never shows mL (weight is never auto-converted to volume)', remainderLabelKg === 'Gram', remainderLabelKg);

    // 3 — 250 GR => 0.25 KG, merged straight into the item's own KG (base)
    // row, live in Total Otomatis (requirement 6).
    const kgRowInput = c1.locator('.opname-unit-input-row', { hasText: /^KG\b/ }).locator('input');
    const remainderQtyKg = c1.locator('.opname-remainder-qty');
    await remainderQtyKg.fill('250');
    check('18.3. 250 GR remainder normalizes to 0.25 KG in the base row', (await kgRowInput.inputValue()) === '0.25');
    const totalText3 = await c1.locator('.opname-total-otomatis').innerText();
    check('18.3b. Total Otomatis reflects it live', /0[.,]25/.test(totalText3), totalText3);

    // 9 — no double counting: re-editing the remainder re-merges as a
    // delta rather than re-adding on top (250 -> 230 means the base row
    // goes to 0.23, not 0.48).
    await remainderQtyKg.fill('230');
    check('18.9. changing the remainder value re-merges as a delta, never double-counts (250->230 means 0.25->0.23, not 0.48)', (await kgRowInput.inputValue()) === '0.23');

    // 10 — zero remainder changes nothing further: going back to 0
    // exactly undoes the merged contribution, leaving the row at 0.
    await remainderQtyKg.fill('0');
    check('18.10. setting the remainder back to 0 removes its contribution, changing nothing else (row back to 0)', (await kgRowInput.inputValue()) === '0');

    // 4 — CTN (KARTON, factor 5 KG) + Gram remainder combine correctly:
    // 10 KARTON = 50 KG, + 250 Gram remainder = 50.25 KG total.
    const kartonRowInput = c1.locator('.opname-unit-input-row', { hasText: /^KARTON\b/ }).locator('input');
    await kartonRowInput.fill('10');
    await remainderQtyKg.fill('250');
    const totalText4 = await c1.locator('.opname-total-otomatis').innerText();
    check('18.4. 10 KARTON (x5 KG) + 250 Gram remainder = 50.25 KG total', /50[.,]25/.test(totalText4), totalText4);
    await backToList(c1);

    // 5/6 — a LITER-base item ALWAYS shows the mL remainder (even with no
    // ML configured), and 250 mL normalizes to 0.25 Liter.
    await search.fill(skuLtrOnly);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('18.5. a LITER-base item ALWAYS shows the SISA SATUAN TERKECIL control (mL)', await c1.locator('.opname-remainder-block').isVisible());
    const remainderLabelLtr = await c1.locator('.opname-remainder-unit-label').innerText();
    check('18.5b. its remainder unit is mL', remainderLabelLtr === 'mL', remainderLabelLtr);
    const ltrRowInput = c1.locator('.opname-unit-input-row', { hasText: /^LTR\b/ }).locator('input');
    const remainderQtyLtr = c1.locator('.opname-remainder-qty');
    await remainderQtyLtr.fill('250');
    check('18.6. 250 mL remainder normalizes to 0.25 Liter in the base row', (await ltrRowInput.inputValue()) === '0.25');
    await backToList(c1);

    // 7 — a PCS-only item shows neither Gram nor mL.
    await search.fill(skuPcsOnly);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('18.7. a PCS-only item hides the remainder control entirely', (await c1.locator('.opname-remainder-block').count()) === 0);
    await backToList(c1);

    // Consistency check — an item that ALSO happens to carry a real,
    // separately-configured GR unit (itemKgGr) behaves identically: the
    // fix is driven by the base unit alone, not gated on whether GR
    // exists or not.
    await search.fill(skuKgGr);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('18.11. an item that ALSO has a real configured GR unit still shows the SAME base-unit-driven control', await c1.locator('.opname-remainder-block').isVisible());
    await backToList(c1);

    // ============================================================
    // 19 — MULTI UNIT COUNT INPUT (V2.14.11.6 expanded spec): every
    // valid frozen unit — INCLUDING the base unit — is its own editable
    // physical-count input, never collapsed into a read-only converted
    // total; Total Otomatis stays output-only and live; the remainder
    // control merges into a REAL GR/ML row when one exists rather than
    // ever creating a parallel/duplicate channel.
    // ============================================================
    const skuKgGrCtn = skuMap[seed.itemKgGr];
    const skuLtrPail = skuMap[seed.itemLtrOnly];
    const skuCtnPackPcs = skuMap[seed.itemCtnPackPcs];

    // 19.1/19.2/19.9 — a CTN+KG item renders BOTH as editable inputs, the
    // base unit (KG) is never hidden, and Total Otomatis is a separate
    // read-only output that never replaces it.
    await search.fill(skuKgOnly);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const unitRowsKgOnly = c1.locator('.opname-unit-input-row');
    check('19.1. a CTN+KG item renders BOTH CTN and KG as editable inputs', (await unitRowsKgOnly.count()) === 2);
    const kartonInputKgOnly = c1.locator('.opname-unit-input-row', { hasText: /^KARTON\b/ }).locator('input');
    const kgInputKgOnly = c1.locator('.opname-unit-input-row', { hasText: /^KG\b/ }).locator('input');
    check('19.2. the base unit (KG) is never hidden — it is a real, independently editable input', await kgInputKgOnly.isEditable());
    const totalTagName = await c1.locator('.opname-total-otomatis').evaluate((el) => el.tagName);
    check('19.9a. Total Otomatis is rendered as plain output (a DIV), never an input element', totalTagName === 'DIV', totalTagName);

    // 19.3 — CTN=10 + KG=3 => 53 KG.
    await kartonInputKgOnly.fill('10');
    await kgInputKgOnly.fill('3');
    const total3 = await c1.locator('.opname-total-otomatis').innerText();
    check('19.3. CTN=10 (x5 KG) + KG=3 calculates to 53 KG', /\b53\b/.test(total3) && !/53[.,]/.test(total3), total3);

    // 19.4 — + 250 Gram remainder => 53.25 KG, live.
    await c1.locator('.opname-remainder-qty').fill('250');
    const total4 = await c1.locator('.opname-total-otomatis').innerText();
    check('19.4. CTN=10 + KG=3 + 250 Gram remainder calculates to 53.25 KG, live', /53[.,]25/.test(total4), total4);
    check('19.9b. re-filling the base (KG) input after Total Otomatis updated still works — it was never replaced by the output', await kgInputKgOnly.isEditable());
    await backToList(c1);

    // 19.5/19.6 — a LITER-base item (LTR + PAIL, no ML configured) shows
    // Liter as an editable input, and Liter+mL remainder calculates
    // correctly (2 PAIL x20 LTR + 3 LTR + 250 mL remainder = 43.25 LTR).
    await search.fill(skuLtrPail);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const pailInput = c1.locator('.opname-unit-input-row', { hasText: /^PAIL\b/ }).locator('input');
    const ltrInput = c1.locator('.opname-unit-input-row', { hasText: /^LTR\b/ }).locator('input');
    check('19.5. a LITER-base item renders Liter as an editable input (never hidden)', await ltrInput.isEditable());
    await pailInput.fill('2');
    await ltrInput.fill('3');
    await c1.locator('.opname-remainder-qty').fill('250');
    const total6 = await c1.locator('.opname-total-otomatis').innerText();
    check('19.6. PAIL=2 (x20 LTR) + LTR=3 + 250 mL remainder calculates to 43.25 LTR', /43[.,]25/.test(total6), total6);
    await backToList(c1);

    // 19.7 — a CTN -> PACK -> PCS chain renders all three real units.
    await search.fill(skuCtnPackPcs);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    check('19.7. a CTN+PACK+PCS item renders all three as editable inputs', (await c1.locator('.opname-unit-input-row').count()) === 3);
    check('19.7b. a PCS-base item (even a 3-tier chain) shows no Gram/mL remainder', (await c1.locator('.opname-remainder-block').count()) === 0);
    await backToList(c1);

    // 19.8 — a plain PCS-only item shows exactly PCS.
    await search.fill(skuPcsOnly);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const unitLabelsPcsOnly = await c1.locator('.opname-unit-input-row label').allInnerTexts();
    check('19.8. a PCS-only item shows exactly one PCS input', unitLabelsPcsOnly.length === 1 && /^PCS\b/.test(unitLabelsPcsOnly[0]), JSON.stringify(unitLabelsPcsOnly));
    await backToList(c1);

    // 19.10/19.11/19.12 — itemKgGr now carries THREE real units (KARTON,
    // KG, GR). The remainder must merge into the REAL GR row (never KG),
    // so KARTON/KG/GR all stay independently auditable — no duplicate
    // contribution, and history preserves every entered unit exactly as
    // the spec's own example describes. The blind counter panel still
    // never leaks supervisor-only concepts (P1/P2 behavior unchanged).
    await search.fill(skuKgGrCtn);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const panelHtml19 = await c1.locator('.opname-counter-panel').innerHTML();
    check('19.12. the counter panel still never leaks system/opponent quantities (P1/P2 blind-count behavior unchanged)', !/system_qty|mismatch|variance/i.test(panelHtml19));
    const kartonInputKgGr = c1.locator('.opname-unit-input-row', { hasText: /^KARTON\b/ }).locator('input');
    const kgInputKgGr = c1.locator('.opname-unit-input-row', { hasText: /^KG\b/ }).locator('input');
    const grInputKgGr = c1.locator('.opname-unit-input-row', { hasText: /^GR\b/ }).locator('input');
    await kartonInputKgGr.fill('10');
    await kgInputKgGr.fill('3');
    await c1.locator('.opname-remainder-qty').fill('250');
    check('19.10a. the remainder merges into the REAL GR row (not KG) — KG stays exactly as typed (3)', (await kgInputKgGr.inputValue()) === '3');
    check('19.10b. the real GR row now holds the merged remainder (250)', (await grInputKgGr.inputValue()) === '250');
    const total1011 = await c1.locator('.opname-total-otomatis').innerText();
    check('19.10c. total is still exactly right (10 KARTON x5 + 3 KG + 250 GR x0.001 = 53.25 KG) with no double-counting', /53[.,]25/.test(total1011), total1011);
    await c1.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Simpan Hitungan') || b.textContent.includes('Simpan Temuan'));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    await c1.locator('button:has-text("💾 Simpan Hitungan"), button:has-text("💾 Simpan Temuan")').click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    const findingsKgGrCtn = await c1.evaluate(async (args) => {
        const res = await fetch(`/api/stock-opname/${args.sid}/items/${args.item}/my-findings`, { credentials: 'include' });
        return (await res.json()).data;
    }, { sid: seed.session_id, item: seed.itemKgGr });
    const goodEntriesKgGrCtn = findingsKgGrCtn[findingsKgGrCtn.length - 1].quantities.GOOD;
    check('19.10d. exactly one raw entry per unit is submitted — no duplicate contribution', new Set(goodEntriesKgGrCtn.map((q) => q.unit_code)).size === goodEntriesKgGrCtn.length, JSON.stringify(goodEntriesKgGrCtn));
    check('19.10e. each real unit keeps its own exact raw quantity (10 KARTON, 3 KG, 250 GR)',
        goodEntriesKgGrCtn.some((q) => q.unit_code === 'KARTON' && q.input_qty === 10)
        && goodEntriesKgGrCtn.some((q) => q.unit_code === 'KG' && q.input_qty === 3)
        && goodEntriesKgGrCtn.some((q) => q.unit_code === 'GR' && q.input_qty === 250),
        JSON.stringify(goodEntriesKgGrCtn));
    await c1.locator('.opname-history-toggle').click();
    const historyText19 = await c1.locator('.opname-history-list').innerText();
    check('19.11. Riwayat Temuan preserves every originally entered unit (10 KARTON + 3 KG + 250 GR), not just a collapsed KG total', /10 KARTON/.test(historyText19) && /3 KG/.test(historyText19) && /250 GR/.test(historyText19), historyText19);
    await backToList(c1);

    // 19.13 — zero horizontal scroll at 375/390/430px with the full
    // multi-unit form (3 real unit rows + remainder block) open. itemKgGr
    // was just counted above, so reopening it lands in SUMMARY mode
    // first (requirement 14 from the earlier round) — "+ Tambah Temuan"
    // opens the same full multi-unit form for this check.
    await search.fill(skuKgGrCtn);
    await c1.waitForSelector('.opname-suggest-row', { timeout: 8000 });
    await suggestRows.first().click();
    await c1.waitForSelector('.opname-counter-panel .card-title', { timeout: 8000 });
    const addFindingBtn19 = c1.locator('button:has-text("+ Tambah Temuan")');
    if (await addFindingBtn19.isVisible()) await addFindingBtn19.click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    for (const w of [375, 390, 430]) {
        await c1.setViewportSize({ width: w, height: 844 });
        await c1.waitForTimeout(100);
        const overflow19 = await c1.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
        check(`19.13. no horizontal scroll at ${w}px with the full multi-unit count form open`, !overflow19);
        await c1.screenshot({ path: path.join(screenshotDir, `19_${w}_multiunit_form.png`) });
    }
    await c1.setViewportSize({ width: 390, height: 844 });
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
