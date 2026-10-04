/**
 * MOCKUP — "Jejak Stock Opname" detail drawer, per the supplied reference
 * design. Fast-path visual mockup only (explicitly NOT a backend/data-
 * model task this round): opens a wide Drawer (reusing the existing
 * Drawer component — drawer.js — untouched) when a session row in
 * stock-opname-report.js's list is clicked.
 *
 * ALL DATA IN THIS FILE IS MOCK, clearly isolated in buildMockDetail()
 * below. Nothing here calls InvApi, posts anything, or touches
 * StockOpnameService/TransferService/FifoService/the DB. Cetak Laporan /
 * Export Excel are safe no-op toasts only; Posting Adjustment is
 * hard-disabled (see open()/buildPerBarangTab()) and can never fire a
 * click handler, let alone a real posting call. The drawer header always
 * carries a "PREVIEW UI — DATA SIMULASI" badge + explanatory note so this
 * can never be mistaken for a live report even once linked from a real
 * menu.
 *
 * Shaped loosely after stock-opname-report.js's own REAL detail response
 * (detail.session / detail.finance_summary / detail.items) so swapping
 * in a real endpoint later is a data-source change, not a UI rewrite.
 */
const StockOpnameJejak = (() => {
    function money(n) { return UI.formatMoney(n); }
    function num(n) { return UI.formatNumber(n, 0); }

    // PRODUCTION PREVIEW — derives the drawer header's "#N" from the real
    // session_number's trailing digit group (e.g. "SO-20260930-0012" -> 12)
    // instead of the raw DB id, when that's safely parseable. Falls back to
    // the raw id unchanged if session_number is missing/unparseable.
    function deriveSessionDisplayNumber(session) {
        const raw = session && session.session_number;
        if (typeof raw === 'string') {
            const match = raw.match(/(\d+)(?!.*\d)/);
            if (match) {
                const parsed = parseInt(match[1], 10);
                if (!Number.isNaN(parsed)) return String(parsed);
            }
        }
        return String((session && session.id) ?? '');
    }

    // ---- MOCK DATA — replace with a real API call in a future round. ----
    function buildMockDetail(session) {
        const items = [
            { sku: 'BRD-001', name: 'Roti Tawar Kupas', category: 'Roti', unit: 'Pack', qty_sistem: 100, count1: 98, petugas1: 'Dewi', count2: 97, petugas2: 'Arman', final: 97, selisih_qty: -3, dead_qty: 0, rusak_qty: 0, hpp: 8500, nilai_selisih: -25500, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'BRD-002', name: 'Roti Manis Coklat', category: 'Roti', unit: 'Pcs', qty_sistem: 250, count1: 252, petugas1: 'Dewi', count2: 251, petugas2: 'Arman', final: 251, selisih_qty: 1, dead_qty: 0, rusak_qty: 0, hpp: 3000, nilai_selisih: 3000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'CK-001', name: 'Cookies Choco Chips', category: 'Kue Kering', unit: 'Pack', qty_sistem: 75, count1: 75, petugas1: 'Dewi', count2: 75, petugas2: 'Arman', final: 75, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, hpp: 12000, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'CK-002', name: 'Cookies Kastengel', category: 'Kue Kering', unit: 'Pack', qty_sistem: 40, count1: 38, petugas1: 'Dewi', count2: 38, petugas2: 'Arman', final: 38, selisih_qty: -2, dead_qty: 0, rusak_qty: 0, hpp: 15000, nilai_selisih: -30000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'TR-001', name: 'Brownies Coklat', category: 'Kue Basah', unit: 'Pcs', qty_sistem: 60, count1: 58, petugas1: 'Dewi', count2: 59, petugas2: 'Arman', final: 59, selisih_qty: -1, dead_qty: 0, rusak_qty: 2, hpp: 10000, nilai_selisih: -10000, nilai_dead: 0, nilai_rusak: 20000 },
            { sku: 'TR-002', name: 'Lapis Legit', category: 'Kue Basah', unit: 'Pcs', qty_sistem: 30, count1: 30, petugas1: 'Dewi', count2: 28, petugas2: 'Arman', final: 28, selisih_qty: -2, dead_qty: 3, rusak_qty: 0, hpp: 25000, nilai_selisih: -50000, nilai_dead: 75000, nilai_rusak: 0 },
            { sku: 'MD-001', name: 'Tepung Terigu', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 500, count1: 505, petugas1: 'Dewi', count2: 505, petugas2: 'Arman', final: 505, selisih_qty: 5, dead_qty: 0, rusak_qty: 0, hpp: 12000, nilai_selisih: 60000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'MD-002', name: 'Gula Pasir', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 300, count1: 300, petugas1: 'Dewi', count2: 300, petugas2: 'Arman', final: 300, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, hpp: 13000, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'MD-003', name: 'Mentega', category: 'Bahan Baku', unit: 'Kg', qty_sistem: 120, count1: 118, petugas1: 'Dewi', count2: 118, petugas2: 'Arman', final: 118, selisih_qty: -2, dead_qty: 0, rusak_qty: 0, hpp: 28000, nilai_selisih: -56000, nilai_dead: 0, nilai_rusak: 0 },
            { sku: 'PKG-001', name: 'Box Kue 26x26', category: 'Kemasan', unit: 'Pcs', qty_sistem: 200, count1: 195, petugas1: 'Dewi', count2: 195, petugas2: 'Arman', final: 195, selisih_qty: -5, dead_qty: 0, rusak_qty: 0, hpp: 4000, nilai_selisih: -20000, nilai_dead: 0, nilai_rusak: 0 },
        ];
        return {
            session: {
                number: session.session_number || 'SO-20260930-0012',
                date: session.session_date || '30 September 2026',
                warehouse: session.warehouse_name || 'Gudang Cibadak',
                warehouse_code: 'CIBADAK',
                status: session.status || 'POSTED',
                created_by: 'superadmin',
                finalized_by: 'Rina', finalized_at: '01 Oktober 2026 10:15',
                posted_by: 'Fajar', posted_at: '01 Oktober 2026 11:30',
                petugas1: 'Dewi', petugas1_date: '30 September 2026',
                petugas2: 'Arman', petugas2_date: '30 September 2026',
                supervisor: 'Andi SPV', supervisor_date: '01 Oktober 2026 09:45',
            },
            kpi: {
                nilai_stok_sistem: 125460000, nilai_final_count: 123985000, selisih_nominal: 1475000,
                dead_stock: 640000, rusak: 225000, adjustment_bersih: 610000,
            },
            bottom: { sku_cocok: 96, perlu_review: 18, dead_stock_sku: 9, rusak_sku: 5 },
            items,
        };
    }

    // ---- small UI pieces (reuses existing .hpp-kpi-*/.so-report-* CSS) ----
    function kpiTile(icon, label, value, color) {
        return UI.el('div', { class: 'card hpp-kpi-card', style: `border-left:3px solid ${color};` }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-label' }, label),
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${color};` }, money(value)),
            ]),
        ]);
    }

    function miniStat(icon, label, value, color) {
        return UI.el('div', { class: 'card hpp-kpi-card', style: `border-left:3px solid ${color};` }, [
            UI.el('div', { class: 'hpp-kpi-icon' }, icon),
            UI.el('div', {}, [
                UI.el('div', { class: 'hpp-kpi-value', style: `color:${color};` }, String(value)),
                UI.el('div', { class: 'hpp-kpi-label' }, label),
            ]),
        ]);
    }

    function variancePill(value, isMoney) {
        if (!value) return UI.el('span', { style: 'color:var(--text3);' }, isMoney ? money(0) : '0');
        const color = value > 0 ? 'var(--green)' : 'var(--red)';
        const text = isMoney ? money(value) : (value > 0 ? `+${num(value)}` : num(value));
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, text);
    }

    function qtyCell(value, color) {
        if (!value) return UI.el('span', { style: 'color:var(--text3);' }, '0');
        return UI.el('span', { style: `color:${color}; font-weight:700;` }, num(value));
    }

    function buildInfoCard(s) {
        const rows = [
            ['Gudang', `${s.warehouse_code} — ${s.warehouse}`],
            ['Status', UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status)],
            ['Tanggal Sesi', s.date],
            ['Dibuat Oleh', s.created_by],
            ['Difinalisasi Oleh', `${s.finalized_by} · ${s.finalized_at}`],
            ['Diposting Oleh', `${s.posted_by} · ${s.posted_at}`],
            ['Petugas Count 01', `${s.petugas1} · ${s.petugas1_date}`],
            ['Petugas Count 02', `${s.petugas2} · ${s.petugas2_date}`],
            ['Supervisor Verifikasi', `${s.supervisor} · ${s.supervisor_date}`],
        ];
        return Drawer.section('Informasi Sesi Stock Opname', Drawer.kv(rows));
    }

    function buildKpiRow(kpi) {
        return UI.el('div', { class: 'hpp-kpi-row' }, [
            kpiTile('🗄️', 'Nilai Stok Sistem', kpi.nilai_stok_sistem, 'var(--accent)'),
            kpiTile('🛒', 'Nilai Final Count', kpi.nilai_final_count, 'var(--green)'),
            kpiTile('⚖️', 'Selisih Nominal', kpi.selisih_nominal, 'var(--red)'),
            kpiTile('📦', 'Dead Stock', kpi.dead_stock, 'var(--orange)'),
            kpiTile('⚠️', 'Rusak', kpi.rusak, '#f97316'),
            kpiTile('🧮', 'Adjustment Bersih', kpi.adjustment_bersih, 'var(--accent2)'),
        ]);
    }

    function buildBottomSummary(bottom) {
        return UI.el('div', { class: 'hpp-kpi-row', style: 'margin-top:16px;' }, [
            miniStat('✅', 'Total SKU cocok', bottom.sku_cocok, 'var(--green)'),
            miniStat('⚠️', 'Perlu review', bottom.perlu_review, 'var(--red)'),
            miniStat('📦', 'Dead stock SKU', `${bottom.dead_stock_sku} SKU`, 'var(--orange)'),
            miniStat('🛠', 'Rusak SKU', `${bottom.rusak_sku} SKU`, '#f97316'),
        ]);
    }

    function buildPerBarangTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(detail.session));
        wrap.appendChild(buildKpiRow(detail.kpi));

        const toolbar = UI.el('div', { style: 'display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;' });
        const searchInput = UI.el('input', { type: 'text', placeholder: 'Cari SKU atau nama barang...', style: 'flex:1; min-width:200px;' });
        const catOptions = Array.from(new Set(detail.items.map((it) => it.category)));
        const catSelect = UI.el('select', { style: 'min-width:180px;' }, [UI.el('option', { value: '' }, 'Semua Kategori')].concat(
            catOptions.map((c) => UI.el('option', { value: c }, c))
        ));
        toolbar.appendChild(searchInput);
        toolbar.appendChild(catSelect);
        wrap.appendChild(toolbar);

        const tableHost = UI.el('div', { class: 'card' });
        wrap.appendChild(tableHost);
        wrap.appendChild(buildBottomSummary(detail.bottom));

        const actionsRow = UI.el('div', { style: 'display:flex; gap:10px; justify-content:flex-end; margin-top:16px; flex-wrap:wrap;' });
        const printBtn = UI.el('button', { class: 'btn btn-secondary' }, '🖨 Cetak Laporan');
        const excelBtn = UI.el('button', { class: 'btn btn-secondary' }, '📊 Export Excel');
        // PRODUCTION PREVIEW — Posting Adjustment is hard-disabled (the
        // `disabled` attribute itself blocks the browser from ever firing a
        // click on it, so there is no code path here that can perform a
        // real posting) and visually marked as preview-only. Never wire
        // this to StockOpnameService or any posting endpoint until a real
        // backend/data-model round explicitly authorizes it.
        const postBtn = UI.el('button', {
            class: 'btn btn-primary',
            disabled: 'disabled',
            title: 'Preview — Posting Adjustment belum tersedia di tahap ini (data simulasi).',
        }, '✅ Posting Adjustment (Preview)');
        printBtn.addEventListener('click', () => UI.toast('Mockup — Cetak Laporan belum terhubung ke data nyata.', 'info'));
        excelBtn.addEventListener('click', () => UI.toast('Mockup — Export Excel belum terhubung ke data nyata.', 'info'));
        actionsRow.appendChild(printBtn);
        actionsRow.appendChild(excelBtn);
        actionsRow.appendChild(postBtn);
        wrap.appendChild(actionsRow);

        function renderTable() {
            tableHost.innerHTML = '';
            const q = searchInput.value.trim().toLowerCase();
            const cat = catSelect.value;
            const rows = detail.items.filter((it) => {
                if (cat && it.category !== cat) return false;
                if (q && !(it.sku.toLowerCase().includes(q) || it.name.toLowerCase().includes(q))) return false;
                return true;
            });

            const table = UI.el('table', { class: 'so-report-table' });
            const thead = UI.el('thead', {}, UI.el('tr', {}, [
                'SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty Sistem', 'Hasil Count 01', 'Petugas 01',
                'Hasil Count 02', 'Petugas 02', 'Final Count', 'Selisih Qty', 'Dead Stock Qty', 'Rusak Qty',
                'HPP (Rp)', 'Nilai Selisih (Rp)', 'Nilai Dead Stock (Rp)', 'Nilai Rusak (Rp)',
            ].map((h) => UI.el('th', {}, h))));
            const tbody = UI.el('tbody', {}, rows.map((it) => UI.el('tr', {}, [
                UI.el('td', {}, it.sku),
                UI.el('td', {}, it.name),
                UI.el('td', {}, it.category),
                UI.el('td', {}, it.unit),
                UI.el('td', { class: 'text-right' }, num(it.qty_sistem)),
                UI.el('td', { class: 'text-right' }, num(it.count1)),
                UI.el('td', {}, it.petugas1),
                UI.el('td', { class: 'text-right' }, num(it.count2)),
                UI.el('td', {}, it.petugas2),
                UI.el('td', { class: 'text-right' }, num(it.final)),
                UI.el('td', { class: 'text-right' }, variancePill(it.selisih_qty, false)),
                UI.el('td', { class: 'text-right' }, qtyCell(it.dead_qty, 'var(--orange)')),
                UI.el('td', { class: 'text-right' }, qtyCell(it.rusak_qty, '#f97316')),
                UI.el('td', { class: 'text-right' }, money(it.hpp)),
                UI.el('td', { class: 'text-right' }, variancePill(it.nilai_selisih, true)),
                UI.el('td', { class: 'text-right' }, it.nilai_dead ? UI.el('span', { style: 'color:var(--orange); font-weight:700;' }, money(it.nilai_dead)) : money(0)),
                UI.el('td', { class: 'text-right' }, it.nilai_rusak ? UI.el('span', { style: 'color:#f97316; font-weight:700;' }, money(it.nilai_rusak)) : money(0)),
            ])));

            const totals = rows.reduce((acc, it) => {
                acc.qty_sistem += it.qty_sistem; acc.count1 += it.count1; acc.count2 += it.count2; acc.final += it.final;
                acc.selisih_qty += it.selisih_qty; acc.dead_qty += it.dead_qty; acc.rusak_qty += it.rusak_qty;
                acc.nilai_selisih += it.nilai_selisih; acc.nilai_dead += it.nilai_dead; acc.nilai_rusak += it.nilai_rusak;
                return acc;
            }, { qty_sistem: 0, count1: 0, count2: 0, final: 0, selisih_qty: 0, dead_qty: 0, rusak_qty: 0, nilai_selisih: 0, nilai_dead: 0, nilai_rusak: 0 });
            const totalRow = UI.el('tr', { class: 'so-report-total-row' }, [
                UI.el('td', { colspan: '4' }, 'TOTAL'),
                UI.el('td', { class: 'text-right' }, num(totals.qty_sistem)),
                UI.el('td', { class: 'text-right' }, num(totals.count1)),
                UI.el('td', {}, ''),
                UI.el('td', { class: 'text-right' }, num(totals.count2)),
                UI.el('td', {}, ''),
                UI.el('td', { class: 'text-right' }, num(totals.final)),
                UI.el('td', { class: 'text-right' }, variancePill(totals.selisih_qty, false)),
                UI.el('td', { class: 'text-right' }, num(totals.dead_qty)),
                UI.el('td', { class: 'text-right' }, num(totals.rusak_qty)),
                UI.el('td', {}, '—'),
                UI.el('td', { class: 'text-right' }, variancePill(totals.nilai_selisih, true)),
                UI.el('td', { class: 'text-right' }, money(totals.nilai_dead)),
                UI.el('td', { class: 'text-right' }, money(totals.nilai_rusak)),
            ]);
            tbody.appendChild(totalRow);

            table.appendChild(thead);
            table.appendChild(tbody);
            tableHost.appendChild(UI.el('div', { class: 'so-report-table-wrap', style: 'max-height:420px; overflow-y:auto;' }, [table]));
        }

        searchInput.addEventListener('input', renderTable);
        catSelect.addEventListener('change', renderTable);
        renderTable();

        return wrap;
    }

    function buildOverviewTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(buildInfoCard(detail.session));
        wrap.appendChild(buildKpiRow(detail.kpi));
        wrap.appendChild(UI.el('div', { class: 'alert alert-info', style: 'margin-top:12px;' },
            'Ringkasan Overview — mockup visual. Data lengkap ada di tab Per Barang.'));
        return wrap;
    }

    function buildRekonsiliasiTab(detail) {
        const wrap = UI.el('div');
        wrap.appendChild(UI.el('div', { class: 'alert alert-info' },
            'Mockup — tab Rekonsiliasi akan menampilkan pencocokan Stok Buku SO vs hasil opname di tahap berikutnya.'));
        wrap.appendChild(buildKpiRow(detail.kpi));
        return wrap;
    }

    function buildAuditTab(detail) {
        const s = detail.session;
        const rows = [
            ['Dibuat', `${s.created_by}`],
            ['Difinalisasi', `${s.finalized_by} · ${s.finalized_at}`],
            ['Diposting', `${s.posted_by} · ${s.posted_at}`],
            ['Supervisor Verifikasi', `${s.supervisor} · ${s.supervisor_date}`],
        ];
        const wrap = UI.el('div');
        wrap.appendChild(Drawer.section('Jejak Audit (mockup)', Drawer.kv(rows)));
        wrap.appendChild(UI.el('div', { class: 'alert alert-info' },
            'Mockup — timeline audit lengkap (per-field before/after, mengikuti pola TraceDrawer) menyusul di tahap berikutnya.'));
        return wrap;
    }

    function open(session) {
        const detail = buildMockDetail(session);
        const s = detail.session;

        const titleNode = UI.el('div', {}, [
            UI.el('div', { style: 'font-size:1.1rem; font-weight:800; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                `Jejak Stock Opname #${deriveSessionDisplayNumber(session)}`,
                // PRODUCTION PREVIEW — reuses the existing .badge/.badge-warning
                // pair (app.css:84/95) as-is; no new badge CSS introduced.
                UI.el('span', { class: 'badge badge-warning', 'data-testid': 'jejak-preview-badge' }, 'PREVIEW UI — DATA SIMULASI'),
            ]),
            UI.el('div', { style: 'font-size:0.78rem; color:var(--text3); font-weight:500; margin-top:2px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;' }, [
                UI.el('span', {}, `${s.number} · ${s.date} · ${s.warehouse_code}`),
                UI.el('span', { class: `badge ${UI.badgeClass(s.status)}` }, s.status),
            ]),
            // PRODUCTION PREVIEW — reuses the existing .hpp-subtitle muted-text
            // style (app.css:776) as-is; no new text-style CSS introduced.
            UI.el('div', { class: 'hpp-subtitle', style: 'margin-top:4px;', 'data-testid': 'jejak-preview-note' },
                'Tampilan ini masih menggunakan data simulasi dan belum terhubung ke data Stock Opname aktual.'),
        ]);

        Drawer.open({
            title: titleNode,
            tabs: [
                { key: 'per-barang', label: 'Per Barang', render: (body) => body.appendChild(buildPerBarangTab(detail)) },
                { key: 'overview', label: 'Overview', render: (body) => body.appendChild(buildOverviewTab(detail)) },
                { key: 'rekonsiliasi', label: 'Rekonsiliasi', render: (body) => body.appendChild(buildRekonsiliasiTab(detail)) },
                { key: 'audit', label: 'Audit', render: (body) => body.appendChild(buildAuditTab(detail)) },
            ],
        });

        // Widen this specific drawer instance beyond the shared 560px
        // default — a modifier class, never a change to .drawer itself
        // (every other screen's drawer stays untouched).
        document.querySelector('.drawer')?.classList.add('drawer-xl');
    }

    return { open };
})();
