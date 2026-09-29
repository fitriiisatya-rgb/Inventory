<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'session.manage');
$pageTitle = 'Sesi Stok Opname';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <h3>Buat Session Baru</h3>
  <form class="inline" id="createForm">
    <label>Nama Session <input type="text" name="name" required style="width:220px;"></label>
    <label>Lokasi <select name="location_id" id="locationSelect" required></select></label>
    <label>Scope
      <select name="scope_type" id="scopeType">
        <option value="ALL">Semua Barang</option>
        <option value="CATEGORY">Kategori Tertentu</option>
      </select>
    </label>
    <label id="categoryWrap" style="display:none;">Kategori <select name="category_id" id="categorySelect"></select></label>
    <label>Tanggal Fisik <input type="date" name="physical_date"></label>
    <button type="submit">Buat Session</button>
  </form>
  <div id="msg"></div>
</div>

<div class="card">
  <table>
    <thead><tr><th>No. Session</th><th>Nama</th><th>Lokasi</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>

<div class="card" id="detailCard" style="display:none;">
  <h3 id="detailTitle"></h3>
  <div id="preflightBox"></div>

  <h4>Petugas P1</h4>
  <ul id="p1List"></ul>
  <h4>Petugas P2</h4>
  <ul id="p2List"></ul>
  <form class="inline" id="assignForm">
    <label>User COUNTER <select name="user_id" id="counterSelect"></select></label>
    <label>Team <select name="team"><option>P1</option><option>P2</option></select></label>
    <label id="assignReasonWrap" style="display:none;">Alasan (wajib — session sudah ACTIVE) <input type="text" name="reason" id="assignReason"></label>
    <button type="submit">Assign</button>
  </form>

  <div style="margin-top:16px;">
    <button id="checkPreflightBtn" class="secondary">Cek Preflight</button>
    <button id="startBtn">Mulai Session</button>
    <button class="secondary" id="historyBtn">Riwayat Assignment</button>
  </div>
  <div id="historyBox" style="display:none; margin-top:12px;"></div>
</div>

<script>
let currentSessionId = null;
let currentSessionStatus = null;

async function loadLocations() {
  const { data } = await SO.api('/api/locations.php');
  document.getElementById('locationSelect').innerHTML = data.map(l => `<option value="${l.id}">${SO.escapeHtml(l.name)}</option>`).join('');
}
async function loadCategories() {
  const { data } = await SO.api('/api/categories.php');
  document.getElementById('categorySelect').innerHTML = data.map(c => `<option value="${c.id}">${SO.escapeHtml(c.name)}</option>`).join('');
}
async function loadCounters() {
  const { data } = await SO.api('/api/users.php');
  const counters = data.filter(u => u.role === 'COUNTER' && u.status === 'ACTIVE');
  document.getElementById('counterSelect').innerHTML = counters.map(u => `<option value="${u.id}">${SO.escapeHtml(u.full_name)} (${u.team||'-'})</option>`).join('');
}

document.getElementById('scopeType').addEventListener('change', (e) => {
  document.getElementById('categoryWrap').style.display = e.target.value === 'CATEGORY' ? 'flex' : 'none';
});

let allSessions = [];

async function loadSessions() {
  const { data } = await SO.api('/api/sessions.php');
  allSessions = data;
  document.getElementById('rows').innerHTML = data.map(s => `
    <tr>
      <td>${SO.escapeHtml(s.session_no)}</td>
      <td>${SO.escapeHtml(s.name)}</td>
      <td>${SO.escapeHtml(s.location_name)}</td>
      <td><span class="badge badge-${s.status.toLowerCase()}">${s.status}</span></td>
      <td>
        ${s.status === 'DRAFT' || s.status === 'ACTIVE' ? `<button class="secondary" onclick="openDetail(${s.id}, '${SO.escapeHtml(s.session_no)}', '${s.status}')">Kelola</button>` : ''}
        ${s.status === 'ACTIVE' ? `<a href="/admin/session-review.php?session_id=${s.id}">Review</a>` : ''}
      </td>
    </tr>`).join('');
}

async function openDetail(id, sessionNo, status) {
  currentSessionId = id;
  currentSessionStatus = status;
  document.getElementById('detailCard').style.display = 'block';
  document.getElementById('detailTitle').textContent = `Kelola Session ${sessionNo} (${status})`;
  document.getElementById('preflightBox').innerHTML = '';
  document.getElementById('historyBox').style.display = 'none';
  document.getElementById('assignReasonWrap').style.display = status === 'ACTIVE' ? 'flex' : 'none';
  document.getElementById('startBtn').style.display = status === 'DRAFT' ? 'inline-block' : 'none';
  await refreshAssignments();
}

