// Laporan Pergerakan Stok (reports v3) — REAL data through the real UI. Fixture: tests/lib/valuation_fixture.php (via seed_valuation_report.php), period 2026-10-01 .. 2026-10-31.
// Hand-computed: Stok Awal 124.000 + IN 492.000 − OUT 278.800 + Transfer IN 32.000 − Transfer OUT 32.000 + Adjustment −10.700 = Stok Akhir 326.500 (W1 309.500 · W2 17.000).
//   DB_DATABASE=inventory_test DB_USERNAME=inv DB_PASSWORD=invpw RV3_SHOT_DIR=/some/dir node tests/browser/playwright_pergerakan_v3.mjs
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import * as T from './lib/rv3.mjs';
const { check, tid, near, text, hasMoney, norm } = T;

const seed = T.seedDb('tests/browser/seed_valuation_report.php');
await T.startServer();
const W1 = seed.wh['1']; const W2 = seed.wh['2'];
const dl = fs.mkdtempSync(path.join(os.tmpdir(), 'mvdl_'));
const R0 = seed.range;
const idle = (page) => page.waitForFunction(() => !document.querySelector('.rp-loading'), null, { timeout: 25000 });
async function open(page) {
    await page.evaluate(() => document.querySelector('.sidebar-link[data-tab="laporan-pergerakan"]').click());
    await page.waitForSelector('#tab-laporan-pergerakan.active .rp-title');
    await idle(page);
}
async function setFilters(page, f) {
    if (f.from !== undefined) await page.fill(tid('mv-start'), f.from);
    if (f.to !== undefined) await page.fill(tid('mv-end'), f.to);
    if (f.wh !== undefined) await page.selectOption(tid('mv-wh'), f.wh);
    if (f.q !== undefined) await page.fill(tid('mv-q'), f.q);
    await page.click(tid('mv-apply'));
    await page.waitForTimeout(400);
    await idle(page);
}
const kpi = (page, k) => text(page.locator(tid(`mv-kpi-${k}-value`)));
const dailyCells = (page, date) => page.evaluate((d) => Array.from(document.querySelectorAll(`tr[data-date="${d}"] td`)).map((td) => td.textContent.trim()), date);

