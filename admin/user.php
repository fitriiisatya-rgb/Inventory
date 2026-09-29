<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'user.manage');
$pageTitle = 'User';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <form class="inline" id="createForm">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required minlength="8"></label>
    <label>Nama Lengkap <input type="text" name="full_name" required></label>
    <label>Jabatan <input type="text" name="job_title"></label>
    <label>Role
      <select name="role" id="roleSelect">
        <option>SUPERADMIN</option><option>ADMIN</option><option>SUPERVISOR</option>
        <option>APPROVER</option><option>COUNTER</option><option>VIEWER</option>
      </select>
    </label>
    <label>Team (jika COUNTER)
      <select name="team"><option value="">-</option><option>P1</option><option>P2</option></select>
    </label>
    <button type="submit">Tambah User</button>
  </form>
  <div id="msg"></div>
  <table>
    <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Team</th><th>Status</th><th></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>
<script>
async function load() {
  const { data } = await SO.api('/api/users.php');
  document.getElementById('rows').innerHTML = data.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.username)}</td>
      <td>${SO.escapeHtml(r.full_name)}</td>
      <td>${SO.escapeHtml(r.role)}</td>
      <td>${SO.escapeHtml(r.team || '-')}</td>
      <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
      <td><button class="secondary" onclick="toggleStatus(${r.id}, '${r.username}', '${SO.escapeHtml(r.full_name)}', '${r.role}', '${r.team||''}', '${r.status}')">
        ${r.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}
      </button></td>
    </tr>`).join('');
}
async function toggleStatus(id, username, full_name, role, team, status) {
  const newStatus = status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
  await SO.api('/api/users.php', { method: 'PUT', body: JSON.stringify({ id, username, full_name, role, team, status: newStatus }) });
  load();
}
document.getElementById('createForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    await SO.api('/api/users.php', { method: 'POST', body: JSON.stringify(Object.fromEntries(fd)) });
    e.target.reset();
    document.getElementById('msg').innerHTML = '';
    load();
  } catch (err) {
    document.getElementById('msg').innerHTML = `<div class="error-box">${SO.escapeHtml(err.message)}</div>`;
  }
});
load();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
