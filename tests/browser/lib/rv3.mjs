// Shared harness for the Reports v3 browser tests (real MariaDB + PHP API + Chromium, GET-only guard, console-error collector, print/Excel helpers).
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync, spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const repoRoot = path.resolve(here, '..', '..', '..');
export const shotDir = process.env.RV3_SHOT_DIR || path.join(repoRoot, 'tests', 'browser');
fs.mkdirSync(shotDir, { recursive: true });
export const dbName = process.env.DB_DATABASE || 'inventory_test';
export const results = [];
export function check(name, pass, detail = '') { results.push(!!pass); console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`); }
export const sh = (cmd) => execSync(cmd, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'], env: process.env, maxBuffer: 64 * 1024 * 1024 }).toString();
export const near = (a, b, e = 0.01) => Math.abs(Number(a) - Number(b)) <= e;
export const tid = (id) => `[data-testid="${id}"]`;
export const idr = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
export const norm = (s) => String(s).replace(/\s+/g, ' ').trim();
export const hasMoney = (txt, n) => norm(txt).includes(idr(n));
export const consoleErrors = [];
export const requests = [];
export const failed = [];

export function seedDb(seedScript) {
    sh(`mysql -uroot -e "DROP DATABASE IF EXISTS ${dbName}; CREATE DATABASE ${dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`);
    sh(`mysql -uroot ${dbName} < database/schema.sql`);
    return JSON.parse(sh(`php ${seedScript}`));
}

