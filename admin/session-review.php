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
      <th>SKU</th><th>Nama</th><th>Round</th><th>System Qty</th>
      <th>P1</th><th>P2</th><th>Status</th><th>Aksi</th>
    </tr></thead>
    <tbody id="rows"></tbody>
  </table>
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

async function loadItems() {
  const { data } = await SO.api('/api/review/items.php?session_id=' + sessionId);
  document.getElementById('rows').innerHTML = data.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.sku)}</td>
      <td>${SO.escapeHtml(r.name)}</td>
      <td>${r.round}</td>
      <td>${r.system_qty_snapshot}</td>
      <td>${r.p1 ? `${r.p1.physical} (${SO.escapeHtml(r.p1.user_name)})` : '-'}</td>
      <td>${r.p2 ? `${r.p2.physical} (${SO.escapeHtml(r.p2.user_name)})` : '-'}</td>
      <td><span class="badge badge-${statusBadgeClass(r.status)}">${r.status}</span>
          ${r.item_status === 'NOT_COUNTABLE' ? `<br><small>${SO.escapeHtml(r.not_countable_reason||'')}</small>` : ''}</td>
      <td>
        ${r.item_status === 'NORMAL' && (r.p1 || r.p2) ? `<button class="secondary" onclick="recount(${r.session_item_id})">Hitung Ulang</button>` : ''}
        ${r.item_status === 'NORMAL' ? `<button class="secondary" onclick="setNotCountable(${r.session_item_id})">Not Countable</button>` : `<button class="secondary" onclick="clearNotCountable(${r.session_item_id})">Clear</button>`}
      </td>
    </tr>`).join('');
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
