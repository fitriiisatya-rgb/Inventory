<?php
/** Included by admin/*.php after they've called Auth::requireLogin(). Expects $pageTitle. */
$navUser = Auth::user();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Stok Opname') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body data-csrf="<?= htmlspecialchars(Csrf::token()) ?>">
<header class="app-header">
  <strong>Stok Opname</strong>
  <nav>
    <a href="/admin/master-barang.php">Master Barang</a>
    <a href="/admin/kategori.php">Kategori</a>
    <a href="/admin/lokasi.php">Lokasi</a>
    <?php if (Permissions::can($navUser['role'], 'user.manage')): ?>
      <a href="/admin/user.php">User</a>
    <?php endif; ?>
    <?php if (Permissions::can($navUser['role'], 'stock_import.manage')): ?>
      <a href="/admin/import-stok.php">Import Stok Sistem</a>
    <?php endif; ?>
    <?php if (Permissions::can($navUser['role'], 'session.manage')): ?>
      <a href="/admin/sessions.php">Sesi SO</a>
    <?php endif; ?>
    <?php if (Permissions::can($navUser['role'], 'counter.count')): ?>
      <a href="/counter/index.php">Hitung Stok</a>
    <?php endif; ?>
    <?php if (Permissions::can($navUser['role'], 'master.manage')): ?>
      <a href="/admin/legacy-import.php">Tools: Import Legacy</a>
    <?php endif; ?>
  </nav>
  <div>
    <?= htmlspecialchars($navUser['full_name']) ?> (<?= htmlspecialchars($navUser['role']) ?>)
    &nbsp;<a href="/logout.php" style="color:#fca5a5;">Keluar</a>
  </div>
</header>
<main class="app-main">
<h2><?= htmlspecialchars($pageTitle ?? '') ?></h2>