let phpServer; let proxy;
export let base = '';
export async function startServer() {
    const phpPort = 9400 + Math.floor(Math.random() * 400);
    phpServer = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', 'public', 'public/router.php'], { cwd: repoRoot, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } });
    let ready = false;
    for (let i = 0; i < 60 && !ready; i++) { await new Promise((r) => setTimeout(r, 200)); try { if ((await fetch(`http://127.0.0.1:${phpPort}/api/auth/me`)).status) ready = true; } catch (e) { /* retry */ } }
    if (!ready) { console.error('php server not ready'); process.exit(1); }
    const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.jpg': 'image/jpeg' };
    proxy = http.createServer((req, res) => {
        const urlPath = decodeURIComponent(req.url.split('?')[0]);
        if (urlPath.startsWith('/api/')) {
            const up = http.request({ host: '127.0.0.1', port: phpPort, path: req.url, method: req.method, headers: { ...req.headers, connection: 'close' }, agent: false }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
            up.on('error', () => { res.writeHead(502); res.end(); });
            req.pipe(up);
            return;
        }
        const staticRoot = process.env.RV3_STATIC_ROOT || path.join(repoRoot, 'public');
        const file = path.join(staticRoot, urlPath === '/' ? 'index.html' : urlPath);
        if (!file.startsWith(staticRoot) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('nf'); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        fs.createReadStream(file).pipe(res);
    });
    await new Promise((r) => proxy.listen(0, '127.0.0.1', r));
    base = `http://127.0.0.1:${proxy.address().port}`;
}
export async function stopServer() { if (proxy) { proxy.closeAllConnections(); proxy.close(); } if (phpServer) { phpServer.kill('SIGKILL'); await new Promise((r) => setTimeout(r, 200)); } }

export async function newSession(browser, opts, user) {
    const name = opts.__name || '';
    const { __name, ...ctxOpts } = opts;
    const context = await browser.newContext({ acceptDownloads: true, ...ctxOpts });
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${name} ${m.text()}`); });
    page.on('pageerror', (e) => consoleErrors.push(`${name} ${String(e)}`));
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/auth/')) failed.push(`${r.status()} ${r.url().replace(base, '')}`); });
    page.on('request', (r) => { if (r.url().includes('/api/') && !r.url().includes('/auth/')) requests.push({ method: r.method(), path: new URL(r.url()).pathname.replace(/^\/api/, '') }); });
    await page.goto(base + '/', { waitUntil: 'load', timeout: 20000 });
    await page.fill('#login-username', user.username);
    await page.fill('#login-password', user.password);
    await page.click('#login-submit');
    await page.waitForSelector('#app-shell', { state: 'visible', timeout: 10000 });
    return { context, page };
}
export const api = (page, p, q) => page.evaluate(async ([pp, qq]) => (await (await fetch(`/api${pp}?${new URLSearchParams(qq)}`, { credentials: 'include' })).json()).data, [p, q]);
export const text = async (loc) => norm(await loc.innerText());
export const shot = (page, name) => page.screenshot({ path: path.join(shotDir, `${name}.png`) });
export const noHScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1 && document.body.scrollWidth <= window.innerWidth + 1);

/** Click "Cetak" and capture the generated print document (window.print is stubbed: the dialog never opens). Returns the HTML string. */
export async function capturePrint(page, btnTestId) {
    await page.evaluate(() => { window.__printed = 0; });
    await page.evaluate(() => { const orig = HTMLIFrameElement.prototype; window.__origPrint = window.print; });
    const html = await page.evaluate(async (id) => {
        const btn = document.querySelector(`[data-testid="${id}"]`);
        const before = ReportTools.lastPrintHtml;
        btn.click();
        for (let i = 0; i < 100; i++) { await new Promise((r) => setTimeout(r, 100)); if (ReportTools.lastPrintHtml && ReportTools.lastPrintHtml !== before) return ReportTools.lastPrintHtml; if (i > 20 && ReportTools.lastPrintHtml) return ReportTools.lastPrintHtml; }
        return ReportTools.lastPrintHtml;
    }, btnTestId);
    await page.waitForTimeout(150);
    return html || '';
}
/** Assertions common to every print document. */
export function printChecks(label, html, { title, mustContain = [], mustNotContain = [], landscape = null }) {
    check(`${label} print: has the title "${title}", the print date, a white background + dark text, repeating header rows and bordered tables`,
        html.includes(`<h1>${title}</h1>`) && html.includes('Tanggal cetak:') && /background:\s*#fff\s*!important/.test(html) && /color:\s*#111\s*!important/.test(html) && html.includes('display: table-header-group') && /border:\s*1px solid/.test(html));
    check(`${label} print: no sidebar / buttons / filter controls in the document`, !/<(button|select|input|nav|aside)\b/i.test(html) && !/sidebar/i.test(html));
    if (landscape !== null) check(`${label} print: page orientation ${landscape ? 'landscape' : 'portrait'}`, html.includes(`size: A4 ${landscape ? 'landscape' : 'portrait'}`));
    mustContain.forEach((s) => check(`${label} print contains "${s}"`, html.includes(s)));
    mustNotContain.forEach((s) => check(`${label} print does NOT contain "${s}"`, !html.includes(s)));
}

/** Download the xlsx produced by a button and return { name, path, zip entries } (the file is parsed with PHP's XlsxReader through tests/lib/xlsx_dump.php). */
export async function downloadVia(page, btnTestId, saveTo) {
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click(tid(btnTestId))]);
    const name = dl.suggestedFilename();
    const file = path.join(saveTo, name);
    await dl.saveAs(file);
    return { name, file, size: fs.statSync(file).size };
}
export function xlsxDump(file) { return JSON.parse(sh(`php tests/lib/xlsx_dump.php ${JSON.stringify(file)}`)); }

export function finish(extra = []) {
    const nonGet = requests.filter((r) => r.method !== 'GET');
    check('only GET requests are issued to /api (read-only reports)', nonGet.length === 0, nonGet.map((r) => `${r.method} ${r.path}`).join(', '));
    check('no console / page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | ') + (failed.length ? ' :: ' + failed.slice(0, 5).join(', ') : ''));
    extra.forEach(([n, p, d]) => check(n, p, d));
    const pass = results.filter(Boolean).length;
    console.log(`\n${pass} / ${results.length} PASSED`);
    return pass === results.length;
}
