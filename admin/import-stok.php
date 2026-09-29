<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'stock_import.manage');
$pageTitle = 'Import Stok Sistem';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <p>Format CSV yang didukung (header wajib pada baris pertama):</p>
  <ul>
    <li><code>sku,system_qty_base,unit_cost</code> — qty sudah dalam base unit item.</li>
    <li><code>sku,qty,unit,unit_cost</code> — qty dikonversi otomatis mengikuti Master Barang (unit harus persis buy/mid/base unit item tsb).</li>
  </ul>
  <form class="inline" id="uploadForm" enctype="multipart/form-data">
    <label>Lokasi <select name="location_id" id="locationSelect" required></select></label>
    <label>File CSV <input type="file" name="file" accept=".csv,text/csv" required></label>
    <button type="submit">Preview Import</button>
  </form>
  <div id="msg"></div>
</div>

<div class="card" id="previewCard" style="display:none;">
  <h3>Preview Batch #<span id="batchId"></span></h3>
  <div id="summary"></div>
  <table>
    <thead><tr><th>#</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Cost</th><th>Status</th><th>Pesan</th></tr></thead>
    <tbody id="previewRows"></tbody>
  </table>
  <button id="commitBtn">Commit Import</button>
</div>

<script>
async function loadLocations() {
  const { data } = await SO.api('/api/locations.php');
  document.getElementById('locationSelect').innerHTML = data.map(l => `<option value="${l.id}">${SO.escapeHtml(l.name)}</option>`).join('');
}

let currentBatchId = null;

document.getElementById('uploadForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('msg');
  msg.innerHTML = '';
  const fd = new FormData(e.target);
  try {
    const result = await SO.api('/api/stock_import/preview.php', { method: 'POST', body: fd });
    currentBatchId = result.batch_id;
    await renderPreview(result.batch_id);
  } catch (err) {
    msg.innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

async function renderPreview(batchId) {
  const { batch, rows } = await SO.api('/api/stock_import/rows.php?batch_id=' + batchId);
  document.getElementById('previewCard').style.display = 'block';
  document.getElementById('batchId').textContent = batchId;
  document.getElementById('summary').innerHTML = `
    Total: ${batch.total_rows} | Matched: ${batch.matched_count} |
    Warning: ${batch.warning_count} | Invalid: ${batch.invalid_count} | Duplicate: ${batch.duplicate_count} |
    Status batch: <span class="badge badge-${batch.status.toLowerCase()}">${batch.status}</span>`;
  document.getElementById('previewRows').innerHTML = rows.map(r => `
    <tr>
      <td>${r.row_no}</td>
      <td>${SO.escapeHtml(r.raw_sku)}</td>
      <td>${SO.escapeHtml(r.parsed_qty_base ?? r.raw_qty)}</td>
      <td>${SO.escapeHtml(r.raw_unit ?? '(base)')}</td>
      <td>${SO.escapeHtml(r.parsed_unit_cost ?? '-')}</td>
      <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
      <td>${SO.escapeHtml(r.message ?? '')}</td>
    </tr>`).join('');
  document.getElementById('commitBtn').style.display = batch.status === 'PREVIEWED' ? 'inline-block' : 'none';
}

document.getElementById('commitBtn').addEventListener('click', async () => {
  if (!currentBatchId) return;
  if (!confirm('Commit import ini? system_qty akan diperbarui untuk semua baris MATCHED/WARNING.')) return;
  try {
    await SO.api('/api/stock_import/commit.php', { method: 'POST', body: JSON.stringify({ batch_id: currentBatchId }) });
    await renderPreview(currentBatchId);
  } catch (err) {
    document.getElementById('msg').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

loadLocations();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
