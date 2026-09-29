<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'reconciliation.view');
$sessionId = (int) ($_GET['session_id'] ?? 0);
$pageTitle = 'Review Session';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <div id="sessionHeader"></div>
  <div id="progressBox" style="margin-top:8px;"></div>
  <div id="actionBox" style="margin-top:12px;"></div>
</div>

<div class="card">
  <div class="inline" style="margin-bottom:8px;">
    <label><input type="checkbox" id="mismatchOnly"> Tampilkan MISMATCH/CONDITION_MISMATCH saja</label>
    <a href="/api/sessions/export_excel.php?session_id=<?= (int) $sessionId ?>" id="exportLink">⬇ Download Excel</a>
    &nbsp;|&nbsp;
    <a href="/admin/session-print.php?session_id=<?= (int) $sessionId ?>" target="_blank">🖨 Print / PDF</a>
  </div>
  <table>
    <thead><tr>
      <th>SKU</th><th>Nama</th><th>Round</th><th>System Qty</th><th>Unit Cost</th>
      <th>P1</th><th>P2</th><th>Status</th><th>Final</th><th>Aksi</th>
    </tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>

<div class="card" id="photoModal" style="display:none;">
  <button class="secondary" onclick="document.getElementById('photoModal').style.display='none';">Tutup</button>
  <div id="photoGrid" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;"></div>
</div>

<div class="card" id="finalModal" style="display:none;">
  <h4 id="finalModalTitle"></h4>
  <div class="inline">
    <label>Good <input type="number" step="0.0001" id="finalGood"></label>
    <label>Rusak <input type="number" step="0.0001" id="finalDamaged" value="0"></label>
    <label>Expired <input type="number" step="0.0001" id="finalExpired" value="0"></label>
    <label>Deadstock <input type="number" step="0.0001" id="finalDeadstock" value="0"></label>
  </div>
  <label style="display:block; margin-top:8px;">Alasan (wajib) <input type="text" id="finalReason" style="width:100%;"></label>
  <div id="finalModalMsg"></div>
  <div style="margin-top:8px;">
    <button id="finalSaveBtn">Simpan Final</button>
    <button class="secondary" onclick="document.getElementById('finalModal').style.display='none';">Batal</button>
  </div>
</div>

<script>
const sessionId = <?= (int) $sessionId ?>;
let allRows = [];
let mismatchOnly = false;

async function loadSessionHeader() {
  const { data } = await SO.api('/api/sessions.php');
  const s = data.find(x => x.id === sessionId);
  if (!s) { document.getElementById('sessionHeader').innerHTML = '<em>Session tidak ditemukan.</em>'; return; }
  document.getElementById('sessionHeader').innerHTML =
    `<h3>${SO.escapeHtml(s.session_no)} — ${SO.escapeHtml(s.name)}</h3>
     <div>Lokasi: ${SO.escapeHtml(s.location_name)} &nbsp;|&nbsp; Status: <span class="badge badge-${s.status.toLowerCase()}">${s.status}</span></div>`;
  renderActions(s.status);
}

function renderActions(status) {
  const box = document.getElementById('actionBox');
  let html = '';
  if (status === 'ACTIVE') {
    html += `<button id="toReviewBtn">Masuk REVIEW</button>`;
  } else if (status === 'REVIEW') {
    html += `<button id="bulkFinalizeBtn" class="secondary">Bulk Finalize (semua MATCH)</button>
             <button id="finishBtn">Selesaikan Session (FINISHED)</button>`;
  }
  box.innerHTML = html;

  document.getElementById('toReviewBtn')?.addEventListener('click', async () => {
    try {
      await SO.api('/api/sessions/transition_review.php', { method: 'POST', body: JSON.stringify({ session_id: sessionId }) });
      loadSessionHeader(); loadItems(); loadProgress();
    } catch (err) {
      box.innerHTML = `<div class="error-box"><strong>Belum dapat masuk REVIEW:</strong><br>${(err.data?.blockers||[SO.escapeHtml(err.message)]).map(SO.escapeHtml).join('<br>')}</div>` + html;
    }
  });
  document.getElementById('bulkFinalizeBtn')?.addEventListener('click', async () => {
    const { data: rows } = await SO.api('/api/review/items.php?session_id=' + sessionId);
    const matchCount = rows.filter(r => r.status === 'MATCH' && !r.final).length;
    if (matchCount === 0) { alert('Tidak ada item MATCH yang perlu di-finalize.'); return; }
    if (!confirm(`${matchCount} item MATCH akan di-finalize otomatis. Lanjutkan?`)) return;
    try {
      const { data } = await SO.api('/api/review/bulk_finalize.php', { method: 'POST', body: JSON.stringify({ session_id: sessionId }) });
      alert(`${data.finalized} item di-finalize, ${data.skipped} dilewati (sudah final).`);
      loadItems();
    } catch (err) { alert(err.message); }
  });
  document.getElementById('finishBtn')?.addEventListener('click', async () => {
    try {
      await SO.api('/api/sessions/finish.php', { method: 'POST', body: JSON.stringify({ session_id: sessionId }) });
      loadSessionHeader(); loadItems(); loadProgress();
    } catch (err) {
      box.innerHTML = `<div class="error-box"><strong>FINISH BLOCKED:</strong><br>${(err.data?.blockers||[SO.escapeHtml(err.message)]).map(SO.escapeHtml).join('<br>')}</div>` + html;
    }
  });
}

