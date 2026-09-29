<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'master.manage');
$pageTitle = 'Go-Live Checklist';

$pdo = Database::pdo();
$items = [];
function glItem(array &$items, string $label, bool $ok, string $detail, string $link = ''): void
{
    $items[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'link' => $link];
}

$superadminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'SUPERADMIN' AND status = 'ACTIVE'")->fetchColumn();
glItem($items, 'Ada akun SUPERADMIN aktif', $superadminCount > 0, "{$superadminCount} akun SUPERADMIN aktif.", '/admin/user.php');

$counterP1 = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'COUNTER' AND team = 'P1' AND status = 'ACTIVE'")->fetchColumn();
$counterP2 = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'COUNTER' AND team = 'P2' AND status = 'ACTIVE'")->fetchColumn();
glItem($items, 'Ada petugas COUNTER untuk P1 dan P2', $counterP1 > 0 && $counterP2 > 0,
    "P1: {$counterP1} petugas, P2: {$counterP2} petugas.", '/admin/user.php');

$locCount = (int) $pdo->query("SELECT COUNT(*) FROM locations WHERE status = 'ACTIVE'")->fetchColumn();
glItem($items, 'Ada Lokasi aktif', $locCount > 0, "{$locCount} lokasi aktif.", '/admin/lokasi.php');

$catCount = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE status = 'ACTIVE'")->fetchColumn();
glItem($items, 'Ada Kategori aktif', $catCount > 0, "{$catCount} kategori aktif.", '/admin/kategori.php');

$itemCount = (int) $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'ACTIVE'")->fetchColumn();
$itemLegacyCount = (int) $pdo->query("SELECT COUNT(*) FROM items WHERE migration_source = 'LEGACY'")->fetchColumn();
glItem($items, 'Master Barang terisi', $itemCount > 0,
    "{$itemCount} item aktif" . ($itemLegacyCount > 0 ? " ({$itemLegacyCount} dari migrasi legacy)." : '.'), '/admin/master-barang.php');

$conversionIssueStmt = $pdo->query('SELECT id, sku, buy_unit, buy_content, mid_unit, mid_content, base_unit FROM items WHERE status = \'ACTIVE\'');
$badConversion = 0;
foreach ($conversionIssueStmt->fetchAll() as $it) {
    $issues = UnitConversion::validateConversion($it['buy_unit'], $it['buy_content'], $it['mid_unit'], $it['mid_content'], $it['base_unit']);
    if (UnitConversion::hasBlockingErrors($issues)) {
        $badConversion++;
    }
}
glItem($items, 'Semua item aktif punya konversi satuan valid', $badConversion === 0,
    $badConversion === 0 ? 'Tidak ada error konversi.' : "{$badConversion} item punya error konversi satuan — perbaiki di Master Barang.", '/admin/master-barang.php');

$committedBatch = (int) $pdo->query("SELECT COUNT(DISTINCT location_id) FROM stock_import_batches WHERE status = 'COMMITTED'")->fetchColumn();
glItem($items, 'Ada System Stock (import) ter-commit', $committedBatch > 0,
    $committedBatch > 0 ? "{$committedBatch} lokasi punya batch stock ter-commit." : 'Belum ada stock import yang di-commit untuk lokasi manapun.', '/admin/import-stok.php');

$backupExists = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'DATABASE_BACKUP'")->fetchColumn();
glItem($items, 'Backup database pernah dijalankan', $backupExists > 0,
    $backupExists > 0 ? "{$backupExists} backup tercatat di audit log." : 'Belum pernah backup — jalankan bin/backup_database.php atau tombol Backup di Import Legacy.', '/admin/legacy-import.php');

$legacyMasterRuns = $pdo->query("SELECT * FROM legacy_migrations WHERE type = 'MASTER' ORDER BY imported_at DESC LIMIT 1")->fetch();
$legacyStockRuns = $pdo->query("SELECT * FROM legacy_migrations WHERE type = 'STOCK' ORDER BY imported_at DESC LIMIT 1")->fetch();
if ($legacyMasterRuns || $legacyStockRuns) {
    $detail = [];
    if ($legacyMasterRuns) {
        $detail[] = "Master: {$legacyMasterRuns['committed_rows']} item dibuat dari " . $legacyMasterRuns['source_file'];
    }
    if ($legacyStockRuns) {
        $detail[] = "Stock: {$legacyStockRuns['committed_rows']} baris dari " . $legacyStockRuns['source_file'];
    }
    glItem($items, 'Migrasi data legacy (jika dipakai)', true, implode(' | ', $detail), '/admin/legacy-import.php');
}

$activeSessions = $pdo->query("SELECT session_no, status FROM stock_opname_sessions WHERE status IN ('DRAFT','ACTIVE','REVIEW')")->fetchAll();
glItem($items, 'Tidak ada session menggantung dari testing', count($activeSessions) === 0,
    count($activeSessions) === 0 ? 'Tidak ada session DRAFT/ACTIVE/REVIEW.' : count($activeSessions) . ' session masih terbuka: ' . implode(', ', array_column($activeSessions, 'session_no')) . ' — pastikan ini bukan sisa testing.',
    '/admin/sessions.php');

$doneCount = count(array_filter($items, fn($i) => $i['ok']));
$totalCount = count($items);

require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <p>Lihat juga <a href="/admin/system-check.php">System Check</a> untuk status teknis (PHP/extensions/DB/dir permissions).</p>
  <p><b><?= $doneCount ?> / <?= $totalCount ?></b> checklist item terpenuhi.</p>
  <?php if ($doneCount === $totalCount): ?>
    <div class="warning-box" style="background:#dcfce7;color:#166534;">Semua checklist bisnis terpenuhi. Siap menjalankan Stok Opname.</div>
  <?php else: ?>
    <div class="error-box">Masih ada item yang belum terpenuhi — lihat detail di bawah.</div>
  <?php endif; ?>
</div>

<div class="card">
  <table>
    <thead><tr><th>Checklist</th><th>Status</th><th>Detail</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $i): ?>
        <tr>
          <td><?= htmlspecialchars($i['label']) ?></td>
          <td><span class="badge badge-<?= $i['ok'] ? 'active' : 'error' ?>"><?= $i['ok'] ? 'OK' : 'BELUM' ?></span></td>
          <td><?= htmlspecialchars($i['detail']) ?></td>
          <td><?php if ($i['link']): ?><a href="<?= htmlspecialchars($i['link']) ?>">Buka</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
