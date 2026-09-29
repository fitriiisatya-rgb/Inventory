<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'reconciliation.view');

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(422);
    die('session_id wajib diisi.');
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$recon = new ReconciliationService($pdo, $locks);
$fin = new FinalizationService($pdo, $recon, $locks);
$report = new SessionReportService($pdo, $recon, $fin);

try {
    $data = $report->getReportData($sessionId);
} catch (RuntimeException $e) {
    http_response_code(404);
    die(htmlspecialchars($e->getMessage()));
}
$session = $data['session'];
$rows = $data['rows'];
$petugas = $data['petugas'];

$fmt = static fn($v) => $v === null ? '-' : (is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : (string) $v);
$fmtMoney = static fn($v) => $v === null ? 'N/A' : number_format((float) $v, 0, ',', '.');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Stok Opname — <?= htmlspecialchars($session['session_no']) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; margin: 24px; }
  h1 { font-size: 16px; margin-bottom: 4px; }
  h2 { font-size: 13px; margin-top: 18px; border-bottom: 1px solid #999; padding-bottom: 3px; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th, td { border: 1px solid #ccc; padding: 3px 5px; text-align: left; font-size: 10px; }
  th { background: #f0f0f0; }
  .meta td { border: none; padding: 1px 5px; }
  .no-print { margin-bottom: 16px; }
  .sig-grid { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 20px; }
  .sig-box { width: 220px; }
  .sig-line { border-bottom: 1px solid #333; height: 50px; margin-bottom: 4px; }
  .badge { padding: 1px 5px; border-radius: 3px; font-size: 9px; }
  .b-match { background: #dcfce7; }
  .b-mismatch { background: #fee2e2; }
  .b-other { background: #fef3c7; }
  @media print {
    .no-print { display: none; }
    body { margin: 0; }
  }
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()">Print / Save as PDF</button>
  <a href="/admin/session-review.php?session_id=<?= (int) $sessionId ?>">&larr; Kembali ke Review</a>
</div>

<h1>Laporan Stok Opname — <?= htmlspecialchars($session['session_no']) ?></h1>
<table class="meta">
  <tr><td><b>Nama Session</b></td><td><?= htmlspecialchars($session['name']) ?></td></tr>
  <tr><td><b>Lokasi</b></td><td><?= htmlspecialchars($session['location_name']) ?></td></tr>
  <tr><td><b>Scope</b></td><td><?= $session['scope_type'] === 'CATEGORY' ? 'Kategori: ' . htmlspecialchars($session['category_name'] ?? '-') : 'Semua Kategori' ?></td></tr>
  <tr><td><b>Tanggal Fisik</b></td><td><?= htmlspecialchars((string) $session['physical_date']) ?></td></tr>
  <tr><td><b>Status</b></td><td><?= htmlspecialchars($session['status']) ?></td></tr>
  <tr><td><b>Selesai</b></td><td><?= htmlspecialchars((string) $session['finished_at']) ?></td></tr>
</table>

<h2>Ringkasan</h2>
<?php
$counts = ['MATCH' => 0, 'MISMATCH' => 0, 'CONDITION_MISMATCH' => 0, 'PARTIAL' => 0, 'BELUM_DIHITUNG' => 0, 'RECOUNT_REQUIRED' => 0, 'NOT_COUNTABLE' => 0];
$totalVariance = 0.0;
foreach ($rows as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    if ($r['final'] && $r['final']['variance_value'] !== null) {
        $totalVariance += (float) $r['final']['variance_value'];
    }
}
?>
<table>
  <tr><th>Total Item</th><th>MATCH</th><th>MISMATCH</th><th>COND. MISMATCH</th><th>NOT COUNTABLE</th><th>Total Selisih (Rp)</th></tr>
  <tr>
    <td><?= count($rows) ?></td><td><?= $counts['MATCH'] ?></td><td><?= $counts['MISMATCH'] ?></td>
    <td><?= $counts['CONDITION_MISMATCH'] ?></td><td><?= $counts['NOT_COUNTABLE'] ?></td>
    <td><?= $fmtMoney($totalVariance) ?></td>
  </tr>
</table>

<h2>Detail Stok Opname</h2>
<table>
  <thead><tr>
    <th>SKU</th><th>Nama</th><th>Sistem</th><th>Final Good</th><th>Rusak</th><th>Expired</th><th>Dead</th>
    <th>Fisik</th><th>Tersedia</th><th>Selisih</th><th>Nilai Selisih</th><th>Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $si = $r['session_item']; $f = $r['final']; ?>
    <tr>
      <td><?= htmlspecialchars($si['sku_snapshot']) ?></td>
      <td><?= htmlspecialchars($si['name_snapshot']) ?></td>
      <td><?= $fmt($si['system_qty_snapshot']) ?></td>
      <td><?= $f ? $fmt($f['final_good_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['final_damaged_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['final_expired_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['final_deadstock_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['final_physical_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['final_available_base_qty']) : '-' ?></td>
      <td><?= $f ? $fmt($f['variance_qty']) : '-' ?></td>
      <td><?= $f ? $fmtMoney($f['variance_value']) : '-' ?></td>
      <td><span class="badge <?= $r['status'] === 'MATCH' ? 'b-match' : ($r['status'] === 'MISMATCH' ? 'b-mismatch' : 'b-other') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Tanda Tangan Petugas</h2>
<div class="sig-grid">
  <?php foreach ($petugas as $p): ?>
    <div class="sig-box">
      <div class="sig-line"></div>
      <div><?= htmlspecialchars($p['user_name_snapshot']) ?> (<?= htmlspecialchars((string) $p['team']) ?>)</div>
      <div><small><?= (int) $p['item_count'] ?> item dihitung</small></div>
    </div>
  <?php endforeach; ?>
  <div class="sig-box">
    <div class="sig-line"></div>
    <div>Diperiksa oleh (Superadmin)</div>
  </div>
</div>

</body>
</html>
