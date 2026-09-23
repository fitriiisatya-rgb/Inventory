/**
 * PHASE V2.11C — Laporan Penjualan / Distribusi Bakery. Every figure shown
 * here is read straight from DistributionReportService's response — this
 * module never computes revenue/HPP/margin itself (see that service's
 * docblock for the strict source-of-truth rules: revenue from ISSUED
 * invoices only, HPP from the real FIFO cost, never the reference/policy
 * price).
 */
const DistributionReports = (() => {
    let filters = {
        date_from: new Date(new Date().setDate(1)).toISOString().slice(0, 10),
        date_to: new Date().toISOString().slice(0, 10),
    };

    async function render(container) {
        container.innerHTML = '';
        container.appendChild(buildFilterBar());
        const resultsHost = UI.el('div', { id: 'distribusi-laporan-results' });
        container.appendChild(resultsHost);
        await loadAndRender(resultsHost);
    }

    function buildFilterBar() {
        const bakeryOptions = Master.bakeryDestinations().map((b) => `<option value="${b.id}">${b.name}</option>`).join('');
        const categoryOptions = Master.categories().map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📊 Laporan Penjualan / Distribusi Bakery')]),
            UI.el('div', { class: 'grid-4', html: `
                <div class="form-group"><label>Dari Tanggal</label><input type="date" id="lap-date-from" value="${filters.date_from}"></div>
                <div class="form-group"><label>Sampai Tanggal</label><input type="date" id="lap-date-to" value="${filters.date_to}"></div>
                <div class="form-group"><label>Bakery</label><select id="lap-bakery"><option value="">Semua Bakery</option>${bakeryOptions}</select></div>
                <div class="form-group"><label>Kategori</label><select id="lap-category"><option value="">Semua Kategori</option>${categoryOptions}</select></div>
            ` }),
            UI.el('button', { class: 'btn btn-primary btn-sm', id: 'lap-filter-btn' }, 'Terapkan Filter'),
        ]);
        setTimeout(() => {
            document.getElementById('lap-filter-btn').addEventListener('click', async () => {
                filters = {
                    date_from: document.getElementById('lap-date-from').value,
                    date_to: document.getElementById('lap-date-to').value,
                    bakery_destination_id: document.getElementById('lap-bakery').value || undefined,
                    category_id: document.getElementById('lap-category').value || undefined,
                };
                await loadAndRender(document.getElementById('distribusi-laporan-results'));
            });
        }, 0);
        return card;
    }

    async function loadAndRender(host) {
        host.innerHTML = '<div class="alert alert-info">Memuat laporan...</div>';
        let summary; let byCategory; let byBakery; let lines;
        try {
            [summary, byCategory, byBakery, lines] = await Promise.all([
                InvApi.distributionReportSummary(filters),
                InvApi.distributionReportByCategory(filters),
                InvApi.distributionReportByBakery(filters),
                InvApi.distributionReportLines(filters),
            ]);
        } catch (err) {
            UI.handleApiError(err);
            host.innerHTML = `<div class="alert alert-error">Gagal memuat laporan: ${(err && err.message) || ''}</div>`;
            return;
        }
        host.innerHTML = '';

        const kpis = [
            ['Total Penjualan / Revenue', UI.formatMoney(summary.total_revenue)],
            ['Total HPP (Aktual)', UI.formatMoney(summary.total_hpp)],
            ['Total Margin Aktual', UI.formatMoney(summary.total_margin)],
            ['Margin Aktual %', `${UI.formatNumber(summary.margin_pct)}%`],
            ['Total DO', UI.formatNumber(summary.total_do, 0)],
            ['Total Invoice', UI.formatNumber(summary.total_invoice, 0)],
            ['Bakery Dilayani', UI.formatNumber(summary.total_bakery_served, 0)],
            ['Jumlah Selisih (Discrepancy)', UI.formatNumber(summary.discrepancy_count, 0)],
        ];
        const kpiBox = UI.el('div', { class: 'grid-4', style: 'margin-bottom:16px;' });
        kpis.forEach(([label, value]) => {
            kpiBox.appendChild(UI.el('div', { class: 'kpi-card' }, [
                UI.el('div', { class: 'kpi-label' }, label),
                UI.el('div', { class: 'kpi-value' }, value),
            ]));
        });
        host.appendChild(kpiBox);

        host.appendChild(aggregateTable('Per Kategori', ['Kategori', 'Qty', 'Revenue', 'HPP', 'Margin', 'Margin %'], byCategory, (r) => [
            r.category_name || 'Tanpa Kategori', UI.formatNumber(r.qty_distributed), UI.formatMoney(r.revenue), UI.formatMoney(r.hpp), UI.formatMoney(r.margin), `${UI.formatNumber(r.margin_pct)}%`,
        ]));
        host.appendChild(aggregateTable('Per Bakery', ['Bakery', 'DO', 'Invoice', 'Qty', 'Revenue', 'HPP', 'Margin', 'Margin %', 'Selisih'], byBakery, (r) => [
            r.bakery_name, r.do_count, r.invoice_count, UI.formatNumber(r.qty_distributed), UI.formatMoney(r.revenue), UI.formatMoney(r.hpp), UI.formatMoney(r.margin), `${UI.formatNumber(r.margin_pct)}%`, r.discrepancy_count,
        ]));

        const lineRows = lines.map((l) => UI.el('tr', {}, [
            UI.el('td', {}, l.do_date), UI.el('td', {}, l.do_number), UI.el('td', {}, l.invoice_number),
            UI.el('td', {}, l.bakery_name), UI.el('td', {}, l.category_name || '-'), UI.el('td', {}, `${l.sku_snapshot} — ${l.item_name_snapshot}`),
            UI.el('td', {}, `${UI.formatNumber(l.qty)} ${l.unit_code}`),
            UI.el('td', {}, UI.formatMoney(l.selling_unit_price)), UI.el('td', {}, UI.formatMoney(l.revenue)),
            UI.el('td', {}, UI.formatMoney(l.actual_hpp)), UI.el('td', {}, UI.formatMoney(l.actual_margin)), UI.el('td', {}, `${UI.formatNumber(l.actual_margin_pct)}%`),
        ]));
        const exportBtn = UI.el('a', { class: 'btn btn-secondary btn-sm', href: InvApi.distributionReportLinesExportUrl(filters), target: '_blank' }, '⬇️ Export CSV');
        host.appendChild(UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [
                UI.el('div', { class: 'card-title' }, 'Detail per Baris'),
                exportBtn,
            ]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal DO', 'No. DO', 'No. Invoice', 'Bakery', 'Kategori', 'Produk', 'Qty', 'Harga Jual', 'Revenue', 'HPP Aktual', 'Margin Aktual', 'Margin %'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, lineRows.length ? lineRows : [UI.el('tr', {}, [UI.el('td', { colspan: '12' }, 'Tidak ada data pada rentang filter ini.')])]),
                ]),
            ]),
        ]));
    }

    function aggregateTable(title, headers, rows, mapRow) {
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, title)]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, headers.map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, rows.length ? rows.map((r) => UI.el('tr', {}, mapRow(r).map((v) => UI.el('td', {}, String(v))))) : [UI.el('tr', {}, [UI.el('td', { colspan: String(headers.length) }, '-')])]),
                ]),
            ]),
        ]);
    }

    return { render };
})();
