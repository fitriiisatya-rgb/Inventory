<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/**
 * Permission-checked photo stream (design review point 20). There is no
 * direct, guessable URL to an uploaded file — the only way to see a photo
 * is through this endpoint, by database id, after a permission check.
 * uploads/opname/ itself denies direct HTTP access (see .htaccess there).
 */

$user = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$photoId = (int) ($_GET['id'] ?? 0);
if ($photoId <= 0) {
    Response::error('id wajib diisi.', 422);
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$evidence = PhotoEvidenceService::fromConfig($pdo, $locks);

try {
    $result = $evidence->getPhotoForViewing($photoId, $user);
} catch (PhotoForbiddenException $e) {
    Response::error($e->getMessage(), 403);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

header('Content-Type: ' . $result['mime']);
header('Content-Length: ' . filesize($result['absolute_path']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($result['absolute_path']);
exit;