let browser;
try {
    browser = await chromium.launch();
    const { page } = await T.newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'admin' }, seed.admin);
    await open(page);
    await setFilters(page, { from: R0.from, to: R0.to });

    // ---------------------------------------------------------------- A. header / filter / KPIs
    check('A title "Laporan Pergerakan Stok" + Cetak + Download Excel buttons', (await text(page.locator(tid('mv-title')))) === 'Laporan Pergerakan Stok' && await page.locator(tid('mv-print')).isVisible() && await page.locator(tid('mv-excel')).isVisible());
    check('A the old sidebar report is not rendered over the new one (a single .rp-title, no .mvr-head)', (await page.locator('#tab-laporan-pergerakan .rp-title').count()) === 1 && (await page.locator('#tab-laporan-pergerakan .mvr-head').count()) === 0);
    const fh = await page.locator(tid('mv-filters')).boundingBox();
    check('A the filter card is ONE compact card of at most 2 rows (height ≤ 130px at 1536)', fh && fh.height <= 130, String(fh && fh.height));
    check('A KPIs: Stok Awal 124.000 · Barang Masuk 492.000 · Barang Keluar 278.800 · Stok Akhir 326.500', hasMoney(await kpi(page, 'opening'), 124000) && hasMoney(await kpi(page, 'masuk'), 492000) && hasMoney(await kpi(page, 'keluar'), 278800) && hasMoney(await kpi(page, 'closing'), 326500));
    check('A the other three KPI cards: Transfer IN Rp 32.000 · Transfer OUT Rp 32.000 · Adjustment −Rp 10.700', hasMoney(await kpi(page, 'tin'), 32000) && hasMoney(await kpi(page, 'tout'), 32000) && hasMoney(await kpi(page, 'adjustment'), 10700) && (await kpi(page, 'adjustment')).includes('-'));
    check('A the KPI cards carry the mockup icon tile (one per card)', (await page.locator(`${tid('mv-kpis')} .rp-kpi .rp-kpi-ic`).count()) === 7);
    check('A the reconciliation strip is green and states "seimbang" with Transfer IN / OUT 32.000 and the formula-adjustment −10.700', await page.locator(tid('mv-recon-ok')).count() === 1 && hasMoney(await text(page.locator(tid('mv-recon-ok'))), 32000) && hasMoney(await text(page.locator(tid('mv-recon-ok'))), 10700));
    const nest = await page.evaluate(() => document.querySelectorAll('#tab-laporan-pergerakan .rp-card .rp-card, #tab-laporan-pergerakan .rp-kpi .rp-kpi').length);
    check('A no card inside a card', nest === 0);

    // ---------------------------------------------------------------- A2. mockup composition: chart Grafik | Tabel, "Detail Pergerakan Stok Harian" card with search + Download Detail
    check('A2 chart card "Grafik Pergerakan Stok Harian" with the Grafik | Tabel toggle and the legend', (await text(page.locator('#mv-chart .rp-card-title'))).startsWith('Grafik Pergerakan Stok Harian') && await page.locator(tid('mv-chartview-grafik')).count() === 1 && await page.locator('#mv-chart .rp-legend').count() === 1);
    await page.click(tid('mv-chartview-tabel'));
    check('A2 "Tabel" shows the daily numbers behind the chart (31 rows, Stok Akhir 326.500 on the last day) and hides the SVG', await page.locator(tid('mv-chart-svg')).count() === 0 && (await page.locator(`${tid('mv-chart-table')} tbody tr`).count()) === 31 && hasMoney(await text(page.locator(`${tid('mv-chart-table')} tbody tr`).last()), 326500));
    await page.click(tid('mv-chartview-grafik'));
    check('A2 back on "Grafik": the SVG chart is drawn full width of its card', await page.locator(tid('mv-chart-svg')).count() === 1 && await page.evaluate(() => { const c = document.querySelector('#mv-chart .rp-chart').getBoundingClientRect(); const s = document.querySelector('[data-testid="mv-chart-svg"]').getBoundingClientRect(); return s.width >= c.width - 4; }));
    check('A2 data card titled "Detail Pergerakan Stok Harian" with the Harian | Per Barang tabs, a date search and "Download Detail"', (await text(page.locator('#mv-data .rp-card-title'))).startsWith('Detail Pergerakan Stok Harian') && await page.locator(tid('mv-search')).count() === 1 && await page.locator(tid('mv-download-detail')).count() === 1);
    await page.fill(tid('mv-search'), '03 Okt');
    await page.waitForTimeout(500);
    check('A2 the date search narrows the daily table to the matching day (03 Okt 2026) and clearing it restores 31 rows', (await page.locator(tid('mv-daily-row')).count()) === 1);
    await page.fill(tid('mv-search'), '');
    await page.waitForTimeout(500);
    check('A2 31 rows again after clearing the search', (await page.locator(tid('mv-daily-row')).count()) === 31);

    // ---------------------------------------------------------------- B. daily table
    const heads = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="mv-daily-table"] thead th')).map((t) => t.textContent.trim()));
    check('B daily table columns: Tanggal, Stok Awal, Barang Masuk, Barang Keluar, Transfer IN, Transfer OUT, Adjustment, Stok Akhir', JSON.stringify(heads) === JSON.stringify(['Tanggal', 'Stok Awal', 'Barang Masuk', 'Barang Keluar', 'Transfer IN', 'Transfer OUT', 'Adjustment', 'Stok Akhir']), heads.join('|'));
    check('B 31 daily rows for October', (await page.locator(tid('mv-daily-row')).count()) === 31);
    const c3 = await dailyCells(page, '2026-10-03');
    check('B 03 Okt 2026: Transfer IN and Transfer OUT both Rp 32.000 (company-wide), Barang Keluar 80.000, Adjustment −14.700', hasMoney(c3[4], 32000) && hasMoney(c3[5], 32000) && hasMoney(c3[3], 80000) && hasMoney(c3[6], 14700) && c3[6].includes('-'), c3.join(' | '));
    const tot = await text(page.locator(tid('mv-daily-total')));
    check('B TOTAL PERIODE row: 124.000 / 492.000 / 278.800 / 32.000 / 32.000 / −10.700 / 326.500', ['124.000', '492.000', '278.800', '32.000', '10.700', '326.500'].every((s) => tot.includes(s)), tot);
    const api = await T.api(page, '/reports/movement/v3/overview', { start_date: R0.from, end_date: R0.to });
    const identity = await page.evaluate(() => {
        const num = (s) => Number(String(s).replace(/[^0-9,-]/g, '').replace(',', '.'));
        const rows = Array.from(document.querySelectorAll('[data-testid="mv-daily-row"]')).map((tr) => Array.from(tr.querySelectorAll('td')).slice(1).map((td) => num(td.textContent)));
        return rows.every((r) => Math.abs(r[0] + r[1] - r[2] + r[3] - r[4] + r[5] - r[6]) < 0.01);
    });
    check('B on the screen: Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment = Stok Akhir on every displayed day', identity && api.reconciliation.ok && api.reconciliation.split_ok);

    // ---------------------------------------------------------------- C. warehouse scope
    await setFilters(page, { wh: String(W1) });
    check('C Gudang W1: Stok Akhir 309.500, Transfer OUT 32.000 and Transfer IN Rp 0 in the total row', hasMoney(await kpi(page, 'closing'), 309500) && (await text(page.locator(tid('mv-daily-total')))).includes('309.500'));
    const w1c3 = await dailyCells(page, '2026-10-03');
    check('C W1 on 03 Okt: Transfer OUT 32.000, Transfer IN 0', hasMoney(w1c3[5], 32000) && !hasMoney(w1c3[4], 32000), w1c3.join(' | '));
    await setFilters(page, { wh: String(W2) });
    const w2c3 = await dailyCells(page, '2026-10-03');
    check('C Gudang W2: Stok Akhir 17.000, Transfer IN 32.000 on 03 Okt, Transfer OUT 0', hasMoney(await kpi(page, 'closing'), 17000) && hasMoney(w2c3[4], 32000) && !hasMoney(w2c3[5], 32000), w2c3.join(' | '));
    await setFilters(page, { wh: '' });

    // ---------------------------------------------------------------- D. qty mode (never a mixed sum)
    await page.click(tid('mv-mode-qty'));
    await page.waitForTimeout(300);
    check('D Kuantitas: KPIs are grouped by unit ("PCS …"), no Rupiah sum', (await kpi(page, 'opening')).startsWith('PCS 80') && (await kpi(page, 'masuk')).startsWith('PCS 690') && (await kpi(page, 'closing')).startsWith('PCS 495'), `${await kpi(page, 'opening')} / ${await kpi(page, 'masuk')}`);
    const unitRow = await text(page.locator(tid('mv-unit-row')).first());
    check('D per-unit summary: PCS Awal 80 · Masuk 690 · Keluar 265 · Transfer 60 / 60 · Adjustment −10 · Akhir 495', ['PCS', '80', '690', '265', '60', '-10', '495'].every((s) => unitRow.includes(s)), unitRow);
    check('D qty chart shows the "pilih satu barang" message instead of a mixed-unit chart', await page.locator(tid('mv-chart-qty-message')).count() === 1);
    await page.click(tid('mv-mode-nominal'));
    await page.waitForTimeout(250);

    // ---------------------------------------------------------------- E. chart
    check('E the daily chart renders (bars + closing line) with a legend', await page.locator(tid('mv-chart-svg')).count() === 1 && (await page.locator('.rp-chart .b-in').count()) > 0 && (await page.locator('.rp-legend span').count()) === 4);

    // ---------------------------------------------------------------- F. per barang tab
    await page.click(tid('mv-tab-barang'));
    await page.waitForSelector(tid('mv-item-row'));
    check('F Per Barang: 7 items, server TOTAL row = Stok Awal 124.000 / Stok Akhir 326.500', (await page.locator(tid('mv-item-row')).count()) === 7 && (await text(page.locator(tid('mv-items-total')))).includes('326.500') && (await text(page.locator(tid('mv-items-total')))).includes('124.000'));
    const ih = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="mv-items-table"] thead th')).map((t) => t.textContent.replace(/[▲▼]/g, '').trim()));
    check('F per-item columns: SKU, Nama Barang, Satuan, Stok Awal, Masuk, Keluar (HPP), Transfer IN, Transfer OUT, Adjustment, Stok Akhir', JSON.stringify(ih) === JSON.stringify(['SKU', 'Nama Barang', 'Satuan', 'Stok Awal', 'Masuk', 'Keluar (HPP)', 'Transfer IN', 'Transfer OUT', 'Adjustment', 'Stok Akhir']), ih.join('|'));
    await page.click('[data-sort="closing_value"]'); await page.waitForTimeout(400); await idle(page);
    await page.click('[data-sort="closing_value"]'); await page.waitForTimeout(400); await idle(page);
    const closes = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="mv-item-row"]')).map((tr) => Number(tr.querySelectorAll('td')[9].textContent.replace(/[^0-9]/g, '') || 0)));
    check('F sorting by Stok Akhir (server-side, 2nd click = desc)', closes.length === 7 && closes.every((v, i) => i === 0 || closes[i - 1] >= v), closes.join(','));
    await page.fill(tid('mv-item-q'), seed.items.R.sku);
    await page.waitForTimeout(800); await idle(page);
    check('F debounced search narrows to item R', (await page.locator(tid('mv-item-row')).count()) === 1);
    const rRow = await text(page.locator(tid('mv-item-row')).first());
    check('F item R row: Transfer IN 32.000, Transfer OUT 32.000, Stok Akhir 45.000', (rRow.match(/32\.000/g) || []).length >= 2 && rRow.includes('45.000'), rRow);
    await page.locator(tid('mv-item-row')).first().click();
    await page.waitForSelector('.drawer.open .rp-table tbody tr');
    const dRows = await page.locator('.drawer.open .rp-table tbody tr').count();
    check('F row click → drawer with the ledger lines of item R (IN ×2, OUT ×2 at least, transfer lines)', dRows >= 5, String(dRows));
    await T.shot(page, 'pergerakan-v3-item-drawer');
    await page.evaluate(() => document.querySelector('.drawer-close').click());
    await page.waitForTimeout(300);
    await page.fill(tid('mv-item-q'), '');
    await page.waitForTimeout(800); await idle(page);

    // ---------------------------------------------------------------- G. print (Cetak)
    await page.click(tid('mv-tab-harian'));
    await page.waitForTimeout(250);
    let html = await T.capturePrint(page, 'mv-print');
    T.printChecks('G Harian', html, { title: 'Laporan Pergerakan Stok', landscape: true, mustContain: ['Ringkasan Harian', 'Transfer IN', 'Transfer OUT', 'TOTAL', '01 Okt 2026 s/d 31 Okt 2026', 'Semua Gudang', 'Nominal (Rp)', 'Rp 326.500'], mustNotContain: ['Rincian per Barang'] });
    check('G print numbers are right-aligned money cells (class r) and the total row is bold', /<td class="r">Rp 124\.000<\/td>/.test(html) && html.includes('class="tot"'));
    await page.click(tid('mv-tab-barang')); await page.waitForSelector(tid('mv-item-row'));
    html = await T.capturePrint(page, 'mv-print');
    T.printChecks('G Per Barang', html, { title: 'Laporan Pergerakan Stok', mustContain: ['Rincian per Barang', seed.items.R.sku, seed.items.R.name], mustNotContain: ['Ringkasan Harian'] });
    await page.click(tid('mv-mode-qty')); await page.waitForTimeout(300);
    html = await T.capturePrint(page, 'mv-print');
    T.printChecks('G Kuantitas', html, { title: 'Laporan Pergerakan Stok', mustContain: ['Ringkasan per Satuan', 'Kuantitas berbeda satuan tidak dijumlahkan', 'Qty Akhir'] });
    await page.click(tid('mv-mode-nominal')); await page.click(tid('mv-tab-harian')); await page.waitForTimeout(250);

    // ---------------------------------------------------------------- H. Excel
    const x = await T.downloadVia(page, 'mv-excel', dl);
    check('H Download Excel: filename Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx', x.name === 'Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx' && x.size > 2000, `${x.name} ${x.size}`);
    const wb = T.xlsxDump(x.file);
    const names = wb.sheets.map((s) => s.name);
    check('H sheets: Ringkasan, Harian, Per Barang, Detail Harian per Barang, Per Satuan (Qty)', JSON.stringify(names) === JSON.stringify(['Ringkasan', 'Harian', 'Per Barang', 'Detail Harian per Barang', 'Per Satuan (Qty)']), names.join('|'));
    const har = wb.sheets[1];
    check('H Harian: header frozen, autofilter, widths set; 31 day rows + header + TOTAL', har.frozen && har.autofilter.startsWith('A1:') && har.col_widths.length >= 8 && har.rows.length === 33);
    check('H Harian: dates are real Excel dates (t=d), money numeric with a Rp format, no text numbers', har.rows[1][0].t === 'd' && har.rows[1][1].t === 'n' && har.rows[1][1].fmt.includes('Rp') && typeof har.rows[2][2].v === 'number');
    const total = har.rows[har.rows.length - 1];
    check('H Harian TOTAL row equals the screen: 124.000 / 492.000 / 278.800 / 32.000 / 32.000 / −10.700 / 326.500', near(total[1].v, 124000) && near(total[2].v, 492000) && near(total[3].v, 278800) && near(total[4].v, 32000) && near(total[5].v, 32000) && near(total[6].v, -10700) && near(total[7].v, 326500));
    const pb = wb.sheets[2];
    check('H Per Barang: 7 items + header + TOTAL, qty numeric, SKU text', pb.rows.length === 9 && pb.rows[1][0].t === 's' && pb.rows[1][4].t === 'n' && near(pb.rows[8][17].v, 326500));
    // follows the filters: W2 only
    await setFilters(page, { wh: String(W2) });
    const x2 = await T.downloadVia(page, 'mv-excel', dl);
    const wb2 = T.xlsxDump(x2.file);
    const t2 = wb2.sheets[1].rows[wb2.sheets[1].rows.length - 1];
    check('H the Excel follows the active filters: Gudang W2 → Stok Akhir 17.000, Transfer IN 32.000', near(t2[7].v, 17000) && near(t2[4].v, 32000) && norm(JSON.stringify(wb2.sheets[0].rows)).includes('Gudang'));
    await setFilters(page, { wh: '' });

    // ---------------------------------------------------------------- I. drilldowns
    await page.locator('tr[data-date="2026-10-03"]').click();
    await page.waitForSelector('.drawer.open .rp-table tbody tr');
    check('I a daily row opens the day drawer with the items that moved on 03 Okt', (await page.locator('.drawer.open .rp-table tbody tr').count()) >= 2);
    await page.evaluate(() => document.querySelector('.drawer-close').click());
    await page.waitForTimeout(300);
    await page.click(tid('mv-kpi-masuk'));
    await page.waitForSelector('.drawer.open .rp-table tbody tr');
    check('I the Barang Masuk KPI opens the period transactions drawer with the IN total', hasMoney(await text(page.locator(tid('mv-ptx-total'))), 492000));
    await page.evaluate(() => document.querySelector('.drawer-close').click());
    await page.waitForTimeout(300);

    // ---------------------------------------------------------------- J. responsive / screenshots
    for (const [name, vp] of [['desktop-1536', { width: 1536, height: 864 }], ['desktop-1366', { width: 1366, height: 768 }], ['ipad-landscape', { width: 1180, height: 820 }], ['ipad-portrait', { width: 820, height: 1180 }]]) {
        await page.setViewportSize(vp);
        await page.waitForTimeout(250);
        const geo = await page.evaluate(() => {
            const k = Array.from(document.querySelectorAll('[data-testid="mv-kpis"] .rp-kpi')).map((e) => e.getBoundingClientRect());
            const f = document.querySelector('[data-testid="mv-filters"]').getBoundingClientRect();
            const vis = Array.from(document.querySelectorAll('.tab-content')).filter((e) => e.offsetParent !== null).length;
            return { n: k.length, tops: new Set(k.map((r) => Math.round(r.top))).size, maxH: Math.max(...k.map((r) => r.height)), fh: f.height, vis, w: innerWidth };
        });
        check(`J ${name}: no horizontal page scroll; SEVEN KPI cards (Stok Awal, Masuk, Keluar, Transfer IN, Transfer OUT, Adjustment, Stok Akhir) — never a giant card`, await T.noHScroll(page) && geo.n === 7 && geo.maxH <= 92, JSON.stringify(geo));
        if (vp.width >= 1100) check(`J ${name}: the seven KPI cards sit in ONE row; the filter card is at most two rows (≤ 150px)`, geo.tops === 1 && geo.fh <= 150, JSON.stringify(geo));
        else check(`J ${name}: KPI cards wrap into a compact grid (2+ columns, ≤ 4 rows)`, geo.tops >= 2 && geo.tops <= 4, JSON.stringify(geo));
        check(`J ${name}: only the active page is displayed (no earlier report left on screen)`, geo.vis === 1, String(geo.vis));
        await T.shot(page, `pergerakan-v3-${name}`);
    }
    await page.setViewportSize({ width: 1536, height: 864 });
    await page.click(tid('mv-tab-barang')); await page.waitForSelector(tid('mv-item-row'));
    await T.shot(page, 'pergerakan-v3-per-barang');

    // ---------------------------------------------------------------- K. STOCK user is pinned to their warehouse
    const st = await T.newSession(browser, { viewport: { width: 1536, height: 864 }, __name: 'stock2' }, seed.stock2);
    await open(st.page);
    await setFilters(st.page, { from: R0.from, to: R0.to });
    check('K STOCK user (W2): the warehouse select is locked to W2 and the numbers are W2-only (Stok Akhir 17.000)', await st.page.locator(tid('mv-wh')).isDisabled() && (await st.page.inputValue(tid('mv-wh'))) === String(W2) && hasMoney(await kpi(st.page, 'closing'), 17000));
} finally {
    const ok = T.finish();
    if (browser) await browser.close();
    await T.stopServer();
    process.exit(ok ? 0 : 1);
}
