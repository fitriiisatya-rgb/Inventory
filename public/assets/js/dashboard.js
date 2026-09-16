/**
 * D3 — read-only dashboard. Every number comes straight from the API's own
 * aggregation (InventoryService/ReconciliationService); this file never
 * recomputes totals from local transaction arrays.
 */
const Dashboard = (() => {
    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat dashboard...</div>';
        try {
            const [value, recon] = await Promise.all([
                InvApi.companyValue(),
                InvApi.reconciliation(),
            ]);
            container.innerHTML = '';
            container.appendChild(buildKpis(value, recon));
            container.appendChild(buildWarehouseValueTable(recon));
            container.appendChild(buildReconciliationSummary(recon));
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat dashboard: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildKpis(value, recon) {
        const wrap = UI.el('div', { class: 'grid-4' }, [
            kpiCard('Nilai Stok On-Hand', UI.formatMoney(value.on_hand_value)),
            kpiCard('Nilai Barang In-Transit', UI.formatMoney(value.in_transit_value)),
            kpiCard('Total Nilai Inventory', UI.formatMoney(value.total_value)),
            kpiCard('Total SKU Aktif', UI.formatNumber(recon.total_sku, 0), `${recon.sku_with_stock} SKU punya stok`),
        ]);
        return wrap;
    }

    function kpiCard(label, valueText, sub) {
        return UI.el('div', { class: 'kpi-card' }, [
            UI.el('div', { class: 'kpi-label' }, label),
            UI.el('div', { class: 'kpi-value' }, valueText),
            sub ? UI.el('div', { class: 'kpi-sub' }, sub) : null,
        ]);
    }

    function buildWarehouseValueTable(recon) {
        const rows = (recon.value_per_warehouse || []).map((row) => UI.el('tr', {}, [
            UI.el('td', {}, row.name),
            UI.el('td', {}, row.code),
            UI.el('td', {}, UI.formatMoney(row.value)),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, 'Nilai Stok per Gudang')]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, [UI.el('th', {}, 'Gudang'), UI.el('th', {}, 'Kode'), UI.el('th', {}, 'Nilai')])]),
                    UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '3' }, 'Belum ada data')])]),
                ]),
            ]),
        ]);
    }

    function buildReconciliationSummary(recon) {
        const statusClass = recon.go_live_ready ? 'alert-success' : 'alert-warning';
        const statusText = recon.go_live_ready ? 'GO_LIVE_READY' : 'BELUM SIAP (ada pemeriksaan berstatus ERROR)';
        const checkRows = Object.entries(recon.checks || {}).map(([name, check]) => UI.el('tr', {}, [
            UI.el('td', {}, name),
            UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(check.status)}` }, check.status)),
            UI.el('td', {}, String(check.count)),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, 'Status Reconciliation')]),
            UI.el('div', { class: `alert ${statusClass}` }, statusText),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, [UI.el('th', {}, 'Pemeriksaan'), UI.el('th', {}, 'Status'), UI.el('th', {}, 'Jumlah')])]),
                    UI.el('tbody', {}, checkRows),
                ]),
            ]),
        ]);
    }

    return { render };
})();
