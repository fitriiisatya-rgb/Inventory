<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'master.manage');
$pageTitle = 'Tools: Import Data Legacy';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <p>Migrasi data dari aplikasi legacy (localStorage) tanpa perlu input ulang manual. Alur: <b>Upload export JSON &rarr; Pilih key Master/Stock &rarr; Backup &rarr; Preview Master &rarr; Import Master &rarr; Preview Stock &rarr; Map Lokasi &rarr; Import Stock</b>.</p>
  <p>Belum punya file export? Buka <a href="/tools/legacy-export.html" target="_blank">tools/legacy-export.html</a> di tab yang sama dengan aplikasi legacy untuk membuatnya (baca instruksinya — ada 3 cara, salah satu pasti bisa dipakai).</p>
</div>

<div class="card">
  <h3>1. Upload Export JSON</h3>
  <input type="file" id="fileInput" accept=".json,application/json">
  <div id="uploadMsg"></div>
  <div id="keyPicker" style="display:none; margin-top:10px;">
    <label>Key Master Barang <select id="masterKeySelect"><option value="">-- pilih --</option></select></label>
    &nbsp;
    <label>Key Stock <select id="stockKeySelect"><option value="">-- pilih --</option></select></label>
  </div>
</div>

<div class="card">
  <h3>2. Backup Database</h3>
  <button id="backupBtn">Backup Sekarang</button>
  <span id="backupStatus"></span>
</div>

<div class="card" id="masterCard" style="display:none;">
  <h3>3. Preview &amp; Import Master Barang</h3>
  <button id="previewMasterBtn">Preview Master</button>
  <div id="masterSummary"></div>
  <div id="categoryPreview"></div>
  <button id="commitMasterBtn" style="display:none;">IMPORT Master (Sudah Saya Cek Preview)</button>
  <div id="masterCommitResult"></div>
  <div style="max-height:400px; overflow:auto; margin-top:10px;">
    <table><thead><tr><th>Row</th><th>SKU</th><th>Nama</th><th>Kategori</th><th>Buy</th><th>Mid</th><th>Base</th><th>Status</th><th>Level</th><th>Catatan</th></tr></thead>
    <tbody id="masterRows"></tbody></table>
  </div>
</div>

<div class="card" id="stockCard" style="display:none;">
  <h3>4. Preview &amp; Import Stock</h3>
  <button id="previewStockBtn">Preview Stock</button>
  <div id="stockSummary"></div>
  <div id="locationMapping"></div>
  <button id="commitStockBtn" style="display:none;">IMPORT Stock (Sudah Saya Petakan Lokasi)</button>
  <div id="stockCommitResult"></div>
  <div style="max-height:400px; overflow:auto; margin-top:10px;">
    <table><thead><tr><th>Row</th><th>SKU</th><th>Lokasi Legacy</th><th>Qty</th><th>Unit</th><th>Cost</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody id="stockRows"></tbody></table>
  </div>
</div>

<script>
let legacyData = null;
let masterRows = null;
let stockRows = null;

document.getElementById('fileInput').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  try {
    const text = await file.text();
    const parsed = JSON.parse(text);
    legacyData = parsed.data && typeof parsed.data === 'object' ? parsed.data : parsed;
    const keys = Object.keys(legacyData).filter(k => Array.isArray(legacyData[k]) && legacyData[k].length > 0 && typeof legacyData[k][0] === 'object');
    if (keys.length === 0) {
      document.getElementById('uploadMsg').innerHTML = '<div class="error-box">Tidak ditemukan key berisi array of object di file ini.</div>';
      return;
    }
    const opts = k => `<option value="${k}">${k} (${legacyData[k].length} baris — contoh field: ${Object.keys(legacyData[k][0]).slice(0,6).join(', ')})</option>`;
    document.getElementById('masterKeySelect').innerHTML = '<option value="">-- pilih --</option>' + keys.map(opts).join('');
    document.getElementById('stockKeySelect').innerHTML = '<option value="">-- pilih --</option>' + keys.map(opts).join('');
    document.getElementById('keyPicker').style.display = 'block';
    document.getElementById('uploadMsg').innerHTML = `<div class="warning-box" style="background:#dcfce7;color:#166534;">File terbaca: ${keys.length} key kandidat ditemukan.</div>`;
  } catch (err) {
    document.getElementById('uploadMsg').innerHTML = `<div class="error-box">Gagal parse file: ${SO.escapeHtml(err.message)}</div>`;
  }
});

