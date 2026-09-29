<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/**
 * COUNTER-only item listing. Deliberately a separate query/serializer
 * from api/review/items.php (design review point 12) — this file never
 * selects system_qty_snapshot, unit_cost_snapshot, the other team's
 * counts, or any MATCH/MISMATCH/variance field. There is no shared
 * "full row minus sensitive fields" path to accidentally widen later.
 */

$user = Auth::requireLogin();
Permissions::require($user['role'], 'counter.count');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$pdo = Database::pdo();

$assignStmt = $pdo->prepare("SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1");
$assignStmt->execute([$sessionId, $user['id']]);
$team = $assignStmt->fetchColumn();
if (!$team) {
    Response::error('Anda tidak di-assign sebagai petugas pada session ini.', 403);
}

$statusFilter = $_GET['status'] ?? 'ACTIVE';   // master item status: ACTIVE|INACTIVE|ALL, default ACTIVE
$countStatusFilter = $_GET['count_status'] ?? 'SEMUA';
$categoryId = (int) ($_GET['category_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['si.session_id = ?'];
$params = [$sessionId];
if (in_array($statusFilter, ['ACTIVE', 'INACTIVE'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}
if ($categoryId > 0) {
    $catStmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $catStmt->execute([$categoryId]);
    $catName = $catStmt->fetchColumn();
    if ($catName !== false) {
        $where[] = 'si.category_snapshot = ?';
        $params[] = $catName;
    }
}
if ($q !== '') {
    $where[] = '(si.sku_snapshot LIKE ? OR si.barcode_snapshot LIKE ? OR si.name_snapshot LIKE ?)';
    $like = "%{$q}%";
    array_push($params, $like, $like, $like);
}

$sql = 'SELECT si.* FROM stock_opname_session_items si JOIN items i ON i.id = si.item_id WHERE ' . implode(' AND ', $where) . ' ORDER BY si.sku_snapshot';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sessionItems = $stmt->fetchAll();

$recon = new ReconciliationService($pdo, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));
$countStmt = $pdo->prepare('SELECT * FROM stock_opname_counts WHERE session_item_id = ? AND team = ? AND round = ? LIMIT 1');
$lockStmt = $pdo->prepare('SELECT user_id FROM stock_opname_item_locks WHERE session_item_id = ? AND team = ? AND expires_at >= NOW() LIMIT 1');

$rows = [];
$teamDone = 0;
$myDone = 0;
foreach ($sessionItems as $si) {
    $countStmt->execute([$si['id'], $team, $si['current_round']]);
    $ownCount = $countStmt->fetch() ?: null;

    $status = $recon->counterStatus($si, $team, $ownCount);
    if ($ownCount) {
        $teamDone++;
        if ((int) $ownCount['user_id'] === (int) $user['id']) {
            $myDone++;
        }
    }

    if ($countStatusFilter !== 'SEMUA' && $countStatusFilter !== $status) {
        continue;
    }

    $lockStmt->execute([$si['id'], $team]);
    $lockedByUserId = $lockStmt->fetchColumn();

    $rows[] = [
        'session_item_id' => (int) $si['id'],
        'sku' => $si['sku_snapshot'],
        'barcode' => $si['barcode_snapshot'],
        'name' => $si['name_snapshot'],
        'category' => $si['category_snapshot'],
        'brand' => $si['brand_snapshot'],
        'buy_unit' => $si['buy_unit_snapshot'],
        'buy_content' => (float) $si['buy_content_snapshot'],
        'mid_unit' => $si['mid_unit_snapshot'],
        'mid_content' => $si['mid_content_snapshot'] !== null ? (float) $si['mid_content_snapshot'] : null,
        'base_unit' => $si['base_unit_snapshot'],
        'levels' => UnitConversion::levels(UnitConversion::fromSessionItemSnapshot($si)),
        'status' => $status,
        'round' => (int) $si['current_round'],
        'locked_by_me' => $lockedByUserId !== false && (int) $lockedByUserId === $user['id'],
        'locked_by_other' => $lockedByUserId !== false && (int) $lockedByUserId !== $user['id'],
        'own_count' => $ownCount ? [
            'count_id' => (int) $ownCount['id'],
            'evidence_status' => $ownCount['evidence_status'],
            'good_buy_qty' => (float) $ownCount['good_buy_qty'],
            'good_mid_qty' => (float) $ownCount['good_mid_qty'],
            'good_base_input_qty' => (float) $ownCount['good_base_input_qty'],
            'good_base_qty' => (float) $ownCount['good_base_qty'],
            'damaged_qty' => (float) $ownCount['damaged_qty'], 'damaged_unit' => $ownCount['damaged_unit'],
            'expired_qty' => (float) $ownCount['expired_qty'], 'expired_unit' => $ownCount['expired_unit'],
            'deadstock_qty' => (float) $ownCount['deadstock_qty'], 'deadstock_unit' => $ownCount['deadstock_unit'],
            'physical_base_qty' => (float) $ownCount['physical_base_qty'],
            'note' => $ownCount['note'],
        ] : null,
        // NOTE: intentionally absent from this response, always: system_qty_snapshot,
        // unit_cost_snapshot, the other team's count, MATCH/MISMATCH, variance.
    ];
}

Response::json([
    'team' => $team,
    'data' => $rows,
    'progress' => [
        'team_total' => count($sessionItems),
        'team_done' => $teamDone,
        'my_done' => $myDone,
    ],
]);
