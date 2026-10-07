// Sidebar "Laporan" — the menu shows EXACTLY the five approved reports (production UI correction). Real application, real browser.
//   Scenario A: the committed index.html.
//   Scenario B: a hostile / stale index.html (11 old links flat in the Laporan submenu, wrong labels, a duplicate row, old links in another group) served with the NEW assets
//               -> report-tools.js + the CSS safety net must still leave exactly five visible report links.
// For every scenario and viewport: visible report links === 5 with the exact labels, none of the old labels visible anywhere in the sidebar, same row height / font as the other
// sidebar links, no duplicate and no blank row in the submenu, the old routes still reachable internally, the five links open their pages. Never writes.
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw RV3_SHOT_DIR=/some/dir node tests/browser/playwright_sidebar_reports.mjs
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import * as T from './lib/rv3.mjs';
const { check } = T;

const seed = T.seedDb('tests/browser/seed_valuation_report.php');
await T.startServer();
const LABELS = ['Laporan Pergerakan Stok', 'Laporan IN / OUT', 'Laporan Pembelian', 'Laporan Nilai HPP', 'Laporan Stock Opname'];
const TABS = ['laporan-pergerakan', 'laporan-inout', 'laporan-pembelian', 'laporan-hpp', 'laporan-opname'];
const OLD = ['Ringkasan Inventory', 'Pergerakan Stok Harian', 'Laporan Stok', 'Laporan Transfer', 'Adjustment / Selisih', 'Expired / Near Expired', 'Pembelian per Supplier', 'Distribusi per Bakery', 'Slow / No Movement', 'Rekonsiliasi Arus Stok', 'Audit Transaksi', 'Nilai Stok & HPP', 'Laporan P1/P2 Stock Opname'];

// --- the stale index.html: the layout the owner reported (every old link visible) + wrong labels + duplicates, built from the committed file
const pub = path.join(T.repoRoot, 'public');
const staleRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'sbstale_'));
fs.cpSync(pub, staleRoot, { recursive: true });
let html = fs.readFileSync(path.join(pub, 'index.html'), 'utf8');
const link = (tab, label, icon = '📄') => `<a class="sidebar-link" data-tab="${tab}" data-require-permission="INVENTORY_VIEW"><span class="icon">${icon}</span> ${label}</a>`;
const g0 = html.indexOf('data-group="laporan"');
const s0 = html.indexOf('<div class="sidebar-submenu"', g0);
const s0end = html.indexOf('</div>', s0);                       // the submenu holds only <a> links: first </div> closes it
const oldFlat = [['laporan-ringkasan', 'Ringkasan Inventory'], ['laporan-pergerakan', 'Pergerakan Stok Harian'], ['laporan-stok', 'Laporan Stok'], ['laporan-transfer', 'Laporan Transfer'], ['laporan-adjustment', 'Adjustment / Selisih'],
    ['laporan-expiry', 'Expired / Near Expired'], ['laporan-supplier', 'Pembelian per Supplier'], ['laporan-bakery', 'Distribusi per Bakery'], ['laporan-slow-movement', 'Slow / No Movement'], ['laporan-rekonsiliasi', 'Rekonsiliasi Arus Stok'], ['laporan-audit', 'Audit Transaksi'],
    ['laporan-inout', 'Laporan IN / OUT'], ['laporan-pembelian', 'Laporan Pembelian'], ['laporan-hpp', 'Nilai Stok & HPP'], ['laporan-opname', 'Laporan Stock Opname'], ['laporan-inout', 'Laporan IN / OUT (dobel)'], ['opname-laporan', 'Laporan P1/P2 Stock Opname']].map(([t, l]) => link(t, l)).join('\n');
html = html.slice(0, s0) + html.slice(s0, html.indexOf('>', s0) + 1) + '\n' + oldFlat + '\n' + html.slice(s0end);
// the legacy hidden container is dropped too: the stale file is the plain old layout
const c0 = html.indexOf('<div class="sidebar-legacy-routes"');
if (c0 >= 0) { let depth = 0; let i = c0; const re = /<(\/?)div\b[^>]*>/g; re.lastIndex = c0; let m; while ((m = re.exec(html))) { depth += m[1] ? -1 : 1; if (depth === 0) { i = re.lastIndex; break; } } html = html.slice(0, c0) + html.slice(i); }
fs.writeFileSync(path.join(staleRoot, 'index.html'), html);

const visibleReportLinks = (page) => page.evaluate(() => {
    const nav = document.getElementById('sidebar');
    const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
    const lab = (a) => { const c = a.cloneNode(true); c.querySelectorAll('.icon').forEach((n) => n.remove()); return c.textContent.replace(/\s+/g, ' ').trim(); };
    const all = Array.from(nav.querySelectorAll('a.sidebar-link'));
    return {
        reports: all.filter((a) => /^(laporan-|opname-laporan)/.test(a.dataset.tab) && vis(a)).map((a) => [a.dataset.tab, lab(a)]),
        visibleText: all.filter(vis).map(lab),
        total: all.length,
    };
});
async function openLaporan(page, narrow) {
    if (narrow) { await page.click('#sidebar-toggle-btn'); await page.waitForTimeout(300); }
    const expanded = await page.evaluate(() => document.querySelector('[data-group="laporan"] .sidebar-group-header').getAttribute('aria-expanded'));
    if (expanded !== 'true') { await page.click('[data-group="laporan"] .sidebar-group-header'); await page.waitForTimeout(300); }
}