async function loadProgress() {
  const { data } = await SO.api('/api/review/progress.php?session_id=' + sessionId);
  document.getElementById('progressBox').innerHTML = Object.entries(data).map(([k,v]) => `<b>${k}</b>: ${v}`).join(' &nbsp;|&nbsp; ');
}

function statusBadgeClass(s) {
  return { MATCH: 'active', MISMATCH: 'error', CONDITION_MISMATCH: 'warning', PARTIAL: 'warning',
    BELUM_DIHITUNG: 'inactive', RECOUNT_REQUIRED: 'warning', NOT_COUNTABLE: 'inactive' }[s] || 'inactive';
}

function costCell(r) {
  if (r.unit_cost_snapshot === null) {
    return `<span title="${r.unit_cost_source}">N/A</span>`;
  }
  return r.unit_cost_snapshot + (r.unit_cost_source === 'MASTER_LAST_BUY_PRICE' ? ' *' : '');
}

function teamCell(team, r) {
  if (!team) return '-';
  const pendingFlag = r[team === r.p1 ? 'p1_evidence_pending' : 'p2_evidence_pending'];
  const photoBtn = (team.damaged > 0 || team.expired > 0 || team.deadstock > 0)
    ? ` <button class="secondary" onclick="showPhotos(${team.count_id})">Foto</button>` : '';
  return `${team.physical} (${SO.escapeHtml(team.user_name)})${pendingFlag ? ' <span class="badge badge-warning">Foto Pending</span>' : ''}${photoBtn}`;
}

function finalCell(r) {
  if (r.item_status === 'NOT_COUNTABLE') return '-';
  if (!r.final) return '<em>belum final</em>';
  const f = r.final;
  const physValueStr = f.variance_physical_value === null ? 'N/A' : f.variance_physical_value;
  const availValueStr = f.variance_available_value === null ? 'N/A' : f.variance_available_value;
  return `Good:${f.good} Rusak:${f.damaged} Exp:${f.expired} Dead:${f.deadstock}<br>
    Selisih Fisik: ${f.variance_physical_qty} (Nilai Selisih Fisik: Rp ${physValueStr})<br>
    Selisih Stok Layak/Available: ${f.variance_available_qty} (Nilai Selisih Available: Rp ${availValueStr})
    <span class="badge badge-${f.source === 'AUTO_MATCH' ? 'active' : 'warning'}">${f.source}</span>`;
}

async function loadItems() {
  const { data } = await SO.api('/api/review/items.php?session_id=' + sessionId);
  allRows = data;
  renderRows();
}

