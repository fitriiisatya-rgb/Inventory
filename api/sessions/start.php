<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'session.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = (int) ($input['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$service = new SessionService(Database::pdo());

try {
    $row = $service->startSession($sessionId, $user['id']);
    Response::json(['data' => $row]);
} catch (SessionPreflightException $e) {
    Response::error('START SESSION ditolak', 422, $e->preflight);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
