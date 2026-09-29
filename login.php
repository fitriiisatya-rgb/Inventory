<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    header('Location: /index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $user = Auth::attemptLogin($username, $password);
    if ($user) {
        header('Location: /index.php');
        exit;
    }
    $error = 'Username atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Stok Opname</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="background:#0b1530;">
<div class="login-wrap card">
  <h2 style="margin-top:0;">Stok Opname</h2>
  <?php if ($error): ?><div class="error-box"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="inline" style="flex-direction:column; align-items:stretch;">
    <label>Username <input type="text" name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Masuk</button>
  </form>
</div>
</body>
</html>
