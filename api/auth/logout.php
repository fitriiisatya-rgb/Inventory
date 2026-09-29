<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

Auth::requireLogin();
Csrf::requireValid();
Auth::logout();

Response::json(['ok' => true]);
