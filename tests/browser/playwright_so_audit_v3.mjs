// Laporan Stock Opname (reports v3 presentation: public/assets/js/report-opname-audit.js) — REAL data through the real UI (real MariaDB + PHP API + Chromium).
// Fixture: tests/browser/seed_so_audit_v3.php = the Jejak fixture (one LEGACY_DUAL_COUNT + one FINDINGS_V1 POSTED session with real evidence photos, a voided finding,
// real posted adjustments) + 12 header-only sessions (6 OPEN / 6 CANCELLED, September 2026). Every number on screen is compared with the API; print + Excel + CSV are
// checked from the generated documents; layout is measured at 1536x900, 1366x768, iPad landscape 1180x820 and iPad portrait 820x1180.
//   DB_DATABASE=inventory_so3 DB_USERNAME=inv DB_PASSWORD=invpw SO3_SHOT_DIR=/some/dir node tests/browser/playwright_so_audit_v3.mjs
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

process.env.RV3_SHOT_DIR = process.env.SO3_SHOT_DIR || process.env.RV3_SHOT_DIR || path.join(os.tmpdir(), 'so3_shots');
const T = await import('./lib/rv3.mjs');
const { check, tid, near, text, norm, sh } = T;

const seed = T.seedDb('tests/browser/seed_so_audit_v3.php');
await T.startServer();
const L = seed.legacy; const V = seed.findings;
const dl = fs.mkdtempSync(path.join(os.tmpdir(), 'so3dl_'));
const TAB = 'laporan-opname';
const DAY = '2026-09-30';
const idr = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const idle = (page) => page.waitForFunction(() => !document.querySelector('.soa3-busy,[data-testid="soa3-loading"],[data-testid="soa3-items-loading"]'), null, { timeout: 25000 });
const root = (page) => page.locator(`#tab-${TAB}`);
async function open(page, tab = TAB) {
    await page.evaluate((t) => document.querySelector(`.sidebar-link[data-tab="${t}"]`).click(), tab);
    await page.waitForSelector(`#tab-${tab}.active ${tid('soa3-title')}`);
    await idle(page);
}
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('soa3-from'), f.from);
    if (f.to !== undefined) await page.fill(tid('soa3-to'), f.to);
    if (f.wh !== undefined) await page.selectOption(tid('soa3-wh'), f.wh);
    if (f.status !== undefined) await page.selectOption(tid('soa3-status'), f.status);
    if (f.q !== undefined) await page.fill(tid('soa3-q'), f.q);
    await page.click(tid('soa3-apply'));
    await page.waitForTimeout(250);
    await idle(page);
}
const rows = (page, id) => page.locator(tid(id)).count();
const headsOf = (page, table) => page.evaluate((t) => Array.from(document.querySelectorAll(`[data-testid="${t}"] thead th`)).map((th) => th.textContent.trim()), table);
async function sessCell(page, sid, label) {
    const hs = await headsOf(page, 'soa3-sessions-table');
    return norm(await page.locator(`${tid('soa3-session-row')}[data-session-id="${sid}"] td`).nth(hs.indexOf(label)).innerText());
}
const itemCell = async (page, sku, col) => norm(await page.locator(`${tid('soa3-item-row')}[data-sku="${sku}"] td[data-col="${col}"]`).innerText());
const kpi = (page, k) => text(page.locator(tid(`soa3-kpi-${k}-value`)));
const apiSessions = (page, q) => T.api(page, '/reports/opname-audit/sessions', q);
const apiItems = (page, q) => T.api(page, '/reports/opname-audit/items', q);
const geom = (page) => page.evaluate(() => {
    const vw = innerWidth;
    const r = document.querySelector('[data-testid="soa3-root"]').getBoundingClientRect();
    const f = document.querySelector('[data-testid="soa3-filter"]');
    const tops = new Set(Array.from(f.querySelectorAll('.rp-f')).map((n) => Math.round(n.getBoundingClientRect().top)));
    const cards = Array.from(document.querySelectorAll('[data-testid="soa3-root"] .rp-card')).every((c) => c.getBoundingClientRect().right <= vw + 1 && c.getBoundingClientRect().left >= -1);
    return {
        vw, hscroll: document.documentElement.scrollWidth - vw, bodyScroll: document.body.scrollWidth - vw, rootRight: Math.round(r.right), filterH: Math.round(f.getBoundingClientRect().height), filterRows: tops.size,
        kpiGrids: document.querySelectorAll('[data-testid="soa3-root"] .rp-kpis').length, kpiCards: document.querySelectorAll('[data-testid="soa3-root"] .rp-kpi').length,
        nested: document.querySelectorAll('[data-testid="soa3-root"] .rp-card .rp-card, [data-testid="soa3-root"] .rp-kpi .rp-kpi, [data-testid="soa3-root"] .rp-kpi .rp-card').length, cardsInside: cards,
        oldCss: Array.from(document.querySelectorAll('[data-testid="soa3-root"] *')).filter((n) => Array.from(n.classList).some((c) => c.startsWith('soa-'))).length,
        scrollEls: Array.from(document.querySelectorAll('[data-testid="soa3-root"] .rp-scroll')).map((s) => [s.scrollWidth, s.clientWidth]),
    };
});

