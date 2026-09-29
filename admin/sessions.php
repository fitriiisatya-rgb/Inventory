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
    <button type="submit">Assign</button>
  </form>

  <div style="margin-top:16px;">
    <button id="checkPreflightBtn" class="secondary">Cek Preflight</button>
    <button id="startBtn">Mulai Session</button>
  </div>
</div>

<script>
let currentSessionId = null;

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

async function loadSessions() {
  const { data } = await SO.api('/api/sessions.php');
  document.getElementById('rows').innerHTML = data.map(s => `
    <tr>
      <td>${SO.escapeHtml(s.session_no)}</td>
      <td>${SO.escapeHtml(s.name)}</td>
      <td>${SO.escapeHtml(s.location_name)}</td>
      <td><span class="badge badge-${s.status.toLowerCase()}">${s.status}</span></td>
      <td>
        ${s.status === 'DRAFT' ? `<button class="secondary" onclick="openDetail(${s.id}, '${SO.escapeHtml(s.session_no)}')">Kelola</button>` : ''}
        ${s.status === 'ACTIVE' ? `<a href="/admin/session-review.php?session_id=${s.id}">Review</a>` : ''}
      </td>
    </tr>`).join('');
}

async function openDetail(id, sessionNo) {
  currentSessionId = id;
  document.getElementById('detailCard').style.display = 'block';
  document.getElementById('detailTitle').textContent = 'Kelola Session ' + sessionNo;
  document.getElementById('preflightBox').innerHTML = '';
  await refreshAssignments();
}

async function refreshAssignments() {
  const { data } = await SO.api('/api/sessions/assign.php?session_id=' + currentSessionId);
  document.getElementById('p1List').innerHTML = data.filter(a => a.team === 'P1').map(a => `<li>${SO.escapeHtml(a.full_name)} <button class="secondary" onclick="unassign(${a.user_id})">Hapus</button></li>`).join('') || '<li><em>belum ada</em></li>';
  document.getElementById('p2List').innerHTML = data.filter(a => a.team === 'P2').map(a => `<li>${SO.escapeHtml(a.full_name)} <button class="secondary" onclick="unassign(${a.user_id})">Hapus</button></li>`).join('') || '<li><em>belum ada</em></li>';
}

async function unassign(userId) {
  await SO.api('/api/sessions/assign.php', { method: 'DELETE', body: JSON.stringify({ session_id: currentSessionId, user_id: userId }) });
  refreshAssignments();
}

document.getElementById('assignForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = Object.fromEntries(fd);
  body.session_id = currentSessionId;
  try {
    await SO.api('/api/sessions/assign.php', { method: 'POST', body: JSON.stringify(body) });
    refreshAssignments();
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('checkPreflightBtn').addEventListener('click', async () => {
  const result = await SO.api('/api/sessions/preflight.php?session_id=' + currentSessionId);
  const box = document.getElementById('preflightBox');
  if (result.blockers.length === 0) {
    box.innerHTML = `<div class="warning-box" style="background:#dcfce7;color:#166534;">Siap dimulai — ${result.item_count} SKU dalam scope.</div>`;
  } else {
    box.innerHTML = `<div class="error-box"><strong>START SESSION ditolak:</strong><br>${result.blockers.map(SO.escapeHtml).join('<br>')}</div>`;
  }
});

document.getElementById('startBtn').addEventListener('click', async () => {
  try {
    await SO.api('/api/sessions/start.php', { method: 'POST', body: JSON.stringify({ session_id: currentSessionId }) });
    document.getElementById('detailCard').style.display = 'none';
    loadSessions();
  } catch (err) {
    const blockers = (err.data && err.data.blockers) || [];
    document.getElementById('preflightBox').innerHTML = blockers.length
      ? `<div class="error-box"><strong>START SESSION ditolak:</strong><br>${blockers.map(SO.escapeHtml).join('<br>')}</div>`
      : `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
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
