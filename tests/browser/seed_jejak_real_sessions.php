<?php
declare(strict_types=1);

/**
 * Seed for the real-data Jejak browser test: the two fixture sessions
 * (LEGACY_DUAL_COUNT "CIBADAK-like" + FINDINGS_V1 "SCM-like", built through
 * the real services) plus a BIG legacy session of 130 lines (direct INSERT —
 * only used to exercise pagination and the vertical-scroll behaviour with a
 * realistically long table). Prints one JSON document.
 */
require_once __DIR__ . '/../lib/jejak_real_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = jejak_build_fixture($pdo);

$kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Gudang BESAR (fixture)', 1)")->execute(['c' => 'JFB' . bin2hex(random_bytes(2))]);
$whBig = (int) $pdo->lastInsertId();
$cat = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$adminId = $fx['admin']['id'];
$uuid = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
$pdo->prepare("INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model, created_by) VALUES (:w, '2026-09-28', :u, 'SO-BIG-0001', 'ALL_ACTIVE_STOCK', 'OPEN', 'LEGACY_DUAL_COUNT', :c)")
    ->execute(['w' => $whBig, 'u' => $uuid, 'c' => $adminId]);
$bigId = (int) $pdo->lastInsertId();
for ($i = 1; $i <= 130; $i++) {
    $sku = sprintf('JFBIG-%03d', $i);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:s,:n,:u,:c,0,\'ACTIVE\')')
        ->execute(['s' => $sku, 'n' => "Barang Besar {$i}", 'u' => $kg, 'c' => $cat]);
    $itemId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base, p1_qty_base, p2_qty_base, match_status, variance_qty_base) VALUES (:s,:i,:q,:c1,1,:h,:c2,:c3,\'MATCH\',:v)')
        ->execute(['s' => $bigId, 'i' => $itemId, 'q' => 10 + $i, 'c1' => 10 + $i - ($i % 3), 'c2' => 10 + $i - ($i % 3), 'c3' => 10 + $i - ($i % 3), 'h' => 1000 + $i, 'v' => -($i % 3)]);
}

$numbers = [];
foreach (['legacy', 'findings'] as $k) {
    $numbers[$k] = $pdo->query("SELECT session_number FROM stock_opname_sessions WHERE id = {$fx[$k]['session_id']}")->fetchColumn();
}

echo json_encode([
    'admin' => $fx['admin'],
    'legacy' => ['id' => $fx['legacy']['session_id'], 'number' => $numbers['legacy'], 'warehouse' => 'CIBADAK', 'model' => 'LEGACY_DUAL_COUNT', 'expect' => $fx['legacy']['expect'],
        'skus' => array_map(fn ($i) => $i['sku'], $fx['legacy']['items']), 'users' => array_map(fn ($u) => $u['username'], $fx['legacy']['users'])],
    'findings' => ['id' => $fx['findings']['session_id'], 'number' => $numbers['findings'], 'warehouse' => 'SCM', 'model' => 'FINDINGS_V1', 'expect' => $fx['findings']['expect'],
        'skus' => array_map(fn ($i) => $i['sku'], $fx['findings']['items']), 'users' => array_map(fn ($u) => $u['username'], $fx['findings']['users'])],
    'big' => ['id' => $bigId, 'number' => 'SO-BIG-0001', 'lines' => 130],
]);
