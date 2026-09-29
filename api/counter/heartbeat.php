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
$siStmt = $pdo->prepare('SELECT session_id FROM stock_opname_session_items WHERE id = ? LIMIT 1');
$siStmt->execute([$sessionItemId]);
$sessionId = $siStmt->fetchColumn();
if (!$sessionId) {
    Response::error('Item sesi tidak ditemukan.', 404);
}

$assignStmt = $pdo->prepare("SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE'");
$assignStmt->execute([$sessionId, $user['id']]);
$team = $assignStmt->fetchColumn();
if (!$team) {
    Response::error('Anda tidak di-assign sebagai petugas pada session ini.', 403);
}

$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$ok = $locks->heartbeat($sessionItemId, $team, $user['id']);

Response::json(['ok' => $ok]);