async function refreshAssignments() {
  const { data } = await SO.api('/api/sessions/assign.php?session_id=' + currentSessionId);
  const row = (a) => `<li>${SO.escapeHtml(a.full_name)} <button class="secondary" onclick="unassign(${a.user_id})">Hapus</button></li>`;
  document.getElementById('p1List').innerHTML = data.filter(a => a.team === 'P1').map(row).join('') || '<li><em>belum ada</em></li>';
  document.getElementById('p2List').innerHTML = data.filter(a => a.team === 'P2').map(row).join('') || '<li><em>belum ada</em></li>';
}

async function unassign(userId) {
  let reason = null;
  if (currentSessionStatus === 'ACTIVE') {
    reason = prompt('Session sudah ACTIVE — alasan unassign wajib diisi:');
    if (!reason) return;
  }
  try {
    await SO.api('/api/sessions/assign.php', { method: 'DELETE', body: JSON.stringify({ session_id: currentSessionId, user_id: userId, reason }) });
    refreshAssignments();
  } catch (err) {
    alert(err.message);
  }
}

document.getElementById('assignForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = Object.fromEntries(fd);
  body.session_id = currentSessionId;
  try {
    await SO.api('/api/sessions/assign.php', { method: 'POST', body: JSON.stringify(body) });
    document.getElementById('assignReason').value = '';
    refreshAssignments();
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('historyBtn').addEventListener('click', async () => {
  const box = document.getElementById('historyBox');
  if (box.style.display === 'block') { box.style.display = 'none'; return; }
  const { data } = await SO.api('/api/sessions/assign.php?session_id=' + currentSessionId + '&history=1');
  box.innerHTML = '<table><thead><tr><th>User</th><th>Team</th><th>Status</th><th>Assigned</th><th>Removed</th></tr></thead><tbody>' +
    data.map(r => `<tr>
      <td>${SO.escapeHtml(r.full_name)}</td>
      <td>${r.team}</td>
      <td><span class="badge badge-${r.status === 'ACTIVE' ? 'active' : 'inactive'}">${r.status}</span></td>
      <td>${SO.escapeHtml(r.assigned_by_name)} — ${r.assigned_at}${r.assigned_reason ? ' — ' + SO.escapeHtml(r.assigned_reason) : ''}</td>
      <td>${r.removed_by_name ? SO.escapeHtml(r.removed_by_name) + ' — ' + r.removed_at + (r.removed_reason ? ' — ' + SO.escapeHtml(r.removed_reason) : '') : '-'}</td>
    </tr>`).join('') + '</tbody></table>';
  box.style.display = 'block';
});

document.getElementById('checkPreflightBtn').addEventListener('click', async () => {
  const result = await SO.api('/api/sessions/preflight.php?session_id=' + currentSessionId);
  document.getElementById('preflightBox').innerHTML = renderPreflight(result);
});

function renderPreflight(result) {
  let html = '';
  if (result.blockers.length === 0) {
    html += `<div class="warning-box" style="background:#dcfce7;color:#166534;">Siap dimulai — ${result.item_count} SKU dalam scope.</div>`;
  } else {
    html += `<div class="error-box"><strong>START SESSION ditolak:</strong><br>${result.blockers.map(SO.escapeHtml).join('<br>')}</div>`;
  }
  if (result.missing_system_stock && result.missing_system_stock.length) {
    html += '<table><caption>SKU tanpa System Stock pada batch ini</caption><thead><tr><th>SKU</th><th>Nama</th><th>Kategori</th><th>Reason</th></tr></thead><tbody>' +
      result.missing_system_stock.map(r => `<tr><td>${SO.escapeHtml(r.sku)}</td><td>${SO.escapeHtml(r.name)}</td><td>${SO.escapeHtml(r.category)}</td><td>${r.reason}</td></tr>`).join('') +
      '</tbody></table>';
  }
  if (result.cost_warnings && result.cost_warnings.length) {
    html += `<div class="warning-box">${result.cost_warnings.length} SKU tidak mempunyai unit cost. Variance Rupiah tidak dapat dihitung untuk SKU tersebut (tidak memblokir mulai session).<br>` +
      result.cost_warnings.map(r => SO.escapeHtml(`${r.sku} — ${r.name}`)).join('<br>') + '</div>';
  }
  return html;
}

document.getElementById('startBtn').addEventListener('click', async () => {
  try {
    await SO.api('/api/sessions/start.php', { method: 'POST', body: JSON.stringify({ session_id: currentSessionId }) });
    document.getElementById('detailCard').style.display = 'none';
    loadSessions();
  } catch (err) {
    if (err.data && (err.data.blockers || err.data.missing_system_stock || err.data.cost_warnings)) {
      document.getElementById('preflightBox').innerHTML = renderPreflight(err.data);
    } else {
      document.getElementById('preflightBox').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
    }
  }
});

document.getElementById('createForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    await SO.api('/api/sessions.php', { method: 'POST', body: JSON.stringify(Object.fromEntries(fd)) });
    e.target.reset();
    document.getElementById('msg').innerHTML = '';
    loadSessions();
  } catch (err) {
    document.getElementById('msg').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});

loadLocations();
loadCategories();
loadCounters();
loadSessions();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
