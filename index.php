<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!Auth::check()) {
    header('Location: /login.php');
    exit;
}

header('Location: /admin/master-barang.php');
exit;
