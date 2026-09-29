<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'counter.count');
$sessionId = (int) ($_GET['session_id'] ?? 0);
$pageTitle = 'Input Stok Opname';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <form class="inline" id="filterForm">
    <label>Kategori <select name="category_id" id="categorySelect"><option value="">Semua</option></select></label>
    <label>Status Barang <select name="status"><option value="ACTIVE">Aktif</option><option value="INACTIVE">Tidak Aktif</option><option value="ALL">Semua</option></select></label>
    <label>Status Hitung
      <select name="count_status">
        <option value="SEMUA">Semua</option>
        <option value="BELUM_DIHITUNG">Belum Dihitung</option>
        <option value="SUDAH_DIHITUNG">Sudah Dihitung</option>
        <option value="SEDANG_DIHITUNG">Sedang Dihitung</option>
        <option value="HITUNG_ULANG">Hitung Ulang</option>
      </select>
    </label>
    <label>Cari <input type="text" name="q" placeholder="SKU / barcode / nama"></label>
    <button type="submit">Filter</button>
  </form>
  <div id="progress"></div>
</div>

<div class="card" id="itemListCard">
  <table>
    <thead><tr><th>SKU</th><th>Nama</th><th>Status</th><th></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>

<div class="card" id="inputCard" style="display:none;">
  <h3 id="inputTitle"></h3>
  <div id="inputMsg"></div>
  <div id="levelFields"></div>

  <h4>Kondisi Barang</h4>
  <div id="conditionFields"></div>

  <label>Catatan (opsional)<br><textarea id="noteField" rows="2" style="width:100%;"></textarea></label>
  <div id="reasonWrap" style="display:none;">
    <label>Alasan perubahan (wajib)<br><input type="text" id="reasonField" style="width:100%;"></label>
  </div>
  <div style="margin-top:12px;">
    <button id="saveBtn">Simpan Hitungan</button>
    <button class="secondary" id="cancelBtn">Batal / Tutup</button>
  </div>
</div>

<script>
const sessionId = <?= (int) $sessionId ?>;
let currentItem = null;
let heartbeatTimer = null;

async function loadCategories() {
  const { data } = await SO.api('/api/categories.php');
  const sel = document.getElementById('categorySelect');
  data.forEach(c => sel.insertAdjacentHTML('beforeend', `<option value="${c.id}">${SO.escapeHtml(c.name)}</option>`));
}

function statusLabel(s) {
  return { BELUM_DIHITUNG: 'Belum Dihitung', SEDANG_DIHITUNG: 'Sedang Dihitung', SUDAH_DIHITUNG: 'Sudah Dihitung',
    HITUNG_ULANG: 'Hitung Ulang', NOT_COUNTABLE: 'Tidak Dapat Dihitung' }[s] || s;
}
function statusClass(s) {
  return { BELUM_DIHITUNG: 'inactive', SEDANG_DIHITUNG: 'warning', SUDAH_DIHITUNG: 'active',
    HITUNG_ULANG: 'warning', NOT_COUNTABLE: 'inactive' }[s] || 'inactive';
}

