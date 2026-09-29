// PHASE V2.14.11.3 — URGENT HOTFIX: Playwright browser smoke against the
// REAL backend (no mocking): php -S serving the actual public/ app, MySQL
// seeded by tests/browser/seed_v21413.php. Drives the ENTIRE "Petugas
// Stock Opname" flow through the real UI exactly as a SUPERADMIN would:
// create two counter accounts, assign them to a session's P1/P2 team,
// then logs in as each counter in a separate browser context and proves
// the landing-tab + sidebar-minimization + server-side-403 requirements.
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_v21413.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const screenshotDir = '/tmp/v21413_screenshots';
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
const seedRaw = sh(`php tests/browser/seed_v21413.php`);
const seed = JSON.parse(seedRaw);
console.log('Seeded:', JSON.stringify({ session_id: seed.session_id, warehouse_id: seed.warehouse_id }));

// ---- boot the real app ----
const port = 8900 + Math.floor(Math.random() * 300) + 3900;
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
    const context = await browser.newContext({ viewport: viewport || { width: 1440, height: 900 } });
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

let browser;
try {
    browser = await chromium.launch();

    // ============================================================
    // SUPERADMIN: create Trial P1 / Trial P2 accounts, then assign
    // ============================================================
    const { context: adminCtx, page: admin } = await loginAs(browser, seed.admin, { width: 1440, height: 900 });
    await gotoTab(admin, 'opname', 'opname');
    await admin.waitForSelector('.card-title:has-text("Petugas Stock Opname")', { timeout: 8000 });

    async function createPetugas(page, fullName, username, password) {
        await page.click('button:has-text("+ Tambah Petugas")');
        await page.fill('input[placeholder="Nama Lengkap"]', fullName);
        await page.fill('input[placeholder="Username"]', username);
        await page.fill('input[placeholder="Password (min. 8 karakter)"]', password);
        await page.fill('input[placeholder="Konfirmasi Password"]', password);
        await page.click('button:has-text("Simpan Petugas")');
        await page.waitForSelector(`td:has-text("${username}")`, { timeout: 8000 });
    }

    const trialP1 = { username: 'trialp1' + Date.now().toString().slice(-6), password: 'TrialP1Secure123' };
    const trialP2 = { username: 'trialp2' + Date.now().toString().slice(-6), password: 'TrialP2Secure123' };
    await createPetugas(admin, 'Trial P1', trialP1.username, trialP1.password);
    await createPetugas(admin, 'Trial P2', trialP2.username, trialP2.password);
    const petugasRowCount = await admin.locator('table tbody tr', { hasText: 'Trial P' }).count();
    check('12a. SUPERADMIN creates both Petugas accounts via the real UI, both appear in the list', petugasRowCount === 2, `${petugasRowCount} rows`);
    await admin.screenshot({ path: path.join(screenshotDir, 'admin_petugas_list.png') });

    // ---- assign to P1/P2 team on the seeded session ----
    // The "Penugasan Tim P1 & Tim P2" card fetched its eligible-counters
    // list when the opname tab FIRST rendered, before Trial P1/P2 existed
    // — reload and re-navigate so it re-fetches with both new accounts
    // included (mirrors a real SUPERADMIN refreshing the page after
    // creating an account, exactly per this phase's own "create the
    // account first, assign second" two-step design).
    await admin.reload();
    await admin.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });
    await gotoTab(admin, 'opname', 'opname');
    await admin.waitForSelector('.card-title:has-text("Penugasan Tim P1 & Tim P2")', { timeout: 8000 });
    async function assignToTeam(page, username, roleLabel, saveLabel) {
        const card = page.locator('.card', { has: page.locator('.card-title:has-text("Penugasan Tim P1 & Tim P2")') });
        const editor = card.locator('.form-group', { has: page.locator(`label:has-text("Tim ${roleLabel}")`) });
        const select = editor.locator('select');
        const optionValue = await select.evaluate((el, uname) => {
            const opt = Array.from(el.options).find((o) => o.textContent.includes(uname));
            return opt ? opt.value : null;
        }, username);
        if (!optionValue) throw new Error(`option for ${username} not found in Tim ${roleLabel} dropdown`);
        await select.selectOption(optionValue);
        await editor.locator(`button:has-text("${saveLabel}")`).click();
        await page.waitForTimeout(500);
    }
    await assignToTeam(admin, trialP1.username, 'P1', 'Simpan Tim P1');
    await assignToTeam(admin, trialP2.username, 'P2', 'Simpan Tim P2');
    const teamBodyText = await admin.locator('.card', { has: admin.locator('.card-title:has-text("Penugasan Tim P1 & Tim P2")') }).innerText();
    check('assignment: both Trial P1 and Trial P2 now shown as assigned', teamBodyText.includes(trialP1.username) && teamBodyText.includes(trialP2.username), teamBodyText.slice(0, 300));
    await adminCtx.close();

    // ============================================================
    // Browser A — Trial P1
    // ============================================================
    const { context: p1Ctx, page: p1 } = await loginAs(browser, trialP1, { width: 1440, height: 900 });
    await p1.waitForSelector('#tab-opname-saya', { timeout: 8000 });
    const p1ActiveTab = await p1.evaluate(() => document.querySelector('.sidebar-link.active')?.dataset.tab);
    check('9a. Trial P1 lands DIRECTLY on Stock Opname Saya after login (never Dashboard)', p1ActiveTab === 'opname-saya', String(p1ActiveTab));
    const p1SessionVisible = await p1.locator('#tab-opname-saya').innerText();
    check('assigned session visible on Trial P1\'s Stock Opname Saya', p1SessionVisible.includes('Gudang:'));

    const p1SidebarVisible = await p1.evaluate(() => Array.from(document.querySelectorAll('.sidebar-link'))
        .filter((el) => el.style.display !== 'none')
        .map((el) => el.dataset.tab));
    check('9b. Trial P1 sidebar shows ONLY Stock Opname Saya (plus nothing else)', JSON.stringify(p1SidebarVisible) === JSON.stringify(['opname-saya']), JSON.stringify(p1SidebarVisible));
    const p1GroupsVisible = await p1.evaluate(() => Array.from(document.querySelectorAll('.sidebar-group'))
        .filter((el) => el.style.display !== 'none')
        .map((el) => el.querySelector('.sidebar-group-label')?.textContent));
    check('no dead empty accordion group left visible for Trial P1', p1GroupsVisible.every((g) => g === 'Stock Opname'), JSON.stringify(p1GroupsVisible));
    await p1.screenshot({ path: path.join(screenshotDir, 'trial_p1_landing.png') });

    const p1BlockedApi = await p1.evaluate(async () => {
        const res = await fetch('/api/reports/stock', { credentials: 'include' });
        return res.status;
    });
    check('Trial P1 direct API call to a permission-gated endpoint is blocked server-side (403)', p1BlockedApi === 403, String(p1BlockedApi));
    const p1BlockedAdminApi = await p1.evaluate(async () => {
        const res = await fetch('/api/stock-opname/counter-accounts', { credentials: 'include' });
        return res.status;
    });
    check('Trial P1 (a counter, not SUPERADMIN) cannot call the counter-accounts admin API (403)', p1BlockedAdminApi === 403, String(p1BlockedAdminApi));
    await p1Ctx.close();

    // ============================================================
    // Browser B — Trial P2
    // ============================================================
    const { context: p2Ctx, page: p2 } = await loginAs(browser, trialP2, { width: 1440, height: 900 });
    await p2.waitForSelector('#tab-opname-saya', { timeout: 8000 });
    const p2ActiveTab = await p2.evaluate(() => document.querySelector('.sidebar-link.active')?.dataset.tab);
    check('9c. Trial P2 lands DIRECTLY on Stock Opname Saya after login (never Dashboard)', p2ActiveTab === 'opname-saya', String(p2ActiveTab));
    const p2SidebarVisible = await p2.evaluate(() => Array.from(document.querySelectorAll('.sidebar-link'))
        .filter((el) => el.style.display !== 'none')
        .map((el) => el.dataset.tab));
    check('9d. Trial P2 sidebar shows ONLY Stock Opname Saya', JSON.stringify(p2SidebarVisible) === JSON.stringify(['opname-saya']), JSON.stringify(p2SidebarVisible));
    await p2.screenshot({ path: path.join(screenshotDir, 'trial_p2_landing.png') });
    await p2Ctx.close();

} finally {
    if (process.env.V21413_DEBUG_STDERR) {
        console.error('\n--- php -S stderr/stdout ---\n' + serverLog);
    }
    server.kill();
    if (browser) await browser.close();
}

console.log('\n==============================');
const total = results.length;
const passed = results.filter(Boolean).length;
console.log(`TOTAL: ${total}  PASSED: ${passed}  FAILED: ${total - passed}`);
if (passed !== total) process.exit(1);
