<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$countId = (int) ($_GET['count_id'] ?? 0);
if ($countId <= 0) {
    Response::error('count_id wajib diisi.', 422);
}

$pdo = Database::pdo();

// Same permission boundary as view.php: COUNTER may only list photos for
// their OWN team's count; SUPERADMIN (reconciliation.view) sees any.
$countStmt = $pdo->prepare(
    'SELECT c.team, si.session_id FROM stock_opname_counts c
     JOIN stock_opname_session_items si ON si.id = c.session_item_id
     WHERE c.id = ? LIMIT 1'
);
$countStmt->execute([$countId]);
$count = $countStmt->fetch();
if (!$count) {
    Response::error('Count tidak ditemukan.', 404);
}

if (!Permissions::can($user['role'], 'reconciliation.view')) {
    $assignStmt = $pdo->prepare(
        "SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1"
    );
    $assignStmt->execute([$count['session_id'], $user['id']]);
    $viewerTeam = $assignStmt->fetchColumn();
    if (!$viewerTeam || $viewerTeam !== $count['team']) {
        Response::error('Anda tidak memiliki akses ke foto ini.', 403);
    }
}

$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$evidence = PhotoEvidenceService::fromConfig($pdo, $locks);

$photos = array_map(static function (array $p) {
    return [
        'id' => (int) $p['id'],
        'condition_type' => $p['condition_type'],
        'status' => $p['status'],
        'caption' => $p['caption'],
        'uploaded_at' => $p['uploaded_at'],
        'superseded_reason' => $p['superseded_reason'],
        'url' => '/api/photos/view.php?id=' . $p['id'],
    ];
}, $evidence->listPhotos($countId));

Response::json(['data' => $photos]);
