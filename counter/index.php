<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'counter.count');
$pageTitle = 'Sesi Saya';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <table>
    <thead><tr><th>No. Session</th><th>Nama</th><th>Lokasi</th><th>Team Saya</th><th></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>
<script>
async function load() {
  const { data } = await SO.api('/api/counter/sessions.php');
  document.getElementById('rows').innerHTML = data.map(s => `
    <tr>
      <td>${SO.escapeHtml(s.session_no)}</td>
      <td>${SO.escapeHtml(s.name)}</td>
      <td>${SO.escapeHtml(s.location_name)}</td>
      <td>${SO.escapeHtml(s.team)}</td>
      <td><a href="/counter/count.php?session_id=${s.id}">Mulai Hitung</a></td>
    </tr>`).join('') || '<tr><td colspan="5"><em>Belum ada session aktif untuk Anda.</em></td></tr>';
}
load();
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