async function loadItems() {
  const fd = new FormData(document.getElementById('filterForm'));
  const params = new URLSearchParams(Object.fromEntries(fd));
  params.set('session_id', sessionId);
  const result = await SO.api('/api/counter/items.php?' + params.toString());
  window.__rows = Object.fromEntries(result.data.map(r => [r.session_item_id, r]));
  document.getElementById('progress').innerHTML = `Team ${result.team}: ${result.progress.team_done} / ${result.progress.team_total} selesai`;
  document.getElementById('rows').innerHTML = result.data.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.sku)}</td>
      <td>${SO.escapeHtml(r.name)}</td>
      <td><span class="badge badge-${statusClass(r.status)}">${statusLabel(r.status)}</span>
          ${r.locked_by_other ? '<br><small>sedang dibuka petugas lain</small>' : ''}</td>
      <td>${r.status !== 'NOT_COUNTABLE' ? `<button onclick="openItem(${r.session_item_id})" ${r.locked_by_other ? 'disabled' : ''}>Buka</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="4"><em>Tidak ada barang sesuai filter.</em></td></tr>';
}

function levelFieldHtml(level) {
  const labels = { buy: 'good_buy_qty', mid: 'good_mid_qty', base: 'good_base_input_qty' };
  return `<label>${SO.escapeHtml(level.unit)} (${level.level})<br>
    <input type="number" step="any" min="0" id="field_${labels[level.level]}" value="0"></label>`;
}

function conditionFieldHtml(cond, label, item) {
  const unitOptions = item.levels.map(l => `<option value="${SO.escapeHtml(l.unit)}">${SO.escapeHtml(l.unit)}</option>`).join('');
  return `<div style="margin-bottom:8px;"><strong>${label}</strong><br>
    <input type="number" step="any" min="0" id="field_${cond}_qty" value="0" style="width:100px;">
    <select id="field_${cond}_unit">${unitOptions}</select></div>`;
}

async function openItem(sessionItemId) {
  try {
    const lockRes = await SO.api('/api/counter/lock.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId }) });
  } catch (err) {
    alert(err.message);
    return;
  }
  currentItem = window.__rows[sessionItemId];
  document.getElementById('inputTitle').textContent = `${currentItem.sku} — ${currentItem.name}`;
  document.getElementById('inputMsg').innerHTML = '';
  document.getElementById('levelFields').innerHTML = currentItem.levels.map(levelFieldHtml).join('');
  document.getElementById('conditionFields').innerHTML =
    conditionFieldHtml('damaged', 'Rusak', currentItem) +
    conditionFieldHtml('expired', 'Expired', currentItem) +
    conditionFieldHtml('deadstock', 'Deadstock', currentItem);

  const editing = currentItem.own_count !== null;
  document.getElementById('reasonWrap').style.display = editing ? 'block' : 'none';
  if (editing) {
    const c = currentItem.own_count;
    if (document.getElementById('field_good_buy_qty')) document.getElementById('field_good_buy_qty').value = c.good_buy_qty;
    if (document.getElementById('field_good_mid_qty')) document.getElementById('field_good_mid_qty').value = c.good_mid_qty;
    if (document.getElementById('field_good_base_input_qty')) document.getElementById('field_good_base_input_qty').value = c.good_base_input_qty;
    document.getElementById('field_damaged_qty').value = c.damaged_qty;
    document.getElementById('field_expired_qty').value = c.expired_qty;
    document.getElementById('field_deadstock_qty').value = c.deadstock_qty;
    document.getElementById('noteField').value = c.note || '';
  }

  document.getElementById('itemListCard').style.display = 'none';
  document.getElementById('inputCard').style.display = 'block';

  heartbeatTimer = setInterval(() => {
    SO.api('/api/counter/heartbeat.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId }) }).catch(() => {});
  }, 90000);
}

async function closeInput(releaseLock) {
  if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
  if (releaseLock && currentItem) {
    await SO.api('/api/counter/lock.php', { method: 'DELETE', body: JSON.stringify({ session_item_id: currentItem.session_item_id }) }).catch(() => {});
  }
  currentItem = null;
  document.getElementById('inputCard').style.display = 'none';
  document.getElementById('itemListCard').style.display = 'block';
  loadItems();
}

document.getElementById('cancelBtn').addEventListener('click', () => closeInput(true));

document.getElementById('saveBtn').addEventListener('click', async () => {
  const val = (id) => document.getElementById(id) ? parseFloat(document.getElementById(id).value || '0') : 0;
  const body = {
    session_item_id: currentItem.session_item_id,
    good_buy_qty: val('field_good_buy_qty'),
    good_mid_qty: val('field_good_mid_qty'),
    good_base_input_qty: val('field_good_base_input_qty'),
    damaged_qty: val('field_damaged_qty'),
    damaged_unit: document.getElementById('field_damaged_unit').value,
    expired_qty: val('field_expired_qty'),
    expired_unit: document.getElementById('field_expired_unit').value,
    deadstock_qty: val('field_deadstock_qty'),
    deadstock_unit: document.getElementById('field_deadstock_unit').value,
    note: document.getElementById('noteField').value,
    reason: document.getElementById('reasonField') ? document.getElementById('reasonField').value : undefined,
  };
  try {
    const result = await SO.api('/api/counter/count.php', { method: 'POST', body: JSON.stringify(body) });
    if (result.data.evidence_required.length) {
      document.getElementById('inputMsg').innerHTML = `<div class="warning-box">Kondisi ${result.data.evidence_required.join(', ')} memerlukan foto bukti (upload foto akan tersedia pada tahap berikutnya).</div>`;
    }
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    currentItem = null;
    setTimeout(() => { document.getElementById('inputCard').style.display = 'none'; document.getElementById('itemListCard').style.display = 'block'; loadItems(); }, result.data.evidence_required.length ? 1500 : 0);
  } catch (err) {
    const issues = (err.data && err.data.issues) || [];
    document.getElementById('inputMsg').innerHTML = `<div class="error-box">${issues.length ? issues.join('<br>') : SO.escapeHtml(err.message)}</div>`;
  }
});

document.getElementById('filterForm').addEventListener('submit', (e) => { e.preventDefault(); loadItems(); });

loadCategories();
loadItems();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