document.getElementById('masterKeySelect').addEventListener('change', (e) => {
  document.getElementById('masterCard').style.display = e.target.value ? 'block' : 'none';
});
document.getElementById('stockKeySelect').addEventListener('change', (e) => {
  document.getElementById('stockCard').style.display = e.target.value ? 'block' : 'none';
});

document.getElementById('backupBtn').addEventListener('click', async () => {
  document.getElementById('backupStatus').textContent = ' Menjalankan backup...';
  try {
    const { data } = await SO.api('/api/legacy/backup.php', { method: 'POST' });
    document.getElementById('backupStatus').innerHTML = ` <span class="badge badge-active">OK</span> ${SO.escapeHtml(data.filename)} (${data.method}, ${(data.size_bytes/1024).toFixed(0)} KB)`;
  } catch (err) {
    document.getElementById('backupStatus').innerHTML = ` <span class="badge badge-error">GAGAL</span> ${SO.escapeHtml(err.message)}`;
  }
});

function levelBadge(level) {
  return { VALID: 'active', WARNING: 'warning', INVALID: 'error' }[level] || 'inactive';
}

document.getElementById('previewMasterBtn').addEventListener('click', async () => {
  const key = document.getElementById('masterKeySelect').value;
  if (!key) { alert('Pilih key Master Barang dulu.'); return; }
  try {
    const result = await SO.api('/api/legacy/preview_master.php', { method: 'POST', body: JSON.stringify({ rows: legacyData[key] }) });
    masterRows = legacyData[key];
    const s = result.summary;
    document.getElementById('masterSummary').innerHTML =
      `<b>Total:</b> ${s.total} &nbsp; <span class="badge badge-active">VALID: ${s.valid}</span> &nbsp;
       <span class="badge badge-warning">WARNING: ${s.warning}</span> &nbsp;
       <span class="badge badge-error">INVALID: ${s.invalid}</span> &nbsp;
       Duplicate SKU: ${s.duplicate} &nbsp; Sudah ada di Master: ${s.already_exists}`;
    document.getElementById('categoryPreview').innerHTML = '<b>Kategori:</b> ' +
      result.categories.map(c => `${SO.escapeHtml(c.name)} (${c.count}${c.exists ? ', sudah ada' : ', akan dibuat baru'})`).join(', ');
    document.getElementById('masterRows').innerHTML = result.rows.map(r => `
      <tr>
        <td>${r.row}</td><td>${SO.escapeHtml(r.sku)}</td><td>${SO.escapeHtml(r.name)}</td><td>${SO.escapeHtml(r.category)}</td>
        <td>${SO.escapeHtml(r.buy_unit)} (${r.buy_content})</td>
        <td>${r.mid_unit ? SO.escapeHtml(r.mid_unit) + ' (' + r.mid_content + ')' : '-'}</td>
        <td>${SO.escapeHtml(r.base_unit)}</td><td>${r.status}</td>
        <td><span class="badge badge-${levelBadge(r.level)}">${r.level}</span></td>
        <td><small>${r.issues.map(SO.escapeHtml).join('; ')}</small></td>
      </tr>`).join('');
    document.getElementById('commitMasterBtn').style.display = s.valid + s.warning > s.already_exists ? 'inline-block' : 'none';
  } catch (err) {
    document.getElementById('masterSummary').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

document.getElementById('commitMasterBtn').addEventListener('click', async () => {
  if (!confirm('Import Master Barang dari data legacy sekarang? Item yang SKU-nya sudah ada TIDAK akan ditimpa.')) return;
  try {
    const { data } = await SO.api('/api/legacy/commit_master.php', { method: 'POST', body: JSON.stringify({ rows: masterRows, source_file: 'legacy_master.json' }) });
    document.getElementById('masterCommitResult').innerHTML =
      `<div class="warning-box" style="background:#dcfce7;color:#166534;">Selesai: ${data.created} item baru dibuat, ${data.skipped_existing} dilewati (sudah ada), ${data.skipped_invalid} dilewati (invalid).</div>`;
  } catch (err) {
    document.getElementById('masterCommitResult').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

let currentLocationList = [];
let allLocations = [];

document.getElementById('previewStockBtn').addEventListener('click', async () => {
  const key = document.getElementById('stockKeySelect').value;
  if (!key) { alert('Pilih key Stock dulu.'); return; }
  try {
    const result = await SO.api('/api/legacy/preview_stock.php', { method: 'POST', body: JSON.stringify({ rows: legacyData[key] }) });
    stockRows = legacyData[key];
    const s = result.summary;
    document.getElementById('stockSummary').innerHTML =
      `<b>Total:</b> ${s.total} &nbsp; <span class="badge badge-active">Matched: ${s.matched||0}</span> &nbsp;
       <span class="badge badge-error">Missing Master: ${s.missing_master||0}</span> &nbsp;
       <span class="badge badge-error">Invalid SKU: ${s.invalid_sku||0}</span> &nbsp;
       <span class="badge badge-error">Invalid Qty: ${s.invalid_qty||0}</span> &nbsp;
       <span class="badge badge-warning">Conversion Error: ${s.conversion_error||0}</span>`;

    const locResp = await SO.api('/api/locations.php');
    allLocations = locResp.data;
    currentLocationList = result.locations;
    document.getElementById('locationMapping').innerHTML = '<h4>Peta Lokasi</h4>' + result.locations.map(l => `
      <div>
        <label>${SO.escapeHtml(l.name)} (${l.count} baris) &rarr;
          <select data-legacy-loc="${SO.escapeHtml(l.name)}" class="locMapSelect">
            <option value="">-- lewati lokasi ini --</option>
            ${allLocations.map(nl => `<option value="${nl.id}" ${l.matched_location_id === nl.id ? 'selected' : ''}>${SO.escapeHtml(nl.name)}</option>`).join('')}
          </select>
        </label>
      </div>`).join('');

    document.getElementById('stockRows').innerHTML = result.rows.map(r => `
      <tr>
        <td>${r.row}</td><td>${SO.escapeHtml(r.sku)}</td><td>${SO.escapeHtml(r.location)}</td>
        <td>${r.qty ?? ''}</td><td>${r.unit ? SO.escapeHtml(r.unit) : '-'}</td><td>${r.unit_cost ?? '-'}</td>
        <td><span class="badge badge-${r.status === 'MATCHED' ? 'active' : (r.status === 'CONVERSION_ERROR' ? 'warning' : 'error')}">${r.status}</span></td>
        <td><small>${r.message ? SO.escapeHtml(r.message) : ''}</small></td>
      </tr>`).join('');
    document.getElementById('commitStockBtn').style.display = (s.matched || 0) > 0 ? 'inline-block' : 'none';
  } catch (err) {
    document.getElementById('stockSummary').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

document.getElementById('commitStockBtn').addEventListener('click', async () => {
  const map = {};
  document.querySelectorAll('.locMapSelect').forEach(sel => {
    if (sel.value) map[sel.dataset.legacyLoc] = parseInt(sel.value, 10);
  });
  if (Object.keys(map).length === 0) { alert('Petakan minimal satu lokasi.'); return; }
  if (!confirm('Import Stock dari data legacy sekarang?')) return;
  try {
    const { data } = await SO.api('/api/legacy/commit_stock.php', { method: 'POST', body: JSON.stringify({ rows: stockRows, location_map: map, source_file: 'legacy_stock.json' }) });
    document.getElementById('stockCommitResult').innerHTML =
      `<div class="warning-box" style="background:#dcfce7;color:#166534;">Selesai: ${data.by_location.map(b => `Lokasi #${b.location_id}: ${b.committed} baris committed (batch #${b.batch_id})`).join('; ') || 'tidak ada baris ter-commit'}. ${data.unmapped ? data.unmapped + ' baris dilewati karena lokasi tidak dipetakan.' : ''}</div>`;
  } catch (err) {
    document.getElementById('stockCommitResult').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
