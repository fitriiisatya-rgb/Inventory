/**
 * D12 — Import module. Flow for every type: choose file -> Upload ->
 * Staging (validated server-side) -> Preview (VALID/WARNING/ERROR rows) ->
 * Commit (blocked while any row is ERROR). Historical Transaction always
 * carries a permanent, un-removable notice that it never affects stock
 * (inventory_effect=0) — there is no control anywhere in this file that
 * could flip that. Opening Stock always affects stock (inventory_effect=1)
 * and needs no such toggle either, for the same reason: neither is a user
 * choice, so neither gets a checkbox.
 */
const Imports = (() => {
    function render(container) {
        container.innerHTML = '';
        container.appendChild(buildBatchSection({
            key: 'master-item', title: '📦 Import Master Barang',
            stage: (path, name) => InvApi.stageMasterItem(path, name),
            commit: (id) => InvApi.commitMasterItem(id),
            preview: (id) => InvApi.previewImportBatch(id),
        }));
        ['SUPPLIER', 'DIVISION', 'WAREHOUSE'].forEach((type) => {
            const label = { SUPPLIER: 'Supplier', DIVISION: 'Divisi', WAREHOUSE: 'Gudang' }[type];
            container.appendChild(buildBatchSection({
                key: `simple-${type.toLowerCase()}`, title: `🗂️ Import Master ${label}`,
                stage: (path, name) => InvApi.stageSimpleMaster(type, path, name),
                commit: (id) => InvApi.commitSimpleMaster(type, id),
                preview: (id) => InvApi.previewImportBatch(id),
            }));
        });
        container.appendChild(buildBatchSection({
            key: 'opening-stock', title: '🗃️ Import Stok Awal (Opening Stock)',
            stage: (path, name) => InvApi.stageOpeningStock(path, name).then((r) => ({ import_batch_id: r.stock_opening_id })),
            commit: (id) => InvApi.commitOpeningStock(id),
            preview: (id) => InvApi.previewOpeningStockBatch(id).then((r) => ({ batch: r.opening, rows: r.lines.map((l) => ({ row_no: l.id, row_status: 'VALID', raw_data: JSON.stringify({ sku: l.sku, name: l.name, qty: l.qty_base, cost: l.unit_cost_base }) })) })),
            note: UI.el('div', { class: 'alert alert-info' }, 'Stok Awal SELALU mempengaruhi saldo stok (inventory_effect=1) — setiap baris akan membuat batch FIFO nyata.'),
        }));
        container.appendChild(buildBatchSection({
            key: 'historical', title: '📜 Import Transaksi Historis',
            stage: (path, name) => InvApi.stageHistorical(path, name),
            commit: (id) => InvApi.commitHistorical(id),
            preview: (id) => InvApi.previewImportBatch(id),
            note: UI.el('div', { class: 'banner-historical' }, '⚠️ HISTORICAL IMPORT — tidak mempengaruhi saldo stok (inventory_effect=0). Data ini hanya untuk laporan/audit, TIDAK membuat batch FIFO dan TIDAK bisa diubah menjadi mempengaruhi stok.'),
        }));
    }

    function buildBatchSection(cfg) {
        const alertId = `import-${cfg.key}-alert`;
        const previewId = `import-${cfg.key}-preview`;
        const card = UI.el('div', { class: 'card' }, [
            UI.el('div', { class: 'card-header' }, [UI.el('div', { class: 'card-title' }, cfg.title)]),
            cfg.note || null,
            UI.el('div', { id: alertId }),
            UI.el('div', { class: 'grid-2', html: `
                <div class="form-group"><label>File (.csv atau .xlsx)</label><input type="file" id="import-${cfg.key}-file" accept=".csv,.xlsx"></div>
                <div class="form-group" style="display:flex; align-items:flex-end;"><button class="btn btn-primary" id="import-${cfg.key}-upload-btn">Upload &amp; Validasi</button></div>
            ` }),
            UI.el('div', { id: previewId }),
        ]);
        setTimeout(() => {
            document.getElementById(`import-${cfg.key}-upload-btn`).addEventListener('click', () => uploadAndStage(cfg));
        }, 0);
        return card;
    }

    async function uploadAndStage(cfg) {
        const fileInput = document.getElementById(`import-${cfg.key}-file`);
        const alertBox = document.getElementById(`import-${cfg.key}-alert`);
        const btn = document.getElementById(`import-${cfg.key}-upload-btn`);
        alertBox.innerHTML = '';
        if (!fileInput.files.length) {
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, 'Pilih file terlebih dahulu.'));
            return;
        }
        btn.disabled = true;
        try {
            const uploaded = await InvApi.uploadImportFile(fileInput.files[0]);
            const staged = await cfg.stage(uploaded.file_path, uploaded.file_name);
            const batchId = staged.import_batch_id;
            await showPreview(cfg, batchId);
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal upload/validasi file.'));
        } finally {
            btn.disabled = false;
        }
    }

    async function showPreview(cfg, batchId) {
        const previewBox = document.getElementById(`import-${cfg.key}-preview`);
        previewBox.innerHTML = '<div class="alert alert-info">Memuat preview...</div>';
        try {
            const data = await cfg.preview(batchId);
            const batch = data.batch || {};
            const rows = data.rows || [];
            const errorCount = rows.filter((r) => r.row_status === 'ERROR').length;
            const warningCount = rows.filter((r) => r.row_status === 'WARNING').length;
            const validCount = rows.filter((r) => r.row_status === 'VALID').length;

            const rowEls = rows.slice(0, 50).map((r) => UI.el('tr', {}, [
                UI.el('td', {}, String(r.row_no)),
                UI.el('td', {}, UI.el('span', { class: `badge ${UI.badgeClass(r.row_status)}` }, r.row_status)),
                UI.el('td', { class: 'mono', style: 'font-size:0.75rem;' }, r.raw_data),
                UI.el('td', { style: 'font-size:0.75rem; color:var(--red);' }, r.messages ? formatMessages(r.messages) : ''),
            ]));

            previewBox.innerHTML = '';
            previewBox.appendChild(UI.el('div', {}, [
                UI.el('div', { class: 'grid-3', style: 'margin:10px 0;' }, [
                    UI.el('span', { class: 'badge badge-pass' }, `${validCount} VALID`),
                    UI.el('span', { class: 'badge badge-warning' }, `${warningCount} WARNING`),
                    UI.el('span', { class: 'badge badge-error' }, `${errorCount} ERROR`),
                ]),
                UI.el('div', { class: 'table-wrapper' }, [
                    UI.el('table', {}, [
                        UI.el('thead', {}, [UI.el('tr', {}, ['#', 'Status', 'Data', 'Pesan'].map((h) => UI.el('th', {}, h)))]),
                        UI.el('tbody', {}, rowEls.length ? rowEls : [UI.el('tr', {}, [UI.el('td', { colspan: '4' }, 'Tidak ada baris')])]),
                    ]),
                ]),
                rows.length > 50 ? UI.el('div', { style: 'font-size:0.75rem; color:var(--text3); margin-top:4px;' }, `Menampilkan 50 dari ${rows.length} baris.`) : null,
                UI.el('button', {
                    class: 'btn btn-success', id: `import-${cfg.key}-commit-btn`, style: 'margin-top:12px;',
                    ...(errorCount > 0 ? { disabled: 'disabled' } : {}),
                }, errorCount > 0 ? 'Perbaiki data ERROR sebelum commit' : `Commit ${validCount} Baris`),
                UI.el('div', { id: `import-${cfg.key}-commit-alert` }),
            ]));
            if (errorCount === 0) {
                document.getElementById(`import-${cfg.key}-commit-btn`).addEventListener('click', () => commitBatch(cfg, batchId));
            }
        } catch (err) {
            UI.handleApiError(err);
            previewBox.innerHTML = `<div class="alert alert-error">Gagal memuat preview: ${(err && err.message) || ''}</div>`;
        }
    }

    function formatMessages(raw) {
        try {
            const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return Array.isArray(parsed) ? parsed.join('; ') : String(parsed);
        } catch (e) {
            return String(raw);
        }
    }

    async function commitBatch(cfg, batchId) {
        const btn = document.getElementById(`import-${cfg.key}-commit-btn`);
        const alertBox = document.getElementById(`import-${cfg.key}-commit-alert`);
        alertBox.innerHTML = '';
        btn.disabled = true;
        try {
            const result = await cfg.commit(batchId);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-success' }, `Berhasil commit: ${result.imported ?? '-'} baris.`));
        } catch (err) {
            UI.handleApiError(err);
            alertBox.appendChild(UI.el('div', { class: 'alert alert-error' }, (err && err.message) || 'Gagal commit import.'));
            btn.disabled = false;
        }
    }

    return { render };
})();
