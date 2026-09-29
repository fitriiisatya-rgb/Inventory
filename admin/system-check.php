<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
Permissions::require($me['role'], 'master.manage');
$pageTitle = 'System Check';

/** @var array<int,array{label:string,status:string,detail:string}> $checks */
$checks = [];
function check(array &$checks, string $label, string $status, string $detail): void
{
    $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
}

// 1. PHP version
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
check($checks, 'PHP Version', $phpOk ? 'PASS' : 'FAIL', PHP_VERSION . ($phpOk ? ' (>= 8.1 required)' : ' — minimum 8.1 required'));

// 2. Required extensions
$requiredExt = ['pdo_mysql', 'zip', 'gd', 'fileinfo', 'mbstring', 'json', 'session'];
foreach ($requiredExt as $ext) {
    $loaded = extension_loaded($ext);
    check($checks, "Extension: {$ext}", $loaded ? 'PASS' : 'FAIL', $loaded ? 'loaded' : 'NOT loaded — required');
}

// 3. Database connectivity + version
try {
    $pdo = Database::pdo();
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    check($checks, 'Database Connectivity', 'PASS', "Connected — server version {$ver}");
} catch (Throwable $e) {
    check($checks, 'Database Connectivity', 'FAIL', $e->getMessage());
    $pdo = null;
}

// 4. Migration status
if ($pdo) {
    $applied = array_column($pdo->query('SELECT filename FROM schema_migrations')->fetchAll(), 'filename');
    $files = array_map('basename', glob(__DIR__ . '/../database/migrations/*.sql') ?: []);
    $missing = array_diff($files, $applied);
    if (empty($files)) {
        check($checks, 'Migration Status', 'WARNING', 'No migration files found on disk.');
    } elseif (empty($missing)) {
        check($checks, 'Migration Status', 'PASS', count($applied) . ' migration(s) applied, matches files on disk.');
    } else {
        check($checks, 'Migration Status', 'FAIL', count($missing) . ' migration(s) not yet applied: ' . implode(', ', $missing) . ' — run bin/migrate.php.');
    }
}

// 5. Upload dir
$uploadDir = $GLOBALS['SO_CONFIG']['app']['upload_dir'] ?? '';
if ($uploadDir === '') {
    check($checks, 'Upload Directory (foto evidence)', 'FAIL', 'upload_dir tidak dikonfigurasi.');
} elseif (!is_dir($uploadDir)) {
    check($checks, 'Upload Directory (foto evidence)', 'FAIL', "{$uploadDir} tidak ada.");
} elseif (!is_writable($uploadDir)) {
    check($checks, 'Upload Directory (foto evidence)', 'FAIL', "{$uploadDir} tidak writable oleh proses PHP.");
} else {
    $htaccess = is_file($uploadDir . '/.htaccess');
    check($checks, 'Upload Directory (foto evidence)', $htaccess ? 'PASS' : 'WARNING',
        $uploadDir . ($htaccess ? ' — writable, .htaccess ada (pastikan Apache benar-benar menegakkannya di hosting produksi).' : ' — writable, TAPI .htaccess TIDAK ada — foto bisa diakses langsung via URL!'));
}

// 6. Backup dir
$backupDir = $GLOBALS['SO_CONFIG']['app']['backup_dir'] ?? (__DIR__ . '/../backups');
if (!is_dir($backupDir)) {
    check($checks, 'Backup Directory', 'WARNING', "{$backupDir} belum ada — akan dibuat otomatis saat backup pertama dijalankan.");
} elseif (!is_writable($backupDir)) {
    check($checks, 'Backup Directory', 'FAIL', "{$backupDir} tidak writable.");
} else {
    $htaccess = is_file($backupDir . '/.htaccess');
    check($checks, 'Backup Directory', $htaccess ? 'PASS' : 'WARNING',
        $backupDir . ($htaccess ? ' — writable, .htaccess ada.' : ' — writable, TAPI .htaccess TIDAK ada — backup SQL bisa terekspos ke web!'));
}

// 7. mysqldump availability (informational — PHP-native fallback exists either way)
$hasMysqldump = function_exists('shell_exec') && trim((string) @shell_exec('command -v mysqldump 2>/dev/null')) !== '';
check($checks, 'mysqldump binary', $hasMysqldump ? 'PASS' : 'WARNING',
    $hasMysqldump ? 'Tersedia — backup akan pakai mysqldump (cepat, lengkap).' : 'Tidak tersedia/shell_exec dimatikan — backup akan otomatis pakai fallback PHP-native (lebih lambat, tetap valid).');

// 8. Timezone
$tzConfig = $GLOBALS['SO_CONFIG']['app']['timezone'] ?? 'Asia/Jakarta';
check($checks, 'Timezone', date_default_timezone_get() === $tzConfig ? 'PASS' : 'WARNING', 'Configured: ' . $tzConfig . ', active: ' . date_default_timezone_get());

// 9. Config password placeholder check
$dbPass = $GLOBALS['SO_CONFIG']['db']['pass'] ?? '';
check($checks, 'DB Password Configured', $dbPass !== '' && $dbPass !== 'CHANGE_ME' ? 'PASS' : 'FAIL',
    $dbPass === 'CHANGE_ME' ? 'Masih memakai placeholder CHANGE_ME dari config.sample.php!' : 'OK (tidak ditampilkan).');

// 10. HTTPS / cookie security
$isHttps = !empty($_SERVER['HTTPS']);
check($checks, 'HTTPS', $isHttps ? 'PASS' : 'WARNING', $isHttps ? 'Request ini via HTTPS — cookie secure flag aktif.' : 'Request ini BUKAN via HTTPS — di produksi WAJIB pasang SSL sebelum go-live (cookie secure flag baru aktif otomatis saat HTTPS).');

// 11. Disk free space
$free = @disk_free_space(__DIR__);
if ($free !== false) {
    $freeMb = round($free / 1024 / 1024, 1);
    check($checks, 'Disk Free Space', $freeMb > 100 ? 'PASS' : 'WARNING', "{$freeMb} MB free");
}

$counts = ['PASS' => 0, 'WARNING' => 0, 'FAIL' => 0];
foreach ($checks as $c) {
    $counts[$c['status']]++;
}

require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <p><b>PASS:</b> <span class="badge badge-active"><?= $counts['PASS'] ?></span>
     &nbsp;<b>WARNING:</b> <span class="badge badge-warning"><?= $counts['WARNING'] ?></span>
     &nbsp;<b>FAIL:</b> <span class="badge badge-error"><?= $counts['FAIL'] ?></span></p>
  <?php if ($counts['FAIL'] > 0): ?>
    <div class="error-box"><strong>Ada item FAIL — perbaiki sebelum go-live.</strong></div>
  <?php elseif ($counts['WARNING'] > 0): ?>
    <div class="warning-box">Ada item WARNING — tinjau sebelum go-live, tidak semuanya blocking.</div>
  <?php else: ?>
    <div class="warning-box" style="background:#dcfce7;color:#166534;">Semua check PASS.</div>
  <?php endif; ?>
</div>

<div class="card">
  <table>
    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['label']) ?></td>
          <td><span class="badge badge-<?= $c['status'] === 'PASS' ? 'active' : ($c['status'] === 'WARNING' ? 'warning' : 'error') ?>"><?= $c['status'] ?></span></td>
          <td><?= htmlspecialchars($c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
