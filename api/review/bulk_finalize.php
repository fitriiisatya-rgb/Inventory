<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'final.set');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = (int) ($input['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$recon = new ReconciliationService($pdo, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));
$fin = new FinalizationService($pdo, $recon, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));

try {
    Response::json(['data' => $fin->bulkFinalizeMatch($sessionId, $user['id'])]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
