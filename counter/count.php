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
        <option value="EVIDENCE_REQUIRED">Belum Lengkap (Foto)</option>
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
  <div id="conversionSummary" style="color:#64748b; margin-bottom:10px;"></div>
  <div id="inputMsg"></div>
  <h4>Stok Baik</h4>
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

<div class="card" id="previewModal" style="display:none;">
  <button class="secondary" onclick="document.getElementById('previewModal').style.display='none';">Tutup</button>
  <div style="text-align:center; margin-top:10px;"><img id="previewImg" style="max-width:100%; max-height:70vh;"></div>
</div>

<script>
const sessionId = <?= (int) $sessionId ?>;
let currentItem = null;
let currentCountId = null;
let heartbeatTimer = null;

async function loadCategories() {
  const { data } = await SO.api('/api/categories.php');
  const sel = document.getElementById('categorySelect');
  data.forEach(c => sel.insertAdjacentHTML('beforeend', `<option value="${c.id}">${SO.escapeHtml(c.name)}</option>`));
}

function statusLabel(s) {
  return { BELUM_DIHITUNG: 'Belum Dihitung', SEDANG_DIHITUNG: 'Sedang Dihitung', SUDAH_DIHITUNG: 'Sudah Dihitung',
    EVIDENCE_REQUIRED: 'Belum Lengkap — Foto Wajib',
    HITUNG_ULANG: 'Hitung Ulang', NOT_COUNTABLE: 'Tidak Dapat Dihitung' }[s] || s;
}
function statusClass(s) {
  return { BELUM_DIHITUNG: 'inactive', SEDANG_DIHITUNG: 'warning', SUDAH_DIHITUNG: 'active',
    EVIDENCE_REQUIRED: 'warning',
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
  return `<label>${SO.escapeHtml(level.unit)}<br>
    <input type="number" step="any" min="0" inputmode="decimal" id="field_${labels[level.level]}" value="0" style="font-size:18px; width:90px;"></label>`;
}

function conversionSummaryText(item) {
  if (item.levels.length === 3) {
    return `1 ${item.buy_unit} = ${item.mid_content} ${item.mid_unit} = ${item.buy_content} ${item.base_unit}`;
  }
  if (item.levels.length === 2) {
    return `1 ${item.buy_unit} = ${item.buy_content} ${item.base_unit}`;
  }
  return `Satuan dasar: ${item.base_unit}`;
}

function conditionFieldHtml(cond, label, item) {
  const unitOptions = item.levels.map(l => `<option value="${SO.escapeHtml(l.unit)}">${SO.escapeHtml(l.unit)}</option>`).join('');
  return `<div style="margin-bottom:14px; border:1px solid #e2e8f0; border-radius:8px; padding:10px;" id="conditionBlock_${cond}">
    <strong>${label}</strong><br>
    <input type="number" step="any" min="0" inputmode="decimal" id="field_${cond}_qty" value="0" style="width:100px; font-size:16px;" onchange="onConditionQtyChange('${cond}')">
    <select id="field_${cond}_unit">${unitOptions}</select>
    <div id="photoZone_${cond}" style="margin-top:8px; display:none;">
      <input type="file" accept="image/*" capture="environment" id="photoInput_${cond}" style="display:none;" onchange="handlePhotoSelect('${cond}')">
      <button type="button" class="secondary" onclick="document.getElementById('photoInput_${cond}').click();">📷 Tambah Foto</button>
      <span id="photoUploadStatus_${cond}"></span>
      <div id="thumbs_${cond}" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;"></div>
    </div>
  </div>`;
}

function onConditionQtyChange(cond) {
  const qty = parseFloat(document.getElementById(`field_${cond}_qty`).value || '0');
  document.getElementById(`photoZone_${cond}`).style.display = qty > 0 ? 'block' : 'none';
}

async function openItem(sessionItemId) {
  try {
    await SO.api('/api/counter/lock.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId }) });
  } catch (err) {
    alert(err.message);
    return;
  }
  currentItem = window.__rows[sessionItemId];
  currentCountId = currentItem.own_count ? currentItem.own_count.count_id : null;
  document.getElementById('inputTitle').textContent = `${currentItem.sku} — ${currentItem.name}`;
  document.getElementById('conversionSummary').textContent = conversionSummaryText(currentItem);
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
    ['damaged', 'expired', 'deadstock'].forEach(onConditionQtyChange);
    if (c.evidence_status === 'EVIDENCE_REQUIRED' && currentCountId) {
      showEvidenceRequiredBanner();
      refreshAllThumbs();
    }
  }

  document.getElementById('itemListCard').style.display = 'none';
  document.getElementById('inputCard').style.display = 'block';

  heartbeatTimer = setInterval(() => {
    SO.api('/api/counter/heartbeat.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId } ) }).catch(() => {});
  }, 90000);
}

function showEvidenceRequiredBanner() {
  document.getElementById('inputMsg').innerHTML = '<div class="warning-box">Belum Lengkap — Foto Wajib untuk kondisi dengan qty &gt; 0. Hitungan ini belum dianggap selesai sampai foto diupload.</div>';
}

async function refreshAllThumbs() {
  if (!currentCountId) return;
  for (const cond of ['damaged', 'expired', 'deadstock']) {
    await refreshThumbs(cond);
  }
}

async function refreshThumbs(cond) {
  if (!currentCountId) return;
  const { data } = await SO.api('/api/photos/list.php?count_id=' + currentCountId);
  const mine = data.filter(p => p.condition_type === cond.toUpperCase() && p.status === 'ACTIVE');
  document.getElementById(`thumbs_${cond}`).innerHTML = mine.map(p => `
    <div style="position:relative;">
      <img src="${p.url}" style="width:70px;height:70px;object-fit:cover;border-radius:6px;cursor:pointer;" onclick="previewPhoto('${p.url}')">
      <button type="button" onclick="removePhoto(${p.id}, '${cond}')" style="position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; border:none; border-radius:50%; width:20px; height:20px; font-size:12px; line-height:1; cursor:pointer;">×</button>
    </div>`).join('');
}

function previewPhoto(url) {
  document.getElementById('previewImg').src = url;
  document.getElementById('previewModal').style.display = 'block';
}

async function removePhoto(photoId, cond) {
  if (!confirm('Hapus foto ini?')) return;
  try {
    await SO.api('/api/counter/photos/delete.php', { method: 'POST', body: JSON.stringify({ photo_id: photoId }) });
    refreshThumbs(cond);
  } catch (err) {
    alert(err.message);
  }
}

// Client-side compression before upload (design review point 17): resize
// to a max dimension and re-encode as JPEG. The server never trusts this
// and re-validates/re-encodes everything itself regardless.
function compressImage(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const reader = new FileReader();
    reader.onload = (e) => { img.src = e.target.result; };
    reader.onerror = reject;
    img.onload = () => {
      const maxDim = 1600;
      let { width, height } = img;
      if (width > maxDim || height > maxDim) {
        const scale = maxDim / Math.max(width, height);
        width = Math.round(width * scale);
        height = Math.round(height * scale);
      }
      const canvas = document.createElement('canvas');
      canvas.width = width; canvas.height = height;
      canvas.getContext('2d').drawImage(img, 0, 0, width, height);
      canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.8);
    };
    img.onerror = reject;
    reader.readAsDataURL(file);
  });
}

