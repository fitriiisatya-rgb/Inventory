<?php
declare(strict_types=1);
require __DIR__ . '/../../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$countId = (int) ($_POST['count_id'] ?? 0);
$conditionType = strtoupper(trim((string) ($_POST['condition_type'] ?? '')));
$caption = trim((string) ($_POST['caption'] ?? ''));

if ($countId <= 0) {
    Response::error('count_id wajib diisi.', 422);
}
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    Response::error('File foto wajib diupload.', 422);
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$evidence = PhotoEvidenceService::fromConfig($pdo, $locks);

try {
    $result = $evidence->uploadPhoto($countId, $conditionType, $user['id'], $_FILES['file'], $caption);
    Response::json([
        'data' => [
            'id' => (int) $result['photo']['id'],
            'condition_type' => $result['photo']['condition_type'],
            'caption' => $result['photo']['caption'],
            'uploaded_at' => $result['photo']['uploaded_at'],
        ],
        'evidence_status' => $result['evidence_status'],
    ], 201);
} catch (PhotoForbiddenException $e) {
    Response::error($e->getMessage(), 403);
} catch (PhotoValidationException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
