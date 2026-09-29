<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$stmt = Database::pdo()->prepare(
    "SELECT s.id, s.session_no, s.name, s.status, l.name AS location_name, sc.team
     FROM stock_opname_session_counters sc
     JOIN stock_opname_sessions s ON s.id = sc.session_id
     JOIN locations l ON l.id = s.location_id
     WHERE sc.user_id = ? AND sc.status = 'ACTIVE' AND s.status = 'ACTIVE'
     ORDER BY s.created_at DESC"
);
$stmt->execute([$user['id']]);

Response::json(['data' => $stmt->fetchAll()]);