async function handlePhotoSelect(cond) {
  const input = document.getElementById(`photoInput_${cond}`);
  const file = input.files[0];
  if (!file) return;
  const statusEl = document.getElementById(`photoUploadStatus_${cond}`);
  statusEl.innerHTML = ' Mengompres & mengirim...';

  if (!currentCountId) {
    // No count row yet — save first so we have a count_id to attach the photo to.
    try {
      await doSave();
    } catch (err) {
      statusEl.innerHTML = ' <span style="color:#ef4444;">Simpan hitungan dahulu sebelum menambah foto.</span>';
      return;
    }
  }

  await uploadPhotoBlob(cond, file, statusEl);
}

async function uploadPhotoBlob(cond, file, statusEl) {
  try {
    const compressed = await compressImage(file);
    const fd = new FormData();
    fd.append('count_id', currentCountId);
    fd.append('condition_type', cond.toUpperCase());
    fd.append('file', compressed, 'photo.jpg');
    const result = await SO.api('/api/counter/photos/upload.php', { method: 'POST', body: fd });
    statusEl.innerHTML = ' <span style="color:#166534;">Terkirim.</span>';
    await refreshThumbs(cond);
    if (result.evidence_status === 'COMPLETE') {
      document.getElementById('inputMsg').innerHTML = '<div class="warning-box" style="background:#dcfce7;color:#166534;">Semua bukti foto lengkap — hitungan ini SUDAH DIHITUNG.</div>';
      setTimeout(() => closeInput(false), 1200);
    }
  } catch (err) {
    statusEl.innerHTML = ` <span style="color:#ef4444;">Foto gagal dikirim.</span> <button type="button" class="secondary" onclick="document.getElementById('photoInput_${cond}').click();">Coba Lagi</button>`;
    // Qty/count draft is untouched — it was already saved server-side
    // before any photo upload was attempted.
  }
}

async function closeInput(releaseLock) {
  if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
  if (releaseLock && currentItem) {
    await SO.api('/api/counter/lock.php', { method: 'DELETE', body: JSON.stringify({ session_item_id: currentItem.session_item_id }) }).catch(() => {});
  }
  currentItem = null;
  currentCountId = null;
  document.getElementById('inputCard').style.display = 'none';
  document.getElementById('itemListCard').style.display = 'block';
  loadItems();
}

document.getElementById('cancelBtn').addEventListener('click', () => {
  // If evidence is still pending the lock is intentionally kept (design
  // review point 15) so nobody else on the team can grab it mid-flow —
  // closing here just returns to the list, it does not release the lock.
  closeInput(currentCountId === null);
});

function val(id) {
  return document.getElementById(id) ? parseFloat(document.getElementById(id).value || '0') : 0;
}

function buildSaveBody() {
  return {
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
}

async function doSave() {
  const result = await SO.api('/api/counter/count.php', { method: 'POST', body: JSON.stringify(buildSaveBody()) });
  currentCountId = result.data.count_id;
  return result;
}

document.getElementById('saveBtn').addEventListener('click', async () => {
  try {
    const result = await doSave();
    if (result.data.evidence_status === 'EVIDENCE_REQUIRED') {
      showEvidenceRequiredBanner();
      ['damaged', 'expired', 'deadstock'].forEach(onConditionQtyChange);
      await refreshAllThumbs();
    } else {
      closeInput(false); // lock already released server-side
    }
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
