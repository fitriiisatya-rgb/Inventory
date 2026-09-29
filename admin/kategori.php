<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Kategori';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <form class="inline" id="createForm">
    <label>Kode <input type="text" name="code" required></label>
    <label>Nama <input type="text" name="name" required></label>
    <button type="submit">Tambah Kategori</button>
  </form>
  <div id="msg"></div>
  <table>
    <thead><tr><th>Kode</th><th>Nama</th><th>Status</th><th></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>
<script>
async function load() {
  const { data } = await SO.api('/api/categories.php');
  document.getElementById('rows').innerHTML = data.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.code)}</td>
      <td>${SO.escapeHtml(r.name)}</td>
      <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
      <td><button class="secondary" onclick="toggleStatus(${r.id}, '${r.code}', '${r.name}', '${r.status}')">
        ${r.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}
      </button></td>
    </tr>`).join('');
}
async function toggleStatus(id, code, name, status) {
  const newStatus = status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
  await SO.api('/api/categories.php', { method: 'PUT', body: JSON.stringify({ id, code, name, status: newStatus }) });
  load();
}
document.getElementById('createForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    await SO.api('/api/categories.php', { method: 'POST', body: JSON.stringify(Object.fromEntries(fd)) });
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
