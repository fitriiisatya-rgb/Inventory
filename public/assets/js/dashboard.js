/**
 * D3 — read-only dashboard. Every number comes straight from the API's own
 * aggregation (InventoryService/ReconciliationService/StockReportService);
 * this file never recomputes totals from local transaction arrays.
 *
 * PHASE V2: extended with the mandated KPI row (SKU Aktif/Ada Stok/Nilai
 * Stok/Transfer Pending/Opname Aktif/Perlu Perhatian/Di Bawah Minimum),
 * a Need Attention list, Recent Activity, and permission-gated Quick
 * Actions. STOCK role stays warehouse-scoped throughout — every call here
 * (companyValue, stockReport, transfers pending, opname sessions,
 * transactionReport) is already self-scoping server-side for a STOCK
 * user, so this file never passes warehouse_id explicitly.
 */
const Dashboard = (() => {
    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat dashboard...</div>';

        try {
            const canViewReconciliation = Auth.hasPermission('RECONCILIATION_VIEW');
            const canViewTransfers = Auth.hasPermission('WAREHOUSE_TRANSFER_MANAGE');
            const canViewOpname = Auth.hasPermission('STOCK_OPNAME_MANAGE');

            const [value, recon, stockSummaryResult, pendingTransfers, opnameSessions, recentActivity] = await Promise.all([
                InvApi.companyValue(),
                canViewReconciliation ? InvApi.reconciliation() : Promise.resolve(null),
                InvApi.stockReport({ per_page: 1 }).catch(() => null),
                canViewTransfers ? InvApi.listPendingTransfers().catch(() => []) : Promise.resolve([]),
                canViewOpname ? InvApi.listOpnameSessions({ status: 'OPEN' }).catch(() => []) : Promise.resolve([]),
                InvApi.transactionReport({ per_page: 5, sort: 'date', dir: 'desc' }).catch(() => null),
            ]);

            const summary = recon || value;
            const stockSummary = stockSummaryResult ? stockSummaryResult.summary : null;

            container.innerHTML = '';
            container.appendChild(buildKpis(value, summary, stockSummary, pendingTransfers, opnameSessions));
            if (stockSummary) container.appendChild(buildNeedAttention(stockSummary, pendingTransfers));
            container.appendChild(buildQuickActions());
            container.appendChild(buildWarehouseValueTable(summary));
            if (recentActivity) container.appendChild(buildRecentActivity(recentActivity.rows || []));
            if (recon) container.appendChild(buildReconciliationSummary(recon));
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat dashboard: ${(err && err.message) || ''}</div>`;
        }
    }

    function buildKpis(value, recon, stockSummary, pendingTransfers, opnameSessions) {
        const needsAttention = stockSummary ? (stockSummary.out_of_stock_count + stockSummary.critical_count + stockSummary.review_count) : null;
        const belowMinimum = stockSummary ? stockSummary.critical_count : null;
        const cards = [
            kpiCard('Total SKU Aktif', UI.formatNumber(recon.total_sku, 0), `${recon.sku_with_stock} SKU punya stok`),
            kpiCard('Nilai Stok', UI.formatMoney(value.total_value)),
            kpiCard('Transfer Pending', UI.formatNumber((pendingTransfers || []).length, 0)),
            kpiCard('Stock Opname Aktif', UI.formatNumber((opnameSessions || []).length, 0)),
        ];
        if (needsAttention !== null) cards.push(kpiCard('Item Perlu Perhatian', UI.formatNumber(needsAttention, 0), needsAttention > 0 ? 'lihat Need Attention' : undefined));
        if (belowMinimum !== null) cards.push(kpiCard('Stok Di Bawah Minimum', UI.formatNumber(belowMinimum, 0)));
        return UI.el('div', { class: 'grid-4' }, cards);
    }

    function kpiCard(label, valueText, sub) {
        return UI.el('div', { class: 'kpi-card' }, [
            UI.el('div', { class: 'kpi-label' }, label),
            UI.el('div', { class: 'kpi-value' }, valueText),
            sub ? UI.el('div', { class: 'kpi-sub' }, sub) : null,
        ]);
    }

    // PHASE V2.2 — every row drills into Stok Barang pre-filtered to the
    // EXACT backend status value that produced its count (never a frontend
    // recomputation of what "critical"/"low"/etc. means — StockReportService
    // is the one place that's decided, same CASE expression the Master
    // Barang/Stok Barang filters already use).
    function buildNeedAttention(stockSummary, pendingTransfers) {
        const items = [];
        if (stockSummary.review_count > 0) items.push(['Migration Negative Review', stockSummary.review_count, { status: 'MIGRATION_NEGATIVE_REVIEW' }]);
        if (stockSummary.out_of_stock_count > 0) items.push(['Stok Habis', stockSummary.out_of_stock_count, { status: 'OUT_OF_STOCK' }]);
        if (stockSummary.critical_count > 0) items.push(['Stok Kritis (di bawah minimum)', stockSummary.critical_count, { status: 'CRITICAL' }]);
        if (stockSummary.low_count > 0) items.push(['Stok Warning (dalam zona buffer)', stockSummary.low_count, { status: 'LOW' }]);
        if ((pendingTransfers || []).length > 0) items.push(['Transfer Pending', pendingTransfers.length, null]);

        if (items.length === 0) {
            return UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '✅ Need Attention')]),
                UI.el('div', { class: 'alert alert-success' }, 'Tidak ada item yang memerlukan perhatian saat ini.'),
            ]);
        }
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '⚠️ Need Attention')]),
            UI.el('div', {}, items.map(([label, count, filters]) => {
                const row = UI.el('div', {
                    class: 'attention-item attention-item-clickable',
                    role: 'button',
                    tabindex: '0',
                    'aria-label': `${label}: ${count}. Klik untuk lihat detail.`,
                }, [
                    UI.el('span', {}, label),
                    UI.el('span', { style: 'display:flex; align-items:center; gap:8px;' }, [
                        UI.el('span', { class: 'count' }, String(count)),
                        UI.el('span', { class: 'attention-chevron' }, '›'),
                    ]),
                ]);
                const go = () => (filters ? window.InvNav.goToStockReport(filters) : window.InvNav.goToTab('transfer'));
                row.addEventListener('click', go);
                row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
                return row;
            })),
        ]);
    }

    function buildQuickActions() {
        const actions = [
            { label: '📥 Stock IN', permission: 'TRANSACTION_IN_CREATE', tab: 'transaksi' },
            { label: '📤 Stock OUT', permission: 'TRANSACTION_OUT_CREATE', tab: 'transaksi' },
            { label: '🚚 Buat Transfer', permission: 'WAREHOUSE_TRANSFER_MANAGE', tab: 'transfer' },
            { label: '📋 Mulai Opname', permission: 'STOCK_OPNAME_MANAGE', tab: 'opname' },
        ].filter((a) => Auth.hasPermission(a.permission));

        if (actions.length === 0) return UI.el('div', {});

        const buttons = actions.map((a) => {
            const btn = UI.el('button', { class: 'btn btn-primary btn-sm' }, a.label);
            btn.addEventListener('click', () => document.querySelector(`[data-tab="${a.tab}"]`)?.click());
            return btn;
        });
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '⚡ Quick Actions')]),
            UI.el('div', { class: 'quick-actions' }, buttons),
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

    function buildRecentActivity(rows) {
        const trs = rows.map((r) => UI.el('tr', {}, [
            UI.el('td', {}, UI.formatDate(r.transaction_date)),
            UI.el('td', {}, r.transaction_type),
            UI.el('td', {}, `${r.item.sku} — ${r.item.name}`),
            UI.el('td', {}, r.warehouse.name),
            UI.el('td', {}, UI.formatNumber(r.input_qty) + ' ' + r.input_unit.code),
        ]));
        return UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🕒 Aktivitas Terbaru')]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Tanggal', 'Jenis', 'Barang', 'Gudang', 'Qty'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, trs.length ? trs : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada aktivitas')])]),
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
