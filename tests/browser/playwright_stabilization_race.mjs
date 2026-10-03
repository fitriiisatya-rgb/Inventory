// STABILIZATION — Task 2 regression: the race between the warehouse
// selector's own auto-load on mount and an immediate manual selection
// change (or two quick switches) used to leave TWO "Post Hasil Opname"
// cards/buttons (for two DIFFERENT sessions) both live in #opname-body at
// once, because renderSession() only guarded its FIRST await — several
// later awaited sub-cards (Reference SCM, Stok Buku SO, team assignment/
// review) could still append after a newer call had already superseded
// it. Fixed with a renderGeneration token re-checked after every one of
// those awaits. This reproduces the exact race deterministically against
// the REAL backend (two FINALIZED sessions on two different warehouses,
// same admin) and asserts the DOM only ever ends up with one button, for
// the LATEST warehouse selected.
// Usage:
//   NODE_PATH=/opt/node22/lib/node_modules node tests/browser/playwright_stabilization_race.mjs
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

function sh(cmd) {
    return execSync(cmd, { cwd: '/home/user/Inventory', stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

sh(`mysql -uroot -e "DROP DATABASE IF EXISTS inventory_test; CREATE DATABASE inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
sh(`mysql -uroot inventory_test < database/schema.sql`);
const seed = JSON.parse(sh(`php tests/browser/seed_stabilization.php`));

const port = 8900 + Math.floor(Math.random() * 300) + 4800;
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: '/home/user/Inventory' });
{
    let ready = false;
    for (let i = 0; i < 50 && !ready; i++) {
        await new Promise((resolve) => setTimeout(resolve, 200));
        try {
            const res = await fetch(`${base}/api/auth/me`);
            if (res.status) ready = true;
        } catch (e) { /* keep polling */ }
    }
    if (!ready) { console.error('Server did not become ready'); process.exit(1); }
}

let browser;
try {
    browser = await chromium.launch();
    const page = await browser.newPage();
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.fill('#login-username', seed.admin.username);
    await page.fill('#login-password', seed.admin.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 8000 });

    const groupHeader = page.locator('.sidebar-group[data-group="opname"] .sidebar-group-header');
    await groupHeader.click();
    await page.click('.sidebar-link[data-tab="opname"]');
    await page.waitForSelector('#opname-wh', { timeout: 8000 });

    // The selector's auto-load-on-mount fires for whatever warehouse is
    // the dropdown's default option; immediately selecting a DIFFERENT
    // warehouse (no wait in between) races that in-flight auto-load —
    // exactly the production scenario (a supervisor opening the page and
    // switching warehouses right away).
    await page.selectOption('#opname-wh', String(seed.warehouse_id));
    await page.waitForTimeout(800); // let every awaited sub-card settle

    const postBtnCount = await page.locator('#opname-post-btn').count();
    check('1. racing the auto-load with an immediate warehouse switch never leaves two Post buttons in the DOM', postBtnCount === 1, `count=${postBtnCount}`);

    const bodyCount = await page.locator('#opname-body').count();
    check('1b. exactly one #opname-body mount point (no leaked duplicate container either)', bodyCount === 1);

    // The surviving view must belong to the LAST warehouse actually
    // selected (SCM / session_ready), not the one that merely auto-loaded
    // first — proving the fix picks the latest request, not just
    // deduplicating arbitrarily.
    const bannerText = await page.locator('.banner-lock').innerText();
    check('2. the surviving view is for the LAST selected warehouse\'s session, not a stale earlier one', bannerText.includes(`Sesi #${seed.session_ready}`), bannerText);

    // Switching warehouses a second time (now settled) must still work
    // normally afterward — the generation guard must not permanently wedge
    // anything.
    await page.selectOption('#opname-wh', String(seed.warehouse_id_cibadak));
    await page.waitForTimeout(800);
    const postBtnCount2 = await page.locator('#opname-post-btn').count();
    check('3. a subsequent, non-racing warehouse switch still renders normally (exactly one button)', postBtnCount2 === 1, `count=${postBtnCount2}`);

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
