// PHASE V2.14.11.7 HOTFIX — Stock Opname FINDINGS_V1: "Simpan Hitungan" must
// never require GOOD > 0. Physical Qty = GOOD + DAMAGED + EXPIRED +
// DEADSTOCK, so a finding with GOOD=0/DAMAGED=0/EXPIRED=0/DEADSTOCK>0 (photo
// attached) must save successfully. Covers CASE A-G from the production
// hotfix report, against the REAL backend (php -S serving the actual
// public/ app, MySQL seeded by the existing tests/browser/seed_v21415.php —
// reused unchanged, since its sessionOne/items/counterOne fixtures already
// cover everything this hotfix needs).
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v21417_hotfix.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v21417_screenshots';
fs.mkdirSync(screenshotDir, { recursive: true });

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

function sh(cmd) {
    return execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

async function makeFakePhoto() {
    const p = path.join('/tmp', `pw_photo_v21417_${Date.now()}_${Math.random().toString(36).slice(2)}.jpg`);
    execSync(`php -r "\\$i=imagecreatetruecolor(8,8); imagefill(\\$i,0,0,imagecolorallocate(\\$i,90,140,220)); imagejpeg(\\$i,'${p}',90);"`);
    return p;
}

// ---- fresh DB + seed (reuses the existing V2.14.11.5 seed unchanged) ----
sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seedRaw = sh(`php tests/browser/seed_v21415.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 4700;
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

async function backToList(page) {
    const backBtn = page.locator('button:has-text("Kembali ke Daftar")');
    if (await backBtn.count()) await backBtn.first().click();
}

// Opens an item's count panel directly by exact SKU (deterministic, no
// suggestion-row race).
async function openBySku(page, sku) {
    const search = page.locator('.opname-blind-search');
    await search.fill(sku);
    await search.press('Enter');
    await page.waitForSelector('.opname-condition-block, .opname-history-toggle', { timeout: 8000 });
}

const saveBtnSel = 'button:has-text("💾 Simpan Hitungan"), button:has-text("💾 Simpan Temuan")';

let browser;
try {
    browser = await chromium.launch();
    const { page: c1 } = await loginAs(browser, seed.counterOne);
    await c1.waitForSelector('#tab-opname-saya .opname-counter-toolbar', { timeout: 8000 });

    const skuMap = await fetchSkuMap(c1, seed.session_id);
    const skuCoklat1 = skuMap[seed.itemCoklat1];     // CASE A
    const skuCoklat2 = skuMap[seed.itemCoklat2];     // CASE B
    const skuPlain = skuMap[seed.itemPlain];         // CASE C
    const skuBarcode = skuMap[seed.itemBarcode];     // CASE D
    const skuAlready = skuMap[seed.itemAlready];     // CASE E (already has 1 finding)
    const skuConflict = skuMap[seed.itemConflict];   // CASE F
    const skuPcsOnly = skuMap[seed.itemPcsOnly];     // CASE G

    // ============================================================
    // CASE A — DEADSTOCK ONLY: GOOD 0, DAMAGED 0, EXPIRED 0,
    // DEADSTOCK 90850, photo deadstock ada -> SAVE BERHASIL
    // ============================================================
    await openBySku(c1, skuCoklat1);
    const blocksA = c1.locator('.opname-condition-block');
    const deadstockQtyA = blocksA.nth(2).locator('input[type="number"]');
    const saveBtnA = c1.locator(saveBtnSel);
    await deadstockQtyA.fill('90850');
    check('CASE A1. Simpan still disabled before the required Deadstock photo is attached', await saveBtnA.isDisabled());
    const photoA = await makeFakePhoto();
    await blocksA.nth(2).locator('input[type="file"]').setInputFiles(photoA);
    await c1.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => /Simpan (Hitungan|Temuan)/.test(b.textContent));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    check('CASE A2. Simpan enabled with GOOD=0/DAMAGED=0/EXPIRED=0/DEADSTOCK=90850 + photo (GOOD is NOT required)', true);
    await saveBtnA.click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    const historyToggleA = c1.locator('.opname-history-toggle');
    if (await historyToggleA.count()) await historyToggleA.first().click();
    await c1.waitForSelector('.opname-finding-row', { timeout: 8000 });
    const histTextA = await c1.locator('.opname-finding-row').first().innerText();
    check('CASE A3. SAVE BERHASIL — finding recorded with Deadstock 90850', /90850/.test(histTextA) || /Deadstock/i.test(histTextA), histTextA.slice(0, 120));
    await backToList(c1);

    // ============================================================
    // CASE B — DAMAGED ONLY: DAMAGED 10, others 0, photo damaged ada
    // -> SAVE BERHASIL
    // ============================================================
    await openBySku(c1, skuCoklat2);
    const blocksB = c1.locator('.opname-condition-block');
    const saveBtnB = c1.locator(saveBtnSel);
    await blocksB.nth(0).locator('input[type="number"]').fill('10');
    const photoB = await makeFakePhoto();
    await blocksB.nth(0).locator('input[type="file"]').setInputFiles(photoB);
    await c1.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => /Simpan (Hitungan|Temuan)/.test(b.textContent));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    check('CASE B1. Simpan enabled with only DAMAGED=10 + photo', true);
    await saveBtnB.click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    check('CASE B2. SAVE BERHASIL', true);
    await backToList(c1);

    // ============================================================
    // CASE C — EXPIRED ONLY: EXPIRED 5, others 0, photo expired ada
    // -> SAVE BERHASIL
    // ============================================================
    await openBySku(c1, skuPlain);
    const blocksC = c1.locator('.opname-condition-block');
    const saveBtnC = c1.locator(saveBtnSel);
    await blocksC.nth(1).locator('input[type="number"]').fill('5');
    const photoC = await makeFakePhoto();
    await blocksC.nth(1).locator('input[type="file"]').setInputFiles(photoC);
    await c1.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => /Simpan (Hitungan|Temuan)/.test(b.textContent));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    check('CASE C1. Simpan enabled with only EXPIRED=5 + photo', true);
    await saveBtnC.click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    check('CASE C2. SAVE BERHASIL', true);
    await backToList(c1);

    // ============================================================
    // CASE D — GOOD ONLY: GOOD 20, others 0 -> behavior unchanged
    // (no photo needed for GOOD, no zero-confirm needed since GOOD>0)
    // ============================================================
    await openBySku(c1, skuBarcode);
    const saveBtnD = c1.locator(saveBtnSel);
    await c1.locator('.opname-unit-input-row input').first().fill('20');
    check('CASE D1. Simpan enabled with GOOD=20 alone, no confirmation/photo needed', !(await saveBtnD.isDisabled()));
    await saveBtnD.click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    check('CASE D2. behavior unchanged — SAVE BERHASIL as before', true);
    await backToList(c1);

    // ============================================================
    // CASE E — ALL ZERO ADDITIONAL FINDING on an already-counted item
    // -> DITOLAK (rejected), even though the client-side zero-confirm
    // checkbox can be ticked (that checkbox only satisfies the CLIENT
    // gate; the server's Gate 3 still refuses an all-zero ADDITIONAL
    // finding).
    // ============================================================
    await openBySku(c1, skuAlready);
    await c1.locator('button:has-text("+ Tambah Temuan")').click();
    await c1.waitForSelector('.opname-condition-block', { timeout: 8000 });
    const saveBtnE = c1.locator(saveBtnSel);
    check('CASE E1. Simpan disabled while all four conditions are 0 and confirmation unchecked', await saveBtnE.isDisabled());
    await c1.locator('#opname-zero-confirm-chk').check();
    check('CASE E2. Simpan becomes clickable once the zero-confirm box is ticked (client gate only)', !(await saveBtnE.isDisabled()));
    await saveBtnE.click();
    await c1.waitForTimeout(400);
    const alertTextE = await c1.locator('.alert-error').first().innerText().catch(() => '');
    check('CASE E3. DITOLAK — server rejects an all-zero ADDITIONAL finding', /physical quantity greater than zero|zero-count result is only valid/i.test(alertTextE), alertTextE);
    await backToList(c1);

    // ============================================================
    // CASE F — DEADSTOCK 10, TANPA FOTO -> DITOLAK, pesan foto wajib
    // ============================================================
    await openBySku(c1, skuConflict);
    const blocksF = c1.locator('.opname-condition-block');
    const saveBtnF = c1.locator(saveBtnSel);
    await blocksF.nth(2).locator('input[type="number"]').fill('10');
    await c1.waitForTimeout(150);
    check('CASE F1. Simpan stays disabled — Deadstock qty > 0 with no photo attached', await saveBtnF.isDisabled());
    // Prove the backend itself also refuses this (defense in depth), not
    // just the client button state, by calling InvApi directly, bypassing
    // the disabled button (same claim/submit calls saveFinding() uses).
    const backendRejectF = await c1.evaluate(async ({ sessionId, itemId }) => {
        try {
            const units = await InvApi.opnameItemUnits(sessionId, itemId);
            const unitList = units.data || units;
            const baseUnitId = unitList.find((u) => u.is_base_unit).unit_id;
            const claim = await InvApi.claimOpnameItem(sessionId, 'p1', itemId);
            await InvApi.submitOpnameFinding(sessionId, 'p1', itemId, {
                GOOD: [{ unit_id: baseUnitId, qty: 0 }],
                DAMAGED: [{ unit_id: baseUnitId, qty: 0 }],
                EXPIRED: [{ unit_id: baseUnitId, qty: 0 }],
                DEADSTOCK: [{ unit_id: baseUnitId, qty: 10 }],
            }, null, claim.claim_token, { DAMAGED: [], EXPIRED: [], DEADSTOCK: [] });
            return { rejected: false, message: 'unexpectedly succeeded' };
        } catch (err) {
            return { rejected: true, message: (err && err.message) || String(err) };
        }
    }, { sessionId: seed.session_id, itemId: seed.itemConflict }).catch((e) => ({ rejected: false, message: String(e) }));
    check('CASE F2. DITOLAK dengan pesan bahwa foto Deadstock wajib (backend, defense in depth)',
        backendRejectF.rejected && /DEADSTOCK photo evidence is required/i.test(backendRejectF.message),
        backendRejectF.message);
    await backToList(c1);
    await c1.reload();
    await c1.waitForSelector('#tab-opname-saya .opname-counter-toolbar', { timeout: 8000 });

    // ============================================================
    // CASE G — MULTI CONDITION: GOOD 100, DAMAGED 5, EXPIRED 3,
    // DEADSTOCK 20, all photos -> SAVE BERHASIL
    // ============================================================
    await openBySku(c1, skuPcsOnly);
    const blocksG = c1.locator('.opname-condition-block');
    const saveBtnG = c1.locator(saveBtnSel);
    await c1.locator('.opname-unit-input-row input').first().fill('100');
    await blocksG.nth(0).locator('input[type="number"]').fill('5');
    await blocksG.nth(1).locator('input[type="number"]').fill('3');
    await blocksG.nth(2).locator('input[type="number"]').fill('20');
    check('CASE G1. Simpan disabled until all three required photos are attached', await saveBtnG.isDisabled());
    for (let i = 0; i < 3; i++) {
        const photo = await makeFakePhoto();
        await blocksG.nth(i).locator('input[type="file"]').setInputFiles(photo);
    }
    await c1.waitForFunction(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => /Simpan (Hitungan|Temuan)/.test(b.textContent));
        return btn && !btn.disabled;
    }, { timeout: 8000 });
    check('CASE G2. Simpan enabled once GOOD/DAMAGED/EXPIRED/DEADSTOCK are all positive with all photos attached', true);
    await saveBtnG.click();
    await c1.waitForSelector('.opname-history-toggle', { timeout: 8000 });
    const historyToggleG = c1.locator('.opname-history-toggle');
    if (await historyToggleG.count()) await historyToggleG.first().click();
    await c1.waitForSelector('.opname-finding-row', { timeout: 8000 });
    const histTextG = await c1.locator('.opname-finding-row').first().innerText();
    check('CASE G3. SAVE BERHASIL — multi-condition finding recorded', /100/.test(histTextG) && /5/.test(histTextG) && /3/.test(histTextG) && /20/.test(histTextG), histTextG.slice(0, 200));

    await c1.screenshot({ path: path.join(screenshotDir, 'final_state.png') });
} catch (err) {
    console.error('FATAL:', err);
    results.push(false);
} finally {
    if (browser) await browser.close();
    server.kill();
}

const passCount = results.filter(Boolean).length;
console.log(`\n${passCount}/${results.length} PASSED`);
process.exit(results.every(Boolean) ? 0 : 1);
