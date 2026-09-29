<?php
declare(strict_types=1);
require __DIR__ . '/../../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$photoId = (int) ($input['photo_id'] ?? 0);
if ($photoId <= 0) {
    Response::error('photo_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$evidence = PhotoEvidenceService::fromConfig($pdo, $locks);

try {
    Response::json($evidence->deletePhoto($photoId, $user['id']));
} catch (PhotoForbiddenException $e) {
    Response::error($e->getMessage(), 403);
} catch (PhotoValidationException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}
