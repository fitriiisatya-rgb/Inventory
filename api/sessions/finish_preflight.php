<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'session.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$recon = new ReconciliationService($pdo, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));
$fin = new FinalizationService($pdo, $recon, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));

Response::json(['data' => ['blockers' => $fin->finishPreflight($sessionId)]]);
