<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::user();
Response::json([
    'user'       => $user,
    'csrf_token' => $user ? Csrf::token() : null,
]);
