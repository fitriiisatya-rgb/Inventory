<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireLogin();
$crud = new CodeNameCrud(Database::pdo(), 'categories');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Response::json(['data' => $crud->list()]);
}

Permissions::require($user['role'], 'master.manage');
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

try {
    if ($method === 'POST') {
        $row = $crud->create((string) ($input['code'] ?? ''), (string) ($input['name'] ?? ''), $user['id']);
        Response::json(['data' => $row], 201);
    }

    if ($method === 'PUT') {
        $id = (int) ($input['id'] ?? 0);
        $row = $crud->update($id, (string) ($input['code'] ?? ''), (string) ($input['name'] ?? ''), (string) ($input['status'] ?? ''), $user['id']);
        Response::json(['data' => $row]);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::error('Method not allowed', 405);
