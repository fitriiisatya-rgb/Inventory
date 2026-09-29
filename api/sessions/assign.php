<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'session.manage');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sessionId = (int) ($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        Response::error('session_id wajib diisi.', 422);
    }
    $service = new SessionService(Database::pdo());
    Response::json(['data' => $service->listCounters($sessionId)]);
}

Csrf::requireValid();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = (int) ($input['session_id'] ?? 0);
$userId = (int) ($input['user_id'] ?? 0);

if ($sessionId <= 0 || $userId <= 0) {
    Response::error('session_id dan user_id wajib diisi.', 422);
}

$service = new SessionService(Database::pdo());

try {
    if ($method === 'POST') {
        $team = (string) ($input['team'] ?? '');
        Response::json(['data' => $service->assignCounter($sessionId, $userId, $team, $user['id'])]);
    }
    if ($method === 'DELETE') {
        Response::json(['data' => $service->unassignCounter($sessionId, $userId, $user['id'])]);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::error('Method not allowed', 405);
