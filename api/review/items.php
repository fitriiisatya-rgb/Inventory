<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/**
 * SUPERADMIN-only. Separate query/serializer from api/counter/items.php
 * by design (design review point 12) — this is the only endpoint in the
 * whole app allowed to return system_qty_snapshot, both teams' counts,
 * and MATCH/MISMATCH/variance.
 */

$user = Auth::requireLogin();
Permissions::require($user['role'], 'reconciliation.view');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$recon = new ReconciliationService($pdo, new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']));

$rows = array_map(static function (array $r) {
    $si = $r['session_item'];
    return [
        'session_item_id' => (int) $si['id'],
        'sku' => $si['sku_snapshot'],
        'name' => $si['name_snapshot'],
        'category' => $si['category_snapshot'],
        'round' => (int) $si['current_round'],
        'item_status' => $si['item_status'],
        'not_countable_reason' => $si['not_countable_reason'],
        'system_qty_snapshot' => (float) $si['system_qty_snapshot'],
        'unit_cost_snapshot' => (float) $si['unit_cost_snapshot'],
        'p1' => $r['p1'] ? [
            'user_name' => $r['p1']['user_name_snapshot'], 'counted_at' => $r['p1']['counted_at'],
            'good' => (float) $r['p1']['good_base_qty'], 'damaged' => (float) $r['p1']['damaged_base_qty'],
            'expired' => (float) $r['p1']['expired_base_qty'], 'deadstock' => (float) $r['p1']['deadstock_base_qty'],
            'physical' => (float) $r['p1']['physical_base_qty'],
        ] : null,
        'p2' => $r['p2'] ? [
            'user_name' => $r['p2']['user_name_snapshot'], 'counted_at' => $r['p2']['counted_at'],
            'good' => (float) $r['p2']['good_base_qty'], 'damaged' => (float) $r['p2']['damaged_base_qty'],
            'expired' => (float) $r['p2']['expired_base_qty'], 'deadstock' => (float) $r['p2']['deadstock_base_qty'],
            'physical' => (float) $r['p2']['physical_base_qty'],
        ] : null,
        'status' => $r['status'],
        'variance_p1' => $r['variance_p1'],
        'variance_p2' => $r['variance_p2'],
    ];
}, $recon->listForReview($sessionId));

Response::json(['data' => $rows]);
