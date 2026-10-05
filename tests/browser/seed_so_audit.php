<?php
declare(strict_types=1);

/**
 * Seed for the Laporan Stock Opname (audit) browser test: the Jejak fixture — one LEGACY_DUAL_COUNT (CIBADAK-like) and one FINDINGS_V1
 * (SCM-like, real evidence photos, a voided finding, a post-count movement) POSTED session, built through the application's own services.
 * Prints one JSON document.
 */
require_once __DIR__ . '/../lib/jejak_real_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = jejak_build_fixture($pdo);
$num = static fn (int $id) => (string) $pdo->query("SELECT session_number FROM stock_opname_sessions WHERE id = {$id}")->fetchColumn();
$mk = static function (array $s) use ($num, $pdo): array {
    $items = [];
    foreach ($s['items'] as $k => $i) {
        $items[$k] = ['id' => $i['id'], 'sku' => $i['sku'], 'name' => $i['name']];
    }
    $wh = $pdo->query("SELECT name FROM warehouses WHERE id = " . (int) $s['warehouse_id'])->fetchColumn();
    return ['session_id' => $s['session_id'], 'session_number' => $num($s['session_id']), 'warehouse_id' => $s['warehouse_id'], 'warehouse' => $wh, 'items' => $items,
        'users' => array_map(static fn ($u) => ['username' => $u['username']], $s['users'])];
};
echo json_encode([
    'admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'outsider' => $fx['outsider'],
    'legacy' => $mk($fx['legacy']), 'findings' => $mk($fx['findings']),
]);
