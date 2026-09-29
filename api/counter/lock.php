<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['POST', 'DELETE'], true)) {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionItemId = (int) ($input['session_item_id'] ?? 0);
if ($sessionItemId <= 0) {
    Response::error('session_item_id wajib diisi.', 422);
}

$pdo = Database::pdo();

$siStmt = $pdo->prepare(
    'SELECT si.*, s.status AS session_status FROM stock_opname_session_items si
     JOIN stock_opname_sessions s ON s.id = si.session_id WHERE si.id = ? LIMIT 1'
);
$siStmt->execute([$sessionItemId]);
$si = $siStmt->fetch();
if (!$si) {
    Response::error('Item sesi tidak ditemukan.', 404);
}

$assignStmt = $pdo->prepare("SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE'");
$assignStmt->execute([$si['session_id'], $user['id']]);
$team = $assignStmt->fetchColumn();
if (!$team) {
    Response::error('Anda tidak di-assign sebagai petugas pada session ini.', 403);
}

$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);

if ($method === 'DELETE') {
    $locks->release($sessionItemId, $team, $user['id']);
    Response::json(['ok' => true]);
}

if ($si['session_status'] !== 'ACTIVE') {
    Response::error('Session tidak berstatus ACTIVE.', 409);
}
if ($si['item_status'] !== 'NORMAL') {
    Response::error('Item berstatus ' . $si['item_status'] . ', tidak dapat dikunci untuk dihitung.', 409);
}

$result = $locks->acquire($sessionItemId, $team, $user['id']);
if (!$result['ok']) {
    Response::error('Item sedang dihitung oleh ' . $result['locked_by']['full_name'], 409, ['locked_by' => $result['locked_by']['full_name']]);
}

Response::json(['ok' => true, 'lock_id' => $result['lock_id'], 'expires_at' => $result['expires_at']]);
