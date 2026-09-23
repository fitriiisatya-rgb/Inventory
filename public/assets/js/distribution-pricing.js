/**
 * PHASE V2.11B — Master / Pricing Distribusi: company default, per-category,
 * and per-SKU selling-price policy for SCM -> Bakery distribution (Part 10).
 * Resolution order (never re-implemented here, just displayed): SKU beats
 * Category beats Company default — see PricingPolicyService::resolve().
 *
 * A policy is never hard-deleted, only deactivated — same convention as
 * every other master-data page in this project.
 */
const DistributionPricing = (() => {
    let policies = [];

    async function render(container) {
        container.innerHTML = '<div class="alert alert-info">Memuat kebijakan harga...</div>';
        try {
            policies = await InvApi.listPricingPolicies();
        } catch (err) {
            UI.handleApiError(err);
            container.innerHTML = `<div class="alert alert-error">Gagal memuat: ${(err && err.message) || ''}</div>`;
            return;
        }
        container.innerHTML = '';
        container.appendChild(buildCompanySection());
        container.appendChild(buildCategorySection());
        container.appendChild(buildSkuSection());
    }

    function methodOptionsHtml(selected) {
        return `
            <option value="AT_COST" ${selected === 'AT_COST' ? 'selected' : ''}>AT_COST (harga jual = harga referensi)</option>
            <option value="COST_PLUS_PERCENT" ${selected === 'COST_PLUS_PERCENT' ? 'selected' : ''}>COST_PLUS_PERCENT (+ persen margin)</option>
            <option value="COST_PLUS_AMOUNT" ${selected === 'COST_PLUS_AMOUNT' ? 'selected' : ''}>COST_PLUS_AMOUNT (+ nominal margin)</option>
        `;
    }

    function policyRow(p, onDeactivate) {
        const label = p.scope === 'COMPANY' ? 'Perusahaan' : p.scope === 'CATEGORY' ? (p.category_name || `Kategori #${p.category_id}`) : `${p.item_sku} — ${p.item_name}`;
        return UI.el('tr', {}, [
            UI.el('td', {}, label),
            UI.el('td', {}, p.pricing_method),
            UI.el('td', {}, p.pricing_method === 'COST_PLUS_PERCENT' ? `${UI.formatNumber(p.margin_value)}%` : UI.formatMoney(p.margin_value)),
            UI.el('td', {}, MasterCommon.statusBadge(!!p.is_active)),
            UI.el('td', {}, p.is_active && Auth.hasPermission('DISTRIBUTION_PRICING_MANAGE') ? (() => {
                const btn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, 'Nonaktifkan');
                btn.addEventListener('click', () => onDeactivate(p.id));
                return btn;
            })() : ''),
        ]);
    }

    // ---- A. Default Perusahaan ----
    function buildCompanySection() {
        const canManage = Auth.hasPermission('DISTRIBUTION_PRICING_MANAGE');
        const active = policies.find((p) => p.scope === 'COMPANY' && p.is_active);
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, 'A. Default Perusahaan')]),
        ]);
        const companyRows = policies.filter((p) => p.scope === 'COMPANY');
        card.appendChild(UI.el('div', { class: 'table-wrapper' }, [
            UI.el('table', {}, [
                UI.el('thead', {}, [UI.el('tr', {}, ['Scope', 'Metode', 'Margin', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                UI.el('tbody', {}, companyRows.length ? companyRows.map((p) => policyRow(p, deactivate)) : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada default perusahaan.')])]),
            ]),
        ]));

        if (canManage) {
            const methodSel = UI.el('select', { html: methodOptionsHtml(active ? active.pricing_method : 'COST_PLUS_PERCENT') });
            const marginInput = UI.el('input', { type: 'number', step: 'any', min: '0', value: active ? active.margin_value : '5' });
            const saveBtn = UI.el('button', { class: 'btn btn-primary btn-sm' }, active ? 'Perbarui Default Perusahaan' : 'Set Default Perusahaan');
            saveBtn.addEventListener('click', async () => {
                try {
                    await InvApi.savePricingPolicy({ scope: 'COMPANY', pricing_method: methodSel.value, margin_value: Number(marginInput.value) });
                    UI.toast('Default perusahaan tersimpan.', 'success');
                    render(document.getElementById('tab-distribusi-pricing'));
                } catch (err) { UI.handleApiError(err); }
            });
            card.appendChild(UI.el('div', { class: 'grid-3', style: 'margin-top:10px;' }, [
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Metode'), methodSel]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Margin'), marginInput]),
                UI.el('div', { style: 'align-self:flex-end;' }, [saveBtn]),
            ]));
        }
        return card;
    }

    // ---- B. Per Kategori ----
    function buildCategorySection() {
        const canManage = Auth.hasPermission('DISTRIBUTION_PRICING_MANAGE');
        const categoryRows = policies.filter((p) => p.scope === 'CATEGORY');
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, 'B. Per Kategori')]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['Kategori', 'Metode', 'Margin', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, categoryRows.length ? categoryRows.map((p) => policyRow(p, deactivate)) : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada kebijakan per kategori.')])]),
                ]),
            ]),
        ]);

        if (canManage) {
            const categoryOptions = Master.categories().filter((c) => c.is_active).map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
            const categorySel = UI.el('select', { html: categoryOptions });
            const methodSel = UI.el('select', { html: methodOptionsHtml('COST_PLUS_PERCENT') });
            const marginInput = UI.el('input', { type: 'number', step: 'any', min: '0', value: '5' });
            const saveBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '+ Simpan Kebijakan Kategori');
            saveBtn.addEventListener('click', async () => {
                try {
                    await InvApi.savePricingPolicy({ scope: 'CATEGORY', category_id: Number(categorySel.value), pricing_method: methodSel.value, margin_value: Number(marginInput.value) });
                    UI.toast('Kebijakan kategori tersimpan.', 'success');
                    render(document.getElementById('tab-distribusi-pricing'));
                } catch (err) { UI.handleApiError(err); }
            });
            card.appendChild(UI.el('div', { class: 'grid-3', style: 'margin-top:10px;' }, [
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Kategori'), categorySel]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Metode'), methodSel]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Margin'), marginInput]),
            ]));
            card.appendChild(saveBtn);
        }
        return card;
    }

    // ---- C. Override SKU ----
    function buildSkuSection() {
        const canManage = Auth.hasPermission('DISTRIBUTION_PRICING_MANAGE');
        const skuRows = policies.filter((p) => p.scope === 'SKU');
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, 'C. Override SKU')]),
            UI.el('div', { class: 'table-wrapper' }, [
                UI.el('table', {}, [
                    UI.el('thead', {}, [UI.el('tr', {}, ['SKU', 'Metode', 'Margin', 'Status', 'Aksi'].map((h) => UI.el('th', {}, h)))]),
                    UI.el('tbody', {}, skuRows.length ? skuRows.map((p) => policyRow(p, deactivate)) : [UI.el('tr', {}, [UI.el('td', { colspan: '5' }, 'Belum ada override SKU.')])]),
                ]),
            ]),
        ]);

        if (canManage) {
            const itemSelectorHost = UI.el('div');
            const methodSel = UI.el('select', { html: methodOptionsHtml('COST_PLUS_PERCENT') });
            const marginInput = UI.el('input', { type: 'number', step: 'any', min: '0', value: '5' });
            const selector = ItemSelector.mount(itemSelectorHost, { onChange: () => {} });
            const saveBtn = UI.el('button', { class: 'btn btn-secondary btn-sm' }, '+ Simpan Override SKU');
            saveBtn.addEventListener('click', async () => {
                const state = selector.getState();
                if (!state.valid || !state.itemId) { UI.toast(ItemSelector.MESSAGES.PICK_FROM_RESULTS, 'error'); return; }
                try {
                    await InvApi.savePricingPolicy({ scope: 'SKU', item_id: state.itemId, pricing_method: methodSel.value, margin_value: Number(marginInput.value) });
                    UI.toast('Override SKU tersimpan.', 'success');
                    render(document.getElementById('tab-distribusi-pricing'));
                } catch (err) { UI.handleApiError(err); }
            });
            card.appendChild(UI.el('div', { class: 'grid-3', style: 'margin-top:10px;' }, [
                itemSelectorHost,
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Metode'), methodSel]),
                UI.el('div', { class: 'form-group' }, [UI.el('label', {}, 'Margin'), marginInput]),
            ]));
            card.appendChild(saveBtn);
        }
        return card;
    }

    async function deactivate(policyId) {
        const confirmed = await Modal.confirm({ title: 'Nonaktifkan Kebijakan', message: 'Nonaktifkan kebijakan harga ini?' });
        if (!confirmed) return;
        try {
            await InvApi.deactivatePricingPolicy(policyId);
            UI.toast('Kebijakan dinonaktifkan.', 'success');
            render(document.getElementById('tab-distribusi-pricing'));
        } catch (err) { UI.handleApiError(err); }
    }

    return { render };
})();