function renderRows() {
  const rows = mismatchOnly ? allRows.filter(r => r.status === 'MISMATCH' || r.status === 'CONDITION_MISMATCH') : allRows;
  document.getElementById('rows').innerHTML = rows.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.sku)}</td>
      <td>${SO.escapeHtml(r.name)}</td>
      <td>${r.round}</td>
      <td>${r.system_qty_snapshot}</td>
      <td>${costCell(r)}</td>
      <td>${teamCell(r.p1, r)}</td>
      <td>${teamCell(r.p2, r)}</td>
      <td><span class="badge badge-${statusBadgeClass(r.status)}">${r.status}</span>
          ${r.item_status === 'NOT_COUNTABLE' ? `<br><small>${SO.escapeHtml(r.not_countable_reason||'')}</small>` : ''}</td>
      <td>${finalCell(r)}</td>
      <td>
        ${r.item_status === 'NORMAL' && (r.p1 || r.p2) ? `<button class="secondary" onclick="recount(${r.session_item_id})">Hitung Ulang</button>` : ''}
        ${r.item_status === 'NORMAL' ? `<button class="secondary" onclick="setNotCountable(${r.session_item_id})">Not Countable</button>` : `<button class="secondary" onclick="clearNotCountable(${r.session_item_id})">Clear</button>`}
        ${r.item_status === 'NORMAL' ? `<button onclick='openFinalModal(${JSON.stringify(r)})'>Set Final</button>` : ''}
      </td>
    </tr>`).join('');
}

document.getElementById('mismatchOnly').addEventListener('change', (e) => {
  mismatchOnly = e.target.checked;
  renderRows();
});

async function showPhotos(countId) {
  const { data } = await SO.api('/api/photos/list.php?count_id=' + countId);
  const grid = document.getElementById('photoGrid');
  grid.innerHTML = data.map(p => `
    <div style="text-align:center;">
      <a href="${p.url}" target="_blank"><img src="${p.url}" style="width:140px;height:140px;object-fit:cover;border-radius:6px;${p.status==='SUPERSEDED'?'opacity:0.5;':''}"></a>
      <div><span class="badge badge-${p.status==='ACTIVE'?'active':'inactive'}">${p.condition_type}${p.status==='SUPERSEDED'?' (lama)':''}</span></div>
      ${p.caption ? `<div><small>${SO.escapeHtml(p.caption)}</small></div>` : ''}
    </div>`).join('') || '<em>Belum ada foto.</em>';
  document.getElementById('photoModal').style.display = 'block';
}

let finalModalSessionItemId = null;
function openFinalModal(r) {
  finalModalSessionItemId = r.session_item_id;
  document.getElementById('finalModalTitle').textContent = `Set Final — ${r.sku} (${r.name}), status: ${r.status}`;
  const f = r.final;
  document.getElementById('finalGood').value = f ? f.good : (r.p1 ? r.p1.good : (r.p2 ? r.p2.good : 0));
  document.getElementById('finalDamaged').value = f ? f.damaged : (r.p1 ? r.p1.damaged : (r.p2 ? r.p2.damaged : 0));
  document.getElementById('finalExpired').value = f ? f.expired : (r.p1 ? r.p1.expired : (r.p2 ? r.p2.expired : 0));
  document.getElementById('finalDeadstock').value = f ? f.deadstock : (r.p1 ? r.p1.deadstock : (r.p2 ? r.p2.deadstock : 0));
  document.getElementById('finalReason').value = '';
  document.getElementById('finalModalMsg').innerHTML = '';
  document.getElementById('finalModal').style.display = 'block';
}

document.getElementById('finalSaveBtn').addEventListener('click', async () => {
  const body = {
    session_item_id: finalModalSessionItemId,
    good: document.getElementById('finalGood').value,
    damaged: document.getElementById('finalDamaged').value,
    expired: document.getElementById('finalExpired').value,
    deadstock: document.getElementById('finalDeadstock').value,
    reason: document.getElementById('finalReason').value,
  };
  try {
    await SO.api('/api/review/set_final.php', { method: 'POST', body: JSON.stringify(body) });
    document.getElementById('finalModal').style.display = 'none';
    loadItems();
  } catch (err) {
    document.getElementById('finalModalMsg').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

async function recount(sessionItemId) {
  const reason = prompt('Alasan hitung ulang:');
  if (!reason) return;
  try {
    await SO.api('/api/review/recount.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId, reason }) });
    loadItems(); loadProgress();
  } catch (err) { alert(err.message); }
}
async function setNotCountable(sessionItemId) {
  const reason = prompt('Alasan tidak dapat dihitung:');
  if (!reason) return;
  try {
    await SO.api('/api/review/not_countable.php', { method: 'POST', body: JSON.stringify({ session_item_id: sessionItemId, reason }) });
    loadItems(); loadProgress();
  } catch (err) { alert(err.message); }
}
async function clearNotCountable(sessionItemId) {
  try {
    await SO.api('/api/review/not_countable.php', { method: 'DELETE', body: JSON.stringify({ session_item_id: sessionItemId }) });
    loadItems(); loadProgress();
  } catch (err) { alert(err.message); }
}

loadSessionHeader();
loadProgress();
loadItems();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
