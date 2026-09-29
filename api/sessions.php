<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::pdo();
$service = new SessionService($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Response::json(['data' => $service->list()]);
}

Permissions::require($user['role'], 'session.manage');
Csrf::requireValid();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'POST') {
    try {
        $row = $service->createSession($input, $user['id']);
        Response::json(['data' => $row], 201);
    } catch (InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
}

Response::error('Method not allowed', 405);
