<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionItemId = (int) ($input['session_item_id'] ?? 0);
if ($sessionItemId <= 0) {
    Response::error('session_item_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$evidence = PhotoEvidenceService::fromConfig($pdo, $locks);
$service = new CountService($pdo, $locks, $evidence);

try {
    $result = $service->saveCount($sessionItemId, $user['id'], $input);
    Response::json(['data' => $result]);
} catch (CountLockException $e) {
    Response::error($e->getMessage(), 409);
} catch (CountValidationException $e) {
    Response::error('Validasi gagal', 422, ['issues' => $e->errors]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