const browser = await chromium.launch();
const scenarios = [['A committed index.html', pub], ['B stale/hostile index.html', staleRoot]];
const viewports = [['1536', { width: 1536, height: 864 }, false], ['1366', { width: 1366, height: 768 }, false], ['iPad landscape', { width: 1180, height: 820 }, false], ['iPad portrait', { width: 820, height: 1180 }, true]];
try {
    for (const [sname, root] of scenarios) {
        process.env.RV3_STATIC_ROOT = root;
        for (const [vname, vp, narrow] of viewports) {
            const label = `${sname} @ ${vname}`;
            const { page } = await T.newSession(browser, { viewport: vp, __name: label }, seed.admin);
            await openLaporan(page, narrow);
            await page.waitForTimeout(400);
            const r = await visibleReportLinks(page);
            check(`${label}: visible report links count === 5`, r.reports.length === 5, JSON.stringify(r.reports));
            check(`${label}: labels exactly ${LABELS.join(' / ')} (in this order)`, JSON.stringify(r.reports.map((x) => x[1])) === JSON.stringify(LABELS) && JSON.stringify(r.reports.map((x) => x[0])) === JSON.stringify(TABS), r.reports.map((x) => x[1]).join(' | '));
            const leaked = OLD.filter((o) => r.visibleText.includes(o));
            check(`${label}: none of the old report labels is visible anywhere in the sidebar`, leaked.length === 0, leaked.join(', '));
            check(`${label}: no duplicate visible label in the sidebar`, new Set(r.visibleText).size === r.visibleText.length, r.visibleText.filter((t, i) => r.visibleText.indexOf(t) !== i).join(', '));
            // same typography / row height as the other sidebar links; no blank rows in the submenu
            const m = await page.evaluate(() => {
                const cs = (e) => { const s = getComputedStyle(e); const r = e.getBoundingClientRect(); return { h: Math.round(r.height), fs: s.fontSize, fw: s.fontWeight, ic: Math.round(e.querySelector('.icon').getBoundingClientRect().width), pl: s.paddingLeft }; };
                const rep = Array.from(document.querySelectorAll('[data-group="laporan"] a.sidebar-link')).filter((a) => a.getBoundingClientRect().height > 0);
                const dash = document.querySelector('a.sidebar-link[data-tab="dashboard"]');
                const sub = document.querySelector('[data-group="laporan"] .sidebar-submenu');
                return { rep: rep.map(cs), dash: cs(dash), subH: Math.round(sub.getBoundingClientRect().height), sumH: rep.reduce((a, e) => a + Math.round(e.getBoundingClientRect().height), 0), n: rep.length };
            });
            check(`${label}: the five rows share one height / font size / weight / icon size and equal the other sidebar links`, m.rep.every((x) => x.h === m.rep[0].h && x.fs === m.rep[0].fs && x.fw === m.rep[0].fw && x.ic === m.rep[0].ic) && m.rep[0].fs === m.dash.fs && m.rep[0].fw === m.dash.fw && Math.abs(m.rep[0].h - m.dash.h) <= 1, JSON.stringify([m.rep[0], m.dash]));
            check(`${label}: no hidden blank rows (submenu height ≈ five rows)`, m.n === 5 && m.subH - m.sumH <= 40, JSON.stringify({ subH: m.subH, sumH: m.sumH, n: m.n }));
            // every one of the five opens its page
            for (let i = 0; i < 5; i++) {
                if (narrow && i > 0) { await page.click('#sidebar-toggle-btn'); await page.waitForTimeout(250); await openLaporan(page, false); }
                await page.evaluate((t) => document.querySelector(`#sidebar a.sidebar-link[data-tab="${t}"]:not([data-rv3-hide])`).click(), TABS[i]);
                await page.waitForSelector(`#tab-${TABS[i]}.active`, { timeout: 8000 }).catch(() => {});
                const act = await page.evaluate((t) => document.querySelector(`#tab-${t}`).classList.contains('active') && document.querySelector(`#tab-${t}`).innerText.length > 40, TABS[i]);
                if (!act) check(`${label}: ${LABELS[i]} opens`, false);
            }
            check(`${label}: the five links open their pages`, true);
            // old routes are still navigable internally (nothing deleted): activating one shows its page
            const oldOk = await page.evaluate(async () => {
                const hidden = document.querySelector('#sidebar a.sidebar-link[data-tab="laporan-stok"]');
                if (!hidden) return false;
                hidden.click();
                await new Promise((r) => setTimeout(r, 900));
                const t = document.getElementById('tab-laporan-stok');
                return !!t && t.classList.contains('active');
            });
            check(`${label}: an old report route (laporan-stok) is still reachable internally`, oldOk);
            const nVisActive = await page.evaluate(() => Array.from(document.querySelectorAll('.tab-content')).filter((e) => e.offsetParent !== null).length);
            check(`${label}: exactly ONE page is displayed after navigating through six pages (no stale page left above)`, nVisActive === 1, String(nVisActive));
            if (vname === '1536' || vname === 'iPad portrait') await T.shot(page, `sidebar-reports-${sname[0]}-${vname.replace(' ', '-')}`);
            await page.context().close();
        }
    }
} finally {
    await browser.close();
    await T.stopServer();
}
process.exit(T.finish() ? 0 : 1);
