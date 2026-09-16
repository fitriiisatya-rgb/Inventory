/**
 * D11 — Tutup Buku (Book Closing). The UI only ever offers the single
 * next_closeable_period the backend computes — period_start is never a
 * free input once any period has ever been LOCKED, so a user can never
 * jump ahead (e.g. closing October while August is still open). The CLOSE
 * button only becomes active when the backend's own preview says
 * can_close, and every number shown (purchase/usage/shrinkage/ending
 * inventory) is exactly what BookClosingService computed — never
 * recomputed client-side.
 */
const Closing = (() => {
    function render(container) {
        container.innerHTML = '';
        container.appendChild(UI.el('div', { id: 'closing-preview-wrap' }));
        container.appendChild(UI.el('div', { id: 'closing-history-wrap' }));
        loadPreview();
        loadHistory();
    }

    function endOfMonth(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().slice(0, 10);
    }

    async function loadPreview() {
        const wrap = document.getElementById('closing-preview-wrap');
        wrap.innerHTML = '<div class="alert alert-info">Memuat periode yang bisa ditutup...</div>';
        try {
            const next = await InvApi.nextCloseablePeriod();
            const periodStart = next.next_closeable_period_start || (new Date().toISOString().slice(0, 7) + '-01');
            const isFirstEver = !next.next_closeable_period_start;
            const periodEnd = endOfMonth(periodStart);

            const preview = await InvApi.previewClosing(periodStart, periodEnd);
            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, '🔐 Tutup Buku Bulanan')]),
                isFirstEver ? UI.el('div', { class: 'alert alert-info' }, 'Belum ada periode yang pernah ditutup — ini akan menjadi penutupan pertama.') : null,
                UI.el('div', { class: 'grid-2', html: `
                    <div><span class="kpi-label">Periode yang bisa ditutup</span><div class="kpi-value" style="font-size:1.1rem;">${UI.formatDate(preview.period_start).split(',')[0]} s/d ${UI.formatDate(preview.period_end).split(',')[0]}</div></div>
                ` }),
                UI.el('div', { class: 'grid-4', style: 'margin-top:12px;' }, [
                    kpi('Pembelian (Purchase)', UI.formatMoney(preview.purchase_total)),
                    kpi('Pemakaian (Usage)', UI.formatMoney(preview.usage_total)),
                    kpi('Penyusutan (Shrinkage)', UI.formatMoney(preview.shrinkage_total)),
                    kpi('Nilai Stok Akhir', UI.formatMoney(preview.ending_inventory_value)),
                    kpi('Nilai Barang In-Transit', UI.formatMoney(preview.in_transit_value)),
                    kpi('Total Nilai Perusahaan', UI.formatMoney(preview.total_company_value)),
                ]),
                UI.el('div', { style: 'margin-top:14px;' }, [
                    UI.el('div', { class: `alert ${preview.can_close ? 'alert-success' : 'alert-error'}` },
                        preview.can_close ? '✅ Siap untuk ditutup (READY)' : '⛔ Belum bisa ditutup:'),
                    preview.can_close ? null : UI.el('ul', { style: 'margin:6px 0 0 20px; font-size:0.85rem; color: var(--text2);' },
                        preview.blockers.map((b) => UI.el('li', {}, b))),
                ]),
                UI.el('button', {
                    class: 'btn btn-warning', id: 'closing-close-btn', style: 'margin-top:14px;',
                    ...(preview.can_close ? {} : { disabled: 'disabled' }),
                }, '🔐 Tutup Buku Periode Ini'),
                UI.el('div', { id: 'closing-action-alert' }),
            ]));
            if (preview.can_close) {
                document.getElementById('closing-close-btn').addEventListener('click', () => closePeriod(preview.period_start, preview.period_end));
            }
        } catch (err) {
            UI.handleApiError(err);
            wrap.innerHTML = `<div class="alert alert-error">Gagal memuat preview tutup buku: ${(err && err.message) || ''}</div>`;
        }
    }

    function kpi(label, value) {
        return UI.el('div', { class: 'kpi-card' }, [
            UI.el('div', { class: 'kpi-label' }, label),
            UI.el('div', { class: 'kpi-value', style: 'font-size:1.1rem;' }, value),
        ]);
    }

    async function closePeriod(periodStart, periodEnd) {
        if (!confirm(`Tutup buku periode ${periodStart} s/d ${periodEnd}? Tindakan ini akan mengunci periode tersebut.`)) return;
        const btn = document.getElementById('closing-close-btn');
        const alertBox = document.getElementById('closing-action-alert');
        alertBox.innerHTML = '';
        btn.disabled = true;
        try {
            await InvApi.closePeriod(periodStart, periodEnd);
            UI.toast('Periode berhasil ditutup.', 'success');
            loadPreview();
            loadHistory();
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal menutup buku.'));
            btn.disabled = false;
        }
    }

    async function loadHistory() {
        const wrap = document.getElementById('closing-history-wrap');
        wrap.innerHTML = '<div class="alert alert-info">Memuat riwayat tutup buku...</div>';
        try {
            const closings = await InvApi.listClosings();
            const rows = closings.map((c) => UI.el('tr', {}, [
                UI.el('td', {}, `${c.period_start} s/d ${c.period_end}`),
                UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(c.status)}` }, c.status)),
                UI.el('td', {}, UI.formatMoney(c.total_closing_value)),
                UI.el('td', {}, UI.formatMoney(c.purchase_total)),
                UI.el('td', {}, UI.formatMoney(c.usage_total)),
                UI.el('td', {}, UI.formatMoney(c.shrinkage_total)),
            ]));
            wrap.innerHTML = '';
            wrap.appendChild(UI.el('div', { class: 'card' }, [
                UI.el('div', { class: 'card-title' }, 'Riwayat Tutup Buku'),
                UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['Periode', 'Status', 'Nilai Stok Akhir', 'Pembelian', 'Pemakaian', 'Penyusutan'].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, rows.length ? rows : [UI.el('tr', {}, [UI.el('td', { colspan: '6' }, 'Belum ada periode yang ditutup')])]),
                    ]),
                ]),
            ]));
        } catch (err) {
            UI.handleApiError(err);
            wrap.innerHTML = `<div class="alert alert-error">Gagal memuat riwayat: ${(err && err.message) || ''}</div>`;
        }
    }

    return { render };
})();