let browser;
try {
    browser = await chromium.launch();
    const { context, page } = await T.newSession(browser, { viewport: { width: 1536, height: 900 }, __name: 'admin' }, seed.admin);
    await open(page);

    // ================================================================ A. header / filter / KPIs
    check('A title "Laporan Stock Opname", the subtext, Cetak + Download Excel in the header', (await text(page.locator(tid('soa3-title')))) === 'Laporan Stock Opname'
        && (await text(root(page).locator('.rp-desc'))).startsWith('Audit hasil Stock Opname') && await page.locator(tid('soa3-print')).isVisible() && await page.locator(tid('soa3-excel')).isVisible());
    let g = await geom(page);
    check('A the retired .soa-* UI is not used (no soa-* classes in the new page, no .soa-title)', g.oldCss === 0 && (await page.locator('.soa-title').count()) === 0, String(g.oldCss));
    check('A ONE compact filter card: Dari / Sampai / Gudang / Status + a long search + Terapkan + Reset, at most 2 rows and ≤ 140px tall', g.filterRows <= 2 && g.filterH <= 140
        && await page.locator(tid('soa3-from')).isVisible() && await page.locator(tid('soa3-wh')).isVisible() && await page.locator(tid('soa3-status')).isVisible() && await page.locator(tid('soa3-q')).isVisible() && await page.locator(tid('soa3-reset')).isVisible(), JSON.stringify([g.filterRows, g.filterH]));
    const qBox = await page.locator(tid('soa3-q')).boundingBox(); const fromBox = await page.locator(tid('soa3-from')).boundingBox();
    check('A filter proportions: the search is at least twice as wide as a date field', qBox.width >= fromBox.width * 2, `${Math.round(qBox.width)} vs ${Math.round(fromBox.width)}`);
    check('A KPI row 1 = 3 cards in ONE .rp-kpis grid + 4 dense condition cards; no card inside a card; no page-level horizontal scroll', g.kpiGrids === 2 && g.kpiCards === 7 && g.nested === 0 && g.hscroll <= 0
        && await page.locator(`${tid('soa3-kpis')} .rp-kpi`).count() === 3 && await page.locator(`${tid('soa3-cond-kpis')} .rp-kpi`).count() === 4 && await page.locator(tid('soa3-cond-kpis')).evaluate((e) => e.classList.contains('dense')), JSON.stringify(g));
    const sAll = await apiSessions(page, { date_from: '', date_to: '' });
    const k = sAll.kpi;
    check('A KPIs == API: Total Sesi 14, Item Diverifikasi 9, Total Selisih Nilai −Rp 20.600 (red), Good / Expired / Rusak / Deadstock SKU + per unit', (await kpi(page, 'sessions')) === '14' && (await kpi(page, 'verified')) === String(k.verified)
        && (await kpi(page, 'variance')) === `-Rp ${idr(Math.abs(k.variance_value))}` && await page.locator(`${tid('soa3-kpi-variance')}.t-red`).count() === 1
        && (await kpi(page, 'good')) === `${k.conditions.good.sku} SKU` && (await text(page.locator(tid('soa3-kpi-rusak')))).includes(`KG ${k.conditions.rusak.by_unit.KG}`) && (await kpi(page, 'deadstock')) === `${k.conditions.deadstock.sku} SKU` && k.variance_value === -20600, await kpi(page, 'variance'));
    check('A the condition KPIs are neutral colours, only the variance card is green / red', await page.locator(`${tid('soa3-cond-kpis')} .t-red, ${tid('soa3-cond-kpis')} .t-green`).count() === 0 && await page.locator(`${tid('soa3-kpis')} .t-green, ${tid('soa3-kpis')} .t-red`).count() === 1);
    check('A two segmented tabs "Ringkasan Sesi" | "Rincian Item" (.rp-tabs), Ringkasan selected', (await text(page.locator('.rp-tabs'))) === 'Ringkasan Sesi Rincian Item' && (await page.getAttribute(tid('soa3-tab-sessions'), 'aria-selected')) === 'true');

    // ================================================================ B. filters
    await setFilters(page, { from: DAY, to: DAY });
    check('B date filter: exactly the two sessions of 2026-09-30; KPIs follow (2 sesi)', (await rows(page, 'soa3-session-row')) === 2 && (await kpi(page, 'sessions')) === '2');
    await setFilters(page, { from: '2020-01-01', to: '2020-01-31' });
    check('B a period without sessions: honest empty state and unknown KPIs shown as "—" (never 0)', (await rows(page, 'soa3-sessions-empty')) === 1 && (await kpi(page, 'sessions')) === '—' && (await kpi(page, 'verified')) === '—' && (await kpi(page, 'variance')) === '—' && (await kpi(page, 'good')) === '—');
    await setFilters(page, { from: DAY, to: DAY, wh: String(L.warehouse_id) });
    check('B warehouse filter: CIBADAK → only the legacy session', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(L.session_number));
    await setFilters(page, { from: '2026-09-01', to: DAY, wh: '' });
    check('B 14 sessions between 2026-09-01 and 2026-09-30', (await rows(page, 'soa3-session-row')) === 14);
    await setFilters(page, { status: 'OPEN' });
    check('B status OPEN → 6 sessions (header-only, zero items shown as 0, no fabricated numbers)', (await rows(page, 'soa3-session-row')) === 6);
    await setFilters(page, { status: 'CANCELLED' });
    check('B status CANCELLED → 6 sessions, all with the CANCELLED pill', (await rows(page, 'soa3-session-row')) === 6 && (await page.locator(`${tid('soa3-session-row')} .rp-pill.bad`).count()) === 6);
    await setFilters(page, { status: 'POSTED' });
    check('B status POSTED → the 2 fixture sessions', (await rows(page, 'soa3-session-row')) === 2);
    await setFilters(page, { status: '', q: V.session_number });
    check('B search by session number → that session only', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(V.session_number));
    await setFilters(page, { q: V.users.f1b.username });
    check('B search by petugas name (a V1 counter) → the V1 session', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(V.session_number));
    await setFilters(page, { q: 'dus jatuh' });
    check('B search by a line note → the legacy session', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(L.session_number));
    await setFilters(page, { q: V.items.rusak.sku });
    check('B search by SKU → the session that holds it', (await rows(page, 'soa3-session-row')) === 1);
    // debounced search: no click on Terapkan
    await page.fill(tid('soa3-q'), '');
    await page.waitForTimeout(700); await idle(page);
    check('B clearing the search (debounced, no button) restores the list', (await rows(page, 'soa3-session-row')) === 14);
    await page.fill(tid('soa3-q'), L.session_number);
    await page.waitForTimeout(900); await idle(page);
    check('B the search is debounced: typing alone (no button, no Enter) filters to the legacy session', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(L.session_number));
    // stale-response guard: the FIRST (slow) request must never overwrite the LATER one
    await page.route('**/api/reports/opname-audit/sessions?*q=SLOWQ*', async (route) => { await new Promise((r) => setTimeout(r, 1800)); await route.continue(); });
    await page.fill(tid('soa3-q'), 'SLOWQ'); await page.keyboard.press('Enter');
    await page.fill(tid('soa3-q'), V.session_number); await page.keyboard.press('Enter');
    await page.waitForTimeout(2600); await idle(page);
    check('B stale-response guard: a slow earlier search ("SLOWQ", 0 rows) never overwrites the later one (V1 session shown)', (await rows(page, 'soa3-session-row')) === 1 && (await text(page.locator(tid('soa3-session-row')))).includes(V.session_number));
    await page.unroute('**/api/reports/opname-audit/sessions?*q=SLOWQ*');
    await page.click(tid('soa3-reset'));
    await page.waitForTimeout(250); await idle(page);
    const defFrom = await page.inputValue(tid('soa3-from')); const defTo = await page.inputValue(tid('soa3-to'));
    check('B Reset: search cleared, status / warehouse back to "Semua", period back to the default window, 14 sessions again', (await page.inputValue(tid('soa3-q'))) === '' && (await page.inputValue(tid('soa3-status'))) === '' && (await page.inputValue(tid('soa3-wh'))) === ''
        && /^\d{4}-\d{2}-\d{2}$/.test(defFrom) && defTo === new Date().toISOString().slice(0, 10) && (await rows(page, 'soa3-session-row')) === 14);
    await setFilters(page, { from: '2026-09-01', to: DAY });

    // ================================================================ C. Ringkasan Sesi table
    const heads = await headsOf(page, 'soa3-sessions-table');
    check('C columns: No. Sesi, Tanggal, Gudang, Status, P1, P2, Supervisor, Finalizer, Total Item, Match, Mismatch, Recount, Excluded, Selisih Nilai, Adjustment +/−, Evidence, Dibuat Oleh, Finalisasi',
        JSON.stringify(heads) === JSON.stringify(['No. Sesi', 'Tanggal', 'Gudang', 'Status', 'P1', 'P2', 'Supervisor', 'Finalizer', 'Total Item', 'Match', 'Mismatch', 'Recount', 'Excluded', 'Selisih Nilai', 'Adjustment +/−', 'Evidence', 'Dibuat Oleh', 'Finalisasi']), heads.join('|'));
    const ppOpts = await page.locator(`${tid('soa3-sessions-pp')} option`).evaluateAll((o) => o.map((x) => x.value));
    check('C rows-per-page options 10 / 25 / 50 / 100 (default 25)', JSON.stringify(ppOpts) === JSON.stringify(['10', '25', '50', '100']) && (await page.inputValue(tid('soa3-sessions-pp'))) === '25');
    await page.selectOption(tid('soa3-sessions-pp'), '10'); await idle(page);
    check('C server-side pagination: 10 per page → 10 rows, "halaman 1 / 2"; page 2 → the remaining 4', (await rows(page, 'soa3-session-row')) === 10 && (await text(page.locator(tid('soa3-sessions-count')))).includes('halaman 1 / 2'));
    await page.click(`${tid('soa3-sessions-pager')} .rp-pg[data-page="2"]`); await idle(page);
    check('C page 2 shows the 4 remaining sessions (and they are different sessions)', (await rows(page, 'soa3-session-row')) === 4);
    await page.selectOption(tid('soa3-sessions-pp'), '25'); await idle(page);
    check('C back to 25 per page → page 1 with all 14', (await rows(page, 'soa3-session-row')) === 14);
    const sess = await apiSessions(page, { date_from: '2026-09-01', date_to: DAY, per_page: '100' });
    const vApi = sess.rows.find((r) => r.id === V.session_id); const lApi = sess.rows.find((r) => r.id === L.session_id);
    const vP1 = await sessCell(page, V.session_id, 'P1');
    check('C V1 row: P1 = f1a + f1b (voided f1c absent), P2 = f2a, Finalizer = admin, Total Item 9, Match 4, Mismatch 0, Excluded 1', vP1.includes(V.users.f1a.username) && vP1.includes(V.users.f1b.username) && !vP1.includes(V.users.f1c.username)
        && (await sessCell(page, V.session_id, 'P2')).includes(V.users.f2a.username) && (await sessCell(page, V.session_id, 'Finalizer')) === seed.admin.username && (await sessCell(page, V.session_id, 'Total Item')) === '9'
        && (await sessCell(page, V.session_id, 'Match')) === String(vApi.match) && (await sessCell(page, V.session_id, 'Mismatch')) === '0' && (await sessCell(page, V.session_id, 'Excluded')) === String(vApi.excluded));
    check('C Selisih Nilai == API on both sessions (V1 −Rp 23.100 red, legacy +Rp 2.500 green)', (await sessCell(page, V.session_id, 'Selisih Nilai')) === `-Rp ${idr(Math.abs(vApi.variance_value))}` && vApi.variance_value === -23100
        && (await sessCell(page, L.session_id, 'Selisih Nilai')) === `+Rp ${idr(lApi.variance_value)}` && lApi.variance_value === 2500
        && await page.locator(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"] .rp-neg`).count() >= 1 && await page.locator(`${tid('soa3-session-row')}[data-session-id="${L.session_id}"] td .rp-pos`).count() >= 1);
    check('C Adjustment +/−: legacy shows posted positive / negative parts (== API), V1 / header-only sessions "—"; Evidence: V1 "12 foto", legacy "—"', lApi.adj_count === 3 && near(lApi.adj_pos + lApi.adj_neg, lApi.adj_value, 0.001)
        && (await sessCell(page, L.session_id, 'Adjustment +/−')).includes(idr(Math.abs(lApi.adj_neg))) && (await sessCell(page, V.session_id, 'Adjustment +/−')) === '—'
        && (await sessCell(page, V.session_id, 'Evidence')) === '12 foto' && (await sessCell(page, L.session_id, 'Evidence')) === '—' && vApi.evidence_count === 12 && lApi.evidence_count === null);
    check('C header-only sessions: Total Item 0 and nothing invented (Selisih Nilai Rp 0 / Excluded 0)', await page.locator(tid('soa3-session-row')).filter({ hasText: seed.extra[0].session_number }).count() === 1);
    const tinfo = await page.evaluate(() => {
        const th = document.querySelector('[data-testid="soa3-sessions-table"] thead th'); const sc = document.querySelector('[data-testid="soa3-sessions-scroll"]');
        const nums = Array.from(document.querySelectorAll('[data-testid="soa3-sessions-table"] tbody tr:first-child td.num')).map((td) => getComputedStyle(td).textAlign);
        return { sticky: getComputedStyle(th).position, over: sc.scrollWidth - sc.clientWidth, nums, page: document.documentElement.scrollWidth - innerWidth, maxH: getComputedStyle(sc).maxHeight, firstSticky: getComputedStyle(document.querySelector('[data-testid="soa3-sessions-table"] td.sticky1')).position };
    });
    check('C sticky header, numbers right-aligned, horizontal scroll ONLY inside the table wrapper (page does not scroll), sticky first column', tinfo.sticky === 'sticky' && tinfo.nums.length >= 6 && tinfo.nums.every((a) => a === 'right') && tinfo.over > 0 && tinfo.page <= 0 && tinfo.firstSticky === 'sticky', JSON.stringify(tinfo));
    await T.shot(page, 'so3-desktop-1536-sessions');

    // ================================================================ D. session drawer
    await page.click(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"]`);
    await page.waitForSelector(tid('soa3-session-fields'));
    const sdText = norm(await page.locator('.drawer.open').textContent());
    check('D row click → session drawer: title, status / model pills, P1 / P2 / supervisor / finalizer, P1 Mulai / P2 Selesai, counts, selisih, adjustment status, evidence count, data-quality notes',
        sdText.includes(V.session_number) && sdText.includes('FINDINGS_V1') && sdText.includes('P1 Mulai') && sdText.includes('P2 Selesai') && sdText.includes(V.users.f2a.username) && sdText.includes('Status Adjustment') && sdText.includes('12 foto') && sdText.includes('Catatan kualitas data') && await page.locator('.drawer.open.rp-drawer').count() === 1);
    check('D the row is highlighted (selected) and the scope chip names the session', await page.locator(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"]`).evaluate((e) => e.classList.contains('sel')) && (await text(page.locator(tid('soa3-scope-chip')))).includes(V.session_number));
    check('D drawer actions are real: "Buka Rincian Item", "Lihat Jejak", "Excel Final" (SUPERADMIN, POSTED) — no dummy buttons', await page.locator(tid('soa3-drawer-rincian')).isVisible() && await page.locator(tid('soa3-drawer-jejak')).isVisible() && await page.locator(tid('soa3-drawer-final')).isVisible());
    await T.shot(page, 'so3-desktop-1536-session-drawer');
    await page.click(tid('soa3-drawer-jejak'));
    await page.waitForFunction(() => document.querySelector('.drawer.open') && /Jejak/i.test(document.querySelector('.drawer.open').innerText), null, { timeout: 15000 });
    check('D "Lihat Jejak" opens the Jejak drawer of the session (StockOpnameJejak still works; the SOA3 drawer width class was released)', await page.locator('.drawer.open.soa3-drawer').count() === 0 && (await page.locator('.drawer.open').innerText()).includes(V.session_number));
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    await page.click(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"]`);
    await page.waitForSelector(tid('soa3-drawer-rincian'));
    await page.click(tid('soa3-drawer-rincian'));
    await page.waitForSelector(tid('soa3-items-table')); await idle(page);

    // ================================================================ E. Rincian Item
    check('E "Buka Rincian Item" switches to the tab; the context header shows the selected session (select + status + petugas + totals)', (await page.getAttribute(tid('soa3-tab-items'), 'aria-selected')) === 'true' && (await page.inputValue(tid('soa3-session-select'))) === String(V.session_id)
        && (await text(page.locator(tid('soa3-ctx-facts')))).includes(V.users.f2a.username) && (await rows(page, 'soa3-item-row')) === 9);
    const iheads = await headsOf(page, 'soa3-items-table');
    check('E default columns (12): SKU, Barang, Qty Sistem, Qty Fisik, Good, Expired, Rusak, Deadstock, Selisih Nilai, P1, P2, Status', JSON.stringify(iheads) === JSON.stringify(['SKU', 'Barang', 'Qty Sistem', 'Qty Fisik', 'Good', 'Expired', 'Rusak', 'Deadstock', 'Selisih Nilai', 'P1', 'P2', 'Status']), iheads.join('|'));
    const itemsApi = await apiItems(page, { session_ids: String(V.session_id), per_page: '200' });
    check('E rows == API (9); the footer TOTAL Selisih Nilai == API footer; per-unit quantities listed below (never added across units)', itemsApi.pagination.total === 9 && (await text(page.locator(tid('soa3-items-total')))).includes(idr(Math.abs(itemsApi.footer.money.variance_value))) && (await text(page.locator(tid('soa3-items-units')))).includes('KG:'));
    const mixSku = V.items.mix.sku;
    check('E V1 mix row: Qty Sistem 100, Qty Fisik 100, Good 90, Expired 3, Rusak 5, Deadstock 2, Selisih Nilai −Rp 7.000', (await itemCell(page, mixSku, 'system_qty')) === '100' && (await itemCell(page, mixSku, 'final_qty')) === '100' && (await itemCell(page, mixSku, 'good_qty')) === '90'
        && (await itemCell(page, mixSku, 'expired_qty')) === '3' && (await itemCell(page, mixSku, 'rusak_qty')) === '5' && (await itemCell(page, mixSku, 'deadstock_qty')) === '2' && (await itemCell(page, mixSku, 'variance_value')) === '-Rp 7.000');
    check('E petugas columns: mix row P1 = f1a, P2 = f2a; "pos" row (a voided f1c finding + live f1b) P1 = f1b only, P2 "—"; unknown values are "—" not 0 (excluded row Qty Fisik "—")', (await itemCell(page, mixSku, 'p_hitung')).includes(V.users.f1a.username) && (await itemCell(page, mixSku, 'p_verifikasi')).includes(V.users.f2a.username)
        && (await itemCell(page, V.items.pos.sku, 'p_hitung')) === V.users.f1b.username && (await itemCell(page, V.items.pos.sku, 'p_verifikasi')) === '—' && (await itemCell(page, V.items.excl.sku, 'final_qty')) === '—');
    check('E Status pills: Match (green) and Dikecualikan / Belum dihitung', await page.locator(`${tid('soa3-item-row')} td[data-col="line_status"] .rp-pill.ok`).count() >= 1 && (await itemCell(page, V.items.excl.sku, 'line_status')) === 'Dikecualikan');
    const ig = await page.evaluate(() => { const s = document.querySelector('[data-testid="soa3-items-scroll"]'); return { head: getComputedStyle(s.querySelector('thead th')).position, foot: getComputedStyle(s.querySelector('tfoot td')).position, nums: Array.from(s.querySelectorAll('tbody tr:first-child td.num')).map((t) => getComputedStyle(t).textAlign) }; });
    check('E sticky header + sticky TOTAL row, numeric cells right-aligned', ig.head === 'sticky' && ig.foot === 'sticky' && ig.nums.length >= 7 && ig.nums.every((a) => a === 'right'), JSON.stringify(ig));
    await T.shot(page, 'so3-desktop-1536-items');

    // ---- row filters
    await page.selectOption(tid('soa3-item-cond'), 'rusak'); await page.waitForTimeout(250); await idle(page);
    const apiRusak = await apiItems(page, { session_ids: String(V.session_id), condition: 'rusak' });
    check('E condition "Ada Rusak" == API (V1: rusak, mix, move = 3)', (await rows(page, 'soa3-item-row')) === apiRusak.pagination.total && apiRusak.pagination.total === 3);
    await page.selectOption(tid('soa3-item-cond'), 'evidence'); await page.waitForTimeout(250); await idle(page);
    check('E condition "Ada evidence" → the 4 lines with photos', (await rows(page, 'soa3-item-row')) === 4);
    await page.selectOption(tid('soa3-item-cond'), '');
    await page.selectOption(tid('soa3-item-match'), 'MATCH'); await page.waitForTimeout(250); await idle(page);
    check('E row status "Match" == API', (await rows(page, 'soa3-item-row')) === (await apiItems(page, { session_ids: String(V.session_id), match_status: 'MATCH' })).pagination.total);
    await page.selectOption(tid('soa3-item-match'), '');
    await page.fill(tid('soa3-item-q'), V.users.f2a.username);
    await page.waitForTimeout(900); await idle(page);
    check('E debounced item search by petugas name → only that petugas\' lines (== API)', (await rows(page, 'soa3-item-row')) === (await apiItems(page, { session_ids: String(V.session_id), item_q: V.users.f2a.username })).pagination.total && (await rows(page, 'soa3-item-row')) < 9);
    await page.fill(tid('soa3-item-q'), mixSku);
    await page.waitForTimeout(900); await idle(page);
    check('E item search by SKU → 1 row', (await rows(page, 'soa3-item-row')) === 1);
    await page.fill(tid('soa3-item-q'), '');
    await page.waitForTimeout(900); await idle(page);
    const ppI = await page.locator(`${tid('soa3-item-pp')} option`).evaluateAll((o) => o.map((x) => x.value));
    await page.selectOption(tid('soa3-item-pp'), '25'); await idle(page);
    check('E rows per page options 25 / 50 / 100 / 200 (default 50); 25 works', JSON.stringify(ppI) === JSON.stringify(['25', '50', '100', '200']) && (await rows(page, 'soa3-item-row')) === 9);
    await page.selectOption(tid('soa3-item-pp'), '50');

    // ---- column chooser
    await page.click(tid('soa3-cols'));
    const catalogue = itemsApi.columns;
    const boxes = await page.locator(`${tid('soa3-colpanel')} input[type=checkbox]`).count();
    const checked = await page.locator(`${tid('soa3-colpanel')} input[type=checkbox]:checked`).count();
    check(`E column chooser lists ALL ${catalogue.length} audit columns of the service catalogue, 12 ticked by default`, boxes === catalogue.length && checked === 12 && await page.locator(tid('soa3-colpanel')).isVisible(), `${boxes}/${checked}`);
    await page.check(tid('soa3-col-category')); await page.check(tid('soa3-col-evidence')); await page.check(tid('soa3-col-adj_ref'));
    await page.waitForTimeout(150);
    const heads2 = await headsOf(page, 'soa3-items-table');
    check('E ticking Kategori + Evidence Foto + Adjustment Ref adds exactly those columns (15), the others stay', heads2.length === 15 && heads2.includes('Kategori') && heads2.includes('Evidence') && heads2.includes('Adjustment Ref') && heads2.includes('SKU'));
    check('E the choice is persisted in localStorage (soa3_item_cols_v1)', await page.evaluate(() => JSON.parse(localStorage.getItem('soa3_item_cols_v1')).includes('adj_ref')));
    await page.click(tid('soa3-cols-all')); await page.waitForTimeout(150);
    const headsAll = await headsOf(page, 'soa3-items-table');
    check(`E "Semua" → every one of the ${catalogue.length} catalogue columns is a table column (labels from the catalogue)`, headsAll.length === catalogue.length && (await page.locator(`${tid('soa3-colpanel')} input:checked`).count()) === catalogue.length);
    await page.click(tid('soa3-cols-reset')); await page.waitForTimeout(150);
    check('E "Bawaan" returns to the 12 default columns', (await headsOf(page, 'soa3-items-table')).length === 12);
    await page.check(tid('soa3-col-evidence')); await page.check(tid('soa3-col-category')); await page.waitForTimeout(150);
    await page.evaluate(() => { Storage.prototype.setItem = () => { throw new Error('quota'); }; });
    await page.uncheck(tid('soa3-col-category')); await page.waitForTimeout(150);
    check('E a failing localStorage (quota / private mode) never breaks the table (guarded by try/catch)', (await headsOf(page, 'soa3-items-table')).length === 13);
    await page.click(tid('soa3-ctx')); // close panel (outside click)
    check('E clicking outside closes the chooser', await page.locator(tid('soa3-colpanel')).isHidden());

    // ---- evidence thumbnails + gallery
    const mixThumbs = page.locator(`${tid('soa3-item-row')}[data-sku="${mixSku}"] ${tid('soa3-thumb')}`);
    check('E evidence column: 3 compact thumbnails + "+3" for the mix row (6 photos); a line without photos says "Tidak ada"', await mixThumbs.count() === 3 && (await text(page.locator(`${tid('soa3-item-row')}[data-sku="${mixSku}"] ${tid('soa3-thumb-more')}`))) === '+3'
        && (await itemCell(page, V.items.pos.sku, 'evidence')) === 'Tidak ada');
    const decoded = await mixThumbs.first().evaluate((img) => new Promise((res) => { if (img.complete) res(img.naturalWidth > 0); else { img.onload = () => res(img.naturalWidth > 0); img.onerror = () => res(false); } }));
    check('E the thumbnail is a REAL decoded image served by the API (naturalWidth > 0)', decoded === true);
    await mixThumbs.first().click();
    await page.waitForSelector(tid('soa3-gallery'));
    await page.waitForFunction(() => { const i = document.querySelector('[data-testid="soa3-gallery-img"]'); return i && i.complete && i.naturalWidth > 0; });
    const ginfo = norm(await page.locator(tid('soa3-gallery-info')).innerText());
    check('E gallery: real image, SKU, session, uploader, upload timestamp, condition + team, "1 / 6"', ginfo.includes(mixSku) && ginfo.includes(V.session_number) && /Diunggah oleh: jfF/.test(ginfo) && /Timestamp upload: .*2026/.test(ginfo) && /Kondisi: (DAMAGED|EXPIRED|DEADSTOCK)/.test(ginfo) && ginfo.includes('1 / 6'), ginfo.slice(0, 200));
    await page.click('.soa3-lb-nav:last-of-type');
    check('E gallery "next" → 2 / 6', norm(await page.locator(tid('soa3-gallery-info')).innerText()).includes('2 / 6'));
    await T.shot(page, 'so3-desktop-1536-gallery');
    await page.keyboard.press('Escape');
    check('E the gallery closes with Esc', (await page.locator(tid('soa3-gallery')).count()) === 0);

    // ================================================================ F. item drawer
    await page.locator(`${tid('soa3-item-row')}[data-sku="${mixSku}"] td[data-col="sku"]`).click();
    await page.waitForSelector(tid('soa3-item-fields'));
    const fieldLabels = await page.locator(`${tid('soa3-item-fields')} .soa3-kv-k`).allInnerTexts();
    const wanted = catalogue.filter((c) => !['no', 'evidence'].includes(c.key)).map((c) => c.label);
    check(`F item drawer "Ringkasan": ALL audit fields of the catalogue are there (${wanted.length} + evidence), the condition block, petugas, timestamps, HPP, selisih`, wanted.every((l) => fieldLabels.map((x) => x.toLowerCase()).includes(l.toLowerCase())) && await page.locator(tid('soa3-condition-block')).count() === 1
        && (await text(page.locator(tid('soa3-item-fields')))).includes('29 Sep 2026'));
    const dtabs = await page.locator('.drawer.open .drawer-tab').allInnerTexts();
    check('F drawer tabs: Ringkasan · Riwayat Hitung · Evidence (6) · Rekonsiliasi · Adjustment · Audit', dtabs.length === 6 && dtabs[0] === 'Ringkasan' && dtabs[2] === 'Evidence (6)' && dtabs[5].startsWith('Audit'));
    await T.shot(page, 'so3-desktop-1536-item-drawer');
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Riwayat Hitung' }).click();
    check('F Riwayat Hitung: P1 and P2 findings with per-condition quantities', (await page.locator(`${tid('soa3-history-table')} tbody tr`).count()) === 2 && (await text(page.locator(tid('soa3-history-table')))).includes('DAMAGED'));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Evidence' }).click();
    check('F Evidence tab: the 6 photos are real decoded images; clicking one opens the gallery on top of the drawer', (await page.locator('.drawer.open .soa3-fig').count()) === 6
        && await page.locator('.drawer.open .soa3-fig-img').first().evaluate((img) => new Promise((res) => { if (img.complete) res(img.naturalWidth > 0); else { img.onload = () => res(img.naturalWidth > 0); img.onerror = () => res(false); } })));
    await page.locator('.drawer.open .soa3-fig').first().click();
    await page.waitForSelector(tid('soa3-gallery'));
    await page.keyboard.press('Escape');
    check('F Esc inside the gallery closes ONLY the gallery (the drawer stays open)', (await page.locator(tid('soa3-gallery')).count()) === 0 && (await page.locator('.drawer.open').count()) === 1);
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Rekonsiliasi' }).click();
    check('F Rekonsiliasi: model FINDINGS_V1, EOD formula, book-stock rows', (await page.locator(tid('soa3-tab-rekon')).textContent()).includes('FINDINGS_V1') && (await page.locator(tid('soa3-tab-rekon')).textContent()).includes('Stok buku EOD'));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Audit' }).click();
    check('F Audit (activity trail): real audit events (finding create)', (await text(page.locator(tid('soa3-audit-table')))).includes('STOCK_OPNAME_FINDING_CREATE'));
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    // legacy session through the select: the posted adjustment of L1
    await page.selectOption(tid('soa3-session-select'), String(L.session_id)); await idle(page);
    check('F the context select switches to the legacy session (items == API 6) and the table follows', (await rows(page, 'soa3-item-row')) === 6 && (await text(page.locator(tid('soa3-ctx-facts')))).includes(L.users.B.username));
    await page.locator(`${tid('soa3-item-row')}[data-sku="${L.items.L1.sku}"] td[data-col="sku"]`).click();
    await page.waitForSelector(tid('soa3-item-fields'));
    await page.locator('.drawer.open .drawer-tab', { hasText: 'Adjustment' }).click();
    const adjText = norm(await page.locator(tid('soa3-tab-adjustment')).innerText());
    check('F posted adjustment of legacy L1 in the drawer: real reference, qty −5, value −Rp 5.000 (FIFO) — and the note that it may differ from Selisih Nilai', /ADJ-|SO-|[A-Z]{2,}-/.test(adjText) && adjText.includes('-5') && adjText.includes('5.000') && adjText.includes('dapat berbeda'), adjText.slice(0, 160));
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    await page.selectOption(tid('soa3-session-select'), String(V.session_id)); await idle(page);
    await page.selectOption(tid('soa3-session-select'), ''); await idle(page);
    check('F "Semua sesi sesuai filter" lists the items of every session in the filter (15) and adds the No. Sesi column', (await rows(page, 'soa3-item-row')) === 15 && (await headsOf(page, 'soa3-items-table')).includes('No. Sesi'));
    await page.selectOption(tid('soa3-session-select'), String(V.session_id)); await idle(page);
    check('F back to one session: the 12 default columns again (no No. Sesi)', (await headsOf(page, 'soa3-items-table')).length === 13 || (await headsOf(page, 'soa3-items-table')).length === 12);
    await page.click(tid('soa3-cols')); await page.click(tid('soa3-cols-reset')); await page.click(tid('soa3-ctx'));
    await page.waitForTimeout(150);

    // ================================================================ G. print (Cetak)
    const printReqs = [];
    page.on('request', (r) => { if (r.url().includes('/reports/opname-audit/items') && r.url().includes('per_page=200')) printReqs.push(r.url()); });
    let html = await T.capturePrint(page, 'soa3-print');
    T.printChecks('G selected session', html, { title: 'Laporan Stock Opname', landscape: true,
        mustContain: ['Ringkasan Sesi', 'Rincian Item', V.session_number, 'Periode:', 'Gudang:', 'Sesi:', 'Qty Sistem', 'Selisih Nilai', 'TOTAL 9 item', 'Rp 7.000', V.items.mix.sku, V.items.excl.sku], mustNotContain: ['tidak ada sesi yang dipilih'] });
    check('G print fetches ALL item pages of the session in 200-row pages (per_page=200 loop)', printReqs.length >= 1 && printReqs.every((u) => u.includes('session_ids=' + V.session_id)));
    check('G print: only the 14 key columns of the wide item table (no timestamps / notes / evidence URLs), Rupiah cells right-aligned, 9 item rows + header', !html.includes('Timestamp Hitung') && !html.includes('/api/reports/opname-audit/photo/') && /<td class="r">[^<]*Rp [^<]*<\/td>/.test(html) && (html.match(/<tr>/g) || []).length >= 9 + 2);
    check('G print: contains both sections for the selected session — "Ringkasan Sesi" (1 row) and "Rincian Item — <SO>"', html.includes(`Rincian Item — ${V.session_number}`) && (html.match(new RegExp(V.session_number, 'g')) || []).length >= 3);
    // print preview as an image + pdf (the very document the browser would print)
    const pv = await context.newPage();
    await pv.setViewportSize({ width: 1123, height: 794 });
    await pv.setContent(html);
    await pv.screenshot({ path: path.join(T.shotDir, 'so3-print-preview-selected.png'), fullPage: true });
    await pv.pdf({ path: path.join(T.shotDir, 'so3-print-preview-selected.pdf'), format: 'A4', landscape: true, printBackground: true });
    const pvBg = await pv.evaluate(() => getComputedStyle(document.body).backgroundColor);
    check('G print preview rendered to PNG + PDF: white page, dark text', pvBg === 'rgb(255, 255, 255)' && fs.statSync(path.join(T.shotDir, 'so3-print-preview-selected.pdf')).size > 4000, pvBg);
    await pv.close();
    // a row filter active in the Rincian tab is printed too
    await page.selectOption(tid('soa3-item-cond'), 'rusak'); await page.waitForTimeout(250); await idle(page);
    html = await T.capturePrint(page, 'soa3-print');
    check('G print follows the Rincian filters: condition "Ada Rusak" → listed under "Filter item", only the 3 rusak lines (TOTAL 3 item)', html.includes('Filter item:') && html.includes('Ada Rusak') && html.includes('TOTAL 3 item') && !html.includes(V.items.zero.sku));
    await page.selectOption(tid('soa3-item-cond'), ''); await idle(page);
    // no session selected: Ringkasan Sesi of the current filters, and it says so
    await page.click(tid('soa3-tab-sessions'));
    await page.click(tid('soa3-scope-clear'));
    await setFilters(page, { from: '2026-09-01', to: DAY });
    html = await T.capturePrint(page, 'soa3-print');
    T.printChecks('G no selection', html, { title: 'Laporan Stock Opname', landscape: true, mustContain: ['Ringkasan Sesi', 'tidak ada sesi yang dipilih', L.session_number, V.session_number, seed.extra[0].session_number, '01 Sep 2026 s/d 30 Sep 2026', 'Semua Gudang'], mustNotContain: ['Rincian Item —'] });
    check('G no selection: the print covers ALL 14 sessions of the filter (not only the current page)', (html.match(/<td class="">SO-\d{8}-\d{4}<\/td>/g) || []).length === 14);
    await setFilters(page, { status: 'POSTED' });
    html = await T.capturePrint(page, 'soa3-print');
    check('G the print header lists the active filters (Status sesi: Posted)', html.includes('Status sesi:') && html.includes('Posted') && !html.includes(seed.extra[0].session_number));
    await setFilters(page, { status: '' });

    // ================================================================ H. Excel (Download Excel) + CSV
    await page.click(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"]`);
    await page.waitForSelector(tid('soa3-drawer-rincian'));
    await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    const x = await T.downloadVia(page, 'soa3-excel', dl);
    check(`H Excel of the selected session: filename Laporan_Stock_Opname_${V.session_number}.xlsx`, x.name === `Laporan_Stock_Opname_${V.session_number}.xlsx` && x.size > 3000, `${x.name} ${x.size}`);
    const zipList = sh(`unzip -l ${JSON.stringify(x.file)}`);
    const wb = T.xlsxDump(x.file);
    const names = wb.sheets.map((s) => s.name);
    check('H a valid .xlsx (zip with 7 worksheets) with the sheets Ringkasan Sesi, Rincian Item, Evidence, Riwayat Hitung, Adjustment, Audit Log, Info', (zipList.match(/xl\/worksheets\/sheet\d+\.xml/g) || []).length === 7
        && JSON.stringify(names) === JSON.stringify(['Ringkasan Sesi', 'Rincian Item', 'Evidence', 'Riwayat Hitung', 'Adjustment', 'Audit Log', 'Info']), names.join('|'));
    const ri = wb.sheets[1];
    const riHead = ri.rows[0].map((c) => c.v);
    check(`H Rincian Item: ALL ${catalogue.length} audit columns (the screen's catalogue), 9 item rows + header, header frozen, autofilter, column widths`, JSON.stringify(riHead) === JSON.stringify(catalogue.map((c) => c.label)) && ri.rows.length === 10 && ri.frozen && ri.autofilter.startsWith('A1:') && ri.col_widths.length >= catalogue.length);
    const col = (label) => riHead.indexOf(label);
    const mixRow = ri.rows.find((r) => r[col('SKU')].v === mixSku);
    check('H typed cells: quantities numeric (t=n), Rupiah numeric with an "Rp" number format, dates / timestamps real Excel dates (t=d), nothing numeric stored as text', mixRow[col('Qty Sistem')].t === 'n' && mixRow[col('Good / Stok Layak')].v === 90 && mixRow[col('Selisih Nilai')].t === 'n' && near(mixRow[col('Selisih Nilai')].v, -7000)
        && mixRow[col('HPP / Unit Cost')].fmt.includes('Rp') && mixRow[col('Tanggal')].t === 'd' && mixRow[col('Timestamp Hitung')].t === 'd' && mixRow[col('No.')].t === 'n');
    const exclRow = ri.rows.find((r) => r[col('SKU')].v === V.items.excl.sku);
    check('H unknown values stay "—" text (excluded row Qty Fisik), the numeric columns keep their type for the other rows', exclRow[col('Qty Fisik Final (Total)')].v === '—' && exclRow[col('Qty Fisik Final (Total)')].t === 's' && mixRow[col('Qty Fisik Final (Total)')].t === 'n');
    check('H Evidence sheet: 12 photo references (URL + uploader + timestamp); Ringkasan Sesi: 1 session row with numeric counts + Rupiah', wb.sheets[2].rows.length === 13 && String(wb.sheets[2].rows[1].map((c) => c.v).join(' ')).includes('/api/reports/opname-audit/photo/') && wb.sheets[0].rows.length === 2
        && wb.sheets[0].rows[1][wb.sheets[0].rows[0].findIndex((c) => c.v === 'Total Item')].v === 9 && wb.sheets[0].rows[1][wb.sheets[0].rows[0].findIndex((c) => c.v === 'Selisih Nilai')].fmt.includes('Rp'));
    check('H Riwayat Hitung sheet carries the VOID finding; Info sheet states the filters', wb.sheets[3].rows.length > 5 && JSON.stringify(wb.sheets[3].rows).includes('salah input') && JSON.stringify(wb.sheets[6].rows).includes('Periode'));
    // legacy session workbook: adjustments sheet
    await page.click(`${tid('soa3-session-row')}[data-session-id="${L.session_id}"]`);
    await page.waitForSelector(tid('soa3-drawer-rincian')); await page.evaluate(() => Drawer.close()); await page.waitForTimeout(300);
    const xl = await T.downloadVia(page, 'soa3-excel', dl);
    const wbl = T.xlsxDump(xl.file);
    const adjHead = wbl.sheets[4].rows[0].map((c) => c.v);
    check('H legacy session: filename uses ITS number; Adjustment sheet = 3 real posted adjustments, Nilai numeric Rp, Σ 3.500', xl.name === `Laporan_Stock_Opname_${L.session_number}.xlsx` && wbl.sheets[4].rows.length === 4
        && near(wbl.sheets[4].rows.slice(1).reduce((a, r) => a + r[adjHead.indexOf('Nilai')].v, 0), 3500) && wbl.sheets[4].rows[1][adjHead.indexOf('Nilai')].fmt.includes('Rp'));
    // the Rincian tab's row filters follow into the export
    await page.click(tid('soa3-tab-items')); await idle(page);
    await page.selectOption(tid('soa3-item-cond'), 'rusak'); await page.waitForTimeout(250); await idle(page);
    const xr = await T.downloadVia(page, 'soa3-excel', dl);
    const wbr = T.xlsxDump(xr.file);
    const apiLR = await apiItems(page, { session_ids: String(L.session_id), condition: 'rusak' });
    check('H Excel from the Rincian tab follows the row filter (Ada Rusak on the legacy session → rows == API)', wbr.sheets[1].rows.length === apiLR.pagination.total + 1 && apiLR.pagination.total >= 1, `${wbr.sheets[1].rows.length - 1}/${apiLR.pagination.total}`);
    await page.selectOption(tid('soa3-item-cond'), ''); await idle(page);
    // no selection: period file name + every session of the filter
    await page.click(tid('soa3-tab-sessions'));
    await page.click(tid('soa3-scope-clear'));
    await setFilters(page, { from: '2026-09-01', to: DAY, status: '' });
    const xp = await T.downloadVia(page, 'soa3-excel', dl);
    const wbp = T.xlsxDump(xp.file);
    check('H without a selected session: filename Laporan_Stock_Opname_<period>.xlsx (2026-09-01_2026-09-30), Ringkasan Sesi = 14 sessions, Rincian Item = 15 items of all sessions', xp.name === 'Laporan_Stock_Opname_2026-09-01_2026-09-30.xlsx' && wbp.sheets[0].rows.length === 15 && wbp.sheets[1].rows.length === 16, `${xp.name} ${wbp.sheets[0].rows.length}/${wbp.sheets[1].rows.length}`);
    await setFilters(page, { status: 'POSTED' });
    const xs = await T.downloadVia(page, 'soa3-excel', dl);
    check('H the Excel follows the header filters: status POSTED → 2 sessions in Ringkasan Sesi', T.xlsxDump(xs.file).sheets[0].rows.length === 3);
    await setFilters(page, { status: '' });
    // CSV extras
    await page.click(tid('soa3-more'));
    const menuItems = await page.locator('.rp-menu .rp-menu-item').allInnerTexts();
    check('H the Download Excel caret offers the 6 CSV exports (Ringkasan Sesi, Rincian Item, Evidence, Riwayat Hitung, Adjustment, Audit Log)', menuItems.length === 6 && menuItems.every((t) => t.startsWith('CSV')), menuItems.join('|'));
    const csv = await T.downloadVia(page, 'soa3-csv-items', dl);
    const csvText = fs.readFileSync(csv.file, 'utf8');
    check('H CSV — Rincian Item downloads a .csv with every audit column in the header and 15 rows', csv.name.endsWith('.csv') && catalogue.every((c) => csvText.split('\n')[0].includes(c.label)) && csvText.trim().split('\n').length >= 16, csv.name);
    await page.click(tid('soa3-more'));
    const csvE = await T.downloadVia(page, 'soa3-csv-evidence', dl);
    check('H CSV — Evidence: 12 photo references', fs.readFileSync(csvE.file, 'utf8').includes('/api/reports/opname-audit/photo/') && csvE.name.endsWith('.csv'));
    // ---- reconciliation stays green
    const rec = sh(`php scripts/opname_audit_reconcile_check.php --app-root=. --session=${L.session_id},${V.session_id}`);
    check('H read-only reconciliation CLI over both fixture sessions: all checks PASS', /\d+ \/ \d+ checks passed — all reconcile/.test(rec), rec.split('\n').slice(-3).join(' | '));

    // ================================================================ I. layouts / screenshots
    await page.evaluate(() => document.querySelectorAll('.toast').forEach((t) => t.remove()));
    await setFilters(page, { from: '2026-09-01', to: DAY });
    for (const [name, vp] of [['desktop-1536', { width: 1536, height: 900 }], ['desktop-1366', { width: 1366, height: 768 }], ['ipad-landscape', { width: 1180, height: 820 }], ['ipad-portrait', { width: 820, height: 1180 }]]) {
        await page.setViewportSize(vp);
        await page.click(tid('soa3-tab-sessions'));
        await page.waitForTimeout(250); await idle(page);
        g = await geom(page);
        check(`I ${name} Ringkasan Sesi: no horizontal page scroll, content inside the viewport, filter card ≤ 2 rows and short (${g.filterH}px), KPIs in one grid, no nested cards, table scrolls inside its wrapper`,
            g.hscroll <= 0 && g.bodyScroll <= 0 && g.rootRight <= vp.width && g.cardsInside && g.filterRows <= 2 && g.filterH <= 160 && g.kpiGrids === 2 && g.kpiCards === 7 && g.nested === 0 && g.oldCss === 0 && g.scrollEls[0][0] > g.scrollEls[0][1], JSON.stringify(g));
        await T.shot(page, `so3-${name}-sessions`);
        await page.click(tid('soa3-tab-items')); await idle(page);
        await page.waitForTimeout(250);
        g = await geom(page);
        const hasSel = await page.locator(tid('soa3-item-row')).count();
        check(`I ${name} Rincian Item: no horizontal page scroll, content inside the viewport, table inside its own scroller (${hasSel} rows)`, g.hscroll <= 0 && g.rootRight <= vp.width && g.cardsInside && g.nested === 0 && hasSel > 0, JSON.stringify(g));
        await T.shot(page, `so3-${name}-items`);
        await page.locator(tid('soa3-item-row')).nth(2).click();
        await page.waitForSelector(tid('soa3-item-fields')); await page.waitForTimeout(300);
        const dg = await page.evaluate(() => { const d = document.querySelector('.drawer.open'); const b = d.getBoundingClientRect(); return { right: Math.round(b.right), left: Math.round(b.left), bottom: Math.round(b.bottom), vh: innerHeight, vw: innerWidth, hscroll: document.documentElement.scrollWidth - innerWidth, bodyOverflow: d.querySelector('.drawer-body').scrollWidth - d.querySelector('.drawer-body').clientWidth }; });
        check(`I ${name} item drawer: inside the viewport (pinned top/bottom), no horizontal overflow`, dg.right <= dg.vw + 1 && dg.left >= -1 && dg.bottom <= dg.vh + 1 && dg.hscroll <= 0 && dg.bodyOverflow <= 0, JSON.stringify(dg));
        await T.shot(page, `so3-${name}-item-drawer`);
        await page.evaluate(() => Drawer.close()); await page.waitForTimeout(250);
    }
    await page.setViewportSize({ width: 1536, height: 900 });

    // ================================================================ J. other roles
    const vs = await T.newSession(browser, { viewport: { width: 1366, height: 768 }, __name: 'viewer' }, seed.viewer);
    await open(vs.page);
    await setFilters(vs.page, { from: '2026-09-01', to: DAY });
    check('J VIEWER (read-only role) opens the report and sees the sessions; the supervisor-only "Excel Final" is not offered', (await vs.page.locator(tid('soa3-session-row')).count()) === 14);
    await vs.page.click(`${tid('soa3-session-row')}[data-session-id="${V.session_id}"]`);
    await vs.page.waitForSelector(tid('soa3-session-fields'));
    check('J VIEWER drawer: "Buka Rincian Item" and "Lihat Jejak" are there, "Excel Final" (needs STOCK_OPNAME_MANAGE / SUPERVISE) is not', await vs.page.locator(tid('soa3-drawer-rincian')).count() === 1 && await vs.page.locator(tid('soa3-drawer-final')).count() === 0);
    const os2 = await T.newSession(browser, { viewport: { width: 1366, height: 768 }, __name: 'outsider' }, seed.outsider);
    await open(os2.page);
    await setFilters(os2.page, { from: '2026-09-01', to: DAY });
    check('J STOCK user of ANOTHER warehouse: the warehouse select is locked to their own warehouse and none of the fixture sessions leak (empty state)', await os2.page.locator(tid('soa3-wh')).isDisabled() && (await os2.page.locator(tid('soa3-session-row')).count()) === 0 && (await os2.page.locator(tid('soa3-sessions-empty')).count()) === 1);

    // ================================================================ K. the second route (tab-opname-laporan) renders the same new UI
    await page.evaluate(() => { const l = document.querySelector('.sidebar-link[data-tab="opname-laporan"]'); if (l) l.click(); });
    await page.waitForSelector('#tab-opname-laporan.active [data-testid="soa3-title"]', { timeout: 10000 });
    check('K the route "opname-laporan" renders the same Laporan Stock Opname (ReportOpnameAudit), not the retired screen', (await page.locator('#tab-opname-laporan .soa-title').count()) === 0 && (await page.locator('#tab-opname-laporan [data-testid="soa3-print"]').count()) === 1);
} catch (e) {
    check('the test run completed without an exception', false, String((e && e.stack) || e).split('\n').slice(0, 8).join(' | '));
} finally {
    const ok = T.finish();
    if (browser) await browser.close();
    await T.stopServer();
    console.log(`screenshots: ${T.shotDir}`);
    process.exit(ok ? 0 : 1);
}
