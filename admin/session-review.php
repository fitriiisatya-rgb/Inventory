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
  <div id="progressBox"></div>
</div>
<div class="card">
  <table>
    <thead><tr>
      <th>SKU</th><th>Nama</th><th>Round</th><th>System Qty</th><th>Unit Cost</th>
      <th>P1</th><th>P2</th><th>Status</th><th>Aksi</th>
    </tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>

<div class="card" id="photoModal" style="display:none;">
  <button class="secondary" onclick="document.getElementById('photoModal').style.display='none';">Tutup</button>
  <div id="photoGrid" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;"></div>
</div>

<script>
const sessionId = <?= (int) $sessionId ?>;

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

async function loadItems() {
  const { data } = await SO.api('/api/review/items.php?session_id=' + sessionId);
  document.getElementById('rows').innerHTML = data.map(r => `
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
      <td>
        ${r.item_status === 'NORMAL' && (r.p1 || r.p2) ? `<button class="secondary" onclick="recount(${r.session_item_id})">Hitung Ulang</button>` : ''}
        ${r.item_status === 'NORMAL' ? `<button class="secondary" onclick="setNotCountable(${r.session_item_id})">Not Countable</button>` : `<button class="secondary" onclick="clearNotCountable(${r.session_item_id})">Clear</button>`}
      </td>
    </tr>`).join('');
}

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

loadProgress();
loadItems();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
