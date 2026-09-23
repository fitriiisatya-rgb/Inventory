/**
 * PHASE V2.11B — Invoices generated from a dispatched Delivery Order.
 * create() already resolves and persists every line's pricing snapshot
 * (Part 32's "internal Admin pricing preview" IS the DRAFT invoice
 * itself) — this module never computes a price client-side, only displays
 * what the server actually stored.
 */
const DistributionInvoices = (() => {
    let dtHandle = null;

    function canManage() { return Auth.hasPermission('DISTRIBUTION_PRICING_MANAGE'); }

    async function render(container) {
        container.innerHTML = '';
        const createHost = UI.el('div');
        container.appendChild(createHost);
        const listHost = UI.el('div');
        container.appendChild(listHost);

        if (Auth.hasPermission('DISTRIBUTION_CREATE')) {
            createHost.appendChild(await buildCreateCard());
        }
        renderList(listHost);
    }

    async function buildCreateCard() {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🧾 Buat Invoice dari Delivery Order' )]),
        ]);
        let eligibleDos = [];
        try {
            const [dispatched, received, discrepancy, completed] = await Promise.all([
                InvApi.listDistributionOrders({ status: 'DISPATCHED' }),
                InvApi.listDistributionOrders({ status: 'RECEIVED' }),
                InvApi.listDistributionOrders({ status: 'RECEIVED_WITH_DISCREPANCY' }),
                InvApi.listDistributionOrders({ status: 'COMPLETED' }),
            ]);
            eligibleDos = [...dispatched, ...received, ...discrepancy, ...completed];
        } catch (err) { UI.handleApiError(err); }

        const doOptions = eligibleDos.map((d) => `<option value="${d.id}">${d.do_number} — ${d.bakery_name} (${d.status})</option>`).join('');
        const doSel = UI.el('select', { html: `<option value="">- pilih Delivery Order -</option>${doOptions}` });
        const dateInput = UI.el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
        const createBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, 'Buat Invoice');
        const alertBox = UI.el('div');
        createBtn.addEventListener('click', async () => {
            if (!doSel.value) { UI.toast('Pilih Delivery Order terlebih dahulu.', 'error'); return; }
            alertBox.innerHTML = '';
            try {
                const result = await InvApi.createDistributionInvoice({ do_id: Number(doSel.value), invoice_date: dateInput.value });
                UI.toast(`Invoice ${result.invoice_number} berhasil dibuat (Draft).`, 'success');
                if (dtHandle) dtHandle.reload();
                openDetail(result.invoice_id);
            } catch (err) {
                alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal membuat invoice.'));
            }
        });
        card.appendChild(UI.el('div', { class: 'grid-3' }, [
            UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Delivery Order (sudah Dispatch)'), doSel]),
            UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Tanggal Invoice'), dateInput]),
            UI.el('div', { style: 'align-self:flex-end;' }, [createBtn]),
        ]));
        card.appendChild(alertBox);
        return card;
    }

    function renderList(container) {
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '📋 Daftar Invoice')]),
        ]);
        const tableHost = UI.el('div');
        card.appendChild(tableHost);
        container.appendChild(card);

        dtHandle = DataTable.render(tableHost, {
            storageKey: 'dt-distribution-invoices',
            filters: [
                { key: 'status', label: 'Status', type: 'select', placeholder: 'Semua Status', options: [
                    { value: 'DRAFT', label: 'DRAFT' }, { value: 'ISSUED', label: 'ISSUED' }, { value: 'CANCELLED', label: 'CANCELLED' },
                ] },
            ],
            defaultSort: 'invoice_date', defaultDir: 'desc', pageSize: 25,
            columns: [
                { key: 'invoice_number', label: 'No. Invoice', render: (r) => r.invoice_number },
                { key: 'do_number', label: 'No. DO', render: (r) => r.do_number },
                { key: 'bakery_name', label: 'Bakery', render: (r) => r.bakery_name },
                { key: 'grand_total', label: 'Grand Total', render: (r) => UI.formatMoney(r.grand_total) },
                { key: 'status', label: 'Status', render: (r) => UI.el('span', { class: `badge ${UI.badgeClass(r.status)}` }, r.status) },
                { key: 'invoice_date', label: 'Tanggal', render: (r) => r.invoice_date },
            ],
            fetchPage: async ({ filters: f }) => {
                const rows = await InvApi.listDistributionInvoices(f);
                return { rows, pagination: { page: 1, total_pages: 1, total: rows.length } };
            },
            onRowClick: (row) => openDetail(row.id),
            emptyMessage: 'Belum ada Invoice.',
        });
    }

    async function openDetail(invoiceId) {
        let detail;
        try {
            detail = await InvApi.getDistributionInvoice(invoiceId);
        } catch (err) { UI.handleApiError(err); return; }

        Drawer.open({
            title: `${detail.invoice_number} — ${detail.status}`,
            render: (body) => {
                body.appendChild(Drawer.section('Informasi', Drawer.kv([
                    ['No. DO', detail.do_number],
                    ['Bakery', detail.bakery_name],
                    ['Tanggal', detail.invoice_date],
                    ['Status', UI.el('span', { class: `badge ${UI.badgeClass(detail.status)}` }, detail.status)],
                    ['Subtotal', UI.formatMoney(detail.subtotal)],
                    ['Diskon', UI.formatMoney(detail.discount_amount)],
                    ['Pajak', UI.formatMoney(detail.tax_amount)],
                    ['Ongkos Kirim', UI.formatMoney(detail.shipping_amount)],
                    ['Grand Total', UI.el('b', {}, UI.formatMoney(detail.grand_total))],
                ])));

                const canOverride = canManage() && detail.status === 'DRAFT';
                const rows = detail.lines.map((l) => {
                    const cells = [
                        UI.el('td', {}, l.sku_snapshot),
                        UI.el('td', {}, l.item_name_snapshot),
                        UI.el('td', {}, `${UI.formatNumber(l.qty)} ${l.unit_code}`),
                        UI.el('td', {}, UI.formatMoney(l.reference_purchase_price)),
                        UI.el('td', {}, `${l.pricing_source} / ${l.pricing_method}`),
                        UI.el('td', {}, UI.formatMoney(l.selling_unit_price) + (Number(l.is_price_overridden) ? ' (override)' : '')),
                        UI.el('td', {}, UI.formatMoney(l.subtotal)),
                    ];
                    if (canOverride) {
                        const overrideBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Override');
                        overrideBtn.addEventListener('click', () => overrideLine(detail.id, l));
                        cells.push(UI.el('td', {}, [overrideBtn]));
                    }
                    return UI.el('tr', {}, cells);
                });
                const headers = ['SKU', 'Nama', 'Qty', 'Harga Referensi', 'Sumber/Metode', 'Harga Jual', 'Subtotal'];
                if (canOverride) headers.push('Aksi');
                body.appendChild(Drawer.section('Barang (Pricing Preview)', UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, headers.map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: String(headers.length) }, '-')])]),
                    ]),
                ])));

                body.appendChild(Drawer.section('Aksi', buildActionPanel(detail)));
            },
        });
    }

    function buildActionPanel(detail) {
        const wrap = UI.el('div', { style: 'display:flex; gap:10px;' });
        if (detail.status === 'DRAFT' && Auth.hasPermission('DISTRIBUTION_CREATE')) {
            const issueBtn = UI.el('button', { class: 'btn btn-primary' }, 'Issue Invoice');
            issueBtn.addEventListener('click', async () => {
                try {
                    await InvApi.issueDistributionInvoice(detail.id);
                    UI.toast('Invoice berhasil di-issue.', 'success');
                    openDetail(detail.id);
                    if (dtHandle) dtHandle.reload();
                } catch (err) { UI.handleApiError(err); }
            });
            wrap.appendChild(issueBtn);
        }
        if (detail.status !== 'CANCELLED' && Auth.hasPermission('DISTRIBUTION_CREATE')) {
            const cancelBtn = UI.el('button', { class: 'btn btn-danger' }, 'Batalkan Invoice');
            cancelBtn.addEventListener('click', async () => {
                const reason = await Modal.prompt({ title: 'Batalkan Invoice', label: 'Alasan pembatalan (min. 5 karakter)', required: true });
                if (reason === null) return;
                try {
                    await InvApi.cancelDistributionInvoice(detail.id, { reason });
                    UI.toast('Invoice dibatalkan.', 'success');
                    openDetail(detail.id);
                    if (dtHandle) dtHandle.reload();
                } catch (err) { UI.handleApiError(err); }
            });
            wrap.appendChild(cancelBtn);
        }
        if (wrap.children.length === 0) {
            wrap.appendChild(UI.el('div', { style: 'color:var(--text3); font-size:0.85rem;' }, 'Tidak ada aksi tersedia.'));
        }
        return wrap;
    }

    async function overrideLine(invoiceId, line) {
        const values = await MasterCommon.formModal({
            title: `Override Harga — ${line.sku_snapshot}`,
            submitLabel: 'Simpan',
            initial: { selling_unit_price: line.selling_unit_price, override_reason: '' },
            fields: [
                { key: 'selling_unit_price', label: 'Harga Jual Baru', type: 'text', required: true },
                { key: 'override_reason', label: 'Alasan Override', type: 'text', required: true },
            ],
        });
        if (!values) return;
        try {
            await InvApi.overrideDistributionInvoiceLinePrice(invoiceId, line.id, {
                selling_unit_price: Number(values.selling_unit_price), override_reason: values.override_reason,
            });
            UI.toast('Harga baris invoice berhasil diubah.', 'success');
            openDetail(invoiceId);
        } catch (err) { UI.handleApiError(err); }
    }

    return { render };
})();
