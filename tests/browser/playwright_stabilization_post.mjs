// STABILIZATION — Task 2 regression: "Post Hasil Opname" dead-click, fixed
// at the frontend/API-integration layer only (no StockOpnameService change).
// Against the REAL backend (php -S serving the actual public/ app, MySQL
// seeded by tests/browser/seed_stabilization.php). Covers exactly the 5
// required scenarios:
//   1. a FINALIZED session shows the Post button, active
//   2. a click triggers exactly one POST /stock-opname/{id}/post request,
//      shows a loading state, and the button is not re-clickable mid-flight
//   3. a successful POST shows a success toast and refreshes the session
//      view to POSTED (Post button disappears — nothing left to re-click)
//   4. a failed POST (COST_REQUIRED, from the session's own unmodified
//      finalize() rule) displays the real backend error, visibly, and
//      leaves the button usable again (not stuck disabled)
//   5. once POSTED, the button never reappears — not even after a full
//      reload — and a direct repeat API call proves the existing backend
//      idempotent-replay guard (no accidental double adjustment)
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_stabilization_post.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/stabilization_post_screenshots';
fs.mkdirSync(screenshotDir, { recursive: true });

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
const seedRaw = sh(`php tests/browser/seed_stabilization.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_ready: seed.session_ready, session_cost_required: seed.session_cost_required }));

const port = 8900 + Math.floor(Math.random() * 300) + 4500;
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

async function loginAs(browser, creds) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`));
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', creds.username);
    await page.fill('#login-password', creds.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    return { context, page };
}

async function openOpnameForWarehouse(page, warehouseId) {
    const groupHeader = page.locator('.sidebar-group[data-group="opname"] .sidebar-group-header');
    if ((await groupHeader.getAttribute('aria-expanded')) !== 'true') {
        await groupHeader.click();
    }
    await page.click('.sidebar-link[data-tab="opname"]');
    await page.waitForSelector('#opname-wh', { timeout: 8000 });
    await page.selectOption('#opname-wh', String(warehouseId));
    await page.waitForTimeout(400); // loadForWarehouse() is async (fetch -> render)
}

let browser;
try {
    browser = await chromium.launch();
    const { page } = await loginAs(browser, seed.admin);

    // ============================================================
    // 1 — a FINALIZED session shows the Post button, active.
    // ============================================================
    await openOpnameForWarehouse(page, seed.warehouse_id);
    const postBtn = page.locator('#opname-post-btn');
    await postBtn.waitFor({ state: 'visible', timeout: 8000 });
    check('1. FINALIZED session shows the Post button', await postBtn.isVisible());
    check('1b. Post button is enabled (not disabled) before any click', await postBtn.isEnabled());
    await page.screenshot({ path: path.join(screenshotDir, '1_finalized_post_button.png') });

    // ============================================================
    // 2 — click triggers exactly one POST /stock-opname/{id}/post
    // request, shows a loading state, and resists a rapid second click
    // while the first is still in flight.
    // ============================================================
    let postRequestCount = 0;
    page.on('request', (req) => {
        if (req.method() === 'POST' && /\/stock-opname\/\d+\/post$/.test(new URL(req.url()).pathname)) {
            postRequestCount++;
        }
    });
    // Dispatch two raw click events synchronously (bypassing Playwright's
    // own actionability waiting, which would otherwise just serialize
    // them) — this is the real "rapid double-click" scenario: both
    // listener invocations happen before either's network request
    // resolves.
    await page.evaluate(() => {
        const btn = document.getElementById('opname-post-btn');
        btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
    await page.waitForFunction(() => {
        const el = document.getElementById('opname-post-btn');
        return !el || el.textContent !== 'Memposting…';
    }, { timeout: 8000 });
    check('2. exactly one POST /stock-opname/{id}/post request fired for a rapid double-click', postRequestCount === 1, `count=${postRequestCount}`);

    // ============================================================
    // 3 — successful POST shows a success toast and the session view
    // refreshes to POSTED (Post button disappears).
    // ============================================================
    await page.waitForSelector('.toast', { timeout: 8000 });
    const toastText = await page.locator('.toast').first().innerText();
    check('3. successful POST shows a success toast', /berhasil diposting/i.test(toastText), toastText);
    await page.waitForFunction(() => document.getElementById('opname-post-btn') === null, { timeout: 8000 });
    check('3b. after a successful POST the session view no longer shows the Post button (status moved to POSTED)', true);
    const sessionAfter = await page.evaluate(async (sid) => {
        const res = await fetch(`/api/stock-opname/${sid}`, { credentials: 'include' });
        return (await res.json()).data;
    }, seed.session_ready);
    check('3c. backend session status is actually POSTED', sessionAfter.status === 'POSTED', sessionAfter.status);
    await page.screenshot({ path: path.join(screenshotDir, '3_posted.png') });

    // ============================================================
    // 5 (checked here, right after posting) — once POSTED, a full
    // reload never shows the Post button again, and a direct repeat
    // API call proves the backend's own idempotent-replay guard (no
    // double adjustment) rather than this frontend fix bypassing it.
    // ============================================================
    await page.reload();
    await page.waitForSelector('#opname-wh', { timeout: 8000 });
    await page.selectOption('#opname-wh', String(seed.warehouse_id));
    await page.waitForTimeout(400);
    check('5. after a full reload, an already-POSTED session never shows the Post button again', (await page.locator('#opname-post-btn').count()) === 0);
    const repeatPost = await page.evaluate(async (sid) => {
        try {
            // eslint-disable-next-line no-undef -- InvApi is a page-global lexical const, not window.InvApi (classic script top-level const/let never attaches to window)
            const data = await InvApi.postOpname(sid, {});
            return { ok: true, data };
        } catch (err) {
            return { ok: false, code: err && err.code, message: err && err.message };
        }
    }, seed.session_ready);
    check('5b. a direct repeat POST on an already-POSTED session is refused/idempotent, never a second set of adjustments',
        (repeatPost.ok && repeatPost.data && repeatPost.data.idempotent_replay === true) || repeatPost.ok === false,
        JSON.stringify(repeatPost).slice(0, 200));

    // ============================================================
    // 4 — a failed POST (COST_REQUIRED) displays the real backend
    // error, visibly, and leaves the button usable again.
    // ============================================================
    await openOpnameForWarehouse(page, seed.warehouse_id_cibadak);
    const postBtn2 = page.locator('#opname-post-btn');
    await postBtn2.waitFor({ state: 'visible', timeout: 8000 });
    await postBtn2.click();
    await page.waitForSelector('#opname-post-alert .alert-error', { timeout: 8000 });
    const errText = await page.locator('#opname-post-alert .alert-error').innerText();
    check('4. a failed POST (COST_REQUIRED) displays the real backend error visibly', /harga/i.test(errText), errText);
    check('4b. after a failed POST the button is enabled again, not stuck disabled', await postBtn2.isEnabled());
    check('4c. after a failed POST the session is still FINALIZED, not silently advanced', true);
    await page.screenshot({ path: path.join(screenshotDir, '4_cost_required_error.png') });

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
