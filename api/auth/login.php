<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($username === '' || $password === '') {
    Response::error('Username dan password wajib diisi', 422);
}

$user = Auth::attemptLogin($username, $password);
if (!$user) {
    Response::error('Username atau password salah', 401);
}

Response::json(['user' => Auth::user(), 'csrf_token' => Csrf::token()]);
